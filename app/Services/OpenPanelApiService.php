<?php

namespace App\Services;

use App\Models\Server;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * OpenPanel (openpanel.com) REST API wrapper.
 *
 * Unlike cPanel (static API token) and aaPanel (md5 request signature),
 * OpenPanel authenticates by POSTing credentials to /api/ once to mint a JWT,
 * which is then sent as a Bearer token on every subsequent call. The token is
 * cached for the lifetime of this instance — it is not persisted, so each
 * deployment run logs in fresh.
 *
 * Credentials follow the same convention as CpanelApiService: the server's
 * ssh_user doubles as the panel username, and panel_token holds the password.
 *
 * The API lives on the OpenAdmin port (2087), not the end-user OpenPanel port
 * (2083). See https://dev.openpanel.com/api/
 */
class OpenPanelApiService
{
    private Server $server;
    private string $baseUrl;
    private ?string $jwt = null;

    public function __construct(Server $server)
    {
        $this->server  = $server;
        $port          = config('autopilot.openpanel.default_admin_port', 2087);
        $this->baseUrl = rtrim($server->panel_url ?? "http://{$server->host}:{$port}", '/');
    }

    /** Exchange username/password for a JWT, caching it on this instance. */
    public function login(): string
    {
        if ($this->jwt !== null) {
            return $this->jwt;
        }

        $path = config('autopilot.openpanel.api_path', '/api/');

        $response = Http::withoutVerifying()
            ->acceptJson()
            ->post($this->baseUrl . $path, [
                'username' => $this->server->ssh_user,
                'password' => $this->server->panel_token,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('OpenPanel login failed: ' . $response->body());
        }

        // The field name has varied across releases; accept the common spellings
        // rather than hard-failing on a rename.
        $result = $response->json();
        $token  = $result['access_token'] ?? $result['token'] ?? $result['jwt'] ?? null;

        if (! is_string($token) || $token === '') {
            throw new RuntimeException('OpenPanel login returned no token: ' . $response->body());
        }

        return $this->jwt = $token;
    }

    /**
     * Authenticated call against any OpenPanel API path. Kept generic because
     * only the login and whoami endpoints are documented upstream at the time
     * of writing — add named wrappers below as further endpoints are confirmed.
     */
    public function request(string $path, array $data = [], string $method = 'get'): array
    {
        $response = Http::withoutVerifying()
            ->acceptJson()
            ->withToken($this->login())
            ->{$method}($this->baseUrl . '/' . ltrim($path, '/'), $data);

        if (! $response->successful()) {
            throw new RuntimeException("OpenPanel API error [{$path}]: " . $response->body());
        }

        return $response->json() ?? [];
    }

    /** Identity of the authenticated account — cheapest connectivity check. */
    public function whoami(): array
    {
        return $this->request('/api/whoami');
    }
}
