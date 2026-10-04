# CLAUDE.md — AutoPilot Deploy Tool
# Laravel 13 + React + MySQL → cPanel / aaPanel / OpenPanel

> This file governs how Claude Code operates inside this project.
> Claude Code must read this file fully before executing any task.

**UPGRADE-v2 is complete** (phases 1–7, see `docs/UPGRADE-v2.md`). Phase 6's
VPS move is a runbook, not code — see `docs/runbooks/hosting-autopilot.md`.

> The AI layer currently returns fallbacks: the Anthropic account has no
> credit. Run `php artisan autopilot:ai-ping` to check. Model ids are correct
> (`claude-sonnet-5` / `claude-haiku-4-5-20251001`) — do not "fix" them.

---

## 🧭 Project Overview

**Product:** AutoPilot Deploy — an AI-powered deployment automation tool
**Purpose:** Automate full-stack deployment of Laravel 13 + React projects to cPanel, aaPanel and OpenPanel servers via SSH, SFTP, and hosting control panel APIs
**Stack:** Laravel 13 · React (Inertia.js) · MySQL · Tailwind CSS
**Powered by:** Claude API — `claude-sonnet-5` for diagnosis and risk audit, `claude-haiku-4-5-20251001` for log triage

---

## 📁 Project Structure

```
autopilot-deploy/
├── app/
│   ├── Console/Commands/          # Artisan deploy commands
│   ├── Http/Controllers/
│   │   ├── DeployController.php   # Deployment trigger + poll API
│   │   ├── ServerController.php   # Server profile CRUD
│   │   ├── SiteController.php     # Site (app) CRUD per server
│   │   └── LogController.php      # Deployment log viewer
│   ├── Services/
│   │   ├── DeployService.php      # Core deployment orchestrator (6 phases)
│   │   ├── SshService.php         # SSH + SFTP via phpseclib3
│   │   ├── CpanelApiService.php   # cPanel UAPI / WHM wrappers
│   │   ├── AaPanelApiService.php  # aaPanel REST API wrappers
│   │   ├── OpenPanelApiService.php # OpenPanel REST API (JWT auth, port 2087)
│   │   ├── ClaudeAgentService.php # Claude API — AI decision engine
│   │   └── RollbackService.php    # Rollback manager
│   ├── Models/
│   │   ├── Server.php             # Server profiles (one per VPS/host)
│   │   ├── Site.php               # Application per server (many per server)
│   │   ├── Deployment.php         # Deployment records (has server_id + site_id)
│   │   └── DeployLog.php          # Per-step logs
├── resources/
│   └── js/
│       ├── Pages/
│       │   ├── Dashboard.jsx           # Deployment dashboard
│       │   ├── Servers/
│       │   │   ├── Index.jsx           # Server list
│       │   │   ├── Create.jsx          # Add server (SSH only, no deploy fields)
│       │   │   ├── Edit.jsx            # Edit server
│       │   │   └── Show.jsx            # Server detail + site cards + Deploy buttons
│       │   ├── Sites/
│       │   │   ├── Create.jsx          # Add app to server
│       │   │   └── Edit.jsx            # Edit app (includes .env section)
│       │   └── Deployments/
│       │       └── Show.jsx            # Live terminal with 2s polling
│       └── Components/
│           └── Terminal.jsx            # Live log streaming component
├── database/
│   └── migrations/
│       ├── ..._create_servers_table.php
│       ├── ..._create_sites_table.php
│       ├── ..._create_deployments_table.php
│       ├── ..._create_deploy_logs_table.php
│       ├── 2026_04_19_000002_add_site_id_to_deployments_table.php
│       ├── 2026_04_19_000003_add_env_content_to_sites_table.php
│       └── 2026_04_19_000004_change_output_to_longtext_in_deploy_logs.php
├── config/
│   └── autopilot.php              # AutoPilot config (panel types, defaults)
├── routes/
│   ├── web.php                    # Includes site routes + poll route
│   └── api.php                    # Webhook endpoints
└── CLAUDE.md                      # ← You are here
```

---

## ⚙️ Tech Stack & Versions

| Layer | Technology | Version |
|-------|-----------|---------|
| Backend | Laravel | 13.x |
| Frontend | React + Inertia.js | 18.x / 2.x |
| Styling | Tailwind CSS | 3.x |
| Database | MySQL | 8.x |
| AI Engine | Claude API | claude-sonnet-5 (smart) / claude-haiku-4-5-20251001 (fast) |
| SSH/SFTP | phpseclib/phpseclib | 3.x |
| Queue | Laravel Queue (database driver) | — |
| Realtime | Laravel Echo + Reverb | — |

