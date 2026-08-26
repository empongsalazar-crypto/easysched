# EasySched Defense Build

EasySched is a complete, local-first class scheduling application for New Sinai School and Colleges Sta. Rosa, Inc. This defense build uses PHP 8.2, SQLite, PDO, server-side sessions, and a browser interface that can be hosted by XAMPP without a separate database server.

## What is included

- Conflict-aware automatic scheduling using MRV ordering and backtracking.
- Hard checks for room capacity, room type/features, room overlap, instructor overlap, section overlap, instructor daily hours, declared availability, and required meetings.
- Published schedule runs with diagnostics. A failed generation never replaces the last published run.
- Role-based accounts: `admin`, `scheduler`, `instructor`, and `student`.
- Server-side password hashes, session cookies, CSRF protection, prepared SQL statements, audit logs, security headers, and restrictive CSP.
- Login protection with independent IP/account throttles, exponential backoff, generic failure messages, failed-login audit events, and an adaptive arithmetic challenge after repeated failures.
- Weekly calendar, filters, print view, CSV export, master-data forms, reports, and account password changes.

The default defense mode uses SQLite. An optional one-way PostgreSQL cloud mirror automatically backs up pending local changes while EasySched is open; see [`docs/ONLINE_DEPLOYMENT.md`](docs/ONLINE_DEPLOYMENT.md).

## Run with XAMPP

1. Install XAMPP with Apache and PHP 8.2 or newer. Confirm that `pdo_sqlite` is enabled in `php.ini`.
2. Copy this folder to `C:\xampp\htdocs\EasySched-Defense` (or another Apache document-root folder).
3. Start Apache in the XAMPP Control Panel.
4. Open `http://localhost/EasySched-Defense/`.
5. The first request creates `data/easysched.sqlite` and inserts the demonstration data. The database directory is denied by `data/.htaccess` when Apache honors `.htaccess` files.
6. Sign in with one of the accounts below, then change the password before using real records.

For a quick local-only run without Apache, from this folder use:

```text
C:\xampp\php\php.exe -S 127.0.0.1:8080 -t .
```

Then open `http://127.0.0.1:8080/`. The PHP development server does not apply `.htaccess`, so use it only on the local machine and never expose it to a network.

## Demonstration accounts

| Role | Username | Initial password | Scope |
| --- | --- | --- | --- |
| Administrator | `admin` | `Admin123!` | Academic master data, terms, user management, generation, edits, reports |
| Scheduler | `scheduler` | `Scheduler123!` | Academic master data (excluding users), generation, schedule edits, reports |
| Instructor | `instructor` | `Instructor123!` | Published classes assigned to the instructor |
| Student | `student` | `Student123!` | Published classes for the student's section |

These are seeded demonstration credentials, not production credentials. The current interface supports password changes for the signed-in account. For production, create unique accounts and remove or disable the seed accounts after provisioning.

## Typical defense demonstration

1. Sign in as `admin` and show the dashboard metrics.
2. Open **Academic setup** and explain the normalized resources: programs, sections, subjects, faculty, rooms, and course offerings.
3. Generate the active term. The result shows assigned tasks, search nodes, soft cost, and the hard constraints checked.
4. Open **Weekly schedule**, filter by section/instructor/room, then print or export CSV.
5. Attempt a conflicting manual edit. The server rejects it and leaves the published entry unchanged.
6. Sign in as `instructor` and `student` to demonstrate server-side scope restrictions. Changing an identifier in a request does not broaden either role's schedule view.
7. Open **Reports** to show room utilization and constraint evidence.

## Architecture

`index.php` is the server-rendered shell. `script.js` requests JSON from `api.php` and renders pages without a frontend framework. `db.php` opens SQLite, enables foreign keys/WAL/busy timeout, applies `schema.sql`, and seeds an empty database. `api.php` contains authentication, authorization, validation, CRUD, generation, manual-edit validation, export, and audit logging.

The core data model is:

```text
academic_terms -> sections -> course_offerings <- subjects
programs ------/                         \        instructors
rooms + time_slots -> schedule_entries -> schedule_occupancy
users -> audit_logs; schedule_runs preserve publication history
```

The database schema uses foreign keys, check constraints, unique keys, indexes, and triggers. The occupancy table has a unique key on `(run_id, resource_type, resource_id, day_of_week, slot_id)`, which is the final database-level guard against double booking a room, instructor, or section in a published run.

