import { Link, router, usePage } from '@inertiajs/react';

// ─── Icons ───────────────────────────────────────────────────────────────────

export const IconServer = ({ className }) => (
    <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" strokeLinejoin="round">
        <rect x="2" y="3" width="20" height="7" rx="2" />
        <rect x="2" y="14" width="20" height="7" rx="2" />
        <circle cx="6" cy="6.5" r="1" fill="currentColor" stroke="none" />
        <circle cx="6" cy="17.5" r="1" fill="currentColor" stroke="none" />
    </svg>
);

export const IconRocket = ({ className }) => (
    <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" strokeLinejoin="round">
        <path d="M12 2C12 2 7 7 7 13c0 2.8 1.1 5 2.5 6.5" />
        <path d="M12 2c0 0 5 5 5 11 0 2.8-1.1 5-2.5 6.5" />
        <circle cx="12" cy="10" r="2" />
        <path d="M7 13c-2 1-3.5 3-3.5 3l2.5 1 1 2.5s2-1.5 3-3.5" />
        <path d="M17 13c2 1 3.5 3 3.5 3l-2.5 1-1 2.5s-2-1.5-3-3.5" />
    </svg>
);

export const IconTerminal = ({ className }) => (
    <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" strokeLinejoin="round">
        <polyline points="4 17 10 11 4 5" />
        <line x1="12" y1="19" x2="20" y2="19" />
    </svg>
);

export const IconLogs = ({ className }) => (
    <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" strokeLinejoin="round">
        <line x1="4" y1="6" x2="20" y2="6" />
        <line x1="4" y1="12" x2="16" y2="12" />
        <line x1="4" y1="18" x2="12" y2="18" />
    </svg>
);

export const IconUsers = ({ className }) => (
    <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" strokeLinejoin="round">
        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" />
        <circle cx="9" cy="7" r="4" />
        <path d="M23 21v-2a4 4 0 0 0-3-3.87" />
        <path d="M16 3.13a4 4 0 0 1 0 7.75" />
    </svg>
);

export const IconChevron = ({ className }) => (
    <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
        <polyline points="9 18 15 12 9 6" />
    </svg>
);

export const IconPlay = ({ className }) => (
    <svg className={className} viewBox="0 0 24 24" fill="currentColor">
        <polygon points="5 3 19 12 5 21 5 3" />
    </svg>
);

export const IconPlus = ({ className }) => (
    <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round">
        <line x1="12" y1="5" x2="12" y2="19" />
        <line x1="5" y1="12" x2="19" y2="12" />
    </svg>
);

export const IconEdit = ({ className }) => (
    <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" strokeLinejoin="round">
        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" />
        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z" />
    </svg>
);

export const IconTrash = ({ className }) => (
    <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" strokeLinejoin="round">
        <polyline points="3 6 5 6 21 6" />
        <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6" />
        <path d="M10 11v6M14 11v6" />
        <path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2" />
    </svg>
);

export const IconCheck = ({ className }) => (
    <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
        <polyline points="20 6 9 17 4 12" />
    </svg>
);

export const IconX = ({ className }) => (
    <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round">
        <line x1="18" y1="6" x2="6" y2="18" />
        <line x1="6" y1="6" x2="18" y2="18" />
    </svg>
);

export const IconWarning = ({ className }) => (
    <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" strokeLinejoin="round">
        <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" />
        <line x1="12" y1="9" x2="12" y2="13" />
        <line x1="12" y1="17" x2="12.01" y2="17" />
    </svg>
);

export const IconDatabase = ({ className }) => (
    <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" strokeLinejoin="round">
        <ellipse cx="12" cy="5" rx="9" ry="3" />
        <path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3" />
        <path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5" />
    </svg>
);

export const IconLogout = ({ className }) => (
    <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" strokeLinejoin="round">
        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
        <polyline points="16 17 21 12 16 7" />
        <line x1="21" y1="12" x2="9" y2="12" />
    </svg>
);

export const IconGitBranch = ({ className }) => (
    <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" strokeLinejoin="round">
        <line x1="6" y1="3" x2="6" y2="15" />
        <circle cx="18" cy="6" r="3" />
        <circle cx="6" cy="18" r="3" />
        <path d="M18 9a9 9 0 0 1-9 9" />
    </svg>
);

export const IconExternal = ({ className }) => (
    <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" strokeLinejoin="round">
        <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6" />
        <polyline points="15 3 21 3 21 9" />
        <line x1="10" y1="14" x2="21" y2="3" />
    </svg>
);

export const IconDashboard = ({ className }) => (
    <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" strokeLinejoin="round">
        <rect x="3" y="3" width="7" height="7" rx="1" />
        <rect x="14" y="3" width="7" height="7" rx="1" />
        <rect x="3" y="14" width="7" height="7" rx="1" />
        <rect x="14" y="14" width="7" height="7" rx="1" />
    </svg>
);

// ─── StatusBadge (shared across pages) ───────────────────────────────────────

