// Runs the Laravel app and lets the phones reach it.
//
//   Records PC:  http://localhost:8080                    (dashboard; admin account)
//   Phones:      https://<online address>/scan            (mobile data, via Cloudflare Tunnel)
//                https://<pc-ip>:8443/scan                (same Wi-Fi / hotspot, no internet needed)
//
// It starts a few `php -S` workers so one slow request never blocks the scanners, restarts
// anything that crashes, backs up the database every few minutes, and only lets the login page
// and the scanner through the phone doors. Phone traffic is tagged X-Attendance-Remote so
// Laravel keeps the records pages on the PC.

import { execSync, spawn } from 'node:child_process';
import fs from 'node:fs';
import http from 'node:http';
import https from 'node:https';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const ENV = readDotEnv(path.join(ROOT, '.env'));
const LAPTOP_PORT = Number(process.env.LAPTOP_PORT || 8080);
const PHONE_PORT = Number(process.env.PHONE_PORT || 8443);
const TUNNEL_PORT = Number(process.env.TUNNEL_PORT || 8090);   // cloudflared connects here (localhost only)
const WORKER_PORTS = [8101, 8102, 8103];
const CERT_DIR = path.join(ROOT, 'storage', 'app', 'cert');
const TUNNEL_FILE = path.join(ROOT, 'storage', 'app', 'tunnel.json');
const CLOUDFLARED = path.join(ROOT, 'tools', 'cloudflared.exe');
// Online (internet) mode is opt-in: ATTENDANCE_ONLINE=true in .env, or ONLINE=1.
const ONLINE = (process.env.ONLINE === '1' || ENV.ATTENDANCE_ONLINE === 'true') && fs.existsSync(CLOUDFLARED);
// Fixed online address (Cloudflare named tunnel). Without these a temporary trycloudflare.com address is used.
const TUNNEL_TOKEN = ENV.CLOUDFLARE_TUNNEL_TOKEN || '';
const TUNNEL_PUBLIC_URL = (ENV.CLOUDFLARE_TUNNEL_URL || '').replace(/\/+$/, '');
const BACKUP_EVERY_MS = 5 * 60 * 1000;
const HEALTH_EVERY_MS = 30 * 1000;
const HEALTH_FAILURES_BEFORE_RESTART = 4;

// What a phone may request. Everything else is refused before it reaches PHP.
const PHONE_ALLOWED = [
  ['GET', /^\/(scan|login)(\?.*)?$/],
  ['POST', /^\/(login|logout)$/],
  ['GET', /^\/scan\/ping(\?.*)?$/],
  ['POST', /^\/scan\/record$/],
  ['GET', /^\/build\/assets\/[\w.-]+$/],
  ['GET', /^\/favicon\.ico$/],
];

