import { useState } from 'react';
import Layout, { IconServer, IconChevron, IconEdit, IconTrash, IconPlus } from '../../Components/Layout';
import { Link, router } from '@inertiajs/react';

function ConfirmModal({ server, onConfirm, onCancel }) {
    if (!server) return null;
    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center">
            <div className="absolute inset-0 bg-slate-900/40 backdrop-blur-sm" onClick={onCancel} />
            <div className="relative bg-white rounded-2xl shadow-2xl border border-slate-200 w-full max-w-sm mx-4 overflow-hidden">
                <div className="h-1 w-full bg-gradient-to-r from-red-400 to-red-600" />
                <div className="p-6">
                    <div className="flex items-start gap-4 mb-5">
                        <span className="w-10 h-10 rounded-xl flex items-center justify-center shrink-0 bg-red-50">
                            <IconTrash className="w-5 h-5 text-red-500" />
                        </span>
                        <div className="pt-0.5">
                            <h3 className="text-base font-semibold text-slate-900 leading-tight">Delete Server</h3>
                            <p className="text-sm text-slate-500 mt-0.5">This cannot be undone.</p>
                        </div>
                    </div>
                    <div className="bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 mb-5">
                        <div className="flex items-center justify-between text-xs">
                            <span className="text-slate-400 font-medium uppercase tracking-wider">Server</span>
                            <span className="font-semibold text-slate-700">{server.name}</span>
                        </div>
                        <div className="flex items-center justify-between text-xs mt-1.5">
                            <span className="text-slate-400 font-medium uppercase tracking-wider">Apps</span>
                            <span className="font-semibold text-slate-700">{server.sites_count ?? 0} will be removed</span>
                        </div>
                    </div>
                    <div className="flex gap-3">
                        <button onClick={onCancel}
                            className="flex-1 px-4 py-2.5 rounded-xl text-sm font-semibold bg-white border border-slate-300 text-slate-700 hover:bg-slate-50 transition-colors">
                            Cancel
                        </button>
                        <button onClick={onConfirm}
                            className="flex-1 px-4 py-2.5 rounded-xl text-sm font-semibold bg-red-600 hover:bg-red-500 text-white transition-colors">
                            Delete
                        </button>
                    </div>
                </div>
            </div>
        </div>
    );
}

export default function ServersIndex({ servers, isAdmin }) {
    const [pendingDelete, setPendingDelete] = useState(null);

    const confirmDelete = () => {
        if (!pendingDelete) return;
        router.delete(`/servers/${pendingDelete.id}`);
        setPendingDelete(null);
    };

    return (
        <Layout title="Servers">
            <ConfirmModal
                server={pendingDelete}
                onConfirm={confirmDelete}
                onCancel={() => setPendingDelete(null)}
            />

            <div className="flex justify-end mb-5">
                <Link
                    href="/servers/create"
                    className="flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg bg-orange-600 hover:bg-orange-500 text-white transition-colors"
                >
                    <IconPlus className="w-3.5 h-3.5" />
                    Add Server
                </Link>
            </div>

            {servers?.length === 0 ? (
                <div className="bg-white border-2 border-dashed border-slate-300 rounded-xl p-14 text-center">
                    <IconServer className="w-10 h-10 text-slate-300 mx-auto mb-4" />
                    <p className="text-slate-600 font-medium mb-1.5">No servers yet</p>
                    <p className="text-slate-400 text-sm mb-6">Add a server to start deploying applications</p>
                    <Link href="/servers/create"
                        className="inline-flex items-center gap-2 bg-orange-600 hover:bg-orange-500 text-white px-5 py-2.5 rounded-lg text-sm font-semibold transition-colors">
                        <IconPlus className="w-4 h-4" />
                        Add Your First Server
                    </Link>
                </div>
            ) : (
                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                    {servers.map(server => (
                        <div key={server.id} className="bg-white border border-slate-200 rounded-xl p-5 hover:border-slate-300 hover:shadow-md transition-all group flex flex-col">
                            <div className="flex items-start justify-between mb-3">
                                <div className="flex items-center gap-2 min-w-0">
                                    <span className={`w-2 h-2 rounded-full shrink-0 mt-0.5 ${server.active ? 'bg-emerald-500' : 'bg-slate-300'}`} />
                                    <Link
                                        href={`/servers/${server.id}`}
                                        className="font-semibold text-slate-900 text-sm truncate hover:text-orange-600 transition-colors"
                                    >
                                        {server.name}
                                    </Link>
                                </div>
                                <span className="text-xs px-2 py-0.5 rounded-md bg-slate-100 border border-slate-200 text-slate-500 shrink-0 ml-2">
                                    {server.panel_type === 'cpanel' ? 'cPanel' : 'aaPanel'}
                                </span>
                            </div>

                            <p className="font-mono text-xs text-slate-400 mb-1 truncate">{server.host}:{server.ssh_port}</p>

                            {isAdmin && server.user && (
                                <p className="text-xs text-slate-400 mb-1">Owner: {server.user.name}</p>
                            )}

                            <p className="text-xs text-slate-400 mb-4 flex-1">
                                <span className="text-orange-600 font-semibold">{server.sites_count ?? 0}</span>{' '}
                                app{server.sites_count !== 1 ? 's' : ''}
                            </p>

                            <div className="border-t border-slate-100 pt-3 flex items-center gap-2">
                                <Link href={`/servers/${server.id}/edit`}
                                    className="p-1.5 rounded-lg text-slate-400 hover:text-slate-700 hover:bg-slate-100 transition-colors"
                                    title="Edit server">
                                    <IconEdit className="w-3.5 h-3.5" />
                                </Link>
                                <button onClick={() => setPendingDelete(server)}
                                    className="p-1.5 rounded-lg text-slate-400 hover:text-red-500 hover:bg-red-50 transition-colors"
                                    title="Delete server">
                                    <IconTrash className="w-3.5 h-3.5" />
                                </button>
                                <Link
                                    href={`/servers/${server.id}`}
                                    className="ml-auto flex items-center gap-1.5 text-xs font-medium text-slate-500 hover:text-orange-600 transition-colors"
                                >
                                    Manage
                                    <IconChevron className="w-3.5 h-3.5" />
                                </Link>
                            </div>
                        </div>
                    ))}
                </div>
            )}
        </Layout>
    );
}
