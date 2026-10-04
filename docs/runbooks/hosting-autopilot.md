# Hosting AutoPilot itself on a Linux VPS

UPGRADE-v2 Phase 6 (F14). Runbook, not automation — these steps run on a new
server and are deliberately left for a human.

## Why

AutoPilot currently runs from a Windows PC via `start.bat`. That means:

- **Webhooks only work while that PC is on.** A CI push at 2am does nothing.
- The queue worker dies with the terminal window.
- Reverb (the live deploy terminal) is equally unavailable.

Deploys themselves work fine from Windows — this is about availability of the
*trigger*, not the deploy.

## What stays on Windows

Mode B builds from a local source folder (`site.source_path` pointing at
`D:\My project\...`) can only run where that folder exists. Those stay a manual
trigger from the PC. The Laragon composer fallback in `getComposerCommand()`
stays for the same reason.

Sites that deploy from a git repo (`repo_url`, Mode C) work fine on the VPS and
are the ones worth moving first.

## Sizing

Small. 2 vCPU / 4GB covers it. The work is network-bound (SFTP upload) and the
builds happen on the target server, not here. Disk matters more than CPU:
`storage/app/` holds deploy zips and database dumps — 40GB+.

## Steps

### 1. Base packages

```bash
apt update && apt install -y php8.3-{fpm,cli,mysql,mbstring,xml,curl,zip,bcmath,gd} \
    mysql-server nginx supervisor unzip git

# Node for `npm run build` on Mode C deploys
curl -fsSL https://deb.nodesource.com/setup_22.x | bash - && apt install -y nodejs
```

`unzip`, `git` and `rsync` must exist — the deploy pipeline shells out to them.

### 2. Code and dependencies

```bash
git clone https://github.com/corasolution/autodeploy.git /var/www/autopilot
cd /var/www/autopilot
composer install --no-dev --optimize-autoloader
npm ci && npm run build
cp .env.example .env && php artisan key:generate
```

> **Do not reuse the Windows APP_KEY unless you also copy the database.** Server
> SSH passwords, panel tokens and webhook secrets are encrypted with it — a new
> key makes every stored credential undecryptable and they must all be re-entered.

### 3. Database

```bash
mysql -e "CREATE DATABASE autopilot_deploy CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -e "CREATE USER 'autopilot'@'localhost' IDENTIFIED BY 'a-strong-password';"
mysql -e "GRANT ALL ON autopilot_deploy.* TO 'autopilot'@'localhost';"
php artisan migrate --force
```

To carry the existing servers across, dump from the Windows box and import here
— **and keep the same APP_KEY**, per the warning above.

### 4. Permissions

```bash
chown -R www-data:www-data /var/www/autopilot
chmod -R 775 /var/www/autopilot/storage /var/www/autopilot/bootstrap/cache
```

### 5. Supervisor

Copy the configs from `deploy/supervisor/`, adjusting paths if you didn't use
`/var/www/autopilot`:

```bash
cp deploy/supervisor/*.conf /etc/supervisor/conf.d/
supervisorctl reread && supervisorctl update && supervisorctl status
```

Use **either** `autopilot-scheduler.conf` **or** a crontab line, never both.

### 6. nginx + TLS

Document root is `/var/www/autopilot/public`. Reverb needs a websocket proxy:

```nginx
location /app {
    proxy_pass http://127.0.0.1:8080;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
}
```

Then `certbot --nginx -d autopilot.yourdomain.com`. TLS is not optional here —
the login form carries the password that unlocks every server you deploy to.

### 7. Lock the dashboard down

This install holds SSH credentials for every server you manage. Treat it as
higher-value than any site it deploys.

- **Enable 2FA on every admin account.** `POST /two-factor/enroll`, scan, confirm.
- Login is rate-limited to 5/min per email+IP (Phase 6) — no action needed.
- **Optional IP allowlist**, if your team has static IPs. In nginx:

  ```nginx
  location /login {
      allow 203.0.113.0/24;   # office
      deny all;
      try_files $uri /index.php?$query_string;
  }
  ```

  Allowlist `/login` rather than `/`, so webhooks from GitHub's ranges still
  reach `/api/webhooks/deploy`.

- `APP_DEBUG=false` and `APP_ENV=production`. Debug mode on a public host leaks
  env values — including the credentials above — in any stack trace.

### 8. Point CI at it

Update `vars.AUTOPILOT_URL` in each GitHub repo to the new HTTPS URL. The
webhook secret is unchanged if you carried the database across; otherwise
rotate it from the site's webhook tab and update `secrets.AUTOPILOT_SECRET`.

## Verifying

```bash
supervisorctl status                      # all three RUNNING
curl -fsS https://autopilot.example.com/up
php artisan queue:work --once --queue=deployments   # drains one job by hand
```

Then trigger one real deploy from the UI and watch the live terminal — that
exercises the worker, Reverb and SSH in one go.
