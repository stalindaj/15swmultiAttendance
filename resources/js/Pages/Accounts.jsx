import { Head, router, useForm } from '@inertiajs/react';
import { Panel, Pill } from '../Components/ui';
import AdminLayout from '../Layouts/AdminLayout';

function NewAccount() {
    const { data, setData, post, processing, errors, reset } = useForm({ name: '', username: '', password: '', role: 'scanner' });
    const submit = (e) => {
        e.preventDefault();
        post('/accounts', { preserveScroll: true, onSuccess: () => reset() });
    };
    const field = (key, label, props = {}) => (
        <label className="grid gap-1 text-xs font-semibold text-muted">
            {label}
            <input className="input" value={data[key]} onChange={(e) => setData(key, e.target.value)} {...props} />
            {errors[key] && <span className="text-bad">{errors[key]}</span>}
        </label>
    );

    return (
        <Panel title="New account" subtitle="One per phone: Gate 1 / gate1, Gate 2 / gate2, Gate 3 / gate3…">
            <form onSubmit={submit} className="flex flex-wrap items-end gap-3 px-4 py-3">
                {field('name', 'Display name', { placeholder: 'Gate 1', required: true })}
                {field('username', 'Username', { placeholder: 'gate1', required: true, autoCapitalize: 'none' })}
                {field('password', 'Password', { type: 'text', placeholder: 'min 6 characters', required: true, autoComplete: 'new-password' })}
                <label className="grid gap-1 text-xs font-semibold text-muted">
                    Type
                    <select className="input" value={data.role} onChange={(e) => setData('role', e.target.value)}>
                        <option value="scanner">Phone scanner</option>
                        <option value="admin">Records (PC)</option>
                    </select>
                </label>
                <button className="btn btn-primary" disabled={processing}>Create</button>
            </form>
        </Panel>
    );
}

export default function Accounts({ accounts }) {
    const act = (url, msg) => {
        if (!msg || confirm(msg)) router.post(url, {}, { preserveScroll: true });
    };
    const setPassword = (a) => {
        const pw = prompt(`New password for ${a.name} (min 6 characters). Their phone will be logged out.`);
        if (pw) router.post(`/accounts/${a.id}/password`, { password: pw }, { preserveScroll: true });
    };

    return (
        <AdminLayout>
            <Head title="Accounts" />
            <div className="grid gap-4">
                <NewAccount />
                <Panel title="Accounts">
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead>
                                <tr>{['Name', 'Username', 'Type', 'Scans today', 'Last scan', 'Phone last seen', ''].map((h) => <th key={h} className="th">{h}</th>)}</tr>
                            </thead>
                            <tbody>
                                {accounts.map((a) => (
                                    <tr key={a.id} className={a.is_active ? '' : 'opacity-50'}>
                                        <td className="td font-semibold">{a.name}</td>
                                        <td className="td font-mono">{a.username}</td>
                                        <td className="td">{a.role === 'admin' ? <Pill tone="dup">Records PC</Pill> : <Pill>Phone</Pill>}{!a.is_active && <> <Pill tone="bad">disabled</Pill></>}</td>
                                        <td className="td tabular-nums">{a.scans_today}</td>
                                        <td className="td tabular-nums">{a.last_scan ?? '—'}</td>
                                        <td className="td tabular-nums">{a.last_seen ?? '—'}</td>
                                        <td className="td">
                                            <div className="flex flex-wrap gap-1">
                                                <button className="btn btn-sm" onClick={() => setPassword(a)}>Password</button>
                                                <button className="btn btn-sm" onClick={() => act(`/accounts/${a.id}/logout`, `Log ${a.name} out on every phone?`)}>Log out phone</button>
                                                <button className="btn btn-sm btn-danger" onClick={() => act(`/accounts/${a.id}/active`, a.is_active ? `Disable ${a.name}? Its phone is logged out immediately.` : null)}>
                                                    {a.is_active ? 'Disable' : 'Enable'}
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </Panel>
            </div>
        </AdminLayout>
    );
}
