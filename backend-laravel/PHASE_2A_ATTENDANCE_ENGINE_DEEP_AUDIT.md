# PHASE 2A — ATTENDANCE ENGINE DEEP AUDIT

## 1. Repository State

- **Branch:** `feat/monthly-recap-impls`
- **HEAD:** `69a389f`
- **Working tree:** Contains Phase 1 cleanup artifacts (`D` deleted files, `M` modified files). No unrelated backend changes beyond Phase 1.
- **Conflict markers:** `0` found in `app/`, `routes/`, `database/`, `tests/`.

---

## 2. Executive Summary

The repository currently has **two** Attendance Engine implementations:

1. **Legacy production engine:** `app/Services/Attendance/AttendanceEngine.php` + `GeofenceService`, `ScheduleResolver`, `PolicyEvaluator`
2. **Intended canonical engine:** `app/Domain/Attendance/Engines/AttendanceEngine.php` + domain rules (`GeofenceRule`, `GpsValidationRule`, `LateDetectionRule`, `EarlyCheckoutRule`, `AttendanceStateRule`) + DTOs

Production traffic follows:

```
Route
  → AttendanceController
    → CheckInEmployee / CheckOutEmployee
      → Services/Attendance/AttendanceEngine
        → GeofenceService
        → ScheduleResolver
        → PolicyEvaluator
```

The Domain Attendance Engine is **not wired into any production path**. It is only exercised by unit tests for `AttendanceStateRule`.

There are **critical semantic differences** between the two implementations that make direct substitution unsafe without a migration plan.

**Verdict:** `PHASE 2A — BLOCKED` for direct migration. Sufficient information exists to design Phase 2B, but migration must not proceed until semantic gaps are closed and tests are added.

---

## 3. Current Production Attendance Flow

### Entry Points

| Method | Route | Controller Method | Action |
|--------|-------|-------------------|--------|
| POST | `/api/v1/attendance/check-in` | `AttendanceController::checkIn` | `CheckInEmployee::execute` |
| POST | `/api/v1/attendance/check-out` | `AttendanceController::checkOut` | `CheckOutEmployee::execute` |
| GET | `/api/v1/attendance` | `AttendanceController::index` | Direct Eloquent query |
| GET | `/api/v1/attendance/{id}` | `AttendanceController::show` | Direct Eloquent query |

### Detailed Call Chain

```
POST /api/v1/attendance/check-in
  ↓
AttendanceController::checkIn(CheckInRequest)
  - Resolves employee from auth user
  - Resolves occurred_at from X-Occurred-At header or now()
  - Stores face_image temporarily if provided
  - Calls CheckInEmployee::execute(employee, occurredAt, context, actorId, request, faceImagePath)
  ↓
CheckInEmployee::execute()
  - Calls $this->engine->evaluateCheckIn(employee, occurredAt, context)
    → Services/Attendance/AttendanceEngine::evaluateCheckIn()
      → GeofenceService::evaluate()
      → ScheduleResolver::resolveForDateTime()
      → PolicyEvaluator::evaluateCheckIn()
      → determineStatus()
  - Checks for existing open session (action-level guard)
  - Calls VerifyFaceAction::execute() if active face profile exists
  - DB::transaction:
      - AttendanceRecord::create() or update status
      - AttendanceSession::create()
      - AttendanceEvent::create() × N
      - AttendanceVerification::create() if face verified
      - RecordAuditAction::execute()
  ↓
AttendanceController::checkIn()
  - Returns AttendanceResource with 201
```

```
POST /api/v1/attendance/check-out
  ↓
AttendanceController::checkOut(CheckOutRequest)
  - Same employee/occurred_at/context resolution as check-in
  - Calls CheckOutEmployee::execute(employee, occurredAt, context, actorId, request, faceImagePath)
  ↓
CheckOutEmployee::execute()
  - Calls $this->engine->evaluateCheckOut(employee, occurredAt, context)
    → Services/Attendance/AttendanceEngine::evaluateCheckOut()
      → GeofenceService::evaluate()
      → ScheduleResolver::resolveForDateTime()
      → PolicyEvaluator::evaluateCheckOut()
  - Checks for existing open session (engine-level)
  - Calls VerifyFaceAction::execute() if active face profile exists
  - DB::transaction:
      - AttendanceSession::update() with check_out_at, duration_minutes, status=closed
      - AttendanceRecord::update() with resolved status
      - AttendanceEvent::create()
      - AttendanceVerification::create() if face verified
      - RecordAuditAction::execute()
  ↓
AttendanceController::checkOut()
  - Returns AttendanceResource with 200
```

### No Other Attendance Entry Points Found

- No jobs, commands, or scheduled tasks reference `AttendanceEngine`, `CheckInEmployee`, or `CheckOutEmployee`.
- No `app(...)`, `resolve(...)`, or container bindings for legacy Attendance services found outside of tests.
- No Facade aliases for Attendance services.

---

## 4. Legacy Attendance Engine

**File:** `app/Services/Attendance/AttendanceEngine.php`

### Constructor Dependencies

| Dependency | Type | Role |
|-----------|------|------|
| `GeofenceService` | Service | PostGIS/scalar geofence evaluation |
| `ScheduleResolver` | Service | Date-bound schedule resolution with cross-midnight fallback |
| `PolicyEvaluator` | Service | Active policy lookup and check-in/out block evaluation |

### Public Methods

#### `evaluateCheckIn(Employee, CarbonImmutable, ?array): array`

**Input:**
- `$employee` — Eloquent Employee model
- `$occurredAt` — event timestamp
- `$context` — array with `latitude`, `longitude`, `accuracy`, `work_location_id`, `source`, `device_metadata`

**Output array keys:**
- `status` — `AttendanceStatus`
- `record` — `AttendanceRecord|null`
- `session` — `AttendanceSession|null`
- `geofence` — `array{passed, distance_meters, method}`
- `policy` — `array{allowed, reason, policies}`
- `events` — `array<int, array>`
- `error` — `string|null`

**Business rules:**
1. **Employment status:** Only `permanent` or `contract` allowed. Returns `Absent` + error for others.
2. **Schedule:** Calls `ScheduleResolver::resolveForDateTime()`. Returns `Absent` + "No active schedule found" if null.
3. **Policy:** Calls `PolicyEvaluator::evaluateCheckIn()`. Returns `Absent` + policy reason if not allowed.
4. **Geofence:** If `latitude`, `longitude`, and `work_location_id` are present, evaluates via `GeofenceService`. Returns `Incomplete` + error if outside geofence.
5. **Status determination:** Always returns `Present` if a shift exists (see `determineStatus()`).
6. **Events:** Builds a single `CheckIn` event array.

**Side effects:** None (evaluation only, no persistence).

**Exceptions thrown:** None (returns error strings).

#### `evaluateCheckOut(Employee, CarbonImmutable, ?array): array`

**Input:** Same as `evaluateCheckIn`.

**Output:** Same shape.

**Business rules:**
1. **Employment status:** Same as check-in.
2. **Open session lookup:** Finds latest `Open` session for employee. Returns `Incomplete` + "No open attendance session found" if none.
3. **Policy:** Calls `PolicyEvaluator::evaluateCheckOut()`. Returns `Incomplete` + policy reason if not allowed.
4. **Geofence:** Same conditional evaluation as check-in.
5. **Status:** Returns `$record->status` (current record status, not recalculated).
6. **Events:** Builds a single `CheckOut` event array.

