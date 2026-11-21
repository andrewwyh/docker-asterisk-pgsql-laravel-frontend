# Independent Asterisk administration

Tenant administration and Realtime dialplan: Laravel 13, PHP 8.5, nginx, administrator authentication, and health checks. Tenant workspaces, scoped PJSIP CRUD, tenant-local numbering, and managed Realtime dialplan editing are available. External SIP trunks remain a later phase. No changes to the parent PBX deployment are required.

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

External SIP trunks and advanced routing remain. Inventory remains read-only; provisioning uses a separately enabled writer connection.

## Phase 4: PJSIP management

Inventory continues using `asterisk`, with its column-restricted reader role. Configure a second non-superuser login using `REALTIME_WRITE_DB_USERNAME` and `REALTIME_WRITE_DB_PASSWORD`, then set `REALTIME_WRITES_ENABLED=true`. Enable this only after applying `database/pjsip-writer-grants.sql` as the PBX database owner with `psql -v writer_role=YOUR_ROLE`. The grants file changes privileges only. The writer role needs LOGIN, CONNECT, and the supplied grants; it must not own the database or schema and must not have superuser, role creation, database creation, replication, or bypass-RLS privileges. Disable PostgreSQL statement/parameter logging for this role when handling SIP credentials. Use native prepared statements as configured.

The writer can insert/delete the three PJSIP tables and update only the permitted columns; it cannot update endpoint IDs/contexts, change schema, edit the dialplan, or mutate runtime contacts. SELECT on the three tables supports PostgreSQL row-version checks; the application explicitly selects safe columns plus `xmin`, never stored credentials. The separate inventory reader still cannot read credential columns. Writer queries and errors containing bound credentials are not logged or returned to the browser. Keep debug tooling and SQL query logging disabled on credential workflows.

Add Endpoint writes `identify_by=auth_username`. Before deploying this version, reapply `database/pjsip-writer-grants.sql` for the configured writer role to grant INSERT on the new column used by the app. As the PBX database owner, also run `database/pjsip-backfill-identify-by.sql` once with `psql -v ON_ERROR_STOP=1` against the PBX database. It sets `identify_by=auth_username` for all existing endpoints, including rows with another identification method.

After changing the environment or image, run `docker compose build app web` and `docker compose up -d --force-recreate app web`. Existing application volumes and sessions are retained. There are no Phase 4 database schema migrations.

### Create and edit

Open a tenant workspace, then **Endpoints → Create SIP account**. A numeric local extension such as `100` becomes `100_abc` in context `abc`. Leading zeroes are preserved. The combined identity is capped at 40 characters, matching the existing authentication username column. An endpoint, userpass authentication record, and AOR are created in one PostgreSQL transaction. Configure the phone's SIP identity and authentication username with the full suffixed ID. Account creation itself does not add dialplan rows. Use the tenant dialplan page to provision local routes, then synchronize newly introduced contexts on the PBX host.

Authentication and AOR pages also have creation forms. A new record must be attached to an existing endpoint in the same tenant; creation and reference addition occur in the same transaction. Record names receive the tenant suffix. New auths use userpass; a blank username uses the generated auth ID. This keeps ownership explicit without adding a PBX schema or creating unattached records. Orphan/shared records are outside these tenant workflows.

Edits keep IDs and contexts immutable and preserve unlisted settings. Blank optional settings explicitly clear that field to NULL; omitted fields stay unchanged. Blank password fields preserve the existing credential. Explicit replacement requires confirmation and 12–80 characters; it is supported for userpass authentication only and clears obsolete stored digests. Passwords are stored in Asterisk's required format, not Laravel administrator-password hashes. Existing passwords are never sent to the form or flashed back after errors. `REALTIME_TRANSPORTS` lists the allowed static transport names; configure it for the PBX. Codec names must match installed PBX support.

Encrypted form tokens bind the tenant, resource, identity, PostgreSQL row version, and a one-hour expiry. Stale updates, password-only concurrent changes, changed deletion dependencies, and cross-resource token reuse are rejected. Short table locks serialize writes to the three PJSIP configuration tables while allowing normal reads. Lock and statement timeouts bound waiting. External configuration writers should coordinate with this administration interface; this is not database-enforced multi-tenancy or a replacement for foreign keys. External dialplan/trunk writers and runtime contact changes can race dependency checks because those tables remain outside this writer's control.

