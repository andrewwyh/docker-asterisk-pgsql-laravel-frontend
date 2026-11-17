# Independent Asterisk administration

Phase 4 administration: Laravel 13, PHP 8.5, nginx, administrator authentication, and health checks. Tenant workspaces, scoped inventory, and tenant-aware PJSIP CRUD are available. Dialplan routing and trunks are later phases. No changes to the parent PBX deployment are required.

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

The separately named `asterisk` connection uses REALTIME_DB_HOST as a DNS name or IP address. Set REALTIME_DB_PORT, REALTIME_DB_DATABASE, REALTIME_DB_USERNAME, REALTIME_DB_PASSWORD, and REALTIME_DB_SSLMODE privately in `.env`. The deployed connection must use the PBX host's reachable private/Tailscale address, not a Docker service name. The dashboard checks the read-only inventory connection only when REALTIME_ENABLED=true; failures display a generic status rather than connection details.

The application requests read-only PostgreSQL sessions with a three-second statement timeout. This is a guardrail, not a substitute for database privileges: use a restricted role, never the PBX superuser. The optional PBX-side `docker-compose.remote.yml` adds a private interface publication while retaining loopback access. A restricted reader role must have CONNECT on the PBX database, USAGE on public, and column-level SELECT on only the inventory fields of ps_endpoints, ps_auths, and ps_aors (see the fixture reader grants in tests/fixtures/02-tenants.sql). No schema changes are required. Keep Tailscale access rules restricted to the administration host; never use a public bind address for PostgreSQL. No PBX table migrations are provided. Never target framework migrations at the `asterisk` connection.

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

The PostgreSQL fixture uses a copy of the repository initialization schema plus synthetic tenant accounts, with no live account data. It has no published port, uses temporary in-memory storage, and runs on an internal network. Trust authentication and the Docker hostname `pbx-fixture` are limited to this disposable test project. The tests use a restricted reader role and verify that credential columns are inaccessible, PBX writes are rejected, and Laravel tables stay out of PostgreSQL.

For local source development, dependencies can be installed with a temporary Composer container. Dependencies are locked in `app/composer.lock`; the Docker build installs them automatically. Runtime images omit development packages. The UI uses plain Blade and static CSS, so no frontend asset build is required.

## Upgrading from Phase 2

Back up the application SQLite database and encryption key together before an upgrade. Rebuild app and web, run `docker compose run --rm app initialize`, then `docker compose up -d app web`. Initialization applies only application migrations. Existing Phase 2 administrator accounts retain platform administrator access, passwords, and sessions; subsequent accounts default to no platform privileges unless created explicitly through the platform administrator CLI command.

## Tenants and access

Open **Tenants** after signing in. Platform administrators can register tenant workspaces and manage memberships. Tenant administrators can change their tenant's display name and manage its PJSIP records when provisioning is enabled; viewers can only read. Context identifiers are unique, immutable, and limited to 32 characters: a lowercase initial letter followed by lowercase letters, digits, or hyphens. Underscores are reserved for future SIP identities such as `100_abc`. The combined identity will need to fit the existing 40-character authentication username limit.

Tenant registration requires confirmation of ownership of the selected PBX context. Register context `default` explicitly if you want the existing legacy endpoint visible in a workspace. There is no automatic tenant creation, account renaming, or data import. New contexts will not carry calls until their Realtime dialplan wiring is configured in a later phase.

Platform administrators see all workspaces. Other users see only assigned workspaces; requests for other tenant IDs are denied server-side before PBX queries. Membership changes take effect on the next request. Tenant contexts filter endpoints exactly. Authentication and AOR records are resolved from inbound/outbound auth and comma-separated AOR references; records also used outside the tenant context, or without an endpoint reference, are omitted. Credential columns and contact URIs are never selected or rendered. Inventory is paginated and read-only. PBX failures produce a generic message.

Create a platform administrator with `docker compose exec app php artisan admin:create`. To create a restricted account, first register its tenant, then run:

```sh
docker compose exec app php artisan admin:create --tenant=abc --role=viewer
# Or use --role=admin for a tenant administrator.
```

The commands use hidden password prompts. To grant an existing account another tenant membership or change its role, use the workspace's **Tenant access** section as a platform administrator. Removing a membership removes only that tenant's access; it does not delete the account. Application metadata changes are recorded in the local `audit_events` table without passwords or PBX credentials.

## PBX-side private publication

On the PBX host, set `PBX_PRIVATE_BIND_ADDRESS` to its Tailscale IPv4 address in the ignored root `.env`. Then use both Compose files:

```sh
docker compose -f docker-compose.yml -f docker-compose.remote.yml up -d --no-deps postgres
```