**Side effects:** None.

**Exceptions thrown:** None.

#### `determineStatus(?CarbonImmutable, ?Shift): AttendanceStatus`

- Returns `Absent` if shift is null.
- Returns `Present` otherwise.
- Does NOT compute late/early checkout status. Comment says "Lateness is tracked separately by the Attendance Session / Daily Recap engines."

### Persistence Behavior

**None.** Legacy engine is pure evaluation. All persistence is in `CheckInEmployee` / `CheckOutEmployee` actions.

---

## 5. Legacy Dependencies

### GeofenceService

**File:** `app/Services/Attendance/GeofenceService.php`

**Public API:**
- `evaluate(WorkLocation, float, float): array{passed, distance_meters, method}`

**Callers:**
- `Services/Attendance/AttendanceEngine` (both check-in and check-out)

**DB queries:**
- PostgreSQL: `SELECT ST_DDistance(location_point, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography) AS distance_meters FROM work_locations WHERE id = ?`
- SQLite: Haversine scalar fallback using `latitude`/`longitude` columns

**Behavior:**
- Returns `passed: true` with `method: 'scalar_unverified'` if `latitude`/`longitude`/`radius_meters` null on SQLite.
- PostGIS path uses `ST_DDistance` directly, not `ST_DWithin`.
- Distance compared to `radius_meters`.

**Mapping to Domain:** `GeofenceRule::validate()` is functionally equivalent but:
- Legacy returns a result array. Domain throws `OutsideGeofenceException`.
- Legacy uses `ST_DDistance` + scalar comparison. Domain uses `ST_DWithin` (PostGIS) or Haversine (scalar).
- Legacy skips geofence if `work_location_id` is null. Domain throws `InvalidLocationException` for null coordinates but requires `WorkLocation` to be resolved before calling validate.

### ScheduleResolver

**File:** `app/Services/Attendance/ScheduleResolver.php`

**Public API:**
- `resolveForDate(Employee, CarbonImmutable): array{schedule, shift, assignment}`
- `resolveForDateTime(Employee, CarbonImmutable): array{schedule, shift, assignment}`

**Callers:**
- `Services/Attendance/AttendanceEngine` (both check-in and check-out)

**DB queries:**
- `ScheduleAssignment` with `effective_from <= date` and `effective_to >= date` (or null)
- Eager loads `workSchedule.shifts` ordered by `start_time`
- Orders by `effective_from DESC`, takes first

**Behavior:**
- Returns `shift` = `$schedule->shifts->first()` (first shift only, even if multiple exist).
- Cross-midnight: if current hour < 3 AND no shift found for current date, re-checks previous day for `cross_midnight` shifts.
- Returns `null` shift if none found.

**Mapping to Domain:** `ScheduleEngine::resolve()` is similar but:
- Legacy uses `orderByDesc('effective_from')->first()`. Domain uses `->get()` then throws `AmbiguousScheduleAssignmentException` if more than one assignment overlaps.
- Legacy silently returns first assignment even if multiple overlap. Domain throws.
- Legacy does NOT validate `WorkSchedule.status`. Domain throws `InactiveScheduleException` if schedule is not active.
- Legacy includes the `assignment` model in return. Domain DTO does not.
- Legacy returns `shift` as part of the result. Domain DTO does not include shift — callers access `$schedule->schedule->shifts->first()`.

### PolicyEvaluator

**File:** `app/Services/Attendance/PolicyEvaluator.php`

**Public API:**
- `activeForDate(Employee, CarbonImmutable): array<int, array{policy, configuration}>`
- `evaluateCheckIn(Employee, CarbonImmutable): array{allowed, reason, policies}`
- `evaluateCheckOut(Employee, CarbonImmutable): array{allowed, reason, policies}`

**Callers:**
- `Services/Attendance/AttendanceEngine` (both check-in and check-out)

**DB queries:**
- `PolicyAssignment` with `effective_from <= date` and `effective_to >= date` (or null)
- Eager loads `policy` with `where('status', 'active')`
- Orders by `effective_from DESC`, takes all

**Behavior:**
- Returns ALL active policy assignments for the date (does not enforce uniqueness).
- Checks `configuration['attendance']['check_in_blocked']` and `check_out_blocked`.
- Returns `policies` array with all matching assignments.

**Mapping to Domain:** `PolicyEngine::resolve()` is different:
- Legacy returns multiple policies. Domain returns exactly one or throws `AmbiguousPolicyAssignmentException`.
- Legacy does NOT throw on inactive policy (filters by `policy.status = 'active'` in query). Domain throws `InactivePolicyException`.
- Legacy does not throw on multiple assignments. Domain throws.
- Domain returns `PolicyResolutionData` DTO. Legacy returns raw array.

---

## 6. Domain Attendance Engine

**File:** `app/Domain/Attendance/Engines/AttendanceEngine.php`

### Constructor Dependencies

| Dependency | Type | Role |
|-----------|------|------|
| `PolicyEngine` | Domain Engine | Policy resolution |
| `ScheduleEngine` | Domain Engine | Schedule resolution |
| `GpsValidationRule` | Domain Rule | Coordinate/accuracy validation |
| `GeofenceRule` | Domain Rule | PostGIS/scalar geofence validation |
| `LateDetectionRule` | Domain Rule | Late check-in detection |
| `EarlyCheckoutRule` | Domain Rule | Early check-out detection |
| `AttendanceStateRule` | Domain Rule | Daily status determination |

### Public Methods

#### `checkIn(Employee, AttendanceOperationData): AttendanceResultData`

**Throws exceptions (does not return error arrays):**
- `InactiveEmployeeException` — if `employee->end_date` is past
- `InvalidLocationException` — if GPS coordinates invalid
- `OutsideGeofenceException` — if outside geofence
- `AttendanceAlreadyCheckedInException` — if open session exists (or unique constraint violated)
- `NoOpenAttendanceSessionException` — NOT thrown by checkIn

**Behavior:**
1. Validates `end_date` is not past.
2. Validates GPS coordinates via `GpsValidationRule`.
3. Resolves work date via `resolveWorkDate()` (cross-midnight logic).
4. Resolves policy and schedule.
5. Resolves `WorkLocation` by `workLocationId` or first active.
6. Validates geofence via `GeofenceRule`.
7. Queries existing `AttendanceRecord` for employee + date.
8. Throws `AttendanceAlreadyCheckedInException` if open session exists.
9. Creates `AttendanceRecord` if missing (with `status: 'incomplete'`).
10. Creates `AttendanceSession` (Open).
11. Creates `AttendanceEvent` (CheckIn).
12. Creates `AttendanceVerification` (Geofence type, NOT Face type).
13. Computes late status via `LateDetectionRule`.
14. Determines status via `AttendanceStateRule`.
15. Updates record status.
16. Returns `AttendanceResultData`.

**Transaction:** NONE. Direct model writes.

#### `checkOut(Employee, AttendanceOperationData): AttendanceResultData`

**Throws:**
- `InactiveEmployeeException`
- `InvalidLocationException` (from `GpsValidationRule`)
- `NoOpenAttendanceSessionException` — if no open session
- `InvalidLocationException` — if check-out time <= check-in time