## Scheduling method

The generator expands each offering into one task per required meeting. It computes every legal room/day/start-slot candidate, rejects candidates that violate hard constraints, and uses minimum-remaining-values ordering to schedule the most constrained task first. Backtracking rolls back occupancy and daily-hour state when a branch fails. Candidate costs prefer capacity fit, declared instructor times, different days for repeated meetings, and avoiding the first/last slot; correctness always takes priority. A node limit prevents runaway searches and returns diagnostics instead of publishing a partial timetable.

Instructor, room, and section availability can be stored in `instructor_availability`, `room_availability`, and `section_availability`; rows marked unavailable are honored by the solver. Instructor time preferences in `instructor_time_preferences` influence candidate order but never override a hard constraint. Gap minimization and balanced daily loads are not implemented yet and should be added only after the institution agrees on weights.

## Security notes

- Passwords are stored only as `password_hash` values using PHP's `password_hash`/`password_verify`.
- Sessions are HTTP-only, SameSite cookies; IDs are regenerated after login.
- Mutating requests require a per-session CSRF token.
- All database values are passed through prepared statements. User-facing errors do not expose SQL details.
- Every protected action checks the session role on the server. UI hiding is not the authorization boundary.
- CSP, frame, MIME-sniffing, referrer, permissions, and no-cache headers are sent by the application.
- CSV export prefixes formula-like values to reduce spreadsheet injection risk.
- Under Apache, `data/.htaccess` blocks direct download of the SQLite file. Also deny the `data` directory at the reverse proxy/web-server layer in production.

HTTPS enforcement and secure-cookie hardening are built in. Follow [`docs/HTTPS_SETUP.md`](docs/HTTPS_SETUP.md) before exposing EasySched beyond localhost. Rotate all seed credentials and keep `data/easysched.sqlite` out of source control.

For local-first cloud backup, enable `pdo_pgsql`, set the server-side `EASYSCHED_CLOUD_DATABASE_URL`, initialize `schema.postgres.sql`, and keep database credentials out of the frontend. Do not also set `EASYSCHED_DATABASE_URL`, because that variable selects cloud-only operation.

## Database and migration

The schema is created idempotently from `schema.sql`; existing rows are not deleted. `CREATE TABLE IF NOT EXISTS` does not alter older table definitions, so a pre-existing server database still needs a reviewed migration. See [`docs/MIGRATION.md`](docs/MIGRATION.md) for a field mapping and staged import strategy for records that currently exist only in the old browser prototype/localStorage. Never import old plaintext passwords. Provision new accounts and set password hashes through the application or a controlled server-side script.

Back up the SQLite file before maintenance:

```text
copy data\easysched.sqlite data\easysched.sqlite.bak
```

For a production backup, stop writes first or use SQLite's online backup tooling. Do not copy a live WAL database while it is being written without a consistent backup procedure.

## Verification

Run the static checks:

```text
C:\xampp\php\php.exe -l api.php
C:\xampp\php\php.exe -l db.php
C:\xampp\php\php.exe -l index.php
node --check script.js
```

Run the HTTP smoke test after copying the folder or from this directory:

```text
powershell -ExecutionPolicy Bypass -File tests\smoke.ps1
```

The smoke test uses a temporary clean copy, starts PHP's local server, and checks authentication, CSRF, RBAC, user/master-data changes, generation, conflict rejection, scoped schedules, CSV export, failed-run preservation, and database occupancy integrity. It removes only its own temporary directory.

## Known boundaries and next production steps

- Time slots and availability tables are supported by the solver but do not yet have dedicated UI editors; configure them through a controlled SQL/admin workflow.
- SQLite is appropriate for a single-campus defense/demo deployment. Move to PostgreSQL/MySQL and add a job queue if many schedulers will generate simultaneously.
- Add automated browser tests (Playwright), centralized monitoring, account recovery, and a formal data-retention policy before production use.

## Defense talking points

Emphasize that the system is a constraint solver, not a random timetable generator: tasks are ordered by constraint tightness, candidates are checked before assignment, occupancy is tracked for all three shared resources, failed branches are undone, and publication is transactional. The normalized schema and unique occupancy key provide a second line of defense. Diagnostics make impossible inputs explainable, while the role checks and scoped queries demonstrate that authorization is enforced by the backend.