### Deletion

Choose **Review deletion**, inspect the listed records, type the exact identity, and check the approval box. The transaction rechecks the form version, ownership, references, and dependencies before deleting anything.

Deleting an endpoint removes its exclusively linked auths/AORs in the same transaction. Shared records, active contacts, literal dialplan references, IP identifiers, and outbound registration dependencies block the operation. Dialplan reference detection is conservative for literal IDs; generated/dynamic references cannot be determined reliably from database text alone. Unregister phones before deleting accounts. Deleting an individual auth/AOR removes its endpoint reference, but is blocked if shared or the last record in a required reference. Runtime contacts and Realtime extensions are never deleted by these workflows.

### Audit outcomes

SQLite audit entries contain actor, tenant, resource ID, operation ID, and status only, with no credentials. A pending intent is recorded before the PostgreSQL transaction. Successful PBX commits are marked `committed`; failures or an uncertain connection outcome are recorded separately. PostgreSQL changes across the three PJSIP tables are atomic, but SQLite audit completion is not a distributed transaction. If completion cannot be confirmed, refresh inventory before retrying rather than repeating the operation blindly. No automatic write retries are performed.

## HTTPS through Tailscale Serve

On the administration host, run `tailscale serve --bg --https=443 http://127.0.0.1:8080`. HTTPS terminates at Tailscale; the local upstream stays HTTP. Set APP_URL to the host's full https://…ts.net URL, SESSION_SECURE_COOKIE=true, and ADMIN_BIND_ADDRESS=127.0.0.1 in the administration .env. Access the UI through its HTTPS URL, rather than direct HTTP, when secure cookies are enabled.

Set TRUSTED_PROXIES to the gateway IP of the administration Docker network (find it with `docker network inspect asterisk-admin_admin`). Host-originated proxy traffic reaches nginx from this gateway. Laravel trusts X-Forwarded-Proto and X-Forwarded-For only from the configured addresses, and does not trust forwarded host headers. Update the setting if the network is recreated with a different subnet. For a different reverse-proxy topology, configure the actual source addresses instead of trusting all clients.

Rebuild app/web after code changes and recreate both services after environment changes. Use `tailscale serve status` to find the HTTPS URL. If a foreground Serve command already owns port 443, stop it with Ctrl-C before starting the background command. No Tailscale Funnel configuration is needed.

## Tenant-local dialing and the managed dialplan

Open **Tenants → workspace → Dialplan**. Viewers can list routes; platform and tenant administrators can preview and manage routes when DIALPLAN_WRITES_ENABLED=true. Numeric extensions keep their leading zeros. The editor supports Answer, Wait, Playback, Echo, Dial, and Hangup, with up to 20 priorities. Add/remove/reorder steps, preview the exact compiled applications and arguments, then explicitly approve the save. Replacing priorities is one PostgreSQL transaction, avoiding intermediate uniqueness conflicts. Deletion requires typing the extension and checking confirmation. Encrypted route snapshots detect stale edits, deletion/recreation, and cross-tenant token reuse.

Dial destinations must be endpoints in the current tenant context. The server generates `Dial(PJSIP/endpoint,timeout,i)` with a bounded timeout and no user-controlled Dial options; the i option ignores endpoint forwarding requests. Playback is restricted to DIALPLAN_PLAYBACK_PROMPTS (default hello-world), Wait is bounded to 30 seconds, and raw applications, variables, URIs, paths, and cross-context calls are not accepted. Every managed route ends in Hangup. Existing unsupported routes/patterns remain in the database; they are read-only in this editor, and their raw arguments are not displayed. Advanced patterns and trunks need a future dedicated workflow.

**Provision missing routes** creates routes for canonical numeric endpoint IDs in that tenant: `100_abc` becomes extension `100` in context abc. It also supplies 200 (Answer/Echo/Hangup) and 201 (Answer/Wait/Playback hello-world/Hangup). Existing extensions are preserved in their entirety. Repeating provisioning does not create duplicates. Numbers 200 and 201 are reserved for tests by this provisioning action, so endpoints with those local numbers are not auto-routed. Legacy unsuffixed endpoints are not renamed or guessed; create an explicit tenant-local Dial route for them if needed.

