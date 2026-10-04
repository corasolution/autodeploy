<?php

namespace App\Http\Controllers;

use App\Models\Server;
use App\Services\SshService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ServerController extends Controller
{
    private function serversQuery()
    {
        $user = auth()->user();
        return $user->isAdmin() ? Server::query() : Server::where('user_id', $user->id);
    }

    private function authorizeServer(Server $server): void
    {
        $user = auth()->user();
        if (!$user->isAdmin() && $server->user_id !== $user->id) {
            abort(403);
        }
    }

    public function index(): Response
    {
        $servers = $this->serversQuery()->withCount('sites')->with('user')->get();

        return Inertia::render('Servers/Index', [
            'servers' => $servers,
            'isAdmin' => auth()->user()->isAdmin(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Servers/Create');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'            => 'required|string|max:100',
            'panel_type'      => 'required|in:cpanel,aapanel,openpanel',
            'host'            => 'required|string|max:255',
            'ssh_port'        => 'integer|min:1|max:65535',
            'ssh_user'        => 'required|string|max:100',
            'web_user'        => 'nullable|string|max:100',
            'ssh_auth'        => 'required|in:password,key',
            'ssh_password'    => 'nullable|string',
            'ssh_private_key' => 'nullable|string',
            'panel_url'       => 'nullable|url',
            'panel_token'     => 'nullable|string',
            'active'          => 'boolean',
        ]);

        $data['user_id'] = auth()->id();
        Server::create($data);

        return redirect()->route('servers.index')->with('success', 'Server added.');
    }

    public function show(Server $server): Response
    {
        $this->authorizeServer($server);
        $server->load([
            'sites',
            'sites.deployments' => fn($q) => $q->latest()->limit(1),
        ]);

        return Inertia::render('Servers/Show', [
            'server' => $server,
        ]);
    }

    public function edit(Server $server): Response
    {
        $this->authorizeServer($server);

        // ssh_password / ssh_private_key / panel_token are in Server::$hidden
        // (encrypted at rest, stripped from default JSON). Surface them
        // explicitly here so the edit form can pre-fill — same pattern as
        // SiteController + env_content (CLAUDE.md rule 12).
        $payload = array_merge($server->toArray(), [
            'ssh_password'    => $server->ssh_password,
            'ssh_private_key' => $server->ssh_private_key,
            'panel_token'     => $server->panel_token,
        ]);

        return Inertia::render('Servers/Edit', ['server' => $payload]);
    }

    public function update(Request $request, Server $server): RedirectResponse
    {
        $this->authorizeServer($server);
        $data = $request->validate([
            'name'            => 'required|string|max:100',
            'panel_type'      => 'required|in:cpanel,aapanel,openpanel',
            'host'            => 'required|string|max:255',
            'ssh_port'        => 'integer|min:1|max:65535',
            'ssh_user'        => 'required|string|max:100',
            'web_user'        => 'nullable|string|max:100',
            'ssh_auth'        => 'required|in:password,key',
            'ssh_password'    => 'nullable|string',
            'ssh_private_key' => 'nullable|string',
            'panel_url'       => 'nullable|url',
            'panel_token'     => 'nullable|string',
            'active'          => 'boolean',
        ]);

        foreach (['ssh_password', 'ssh_private_key', 'panel_token'] as $field) {
            if (empty($data[$field])) {
                unset($data[$field]);
            }
        }

        $server->update($data);

        return redirect()->route('servers.show', $server)->with('success', 'Server updated.');
    }

    public function destroy(Server $server): RedirectResponse
    {
        $this->authorizeServer($server);
        $server->delete();

        return redirect()->route('servers.index')->with('success', 'Server deleted.');
    }

    public function testConnection(Server $server): JsonResponse
    {
        $ssh    = new SshService($server);
        $result = $ssh->testConnectionDetailed();

        return response()->json([
            'connected' => $result['ok'],
            'message'   => $result['message'],
            'reason'    => $result['reason'] ?? null,
        ]);
    }
}
