# Deploying to DigitalOcean App Platform with Aiven MySQL

This is the demo deployment: one Docker image on DigitalOcean App Platform, and Aiven for MySQL on DigitalOcean. The first deploy only has to prove that the container boots, connects to MySQL over TLS, and answers `/up`. Use fictional data only. Free tiers are for this demo. They are not a production commitment (see `SECURITY.md` and `SYSTEM_OVERVIEW.md`).

Section 9 is the Render fallback. It runs this same image, with the same variables, on Render’s free web service.

No secret belongs in this file, in Git, or in a screenshot. Put secrets only in the provider dashboards.

The image is `docker/Dockerfile`. It runs nginx, PHP-FPM, `queue:work`, and `schedule:work` under supervisord. The branch you deploy must already be on GitHub and must contain that Dockerfile. `.env` is excluded from the image, so the running app reads configuration only from environment variables.

Dashboard labels change. Any step below that names a button, field, region code, plan name, or price is marked **verify in the provider dashboard**.

## 1. Environment variables

Set these on the App Platform web service before the first deploy you expect to succeed. Mark every row whose Secret column is Yes as an encrypted value. **Verify in the provider dashboard** what that control is called.

Changing a variable requires a new deploy. The entrypoint runs `php artisan config:cache` at startup, so a running container keeps the values it started with.

Leave `DB_URL` unset. Set the `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD` rows instead, using the separate fields from the Aiven connection panel. Copy the password from that password field. A password copied out of a connection URI is often percent-encoded and will not match.

| Variable | What it is | Example placeholder | Secret |
|---|---|---|---|
| `APP_NAME` | Name shown by the application | `University Portal` | No |
| `APP_ENV` | Environment name. Anything other than `local` turns on the boot checks | `production` | No |
| `APP_KEY` | Laravel encryption key. Generate it with the command in section 2 | `base64:<paste the line printed by key:generate --show>` | Yes |
| `APP_DEBUG` | Error detail shown to browsers. The container starts only when this is the string `false` | `false` | No |
| `APP_URL` | Public `https` origin. The boot check only requires a non-empty value. Use the placeholder for the first deploy, then replace it with the real hostname and redeploy (section 5) | `https://pending.example.com` | No |
| `APP_TIMEZONE` | Application clock | `Africa/Lagos` | No |
| `DB_CONNECTION` | Database driver. The container starts only when this is `mysql` | `mysql` | No |
| `DB_HOST` | Aiven hostname from the service connection panel | `mysql-example.aivencloud.com` | No |
| `DB_PORT` | Aiven TCP port from that same panel. Use the number shown there | `12345` | No |
| `DB_DATABASE` | Database name from that same panel | `defaultdb` | No |
| `DB_USERNAME` | Database user from that same panel | `avnadmin` | No |
| `DB_PASSWORD` | Database password from that same panel | `<paste from the Aiven console>` | Yes |
| `DB_SSL_CA` | Aiven CA certificate, pasted as PEM text. See section 3 | `-----BEGIN CERTIFICATE-----<paste the downloaded CA>-----END CERTIFICATE-----` | No |
| `SESSION_DRIVER` | Where login sessions are stored | `database` | No |
| `SESSION_SECURE_COOKIE` | Send the session cookie only over HTTPS. HttpOnly and SameSite=lax stay on in every environment. Set this on the live HTTPS site | `true` | No |
| `CACHE_STORE` | Cache backend. The scheduler heartbeat uses this | `database` | No |
| `QUEUE_CONNECTION` | Queue backend the in-container worker consumes | `database` | No |
| `MAIL_MAILER` | Mail transport. Keep `log` until Brevo is configured | `log` | No |
| `MAIL_FROM_ADDRESS` | From address stored for later mail | `hello@example.com` | No |
| `MAIL_FROM_NAME` | From name stored for later mail | `University Portal` | No |
| `MAIL_API_URL` | HTTPS host for the mail adapter. The app adds `/v3/smtp/email`. Not a secret | `https://api.brevo.com` | No |
| `MAIL_API_KEY` | Mail API key. Leave empty while `MAIL_MAILER=log` | *(empty)* | Yes |
| `MAIL_API_TIMEOUT` | HTTP timeout in seconds for the mail adapter | `10` | No |
| `MAIL_DAILY_LIMIT` | Documented daily cap. The scheduler enforces it later. Production refuses a real-adapter send when this is unset | `250` | No |
| `PAYMENT_PROVIDER` | Active payment adapter. Keep the demo gateway for this deploy | `demo` | No |
| `REMITA_ENV` | Remita environment. Stay on the demo environment | `demo` | No |
| `REMITA_MERCHANT_ID` | Remita merchant id. Leave empty while the provider is `demo` | *(empty)* | Yes |
| `REMITA_SERVICE_TYPE_ID` | Remita service type id. Leave empty for this deploy | *(empty)* | Yes |
| `REMITA_API_KEY` | Remita API key. Leave empty for this deploy | *(empty)* | Yes |
| `INTERSWITCH_MODE` | Interswitch environment. Stay on the test environment | `TEST` | No |
| `INTERSWITCH_MERCHANT_CODE` | Interswitch merchant code. Leave empty for this deploy | *(empty)* | Yes |
| `INTERSWITCH_PAY_ITEM_ID` | Interswitch pay item id. Leave empty for this deploy | *(empty)* | Yes |
| `INTERSWITCH_CLIENT_ID` | Interswitch client id. Leave empty for this deploy | *(empty)* | Yes |
| `INTERSWITCH_SECRET` | Interswitch secret. Leave empty for this deploy | *(empty)* | Yes |
| `FILESYSTEM_DISK` | File storage. `local` is correct for this demo. Files on the container disk disappear when the instance is replaced | `local` | No |
| `AWS_ACCESS_KEY_ID` | Spaces or S3 access key. Leave empty while the disk is `local` | *(empty)* | Yes |
| `AWS_SECRET_ACCESS_KEY` | Spaces or S3 secret key. Leave empty for this deploy | *(empty)* | Yes |
| `AWS_DEFAULT_REGION` | Spaces or S3 region. Leave empty for this deploy | *(empty)* | No |
| `AWS_BUCKET` | Spaces or S3 bucket. Leave empty for this deploy | *(empty)* | No |
| `AWS_USE_PATH_STYLE_ENDPOINT` | Path-style S3 addressing. Unused for this deploy | `false` | No |
| `DEMO_SEED_PASSWORD` | Password used only by a later seeder. Leave empty. This deploy does not seed data | *(empty)* | Yes |
| `BOOTSTRAP_SUPER_ADMIN_EMAIL` | Email of the first Super Admin. Leave empty until you are ready to create that account (section 10) | `admin@example.com` | No |
| `BOOTSTRAP_SUPER_ADMIN_PASSWORD_HASH` | Bcrypt hash from `php artisan portal:hash-password`. Leave empty until section 10. Delete it after the first password change | `$2y$12$<paste the hash>` | Yes |
| `RUN_MIGRATIONS` | When `true`, the entrypoint runs `php artisan migrate --force` before the web server starts. `true` for the first successful deploy, then `false` | `true` | No |
| `LOG_CHANNEL` | Log destination. The image default `stderr` is what App Platform can collect. Keep it | `stderr` | No |
| `LOG_LEVEL` | Log verbosity. The image default is enough | `info` | No |
| `PORT` | TCP port nginx listens on. The image default is `8080`. On App Platform, set this only when the dashboard does not inject it, and keep it equal to the HTTP port in section 5. On Render, do not set it (section 9) | `8080` | No |
| `APP_VERSION` | Optional label returned by `/health` | *(empty)* | No |

