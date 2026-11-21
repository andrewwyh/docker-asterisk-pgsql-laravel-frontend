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
docker compose exec -T asterisk asterisk -rx 'realtime pgsql status'
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

## 4. Connect Laravel to the PBX

1. From the repo root, set `PBX_PRIVATE_BIND_ADDRESS` in the root `.env` to a private address on the PBX host that the admin container can reach, such as the host's Tailscale IPv4. Restrict TCP `5432` to the admin connection path. Apply the private publication below. This may recreate PostgreSQL. Continue using both root Compose files for subsequent PBX operations to retain the private binding.

   ```bash
   docker compose -f docker-compose.yml -f docker-compose.remote.yml \
     up -d --no-deps postgres
   ```

2. Create a dedicated PostgreSQL login for inventory. Use the reader grants in [`02-tenants.sql`](laravel-admin/tests/fixtures/02-tenants.sql) as a template, substituting your actual role and database names. In `laravel-admin/.env`, set `REALTIME_ENABLED=true` and fill in `REALTIME_DB_HOST`, `REALTIME_DB_DATABASE`, `REALTIME_DB_USERNAME`, and `REALTIME_DB_PASSWORD`. Set the host to the private address used above.
3. To create SIP accounts and edit dialplans through the UI, create separate restricted logins and apply [the PJSIP writer grants](laravel-admin/database/pjsip-writer-grants.sql) and [the dialplan grants](laravel-admin/database/dialplan-grants.sql). Follow the [admin README](laravel-admin/README.md) for the role variables, feature flags, and the `identify_by` backfill for existing endpoints. After editing `.env`, run `docker compose up -d --force-recreate app web` from `laravel-admin`.
4. Register a tenant in the UI, then create a SIP account and routes. When you introduce a new tenant context, run `python3 asterisk/scripts/sync-realtime-contexts.py` from the PBX repo root. Confirm the inventory connection from the dashboard, then verify registration and calling from a handset.

For phone profile downloads or the handset **Reboot** button, follow the [phone provisioning and AMI setup](laravel-admin/README.md#flyingvoice-fip10p-provisioning). Reboot requires a dedicated AMI account and a private listener on TCP `5038`. phpPgAdmin is optional. Its [Compose file](phppgaadmin/compose.yml) publishes port `82` on all interfaces by default. Change the binding to loopback or a private interface before starting it.

## Move an existing installation

- **PostgreSQL.** Take a logical backup of the PBX database and preserve the login roles and grants used by Asterisk and Laravel. Restore the database and roles, then verify endpoints, auth records, and routes. Plan the restore before using the target database. The first start against an empty data directory already creates a schema and demo routes.
- **Laravel.** Stop the admin app for a consistent final backup. Restore `database.sqlite` and `app-key` together into the target `application-data` volume. The key is required to decrypt stored phone profiles. Run `docker compose run --rm app initialize` in `laravel-admin` after restoring the volume and before starting the app.
- **Asterisk.** Transfer any required runtime files under `data/asterisk/`, including recordings, voicemail, and custom sounds. Preserve the runtime user permissions. Recreate or securely transfer the gitignored config and environment files, update host-specific addresses, and regenerate the Realtime contexts.
- **Cutover.** Pause writes for the final backup and restore. Verify admin login, SIP registration, two-way audio, and any phone provisioning you use on the new VM before switching DNS or handset settings.
