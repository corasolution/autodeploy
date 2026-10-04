import Layout from '../../Components/Layout';
import { useForm } from '@inertiajs/react';
import { PANELS, PANEL_KEYS, panelFor, webUserPlaceholder } from '../../Components/panels';

export default function ServerCreate() {
    const { data, setData, post, processing, errors } = useForm({
        name: '', panel_type: 'cpanel', host: '', ssh_port: 22,
        ssh_user: '', web_user: '', ssh_auth: 'password', ssh_password: '', ssh_private_key: '',
        panel_url: '', panel_token: '', active: true,
    });

    const set = (field) => (e) => setData(field, e.target.value);

    const panel = panelFor(data.panel_type);

    const handlePanelType = (type) => {
        const url = data.host ? `https://${data.host}:${PANELS[type].port}` : '';
        setData((prev) => ({ ...prev, panel_type: type, panel_url: url }));
    };

    const handleHost = (host) => {
        const url = host ? `https://${host}:${panel.port}` : '';
        setData((prev) => ({ ...prev, host, panel_url: url }));
    };

    const submit = (e) => { e.preventDefault(); post('/servers'); };

    return (
        <Layout title="Add Server">
            <div className="max-w-2xl">
                <p className="text-slate-600 text-sm mb-4">
                    Add your VPS / hosting server once. You can then add multiple applications on it.
                </p>

                <form onSubmit={submit} className="space-y-4 bg-white border border-slate-200 rounded-xl p-5 shadow-sm">

                    {/* ── Panel type ── */}
                    <section>
                        <SectionLabel>Hosting Panel *</SectionLabel>
                        <div className="grid grid-cols-3 gap-3">
                            {PANEL_KEYS.map((type) => (
                                <button key={type} type="button"
                                    onClick={() => handlePanelType(type)}
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
                            <Field label="Server Name *" hint="A friendly label, e.g. My VPS or Production Server" error={errors.name}>
                                <input className={inp} value={data.name} onChange={set('name')} placeholder="My VPS" required />
                            </Field>
                            <div className="grid grid-cols-3 gap-3">
                                <div className="col-span-2">
                                    <Field label="Host / IP *" hint="Server IP or hostname" error={errors.host}>
                                        <input className={inp} value={data.host} onChange={(e) => handleHost(e.target.value)} placeholder="72.62.69.111" required />
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
                            <Field label="SSH Username *" hint={panel.sshUserHint} error={errors.ssh_user}>
                                <input className={inp} value={data.ssh_user} onChange={set('ssh_user')}
                                    placeholder={panel.sshUserPlaceholder} required />
                            </Field>

                            <Field label="Web User" hint={panel.webUserHint} error={errors.web_user}>
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
                                <Field label="SSH Password" error={errors.ssh_password}>
                                    <input className={inp} type="password" value={data.ssh_password} onChange={set('ssh_password')} autoComplete="new-password" />
                                </Field>
                            )}
                            {data.ssh_auth === 'key' && (
                                <Field label="SSH Private Key" hint="Paste your private key (PEM format)" error={errors.ssh_private_key}>
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
                            <Field label="Panel URL" hint="Auto-filled from host above" error={errors.panel_url}>
                                <input className={inp} value={data.panel_url} onChange={set('panel_url')}
                                    placeholder={`https://server.com:${panel.port}`} />
                            </Field>
                            <Field label={panel.tokenLabel} hint={panel.tokenHint} error={errors.panel_token}>
                                <input className={inp} type="password" value={data.panel_token} onChange={set('panel_token')} autoComplete="new-password" />
                            </Field>
                        </div>
                    </section>

                    <button type="submit" disabled={processing}
                        className="w-full bg-orange-600 hover:bg-orange-500 disabled:opacity-50 text-white py-3 rounded-lg font-semibold text-sm transition-colors">
                        {processing ? 'Saving…' : 'Save Server → Add Apps Next'}
                    </button>
                </form>
            </div>
        </Layout>
    );
}

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