---

## 🔑 Environment Variables

```env
# App
APP_NAME="AutoPilot Deploy"
APP_ENV=local
APP_KEY=
APP_DEBUG=true
APP_URL=http://localhost

# Database
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=autopilot_deploy
DB_USERNAME=root
DB_PASSWORD=

# Claude AI
ANTHROPIC_API_KEY=sk-ant-...
AUTOPILOT_MODEL_SMART=claude-sonnet-5
AUTOPILOT_MODEL_FAST=claude-haiku-4-5-20251001
ANTHROPIC_MAX_TOKENS=2048

# Deploy notifications (optional)
TELEGRAM_BOT_TOKEN=
TELEGRAM_CHAT_ID=

# SSH defaults
SSH_TIMEOUT=30
SSH_PORT=22

# Queue
QUEUE_CONNECTION=database

# Broadcasting (for live terminal)
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=
REVERB_APP_KEY=
REVERB_APP_SECRET=
REVERB_HOST=localhost
REVERB_PORT=8080
```

---

## 🏗️ Architecture — One Server, Many Apps

The data model is:
- **Server** — one record per VPS/host. Stores SSH credentials, panel type, and **`web_user`** (the OS user PHP-FPM runs as; defaults to `www` for aaPanel).
- **Site** — one record per application on that server. Stores `deploy_path`, **`source_path`**, `php_binary`, `app_url`, encrypted `env_content`.
- **Deployment** — belongs to both a Server and a Site.

Server fields do NOT include deploy_path/php_binary/app_url — those belong to Site.
When deploying, `DeployService` loads `$deployment->site` to get app-specific config.

### Two deploy modes (controlled by `site.source_path`)

- **Mode B — full auto (source_path SET):** autodeploy builds the local project at `source_path`, zips it, uploads via SFTP, extracts on server, chowns, runs artisan commands. One-click from laptop.
- **Mode A — remote-only (source_path BLANK):** phases 2 & 3 are skipped; user is responsible for putting code on the server. Autodeploy only writes `.env` and runs remote artisan commands.

---

## 🚀 Deployment Pipeline — Step-by-Step Logic

### Phase 1 — Pre-Flight Check
```
1. Validate server profile (host, user, auth method)
2. Test SSH connectivity → abort if unreachable
3. Check remote disk space → warn if < 500MB
4. Check PHP version on remote → warn if < 8.2
5. Check MySQL availability on remote
6. Snapshot current remote state (git HEAD)
```

### Phase 2 — Build Assets Locally
```
7.  Run: npm run build  (Vite production build)
8.  Run: composer install --no-dev --optimize-autoloader
9.  Confirm /public/build/ exists and is non-empty
```

### Phase 3 — Upload to Server (ZIP + SFTP — works on Windows)
```
10. Create zip archive locally from site.source_path using PHP ZipArchive
    Excluded: .env, node_modules, .git, storage/logs, tests, public/hot, .claude, CLAUDE.md
11. Upload zip to /tmp/ via SFTP (makeSftp() creates separate SFTP connection)
12. Reconnect SSH after SFTP (prevents "Please close the channel" error)
    Run single chained exec: mkdir + unzip (output → /dev/null) + rm zip + echo EXIT:$?
    IMPORTANT: chain into ONE exec() call — multiple exec() calls after large operations
    cause phpseclib "Please close the channel (1)" errors
13. Reconnect SSH again before chmod
    Set permissions: dirs 755, files 644, writable_dirs 775
    IMPORTANT: chain find+chmod into ONE exec() call
13b. chown -R {server.web_user}:{server.web_user} {deploy_path}
     Auto-defaults to "www" on aaPanel if web_user is blank.
     Without this, Laravel cannot write to storage/ or bootstrap/cache/ → blank page.
```

### Phase 4 — Remote Commands via SSH
```
14. Write .env to server via SFTP uploadContent() before any artisan commands
    (site.env_content is stored encrypted in DB, decrypted before upload)
15. php artisan down --retry=60 --secret={random_token}
16. php artisan migrate --force  ← CRITICAL: throws on failure
17. php artisan config:cache
18. php artisan route:clear  ← NOT route:cache (deployed apps using Filament/Livewire break with cached routes → 405 on POST)
19. php artisan view:cache
20. php artisan event:cache
21. php artisan storage:link (success if "already exists")
22. php artisan queue:restart
23. php artisan up  ← CRITICAL: throws on failure
```

