# AutoPilot Deploy — UPGRADE-v2 Phase 7: Review Fixes

> Handoff for Claude Code. Place at `docs/UPGRADE-v2-phase7.md` and update `CLAUDE.md`:
> `Active work: docs/UPGRADE-v2-phase7.md — one PR, fixes in the order below.`

Phases 1–6 are merged. A code review found four bugs and one minor issue. Fix all of them in a single PR titled `fix: UPGRADE-v2 Phase 7 — review fixes`. **Priority: R2 and R3 must land before any client site is converted to `atomic`.**

---

## R1 — Invalid Sonnet model string makes AI silently fail

**Where:** `config/autopilot.php`, `app/Services/ClaudeAgentService.php::call()`

**Problem:** `model_smart` defaults to `claude-sonnet-5`, which is not a valid model ID. Every call to `diagnoseError` and `auditConfig` fails, and `call()` returns the fallback array. Nothing is logged, so the AI layer looks fine and does nothing.

**Fix**
1. `'model_smart' => env('AUTOPILOT_MODEL_SMART', 'claude-sonnet-5-5'),`
2. Update `.env.example` with both `AUTOPILOT_MODEL_FAST` and `AUTOPILOT_MODEL_SMART`.
3. In `call()`, when returning the fallback (request exception **or** invalid JSON), write `Log::warning('Claude fallback used', ['model' => $model, 'reason' => …])`. Add `'fallback' => true` to the returned array so callers and the UI can show "AI unavailable" instead of an empty verdict.
4. In `Deployments/Show.jsx`, render "AI unavailable — check model/API key" when `fallback` is true on an `ai_diagnosis` or `ai_audit_result`.
5. Add `php artisan autopilot:ai-ping` — sends a one-line prompt to both models and prints OK/FAIL per model. Mention it in `docs/runbooks/hosting-autopilot.md`.

**Acceptance**
- Unit test: Anthropic fake throws → result has `fallback: true` and a warning is logged.
- Unit test: config default for `model_smart` equals `claude-sonnet-5-5`.

---

## R2 — `queue:restart` runs before the atomic switch

**Where:** `DeployService::phase4RemoteCommands()` step 22, `DeployService::run()`, `RollbackService::rollback()`, `DeployController::rollback()`

**Problem:** On atomic sites, step 22 runs while `current` still points at the old release. Workers restart into the **old** code and keep running it until the next deploy.

**Fix**
1. In `phase4RemoteCommands()`, only add step 22 when `! $this->site->isAtomic()`.
2. Add a private `restartQueues(string $path)` helper that runs `cd {path} && {php} artisan queue:restart` and logs it as step 22.
3. Call it with `$this->site->currentPath()`:
   - in `run()` right after `completeAtomicRelease()`;
   - in `RollbackService::rollback()` right after a successful `switchTo()` back to the previous release;
   - in the manual rollback controller action after its `switchTo()`.
4. Add to the site edit form and to `docs/runbooks/hosting-autopilot.md`: client-site Supervisor programs **must** use `{deploy_path}/current/artisan queue:work`, never a release path. Otherwise workers stay pinned to one release forever.

**Acceptance**
- Fake-SSH test (atomic site): the index of the `queue:restart` command is **after** the `mv -Tf … current` command.
- Fake-SSH test (in_place site): `queue:restart` still runs inside phase 4, unchanged.
- Fake-SSH test: an automatic rollback runs `queue:restart` after swapping back.

---

## R3 — Composer failure is hidden, so a broken release can go live

**Where:** `DeployService::phase4RemoteCommands()` early steps 148 and 147

**Problem:** `(composer install … || (sleep 5 && composer install …)) | grep -v 'Deprecat' | grep -v 'PHP Warning' || true` always exits 0. A release with a missing or partial `vendor/` is switched live and only recovered if the health check catches it. Step 147 (`package:discover … || true`) hides failures the same way.

