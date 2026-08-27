# ICT Equipment & Borrowing Management System

System for tracking ICT hardware, category taxonomies, and borrowing/return
workflows against a central MySQL database. The client is online-only: it holds
no copy of the inventory and requires a live connection to the API.

The functional specification lives in [CLAUDE.md](CLAUDE.md).

## Two projects

| Path | Project | Stack | Dev URL |
| --- | --- | --- | --- |
| [`web/`](web) | UI/UX | Vue 3, PrimeVue 4, Pinia, Vite | http://localhost:5173 |
| [`api/`](api) | API | Laravel 13, PHP 8.4, MySQL 8 | http://localhost:8000 |

They are independent: separate dependency trees, separate builds, separate
deployments. The only coupling is the HTTP sync contract, plus a small status
vocabulary intentionally duplicated in `api/app/Domain/InventoryStatus.php` and
`web/src/domain/constants.js`.

Deploying to a server? Copy `api/`, `web/` and `install-on-server.sh` across and
run `sudo bash install-on-server.sh`. Details and the manual procedure are in
**[DEPLOYMENT.md](DEPLOYMENT.md)** (Ubuntu 24.04,
nginx + PHP-FPM + MySQL, both projects on subpaths of one host).

> **The QR scanner needs HTTPS.** Browsers only expose the camera
> (`getUserMedia`) in a secure context — HTTPS or `localhost`. Over plain HTTP,
> or on a bare IP, the camera silently never starts and the app falls back to its
> text input. Everything else works normally.

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

That seeds one admin account, the 81-office register and the category taxonomy —
configuration a field office keeps. The sample equipment is **not** included,
because five fictional machines have no business in a property register that
gets audited. Add them to a demo or development database with:

```bash
cd api && php artisan db:seed --class=EquipmentSeeder
```

**2. UI**

```bash
cd web && npm install && npm run dev
```

Open http://localhost:5173.

