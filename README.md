# ICT Equipment & Borrowing Management System

Local-first, offline-capable system for tracking ICT hardware, category
taxonomies, and borrowing/return workflows, with bidirectional background
synchronization to a central MySQL database.

The functional specification lives in [CLAUDE.md](CLAUDE.md).

## Two projects

| Path | Project | Stack | Dev URL |
| --- | --- | --- | --- |
| [`web/`](web) | UI/UX | Vue 3, PrimeVue 4, Pinia, Vite, Dexie/IndexedDB | http://localhost:5173 |
| [`api/`](api) | API | Laravel 13, PHP 8.4, MySQL 8 | http://localhost:8000 |

They are independent: separate dependency trees, separate builds, separate
deployments. The only coupling is the HTTP sync contract, plus a small status
vocabulary intentionally duplicated in `api/app/Domain/InventoryStatus.php` and
`web/src/domain/constants.js`.

## Quick start

**1. API**

```bash
cd api && composer install
```

```bash
mysql -u root -e "CREATE DATABASE IF NOT EXISTS ict_inventory CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
```

Set the `DB_*` values in `api/.env`, then:

```bash
cd api && php artisan migrate --seed && php artisan serve --port=8000
```

**2. UI**

```bash
cd web && npm install && npm run dev
```

Open http://localhost:5173.

The UI keeps working with the API stopped — every screen reads and writes
IndexedDB. Mutations queue in the outbox and drain automatically once the API is
reachable again.

## Authentication

Sign-in is **passwordless**: you enter your work email, receive a 6-digit code,
and exchange it for a Sanctum bearer token. There are no passwords to store,
reset, or leak.

```
POST /api/auth/request-code  { email }         → emails a code (10 min, single use)
POST /api/auth/verify-code   { email, code }   → { token, user }
```

Security properties worth knowing:

- Only a **hash** of the code is stored, so a database leak yields nothing usable.
- Requesting a new code **invalidates the previous one**.
- After 5 wrong guesses the code is burned; a new one must be requested.
- Rate limited per address *and* per IP.
- `request-code` returns the **same response for unknown addresses**, so it
  cannot be used to enumerate staff emails.
- Deactivating an account revokes its tokens and blocks it on the next request.

There is no public sign-up — an admin provisions accounts on the Accounts
screen, and the person simply signs in with a code afterwards.

### Email domain policy

Only **`@dswd.gov.ph`** addresses may hold an account or receive a sign-in code.
The rule is enforced in three places — account creation, account email changes,
and code issuance — so an account that predates the policy (or was inserted
straight into the database) still cannot sign in.

Configured in `api/config/accounts.php`, overridable per environment:

```
ACCOUNT_EMAIL_DOMAINS=dswd.gov.ph,fo11.dswd.gov.ph   # comma separated
ACCOUNT_EMAIL_DOMAINS=                                # empty = allow any domain
```

Matching is exact and case-insensitive, so lookalikes like
`dswd.gov.ph.example.com` and subdomains like `sub.dswd.gov.ph` are rejected
unless listed explicitly. The Vue client shows the same rule via
`VITE_ALLOWED_EMAIL_DOMAINS` purely as a hint — the API is the authority.

### Roles

| Role | Can do |
| --- | --- |
| `ADMIN` | Everything, plus account management |
| `MANAGER` | Register equipment, issue and receive loans, manage categories |
| `STAFF` | Browse the catalogue and request loans (read-only sync) |

The client hides what a role cannot do, the router guards the routes, and the
API enforces it independently — the UI is a convenience, not the boundary.

Seeded accounts in local development: `admin@ict.local`, `manager@ict.local`,
`staff@ict.local`. With `MAIL_MAILER=log` the code is written to
`api/storage/logs/laravel.log`:

```bash
grep "Sign-in code" api/storage/logs/laravel.log | tail -1
```

## How the offline model works

```
Vue component → Pinia inventory store ─┬→ Dexie table   (immediate, reactive re-render)
                                       └→ sync_queue    (outbox, same transaction)
                                                │
                              Pinia sync store (push → pull, every 30s)
                                                │
                        POST /api/sync/push  →  Laravel  →  MySQL
                        GET  /api/sync/pull   ←          ←
```

The sync store runs a cycle on load, when connectivity returns, when the tab
regains focus, and on a slow poll.

**Failure handling** distinguishes two cases, which is what the status badge
reflects:

- **Transient** (offline, 5xx, unreachable database) → entries return to
  `PENDING` and retry. Badge stays `Sync pending [X]`.
- **Rejected** (4xx, or a change the server refused on its merits) → entries go
  to `FAILED` and wait for a human. Badge turns red; clicking it offers a retry.

Orphaned `IN_FLIGHT` entries — a tab closed mid-push — are released back to
`PENDING` after 60s, so no mutation is ever stranded.

## Conflict resolution

| Case | Rule |
| --- | --- |
| Category / equipment metadata | Last-Write-Wins on UTC `updatedAt`; staler writes are dropped and the server document is echoed back |
| Duplicate asset tag | Hard `REJECTED` — the unique constraint is surfaced instead of silently dropping a record |
| Overlapping active loans on one asset | **Status Lock Rule** — the loan with the earlier `createdAt` keeps the asset (ties break on id, so every client reaches the same verdict); the loser is flagged `CONFLICT_PENDING_REVIEW` |
| Equipment availability | Derived server-side from the authoritative loan rows. `MAINTENANCE` / `DECOMMISSIONED` / `LOST` are operator decisions and are never overwritten |

During a pull, documents with unsynced local edits are skipped so offline work
is not clobbered. The one exception is a server-flagged conflict, which always
wins so the contested record surfaces immediately.

## Tests

```bash
cd api && php artisan test
```

65 tests / 215 assertions covering the loan maths, the conflict engine in both
arrival orders, Last-Write-Wins, equipment reconciliation, request validation,
the full OTP lifecycle (expiry, reuse, brute force, rate limiting, enumeration
resistance), the email domain policy, role enforcement, and account management.
They run against a real MySQL database (`ict_inventory_test`).

```bash
cd web && npm run build
```

## Notes

- **QR / barcode scanning** uses the device camera via `html5-qrcode`. Browsers
  only grant camera access on `https://` or `localhost`, so on a plain-HTTP LAN
  deployment the scanner falls back to the text input — which also accepts USB
  wedge scanners (they type the code and press Enter).
- **PrimeVue is pinned to the 4.x line**, which is MIT-licensed and free.
  PrimeVue 5 is commercially licensed and logs
  `[PrimeUI] PrimeUI license is not configured` at runtime. If that warning ever
  reappears after a dependency change, clear the stale pre-bundle cache with
  `rm -rf web/node_modules/.vite`.
- **Sign-in codes are sent from `icts.fo11@dswd.gov.ph`** ("DSWD FO11 ICT
  Section"), configured via `MAIL_OTP_FROM_ADDRESS` in `api/.env`. Note that a
  From header alone does not authorise sending as `@dswd.gov.ph` — you also need
  an SMTP relay permitted for that domain, with SPF/DKIM covering it, or the mail
  will bounce or land in spam.
- **Email delivery** uses `MAIL_MAILER=log` out of the box, so sign-in codes land
  in `api/storage/logs/laravel.log`. Point `MAIL_*` at that real SMTP service
  before anyone outside your machine needs to sign in.
- **Overdue loans** are promoted to `OVERDUE` on entering the borrowing screen
  and on each sync tick.
