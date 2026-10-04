import { useState } from 'react';
import Layout, { IconPlus, IconEdit, IconTrash, IconX } from '../../Components/Layout';
import { router, usePage } from '@inertiajs/react';

const EMPTY_FORM = { name: '', email: '', password: '', role: 'user' };

const inp = 'w-full bg-white border border-slate-300 rounded-lg px-3 py-2 text-sm text-slate-900 placeholder-slate-400 focus:outline-none focus:border-orange-400 focus:ring-1 focus:ring-orange-200 transition-colors';

export default function UsersIndex({ users }) {
    const { props } = usePage();
    const flash = props.flash ?? {};

    const [showCreate, setShowCreate] = useState(false);
    const [editUser, setEditUser]     = useState(null);
    const [form, setForm]             = useState(EMPTY_FORM);
    const [errors, setErrors]         = useState({});

    const set = (field, val) => setForm(f => ({ ...f, [field]: val }));

    const openCreate = () => { setForm(EMPTY_FORM); setErrors({}); setEditUser(null); setShowCreate(true); };
    const openEdit   = (u)  => { setForm({ name: u.name, email: u.email, password: '', role: u.role }); setErrors({}); setEditUser(u); setShowCreate(false); };
    const closeAll   = ()   => { setShowCreate(false); setEditUser(null); };

    const submit = (e) => {
        e.preventDefault();
        const opts = { onError: (errs) => setErrors(errs), onSuccess: () => closeAll() };
        if (editUser) { router.put(`/users/${editUser.id}`, form, opts); }
        else          { router.post('/users', form, opts); }
    };

    const deleteUser = (u) => {
        if (confirm(`Delete user "${u.name}"? This cannot be undone.`)) {
            router.delete(`/users/${u.id}`);
        }
    };

    const isOpen = showCreate || editUser !== null;

    return (
        <Layout title="User Management">
            {flash.success && (
                <div className="mb-4 px-4 py-2.5 bg-emerald-50 border border-emerald-200 text-emerald-700 rounded-lg text-sm">
                    {flash.success}
                </div>
            )}
            {flash.error && (
                <div className="mb-4 px-4 py-2.5 bg-red-50 border border-red-200 text-red-700 rounded-lg text-sm">
                    {flash.error}
                </div>
            )}

            <div className="flex justify-end mb-5">
                <button
                    onClick={openCreate}
                    className="flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg bg-orange-600 hover:bg-orange-500 text-white transition-colors"
                >
                    <IconPlus className="w-3.5 h-3.5" />
                    Add User
                </button>
            </div>

            {/* User table */}
            <div className="bg-white rounded-xl border border-slate-200 overflow-hidden shadow-sm">
                <table className="w-full text-sm">
                    <thead>
                        <tr className="border-b border-slate-200">
                            <th className="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">User</th>
                            <th className="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Email</th>
                            <th className="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Role</th>
                            <th className="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Servers</th>
                            <th className="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Deploys</th>
                            <th className="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        {users?.length === 0 && (
                            <tr>
                                <td colSpan={6} className="px-4 py-10 text-center text-slate-400 text-sm">No users found.</td>
                            </tr>
                        )}
                        {users?.map(u => (
                            <tr key={u.id} className="border-t border-slate-100 hover:bg-slate-50 transition-colors">
                                <td className="px-4 py-3">
                                    <div className="flex items-center gap-3">
                                        <span className="w-7 h-7 rounded-full bg-slate-200 flex items-center justify-center text-slate-600 text-xs font-bold uppercase shrink-0">
                                            {u.name?.charAt(0) ?? '?'}
                                        </span>
                                        <span className="font-medium text-slate-900 text-sm">{u.name}</span>
                                    </div>
                                </td>
                                <td className="px-4 py-3 text-slate-500 text-xs">{u.email}</td>
                                <td className="px-4 py-3">
                                    <span className={`text-xs px-2 py-0.5 rounded-full border font-medium ${
                                        u.role === 'admin'
                                            ? 'bg-orange-50 text-orange-700 border-orange-200'
                                            : 'bg-slate-100 text-slate-500 border-slate-200'
                                    }`}>
                                        {u.role}
                                    </span>
                                </td>
                                <td className="px-4 py-3 text-slate-500 text-xs">{u.servers_count ?? 0}</td>
                                <td className="px-4 py-3 text-slate-500 text-xs">{u.deployments_count ?? 0}</td>
                                <td className="px-4 py-3">
                                    <div className="flex items-center gap-2">
                                        <button onClick={() => openEdit(u)}
                                            className="p-1.5 rounded-lg text-slate-400 hover:text-slate-700 hover:bg-slate-100 transition-colors">
                                            <IconEdit className="w-3.5 h-3.5" />
                                        </button>
                                        <button onClick={() => deleteUser(u)}
                                            className="p-1.5 rounded-lg text-slate-400 hover:text-red-500 hover:bg-red-50 transition-colors">
                                            <IconTrash className="w-3.5 h-3.5" />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            {/* Modal */}
            {isOpen && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/30 backdrop-blur-sm">
                    <div className="bg-white rounded-2xl border border-slate-200 shadow-xl w-full max-w-md mx-4 p-6">
                        <div className="flex items-center justify-between mb-5">
                            <h2 className="text-base font-semibold text-slate-900">
                                {editUser ? 'Edit User' : 'Create User'}
                            </h2>
                            <button onClick={closeAll} className="text-slate-400 hover:text-slate-700 transition-colors p-1">
                                <IconX className="w-4 h-4" />
                            </button>
                        </div>

                        <form onSubmit={submit} className="space-y-4">
                            <div>
                                <label className="block text-xs font-medium text-slate-600 mb-1.5">Name</label>
                                <input value={form.name} onChange={e => set('name', e.target.value)}
                                    className={inp} placeholder="Full name" required />
                                {errors.name && <p className="text-red-500 text-xs mt-1">{errors.name}</p>}
                            </div>

                            <div>
                                <label className="block text-xs font-medium text-slate-600 mb-1.5">Email</label>
                                <input type="email" value={form.email} onChange={e => set('email', e.target.value)}
                                    className={inp} placeholder="user@example.com" required />
                                {errors.email && <p className="text-red-500 text-xs mt-1">{errors.email}</p>}
                            </div>

                            <div>
                                <label className="block text-xs font-medium text-slate-600 mb-1.5">
                                    Password{' '}
                                    {editUser && <span className="text-slate-400 font-normal">(leave blank to keep current)</span>}
                                </label>
                                <input type="password" value={form.password} onChange={e => set('password', e.target.value)}
                                    className={inp} placeholder="••••••••" {...(!editUser && { required: true })} />
                                {errors.password && <p className="text-red-500 text-xs mt-1">{errors.password}</p>}
                            </div>

                            <div>
                                <label className="block text-xs font-medium text-slate-600 mb-1.5">Role</label>
                                <div className="flex gap-2">
                                    {['user', 'admin'].map(r => (
                                        <button type="button" key={r} onClick={() => set('role', r)}
                                            className={`flex-1 py-2 rounded-lg text-xs font-semibold border transition-colors ${
                                                form.role === r
                                                    ? 'bg-orange-600 text-white border-orange-600'
                                                    : 'bg-white text-slate-600 border-slate-300 hover:border-slate-400'
                                            }`}>
                                            {r === 'admin' ? 'Admin' : 'User'}
                                        </button>
                                    ))}
                                </div>
                                {errors.role && <p className="text-red-500 text-xs mt-1">{errors.role}</p>}
                            </div>

                            <div className="flex gap-2 pt-1">
                                <button type="submit"
                                    className="flex-1 bg-orange-600 hover:bg-orange-500 text-white py-2.5 rounded-lg text-sm font-semibold transition-colors">
                                    {editUser ? 'Save Changes' : 'Create User'}
                                </button>
                                <button type="button" onClick={closeAll}
                                    className="flex-1 bg-white hover:bg-slate-50 border border-slate-300 text-slate-700 py-2.5 rounded-lg text-sm font-semibold transition-colors">
                                    Cancel
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </Layout>
    );
}
