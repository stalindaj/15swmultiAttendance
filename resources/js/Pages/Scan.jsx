import { Head, router, usePage } from '@inertiajs/react';
import axios from 'axios';
import { useCallback, useEffect, useRef, useState } from 'react';
import { feedback, unlockAudio } from '../lib/feedback';
import { personLine } from '../lib/people';
import { decodeQr, initDetector } from '../lib/qr';
import { LoggedOutError, flushQueue, queuedCount, sendScan } from '../lib/scanQueue';

const uid = () => (crypto.randomUUID ? crypto.randomUUID() : Date.now().toString(36) + Math.random().toString(36).slice(2));
const stored = (key, fallback) => {
    try {
        return JSON.parse(localStorage.getItem(key)) ?? fallback;
    } catch {
        return fallback;
    }
};
const store = (key, value) => {
    try {
        localStorage.setItem(key, JSON.stringify(value));
    } catch {
        /* storage unavailable */
    }
};

const TITLES = {
    sending: 'Sending…',
    ok: (kind) => (kind === 'OUT' ? 'TIME OUT ✓' : 'TIME IN ✓'),
    pending: 'SCANNED ✓',
    duplicate: 'ALREADY RECORDED',
    queued: 'SAVED ON PHONE',
    error: 'NOT RECORDED',
};
const BACKGROUNDS = {
    sending: 'bg-night/90',
    ok: 'bg-green-700',
    pending: 'bg-green-700',
    duplicate: 'bg-dup',
    queued: 'bg-amber-700',
    error: 'bg-bad',
};

function ResultOverlay({ result, onClose }) {
    if (!result) return null;
    const p = result.person;
    const title = typeof TITLES[result.status] === 'function' ? TITLES[result.status](result.kind) : TITLES[result.status];
    const bg = result.status === 'ok' && result.kind === 'OUT' ? 'bg-out' : BACKGROUNDS[result.status] || 'bg-bad';
    return (
        <div className={`fixed inset-0 z-30 grid place-items-center p-6 text-center text-white ${bg}`} onClick={onClose}>
            <div className="w-full max-w-md">
                <div className="text-4xl font-black tracking-wide">{title}</div>
                {p && <div className="mt-3 text-2xl font-extrabold">{personLine(p)}</div>}
                {p && <div className="mt-1 text-lg opacity-90">{[p.squadron, p.office].filter(Boolean).join(' · ')}</div>}
                {!p && result.qr_text && <div className="mt-2 font-mono text-lg">{result.qr_text}</div>}
                {result.time && <div className="mt-1 text-lg opacity-90">{result.time}</div>}
                {result.status !== 'ok' && result.message && <div className="mt-4 text-base">{result.message}</div>}
                {p?.walk_in && <div className="mt-2">Walk-in (not on roster)</div>}
            </div>
        </div>
    );
}

