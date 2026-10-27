# CLAUDE.md - System Specification & Build Instructions

## Project Overview
This repository contains the **ICT Equipment & Borrowing Management System**, an online web application for tracking ICT hardware, managing categorical taxonomies, and handling borrowing/return workflows. The client is a thin, connected front end: it requires a live connection to the API and stores nothing on the device.

---

## Architecture Strategy: Connected Client + REST API

The system is delivered as **two independent projects** in this repository:

```
inventory-management/
  web/   -> UI/UX project   (Vue 3 + PrimeVue + Pinia, Vite dev server :5173)
  api/   -> API project     (Laravel + MySQL, php artisan serve :8000)
```

They share no build pipeline and deploy separately. Their only contract is the
REST API below, plus the status vocabulary duplicated in
`api/app/Domain/InventoryStatus.php` and `web/src/domain/constants.js`.

```
+-----------------------------------------------------------------------+
|                    UI/UX Project  (web/ :5173)                        |
|  +---------------------------+       +------------------------------+ |
|  | Vue 3 + PrimeVue + Pinia  | <---> | In-memory working copy       | |
|  +---------------------------+       +--------------+---------------+ |
|                                                     |                 |
|                        Connection gate: no connection, no application |
+-----------------------------------------------------|-----------------+
                                                      |
                                            CORS / HTTP JSON
                                                      v
+-----------------------------------------------------------------------+
|                    API Project  (api/ :8000)                          |
|  +---------------------------+       +-----------------------------+  |
|  |   Laravel Route Handlers  | <---> | Central Database (MySQL)     | |
|  +---------------------------+       +-----------------------------+  |
+-----------------------------------------------------------------------+
```

### Core Stack
- **Frontend Framework:** Vue 3 (Composition API, `<script setup>`) built with Vite
- **UI Component Library:** PrimeVue 4 (Aura theme, MIT-licensed line) + PrimeIcons
- **Client State:** Pinia (`inventory` store for data, `connection` store for reachability)
- **Backend API:** Laravel (PHP 8.4) REST route handlers
- **Central Storage:** MySQL 8

---

## User Stories & Functional Requirements

### Epic 0: Accounts & Authentication
- **US-0.1:** As a User, sign in without a password by receiving a one-time code at my work email.
- **US-0.2:** As a System Admin, provision accounts and assign roles (`ADMIN`, `MANAGER`).
- **US-0.3:** As a System Admin, deactivate an account and have it lose access immediately on every device.
- **US-0.4:** As a User, update my own display name; only an admin can change my email or role.

### Epic 1: Category & Attribute Management
- **US-1.1:** As an Admin, create, update, and soft-delete equipment categories (e.g., Laptops, Networking, Peripherals).
- **US-1.2:** As an Admin, define dynamic custom metadata fields (JSON schema) per category (e.g., RAM/Processor for Laptops, MAC Address for Routers).
- **US-1.3:** As an Admin, set default loan durations per category to enforce auto-calculated return dates.
- **US-1.4:** As an Admin, manage the office register — divisions and the sections within them — so equipment is filed against a real office rather than typed text.

### Epic 2: ICT Equipment Inventory Management
- **US-2.1:** As an Inventory Manager, register new equipment with key attributes (Serial Number, Asset Tag, Category, Status, Location, Condition, Custom Specs).
- **US-2.2:** As an Inventory Manager, generate, print, and scan QR codes/barcodes for fast device audits and lookups.
- **US-2.3:** As an Inventory Manager, manage equipment status state transitions: `AVAILABLE`, `BORROWED`, `ISSUED`, `MAINTENANCE`, `DECOMMISSIONED`, `LOST`. `ISSUED` marks an asset permanently handed to a named user on a PAR, which takes it out of the loan pool entirely — unlike `BORROWED`, which is temporary.
- **US-2.4:** As an Inventory Manager, filter, search, and export equipment lists without a round-trip per keystroke.
- **US-2.5:** As a Property Officer, record the accountability trail for each asset — Property Number, Date Acquired, Division, Province and the officer the PAR is issued to (all required), plus an optional Section and Sub-PAR holder for onward reassignment.
- **US-2.6:** As an Inventory Manager, record who is actually using an asset (name, position, Active Directory account, AnyDesk number) separately from who is accountable for it on the PAR.
- **US-2.7:** As an Inventory Manager, flag a category as recording two serial numbers, so a desktop set captures both the system unit and the monitor as one asset.
- **US-2.8:** As an Inventory Manager, add free-text remarks to an asset for anything the structured fields have no column for — defect history, transfer notes, why a unit is set aside.
- **US-2.9:** As an Inventory Manager, review everything that has been removed in an Archive screen and restore an asset archived by mistake; as a System Admin, permanently destroy an archived asset that has been disposed of, leaving an audit snapshot behind.
- **US-2.11:** As an Inventory Manager, bulk-register equipment from a CSV using a column set matched to the type of asset, download a sample file with the right headers, and see exactly what a file would do before any of it is saved.
- **US-2.10:** As a Property Officer, open a History screen and see every change anyone has made — who, what, when, and which fields moved from what to what — filtered by person, record type, action or date.