`DB_SSL_CA` is a public CA certificate, so it is not a secret. Still paste it only into the dashboard. Do not commit `ca.pem`.

Outside `local`, the container starts only when `APP_DEBUG` is `false` and `APP_KEY`, `APP_URL`, `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD` are all set. A blank `APP_DEBUG` fails that check.

Leave mail SMTP variables unset. This deploy uses `MAIL_MAILER=log`.

## 2. Generate `APP_KEY` without committing it

On Windows PowerShell, from the project root:

```powershell
php artisan key:generate --show
```

The command prints one line beginning with `base64:`. Copy that line into the App Platform `APP_KEY` value and mark it encrypted (**verify in the provider dashboard**).

`--show` prints the key and leaves `.env` unchanged. Generate a new key for this demo. Keep the local `.env` key on this machine. After the first successful boot, keep the same demo `APP_KEY`. Replacing it later invalidates encrypted cookies and sessions.

The key exists only in the dashboard and in your terminal scrollback. Clear the terminal after you have saved it, and do not paste it into a file in this repository.

## 3. How the Aiven CA reaches the container

Aiven requires TLS. Download the service CA from the Aiven connection details. **Verify in the provider dashboard** the control name (it is often a “CA certificate” or `ca.pem` download on the service overview).

Open `ca.pem` in a text editor. Copy the whole file into the `DB_SSL_CA` value. Keep these lines exactly, including the space in each header:

```text
-----BEGIN CERTIFICATE-----
-----END CERTIFICATE-----
```

A bundle has one pair of those lines per certificate. Paste every block. A real line break between base64 lines is the best paste. One pair of straight quotes around the whole value is stripped. A filesystem path is not useful here: that file is not inside the image.

