// Beep + vibrate so the person scanning knows the result without reading the screen.

let audio;

export function unlockAudio() {
    try {
        audio ??= new (window.AudioContext || window.webkitAudioContext)();
        audio.resume();
    } catch {
        /* no audio */
    }
}

const PATTERNS = {
    ok: [[1320, 120]],
    pending: [[990, 90]],
    duplicate: [[880, 90], [0, 40], [880, 90]],
    queued: [[520, 250]],
    error: [[260, 450]],
};

export function feedback(status) {
    const pattern = PATTERNS[status] || PATTERNS.error;
    navigator.vibrate?.(pattern.map((p) => p[1]));
    if (!audio) return;
    let t = audio.currentTime;
    for (const [freq, ms] of pattern) {
        if (freq) {
            const osc = audio.createOscillator();
            const gain = audio.createGain();
            osc.frequency.value = freq;
            osc.connect(gain);
            gain.connect(audio.destination);
            gain.gain.setValueAtTime(0.25, t);
            osc.start(t);
            osc.stop(t + ms / 1000);
        }
        t += ms / 1000 + 0.05;
    }
}