**Fix**
1. Remove the `| grep … || true` pipeline from step 148. Run composer plainly (keep the one retry with `sleep 5`) and check the real exit code.
2. Filter `Deprecat` and `PHP Warning` lines in PHP before logging, e.g. a `filterNoise(string $output): string` helper. Never filter in the shell.
3. If composer still fails after the retry, throw `RuntimeException('composer install failed — release not activated.')`.
4. Step 147: for **atomic** sites, remove `|| true` and throw on a non-zero exit. For **in_place** sites keep `|| true`, since non-Laravel sites can use in_place.
5. Atomic only: after step 147, run `test -f vendor/autoload.php && {php} artisan --version` in the release. If that fails, throw before migrate or switch.

**Acceptance**
- Fake-SSH test (atomic): composer returns exit 1 twice → deployment `failed`, no `mv -Tf … current` command was issued, and the log says the live site is unchanged.
- Fake-SSH test: composer output containing `Deprecated:` lines is logged without those lines, and exit 0 is still treated as success.

---

## R4 — Risk audit never sees the new migrations

**Where:** `DeployService::run()`, `auditRequiresApproval()`, `pendingMigrations()`

**Problem:** The audit runs in pre-flight, before upload. `pendingMigrations()` calls `migrate:status --pending` on `current`, which is the old code, so migrations added by this deploy are invisible. The `high` gate will almost never trigger.

**Fix — atomic sites**
1. Move the `auditRequiresApproval()` call out of pre-flight. Call it after steps 147/148 succeed inside the new release and **before** pre-migrate commands, migrate, and the switch. Split `phase4RemoteCommands()` into `phase4Prepare()` (link shared, caches, composer, discover) and `phase4Migrate()` (pre-migrate, migrate, tenant migrate, seeders, cache, up) so the audit can sit between them.
2. `pendingMigrations()` takes a `string $path` argument; pass `$this->releasePath`.
3. When the audit holds a deploy: return without switching. Log that the prepared release `releases/{id}` was kept and the live site is unchanged. On approval, the same deployment ID re-runs; extracting into the same release folder again is fine.

**Fix — in_place sites (Mode B and C)**
1. Run the audit after phase 2 (local build or clone) and **before** phase 3 upload.
2. Compute pending migrations by diffing local migration filenames (`{source}/database/migrations/*.php`, basename without `.php`) against the migrations already run on the server. Get those from `artisan migrate:status` on `deploy_path`, parsing the "Ran" rows.
3. Mode A (no source, no repo): skip the migration part of the audit; keep the env-key diff.

**Shared**
- Return `[]` and log a warning (do not throw) if `migrate:status` fails, e.g. on a first deploy with an empty database.
- Prompt to the smart model includes migration **file names plus their `up()` body**, read from the release (atomic) or local source (in_place), truncated to 4 KB per file. The current filename-only prompt can't judge whether a migration is destructive.

**Acceptance**
- Fake-SSH test (atomic): the `migrate:status` command runs with `cd` into the release path, after composer and before `migrate --force`.
- Test: a new local migration file containing `dropColumn` → audit receives it → with the AI fake returning `high`, status becomes `awaiting_approval` and no `migrate --force` or switch command was issued.
- Test: approving re-runs the job and reaches the switch without auditing again.

---

## R5 (minor) — `optimize:clear` flushes the live site's cache on atomic deploys

**Where:** `phase4RemoteCommands()` early step 150

**Problem:** `optimize:clear` includes `cache:clear`. On atomic sites, `storage/` is shared, and Redis is shared anyway, so preparing a release wipes the live app's cache before the switch.

**Fix:** for atomic sites, replace step 150 with `config:clear && route:clear && view:clear && event:clear`. Keep `optimize:clear` for in_place.

**Acceptance:** fake-SSH test — an atomic deploy issues no `optimize:clear` or `cache:clear` command.

---

## Rules
- One PR. Run `php artisan test` and `vendor/bin/pint` before committing.
- Keep step numbers stable; reuse existing numbers where a command just moved.
- No new `shell_exec`. Remote commands go through `SshService::exec`, local through `Process`.
- When done, add a "Phase 7" section to `docs/UPGRADE-v2.md` listing R1–R5 as complete.