### Epic 3: Borrowing & Return Management
- **US-3.1:** As an Inventory Manager, record a loan request on a borrower's behalf, specifying required dates and intended usage. Borrowers are named on the transaction and do not hold accounts.
- **US-3.2:** As an Inventory Manager, process, approve, and issue equipment with digital barcode confirmation.
- **US-3.3:** As an Inventory Manager, process returns, record item condition changes, and log damages or missing accessories.
- **US-3.4:** As a System Admin, trigger automated overdue notifications and track borrowing history logs.

### Epic 4: Connected Operation
- **US-4.1:** As a User, always see current data, because every screen reads from the central database rather than a copy.
- **US-4.2:** As a User, be told plainly when the server is unreachable instead of being allowed to type into a form that cannot be saved.
- **US-4.3:** As a System Admin, have concurrent edits from two operators adjudicated deterministically (Last-Write-Wins on attributes; the Status Lock Rule on physical stock states).
- **US-4.4:** As a User, view a live connection badge (`Online`, `Reconnecting`, `Offline`) and refresh on demand.

---

## Database Schemas

### Central MySQL Schema

MySQL has no native UUID type, so ids are `CHAR(36)`. Timestamps use
`DATETIME(3)` rather than `TIMESTAMP`, whose range ends in 2038 — loan due dates
must be able to outlive that. All timestamps are stored and compared in UTC.

The authoritative definition lives in `api/database/migrations/`; the block below
is a readable summary of what those migrations produce.

