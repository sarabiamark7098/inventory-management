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

Deploying to a server? See **[DEPLOYMENT.md](DEPLOYMENT.md)** (Ubuntu 24.04,
nginx + PHP-FPM + MySQL).

> **HTTPS is a functional requirement, not just hardening.** The QR scanner uses
> `getUserMedia()`, which browsers only expose in a secure context. Over plain
> HTTP the camera silently never starts.

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

There is no borrower role. A borrower is recorded on the loan itself by name and
email and never signs in, so an account for them would be a login with nothing
to do. Everyone who signs in handles inventory.

The client hides what a role cannot do, the router guards the routes, and the
API enforces it independently — the UI is a convenience, not the boundary.

The seeder creates one admin from `SEED_ADMIN_EMAIL`; everyone else is
provisioned from the Accounts screen. Every address must be a real mailbox on an
allowed domain, since a sign-in code has to actually arrive. With
`MAIL_MAILER=log` the code is written to `api/storage/logs/laravel.log`
instead of being sent:

```bash
grep "Sign-in code" api/storage/logs/laravel.log | tail -1
```

## Government property accountability

Equipment registration captures the fields a Philippine public-sector inventory
is audited against:

| Field | Required | Notes |
| --- | --- | --- |
| Property number | yes | Official government identifier; unique, and the scanner resolves it |
| Date acquired | yes | Calendar date, drives useful-life reporting |
| Division | yes | Office unit holding the asset |
| Section | yes | Unit within the division |
| Province | yes | Region XI provinces offered by default, free text allowed |
| PAR number | yes | Property Acknowledgement Receipt |
| PAR issued to | yes | The accountable officer named on the PAR |
| Sub-PAR number | no | Only when reassigned onward from that officer |
| Sub-PAR issued to | only with a Sub-PAR number | Who holds it now |
| Actual user | no | The person actually using it — often not the accountable officer |
| Position | no | Their job position |
| Active Directory user | no | AD account on that machine |
| AnyDesk number | no | Remote support id |

The last four are optional by design: a spare laptop in the storeroom has no
user, and a projector has no Active Directory account. Requiring them would
block registering stock that has not been deployed yet.

### Desktop sets: two serial numbers

A desktop is one asset with two serialised parts, so it records the **system
unit** serial in `serialNumber` and the **monitor** serial in
`monitorSerialNumber`.

Which categories work that way is a flag on the category
(`requiresMonitorSerial`), ticked in the category editor — not inferred by
string-matching the name "desktop", so an office can apply it to any category
that pairs two serialised parts.

When the flag is on, registering equipment in that category requires both
serials. Serials are checked **across both columns**: a monitor serial cannot
collide with another machine's system unit serial, and one asset cannot use the
same serial for both parts — otherwise scanning one physical device would
resolve to two records. The scanner resolves either serial to the same asset.

The PAR pair is enforced **on new registrations only** — in the form, in the
Pinia store, and again in `SyncService::applyEquipment()`. Equipment registered
before this policy keeps syncing, so the numbers can be corrected from the app
instead of the record becoming impossible to save. An update can never blank out
a PAR number or holder that is already recorded.

Property number, PAR, holder, province, division and section are searchable,
filterable by province, included in the offline CSV export, and printed on the
QR labels. The scanner resolves an asset tag, a property number, a serial number
or a QR payload.

Acquisition dates are handled as **calendar dates**, never timestamps: the client
formats them from local date parts rather than `toISOString()`, which in UTC+8
would shift a date picked as the 15th back to the 14th.

### Equipment out of the loan pool

`ISSUED` means an asset is permanently handed to a named user on a PAR — a staff
member's own workstation, not shared stock — so it **can never be booked out**.
That is different from `BORROWED`, which is merely busy until the current loan
ends; a future loan on a borrowed asset is still legitimate and the overlap
engine judges the dates.

| Equipment status | Can be borrowed? |
| --- | --- |
| `AVAILABLE` | yes |
| `BORROWED` | yes — a future, non-overlapping window |
| `ISSUED` | **no** |
| `MAINTENANCE` | **no** |
| `DECOMMISSIONED` | **no** |
| `LOST` | **no** |

Enforced in the client *and* in `SyncService::applyBorrowing()`, because an
offline client may not have seen the status change yet — the server is what
decides. The guard applies to **new** loans only, so returning or correcting an
existing loan still syncs even after the asset is withdrawn.

An asset on loan must be returned before it can be issued (`BORROWED → ISSUED`
is not a legal transition), and reconciliation never overwrites `ISSUED` — it is
an operator decision, like `MAINTENANCE`.

> **Naming:** `ISSUED` also exists as a *loan* status meaning "handed over for
> this loan". They are separate vocabularies — `equipment.status` versus
> `borrowing_transactions.status` — and are never compared to each other.

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

When a document is skipped, the sync checkpoint is **rewound to just before it**
rather than advanced to the server time. Otherwise the next incremental pull
would start after that document and the server's version would never be offered
again — the two copies would silently diverge forever.

## Tests

```bash
cd api && php artisan test
```

104 tests / 360 assertions covering the loan maths, the conflict engine in both
arrival orders, Last-Write-Wins, equipment reconciliation, request validation,
the full OTP lifecycle (expiry, reuse, brute force, rate limiting, enumeration
resistance), the email domain policy, property accountability (PAR required on registration,
never blanked by an update), the desktop two-serial rule and cross-column serial
uniqueness, the loan-pool guard on issued/withdrawn assets, role enforcement,
and account management.
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
