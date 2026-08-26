# ICT Inventory — API project

Laravel + MySQL backend for the ICT Equipment & Borrowing Management System.
The UI/UX project lives in [`../web`](../web) and is deployed separately.

## Setup

```bash
composer install
```

```bash
mysql -u root -e "CREATE DATABASE IF NOT EXISTS ict_inventory CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
```

For a server, start from the production template instead:

```bash
cp .env.production.example .env
```

Configure `DB_*` in `.env`, then:

```bash
php artisan migrate --seed
```

```bash
php artisan serve --port=8000
```

### CORS

The Vue client runs on a different origin, so `config/cors.php` reads allowed
origins from `FRONTEND_URLS` (comma separated, defaults to the Vite dev server):

```
FRONTEND_URLS=http://localhost:5173,https://inventory.example.org
```

## Endpoints

All routes except `/api/health` and the two OTP steps require a
`Authorization: Bearer <token>` header.

| Method | Path | Role | Purpose |
| --- | --- | --- | --- |
| `GET` | `/api/health` | public | Liveness probe |
| `POST` | `/api/auth/request-code` | public | Email a 6-digit sign-in code |
| `POST` | `/api/auth/verify-code` | public | Exchange the code for a bearer token |
| `GET` | `/api/auth/me` | any | Current user |
| `PUT` | `/api/auth/profile` | any | Update own display name |
| `POST` | `/api/auth/logout` | any | Revoke the current token |
| `POST` | `/api/auth/logout-all` | any | Revoke every token |
| `GET` | `/api/users` | admin | List accounts |
| `POST` | `/api/users` | admin | Provision an account |
| `PUT` | `/api/users/{user}` | admin | Update name, email, role, active state |
| `DELETE` | `/api/users/{user}` | admin | Delete an account |

| Method | Path | Purpose |
| --- | --- | --- |
| `POST` | `/api/sync/push` | Upload a client's queued offline mutations (admin/manager) |
| `GET` | `/api/sync/pull?last_synced_at=ISO` | Download everything changed since a checkpoint (omit for a full bootstrap) |
| `GET` | `/api/categories` | List categories |
| `GET` | `/api/equipment` | List equipment (`status`, `category_id`, `search` filters) |
| `GET` | `/api/borrowings` | List transactions (`status`, `active` filters) |
| `GET` | `/api/stats` | Dashboard counters |
| `POST` | `/api/{table}` | Create (`categories` \| `equipment` \| `borrowings`) |
| `PUT` | `/api/{table}/{id}` | Update |
| `DELETE` | `/api/{table}/{id}` | Soft delete |

### Push verdicts

Each change in a push receives its own verdict, so one bad record never fails
the batch:

| Status | Meaning |
| --- | --- |
| `APPLIED` | Written to MySQL |
| `IGNORED_STALE` | Older than the stored row (Last-Write-Wins); server state echoed back |
| `CONFLICT` | Overlapping active loan detected; the losing transaction is `CONFLICT_PENDING_REVIEW` |
| `REJECTED` | Refused on its merits (e.g. duplicate asset tag). The client stops retrying and asks a human |

A database outage is **not** reported as `REJECTED` — it raises a 500 so the
client retries the whole batch later. See `isInfrastructureFailure()` in
`app/Services/SyncService.php`.

## Authentication

Passwordless: `request-code` emails a 6-digit code, `verify-code` exchanges it
for a Sanctum token. Only the code's **hash** is stored, codes are single-use
and expire after 10 minutes, 5 wrong guesses burns the code, and both endpoints
are rate limited per address and per IP. `request-code` answers identically for
unknown addresses so it cannot enumerate staff emails.

Accounts are admin-provisioned — there is no public sign-up. Deactivating an
account revokes its tokens immediately, and `EnsureRole` re-checks `is_active`
on every request.

Only `@dswd.gov.ph` addresses are accepted (`App\Rules\AllowedEmailDomain`,
configured in `config/accounts.php` via `ACCOUNT_EMAIL_DOMAINS`). The check runs
on account creation, on email changes, **and** inside `OtpService::issue()`, so
a row inserted directly into the database still cannot receive a code. Set the
variable empty to allow any domain.

Roles: `ADMIN` (everything + accounts) and `MANAGER` (inventory writes). There is
no borrower role — borrowers are named on the loan and never sign in.

### Sign-in code sender

Codes are sent from `icts.fo11@dswd.gov.ph` ("DSWD FO11 ICT Section"), pinned in
`config/mail.php` under `otp_from` and overridable per environment:

```
MAIL_OTP_FROM_ADDRESS=icts.fo11@dswd.gov.ph
MAIL_OTP_FROM_NAME="DSWD FO11 ICT Section"
```

It is set separately from the global `MAIL_FROM_ADDRESS` because this is the one
address staff are told to trust — it must not drift when other mail is added.

> **Delivery needs more than a From header.** Setting the sender does not by
> itself let you send as `@dswd.gov.ph`. Point `MAIL_*` at an SMTP relay that is
> authorised for that domain and make sure SPF (and ideally DKIM/DMARC) cover
> it, or receiving servers will reject the mail or file it as spam. Until then
> `MAIL_MAILER=log` keeps codes in `storage/logs/laravel.log`.

## Layout

```
app/Domain/            Pure business rules (loan maths, status machine, roles)
app/Models/            Eloquent models + toSyncArray() wire format
app/Services/          SyncService (conflict engine), OtpService (sign-in codes)
app/Mail/              LoginCodeMail
app/Http/Middleware/   EnsureRole — role + active-account guard
app/Http/Controllers/  Thin controllers delegating to the services
database/migrations/   Central MySQL schema
tests/                 Feature + unit coverage
```

REST writes deliberately funnel through `SyncService` rather than writing via
Eloquent directly, so the Status Lock Rule and Last-Write-Wins have exactly one
implementation.

## Tests

```bash
php artisan test
```

Tests run against a real MySQL database (`ict_inventory_test`, configured in
`phpunit.xml`) rather than SQLite, because the sync engine depends on MySQL's
unique-constraint behaviour and `DATETIME(3)` precision.

```bash
mysql -u root -e "CREATE DATABASE IF NOT EXISTS ict_inventory_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
```
