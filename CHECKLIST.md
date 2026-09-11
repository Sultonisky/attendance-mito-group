# Attendance MITO Group - Full Project Checklist

This checklist is project-wide and is not organized as implementation phases.

---

## 1. Architecture

- [ ] Vue 3 SPA is the only primary frontend architecture.
- [ ] Laravel 13 is the main backend.
- [ ] PostgreSQL is the source of truth.
- [ ] PostGIS is used for geospatial processing.
- [ ] Redis is used for infrastructure.
- [ ] FastAPI is limited to AI/CV.
- [ ] No unnecessary microservices exist.
- [ ] Controllers remain thin.
- [ ] Business logic is not duplicated across layers.

---

## 2. Authentication

- [ ] Laravel Sanctum configured.
- [ ] Login works.
- [ ] Logout works.
- [ ] Session handling works.
- [ ] Unauthenticated requests return 401.
- [ ] Authentication state is correctly handled by Vue.
- [ ] CSRF/session requirements are correctly configured.

---

## 3. Authorization

- [ ] SUPER_ADMIN role exists.
- [ ] ADMIN role exists.
- [ ] USER role exists.
- [ ] Permissions are dynamic.
- [ ] Permission middleware exists.
- [ ] Policies/Gates are implemented.
- [ ] Backend authorization is authoritative.
- [ ] Unauthorized API requests return 403.
- [ ] Frontend only uses permissions for UX.

---

## 4. Employee

- [ ] Employee CRUD works.
- [ ] Employee ID is unique.
- [ ] Employee status is validated.
- [ ] Employment lifecycle is supported.
- [ ] Search is server-side.
- [ ] Filtering is server-side.
- [ ] Pagination is server-side.
- [ ] Sensitive employee data is protected.

---

## 5. Policy

- [ ] Policies are persisted.
- [ ] Policy assignments are supported.
- [ ] Policy rules are centralized.
- [ ] Effective policy resolution works.
- [ ] Policy changes are auditable.
- [ ] Vue does not contain authoritative policy logic.

---

## 6. Schedule

- [ ] Work schedules are persisted.
- [ ] Shifts are supported.
- [ ] Working days are supported.
- [ ] Off days are supported.
- [ ] Holidays are supported.
- [ ] Schedule resolution is centralized.
- [ ] Schedule conflicts are handled.

---

## 7. Attendance

- [ ] Check-in works.
- [ ] Check-out works.
- [ ] Multiple sessions are supported.
- [ ] Duplicate check-in is prevented.
- [ ] Duplicate check-out is prevented.
- [ ] Missing OUT becomes incomplete/open session.
- [ ] Attendance status is calculated server-side.
- [ ] Late detection works.
- [ ] Early checkout detection works.
- [ ] Attendance events are recorded.
- [ ] Attendance history is auditable.

---

## 8. GPS / Geofence

- [ ] Latitude is validated.
- [ ] Longitude is validated.
- [ ] GPS accuracy is validated.
- [ ] Work locations support geospatial data.
- [ ] PostGIS is installed.
- [ ] PostGIS extension is enabled.
- [ ] Spatial indexes exist where required.
- [ ] Geofence is validated server-side.
- [ ] GPS cannot be trusted solely from frontend validation.

---

## 9. Face Verification

- [ ] FastAPI service exists.
- [ ] Laravel can communicate with FastAPI.
- [ ] Face verification endpoint works.
- [ ] Liveness result is returned.
- [ ] Confidence is returned.
- [ ] Model version is returned.
- [ ] Laravel performs final decision.
- [ ] AI failures are handled gracefully.
- [ ] Timeouts are configured.
- [ ] Sensitive biometric data is not unnecessarily logged.

---

## 10. Attendance Security

- [ ] Authentication required.
- [ ] Employee identity validated.
- [ ] Schedule validated.
- [ ] GPS validated.
- [ ] Geofence validated.
- [ ] Face verification validated.
- [ ] Liveness validated.
- [ ] Duplicate request protection exists.
- [ ] Concurrency is handled.
- [ ] Critical operations are transactional.
- [ ] Attendance actions are audited.

---

## 11. Leave

- [ ] Leave types exist.
- [ ] Annual leave exists.
- [ ] Special leave exists.
- [ ] Leave eligibility is calculated server-side.
- [ ] 6-month eligibility rule works.
- [ ] Annual quota accrual works.
- [ ] 12-month expiry works.
- [ ] Leave balances exist.
- [ ] Leave transactions exist.
- [ ] FIFO quota consumption works where applicable.
- [ ] Special leave does not deduct annual leave unless configured.
- [ ] Leave approval is audited.
- [ ] Leave cancellation is handled correctly.

