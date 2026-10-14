import { router } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { Panel } from '../ui';

// One absent list on screen; the Excel export is what splits excused from unaccounted.
const TABS = [
    ['absent', 'Absent'],
    ['present', 'Present'],
    ['extras', 'Extras (not on list)'],
];

const TITLES = {
    absent: 'On the list, not yet scanned',
    present: 'Counted present',
    extras: 'Came, but not on the list',
};

export default function AbsentTable({ eventId, absent }) {
    const [tab, setTab] = useState('absent');
    const [filter, setFilter] = useState('');

    const rows = useMemo(() => {
        const f = filter.trim().toLowerCase();
        const list = absent?.[tab] ?? [];
        return f ? list.filter((p) => [p.rank, p.name, p.squadron, p.office, p.serial, p.psr_status].join(' ').toLowerCase().includes(f)) : list;
    }, [absent, tab, filter]);

    const reload = () => router.reload({ only: ['stats', 'bySquadron', 'arrivals', 'recent', 'absent'] });

    const markPresent = (p) => router.post(`/events/${eventId}/present/${p.id}`, {}, { preserveScroll: true, onSuccess: reload });

    const removePresent = (p) => {
        if (confirm(`Remove ${p.rank} ${p.name} from the present list? Their scans for this event are deleted.`)) {
            router.delete(`/events/${eventId}/present/${p.id}`, { preserveScroll: true, onSuccess: reload });
        }
    };

    return (
        <Panel
            title={TITLES[tab]}
            subtitle={tab === 'absent' ? 'Everyone still missing. The export marks who the PSR excuses (deployed, leave, DS…).' : undefined}
            actions={
                <>
                    {TABS.map(([key, label]) => (
                        <button
                            key={key}
                            onClick={() => setTab(key)}
                            className={`rounded-lg px-2.5 py-1.5 text-sm font-semibold ${tab === key ? 'bg-slate-100 text-ink' : 'text-muted'}`}
                        >
                            {label} {absent && `(${absent[key].length})`}
                        </button>
                    ))}
                    <input className="input" type="search" placeholder="Filter name, squadron, office…" value={filter} onChange={(e) => setFilter(e.target.value)} />
                </>
            }
        >
            <div className="max-h-[480px] overflow-auto">
                <table className="w-full text-sm">
                    <thead>
                        <tr>{['Rank', 'Name', 'Squadron', 'Office', tab === 'present' ? 'Time' : 'PSR status', ''].map((h) => <th key={h} className="th">{h}</th>)}</tr>
                    </thead>
                    <tbody>
                        {!absent && <tr><td colSpan={6} className="td py-5 text-center text-muted">Loading…</td></tr>}
                        {absent && !rows.length && <tr><td colSpan={6} className="td py-5 text-center text-muted">Nobody here.</td></tr>}
                        {rows.slice(0, 1000).map((p) => (
                            <tr key={p.id}>
                                <td className="td">{p.rank}</td>
                                <td className="td">{p.name}</td>
                                <td className="td">{p.squadron}</td>
                                <td className="td">{p.office}</td>
                                <td className="td">
                                    {tab === 'present' ? <>{p.time} {p.status === 'OUT' && <span className="text-xs text-muted">(out)</span>}</> : p.psr_status}
                                </td>
                                <td className="td">
                                    {tab === 'absent' && <button className="btn btn-sm" onClick={() => markPresent(p)}>Mark present</button>}
                                    {tab !== 'absent' && <button className="btn btn-sm btn-danger" onClick={() => removePresent(p)}>Remove</button>}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </Panel>
    );
}
