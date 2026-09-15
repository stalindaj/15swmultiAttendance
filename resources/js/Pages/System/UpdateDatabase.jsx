import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { Panel } from '../../Components/ui';
import AdminLayout from '../../Layouts/AdminLayout';

/** Shown instead of the records pages when a newly pulled version needs database changes. */
export default function UpdateDatabase({ pending }) {
    const [busy, setBusy] = useState(false);
    const run = () => router.post('/system/update-database', {}, { onStart: () => setBusy(true), onFinish: () => setBusy(false) });

    return (
        <AdminLayout>
            <Head title="Update database" />
            <Panel title="This version needs a database update">
                <div className="grid gap-3 px-4 py-4">
                    <p className="text-sm">
                        A new version was installed. Before its pages can open, the database needs {pending.length} change{pending.length === 1 ? '' : 's'}.
                        Existing data is kept.
                    </p>
                    <ul className="list-disc pl-5 font-mono text-xs text-muted">
                        {pending.map((m) => <li key={m}>{m}</li>)}
                    </ul>
                    <div><button className="btn btn-primary" disabled={busy} onClick={run}>{busy ? 'Updating…' : 'Update database now'}</button></div>
                </div>
            </Panel>
        </AdminLayout>
    );
}