---

## 12. Permission

- [ ] Permission request exists.
- [ ] Permission approval exists.
- [ ] Permission rejection exists.
- [ ] Permission cancellation exists.
- [ ] Permission affects attendance according to policy.
- [ ] Approval is auditable.

---

## 13. Overtime

- [ ] Overtime detection works.
- [ ] Overtime qualification works.
- [ ] Rounding rules work.
- [ ] Overtime limits work.
- [ ] Overtime request works.
- [ ] Overtime approval works.
- [ ] Approved overtime is separate from detected overtime.
- [ ] Overtime history is auditable.

---

## 14. Penalty

- [ ] Penalty rules exist.
- [ ] Violations are detected.
- [ ] Frequency rules work.
- [ ] Points/adjustments work.
- [ ] Original penalty is preserved.
- [ ] Adjustments are preserved.
- [ ] Final penalty is auditable.

---

## 15. Monthly Recap

- [ ] Monthly attendance aggregation works.
- [ ] Leave aggregation works.
- [ ] Permission aggregation works.
- [ ] Overtime aggregation works.
- [ ] Penalty aggregation works.
- [ ] Monthly snapshot exists.
- [ ] DRAFT status works.
- [ ] REVIEW status works.
- [ ] FINALIZED status works.
- [ ] EXPORTED status works.
- [ ] Finalized recap cannot be silently edited.
- [ ] Corrections are auditable.

---

## 16. API

- [ ] `/api/v1` namespace is used.
- [ ] JSON responses are consistent.
- [ ] Error responses are consistent.
- [ ] Validation responses are consistent.
- [ ] HTTP status codes are correct.
- [ ] Pagination works.
- [ ] Filtering works.
- [ ] Search works.
- [ ] Sorting works.
- [ ] Resources are used.
- [ ] API authorization is enforced.
- [ ] Breaking changes use versioning.
- [ ] Idempotency exists for critical operations.

---

## 17. Database

- [ ] PostgreSQL configured.
- [ ] PostGIS installed.
- [ ] PostGIS enabled.
- [ ] Foreign keys exist.
- [ ] Unique constraints exist where required.
- [ ] Important indexes exist.
- [ ] Spatial indexes exist.
- [ ] Transactions protect critical writes.
- [ ] N+1 queries are avoided.
- [ ] Large datasets are paginated.
- [ ] Historical data is preserved.

---

## 18. Redis

- [ ] Redis connection works.
- [ ] Cache works.
- [ ] Queue works.
- [ ] Queue worker works.
- [ ] Failed jobs can be inspected.
- [ ] Locks are used where required.
- [ ] Rate limiting works where required.
- [ ] Redis is not used as the authoritative database.

---

## 19. Frontend

- [ ] Vue Router configured.
- [ ] Pinia configured.
- [ ] API client is centralized.
- [ ] Authentication state is centralized.
- [ ] Permission-aware UI exists.
- [ ] Loading states exist.
- [ ] Error states exist.
- [ ] Empty states exist.
- [ ] Forms have validation.
- [ ] API errors are displayed correctly.
- [ ] Mobile UX works.
- [ ] PWA behavior works.
- [ ] Camera permissions are handled.
- [ ] Location permissions are handled.

---

## 20. Performance

- [ ] Server-side pagination.
- [ ] Server-side filtering.
- [ ] Server-side sorting.
- [ ] Server-side search.
- [ ] Database indexes reviewed.
- [ ] N+1 queries eliminated.
- [ ] Dashboard queries optimized.
- [ ] Heavy processing moved to queues.
- [ ] Redis cache used where appropriate.
- [ ] Browser does not load all attendance records.
- [ ] Monthly recap avoids unnecessary full-history recalculation.

---

## 21. Security

- [ ] Production APP_DEBUG=false.
- [ ] Secrets are not committed.
- [ ] `.env` is ignored.
- [ ] Authorization enforced server-side.
- [ ] Validation enforced server-side.
- [ ] Rate limiting exists where required.
- [ ] Sensitive data is protected.
- [ ] Credentials are never logged.
- [ ] Tokens are never logged.
- [ ] Raw biometric data is not logged.
- [ ] SQL injection risks are controlled.
- [ ] File upload validation exists where applicable.
- [ ] API error messages do not expose internals.