export function StatusBadge({ status }) {
    const map = {
        success:     { dot: 'bg-emerald-500',             text: 'text-emerald-700', bg: 'bg-emerald-50',  label: 'Success'     },
        failed:      { dot: 'bg-red-500',                 text: 'text-red-700',     bg: 'bg-red-50',      label: 'Failed'      },
        running:     { dot: 'bg-amber-500 animate-pulse', text: 'text-amber-700',   bg: 'bg-amber-50',    label: 'Running'     },
        pending:     { dot: 'bg-blue-500',                text: 'text-blue-700',    bg: 'bg-blue-50',     label: 'Pending'     },
        rolled_back: { dot: 'bg-slate-400',               text: 'text-slate-600',   bg: 'bg-slate-100',   label: 'Rolled Back' },
    };
    const s = map[status] ?? map.rolled_back;
    return (
        <span className={`inline-flex items-center gap-1.5 text-xs font-medium px-2.5 py-1 rounded-full ${s.bg} ${s.text}`}>
            <span className={`w-1.5 h-1.5 rounded-full shrink-0 ${s.dot}`} />
            {s.label}
        </span>
    );
}

// ─── Layout ──────────────────────────────────────────────────────────────────

export default function Layout({ children, title }) {
    const { url, props } = usePage();
    const user = props.auth?.user;

    const logout = (e) => {
        e.preventDefault();
        router.post('/logout');
    };

    const navItem = (href, label, Icon) => {
        const active = url === href || (href !== '/' && url.startsWith(href + '/')) || (href !== '/' && url.startsWith(href + '?'));
        return (
            <Link
                href={href}
                className={`flex items-center gap-3 px-4 py-2.5 rounded-lg mx-2 text-sm font-medium transition-colors ${
                    active
                        ? 'bg-orange-50 text-orange-600'
                        : 'text-slate-600 hover:text-slate-900 hover:bg-slate-50'
                }`}
            >
                <Icon className={`w-4 h-4 shrink-0 ${active ? 'text-orange-600' : 'text-slate-400'}`} />
                {label}
            </Link>
        );
    };

    return (
        <div className="min-h-screen bg-slate-50 text-slate-900">
            {/* Sidebar */}
            <aside className="fixed inset-y-0 left-0 w-60 bg-white border-r border-slate-200 flex flex-col z-30">
                {/* Logo */}
                <div className="flex items-center gap-3 px-5 py-5 border-b border-slate-200 shrink-0">
                    <span className="w-8 h-8 rounded-xl bg-gradient-to-br from-orange-400 to-orange-600 flex items-center justify-center text-white font-bold text-sm shadow-lg shadow-orange-500/25 shrink-0">
                        A
                    </span>
                    <div>
                        <p className="font-semibold text-slate-900 text-sm tracking-tight leading-tight">AutoPilot</p>
                        <p className="text-xs text-slate-400 leading-tight">Deploy</p>
                    </div>
                </div>

                {/* Nav */}
                <nav className="flex-1 overflow-y-auto py-4">
                    <p className="text-xs font-semibold text-slate-400 uppercase tracking-widest px-6 mb-2">
                        Navigation
                    </p>
                    <div className="space-y-0.5">
                        {navItem('/', 'Dashboard', IconDashboard)}
                        {navItem('/servers', 'Servers', IconServer)}
                        {navItem('/deployments', 'Deployments', IconRocket)}
                        {navItem('/logs', 'Logs', IconLogs)}
                        {user?.role === 'admin' && navItem('/users', 'Users', IconUsers)}
                    </div>
                </nav>

                {/* User section */}
                {user && (
                    <div className="border-t border-slate-200 p-4 shrink-0">
                        <div className="flex items-center gap-3">
                            <span className="w-8 h-8 rounded-full bg-slate-200 flex items-center justify-center text-slate-600 text-xs font-bold uppercase shrink-0">
                                {user.name?.charAt(0) ?? '?'}
                            </span>
                            <div className="flex-1 min-w-0">
                                <p className="text-sm font-medium text-slate-900 truncate leading-tight">{user.name}</p>
                                <p className={`text-xs leading-tight ${user.role === 'admin' ? 'text-orange-600' : 'text-slate-400'}`}>
                                    {user.role}
                                </p>
                            </div>
                            <button
                                onClick={logout}
                                title="Sign out"
                                className="text-slate-400 hover:text-red-500 transition-colors p-1 shrink-0"
                            >
                                <IconLogout className="w-4 h-4" />
                            </button>
                        </div>
                    </div>
                )}
            </aside>

            {/* Main content */}
            <div className="ml-60 min-h-screen flex flex-col">
                {title && (
                    <header className="h-14 border-b border-slate-200 flex items-center px-8 shrink-0 bg-white sticky top-0 z-20">
                        <h1 className="text-base font-semibold text-slate-900 tracking-tight">{title}</h1>
                    </header>
                )}
                <main className="flex-1 px-8 py-6">
                    {children}
                </main>
            </div>
        </div>
    );
}