App Platform sometimes saves the value as one line, replaces line breaks with spaces, or stores a line break as the two characters `\` and `n`. Leave that saved value as it is. On startup, `docker/entrypoint.sh` turns those forms back into a normal PEM:

1. If `DB_SSL_CA` is empty, it leaves the connection without a CA file.
2. If the value contains `-----BEGIN CERTIFICATE-----` and `-----END CERTIFICATE-----`, it rebuilds each block with 64-character base64 lines, writes `/var/www/html/storage/app/certs/mysql-ca.pem`, sets the file mode to `600`, and checks the file with `openssl`. It then sets `DB_SSL_CA` to that path.
3. If the value has no certificate header, it treats it as a filesystem path and uses it only when that path is already a readable file inside the container.

The entrypoint does this before `php artisan config:cache`. PHP then points PDO at the file. The file is recreated on every boot. The container disk is ephemeral, which is fine for this certificate.

A value that is not a certificate stops the boot. The runtime log states the problem and does not print the value. Paste the Aiven CA again. A path such as `C:\certs\ca.pem` fails for the same reason: that file is not in the container.

**How to tell it worked.** After deploy, the runtime log contains a line like:

```text
Database CA certificate written (1 block(s)).
```

The number is how many certificate blocks were rebuilt. That line does not include the certificate. Then open `https://<your-app>/health`. A working TLS connection reports `"database": "ok"`.

You can rehearse the same rebuild on this Windows machine before you deploy. Put the downloaded CA in the project root as `ca.pem`. That file is not gitignored, so delete it when the check finishes and do not commit it. From PowerShell in the project root:

```powershell
$env:PATH = "C:\Program Files\Git\usr\bin;" + $env:PATH
$env:MYSQL_CA_DIRECTORY = Join-Path $env:TEMP "mysql-ca-check"
New-Item -ItemType Directory -Force -Path $env:MYSQL_CA_DIRECTORY | Out-Null
$env:DB_SSL_CA = (Get-Content -Raw .\ca.pem) -replace "`r?`n", "\n"
& "C:\Program Files\Git\bin\sh.exe" .\docker\entrypoint.sh --write-database-ca
```

The `-replace` stores literal `\n` sequences, which is the awkward dashboard case. Success prints `Database CA certificate written (N block(s)).` The file `%TEMP%\mysql-ca-check\mysql-ca.pem` starts with `-----BEGIN CERTIFICATE-----` and its base64 lines are at most 64 characters. Delete `ca.pem` and clear `DB_SSL_CA` when you are done:

```powershell
Remove-Item .\ca.pem
Remove-Item Env:DB_SSL_CA
```

`tests/Unit/MysqlCaEntrypointTest.php` covers the same rebuild with a throwaway certificate, so `composer check` does not need your Aiven file.

## 4. Create the Aiven MySQL service

Do this before the first App Platform deploy so the connection values exist.

1. In the Aiven console, create a MySQL service. **Verify in the provider dashboard:** cloud provider DigitalOcean, the free plan, and a region that also exists for the App Platform app. Aiven region codes and DigitalOcean region slugs are different strings. Pick the pair yourself in the two consoles and use that pair for both services. Same cloud and same region keep each query off a long network hop.
2. Open the service connection details and copy host, port, database name, user, and password into the variables in section 1. The port is whatever Aiven shows. It is often different from `3306`.
3. Download the CA and paste it into `DB_SSL_CA` as described in section 3.
4. Set the IP allow-list as described in section 6.
5. The free plan can be powered off after a period of no use. **Verify in the provider dashboard** whether the service is running before you treat a connection error as an application fault. Power it on and wait until Aiven reports it running.

**Verify in the provider dashboard** whether the free plan lets you create a database other than the one shown in the connection panel. Use the name the panel shows.

## 5. App Platform settings

**Billing, before you deploy.** In the create form, read the price of the instance size you select. Then set a DigitalOcean spending alert for this account. **Verify in the provider dashboard** where monthly spend alerts are configured. Do that before the first deploy.

Create one app from the GitHub repository. **Verify in the provider dashboard** the current create-app flow, including the GitHub authorization prompt.

Use these settings:

| Setting | Value |
|---|---|
| Source | The GitHub repository, on the branch that contains `docker/Dockerfile` |
| Resource type | Web service. One public HTTP service. The queue worker and scheduler are already inside this container |
| Build | Dockerfile. Source directory is the repository root. Dockerfile path is `docker/Dockerfile` |
| Run command | Empty, so the image entrypoint starts supervisord. If the form requires a command, use `/usr/bin/supervisord -c /etc/supervisor/supervisord.conf` |
| HTTP port | `8080` |
| Health check | HTTP path `/up` |
| Instance count | `1` |
| Instance size | Smallest plan with at least 1 GiB of memory. See below |
| `RUN_MIGRATIONS` | `true` on the first deploy that must create the tables, then `false` |

