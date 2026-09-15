import { useEffect, useRef } from 'react';

export function Panel({ title, subtitle, actions, children, className = '' }) {
    return (
        <section className={`panel ${className}`}>
            {(title || actions) && (
                <header className="panel-head">
                    {title && <h2 className="text-[15px] font-bold">{title}</h2>}
                    {subtitle && <span className="text-xs text-muted">{subtitle}</span>}
                    {actions && <div className="ml-auto flex flex-wrap items-center gap-2">{actions}</div>}
                </header>
            )}
            {children}
        </section>
    );
}

const KPI_TONES = { ok: 'text-ok', bad: 'text-bad', warn: 'text-warn', plain: 'text-ink' };

export function Kpi({ label, value, sub, tone = 'plain', progress }) {
    return (
        <div className="rounded-xl border border-line bg-white px-4 py-3">
            <div className="text-[13px] font-semibold text-muted">{label}</div>
            <div className={`text-3xl leading-tight font-extrabold tabular-nums ${KPI_TONES[tone]}`}>{value}</div>
            {sub && <div className="text-[13px] text-muted">{sub}</div>}
            {progress !== undefined && (
                <div className="mt-1.5 h-1.5 overflow-hidden rounded bg-slate-200">
                    <div className="h-full bg-ok transition-[width]" style={{ width: `${progress}%` }} />
                </div>
            )}
        </div>
    );
}

const PILL_TONES = {
    ok: 'bg-ok-soft text-ok',
    dup: 'bg-dup-soft text-dup',
    warn: 'bg-warn-soft text-warn',
    bad: 'bg-bad-soft text-bad',
    in: 'bg-ok-soft text-ok',
    out: 'bg-out-soft text-out',
    plain: 'bg-slate-100 text-muted',
};

export function Pill({ tone = 'plain', children }) {
    return <span className={`inline-block rounded-full px-2 py-px text-xs font-semibold ${PILL_TONES[tone]}`}>{children}</span>;
}

export function Modal({ open, title, onClose, children, footer }) {
    const ref = useRef(null);
    useEffect(() => {
        const d = ref.current;
        if (!d) return;
        if (open && !d.open) d.showModal();
        if (!open && d.open) d.close();
    }, [open]);

    return (
        <dialog
            ref={ref}
            onClose={onClose}
            className="m-auto w-[min(560px,calc(100vw-32px))] rounded-2xl p-0 shadow-2xl backdrop:bg-slate-900/45"
        >
            <header className="flex items-center gap-2 border-b border-line px-4 py-3">
                <h3 className="flex-1 text-base font-bold">{title}</h3>
                <button className="btn btn-sm" onClick={onClose} aria-label="Close">✕</button>
            </header>
            <div className="max-h-[60vh] overflow-auto px-4 py-3">{children}</div>
            {footer && <footer className="flex flex-wrap justify-end gap-2 border-t border-line px-4 py-3">{footer}</footer>}
        </dialog>
    );
}

export function Notice({ tone = 'warn', children }) {
    const tones = { warn: 'bg-warn-soft text-warn', ok: 'bg-ok-soft text-ok', bad: 'bg-bad-soft text-bad' };
    return <div className={`rounded-lg px-3.5 py-2.5 text-sm ${tones[tone]}`}>{children}</div>;
}
