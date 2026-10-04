import Layout, { StatusBadge, IconServer, IconRocket, IconCheck, IconChevron, IconGitBranch } from '../Components/Layout';
import { Link } from '@inertiajs/react';

function StatCard({ label, value, accentColor, Icon, subLabel }) {
    const borders = {
        orange:  'border-l-orange-500',
        amber:   'border-l-amber-500',
        emerald: 'border-l-emerald-500',
    };
    return (
        <div className={`bg-white border border-slate-200 border-l-2 ${borders[accentColor] ?? borders.orange} rounded-xl px-5 py-4 shadow-sm`}>
            <div className="flex items-center justify-between mb-3">
                <p className="text-xs font-medium text-slate-500 uppercase tracking-wider">{label}</p>
                <Icon className="w-4 h-4 text-slate-400" />
            </div>
            <p className="text-3xl font-bold text-slate-900 tabular-nums">{value}</p>
            {subLabel && <p className="text-xs text-slate-400 mt-1">{subLabel}</p>}
        </div>
    );
}

function ServerCard({ server }) {
    const panelLabel = server.panel_type === 'cpanel' ? 'cPanel' : 'aaPanel';
    return (
        <Link
            href={`/servers/${server.id}`}
            className="bg-white border border-slate-200 rounded-xl p-4 hover:border-slate-300 hover:shadow-md transition-all group block"
        >
            <div className="flex items-start justify-between mb-3">
                <div className="flex items-center gap-2 min-w-0">
                    <span className={`w-2 h-2 rounded-full shrink-0 mt-0.5 ${server.active ? 'bg-emerald-500' : 'bg-slate-300'}`} />
                    <span className="font-semibold text-slate-900 text-sm truncate group-hover:text-slate-900 transition-colors">
                        {server.name}
                    </span>
                </div>
                <span className="text-xs px-2 py-0.5 rounded-md bg-slate-100 border border-slate-200 text-slate-500 shrink-0 ml-2">
                    {panelLabel}
                </span>
            </div>
            <p className="font-mono text-xs text-slate-400 mb-3 truncate">{server.host}</p>
            <div className="flex items-center justify-between">
                <span className="text-xs text-slate-500">
                    <span className="text-orange-600 font-semibold">{server.sites_count ?? 0}</span>{' '}
                    app{server.sites_count !== 1 ? 's' : ''}
                </span>
                <span className="text-xs text-slate-400 group-hover:text-orange-600 transition-colors flex items-center gap-1">
                    Manage
                    <IconChevron className="w-3 h-3" />
                </span>
            </div>
        </Link>
    );
}

const STATUS_DOT = {
    success:     'bg-emerald-500',
    failed:      'bg-red-500',
    running:     'bg-amber-500 animate-pulse',
    pending:     'bg-blue-500',
    rolled_back: 'bg-slate-400',
};

export default function Dashboard({ servers, recentDeployments }) {
    const running = recentDeployments?.filter(d => d.status === 'running').length ?? 0;
    const successful = recentDeployments?.filter(d => d.status === 'success').length ?? 0;
    const failed = recentDeployments?.filter(d => d.status === 'failed').length ?? 0;

    return (
        <Layout title="Dashboard">
            {/* Stat cards */}
            <div className="grid grid-cols-1 md:grid-cols-3 gap-4 mb-8">
                <StatCard
                    label="Total Servers"
                    value={servers?.length ?? 0}
                    accentColor="orange"
                    Icon={IconServer}
                    subLabel={`${servers?.filter(s => s.active).length ?? 0} active`}
                />
                <StatCard
                    label="Active Deployments"
                    value={running}
                    accentColor="amber"
                    Icon={IconRocket}
                    subLabel={running === 0 ? 'All quiet' : `${running} in progress`}
                />
                <StatCard
                    label="Successful Today"
                    value={successful}
                    accentColor="emerald"
                    Icon={IconCheck}
                    subLabel={failed > 0 ? `${failed} failed` : 'No failures'}
                />
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-5 gap-6">
                {/* Servers */}
                <section className="lg:col-span-2">
                    <div className="flex items-center justify-between mb-3">
                        <h2 className="text-xs font-semibold text-slate-400 uppercase tracking-widest">Servers</h2>
                        <Link
                            href="/servers/create"
                            className="text-xs px-3 py-1.5 rounded-lg bg-orange-600 hover:bg-orange-500 text-white font-medium transition-colors"
                        >
                            + Add Server
                        </Link>
                    </div>

                    {servers?.length === 0 ? (
                        <div className="bg-white border-2 border-dashed border-slate-300 rounded-xl p-8 text-center">
                            <IconServer className="w-8 h-8 text-slate-300 mx-auto mb-3" />
                            <p className="text-slate-500 text-sm mb-3">No servers yet</p>
                            <Link href="/servers/create" className="text-xs text-orange-600 hover:text-orange-500">
                                Add your first server →
                            </Link>
                        </div>
                    ) : (
                        <div className="space-y-3">
                            {servers.map(server => (
                                <ServerCard key={server.id} server={server} />
                            ))}
                        </div>
                    )}
                </section>

                {/* Recent Deployments — timeline */}
                <section className="lg:col-span-3">
                    <div className="flex items-center justify-between mb-3">
                        <h2 className="text-xs font-semibold text-slate-400 uppercase tracking-widest">Recent Deployments</h2>
                        <Link href="/deployments" className="text-xs text-orange-600 hover:text-orange-500 transition-colors">
                            View all →
                        </Link>
                    </div>

                    {recentDeployments?.length === 0 ? (
                        <div className="bg-white border-2 border-dashed border-slate-300 rounded-xl p-8 text-center">
                            <IconRocket className="w-8 h-8 text-slate-300 mx-auto mb-3" />
                            <p className="text-slate-500 text-sm">No deployments yet</p>
                        </div>
                    ) : (
                        <div className="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm">
                            <div className="relative">
                                {recentDeployments?.map((dep, i) => (
                                    <Link
                                        key={dep.id}
                                        href={`/deployments/${dep.id}`}
                                        className={`flex items-center gap-4 px-5 py-3.5 hover:bg-slate-50 transition-colors group ${i > 0 ? 'border-t border-slate-100' : ''}`}
                                    >
                                        {/* Status dot */}
                                        <span className={`w-2 h-2 rounded-full shrink-0 ${STATUS_DOT[dep.status] ?? 'bg-slate-300'}`} />

                                        {/* Deploy ID */}
                                        <span className="font-mono text-xs text-slate-400 w-10 shrink-0">#{dep.id}</span>

                                        {/* App / Server name */}
                                        <span className="text-slate-700 text-sm font-medium truncate flex-1">
                                            {dep.site?.name ?? dep.server?.name ?? '—'}
                                        </span>

                                        {/* Branch chip */}
                                        <span className="hidden sm:flex items-center gap-1 font-mono text-xs text-slate-500 bg-slate-100 border border-slate-200 px-2 py-0.5 rounded shrink-0">
                                            <IconGitBranch className="w-3 h-3" />
                                            {dep.branch}
                                        </span>

                                        {/* Status badge */}
                                        <StatusBadge status={dep.status} />
                                    </Link>
                                ))}
                            </div>
                        </div>
                    )}
                </section>
            </div>
        </Layout>
    );
}
