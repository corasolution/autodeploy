<?php

namespace App\Services;

use App\Models\Server;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class AaPanelApiService
{
    private Server $server;
    private string $baseUrl;

    public function __construct(Server $server)
    {
        $this->server = $server;
        $port         = config('autopilot.aapanel.default_port_http', 7800);
        $this->baseUrl = rtrim($server->panel_url ?? "http://{$server->host}:{$port}", '/');
    }

    private function sign(string $path): array
    {
        $time       = time();
        $token      = $this->server->panel_token;
        $requestToken = md5(md5((string) $time) . $token);

        return [
            'request_time'  => $time,
            'request_token' => $requestToken,
        ];
    }

    public function request(string $path, array $data = []): array
    {
        $payload = array_merge($data, $this->sign($path));
        $url     = $this->baseUrl . $path;

        $response = Http::asForm()
            ->withoutVerifying()
            ->post($url, $payload);

        if (!$response->successful()) {
            throw new RuntimeException("aaPanel API error [{$path}]: " . $response->body());
        }

        $result = $response->json();

        if (isset($result['status']) && $result['status'] === false) {
            throw new RuntimeException("aaPanel API failed [{$path}]: " . ($result['msg'] ?? 'Unknown error'));
        }

        return $result;
    }

    public function listSites(): array
    {
        return $this->request('/site?action=getData');
    }

    public function listDatabases(): array
    {
        return $this->request('/database?action=getData');
    }

    public function getSiteInfo(string $siteName): array
    {
        return $this->request('/site?action=getSiteInfo', ['siteName' => $siteName]);
    }

    public function listFiles(string $path): array
    {
        return $this->request('/files?action=GetDir', ['path' => $path]);
    }
}
