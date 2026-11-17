# Independent Asterisk administration

Phase 2 foundation: Laravel 13, PHP 8.5, nginx, administrator authentication, and health checks. No tenant or SIP CRUD yet. No changes to the parent PBX deployment are required.

## First start

Run these commands from this directory on the administration host. Docker Engine and Compose v2.24+ are required; host PHP, Composer, and Node are not required.

```sh
cp .env.example .env
# Edit APP_URL and the bind address if necessary. Keep REALTIME_ENABLED=false.
docker compose build app web
docker compose run --rm app initialize
docker compose up -d app web
docker compose exec app php artisan admin:create
```

The administrator command prompts for name, email, and a hidden password (minimum 12 characters). There is no default account or public registration. Open http://localhost:8080/login, or the address configured in APP_URL. Startup defaults to host loopback. For access from another machine, bind the intended private interface; use HTTPS before signing in over a network and set SESSION_SECURE_COOKIE=true. This Compose file does not provision TLS or a reverse proxy.

`initialize` explicitly runs migrations against the application's SQLite connection only. Normal startup never runs migrations. The application encryption key and SQLite database persist in the `application-data` volume; preserve both together in backups. To apply future application migrations, run `docker compose run --rm app initialize` again. Do not remove the data volume as part of normal upgrades.

## Database separation

The default connection, user model, and existing migrations are pinned to SQLite. Accounts, sessions, and login rate limits live there. No live PBX credentials are copied from the parent repository. The app has no Asterisk mounts, Docker socket mount, or external PBX network attachment.

The separately named `asterisk` connection uses REALTIME_DB_HOST as a DNS name or IP address. Set REALTIME_DB_PORT, REALTIME_DB_DATABASE, REALTIME_DB_USERNAME, REALTIME_DB_PASSWORD, and REALTIME_DB_SSLMODE privately in `.env`. The deployed connection must use the PBX host's reachable private/Tailscale address, not a Docker service name. The dashboard attempts a simple connection check only when REALTIME_ENABLED=true; failures display a generic status rather than connection details.

Phase 2 requests read-only PostgreSQL sessions with a three-second statement timeout. This is a guardrail, not a substitute for database privileges: use a restricted role, never the PBX superuser. Remote publishing, PostgreSQL access rules, and a dedicated role remain a separately approved later phase. No PBX table migrations are provided. Never target framework migrations at the `asterisk` connection.

Health endpoint `/up` checks application bootstrap and the application migration table. It does not connect to the PBX, so a PBX outage does not prevent administrator login. Detailed connection status requires authentication.

## Tests

Application tests use in-memory SQLite:

```sh
docker compose --profile test run --build --rm test
```

Full integration checks use a second, separate Compose project:

```sh
docker compose -f compose.test.yml up --build --abort-on-container-exit --exit-code-from test
# Stop the disposable test project after reviewing results:
docker compose -f compose.test.yml stop
```

The PostgreSQL fixture uses a copy of the repository initialization schema, with no live account data. It has no published port, uses temporary in-memory storage, and runs on an internal network. Trust authentication and the Docker hostname `pbx-fixture` are limited to this disposable test project. The tests verify that PBX writes are rejected and that Laravel tables stay out of PostgreSQL.

For local source development, dependencies can be installed with a temporary Composer container. Dependencies are locked in `app/composer.lock`; the Docker build installs them automatically. Runtime images omit development packages. The UI uses plain Blade and static CSS, so no frontend asset build is required.

## Remaining phases

Tenant metadata and authorization come next, followed by PJSIP CRUD, approved remote connectivity, Realtime context routing, dialplan management, and trunks. Existing PBX accounts will not be renamed or assigned to tenants automatically.