Stop the API and the UI replaces itself with a "No connection" screen until it
comes back. That is deliberate — see [Connection model](#connection-model).

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

`UserSeeder` creates one admin from `SEED_ADMIN_EMAIL`; everyone else is
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
| Division | yes | From the office register |
| Section | **no** | From the register, filtered to the chosen division |
| Province | yes | Region XI provinces offered by default, free text allowed |
| PAR issued to | yes | The accountable officer named on the PAR |
| Sub-PAR issued to | no | Who holds it after onward reassignment |
| Actual user | no | The person actually using it — often not the accountable officer |
| Position | no | Their job position |
| Active Directory user | no | AD account on that machine |
| AnyDesk number | no | Remote support id |
| Remarks | no | Free text, after the specifications — anything the columns above have no place for |

The last five are optional by design: a spare laptop in the storeroom has no
user, and a projector has no Active Directory account. Requiring them would
block registering stock that has not been deployed yet.

Remarks are a `TEXT` column, not a capped string — a maintenance history
outgrows 255 characters quickly, and truncation would drop the newest entry.
Unlike the PAR fields, clearing remarks in the form is treated as a real edit
and sticks; the PAR fields are protected from being blanked by an incomplete
payload, but a note the operator deliberately erased should stay erased.

### The office register

Divisions and sections are one self-referencing table rather than free text: an
office with no parent **is** a division, one with a parent is a section inside
it. Typed text made "Administrative Division" and "Admin Division" two different
offices as far as any report was concerned.

Managed on the **Offices** screen and seeded from the field office's own
register (81 offices — 10 divisions, 71 sections). Equipment points at rows, so
renaming an office updates every asset at once.

Guards worth knowing: a section cannot belong to another section, an office
cannot be its own division, codes are unique, a division holding sections cannot
be demoted to one, and an office still holding assets or sections cannot be
removed. The server rejects a section that does not belong to the division it is
filed under.

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

Property number, acquisition date, division and PAR holder are enforced **on new
registrations only** — in the form, in the
Pinia store, and again in `SyncService::applyEquipment()`. Equipment registered
before this policy keeps syncing, so the numbers can be corrected from the app
instead of the record becoming impossible to save. An update can never blank out
a PAR holder or division that is already recorded.

Property number, PAR, holder, province, division and section are searchable,
filterable by province, included in the CSV export, and printed on the
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

Enforced in the client *and* in `SyncService::applyBorrowing()`, because a
client's working copy can be seconds stale — the server is what decides. The
guard applies to **new** loans only, so returning or correcting an existing loan
still saves even after the asset is withdrawn.

An asset on loan must be returned before it can be issued (`BORROWED → ISSUED`
is not a legal transition), and reconciliation never overwrites `ISSUED` — it is
an operator decision, like `MAINTENANCE`.

> **Naming:** `ISSUED` also exists as a *loan* status meaning "handed over for
> this loan". They are separate vocabularies — `equipment.status` versus
> `borrowing_transactions.status` — and are never compared to each other.

## Connection model

```
Vue component → Pinia inventory store → REST → Laravel → MySQL
                        │                                   │
                 in-memory working copy  ←  stored row  ←───┘
```

There is no local database. The store keeps a working copy of the last server
response so screens can render, sort and filter without a request per keystroke,
and that copy is dropped on sign-out and on reload. Every write goes to the API
and the **stored row comes back** — the server trims input, applies defaults and
reconciles equipment status from loan activity, so echoing the request payload
would show something subtly different from what was saved.

**The connection gate.** When the API cannot be reached the entire application
is replaced by a blocking screen that retries every 5 seconds. A form that looks
like it saved and did not is worse than one that refuses to open.

Reachability is decided by an actual response from `/api/health`, not by
`navigator.onLine` — that flag reports whether a network interface is up, and is
cheerfully `true` on a captive-portal wifi or when the API host itself is down.
Ordinary traffic reports in as it goes, so the heartbeat only has to run every
30 seconds while someone is working.

> **Upgrading from the offline build?** Earlier versions kept a full copy of the
> inventory in IndexedDB, and it outlived both sign-out and the upgrade. The new
> build deletes that database once on first load (`web/src/main.js`).

## Conflict resolution

| Case | Rule |
| --- | --- |
| Category / equipment metadata | Last-Write-Wins on UTC `updatedAt`; staler writes are dropped and the server document is echoed back |
| Duplicate asset tag | Hard `REJECTED` — the unique constraint is surfaced instead of silently dropping a record |
| Overlapping active loans on one asset | **Status Lock Rule** — the loan with the earlier `createdAt` keeps the asset (ties break on id, so every client reaches the same verdict); the loser is flagged `CONFLICT_PENDING_REVIEW` |
| Equipment availability | Derived server-side from the authoritative loan rows. `MAINTENANCE` / `DECOMMISSIONED` / `LOST` are operator decisions and are never overwritten |

These rules outlived offline mode because they were never really about offline:
two operators on two browsers can still submit overlapping loans for the same
asset within the same second.

A contested loan comes back as HTTP **409 with the stored row attached** — it is
not a failed write. The transaction was saved and flagged, because someone
physically has the device either way; the error message is how the operator
learns it needs adjudicating.

## The archive

Deleting anything is a **soft delete**: the row stays with `deleted_at` set, so
the loan history and property trail it belongs to keep reading correctly. The
**Archive** screen is where those records live.

| Action | Who | Notes |
| --- | --- | --- |
| Restore an asset | Anyone who may write inventory | Undoes a mistaken removal. An asset archived mid-loan returns `AVAILABLE` if that loan has since closed |
| Delete forever | **Admin only** | Erases the row. Refused while a borrower still holds the asset |

Restoring can never collide on identifiers: MySQL's unique indexes cover
soft-deleted rows, so an archived asset keeps its asset tag, property number and
serials reserved for as long as it sits there — nothing can have claimed them.

A permanent delete leaves a full snapshot of the row in the activity log as
operation `PURGE`. After that, the snapshot is the only remaining evidence the
asset ever existed, which is what a property audit will ask for. The loan rows survive too, with their `equipment_id` set to `NULL`, and
the confirmation dialog says how many will come loose before you agree to it.

## History

Every change anyone makes is recorded, and the **History** screen is where you
read it: who, what, when, and which fields moved from what to what. Filter by
person, record type, action or date range, or search an asset tag to get that
asset's whole trail.

Backed by [`spatie/laravel-activitylog`](https://github.com/spatie/laravel-activitylog),
driven from Eloquent model events rather than from the controllers — so a write
from the API, a console command or a seeder is recorded the same way, and no
code path can skip it by forgetting a call. Nothing in the app can edit or delete
an entry.

| Action | Recorded as |
| --- | --- |
| Register a record | `INSERT` |
| Edit one | `UPDATE`, with the before and after of each changed field |
| Archive one | `DELETE` |
| Restore one | `RESTORE` |
| Delete one forever | `PURGE`, with a full snapshot of what was destroyed |

Account activity — provisioning, role changes, deactivation — is recorded too,
and is visible only to admins.

Three details worth knowing, because they are where the package's defaults do
not fit this schema:

- Nothing here uses Laravel's `SoftDeletes`; `deleted_at` is an ordinary column.
  To Eloquent, archiving and restoring are both plain updates, so the trait
  reads the column's transition to tell the three apart.
- The package suppresses the update that clears `deleted_at`, expecting
  `SoftDeletes` to fire a `restored` event. It never does here, so that
  suppression is overridden — otherwise restores would be the one thing the log
  missed.
- An entry stores only what changed, so an edit carries no asset tag. The screen
  resolves names from the live record, then from the entry's snapshot, then from
  that record's `INSERT` or `PURGE` entry — which is how a purged asset's trail
  still reads by name.

> The previous audit table, `sync_changes`, has been migrated into the activity
> log and is no longer written to. It is left in place so the move stays
> reversible; nothing reads it.

## Tests

```bash
cd api && php artisan test
```

157 tests / 548 assertions covering the loan maths, the conflict engine in both
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
