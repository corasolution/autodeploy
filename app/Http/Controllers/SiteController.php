<?php

namespace App\Http\Controllers;

use App\Models\Server;
use App\Models\Site;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SiteController extends Controller
{
    private function authorizeServer(Server $server): void
    {
        $user = auth()->user();
        if (! $user->isAdmin() && $server->user_id !== $user->id) {
            abort(403);
        }
    }

    private function authorizeSite(Site $site): void
    {
        $this->authorizeServer($site->server);
    }

    public function create(Server $server): Response
    {
        $this->authorizeServer($server);

        return Inertia::render('Sites/Create', ['server' => $server]);
    }

    public function store(Request $request, Server $server): RedirectResponse
    {
        $this->authorizeServer($server);
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'deploy_path' => 'required|string|max:500',
            'source_path' => 'nullable|string|max:500',
            'repo_url' => 'nullable|string|max:500',
            'branch' => 'string|max:100',
            'run_seeders' => 'boolean',
            'run_tenant_migrations' => 'boolean',
            'grant_createdb' => 'boolean',
            'pre_migrate_commands' => 'nullable|string',
            'php_binary' => 'string|max:200',
            'app_url' => 'nullable|url',
            'env_content' => 'nullable|string',
            'active' => 'boolean',
            'environment' => 'sometimes|in:production,staging,local',
        ]);

        if (! empty($data['source_path']) && ! empty($data['repo_url'])) {
            throw ValidationException::withMessages([
                'repo_url' => 'You cannot set both Source Path and Repo URL. Use one or the other.',
            ]);
        }

        $server->sites()->create($data);

        return redirect()->route('servers.show', $server)->with('success', 'Application added.');
    }

    public function edit(Site $site): Response
    {
        $this->authorizeSite($site);
        $siteData = array_merge($site->toArray(), ['env_content' => $site->env_content]);

        return Inertia::render('Sites/Edit', ['site' => $siteData, 'server' => $site->server]);
    }

    public function update(Request $request, Site $site): RedirectResponse
    {
        $this->authorizeSite($site);
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'deploy_path' => 'required|string|max:500',
            'source_path' => 'nullable|string|max:500',
            'repo_url' => 'nullable|string|max:500',
            'branch' => 'string|max:100',
            'run_seeders' => 'boolean',
            'run_tenant_migrations' => 'boolean',
            'grant_createdb' => 'boolean',
            'pre_migrate_commands' => 'nullable|string',
            'php_binary' => 'string|max:200',
            'app_url' => 'nullable|url',
            'env_content' => 'nullable|string',
            'active' => 'boolean',
            'environment' => 'sometimes|in:production,staging,local',
        ]);

        if (! empty($data['source_path']) && ! empty($data['repo_url'])) {
            throw ValidationException::withMessages([
                'repo_url' => 'You cannot set both Source Path and Repo URL. Use one or the other.',
            ]);
        }

        $site->update($data);

        return redirect()->route('servers.show', $site->server_id)->with('success', 'Application updated.');
    }

    public function destroy(Site $site): RedirectResponse
    {
        $this->authorizeSite($site);
        $serverId = $site->server_id;
        $site->delete();

        return redirect()->route('servers.show', $serverId)->with('success', 'Application removed.');
    }
}
