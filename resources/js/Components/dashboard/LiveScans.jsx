import { router } from '@inertiajs/react';
import { useState } from 'react';
import { personLine } from '../../lib/people';
import PersonPicker from '../PersonPicker';
import { Panel, Pill } from '../ui';

const STATUS_TONE = { ok: 'ok', duplicate: 'dup', pending: 'warn', void: 'plain' };

export default function LiveScans({ recent }) {
    const [changing, setChanging] = useState(null);

    const cancel = (s) => {
        if (confirm('Cancel this scan?')) router.post(`/scans/${s.id}/void`, {}, { preserveScroll: true });
    };

    return (
        <Panel title="Live scans" actions={recent.length ? <span className="text-xs text-muted">latest {recent.length}</span> : null}>
            <div className="max-h-[760px] overflow-auto">
                {!recent.length && <p className="px-4 py-6 text-center text-sm text-muted">No scans yet.</p>}
                {recent.map((s) => (
                    <div key={s.id} className={`grid grid-cols-[52px_1fr_auto] items-start gap-x-2.5 border-b border-line px-4 py-2 ${s.status === 'void' ? 'line-through opacity-45' : ''}`}>
                        <div className="font-bold tabular-nums">{s.time}</div>
                        <div className="min-w-0">
                            <div className="font-semibold">{s.person ? personLine(s.person) : <span className="font-mono">{s.qr_text}</span>}</div>
                            <div className="flex flex-wrap items-center gap-1 text-xs text-muted">
                                <Pill tone={s.kind === 'OUT' ? 'out' : 'in'}>{s.kind}</Pill>
                                <Pill tone={STATUS_TONE[s.status]}>{s.status}</Pill>
                                {s.person && [s.person.squadron, s.person.office].filter(Boolean).join(' · ')}
                                {s.person?.walk_in && <Pill tone="warn">walk-in</Pill>}
                                {s.auto && <span title={`Matched automatically from “${s.qr_text}”. Wrong? Click Change.`}><Pill tone="dup">auto</Pill></span>}
                                <span>· {s.station}{s.method && ` (${s.method})`}</span>
                            </div>
                        </div>
                        {s.status !== 'void' && (
                            <div className="flex gap-1">
                                <button className="btn btn-sm" title="Wrong person? Change it" onClick={() => setChanging(s)}>Change</button>
                                <button className="btn btn-sm btn-danger" title="Cancel this scan" onClick={() => cancel(s)}>✕</button>
                            </div>
                        )}
                    </div>
                ))}
            </div>
            <PersonPicker
                open={Boolean(changing)}
                title="Who is this scan really for?"
                onClose={() => setChanging(null)}
                onPick={(p) => {
                    router.post(`/scans/${changing.id}/assign`, { personnel_id: p.id }, { preserveScroll: true });
                    setChanging(null);
                }}
            />
        </Panel>
    );
}
