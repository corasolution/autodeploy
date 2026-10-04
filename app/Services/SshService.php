<?php

namespace App\Services;

use App\Models\Server;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SFTP;
use phpseclib3\Net\SSH2;
use RuntimeException;

class SshService
{
    private ?SSH2 $connection = null;

    private Server $server;

    public function __construct(Server $server)
    {
        $this->server = $server;
    }

    public function connect(): void
    {
        if ($this->server->ssh_auth === 'key') {
            if (empty($this->server->ssh_private_key)) {
                throw new RuntimeException(
                    'SSH private key is missing or could not be decrypted for server "'.$this->server->name.'". '.
                    'Please re-enter the private key in Server → Edit.'
                );
            }
        } else {
            if (empty($this->server->ssh_password)) {
                throw new RuntimeException(
                    'SSH password is missing or could not be decrypted for server "'.$this->server->name.'". '.
                    'Please re-enter the password in Server → Edit.'
                );
            }
        }

        $ssh = new SSH2($this->server->host, $this->server->ssh_port);
        // Sized for large remote operations (extracting ~1GB archives, composer
        // install, queue:restart broadcasts) that may go silent for minutes.
        $ssh->setTimeout(config('autopilot.ssh.timeout', 3600));

        if ($this->server->ssh_auth === 'key') {
            $key = PublicKeyLoader::load($this->server->ssh_private_key);
            if (! $ssh->login($this->server->ssh_user, $key)) {
                throw new RuntimeException('SSH key authentication failed for '.$this->server->host);
            }
        } else {
            if (! $ssh->login($this->server->ssh_user, $this->server->ssh_password)) {
                throw new RuntimeException('SSH password authentication failed for '.$this->server->host);
            }
        }

        $this->connection = $ssh;
    }

    public function exec(string $command): array
    {
        if (! $this->connection) {
            throw new RuntimeException('SSH not connected. Call connect() first.');
        }

        try {
            $output = $this->connection->exec($command);
            $exitCode = $this->connection->getExitStatus();
        } catch (\Throwable $e) {
            // Reconnect and retry on two recoverable phpseclib conditions:
            // 1. "Please close the channel" — previous exec channel not torn down (common after heavy SFTP).
            // 2. "timed out" / "connection closed" — SSH keepalive dropped the connection during upload.
            $msg = $e->getMessage();
            $recoverable = stripos($msg, 'close the channel') !== false
                || stripos($msg, 'timed out') !== false
                || stripos($msg, 'connection closed') !== false;

            if ($recoverable) {
                $this->reconnect();
                $output = $this->connection->exec($command);
                $exitCode = $this->connection->getExitStatus();
            } else {
                throw $e;
            }
        }

        return ['output' => $output, 'exit_code' => $exitCode];
    }

    public function execSafe(string $command, array $args = []): array
    {
        $safeArgs = array_map('escapeshellarg', $args);
        $cmd = vsprintf($command, $safeArgs);

        return $this->exec($cmd);
    }

    public function isConnected(): bool
    {
        return $this->connection !== null && $this->connection->isConnected();
    }

    public function disconnect(): void
    {
        if ($this->connection) {
            $this->connection->disconnect();
            $this->connection = null;
        }
    }

    public function reconnect(): void
    {
        $this->disconnect();
        $this->connect();
    }

    public function uploadContent(string $content, string $remotePath): void
    {
        $sftp = $this->makeSftp();

        if (! $sftp->put($remotePath, $content)) {
            throw new RuntimeException('SFTP write failed: '.$remotePath);
        }
    }

