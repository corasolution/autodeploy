import { useState } from 'react';
import Layout from '../../Components/Layout';
import { useForm, Link } from '@inertiajs/react';

const DB_PRESETS = {
    mysql: { connection: 'mysql', port: '3306' },
    pgsql: { connection: 'pgsql', port: '5432' },
};

const ENV_TEMPLATE = (appUrl, db = 'mysql') => {
    const preset = DB_PRESETS[db] || DB_PRESETS.mysql;
    return `APP_NAME=Laravel
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=${appUrl || 'https://yourdomain.com'}

DB_CONNECTION=${preset.connection}
DB_HOST=127.0.0.1
DB_PORT=${preset.port}
DB_DATABASE=your_database
DB_USERNAME=your_user
DB_PASSWORD=your_password

CACHE_STORE=file
SESSION_DRIVER=file
QUEUE_CONNECTION=database

MAIL_MAILER=smtp
MAIL_HOST=
MAIL_PORT=587
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_FROM_ADDRESS=noreply@yourdomain.com
MAIL_FROM_NAME="\${APP_NAME}"
`;
};

const inp = 'w-full bg-white border border-slate-300 rounded-lg px-3 py-2 text-sm text-slate-900 placeholder-slate-400 focus:outline-none focus:border-orange-400 focus:ring-1 focus:ring-orange-200 transition-colors';

function Field({ label, hint, error, children }) {
    return (
        <div>
            <label className="block text-xs font-medium text-slate-600 mb-1.5">{label}</label>
            {children}
            {hint && !error && <p className="text-slate-400 text-xs mt-1">{hint}</p>}
            {error && <p className="text-red-500 text-xs mt-1">{error}</p>}
        </div>
    );
}

function SectionCard({ title, children }) {
    return (
        <div className="bg-white border border-slate-200 rounded-xl p-4 shadow-sm">
            {title && <p className="text-xs font-semibold text-slate-400 uppercase tracking-widest mb-3">{title}</p>}
            <div className="space-y-3">{children}</div>
        </div>
    );
}

