# 15SW Attendance

Phones scan the QR code on personnel IDs (e.g. `CHR SANTOS, CEIS`). The PC is the **records
station**: it runs the app, confirms new IDs, and keeps the reports. Each phone logs in with its
own account (Gate 1, Gate 2, Gate 3…).

```
 Gate 1 phone ─┐                                   ┌───────── Records PC ──────────┐
 Gate 2 phone ─┼── internet (Cloudflare Tunnel) ─► │ tools/gateway.mjs → Laravel   │ ◄─ http://localhost:8080
 Gate 3 phone ─┘   or same Wi-Fi (https :8443)     │ SQLite, backups, Excel export │    (dashboard, admin login)
```

| Layer | Choice |
|---|---|
| Backend | PHP 8.3 (Composer `platform.php` 8.3.0), Laravel 13 |
| Server ↔ client | Inertia.js — `inertiajs/inertia-laravel` ^3.1 + `@inertiajs/react` ^2.3 |
| UI | React 18.3, Tailwind CSS 4 (`@tailwindcss/vite`, theme in `resources/css/app.css` `@theme`), Recharts ^2 |
| Bundler | Vite 7 (`laravel-vite-plugin` ^2, `@vitejs/plugin-react` ^5) |
| Database | SQLite locally, MySQL InnoDB in production (`Builder::defaultStringLength(191)`) |
| Auth | Custom session auth (`Auth::attempt`, username login, roles `admin` / `scanner`) |
| Tests | PHPUnit 12 — `php artisan test` |

## First-time setup

```
composer install
npm install
npm run build
php artisan migrate
php artisan attendance:user admin --name="Records PC"
```

The last command asks you for the records-account password. Then start the system (`start.bat`),
log in on the PC, open **Accounts** and create one phone account per gate: `Gate 1 / gate1`, `Gate 2 / gate2`, `Gate 3 / gate3`.

## Event day

1. **PC:** plug in, set Windows to never sleep, connect to the internet (Wi-Fi or USB tethering).
2. Double-click **`start.bat`** and keep the black window open. Log in with the records account.
   - First time only: if Windows Firewall asks about Node.js, click **Allow**.
3. **Phones:** on the dashboard's *Phones* panel, scan the 🌐 QR code with each phone's camera, log in
   with that phone's account, tap **Start camera**, and set **TIME IN / TIME OUT**.
   The *Phones* panel shows a green dot for every phone that is connected.

### While scanning

- **Known ID** → phone shows **TIME IN ✓** with the name.
- **New ID** → phone shows **SCANNED ✓**; the PC chimes and lists it under **To confirm**:
  family name → office → **Confirm**. The scan keeps its original time, and that ID is instant from then on.
  - *rank ≠ SSG* means the rank on the ID differs from the roster — check before confirming.
  - Not on the PSR (e.g. civilian staff) → **Add as walk-in**.
- **No ID** → *Not yet scanned* → **Mark present**.
- **Wrong person** → *Live scans* → **Change** (or ✕ to cancel the scan).
- **No signal on a phone** → it keeps scanning and saves scans on the phone; they are sent when it is back online.
- **Lost / borrowed phone** → *Accounts* → **Log out phone** or **Disable**.

### After

Dashboard → **⬇ Export Excel**: Summary per squadron, Present (time in/out, which phone), Unaccounted,
Excused per PSR, To confirm, Scan log.

## Online address

Without extra setup the PC gets a free temporary `https://….trycloudflare.com` address. Those can
stop working without warning; the gateway checks every 30 s and reconnects, but the address then
changes and phones must scan the new QR (scans saved on a phone under the old address stay on that
phone). **For the event, use a fixed address:** create a Cloudflare Tunnel in the Cloudflare
dashboard (Zero Trust → Networks → Tunnels) pointing a hostname you own at `http://localhost:8090`,
then add to `.env`:

```
CLOUDFLARE_TUNNEL_TOKEN=<token from the dashboard>
CLOUDFLARE_TUNNEL_URL=https://attendance.example.com
```

From the internet only `/login`, `/logout`, `/scan`, `/scan/ping`, `/scan/record` and the built
assets are reachable; records pages only open on the PC itself.

## Roster

**Roster** page → upload the PSR `.xlsx`; columns are detected automatically.

1. Sheet **Daily PSR**, mode **Replace the whole roster**.
2. Sheet **Combined Data**, mode **Only update existing people** (fills in OFFICE by SN).

Command-line equivalent:

```
php artisan roster:import "15SW Daily PSR.xlsx" --sheet="Daily PSR" --mode=replace
php artisan roster:import "15SW Daily PSR.xlsx" --sheet="Combined Data" --mode=update --only=serial,office
```

Before the real event, delete practice scans on the dashboard (**Delete ALL scans**). Confirmed
ID links are kept — they are real and make the event faster.

## Online on cPanel (MySQL, no terminal needed)

Every push to `main` on GitHub runs the tests (SQLite and MySQL) and, if they pass, publishes a
ready-to-run copy — including `vendor/` and `public/build/` — to the **`deploy`** branch
(`.github/workflows/release.yml`). The server never runs composer or npm.

**First time**

1. **PHP:** cPanel → *MultiPHP Manager* → PHP **8.3** or 8.4 for the domain. *Select PHP Version →
   Extensions*: pdo_mysql, mbstring, openssl, fileinfo, zip, gd, xml, dom, simplexml, zlib, iconv, ctype (intl if listed).
2. **Database:** cPanel → *MySQL Databases* → create a database and a user, and add the user to the
   database with **ALL PRIVILEGES**.
3. **Code:** cPanel → *Git Version Control* → *Create* → clone the GitHub repo, branch **`deploy`**,
   into e.g. `/home/ACCOUNT/attendance` (not inside `public_html`). Private repo: use an HTTPS clone
   URL with a GitHub token (`https://TOKEN@github.com/OWNER/REPO.git`), or add cPanel's SSH key to the repo as a deploy key.
4. **Address:** cPanel → *Domains* → create e.g. `attendance.yourdomain.com` with document root
   **`/home/ACCOUNT/attendance/public`**. Turn on *Force HTTPS Redirect* (AutoSSL gives the certificate).
5. **Settings:** File Manager → in `/home/ACCOUNT/attendance` copy `.env.production.example` to
   **`.env`** and fill in `APP_URL`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, and a long random `INSTALL_TOKEN`.
6. Open **`https://attendance.yourdomain.com/install?token=YOUR_INSTALL_TOKEN`**: paste the APP_KEY it
   shows into `.env`, press **Set up / update database**, create the **records account**.
   Then clear `INSTALL_TOKEN` in `.env` (the page disappears).
7. Log in, upload the PSR on **Roster**, create the phone accounts on **Accounts**. Phones open the
   site address (QR code on the dashboard) and log in.

**Updating:** push to `main` → wait for the GitHub Action to finish → cPanel → *Git Version Control* →
*Manage* → *Pull or Deploy* → **Update from Remote**. If the dashboard then shows *“This version needs
a database update”*, press **Update now**.

Back up the MySQL database with cPanel → *Backup* (or phpMyAdmin → Export).

## Development

```
php artisan test      # PHPUnit
npm run dev           # Vite dev server (PC only; phones need `npm run build`)
node tools/gateway.mjs
```