export default function Scan() {
    const { auth, event } = usePage().props;
    const [mode, setModeState] = useState(() => stored('mode', 'IN'));
    const [history, setHistory] = useState(() => stored('history', []));
    const [result, setResult] = useState(null);
    const [net, setNet] = useState({ state: 'online', queued: queuedCount() });
    const [camera, setCamera] = useState({ on: false, msg: 'Tap “Start camera”, then hold the ID’s QR code inside the box.' });

    const videoRef = useRef(null);
    const streamRef = useRef(null);
    const pausedRef = useRef(false);
    const lastRef = useRef({ text: '', at: 0 });
    const modeRef = useRef(mode);
    const wantCameraRef = useRef(false);
    const resultTimer = useRef(null);

    const setMode = (m) => {
        setModeState(m);
        modeRef.current = m;
        store('mode', m);
    };

    const addHistory = useCallback((res) => {
        setHistory((h) => {
            const next = [{ t: res.time || new Date().toTimeString().slice(0, 5), s: res.status, k: res.kind, who: res.person ? personLine(res.person) : res.qr_text || '' }, ...h].slice(0, 15);
            store('history', next);
            return next;
        });
    }, []);

    const showResult = useCallback((res) => {
        clearTimeout(resultTimer.current);
        setResult(res);
        if (res.status === 'sending') return;
        const quick = res.status === 'ok' || res.status === 'pending';   // keep the line moving
        resultTimer.current = setTimeout(() => {
            setResult(null);
            pausedRef.current = false;
            lastRef.current.at = Date.now();
        }, quick ? 1800 : 3500);
    }, []);

    const onCode = useCallback(async (text, method) => {
        text = (text || '').trim();
        if (!text || pausedRef.current) return;
        const now = Date.now();
        if (text === lastRef.current.text && now - lastRef.current.at < 4000) return;   // same card still in front of the camera
        lastRef.current = { text, at: now };
        pausedRef.current = true;

        showResult({ status: 'sending' });
        const res = await sendScan({ client_id: uid(), qr_text: text, kind: modeRef.current, method, client_ts: now });
        showResult(res);
        feedback(res.status);
        addHistory(res);
        setNet({ state: res.loggedOut ? 'loggedOut' : res.status === 'queued' ? 'offline' : 'online', queued: queuedCount() });
    }, [addHistory, showResult]);

    // ------------------------------------------------------------ camera

    const stopCamera = () => {
        streamRef.current?.getTracks().forEach((t) => t.stop());
        streamRef.current = null;
        setCamera((c) => ({ ...c, on: false }));
    };

    const startCamera = useCallback(async () => {
        unlockAudio();
        wantCameraRef.current = true;
        if (!navigator.mediaDevices?.getUserMedia) {
            setCamera({ on: false, msg: 'The live camera is not available on this link. Use “Take photo”.' });
            return;
        }
        try {
            streamRef.current = await navigator.mediaDevices.getUserMedia({
                video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 }, height: { ideal: 720 } },
                audio: false,
            });
        } catch (e) {
            setCamera({ on: false, msg: `Camera blocked (${e.name}). Allow camera access for this site, or use “Take photo”.` });
            return;
        }
        const video = videoRef.current;
        video.srcObject = streamRef.current;
        await video.play().catch(() => {});
        await initDetector();
        try {
            await navigator.wakeLock?.request('screen');
        } catch {
            /* optional */
        }
        setCamera({ on: true, msg: '' });
    }, []);

    useEffect(() => {
        if (!camera.on) return;
        let stopped = false;
        const loop = async () => {
            const video = videoRef.current;
            if (stopped || !streamRef.current) return;
            if (!pausedRef.current && video.readyState >= 2) {
                try {
                    const text = await decodeQr(video, video.videoWidth, video.videoHeight);
                    if (text) await onCode(text, 'camera');
                } catch {
                    /* keep scanning */
                }
            }
            setTimeout(loop, 110);
        };
        loop();
        return () => {
            stopped = true;
        };
    }, [camera.on, onCode]);

    // Release the camera when the phone screen is off / app switched; resume when back.
    useEffect(() => {
        const onVis = () => {
            if (document.hidden) stopCamera();
            else if (wantCameraRef.current && !streamRef.current) startCamera();
        };
        document.addEventListener('visibilitychange', onVis);
        return () => {
            document.removeEventListener('visibilitychange', onVis);
            streamRef.current?.getTracks().forEach((t) => t.stop());
        };
    }, [startCamera]);

    const onPhoto = async (e) => {
        const file = e.target.files[0];
        e.target.value = '';
        if (!file) return;
        unlockAudio();
        await initDetector();
        try {
            const bmp = await createImageBitmap(file);
            const text = await decodeQr(bmp, bmp.width, bmp.height, true);
            if (text) onCode(text, 'photo');
            else showResult({ status: 'error', message: 'No QR code found in that photo. Hold the ID closer and try again.' });
        } catch {
            showResult({ status: 'error', message: 'Could not read that photo.' });
        }
    };

    // ------------------------------------------------------------ heartbeat + offline queue

    useEffect(() => {
        const beat = async () => {
            try {
                await axios.get('/scan/ping', { timeout: 6000 });
                if (queuedCount()) {
                    setNet({ state: 'online', queued: queuedCount() });
                    (await flushQueue()).forEach(addHistory);
                }
                setNet({ state: 'online', queued: queuedCount() });
            } catch (err) {
                const loggedOut = err instanceof LoggedOutError || err.response?.status === 401;
                setNet({ state: loggedOut ? 'loggedOut' : 'offline', queued: queuedCount() });
            }
        };
        beat();
        const t = setInterval(beat, 6000);
        return () => clearInterval(t);
    }, [addHistory]);

    const netLabel = {
        online: net.queued ? `● Sending ${net.queued}…` : '● Online',
        offline: `● No signal${net.queued ? ` · ${net.queued} saved` : ''}`,
        loggedOut: `● Logged out${net.queued ? ` · ${net.queued} saved` : ''}`,
    }[net.state];

    return (
        <div className="min-h-dvh bg-night text-white">
            <Head title="Scanner" />
            <header className="flex items-center gap-2 px-3.5 pt-[max(10px,env(safe-area-inset-top))] pb-2.5">
                <div className="min-w-0 flex-1">
                    <div className="truncate text-xs text-slate-400">{event}</div>
                    <div className="truncate font-bold">📱 {auth.user?.name}</div>
                </div>
                <span className={`text-sm font-semibold whitespace-nowrap ${net.state === 'online' ? 'text-green-400' : 'text-red-300'}`}>{netLabel}</span>
                <button className="rounded-full bg-white/10 px-3 py-1 text-xs" onClick={() => confirm('Log out this phone?') && router.post('/logout')}>
                    Log out
                </button>
            </header>

            {net.state === 'loggedOut' && (
                <div className="mx-3.5 mb-2.5 rounded-xl bg-bad px-3.5 py-3">
                    This phone was logged out. Scans are kept on the phone.{' '}
                    <a href="/login" className="font-bold underline">Log in again</a> to send them.
                </div>
            )}

            <div className="grid grid-cols-2 gap-2 px-3.5 pb-2.5">
                {['IN', 'OUT'].map((m) => (
                    <button
                        key={m}
                        onClick={() => setMode(m)}
                        className={`rounded-xl border-2 py-3 text-lg font-extrabold tracking-wide ${mode === m
                            ? (m === 'IN' ? 'border-green-600 bg-green-600 text-white' : 'border-orange-600 bg-orange-600 text-white')
                            : 'border-white/20 text-slate-300'}`}
                    >
                        TIME {m}
                    </button>
                ))}
            </div>

            <main className="px-3.5 pb-6">
                <div className="relative aspect-[3/4] max-h-[58vh] w-full overflow-hidden rounded-2xl bg-black">
                    <video ref={videoRef} playsInline muted className="h-full w-full object-cover" />
                    {camera.on ? (
                        <div className="pointer-events-none absolute inset-[18%] rounded-2xl border-[3px] border-white/85 shadow-[0_0_0_999px_rgba(0,0,0,0.25)]" />
                    ) : (
                        <div className="absolute inset-0 grid place-items-center p-6 text-center font-semibold text-slate-200">{camera.msg}</div>
                    )}
                </div>
                <div className="mt-2.5 flex gap-2">
                    <button className="flex-1 rounded-xl bg-blue-600 py-3 font-semibold disabled:opacity-60" onClick={startCamera} disabled={camera.on}>
                        {camera.on ? 'Camera on' : 'Start camera'}
                    </button>
                    <label className="flex-1 cursor-pointer rounded-xl bg-white/10 py-3 text-center font-semibold">
                        Take photo
                        <input type="file" accept="image/*" capture="environment" hidden onChange={onPhoto} />
                    </label>
                </div>

                <ul className="mt-4 text-sm text-slate-400">
                    {history.map((h, i) => (
                        <li key={i} className="flex gap-2 border-b border-white/5 py-1.5">
                            <span className="tabular-nums">{h.t}</span>
                            <span className={`rounded-full px-2 text-xs font-semibold ${h.k === 'OUT' ? 'bg-orange-900 text-orange-200' : 'bg-green-900 text-green-200'}`}>{h.k}</span>
                            <b className="min-w-0 flex-1 truncate font-semibold text-white">{h.who}</b>
                            <span>{h.s === 'pending' ? 'sent' : h.s}</span>
                        </li>
                    ))}
                </ul>
            </main>

            <ResultOverlay
                result={result}
                onClose={() => {
                    if (result?.status === 'sending') return;
                    clearTimeout(resultTimer.current);
                    setResult(null);
                    pausedRef.current = false;
                    lastRef.current.at = Date.now();
                }}
            />
        </div>
    );
}