---

## 22. Audit

- [ ] Critical actions are logged.
- [ ] Actor is recorded.
- [ ] Entity is recorded.
- [ ] Action is recorded.
- [ ] Timestamp is recorded.
- [ ] Old value is recorded when appropriate.
- [ ] New value is recorded when appropriate.
- [ ] IP/request context is recorded where appropriate.
- [ ] Secrets are excluded.
- [ ] Biometric data is excluded.

---

## 23. Testing

- [ ] Authentication tests.
- [ ] Authorization tests.
- [ ] Employee tests.
- [ ] Policy tests.
- [ ] Schedule tests.
- [ ] Attendance tests.
- [ ] Geofence tests.
- [ ] Leave tests.
- [ ] Overtime tests.
- [ ] Penalty tests.
- [ ] Monthly recap tests.
- [ ] AI integration tests.
- [ ] API feature tests.
- [ ] Concurrency/idempotency tests for critical operations.

---

## 23.1 Test Database Strategy

- [x] Default `php artisan test` uses SQLite memory
- [x] Automated tests never mutate development PostgreSQL
- [x] PostgreSQL integration tests use dedicated test DB
- [x] PostGIS integration tests remain available
- [x] Development runtime remains PostgreSQL/PostGIS

---

## 23.2 Phase 5 — Core Domain Foundation

- [x] Action/Application Service convention established
- [x] DTO convention established (immutable, explicit boundaries)
- [x] Domain exception base class exists
- [x] Specific domain exceptions created (InvalidStateException, InactiveEmployeeException)
- [x] Audit foundation implemented (RecordAuditAction + AuditRecordData)
- [x] Transaction boundary convention documented and implemented in Actions
- [x] Phase 5 tests cover enums, DTOs, exceptions, Actions, and audit
- [x] No Phase 6+ business logic implemented
- [x] Documentation updated

---

## 24. Dependency Management

- [ ] Every package has a clear purpose.
- [ ] Laravel 13 compatibility verified.
- [ ] PHP compatibility verified.
- [ ] No unnecessary packages.
- [ ] No `--ignore-platform-reqs`.
- [ ] PHPUnit baseline remains compatible.
- [ ] Pest is not forced into PHP 8.3/Pest 5 incompatibility.

---

## 25. Production

- [ ] Production environment configured.
- [ ] APP_DEBUG=false.
- [ ] HTTPS enabled.
- [ ] PostgreSQL secured.
- [ ] Redis secured.
- [ ] FastAPI internal endpoint secured.
- [ ] Queue worker configured.
- [ ] Scheduler configured where needed.
- [ ] Logs configured.
- [ ] Monitoring configured.
- [ ] Backups configured.
- [ ] Database restore procedure tested.
- [ ] Health checks exist.
- [ ] Deployment rollback strategy exists.

---

## 26. AI / Vibe Coding Completion Gate

Before considering a feature complete:

- [ ] Agent read AGENTS.md.
- [ ] Existing implementation was inspected.
- [ ] No duplicate business logic introduced.
- [ ] No unnecessary dependency added.
- [ ] No unauthorized schema change.
- [ ] API contract remains valid.
- [ ] Backend authorization tested.
- [ ] Business rules tested.
- [ ] Relevant tests pass.
- [ ] Code formatting passes.
- [ ] Database queries reviewed.
- [ ] Security implications reviewed.
- [ ] Error handling reviewed.
- [ ] Diff reviewed.
- [ ] Documentation updated if architecture/API changed.

---

## 27. FINAL COMPLETION GATE

The project should only be considered production-ready when:

- [ ] Architecture
- [ ] Authentication
- [ ] Authorization
- [ ] Employee
- [ ] Policy
- [ ] Schedule
- [ ] Attendance
- [ ] GPS / PostGIS
- [ ] Face / Liveness
- [ ] Leave
- [ ] Permission
- [ ] Overtime
- [ ] Penalty
- [ ] Monthly Recap
- [ ] API
- [ ] Database
- [ ] Redis
- [ ] Frontend
- [ ] Performance
- [ ] Security
- [ ] Audit
- [ ] Testing
- [ ] Production

**Final rule:** A feature is not complete merely because the UI works. It is complete when the UI, API, business rules, persistence, authorization, error handling, auditability, tests, and production behavior are all correct.

