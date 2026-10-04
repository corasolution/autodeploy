import { Link, router } from '@inertiajs/react';
import Layout, { StatusBadge } from '../../Components/Layout';

const PHASE_LABELS = {
    1: 'Pre-flight', 2: 'Build', 3: 'Upload', 4: 'Remote', 5: 'Health', 6: 'Rollback',
};

export default function LogsIndex({ logs, filters }) {
    const setFilter = (key, value) => {
        router.get('/logs', { ...filters, [key]: value || undefined }, {
            preserveState: true, replace: true,
        });
    };

    const sel = 'bg-white border border-slate-300 rounded-lg px-3 py-1.5 text-sm text-slate-700 focus:outline-none focus:border-orange-400 transition-colors';
    const inp = 'bg-white border border-slate-300 rounded-lg px-3 py-1.5 text-sm text-slate-700 placeholder-slate-400 focus:outline-none focus:border-orange-400 transition-colors w-36';

    return (
        <Layout title="Deploy Logs">
            {/* Filter toolbar */}
            <div className="bg-white border border-slate-200 rounded-xl px-4 py-3 mb-4 flex flex-wrap items-center gap-3 shadow-sm">
                <span className="text-xs font-medium text-slate-400 uppercase tracking-wider shrink-0">Filter</span>

                <select className={sel} value={filters.status ?? ''} onChange={e => setFilter('status', e.target.value)}>
                    <option value="">All statuses</option>
                    <option value="success">Success</option>
                    <option value="error">Error</option>
                    <option value="warning">Warning</option>
                    <option value="info">Info</option>
                </select>

                <select className={sel} value={filters.phase ?? ''} onChange={e => setFilter('phase', e.target.value)}>
                    <option value="">All phases</option>
                    {Object.entries(PHASE_LABELS).map(([n, label]) => (
                        <option key={n} value={n}>{n} — {label}</option>
                    ))}
                </select>

                <input
                    type="number"
                    placeholder="Deployment #"
                    className={inp}
                    value={filters.deployment_id ?? ''}
                    onChange={e => setFilter('deployment_id', e.target.value)}
                />

                {(filters.status || filters.phase || filters.deployment_id) && (
                    <button
                        onClick={() => router.get('/logs', {}, { replace: true })}
                        className="text-xs text-slate-400 hover:text-slate-700 transition-colors"
                    >
                        Clear filters
                    </button>
                )}

                <span className="ml-auto text-xs text-slate-400 tabular-nums">
                    {logs.meta?.total ?? 0} entries
                </span>
            </div>

            {/* Table */}
            <div className="bg-white rounded-xl border border-slate-200 overflow-x-auto shadow-sm">
                <table className="w-full text-sm">
                    <thead>
                        <tr className="border-b border-slate-200">
                            <th className="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">#</th>
                            <th className="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Deploy</th>
                            <th className="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Server</th>
                            <th className="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Phase</th>
                            <th className="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Step</th>
                            <th className="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Status</th>
                            <th className="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Command</th>
                            <th className="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Output</th>
                            <th className="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Exit</th>
                            <th className="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        {logs.data?.length === 0 && (
                            <tr>
                                <td colSpan={10} className="px-4 py-10 text-center text-slate-400 text-sm">
                                    No logs found.
                                </td>
                            </tr>
                        )}
                        {logs.data?.map(log => (
                            <tr key={log.id} className="border-t border-slate-100 hover:bg-slate-50 transition-colors">
                                <td className="px-4 py-2.5 text-slate-400 text-xs font-mono">{log.id}</td>
                                <td className="px-4 py-2.5">
                                    <Link href={`/deployments/${log.deployment_id}`}
                                        className="text-orange-600 hover:text-orange-500 font-semibold text-xs font-mono">
                                        #{log.deployment_id}
                                    </Link>
                                </td>
                                <td className="px-4 py-2.5 text-slate-500 text-xs">
                                    {log.deployment?.server?.name ?? '—'}
                                </td>
                                <td className="px-4 py-2.5 text-xs">
                                    <span className="font-mono text-slate-500">{log.phase}</span>
                                    {PHASE_LABELS[log.phase] && (
                                        <span className="text-slate-400 ml-1">— {PHASE_LABELS[log.phase]}</span>
                                    )}
                                </td>
                                <td className="px-4 py-2.5 text-xs text-slate-400 font-mono">{log.step}</td>
                                <td className="px-4 py-2.5">
                                    <StatusBadge status={log.status} />
                                </td>
                                <td className="px-4 py-2.5 max-w-[180px]">
                                    {log.command ? (
                                        <span className="font-mono text-xs bg-slate-100 border border-slate-200 px-1.5 py-0.5 rounded text-slate-600 block truncate"
                                            title={log.command}>
                                            {log.command.length > 60 ? log.command.slice(0, 60) + '…' : log.command}
                                        </span>
                                    ) : <span className="text-slate-300">—</span>}
                                </td>
                                <td className="px-4 py-2.5 max-w-[220px]">
                                    {log.output ? (
                                        <span className="font-mono text-xs bg-slate-100 border border-slate-200 px-1.5 py-0.5 rounded text-slate-600 block truncate"
                                            title={log.output}>
                                            {log.output.length > 80 ? log.output.slice(0, 80) + '…' : log.output}
                                        </span>
                                    ) : <span className="text-slate-300">—</span>}
                                </td>
                                <td className="px-4 py-2.5 text-xs text-center">
                                    {log.exit_code !== null ? (
                                        <span className={log.exit_code === 0 ? 'text-emerald-600' : 'text-red-500'}>
                                            {log.exit_code}
                                        </span>
                                    ) : <span className="text-slate-300">—</span>}
                                </td>
                                <td className="px-4 py-2.5 text-xs text-slate-400 whitespace-nowrap">
                                    {log.logged_at ? new Date(log.logged_at).toLocaleString() : '—'}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            {/* Pagination */}
            {logs.links?.length > 3 && (
                <div className="flex items-center justify-center gap-1 mt-5 flex-wrap">
                    {logs.links.map((link, i) => (
                        link.url ? (
                            <Link key={i} href={link.url}
                                className={`px-3 py-1.5 rounded-lg text-xs font-medium border transition-colors ${
                                    link.active
                                        ? 'bg-orange-600 text-white border-orange-600'
                                        : 'bg-white text-slate-600 border-slate-300 hover:border-slate-400 hover:text-slate-900'
                                }`}
                                dangerouslySetInnerHTML={{ __html: link.label }}
                            />
                        ) : (
                            <span key={i}
                                className="px-3 py-1.5 rounded-lg text-xs font-medium border bg-white text-slate-300 border-slate-200 cursor-not-allowed"
                                dangerouslySetInnerHTML={{ __html: link.label }}
                            />
                        )
                    ))}
                </div>
            )}
        </Layout>
    );
}
