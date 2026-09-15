import { router } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { Panel } from '../ui';

export default function AbsentTable({ absent }) {
    const [tab, setTab] = useState('unaccounted');
    const [filter, setFilter] = useState('');

    const rows = useMemo(() => {
        const f = filter.trim().toLowerCase();
        const list = absent?.[tab] ?? [];
        return f ? list.filter((p) => [p.rank, p.name, p.squadron, p.office, p.serial, p.psr_status].join(' ').toLowerCase().includes(f)) : list;
    }, [absent, tab, filter]);

    const tabBtn = (key, label) => (
        <button
            onClick={() => setTab(key)}
            className={`rounded-lg px-2.5 py-1.5 text-sm font-semibold ${tab === key ? 'bg-slate-100 text-ink' : 'text-muted'}`}
        >
            {label} {absent && `(${absent[key].length})`}
        </button>
    );

    return (
        <Panel
            title="Not yet scanned"
            actions={
                <>
                    {tabBtn('unaccounted', 'Unaccounted')}
                    {tabBtn('excused', 'Excused per PSR')}
                    <input className="input" type="search" placeholder="Filter name, squadron, office…" value={filter} onChange={(e) => setFilter(e.target.value)} />
                </>
            }
        >
            <div className="max-h-[480px] overflow-auto">
                <table className="w-full text-sm">
                    <thead>
                        <tr>{['Rank', 'Name', 'Squadron', 'Office', 'PSR status', ''].map((h) => <th key={h} className="th">{h}</th>)}</tr>
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
                                <td className="td">{p.psr_status}</td>
                                <td className="td">
                                    <button className="btn btn-sm" onClick={() => router.post(`/present/${p.id}`, {}, { preserveScroll: true })}>
                                        Mark present
                                    </button>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </Panel>
    );
}
