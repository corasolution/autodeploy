import { useForm } from '@inertiajs/react';
import { useEffect, useRef } from 'react';

export default function TwoFactorChallenge() {
    const { data, setData, post, processing, errors } = useForm({ code: '' });
    const inputRef = useRef(null);

    useEffect(() => { inputRef.current?.focus(); }, []);

    const submit = (e) => {
        e.preventDefault();
        post('/two-factor');
    };

    return (
        <div className="min-h-screen flex items-center justify-center bg-slate-50 px-4">
            <div className="w-full max-w-sm">
                <div className="text-center mb-8">
                    <div className="w-14 h-14 rounded-2xl bg-gradient-to-br from-orange-400 to-orange-600 mx-auto mb-4 flex items-center justify-center shadow-lg shadow-orange-500/20">
                        <span className="text-white text-2xl font-bold">A</span>
                    </div>
                    <h1 className="text-2xl font-bold text-slate-900">Two-factor authentication</h1>
                    <p className="text-sm text-slate-500 mt-1">
                        Enter the 6-digit code from your authenticator app.
                    </p>
                </div>

                <form onSubmit={submit} className="bg-white rounded-2xl shadow-sm border border-slate-200 p-6">
                    <label className="block text-sm font-medium text-slate-700 mb-1.5">
                        Authentication code
                    </label>
                    <input
                        ref={inputRef}
                        value={data.code}
                        onChange={(e) => setData('code', e.target.value)}
                        inputMode="numeric"
                        autoComplete="one-time-code"
                        placeholder="000000"
                        className="w-full px-4 py-3 rounded-xl border border-slate-300 text-center text-2xl font-mono tracking-[0.3em] focus:outline-none focus:ring-2 focus:ring-orange-400 focus:border-orange-400"
                    />
                    {errors.code && (
                        <p className="text-sm text-red-600 mt-2">{errors.code}</p>
                    )}

                    <button
                        type="submit"
                        disabled={processing}
                        className="w-full mt-5 px-4 py-3 rounded-xl text-sm font-semibold bg-orange-600 hover:bg-orange-500 text-white transition-colors disabled:opacity-50"
                    >
                        {processing ? 'Verifying…' : 'Verify'}
                    </button>

                    <p className="text-xs text-slate-400 mt-4 text-center">
                        Lost your device? Enter one of your recovery codes instead.
                    </p>
                </form>
            </div>
        </div>
    );
}