```sql
-- Categories Table
CREATE TABLE categories (
    id CHAR(36) PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    custom_fields JSON NOT NULL,
    default_loan_days INT NOT NULL DEFAULT 7,
    -- Desktop sets record a system unit serial and a monitor serial.
    requires_monitor_serial TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    deleted_at DATETIME(3),
    INDEX categories_updated_at_idx (updated_at)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Offices Table: divisions and the sections inside them.
-- division_id NULL means this office IS a division.
CREATE TABLE offices (
    id CHAR(36) PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(255) NOT NULL,
    division_id CHAR(36),
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    deleted_at DATETIME(3),
    INDEX offices_division_idx (division_id),
    FOREIGN KEY (division_id) REFERENCES offices(id) ON DELETE RESTRICT
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Equipment Table
CREATE TABLE equipment (
    id CHAR(36) PRIMARY KEY,
    asset_tag VARCHAR(100) NOT NULL UNIQUE,
    property_number VARCHAR(100) UNIQUE,
    date_acquired DATE,
    serial_number VARCHAR(100) UNIQUE,
    monitor_serial_number VARCHAR(100) UNIQUE,
    category_id CHAR(36),
    model_name VARCHAR(255) NOT NULL,
    brand VARCHAR(100),
    status VARCHAR(50) NOT NULL DEFAULT 'AVAILABLE',
    `condition` VARCHAR(50) NOT NULL DEFAULT 'GOOD',
    location VARCHAR(255),
    -- Government property accountability. Nullable so assets registered before
    -- the policy stay syncable; the PAR pair is required on new registrations.
    division_id CHAR(36),          -- office register; required on new records
    section_id CHAR(36),           -- optional
    province VARCHAR(100),
    par_issued_to VARCHAR(255),
    sub_par_issued_to VARCHAR(255),
    -- Who is actually using the asset, and how support reaches it.
    actual_user VARCHAR(255),
    position VARCHAR(255),
    ad_username VARCHAR(100),
    anydesk_number VARCHAR(50),
    specifications JSON NOT NULL,
    remarks TEXT,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    deleted_at DATETIME(3),
    INDEX equipment_category_id_idx (category_id),
    INDEX equipment_status_idx (status),
    INDEX equipment_updated_at_idx (updated_at),
    INDEX equipment_par_issued_to_idx (par_issued_to),
    INDEX equipment_province_idx (province),
    INDEX equipment_division_idx (division_id),
    INDEX equipment_section_idx (section_id),
    INDEX equipment_actual_user_idx (actual_user),
    INDEX equipment_ad_username_idx (ad_username),
    INDEX equipment_anydesk_idx (anydesk_number),
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
    FOREIGN KEY (division_id) REFERENCES offices(id) ON DELETE RESTRICT,
    FOREIGN KEY (section_id) REFERENCES offices(id) ON DELETE RESTRICT
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Borrowing Transactions Table
CREATE TABLE borrowing_transactions (
    id CHAR(36) PRIMARY KEY,
    equipment_id CHAR(36),
    borrower_name VARCHAR(255) NOT NULL,
    borrower_email VARCHAR(255) NOT NULL,
    issue_date DATETIME(3) NOT NULL,
    expected_return_date DATETIME(3) NOT NULL,
    actual_return_date DATETIME(3),
    status VARCHAR(50) NOT NULL DEFAULT 'ISSUED',
    notes TEXT,
    conflict_reason TEXT,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    deleted_at DATETIME(3),
    INDEX borrowing_transactions_equipment_id_idx (equipment_id),
    INDEX borrowing_transactions_status_idx (status),
    INDEX borrowing_transactions_updated_at_idx (updated_at),
    FOREIGN KEY (equipment_id) REFERENCES equipment(id) ON DELETE SET NULL
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Activity Log: an append-only audit trail of every write, from
-- spatie/laravel-activitylog. Published from the package with one change —
-- `subject_id` is a string, because four of the five logged models key on
-- CHAR(36) UUIDs and the package's bigint would coerce every one of them to 0.
CREATE TABLE activity_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    log_name VARCHAR(255),              -- equipment | categories | offices | borrowings | users
    description TEXT NOT NULL,          -- the operation, same vocabulary as `event`
    subject_type VARCHAR(255),
    subject_id VARCHAR(36),             -- the record this happened to
    event VARCHAR(255),                 -- INSERT | UPDATE | DELETE | RESTORE | PURGE
    causer_type VARCHAR(255),
    causer_id BIGINT UNSIGNED,          -- users.id; NULL once the account is deleted
    attribute_changes JSON,             -- { attributes: {...}, old: {...} }
    properties JSON,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    INDEX activity_log_log_name_index (log_name),
    INDEX subject (subject_type, subject_id),
    INDEX causer (causer_type, causer_id),
    INDEX activity_log_created_at_index (created_at),
    INDEX activity_log_event_created_at_index (event, created_at)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- `sync_changes` was the previous audit log. Its rows were copied into
-- activity_log and nothing writes to it any more; the table is left in place
-- only so the move stays reversible.
```

---

## Connection Model & Conflict Strategy

1. **No local persistence.** The Pinia store holds a working copy in memory for
   rendering and filtering; it is dropped on sign-out and on reload. Nothing
   about the inventory is written to the device.
2. **Reads:** `GET /api/{offices,categories,equipment,borrowings}` on sign-in,
   on reconnect, and on demand.
3. **Writes:** `POST /api/{table}`, `PUT /api/{table}/{id}`,
   `DELETE /api/{table}/{id}`. Each returns the stored row, which replaces the
   working copy's version — the server normalises input and reconciles equipment
   status from loan activity, so the response is often not what was sent.
4. **The connection gate.** `web/src/stores/connection.js` treats a real response
   from `/api/health` as the source of truth, not `navigator.onLine`, which
   reports only that a network interface is up. When the API is unreachable the
   whole application is replaced by a blocking screen.
5. **Conflict Rules** — still needed with no offline client, because two
   operators on two browsers can submit overlapping loans within the same second:
   - **Loan Pool Rule:** Equipment in `ISSUED`, `MAINTENANCE`, `DECOMMISSIONED` or `LOST` cannot be booked at all. Enforced server-side, because a client's working copy can be seconds stale.
   - **Status Lock Rule:** Physical loan states (`BORROWED`, `ISSUED`) are validated server-side. On a dual checkout the loser is stored and flagged `CONFLICT_PENDING_REVIEW` rather than dropped - someone physically has the device either way. The API answers `409` with the stored row attached.
   - **Attribute Updates:** Managed via Last-Write-Wins (LWW) utilizing UTC timestamps.

---

## The Archive