**Verify in the provider dashboard** the field names for source directory, Dockerfile path, HTTP port, health check path, run command, and instance size. The image build context has to be the repository root. `docker/Dockerfile` copies `composer.json`, `package.json`, and the rest of the application from that root. A source directory of `docker/` makes the build fail.

App Platform uses its own health check. The `HEALTHCHECK` instruction in the Dockerfile does not replace the platform check. Point the platform check at `http` port `8080` and path `/up`.

`/up` is the liveness probe. It answers when PHP is up. `/health` is a deeper JSON check (database and scheduler heartbeat) and can return HTTP 503 while MySQL is down. Use `/up` for the platform probe so a database blip does not restart the instance. After deploy, open `/health` yourself.

`bootstrap/app.php` trusts every connecting address (`at: '*'`). It honours `X-Forwarded-Host`, `X-Forwarded-Proto`, `X-Forwarded-Port`, and `X-Forwarded-Prefix`, so the app sees HTTPS behind App Platform or Render. It does not honour `X-Forwarded-For`. A client can forge that header, and neither host publishes a stable proxy range, so the app does not treat it as the client address.

Login limits use the socket address (`REMOTE_ADDR`), not `X-Forwarded-For`. On Render that address is the platform proxy, shared by every visitor to the instance. The identifier limit is 10 failures in 15 minutes and is the control that still belongs to one account. The other limit is 5 failures per minute for one identifier from one socket address. `/health` uses `$request->ip()`, which is that same socket address while `X-Forwarded-For` stays untrusted.

What remains uncertain on Render: the platform does not publish a proxy range, and it is not verified from this repository whether Render overwrites or appends `X-Forwarded-For`. Until a production host publishes ranges, do not trust that header. The same gap applies to `audit_logs` when that column is written (TASK-046).

The first boot caches config, routes, and views, and may run migrations, before nginx listens. Give the health check an initial delay of at least 60 seconds. On the first deploy, a longer delay is safer. **Verify in the provider dashboard** the initial-delay field name.

**Instance size.** This container runs nginx, PHP-FPM (up to a few 128 MB workers), a queue worker limited to 96 MB, and the scheduler in one instance. A 512 MiB plan is too small for that set and is likely to be killed. For this health-page demo, choose the smallest listed plan that has at least 1 GiB of memory. **Verify in the provider dashboard** the current size names and prices. Production sizing is a separate choice and is described in `SYSTEM_OVERVIEW.md`. App Platform prices are not the Droplet prices in that document.

**HTTP port and `PORT`.** Set the service HTTP port to `8080`. The entrypoint templates nginx with the `PORT` variable, which defaults to `8080` in the image. **Verify in the provider dashboard** whether App Platform also injects `PORT`. If it does, that value has to be `8080` as well.

**`APP_URL`.** Outside `local`, the entrypoint and the application boot guard refuse to start when `APP_URL` is missing. They only check that the value is present. They do not check that the hostname answers. For the first deploy, set:

```text
APP_URL=https://pending.example.com
```

That placeholder lets the container boot before App Platform has shown the real hostname. Email links and the secure session cookie use whatever `APP_URL` was at startup, so the placeholder is only for that first boot. When the dashboard shows the `ondigitalocean.app` hostname (**verify in the provider dashboard** where it appears), set `APP_URL` to `https://` plus that hostname and redeploy. The new deploy rebuilds the config cache with the real origin. A deploy with no `APP_URL` at all logs `APP_URL is missing` and the health check fails.

**`RUN_MIGRATIONS`.** Set it to `true` for the first deploy so the entrypoint runs `php artisan migrate --force` once. When that deploy is healthy and the log shows the migrations finished, set `RUN_MIGRATIONS` to `false` and redeploy. Later restarts then skip migrate. When a future release adds migrations, set it back to `true` for one deploy, confirm they applied, then set it to `false` again.

Deploy one instance. A second instance would run a second queue worker and a second scheduler.

## 6. Aiven allow-list for this demo

App Platform’s outbound addresses can change, and a basic app does not have one stable egress address. **Verify in the provider dashboard** whether a dedicated egress IP add-on exists and what it costs. This demo does not depend on that add-on.

For the demo, the Aiven IP allow-list has to permit every address, or the app will lose the database the next time its egress address changes. **Verify in the provider dashboard** the allow-list control. Typical values are `0.0.0.0/0` for IPv4 and, if the form has a separate IPv6 field, the matching “all addresses” value there.

That open list is acceptable only for this demo, and only with fictional data. Authentication and TLS still apply. Production must restrict the allow-list to the application’s egress addresses. Do that before any real student data is stored. An open allow-list is not a production setting.