**Behavior:**
1. Validates `end_date`.
2. Validates GPS.
3. Finds open session.
4. Resolves `WorkLocation`.
5. Validates geofence.
6. Validates check-out time > check-in time.
7. Computes duration.
8. Resolves schedule and policy for record date.
9. Updates session (closed).
10. Creates `AttendanceEvent` (CheckOut).
11. Creates `AttendanceVerification` (Geofence type).
12. Updates record status via `AttendanceStateRule`.
13. Returns `AttendanceResultData`.

**Transaction:** NONE. Direct model writes.

### Critical Differences from Legacy

| Aspect | Legacy | Domain |
|--------|--------|--------|
| Return type | `array` with `error` key | Throws exceptions OR returns `AttendanceResultData` |
| Employment check | `employment_status !== 'permanent' && !== 'contract'` | `end_date && end_date->isPast()` |
| Employment allowed values | `permanent`, `contract` only | Any status as long as `end_date` not past (probation, outsource allowed) |
| Open session check | Action-level query in `CheckInEmployee` | Engine-level query (both check-in and check-out) |
| Face verification | Action-level (`CheckInEmployee`/`CheckOutEmployee`) | NOT in engine (creates Geofence verification only) |
| Geofence result | Returns array | Throws `OutsideGeofenceException` |
| Policy result | Returns array with all policies | Returns single `PolicyResolutionData` DTO |
| Multiple policies | Allowed, returns all | Throws `AmbiguousPolicyAssignmentException` |
| Inactive policy | Silently skipped (query filter) | Throws `InactivePolicyException` |
| Inactive schedule | Returns null shift, engine returns Absent | Throws `InactiveScheduleException` |
| Schedule resolution | Returns `schedule`, `shift`, `assignment` | Returns `ScheduleResolutionData` with `schedule` only |
| Late detection | Not computed in engine | Computed via `LateDetectionRule` |
| Early checkout | Not computed in engine | Computed via `EarlyCheckoutRule` |
| Status determination | Always `Present` if shift exists | Full state machine (Absent, Incomplete, Late, Present) |
| Transactions | Action-level `DB::transaction` | None in engine |
| Audit | Action-level | None in engine |
| Record date | `occurredAt->toDateString()` | `resolveWorkDate()` with cross-midnight adjustment |
| WorkLocation fallback | N/A (requires explicit work_location_id) | First active work location if not provided |
| AttendanceVerification type | Face (when face verified) | Geofence only |

---

## 7. Legacy vs Domain Comparison

| Concern | Legacy | Domain | Same? | Risk | Required Action |
|---------|--------|--------|-------|------|-----------------|
| Employee eligibility | `employment_status in ['permanent', 'contract']` | `end_date is null or future` | NO | HIGH | Business decision needed |
| Employee active/inactive | String enum check | Date check | NO | HIGH | Business decision needed |
| Employment status enum | `EmploymentStatus` enum exists but not used | `InactiveEmployeeException` | NO | HIGH | Must align |
| Check-in | evaluateCheckIn returns array | checkIn() throws or returns DTO | PARTIAL | HIGH | Action boundary must change |
| Check-out | evaluateCheckOut returns array | checkOut() throws or returns DTO | PARTIAL | HIGH | Action boundary must change |
| Attendance date | `occurredAt->toDateString()` | `resolveWorkDate()` | PARTIAL | MEDIUM | Cross-midnight differs |
| Multiple sessions | Unlimited (Action-level guard) | Unlimited (Engine-level guard) | YES | LOW | Compatible |
| Duplicate check-in | Action-level `hasOpenSession` query | Engine-level `AttendanceAlreadyCheckedInException` | YES | LOW | Compatible |
| Open session lookup | `AttendanceSession::whereHas(...)->where('status', 'open')->orderByDesc('check_in_at')->first()` | Same query | YES | LOW | Compatible |
| Cross-midnight | ScheduleResolver only (hour < 3 check) | resolveWorkDate + resolveScheduledStart/End | PARTIAL | MEDIUM | Slightly different logic |
| Geofence | `GeofenceService::evaluate()` returns array | `GeofenceRule::validate()` throws | NO | HIGH | Caller contract must change |
| PostGIS | `ST_DDistance` + scalar compare | `ST_DWithin` | YES | LOW | Semantically equivalent |
| Schedule | `ScheduleResolver::resolveForDateTime()` | `ScheduleEngine::resolve()` | PARTIAL | HIGH | Throws on ambiguous/inactive |
| Policy | `PolicyEvaluator::evaluateCheckIn()` returns all policies | `PolicyEngine::resolve()` returns one or throws | NO | HIGH | Business decision needed |
| Grace period | NOT computed in engine | `LateDetectionRule` supports grace | NO | MEDIUM | May need policy integration |
| Face verification | Action-level, persists Face verification | NOT in engine (persists Geofence only) | NO | HIGH | Missing in domain engine |
| FastAPI | `VerifyFaceAction` in action | NOT in engine | NO | HIGH | Must be added or stay in action |
| Audit | Action-level `RecordAuditAction` | None in engine | NO | MEDIUM | Must be added or stay in action |
| Transactions | Action-level `DB::transaction` | None in engine | NO | HIGH | Must be added |
| Persistence | Action-level | Engine-level | NO | HIGH | Responsibility shift |
| Exceptions | Error strings in array | Domain exceptions | NO | HIGH | HTTP mapping must be added |
| Response contract | Array with `error`, `conflict` keys | `AttendanceResultData` DTO | NO | HIGH | Resource mapping needed |

---

## 8. Employment Status Differences

### Legacy Behavior

**Location:** `app/Services/Attendance/AttendanceEngine.php:38,162`

```php
if ($employee->employment_status !== 'permanent' && $employee->employment_status !== 'contract') {
    return [
        'status' => AttendanceStatus::Absent,
        // ...
        'error' => 'Employee employment status is inactive.',
    ];
}
```

- **Allowed values:** `permanent`, `contract` only.
- **Other values treated as inactive:** `probation`, `outsource`, `resigned`, `terminated`, etc.
- **No date check:** `join_date` and `end_date` are ignored.
- **Enum exists:** `EmploymentStatus` enum defines `Permanent`, `Contract`, `Probation`, `Outsource` but legacy engine does not use it.

### Domain Behavior

**Location:** `app/Domain/Attendance/Engines/AttendanceEngine.php:60,118`

```php
if ($employee->end_date && $employee->end_date->isPast()) {
    throw new InactiveEmployeeException('Employee has ended employment.');
}
```

- **Allowed values:** ANY `employment_status` as long as `end_date` is null or in the future.
- **Probation/Outsource allowed:** Yes, if not ended.
- **Date-driven:** Uses `end_date` (cast to `date` in Employee model).
- **Enum not used:** `EmploymentStatus` enum is not referenced.

### Difference Summary

| Dimension | Legacy | Domain |
|-----------|--------|--------|
| Gate condition | String enum (`permanent`/`contract`) | Date check (`end_date` past?) |
| Probation employees | Blocked | Allowed |
| Outsource employees | Blocked | Allowed |
| Resigned but not ended | Blocked (by status) | Depends on `end_date` |
| Contract expired but status not updated | Allowed if status still `contract` | Blocked if `end_date` past |
| `join_date` | Not checked | Not checked |

### Risk

**HIGH.** This is the most dangerous semantic difference. If probation or outsource employees are currently blocked from attendance but the domain engine allows them, or vice versa, a direct swap would change attendance eligibility.

### Required Migration Behavior

A business decision is required:
- Should ALL non-permanent/non-contract employees be blocked (legacy behavior)?
- Or should only ended employees be blocked (domain behavior)?
- Or should `probation` and `outsource` be explicitly allowed or blocked?

