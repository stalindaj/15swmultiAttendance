import { Head, useForm, usePage } from '@inertiajs/react';

export default function Login() {
    const { appName } = usePage().props;
    const { data, setData, post, processing, errors } = useForm({ username: '', password: '', remember: true });

    const submit = (e) => {
        e.preventDefault();
        post('/login', { onFinish: () => setData('password', '') });
    };

    return (
        <div className="grid min-h-dvh place-items-center bg-canvas px-4">
            <Head title="Log in" />
            <form onSubmit={submit} className="w-full max-w-sm rounded-2xl border border-line bg-white p-6 shadow-sm">
                <h1 className="text-xl font-bold text-navy">{appName}</h1>
                <p className="mb-5 text-sm text-muted">Log in with this phone's account (e.g. gate1), or the records account on the PC.</p>

                <label className="mb-3 grid gap-1 text-sm font-semibold">
                    Username
                    <input className="input py-3 text-base" autoComplete="username" autoCapitalize="none" autoCorrect="off" autoFocus
                        value={data.username} onChange={(e) => setData('username', e.target.value)} />
                </label>
                <label className="mb-3 grid gap-1 text-sm font-semibold">
                    Password
                    <input className="input py-3 text-base" type="password" autoComplete="current-password"
                        value={data.password} onChange={(e) => setData('password', e.target.value)} />
                </label>
                {(errors.username || errors.password) && <p className="mb-3 text-sm text-bad">{errors.username || errors.password}</p>}
                <label className="mb-4 flex items-center gap-2 text-sm text-muted">
                    <input type="checkbox" checked={data.remember} onChange={(e) => setData('remember', e.target.checked)} />
                    Keep me logged in on this device
                </label>
                <button className="btn btn-primary w-full py-3 text-base" disabled={processing}>Log in</button>
            </form>
        </div>
    );
}
