import { useForm } from '@inertiajs/react';

export default function Login() {
    const { data, setData, post, processing, errors } = useForm({
        email:    '',
        password: '',
        remember: false,
    });

    const submit = (e) => {
        e.preventDefault();
        post('/login');
    };

    const inp = 'w-full bg-white border border-slate-300 rounded-lg px-3 py-2.5 text-sm text-slate-900 placeholder-slate-400 focus:outline-none focus:border-orange-400 focus:ring-1 focus:ring-orange-200 transition-colors';

    return (
        <div className="min-h-screen bg-slate-50 flex items-center justify-center px-4">
            <div className="w-full max-w-sm">
                {/* Logo */}
                <div className="flex flex-col items-center mb-8">
                    <span className="w-14 h-14 rounded-2xl bg-gradient-to-br from-orange-400 to-orange-600 flex items-center justify-center text-white font-bold text-2xl shadow-xl shadow-orange-500/25 mb-4">
                        A
                    </span>
                    <h1 className="text-2xl font-bold text-slate-900 tracking-tight">AutoPilot Deploy</h1>
                    <p className="text-slate-500 text-sm mt-1.5">Sign in to your account</p>
                </div>

                <form onSubmit={submit} className="bg-white border border-slate-200 rounded-2xl shadow-sm p-6 space-y-4">
                    <div>
                        <label className="block text-sm font-medium text-slate-700 mb-1.5">Email</label>
                        <input
                            type="email"
                            value={data.email}
                            onChange={e => setData('email', e.target.value)}
                            className={inp}
                            placeholder="you@example.com"
                            autoComplete="email"
                            required
                        />
                        {errors.email && <p className="text-red-500 text-xs mt-1.5">{errors.email}</p>}
                    </div>

                    <div>
                        <label className="block text-sm font-medium text-slate-700 mb-1.5">Password</label>
                        <input
                            type="password"
                            value={data.password}
                            onChange={e => setData('password', e.target.value)}
                            className={inp}
                            placeholder="••••••••"
                            autoComplete="current-password"
                            required
                        />
                        {errors.password && <p className="text-red-500 text-xs mt-1.5">{errors.password}</p>}
                    </div>

                    <div className="flex items-center gap-2.5">
                        <input
                            type="checkbox"
                            id="remember"
                            checked={data.remember}
                            onChange={e => setData('remember', e.target.checked)}
                            className="w-4 h-4 accent-orange-500 rounded"
                        />
                        <label htmlFor="remember" className="text-sm text-slate-500 select-none cursor-pointer">Remember me</label>
                    </div>

                    <button
                        type="submit"
                        disabled={processing}
                        className="w-full bg-orange-600 hover:bg-orange-500 active:bg-orange-700 disabled:opacity-50 text-white py-2.5 rounded-lg font-semibold text-sm transition-colors mt-2"
                    >
                        {processing ? 'Signing in…' : 'Sign In'}
                    </button>
                </form>
            </div>
        </div>
    );
}