### Phase 5 — Health Check
```
24. HTTP GET {site.app_url}/health
      2xx/3xx → success
      4xx     → WARNING only (endpoint missing; app is still reachable — no rollback)
      5xx / connection error → FAIL → trigger Phase 6
25. Retrieve last 50 lines of Laravel log via SSH tail
26. Claude AI analyses logs → flag anomalies (wrapped in try/catch — non-fatal)
```

### Phase 6 — Rollback (if Phase 5 fails)
```
28. Claude AI explains what went wrong (structured diagnosis)
29. Restore previous build from snapshot
30. Run: php artisan migrate:rollback (if migrations ran)
31. Run: php artisan up
32. Notify user via broadcast event
```

---

## ⚠️ Known phpseclib SSH Channel Issue

**Problem:** After large SFTP uploads or commands that produce heavy output, phpseclib throws:
`Please close the channel (1) before trying to open it again`

**Root cause:** phpseclib SSH2 only supports one exec channel at a time. After heavy I/O, the channel doesn't close cleanly before the next `exec()` call.

**Solutions applied:**
1. **`SshService::exec()` auto-retries:** catches "close the channel" errors and transparently calls `reconnect()` + retries once. This is the belt-and-suspenders fix — see `@app/Services/SshService.php`.
2. Call `$this->ssh->reconnect()` after SFTP upload (before any exec)
3. Redirect unzip output entirely to `/dev/null` — never capture large outputs
4. Chain multiple commands into a SINGLE `exec()` call using `;` and `&&`
5. Call `$this->ssh->reconnect()` again before chmod / after SFTP `uploadContent()` in Phase 4

**Rule:** Never call `exec()` multiple times in rapid succession after a heavy operation. Chain commands or reconnect first.

---

## 🔐 env_content — Encrypted .env Per Site

- Stored in `sites.env_content` column, encrypted via Laravel `encrypt()`/`decrypt()`
- `Site` model has accessor/mutator to auto-encrypt on save, decrypt on read
- `env_content` is in `$hidden` on the model — when passing to Inertia edit page,
  explicitly pass it: `array_merge($site->toArray(), ['env_content' => $site->env_content])`
- Written to server via `$this->ssh->uploadContent()` in Phase 4 Step 14
- If env_content is null/empty, Phase 4 logs a warning but continues
- **If a deploy fails before Phase 4, the server .env is NOT updated** — fix and redeploy

---

## 🤖 Claude API Integration — ClaudeAgentService

Claude is used at **three points** in the pipeline (all wrapped in try/catch — non-fatal):

### 1. Pre-deploy Config Audit
```php
// Return JSON: { risk_level: low|medium|high, warnings: [], suggestions: [] }
```

### 2. Error Diagnosis
```php
// Return JSON: { cause: string, fix: string, safe_to_retry: bool, rollback_recommended: bool }
```

### 3. Post-Deploy Log Analysis
```php
// Return JSON: { status: ok|warning|critical, issues: [], severity_score: 0-10 }
```

**Always use structured JSON output from Claude. Parse with json_decode().**
Secrets are masked before sending to Claude (DB_PASSWORD, APP_KEY, *_SECRET, *_TOKEN).

---

## 📡 Live Deployment UI

- `Deployments/Show.jsx` polls `/deployments/{id}/poll` every 2 seconds
- Polling stops automatically when status is `success`, `failed`, or `rolled_back`
- `DeployController::poll()` returns `{ status, logs[] }` as JSON
- ANSI escape codes in output are rendered as garbled text — strip with `preg_replace('/\x1B\[[0-9;]*m/', '', $output)` if needed

---

## 🗃️ Database Schema (Key Tables)

