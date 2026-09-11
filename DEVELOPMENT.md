# Attendance MITO Group Development Guide

## 1. Local Architecture

Recommended local services:

```text
Vue
  http://localhost:5173

Laravel
  http://localhost:8000

FastAPI
  http://localhost:8001

PostgreSQL
  localhost:5433

Redis
  localhost:6379
```

Ports may be changed if required, but documentation and environment configuration must remain consistent.

## 2. Prerequisites

Required:

PHP 8.3+
Composer
Node.js
npm
PostgreSQL 17
PostGIS
Redis
Python 3.11/3.12
Git

Recommended:

VS Code / compatible IDE
Postman / Insomnia
pgAdmin or psql

## 3. Backend Setup
cd backend
composer install

Create environment:

cp .env.example .env

Windows PowerShell alternative:

Copy-Item .env.example .env

Generate application key:

php artisan key:generate

Configure:

APP_ENV=local
APP_DEBUG=true

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5433
DB_DATABASE=attendance_db
DB_USERNAME=postgres
DB_PASSWORD=your_password

REDIS_HOST=127.0.0.1
REDIS_PORT=6379
## 4. PostgreSQL

Create database:

CREATE DATABASE attendance_db;

Connect:

psql -U postgres -d attendance_db
## 5. PostGIS

PostGIS must be installed at the PostgreSQL server/system level before enabling it.

If PostgreSQL was installed using the EDB Windows installer, install PostGIS through StackBuilder:

StackBuilder
 → Spatial Extensions
 → PostGIS

After installation, connect to the application database:

CREATE EXTENSION IF NOT EXISTS postgis;

Verify:

SELECT PostGIS_Version();

If this returns a version, PostGIS is available.

If PostgreSQL reports:

postgis.control: No such file or directory

the PostGIS extension is not installed on the PostgreSQL server.

Do not attempt to solve this only from Laravel migrations.

## 6. Redis

Redis must be running for local development runtime:

Cache
Queue
Locks
Rate limiting

Verify connectivity from Laravel after environment configuration.

Note: The default `php artisan test` suite uses SQLite :memory: with array/sync drivers and does NOT require a running Redis server.

## 7. Laravel Commands

Install dependencies:

composer install

Run migrations:

php artisan migrate

Run development server:

php artisan serve

Run queue worker:

php artisan queue:work

Clear caches during development:

php artisan optimize:clear

Run tests:

php artisan test

Format code:

./vendor/bin/pint

Verify infrastructure (PostgreSQL, PostGIS, Redis, cache, queue, AI service):

php artisan infra:check

Dispatch and process a queue smoke-test job (requires QUEUE_CONNECTION=redis and a running worker-capable Redis connection):

php artisan infra:queue-test

## 7.1 Authentication Development Notes

Authentication uses Laravel Sanctum with first-party SPA session/cookie flow:

* CSRF initialization: GET /sanctum/csrf-cookie
* Login: POST /api/v1/login
* Current user: GET /api/v1/auth/me
* Logout: POST /api/v1/logout

Required environment values (see backend .env.example):

* SANCTUM_STATEFUL_DOMAINS must include the SPA origin
  (localhost:5173,127.0.0.1:5173 for local development).
* SESSION_DRIVER=redis, SESSION_DOMAIN=null, CORS allows the SPA origin with
  credentials.

RBAC uses spatie/laravel-permission with roles SUPER_ADMIN, ADMIN, and USER:

* SUPER_ADMIN bypasses permission checks centrally via Gate::before.
* ADMIN and USER require explicitly assigned permissions.
* Permissions use the module.action naming convention and are seeded by
  Database\Seeders\RolesAndPermissionsSeeder (idempotent).

Development-only credentials (local environment only, password: "password"):

* superadmin@example.com (SUPER_ADMIN)
* admin@example.com (ADMIN)
* user@example.com (USER)
* permless@example.com (no role, no permissions — used to verify denials)

These accounts exist only after running `php artisan db:seed` in a
non-production environment. Never reuse them in staging/production.

Route protection should prefer Gate abilities:

```php
Route::get('/example', ...)->middleware('can:employees.view');
```

so the centralized Super Admin bypass applies consistently.
## 8. Frontend Setup
cd frontend
npm install

Run development server:

npm run dev

Production build:

npm run build
## 9. FastAPI Setup

Create Python environment:

python -m venv .venv

Windows:

.\.venv\Scripts\Activate.ps1

Install dependencies:

pip install -r requirements.txt

Run:

uvicorn app.main:app --reload --port 8001

FastAPI must remain an internal specialized service.

## 10. Startup Order

Recommended:

## 1. PostgreSQL
## 2. Redis
## 3. FastAPI
## 4. Laravel
## 5. Laravel Queue Worker
## 6. Vue
## 11. Development Environment

Never commit:

.env
.env.production
private keys
credentials
service account keys
AI secrets

Use:

.env.example

for required environment variable documentation.

## 12. Testing

### Default Test Database — SQLite :memory:

The default automated test suite uses SQLite in-memory:

