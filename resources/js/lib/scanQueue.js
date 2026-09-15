import axios from 'axios';

// Sends scans to the records PC. Scans that can't be sent (no signal, phone logged out) are
// kept in this phone's storage and sent later, keeping the time they were actually made.

const KEY = 'scanQueue';

const load = () => {
    try {
        return JSON.parse(localStorage.getItem(KEY) || '[]');
    } catch {
        return [];
    }
};
const save = (q) => {
    try {
        localStorage.setItem(KEY, JSON.stringify(q));
    } catch {
        /* storage unavailable */
    }
};

export const queuedCount = () => load().length;

export class LoggedOutError extends Error {}

async function post(item, retried = false) {
    try {
        const { data } = await axios.post('/scan/record', { ...item, client_now: Date.now() }, { timeout: 8000 });
        return data;
    } catch (err) {
        const status = err.response?.status;
        if (status === 419 && !retried) {
            await axios.get('/scan/ping', { timeout: 8000 });   // refreshes the CSRF cookie
            return post(item, true);
        }
        if (status === 401 || (status === 419 && retried)) throw new LoggedOutError();
        if (!err.response) throw err;                        // network problem
        return { status: 'error', message: err.response.data?.message || `Error ${status}` };
    }
}

/** Send one scan now; queue it if that isn't possible. */
export async function sendScan(item) {
    try {
        return await post(item);
    } catch (err) {
        save([...load(), item]);
        return {
            status: 'queued',
            kind: item.kind,
            qr_text: item.qr_text,
            loggedOut: err instanceof LoggedOutError,
            message: err instanceof LoggedOutError
                ? 'Saved on this phone. It was logged out: log in again to send it.'
                : 'Saved on this phone. It will be sent when there is signal.',
        };
    }
}

/** Try to send everything waiting. Returns the results that went through. Throws LoggedOutError. */
export async function flushQueue() {
    const sent = [];
    let q = load();
    while (q.length) {
        const res = await post(q[0]);   // network errors / logged out stop the loop and keep the queue
        sent.push(res);
        q = load().slice(1);
        save(q);
    }
    return sent;
}