If Aiven’s default is already “allow all”, leave it that way for the demo and record that it must be tightened for production. If you add a single office IP, the App Platform app cannot connect.

## 7. First-deploy checklist

- [ ] The GitHub branch contains `docker/Dockerfile`, and that commit is on GitHub.
- [ ] Aiven MySQL is on DigitalOcean, in the region you paired with the app, and the service is running.
- [ ] Host, port, database, user, and password are copied from the Aiven connection panel into the App Platform variables. `DB_URL` is unset.
- [ ] `DB_SSL_CA` is the Aiven CA text, including `-----BEGIN CERTIFICATE-----` and `-----END CERTIFICATE-----`. A flattened paste is acceptable (section 3).
- [ ] The Aiven allow-list allows all addresses for this demo (section 6).
- [ ] `APP_KEY` came from `php artisan key:generate --show` and is stored only as an encrypted dashboard value.
- [ ] `APP_ENV=production`, `APP_DEBUG=false`, `DB_CONNECTION=mysql`.
- [ ] The instance price was read in the create form, and a spending alert was set, before deploy.
- [ ] `APP_URL` is `https://pending.example.com` for the first deploy.
- [ ] `SESSION_DRIVER=database`, `SESSION_SECURE_COOKIE=true`, `CACHE_STORE=database`, `QUEUE_CONNECTION=database`.
- [ ] `MAIL_MAILER=log`, `MAIL_API_URL=https://api.brevo.com`, `PAYMENT_PROVIDER=demo`, `FILESYSTEM_DISK=local`. `MAIL_API_KEY`, Remita, Interswitch, and Spaces secrets are empty.
- [ ] `RUN_MIGRATIONS=true` for this first deploy only.
- [ ] The web service uses the repository Dockerfile at `docker/Dockerfile`, HTTP port `8080`, health check path `/up`, and one instance with at least 1 GiB of memory.
- [ ] The deploy finishes, the runtime log contains `Database CA certificate written`, and `https://<your-app>/up` returns HTTP 200.
- [ ] `APP_URL` is then set to `https://` plus the real hostname, and a redeploy still passes `/up`.
- [ ] `https://<your-app>/health` returns HTTP 200 JSON with `"status": "ok"`, `"database": "ok"`, and `"heartbeat": "ok"`.
- [ ] Runtime logs show the queue worker and the scheduler started (section 8).
- [ ] `RUN_MIGRATIONS` is then set to `false`, and a redeploy still passes `/up`.
- [ ] No secret was committed, and the data in Aiven is fictional.

This checklist does not load seed data. The first Super Admin is section 10, after `/health` is ok. Live Remita or Interswitch credentials stay out of this demo.

## 8. Troubleshooting

Read the App Platform runtime logs first. The entrypoint prints `Refusing to boot. ...` and then exits, so nginx never listens and the health check fails. **Verify in the provider dashboard** where build logs and runtime logs are shown. They are different: a failed `COPY` or `npm run build` is a build log; a missing `APP_KEY` is a runtime log.

### Database connection errors

| What you see | What to check |
|---|---|
| `Refusing to boot` and a missing `DB_*` name | That variable is empty on the web service. Set it and redeploy |
| `DB_SSL_CA must be a PEM certificate` or `not a valid certificate` or `body is not valid PEM` | The log does not contain the value. Paste the Aiven CA again, including both `BEGIN CERTIFICATE` and `END CERTIFICATE` lines (section 3). A missing `Database CA certificate written` line means this step did not finish |
| SSL, certificate, or “unable to get local issuer” errors from MySQL | The boot line `Database CA certificate written` is absent, or the saved value is truncated. Re-paste the Aiven CA (section 3) |
| Timeout or “connection refused” | `DB_HOST` and `DB_PORT` must match the Aiven panel, including the non-default port. Then check the allow-list (section 6) and that the Aiven service is running, not powered off |
| Access denied | `DB_USERNAME` and `DB_PASSWORD` must be the console password, not a percent-encoded password from a URI |
| Unknown database | `DB_DATABASE` must be the name shown by Aiven |

A firewall allow-list that contains only your home or office address blocks App Platform. For this demo, allow all addresses (section 6). Production has to replace that with the app’s real egress addresses.

Migrations run before nginx starts. A database error during `RUN_MIGRATIONS=true` stops the container, and the health check never sees `/up`. Fix the connection, then redeploy.

### Health check failing

