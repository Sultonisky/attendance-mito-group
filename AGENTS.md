# Project

Attendance MITO Group is a web-based employee attendance and HR attendance management system.

The system is designed for approximately 500–1,000 employees and must prioritize:

- Correct business rules
- Security
- Auditability
- Performance
- Maintainability
- Clear separation of responsibilities
- API-first architecture
- AI/Vibe Coding compatibility

---

## 1. LOCKED ARCHITECTURE

The following architecture is mandatory unless an explicit architectural decision changes it.

```text
Vue 3 SPA + TypeScript + Vite + PWA
            │
            │ REST / JSON
            ▼
      Laravel 13 API
      ├── Authentication
      ├── Authorization / RBAC
      ├── API
      ├── Business Logic
      ├── Domain Engines
      ├── Validation
      ├── Transactions
      └── Audit
            │
      ┌─────┴─────┐
      ▼           ▼
PostgreSQL     FastAPI
+ PostGIS      Python
      │           │
      │        Face / CV
      │        Liveness
      │        Verification
      │
      └──── Redis
         Cache / Queue /
         Lock / Rate Limit /
         Temporary State
```

## Component Authority

### Vue

Responsible for:

UI
UX
Client-side state
Form interaction
Client-side validation for usability
API consumption
PWA functionality

Vue is NOT the authority for business rules.

Never rely on Vue to enforce:

Attendance validity
Leave eligibility
Permission rules
Overtime approval rules
Penalty calculation
Payroll recap rules
Authorization

### Laravel

Laravel is the primary backend and business authority.

Laravel is responsible for:

Authentication
Authorization
RBAC
Validation
Business rules
Domain engines
Transactions
Attendance decisions
Leave decisions
Overtime decisions
Penalty decisions
Monthly recap
Audit logging
API responses
Integration orchestration

Laravel is the final decision maker.

### PostgreSQL

PostgreSQL is the primary system of record.

Use PostgreSQL for:

Employees
Attendance
Leave
Overtime
Penalties
Policies
Schedules
Monthly recaps
Audit logs
Application data

PostGIS is used for geospatial operations.

### Redis

Redis is infrastructure, not a source of truth.

Use Redis for:

Cache
Queue
Distributed locks
Rate limiting
Temporary state

Do not store authoritative attendance or HR records only in Redis.

### FastAPI / Python

FastAPI exists only for specialized AI/CV workloads.

Allowed responsibilities:

Face detection
Face verification
Face matching
Liveness detection
Computer vision preprocessing
AI model inference

FastAPI must return facts/results.

Example:

```json
{
  "verified": true,
  "confidence": 0.94,
  "liveness": true,
  "model_version": "face-v3"
}
```

Laravel decides what that result means for the business process.

Python must NOT become the main attendance backend.

## 2. NON-NEGOTIABLE ARCHITECTURE RULES

Do not introduce:

Laravel Blade as the primary application UI
A second frontend architecture
Vue + Blade hybrid application architecture
Inertia unless explicitly approved
A second backend for ordinary business logic
Microservices without explicit architectural approval
Business rules inside Vue
Business rules inside routes
Business rules inside migrations
Business rules inside controllers
Direct database access from Vue
Direct database access from FastAPI
AI decisions as final business decisions

## 3. BACKEND FLOW

Preferred backend structure:

```text
Request
   ↓
Form Request / Validation
   ↓
Controller
   ↓
Action / Application Service
   ↓
Domain Engine
   ↓
Domain Rules
   ↓
Transaction
   ↓
Resource / JSON Response
```

Controllers must remain thin.

Avoid:

```php
public function store(Request $request)
{
    // 200 lines of business logic
}
```

Prefer:

```php
public function store(StoreAttendanceRequest $request)
{
    $result = $this->checkInAction->execute(
        $request->user(),
        $request->validated()
    );

    return new AttendanceResource($result);
}
```

## 4. DOMAIN ENGINES

Core engines:

