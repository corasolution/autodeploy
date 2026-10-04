<?php

namespace App\Http\Controllers;

use App\Models\Deployment;
use App\Models\DeployLog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LogController extends Controller
{
    public function index(Request $request): Response
    {
        $logs = DeployLog::with('deployment.server')
            ->when($request->deployment_id, fn($q, $id) => $q->where('deployment_id', $id))
            ->when($request->status, fn($q, $s) => $q->where('status', $s))
            ->when($request->phase, fn($q, $p) => $q->where('phase', $p))
            ->latest('logged_at')
            ->paginate(50);

        return Inertia::render('Logs/Index', [
            'logs'    => $logs,
            'filters' => $request->only(['deployment_id', 'status', 'phase']),
        ]);
    }

    public function forDeployment(Deployment $deployment): Response
    {
        $deployment->load(['server', 'logs' => fn($q) => $q->orderBy('phase')->orderBy('step')]);

        return Inertia::render('Logs/Deployment', [
            'deployment' => $deployment,
            'logs'       => $deployment->logs,
        ]);
    }
}
