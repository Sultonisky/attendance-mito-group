# Architecture Decision Records

This document contains decisions that are considered locked unless explicitly changed.

## ADR-001 — Vue 3 SPA

### Decision

The application uses:

```text
Vue 3
TypeScript
Vite
Vue Router
Pinia
PWA
```

as the primary frontend architecture.

### Reason

The application requires:

Rich attendance interaction
Camera integration
GPS interaction
PWA capabilities
Mobile-friendly UX
Centralized client state

### Rejected

Laravel Blade as the primary application UI.

## ADR-002 — Laravel Is the Business Authority

### Decision

Laravel 13 is the authoritative backend.

Laravel owns:

Business rules
Attendance decisions
Leave decisions
Overtime decisions
Penalty calculations
Authorization
Transactions

### Reason

Business rules must exist in one authoritative location.

## ADR-003 — FastAPI Is AI/CV Only

### Decision

FastAPI/Python is used only for:

Face verification
Liveness
Computer vision
AI inference

### Reason

AI/CV workloads have different runtime requirements from ordinary HR business logic.

FastAPI returns facts.

Laravel makes the final business decision.

## ADR-004 — PostgreSQL + PostGIS

### Decision

PostgreSQL is the primary database.

PostGIS is used for geospatial operations.

### Reason

PostgreSQL provides:

Strong transactions
Relational integrity
Indexing
Aggregation
Scalability for expected workload

PostGIS provides native spatial processing.

## ADR-005 — Redis as Infrastructure

### Decision

Redis is used for:

Cache
Queue
Locks
Rate limiting
Temporary state

### Reason

Redis improves performance and concurrency handling without becoming the source of truth.

## ADR-006 — Sanctum

### Decision

Laravel Sanctum is used for first-party Vue SPA authentication.

### Reason

The application is a first-party SPA consuming its Laravel backend.

Custom token authentication is unnecessary unless a future requirement demands it.

## ADR-007 — Server-Side Data Processing

### Decision

Filtering, sorting, pagination, searching, and heavy aggregation happen server-side.

### Reason

Attendance data can grow substantially.

The frontend must not download the entire attendance dataset.

## ADR-008 — No Premature Microservices

### Decision

The system remains primarily:

Vue

- Laravel
- PostgreSQL
- Redis
- FastAPI

No additional microservices are introduced without explicit need.

### Reason

The expected employee count does not justify distributed-system complexity at the initial stage.

## ADR-009 — Backend Authorization Is Authoritative

### Decision

Backend authorization is mandatory even when the frontend hides UI elements.

### Reason

Frontend code can be manipulated.

Security decisions must happen server-side.

## ADR-010 — No Business Logic Duplication in Vue

### Decision

Vue may provide UX validation, but authoritative business calculations remain in Laravel.

Examples:

Leave quota
Attendance status
Late minutes
Overtime approval
Penalty
Geofence acceptance
Face verification acceptance

must be determined by Laravel.

## ADR-011 — PHPUnit Baseline

### Decision

PHPUnit is the baseline test framework while the environment remains:

PHP 8.3
Laravel 13

### Reason

Pest 5 requires PHP 8.4+.

The project must not force an incompatible dependency merely to use Pest.

If PHP is upgraded later, Pest compatibility may be reconsidered.

## ADR-012 — PostGIS Must Be Installed Server-Side

### Decision

PostGIS must be installed into PostgreSQL itself.

Running:

CREATE EXTENSION postgis;

is only valid after the PostGIS extension files exist on the PostgreSQL server.

### Reason

PostGIS is a PostgreSQL server extension, not merely a Laravel dependency.

## ADR-013 — Stateless Laravel Application

### Decision

Laravel application instances should remain stateless where practical.

Persistent state belongs in:

PostgreSQL

Shared temporary/infrastructure state belongs in:

Redis

### Reason

This allows future horizontal scaling.

## ADR-014 — Controllers Must Be Thin

### Decision

Controllers coordinate HTTP concerns.

Business logic belongs in:

Actions
Services
Domain Engines
Rules

### Reason

This improves:

Testability
Reuse
Maintainability
Separation of concerns

## ADR-015 — Monthly Recap Can Be Snapshotted

### Decision

Monthly recap may store summarized snapshots rather than recalculating all historical attendance data every time.

### Reason

Monthly data becomes stable after finalization and can be reused for:

Reporting
Payroll integration
Historical queries

Finalized snapshots must remain auditable.

## ADR-016 — Finalized Recaps Are Locked

### Decision

A finalized monthly recap cannot be silently modified.

### Reason

Payroll and reporting require historical consistency.

Corrections require explicit workflows.

## ADR-017 — AI Does Not Make HR Decisions

### Decision

AI output is evidence/facts, not the final business decision.

Example:

AI:
verified = true
confidence = 0.94
liveness = true

