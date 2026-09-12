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
 └─► Vue SPA (camera + GPS)
      │
      │ POST /api/v1/attendance/check-in (JSON)
      │   latitude / longitude / accuracy
      │   face verification context
      ▼
 Laravel 13
 ├─ Sanctum        (authentication)
 ├─ Authorization   (Gate / Policy)
 ├─ Form Request    (validation)
 ├─ Attendance Engine / CheckInEmployee
 │    ├─ employee active
 │    ├─ schedule resolve
 │    ├─ current attendance state
 │    ├─ GPS + accuracy validation
 │    ├─ PostGIS geofence
 │    ├─ FastApiService → FastAPI /face/verify
 │    │    └─ AI facts (verified, confidence, liveness, ...)
 │    ├─ Laravel final decision
 │    └─ Transaction
 │         ├─ Attendance Record
 │         ├─ Attendance Session
 │         ├─ Attendance Event
 │         ├─ AttendanceVerification
 │         └─ Audit
      ▼
 JSON Response
```

Note: Phase 7 attendance actions (CheckInEmployee, CheckOutEmployee, AttendanceEngine)
are planned but not yet implemented in this repository. Face verification endpoints
are currently standalone (`/api/v1/face/enroll`, `/api/v1/face/verify`) and will be
integrated into the check-in/out flow when the attendance actions are implemented.

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

