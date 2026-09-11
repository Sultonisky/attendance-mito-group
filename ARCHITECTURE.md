# Attendance MITO Group Architecture

## 1. System Architecture

```text
                    ┌──────────────────────┐
                    │      Vue 3 SPA       │
                    │ TypeScript + Vite    │
                    │ PWA + Pinia + Router │
                    └──────────┬───────────┘
                               │
                         REST / JSON
                               │
                               ▼
                    ┌──────────────────────┐
                    │     Laravel 13       │
                    │        API           │
                    │                      │
                    │ Auth / RBAC          │
                    │ Controllers          │
                    │ Actions              │
                    │ Domain Engines       │
                    │ Validation           │
                    │ Transactions         │
                    │ Audit                │
                    └──────┬───────┬───────┘
                           │       │
                  ┌────────┘       └────────┐
                  ▼                         ▼
        ┌─────────────────┐       ┌─────────────────┐
        │ PostgreSQL      │       │    FastAPI      │
        │ + PostGIS       │       │    Python       │
        │                 │       │                 │
        │ System of       │       │ Face / CV       │
        │ Record          │       │ Liveness        │
        └────────┬────────┘       └─────────────────┘
                 │
                 ▼
        ┌─────────────────┐
        │      Redis      │
        │                 │
        │ Cache           │
        │ Queue           │
        │ Lock            │
        │ Rate Limit      │
        └─────────────────┘
```

## 2. Responsibility Boundaries
Vue

Responsible for:

Presentation
Interaction
Client state
API communication
PWA
UX validation

Not responsible for business authority.

Laravel

Responsible for:

Authentication
Authorization
Business rules
Domain engines
Transactions
API
Audit
Integration orchestration

Laravel is authoritative.

PostgreSQL

Responsible for:

Persistent application state
Relationships
Constraints
Transactions
Aggregations
Geospatial calculations

Automated tests:
SQLite in-memory for fast, portable application tests.

Production/runtime:
PostgreSQL + PostGIS for authoritative persistence.

Database-specific integration:
PostgreSQL + PostGIS for geography, GIST, and PostGIS function validation.
Redis

Responsible for:

Cache
Queue
Locks
Rate limiting
Temporary state
FastAPI

Responsible for:

AI inference
Computer vision
Face verification
Liveness
## 3. Backend Structure

Recommended:

```text
app/
├── Actions/
├── Console/
│   └── Commands/
├── Domain/
│   ├── Attendance/
│   │   ├── Engines/
│   │   ├── Rules/
│   │   ├── DTOs/
│   │   └── Exceptions/
│   ├── Leave/
│   ├── Overtime/
│   ├── Penalty/
│   └── Policy/
├── Enums/
├── Events/
├── Exceptions/
├── Http/
│   ├── Controllers/
│   ├── Middleware/
│   ├── Requests/
│   └── Resources/
├── Jobs/
├── Listeners/
├── Models/
├── Notifications/
├── Observers/
├── Policies/
├── Providers/
├── Services/
└── Traits/
```

## 4. Frontend Structure

```text
src/
├── app/
├── router/
├── layouts/
├── pages/
├── components/
├── features/
│   ├── auth/
│   ├── attendance/
│   ├── employees/
│   ├── leave/
│   ├── overtime/
│   ├── penalty/
│   └── reports/
├── stores/
├── services/
├── composables/
└── types/
```

Feature-specific functionality should stay within its feature where practical.

## 5. API Request Flow

```text
Vue
 ↓
HTTP Request
 ↓
Laravel Middleware
 ↓
Authentication
 ↓
Permission
 ↓
Form Request
 ↓
Controller
 ↓
Action
 ↓
Domain Engine
 ↓
Rule
 ↓
Database Transaction
 ↓
Resource
 ↓
JSON
 ↓
Vue
```

## 6. Attendance Check-In Flow

```text
Employee
 ↓
Vue
 ↓
POST /api/v1/attendance/check-in
 ↓
Sanctum
 ↓
Authentication
 ↓
Active Employee Check
 ↓
Schedule Engine
 ↓
Current Attendance State
 ↓
GPS Validation
 ↓
PostGIS Geofence
 ↓
FastAPI Face Verification
 ↓
Laravel Attendance Engine
 ↓
Transaction
 ├── Attendance Record
 ├── Attendance Session
 ├── Attendance Event
 ├── Verification
 └── Audit
 ↓
JSON Response
```

## 7. AI Integration

Laravel communicates with FastAPI.

Example:

```text
Laravel
   │
   │ face verification request
   ▼
FastAPI
   │
   ├── Face Detection
   ├── Face Matching
   └── Liveness
   │
   ▼
AI Result
   │
   ▼
Laravel
   │
   └── Final Business Decision
```

