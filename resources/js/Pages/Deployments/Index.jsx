import Layout, { StatusBadge, IconGitBranch } from '../../Components/Layout';
import { Link, router } from '@inertiajs/react';

const RISK_BADGE = {
    high:   'bg-red-50 text-red-700 border border-red-200',
    medium: 'bg-amber-50 text-amber-700 border border-amber-200',
    low:    'bg-emerald-50 text-emerald-700 border border-emerald-200',
};

const ACCENT = {
    success:     'bg-emerald-500',
    failed:      'bg-red-500',
    running:     'bg-amber-500 animate-pulse',
    pending:     'bg-blue-500',
    rolled_back: 'bg-slate-400',
};

export default function DeploymentsIndex({ deployments, isAdmin }) {
    return (
        <Layout title="Deployments">
            <div className="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm">
                <table className="w-full text-sm">
                    <thead>
                        <tr className="border-b border-slate-200">
                            <th className="w-1 pl-4 py-3" />
                            <th className="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">#</th>
                            <th className="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">App / Server</th>
                            <th className="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Branch</th>
                            <th className="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Status</th>
                            <th className="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">AI Risk</th>
                            {isAdmin && <th className="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">User</th>}
                            <th className="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Duration</th>
                            <th className="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        {deployments?.data?.length === 0 && (
                            <tr>
                                <td colSpan={isAdmin ? 9 : 8} className="px-4 py-10 text-center text-slate-400 text-sm">
                                    No deployments yet.
                                </td>
                            </tr>
                        )}
                        {deployments?.data?.map(dep => (
                            <tr
                                key={dep.id}
                                onClick={() => router.visit(`/deployments/${dep.id}`)}
                                className="border-t border-slate-100 hover:bg-slate-50 transition-colors cursor-pointer group"
                            >
                                {/* Status accent bar */}
                                <td className="pl-4 pr-2 py-3.5 w-1">
                                    <span className={`block w-0.5 h-8 rounded-full ${ACCENT[dep.status] ?? 'bg-slate-300'}`} />
                                </td>
                                <td className="px-4 py-3.5">
                                    <span className="text-orange-600 group-hover:text-orange-500 font-semibold font-mono text-xs transition-colors">
                                        #{dep.id}
                                    </span>
                                </td>
                                <td className="px-4 py-3.5 text-slate-700 font-medium">
                                    {dep.site?.name ?? dep.server?.name ?? '—'}
                                </td>
                                <td className="px-4 py-3.5">
                                    <span className="inline-flex items-center gap-1 font-mono text-xs bg-slate-100 border border-slate-200 px-1.5 py-0.5 rounded text-slate-500">
                                        <IconGitBranch className="w-3 h-3" />
                                        {dep.branch}
                                    </span>
                                </td>
                                <td className="px-4 py-3.5">
                                    <StatusBadge status={dep.status} />
                                </td>
                                <td className="px-4 py-3.5">
                                    {dep.ai_risk_level && (
                                        <span className={`text-xs px-2 py-0.5 rounded-full font-medium ${RISK_BADGE[dep.ai_risk_level] ?? 'bg-slate-100 text-slate-500'}`}>
                                            {dep.ai_risk_level}
                                        </span>
                                    )}
                                </td>
                                {isAdmin && (
                                    <td className="px-4 py-3.5 text-xs text-slate-400">
                                        {dep.user?.name ?? dep.triggered_by ?? '—'}
                                    </td>
                                )}
                                <td className="px-4 py-3.5 text-xs text-slate-400 font-mono">
                                    {dep.duration_seconds ? `${dep.duration_seconds}s` : '—'}
                                </td>
                                <td className="px-4 py-3.5 text-xs text-slate-400 whitespace-nowrap">
                                    {new Date(dep.created_at).toLocaleString()}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            {/* Pagination */}
            {deployments?.links?.length > 3 && (
                <div className="flex items-center justify-center gap-1 mt-5 flex-wrap">
                    {deployments.links.map((link, i) => (
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