### Dialplan database access

Configure a separate non-superuser login through REALTIME_DIALPLAN_DB_USERNAME and REALTIME_DIALPLAN_DB_PASSWORD, then apply `database/dialplan-grants.sql` as the PBX database owner with psql variables reader_role and dialplan_role. The role receives SELECT/DELETE and limited INSERT on extensions, USAGE on its existing sequence, and SELECT on endpoint IDs/contexts. It receives no PJSIP mutation or schema-creation privileges. The inventory reader gains only the columns needed to list routes, not appdata or credentials. The PJSIP writer still cannot modify extensions. Set DIALPLAN_WRITES_ENABLED=true and recreate the administration containers after configuration. No database schema migrations are required.

PJSIP and dialplan mutations share a database advisory transaction lock before their table locks. This coordinates account deletion and route creation through the application; external SQL tools must coordinate separately. Audit intent/outcome semantics are the same as PJSIP changes, with no raw route arguments in audit records. PostgreSQL roles are not a replacement for tenant policies or database row-level security.

### Activate additional contexts on the PBX host

The remote Laravel deployment does not mount Asterisk configuration, control Docker, or reload Asterisk. After creating routes or endpoints for a new context, run on the PBX host:

```sh
python3 asterisk/scripts/sync-realtime-contexts.py
# Optional read-only check (exit 1 means regeneration is needed):
python3 asterisk/scripts/sync-realtime-contexts.py --check
```

The script reads distinct endpoint/dialplan contexts through the existing PostgreSQL container, validates identifiers, preserves contexts already defined in extensions.conf, atomically writes the ignored realtime-contexts.conf include, and reloads only the dialplan. The existing default and abc mappings are retained. Generated contexts must be regenerated on another PBX host; they are runtime state rather than committed tenant data. Context names general/globals are reserved. Provisioning a tenant's routes before synchronization ensures its context exists in PostgreSQL. Existing activated contexts use changed Realtime routes without a reload. No SIP reload or container restart is needed.

For the initial demo routes alone, `postgres/init/02-test-extensions.sql` is also safe to apply to an existing database; it preserves existing extension numbers. New installations load it after the schema initialization script.

## Flyingvoice FIP10P provisioning

Open **Tenants → workspace → Phones**. Viewers can list phones. Tenant and platform administrators can add, edit, remove, and reboot phones. Each record links one handset MAC address to an existing PJSIP endpoint in that tenant's context. Enable the existing read-only Realtime inventory before adding phones. Use a dedicated endpoint and AoR with one contact per handset. Asterisk sends the reboot request to every contact on the selected endpoint.

1. Back up the Laravel SQLite database and application key. Rebuild and apply the SQLite migration.

   ```sh
   docker compose build app web
   docker compose run --rm app initialize
   docker compose up -d app web
   ```

2. Set `PHONE_PROVISIONING_URL` in the administration `.env` to an HTTPS origin reachable by the handset, or use the existing `APP_URL` if it is already reachable. A phone at a remote site needs a route to this origin through its site network or VPN. A Tailscale Serve URL needs a tailnet route from that network. If there is another reverse proxy, exclude `/provision/` URLs from request logging because the URL acts as a credential.

3. Add a phone and upload its firmware-compatible provisioning file. Laravel serves the uploaded XML or CFG bytes and encrypts the contents in SQLite. The file must contain the settings for that handset, including the SIP settings if it needs initial registration. Use a vendor template matching your firmware. Flyingvoice's general provisioning guide documents CFG for account settings, so use CFG unless the installed firmware supports the XML profile you are uploading. [Flyingvoice provisioning guide](https://www.flyingvoice.com/Uploads/detail/2024-11-05/Flyingvoice%20Phones%20Auto%20Provisoning%20Guide.pdf)

4. On the handset, open **Administration → Provision**. Enable provisioning and **Resync from SIP**, where shown. Paste the entire Profile URL from **Edit and setup** into **Profile Rule**, then choose **Save & Apply**. Confirm the profile is applied and the endpoint is registered. Changing the MAC address, endpoint, or file extension changes the URL. Removing the record disables the URL.

