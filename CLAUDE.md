# CLAUDE.md - System Specification & Build Instructions

## Project Overview
This repository contains the **ICT Equipment & Borrowing Management System**, a local-first, offline-capable hybrid web application designed for tracking ICT hardware, managing categorical taxonomies, and handling borrowing/return workflows with automated bidirectional background synchronization.

---

## Architecture Strategy: Local-First Hybrid Model

```
+-----------------------------------------------------------------------+
|                            Client Application                         |
|  +---------------------------+       +-----------------------------+  |
|  |     UI Layer (React/Next) | <---> | Local Database (RxDB/IndexedDB)|
|  +---------------------------+       +--------------+--------------+  |
+-----------------------------------------------------|-----------------+
                                                      |
                                          Background Sync Engine
                                          (Web Worker / WebSocket)
                                                      |
                                                      v
+-----------------------------------------------------------------------+
|                            Backend Server                             |
|  +---------------------------+       +-----------------------------+  |
|  |     API Engine (Node/Go)  | <---> | Central Database (MySQL)     | |
|  +---------------------------+       +-----------------------------+  |
+-----------------------------------------------------------------------+
```

### Core Stack
- **Frontend Framework:** Next.js 14+ (App Router, TypeScript, Tailwind CSS)
- **Local Client Store:** RxDB / IndexedDB (for zero-latency offline read/write operations)
- **Backend API:** Node.js (Express / Fastify) or Next.js Route Handlers
- **Central Storage:** MySQL 8
- **Synchronization Engine:** Custom Outbox Queue with HTTP Pull/Push REST + WebSockets

---

## User Stories & Functional Requirements

### Epic 1: Category & Attribute Management
- **US-1.1:** As an Admin, create, update, and soft-delete equipment categories (e.g., Laptops, Networking, Peripherals).
- **US-1.2:** As an Admin, define dynamic custom metadata fields (JSON schema) per category (e.g., RAM/Processor for Laptops, MAC Address for Routers).
- **US-1.3:** As an Admin, set default loan durations per category to enforce auto-calculated return dates.

### Epic 2: ICT Equipment Inventory Management
- **US-2.1:** As an Inventory Manager, register new equipment with key attributes (Serial Number, Asset Tag, Category, Status, Location, Condition, Custom Specs).
- **US-2.2:** As an Inventory Manager, generate, print, and scan QR codes/barcodes for fast device audits and lookups.
- **US-2.3:** As an Inventory Manager, manage equipment status state transitions: `AVAILABLE`, `BORROWED`, `MAINTENANCE`, `DECOMMISSIONED`, `LOST`.
- **US-2.4:** As an Inventory Manager, filter, search, and export equipment lists offline.

### Epic 3: Borrowing & Return Management
- **US-3.1:** As a Borrower/Staff, request device loans specifying required dates and intended usage.
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

The authoritative definition lives in `prisma/schema.prisma`; the generated DDL
is `prisma/migrations/0_init/migration.sql`.

```sql
-- Categories Table
CREATE TABLE categories (
    id CHAR(36) PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    custom_fields JSON NOT NULL,
    default_loan_days INT NOT NULL DEFAULT 7,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    deleted_at DATETIME(3),
    INDEX categories_updated_at_idx (updated_at)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Equipment Table
CREATE TABLE equipment (
    id CHAR(36) PRIMARY KEY,
    asset_tag VARCHAR(100) NOT NULL UNIQUE,
    serial_number VARCHAR(100) UNIQUE,
    category_id CHAR(36),
    model_name VARCHAR(255) NOT NULL,
    brand VARCHAR(100),
    status VARCHAR(50) NOT NULL DEFAULT 'AVAILABLE',
    `condition` VARCHAR(50) NOT NULL DEFAULT 'GOOD',
    location VARCHAR(255),
    specifications JSON NOT NULL,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    deleted_at DATETIME(3),
    INDEX equipment_category_id_idx (category_id),
    INDEX equipment_status_idx (status),
    INDEX equipment_updated_at_idx (updated_at),
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

1. **Local Outbox Queue:** Mutations write to RxDB and queue an entry in local `sync_queue`:
   `{ id, table, action, payload, client_timestamp }`.
2. **Push Phase (`POST /api/sync/push`):** Uploads local mutations.
3. **Pull Phase (`GET /api/sync/pull?last_synced_at=TIMESTAMP`):** Downloads server updates changed since `last_synced_at`.
4. **Conflict Rules:**
   - **Status Lock Rule:** Physical loan states (`BORROWED`, `ISSUED`) strictly enforce Server Validation. If dual checkout occurs offline, server flags the transaction as `CONFLICT_PENDING_REVIEW`.
   - **Attribute Updates:** Managed via Last-Write-Wins (LWW) utilizing UTC timestamps.

---

## Claude Code Step-by-Step Implementation Prompts

### Phase 1: Foundation Setup
`claude "Initialize Next.js App Router project with TypeScript, Tailwind CSS, and lucide-react. Set up Prisma / PostgreSQL ORM schemas for Categories, Equipment, BorrowingTransactions, and SyncLogs."`

### Phase 2: Client Offline Database Engine
`claude "Implement RxDB client database setup with IndexedDB adapter. Create schemas matching Equipment, Categories, and Borrowing. Implement a local Sync Queue module using Outbox pattern."`

### Phase 3: Dynamic Category & Equipment UI
`claude "Build CRUD pages for Categories with dynamic JSON custom fields builder. Create Equipment management page with table search, offline filtering, and QR code scanner component."`

### Phase 4: Borrowing Management Workflow
`claude "Build Borrowing/Return checkout workflow UI. Include quick scan checkout, duration calculations based on category defaults, return inspection form, and status badge indicators."`

### Phase 5: Sync API & Conflict Engine
`claude "Build /api/sync/push and /api/sync/pull endpoints. Implement conflict detection for overlapping borrowings and Last-Write-Wins for equipment metadata. Connect client sync engine to auto-trigger on network connection restoration."`