The migration must align the domain engine's `end_date` check with the legacy `employment_status` check, or the business rule must be explicitly updated.

---

## 9. Schedule Differences

### Legacy: ScheduleResolver

**File:** `app/Services/Attendance/ScheduleResolver.php`

**Method:** `resolveForDateTime(Employee, CarbonImmutable)`

**Behavior:**
1. Calls `resolveForDate()`.
2. `resolveForDate()`:
   - Queries `ScheduleAssignment` where `effective_from <= date` and (`effective_to IS NULL OR effective_to >= date`).
   - Eager loads `workSchedule.shifts` ordered by `start_time`.
   - Orders by `effective_from DESC`, takes first.
   - Returns `schedule`, `shift = schedule->shifts->first()`, `assignment`.
3. If no shift found AND `hour < 3`, re-checks previous day for `cross_midnight` shifts.
4. Returns first shift even if schedule has multiple shifts.

### Domain: ScheduleEngine

**File:** `app/Domain/Schedule/Engines/ScheduleEngine.php`

**Method:** `resolve(Employee, CarbonImmutable)`

**Behavior:**
1. Queries `ScheduleAssignment` with same date range.
2. Eager loads `workSchedule.shifts`.
3. Uses `->get()` (not `first()`).
4. If empty → returns `ScheduleResolutionData` with `schedule: null`.
5. If more than one → throws `AmbiguousScheduleAssignmentException`.
6. If schedule status not `active` → throws `InactiveScheduleException`.
7. Returns `ScheduleResolutionData` with `schedule` (shift not included).

### Cross-Midnight

**Legacy:**
- `ScheduleResolver::resolveForDateTime()` checks if `hour < 3` and re-queries previous day.
- This means a 22:00-06:00 shift checked at 01:00 resolves to previous day's schedule.

**Domain:**
- `AttendanceEngine::resolveWorkDate()` checks if shift `cross_midnight` and current time < `end_time`, then subtracts one day.
- `resolveScheduledStart()` and `resolveScheduledEnd()` have similar cross-midnight logic.
- Both use `shift->cross_midnight` flag.

**Difference:** The legacy `hour < 3` hardcoded check is less precise than the domain's `current_time < shift->end_time` check. For a 23:00-05:00 shift, legacy would NOT cross-midnight at 04:00 (hour=4 >= 3), but domain would correctly identify it.

### Key Differences

| Aspect | Legacy | Domain |
|--------|--------|--------|
| Multiple assignments | Silently picks first (by `effective_from DESC`) | Throws `AmbiguousScheduleAssignmentException` |
| Inactive schedule | Returns null shift, engine returns Absent | Throws `InactiveScheduleException` |
| Shift selection | `shifts->first()` (ordered by `start_time`) | Same, but caller must do it |
| Cross-midnight check | `hour < 3` hardcoded | `current_time < shift->end_time` |
| Return shape | `schedule`, `shift`, `assignment` | `ScheduleResolutionData` with `schedule` only |

### Risk

**HIGH.** Multiple active schedule assignments currently resolve silently in legacy. Domain would throw. If any employee has overlapping assignments (even unintentionally), migration would change behavior.

---

## 10. Policy Differences

### Legacy: PolicyEvaluator

**File:** `app/Services/Attendance/PolicyEvaluator.php`

**Methods:**
- `activeForDate(Employee, CarbonImmutable): array`
- `evaluateCheckIn(Employee, CarbonImmutable): array`
- `evaluateCheckOut(Employee, CarbonImmutable): array`

**Behavior:**
- Returns ALL active policy assignments for the date.
- Checks `configuration['attendance']['check_in_blocked']` and `check_out_blocked`.
- No uniqueness enforcement.
- No inactive policy check (filters by `policy.status = 'active'` in query).

### Domain: PolicyEngine

**File:** `app/Domain/Policy/Engines/PolicyEngine.php`

**Method:** `resolve(Employee, CarbonImmutable): PolicyResolutionData`

**Behavior:**
- Returns exactly one policy or throws.
- Throws `AmbiguousPolicyAssignmentException` if multiple assignments overlap.
- Throws `InactivePolicyException` if policy status not `active`.
- Returns `PolicyResolutionData` with `hasPolicy()` method.

### Key Differences

| Aspect | Legacy | Domain |
|--------|--------|--------|
| Multiple policies | Returns all | Throws exception |
| Inactive policy | Silently filtered | Throws exception |
| Return shape | Array with `allowed`, `reason`, `policies` | `PolicyResolutionData` DTO |
| Check-in block | `check_in_blocked` config | Not implemented in domain engine (only returns policy) |
| Check-out block | `check_out_blocked` config | Not implemented in domain engine |

### Risk

**HIGH.** Legacy allows multiple policies and checks `check_in_blocked`/`check_out_blocked` inline. Domain engine returns only one policy and does not evaluate block flags. The block evaluation must be re-implemented in the domain layer or the attendance engine must be updated.

---

## 11. Geofence Differences

### Legacy: GeofenceService

**File:** `app/Services/Attendance/GeofenceService.php`

**Method:** `evaluate(WorkLocation, float, float): array`

**PostGIS path:**
```sql
SELECT ST_DDistance(
    location_point,
    ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography
) AS distance_meters
FROM work_locations
WHERE id = ?
```

**Scalar path:**
- Haversine distance using `latitude`/`longitude` columns.
- Returns `passed: true` with `method: 'scalar_unverified'` if any coordinate is null.

### Domain: GeofenceRule

**File:** `app/Domain/Attendance/Rules/GeofenceRule.php`

**Method:** `validate(WorkLocation, float, float): void`

**PostGIS path:**
```sql
SELECT ST_DWithin(
    ?::geography,
    ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography,
    COALESCE(?, 0)
) AS inside
```

**Scalar path:**
- Haversine distance.
- Returns silently (no pass result) if `latitude`/`longitude` null.

### Key Differences

| Aspect | Legacy | Domain |
|--------|--------|--------|
| PostGIS function | `ST_DDistance` + scalar compare | `ST_DWithin` |
| Null coordinate handling | Returns `passed: true` (unverified) | Returns silently (no exception) |
| Return type | Result array | Throws `OutsideGeofenceException` or returns void |
| Caller contract | Check `passed` key | Catch exception or assume passed |

### Risk

**LOW.** The PostGIS functions are semantically equivalent for point-in-circle checks. `ST_DWithin` with geography is the more idiomatic approach. The null-coordinate behavior difference is minor (both effectively pass when coordinates are missing).

---

## 12. Face Verification Differences

### Production Path (Legacy)

**Flow:**
1. `AttendanceController` stores `face_image` to temp path.
2. `CheckInEmployee::verifyFaceForAttendance()`:
   - Looks up active `EmployeeFaceProfile`.
   - If no profile → passes without verification.
   - If profile exists but no image → fails.
   - Calls `VerifyFaceAction::execute()` with `livenessRequired=true`, `persistRecord=false`.
3. `VerifyFaceAction`:
   - Calls `FastApiService::verify()`.
   - Evaluates `faceDetected`, `verified`, `liveness`.
   - If `persistRecord=false`, does NOT create `AttendanceVerification`.
4. Back in `CheckInEmployee`:
   - If face passed → creates `AttendanceVerification` with `verification_type=Face`.
5. Inside `DB::transaction`: persists record, session, events, face verification, audit.

