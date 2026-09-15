import { Head, router, useForm } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import ImportWizard from '../Components/ImportWizard';
import { Kpi, Panel, Pill } from '../Components/ui';
import { personLine } from '../lib/people';
import AdminLayout from '../Layouts/AdminLayout';

const ROSTER_MODES = [
    { value: 'upsert', label: 'Add / update (match by SN)' },
    { value: 'update', label: 'Only update existing people (e.g. add OFFICE)' },
    { value: 'replace', label: 'Replace the whole roster' },
];

function AddPerson() {
    const { data, setData, post, processing, errors, reset } = useForm({ rank: '', surname: '', first_name: '', middle: '', office: '', squadron: '', serial: '' });
    const input = (key, label, props = {}) => (
        <label className="grid gap-1 text-xs font-semibold text-muted">
            {label}
            <input className="input" value={data[key]} onChange={(e) => setData(key, e.target.value)} {...props} />
        </label>
    );
    return (
        <Panel title="Add a person" subtitle="For people not in the PSR, e.g. civilian (CHR) staff">
            <form className="flex flex-wrap items-end gap-3 px-4 py-3" onSubmit={(e) => { e.preventDefault(); post('/roster/person', { preserveScroll: true, onSuccess: () => reset() }); }}>
                {input('rank', 'Rank / category', { placeholder: 'CHR', size: 8 })}
                {input('surname', 'Family name*', { required: true })}
                {input('first_name', 'First name')}
                {input('middle', 'MI', { size: 3 })}
                {input('office', 'Office', { placeholder: 'CEIS', size: 10 })}
                {input('squadron', 'Squadron', { size: 10 })}
                {input('serial', 'SN / ID no.', { size: 12 })}
                <button className="btn btn-primary" disabled={processing}>Add</button>
                {errors.surname && <span className="text-sm text-bad">{errors.surname}</span>}
            </form>
        </Panel>
    );
}

export default function Roster({ fields, people, collisions, linkedQr }) {
    const [filter, setFilter] = useState('');
    const rows = useMemo(() => {
        const f = filter.trim().toLowerCase();
        return f ? people.filter((p) => [p.rank, p.name, p.squadron, p.office, p.serial, p.psr_status].join(' ').toLowerCase().includes(f)) : people;
    }, [people, filter]);
    const roster = people.filter((p) => !p.walk_in);

    const remove = (p) => {
        if (confirm(`Remove ${personLine(p)} from the roster?`)) router.delete(`/roster/person/${p.id}`, { preserveScroll: true });
    };

    return (
        <AdminLayout>
            <Head title="Roster" />
            <section className="mb-4 grid grid-cols-[repeat(auto-fit,minmax(160px,1fr))] gap-3">
                <Kpi label="On roster" value={roster.length} />
                <Kpi label="With office" value={roster.filter((p) => p.office).length} />
                <Kpi label="Walk-ins added" value={people.length - roster.length} />
                <Kpi label="IDs confirmed" value={linkedQr} sub="QR codes already linked to a person" />
            </section>
            <div className="grid gap-4">
                <Panel title="Import personnel list" subtitle="Excel (.xlsx) or CSV, e.g. the Daily PSR. Event attendee lists also add people here.">
                    <div className="px-4 py-3">
                        <ImportWizard
                            baseUrl="/roster"
                            fields={fields}
                            modes={ROSTER_MODES}
                            confirmMode={{ replace: 'Replace the whole roster with this file?' }}
                            describe={(r) => `Done: ${r.added} added, ${r.updated} updated, ${r.skipped} rows skipped${r.not_found ? `, ${r.not_found} SNs not on the roster` : ''}.`}
                            onImported={() => router.reload({ only: ['people', 'collisions', 'linkedQr'] })}
                        />
                    </div>
                </Panel>
                <AddPerson />
                {collisions.length > 0 && (
                    <Panel title="Same family name and office" subtitle="The QR alone can't tell these apart; confirm their first scan carefully.">
                        <div className="max-h-60 overflow-auto px-4 py-3 text-sm">
                            {collisions.map((g, i) => (
                                <div key={i} className="mb-1">• {g.map((p) => `${personLine(p)} (${p.office || p.squadron})`).join('  /  ')}</div>
                            ))}
                        </div>
                    </Panel>
                )}
                <Panel title="Roster" actions={<input className="input" type="search" placeholder="Filter…" value={filter} onChange={(e) => setFilter(e.target.value)} />}>
                    <div className="max-h-[600px] overflow-auto">
                        <table className="w-full text-sm">
                            <thead><tr>{['Rank', 'Name', 'Squadron', 'Office', 'SN', 'PSR status', ''].map((h) => <th key={h} className="th">{h}</th>)}</tr></thead>
                            <tbody>
                                {!rows.length && <tr><td colSpan={7} className="td py-5 text-center text-muted">No one yet. Import your PSR above.</td></tr>}
                                {rows.slice(0, 1500).map((p) => (
                                    <tr key={p.id}>
                                        <td className="td">{p.rank}</td>
                                        <td className="td">{p.name} {p.walk_in && <Pill tone="warn">walk-in</Pill>}</td>
                                        <td className="td">{p.squadron}</td>
                                        <td className="td">{p.office}</td>
                                        <td className="td font-mono">{p.serial}</td>
                                        <td className="td">{p.psr_status}</td>
                                        <td className="td"><button className="btn btn-sm btn-danger" title="Remove" onClick={() => remove(p)}>✕</button></td>
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