Deleting anywhere in the system is a **soft delete**: `deleted_at` is set and
the row stays, so the loan history and property trail it belongs to keep
reading correctly.

- `GET /api/archive` returns every soft-deleted record across all four tables,
  with each asset's loan count and active-loan count.
- `POST /api/archive/equipment/{id}/restore` puts an asset back. Open to anyone
  who may write inventory - it undoes a mistake. An asset archived mid-loan
  comes back `AVAILABLE` if that loan has since closed.
- `DELETE /api/archive/equipment/{id}` destroys the row permanently.
  **Admin-only**, refused while the asset is not archived or is still out on an
  active loan. Deleting the row fires Eloquent's `deleted` event, and the
  activity log entry that produces carries a full snapshot of it - after the
  delete that entry is the only remaining evidence the asset existed.

A restore can never collide on identifiers: MySQL's unique indexes cover
soft-deleted rows, so an archived asset keeps its asset tag, property number
and serials reserved for as long as it sits there.

---

## How a category shapes the equipment form

The form is category-driven in three ways, all of them data rather than markup,
so a field office can change what it records without changing code:

- **`custom_fields`** lists the specifications the category records, and the
  form renders exactly those. A field may also name a **`slot`**, which lifts it
  out of the Specifications block and draws it beside the field it belongs with:
  `identity` next to the property number (a machine's host names), `monitor`
  next to the monitor's serial (its property number), `deployment` ahead of the
  actual user (their ID number). A host name read half a screen from the
  property number is harder to check against the machine in front of you.
- **`requires_monitor_serial`** makes an asset two serialised parts, and the
  monitor serial a required field.
- **`tracks_anydesk`** decides whether the AnyDesk field appears at all — a
  projector has no remote session. An asset already holding a number keeps
  showing the field whatever its category now says, so switching the flag off
  hides it without stranding a value nobody can edit.

`SpecificationField` renders one input and `SpecificationFields` the block of
them, which is what lets a slotted field sit inside the main grid rather than
nesting a second grid in a cell.

---

## The Activity History

Every write is recorded by `spatie/laravel-activitylog`, driven from Eloquent's
model events by the `RecordsActivity` trait rather than from any controller.
That is what makes the log complete: a write from the REST API, a console
command or a seeder is recorded the same way, and no code path can skip it by
forgetting a call.

- `GET /api/history` — newest first, paginated, filterable by `search`,
  `entityType`, `operation`, `actor`, `from` and `to`.
- `GET /api/history/{id}` — one entry with its field-level before/after.

Read-only by construction: no route edits or deletes an entry.

Three things the package could not know about this schema, all handled in the
trait:

1. **Operations, not events.** Nothing here uses Laravel's `SoftDeletes`, so
   archiving and restoring are both plain `updated` events. The trait reads the
   `deleted_at` transition to tell `DELETE`, `RESTORE` and `UPDATE` apart, and
   writes the result to both `description` and the indexed `event` column.
2. **Restores must be logged.** The package suppresses the `updated` event that
   clears `deleted_at`, assuming `SoftDeletes` will fire its own `restored`
   event. That event never comes here, so `isRestoring()` is overridden to
   `false` — otherwise a restore is the one operation the log silently misses.
3. **A destroyed row has no "after".** The package files a deleted record's
   final state under `old` rather than `attributes`, so the History screen
   reads whichever half is present.

**Who can read it.** Any signed-in user, except account activity (`users`),
which is admin-only — in the listing, in the filter options, and on the
single-entry route, so hiding it is a permission rather than a filter.

**Naming records.** An entry stores only the fields that changed, so an edit
carries no asset tag. The screen resolves the record's name from the live table,
falling back to the entry's own snapshot, and finally to the record's `INSERT`
or `PURGE` entry elsewhere in the log — which is what lets a purged asset's
whole trail still read by name. Search works the same way in reverse, so
searching an asset tag returns every entry about that asset rather than only the
ones that happen to contain the text.

---

## Import

Bulk registration from a CSV, with an **import profile per type of ICT
equipment** — `desktop`, `laptop`, `tablet` — because the field office's own
workbook keeps a sheet per type and they genuinely differ. A desktop records a
monitor and a host name; a tablet records neither but does record a charger. One
universal column set would make every type's required fields optional, which is
the same as validating nothing.

```
GET  /api/import/profiles              the types, and every column each accepts
GET  /api/import/{profile}/template    the sample CSV  (?headersOnly=1 for no examples)
POST /api/import/{profile}/preview     a dry run — writes nothing
POST /api/import/{profile}             the write
```

A profile is `app/Imports/*ImportProfile.php`: an ordered list of
`ImportColumn`s naming the payload fields each header feeds, assembled from the
factories on the base class. The order is what the generated template emits, so
it mirrors the sheets the field office actually works from. Adding printers or
scanners is one subclass plus a line in `ImportProfileRegistry`.

**A column that writes a specification must be declared by its category.** The
equipment form renders exactly the fields `category.custom_fields` names, so a
`spec:` target no category declares is stored and searchable but can never be
read or edited on the equipment screen. `CategorySeeder` and the profiles are
therefore two halves of one thing, and `ImportTest` fails if they drift apart.
Retiring a field means retiring its column too, or the import writes somewhere
nothing displays — the workbook's `Description` and combined
`Keyboard / Mouse Available ( K / M )` are now reported as unrecognised for
exactly that reason.

Yes/no columns (`XDR`, `Sentinel`, `Keyboard`, `Mouse`, `Working Charger`, the
three warranties) settle on `Yes`/`No` on the way in, because the equipment form
shows them as a two-option dropdown and a value outside it renders blank. A cell
the pattern does not recognise is kept verbatim instead — the older sheets write
`YES - 8.7` under XDR, and losing the agent version would be worse than an
untidy value, which is also why `xdr` is a text field rather than a dropdown.

**Columns are matched by header name, never by position.** Both sides are
reduced to letters and digits before comparing, so spacing, punctuation and
capitalisation stop mattering, and each column carries the source workbook's own
spellings as aliases — including `Date Aquired`, misspelt there since at least
2024. Unmatched and repeated headers are reported rather than ignored, because a
column the operator believed was being read, silently dropped, puts a quietly
incomplete record into an audited register.

**Preview and commit run identical validation**; the only difference is whether
the result is handed to `SyncService`. Rows are applied individually, so one bad
record cannot discard 900 good ones.

**Corrections** close the loop between the two. Each row comes back with `fix`
(the columns its errors point at), `cells` (the file's own values) and
`corrected` (the edits in force), and the screen edits a failing row in place
rather than sending the operator back to Excel. The request carries them as
`corrections`, a JSON map of line → column → value.

A correction replaces a **cell, not a verdict**: the file is read and validated
again with the edits overlaid, so a corrected row still has to satisfy every
rule — a corrected property number can still collide, and a corrected date can
still be unreadable. The client never submits a finished record, which is what
keeps the server the only authority. `cells` deliberately stays un-overlaid, so
the editor can tell a real edit from a value typed back to what the file already
said, and drop the correction in that case.

The client re-previews before it will commit: the store compares a signature of
the defaults and corrections against the one the report was built from, and
blocks the import button while they differ. Gating on "are there corrections?"
instead would disable it permanently after the first re-check.

### At volume

A five thousand row file is the limit (`CsvReader::MAX_ROWS`), and reaching it
took four changes. Measured on that file, the commit went from 34.1s and 57,004
queries to 10.4s and 9,520 — PHP's default execution limit is 30s, so the first
number was not a slow import but a failed one.

1. **`SyncService::pushBatch()`** replaces `push()` for the import. A
   `BulkContext` resolves the offices and categories once instead of per row,
   each chunk of 500 shares one transaction, and the per-row uniqueness SELECTs
   are skipped because the importer has already run them — the unique indexes
   remain the guarantee. A chunk that does hit one rolls back and is replayed
   row by row, so a race costs one record rather than five hundred.
2. **The activity logger stops re-reading each row.** Spatie calls `fresh()`
   inside `resolveModelForLogging()`, a third of all the queries a bulk insert
   issues. {@see App\Models\Concerns\ActivityLogMode} scopes the skip to
   `created` events inside a batch, where every column was set explicitly and
   the re-read provably cannot differ. Ordinary writes keep the safeguard.
3. **The report lists a sample, not the file.** `present()` returns every row
   needing attention up to 500, plus 25 clean ones; `summary` still counts
   everything and `rowsOmitted` says how many are not listed. This took the
   response from 11.2MB to 0.4MB, and the `cells` that back the correction
   editor are now built only for listed rows.
4. **Nothing scales with the register.** The identifier snapshot queries only
   the values the file carries rather than reading the equipment table, and the
   next asset tag is a `MAX()` in SQL rather than every tag pulled into PHP.

The costs that remain are the two the audit trail requires: one insert for the
asset and one for its log entry. Batching those would be faster still and is
the reason not to — a hand-written bulk insert is exactly the code path that
can forget to log.

Four things the spreadsheets need that a tidy CSV would not:

1. **Combined identifier columns.** `PROPERTY NO / SN No.` packs two values into
   one cell. A column declares several `targets` and the value splits across
   them in order. A cell with no separator goes to the column's `soleTarget` —
   the property number for the main column, but the *serial* for the monitor
   column, whose cells hold bare serials despite its header.
2. **Placeholders.** `N/A` is stripped from identifier columns only. A literal
   there would take a unique index and reject every later row; the same word in
   a descriptive column is a real answer and is kept.
3. **Spreadsheet padding.** A trailing row holding nothing but a leftover
   `FALSE` is counted as `BLANK`, not reported as a failed import. The source
   sheets carry around 900 of them.
4. **Ambiguous dates.** `03/04/2026` is refused rather than guessed. Acquisition
   dates drive useful-life reporting, so only unambiguous forms are accepted —
   `YYYY-MM-DD`, textual months, an Excel day count, or a slash date where one
   part is too large to be a month.

Asset tags are generated per profile (`ICT-DT-0042`) when a row has none, since
the register keys on property numbers and has no tag column.

### Derived records

A workstation's row can name a printer and a scanner, and those are two further
pieces of equipment rather than two more facts about the computer — each has
its own property number, and therefore its own PAR. So the `printer:` and
`scanner:` column targets do not become specifications on the machine: the
importer registers a **Printers** and a **Scanners** asset from them, tagged
`ICT-PRN-####` and `ICT-SCN-####`.

A derived record inherits what it genuinely shares — division, section,
province, location, acquisition date, PAR holder, actual user, status — because
a printer delivered with a machine was bought and issued alongside it. It must
bring its own **model and property number**, which are what identify it; a row
that names a printer without them gets a warning rather than a half-identified
asset. `spec:attachedTo` holds the workstation's asset tag, which is what keeps
the pair findable once they are separate records.

Derived rows run through the same `examine()` as any other, with their values
preset rather than read from columns, so every rule applies to them unchanged —
including clashing with a property number already in the register. They carry
`derivedFrom` (the line they came from) and offer no correction editor of their
own: the fix is to correct that line's `Printer Property` cell and re-check.

`summary.derived` counts them, and `summary.total` still counts file lines —
which is why the screen's record count is `total - blank + derived`.

**`parFallback`** is opt-in and never automatic: the source workbook leaves
`PAR / Assigned to` empty on every row and names the holder under
`Sub-PAR / Actual User`, but the PAR names who is *financially accountable*,
which is frequently not the user. The operator asks for the substitution, and
every row it touches carries a warning saying so.

---

## Working On This Repository

### Running both projects

```bash
# terminal 1 — API
cd api && php artisan serve --port=8000

# terminal 2 — UI
cd web && npm run dev
```

The UI reads `VITE_API_URL` (default `http://localhost:8000/api`). The API allows
the UI's origin via `FRONTEND_URLS` in `api/.env`.

### Checks

```bash
cd api && php artisan test
```

```bash
cd web && npm run build
```

`api/tests` runs against a real MySQL database (`ict_inventory_test`), because the
sync engine depends on MySQL's unique-constraint behaviour and `DATETIME(3)`
precision that SQLite does not reproduce faithfully.

### Authentication

Passwordless email OTP exchanged for a Sanctum bearer token. Only the code hash
is stored; codes are single-use, expire in 10 minutes, burn after 5 wrong
guesses, and are rate limited per address and per IP. `request-code` answers
identically for unknown addresses so it cannot enumerate staff emails.

Accounts are admin-provisioned — there is no public sign-up. Roles gate access
in three independent places: the UI hides what you cannot do, the router guards
the route, and the API enforces it (`EnsureRole`). Only the last is a security
boundary.

### Conventions
- Vue components use `<script setup>`; state lives in Pinia stores, never in
  ad-hoc module globals.
- Authorization is enforced server-side; treat client-side role checks purely as
  user experience.
- Every read and write on the client goes through `web/src/stores/inventory.js`;
  components never call the API directly.
- Writes on the API always go through `App\Services\SyncService`, including the
  REST controllers, so the conflict rules have exactly one implementation. The
  name predates the removal of offline mode; it is now simply the write path.
