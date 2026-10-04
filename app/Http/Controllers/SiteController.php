<?php

namespace App\Http\Controllers;

use App\Models\Server;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SiteController extends Controller
{
    /**
     * UPGRADE-v2 Phase 5 — webhook tab.
     *
     * Rotates (or creates) the site's webhook secret and returns it together
     * with a ready-to-paste GitHub Actions workflow.
     *
     * The secret is returned EXACTLY ONCE, here, at the moment it is generated.
     * It is encrypted at rest and `$hidden` on the model, so it is never
     * serialised into any other response.
     */
    public function rotateWebhookSecret(Site $site): JsonResponse
    {
        $this->authorizeServer($site->server);

        $secret = 'whsec_'.bin2hex(random_bytes(24));

        $site->update(['webhook_secret' => $secret]);

        return response()->json([
            'secret' => $secret,
            'url' => rtrim(config('app.url'), '/').'/api/webhooks/deploy',
            'site' => $site->name,
            'workflow' => $this->githubWorkflow($site),
        ]);
    }

    /**
     * The deploy step signs the exact bytes it posts — `printf '%s'` rather
     * than echo, which would append a newline and break the HMAC.
     */
    private function githubWorkflow(Site $site): string
    {
        $branch = $site->branch ?: 'main';

        return <<<YAML
        name: Test & Deploy
        on: { push: { branches: [{$branch}] } }

        jobs:
          test:
            runs-on: ubuntu-latest
            steps:
              - uses: actions/checkout@v4
              - uses: shivammathur/setup-php@v2
                with: { php-version: '8.3' }
              - run: composer install --no-interaction
              - run: cp .env.example .env && php artisan key:generate
              - run: php artisan test

          deploy:
            needs: test
            runs-on: ubuntu-latest
            steps:
              - name: Trigger AutoPilot
                run: |
                  BODY='{"site":"\${{ vars.AUTOPILOT_SITE }}","branch":"{$branch}","commit":"\${{ github.sha }}"}'
                  SIG=\$(printf '%s' "\$BODY" | openssl dgst -sha256 -hmac "\${{ secrets.AUTOPILOT_SECRET }}" | sed 's/^.* //')
                  curl -fsS -X POST "\${{ vars.AUTOPILOT_URL }}/api/webhooks/deploy" \\
                    -H "Content-Type: application/json" \\
                    -H "X-Signature: sha256=\$SIG" \\
                    -d "\$BODY"
        YAML;
    }

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
