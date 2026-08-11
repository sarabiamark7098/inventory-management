# ICT Equipment & Borrowing Management System

Local-first, offline-capable web app for tracking ICT hardware, category
taxonomies, and borrowing/return workflows, with bidirectional background
synchronization to a central PostgreSQL database.

The functional specification lives in [CLAUDE.md](CLAUDE.md).

## Stack

| Layer | Choice |
| --- | --- |
| Frontend | Next.js 16 (App Router), React 19, TypeScript, Tailwind CSS v4 |
| Local store | RxDB 17 on Dexie/IndexedDB |
| Server API | Next.js Route Handlers (`/api/sync/*`) |
| Central DB | MySQL 8 via Prisma 7 (`@prisma/adapter-mariadb`) |
| Sync | Custom outbox queue, HTTP push/pull, leader-elected across tabs |

## Getting started

1. **Install dependencies**

   ```bash
   npm install
   ```

2. **Point at a MySQL 8 instance**

   ```bash
   cp .env.example .env
   ```

   Edit `DATABASE_URL` in `.env` (format:
   `mysql://USER:PASSWORD@HOST:PORT/DATABASE`).

3. **Create the database and schema**

   ```bash
   mysql -u root -e "CREATE DATABASE IF NOT EXISTS ict_inventory CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
   ```

   ```bash
   npx prisma migrate deploy
   ```

   `prisma/migrations/0_init/migration.sql` is generated and ready. For an
   iterative workflow use `npm run db:migrate` instead.

4. **(Optional) Seed starter data**

   ```bash
   npm run db:seed
   ```

5. **Run**

   ```bash
   npm run dev
   ```

The app works with no database at all — every screen reads and writes the local
RxDB store. Mutations queue in the outbox and drain automatically once the API
becomes reachable.

## Scripts

| Script | Purpose |
| --- | --- |
| `npm run dev` / `build` / `start` | Next.js lifecycle |
| `npm run check` | typecheck + lint + domain checks + RxDB schema checks |
| `npm run check:domain` | Assertions for loan maths, overdue rules, conflict tie-breaks |
| `npm run check:schemas` | Boots RxDB with ajv validation to prove the client schemas are valid |
| `npm run db:migrate` / `db:push` / `db:studio` / `db:seed` | Prisma helpers |

## Architecture

### Local-first write path

Every mutation goes through `src/lib/db/repositories.ts`, which writes the RxDB
document **and** appends an entry to the local `sync_queue` collection in the
same step (outbox pattern). The UI never talks to the network directly, so all
CRUD keeps working offline.

```
UI → repositories.ts ─┬→ RxDB collection (immediate, reactive re-render)
                      └→ sync_queue (outbox)
                                │
                    sync-engine.ts (leader tab only)
                                │
             POST /api/sync/push  →  Prisma  →  PostgreSQL
             GET  /api/sync/pull  ←            ←
```

### Sync engine

`src/lib/db/sync-engine.ts` runs one push→pull cycle on load, on `online`, on
tab focus, and every 30s. `waitForLeadership()` ensures only one open tab drives
it. Outbox entries are drained oldest-first by `clientTimestamp` so the server
replays edits in the order the operator made them.

Failure handling distinguishes two cases, which matters for the status badge:

Orphaned `IN_FLIGHT` entries (a tab closed mid-push) are released back to
`PENDING` after 60s, so no mutation is ever stranded.

- **Transient** (offline, 5xx, unreachable database) → entries return to
  `PENDING` and retry on the next cycle. Badge stays `Sync pending [X]`.
- **Rejected** (4xx, or a change the server refused on its merits) → entries go
  to `FAILED` and wait for a human. Badge turns red; clicking it retries.

### Conflict resolution

| Case | Rule |
| --- | --- |
| Category / equipment metadata | Last-Write-Wins on UTC `updatedAt`; staler writes are dropped and the server document is echoed back |
| Duplicate asset tag | Hard `REJECTED` — the unique constraint is surfaced instead of silently dropping a record |
| Overlapping active loans on one asset | **Status Lock Rule** — the loan with the earlier `createdAt` keeps the asset (ties break on id, so every client reaches the same verdict); the loser is flagged `CONFLICT_PENDING_REVIEW` |
| Equipment availability | Derived server-side from the authoritative loan rows. `MAINTENANCE` / `DECOMMISSIONED` / `LOST` are operator decisions and are never overwritten |

During a pull, documents with unsynced local edits are skipped so offline work
is not clobbered (the "local override" of Server-Wins). The one exception is a
server-flagged conflict, which always wins so the contested record surfaces
immediately.

## Project layout

```
prisma/schema.prisma          Central PostgreSQL schema
prisma/migrations/0_init/     Generated DDL
scripts/                      Verification + seed scripts (node --experimental-strip-types)
src/app/                      Dashboard, equipment, categories, borrowing, /api/sync/*
src/components/               UI primitives, scanner, QR labels, forms
src/lib/domain/               Shared types + pure business rules (used by client AND server)
src/lib/db/                   RxDB setup, schemas, outbox, repositories, sync engine
src/lib/server/               Prisma client, DTO mappers, sync service + conflict engine
```

## Notes

- **QR / barcode scanning** uses the device camera via `html5-qrcode`. Browsers
  only grant camera access on `https://` or `localhost`, so on a plain-HTTP LAN
  deployment the scanner falls back to the text input — which also accepts USB
  wedge scanners (they type the code and press Enter).
- **Overdue loans** are promoted to `OVERDUE` on app load and on each sync tick,
  with an optional desktop notification. The in-app banner is the guaranteed
  channel; notifications are best-effort and silently skipped if denied.
- Two toolchains wrote agent-instruction files into the repo. `prisma init`
  installed its docs under `.claude/skills/`, `.agents/skills/`, and
  `.windsurf/skills/`, plus `skills-lock.json` — unused by this app and safe to
  delete. `next dev` generates and re-adds the block in `AGENTS.md`, so deleting
  that one just recreates it.
- `.claude/launch.json` is a local dev-server config, not part of the app.