FastAPI must not directly update attendance records.

## 8. Geospatial Architecture

Geospatial processing:

```text
GPS
 ↓
Laravel
 ↓
PostGIS
 ↓
Distance / Polygon Validation
 ↓
Laravel
```

Do not send ordinary geofence calculations to Python.

## 9. Domain Relationships

```text
Employee
   │
   ├── Policy Assignment
   │
   ├── Schedule
   │
   ├── Attendance
   │      ├── Sessions
   │      ├── Events
   │      └── Verifications
   │
   ├── Leave
   │      ├── Requests
   │      ├── Balances
   │      └── Transactions
   │
   ├── Overtime
   │
   └── Penalty
```

Monthly recap aggregates employee-level records.

## 10. Attendance Session Model

A daily attendance record represents the employee's attendance state for a date.

Sessions represent actual IN/OUT intervals.

Example:

```text
Attendance: 2026-09-08

Session 1
08:01 IN
12:00 OUT

Session 2
13:01 IN
17:05 OUT
```

Events preserve the chronological event history.

## 11. Transaction Boundaries

Critical state changes must be transactional.

Examples:

- Check-in
- Check-out
- Leave approval
- Leave cancellation
- Leave balance transaction
- Overtime approval
- Penalty creation
- Monthly recap finalization
## 12. Concurrency

Attendance operations must protect against:

- Double click
- Duplicate requests
- Network retry
- Parallel requests
- Multiple browser tabs
- Mobile retry

Use appropriate:

- Database constraints
- Transactions
- Idempotency
- Redis locks where required

Do not rely only on frontend button disabling.

## 13. Scaling

Initial target:

500–1,000 employees

The system should remain stateless at the Laravel application layer.

Persistent state belongs in PostgreSQL.

Temporary/shared state belongs in Redis.

This allows future horizontal scaling without redesigning the business domain.

## 14. Queue Architecture

Use Redis-backed queues for:

Heavy reports
Monthly recap generation
Notifications
Large imports
AI-related asynchronous tasks where applicable
Background maintenance

Do not put long-running work inside ordinary HTTP requests when unnecessary.

## 15. Caching

Good cache candidates:

Static configuration
Policies
Work locations
Permission metadata
Dashboard aggregates where appropriate

Do not cache authoritative mutable attendance state indefinitely.

Cache invalidation must be deliberate.

## 16. Authorization Architecture

```text
Request
 ↓
Sanctum
 ↓
Permission Middleware
 ↓
Gate / Policy
 ↓
Action
 ↓
Business Rule
```

The frontend may hide inaccessible menu items.

The backend must still reject unauthorized requests.

## 17. Error Boundaries

Expected application errors should be converted into consistent API responses.

Examples:

- 401 Unauthorized
- 403 Forbidden
- 404 Not Found
- 409 Conflict
- 422 Validation / Business Validation
- 429 Too Many Requests

Do not expose stack traces in production responses.

## 18. Architecture Principle

The most important rule:

UI displays decisions.
Laravel makes decisions.
PostgreSQL stores decisions.
FastAPI provides AI facts.
Redis accelerates infrastructure.

## 19. Phase 5 — Core Domain Foundation

### Action / Application Service Convention

```text
Controller
    ↓
Action
    ↓
Domain Engine / Rule
    ↓
Repository / Model
```

Controllers must remain thin. Actions represent meaningful use cases and own
transaction boundaries when an operation modifies multiple pieces of domain
state.

### DTO Convention

DTOs are immutable data carriers at domain/application boundaries:

```php
final readonly class AttendanceCheckInData
{
    public function __construct(
        public int $employeeId,
        public int $workLocationId,
        public float $latitude,
        public float $longitude,
        public ?float $accuracy,
        public ?string $deviceIdentifier,
    ) {}
}
```

DTOs must not contain persistence logic, HTTP request objects, or Vue concerns.

### Domain Exception Convention

Domain exceptions represent business operations that cannot legally proceed.
They are distinct from programming errors, framework errors, and infrastructure
failures.

Base: `App\Exceptions\Domain\DomainException`
Examples: `InvalidStateException`, `InactiveEmployeeException`

### Audit Foundation

Audit entries are recorded through `App\Actions\Audit\RecordAuditAction`
using the existing `audit_logs` schema. The action owns the transaction
boundary and accepts an `AuditRecordData` DTO.

### Transaction Boundary

Application Actions own transaction boundaries. Use `DB::transaction()`
inside Actions, not in controllers, routes, or models.

### Time / Date Handling

Use Laravel's supported date/time tooling consistently. Future tests must
be able to freeze/control time via `Carbon::setTestNow()`.

