<?php

namespace App\Http\Controllers;

use App\Jobs\RunDeploymentJob;
use App\Models\Deployment;
use App\Models\Server;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class DeployController extends Controller
{
    private function deploymentsQuery()
    {
        $user = auth()->user();
        $q = Deployment::with(['server', 'site', 'user']);

        return $user->isAdmin() ? $q : $q->where('user_id', $user->id);
    }

    public function dashboard(): Response
    {
        $user = auth()->user();

        $servers = $user->isAdmin()
            ? Server::all()
            : Server::where('user_id', $user->id)->get();

        $recentDeployments = $this->deploymentsQuery()->latest()->limit(10)->get();

        return Inertia::render('Dashboard', [
            'servers' => $servers,
            'recentDeployments' => $recentDeployments,
        ]);
    }

    public function index(): Response
    {
        $deployments = $this->deploymentsQuery()->latest()->paginate(20);

        return Inertia::render('Deployments/Index', [
            'deployments' => $deployments,
            'isAdmin' => auth()->user()->isAdmin(),
        ]);
    }

    public function show(Deployment $deployment): Response
    {
        $this->authorizeDeployment($deployment);
        $deployment->load(['server', 'site', 'logs']);

        return Inertia::render('Deployments/Show', [
            'deployment' => $deployment,
        ]);
    }

    public function trigger(Request $request): RedirectResponse|JsonResponse
    {
        $request->validate([
            'site_id' => 'required|exists:sites,id',
            'with_data' => 'sometimes|boolean',
            'with_database' => 'sometimes|boolean',
            'confirm_site_name' => 'sometimes|string',
        ]);

        $site = Site::with('server')->findOrFail($request->input('site_id'));

        $this->authorizeServer($site->server);

        // F7: `with_database` REPLACEs remote rows with the local dump. On a
        // production site that is a destructive one-click action, so require
        // the operator to type the site name back.
        if ($request->boolean('with_database') && $site->isProduction()) {
            if ($request->input('confirm_site_name') !== $site->name) {
                throw ValidationException::withMessages([
                    'confirm_site_name' => sprintf(
                        'Pushing a local database over "%s" overwrites live data. '
                        .'Type the site name exactly to confirm.',
                        $site->name
                    ),
                ]);
            }
        }

        $deployment = Deployment::create([
            'user_id' => auth()->id(),
            'server_id' => $site->server_id,
            'site_id' => $site->id,
            'branch' => $site->branch,
            'with_data' => $request->boolean('with_data'),
            'with_database' => $request->boolean('with_database'),
            'triggered_by' => auth()->user()?->name ?? 'api',
            'status' => 'pending',
        ]);

        RunDeploymentJob::dispatch($deployment);

        if ($request->wantsJson()) {
            return response()->json(['deployment_id' => $deployment->id, 'status' => 'dispatched']);
        }

        return redirect()->route('deployments.show', $deployment)
            ->with('success', 'Deployment started for '.$site->name.'.');
    }

    /**
     * CI deploy trigger.
     *
     * Auth is an HMAC over the raw body, not a bearer-style token compare:
     *
     *     X-Signature: sha256=<hash_hmac('sha256', $rawBody, $secret)>
     *
     * The previous implementation compared `$server->webhook_secret !== $token`
     * directly, which authenticated anyone when no secret was configured
     * (null !== null is false) and leaked timing. This fails closed: no secret
     * on the site means the webhook is disabled, not open.
     */
    public function webhook(Request $request): JsonResponse
    {
        $site = $this->resolveWebhookSite($request);

        if (! $site || ! $site->active) {
            // Same response as a bad signature — don't let an unauthenticated
            // caller probe which site identifiers exist.
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        if (! $this->webhookSignatureIsValid($request, $site)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $branch = (string) $request->input('branch', $site->branch ?: 'main');

        if ($branch !== ($site->branch ?: 'main')) {
            return response()->json([
                'error' => 'Branch mismatch',
                'expected' => $site->branch ?: 'main',
                'received' => $branch,
            ], 422);
        }

        $commit = $request->input('commit');

        // CI can retry a workflow; don't redeploy a commit that already shipped.
        if ($commit && $this->commitAlreadyDeployed($site, $commit)) {
            return response()->json([
                'status' => 'skipped',
                'reason' => 'commit already deployed successfully',
                'commit' => $commit,
            ]);
        }

        $deployment = Deployment::create([
            'server_id' => $site->server_id,
            // F2: without site_id, DeployService::run() dereferences a null
            // site and every webhook deploy died before phase 1.
            'site_id' => $site->id,
            'user_id' => $site->server?->user_id,
            'branch' => $branch,
            'commit_hash' => $commit,
            'triggered_by' => 'webhook',
            'status' => 'pending',
        ]);

        RunDeploymentJob::dispatch($deployment);

        return response()->json([
            'deployment_id' => $deployment->id,
            'status' => 'queued',
        ]);
    }

    /**
     * Resolve the target site from the `site` key — numeric id or exact name.
     * Falls back to the legacy `{server: "<name>"}` payload while existing
     * webhooks are migrated, but only when that server has exactly one site
     * (otherwise there is no safe guess about what to deploy).
     */
    private function resolveWebhookSite(Request $request): ?Site
    {
        $identifier = $request->input('site');

        if ($identifier !== null && $identifier !== '') {
            return is_numeric($identifier)
                ? Site::find((int) $identifier)
                : Site::where('name', $identifier)->first();
        }

        $serverName = $request->input('server');
        if (! $serverName) {
            return null;
        }

        $server = Server::where('name', $serverName)->where('active', true)->first();
        if (! $server) {
            return null;
        }

        $sites = Site::where('server_id', $server->id)->where('active', true)->take(2)->get();

        return $sites->count() === 1 ? $sites->first() : null;
    }

    /**
     * Constant-time HMAC check over the raw request body.
     */
    private function webhookSignatureIsValid(Request $request, Site $site): bool
    {
        // Site secret is the new home; server secret is the migration fallback.
        $secret = $site->webhook_secret ?: $site->server?->webhook_secret;

        if (! $secret) {
            return false;
        }

        $header = (string) $request->header('X-Signature');
        if ($header === '') {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, $header);
    }

    private function commitAlreadyDeployed(Site $site, string $commit): bool
    {
        return Deployment::where('site_id', $site->id)
            ->where('commit_hash', $commit)
            ->where('status', 'success')
            ->exists();
    }

    public function poll(Deployment $deployment): JsonResponse
    {
        $this->authorizeDeployment($deployment);
        $deployment->load(['server', 'site', 'logs']);

        return response()->json([
            'status' => $deployment->status,
            'duration_seconds' => $deployment->duration_seconds,
            'ai_risk_level' => $deployment->ai_risk_level,
            'ai_audit_result' => $deployment->ai_audit_result,
            'logs' => $deployment->logs,
        ]);
    }

    public function health(): JsonResponse
    {
        return response()->json(['status' => 'ok', 'timestamp' => now()->toIso8601String()]);
    }

    private function authorizeDeployment(Deployment $deployment): void
    {
        $user = auth()->user();
        if (! $user->isAdmin() && $deployment->user_id !== $user->id) {
            abort(403);
        }
    }

    private function authorizeServer(Server $server): void
    {
        $user = auth()->user();
        if (! $user->isAdmin() && $server->user_id !== $user->id) {
            abort(403);
        }
    }
}
