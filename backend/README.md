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
- `APPS_ROOT`
- `APPS_PROJECT_PATH_OVERRIDES` (optional semicolon-separated `slug=absolute-path` entries for apps stored outside the default apps workspace)
- `GAME_APPS_ROOT`
- `RUST_GAMES_ROOT`

Project discovery is manifest-driven. The checked-in
`backend/config/project-manifest.json` lists the approved project directories
for each configured root; adding a directory to the filesystem does not make
it eligible for reconciliation until the manifest is reviewed and updated.

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
SOURCE backend/database/003_add_project_roost_display_name.sql;
SOURCE backend/database/004_clean_shared_project_catalog.sql;
SOURCE backend/database/005_create_project_roost_bug_reports.sql;
SOURCE backend/database/006_add_project_roost_archived.sql;
SOURCE backend/database/007_remove_retired_auth_portal.sql;
SOURCE backend/database/008_move_comfyui_to_local.sql;
SOURCE backend/database/009_hide_merged_monster_maker.sql;
SOURCE backend/database/010_replace_retired_wh_tracker.sql;
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

`rust-games` imports scan `RUST_GAMES_ROOT` for immediate child projects that include
`Cargo.toml` and either the source `game_page.json` or a generated `index.html`.
The title and first `about` entry in `game_page.json` are the player-facing display
name and short description; Rust remains internal catalog metadata. The shared
publish script sends the same inventory inline when publishing Project Roost so
production can refresh RustGames ratings without reading the local Windows path.