## 20. Phase 6 — Policy + Schedule Engine

### Policy Domain

```text
Employee
   │
   ▼
PolicyAssignment
   │
   ▼
PolicyEngine
   │
   ▼
Policy (active/inactive/draft/expired)
```

The Policy Engine answers: "What policy applies to employee X on date Y?"

Resolution rules:
- Find assignments where `effective_from <= date` and (`effective_to` is null or `effective_to >= date`).
- Zero matches → explicit no-policy result (`PolicyResolutionData` with `hasPolicy() === false`).
- Multiple overlapping matches → `AmbiguousPolicyAssignmentException`.
- Single match with inactive policy → `InactivePolicyException`.
- Single match with active policy → `PolicyResolutionData` with the policy.

### Schedule Domain

```text
Employee
   │
   ▼
ScheduleAssignment
   │
   ▼
ScheduleEngine
   │
   ▼
WorkSchedule (with Shifts)
```

The Schedule Engine answers: "What schedule/shift applies to employee X on date Y?"

Resolution rules:
- Find assignments where `effective_from <= date` and (`effective_to` is null or `effective_to >= date`).
- Zero matches → explicit no-schedule result (`ScheduleResolutionData` with `hasSchedule() === false`).
- Multiple overlapping matches → `AmbiguousScheduleAssignmentException`.
- Single match with inactive schedule → `InactiveScheduleException`.
- Single match with active schedule → `ScheduleResolutionData` with the schedule and its shifts.

### Effective Date Handling

Both engines support historical and future resolution using explicit `CarbonImmutable` date inputs. The engines query `effective_from` / `effective_to` directly; they do not use `created_at` or `latest()` for resolution.

### Conflict Handling

Overlapping assignments are treated as ambiguous. The engines throw domain exceptions rather than silently choosing one assignment based on `id`, `created_at`, or arbitrary precedence.

### Shift Resolution

The current schema supports `cross_midnight` shifts. The Schedule Engine preserves shift data as stored; it does not normalize or recalculate shift times. Attendance Engine (Phase 7) will consume shift data.

### Holiday / Off-Day Note

The current database schema does not contain a holidays table or day-of-week pattern. Holiday and weekly off-day resolution are not implemented in Phase 6. The Schedule Engine returns what the existing schema supports: schedule presence or absence.

## 21. Phase 7 — Attendance Engine

### Attendance Domain Structure

```text
app/Domain/Attendance/
├── DTOs/
├── Engines/
├── Exceptions/
└── Rules/
```

### Attendance Engine

The `AttendanceEngine` is the domain coordinator. It orchestrates:
- Employee validation
- Policy resolution via `PolicyEngine`
- Schedule resolution via `ScheduleEngine`
- GPS validation via `GpsValidationRule`
- Geofence validation via `GeofenceRule`
- Attendance state determination via `AttendanceStateRule`
- Late detection via `LateDetectionRule`
- Early checkout detection via `EarlyCheckoutRule`

The engine does not contain business logic itself; it delegates to rules and engines.

### Check-in Flow

1. Validate employee is active (not ended)
2. Resolve policy for attendance date
3. Resolve schedule for attendance date
4. Determine work date (cross-midnight aware)
5. Validate GPS coordinates
6. Validate geofence
7. Check for existing open session
8. Create/update attendance record
9. Create attendance session
10. Create attendance event (IN)
11. Create verification record
12. Determine attendance status
13. Return result

### Check-out Flow

1. Validate employee is active
2. Find open attendance session
3. Validate GPS coordinates
4. Validate geofence
5. Close session with duration
6. Create attendance event (OUT)
7. Create verification record
8. Determine attendance status
9. Return result

### Cross-Midnight Handling

The engine determines the "work date" based on the resolved shift:
- If shift is `cross_midnight` and current time < shift end time, the work date is the previous calendar day.
- Otherwise, the work date is the current calendar day.

### Transaction Boundary

Check-in and check-out operations are wrapped in `DB::transaction()` by their respective Actions. All state changes (record, session, event, verification) are atomic.

### Concurrency / Idempotency

- Database unique constraint on `attendance_records(employee_id, attendance_date)` prevents duplicate records.
- Open session check prevents duplicate check-ins.
- `UniqueConstraintViolationException` is caught and converted to `AttendanceAlreadyCheckedInException`.

### PostGIS Geofence

Geofence validation uses PostGIS `ST_DWithin` for PostgreSQL and falls back to Haversine distance for SQLite. Integration tests run against `attendance_mito_test` with real PostGIS.

### API Endpoints

- `POST /api/v1/attendance/check-in`
- `POST /api/v1/attendance/check-out`

Both require `auth:sanctum` and return JSON per `API-CONTRACT.md`.

