import axios from 'axios';
import { useState } from 'react';
import { Notice } from './ui';

const errorText = (err) => err.response?.data?.error || err.response?.data?.message || err.message;

/**
 * Upload a PSR / Excel / CSV file, pick the sheet, check the detected columns, import.
 * Used for the People roster and for each event's attendee list.
 *
 * baseUrl: '/roster' or '/events/5/attendees' (…/upload, …/preview/{token}, …/import)
 * modes:   [{ value, label }], the first is the default
 * describe(result): the message shown after a successful import
 */
export default function ImportWizard({ baseUrl, fields, modes, describe, onImported, confirmMode }) {
    const [upload, setUpload] = useState(null);      // { token, sheets, suggested }
    const [sheet, setSheet] = useState('');
    const [layout, setLayout] = useState(null);      // { header_row, headers, mapping, sample, total }
    const [mapping, setMapping] = useState({});
    const [mode, setMode] = useState(modes[0].value);
    const [fixedSquadron, setFixedSquadron] = useState('');
    const [msg, setMsg] = useState(null);            // { tone, text }
    const [busy, setBusy] = useState(false);

    const loadSheet = async (token, name) => {
        setSheet(name);
        setLayout(null);
        setMsg({ tone: 'warn', text: 'Reading sheet…' });
        try {
            const { data } = await axios.get(`${baseUrl}/preview/${token}`, { params: { sheet: name }, timeout: 120000 });
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
            const { data } = await axios.post(`${baseUrl}/upload`, fd, { timeout: 120000 });
            setUpload(data);
            await loadSheet(data.token, data.suggested || data.sheets[0].name);
        } catch (err) {
            setMsg({ tone: 'bad', text: errorText(err) });
        }
    };

    const runImport = async () => {
        if (confirmMode?.[mode] && !confirm(confirmMode[mode])) return;
        setBusy(true);
        setMsg({ tone: 'warn', text: 'Importing…' });
        try {
            const { data } = await axios.post(`${baseUrl}/import`, {
                token: upload.token, sheet, header_row: layout.header_row, mapping, mode, fixed_squadron: fixedSquadron,
            }, { timeout: 180000 });
            setMsg({ tone: 'ok', text: describe(data) });
            onImported?.(data);
        } catch (err) {
            setMsg({ tone: 'bad', text: errorText(err) });
        } finally {
            setBusy(false);
        }
    };

    return (
        <div className="grid gap-3">
            <div className="flex flex-wrap items-end gap-3">
                <label className="grid gap-1 text-xs font-semibold text-muted">
                    File (PSR .xlsx or CSV)
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
                                {modes.map((m) => <option key={m.value} value={m.value}>{m.label}</option>)}
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
    );
}
