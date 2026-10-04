import { useState, useEffect } from 'react';
import Layout, { StatusBadge, IconServer, IconRocket, IconPlay, IconPlus, IconEdit, IconTrash, IconDatabase, IconGitBranch, IconExternal, IconCheck, IconX, IconWarning } from '../../Components/Layout';
import { Link, router } from '@inertiajs/react';

const STATUS_DOT = {
    success:     'bg-emerald-500',
    failed:      'bg-red-500',
    running:     'bg-amber-500 animate-pulse',
    pending:     'bg-blue-500',
    rolled_back: 'bg-slate-400',
};

const STATUS_LABEL = {
    success: 'Success', failed: 'Failed', running: 'Running',
    pending: 'Pending', rolled_back: 'Rolled Back',
};

// ─── Confirm Modal ────────────────────────────────────────────────────────────

function ConfirmModal({ action, onConfirm, onCancel }) {
    const [typedName, setTypedName] = useState('');

    // Clear the confirmation box whenever a different action opens the modal,
    // so a previously-typed name can't carry over into the next push.
    useEffect(() => { setTypedName(''); }, [action?.type, action?.site?.id]);

    if (!action) return null;

    const isDeploy = action.type !== 'delete';
    const isWarning = action.type === 'withDatabase';

    // Pushing a local DB over a production site overwrites live rows, so the
    // server requires the site name back (see DeployController::trigger). Mirror
    // that here rather than letting the request fail validation.
    const needsNameConfirm = isWarning && action.site?.environment !== 'staging'
        && action.site?.environment !== 'local';
    const nameConfirmed = !needsNameConfirm || typedName === action.site?.name;

    const iconBg   = isDeploy ? 'bg-orange-50'   : 'bg-red-50';
    const iconColor = isDeploy ? 'text-orange-500' : 'text-red-500';
    const btnClass  = isDeploy
        ? 'bg-orange-600 hover:bg-orange-500 text-white'
        : 'bg-red-600 hover:bg-red-500 text-white';

    const Icon = isDeploy ? IconRocket : IconTrash;

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center">
            {/* Backdrop */}
            <div className="absolute inset-0 bg-slate-900/40 backdrop-blur-sm" onClick={onCancel} />

            {/* Card */}
            <div className="relative bg-white rounded-2xl shadow-2xl border border-slate-200 w-full max-w-sm mx-4 overflow-hidden">
                {/* Top accent bar */}
                <div className={`h-1 w-full ${isDeploy ? 'bg-gradient-to-r from-orange-400 to-orange-600' : 'bg-gradient-to-r from-red-400 to-red-600'}`} />

                <div className="p-6">
                    {/* Icon + title */}
                    <div className="flex items-start gap-4 mb-5">
                        <span className={`w-10 h-10 rounded-xl flex items-center justify-center shrink-0 ${iconBg}`}>
                            <Icon className={`w-5 h-5 ${iconColor}`} />
                        </span>
                        <div className="min-w-0 pt-0.5">
                            <h3 className="text-base font-semibold text-slate-900 leading-tight">
                                {action.label}
                            </h3>
                            {action.description && (
                                <p className="text-sm text-slate-500 mt-0.5">{action.description}</p>
                            )}
                        </div>
                    </div>

                    {/* Site/server info box */}
                    {action.site && (
                        <div className="bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 mb-5 space-y-1.5">
                            <div className="flex items-center justify-between text-xs">
                                <span className="text-slate-400 font-medium uppercase tracking-wider">Site</span>
                                <span className="font-semibold text-slate-700">{action.site.name}</span>
                            </div>
                            {action.server && (
                                <div className="flex items-center justify-between text-xs">
                                    <span className="text-slate-400 font-medium uppercase tracking-wider">Server</span>
                                    <span className="font-semibold text-slate-700">{action.server}</span>
                                </div>
                            )}
                            {action.site.branch && action.type !== 'delete' && (
                                <div className="flex items-center justify-between text-xs">
                                    <span className="text-slate-400 font-medium uppercase tracking-wider">Branch</span>
                                    <span className="font-mono text-slate-700">{action.site.branch}</span>
                                </div>
                            )}
                        </div>
                    )}

                    {/* Warning for DB push */}
                    {isWarning && (
                        <div className="flex items-start gap-2 bg-amber-50 border border-amber-200 rounded-xl px-3 py-2.5 mb-5">
                            <IconWarning className="w-4 h-4 text-amber-500 shrink-0 mt-0.5" />
                            <p className="text-xs text-amber-700">
                                This will overwrite remote database rows with your local data.
                                A backup of the remote database is taken first.
                            </p>
                        </div>
                    )}

                    {/* Type-to-confirm for production DB pushes */}
                    {needsNameConfirm && (
                        <div className="mb-5">
                            <label className="block text-xs font-medium text-slate-600 mb-1.5">
                                Type <span className="font-mono font-semibold text-slate-900">{action.site.name}</span> to confirm
                            </label>
                            <input
                                autoFocus
                                value={typedName}
                                onChange={(e) => setTypedName(e.target.value)}
                                placeholder={action.site.name}
                                className="w-full px-3 py-2 rounded-xl border border-slate-300 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-orange-400 focus:border-orange-400"
                            />
                        </div>
                    )}

                    {/* Buttons */}
                    <div className="flex gap-3">
                        <button
                            onClick={onCancel}
                            className="flex-1 px-4 py-2.5 rounded-xl text-sm font-semibold bg-white border border-slate-300 text-slate-700 hover:bg-slate-50 transition-colors"
                        >
                            Cancel
                        </button>
                        <button
                            onClick={() => onConfirm(typedName)}
                            disabled={!nameConfirmed}
                            className={`flex-1 px-4 py-2.5 rounded-xl text-sm font-semibold transition-colors ${btnClass} disabled:opacity-40 disabled:cursor-not-allowed`}
                        >
                            {isDeploy ? 'Deploy' : 'Remove'}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    );
}

