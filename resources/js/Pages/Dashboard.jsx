import { Head, router, usePage, usePoll } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import AbsentTable from '../Components/dashboard/AbsentTable';
import { ArrivalsChart, SquadronChart } from '../Components/dashboard/Charts';
import ConnectPhones from '../Components/dashboard/ConnectPhones';
import LiveScans from '../Components/dashboard/LiveScans';
import ToConfirm from '../Components/dashboard/ToConfirm';
import { Kpi, Panel } from '../Components/ui';
import AdminLayout from '../Layouts/AdminLayout';

const LIVE_PROPS = ['stats', 'bySquadron', 'arrivals', 'pending', 'recent', 'autoMatch', 'connect', 'phones', 'serverTime'];

function Toolbar({ date }) {
    const { event } = usePage().props;
    const [name, setName] = useState(event);
    useEffect(() => setName(event), [event]);

    const saveName = () => {
        if (name.trim() && name !== event) router.post('/settings', { event_name: name }, { preserveScroll: true });
    };

    return (
        <div className="flex flex-wrap items-center gap-2">
            <input
                className="min-w-[14ch] rounded-lg border border-dashed border-white/40 bg-transparent px-2 py-1 text-sm text-white"
                value={name}
                title="Event name (click to edit)"
                onChange={(e) => setName(e.target.value)}
                onBlur={saveName}
                onKeyDown={(e) => e.key === 'Enter' && e.currentTarget.blur()}
            />
            <input
                type="date"
                className="rounded-lg bg-white px-2 py-1 text-sm text-ink"
                value={date}
                onChange={(e) => router.get('/', { date: e.target.value }, { preserveState: true, replace: true })}
            />
            <a className="btn btn-sm" href={`/export?date=${date}`}>⬇ Export Excel</a>
        </div>
    );
}

export default function Dashboard({ date, stats, bySquadron, arrivals, pending, recent, autoMatch, connect, phones, serverTime, absent }) {
    usePoll(3000, { only: LIVE_PROPS });

    // The absent list is big; fetch it on its own, less often.
    useEffect(() => {
        const load = () => router.reload({ only: ['absent'], async: true });
        load();
        const t = setInterval(load, 15000);
        return () => clearInterval(t);
    }, [date]);

    const pct = stats.roster_total ? Math.round((stats.present * 100) / stats.roster_total) : 0;

    const clear = (all) => {
        if (all ? prompt('This deletes ALL scans on every date. Type DELETE to confirm.') === 'DELETE'
                : confirm(`Delete every scan recorded on ${date}? This cannot be undone.`)) {
            router.post('/scans/clear', { date: all ? 'ALL' : date }, { preserveScroll: true });
        }
    };

    return (
        <AdminLayout toolbar={<Toolbar date={date} />}>
            <Head title="Dashboard" />
            <section className="mb-4 grid grid-cols-[repeat(auto-fit,minmax(160px,1fr))] gap-3">
                <Kpi label="Present (roster)" value={stats.present} tone="ok" sub={`of ${stats.roster_total} on roster · ${pct}%`} progress={pct} />
                <Kpi label="Inside now" value={stats.inside} sub="Status IN (timed in, not out)" />
                <Kpi label="Unaccounted" value={stats.unaccounted} tone="bad" sub="On duty per PSR, not scanned" />
                <Kpi label="Excused per PSR" value={stats.excused} sub="Deployed, leave, DS, schooling…" />
                <Kpi label="Walk-ins" value={stats.walk_ins} sub="Not on roster (e.g. CHR)" />
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

            <div className="mt-4">
                <AbsentTable absent={absent} />
            </div>

            <Panel title="Test data" subtitle="Delete practice scans before the real event. The roster and confirmed IDs are kept." className="mt-4">
                <div className="flex flex-wrap gap-2 px-4 py-3">
                    <button className="btn btn-danger" onClick={() => clear(false)}>Delete scans for this date</button>
                    <button className="btn btn-danger" onClick={() => clear(true)}>Delete ALL scans</button>
                </div>
            </Panel>
        </AdminLayout>
    );
}
