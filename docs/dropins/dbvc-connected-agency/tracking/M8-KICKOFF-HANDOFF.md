# Connected Environments — session handoff (M8 kickoff)

Written 2026-09-24 to hand M8 to a fresh Claude Code session with clean context.

## 1. Where things stand

- **Product:** two independently gated add-ons in `db-version-control-main` — **Connected Environments** (the *connector*, `addons/connected-environments/`) and **Agency Control** (the *hub*, `addons/agency-control/`). A site can run either or both gates.
- **M0–M7 are complete and live-verified on `master`.** M7 (media/attachment transfer for `wp.service`: transfer → converge under the hash contract → clean rollback) was live-verified on the real installs 2026-09-24 (PR #60). Hub schema is **v11**, connector schema **v5**.
- **M8 is PLANNED (design only — nothing built).** Full design: `docs/implementation/active/connected-environments-agency-control.md` → **`## M8`**. Tracker: `docs/dropins/dbvc-connected-agency/tracking/tasks.json` (M8 `status: planned`).

## 2. What M8 is

"Universal post-type coverage and configurable parity." Two intertwined jobs:

1. **Universal CPT coverage (opt-in):** lift the connector from the single `wp.service` post domain (one filterable CPT) to **any opted-in CPT with its meta + taxonomies** — `wp.post:<type>` domains generated from an allow-list, `ServicePostObserver` → a parameterized `PostTypeObserver`, `wp.service` kept as a back-compat alias, and `ObservationEvent::DOMAINS` made a validated `wp.post:<type>` pattern (the one protocol change).
2. **Configurable parity:** a per-pair **sync policy** (direction=push; mode=manual|assisted|auto; scope/CPTs; conflict=hold|source_wins|skip; create_new/propagate_deletions off by default; caps) whose hub driver drives `outgoing` objects to `synchronized` **through the existing** release → prepare → approve → guarded apply → verify → rollback pipeline (+ M6 rollouts for fleets). **No new write path.**

Read `## M8` in the guide for the full design, controls, and the 4-slice breakdown. **Start with slice 1.**

## 3. Why it's a layer, not a rewrite (the code that already generalizes)

- **Domains:** `addons/connected-environments/src/Adapters/DomainRegistry.php` — 3 domains today (2 Bricks option collections + `wp.service`, which has `kind:'post'` → `ServicePostObserver`). `definitions()` is a hardcoded array (no filter yet).
- **Observer:** `ServicePostObserver` observes ONE type via `apply_filters('dbvc_connected_service_post_type', 'service')`. Its projection is already generic: post fields + all meta (minus `dbvc_connected_service_ignored_meta_keys` + the DBVC export mask) + portable taxonomy terms.
- **Taxonomies (already per-type):** `DBVC_Sync_Posts::export_tax_input_portable($id,$type)` / `import_tax_input_for_post($id,$type,$tax,$create_terms)` in `includes/class-sync-posts.php` — includes a `$create_terms` knob.
- **Capture (already multi-type-ready):** `PostSignalListener` iterates `DomainRegistry::watched_post_types()` (a post_type→domain map). Add domains → it watches them all, no change.
- **Protocol allow-list (the one hard gate):** `includes/Dbvc/ConnectedProtocol/ObservationEvent.php` → `DOMAINS = ['bricks.global_class','bricks.variable','wp.service']`, enforced in its validator.
- **Parity primitives already present:** `dbvc_ac_subscriptions` (source→target pair + domains, drives routing), instance links (M3, per-object mapping), `ComparisonService` (per-object state: synchronized/outgoing/incoming/converged/conflict/baseline_required), releases (M4/M5) + rollouts (M6) as the guarded mechanism, apply gate authoritative on the connector.

## 4. Working agreement (the loop used through M1–M8-planning)

- Each change = **one small reviewable PR** on a `claude/<name>` branch cut from **`origin/master`**.
- Tests: `vendor/bin/phpunit --testsuite "DBVC Plugin" --group connected-environments` (real WP+MySQL test lib under `tmp/wordpress-tests-lib`).
- **agent-docs discipline:** after source edits run `composer agent-docs:refresh` **then** `composer agent-docs:check` (must end "0 unmapped"); map any new CLI/REST/filters into the manifest. Run refresh LAST before committing.
- Merge: `gh pr merge <N> --merge`; then `git -C <main checkout> pull --ff-only origin master`; then update `tasks.json` + the `connected-environments-branch-state` memory.
- **CI is `workflow_dispatch`-only** (the private repo's GitHub Actions is billing-blocked) — validate locally; PRs won't show a red X. (Re-add pull_request/push triggers to `.github/workflows/agent-docs.yml` to restore CI once billing is fixed — user-side.)
- Attribution on every commit/PR: `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>` and, in PR bodies, `🤖 Generated with [Claude Code](https://claude.com/claude-code)`.

## 5. Live verification (only when a slice needs it)

- **Hub = `dbvc-codexchanges.local`** — a **real client site**; use it **orchestrate-only** (additive + fully cleaned up), never write its content. Its connector gate is ON with a real ~1718-event outbox (provisional/disconnected) — **do not touch it**. Hub schema is v11.
- **Target connector = `dbvc-connectedsite-a.local`** — the disposable clone; safe to write. Its plugin is a git checkout of the same remote: `git -C /Users/rhettbutler/Documents/LocalWP/dbvc-connectedsite-a/app/public/wp-content/plugins/db-version-control-main pull --ff-only origin master` to load new code.
- **wp-cli:** LocalWP phar at `/Applications/Local.app/Contents/Resources/extraResources/bin/wp-cli/wp-cli.phar`; per-site MySQL sockets `~/Library/Application Support/Local/run/<id>/mysql/mysqld.sock` — hub id `4gScrLykQ`, scratch id `4pwRYxV15`. Invoke: `php -d mysqli.default_socket=<sock> <phar> --path=<app/public> --url=https://<site> <cmd>`. (Recreate the `livewp.sh`/`scratchwp.sh` wrappers from these — they are session-local and won't carry over.)
- **Hub gate:** enable with `DBVC_Agency_Control_Addon::save_settings([DBVC_Agency_Control_Addon::OPTION_ENABLED=>'1'])`; **disable with `save_settings([])`** — it keys enablement on KEY PRESENCE, not value. Restore to OFF after verifying (schema stays; migrations are additive/one-way).
- **Self-signed HTTPS** (cross-site): the `dbvc_connected_allow_insecure_hub` filter + a temporary host-scoped mu-plugin setting `http_request_args` `sslverify=false` for `.local`; remove it after.
- A full continuous cross-site round trip needs **two** connectors (the one clone can't be both source and target); M7 was verified split at the wire (hub DB + connector WP) because M5 already proved the HTTPS transport. M8 slice 4 may want a second disposable clone for a true continuous parity run.

## 6. Gotchas

- **Do not build in the `nifty-proskuriakova-e62110` worktree** — it is on a STALE pre-`wp.service` branch (`claude/bold-meninsky-a4e4e9`) missing the whole wp.service/M7 chain. Branch M8 from `origin/master`.
- `npx wp-scripts build connected-app` deletes the other `build/` outputs — restore with `git checkout -- build/<others>`.
- Observing a newly opted-in CPT triggers the one-time M7-3a projection re-hash for that type's media-bearing posts (expected; re-observe/re-baseline once).
- Keep changes altitude-appropriate: parity is orchestration over the existing guarded pipeline — resist adding any second, unguarded write path.

## 7. Invariants M8 must preserve

Apply gate stays authoritative (auto mode never writes to a target whose apply gate is off, never bypasses prepare/approve/verify); every write is a single fingerprint-guarded, journalled, rollbackable operation; conflicts never auto-overwrite unless `source_wins` is explicitly chosen; masked/privacy fields never sync; deletions trash, never hard-delete; new-object creation and deletion propagation are off by default; everything is opt-in with conservative defaults (manual mode, push only, public+`show_in_rest` CPTs).

## 8. First action for the new session

Slice 1 — **universal post domains**: parameterized `PostTypeObserver` + `wp.post:<type>` registration from an opt-in allow-list, `wp.service` alias, the `ObservationEvent::DOMAINS` pattern, confirm the hub stays domain-agnostic end to end, per-type meta allow/deny + create-missing-terms. Cover with PHPUnit and live-verify a second CPT end to end. One small PR.
