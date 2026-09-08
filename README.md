# Attendance MITO Group

Internal project documentation for the Attendance MITO Group attendance and HR management system.

This repository is intended for internal company use only and is not an open-source project.

## Purpose

This system supports:

- employee attendance tracking
- check-in and check-out workflows
- attendance policies and schedules
- leave, permission, overtime, and penalty management
- monthly recap and reporting
- auditability and authorization controls
- AI-assisted verification for face and liveness checks

## Architecture overview

```text
Vue 3 SPA
  �
  +-- REST / JSON API
  v
Laravel 13 API
  +-- Auth / RBAC
  +-- Validation
  +-- Business rules
  +-- Domain engines
  +-- Transactions
  +-- Audit
  �
  +-- PostgreSQL + PostGIS
  +-- Redis
  +-- FastAPI / Python AI service
```

## Repository layout

```text
.
+-- frontend/              Vue SPA
+-- backend-laravel/       Laravel API backend
+-- ai-service/            FastAPI AI service
+-- redis-php/             Redis-related local resources
+-- AGENTS.md              Agent and architecture rules
+-- ARCHITECTURE.md        Architecture reference
+-- API-CONTRACT.md        API contract
+-- CHECKLIST.md           Project checklist
+-- DECISIONS.md           Decision log
+-- DEVELOPMENT.md         Local development guide
+-- LICENSE                Internal proprietary license
+-- PROJECT.md             Project scope and requirements
+-- README.md              Internal project overview
+-- SECURITY.md            Internal security reporting policy
+-- .gitignore             Git ignore configuration
+-- .env.example           Example local env (if present)
+-- .env                   Local environment (not committed)
```

## Scope and rules

- This project is internal and confidential.
- No public OSS publication is intended.
- All changes must follow the repository architecture and business rules.
- The Laravel backend remains the final authority for business decisions.
- The frontend is UI-only and must not enforce business logic.
- FastAPI returns facts only; Laravel decides the business meaning.

## Local runtime stack

- PHP 8.3+
- Composer
- Node.js + npm
- PostgreSQL 17 + PostGIS
- Redis
- Python 3.11/3.12
- Laravel 13
- Vue 3 + Vite
- FastAPI

## Development entrypoints

### Backend

```powershell
Set-Location backend-laravel
composer install
php artisan migrate
php artisan serve
```

### Frontend

```powershell
Set-Location frontend
npm install
npm run dev
```

### AI service

```powershell
Set-Location ai-service
python -m venv .venv
.\.venv\Scripts\Activate.ps1
pip install -r requirements.txt
uvicorn app.main:app --reload --port 8001
```

### Redis validation

```powershell
redis-cli -h 127.0.0.1 -p 6379 ping
```

Expected response:

```text
PONG
```

## Security expectations

- Do not commit secrets, credentials, private keys, or environment values.
- Do not expose internal APIs publicly.
- Do not log biometric payloads, tokens, or private identity data.
- Keep backend validation and authorization server-side.
- Follow the rules in [SECURITY.md](SECURITY.md).

## Internal usage note

This project is for internal operational and engineering use within Attendance MITO Group. Any external publishing, distribution, or reuse requires explicit written authorization.

## Documentation

- [ARCHITECTURE.md](ARCHITECTURE.md)
- [API-CONTRACT.md](API-CONTRACT.md)
- [DEVELOPMENT.md](DEVELOPMENT.md)
- [PROJECT.md](PROJECT.md)
- [CHECKLIST.md](CHECKLIST.md)
- [DECISIONS.md](DECISIONS.md)
- [AGENTS.md](AGENTS.md)
- [SECURITY.md](SECURITY.md)
- [LICENSE](LICENSE)

## Author

**Mohammad Sultoni Powered by MITO Group**

- GitHub: (https://github.com/Sultonisky)
- Email: (muhsultonipml111@gmail.com)

---

_MITO Group Attendance - Attendance Project application for MITO Group_
