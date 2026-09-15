import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { offDuty, personLine, personMeta } from '../../lib/people';
import PersonPicker from '../PersonPicker';
import { Panel, Pill } from '../ui';

function chime() {
    try {
        const ctx = new (window.AudioContext || window.webkitAudioContext)();
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        osc.frequency.value = 880;
        gain.gain.value = 0.15;
        osc.connect(gain);
        gain.connect(ctx.destination);
        osc.start();
        osc.stop(ctx.currentTime + 0.15);
    } catch {
        /* audio is blocked until the page has been clicked once */
    }
}

const confirm = (qr_text, body) => router.post('/confirm', { qr_text, ...body }, { preserveScroll: true });

function Candidate({ c, officeMatch, qrRank, recommended, onConfirm }) {
    const rankDiffers = qrRank && c.rank && !c.rank_match;
    return (
        <button
            onClick={onConfirm}
            className={`mt-1.5 flex w-full items-center gap-2 rounded-lg border px-3 py-2 text-left hover:border-navy ${officeMatch ? 'border-green-200 bg-ok-soft' : 'border-line bg-slate-50'}`}
        >
            <span className="min-w-0 flex-1">
                <b>{personLine(c)}</b>
                <br />
                <span className="text-xs text-muted">
                    {personMeta(c)}
                    {offDuty(c) && ` · PSR: ${c.psr_status}`}
                </span>
            </span>
            {c.on_list && <Pill tone="ok">on list</Pill>}
            {officeMatch && <Pill tone="ok">office match</Pill>}
            {c.rank_match && <Pill tone="dup">rank match</Pill>}
            {c.first_match && <Pill tone="dup">first name match</Pill>}
            {rankDiffers && <Pill tone="warn">rank ≠ {qrRank}</Pill>}
            <span className={`btn btn-sm ${recommended ? 'btn-ok' : ''}`}>Confirm</span>
        </button>
    );
}

function Item({ p, onSearch }) {
    const fam = p.parsed.surname || '?';
    const office = p.parsed.unit;
    const all = p.office_matches.length + p.others.length;
    const summary = !all
        ? `No “${fam}” on the roster.`
        : office && p.office_matches.length
          ? `${all} named ${fam} · ${p.office_matches.length} in ${office}`
          : office
            ? `${all} named ${fam} · none in ${office}. Check carefully.`
            : `${all} named ${fam}`;
    const only = p.office_matches.length === 1 ? p.office_matches[0] : null;
    const recommendedId = only && (only.rank_match || !p.parsed.rank) ? only.id : null;

    const walkIn = () => {
        const first = prompt(`Add “${p.qr_text}” as a walk-in (not on the roster).\nFirst name (optional):`, '');
        if (first !== null) confirm(p.qr_text, { new_person: { first_name: first.trim() } });
    };

    return (
        <div className="border-b border-line px-4 py-3">
            <div>
                <span className="rounded-md bg-warn-soft px-2 py-0.5 font-mono font-bold text-warn">{p.qr_text}</span>{' '}
                <span className="text-xs text-muted">
                    {p.time} · {p.count}× · {p.stations}
                </span>
            </div>
            <div className="mt-1 text-sm">{summary}</div>
            {p.office_matches.map((c) => (
                <Candidate key={c.id} c={c} officeMatch qrRank={p.parsed.rank} recommended={c.id === recommendedId}
                    onConfirm={() => confirm(p.qr_text, { personnel_id: c.id })} />
            ))}
            {p.others.map((c) => (
                <Candidate key={c.id} c={c} qrRank={p.parsed.rank} onConfirm={() => confirm(p.qr_text, { personnel_id: c.id })} />
            ))}
            <div className="mt-2 flex flex-wrap gap-1.5">
                <button className="btn btn-sm" onClick={() => onSearch(p)}>Search other name</button>
                <button className="btn btn-sm" onClick={walkIn}>Add as walk-in</button>
            </div>
        </div>
    );
}

function AutoMatchControls({ autoMatch, waiting }) {
    const toggle = (e) => router.post('/settings', { auto_match: e.target.checked }, { preserveScroll: true });
    return (
        <div className="flex flex-wrap items-center gap-3 border-b border-line bg-slate-50 px-4 py-2 text-sm">
            <label className="flex items-center gap-2 font-semibold">
                <input type="checkbox" checked={autoMatch} onChange={toggle} />
                Match automatically
            </label>
            <span className="text-xs text-muted">Rank + family name (then office) points to one person → recorded without asking.</span>
            {autoMatch && waiting > 0 && (
                <button className="btn btn-sm ml-auto" onClick={() => router.post('/confirm/auto', {}, { preserveScroll: true })}>
                    Match waiting IDs now
                </button>
            )}
        </div>
    );
}

export default function ToConfirm({ pending, autoMatch }) {
    const [searching, setSearching] = useState(null);
    const seen = useRef(null);

    // Chime when a new ID arrives (not on first load).
    useEffect(() => {
        const now = new Set(pending.map((p) => p.qr_text));
        if (seen.current && [...now].some((q) => !seen.current.has(q))) chime();
        seen.current = now;
    }, [pending]);

    return (
        <Panel title="To confirm" subtitle="Family name → office → confirm. Next time that ID records instantly.">
            <AutoMatchControls autoMatch={autoMatch} waiting={pending.length} />
            {pending.length ? pending.map((p) => <Item key={p.qr_text} p={p} onSearch={setSearching} />)
                : <p className="px-4 py-6 text-center text-sm text-muted">Nothing to confirm.</p>}
            <PersonPicker
                open={Boolean(searching)}
                title={searching ? `Who is “${searching.qr_text}”?` : ''}
                initial={searching?.parsed.surname || ''}
                onClose={() => setSearching(null)}
                onPick={(person) => {
                    confirm(searching.qr_text, { personnel_id: person.id });
                    setSearching(null);
                }}
            />
        </Panel>
    );
}
