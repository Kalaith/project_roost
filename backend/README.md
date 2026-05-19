# Project Roost Backend

PHP API for Project Roost. It stores canonical project rows in the shared `projects` table and keeps Project Roost-specific metadata in extension tables.

## Required Env

- `APP_NAME`
- `APP_VERSION`
- `API_BASE_PATH`
- `CORS_ORIGIN`
- `DB_HOST`
- `DB_PORT`
- `DB_NAME`
- `DB_USER`
- `DB_PASSWORD`
- `PROJECTS_TABLE`
- `JWT_SECRET`
- `PUBLISH_EVENT_TOKEN`
- `WEBHATCHERY_LOGIN_URL`
- `APPS_SUMMARY_PATH`
- `GAME_APPS_SUMMARY_PATH`
- `RUST_GAMES_ROOT`

No endpoint uses local login or register flows. Mutating routes validate a bearer token and require an admin claim.
The deployment and summary import endpoints also accept `PUBLISH_EVENT_TOKEN` through `X-Project-Roost-Publish-Token` so the shared publish script can record deploys and refresh rating snapshots from the current app, game, and RustGames inventories. Import preview remains admin-only.

## Migration

Run after the shared `projects` table exists. This also imports the current app, game, and RustGames summary reports as seed data:

```powershell
composer run db:init
```

Manual equivalent:

```sql
SOURCE backend/database/001_create_project_roost_tables.sql;
SOURCE backend/database/002_create_project_roost_deployments.sql;
```

## Manual Seed Export

For production environments where only manual SQL files can be run:

```powershell
composer run seed:export
composer run init:export
```

This writes `backend/database/seeds/900_seed_project_roost_from_summaries.sql`.
It also writes `backend/database/project_roost_init.sql`, a single idempotent SQL initializer containing schema and seed data.

## RustGames Import

`rust-games` imports scan `RUST_GAMES_ROOT` for immediate child projects that include both `Cargo.toml` and `index.html`. The shared publish script sends the same inventory inline when publishing Project Roost so production can refresh RustGames ratings without reading the local Windows path.