### Domain Engine Path

**Flow:**
1. Domain engine does NOT handle face verification.
2. Creates `AttendanceVerification` with `verification_type=Geofence` (not Face).
3. No face verification call in engine.

### Critical Difference

The Domain Attendance Engine **does not include face verification**. If migrated as-is, face-verified attendance would lose the `Face` verification type and the FastAPI call would need to happen elsewhere.

### Risk

**HIGH.** Face verification is a business requirement for employees with active face profiles. The domain engine must be extended to support it, or it must remain in the action layer.

---

## 13. Transaction Ownership

### Legacy

- **Action layer** (`CheckInEmployee` / `CheckOutEmployee`) owns the transaction.
- `DB::transaction()` wraps:
  - `AttendanceRecord` create/update
  - `AttendanceSession` create/update
  - `AttendanceEvent` create × N
  - `AttendanceVerification` create
  - `RecordAuditAction::execute()`
- FastAPI call happens **outside** the transaction (in action, before transaction).
- Face verification happens **outside** the transaction.

### Domain

- **Engine layer** has NO transaction.
- All model writes are direct (`::create()`, `->update()`).
- If an exception occurs mid-write, partial data persists.

### Risk

**HIGH.** Moving to the domain engine without adding transaction boundaries would change rollback behavior. If face verification succeeds but `AttendanceRecord` creation fails, legacy rolls back everything. Domain engine would leave partial data.

---

## 14. Persistence Comparison

| Model | Legacy Writer | Domain Writer | Same? |
|-------|--------------|---------------|-------|
| `AttendanceRecord` | `CheckInEmployee` action | Domain engine `createAttendanceRecord()` | YES |
| `AttendanceSession` | `CheckInEmployee` / `CheckOutEmployee` | Domain engine `createSession()` | YES |
| `AttendanceEvent` | `CheckInEmployee` / `CheckOutEmployee` | Domain engine `createEvent()` | YES |
| `AttendanceVerification` | `CheckInEmployee` / `CheckOutEmployee` | Domain engine `createVerification()` | PARTIAL |
| `AuditLog` | `RecordAuditAction` in action | None in engine | NO |

**AttendanceVerification difference:**
- Legacy: Creates with `verification_type=Face` when face passes; `details` includes AI data.
- Domain: Creates with `verification_type=Geofence`; `details` includes location data only.
- Legacy does NOT create verification when face fails or no profile exists.
- Domain ALWAYS creates geofence verification.

---

## 15. Exception Matrix

| Exception | Legacy | Domain | Active? | Equivalent? | Migration Action |
|-----------|--------|--------|---------|-------------|-----------------|
| Error string (inline) | Yes | No | Legacy only | N/A | Replace with domain exceptions |
| `AttendanceStateConflictException` | Dead (deleted in Phase 1) | Dead | No | N/A | Already deleted |
| `DuplicateAttendanceRequestException` | Dead (deleted in Phase 1) | Dead | No | N/A | Already deleted |
| `AttendanceAlreadyCheckedInException` | No | Yes | Domain only | PARTIAL | Must be caught in action/controller |
| `NoOpenAttendanceSessionException` | No | Yes | Domain only | YES | Must be caught in action/controller |
| `OutsideGeofenceException` | No | Yes | Domain only | YES | Must be caught in action/controller |
| `InvalidLocationException` | No | Yes | Domain only | YES | Must be caught in action/controller |
| `InactiveEmployeeException` | No | Yes | Domain only | PARTIAL | Different trigger condition |
| `InactivePolicyException` | No | Yes | Domain only | NEW | Must be caught |
| `InactiveScheduleException` | No | Yes | Domain only | NEW | Must be caught |
| `AmbiguousPolicyAssignmentException` | No | Yes | Domain only | NEW | Must be caught |
| `AmbiguousScheduleAssignmentException` | No | Yes | Domain only | NEW | Must be caught |

---

## 16. DTO Comparison

### Legacy

No DTOs. Uses raw associative arrays throughout.

### Domain

| DTO | File | Role |
|-----|------|------|
| `AttendanceOperationData` | `app/Domain/Attendance/DTOs/AttendanceOperationData.php` | Input DTO for check-in/check-out |
| `AttendanceResultData` | `app/Domain/Attendance/DTOs/AttendanceResultData.php` | Output DTO for successful operations |

**`AttendanceOperationData` fields:**
- `employeeId: int`
- `latitude: float`
- `longitude: float`
- `accuracy: ?float`
- `deviceIdentifier: ?string`
- `source: ?string`
- `workLocationId: ?int`
- `occurredAt: CarbonImmutable`
- `eventType: AttendanceEventType`

**Missing from legacy context:**
- `deviceIdentifier` — legacy uses `device_metadata` array, not a single string.
- `eventType` — legacy infers from method (CheckIn vs CheckOut).

**`AttendanceResultData` fields:**
- `attendanceRecord: AttendanceRecord`
- `session: ?AttendanceSession`
- `verification: ?AttendanceVerification`
- `message: string`

**Cannot represent legacy behavior:**
- Legacy returns error strings in the same array as success data. `AttendanceResultData` is success-only.
- Legacy returns `geofence` and `policy` arrays. Domain engine does not include them in result.
- Legacy returns `conflict` flag. Not in domain DTO.

### Risk

**MEDIUM.** The domain DTO contract is narrower than the legacy array contract. The action/controller layer must bridge this gap.

---

## 17. API Response Contract

### Current Production Response

**Check-in success (201):**
```json
{
  "success": true,
  "data": {
    "id": 1,
    "employee_id": 1,
    "attendance_date": "2026-09-12",
    "status": "present",
    "sessions": [
      {
        "id": 1,
        "attendance_record_id": 1,
        "check_in_at": "2026-09-12T08:00:00Z",
        "check_out_at": null,
        "duration_minutes": null,
        "status": "open"
      }
    ],
    "geofence": { "passed": true, "distance_meters": 123.4, "method": "postgis" },
    "policy": { "allowed": true, "reason": null, "policies": [] },
    "error": null,
    "created_at": "2026-09-12T...",
    "updated_at": "2026-09-12T..."
  }
}
```

**Check-in error (422 or 409):**
```json
{
  "success": false,
  "data": {
    "status": "incomplete",
    "error": "Employee employment status is inactive.",
    "geofence": { ... },
    "policy": { ... }
  }
}
```

### Domain Engine Output

Domain engine returns `AttendanceResultData` which only contains:
- `attendanceRecord`
- `session`
- `verification`
- `message`

It does NOT include:
- `geofence` result
- `policy` result
- `error` (uses exceptions instead)

### Risk

**HIGH.** The API response contract would change if the domain engine were wired directly. The controller/action must reconstruct the legacy response format.

---

## 18. Test Coverage Matrix

