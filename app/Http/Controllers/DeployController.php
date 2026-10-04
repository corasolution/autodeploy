<?php

namespace App\Http\Controllers;

use App\Jobs\RunDeploymentJob;
use App\Models\Deployment;
use App\Models\Server;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DeployController extends Controller
{
    private function deploymentsQuery()
    {
        $user = auth()->user();
        $q    = Deployment::with(['server', 'site', 'user']);

        return $user->isAdmin() ? $q : $q->where('user_id', $user->id);
    }

    public function dashboard(): Response
    {
        $user = auth()->user();

        $servers = $user->isAdmin()
            ? \App\Models\Server::all()
            : \App\Models\Server::where('user_id', $user->id)->get();

        $recentDeployments = $this->deploymentsQuery()->latest()->limit(10)->get();

        return Inertia::render('Dashboard', [
            'servers'           => $servers,
            'recentDeployments' => $recentDeployments,
        ]);
    }

    public function index(): Response
    {
        $deployments = $this->deploymentsQuery()->latest()->paginate(20);

        return Inertia::render('Deployments/Index', [
            'deployments' => $deployments,
            'isAdmin'     => auth()->user()->isAdmin(),
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
            'site_id'       => 'required|exists:sites,id',
            'with_data'     => 'sometimes|boolean',
            'with_database' => 'sometimes|boolean',
        ]);

        $site = Site::with('server')->findOrFail($request->input('site_id'));

        $this->authorizeServer($site->server);

        $deployment = Deployment::create([
            'user_id'       => auth()->id(),
            'server_id'     => $site->server_id,
            'site_id'       => $site->id,
            'branch'        => $site->branch,
            'with_data'     => $request->boolean('with_data'),
            'with_database' => $request->boolean('with_database'),
            'triggered_by'  => auth()->user()?->name ?? 'api',
            'status'        => 'pending',
        ]);

        RunDeploymentJob::dispatch($deployment);

        if ($request->wantsJson()) {
            return response()->json(['deployment_id' => $deployment->id, 'status' => 'dispatched']);
        }

        return redirect()->route('deployments.show', $deployment)
            ->with('success', 'Deployment started for ' . $site->name . '.');
    }

    public function webhook(Request $request): JsonResponse
    {
        $serverName = $request->input('server');
        $branch     = $request->input('branch', 'main');
        $token      = $request->header('X-Deploy-Token');

        $server = Server::where('name', $serverName)->where('active', true)->firstOrFail();

        if ($server->webhook_secret !== $token) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $deployment = Deployment::create([
            'server_id'    => $server->id,
            'branch'       => $branch,
            'commit_hash'  => $request->input('commit'),
            'triggered_by' => 'webhook',
            'status'       => 'pending',
        ]);

        RunDeploymentJob::dispatch($deployment);

        return response()->json([
            'deployment_id' => $deployment->id,
            'status'        => 'queued',
        ]);
    }

    public function poll(Deployment $deployment): JsonResponse
    {
        $this->authorizeDeployment($deployment);
        $deployment->load(['server', 'site', 'logs']);

        return response()->json([
            'status'           => $deployment->status,
            'duration_seconds' => $deployment->duration_seconds,
            'ai_risk_level'    => $deployment->ai_risk_level,
            'ai_audit_result'  => $deployment->ai_audit_result,
            'logs'             => $deployment->logs,
        ]);
    }

    public function health(): JsonResponse
    {
        return response()->json(['status' => 'ok', 'timestamp' => now()->toIso8601String()]);
    }

    private function authorizeDeployment(Deployment $deployment): void
    {
        $user = auth()->user();
        if (!$user->isAdmin() && $deployment->user_id !== $user->id) {
            abort(403);
        }
    }

    private function authorizeServer(Server $server): void
    {
        $user = auth()->user();
        if (!$user->isAdmin() && $server->user_id !== $user->id) {
            abort(403);
        }
    }
}
