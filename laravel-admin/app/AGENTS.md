# Asterisk administration application

This is an independent Laravel 13 application, deployed with ../compose.yml. PHP and Composer run in containers; host installations are not required. Production images exclude development tools.

## Boundaries

- Preserve the parent Asterisk/PostgreSQL deployment and its live data.
- SQLite is the application database. Keep users and framework migrations explicitly on SQLite.
- The named `asterisk` PostgreSQL connection is disabled by default and read-only for Phase 3.
- Never copy live credentials into source, logs, tests, or examples.
- Production PBX access uses an explicitly configured DNS/IP address, ultimately through Tailscale.
- Tenant records, roles, and audit metadata live in SQLite. Authorize tenant access before every PBX query.
- Existing Phase 2 users retain platform access through the upgrade migration. New users default to no platform access.
- SIP CRUD, dialplan editing, and trunks are later phases.

## Development

Use existing Laravel conventions, typed methods, Form Requests when validation becomes complex, and Blade with static CSS. No Node build is needed for the current interface.

From the parent laravel-admin directory, run application tests with `docker compose --profile test run --build --rm test`. Run the full disposable PostgreSQL integration suite with `docker compose -f compose.test.yml up --build --abort-on-container-exit --exit-code-from test`. This test project has no live database access.

Run `vendor/bin/pint --format agent` in the development container after PHP changes. Runtime startup must not automatically run migrations; `app initialize` explicitly migrates SQLite only.