- The platform path is `/up` on port `8080`. A check against `/` or `/health`, or against another port, fails even when the app is fine.
- The runtime log says `Refusing to boot` when `APP_DEBUG` is not `false` or a required variable from section 1 is missing. `/up` cannot answer until that check passes.
- The initial delay is shorter than startup. Config cache plus the first migration can take longer than a few seconds. Raise the delay (**verify in the provider dashboard**) and redeploy.
- The instance is out of memory. A 512 MiB plan is too small for this container. Move to a plan with at least 1 GiB and redeploy.
- The build failed, so no container is listening. Read the build log. The Dockerfile path must be `docker/Dockerfile` and the source directory must be the repository root.
- `/health` returns 503 when the database probe fails or the scheduler heartbeat is older than two minutes. That is a degraded status for you to read. Keep the platform health check on `/up`.

### Queue worker not running

The worker is a supervisord program in this same container. It runs:

`php artisan queue:work --sleep=3 --tries=3 --max-time=3600 --memory=96 --timeout=90`

There is no separate App Platform worker component to create. A second component would be a second copy of the whole stack.

`/health` does not prove the worker is consuming jobs. The heartbeat only shows that `portal:heartbeat` ran (once at container start, then every minute from the scheduler).

In the runtime logs, confirm a `queue:work` process started and is still running. If the container is restarting, the worker restarts with it and will exit again until the boot error is fixed. `QUEUE_CONNECTION` must be `database`. The `jobs` table is created by the first migration, so a boot with `RUN_MIGRATIONS=false` against an empty database leaves the worker unable to query that table. Set `RUN_MIGRATIONS=true`, deploy once, then set it back to `false`.

### 500 errors

Keep `APP_DEBUG=false`. A 500 page in the browser is generic on purpose. The detail is in the runtime log.

- Missing tables: the first deploy ran with `RUN_MIGRATIONS` unset or `false`. Set it to `true`, redeploy, confirm migrate finished, then set it to `false`.
- `APP_KEY` is not a `base64:` key from `php artisan key:generate --show`. Generate it again with section 2 and redeploy. Sessions created with the previous key are discarded.
- `APP_URL` is an `http://` URL or a different host than the one in the browser. Set it to the `https` hostname App Platform serves and redeploy.
- A variable was changed in the dashboard but the app was not redeployed, so the cached config is stale. Redeploy.
- The log mentions a write error under `storage`. The entrypoint creates the storage directories on boot as `www-data`. A crash before that step is the boot check, not permissions. Read the `Refusing to boot` line.

The load balancer is already trusted for forwarded HTTPS and client IP. You do not set a trusted-proxy variable for this host.

## 9. Render fallback (same image)

Use this when App Platform is not available. The service is still one container from `docker/Dockerfile`: nginx, PHP-FPM, `queue:work`, and `schedule:work`. The database stays the Aiven MySQL service from sections 3, 4, and 6. The queue worker and scheduler already run inside this container.

No secret belongs in `render.yaml`, in Git, or in a screenshot. `sync: false` in the blueprint means Render asks for that value in the dashboard and does not store it in the file.

Dashboard labels change. Any step below that names a button, field, region, or plan is marked **verify in the provider dashboard**.

### Create the web service

Create one web service from the GitHub repository. **Verify in the provider dashboard** the current create flow, including the GitHub authorization prompt. The branch must already be on GitHub and must contain `docker/Dockerfile`.

Use these settings:

| Setting | Value |
|---|---|
| Source | The GitHub repository, on the branch that contains `docker/Dockerfile` |
| Runtime | Docker |
| Dockerfile path | `docker/Dockerfile` |
| Docker build context | The repository root (`.`) |
| Start command | Empty, so the image entrypoint starts supervisord. If the form requires a command, use `/usr/bin/supervisord -c /etc/supervisor/supervisord.conf` |
| Instance | Free (`free`: 0.1 CPU, 512 MB). See Free-tier behaviour below |
| Instance count | `1` |
| Health check path | `/up` |
| Environment variables | Every row in section 1, with the Render differences in the next subsection |

**Verify in the provider dashboard** the field names for Dockerfile path, Docker build context, health check path, and instance type. The build context has to be the repository root. `docker/Dockerfile` copies the application from that root. A context of `docker/` makes the build fail.

Choose the region nearest the Aiven service. Render’s regions are Oregon, Ohio, Virginia, Frankfurt, and Singapore. **Verify in the provider dashboard** the current list. The region cannot be changed after the service is created. Aiven on DigitalOcean and Render are different clouds, so this fallback always crosses a network boundary. Pick the Render region closest to the Aiven region you already chose.

`render.yaml` at the repository root is the same service. Applying that blueprint in the Render dashboard creates it and prompts for every `sync: false` variable. You can create the service by hand instead and ignore the file. Do not put secret values into the file either way.

### Environment variables