function readDotEnv(file) {
  const out = {};
  try {
    for (const line of fs.readFileSync(file, 'utf8').split(/\r?\n/)) {
      const m = line.match(/^\s*([A-Z0-9_]+)\s*=\s*(.*)\s*$/);
      if (m) out[m[1]] = m[2].replace(/^(['"])(.*)\1$/, '$2');
    }
  } catch { /* no .env */ }
  return out;
}

function findPhp() {
  if (process.env.PHP_BIN) return process.env.PHP_BIN;
  // `php` is often a .bat shim (Herd); ask it for the real executable.
  try {
    return execSync('php -r "echo PHP_BINARY;"', { encoding: 'utf8' }).trim();
  } catch {
    console.error('PHP was not found. Install PHP 8.3+ or set PHP_BIN.');
    process.exit(1);
  }
}

const PHP = findPhp();
const ROUTER = path.join(ROOT, 'vendor', 'laravel', 'framework', 'src', 'Illuminate', 'Foundation', 'resources', 'server.php');
let shuttingDown = false;
const log = (msg) => console.log(`${new Date().toTimeString().slice(0, 8)}  ${msg}`);

// ---------------------------------------------------------------- PHP workers

const workers = WORKER_PORTS.map((port) => ({ port, proc: null, alive: false }));

function startWorker(w) {
  const ini = ['-d', 'upload_max_filesize=30M', '-d', 'post_max_size=32M', '-d', 'memory_limit=1G', '-d', 'max_execution_time=180'];
  w.proc = spawn(PHP, [...ini, '-S', `127.0.0.1:${w.port}`, ROUTER], {
    cwd: path.join(ROOT, 'public'),
    env: { ...process.env, APP_URL: `http://localhost:${LAPTOP_PORT}` },
    windowsHide: true,
  });
  w.alive = true;
  const onOutput = (buf) => {
    for (const line of buf.toString().split(/\r?\n/)) {
      // php -S logs every request; only surface real problems.
      if (/PHP (Fatal|Parse|Warning)|Failed to listen/i.test(line)) log(`[php ${w.port}] ${line.trim()}`);
    }
  };
  w.proc.stdout.on('data', onOutput);
  w.proc.stderr.on('data', onOutput);
  w.proc.on('exit', (code) => {
    w.alive = false;
    if (shuttingDown) return;
    log(`[php ${w.port}] stopped (code ${code}), restarting...`);
    setTimeout(() => startWorker(w), 1000);
  });
}

// ---------------------------------------------------------------- proxy

let next = 0;

function forward(req, res, body, door, attempt = 0) {
  const live = workers.filter((w) => w.alive);
  if (!live.length || attempt >= workers.length) {
    res.writeHead(503, { 'content-type': 'text/plain' });
    res.end('The attendance app is starting or unavailable. Try again in a few seconds.');
    return;
  }
  const w = live[next++ % live.length];

  const headers = { ...req.headers, 'content-length': body.length };
  delete headers['x-attendance-remote'];
  delete headers['transfer-encoding'];
  if (door.phone) {
    headers['x-attendance-remote'] = '1';
    headers['x-forwarded-proto'] = 'https';
  }

  const upstream = http.request(
    { host: '127.0.0.1', port: w.port, method: req.method, path: req.url, headers },
    (up) => {
      // Redirects become relative, so they work under every address the page is opened with.
      if (up.headers.location) up.headers.location = up.headers.location.replace(/^https?:\/\/[^/]+/i, '') || '/';
      res.writeHead(up.statusCode, up.headers);
      up.pipe(res);
    },
  );
  upstream.setTimeout(60000, () => upstream.destroy(new Error('timeout')));
  upstream.on('error', (err) => {
    if (res.headersSent) return res.destroy();
    if (err.code === 'ECONNREFUSED' || err.code === 'ECONNRESET') return forward(req, res, body, door, attempt + 1);
    res.writeHead(502, { 'content-type': 'text/plain' });
    res.end('Records PC app error: ' + err.message);
  });
  upstream.end(body);
}

function handler(door) {
  return (req, res) => {
    if (door.phone) {
      if (req.url === '/' || req.url === '') {
        res.writeHead(302, { location: '/scan' });
        return res.end();
      }
      if (!PHONE_ALLOWED.some(([m, re]) => m === req.method && re.test(req.url))) {
        res.writeHead(404, { 'content-type': 'text/plain' });
        return res.end('Not found');
      }
    }
    const chunks = [];
    let size = 0;
    const limit = door.phone ? 64 * 1024 : 30 * 1024 * 1024;
    req.on('data', (c) => {
      size += c.length;
      if (size > limit) req.destroy();
      else chunks.push(c);
    });
    req.on('end', () => forward(req, res, Buffer.concat(chunks), door));
  };
}

// ---------------------------------------------------------------- Cloudflare tunnel (online mode)

let tunnelProc = null;
let tunnelUrl = null;
let healthFailures = 0;

function writeTunnel(state) {
  try { fs.writeFileSync(TUNNEL_FILE, JSON.stringify({ ...state, fixed: Boolean(TUNNEL_TOKEN), updated: new Date().toISOString() })); } catch { /* ignore */ }
}

function startTunnel() {
  tunnelUrl = null;
  healthFailures = 0;
  writeTunnel({ status: 'starting', url: null });
  const args = TUNNEL_TOKEN
    ? ['tunnel', '--no-autoupdate', 'run', '--token', TUNNEL_TOKEN]
    : ['tunnel', '--no-autoupdate', '--url', `http://127.0.0.1:${TUNNEL_PORT}`];
  const proc = spawn(CLOUDFLARED, args, { windowsHide: true });
  const onOutput = (buf) => {
    const text = buf.toString();
    const quick = text.match(/https:\/\/[a-z0-9-]+\.trycloudflare\.com/);
    const connected = TUNNEL_TOKEN && /Registered tunnel connection/i.test(text);
    if (!tunnelUrl && (quick || connected)) {
      tunnelUrl = quick ? quick[0] : TUNNEL_PUBLIC_URL;
      writeTunnel({ status: 'up', url: tunnelUrl });
      log(`ONLINE address for phones: ${tunnelUrl}/scan`);
    }
    if (!tunnelUrl && /failed to request quick Tunnel|no such host|dial tcp .* connect/i.test(text)) {
      writeTunnel({ status: 'error', url: null, error: 'No internet on this PC. Phones can still use the Wi-Fi link.' });
    }
  };
  proc.stdout.on('data', onOutput);
  proc.stderr.on('data', onOutput);
  proc.on('exit', () => {
    if (shuttingDown) return;
    writeTunnel({ status: 'restarting', url: null, error: TUNNEL_TOKEN ? 'Reconnecting…' : 'Online link dropped. Reconnecting; phones must open the new address.' });
    log('Online tunnel stopped, reconnecting in 10 s.');
    setTimeout(startTunnel, 10000);
  });
  tunnelProc = proc;
}

// Temporary trycloudflare.com addresses can silently stop working; check ours and reconnect if it has.
function checkTunnel() {
  if (!tunnelUrl || !tunnelProc) return;
  const req = https.get(`${tunnelUrl}/login`, { timeout: 10000 }, (res) => {
    res.resume();
    healthFailures = 0;
  });
  const fail = () => {
    healthFailures++;
    if (healthFailures >= HEALTH_FAILURES_BEFORE_RESTART) {
      log(`Online address not reachable for ${healthFailures} checks; restarting the tunnel.`);
      tunnelProc.kill();   // the exit handler starts a new one
    }
  };
  req.on('timeout', () => req.destroy(new Error('timeout')));
  req.on('error', fail);
}

// ---------------------------------------------------------------- backups

function backup() {
  const proc = spawn(PHP, ['artisan', 'attendance:backup'], { cwd: ROOT, windowsHide: true });
  proc.stderr.on('data', (b) => log(`[backup] ${b.toString().trim()}`));
}

// ---------------------------------------------------------------- start

function lanIps() {
  const ips = [];
  for (const [name, addrs] of Object.entries(os.networkInterfaces())) {
    for (const a of addrs || []) {
      if (a.family === 'IPv4' && !a.internal && !a.address.startsWith('169.254.')) ips.push({ name, ip: a.address });
    }
  }
  return ips.sort((a, b) => (b.ip.startsWith('192.168.') - a.ip.startsWith('192.168.')));
}

function shutdown() {
  shuttingDown = true;
  for (const w of workers) w.proc?.kill();
  tunnelProc?.kill();
  writeTunnel({ status: 'off', url: null });
  process.exit(0);
}
process.on('SIGINT', shutdown);
process.on('SIGTERM', shutdown);

const certFile = path.join(CERT_DIR, 'cert.pem');
const keyFile = path.join(CERT_DIR, 'key.pem');
if (!fs.existsSync(certFile) || !fs.existsSync(keyFile)) {
  console.error('HTTPS certificate missing. Run:  php artisan attendance:cert');
  process.exit(1);
}
if (!fs.existsSync(path.join(ROOT, 'public', 'build', 'manifest.json'))) {
  console.error('Frontend not built. Run:  npm run build');
  process.exit(1);
}

workers.forEach(startWorker);

http.createServer(handler({ phone: false })).listen(LAPTOP_PORT, '127.0.0.1');
https
  .createServer({ cert: fs.readFileSync(certFile), key: fs.readFileSync(keyFile) }, handler({ phone: true }))
  .on('tlsClientError', () => {}) // phones probing before the certificate is accepted
  .listen(PHONE_PORT, '0.0.0.0');
http.createServer(handler({ phone: true })).listen(TUNNEL_PORT, '127.0.0.1');

if (ONLINE) {
  startTunnel();
  setInterval(checkTunnel, HEALTH_EVERY_MS);
} else {
  writeTunnel({ status: 'off', url: null });
}

setInterval(backup, BACKUP_EVERY_MS);

console.log('='.repeat(70));
console.log('  ATTENDANCE SYSTEM RUNNING  -  keep this window open');
console.log('');
console.log(`  Records PC       :  http://localhost:${LAPTOP_PORT}`);
console.log('  Phones           :  scan the QR code on the dashboard, then log in');
if (ONLINE) console.log(`                     (online address ${TUNNEL_TOKEN ? TUNNEL_PUBLIC_URL : 'appears here in a few seconds'})`);
for (const { name, ip } of lanIps()) console.log(`  Same Wi-Fi link  :  https://${ip}:${PHONE_PORT}/scan   (${name})`);
console.log('');
console.log('  Close this window or press Ctrl+C to stop.');
console.log('='.repeat(70));
