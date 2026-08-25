# CLAUDE.md - System Specification & Build Instructions

## Project Overview
This repository contains the **ICT Equipment & Borrowing Management System**, a local-first, offline-capable hybrid web application designed for tracking ICT hardware, managing categorical taxonomies, and handling borrowing/return workflows with automated bidirectional background synchronization.

---

## Architecture Strategy: Local-First Hybrid Model

The system is delivered as **two independent projects** in this repository:

```
inventory-management/
  web/   -> UI/UX project   (Vue 3 + PrimeVue + Pinia, Vite dev server :5173)
  api/   -> API project     (Laravel + MySQL, php artisan serve :8000)
```

They share no build pipeline and deploy separately. Their only contract is the
HTTP sync protocol below, plus the status vocabulary duplicated in
`api/app/Domain/InventoryStatus.php` and `web/src/domain/constants.js`.

```
+-----------------------------------------------------------------------+
|                    UI/UX Project  (web/ :5173)                        |
|  +---------------------------+       +------------------------------+ |
|  | Vue 3 + PrimeVue + Pinia  | <---> | Local Database (Dexie/IndexedDB)|
|  +---------------------------+       +--------------+---------------+ |
+-----------------------------------------------------|-----------------+
                                                      |
                                          Background Sync Engine
                                          (Pinia store + outbox queue)
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
- **Client State:** Pinia (`inventory` store for data, `sync` store for the engine)
- **Local Client Store:** Dexie / IndexedDB (for zero-latency offline read/write operations)
- **Backend API:** Laravel (PHP 8.4) REST route handlers
- **Central Storage:** MySQL 8
- **Synchronization Engine:** Custom Outbox Queue with HTTP Pull/Push REST

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

### Epic 2: ICT Equipment Inventory Management
- **US-2.1:** As an Inventory Manager, register new equipment with key attributes (Serial Number, Asset Tag, Category, Status, Location, Condition, Custom Specs).
- **US-2.2:** As an Inventory Manager, generate, print, and scan QR codes/barcodes for fast device audits and lookups.
- **US-2.3:** As an Inventory Manager, manage equipment status state transitions: `AVAILABLE`, `BORROWED`, `ISSUED`, `MAINTENANCE`, `DECOMMISSIONED`, `LOST`. `ISSUED` marks an asset permanently handed to a named user on a PAR, which takes it out of the loan pool entirely — unlike `BORROWED`, which is temporary.
- **US-2.4:** As an Inventory Manager, filter, search, and export equipment lists offline.
- **US-2.5:** As a Property Officer, record the accountability trail for each asset — Property Number, Date Acquired, Division, Section, Province, PAR number and the officer it is issued to (all required), plus an optional Sub-PAR number and holder for onward reassignment.
- **US-2.6:** As an Inventory Manager, record who is actually using an asset (name, position, Active Directory account, AnyDesk number) separately from who is accountable for it on the PAR.
- **US-2.7:** As an Inventory Manager, flag a category as recording two serial numbers, so a desktop set captures both the system unit and the monitor as one asset.

### Epic 3: Borrowing & Return Management
- **US-3.1:** As an Inventory Manager, record a loan request on a borrower's behalf, specifying required dates and intended usage. Borrowers are named on the transaction and do not hold accounts.
- **US-3.2:** As an Inventory Manager, process, approve, and issue equipment with digital barcode confirmation.
- **US-3.3:** As an Inventory Manager, process returns, record item condition changes, and log damages or missing accessories.
- **US-3.4:** As a System Admin, trigger automated overdue notifications and track borrowing history logs.

### Epic 4: Hybrid Offline Operations & Auto-Syncing
- **US-4.1:** As a User, perform all CRUD operations locally without an active internet connection.
- **US-4.2:** As a User, automatically synchronize local offline changes with the central database upon network restoration.
- **US-4.3:** As a System Admin, handle data conflicts via deterministically governed policies (Server-Wins with Local Override flags for physical stock states).
- **US-4.4:** As a User, view a live visual status badge (`Online`, `Offline`, `Syncing`, `Sync Pending [X]`).

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
    section VARCHAR(255),
    division VARCHAR(255),
    province VARCHAR(100),
    par_number VARCHAR(100),
    par_issued_to VARCHAR(255),
    sub_par_number VARCHAR(100),
    sub_par_issued_to VARCHAR(255),
    -- Who is actually using the asset, and how support reaches it.
    actual_user VARCHAR(255),
    position VARCHAR(255),
    ad_username VARCHAR(100),
    anydesk_number VARCHAR(50),
    specifications JSON NOT NULL,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    deleted_at DATETIME(3),
    INDEX equipment_category_id_idx (category_id),
    INDEX equipment_status_idx (status),
    INDEX equipment_updated_at_idx (updated_at),
    INDEX equipment_par_number_idx (par_number),
    INDEX equipment_par_issued_to_idx (par_issued_to),
    INDEX equipment_province_idx (province),
    INDEX equipment_office_idx (division, section),
    INDEX equipment_actual_user_idx (actual_user),
    INDEX equipment_ad_username_idx (ad_username),
    INDEX equipment_anydesk_idx (anydesk_number),
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
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

-- Sync Queue Log Table
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

## Offline Synchronization Protocol & Conflict Strategy

1. **Local Outbox Queue:** Mutations write to Dexie/IndexedDB and queue an entry in
   the local `sync_queue` table — both inside one Dexie transaction, so a change
   can never be applied locally without also being scheduled for the server:
   `{ id, table, action, docId, payload, clientTimestamp, status, attempts, lastError }`.
2. **Push Phase (`POST /api/sync/push`):** Uploads local mutations.
3. **Pull Phase (`GET /api/sync/pull?last_synced_at=TIMESTAMP`):** Downloads server updates changed since `last_synced_at`.
4. **Conflict Rules:**
   - **Loan Pool Rule:** Equipment in `ISSUED`, `MAINTENANCE`, `DECOMMISSIONED` or `LOST` cannot be booked at all. Enforced server-side on new loans, because an offline client may hold a stale status.
   - **Status Lock Rule:** Physical loan states (`BORROWED`, `ISSUED`) strictly enforce Server Validation. If dual checkout occurs offline, server flags the transaction as `CONFLICT_PENDING_REVIEW`.
   - **Attribute Updates:** Managed via Last-Write-Wins (LWW) utilizing UTC timestamps.

---

5. **Failure handling:** transient failures (offline, 5xx, unreachable database)
   return outbox entries to `PENDING` for automatic retry; only a server that
   refuses a change on its merits (4xx / `REJECTED`) marks it `FAILED` for a
   human. Orphaned `IN_FLIGHT` entries are released after 60s.

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
- Every local write goes through `web/src/stores/inventory.js` so the document
  and its outbox entry are always created together.
- Writes on the API always go through `App\Services\SyncService`, including the
  REST controllers, so the conflict rules have exactly one implementation.
