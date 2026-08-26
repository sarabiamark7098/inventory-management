# ICT Inventory — UI/UX project

Vue 3 + PrimeVue + Pinia client for the ICT Equipment & Borrowing Management
System. The API project lives in [`../api`](../api) and is deployed separately.

## Setup

```bash
npm install
```

```bash
cp .env.example .env
```

`VITE_API_URL` points at the Laravel project (default
`http://localhost:8000/api`).

```bash
npm run dev
```

Open http://localhost:5173. The app works with the API stopped — every screen
reads and writes IndexedDB, and mutations queue until the API is reachable.

## Scripts

| Script | Purpose |
| --- | --- |
| `npm run dev` | Vite dev server on :5173 |
| `npm run build` | Production build to `dist/` |
| `npm run preview` | Serve the production build |

## Authentication

Passwordless sign-in: enter your email, receive a 6-digit code, and the app
exchanges it for a bearer token held in `localStorage`. `stores/auth.js` owns the
flow; the router guards every non-public route and re-validates a persisted
token once on boot.

Signing out wipes the local IndexedDB cache — on a shared workstation the next
person must not inherit the previous user's catalogue and loan history.

In local development the API logs the code instead of emailing it:

```bash
grep "Sign-in code" ../api/storage/logs/laravel.log | tail -1
```

## Architecture

```
src/
  api/client.js        fetch wrapper; attaches the bearer token, ApiError.isTransient drives retries
  stores/auth.js       OTP sign-in, session restore, profile
  db/local.js          Dexie schema (categories, equipment, borrowings, sync_queue, meta)
  db/outbox.js         Outbox queue: enqueue, claim, acknowledge, requeue, release stale
  domain/              Pure rules + status vocabulary shared with the API
  stores/inventory.js  The single write path; Dexie live queries feed every screen
  stores/sync.js       Background engine: push → pull, connectivity + focus triggers
  components/          Scanner, QR labels, dynamic field builder, dialogs
  views/               Dashboard, Equipment, Categories, Borrowing
```

**Every local write goes through `stores/inventory.js`**, which writes the
document and its outbox entry inside one Dexie transaction. Components never
call the network directly — that is what makes the whole app work offline.

Reads are Dexie `liveQuery` subscriptions, so a local write *or* a document
arriving from the pull phase re-renders the UI automatically.

## PrimeVue version

Pinned to the **4.x line, which is MIT-licensed**. PrimeVue 5 is commercially
licensed and logs `[PrimeUI] PrimeUI license is not configured` at runtime.
Components are registered globally in `src/plugins/primevue-components.js`.

## Deploying under a subdirectory

The build has **no base-path setting**. Assets are emitted as relative URLs
(`./assets/…`) and the router uses hash history, so the same `dist/` runs at the
domain root, at `/inventory_management`, or anywhere else — nothing to keep in
sync with nginx, and no history-mode fallback to misconfigure.

`VITE_API_URL` is the only build-time setting the client needs. For a server,
start from `.env.production.example`.

## Mobile

The UI is built for phones as well as desktops, because audits happen on the
floor with a handset:

- Below 768px the wide data tables are **not rendered at all** — `useIsMobile()`
  swaps in a card list, so there is nothing to scroll sideways.
- Dialogs become full-screen sheets; the equipment form, scanner and QR sheet
  all fit a 375px viewport.
- Every text input is 16px on small screens, which is what stops iOS Safari
  zooming the page when a field is focused.
- Buttons and fields have a 44px minimum height (WCAG 2.5.5).
- `viewport-fit=cover` plus `env(safe-area-inset-*)` keeps content clear of the
  iPhone notch and home indicator.
- Pinch-zoom is deliberately left enabled; disabling it fails accessibility.

Verified at 375x812 (iPhone), 768x1024 (tablet) and desktop with no horizontal
overflow on any screen.

## Notes

- Camera scanning needs a secure context (`https://` or `localhost`). Elsewhere
  the scanner falls back to the text input, which also accepts USB wedge
  scanners (they type the code then press Enter).
- Dialog open/close animations rely on `requestAnimationFrame`; in a hidden or
  non-compositing tab those transitions cannot complete. This only affects
  automated headless testing, not real use.
