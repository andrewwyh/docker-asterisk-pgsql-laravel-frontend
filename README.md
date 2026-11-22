# Deploy Asterisk, PostgreSQL, and Laravel on a cloud VM

This guide runs both Compose projects on one Linux VM. The root project runs the Asterisk PBX and its Realtime PostgreSQL database. The `laravel-admin` project runs the admin UI and stores its own accounts and settings in SQLite. For an existing installation, plan the [migration](#move-an-existing-installation) before creating accounts or registering phones on the new host.

## 1. Prepare the VM

Install Git, Python 3, Docker Engine, and Docker Compose v2.24 or newer. Use persistent storage for the PBX runtime files and both databases. If the GitHub repository is private, configure GitHub access on the VM before cloning. The command below checks out the branch containing this guide. For a later deployment, select the release or branch you intend to run. [Docker Engine installation on Ubuntu](https://docs.docker.com/engine/install/ubuntu/)

```bash
git clone --branch create_readme \
  https://github.com/andrewwyh/docker-asterisk-pgsql-laravel-frontend.git \
  asterisk-pgsql-2026-2
cd asterisk-pgsql-2026-2
docker compose version
```

## 2. Start PostgreSQL and Asterisk

From the repo root, use `umask 077` before creating the two gitignored files below. Generate a password with `openssl rand -hex 32` and use the same value in both files.

**Root `.env`**

```dotenv
ASTERISK_VERSION=22.11.0
POSTGRES_IMAGE=postgres:17-bookworm
POSTGRES_DB=asterisk
POSTGRES_USER=asterisk
POSTGRES_PASSWORD=REPLACE_WITH_GENERATED_PASSWORD
```

**`asterisk/config/res_pgsql.conf`**

```ini
[general]
hostname=127.0.0.1
port=5432
dbname=asterisk
user=asterisk
password=REPLACE_WITH_GENERATED_PASSWORD
requirements=warn
```

Asterisk uses host networking and connects to PostgreSQL on the host loopback. Build the image, then grant the image's non-root user write access to its runtime directories and read access to its PostgreSQL configuration.

```bash
docker compose config --quiet
docker compose build asterisk

mkdir -p data/asterisk/{lib,log,spool}
pbx_uid=$(docker compose run --rm --no-deps asterisk id -u)
pbx_gid=$(docker compose run --rm --no-deps asterisk id -g)
sudo chown "$pbx_uid:$pbx_gid" data/asterisk/{lib,log,spool}
sudo chgrp "$pbx_gid" asterisk/config/res_pgsql.conf
chmod 640 asterisk/config/res_pgsql.conf

docker run --rm --user 0 --entrypoint cp \
  --mount "type=bind,src=$PWD/data/asterisk/lib,dst=/pbx-data" \
  local/asterisk:22.11.0 -R /var/lib/asterisk/documentation /pbx-data/

docker compose up -d
docker compose ps
docker compose logs --tail=80 postgres asterisk
docker compose exec -T asterisk asterisk -rx 'realtime show pgsql status'
```

The host bind mount hides the XML documentation installed in the image. The `docker run` command copies it from the built image into the mounted directory so Stasis can start. Use the image tag matching `ASTERISK_VERSION`. If Asterisk is already restarting with `Stasis initialization failed` and missing XML documentation messages, run the same copy command from the repo root, then run `docker compose restart asterisk`. Repeat the copy after rebuilding with a new Asterisk version. [Asterisk XML documentation loader](https://github.com/asterisk/asterisk/blob/22.11.0/main/xmldoc.c)

PostgreSQL initializes the Realtime schema and creates test routes `200` (echo) and `201` (playback) in `default` and `abc` on the first start with an empty data directory. Create or restore SIP accounts before registering phones.

Allow UDP `5060` and the PBX's configured UDP RTP range from the phone network or VPN in the cloud and host firewalls. Asterisk's NAT example uses UDP `10000–20000`. If a cloud public IP maps to the VM's private IP, configure `local_net`, `external_signaling_address`, and `external_media_address` in `asterisk/config/pjsip.conf` for that topology. Once a phone is configured, use the following command to verify registration and dial `200` to verify two-way audio. [Asterisk PJSIP NAT guide](https://docs.asterisk.org/Configuration/Channel-Drivers/SIP/Configuring-res_pjsip/Configuring-res_pjsip-to-work-through-NAT/)

```bash
docker compose exec -T asterisk asterisk -rx 'pjsip show contacts'
```

## 3. Start the Laravel admin UI

This example uses Tailscale Serve for private HTTPS. Join the VM to your tailnet first. Keep `ADMIN_BIND_ADDRESS=127.0.0.1` in the admin environment file.

```bash
cd laravel-admin
umask 077
cp .env.example .env

docker compose build app web
docker compose run --rm app initialize
docker compose up -d app web
docker compose exec app php artisan admin:create

sudo tailscale serve --bg --https=443 http://127.0.0.1:8080
tailscale serve status
docker network inspect asterisk-admin_admin \
  --format '{{(index .IPAM.Config 0).Gateway}}'
```

Set `APP_URL` in `laravel-admin/.env` to the HTTPS origin reported by Serve. Set `TRUSTED_PROXIES` to the Docker gateway printed above and keep `SESSION_SECURE_COOKIE=true`. Recreate the services, check the health endpoint, then open `/login` on the HTTPS URL from a tailnet device. [Tailscale Serve documentation](https://tailscale.com/docs/reference/tailscale-cli/serve)

```bash
docker compose up -d --force-recreate app web
curl -fsS http://127.0.0.1:8080/up
```

### Connect Laravel to the PBX for read-only inventory

From the repo root, open `psql` as the PostgreSQL account configured in the root `.env`.

```bash
docker compose exec postgres sh -c 'psql -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$POSTGRES_DB"'
```

At the `psql` prompt, create the inventory role and grant access to the required columns. Replace `asterisk` in the database grant if you chose a different database name. If the role already exists, skip `CREATE ROLE`. The `\password` command prompts for the password twice. Each successful grant prints `GRANT`.

```sql
CREATE ROLE laravel_readonly LOGIN;
\password laravel_readonly

GRANT CONNECT ON DATABASE asterisk TO laravel_readonly;
GRANT USAGE ON SCHEMA public TO laravel_readonly;

GRANT SELECT (id, context, transport, auth, outbound_auth, aors, "allow", callerid)
    ON public.ps_endpoints TO laravel_readonly;

GRANT SELECT (id, auth_type, username, realm)
    ON public.ps_auths TO laravel_readonly;

GRANT SELECT (id, max_contacts, default_expiration, maximum_expiration, qualify_frequency)
    ON public.ps_aors TO laravel_readonly;

ALTER ROLE laravel_readonly SET default_transaction_read_only = on;
\q
```

Set `PBX_PRIVATE_BIND_ADDRESS` in the root `.env` to a private IP on the PBX host that the admin container can reach. Run `tailscale ip -4` on the PBX host if you use its Tailscale address. Restrict TCP `5432` to the admin connection path. From the repo root, apply the private publication and check the port mappings.

```bash
docker compose -f docker-compose.yml -f docker-compose.remote.yml up -d --no-deps postgres
docker compose -f docker-compose.yml -f docker-compose.remote.yml ps postgres
```

PostgreSQL may be recreated. The `PORTS` column should show both the loopback and private IP on port `5432`. Use both root Compose files for subsequent PBX operations to retain the private binding.

Update `laravel-admin/.env` with the private IP you just used, the database name, and the password you set for `laravel_readonly`.

```dotenv
REALTIME_ENABLED=true
REALTIME_DB_HOST=YOUR_PBX_PRIVATE_IP
REALTIME_DB_PORT=5432
REALTIME_DB_DATABASE=asterisk
REALTIME_DB_USERNAME=laravel_readonly
REALTIME_DB_PASSWORD='YOUR_INVENTORY_PASSWORD'
REALTIME_WRITES_ENABLED=false
DIALPLAN_WRITES_ENABLED=false
```

From `laravel-admin`, run `docker compose up -d --force-recreate app web`. Sign in and check the dashboard. **“PBX connection is read-only”** confirms Laravel can connect with the inventory role. The `/up` endpoint checks Laravel itself.

## 4. Enable SIP account and dialplan management

Enable either feature independently. Keep the inventory login from section 3 configured. Run the PostgreSQL commands from the repo root on the PBX host.

### Create the writer logins

Open `psql` as the PBX database owner.

```bash
docker compose -f docker-compose.yml -f docker-compose.remote.yml exec postgres \
  sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB"'
```

At the `psql` prompt, run the statements for the features you want. Skip `CREATE ROLE` for an existing writer login. Each `\password` command prompts twice. Use a separate password for each login, then type `\q` to return to the shell.

```sql
CREATE ROLE laravel_pjsip_writer LOGIN;
\password laravel_pjsip_writer

CREATE ROLE laravel_dialplan_writer LOGIN;
\password laravel_dialplan_writer
```

### Enable PJSIP account management

Apply the [PJSIP writer grants](laravel-admin/database/pjsip-writer-grants.sql).

```bash
docker compose -f docker-compose.yml -f docker-compose.remote.yml exec -T postgres \
  sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -v ON_ERROR_STOP=1 -v writer_role=laravel_pjsip_writer --single-transaction -f -' \
  < laravel-admin/database/pjsip-writer-grants.sql
```

If you migrated existing endpoints, review the [`identify_by` backfill](laravel-admin/README.md#phase-4-pjsip-management) before applying it. It changes existing endpoints to use `auth_username`.

Add these settings to `laravel-admin/.env` using the password you set for `laravel_pjsip_writer`.

```dotenv
REALTIME_WRITES_ENABLED=true
REALTIME_WRITE_DB_USERNAME=laravel_pjsip_writer
REALTIME_WRITE_DB_PASSWORD='YOUR_PJSIP_WRITER_PASSWORD'
```

### Enable dialplan editing

Apply the [dialplan grants](laravel-admin/database/dialplan-grants.sql). If your inventory role has a different name, substitute it in `reader_role=`.

```bash
docker compose -f docker-compose.yml -f docker-compose.remote.yml exec -T postgres \
  sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -v ON_ERROR_STOP=1 -v reader_role=laravel_readonly -v dialplan_role=laravel_dialplan_writer --single-transaction -f -' \
  < laravel-admin/database/dialplan-grants.sql
```

Add these settings to `laravel-admin/.env` using the password you set for `laravel_dialplan_writer`.

```dotenv
DIALPLAN_WRITES_ENABLED=true
REALTIME_DIALPLAN_DB_USERNAME=laravel_dialplan_writer
REALTIME_DIALPLAN_DB_PASSWORD='YOUR_DIALPLAN_WRITER_PASSWORD'
```

### Apply and verify

After applying the grants and updating the environment, recreate the admin containers from the repo root.

```bash
cd laravel-admin
docker compose up -d --force-recreate app web
```

Sign in as a platform or tenant administrator and open a registered tenant. Check **Endpoints → Create SIP account** if you enabled the PJSIP writer, or **Dialplan** if you enabled dialplan editing. When you introduce a new tenant context, run `python3 asterisk/scripts/sync-realtime-contexts.py` from the PBX repo root. Verify registration and calling from a handset.

For phone profile downloads or the handset **Reboot** button, follow the [phone provisioning and AMI setup](laravel-admin/README.md#flyingvoice-fip10p-provisioning). Reboot requires a dedicated AMI account and a private listener on TCP `5038`. phpPgAdmin is optional. Its [Compose file](phppgaadmin/compose.yml) publishes port `82` on all interfaces by default. Change the binding to loopback or a private interface before starting it.

## Move an existing installation

- **PostgreSQL.** Take a logical backup of the PBX database and preserve the login roles and grants used by Asterisk and Laravel. Restore the database and roles, then verify endpoints, auth records, and routes. Plan the restore before using the target database. The first start against an empty data directory already creates a schema and demo routes.
- **Laravel.** Stop the admin app for a consistent final backup. Restore `database.sqlite` and `app-key` together into the target `application-data` volume. The key is required to decrypt stored phone profiles. Run `docker compose run --rm app initialize` in `laravel-admin` after restoring the volume and before starting the app.
- **Asterisk.** Transfer any required runtime files under `data/asterisk/`, including recordings, voicemail, and custom sounds. Preserve the runtime user permissions. Recreate or securely transfer the gitignored config and environment files, update host-specific addresses, and regenerate the Realtime contexts.
- **Cutover.** Pause writes for the final backup and restore. Verify admin login, SIP registration, two-way audio, and any phone provisioning you use on the new VM before switching DNS or handset settings.
