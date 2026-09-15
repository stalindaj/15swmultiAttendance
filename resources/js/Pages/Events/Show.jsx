import { Head, Link, router, usePoll } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import AbsentTable from '../../Components/dashboard/AbsentTable';
import AttendeeList from '../../Components/dashboard/AttendeeList';
import { ArrivalsChart, SquadronChart } from '../../Components/dashboard/Charts';
import ConnectPhones from '../../Components/dashboard/ConnectPhones';
import LiveScans from '../../Components/dashboard/LiveScans';
import ToConfirm from '../../Components/dashboard/ToConfirm';
import { Kpi, Panel, Pill } from '../../Components/ui';
import AdminLayout from '../../Layouts/AdminLayout';

const LIVE_PROPS = ['stats', 'bySquadron', 'arrivals', 'pending', 'recent', 'autoMatch', 'connect', 'phones', 'serverTime'];

function Toolbar({ event }) {
    const [name, setName] = useState(event.name);
    useEffect(() => setName(event.name), [event.name]);
    const save = (data) => router.patch(`/events/${event.id}`, data, { preserveScroll: true });

    return (
        <div className="flex flex-wrap items-center gap-2">
            <Link href="/" className="text-sm text-white/80 hover:text-white">← Events</Link>
            <input
                className="min-w-[16ch] rounded-lg border border-dashed border-white/40 bg-transparent px-2 py-1 text-sm font-semibold text-white"
                value={name}
                title="Event name (click to edit)"
                onChange={(e) => setName(e.target.value)}
                onBlur={() => name.trim() && name !== event.name && save({ name })}
                onKeyDown={(e) => e.key === 'Enter' && e.currentTarget.blur()}
            />
            <input
                type="date"
                className="rounded-lg bg-white px-2 py-1 text-sm text-ink"
                value={event.date}
                onChange={(e) => e.target.value && save({ event_date: e.target.value })}
            />
            <button
                className={`btn btn-sm ${event.is_open ? '' : 'btn-ok'}`}
                title={event.is_open ? 'Phones can scan into this event' : 'Phones cannot scan into this event'}
                onClick={() => save({ is_open: !event.is_open })}
            >
                {event.is_open ? '● Open · close it' : 'Closed · reopen'}
            </button>
            <a className="btn btn-sm" href={`/events/${event.id}/export`}>⬇ Export Excel</a>
        </div>
    );
}

export default function EventShow({ event, fields, stats, bySquadron, arrivals, pending, recent, autoMatch, connect, phones, serverTime, absent, attendees }) {
    usePoll(3000, { only: LIVE_PROPS });

    // The absent / extras lists are big; fetch them on their own, less often.
    useEffect(() => {
        const load = () => router.reload({ only: ['absent'], async: true });
        load();
        const t = setInterval(load, 15000);
        return () => clearInterval(t);
    }, [event.id]);

    const pct = stats.expected ? Math.round((stats.present * 100) / stats.expected) : 0;

    const clearScans = () => {
        if (confirm(`Delete every scan recorded for “${event.name}”? The attendee list is kept. This cannot be undone.`)) {
            router.post(`/events/${event.id}/scans/clear`, {}, { preserveScroll: true });
        }
    };
    const deleteEvent = () => {
        if (prompt(`Delete “${event.label}” with its list and all its scans? Type DELETE to confirm.`) === 'DELETE') {
            router.delete(`/events/${event.id}`);
        }
    };

    return (
        <AdminLayout toolbar={<Toolbar event={event} />}>
            <Head title={event.label} />
            {!event.is_open && <div className="mb-3"><Pill tone="bad">Closed: phones cannot scan into this event</Pill></div>}

            {stats.expected === 0 && (
                <div className="mb-4">
                    <AttendeeList event={event} fields={fields} expected={0} attendees={attendees} startOpen />
                </div>
            )}

            <section className="mb-4 grid grid-cols-[repeat(auto-fit,minmax(160px,1fr))] gap-3">
                <Kpi label="Present (on list)" value={stats.present} tone="ok" sub={`of ${stats.expected} on the list · ${pct}%`} progress={pct} />
                <Kpi label="Inside now" value={stats.inside} sub="Status IN (timed in, not out)" />
                <Kpi label="Unaccounted" value={stats.unaccounted} tone="bad" sub="On the list, on duty, not scanned" />
                <Kpi label="Excused per PSR" value={stats.excused} sub="Deployed, leave, DS, schooling…" />
                <Kpi label="Extras" value={stats.extras} sub="Came, but not on the list" />
                <Kpi label="To confirm" value={pending.length} tone="warn" sub="New IDs waiting for your check" />
            </section>

            <div className="grid items-start gap-4 lg:grid-cols-2 xl:grid-cols-[1.05fr_1.3fr_0.9fr]">
                <div className="grid gap-4">
                    <ToConfirm pending={pending} autoMatch={autoMatch} />
                    <ConnectPhones connect={connect} phones={phones} serverTime={serverTime} />
                </div>
                <LiveScans recent={recent} />
                <div className="grid gap-4 lg:col-span-2 xl:col-span-1">
                    <ArrivalsChart rows={arrivals} />
                    <SquadronChart rows={bySquadron} />
                </div>
            </div>

            <div className="mt-4 grid gap-4">
                <AbsentTable eventId={event.id} absent={absent} />
                {stats.expected > 0 && <AttendeeList event={event} fields={fields} expected={stats.expected} attendees={attendees} startOpen={false} />}
            </div>

            <Panel title="Danger zone" className="mt-4">
                <div className="flex flex-wrap gap-2 px-4 py-3">
                    <button className="btn btn-danger" onClick={clearScans}>Delete this event's scans</button>
                    <button className="btn btn-danger" onClick={deleteEvent}>Delete event</button>
                </div>
            </Panel>
        </AdminLayout>
    );
}
