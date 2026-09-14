# Attendance MITO Group API Contract

## 1. Base URL

All application APIs use:

```text
/api/v1
```

Example:

```text
GET /api/v1/attendance
POST /api/v1/attendance/check-in
POST /api/v1/attendance/check-out
```

## 2. Protocol

API requirements:

- HTTPS
- REST
- JSON
- UTF-8

Authentication uses Laravel Sanctum.

## 3. Authentication

First-party SPA authentication:

```text
Vue
 ↓
Laravel Sanctum
 ↓
Authenticated Session
```

Protected endpoints require authentication.

## 4. Success Response

Recommended structure:

```json
{
    "data": {},
    "message": "Operation successful"
}
```

For collections:

```json
{
    "data": [],
    "meta": {},
    "links": {}
}
```

## 5. Error Response

Use a consistent structure:

```json
{
    "message": "Unable to process request.",
    "errors": {}
}
```

Validation example:

```json
{
    "message": "The given data was invalid.",
    "errors": {
        "latitude": [
            "The latitude field is required."
        ]
    }
}
```

## 6. HTTP Status Codes

- 200 OK
- 201 Created
- 204 No Content

- 400 Bad Request
- 401 Unauthorized
- 403 Forbidden
- 404 Not Found
- 409 Conflict
- 422 Unprocessable Entity
- 429 Too Many Requests

- 500 Internal Server Error
- 503 Service Unavailable

## 7. Pagination

Large collections must use server-side pagination.

Example:

```text
GET /api/v1/attendance?page=1&per_page=50
```

Recommended metadata:

```json
{
    "current_page": 1,
    "per_page": 50,
    "total": 1000,
    "last_page": 20
}
```

Do not return unlimited attendance history.

## 8. Filtering

Filtering is server-side.

Example:

```text
GET /api/v1/attendance
    ?employee_id=EMP001
    &status=present
    &date_from=2026-09-01
    &date_to=2026-09-30
```

## 9. Search

Search should be server-side.

Example:

```text
GET /api/v1/employees?search=john
```

Do not download all employees simply to search in Vue.

## 10. Sorting

Example:

```text
GET /api/v1/attendance?sort=-attendance_date
```

Allowed sort fields must be explicitly controlled.

Never directly inject user-provided sort strings into raw SQL.

## 11. Resources

Laravel API Resources should control response structure.

Examples:

- EmployeeResource
- AttendanceResource
- LeaveRequestResource
- OvertimeResource
- PenaltyResource
- MonthlyRecapResource

Do not return raw Eloquent models blindly.

## 12. Attendance API

Example:

```text
POST /api/v1/attendance/check-in
POST /api/v1/attendance/check-out
GET  /api/v1/attendance
GET  /api/v1/attendance/{attendance}
```

Check-in request may contain:

```json
{
    "latitude": -6.2,
    "longitude": 106.8,
    "accuracy": 12.5,
    "face_session_id": "..."
}
```

The client must not submit authoritative values such as:

```text
status=present
late_minutes=0
approved=true
```

Laravel calculates those values.

Face check-in/check-out is not yet implemented. Face verification endpoints are standalone:

```text
POST /api/v1/face/enroll
POST /api/v1/face/verify
```

See Section 18 (Internal FastAPI Contract) for details.

## 13. Attendance Validation

Laravel must validate:

- Authenticated user
- Active employee
- Schedule
- Attendance state
- GPS
- Accuracy
- Geofence
- Face verification
- Liveness
- Duplicate request
- Attendance policy

## 14. Leave API

Example endpoints:

```text
GET  /api/v1/leave/types
GET  /api/v1/leave/balance
GET  /api/v1/leave/requests
POST /api/v1/leave/requests
GET  /api/v1/leave/requests/{leave}
POST /api/v1/leave/requests/{leave}/approve
POST /api/v1/leave/requests/{leave}/reject
POST /api/v1/leave/requests/{leave}/cancel
```

Laravel calculates eligibility and balance.

The frontend must not calculate authoritative quota.

Auth: Sanctum session. Permissions: `leave.view` (types/balance/list/show),
`leave.create` (store), `leave.approve` (approve), `leave.reject` (reject),
`leave.cancel` (cancel; owners may cancel their own requests). Statuses:
201 on create, 200 on read/mutate, 401 unauthenticated, 403 unauthorized
(including USER approve), 404 for foreign requests (ownership-masked),
422 for validation/business-rule failures (overlap, ineligibility, insufficient
balance, self-approval).

## 15. Overtime API

Example:

```text
GET  /api/v1/overtime
POST /api/v1/overtime/requests
GET  /api/v1/overtime/{overtime}
POST /api/v1/overtime/{overtime}/approve
POST /api/v1/overtime/{overtime}/reject
```