Policy Engine
Schedule Engine
Attendance Engine
Leave Engine
Overtime Engine
Penalty Engine
Monthly Recap Engine

Relationship:

```text
Employee
   ↓
Policy
   ↓
Schedule
   ↓
Attendance
   ├── Overtime
   └── Penalty
         ↓
   Monthly Recap
```

Overtime and Penalty are siblings.

They both consume attendance/business facts.

They are not sequential dependencies.

## 5. AUTHENTICATION

Use Laravel Sanctum for the first-party Vue SPA.

Preferred model:

```text
Vue SPA
   ↓
Laravel Sanctum
   ↓
Authenticated Laravel session
```

Do not create custom authentication unless required.

## 6. AUTHORIZATION

Roles:

SUPER_ADMIN
ADMIN
USER

Authorization must be enforced server-side.

Recommended flow:

```text
Route
  ↓
Permission Middleware
  ↓
Gate / Policy
  ↓
Action / Service
  ↓
Business Rule
```

Frontend permission checks are only for UX.

Never assume hidden UI means unauthorized access.

## 7. ATTENDANCE

Check-in:

```text
Authenticate
    ↓
Active Employee
    ↓
Schedule
    ↓
Current Attendance State
    ↓
GPS + Accuracy
    ↓
PostGIS Geofence
    ↓
Face Verification
    ↓
Attendance Engine
    ↓
Transaction
    ↓
Audit
```

Check-out follows the same principle.

Multiple IN/OUT sessions must be supported.

Missing OUT is:

Incomplete / Open Session

It is not automatically equivalent to absence.

## 8. ATTENDANCE STATUS PRECEDENCE

Daily status precedence:

```text
Holiday
    ↓
Off Day
    ↓
Approved Leave
    ↓
Approved Business Trip
    ↓
Scheduled Work
    ↓
No Attendance = Absent
    ↓
Open IN = Incomplete
    ↓
Valid Attendance = Present
```

Do not change precedence without an explicit business decision.

## 9. LEAVE RULES

Annual leave:

Eligible after 6 months.
Example:
Join date: 1 January 2026
Eligible: 1 July 2026
After eligibility, quota accrues +1 on the employee's join-date month/day cycle.
Leave quota expires after 12 months.
Quota consumption must be traceable.
FIFO consumption should be used where applicable.

Special leave:

Bereavement
Childbirth
Sickness
Other configured special leave

Special leave does not deduct annual leave unless explicitly configured otherwise.

Leave balances must be represented by transactions/history.

Do not silently overwrite historical balances.

## 10. OVERTIME

Overtime processing:

```text
Attendance
   ↓
Detection
   ↓
Qualification
   ↓
Rounding
   ↓
Limit
   ↓
Request
   ↓
Approval
   ↓
Approved Overtime
```

Keep these concepts separate:

Potential overtime
Requested overtime
Approved overtime
Actual overtime

Do not assume all overtime detected by the system is automatically approved.

## 11. PENALTY

Penalty flow:

```text
Attendance / Violation
        ↓
Penalty Rule
        ↓
Frequency / Threshold
        ↓
Points / Adjustment
        ↓
Penalty Record
```

Preserve:

Original violation
Applied rule
Original penalty
Adjustment
Final penalty

Historical records must remain auditable.

## 12. MONTHLY RECAP

Monthly recap aggregates:

Attendance
Leave
Permission
Overtime
Penalty

Recommended lifecycle:

```text
DRAFT
  ↓
REVIEW
  ↓
FINALIZED
  ↓
EXPORTED
```

FINALIZED data is business-locked.

Any correction after finalization must use an explicit correction/reopening process.

## 13. AI / FACE VERIFICATION

AI service returns facts.

Example:

```json
{
  "verified": true,
  "confidence": 0.94,
  "liveness": true,
  "model_version": "face-v3"
}
```

Laravel evaluates:

Employee identity
Attendance context
Geofence
Schedule
Policy
Confidence threshold
Liveness requirement
Business rules

