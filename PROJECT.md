# Attendance MITO Group

## 1. Overview

Attendance MITO Group is a web-based employee attendance and attendance-management system.

The system is designed for approximately 500–1,000 employees.

The application handles:

- Employee attendance
- Check-in
- Check-out
- GPS location
- Geofencing
- Face verification
- Liveness verification
- Work schedules
- Attendance policies
- Leave
- Permission
- Overtime
- Penalties
- Monthly attendance recap
- Reporting
- Audit history

---

## 2. Main Objectives

The system must provide:

1. Reliable employee attendance recording.

2. Accurate attendance business rules.

3. Location-aware attendance validation.

4. Face verification integration.

5. Centralized HR business logic.

6. Strong authorization.

7. Complete auditability.

8. Efficient operation for 500–1,000 employees.

9. API-first architecture.

10. Maintainable code suitable for long-term development.

---

## 3. Technology Stack

## Frontend

```text
Vue 3
TypeScript
Vite
Vue Router
Pinia
PWA
```

Frontend communicates with Laravel through REST/JSON APIs.

Backend
Laravel 13
PHP 8.3+
Laravel Sanctum
Spatie Laravel Permission

Laravel is the main backend and business authority.

Database
PostgreSQL 17
PostGIS

PostgreSQL is the system of record.

PostGIS provides spatial functionality.

Infrastructure
Redis

Redis provides:

Cache
Queue
Locks
Rate limiting
Temporary state
AI / Computer Vision
Python
FastAPI
OpenCV
NumPy
AI/CV model runtime

The exact face/liveness model is an implementation detail and must not change the overall architecture.

## 4. System Components

```text
Vue SPA
│
▼
Laravel API
│
├── PostgreSQL/PostGIS
│
├── Redis
│
└── FastAPI
│
└── AI/CV
```

## 5. User Roles

Initial roles:

SUPER_ADMIN
ADMIN
USER

The permission system should remain dynamic.

Roles determine access through permissions.

Backend authorization is authoritative.

## 6. Core Modules

### 6.1 Authentication

Responsibilities:

Login
Logout
Session management
Authentication state
Password management
Security controls

Laravel Sanctum is used for first-party SPA authentication.

### 6.2 Employee Management

Employee data includes core information such as:

Employee ID
Full name
Branch
Division
Department
Job position
Job level
Grade
Join date
Employment status
Direct superior
Indirect superior
Contact information
Other HR information

Employee status may include:

Permanent
Contract
Probation
Outsource

## 7. Policy Management

Policies determine attendance behavior.

Examples:

Work location rules
Geofence rules
Working hours
Late tolerance
Early checkout tolerance
Overtime rules
Attendance exceptions
Leave rules

Policies must be centralized.

Do not duplicate policy calculations in Vue.

## 8. Schedule Management

Schedules determine when an employee is expected to work.

Schedule information may include:

Work date
Shift
Start time
End time
Break
Working day
Off day
Holiday

Schedule Engine must provide a consistent source of attendance expectations.

## 9. Attendance

Attendance supports:

Check-in
Check-out
Multiple sessions
GPS
Accuracy
Geofence
Face verification
Liveness
Attendance status
Late detection
Early checkout
Incomplete sessions

Attendance is the central operational module.

## 10. Attendance Sessions

Employees may have multiple attendance sessions during one working day.

Example:

08:00 IN
12:00 OUT

13:00 IN
17:00 OUT

These should be represented as separate sessions and associated with the daily attendance record.

## 11. Leave

Leave supports:

Annual leave
Special leave
Leave request
Approval
Balance
Transactions
Expiry

Annual leave eligibility:

Join Date + 6 months

Annual quota expires after 12 months.

Special leave does not deduct annual leave unless configured otherwise.

## 12. Permission

Permission requests support situations where an employee cannot follow the standard attendance process.

Examples:

Late arrival permission
Early checkout permission
Personal permission
Business-related permission

Permission approval must be auditable.

## 13. Overtime

Overtime supports:

Overtime detection
Qualification
Rounding
Limits
Request
Approval
Final approved overtime

Detected overtime is not automatically approved overtime.

## 14. Penalty

Penalty handles attendance violations.

Examples:

Late arrival
Early checkout
Missing attendance
Policy violations

The exact rules must be configurable through the policy/rule system.

Penalty history must remain auditable.

## 15. Monthly Recap

Monthly recap summarizes:

Attendance
Present days
Absent days
Leave
Permission
Overtime
Penalty

Lifecycle:

DRAFT
REVIEW
FINALIZED
EXPORTED

Finalized data is locked for normal modification.

## 16. Face Verification

Face verification is an AI-assisted attendance security mechanism.

FastAPI provides:

Face verification result
Confidence
Liveness result
Model version

Laravel decides whether attendance is accepted.

## 17. Geofencing

Work locations are stored as geospatial data.

PostGIS handles:

Distance calculation
Polygon validation
Spatial queries

Python is not responsible for normal geofence calculations.

## 18. Audit

Critical actions must be auditable.

Audit records may include:

Actor
Action
Entity
Old value
New value
Timestamp
IP address
Request context

Never store secrets or unnecessary biometric information in audit logs.

## 19. Non-Functional Requirements

Security

The system must enforce:

Authentication
Authorization
Permission checks
Input validation
Rate limiting where required
Secure session handling
Auditability
Secret management
Performance

The system must support approximately:

500–1,000 employees

Important requirements:

Server-side pagination
Indexed queries
Efficient aggregation
Redis caching
Background queues
Avoid N+1 queries
Avoid loading unnecessary records
Reliability

Critical operations should use database transactions.

Examples:

Check-in
Check-out
Leave approval
Leave balance transaction
Overtime approval
Monthly recap finalization
Maintainability

The application should use:

Clear domain boundaries
Thin controllers
Reusable actions/services
Centralized business rules
Consistent API responses
Automated tests
Documentation

## 20. Out of Scope

Unless explicitly approved, do not add:

Payroll processing
Full accounting
Recruitment/ATS
Performance management
Full ERP functionality
Unnecessary microservices
Kubernetes
Event-driven architecture everywhere
Complex distributed infrastructure
Multiple frontend architectures

The system may integrate with payroll later, but payroll calculation is not the primary responsibility of this application.
