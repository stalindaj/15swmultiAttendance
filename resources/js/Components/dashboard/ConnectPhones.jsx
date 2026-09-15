import { Link } from '@inertiajs/react';
import { Notice, Panel, Pill } from '../ui';

function QrFigure({ url, label }) {
    return (
        <figure className="m-0 text-center">
            <img src={`/qr.svg?data=${encodeURIComponent(url)}`} alt={`QR code: ${label}`} className="h-[170px] w-[170px] rounded-lg border border-line bg-white" />
            <figcaption className="mt-1 max-w-[220px] font-mono text-xs break-all">
                <b className="font-sans">{label}</b>
                <br />
                {url}
            </figcaption>
        </figure>
    );
}

const secondsSince = (hms, now) => {
    if (!hms || !now) return Infinity;
    const toS = (t) => t.split(':').reduce((a, b) => a * 60 + Number(b), 0);
    return toS(now) - toS(hms);
};

export default function ConnectPhones({ connect, phones, serverTime }) {
    const { online, online_url: onlineUrl, lan_urls: lanUrls } = connect;
    const connecting = online?.status === 'starting' || online?.status === 'restarting';

    return (
        <Panel title="Phones" actions={<Link href="/accounts" className="btn btn-sm">Manage accounts</Link>}>
            <div className="px-4 py-3">
                {phones.length ? (
                    <ul className="mb-3 grid gap-1.5">
                        {phones.map((p) => {
                            const live = secondsSince(p.last_seen, serverTime) < 30;
                            return (
                                <li key={p.id} className="flex items-center gap-2 text-sm">
                                    <span className={`h-2.5 w-2.5 rounded-full ${live ? 'bg-ok' : 'bg-slate-300'}`} title={live ? 'Online' : 'Not connected'} />
                                    <b className="flex-1">{p.name}</b>
                                    <span className="text-muted tabular-nums">{p.scans} scans{p.last_scan && ` · last ${p.last_scan}`}</span>
                                    {!live && <Pill>{p.last_seen ? `seen ${p.last_seen}` : 'not logged in'}</Pill>}
                                </li>
                            );
                        })}
                    </ul>
                ) : (
                    <Notice>No phone accounts yet. <Link href="/accounts" className="underline">Create Gate 1, Gate 2, Gate 3</Link>.</Notice>
                )}

                <ol className="list-decimal pl-5 text-sm">
                    <li>On each phone, scan a QR code below with the camera app.</li>
                    <li>Log in with that phone's account (gate1, gate2…).</li>
                    <li>Tap <b>Start camera</b> and allow camera access.</li>
                </ol>
                <div className="mt-3 flex flex-wrap items-start gap-4">
                    {onlineUrl ? (
                        <QrFigure url={onlineUrl} label={online?.hosted ? '📱 Scanner address' : '🌐 Online (mobile data)'} />
                    ) : online?.status === 'off' || !online ? null : (
                        <Notice>
                            🌐 Online link {connecting ? 'connecting…' : 'unavailable'}
                            {online?.error && <><br />{online.error}</>}
                        </Notice>
                    )}
                    {lanUrls.slice(0, 1).map((u) => <QrFigure key={u} url={u} label={onlineUrl ? '📶 Same Wi-Fi (backup)' : '📶 Same Wi-Fi'} />)}
                </div>
            </div>
        </Panel>
    );
}