This can recreate PostgreSQL; Asterisk's existing loopback publication is retained. Continue using both files for subsequent PBX Compose operations so the private publication remains configured. Existing PostgreSQL host authentication rules must permit the restricted reader role. Firewall and tailnet access policies are managed separately. The frontend's REALTIME_DB_HOST should be this private IP or a DNS name resolving to it. Tailnet access from a second machine must be validated from that machine; local connectivity does not establish its access rights.

## Remaining phases

Realtime context routing, dialplan management, and external trunks remain. Inventory remains read-only; provisioning uses a separately enabled writer connection.

## Phase 4: PJSIP management

Inventory continues using `asterisk`, with its column-restricted reader role. Configure a second non-superuser login using `REALTIME_WRITE_DB_USERNAME` and `REALTIME_WRITE_DB_PASSWORD`, then set `REALTIME_WRITES_ENABLED=true`. Enable this only after applying `database/pjsip-writer-grants.sql` as the PBX database owner with `psql -v writer_role=YOUR_ROLE`. The grants file changes privileges only. The writer role needs LOGIN, CONNECT, and the supplied grants; it must not own the database or schema and must not have superuser, role creation, database creation, replication, or bypass-RLS privileges. Disable PostgreSQL statement/parameter logging for this role when handling SIP credentials. Use native prepared statements as configured.

The writer can insert/delete the three PJSIP tables and update only the permitted columns; it cannot update endpoint IDs/contexts, change schema, edit the dialplan, or mutate runtime contacts. SELECT on the three tables supports PostgreSQL row-version checks; the application explicitly selects safe columns plus `xmin`, never stored credentials. The separate inventory reader still cannot read credential columns. Writer queries and errors containing bound credentials are not logged or returned to the browser. Keep debug tooling and SQL query logging disabled on credential workflows.

After changing the environment or image, run `docker compose build app web` and `docker compose up -d --force-recreate app web`. Existing application volumes and sessions are retained. There are no Phase 4 database schema migrations.

### Create and edit

Open a tenant workspace, then **Endpoints → Create SIP account**. A numeric local extension such as `100` becomes `100_abc` in context `abc`. Leading zeroes are preserved. The combined identity is capped at 40 characters, matching the existing authentication username column. An endpoint, userpass authentication record, and AOR are created in one PostgreSQL transaction. Configure the phone's SIP identity and authentication username with the full suffixed ID. No dialplan rows or context wiring are added, so tenant-local dialing remains a later phase.

Authentication and AOR pages also have creation forms. A new record must be attached to an existing endpoint in the same tenant; creation and reference addition occur in the same transaction. Record names receive the tenant suffix. New auths use userpass; a blank username uses the generated auth ID. This keeps ownership explicit without adding a PBX schema or creating unattached records. Orphan/shared records are outside these tenant workflows.

Edits keep IDs and contexts immutable and preserve unlisted settings. Blank optional settings explicitly clear that field to NULL; omitted fields stay unchanged. Blank password fields preserve the existing credential. Explicit replacement requires confirmation and 12–80 characters; it is supported for userpass authentication only and clears obsolete stored digests. Passwords are stored in Asterisk's required format, not Laravel administrator-password hashes. Existing passwords are never sent to the form or flashed back after errors. `REALTIME_TRANSPORTS` lists the allowed static transport names; configure it for the PBX. Codec names must match installed PBX support.

Encrypted form tokens bind the tenant, resource, identity, PostgreSQL row version, and a one-hour expiry. Stale updates, password-only concurrent changes, changed deletion dependencies, and cross-resource token reuse are rejected. Short table locks serialize writes to the three PJSIP configuration tables while allowing normal reads. Lock and statement timeouts bound waiting. External configuration writers should coordinate with this administration interface; this is not database-enforced multi-tenancy or a replacement for foreign keys. External dialplan/trunk writers and runtime contact changes can race dependency checks because those tables remain outside this writer's control.

### Deletion

Choose **Review deletion**, inspect the listed records, type the exact identity, and check the approval box. The transaction rechecks the form version, ownership, references, and dependencies before deleting anything.

Deleting an endpoint removes its exclusively linked auths/AORs in the same transaction. Shared records, active contacts, literal dialplan references, IP identifiers, and outbound registration dependencies block the operation. Dialplan reference detection is conservative for literal IDs; generated/dynamic references cannot be determined reliably from database text alone. Unregister phones before deleting accounts. Deleting an individual auth/AOR removes its endpoint reference, but is blocked if shared or the last record in a required reference. Runtime contacts and Realtime extensions are never deleted by these workflows.

### Audit outcomes

SQLite audit entries contain actor, tenant, resource ID, operation ID, and status only, with no credentials. A pending intent is recorded before the PostgreSQL transaction. Successful PBX commits are marked `committed`; failures or an uncertain connection outcome are recorded separately. PostgreSQL changes across the three PJSIP tables are atomic, but SQLite audit completion is not a distributed transaction. If completion cannot be confirmed, refresh inventory before retrying rather than repeating the operation blindly. No automatic write retries are performed.