    public function uploadFile(string $localPath, string $remotePath, ?callable $onProgress = null): void
    {
        $totalBytes = @filesize($localPath) ?: 0;
        $totalMb = $totalBytes > 0 ? round($totalBytes / 1024 / 1024, 1) : 0;

        \Log::info("SFTP upload starting: {$localPath} → {$remotePath} ({$totalMb}MB)");

        // Progress callback: log every 2% or every 2 seconds, whichever first.
        // Tighter cadence keeps the live terminal feeling responsive.
        $lastLoggedBytes = 0;
        $lastLoggedAt = microtime(true);
        $startedAt = microtime(true);
        $progress = function ($sent) use (&$lastLoggedBytes, &$lastLoggedAt, $startedAt, $totalBytes, $onProgress) {
            $now = microtime(true);
            $deltaPct = $totalBytes > 0 ? (($sent - $lastLoggedBytes) * 100 / $totalBytes) : 100;
            if ($deltaPct >= 2 || ($now - $lastLoggedAt) >= 2) {
                $sentMb = round($sent / 1024 / 1024, 1);
                $totalMb = $totalBytes > 0 ? round($totalBytes / 1024 / 1024, 1) : 0;
                $elapsed = max(0.001, $now - $startedAt);
                $rateMbps = round(($sent / 1024 / 1024) / $elapsed, 2);
                $pct = $totalBytes > 0 ? round($sent * 100 / $totalBytes, 1) : 0;
                \Log::info("SFTP progress: {$sentMb}/{$totalMb}MB ({$pct}%) at {$rateMbps} MB/s");
                if ($onProgress) {
                    try {
                        $onProgress($sent, $totalBytes, $rateMbps, $pct);
                    } catch (\Throwable $e) {
                        \Log::warning('Upload progress callback failed: '.$e->getMessage());
                    }
                }
                $lastLoggedBytes = $sent;
                $lastLoggedAt = $now;
            }
        };

        // Retry with growing backoff — large uploads can fail on transient network
        // issues, but repeated "unreachable host" errors specifically right after a
        // burst of SSH reconnects (unzip/chmod/chown, then a fresh SFTP session for
        // seed assets) are consistent with a host-side connection-rate limiter
        // (e.g. fail2ban) briefly dropping new connections from this IP. A single
        // 3s retry isn't enough for that window to clear, so back off further.
        $maxAttempts = 4;
        $backoffSeconds = [3, 8, 15];

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $sftp = $this->makeSftp();
                // Sized for archives up to ~1GB. Default 30s would surface as
                // "Unable to write N bytes" mid-transfer on large uploads.
                $sftp->setTimeout(3600);

                $ok = $sftp->put(
                    $remotePath,
                    $localPath,
                    SFTP::SOURCE_LOCAL_FILE,
                    -1,
                    -1,
                    $progress
                );

                if ($ok) {
                    $elapsed = round(microtime(true) - $startedAt, 1);
                    \Log::info("SFTP upload complete: {$totalMb}MB in {$elapsed}s".($attempt > 1 ? " (attempt {$attempt})" : ''));

                    return;
                }
            } catch (\Throwable $e) {
                \Log::warning("SFTP attempt {$attempt}/{$maxAttempts} failed: ".$e->getMessage());
                if ($attempt === $maxAttempts) {
                    throw new RuntimeException("SFTP upload failed after {$maxAttempts} attempts: ".$e->getMessage());
                }
            }

            sleep($backoffSeconds[$attempt - 1] ?? 15);
        }

        throw new RuntimeException('SFTP upload failed: '.$localPath.' → '.$remotePath);
    }

    private function makeSftp(): SFTP
    {
        $sftp = new SFTP($this->server->host, $this->server->ssh_port);
        $sftp->setTimeout(config('autopilot.ssh.timeout', 30));

        if ($this->server->ssh_auth === 'key') {
            $key = PublicKeyLoader::load($this->server->ssh_private_key);
            if (! $sftp->login($this->server->ssh_user, $key)) {
                throw new RuntimeException('SFTP key authentication failed.');
            }
        } else {
            if (! $sftp->login($this->server->ssh_user, $this->server->ssh_password)) {
                throw new RuntimeException('SFTP password authentication failed.');
            }
        }

        return $sftp;
    }

    public function testConnection(): bool
    {
        try {
            $this->connect();
            $result = $this->exec('echo connected');
            $this->disconnect();

            return str_contains($result['output'], 'connected');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Like testConnection() but returns a specific reason so the UI can show
     * the user something more useful than "SSH connection failed."
     *
     * @return array{ok:bool,message:string,reason?:string}
     */
    public function testConnectionDetailed(): array
    {
        try {
            $this->connect();
            $result = $this->exec('echo connected');
            $this->disconnect();

            if (str_contains($result['output'] ?? '', 'connected')) {
                return ['ok' => true, 'message' => 'SSH connection successful.'];
            }

            return [
                'ok' => false,
                'message' => 'Connected but echo test failed — server may have a restricted shell.',
                'reason' => 'restricted_shell',
            ];
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            $low = strtolower($msg);
            $reason = 'unknown';
            $hint = $msg;

            if (str_contains($low, 'authentication') || str_contains($low, 'login failed')) {
                $reason = 'auth';
                $hint = 'Authentication failed. Check username/password, or the host may require an SSH key (password SSH disabled).';
            } elseif (str_contains($low, 'connection refused')) {
                $reason = 'refused';
                $hint = 'Connection refused on port '.$this->server->ssh_port.'. The SSH service is not listening on this port — try the host\'s real SSH port (often 2222, 21098, 65002 on shared hosts).';
            } elseif (str_contains($low, 'timed out') || str_contains($low, 'timeout')) {
                $reason = 'timeout';
                $hint = 'Connection timed out. Port '.$this->server->ssh_port.' is likely firewalled. Check the host\'s real SSH port in your hosting control panel.';
            } elseif (str_contains($low, 'unable to connect') || str_contains($low, 'no route') || str_contains($low, 'getaddrinfo')) {
                $reason = 'unreachable';
                $hint = 'Host unreachable: '.$msg;
            }

            return ['ok' => false, 'message' => $hint, 'reason' => $reason];
        }
    }

    public function getDiskFreeSpace(string $path): int
    {
        $result = $this->exec('df -m '.escapeshellarg($path)." | awk 'NR==2{print $4}'");

        return (int) trim($result['output']);
    }

    public function getPhpVersion(): string
    {
        $binary = $this->server->php_binary;
        $result = $this->exec(escapeshellarg($binary).' -r "echo PHP_VERSION;"');

        return trim($result['output']);
    }
}
