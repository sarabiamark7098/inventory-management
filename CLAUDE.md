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

-- Change Log: an append-only audit trail of every write, including the
-- PURGE snapshot that outlives the row it describes.
CREATE TABLE sync_changes (
    id CHAR(36) PRIMARY KEY,
    entity_type VARCHAR(50) NOT NULL,
    entity_id CHAR(36) NOT NULL,
    operation VARCHAR(20) NOT NULL,
    data JSON NOT NULL,
    client_id VARCHAR(100),
    client_timestamp DATETIME(3) NOT NULL,
    synced_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    INDEX sync_changes_entity_idx (entity_type, entity_id),
    INDEX sync_changes_synced_at_idx (synced_at)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
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
  active loan, and it writes a full snapshot to `sync_changes` as operation
  `PURGE` first - after the delete that snapshot is the only remaining evidence
  the asset existed.

A restore can never collide on identifiers: MySQL's unique indexes cover
soft-deleted rows, so an archived asset keeps its asset tag, property number
and serials reserved for as long as it sits there.

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