// ─── Page ─────────────────────────────────────────────────────────────────────

export default function ServerShow({ server }) {
    const [testing, setTesting]       = useState(false);
    const [testResult, setTestResult] = useState(null);
    const [deploying, setDeploying]   = useState(null);
    const [pendingAction, setPendingAction] = useState(null);

    const testConnection = async () => {
        setTesting(true);
        setTestResult(null);
        try {
            const res  = await fetch(`/servers/${server.id}/test-connection`, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            const json = await res.json();
            setTestResult({ ok: json.connected ?? json.ok, message: json.message });
        } catch {
            setTestResult({ ok: false, message: 'Request failed — check the server is reachable.' });
        } finally {
            setTesting(false);
        }
    };

    const askDeploy = (site, { withData = false, withDatabase = false } = {}) => {
        let type  = 'deploy';
        let label = 'Deploy';
        let description = 'Build, upload and run migrations on the remote server.';
        if (withDatabase) {
            type = 'withDatabase';
            label = 'Deploy + Push DB';
            description = 'Deploy code and push local database rows to the server.';
        } else if (withData) {
            type = 'withData';
            label = 'Deploy + Seeders';
            description = 'Deploy code and run database seeders on the server.';
        }
        setPendingAction({ type, label, description, site, server: server.name, withData, withDatabase });
    };

    const confirmDeploy = (typedName = '') => {
        if (!pendingAction) return;
        setDeploying(pendingAction.site.id);
        router.post('/deploy', {
            site_id: pendingAction.site.id,
            with_data: pendingAction.withData,
            with_database: pendingAction.withDatabase,
            // Only meaningful for production DB pushes; the server re-checks it.
            confirm_site_name: typedName,
        }, { onFinish: () => setDeploying(null) });
        setPendingAction(null);
    };

    const askDelete = (site) => {
        setPendingAction({
            type: 'delete',
            label: 'Remove Application',
            description: 'This will permanently delete the app record. The files on the server are not removed.',
            site,
        });
    };

    const confirmDelete = () => {
        if (!pendingAction) return;
        router.delete(`/sites/${pendingAction.site.id}`);
        setPendingAction(null);
    };

    const handleConfirm = (typedName) => {
        if (pendingAction?.type === 'delete') confirmDelete();
        else confirmDeploy(typedName);
    };

    const panelLabel = server.panel_type === 'cpanel' ? 'cPanel' : 'aaPanel';

    return (
        <Layout title={server.name}>
            <ConfirmModal
                action={pendingAction}
                onConfirm={handleConfirm}
                onCancel={() => setPendingAction(null)}
            />

            {/* Back link */}
            <div className="mb-5">
                <Link href="/servers" className="text-xs text-slate-400 hover:text-slate-700 transition-colors flex items-center gap-1">
                    ← Back to Servers
                </Link>
            </div>

            {/* Server header card */}
            <div className="bg-white border border-slate-200 rounded-xl px-6 py-5 mb-6 shadow-sm">
                <div className="flex items-start justify-between gap-4">
                    <div>
                        <div className="flex items-center gap-2.5 mb-2">
                            <span className={`w-2.5 h-2.5 rounded-full shrink-0 ${server.active ? 'bg-emerald-500' : 'bg-slate-300'}`} />
                            <h2 className="text-lg font-semibold text-slate-900">{server.name}</h2>
                            <span className="text-xs px-2 py-0.5 rounded-md bg-slate-100 border border-slate-200 text-slate-500">
                                {panelLabel}
                            </span>
                            {!server.active && (
                                <span className="text-xs px-2 py-0.5 rounded-md bg-slate-100 border border-slate-200 text-slate-400">
                                    Inactive
                                </span>
                            )}
                        </div>
                        <p className="font-mono text-xs text-slate-400">
                            {server.ssh_user}@{server.host}:{server.ssh_port}
                        </p>

                        {testResult && (
                            <div className={`mt-2 flex items-center gap-2 text-xs ${testResult.ok ? 'text-emerald-600' : 'text-red-500'}`}>
                                {testResult.ok
                                    ? <IconCheck className="w-3.5 h-3.5 shrink-0" />
                                    : <IconX className="w-3.5 h-3.5 shrink-0" />}
                                {testResult.message}
                            </div>
                        )}
                    </div>

                    <div className="flex items-center gap-2 shrink-0">
                        <button
                            onClick={testConnection}
                            disabled={testing}
                            className="flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg bg-white border border-slate-300 hover:border-slate-400 text-slate-700 hover:text-slate-900 disabled:opacity-50 transition-colors"
                        >
                            {testing ? 'Testing…' : 'Test SSH'}
                        </button>
                        <Link
                            href={`/servers/${server.id}/edit`}
                            className="flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg bg-white border border-slate-300 hover:border-slate-400 text-slate-700 hover:text-slate-900 transition-colors"
                        >
                            <IconEdit className="w-3.5 h-3.5" />
                            Edit Server
                        </Link>
                    </div>
                </div>
            </div>

            {/* Applications header */}
            <div className="flex items-center justify-between mb-4">
                <h2 className="text-xs font-semibold text-slate-400 uppercase tracking-widest">Applications</h2>
                <Link
                    href={`/servers/${server.id}/sites/create`}
                    className="flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg bg-orange-600 hover:bg-orange-500 text-white transition-colors"
                >
                    <IconPlus className="w-3.5 h-3.5" />
                    Add Application
                </Link>
            </div>

            {/* App cards grid */}
            {server.sites?.length === 0 ? (
                <div className="bg-white border-2 border-dashed border-slate-300 rounded-xl p-14 text-center">
                    <IconRocket className="w-10 h-10 text-slate-300 mx-auto mb-4" />
                    <p className="text-slate-600 font-medium mb-1.5">No applications on this server</p>
                    <p className="text-slate-400 text-sm mb-6">Add your first app to start deploying</p>
                    <Link
                        href={`/servers/${server.id}/sites/create`}
                        className="inline-flex items-center gap-2 bg-orange-600 hover:bg-orange-500 text-white px-5 py-2.5 rounded-lg text-sm font-semibold transition-colors"
                    >
                        <IconPlus className="w-4 h-4" />
                        Add Application
                    </Link>
                </div>
            ) : (
                <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
                    {server.sites.map(site => {
                        const lastDeploy = site.deployments?.[0];
                        const isDeploying = deploying === site.id;

                        return (
                            <div
                                key={site.id}
                                className={`bg-white border rounded-xl p-5 flex flex-col transition-all ${
                                    isDeploying
                                        ? 'border-amber-400 bg-amber-50/30 ring-1 ring-amber-400/20 shadow-md'
                                        : 'border-slate-200 hover:border-slate-300 hover:shadow-md'
                                }`}
                            >
                                {/* Card top: name + last deploy status */}
                                <div className="flex items-start justify-between gap-3 mb-3">
                                    <div className="min-w-0">
                                        <div className="flex items-center gap-2 flex-wrap">
                                            <h3 className="font-semibold text-slate-900 text-sm">{site.name}</h3>
                                            {!site.active && (
                                                <span className="text-xs px-1.5 py-0.5 rounded bg-slate-100 text-slate-400 border border-slate-200">
                                                    inactive
                                                </span>
                                            )}
                                        </div>
                                    </div>

                                    {lastDeploy ? (
                                        <Link
                                            href={`/deployments/${lastDeploy.id}`}
                                            className="flex items-center gap-1.5 shrink-0 group"
                                        >
                                            <span className={`w-2 h-2 rounded-full shrink-0 ${STATUS_DOT[lastDeploy.status] ?? 'bg-slate-300'}`} />
                                            <span className="text-xs text-slate-400 group-hover:text-slate-700 transition-colors whitespace-nowrap">
                                                {STATUS_LABEL[lastDeploy.status] ?? lastDeploy.status}
                                            </span>
                                        </Link>
                                    ) : (
                                        <span className="text-xs text-slate-400 shrink-0">No deploys yet</span>
                                    )}
                                </div>

                                {/* Meta info */}
                                <div className="space-y-1.5 mb-4 flex-1">
                                    <p className="font-mono text-xs text-slate-400 truncate" title={site.deploy_path}>
                                        {site.deploy_path}
                                    </p>
                                    <div className="flex items-center gap-3 flex-wrap">
                                        <span className="flex items-center gap-1 text-xs text-slate-500">
                                            <IconGitBranch className="w-3 h-3" />
                                            <span className="font-mono">{site.branch}</span>
                                        </span>
                                        {site.app_url && (
                                            <a
                                                href={site.app_url}
                                                target="_blank"
                                                rel="noreferrer"
                                                onClick={e => e.stopPropagation()}
                                                className="flex items-center gap-1 text-xs text-orange-600 hover:text-orange-500 transition-colors truncate max-w-[200px]"
                                            >
                                                <IconExternal className="w-3 h-3 shrink-0" />
                                                {site.app_url.replace(/^https?:\/\//, '')}
                                            </a>
                                        )}
                                    </div>
                                </div>

                                {/* Action row */}
                                <div className="border-t border-slate-100 pt-3 flex items-center gap-2">
                                    <Link
                                        href={`/sites/${site.id}/edit`}
                                        className="p-1.5 rounded-lg text-slate-400 hover:text-slate-700 hover:bg-slate-100 transition-colors"
                                        title="Edit application"
                                    >
                                        <IconEdit className="w-3.5 h-3.5" />
                                    </Link>
                                    <button
                                        onClick={() => askDelete(site)}
                                        className="p-1.5 rounded-lg text-slate-400 hover:text-red-500 hover:bg-red-50 transition-colors"
                                        title="Remove application"
                                    >
                                        <IconTrash className="w-3.5 h-3.5" />
                                    </button>

                                    <div className="w-px h-4 bg-slate-200 mx-0.5" />

                                    <button
                                        onClick={() => askDeploy(site, { withDatabase: true })}
                                        disabled={isDeploying}
                                        title="Deploy + push local DB rows to remote"
                                        className="flex items-center gap-1.5 px-2.5 py-1.5 text-xs font-medium rounded-lg bg-white border border-slate-300 hover:border-purple-400 hover:text-purple-600 text-slate-500 disabled:opacity-40 transition-colors"
                                    >
                                        <IconDatabase className="w-3 h-3" />
                                        +DB
                                    </button>
                                    <button
                                        onClick={() => askDeploy(site, { withData: true })}
                                        disabled={isDeploying}
                                        title="Deploy + run seeders"
                                        className="flex items-center gap-1.5 px-2.5 py-1.5 text-xs font-medium rounded-lg bg-white border border-slate-300 hover:border-emerald-400 hover:text-emerald-600 text-slate-500 disabled:opacity-40 transition-colors"
                                    >
                                        <IconDatabase className="w-3 h-3" />
                                        +Data
                                    </button>

                                    <button
                                        onClick={() => askDeploy(site)}
                                        disabled={isDeploying}
                                        title="Deploy code only"
                                        className="ml-auto flex items-center gap-2 px-4 py-1.5 text-sm font-semibold rounded-lg bg-orange-600 hover:bg-orange-500 text-white disabled:opacity-40 transition-colors shadow-sm"
                                    >
                                        <IconPlay className="w-3 h-3" />
                                        {isDeploying ? 'Queuing…' : 'Deploy'}
                                    </button>
                                </div>
                            </div>
                        );
                    })}
                </div>
            )}
        </Layout>
    );
}