Detected overtime and approved overtime must remain distinct.

## 16. Penalty API

Example:

```text
GET /api/v1/penalties
GET /api/v1/penalties/{penalty}
```

Administrative endpoints may include:

```text
POST /api/v1/penalties
POST /api/v1/penalties/{penalty}/adjust
```

All adjustments must be auditable.

## 17. Monthly Recap API

Example:

```text
GET /api/v1/monthly-recaps
GET /api/v1/monthly-recaps/{recap}
POST /api/v1/monthly-recaps/{recap}/review
POST /api/v1/monthly-recaps/{recap}/finalize
POST /api/v1/monthly-recaps/{recap}/export
```

Finalized recaps cannot be silently edited.

## 18. Internal FastAPI Contract

FastAPI is an internal AI/CV service consumed only by Laravel.
The browser never calls FastAPI directly.

### Authentication

FastAPI uses a shared API key for all protected endpoints, sent as the
`X-API-Key` header. The key is configured via the `AI_API_KEY` environment
variable (default: `dev-only-change-me` for local development only; never use
in production).

The `/health` endpoint is intentionally unauthenticated so Laravel's
`/api/v1/ai-health` probe works without credentials.

### Endpoints

#### Health (unauthenticated)

```text
GET /health
```

Response:

```json
{
    "status": "ok",
    "service": "attendance-ai"
}
```

#### Face Enrollment

```text
POST /face/enroll
```

Request: `multipart/form-data`

| Field         | Type | Required | Description                          |
|---------------|------|----------|--------------------------------------|
| `image`       | file | yes      | Face image (JPEG/PNG/WebP)           |
| `employee_id` | str  | yes      | Employee identifier (employee_code)  |

Response (200):

```json
{
    "enrolled": true,
    "model_version": "face-dev-v1",
    "embedding_reference": "abc123",
    "face_detected": true,
    "quality_score": 0.95
}
```

The actual embedding vector is retained internally by FastAPI and is never
returned to Laravel or the browser.

Errors:

- 401 — invalid or missing API key
- 400 — image validation failure (bad MIME, oversized, corrupt, bad dimensions)
- 422 — missing `image` or `employee_id` fields
- 500 — internal processing failure

#### Face Verification

```text
POST /face/verify
```

Request: `multipart/form-data`

| Field                | Type   | Required | Description                                    |
|----------------------|--------|----------|------------------------------------------------|
| `image`              | file   | yes      | Probe face image (JPEG/PNG/WebP)               |
| `employee_id`        | str    | yes      | Employee identifier to verify against          |
| `embedding_reference`| str    | no       | Opaque reference from enrollment               |

Response (200):

```json
{
    "verified": true,
    "confidence": 0.94,
    "liveness": false,
    "liveness_reason": "Liveness check is disabled (no production liveness model configured).",
    "face_detected": true,
    "model_version": "face-dev-v1",
    "processing_time_ms": 45,
    "quality_score": 0.88
}
```

`verified` is an AI fact: the probe face matched the enrolled embedding to
within the configured similarity threshold. It does NOT mean attendance is
accepted. Laravel combines this with liveness, face detection, geofence,
schedule, and policy to make the final business decision.

Errors:

- 401 — invalid or missing API key
- 400 — image validation failure
- 422 — missing `image` or `employee_id` fields
- 500 — internal processing failure

### Configuration

FastAPI settings are loaded from environment variables with the `AI_` prefix:

| Variable                | Default               | Description                          |
|-------------------------|-----------------------|--------------------------------------|
| `AI_API_KEY`            | `dev-only-change-me`  | Shared secret for Laravel requests   |
| `AI_MODEL_VERSION`      | `face-dev-v1`         | Returned with every AI result        |
| `AI_MAX_IMAGE_SIZE_BYTES`| `5000000`            | Max image upload size                |
| `AI_MAX_IMAGE_DIMENSION`| `4096`               | Max image width/height               |
| `AI_SIMILARITY_THRESHOLD`| `0.6`              | Dev adapter match threshold          |
| `AI_LIVENESS_MODE`      | `disabled`            | `disabled` or `dev`                  |

### Laravel → FastAPI Communication

Laravel calls FastAPI via `App\Services\Integration\FastApiService`.
The service sends the image as `multipart/form-data`, includes the `X-API-Key`
header, and applies the configured timeout (`FASTAPI_TIMEOUT`, default 5s).

FastAPI response failures are mapped to `FastApiStatus`:

