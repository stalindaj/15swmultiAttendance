import { Head, router, useForm } from '@inertiajs/react';
import axios from 'axios';
import { useMemo, useState } from 'react';
import { Kpi, Notice, Panel, Pill } from '../Components/ui';
import { personLine } from '../lib/people';
import AdminLayout from '../Layouts/AdminLayout';

const errorText = (err) => err.response?.data?.error || err.response?.data?.message || err.message;

function ImportWizard({ fields }) {
    const [upload, setUpload] = useState(null);      // { token, sheets, suggested }
    const [sheet, setSheet] = useState('');
    const [layout, setLayout] = useState(null);      // { header_row, headers, mapping, sample, total }
    const [mapping, setMapping] = useState({});
    const [mode, setMode] = useState('upsert');
    const [fixedSquadron, setFixedSquadron] = useState('');
    const [msg, setMsg] = useState(null);            // { tone, text }
    const [busy, setBusy] = useState(false);

    const loadSheet = async (token, name) => {
        setSheet(name);
        setLayout(null);
        setMsg({ tone: 'warn', text: 'Reading sheet…' });
        try {
            const { data } = await axios.get(`/roster/preview/${token}`, { params: { sheet: name }, timeout: 120000 });
            setLayout(data);
            setMapping(data.mapping);
            setMsg(null);
        } catch (err) {
            setMsg({ tone: 'bad', text: errorText(err) });
        }
    };

    const choose = async (file) => {
        if (!file) return;
        setUpload(null);
        setLayout(null);
        setMsg({ tone: 'warn', text: 'Reading the file… (a big workbook takes a few seconds)' });
        const fd = new FormData();
        fd.append('file', file);
        try {
            const { data } = await axios.post('/roster/upload', fd, { timeout: 120000 });
            setUpload(data);
            await loadSheet(data.token, data.suggested || data.sheets[0].name);
        } catch (err) {
            setMsg({ tone: 'bad', text: errorText(err) });
        }
    };

    const runImport = async () => {
        if (mode === 'replace' && !confirm('Replace the whole roster with this file?')) return;
        setBusy(true);
        setMsg({ tone: 'warn', text: 'Importing…' });
        try {
            const { data } = await axios.post('/roster/import', {
                token: upload.token, sheet, header_row: layout.header_row, mapping, mode, fixed_squadron: fixedSquadron,
            }, { timeout: 180000 });
            setMsg({ tone: 'ok', text: `Done: ${data.added} added, ${data.updated} updated, ${data.skipped} rows skipped${data.not_found ? `, ${data.not_found} SNs not on the roster` : ''}.` });
            router.reload({ only: ['people', 'collisions', 'linkedQr'] });
        } catch (err) {
            setMsg({ tone: 'bad', text: errorText(err) });
        } finally {
            setBusy(false);
        }
    };

    return (
        <Panel title="Import personnel list" subtitle="Excel (.xlsx) or CSV, e.g. the Daily PSR">
            <div className="grid gap-3 px-4 py-3">
                <div className="flex flex-wrap items-end gap-3">
                    <label className="grid gap-1 text-xs font-semibold text-muted">
                        File
                        <input type="file" accept=".xlsx,.xlsm,.csv" onChange={(e) => choose(e.target.files[0])} className="text-sm" />
                    </label>
                    {upload?.sheets.length > 1 && (
                        <label className="grid gap-1 text-xs font-semibold text-muted">
                            Sheet
                            <select className="input" value={sheet} onChange={(e) => loadSheet(upload.token, e.target.value)}>
                                {upload.sheets.filter((s) => s.rows > 0).map((s) => <option key={s.name} value={s.name}>{s.name} ({s.rows} rows)</option>)}
                            </select>
                        </label>
                    )}
                </div>
                {msg && <Notice tone={msg.tone}>{msg.text}</Notice>}

                {layout && (
                    <>
                        <p className="text-sm text-muted">
                            Check each field points at the right column. Header on row <b>{layout.header_row + 1}</b>, <b>{layout.total}</b> rows below it.
                        </p>
                        <div className="grid grid-cols-[repeat(auto-fill,minmax(220px,1fr))] gap-2.5">
                            {Object.entries(fields).map(([f, label]) => (
                                <label key={f} className="grid gap-1 text-xs font-semibold">
                                    {label}
                                    <select className="input" value={mapping[f] ?? -1}
                                        onChange={(e) => setMapping({ ...mapping, [f]: Number(e.target.value) })}>
                                        <option value={-1}>— not in file —</option>
                                        {layout.headers.map((h, i) => <option key={i} value={i}>{h}</option>)}
                                    </select>
                                </label>
                            ))}
                        </div>
                        <div className="flex flex-wrap items-end gap-3">
                            <label className="grid gap-1 text-xs font-semibold text-muted">
                                Squadron for rows without one
                                <input className="input" placeholder="optional, e.g. CHR" value={fixedSquadron} onChange={(e) => setFixedSquadron(e.target.value)} />
                            </label>
                            <label className="grid gap-1 text-xs font-semibold text-muted">
                                Mode
                                <select className="input" value={mode} onChange={(e) => setMode(e.target.value)}>
                                    <option value="upsert">Add / update (match by SN)</option>
                                    <option value="update">Only update existing people (e.g. add OFFICE)</option>
                                    <option value="replace">Replace the whole roster</option>
                                </select>
                            </label>
                            <button className="btn btn-primary" disabled={busy} onClick={runImport}>Import</button>
                        </div>
                        <div className="overflow-x-auto">
                            <table className="w-full text-xs">
                                <thead><tr>{layout.headers.map((h, i) => <th key={i} className="th">{h}</th>)}</tr></thead>
                                <tbody>
                                    {layout.sample.map((r, i) => (
                                        <tr key={i}>{layout.headers.map((_, j) => <td key={j} className="td whitespace-nowrap">{r[j] ?? ''}</td>)}</tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </>
                )}
            </div>
        </Panel>
    );
}

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
                <ImportWizard fields={fields} />
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