```sql
-- servers: one per VPS/hosting account
CREATE TABLE servers (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  name VARCHAR(100) NOT NULL,
  panel_type ENUM('cpanel','aapanel','openpanel') NOT NULL,
  host VARCHAR(255) NOT NULL,
  ssh_port INT DEFAULT 22,
  ssh_user VARCHAR(100) NOT NULL,
  ssh_auth ENUM('password','key') DEFAULT 'key',
  ssh_password TEXT NULL,                -- encrypted
  ssh_private_key TEXT NULL,             -- encrypted
  panel_url VARCHAR(255) NULL,
  panel_token TEXT NULL,                 -- encrypted
  active BOOLEAN DEFAULT true,
  created_at TIMESTAMP,
  updated_at TIMESTAMP
);

-- sites: one per application on a server
CREATE TABLE sites (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  server_id BIGINT REFERENCES servers(id),
  name VARCHAR(100) NOT NULL,
  deploy_path VARCHAR(500) NOT NULL,     -- /www/wwwroot/myapp
  branch VARCHAR(100) DEFAULT 'main',
  php_binary VARCHAR(200) DEFAULT 'php',
  app_url VARCHAR(255) NULL,
  env_content LONGTEXT NULL,             -- encrypted .env content
  active BOOLEAN DEFAULT true,
  created_at TIMESTAMP,
  updated_at TIMESTAMP
);

-- deployments: one record per deployment run
CREATE TABLE deployments (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  server_id BIGINT REFERENCES servers(id),
  site_id BIGINT REFERENCES sites(id),
  branch VARCHAR(100) DEFAULT 'main',
  status ENUM('pending','running','success','failed','rolled_back') DEFAULT 'pending',
  triggered_by VARCHAR(100) NULL,
  started_at TIMESTAMP NULL,
  finished_at TIMESTAMP NULL,
  duration_seconds INT NULL,
  created_at TIMESTAMP,
  updated_at TIMESTAMP
);

-- deploy_logs: granular per-step log entries
CREATE TABLE deploy_logs (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  deployment_id BIGINT REFERENCES deployments(id),
  phase TINYINT NOT NULL,                -- 1-6
  step INT NOT NULL,
  command LONGTEXT NULL,
  output LONGTEXT NULL,                  -- LONGTEXT — composer output can be large
  exit_code INT NULL,
  ai_diagnosis JSON NULL,
  status ENUM('info','success','warning','error') DEFAULT 'info',
  logged_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

---

## 🔐 Security Rules

- **NEVER commit `.env` to git** — always pull from server vault or ask user
- SSH private keys stored in DB must be **encrypted** using Laravel's `encrypt()`
- Panel API tokens must be **encrypted at rest**
- All SSH commands must be sanitised — use `escapeshellarg()` for any user input
- `.env` diffs sent to Claude API must **mask** `DB_PASSWORD`, `APP_KEY`, `*_SECRET`, `*_TOKEN`
- Rollback snapshots stored in `/storage/app/snapshots/{deployment_id}/` — exclude from public
- The `/health` endpoint must be unauthenticated but rate-limited

---

## 🖥️ Panel-Specific Notes

> Server-side recipes that autodeploy does *not* automate (upload limits,
> scheduler/queue cron, locating the live php.ini) live in
> `docs/runbooks/`. Read those files on demand — keep them out of this one.

### aaPanel — one-time setup per site
Before the first deploy, configure these **once** in aaPanel → Website → {domain}:

1. **Site Directory** → **Running directory** = `/public` (so Nginx doc root = `/www/wwwroot/{domain}/public`)
2. **URL rewrite** → select **`laravel5`** template (adds `try_files $uri $uri/ /index.php$is_args$query_string;`). Without this, every route except `/` returns Nginx 404.
3. **PHP version** → 8.3+
4. SSH user: `www` or `root`. Set server's **`web_user`** field in autodeploy to `www` (or leave blank — auto-defaults to `www`).

Autodeploy handles file ownership (`chown www:www`) automatically on every deploy via Phase 3 step 13b.

### cPanel
- API: UAPI (preferred over legacy API2)
- Auth: API Token (store in `servers.api_token`)
- Default web root: `/home/{user}/public_html/{domain}/`
- Document root must point to the `public/` subfolder for Laravel

### OpenPanel (openpanel.com)
- API: REST on the **OpenAdmin** port `2087` (the end-user panel on `2083` is a different UI)
- Auth: **JWT** — unlike the other two panels there is no static token. `POST /api/`
  with `{username, password}` returns a token used as `Authorization: Bearer …`.
  Autodeploy stores the password in `servers.panel_token` and reuses `ssh_user`
  as the API username (same convention as `CpanelApiService`).
- Each account runs in its own container; sites live under `/home/{user}/`
- **`web_user` has no safe default** — it's the account username, which is often
  *not* the SSH user (root on a VPS). Set it explicitly per server or Phase 3
  step 13b skips the chown and logs a warning.
- Document root must point to the `public/` subfolder for Laravel

---

## 🧪 Testing Strategy

```
tests/
├── Unit/
│   ├── Services/ClaudeAgentServiceTest.php
│   ├── Services/SshServiceTest.php
│   └── Services/DeployServiceTest.php
└── Feature/
    ├── DeployControllerTest.php
    └── RollbackTest.php
