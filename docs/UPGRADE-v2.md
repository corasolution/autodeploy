# AutoPilot Deploy — v2 Upgrade Spec

> Handoff for Claude Code. Place at `docs/UPGRADE-v2.md` and add one line to `CLAUDE.md`:
> `Active work: implement docs/UPGRADE-v2.md phase by phase. Do not start a phase until the previous one's acceptance tests pass.`

Stack stays the same: Laravel 13, React + Inertia, phpseclib3, Reverb, mozex/anthropic-laravel. No FilamentPHP.

---

## 0. Audit findings (why this upgrade)

| # | Severity | Finding | Where |
|---|---|---|---|
| F1 | 🔴 Critical | **Webhook auth bypass**: `$server->webhook_secret !== $token` passes when both are `null` (no secret set + no header). Also not timing-safe. | `DeployController::webhook` |
| F2 | 🔴 Critical | **Webhook deploys crash**: Deployment is created without `site_id`, but `DeployService::run()` reads `$deployment->site`. | `DeployController::webhook` |
| F3 | 🔴 Critical | **Failed deploy leaves site in maintenance mode**: any exception after `artisan down` (e.g. migration fails) never runs `artisan up`. | `DeployService::run` / phase 4 |
| F4 | 🔴 Critical | **Auto-rollback is dead code**: `phase5HealthCheck()` always returns `true`; `createSnapshot()` is never called; snapshot path is a local path used in a remote `rsync`. | `DeployService`, `RollbackService` |
| F5 | 🟠 High | **Not atomic**: zip is extracted over the live folder and `composer install` runs **before** `artisan down` → users hit mixed old/new code. Files deleted in the repo are never deleted on the server. | phase 3 + phase 4 order |
| F6 | 🟠 High | **Local build failures are logged as success**: `shell_exec()` has no exit code; a failed `npm run build` / `git clone` / `composer install` still ships. | `phase2BuildAssets`, `phase2CloneAndBuild` |
| F7 | 🟠 High | **`with_database` pushes local data to production** with REPLACE — one wrong click overwrites live data. | `pushLocalDatabase` |
| F8 | 🟡 Medium | Health endpoint default `/health`, but Laravel's built-in route is `/up` → most sites return 404 → warning only. SSL verification disabled. | `config/autopilot.php`, phase 5 |
| F9 | 🟡 Medium | `HealthCheckJob` reads `$server->app_url` (doesn't exist — it's on Site). | `HealthCheckJob` |
| F10 | 🟡 Medium | AI: outdated default model, one model for all tasks, no ```json fence stripping, `auditConfig` not used in the pipeline (CLI only). | `ClaudeAgentService` |
| F11 | 🟡 Medium | Pre-flight checks `mysql --version` (stack is Postgres); snapshot step runs `git rev-parse` on a path that has no `.git`. | `phase1PreFlight` |
| F12 | 🟡 Medium | `grant_createdb`: `escapeshellarg()` inside a double-quoted SQL string produces broken quoting. | phase 4 |
| F13 | 🟢 Low | Leftovers: `public/js/filament/*`, `public/css/filament/*`, `public/fonts/filament/*`, `artisan filament:assets` / `livewire:publish` steps, `Deployment.php.bak`. | various |
| F14 | 🟢 Low | Runs from a Windows/Laragon PC (`start.bat`) → webhooks only work while that PC is on. | ops |

---

## Phase 1 — Safety hotfixes (ship first, small diff)

1. **Webhook (F1, F2)**
   - Move secret to **Site** (`sites.webhook_secret`, encrypted, required to enable webhook). Keep server-level secret only as fallback during migration.
   - Payload: `{ "site": "<site slug or id>", "branch": "main", "commit": "<sha>" }`.
   - Auth: header `X-Signature: sha256=<hex>` = `hash_hmac('sha256', $request->getContent(), $secret)`. Compare with `hash_equals`. Reject (401) if secret empty, header missing, or mismatch.
   - Reject (422) if `branch` ≠ `site.branch`. Ignore duplicate `commit` already deployed successfully (return 200 `{status:"skipped"}`).
   - Create Deployment **with `site_id`**, `user_id = site.server.user_id`.
2. **Always bring site back up (F3)**: track `$this->maintenanceOn`; in `run()`'s `catch`, if true, exec `artisan up` before rethrowing and log step 23 result.
3. **Exit codes for local commands (F6)**: replace every `shell_exec` in `DeployService` with `Illuminate\Support\Facades\Process` (`Process::path($dir)->timeout(1800)->run($cmd)`). If `failed()` → log `error` with output and throw.
4. **DB push guard (F7)**: add `sites.environment` (`production|staging|local`, default `production`). If `production`, `with_database` is rejected unless the request includes `confirm_site_name` matching `site.name`. Before any push, run a remote dump to `shared/backups/{deployment_id}.sql.gz` (pg_dump or mysqldump based on `DB_CONNECTION` in env_content).
5. **Remove dead/wrong bits (F9, F13)**: delete `HealthCheckJob`, `Deployment.php.bak`, Filament public assets, steps 215/216/217.

**Acceptance**
- Feature test: webhook with no secret configured → 401; wrong signature → 401; valid → 200 and Deployment has `site_id`.
- Unit test with fake SSH: exception thrown after `down` → `artisan up` is executed.
- Unit test: failing local `npm run build` (Process fake) → deployment `failed`, no upload attempted.

---

## Phase 2 — Atomic releases (the core upgrade)

### Layout on the server
```
{deploy_path}/
  releases/{deployment_id}/   ← one folder per deploy
  shared/.env
  shared/storage/             ← uploads, logs, sessions survive deploys
  shared/backups/
  current -> releases/{id}    ← web root = {deploy_path}/current/public
```

### Schema
- `sites.release_mode` enum `in_place|atomic`, default **`in_place`** (existing sites keep working unchanged).
- `sites.keep_releases` int default 5.
- `deployments.release_path` string nullable, `deployments.previous_release` string nullable.

### New pipeline for `atomic` sites (old pipeline remains for `in_place`)
| Step | Action | Live site affected? |
|---|---|---|
| 1 | Pre-flight (see Phase 3 fixes) + record `previous_release = readlink current` | No |
| 2 | Build locally / clone + build (exit-code checked) | No |
| 3 | Upload zip → extract into `releases/{id}` | No |
| 4 | In release: `rm -rf storage && ln -s ../../shared/storage storage`, `ln -s ../../shared/.env .env`, `composer install --no-dev …`, `package:discover`, `config:cache`, `view:cache`, `event:cache`, `ln -sfn` public/storage | No |
| 5 | `migrate --force` (+ `tenants:migrate` if enabled), run from the **new** release. Only use `artisan down` if `sites.maintenance_on_migrate = true` | Only if flagged |
| 6 | Atomic switch: `ln -sfn releases/{id} current.tmp && mv -Tf current.tmp current` | Switch is instant |
| 7 | Reload PHP-FPM / reset opcache (`sites.reload_command`, e.g. `sudo -n systemctl reload php8.3-fpm`), `queue:restart` | — |
| 8 | Health check (Phase 3) → on failure: switch `current` back to `previous_release`, reload, mark `rolled_back` | — |
| 9 | Prune: keep newest `keep_releases` folders, never delete `current` or `previous_release` | — |

- Ownership: chown only the new release folder + `shared/`, never the whole `deploy_path` (it grows with releases).
- Nginx note in UI: use `$realpath_root` instead of `$document_root` for `SCRIPT_FILENAME` so opcache follows the symlink.
- Remove `RollbackService` snapshot/rsync logic; rollback becomes a symlink swap.
- **Manual rollback**: `POST /deployments/{deployment}/rollback` (+ button on Deployments/Show) → swaps `current` to that deployment's `release_path` if the folder still exists.
- **Migrations are not rolled back automatically**. Show a warning in the rollback UI when the rolled-back deploy ran migrations. Encourage expand/contract (additive) migrations.

### One-time converter
Artisan command + button `deploy:convert-atomic {site}`: creates `shared/`, moves `storage/` and `.env` into it, moves current code into `releases/initial`, creates `current` symlink, prints the new web root the user must set in the panel (aaPanel / OpenPanel / cPanel). Dry-run flag shows the plan without changing anything.

**Panel notes**
- aaPanel / OpenPanel: set site root to `{deploy_path}/current/public`.
- cPanel shared hosting where docroot can't change: make `public_html` a symlink to `current/public` if allowed; otherwise keep `in_place`.

**Acceptance**
- Fake-SSH test asserts command order: nothing touches `current` until step 6.
- Health failure → `current` points to `previous_release`, status `rolled_back`.
- Prune keeps exactly `keep_releases` + never removes active/previous.

---

## Phase 3 — Health check & pre-flight correctness (F8, F11, F12)

- Default endpoint `/up`; per-site override `sites.health_path`.
- SSL verification **on** by default; per-site `sites.health_verify_ssl` toggle for broken certs.
- Retry 3× with 5s gap. Result rules:
  - 2xx → healthy.
  - 5xx or connection error after retries → **unhealthy → rollback** (atomic) / alert (in_place).
  - 4xx → warning "health route missing", treated as healthy.
- AI log analysis `critical` → mark deployment `needs_attention` and alert; do not auto-rollback on AI alone.
- Pre-flight: detect DB driver from `env_content` `DB_CONNECTION` → check `psql --version` or `mysql --version`. Drop the `git rev-parse` snapshot step; record `previous_release` instead.
- `grant_createdb`: validate DB user against `/^[A-Za-z0-9_]+$/`, then `psql -U postgres -c 'ALTER USER "<user>" CREATEDB;'` built safely.

---

## Phase 4 — AI layer upgrade (F10)

- Config:
  ```php
  'claude' => [
      'model_fast'  => env('AUTOPILOT_MODEL_FAST',  'claude-haiku-4-5-20251001'), // log triage
      'model_smart' => env('AUTOPILOT_MODEL_SMART', 'claude-sonnet-5-5'),        // diagnosis, audit
      'max_tokens'  => 2048,
  ],
  ```
- `analysePostDeployLogs` → fast model. `diagnoseError`, `auditConfig` → smart model.
- Use a `system` prompt for the role; strip ```json fences before `json_decode`; on invalid JSON return `['status' => 'unknown', 'raw' => …]` instead of throwing.
- Mask secrets (`maskSecrets`) on **every** prompt, not only audit — logs can contain DSNs and tokens.
- Wire `auditConfig` into pre-flight: diff local/site `env_content` keys vs remote `shared/.env` keys (names only) + list pending migrations from `artisan migrate:status` in the new release. If `risk_level = high` → deployment status `awaiting_approval`; UI shows Approve / Cancel. Webhook deploys wait for approval too.
- On any failed step, call `diagnoseError` with that step's command + output and store in `deploy_logs.ai_diagnosis`.

---

## Phase 5 — CI integration & notifications

**GitHub Actions template** — show in Site → Webhook tab with the site's values filled in:
```yaml
name: Test & Deploy
on: { push: { branches: [main] } }
jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: '8.3' }
      - run: composer install --no-interaction
      - run: cp .env.example .env && php artisan key:generate
      - run: php artisan test
  deploy:
    needs: test
    runs-on: ubuntu-latest
    steps:
      - name: Trigger AutoPilot
        run: |
          BODY='{"site":"${{ vars.AUTOPILOT_SITE }}","branch":"main","commit":"${{ github.sha }}"}'
          SIG=$(printf '%s' "$BODY" | openssl dgst -sha256 -hmac "${{ secrets.AUTOPILOT_SECRET }}" | sed 's/^.* //')
          curl -fsS -X POST "${{ vars.AUTOPILOT_URL }}/api/webhooks/deploy" \
            -H "Content-Type: application/json" -H "X-Signature: sha256=$SIG" -d "$BODY"
```

**Telegram notifications**
- `servers.telegram_chat_id` (or global in settings) + `TELEGRAM_BOT_TOKEN` env.
- Send on: success (site, commit, duration), failed (step + AI cause/fix), rolled_back, awaiting_approval (with link).

---

## Phase 6 — Hosting AutoPilot itself (F14)

- Move AutoPilot from the Windows PC to a small Linux VPS so webhooks run 24/7.
- Supervisor programs: `queue:work --queue=deployments,default --timeout=3600`, `reverb:start`.
- Keep Windows support for local Mode B builds (Laragon composer fallback stays).
- Protect the dashboard: enforce 2FA for admin users (new), restrict `/login` with rate limiting (exists) and optionally IP allowlist.

---

## Rules for Claude Code
- One phase per PR. Run `php artisan test` + `vendor/bin/pint` before each commit.
- Never change behaviour of `in_place` sites except the Phase 1 hotfixes.
- All remote commands go through `SshService::exec`; all local commands through `Process`. No new `shell_exec`.
- Every new remote command must be logged via `$this->log()` with a step number in the existing numbering style.
- Never log or send to Claude: `env_content`, private keys, panel tokens, webhook secrets.

---

## Phase 7 — Review fixes (complete)

Spec: `docs/UPGRADE-v2-phase7.md`.

| # | Status | Notes |
|---|---|---|
| R1 | Done, **with a correction** | See below — the diagnosed cause was wrong. |
| R2 | Done | `queue:restart` moved after the atomic switch, targeting `current/`; also added to both rollback paths. |
| R3 | Done | Composer can fail again; noise filtered in PHP; release boot check before migrate/switch. |
| R4 | Done | Audit moved between phase 4a/4b (atomic) or after build/before upload (in_place); migration bodies now sent. |
| R5 | Done | Atomic uses targeted `*:clear` instead of `optimize:clear`. |

### R1 correction — the model id was never the problem

The spec asserted `claude-sonnet-5` is invalid and prescribed changing
`model_smart` to `claude-sonnet-5-5`. **That is backwards.** `claude-sonnet-5`
is the correct id for Sonnet 5; `claude-sonnet-5-5` does not exist. Applying
the prescribed change would have introduced the exact silent failure it was
meant to fix.

The *symptom* described in R1 was real, and `autopilot:ai-ping` (added by R1
step 5) identified the actual cause on its first run:

```
FAIL fast   claude-haiku-4-5-20251001
     Your credit balance is too low to access the Anthropic API.
FAIL smart  claude-sonnet-5
     Your credit balance is too low to access the Anthropic API.
```

Verified by sending a deliberately bogus model id (`totally-not-a-model-xyz`)
and getting the *same* billing error — the API rejects on balance before it
validates the model, so a failing ping says nothing about model correctness
while billing is unresolved.

**Action required:** add credit to the Anthropic account. Until then the AI
layer returns fallbacks — now logged and surfaced in the UI rather than
silent. Model ids are left at `claude-sonnet-5` / `claude-haiku-4-5-20251001`.
