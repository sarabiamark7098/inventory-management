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

A borrower (`STAFF`) gets a read-only sync: the engine skips the push phase
entirely rather than collecting 403s.

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

## Notes

- Camera scanning needs a secure context (`https://` or `localhost`). Elsewhere
  the scanner falls back to the text input, which also accepts USB wedge
  scanners (they type the code then press Enter).
- Dialog open/close animations rely on `requestAnimationFrame`; in a hidden or
  non-compositing tab those transitions cannot complete. This only affects
  automated headless testing, not real use.