Laravel:
attendance = ACCEPTED

### Reason

Business policy belongs to the application domain, not the AI model.

## ADR-019 — SQLite In-Memory for Default Automated Tests

### Decision

The default `php artisan test` suite uses SQLite in-memory.

Production and local development continue to use PostgreSQL 17 + PostGIS.

PostgreSQL/PostGIS-specific integration tests are isolated and run explicitly
against a dedicated PostgreSQL test database, not the development database.

### Reason

- SQLite in-memory is fast and requires no external PostgreSQL service.
- The default suite is CI-friendly and cannot accidentally mutate development data.
- PostgreSQL-specific behavior (PostGIS, geography, GIST indexes, spatial
  functions) is validated explicitly in an integration suite rather than being
  silently skipped or weakened.

### Consequences

- Default tests remain portable and do not depend on PostgreSQL.
- Production schema is not degraded to accommodate SQLite.
- PostGIS integration tests must be run explicitly when PostgreSQL is available.

## ADR-020 — Phase 5 Core Domain Foundation

### Decision

Phase 5 establishes the reusable foundation for future domain modules without
implementing complete domain engines:

- **Enums** — backed enums for stable domain values already present in the schema.
- **DTOs** — immutable data carriers at application boundaries.
- **Domain Exceptions** — base `DomainException` with specific subclasses for
  business rule violations.
- **Actions** — application use-case classes that own transaction boundaries.
- **Audit Foundation** — `RecordAuditAction` with `AuditRecordData` DTO around
  the existing `audit_logs` schema.

### Reason

- Clear boundaries between controllers, application services, domain logic,
  and persistence.
- Actions provide a consistent place for transaction management.
- DTOs prevent ad-hoc array passing across layers.
- Domain exceptions make business failures explicit and testable.
- Audit is centralized rather than scattered across controllers.

### Consequences

- Future domain engines have a clear place in the architecture.
- Controllers remain thin.
- Transaction boundaries are predictable.
- The default test suite continues to use SQLite :memory:.

## ADR-021 — Phase 6 Policy + Schedule Engine

### Decision

Phase 6 establishes the Policy and Schedule domain engines with the following
characteristics:

- **PolicyEngine** resolves the active policy for an employee on a given date
  using `policy_assignments` effective date ranges.
- **ScheduleEngine** resolves the active work schedule (and its shifts) for an
  employee on a given date using `schedule_assignments` effective date ranges.
- Both engines support historical and future date resolution.
- Overlapping assignments throw domain exceptions (`AmbiguousPolicyAssignmentException`,
  `AmbiguousScheduleAssignmentException`) rather than silently choosing one.
- Inactive policies/schedules throw `InactivePolicyException` /
  `InactiveScheduleException`.
- No new database tables were created. Holiday and weekly off-day resolution
  were not implemented because the current schema does not support them.

### Reason

- Deterministic policy/schedule resolution is a prerequisite for the Attendance
  Engine.
- Effective-date-aware assignments already exist in the schema; the engines
  make the resolution logic explicit and testable.
- Ambiguous resolution must fail safely to prevent silent attendance errors.

### Consequences

- Phase 7 Attendance Engine can consume `PolicyResolutionData` and
  `ScheduleResolutionData`.
- The default test suite continues to use SQLite :memory:.
- Holiday/off-day resolution requires schema additions in a future phase.

## ADR-022 — Phase 7 Attendance Engine

### Decision

Phase 7 establishes the Attendance domain engine with the following
characteristics:

- **AttendanceEngine** coordinates check-in/check-out using Phase 6
  `PolicyEngine` and `ScheduleEngine`.
- **Actions** (`CheckInEmployee`, `CheckOutEmployee`) own the transaction
  boundary.
- **Domain rules** handle GPS validation, geofence, late detection, early
  checkout, and attendance state.
- **Cross-midnight shifts** are resolved by comparing current time against
  shift end time to determine the correct work date.
- **Concurrency** is protected by database unique constraints plus
  application-level open-session checks.
- **PostGIS** is used for geofence validation in PostgreSQL; SQLite tests
  fall back to Haversine distance.
- **Audit** uses the Phase 5 `RecordAuditAction` foundation.
- A `user_id` foreign key was added to `employees` to link authenticated
  users to employee records.

### Reason

- Attendance is the core business operation and must be atomic, auditable,
  and deterministic.
- Policy and schedule context must be resolved per-attendance-date, not
  blindly using latest configuration.
- PostGIS belongs in PostgreSQL, not Python.
- Default tests must remain portable via SQLite :memory:.

### Consequences

- Check-in/check-out are fully implemented with transaction safety.
- PostGIS-specific behavior is tested in `tests/Integration/PostgreSQL/`.
- The default test suite does not require PostgreSQL, PostGIS, or Redis.
- Face AI integration is deferred to Phase 8.