```

```bash
php artisan test --parallel
```

---

## 📡 Webhook Support

```
POST /api/webhooks/deploy
Headers: X-Deploy-Token: {server.webhook_secret}
Body: { "server": "production", "branch": "main", "commit": "abc123" }
```

---

## 💡 Claude Code Behaviour Rules

1. **Always read this file first** before starting any task
2. **Never hardcode credentials** — use `env()` or `config()` always
3. **All SSH operations** must go through `SshService` — never raw `exec()` or `shell_exec()`
4. **All Claude API calls** must go through `ClaudeAgentService` — never inline fetch
5. **Deployment steps must be logged** to `deploy_logs` table with `exit_code` captured
6. **Broadcast events** must be fired after each phase for real-time UI updates
7. **Migrations** — always add `--force` flag when running on production remotely
8. **Rollback must be idempotent** — safe to run multiple times
9. **When editing `DeployService.php`** — maintain the 6-phase structure strictly
10. **Never delete snapshot files** during an active deployment
11. **SSH exec() calls** — never make multiple rapid exec() calls after heavy I/O; chain commands or reconnect first
12. **env_content** — always explicitly pass via `array_merge` in SiteController::edit(), never rely on toArray() alone since it's in $hidden

---

## 🔄 Queue Jobs

```
app/Jobs/
├── RunDeploymentJob.php     # Main job — dispatched per deploy trigger
├── HealthCheckJob.php       # Runs after deploy to verify app is up
└── CleanSnapshotsJob.php    # Prune old snapshots (keep last 5 per server)
```

```bash
php artisan queue:work --queue=deployments,default --tries=1 --timeout=300
```

---

## 🛠️ Local Dev Setup

```bash
git clone {repo}
cd autopilot-deploy
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
npm run dev
php artisan queue:work &
php artisan reverb:start &
```

---

## 📝 Commit Convention

```
feat: add aaPanel file sync via SFTP
fix: handle SSH timeout during migration phase
chore: update Claude API model to claude-sonnet-4-20250514
refactor: extract RollbackService from DeployService
test: add mock SSH responses for phase 4
```

---

## 🎯 Playbook — Deploying a New App

Quick reference for adding and deploying any new Laravel + Inertia + Vite app.

### One-time server setup (if server doesn't exist yet)
1. **Servers → Add Server** in autodeploy
   - Fill host, SSH user, auth, **web_user** (aaPanel: `www`; cPanel: account user)
2. Click **Test Connection** — must be green.

### One-time panel config on the server (aaPanel)
1. aaPanel → Website → **Add Site** → set domain + create DB
2. Website → {domain} → **Site Directory** → Running directory = `/public`
3. Website → {domain} → **URL rewrite** → template = `laravel5`
4. Set PHP to 8.3+

### Per-app setup in autodeploy
1. Server → **Add Application**
2. Fill:
   - **Name** (e.g. Sola Ecommer)
   - **Deploy Path** (e.g. `/www/wwwroot/ecommersola`)
   - **Source Path** (e.g. `D:\My project\ecommer_solar`) — leave blank only if you manage uploads yourself
   - **App URL** (e.g. `https://slsolarpower.com`)
   - **Environment (.env)** — paste production `.env` content (encrypted in DB)
3. Save.

### Deploying
Click **Deploy** on the app. That's it. Autodeploy will:
1. Pre-flight check SSH/disk/PHP/MySQL
2. Build locally (`npm run build` + `composer install --no-dev`)
3. Zip (excluding dev files), upload via SFTP, unzip, chmod, **chown to web_user**
4. Write `.env`, run migrations, caches, queue:restart, artisan up
5. Health check + Claude AI log analysis

### Troubleshooting checklist (if something breaks)
- **Blank page** → check `public/hot` doesn't exist (excluded by default now). Check `chown www:www` ran. Check aaPanel doc root is `/public`.
- **404 on any route but `/`** → Nginx URL rewrite template not set to `laravel5`.
- **500 error** → SSH in, `tail -50 {deploy_path}/storage/logs/laravel.log`. Temporarily set `APP_DEBUG=true` in the site's .env textarea, redeploy, see the stack trace, then turn it back off.
- **"Please close the channel" in live terminal** → should auto-recover now (SshService::exec retries on reconnect). If it doesn't, call `ssh->reconnect()` manually at the offending call site.
- **Filament type errors in a DEPLOYED app** → that app's local Filament major version differs from the remote's stale composer lock. Mode B replaces vendor/ wholesale, so just redeploy cleanly. (AutoPilot itself does not use Filament.)

---

*Last updated: April 2026 — Corasoft / AutoPilot Deploy*
