import { router } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { personLine, personMeta } from '../../lib/people';
import ImportWizard from '../ImportWizard';
import { Panel } from '../ui';

const MODES = [
    { value: 'replace', label: 'Set the list to this file' },
    { value: 'add', label: 'Add this file to the list' },
];

/** The event's attendee list: upload it from a PSR / Excel file, see it, remove people. */
export default function AttendeeList({ event, fields, expected, attendees, startOpen }) {
    const [open, setOpen] = useState(startOpen);
    const [showList, setShowList] = useState(false);
    const [filter, setFilter] = useState('');

    const rows = useMemo(() => {
        const f = filter.trim().toLowerCase();
        const list = attendees ?? [];
        return f ? list.filter((p) => [p.rank, p.name, p.squadron, p.office, p.serial].join(' ').toLowerCase().includes(f)) : list;
    }, [attendees, filter]);

    const toggleList = () => {
        if (!showList) router.reload({ only: ['attendees'] });
        setShowList(!showList);
    };

    const remove = (p) => {
        if (confirm(`Remove ${personLine(p)} from this event's list?`)) {
            router.delete(`/events/${event.id}/attendees/${p.id}`, { preserveScroll: true, onSuccess: () => router.reload({ only: ['attendees'] }) });
        }
    };

    return (
        <Panel
            title={`Attendee list · ${expected}`}
            subtitle="Upload the PSR / Excel list of who should attend. People not in the roster yet are added to it."
            actions={
                <>
                    {expected > 0 && <button className="btn btn-sm" onClick={toggleList}>{showList ? 'Hide list' : 'Show list'}</button>}
                    <button className={`btn btn-sm ${open ? '' : 'btn-primary'}`} onClick={() => setOpen(!open)}>{open ? 'Close upload' : 'Upload list'}</button>
                </>
            }
        >
            {open && (
                <div className="border-b border-line px-4 py-3">
                    <ImportWizard
                        baseUrl={`/events/${event.id}/attendees`}
                        fields={fields}
                        modes={MODES}
                        confirmMode={expected > 0 ? { replace: `Replace the ${expected} people on this event's list with this file?` } : undefined}
                        describe={(r) => `Done: ${r.on_list} people from the file are on the list (${r.new_people} new to the roster, ${r.skipped} rows skipped). The list now has ${r.total}.`}
                        onImported={() => router.reload({ only: ['stats', 'bySquadron', 'absent', 'attendees'] })}
                    />
                </div>
            )}
            {showList && (
                <div className="px-4 py-3">
                    <input className="input mb-2 w-full" type="search" placeholder="Filter…" value={filter} onChange={(e) => setFilter(e.target.value)} />
                    <div className="max-h-[420px] overflow-auto">
                        {!attendees && <p className="text-sm text-muted">Loading…</p>}
                        {rows.slice(0, 1500).map((p) => (
                            <div key={p.id} className="flex items-center gap-2 border-b border-line py-1.5 text-sm">
                                <span className="min-w-0 flex-1"><b>{personLine(p)}</b> <span className="text-xs text-muted">{personMeta(p)}</span></span>
                                <button className="btn btn-sm btn-danger" title="Remove from this event" onClick={() => remove(p)}>✕</button>
                            </div>
                        ))}
                    </div>
                </div>
            )}
        </Panel>
    );
}