| Behavior | Production exists? | Legacy tested? | Domain tested? | Integration tested? | Missing test? |
|---------|-------------------|----------------|----------------|---------------------|---------------|
| Check-in creates record + session | YES | YES (`AttendanceDirectActionTest`) | NO | YES (`AttendanceEngineTest`) | Domain integration |
| Check-out closes session | YES | YES | NO | YES | Domain integration |
| Multiple sessions | YES | YES | NO | YES | Domain integration |
| Policy blocks check-in | YES | YES | NO | YES | Domain integration |
| Policy blocks check-out | YES | YES | NO | YES | Domain integration |
| Cross-midnight check-in | YES | YES | NO | YES | Domain integration |
| Cross-midnight check-out | YES | YES | NO | YES | Domain integration |
| Check-out without open session | YES | YES | NO | YES | Domain integration |
| Inactive employee | YES | YES | NO | YES | Domain integration |
| No schedule | YES | YES | NO | YES | Domain integration |
| Face verification success | YES | YES | NO | YES | Domain engine missing |
| Face verification failure | YES | YES | NO | YES | Domain engine missing |
| AI service unavailable | YES | YES | NO | YES | Domain engine missing |
| Liveness failure | YES | YES | NO | YES | Domain engine missing |
| No face profile (skip) | YES | YES | NO | YES | Domain engine missing |
| Audit log creation | YES | NO (action only) | NO | YES | Both |
| Transaction rollback | YES | NO (action only) | NO | NO | CRITICAL |
| Duplicate check-in | YES | YES | NO | NO | CRITICAL |
| Geofence boundary | YES | NO | NO | NO | HIGH |
| Schedule missing | YES | NO | NO | NO | MEDIUM |
| Policy missing | YES | NO | NO | NO | MEDIUM |
| Employment status variants | YES | NO | NO | NO | HIGH |
| Multiple policy assignments | YES | NO | NO | NO | HIGH |
| Inactive schedule | YES | NO | NO | NO | HIGH |

---

## 19. Direct Legacy Test Dependencies

**File:** `tests/Feature/Attendance/AttendanceDirectActionTest.php`

Directly instantiates:
- `App\Services\Attendance\AttendanceEngine`
- `App\Services\Attendance\GeofenceService`
- `App\Services\Attendance\ScheduleResolver`
- `App\Services\Attendance\PolicyEvaluator`

**This test must be rewritten** when migrating to the domain engine. It tests the legacy engine through the action layer.

**File:** `tests/Feature/AttendanceEngineTest.php`

Tests via HTTP endpoints (indirect). Would still pass after migration if the action/controller boundary is preserved.

**File:** `tests/Feature/Attendance/AttendanceAuthorizationTest.php`

Tests auth only. Unaffected by engine migration.

**File:** `tests/Feature/AttendanceSchemaTest.php`

Tests schema constraints. Unaffected.

**File:** `tests/Unit/Domain/Attendance/AttendanceStateRuleTest.php`

Tests domain rule in isolation. Unaffected.

---

## 20. Reverse Dependency Map

### Legacy AttendanceEngine

| Caller | Type |
|--------|------|
| `App\Actions\Attendance\CheckInEmployee` | Production |
| `App\Actions\Attendance\CheckOutEmployee` | Production |
| `Tests\Feature\Attendance\AttendanceDirectActionTest` | Test |

### Legacy GeofenceService

| Caller | Type |
|--------|------|
| `App\Services\Attendance\AttendanceEngine` | Production |
| `Tests\Feature\Attendance\AttendanceDirectActionTest` | Test |

### Legacy ScheduleResolver

| Caller | Type |
|--------|------|
| `App\Services\Attendance\AttendanceEngine` | Production |
| `Tests\Feature\Attendance\AttendanceDirectActionTest` | Test |

### Legacy PolicyEvaluator

| Caller | Type |
|--------|------|
| `App\Services\Attendance\AttendanceEngine` | Production |
| `Tests\Feature\Attendance\AttendanceDirectActionTest` | Test |

### Domain AttendanceEngine

| Caller | Type |
|--------|------|
| None (no production callers) | — |
| `Tests\Unit\Domain\Attendance\AttendanceStateRuleTest` | Test (indirect, tests rule only) |

---

## 21. Service Container Dependencies

**No explicit container bindings** found for any Attendance engine or service. All are resolved via constructor injection.

**Laravel auto-resolution** handles:
- `CheckInEmployee` → injects `Services\Attendance\AttendanceEngine`
- `CheckOutEmployee` → injects `Services\Attendance\AttendanceEngine`
- `AttendanceController` → injects `CheckInEmployee`, `CheckOutEmployee`

Switching the action constructors to inject `Domain\Attendance\Engines\AttendanceEngine` would require no container configuration changes.

---

## 22. Database/Model Dependencies

### AttendanceRecord

- **PK:** `id`
- **Unique:** `(employee_id, attendance_date)`
- **Indexes:** `(employee_id, created_at)`, `status`
- **Casts:** `attendance_date` → `date`
- **Relationships:** `employee`, `sessions`, `events`, `verifications`

### AttendanceSession

- **PK:** `id`
- **FK:** `attendance_record_id` → `attendance_records.id` (cascade delete)
- **Indexes:** `(attendance_record_id, status)`
- **Casts:** `check_in_at`, `check_out_at` → `datetime`

### AttendanceEvent

- **PK:** `id`
- **FKs:** `employee_id` (restrict), `attendance_id` (null on delete), `attendance_session_id` (null on delete)
- **Indexes:** `(employee_id, occurred_at)`, `(attendance_id, event_type)`, `attendance_session_id`
- **PostGIS:** `geography('location', 4326)` with spatial index
- **Casts:** `device_metadata` → `array`, `occurred_at` → `datetime`, `latitude`/`longitude` → `float`

### AttendanceVerification

- **PK:** `id`
- **FKs:** `attendance_id` (cascade), `attendance_session_id` (null on delete), `employee_id` (restrict)
- **Indexes:** `attendance_id`, `(employee_id, verified_at)`
- **Casts:** `details` → `array`, `verified_at` → `datetime`

### WorkLocation

- **Fillable:** `code`, `name`, `latitude`, `longitude`, `radius_meters`, `location_point`, `status`
- **Casts:** `latitude`/`longitude`/`radius_meters` → `float`

### Shift

- **Fillable:** `work_schedule_id`, `name`, `start_time`, `end_time`, `break_start`, `break_end`, `cross_midnight`
- **Casts:** `cross_midnight` → `boolean`

### Schema Assumptions in Domain Engine

| Domain Assumption | Schema Reality | Match? |
|-------------------|----------------|--------|
| `attendance_date` is date | `date` column | YES |
| `check_in_at` / `check_out_at` are timestamps | `timestampTz` | YES |
| `status` is string | `string` column | YES |
| `attendance_id` FK on events/verifications | `attendance_id` column | YES |
| `attendance_session_id` nullable | Nullable | YES |
| `employee_id` restrict on delete | `restrictOnDelete` | YES |
| `location_point` PostGIS geography | `geography` column | YES |
| `radius_meters` on WorkLocation | `radius_meters` column | YES |

---

## 23. Cross-Domain Dependencies

### Attendance → Policy

- **Legacy:** `PolicyEvaluator` (service) → `Policy` model
- **Domain:** `PolicyEngine` (domain engine) → `Policy` model
- **Shared:** `PolicyAssignment`, `Policy` models
- **Other consumers:** `PenaltyEngine`, `OvertimeEngine`, `LeaveEngine`, `MonthlyRecapEngine`
- **Circular dependency risk:** None. PolicyEngine has no Attendance dependency.

### Attendance → Schedule

- **Legacy:** `ScheduleResolver` (service) → `ScheduleAssignment`, `WorkSchedule`, `Shift`
- **Domain:** `ScheduleEngine` (domain engine) → same models
- **Shared:** `ScheduleAssignment`, `WorkSchedule`, `Shift` models
- **Other consumers:** `PenaltyEngine`, `OvertimeEngine`, `MonthlyRecapEngine`
- **Circular dependency risk:** None.

