import { useEffect, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import Layout, { StatusBadge, IconCheck, IconX, IconWarning, IconPlay, IconDatabase, IconRocket, IconTerminal } from '../../Components/Layout';

const DONE_STATUSES = ['success', 'failed', 'rolled_back'];

const PHASES = [
    { n: 1, label: 'Pre-Flight'  },
    { n: 2, label: 'Build'       },
    { n: 3, label: 'Upload'      },
    { n: 4, label: 'Remote'      },
    { n: 5, label: 'Health'      },
    { n: 6, label: 'Rollback'    },
];

const STEP_CLASS = {
    idle:    'bg-slate-100 border-slate-300 text-slate-400',
    done:    'bg-emerald-50 border-emerald-300 text-emerald-600',
    running: 'bg-amber-50 border-amber-300 text-amber-600 animate-pulse',
    error:   'bg-red-50 border-red-300 text-red-600',
};

const LABEL_CLASS = {
    idle: 'text-slate-400', done: 'text-emerald-600', running: 'text-amber-600', error: 'text-red-600',
};

function PhaseWizard({ logs, deploymentStatus }) {
    const getPhaseStatus = (phaseNum) => {
        const phaseLogs = logs.filter(l => Number(l.phase) === phaseNum);
        if (!phaseLogs.length) return 'idle';
        if (phaseLogs.some(l => l.status === 'error')) return 'error';
        const maxPhase = Math.max(...logs.map(l => Number(l.phase)));
        if (deploymentStatus === 'running' && phaseNum === maxPhase) return 'running';
        return 'done';
    };

    return (
        <div className="bg-white border border-slate-200 rounded-xl px-5 py-4 mb-6 shadow-sm">
            <p className="text-xs font-semibold text-slate-400 uppercase tracking-widest mb-4">Deployment Phases</p>
            <div className="flex items-center overflow-x-auto">
                {PHASES.map((phase, i) => {
                    const status = getPhaseStatus(phase.n);
                    return (
                        <div key={phase.n} className="flex items-center flex-1 min-w-0">
                            <div className="flex flex-col items-center gap-1.5 min-w-0 px-1">
                                <span className={`w-9 h-9 rounded-full border-2 flex items-center justify-center transition-all ${STEP_CLASS[status]}`}>
                                    {status === 'done'    && <IconCheck   className="w-4 h-4" />}
                                    {status === 'error'   && <IconX       className="w-4 h-4" />}
                                    {status === 'running' && <span className="w-2.5 h-2.5 rounded-full bg-amber-500 animate-bounce" />}
                                    {status === 'idle'    && <span className="text-xs font-bold text-slate-400">{phase.n}</span>}
                                </span>
                                <span className={`text-xs font-medium whitespace-nowrap ${LABEL_CLASS[status]}`}>
                                    {phase.label}
                                </span>
                            </div>
                            {i < PHASES.length - 1 && (
                                <div className={`flex-1 h-0.5 rounded-full transition-colors mb-5 ${status === 'done' ? 'bg-emerald-200' : 'bg-slate-200'}`} />
                            )}
                        </div>
                    );
                })}
            </div>
        </div>
    );
}

export default function DeploymentShow({ deployment: initial }) {
    const [dep, setDep]     = useState(initial);
    const [logs, setLogs]   = useState(initial.logs ?? []);
    const terminalRef       = useRef(null);
    const intervalRef       = useRef(null);

    const isLive = !DONE_STATUSES.includes(dep.status);
    const [redeploying, setRedeploying] = useState(false);

    const redeploy = ({ withData = false, withDatabase = false } = {}) => {
        const siteId = dep.site?.id ?? initial.site?.id ?? initial.site_id;
        if (!siteId) { alert('Cannot redeploy: this deployment has no associated site.'); return; }
        let label = 'Redeploy (code only)';
        if (withDatabase) label = 'Redeploy + push local DB rows to server (overwrites remote rows!)';
        else if (withData) label = 'Redeploy with data (runs seeders)';
        if (!confirm(`${label}\n\nStart a new deployment for this site?`)) return;
        setRedeploying(true);
        router.post('/deploy', { site_id: siteId, with_data: withData, with_database: withDatabase }, {
            onFinish: () => setRedeploying(false),
        });
    };

    const poll = async () => {
        try {
            const res  = await fetch(`/deployments/${initial.id}/poll`, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            const data = await res.json();
            setDep(prev => ({ ...prev, ...data }));
            setLogs(data.logs ?? []);
            if (DONE_STATUSES.includes(data.status)) clearInterval(intervalRef.current);
        } catch { /* network blip — keep polling */ }
    };

    useEffect(() => {
        if (isLive) intervalRef.current = setInterval(poll, 2000);
        return () => clearInterval(intervalRef.current);
    }, []);

    useEffect(() => {
        if (terminalRef.current) {
            terminalRef.current.scrollTop = terminalRef.current.scrollHeight;
        }
    }, [logs]);

    // Parse progress from Phase 3 logs
    const uploadRegex = /Uploading\s+([\d.]+)\/([\d.]+)MB\s+\(([\d.]+)%\)(?:\s+at\s+([\d.]+)\s+MB\/s)?/;
    const zipRegex    = /Creating archive\s+\((\d+)%\)/;
    const activeProgress = (() => {
        for (let i = logs.length - 1; i >= 0; i--) {
            const l = logs[i];
            if (Number(l.phase) !== 3 || !l.output) continue;
            if (Number(l.step) === 11) {
                if (l.status === 'success') return null;
                const m = l.output.match(uploadRegex);
                if (m) return { label: 'Uploading to server', sent: parseFloat(m[1]), total: parseFloat(m[2]), pct: parseFloat(m[3]), rate: parseFloat(m[4] || 0), type: 'upload' };
            }
            if (Number(l.step) === 10) {
                if (l.status === 'success') continue;
                const m = l.output.match(zipRegex);
                if (m) return { label: 'Creating archive', pct: parseFloat(m[1]), type: 'zip' };
            }
        }
        return null;
    })();

    const terminalLines = logs.map(l => {
        const lines = [];
        if (l.command) lines.push({ text: `$ ${l.command}`, cls: 'text-zinc-600' });
        if (l.output)  lines.push({ text: l.output.trim(), cls:
            l.status === 'error'   ? 'text-red-400'    :
            l.status === 'success' ? 'text-emerald-400':
            l.status === 'warning' ? 'text-amber-400'  : 'text-zinc-300' });
        if (!l.command && !l.output) lines.push({ text: '(no output)', cls: 'text-zinc-700' });
        return lines;
    }).flat();

    return (
        <Layout title={`Deployment #${initial.id}`}>

            {/* Info cards row */}
            <div className="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
                <InfoCard label="Application" value={dep.site?.name ?? dep.server?.name ?? '—'} />
                <InfoCard label="Branch" value={dep.branch} mono />
                <InfoCard label="Status">
                    <div className="flex items-center gap-2 flex-wrap">
                        <StatusBadge status={dep.status} />
                        {activeProgress && (
                            <span className="text-xs font-bold text-amber-600">{activeProgress.pct}%</span>
                        )}
                    </div>
                </InfoCard>
                <InfoCard label="Duration" value={dep.duration_seconds ? `${dep.duration_seconds}s` : isLive ? 'running…' : '—'} />
            </div>

            {/* AI audit */}
            {dep.ai_audit_result && (
                <div className="mb-6 bg-amber-50 border border-amber-200 rounded-xl p-4">
                    <h3 className="text-sm font-semibold text-amber-700 mb-1 flex items-center gap-2">
                        <IconWarning className="w-4 h-4" />
                        AI Audit
                    </h3>
                    <p className="text-sm text-slate-700">
                        Risk: <span className="font-bold text-slate-900">{dep.ai_risk_level?.toUpperCase()}</span>
                    </p>
                </div>
            )}

            {/* Phase wizard */}
            <PhaseWizard logs={logs} deploymentStatus={dep.status} />

            {/* Redeploy toolbar (only when done) */}
            {!isLive && (
                <div className="flex items-center gap-2 mb-4 flex-wrap">
                    <span className="text-xs text-slate-400 mr-1">Redeploy as:</span>
                    <button onClick={() => redeploy({ withDatabase: true })} disabled={redeploying}
                        className="flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg bg-white border border-slate-300 hover:border-purple-400 hover:text-purple-600 text-slate-500 disabled:opacity-40 transition-colors">
                        <IconDatabase className="w-3 h-3" /> +DB
                    </button>
                    <button onClick={() => redeploy({ withData: true })} disabled={redeploying}
                        className="flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg bg-white border border-slate-300 hover:border-emerald-400 hover:text-emerald-600 text-slate-500 disabled:opacity-40 transition-colors">
                        <IconDatabase className="w-3 h-3" /> +Data
                    </button>
                    <button onClick={() => redeploy()} disabled={redeploying}
                        className="flex items-center gap-2 px-4 py-1.5 text-sm font-semibold rounded-lg bg-orange-600 hover:bg-orange-500 text-white disabled:opacity-40 transition-colors shadow-sm">
                        <IconPlay className="w-3 h-3" />
                        {redeploying ? 'Queuing…' : 'Redeploy'}
                    </button>
                </div>
            )}

            {/* Progress bar */}
            {activeProgress && (
                <div className="mb-4 bg-white border border-slate-200 rounded-xl p-4 shadow-sm">
                    <div className="flex items-center justify-between mb-2">
                        <span className="text-xs font-medium text-slate-700">
                            {activeProgress.label}…
                        </span>
                        <span className="text-xs font-mono text-slate-500">
                            {activeProgress.type === 'upload'
                                ? `${activeProgress.sent} / ${activeProgress.total} MB — ${activeProgress.rate} MB/s`
                                : `${activeProgress.pct}%`}
                        </span>
                    </div>
                    <div className="w-full bg-slate-100 rounded-full h-2.5 overflow-hidden">
                        <div
                            className="h-full rounded-full bg-gradient-to-r from-amber-500 to-orange-500 transition-all duration-500 ease-out"
                            style={{ width: `${Math.min(activeProgress.pct, 100)}%` }}
                        />
                    </div>
                    <div className="text-right mt-1">
                        <span className="text-sm font-bold text-amber-600">{activeProgress.pct}%</span>
                    </div>
                </div>
            )}

            {/* Terminal — keep dark */}
            <div className="rounded-xl overflow-hidden border border-slate-200 mb-6 shadow-sm">
                {/* macOS-style titlebar — keep dark */}
                <div className="bg-zinc-800 px-4 py-2.5 flex items-center gap-3 border-b border-zinc-700">
                    <div className="flex items-center gap-1.5">
                        <span className="w-3 h-3 rounded-full bg-red-500/60" />
                        <span className="w-3 h-3 rounded-full bg-amber-500/60" />
                        <span className="w-3 h-3 rounded-full bg-emerald-500/60" />
                    </div>
                    <span className="font-mono text-xs text-zinc-500 flex-1">
                        deployment-{initial.id} · {dep.branch}
                    </span>
                    {isLive && (
                        <span className="flex items-center gap-1.5 text-xs text-amber-400">
                            <span className="w-1.5 h-1.5 rounded-full bg-amber-400 animate-pulse" />
                            live
                        </span>
                    )}
                    {!isLive && dep.status === 'success' && (
                        <span className="flex items-center gap-1.5 text-xs text-emerald-400">
                            <IconCheck className="w-3 h-3" />
                            completed
                        </span>
                    )}
                    {!isLive && dep.status === 'failed' && (
                        <span className="flex items-center gap-1.5 text-xs text-red-400">
                            <IconX className="w-3 h-3" />
                            failed
                        </span>
                    )}
                </div>
                {/* Terminal body — keep dark */}
                <div ref={terminalRef}
                    className="bg-zinc-950 p-4 font-mono text-xs h-96 overflow-y-auto">
                    {terminalLines.length === 0 ? (
                        <span className="text-zinc-700">Waiting for deployment output…</span>
                    ) : (
                        terminalLines.map((line, i) => (
                            <div key={i} className={`${line.cls} leading-5`}>
                                <pre className="whitespace-pre-wrap break-words">{line.text}</pre>
                            </div>
                        ))
                    )}
                </div>
            </div>

            {/* Phase breakdown accordion */}
            <h2 className="text-xs font-semibold text-slate-400 uppercase tracking-widest mb-3">Deployment Steps</h2>
            <div className="space-y-2">
                {[1, 2, 3, 4, 5, 6].map(phase => {
                    const phaseLogs = logs.filter(l => Number(l.phase) === phase);
                    if (!phaseLogs.length) return null;
                    const hasError   = phaseLogs.some(l => l.status === 'error');
                    const hasWarning = phaseLogs.some(l => l.status === 'warning');
                    const phaseLabel = PHASES.find(p => p.n === phase)?.label ?? `Phase ${phase}`;
                    const pill = hasError
                        ? 'bg-red-50 text-red-700 border border-red-200'
                        : hasWarning
                        ? 'bg-amber-50 text-amber-700 border border-amber-200'
                        : 'bg-emerald-50 text-emerald-700 border border-emerald-200';
                    return (
                        <details key={phase} className="bg-white border border-slate-200 rounded-xl shadow-sm" open={hasError}>
                            <summary className="px-4 py-3 cursor-pointer text-sm font-medium text-slate-700 flex items-center gap-2 select-none hover:bg-slate-50 rounded-xl transition-colors list-none">
                                <span className="text-slate-400 font-mono text-xs w-5 shrink-0">{phase}</span>
                                <span className="flex-1">Phase {phase}: {phaseLabel}</span>
                                <span className={`text-xs px-2 py-0.5 rounded-full font-medium ${pill}`}>
                                    {hasError ? 'error' : hasWarning ? 'warning' : 'ok'}
                                </span>
                            </summary>
                            <div className="px-4 pb-4 space-y-2 font-mono text-xs border-t border-slate-200 pt-3 bg-slate-50 rounded-b-xl">
                                {phaseLogs.map((log, i) => (
                                    <div key={i} className={
                                        log.status === 'error'   ? 'text-red-600'    :
                                        log.status === 'warning' ? 'text-amber-600'  :
                                        log.status === 'success' ? 'text-emerald-600': 'text-slate-500'}>
                                        {log.command && <div className="text-slate-400 mb-0.5">$ {log.command}</div>}
                                        {log.output  && <pre className="whitespace-pre-wrap break-words">{log.output}</pre>}
                                        {log.ai_diagnosis && (
                                            <div className="mt-1 text-amber-700 text-xs">
                                                AI: {typeof log.ai_diagnosis === 'string'
                                                    ? log.ai_diagnosis
                                                    : JSON.stringify(log.ai_diagnosis, null, 2)}
                                            </div>
                                        )}
                                    </div>
                                ))}
                            </div>
                        </details>
                    );
                })}
            </div>
        </Layout>
    );
}

function InfoCard({ label, value, children, mono }) {
    return (
        <div className="bg-white border border-slate-200 rounded-xl px-4 py-3 shadow-sm">
            <p className="text-slate-400 text-xs font-medium mb-1.5 uppercase tracking-wider">{label}</p>
            {children ?? (
                <p className={`text-sm truncate text-slate-900 font-medium ${mono ? 'font-mono' : ''}`}>{value}</p>
            )}
        </div>
    );
}
