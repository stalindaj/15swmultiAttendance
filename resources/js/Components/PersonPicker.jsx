import axios from 'axios';
import { useEffect, useState } from 'react';
import { personLine, personMeta } from '../lib/people';
import { Modal } from './ui';

/** Search the roster and pick one person. */
export default function PersonPicker({ open, title, initial = '', onPick, onClose }) {
    const [q, setQ] = useState(initial);
    const [results, setResults] = useState([]);

    useEffect(() => {
        if (open) setQ(initial);
    }, [open, initial]);

    useEffect(() => {
        if (!open || q.trim().length < 2) {
            setResults([]);
            return;
        }
        const t = setTimeout(async () => {
            const { data } = await axios.get('/search', { params: { q } });
            setResults(data);
        }, 200);
        return () => clearTimeout(t);
    }, [q, open]);

    return (
        <Modal open={open} title={title} onClose={onClose}>
            <input
                className="input w-full"
                type="search"
                autoFocus
                placeholder="Family name, first name or SN…"
                value={q}
                onChange={(e) => setQ(e.target.value)}
            />
            <div className="mt-2 grid gap-1.5">
                {results.map((p) => (
                    <button
                        key={p.id}
                        onClick={() => onPick(p)}
                        className="flex w-full items-center gap-2 rounded-lg border border-line bg-slate-50 px-3 py-2 text-left hover:border-navy"
                    >
                        <span className="min-w-0 flex-1">
                            <b>{personLine(p)}</b>
                            <br />
                            <span className="text-xs text-muted">{personMeta(p)}{p.walk_in && ' · walk-in'}</span>
                        </span>
                        <span className="btn btn-sm">Choose</span>
                    </button>
                ))}
                {q.trim().length >= 2 && !results.length && <p className="text-sm text-muted">No match.</p>}
            </div>
        </Modal>
    );
}