Set every variable in the section 1 table. Mark every Secret = Yes row as a secret. **Verify in the provider dashboard** what that control is called. Generate `APP_KEY` with section 2. Paste `DB_SSL_CA` as in section 3. The Aiven allow-list stays open for this demo (section 6), because Render’s outbound address can change too.

These rows differ from App Platform:

| Variable | On Render |
|---|---|
| `PORT` | Leave it unset. Render injects it (the default is `10000`). The image listens on that value. Setting `8080` here makes nginx listen on the wrong port |
| `APP_URL` | `https://pending.example.com` for the first deploy, then `https://` plus the `onrender.com` hostname the dashboard shows, then redeploy |
| `RUN_MIGRATIONS` | `true` for the first deploy that must create tables, then `false`, then redeploy |
| `LOG_CHANNEL` | Keep `stderr` so the Render logs can collect it |

Changing a variable requires a new deploy. The entrypoint caches config at startup.

If the blueprint form requires a value for an unused secret (`MAIL_API_KEY`, the Remita and Interswitch secrets, the Spaces keys, `DEMO_SEED_PASSWORD`), enter a single hyphen. Those features stay on the demo settings in section 1, so the hyphen is not used. Leave the field empty when the form allows it. `MAIL_API_URL` is not a secret; set it to `https://api.brevo.com`.

### Health check

Set the health check path to `/up`. Render sends `GET /up` and expects a successful response. `/up` answers when PHP is up. `/health` can return HTTP 503 while MySQL is down, so a database blip would restart the instance. After deploy, open `/health` yourself.

The first boot caches config, routes, and views, and may run migrations, before nginx listens. Render waits for the health check during the deploy (the platform limit is on the order of 15 minutes). **Verify in the provider dashboard** whether an initial-delay field is shown. If it is, set it to at least 60 seconds.

The `HEALTHCHECK` instruction in the Dockerfile calls `http://127.0.0.1:${PORT}/up`. That check follows the same `PORT` value. It does not replace Render’s health check. Point the platform check at `/up`.

### `PORT`

Render sets `PORT` for the web service. The default is `10000`. The image’s own default is `8080`, and it applies only when `PORT` is unset. A value from the platform replaces that default.

On startup, `docker/entrypoint.sh` reads `PORT`, rejects a value that is not a number from 1 to 65535, and writes it into the nginx `listen` directive in place of `__PORT__`. The template is `listen <port>;`, which nginx binds on all interfaces, so Render’s proxy can reach it. `EXPOSE 8080` in the Dockerfile does not choose the port.

Do not add `PORT` to `render.yaml` and do not set it in the dashboard. Leave Render’s value. Confirm in the runtime log that the process stayed up, then open `https://<your-service>.onrender.com/up` and expect HTTP 200.

A local run of this same image with `PORT=10000` served `/up` with HTTP 200 on port 10000. Connections to port 8080 were refused. The nginx config line was `listen 10000;`. `/health` on that run returned HTTP 200 with `"database": "ok"` and `"heartbeat": "ok"`.

### Free-tier behaviour

The free web service sleeps after 15 minutes without an inbound HTTP request or WebSocket message. The next request wakes it. Waking takes about a minute, and Render shows a loading page in the browser while that happens.

Wake it before a demo. The day before, and again the morning of the demo, open `https://<your-service>.onrender.com/up` and wait until that address returns HTTP 200, so the service is already awake. Confirm the Aiven service is running at the same time (section 4). A database that is powered off still fails `/health` after the app wakes.

The container disk is ephemeral. Files written inside the instance are lost when it sleeps, restarts, or redeploys. Uploads on `FILESYSTEM_DISK=local` do not survive. Rows in Aiven do survive, including sessions, cache, and queued jobs. The CA file is written again from `DB_SSL_CA` on every boot. The free plan cannot attach a persistent disk. Do not add one for this demo.

The free instance is 0.1 CPU and 512 MB of RAM. Section 5 sizes this same container for at least 1 GiB on App Platform, because nginx, PHP-FPM, the queue worker, and the scheduler share one instance. On this fallback the process can be killed when it runs out of memory. **Verify in the provider dashboard** the current free-plan limits before you rely on it for a demo. One instance is the maximum on the free plan.

The free plan also blocks outbound traffic on ports 25, 465, and 587. `MAIL_MAILER=log` does not use those ports.

### Blueprint file

`render.yaml` defines one Docker web service named `university-portal` on plan `free`, with `dockerfilePath: ./docker/Dockerfile`, `dockerContext: .`, `numInstances: 1`, and `healthCheckPath: /up`. Preview environments are off, so a pull request does not start a second copy of the stack.