- `Available` — successful response with valid JSON
- `Unavailable` — connection refused or unexpected error
- `Timeout` — connection timed out
- `InvalidResponse` — non-2xx or non-array JSON

Laravel never treats a FastAPI failure as a successful verification.

### Development Adapter Status

The current face recognition implementation is a **DEVELOPMENT ADAPTER**, NOT
production biometric recognition:

- Face detection: pixel variance heuristic (NOT a Haar/CNN detector)
- Embeddings: 64-dimensional perceptual hash via Pillow (NOT a face embedding model)
- Liveness: `disabled` mode returns `False` always; `dev` mode uses image variance
- No OpenCV, NumPy, or deep-learning frameworks installed
- Two genuinely different faces will never match

A production model (e.g., InsightFace, MediaPipe) should replace this boundary
in a dedicated biometric-hardening phase.

FastAPI must not decide:

- attendance accepted
- attendance rejected
- leave approved
- employee authorized

Those are Laravel decisions.

## 19. API Security

Requirements:

- HTTPS in production
- Sanctum authentication
- Authorization middleware
- Validation
- Rate limiting
- Request size limits
- Secure error handling
- Audit logging for critical actions

Never expose:

- Database credentials
- Internal service secrets
- AI model internals unnecessarily
- Stack traces
- Private keys

## 20. API Versioning

Current:

```text
/api/v1
```

Breaking changes require a new version.

Do not silently change an existing endpoint's semantics.

## 21. Idempotency

Critical operations should be protected against retries.

Especially:

- Check-in
- Check-out
- Leave approval
- Overtime approval
- Monthly recap finalization

Use appropriate combination of:

- Request IDs
- Unique constraints
- Database transactions
- Locks

## 22. API Authority

The fundamental rule:

```text
Client proposes.
Laravel validates.
Laravel decides.
PostgreSQL persists.
```

## 23. Infrastructure Health Endpoints

Health endpoints are unauthenticated and must not return sensitive
infrastructure details (no credentials, no stack traces, no internal network
information, no environment secrets).

### Application health

```text
GET /api/v1/health
```

Response:

```json
{
    "status": "ok",
    "service": "laravel-api"
}
```

### Laravel -> FastAPI communication health

```text
GET /api/v1/ai-health
```

Response when the AI service is available:

```json
{
    "laravel": "ok",
    "ai": {
        "status": "ok",
        "service": "attendance-ai"
    }
}
```

When the AI service is not available, the endpoint returns 503 with an
explicit failure state (`unavailable`, `timeout`, or `invalid_response`) and
never leaks internal exceptions:

```json
{
    "message": "AI service is not available.",
    "laravel": "ok",
    "ai_status": "unavailable"
}
```

### Deep infrastructure verification

Deep checks (PostgreSQL, PostGIS, Redis, cache round trip, queue connection,
AI service) are performed by the console command:

```text
php artisan infra:check
```

## 24. Authentication API

First-party SPA authentication uses Laravel Sanctum with session/cookie
based authentication. No JWT, bearer tokens, or custom token tables exist.

### CSRF initialization

```text
GET /sanctum/csrf-cookie
```

Handled by Sanctum. Must be called before the first mutating request of a
session. Do not wrap it under `/api/v1`.

### Login

```text
POST /api/v1/login
```

Request body:

```json
{
    "email": "user@example.com",
    "password": "..."
}
```

Response (200):

```json
{
    "success": true,
    "message": "Authenticated successfully.",
    "data": {
        "user": {
            "id": 1,
            "name": "Example User",
            "email": "user@example.com",
            "roles": ["USER"],
            "permissions": ["dashboard.view"]
        }
    }
}
```

Errors:

* 422 — validation errors (including invalid credentials; never 500).
* 419 — CSRF token missing/invalid (session initialization skipped).

Passwords, password hashes, remember tokens, and session identifiers are
never returned.

### Auth me

```text
GET /api/v1/auth/me
```

Requires authentication. Returns the same safe user payload as login.

Errors:

* 401 — unauthenticated.

### Logout

```text
POST /api/v1/logout
```

Requires authentication. Invalidates the session and regenerates the CSRF
token. Response (200):

```json
{
    "success": true,
    "message": "Logged out successfully."
}
```

## 25. Authorization

Backend authorization is authoritative. Permission-protected endpoints use
Laravel Gate abilities derived from Spatie permissions:

* SUPER_ADMIN bypasses permission checks centrally via `Gate::before`.
* Roles ADMIN and USER require explicitly assigned permissions
  (`module.action` naming convention).
* Authorization failures return 403 with a clean JSON message; no stack
  traces or permission names are leaked.

Unauthenticated requests return 401; authenticated but unauthorized requests
return 403; validation failures return 422.
