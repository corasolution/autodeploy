<?php

namespace App\Services;

use App\Models\Server;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class CpanelApiService
{
    private Server $server;
    private string $baseUrl;

    public function __construct(Server $server)
    {
        $this->server  = $server;
        $port          = config('autopilot.cpanel.default_port', 2083);
        $this->baseUrl = rtrim($server->panel_url ?? "https://{$server->host}:{$port}", '/');
    }

    public function uapi(string $module, string $function, array $params = []): array
    {
        $path = config('autopilot.cpanel.uapi_path', '/execute/');
        $url  = "{$this->baseUrl}{$path}{$module}/{$function}";

        $response = Http::withHeaders([
            'Authorization' => 'cpanel ' . $this->server->ssh_user . ':' . $this->server->panel_token,
        ])->get($url, $params);

        if (!$response->successful()) {
            throw new RuntimeException("cPanel UAPI error [{$module}/{$function}]: " . $response->body());
        }

        $data = $response->json();

        if (($data['status'] ?? 0) !== 1) {
            $errors = implode(', ', $data['errors'] ?? ['Unknown error']);
            throw new RuntimeException("cPanel UAPI failed [{$module}/{$function}]: {$errors}");
        }

        return $data['data'] ?? [];
    }

    public function listFiles(string $directory): array
    {
        return $this->uapi('Fileman', 'list_files', ['dir' => $directory]);
    }

    public function checkDatabaseExists(string $dbName): bool
    {
        $databases = $this->uapi('MysqlFE', 'list_databases');
        $names     = array_column($databases, 'database');

        return in_array($dbName, $names, true);
    }

    public function getSslInfo(string $domain): array
    {
        return $this->uapi('SSL', 'fetch_cert_info', ['domain' => $domain]);
    }

    public function getPhpVersion(string $domain): string
    {
        $data = $this->uapi('LangPHP', 'php_get_vhost_versions', ['vhost' => $domain]);

        return $data['phpversion'] ?? 'unknown';
    }

    public function triggerGitDeploy(string $repoRoot): array
    {
        return $this->uapi('VersionControl', 'update', ['repository_root' => $repoRoot]);
    }
}
