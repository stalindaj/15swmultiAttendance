import { Head, Link, useForm } from '@inertiajs/react';
import { Panel, Pill } from '../../Components/ui';
import AdminLayout from '../../Layouts/AdminLayout';

function NewEvent({ today }) {
    const { data, setData, post, processing, errors } = useForm({ name: '', event_date: today });
    return (
        <Panel title="New event" subtitle="e.g. SAFETY MEETING. Next you upload who should attend (PSR / Excel).">
            <form className="flex flex-wrap items-end gap-3 px-4 py-3" onSubmit={(e) => { e.preventDefault(); post('/events'); }}>
                <label className="grid gap-1 text-xs font-semibold text-muted">
                    Event name
                    <input className="input w-72" required placeholder="SAFETY MEETING" value={data.name} onChange={(e) => setData('name', e.target.value)} />
                </label>
                <label className="grid gap-1 text-xs font-semibold text-muted">
                    Date
                    <input className="input" type="date" required value={data.event_date} onChange={(e) => setData('event_date', e.target.value)} />
                </label>
                <button className="btn btn-primary" disabled={processing}>Create event</button>
                {(errors.name || errors.event_date) && <span className="text-sm text-bad">{errors.name || errors.event_date}</span>}
            </form>
        </Panel>
    );
}

export default function EventsIndex({ events, today }) {
    return (
        <AdminLayout>
            <Head title="Events" />
            <div className="grid gap-4">
                <NewEvent today={today} />
                <Panel title="Events">
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead>
                                <tr>{['Date', 'Event', 'Status', 'Present', 'On list', 'To confirm'].map((h) => <th key={h} className="th">{h}</th>)}</tr>
                            </thead>
                            <tbody>
                                {!events.length && <tr><td colSpan={6} className="td py-6 text-center text-muted">No events yet. Create the first one above.</td></tr>}
                                {events.map((e) => (
                                    <tr key={e.id} className="hover:bg-slate-50">
                                        <td className="td tabular-nums whitespace-nowrap">{e.date}</td>
                                        <td className="td"><Link href={`/events/${e.id}`} className="font-semibold text-navy hover:underline">{e.name}</Link></td>
                                        <td className="td">{e.is_open ? <Pill tone="ok">open</Pill> : <Pill>closed</Pill>}</td>
                                        <td className="td tabular-nums"><b>{e.present}</b></td>
                                        <td className="td tabular-nums">{e.attendees || <span className="text-warn">no list yet</span>}</td>
                                        <td className="td tabular-nums">{e.waiting ? <Pill tone="warn">{e.waiting}</Pill> : ''}</td>
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