5. For the Reboot button, configure a dedicated AMI account on the same Asterisk instance used for Realtime inventory. Load `res_pjsip_notify` and restrict the manager listener and account ACL to the administration app's private network address. Use no event read permissions and the `system` write privilege required for `PJSIPNotify`. Set `PHONE_AMI_ADDRESS` (for example `tcp://pbx.internal:5038` over Tailscale), `PHONE_AMI_USERNAME`, and `PHONE_AMI_SECRET` in the administration `.env`, then recreate the app container. [Asterisk PJSIPNotify documentation](https://docs.asterisk.org/Asterisk_20_Documentation/API_Documentation/AMI_Actions/PJSIPNotify/)

6. Click **Reboot** and check that the handset restarts and registers again. This uses Flyingvoice's `check-sync;reboot=true` SIP notification through the endpoint's registered contact. Remote phones need a working SIP return path from Asterisk. The dashboard reports whether Asterisk accepted the action. Confirm the reboot on the handset.

### Per-phone Profile URL

In **Phones → Edit and setup**, Profile URL is editable and saved with **Save phone**. Set an HTTP/HTTPS host, port, and optional proxy prefix reachable from that handset while retaining the generated `/provision/...` token/filename suffix. Clearing the field restores PHONE_PROVISIONING_URL or APP_URL. The per-phone base override is encrypted in SQLite; the full credential-bearing URL is not flashed after validation errors. Existing profiles and tokens are preserved, and later MAC/endpoint/format changes regenerate the managed path using the saved base. Apply the additional SQLite migration during deployment. Changing the field does not create a listener, configure DNS/proxying, or apply settings to the handset automatically.

### AMI/notifier setup required for Reboot

Provisioning downloads and SIP registration do not require AMI. The Reboot button does. New installations require manual setup: `manager.conf.example` is not loaded automatically and has AMI disabled by default. No reusable secret is supplied.

1. On the PBX host, create the private configuration file:

   ```sh
   cp asterisk/config/manager.conf.example asterisk/config/manager.conf
   ```

   Use this only for a new installation; preserve any existing `manager.conf`.

2. **Generate your own secret** in your local terminal:

   ```sh
   python3 -c 'import secrets; print(secrets.token_hex(32))'
   ```

   Put the generated value in `secret` under `[phone-provisioning]` in the private PBX `manager.conf`, and use that same value for `PHONE_AMI_SECRET` in the administration host's private `laravel-admin/.env`. Generate a separate secret for each deployment. Keep the tracked example files as placeholders.

3. Replace `REPLACE_WITH_PBX_TAILSCALE_IP` with the PBX's private Tailscale address. Replace `REPLACE_WITH_ADMIN_SOURCE_IP` with the administration client's actual source address: the app container IP on this same host, or the remote administration host's Tailscale IP. Recheck the ACL if that address changes. Bind AMI to this specific private interface, not a public/wildcard interface.

4. After replacing every placeholder, set `enabled = yes`. Ensure the Asterisk container user can read `manager.conf` without making it world-readable. The actual `manager.conf` and administration `.env` are gitignored and must not be committed.

`asterisk/config/pjsip_notify.conf` must also exist: without it, res_pjsip_notify can decline to load and PJSIPNotify will be unavailable. The included Flyingvoice option escapes the semicolon in the Asterisk configuration file. Laravel supplies the raw `Event=check-sync;reboot=true` value through AMI.

Apply the PBX configuration without restarting SIP:

```sh
docker compose exec -T asterisk asterisk -rx 'manager reload'
docker compose exec -T asterisk asterisk -rx 'module load res_pjsip_notify.so'
docker compose exec -T asterisk asterisk -rx 'module show like res_pjsip_notify'
```

Set PHONE_AMI_ADDRESS to `tcp://<PBX-Tailscale-IP>:5038`, PHONE_AMI_USERNAME to the dedicated account name, and PHONE_AMI_SECRET to the matching secret in the administration host's private .env, then recreate app/web. Setting those environment variables alone does not enable AMI in Asterisk. The account requires system write permission for PJSIPNotify and no event read permissions. Confirm a successful action on the handset; AMI acceptance alone is not proof of a completed reboot.