export default function SiteCreate({ server }) {
    const [showAdvanced, setShowAdvanced] = useState(false);
    const [showEnv, setShowEnv]           = useState(false);
    const [dbType, setDbType]             = useState('mysql');
    const [sourceMode, setSourceMode]     = useState('local');

    const pathPrefix = server.panel_type === 'cpanel'
        ? `/home/${server.ssh_user}/public_html/`
        : '/www/wwwroot/';

    const { data, setData, post, processing, errors } = useForm({
        name:                 '',
        deploy_path:          pathPrefix,
        source_path:          '',
        repo_url:             '',
        branch:               'main',
        run_seeders:          false,
        pre_migrate_commands: '',
        php_binary:           'php',
        app_url:              '',
        env_content:          '',
        active:               true,
    });

    const set = (field) => (e) => setData(field, e.target.value);

    const handleName = (name) => {
        const slug = name.toLowerCase().replace(/[^a-z0-9]/g, '');
        setData((prev) => ({
            ...prev,
            name,
            deploy_path: slug ? pathPrefix + slug : pathPrefix,
        }));
    };

    const handleSourceMode = (mode) => {
        setSourceMode(mode);
        if (mode === 'local')  setData('repo_url', '');
        if (mode === 'git')    setData('source_path', '');
        if (mode === 'remote') { setData('source_path', ''); setData('repo_url', ''); }
    };

    const loadTemplate = (db) => {
        setData('env_content', ENV_TEMPLATE(data.app_url, db || dbType));
    };

    const submit = (e) => {
        e.preventDefault();
        post(`/servers/${server.id}/sites`);
    };

    return (
        <Layout title={`Add App — ${server.name}`}>
            <div className="max-w-3xl">
                {/* Breadcrumb */}
                <div className="flex items-center gap-2 text-xs text-slate-400 mb-4">
                    <Link href={`/servers/${server.id}`} className="hover:text-orange-600 transition-colors">{server.name}</Link>
                    <span className="text-slate-300">›</span>
                    <span className="text-slate-600">Add Application</span>
                </div>

                {/* Server context */}
                <div className="bg-white border border-slate-200 rounded-xl px-4 py-3 mb-5 flex items-center gap-3 shadow-sm">
                    <span className="text-xs text-slate-400">Server:</span>
                    <span className="font-mono text-xs text-slate-700">{server.ssh_user}@{server.host}</span>
                    <span className="text-xs px-2 py-0.5 rounded-md bg-slate-100 border border-slate-200 text-slate-500">
                        {server.panel_type === 'cpanel' ? 'cPanel' : 'aaPanel'}
                    </span>
                </div>

                <form onSubmit={submit} className="space-y-4">
                    {/* Identity */}
                    <SectionCard title="Identity">
                        <Field label="Application Name *" hint="e.g. My Store, Blog, API" error={errors.name}>
                            <input className={inp} value={data.name} onChange={(e) => handleName(e.target.value)}
                                placeholder="My Store" required />
                        </Field>
                        <Field label="Deploy Path *" hint="Absolute path on the server" error={errors.deploy_path}>
                            <input className={inp} value={data.deploy_path} onChange={set('deploy_path')} required />
                        </Field>
                    </SectionCard>

                    {/* Source */}
                    <SectionCard title="Deploy Source">
                        {/* Source mode toggle */}
                        <div className="flex gap-2 mb-1">
                            {[
                                { id: 'local',  label: 'Local Path'  },
                                { id: 'git',    label: 'Git Repo'    },
                                { id: 'remote', label: 'Remote Only' },
                            ].map(m => (
                                <button type="button" key={m.id} onClick={() => handleSourceMode(m.id)}
                                    className={`px-3 py-1.5 rounded-lg text-xs font-medium border transition-colors ${
                                        sourceMode === m.id
                                            ? 'bg-orange-600 text-white border-orange-600'
                                            : 'bg-white border-slate-300 text-slate-500 hover:border-slate-400'
                                    }`}>
                                    {m.label}
                                </button>
                            ))}
                        </div>

                        {sourceMode === 'local' && (
                            <Field label="Source Path" hint="Absolute LOCAL path to build and upload" error={errors.source_path}>
                                <input className={inp} value={data.source_path} onChange={set('source_path')}
                                    placeholder="D:\My project\my-app" />
                            </Field>
                        )}
                        {sourceMode === 'git' && (
                            <Field label="Git Repo URL" hint="For private repos: https://TOKEN@github.com/user/repo.git" error={errors.repo_url}>
                                <input className={inp} value={data.repo_url} onChange={set('repo_url')}
                                    placeholder="https://github.com/user/project.git" />
                            </Field>
                        )}
                        {sourceMode === 'remote' && (
                            <p className="text-xs text-slate-400 py-1">
                                Code is already on the server. AutoPilot will skip phases 2 & 3 and only run remote artisan commands.
                            </p>
                        )}

                        <div className="grid grid-cols-2 gap-3">
                            <Field label="Branch" hint="Git branch" error={errors.branch}>
                                <input className={inp} value={data.branch} onChange={set('branch')} placeholder="main" />
                            </Field>
                            <Field label="App URL" hint="Used for health checks" error={errors.app_url}>
                                <input className={inp} value={data.app_url} onChange={set('app_url')} placeholder="https://myapp.com" />
                            </Field>
                        </div>
                    </SectionCard>

                    {/* .env section */}
                    <div className="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm">
                        <button type="button" onClick={() => setShowEnv(!showEnv)}
                            className="w-full flex items-center justify-between px-4 py-3 hover:bg-slate-50 text-sm text-left transition-colors">
                            <div className="flex items-center gap-2">
                                <span className="text-xs font-semibold text-slate-400 uppercase tracking-widest">Environment (.env)</span>
                                {data.env_content
                                    ? <span className="text-xs px-2 py-0.5 rounded-full bg-emerald-50 border border-emerald-200 text-emerald-700">configured</span>
                                    : <span className="text-xs px-2 py-0.5 rounded-full bg-amber-50 border border-amber-200 text-amber-700">not set</span>
                                }
                            </div>
                            <span className="text-slate-400 text-xs">{showEnv ? '▾' : '▸'}</span>
                        </button>

                        {showEnv && (
                            <div className="p-4 border-t border-slate-200 space-y-3">
                                <p className="text-xs text-slate-400">
                                    Paste your production <code className="text-slate-600">.env</code> here.
                                    Encrypted in DB, written to server before each deploy. Never committed to git.
                                </p>
                                <div className="flex items-center justify-between flex-wrap gap-2">
                                    <div className="flex items-center gap-2">
                                        <span className="text-xs text-slate-400">Database:</span>
                                        {Object.keys(DB_PRESETS).map(key => (
                                            <button key={key} type="button"
                                                onClick={() => { setDbType(key); loadTemplate(key); }}
                                                className={`text-xs px-2.5 py-1 rounded-lg font-medium border transition-colors ${
                                                    dbType === key
                                                        ? 'bg-orange-600 text-white border-orange-600'
                                                        : 'bg-white text-slate-500 border-slate-300 hover:border-slate-400'
                                                }`}>
                                                {key === 'mysql' ? 'MySQL' : 'PostgreSQL'}
                                            </button>
                                        ))}
                                    </div>
                                    <button type="button" onClick={() => loadTemplate()}
                                        className="text-xs text-orange-600 hover:text-orange-500 font-medium transition-colors">
                                        Load template
                                    </button>
                                </div>
                                <textarea
                                    className="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 text-xs font-mono text-emerald-700 focus:outline-none focus:border-orange-400 placeholder-slate-400"
                                    rows={16}
                                    value={data.env_content}
                                    onChange={set('env_content')}
                                    placeholder={'APP_NAME=Laravel\nAPP_ENV=production\nAPP_KEY=\n\nDB_CONNECTION=mysql\nDB_HOST=127.0.0.1\nDB_DATABASE=...\nDB_USERNAME=...\nDB_PASSWORD=...'}
                                    spellCheck={false}
                                />
                                {errors.env_content && <p className="text-red-500 text-xs">{errors.env_content}</p>}
                            </div>
                        )}
                    </div>

                    {/* Advanced */}
                    <div className="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm">
                        <button type="button" onClick={() => setShowAdvanced(!showAdvanced)}
                            className="w-full flex items-center justify-between px-4 py-3 hover:bg-slate-50 text-left transition-colors">
                            <span className="text-xs font-semibold text-slate-400 uppercase tracking-widest">Advanced</span>
                            <span className="text-slate-400 text-xs">{showAdvanced ? '▾' : '▸'}</span>
                        </button>
                        {showAdvanced && (
                            <div className="p-4 border-t border-slate-200 space-y-3">
                                <Field label="PHP Binary" hint="Full path if php is not in PATH" error={errors.php_binary}>
                                    <input className={inp} value={data.php_binary} onChange={set('php_binary')} placeholder="php" />
                                </Field>
                                <div className="flex items-center gap-3">
                                    <input type="checkbox" id="run_seeders" checked={data.run_seeders}
                                        onChange={(e) => setData('run_seeders', e.target.checked)}
                                        className="w-4 h-4 accent-orange-500" />
                                    <label htmlFor="run_seeders" className="text-sm text-slate-600 select-none cursor-pointer">
                                        Run seeders on every deploy (<code className="text-slate-500 bg-slate-100 px-1 rounded text-xs">php artisan db:seed --force</code>)
                                    </label>
                                </div>
                                <Field
                                    label="Pre-migrate commands"
                                    hint="One command per line — runs before migrate --force on every deploy. e.g. php artisan tenancy:install"
                                    error={errors.pre_migrate_commands}
                                >
                                    <textarea
                                        className={inp + ' font-mono text-xs'}
                                        rows={3}
                                        value={data.pre_migrate_commands}
                                        onChange={set('pre_migrate_commands')}
                                        placeholder={'php artisan tenancy:install\nphp artisan vendor:publish --tag=tenancy-migrations'}
                                        spellCheck={false}
                                    />
                                </Field>
                            </div>
                        )}
                    </div>

                    {/* Submit */}
                    <div className="flex gap-3 pt-1">
                        <Link href={`/servers/${server.id}`}
                            className="flex-1 text-center bg-white border border-slate-300 text-slate-700 hover:bg-slate-50 py-2.5 rounded-lg text-sm font-medium transition-colors">
                            Cancel
                        </Link>
                        <button type="submit" disabled={processing}
                            className="flex-1 bg-orange-600 hover:bg-orange-500 disabled:opacity-50 text-white py-2.5 rounded-lg font-semibold text-sm transition-colors">
                            {processing ? 'Saving…' : 'Add Application'}
                        </button>
                    </div>
                </form>
            </div>
        </Layout>
    );
}
