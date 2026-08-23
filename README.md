# Project Roost

Project Roost is a WebHatchery project registry and review dashboard for the existing app and game summary reports.

## Structure

- `frontend/`: React, TypeScript, Vite dashboard
- `backend/`: PHP API with PDO repositories and shared WebHatchery bearer-token writes
- `backend/database/001_create_project_roost_tables.sql`: Project Roost extension tables
- `publish.ps1`: delegates to `H:\WebHatchery\publish.ps1`

## Backend Setup

The shared database must already contain the `projects` table. Run the Project Roost migration and seed import after configuring `backend/.env`:

```powershell
cd backend
composer run db:init
```

Required backend env values include `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`, `PROJECTS_TABLE`, `JWT_SECRET`, `PUBLISH_EVENT_TOKEN`, `WEBHATCHERY_LOGIN_URL`, `CORS_ORIGIN`, `API_BASE_PATH`, `APPS_ROOT`, and `GAME_APPS_ROOT`. Summary import additionally requires the explicitly configured `APPS_SUMMARY_PATH` and `GAME_APPS_SUMMARY_PATH` files.

The shared root `H:\WebHatchery\.env` can enable publish tracking with:

```text
PROJECT_ROOST_PUBLISH_TOKEN=...
PROJECT_ROOST_API_URL_PREVIEW=http://127.0.0.1/project_roost/api/v1
PROJECT_ROOST_API_URL_PRODUCTION=https://webhatchery.au/project_roost/api/v1
```

## API

- `GET /projects`
- `POST /projects`
- `GET /projects/{id}`
- `PATCH /projects/{id}`
- `DELETE /projects/{id}`
- `GET /projects/{id}/reviews`
- `POST /projects/{id}/reviews`
- `GET /projects/{id}/tasks`
- `POST /projects/{id}/tasks`
- `PATCH /tasks/{id}`
- `POST /import/html-summary`
- `GET /dashboard/summary`
- `GET /dashboard/fix-queue`

Write, delete, and import routes require an admin bearer token from shared WebHatchery Login.

## Publish

```powershell
.\publish.ps1
```

Preview URL: `http://127.0.0.1/project_roost/`

## Production Seed SQL

When production can run SQL files but not `db:init`, generate the manual seed file from the current reports:

```powershell
cd backend
composer run seed:export
composer run init:export
```

Apply `001_create_project_roost_tables.sql`, `002_create_project_roost_deployments.sql`, then `seeds/900_seed_project_roost_from_summaries.sql` in production.

For a single production upload/run, use `backend/database/project_roost_init.sql`. It contains the Roost schema and current seed data in one idempotent SQL file.
