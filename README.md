<p align="center">
<img src="backend-laravel/public/assets/mito-white.png" width="280" alt="MITO Electronic">
</p>

# Attendance MITO Group

Attendance MITO Group is an API-first employee attendance and HR attendance management system for approximately 500-1,000 employees.

The system is designed around reliable attendance decisions, server-side business rules, auditability, security, and maintainable domain boundaries.

## Core Capabilities

- Employee attendance and attendance history
- Check-in and check-out with multiple sessions
- GPS accuracy and geofence validation
- Face verification and liveness verification
- Work schedules, shifts, holidays, and attendance policies
- Leave, permission, overtime, and penalty management
- Monthly attendance recap and reporting
- Authorization, audit logging, and API-first integration

## Architecture

```text
Vue 3 SPA + TypeScript + Vite + PWA
            |
            | REST / JSON
            v
      Laravel 13 API
      |-- Authentication and Sanctum
      |-- Authorization and RBAC
      |-- Business rules and domain engines
      |-- Validation, transactions, and audit
            |
      +-------+--------+
      v                v
PostgreSQL         FastAPI
+ PostGIS          Python
      |            Face / CV / Liveness
      v
    Redis
 Cache / Queue / Lock / Rate Limit
```

### Component Authority

| Component            | Responsibility                                                                                          |
| -------------------- | ------------------------------------------------------------------------------------------------------- |
| Vue 3                | UI, UX, client state, API consumption, PWA, and usability validation                                    |
| Laravel 13           | Authentication, authorization, business rules, domain decisions, transactions, API responses, and audit |
| PostgreSQL + PostGIS | Persistent system of record, relational integrity, aggregation, and geospatial operations               |
| Redis                | Cache, queues, locks, rate limiting, and temporary state                                                |
| FastAPI / Python     | Face verification, liveness, computer vision, and AI inference facts                                    |

Laravel is the final business authority. FastAPI returns AI facts and must not make attendance, leave, authorization, or other HR decisions.

## Repository Structure

```text
.
|-- frontend/          Vue 3 SPA
|-- backend-laravel/   Laravel 13 API
|-- ai-service/        FastAPI AI/CV service
|-- redis-php/         Redis source and documentation
|-- AGENTS.md          Agent and architecture rules
|-- ARCHITECTURE.md    Detailed architecture
|-- API-CONTRACT.md    API contract
|-- CHECKLIST.md       Project completion checklist
|-- DECISIONS.md       Architecture decision records
|-- DEVELOPMENT.md     Local development guide
`-- PROJECT.md         Project requirements and scope
```

## Prerequisites

- PHP 8.3+
- Composer
- Node.js and npm
- PostgreSQL 17
- PostGIS installed on the PostgreSQL server
- Redis
- Python 3.11 or 3.12
- Git

## Local Services

| Service    | Default URL or Port     |
| ---------- | ----------------------- |
| Vue        | `http://localhost:5173` |
| Laravel    | `http://localhost:8000` |
| FastAPI    | `http://localhost:8001` |
| PostgreSQL | `localhost:5432`        |
| Redis      | `localhost:6379`        |

## Setup

### 1. Backend

```powershell
Set-Location backend-laravel
composer install
Copy-Item .env.example .env
php artisan key:generate
```

Configure the Laravel environment with PostgreSQL and Redis:

```dotenv
APP_ENV=local
APP_DEBUG=true

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=attendance_mito
DB_USERNAME=postgres
DB_PASSWORD=your_password

REDIS_HOST=127.0.0.1
REDIS_PORT=6379
```

Create the database, enable PostGIS, and run migrations:

```sql
CREATE DATABASE attendance_mito;

-- Run while connected to the attendance_mito database.
CREATE EXTENSION IF NOT EXISTS postgis;
```

```powershell
php artisan migrate
php artisan serve
```

PostGIS must be installed at the PostgreSQL server level before `CREATE EXTENSION` can work.

### 2. Frontend

```powershell
Set-Location frontend
npm install
npm run dev
```

Build the frontend with:

```powershell
npm run build
```

### 3. FastAPI AI Service

```powershell
Set-Location ai-service
python -m venv .venv
.\.venv\Scripts\Activate.ps1
pip install -r requirements.txt
uvicorn app.main:app --reload --port 8001
```

FastAPI is an internal specialized service for AI/CV workloads only.

### 4. Redis

Start Redis on port `6379` and verify connectivity:

```powershell
redis-cli -h 127.0.0.1 -p 6379 ping
```

The expected response is `PONG`.

## Recommended Startup Order

1. PostgreSQL
2. Redis
3. FastAPI
4. Laravel
5. Laravel queue worker
6. Vue

Run a queue worker from `backend-laravel` when queue-backed work is required:

```powershell
php artisan queue:work
```

## API

The application API uses the `/api/v1` namespace, JSON responses, Laravel Sanctum authentication, server-side pagination, filtering, searching, and sorting.

Example endpoints:

```text
POST /api/v1/attendance/check-in
POST /api/v1/attendance/check-out
GET  /api/v1/attendance
GET  /api/v1/leave/types
GET  /api/v1/leave/balances
GET  /api/v1/leave/requests
GET  /api/v1/monthly-recaps
```

The client proposes input. Laravel validates and decides. PostgreSQL persists the result.

## Business Rules

- Vue provides usability validation only; it is not the business authority.
- Laravel calculates attendance status, late minutes, leave eligibility, balances, overtime, penalties, and authorization decisions.
- PostgreSQL is the source of truth for application data.
- Redis is not an authoritative store for attendance or HR records.
- FastAPI returns face verification and liveness facts; Laravel makes the final decision.
- Attendance supports multiple IN/OUT sessions.
- An open IN session is incomplete; it is not automatically absence.
- Finalized monthly recaps cannot be silently edited.
- Critical operations must be transactional, auditable, and protected against duplicate requests or concurrency issues.

## Testing and Quality Checks

Backend tests:

```powershell
Set-Location backend-laravel
php artisan test
```

Useful development commands:

```powershell
php artisan optimize:clear
php artisan route:list
php artisan migrate:status
```

Format Laravel code with Pint when available:

```powershell
./vendor/bin/pint
```

Critical business rules require automated tests, including authentication, authorization, attendance, geofence, leave, overtime, penalty, monthly recap, AI integration, and concurrency/idempotency behavior.

## Security Rules

- Never commit `.env`, credentials, private keys, service account keys, or AI secrets.
- Enforce authentication, authorization, and validation on the backend.
- Do not trust client-provided status, approval, totals, balances, or employee identity.
- Do not log credentials, access tokens, raw biometric payloads, or face embeddings.
- Do not expose stack traces or internal implementation details in production API responses.
- Use HTTPS and secure infrastructure configuration in production.

## Documentation

- [Project requirements](PROJECT.md)
- [Architecture](ARCHITECTURE.md)
- [API contract](API-CONTRACT.md)
- [Development guide](DEVELOPMENT.md)
- [Architecture decisions](DECISIONS.md)
- [Project checklist](CHECKLIST.md)
- [Agent rules](AGENTS.md)

## Scope

The initial scope covers employee attendance and related HR attendance workflows. Payroll processing, full accounting, recruitment, full ERP functionality, Kubernetes, and unnecessary microservices are out of scope unless explicitly approved.