Stable demo settings (such as `APP_DEBUG=false`, `DB_CONNECTION=mysql`, and `PAYMENT_PROVIDER=demo`) are plain values in the file. Everything that is a secret, a password, the Aiven CA, the database host details, `APP_KEY`, `APP_URL`, or `RUN_MIGRATIONS` is `sync: false`. Render prompts for those during the first blueprint create. Later syncs leave `sync: false` values alone. Add any new secret in the dashboard. Do not switch those keys to hardcoded values.

The file does not set `region`. Render’s default for a new service is Oregon. Before the first apply, add a `region` line if another region is closer to Aiven (`oregon`, `ohio`, `virginia`, `frankfurt`, or `singapore`). **Verify in the provider dashboard.** That value cannot be changed later.

The file does not set `PORT`.

### Render checklist

- [ ] The GitHub branch contains `docker/Dockerfile` and `render.yaml`, and that commit is on GitHub.
- [ ] The service is a Docker web service, free instance, one instance, Dockerfile `docker/Dockerfile`, build context the repository root.
- [ ] The health check path is `/up`.
- [ ] Every section 1 variable is set. `PORT` is not set. Secrets and `DB_SSL_CA` exist only in the dashboard.
- [ ] Aiven is running, the allow-list allows all addresses for this demo (section 6), and `DB_SSL_CA` is the Aiven CA.
- [ ] `APP_URL` is `https://pending.example.com` for the first deploy, then the real `https://…onrender.com` origin, with a redeploy after the change.
- [ ] `RUN_MIGRATIONS=true` for the first deploy only, then `false`.
- [ ] `https://<your-service>.onrender.com/up` returns HTTP 200, and `/health` returns HTTP 200 with `"database": "ok"` and `"heartbeat": "ok"`.
- [ ] The service was opened at `/up` until it answered, before anyone treats it as awake.
- [ ] No secret was committed.
- [ ] The first Super Admin was created with section 10, then both bootstrap variables were removed.

## 10. First Super Admin

Do this after `/health` reports the database ok and migrations have run. The password is never a command argument, so it cannot land in shell history.

On your machine, from this repository, with `BCRYPT_ROUNDS` matching the live site (12 in `.env.example`):

```powershell
php artisan portal:hash-password
```

The command asks for the password twice, without showing it, and prints one bcrypt hash. Copy that hash into the host dashboard as `BOOTSTRAP_SUPER_ADMIN_PASSWORD_HASH` and mark it secret. Set `BOOTSTRAP_SUPER_ADMIN_EMAIL` to the address you will sign in with. Leave both empty until this step. Do not commit either value.

Deploy. `docker/entrypoint.sh` runs `php artisan create-super-admin --no-interaction` only when `APP_ENV` is `production` and both variables are set. The account is Active, must change the password, and the temporary password expires 24 hours after creation. The role is Super Admin with no faculty and no department. The display name is Super Admin. Sign in at `/login`, then change the password.

Delete both variables and redeploy. While they are still set and any Super Admin role assignment exists, including a deactivated user, every boot logs this line and does not create another account:

```text
Bootstrap variables are still set; remove BOOTSTRAP_SUPER_ADMIN_EMAIL and BOOTSTRAP_SUPER_ADMIN_PASSWORD_HASH.
```

That line does not include the email or the hash. If the same email still has `must_change_password` true, that boot also stores the hash again and sets `temp_password_expires_at` to 24 hours from now. After the password has been changed, the bootstrap leaves the account alone.

A failed non-interactive bootstrap does not stop the web server. The entrypoint keeps one line and continues. That line has a reason code and the exit code. It has no stack trace, no email, no hash, and no password.

```text
Super Admin bootstrap failed: tables_missing exit=1. run migrations first.
```

| Code | What to do |
| --- | --- |
| `missing_variable` | Set both `BOOTSTRAP_SUPER_ADMIN_EMAIL` and `BOOTSTRAP_SUPER_ADMIN_PASSWORD_HASH`. |
| `invalid_email` | Set `BOOTSTRAP_SUPER_ADMIN_EMAIL` to one valid address. |
| `hash_rejected` | Run `php artisan portal:hash-password` again and replace `BOOTSTRAP_SUPER_ADMIN_PASSWORD_HASH`. |
| `database_unreachable` | Check that the database is running and that the `DB_*` variables match it. |
| `tables_missing` | Run migrations first. |
| `super_admin_exists` | A Super Admin already exists, so nothing was created. Remove both bootstrap variables. This outcome exits 0 and still prints the reminder above. |
| `duplicate_email` | That address is already a user. Use a different address, or remove that user before trying again. |
| `unexpected_error` | Nothing was created. Fix the database, then deploy again. |

The variable name must be exactly `RUN_MIGRATIONS`.

When a shell is available, `php artisan create-super-admin` asks for the name, email, and a hidden password instead of reading those variables. It still refuses to create a second Super Admin.