Never place final attendance authorization solely in Python.

Do not store raw biometric data unnecessarily.

Never log:

Face embeddings
Raw biometric payloads
Authentication credentials
Access tokens

## 14. GEOLOCATION

Normal geofence calculations belong to PostgreSQL/PostGIS.

Do not use Python for ordinary radius/polygon calculations.

Use PostGIS for:

Point-in-polygon
Distance calculations
Spatial filtering
Work location validation

GPS accuracy must be considered.

Never treat a GPS coordinate as perfectly accurate.

## 15. DATABASE

PostgreSQL is the source of truth.

Important indexes should exist for high-volume queries.

Examples:

```text
(employee_id, attendance_date)
(employee_id, created_at)
(employee_id, status)
(attendance_id, event_type)
```

Spatial columns should have appropriate GIST indexes.

Do not fetch large attendance history without pagination.

## 16. PERFORMANCE

Target:

500–1,000 employees

Potential attendance volume:

```text
1,000 employees
× 2 attendance events/day
≈ 730,000 events/year
```

Design for:

Indexes
Pagination
Efficient aggregation
Queue-based heavy processing
Redis caching
Database-level filtering
Monthly snapshots

Never download all attendance history to the browser.

## 17. API RULES

API base:

```text
/api/v1
```

Use:

REST
JSON
Consistent status codes
Consistent validation errors
Pagination
Server-side filtering
Server-side sorting
Server-side searching

Do not expose internal implementation details in API responses.

## 18. SECURITY

Never trust:

Client-side validation
Vue permissions
User-provided employee IDs
User-provided approval status
User-provided calculated totals
User-provided attendance status
User-provided leave balances

All critical values must be recalculated or verified server-side.

Never commit:

.env
credentials
private keys
service account keys
AI secrets
production passwords

## 19. TESTING

Minimum expectation:

Unit tests for domain rules
Feature tests for API endpoints
Authorization tests
Attendance business-rule tests
Leave tests
Overtime tests
Penalty tests
Monthly recap tests
AI integration tests
Critical database behavior tests

Current environment:

PHP 8.3
Laravel 13

Do not force Pest 5 into this environment because Pest 5 requires PHP 8.4+.

PHPUnit is the baseline test runner unless the PHP environment is upgraded and compatibility is explicitly verified.

## 20. DEPENDENCY RULE

Do not install packages simply because they may be useful later.

Before adding a dependency:

Verify necessity.
Verify Laravel 13 compatibility.
Verify PHP compatibility.
Check maintenance status.
Check whether native Laravel functionality is sufficient.
Check whether the dependency duplicates existing functionality.

Never use:

--ignore-platform-reqs

to bypass architectural dependency problems.

## 21. DEBUGGING PROTOCOL

When fixing a bug:

Reproduce the problem.
Identify the actual layer.
Read relevant code.
Trace request/data flow.
Identify root cause.
Make the smallest correct change.
Run relevant tests.
Run formatting/static checks when appropriate.
Verify no unrelated behavior changed.

Do not randomly rewrite large sections of the application.

## 22. VIBE CODING RULES

AI agents must:

Read existing architecture before coding.
Read relevant documentation.
Search existing implementations before creating new ones.
Reuse existing services/actions/components where appropriate.
Avoid duplicate business logic.
Avoid unnecessary dependencies.
Avoid changing database schema without necessity.
Avoid changing public API contracts without approval.
Preserve existing behavior unless the task explicitly changes it.
Keep changes focused.
Test after modifications.

If an architectural decision is unclear, stop and report the conflict instead of silently inventing a new architecture.

## 23. FINAL AGENT REPORT

After completing a task, report:

Summary:

- What was changed.

Files:

- Files created.
- Files modified.
- Files deleted.

Business Logic:

- Rules affected.

API:

- Endpoints affected.

Database:

- Schema/query/index changes.

Tests:

- Tests executed.
- Results.

Risks:

- Known limitations.

Follow-up:

- Optional remaining work.