### Attendance → Face/FastAPI

- **Legacy:** `VerifyFaceAction` → `FastApiService` → FastAPI HTTP
- **Domain:** None
- **Risk:** If face verification moves into domain engine, domain layer would depend on integration service. Better to keep in action layer.

### Attendance → Leave/Overtime/Penalty/MonthlyRecap

- **No direct Attendance → Leave/Overtime/Penalty/MonthlyRecap dependencies.**
- Reverse dependencies exist (PenaltyEngine, OvertimeEngine, MonthlyRecapEngine read AttendanceRecord).

---

## 24. Business Rule Ownership

| Rule | Current Location | Classification |
|------|-----------------|----------------|
| Employment eligibility | Legacy `AttendanceEngine::evaluateCheckIn/Out` | Legacy Service |
| Employment eligibility (domain) | Domain `AttendanceEngine::checkIn/Out` | Domain Engine |
| Schedule resolution | Legacy `ScheduleResolver` / Domain `ScheduleEngine` | Domain Engine |
| Policy resolution | Legacy `PolicyEvaluator` / Domain `PolicyEngine` | Domain Engine |
| Policy block evaluation | Legacy `PolicyEvaluator::evaluateCheckIn/Out` | Legacy Service |
| Geofence validation | Legacy `GeofenceService` / Domain `GeofenceRule` | Domain Rule |
| GPS validation | Domain `GpsValidationRule` only | Domain Rule |
| Late detection | Legacy comment only / Domain `LateDetectionRule` | Domain Rule |
| Early checkout detection | Legacy comment only / Domain `EarlyCheckoutRule` | Domain Rule |
| Status determination | Legacy `determineStatus()` / Domain `AttendanceStateRule` | Domain Rule |
| Open session guard | Legacy action / Domain engine | Mixed |
| Face verification | `CheckInEmployee`/`CheckOutEmployee` action | Action |
| FastAPI call | `VerifyFaceAction` | Action |
| Audit | `RecordAuditAction` in action | Action |
| Transaction | `CheckInEmployee`/`CheckOutEmployee` action | Action |
| Record persistence | Legacy action / Domain engine | Mixed |
| Session persistence | Legacy action / Domain engine | Mixed |
| Event persistence | Legacy action / Domain engine | Mixed |
| Verification persistence | Legacy action / Domain engine | Mixed |

---

## 25. Semantic Gap Classification

| Gap | Classification |
|-----|---------------|
| Employment status gate (`permanent`/`contract` vs `end_date`) | **BEHAVIORALLY DIFFERENT** |
| Multiple policy assignments (allowed vs throw) | **BEHAVIORALLY DIFFERENT** |
| Inactive policy (silent skip vs throw) | **BEHAVIORALLY DIFFERENT** |
| Inactive schedule (null shift vs throw) | **BEHAVIORALLY DIFFERENT** |
| Policy block evaluation (`check_in_blocked`) | **MISSING IN DOMAIN** |
| Face verification in engine | **MISSING IN DOMAIN** |
| Transaction ownership | **ARCHITECTURAL DECISION** |
| Audit ownership | **ARCHITECTURAL DECISION** |
| Exception-based vs array-based error handling | **BEHAVIORALLY DIFFERENT** |
| API response contract (geofence/policy in response) | **BEHAVIORALLY DIFFERENT** |
| Cross-midnight date resolution | **POTENTIAL REGRESSION** |
| WorkLocation fallback (first active) | **MISSING IN DOMAIN** |
| AttendanceVerification type (Face vs Geofence) | **BEHAVIORALLY DIFFERENT** |
| Late detection with grace period | **MISSING IN DOMAIN** (grace not wired from policy) |
| `device_identifier` field | **SAFE REUSE** (not in domain DTO yet) |

---

## 26. Migration Risk Matrix

| Area | Risk | Why | Required Before Migration |
|------|------|-----|---------------------------|
| Employee eligibility | **HIGH** | `employment_status` vs `end_date` logic differs | Business decision on eligibility rule |
| Schedule | **HIGH** | Throws on ambiguous/inactive; legacy silently handles | Data cleanup for overlapping assignments |
| Policy | **HIGH** | Throws on ambiguous/inactive; block evaluation missing | Policy data audit + block rule implementation |
| Geofence | **LOW** | Semantically equivalent (ST_DDistance vs ST_DWithin) | None |
| Face | **HIGH** | Missing from domain engine | Design decision: engine vs action |
| Persistence | **HIGH** | Responsibility shifts to engine | Transaction wrapper in engine |
| Transactions | **HIGH** | Domain engine has none | Add DB::transaction in engine or action |
| Audit | **MEDIUM** | Must be added to engine or kept in action | Design decision |
| Exceptions | **HIGH** | 6 new exception types must be caught and mapped to HTTP | Exception handler or action catch block |
| DTO | **MEDIUM** | Domain DTO is narrower than legacy array | Bridge code in action/controller |
| API response | **MEDIUM** | geofence/policy/error fields lost | Bridge code in controller |
| Tests | **HIGH** | Direct legacy test must be rewritten | Add domain integration tests |
| Cross-midnight | **MEDIUM** | Slightly different logic | Verify with existing cross-midnight test |
| Multiple sessions | **LOW** | Compatible | None |
| Concurrency | **MEDIUM** | Unique constraint handling differs | Verify `UniqueConstraintViolationException` catch |

---

## 27. Proposed Target Architecture

```
Route
  ↓
Form Request (Attendance/CheckInRequest, Attendance/CheckOutRequest)
  ↓
Controller (AttendanceController)
  ↓
Action / Application Service (CheckInEmployee, CheckOutEmployee)
  ↓
Domain Attendance Engine (checkIn / checkOut)
  ├── Attendance Rules
  │   ├── GpsValidationRule
  │   ├── GeofenceRule
  │   ├── LateDetectionRule
  │   ├── EarlyCheckoutRule
  │   └── AttendanceStateRule
  ├── PolicyEngine
  ├── ScheduleEngine
  └── (Face Verification Action — stays here or in engine)
  ↓
DB::transaction (in Action or Engine)
  ↓
Audit (RecordAuditAction)
  ↓
Resource (AttendanceResource)
```

**Deviations from preferred architecture:**
1. Face verification should remain in the action layer (or be injected into engine as a separate concern) because it requires HTTP I/O to FastAPI, which is an integration concern.
2. `AttendanceOperationData` DTO needs `device_identifier` and `eventType` fields populated from context.
3. Domain engine needs transaction boundaries — either in engine or wrapped by action.

---

## 28. Proposed Migration Sequence

### Step 1: Add Missing Tests (HIGH PRIORITY)

**Files:** New tests in `tests/Feature/Attendance/` and `tests/Unit/Domain/Attendance/`

- Test domain engine directly for all legacy behaviors.
- Test exception mapping.
- Test transaction behavior.
- Test face verification integration.
- Test cross-midnight edge cases.
- Test multiple policy/schedule edge cases.

**Risk:** LOW
**Prerequisite:** None
**Rollback:** None (tests only)

### Step 2: Extend Domain Engine for Missing Behaviors

**Files:**
- `app/Domain/Attendance/Engines/AttendanceEngine.php` — add face verification support, device_identifier, eventType, transaction, audit
- `app/Domain/Attendance/DTOs/AttendanceOperationData.php` — add missing fields if needed
- `app/Domain/Attendance/DTOs/AttendanceResultData.php` — add geofence/policy/error if needed