```bash
php artisan test
```

Configuration:

```env
DB_CONNECTION=sqlite
DB_DATABASE=:memory:
```

This provides fast execution without external database dependencies, CI-friendly isolation (no PostgreSQL required), and ensures no mutation of development data.

### Development Runtime Database — PostgreSQL + PostGIS

The Laravel application runtime uses PostgreSQL 17 + PostGIS:

```env
DB_CONNECTION=pgsql
DB_DATABASE=attendance_db
```

This is configured in `.env`, NOT in `.env.testing`.

### PostgreSQL/PostGIS Integration Tests

PostgreSQL-specific integration tests use a dedicated test database and configuration:

```bash
vendor/bin/phpunit -c phpunit.postgres.xml
```

This targets `attendance_mito_test`, NEVER `attendance_db`.

Required environment variables (not committed to source):

```powershell
$env:DB_USERNAME = 'postgres'
$env:DB_PASSWORD = 'your_password'
vendor/bin/phpunit -c phpunit.postgres.xml
```

### Why Separate Databases?

SQLite in-memory is chosen because it is fast, requires no PostgreSQL service, and is CI-friendly. PostgreSQL-specific behavior (PostGIS, geography, GIST indexes) is validated explicitly in a separate integration suite rather than silently skipped.

### Running Tests

```bash
# Default suite (SQLite, fast, portable)
php artisan test

# PostgreSQL integration suite (requires PostgreSQL + PostGIS)
vendor/bin/phpunit -c phpunit.postgres.xml

# Specific test filter
php artisan test --filter=Attendance
```

## 13. Phase 5 — Core Domain Foundation

### Action Convention

Controllers delegate to Actions. Actions own the transaction boundary when
an operation modifies multiple pieces of domain state.

```text
Controller -> Action -> Domain Engine/Rule -> Repository/Model
```

### DTO Convention

DTOs are immutable data carriers at domain boundaries. Use `final readonly class`
with typed constructor properties. DTOs must not contain persistence logic,
HTTP request objects, or Vue concerns.

### Domain Exception Convention

Domain exceptions extend `App\Exceptions\Domain\DomainException` and represent
business operations that cannot legally proceed. They are distinct from
programming errors and infrastructure failures.

Examples:
- `InvalidStateException` — invalid state transition
- `InactiveEmployeeException` — operation requires active employee

### Audit Convention

Audit entries are recorded through `App\Actions\Audit\RecordAuditAction`
with an `AuditRecordData` DTO. The action wraps persistence in a transaction.

### Time / Date

Use Laravel's date/time tooling consistently. Freeze time in tests via
`Carbon::setTestNow()`.

## 14. Dependency Compatibility

Current baseline:

PHP 8.3
Laravel 13

Important:

Pest 5 requires PHP 8.4+.

Therefore do not force Pest 5 into the current PHP 8.3 environment.

Use PHPUnit as the baseline unless the PHP runtime is upgraded and dependency compatibility is verified.

Never use:

--ignore-platform-reqs

to bypass dependency compatibility.

## 14. Debugging

When an issue occurs:

## 1. Reproduce
## 2. Identify layer
## 3. Trace request
## 4. Trace data
## 5. Identify root cause
## 6. Make minimal fix
## 7. Run relevant tests
## 8. Verify regression

Useful Laravel commands:

php artisan route:list
php artisan about
php artisan config:show database
php artisan queue:failed
php artisan migrate:status

Clear development caches:

php artisan optimize:clear
## 15. Database Debugging

Verify PostgreSQL:

psql -U postgres

Verify PostGIS:

SELECT PostGIS_Version();

Check database:

SELECT current_database();

Check extension:

SELECT extname FROM pg_extension;
## 16. Git Hygiene

Before committing:

git status
git diff

Do not commit:

.env
node_modules
vendor
.venv
runtime secrets
generated sensitive files

Commit focused changes.

Avoid giant unrelated commits.

## 17. AI/Vibe Coding Development

Before asking an AI agent to implement a feature:

Read AGENTS.md.
Read PROJECT.md.
Read relevant architecture section.
Identify affected module.
Identify existing implementation.
Define acceptance criteria.
Implement minimally.
Run tests.
Review diff.
## 18. Local Smoke Test

Verify:

Vue
 ↓
Laravel API
 ↓
PostgreSQL

Then:

Laravel
 ↓
FastAPI
 ↓
AI result

Then:

Laravel
 ↓
Redis
 ↓
Cache / Queue / Lock

Finally verify:

SELECT PostGIS_Version();
## 19. Production Preparation

Before deployment:

APP_ENV=production
APP_DEBUG=false

Use:

php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

Do not blindly clear individual caches if optimize:clear is already used appropriately.

## 20. Important Development Principle

Development speed must never override architecture.

Do not solve problems by:

Moving business logic to Vue
Bypassing Laravel authorization
Writing directly to PostgreSQL from frontend
Making FastAPI the main backend
Disabling validation
Ignoring dependency conflicts
Disabling tests
Adding unnecessary packages

