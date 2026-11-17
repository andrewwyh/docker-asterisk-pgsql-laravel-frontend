# Phase 4 checkpoint — COMPLETE

Workspace: `/root/asterisk-pgsql-2026-2`
Codex conversation: `01a1e7de-84aa-7f10-91a2-af00638fff32`

The user requested saving progress and moving this same conversation into tmux. Changes are saved on disk; no Git commit or stash was made.

## Goal and authorization

Complete Phase 4: tenant-aware PJSIP CRUD in the independent Laravel deployment. The user authorizes necessary work on this test VM/PBX without a separate conversational approval. Keep credentials private, preserve existing accounts/data, and use the native sandbox escalation mechanism where required. Do not start Phase 5/6 or implement dialplan routing/trunks yet.

## Implemented and deployed

- Platform/tenant administrators can manage endpoints, auths, and AORs; viewers cannot.
- Guided SIP-account creation atomically inserts all three tables. Numeric local extensions retain leading zeros and become extension_context, capped at 40 characters for SIP usernames.
- Additional auth/AOR records must be attached to an existing endpoint in the same tenant, atomically with their reference updates.
- IDs/contexts cannot be edited; unlisted fields remain unchanged. Blank passwords preserve credentials. Userpass replacement is confirmed, never displayed/flashed/logged, and clears old digests.
- Signed/encrypted version tokens use PostgreSQL xmin, expire after an hour, and reject stale edits/deletions including password-only changes and modified related records.
- Short configuration-table locks serialize writes. Review/typed confirmation is required before deletion. Shared records, active contacts, last required references, literal dialplan dependencies, IP identifiers, and outbound registrations block deletion.
- Separate disabled-by-default `asterisk_write` connection; the existing read-only, column-restricted inventory reader is unchanged.
- Least-privilege writer grants are in `laravel-admin/database/pjsip-writer-grants.sql`. Writer cannot update IDs/contexts, change schema, edit extensions, or mutate contacts. Credential statement/parameter logging was disabled for this role.
- Local audit intents/outcomes contain no credentials. The three PBX tables share one transaction; SQLite audit completion is explicitly not a distributed transaction.
- README, AGENTS, forms, navigation, fixtures, and tests updated.

## Validation completed

Full isolated PostgreSQL suite: **45 tests, 288 assertions, all passed**.
Log: `/tmp/asterisk-admin-phase4-verified.log`.
Laravel Pint passed. Production images built successfully.

The live writer role was created, credentials saved only in ignored `laravel-admin/.env`, and writes enabled. Private credentials were never printed. Existing endpoint/auth/AOR/extensions table contents were compared before/after granting privileges and verified unchanged. No PBX schema changes, reloads, or PostgreSQL restarts were needed in this phase.

Deployment was applied with:

```sh
docker compose -f laravel-admin/compose.yml up -d --force-recreate app web
```

## Completion verification

Phase 4 is complete and deployed. The resumed live smoke check succeeded through `admin-entrypoint`: CRUD, blank-password preservation, attached AOR creation, dependency-reviewed deletion, and role restrictions all passed. PostgreSQL and SQLite outer transactions were rolled back, retaining no temporary tenants or SIP records.

HTTP health/login checks passed; dashboard, tenants, and creation routes require authentication. The admin web service and PostgreSQL are healthy; Asterisk remains running with one endpoint and one contact, and its native PostgreSQL connection is confirmed connected. `git diff --check` passed. The previously completed integration suite remains 45 tests / 288 assertions, all passing; no implementation changes were required after resuming.

No Git commit was created. Tenant-local dialing, extensions editing, and trunks remain later phases.

## Previously pending checks (all completed)

1. Rerun the live CRUD smoke check using the application entrypoint, not plain `docker compose exec app php`.
2. Check live HTTP health/login/protected routes and service health.
3. Check Asterisk endpoint/contact counts and its PostgreSQL connection using **`realtime show pgsql status`** (not `realtime pgsql status`). Do not display raw account/credential output.
4. Run `git diff --check`, inspect final changes, and report completion without committing unless requested.

### Why the first live smoke check stopped

The check ran PHP via `docker compose exec -T app php` and reached encryption without an APP_KEY. Docker exec does not rerun the image entrypoint that loads the persisted key. It raised `Illuminate\Encryption\MissingAppKeyException`; **both SQLite and PostgreSQL outer transactions were rolled back**, leaving no temporary tenant/SIP records.

Use this wrapper for CLI checks requiring encryption:

```sh
docker compose -f laravel-admin/compose.yml exec -T app admin-entrypoint php
```

Supply a PHP script through stdin that loads vendor/autoload.php, bootstraps the Console Kernel, and exercises `PjsipManager`. Select an existing platform administrator and use `Auth::setUser` only in that process (no login/session changes). Begin outer transactions on SQLite and `asterisk_write`, create a temporary uniquely named tenant and SIP bundle, edit auth with a blank password and verify preservation, attach an extra AOR, review/delete the temporary bundle, verify role restrictions, and roll back both outer transactions in finally. Print only pass/fail summaries, never passwords or account records. The outer rollback ensures Asterisk cannot observe temporary test configuration.

The persisted encryption key is under `/var/lib/laravel/app-key` in the private application volume; never print it. Runtime web requests already receive it through the entrypoint.

## Relevant files

- `laravel-admin/app/app/Services/PjsipManager.php`
- `laravel-admin/app/app/Http/Requests/PjsipRequest.php`
- `laravel-admin/app/app/Http/Controllers/PjsipController.php`
- `laravel-admin/app/resources/views/pjsip/`
- `laravel-admin/app/tests/Feature/PjsipManagementTest.php`
- `laravel-admin/database/pjsip-writer-grants.sql`
- `laravel-admin/README.md`

Private one-off provisioning helpers are in /tmp and contain no literal credentials: `/tmp/asterisk-admin-phase4-writer.py`. Do not rerun provisioning unnecessarily; the role and environment are already configured.

The prior-phase optional PBX remote publication still uses both root Compose files. Preserve it for any PBX recreation:

```sh
docker compose -f docker-compose.yml -f docker-compose.remote.yml ...
```

Existing administrator sessions and PBX accounts should remain untouched. Tenant dialing and extensions-table edits remain later phases.