**Risk:** HIGH
**Prerequisite:** Step 1
**Rollback:** Keep legacy engine untouched until Step 5

### Step 3: Align Employment Status Semantics

**Files:**
- `app/Domain/Attendance/Engines/AttendanceEngine.php`
- `app/Services/Attendance/AttendanceEngine.php` (read-only until Step 5)

**Action:** Make domain engine's employment check match legacy OR get business decision to change legacy behavior.

**Risk:** HIGH
**Prerequisite:** Business decision
**Rollback:** Keep both engines until verified

### Step 4: Implement Policy Block Evaluation in Domain

**Files:**
- New domain rule or engine method for `check_in_blocked` / `check_out_blocked`
- OR extend `AttendanceStateRule` to accept policy configuration

**Risk:** MEDIUM
**Prerequisite:** Step 3
**Rollback:** Keep legacy PolicyEvaluator until verified

### Step 5: Update Action Boundary

**Files:**
- `app/Actions/Attendance/CheckInEmployee.php`
- `app/Actions/Attendance/CheckOutEmployee.php`

**Action:** Change constructor to inject `Domain\Attendance\Engines\AttendanceEngine`. Add exception handling to convert domain exceptions to the legacy array format expected by the controller.

**Risk:** HIGH
**Prerequisite:** Steps 1-4
**Rollback:** Revert constructor injection

### Step 6: Switch Production Dependency

**Files:**
- `app/Actions/Attendance/CheckInEmployee.php`
- `app/Actions/Attendance/CheckOutEmployee.php`

**Action:** Change `use App\Services\Attendance\AttendanceEngine` to `use App\Domain\Attendance\Engines\AttendanceEngine`.

**Risk:** HIGH
**Prerequisite:** Step 5 passing all tests
**Rollback:** Revert use statement

### Step 7: Run Full Regression

**Command:** `php artisan test`

**Risk:** MEDIUM
**Prerequisite:** Step 6
**Rollback:** Revert to Step 5

### Step 8: Remove Legacy Services

**Files to delete:**
- `app/Services/Attendance/AttendanceEngine.php`
- `app/Services/Attendance/GeofenceService.php`
- `app/Services/Attendance/ScheduleResolver.php`
- `app/Services/Attendance/PolicyEvaluator.php`
- `tests/Feature/Attendance/AttendanceDirectActionTest.php` (rewrite for domain engine)

**Risk:** MEDIUM
**Prerequisite:** Step 7 all green
**Rollback:** Git revert

### Step 9: Final Structural Audit

Verify:
- No remaining references to legacy services.
- All attendance tests pass.
- Pint passes.
- No dead code.

---

## 29. Future Delete List

### Safe After Migration

| File | Condition |
|------|-----------|
| `app/Services/Attendance/AttendanceEngine.php` | After Step 8 |
| `app/Services/Attendance/GeofenceService.php` | After Step 8 |
| `app/Services/Attendance/ScheduleResolver.php` | After Step 8 |
| `app/Services/Attendance/PolicyEvaluator.php` | After Step 8 |
| `tests/Feature/Attendance/AttendanceDirectActionTest.php` | After rewrite for domain engine |

### Requires Migration

| File | Reason |
|------|--------|
| `app/Actions/Attendance/CheckInEmployee.php` | Must be updated to use domain engine |
| `app/Actions/Attendance/CheckOutEmployee.php` | Must be updated to use domain engine |
| `app/Domain/Attendance/Engines/AttendanceEngine.php` | Must be extended for face verification, transactions, audit |
| `app/Domain/Attendance/DTOs/AttendanceOperationData.php` | May need additional fields |
| `app/Domain/Attendance/DTOs/AttendanceResultData.php` | May need geofence/policy/error fields |

### Manual Decision

| File | Reason |
|------|--------|
| `app/Domain/Attendance/Exceptions/AttendanceStateConflictException.php` | Already deleted in Phase 1 |
| `app/Domain/Attendance/Exceptions/DuplicateAttendanceRequestException.php` | Already deleted in Phase 1 |
| `app/Domain/Attendance/Exceptions/AttendanceAlreadyCheckedInException.php` | Domain-only, but action boundary needs design |
| Employment status business rule | Must be decided before migration |

---

## 30. Known Limitations

1. **Face verification gap:** The domain engine does not implement face verification. This audit cannot determine whether it should be added to the engine or remain in the action layer without a business/architecture decision.

2. **Employment status semantics:** The difference between `employment_status` enum check and `end_date` date check is a business rule mismatch that requires explicit decision.

3. **Policy block evaluation:** The domain engine does not evaluate `check_in_blocked` / `check_out_blocked` configuration flags. These are currently evaluated in the legacy `PolicyEvaluator`.

4. **Transaction placement:** The domain engine currently has no transaction boundaries. Where transactions should live (engine vs action) is an architectural decision.

5. **Audit placement:** The domain engine does not create audit logs. Whether audit belongs in the engine or action layer needs clarification.

6. **Grace period:** `LateDetectionRule` supports `graceMinutes` parameter, but the legacy engine and domain engine do not currently read grace period from policy configuration.

7. **Multiple shifts:** Legacy `ScheduleResolver` always returns `shifts->first()`. If schedules have multiple shifts per day, only the first is used. This is preserved in both implementations but may be a latent bug.

8. **Policy/schedule ambiguity:** Legacy silently resolves overlapping assignments. Domain throws. Data quality must be verified before migration.

---

## 31. Verification Results

### Git Status

```
Branch: feat/monthly-recap-impls
HEAD: 69a389f
Conflict markers: 0
```

### Test Suite Baseline

```
Tests: 289 passed, 0 failed
Assertions: 722
Duration: 10,535 ms
```

### Reference Searches

- `CheckInRequest` — 0 references to root file (deleted in Phase 1), canonical `Attendance/CheckInRequest` used by `AttendanceController`
- `CheckOutRequest` — 0 references to root file (deleted in Phase 1), canonical `Attendance/CheckOutRequest` used by `AttendanceController`
- `AttendanceResource` — 0 references to root file (deleted in Phase 1), canonical `Attendance/AttendanceResource` used by `AttendanceController`
- `AttendanceSessionResource` — 0 references to root file (deleted in Phase 1), canonical used by canonical resource
- `AttendanceStateConflictException` — 0 references (deleted in Phase 1)
- `DuplicateAttendanceRequestException` — 0 references (deleted in Phase 1)
- `Services/Attendance/AttendanceEngine` — referenced by `CheckInEmployee`, `CheckOutEmployee`, and `AttendanceDirectActionTest`
- `Domain/Attendance/Engines/AttendanceEngine` — 0 production references

---

## 32. Final Verdict

```text
PHASE 2A — BLOCKED
```

The repository contains sufficient information to design Phase 2B, but direct migration is unsafe due to:

1. **Employment status semantic mismatch** (HIGH RISK — requires business decision)
2. **Policy block evaluation missing in domain** (HIGH RISK — requires implementation)
3. **Face verification not in domain engine** (HIGH RISK — requires design decision)
4. **Transaction ownership undefined** (HIGH RISK — requires architectural decision)
5. **Policy/schedule ambiguity handling** (HIGH RISK — data quality unknown)
6. **Missing domain integration tests** (HIGH RISK — cannot verify regression safety)

Phase 2B must produce a file-by-file migration plan that addresses each gap before implementation begins.
