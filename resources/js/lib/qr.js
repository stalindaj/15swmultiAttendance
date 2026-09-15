import jsQR from 'jsqr';

let detector;

/** Native QR detector where the browser has one (Android Chrome); jsQR everywhere else (iPhone). */
export async function initDetector() {
    if (detector !== undefined) return;
    detector = null;
    if ('BarcodeDetector' in window) {
        try {
            if ((await window.BarcodeDetector.getSupportedFormats()).includes('qr_code')) {
                detector = new window.BarcodeDetector({ formats: ['qr_code'] });
            }
        } catch {
            detector = null;
        }
    }
}

const canvas = typeof document !== 'undefined' ? document.createElement('canvas') : null;
const ctx = canvas?.getContext('2d', { willReadFrequently: true });

/**
 * Returns the QR text in the image/video frame, or null.
 * `full` scans the whole picture (photos); otherwise only the centre where the guide box is,
 * scaled down, which is faster and sharper for the small code on an ID.
 */
export async function decodeQr(source, width, height, full = false) {
    if (detector) {
        try {
            const codes = await detector.detect(source);
            if (codes.length) return codes[0].rawValue;
            if (!full) return null;
        } catch {
            /* fall back to jsQR */
        }
    }
    const side = full ? Math.max(width, height) : Math.min(width, height) * 0.8;
    const sx = full ? 0 : (width - side) / 2;
    const sy = full ? 0 : (height - side) / 2;
    const sw = full ? width : side;
    const sh = full ? height : side;
    const scale = Math.min(1, (full ? 1400 : 640) / Math.max(sw, sh));
    canvas.width = Math.round(sw * scale);
    canvas.height = Math.round(sh * scale);
    ctx.drawImage(source, sx, sy, sw, sh, 0, 0, canvas.width, canvas.height);
    const img = ctx.getImageData(0, 0, canvas.width, canvas.height);
    return jsQR(img.data, img.width, img.height, { inversionAttempts: full ? 'attemptBoth' : 'dontInvert' })?.data || null;
}
