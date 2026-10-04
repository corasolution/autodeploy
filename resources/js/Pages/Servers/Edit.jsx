import Layout from '../../Components/Layout';
import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import { PANELS, PANEL_KEYS, panelFor, webUserPlaceholder } from '../../Components/panels';

const inp = 'w-full bg-white border border-slate-300 rounded-lg px-3 py-2 text-sm text-slate-900 placeholder-slate-400 focus:outline-none focus:border-orange-400 focus:ring-1 focus:ring-orange-200 transition-colors';

function SectionLabel({ children }) {
    return <h3 className="text-xs font-semibold uppercase tracking-widest text-slate-400 mb-3">{children}</h3>;
}

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

export default function ServerEdit({ server }) {
    const { data, setData, put, processing, errors } = useForm({
        name:            server.name            ?? '',
        panel_type:      server.panel_type      ?? 'cpanel',
        host:            server.host            ?? '',
        ssh_port:        server.ssh_port        ?? 22,
        ssh_user:        server.ssh_user        ?? '',
        web_user:        server.web_user        ?? '',
        ssh_auth:        server.ssh_auth        ?? 'password',
        ssh_password:    server.ssh_password    ?? '',
        ssh_private_key: server.ssh_private_key ?? '',
        panel_url:       server.panel_url       ?? '',
        panel_token:     server.panel_token     ?? '',
        active:          server.active          ?? true,
    });

    const [showPassword, setShowPassword] = useState(false);
    const [showToken, setShowToken]       = useState(false);

    const set = (field) => (e) => setData(field, e.target.value);
    const submit = (e) => { e.preventDefault(); put(`/servers/${server.id}`); };

    return (
        <Layout title={`Edit — ${server.name}`}>
            <div className="max-w-2xl">
                <p className="text-slate-600 text-sm mb-4">
                    Credentials are pre-filled. Editing a field replaces the saved value; leaving it blank keeps the existing value.
                </p>

                <form onSubmit={submit} className="space-y-4 bg-white border border-slate-200 rounded-xl p-5 shadow-sm">

                    {/* ── Panel type ── */}
                    <section>
                        <SectionLabel>Hosting Panel *</SectionLabel>
                        <div className="grid grid-cols-3 gap-3">
                            {PANEL_KEYS.map((type) => (
                                <button key={type} type="button"
                                    onClick={() => setData('panel_type', type)}
                                    className={`rounded-xl border-2 py-4 text-center text-sm font-semibold transition-all
                                        ${data.panel_type === type
                                            ? 'border-orange-400 bg-orange-50 text-orange-700'
                                            : 'border-slate-200 bg-slate-50 text-slate-500 hover:border-slate-300'}`}>
                                    {PANELS[type].label}
                                    <div className="text-xs font-normal mt-1 text-slate-400">
                                        {PANELS[type].portHint}
                                    </div>
                                </button>
                            ))}
                        </div>
                    </section>

                    {/* ── Identity ── */}
                    <section>
                        <SectionLabel>Server Identity</SectionLabel>
                        <div className="space-y-4">
                            <Field label="Server Name *" error={errors.name}>
                                <input className={inp} value={data.name} onChange={set('name')} required />
                            </Field>
                            <div className="grid grid-cols-3 gap-3">
                                <div className="col-span-2">
                                    <Field label="Host / IP *" error={errors.host}>
                                        <input className={inp} value={data.host} onChange={set('host')} required />
                                    </Field>
                                </div>
                                <Field label="SSH Port" error={errors.ssh_port}>
                                    <input className={inp} type="number" value={data.ssh_port} onChange={set('ssh_port')} />
                                </Field>
                            </div>
                        </div>
                    </section>

                    {/* ── SSH ── */}
                    <section>
                        <SectionLabel>SSH Access</SectionLabel>
                        <div className="space-y-4">
                            <Field label="SSH Username *" error={errors.ssh_user}>
                                <input className={inp} value={data.ssh_user} onChange={set('ssh_user')} required />
                            </Field>

                            <Field label="Web User" hint={panelFor(data.panel_type).webUserHint} error={errors.web_user}>
                                <input className={inp} value={data.web_user} onChange={set('web_user')}
                                    placeholder={webUserPlaceholder(data.panel_type, data.ssh_user)} />
                            </Field>

                            <div>
                                <label className="block text-xs font-medium text-slate-600 mb-1.5">Auth Method *</label>
                                <div className="flex rounded-lg overflow-hidden border border-slate-300 w-fit">
                                    {[['password', 'Password'], ['key', 'Private Key']].map(([val, label]) => (
                                        <button key={val} type="button"
                                            onClick={() => setData('ssh_auth', val)}
                                            className={`px-5 py-2 text-sm font-medium transition-colors
                                                ${data.ssh_auth === val
                                                    ? 'bg-orange-600 text-white'
                                                    : 'bg-white text-slate-600 hover:bg-slate-50 border-slate-300'}`}>
                                            {label}
                                        </button>
                                    ))}
                                </div>
                            </div>

                            {data.ssh_auth === 'password' && (
                                <Field label="SSH Password" hint="Leave blank to keep the existing password." error={errors.ssh_password}>
                                    <div className="relative">
                                        <input
                                            className={inp + ' pr-16'}
                                            type={showPassword ? 'text' : 'password'}
                                            value={data.ssh_password}
                                            onChange={set('ssh_password')}
                                            autoComplete="new-password"
                                        />
                                        <button type="button"
                                            onClick={() => setShowPassword(v => !v)}
                                            className="absolute right-2 top-1/2 -translate-y-1/2 text-xs px-2 py-1 rounded text-slate-500 hover:text-slate-700 hover:bg-slate-100 transition-colors">
                                            {showPassword ? 'Hide' : 'Show'}
                                        </button>
                                    </div>
                                </Field>
                            )}
                            {data.ssh_auth === 'key' && (
                                <Field label="SSH Private Key" hint="Leave blank to keep the existing key." error={errors.ssh_private_key}>
                                    <textarea className={inp} rows={5} value={data.ssh_private_key} onChange={set('ssh_private_key')}
                                        placeholder="-----BEGIN RSA PRIVATE KEY-----&#10;...&#10;-----END RSA PRIVATE KEY-----" />
                                </Field>
                            )}
                        </div>
                    </section>

                    {/* ── Panel API ── */}
                    <section>
                        <SectionLabel>Panel API <span className="text-slate-400 font-normal">(optional)</span></SectionLabel>
                        <div className="space-y-4">
                            <Field label="Panel URL" error={errors.panel_url}>
                                <input className={inp} value={data.panel_url} onChange={set('panel_url')}
                                    placeholder={`https://server.com:${panelFor(data.panel_type).port}`} />
                            </Field>
                            <Field label={panelFor(data.panel_type).tokenLabel}
                                hint={panelFor(data.panel_type).tokenHint}
                                error={errors.panel_token}>
                                <div className="relative">
                                    <input
                                        className={inp + ' pr-16'}
                                        type={showToken ? 'text' : 'password'}
                                        value={data.panel_token}
                                        onChange={set('panel_token')}
                                        autoComplete="new-password"
                                    />
                                    <button type="button"
                                        onClick={() => setShowToken(v => !v)}
                                        className="absolute right-2 top-1/2 -translate-y-1/2 text-xs px-2 py-1 rounded text-slate-500 hover:text-slate-700 hover:bg-slate-100 transition-colors">
                                        {showToken ? 'Hide' : 'Show'}
                                    </button>
                                </div>
                            </Field>
                        </div>
                    </section>

                    {/* ── Active toggle ── */}
                    <div className="flex items-center gap-3 pt-1">
                        <input type="checkbox" id="active" checked={data.active}
                            onChange={(e) => setData('active', e.target.checked)}
                            className="w-4 h-4 accent-orange-500" />
                        <label htmlFor="active" className="text-sm text-slate-600 select-none cursor-pointer">
                            Server is active
                        </label>
                    </div>

                    <button type="submit" disabled={processing}
                        className="w-full bg-orange-600 hover:bg-orange-500 disabled:opacity-50 text-white py-3 rounded-lg font-semibold text-sm transition-colors">
                        {processing ? 'Saving…' : 'Update Server'}
                    </button>
                </form>
            </div>
        </Layout>
    );
}
