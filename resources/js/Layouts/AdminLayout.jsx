import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

function Flash() {
    const { flash } = usePage().props;
    const [shown, setShown] = useState(null);

    useEffect(() => {
        const msg = flash?.error || flash?.success;
        if (!msg) return;
        setShown({ msg, error: Boolean(flash.error) });
        const t = setTimeout(() => setShown(null), 3500);
        return () => clearTimeout(t);
    }, [flash]);

    if (!shown) return null;
    return (
        <div
            className={`fixed bottom-6 left-1/2 z-50 max-w-[calc(100vw-32px)] -translate-x-1/2 rounded-lg px-4 py-2.5 text-sm text-white shadow-lg ${shown.error ? 'bg-bad' : 'bg-ink'}`}
        >
            {shown.msg}
        </div>
    );
}

function UpdateDatabaseBanner({ count }) {
    const [busy, setBusy] = useState(false);
    if (!count) return null;
    return (
        <div className="flex flex-wrap items-center gap-3 bg-warn-soft px-5 py-2.5 text-sm text-warn">
            <b>This version needs a database update ({count} change{count === 1 ? '' : 's'}).</b>
            <button
                className="btn btn-sm"
                disabled={busy}
                onClick={() => router.post('/system/update-database', {}, { onStart: () => setBusy(true), onFinish: () => setBusy(false) })}
            >
                {busy ? 'Updating…' : 'Update now'}
            </button>
        </div>
    );
}

export default function AdminLayout({ children, toolbar }) {
    const { auth, appName, pendingMigrations } = usePage().props;
    const { url } = usePage();
    const path = url.split('?')[0];
    const nav = (href, label) => (
        <Link
            href={href}
            className={`rounded-lg px-3 py-1.5 text-sm font-semibold text-white ${(href === '/' ? path === '/' || path.startsWith('/events') : path === href) ? 'bg-white/25' : 'bg-white/10 hover:bg-white/20'}`}
        >
            {label}
        </Link>
    );

    return (
        <div className="min-h-screen">
            <header className="flex flex-wrap items-center gap-x-4 gap-y-2 bg-navy px-5 py-2.5 text-white">
                <h1 className="text-lg font-bold">Attendance</h1>
                <span className="hidden text-sm text-white/70 sm:inline">{appName}</span>
                {toolbar}
                <nav className="ml-auto flex flex-wrap items-center gap-1.5">
                    {nav('/', 'Events')}
                    {nav('/roster', 'People')}
                    {nav('/accounts', 'Accounts')}
                    <button
                        onClick={() => router.post('/logout')}
                        className="rounded-lg px-3 py-1.5 text-sm text-white/80 hover:bg-white/10"
                        title={auth.user?.email}
                    >
                        Log out
                    </button>
                </nav>
            </header>
            <UpdateDatabaseBanner count={pendingMigrations} />
            <main className="mx-auto max-w-[1500px] px-5 pt-4 pb-10">{children}</main>
            <Flash />
        </div>
    );
}
