# M9 Handoff — Fleet Framework Distribution & Client-Drift Governance

**Audience:** the next Claude Code (or human) developer picking up the **M9** sprint cold, in a fresh session.
**Written:** 2026-09-27. **Master tip at handoff:** `6d55803` (advances once the handoff PR merges).
**Status:** M0–M8 shipped + live-verified; the reassign-client follow-up shipped; **M9 is fully planned (design-only) and ready to build. No M9 code exists yet.** Start with **Slice 0**.

Read these three first, in order:
1. This file.
2. `docs/implementation/active/connected-environments-agency-control.md` → the **`## M9`** section (goal, ownership model, reuse map, Parts 1–7, Design & UX, **the readiness checklist**, and the **reordered slices 0–7**). This is the design of record.
3. The running project log in memory: `~/.claude/projects/-Users-rhettbutler-…-db-version-control-main/memory/connected-environments-branch-state.md` (dense history of every milestone + gotchas).

---

## 1. What we're building (one paragraph)

From the **agency hub** (`dbvc-codexchanges`), push official **Bricks-framework** changes — updated *and* brand-new global classes, variables, components, palettes, theme styles, templates — to the whole **fleet** of connected client sites in one action, while **approved client customizations take precedence on each site and are never silently overwritten.** Two verbs: **distribute** (framework → fleet) and **govern drift** (lock/unlock per client). It is **not a new write path** — it rides the existing M5 pipeline (release → prepare → approve → fingerprint-guarded apply → verify → rollback) staged fleet-wide by M6 rollouts. The goal is to stop logging into every client site to ship official changes.

### The ownership model (the shared mental model — keep it exact)
1. **Framework object, client unchanged** → official version wins; the push converges it.
2. **Framework object, client customized + approved ("locked")** → the client's value wins on their site; the push **skips** it. If the push is a *new framework version of that same object*, the lock is flagged **`needs_rebase_review`** (keep / re-approve / unlock). Never a silent overwrite.
3. **Client-owned original object** (never part of the framework) → never touched, never flagged.

---

## 2. The working agreement (follow this exactly — it is how every prior slice shipped)

- **Branch** `claude/<short-name>` from **`origin/master`** every time — never from a stale worktree/branch. `git fetch origin` first.
- **One small, reviewable PR per slice.** Keep diffs focused.
- After any **source** edit: `composer agent-docs:refresh` then `composer agent-docs:check` — must print **`0 unmapped`** (strict). Run `refresh` as the **last** step before committing (a docblock edited after refresh makes the discovery snapshot stale and fails the check).
- **Merge** with `gh pr merge <n> --merge`, then **fast-forward the hub's live checkout** (see §4), then **deploy to the connectors** you'll live-verify on, then **update `tasks.json` + memory**.
- **CI is `workflow_dispatch`-only** (GitHub Actions is billing-blocked on this private repo) → **validate locally**; PRs merge past the un-run checks.
- **Attribution** (unless the session's own system reminder says otherwise — that governs): end commit messages with
  `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`
  and PR descriptions with
  `🤖 Generated with [Claude Code](https://claude.com/claude-code)`.

### Safety constraints (hard rules)
- The **real hub** `dbvc-codexchanges` is orchestrate-only: **additive schema + fully cleaned up**. Never leave test rows; restore the hub gate to its prior state (usually **off**).
- **Never run a parity apply against real client content.** Use the **disposable connector clones** (`dbvc-connectedsite-a`, `dbvc-connectedsite-butler`).
- **Net-zero after every live-verify** — delete fixtures/users/rows, remove any temp mu-plugins, restore gates. Prove it (query counts back to baseline).
- Confirm irreversible/outward actions; don't hard-delete (deletions **trash** only); masked/privacy fields never sync.

---

## 3. Environment (LocalWP on this Mac)

| Role | Site | LocalWP id | URL | MySQL socket |
|---|---|---|---|---|
| **Hub** | `dbvc-codexchanges` | `4gScrLykQ` | https://dbvc-codexchanges.local | `~/Library/Application Support/Local/run/4gScrLykQ/mysql/mysqld.sock` |
| **Connector A** (scratch) | `dbvc-connectedsite-a` | `4pwRYxV15` | https://dbvc-connectedsite-a.local | `~/Library/Application Support/Local/run/4pwRYxV15/mysql/mysqld.sock` |
| **Connector B** (scratch) | `dbvc-connectedsite-butler` | `jEFL3lXs8` | https://dbvc-connectedsite-butler.local | `~/Library/Application Support/Local/run/jEFL3lXs8/mysql/mysqld.sock` |

- DB (all sites): name `local`, user `root`, pass `root` (LocalWP local defaults). Hub table prefix `wp_`.
- **The hub's live plugin IS the main git checkout** at `/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main` (on `master`). Fast-forwarding it = deploying to the hub.
- Both connectors' plugins are **git checkouts of the same remote** (`origin` = `https://github.com/franklyco/db-version-control-main.git`) → deploy with `git -C <path> pull --ff-only origin master`.
- This session worked inside a git **worktree** at `…/db-version-control-main/.claude/worktrees/m8-slice1-post-type-coverage-4202a2`. You may reuse it or make your own; just branch from `origin/master`. The **git stash stack is shared** across worktrees — don't use bare `git stash`.
- The MySQL client binary: `/Applications/Local.app/Contents/Resources/extraResources/lightning-services/mysql-8.0.16+6/bin/darwin/bin/mysql`.

---

## 4. Recipes (copy-paste)

**Branch for a slice** (from the plugin dir):
```
git fetch origin && git checkout -b claude/m9-slice0-identity origin/master
```

**Build the hub React app** (single file `src/connected-app/index.js` → `build/connected-app.*`):
```
npx wp-scripts build connected-app
# wp-scripts deletes the OTHER apps' build outputs — restore them:
git checkout -- build/admin-app.* build/admin-entity-editor.* build/content-collector-v2-app.* build/style-admin-app*.css build/style-content-collector-v2-app*.css
npx wp-scripts lint-js src/connected-app/index.js
```

**Run the PHPUnit group** (needs `composer install` in the checkout; `tmp/wordpress-tests-lib` present; test DB is `local` with the isolated `wptests_` prefix — safe alongside live `wp_`):
```
php -d mysqli.default_socket="$HOME/Library/Application Support/Local/run/4gScrLykQ/mysql/mysqld.sock" \
  vendor/bin/phpunit --group connected-environments
```
Current baseline: **128 tests / 3107 assertions** green.

**agent-docs** (after source edits, last):
```
composer agent-docs:refresh && composer agent-docs:check   # must say 0 unmapped
```
Map any new **CLI subcommand** id in `docs/agents/manifest.json` (find the `wp dbvc agency …` record and add `cli.command.dbvc.agency.<sub>` to both its `identifier` pipe-list and `discovery_ids`). **REST routes are loop-generated** from `RestController` READS/WRITES — no separate discovery. **Filter names must be string literals** (a dynamic/variable filter name is invisible to the scanner).

**Deploy after merge:**
```
git -C /Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main merge --ff-only origin/master   # hub
git -C /Users/rhettbutler/Documents/LocalWP/dbvc-connectedsite-a/app/public/wp-content/plugins/db-version-control-main pull --ff-only origin master   # connector A
git -C /Users/rhettbutler/Documents/LocalWP/dbvc-connectedsite-butler/app/public/wp-content/plugins/db-version-control-main pull --ff-only origin master  # connector B
```

**wp-cli against a site** (LocalWP php + socket), e.g. the hub:
```
php -d mysqli.default_socket="$HOME/Library/Application Support/Local/run/4gScrLykQ/mysql/mysqld.sock" \
  <localwp-wp-cli-phar> --path=/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public \
  --url=https://dbvc-codexchanges.local dbvc agency …
```
(Prior sessions kept `livewp.sh`/`scratchwp.sh` wrappers in the scratchpad for this. Add `-d error_reporting=0 -d display_errors=0` to keep wp-cli deprecation notices off stdout.)

**Enable/disable a gate on a live site** (⚠ `save_settings` keys on **KEY PRESENCE, not value**):
```
DBVC_Agency_Control_Addon::save_settings([DBVC_Agency_Control_Addon::OPTION_ENABLED => '1']);  // enable hub
DBVC_Agency_Control_Addon::save_settings([]);  // disable (removes the key)
```

**`.phpunit.result.cache`** is tracked but gitignored — if it shows as modified, `git checkout -- .phpunit.result.cache` before committing.

---

## 5. The linchpin you must solve in Slice 0 (verified in code)

**Bricks option-collection members (global classes, variables, components, palettes, theme styles) identify by their SITE-LOCAL `id`.** `addons/connected-environments/src/Adapters/BricksOptionCollectionObserver.php` sets `key_field='id'`, so the projection's `instance_uid = entry['id']`. `addons/agency-control/src/Comparison/ComparisonService.php::compare()` matches an object across two environments **only** by an equal `instance_uid` **or** an explicit `InstanceLinkStore` link (`map_for_pair`). **There is no name/slug auto-matching today** — the M8 "match by `slug+type`/`title`" was planned in the doc but never implemented (grep-confirmed absent).

Consequence: Bricks generates a different local id per site, so `fw-container` has a different id on every client → the hub sees them as unrelated/absent, and parity, overrides and the dashboard cannot align a framework object across the fleet.

**Slice 0 = the fix:** an auto-linker for framework-owned objects that matches by **name/token**, reusing `DBVC_Bricks_Portability_Registry::get_domain_registry()` `match_order` (classes `[name,id]`, variables `[token,name,id]`, components `[name,slug,id]`, templates `[slug,name]`), and writes `InstanceLinkStore` links **proposed for operator review, never silent**. Then route comparison, `OverrideStore` lookups (which key on `(environment_id, domain, instance_uid, definition_uid)` = the local id) and the dashboard through the resolved identity. **Everything else in M9 depends on this.**

**Two more identity facts:** option-collection domains have **no `vf_object_uid`** (that meta is only on entity-backed domains — templates, service/CPT posts); and `Dbvc\ConnectedProtocol\Comparison` already emits the states `clean` / `local_drift` / `approved_override` / `version_differs` (framework) and `synchronized` / `outgoing` / `incoming` / `converged` / `conflict` (environment) — you consume these, you don't invent new ones.

---

## 6. Reuse map (what to build ON, not reinvent — recon-confirmed)

| Need | Reuse | Where |
|---|---|---|
| Cross-site identity for Bricks | `match_order` identity model | `addons/bricks/portability/class-dbvc-bricks-portability-registry.php` |
| Hash/canonicalize/fingerprint | `DBVC_Bricks_Artifacts::canonicalize()/fingerprint()`; protocol `Canonicalizer` | `addons/bricks/bricks-artifacts.php`; `includes/Dbvc/ConnectedProtocol/Canonicalizer.php` |
| Drift states + dot-path diff summary + ignore/mask rules | `DBVC_Bricks_Drift::scan()` (`CLEAN/DIVERGED/OVERRIDDEN/PENDING_REVIEW`) | `addons/bricks/bricks-drift.php` |
| Source-vs-target value diff | `DBVC_Bricks_Portability_Diff_Engine::build_review_session()` (match_order paired) | `addons/bricks/portability/class-dbvc-bricks-portability-diff-engine.php` |
| **Value diff at approval ("see the CSS") + baseline-vs-override policy** | `ConfigurationPortability` `DomainProviderInterface::diff()` + `Field` policy vocab (`PORTABLE/EXCLUDE/REDACT/KEEP_EXISTING/REPLACE/…`, default profile **`agency_baseline`**) + PHP accordion `AdminPage::render_domain_diff_accordion()` | `includes/Dbvc/ConfigurationPortability/` |
| Decision vocabulary at approval | `accept` / `keep` / `accept_new` / `decline_new` | proposal system (`admin/class-admin-app.php`) — borrow the vocab only |
| Overrides (approved drift) | `OverrideStore` (approved hash + rationale + approver, `needs_rebase_review`) | `addons/agency-control/src/Storage/OverrideStore.php` |
| Framework publish/adopt | `FrameworkService::publish/set_desired/adopt_version` (+ override approve/detach) | `addons/agency-control/src/Framework/FrameworkService.php` |
| Guarded apply pipeline (no new write path) | `ReleaseService`, `PreparationService`, `ApprovalService`, `RolloutService`; connector `Applier` | `addons/agency-control/src/Release/`; `addons/connected-environments/src/Apply/Applier.php` |
| Parity engine + cron | `SyncDriver`, `SyncPolicyService`, `ParityScheduler` | `addons/agency-control/src/Parity/` |
| Identity/link store + comparison | `InstanceLinkStore`, `ComparisonService` | `addons/agency-control/src/Storage/`, `.../Comparison/` |
| Envelope pattern (if a framework package is needed) | `package_type` + `package_schema_version` + `generator` + `compatibility` + `checksums` | `DBVC_Backup_Manager` / `AiPackage` / `ConfigurationPortability` |

**Do NOT reuse** (post/term-welded, or compiled): `DBVC_Backup_Manager` / `DBVC_Snapshot_Manager` / `DBVC_Sync_Posts::import_backup`, and the React proposals SPA (`src/admin-app`). Store M9 records in agency-control's own stores; borrow only patterns/diff layers.

**Genuinely new (kept minimal):** environment **tags/segments** (additive column or link table); a **fleet desired-vs-observed rollup** (composed from `ProjectionStore` + `BaselineStore` + `OverrideStore` + rollout status); **framework-definition policy lineage** (extend `DefinitionStore`); **guarded creation apply**.

---

## 7. Slices (reordered — build in this order)

Full text in the design doc `## M9 → ### Suggested slices (build order)`. Summary:

0. **Cross-site object identity & linking** (prerequisite — §5). *(S–M)*
1. **Overrides → parity enforcement** + approved-drift badges + inline lock/unlock. `SyncDriver::select_objects()` must consult `OverrideStore` and never overwrite an approved override, even under `source_wins`. *(S)*
2. **Value diff at approval** (reuse ConfigurationPortability diff + accordion / Bricks diff engine; fed by the sealed release payload). *(S–M)*
3. **Fleet dashboard** (env × state matrix; the mockup exists — see §9). *(M)*
4. **Framework publish → fleet rollout + guarded creation apply** (the headline; `create_new` today proposes but the apply blocks `creation_requires_decision`). *(L)*
5. **Environment tags/segments** (target a push by segment across clients). *(M)*
6. **New Bricks domain adapters** (components/palettes/theme-styles/pseudo-classes collections; breakpoints/global-settings singletons; Bricks-aware templates). *(L)*
7. **Notifications + roles + maintenance-window/freeze + audit export.** *(M)*

Each slice: PHPUnit `--group connected-environments` + **net-zero** live-verify on the disposable scratch(es). The continuous **cross-site framework-push** run (hub + connector A + connector B) is the **M9 exit gate**.

Also honored across all slices (from the doc's Design & UX + readiness sections):
- **Mockup-first** for the two net-new surfaces (dashboard, publish flow) — done for the first pass (§9).
- **Plain-language labels** in the UI (technical term on hover); technical identifiers stay in code/CLI/protocol. One `__()`-wrapped label map, translatable.
- The full **readiness checklist** (golden-source payload freeze, creation reference-remap + `collection.order`/categories, topological apply order, Bricks CSS regen coverage + per-site Bricks version skew gating, scheduler/concurrency via M6 cohorts, role capabilities in `RestController::permission`, secret redaction in the diff, a Bricks cross-reference test fixture). Read `## M9 → ### Pre-wiring, requirements & pitfalls`.

---

## 8. Accumulated gotchas (bite marks from M0–M8)

- `wp-scripts build connected-app` **deletes the other build outputs** → restore via `git checkout -- build/…` (see §4).
- In the built-in browser, `form_input` does **not** fire React `onChange` for a **checkbox** — click it with a real `computer left_click`; and a React re-render **moves buttons**, so re-find refs before clicking.
- The hub **cannot seal a release alone** — it stores only hashes; the **source connector delivers payloads** to seal. Approval/apply then proceed.
- An **empty execution-receipt `items`** array does **not** advance the hub baseline — the receipt must carry the applied item with `after_hash` + `verified`.
- `collection.order` (Bricks) is **never releasable** — parity/creation must skip it as an object but keep the order coherent when creating.
- The scratch connectors' **export mask** (`post_date/post_date_gmt/post_modified*`) makes a post projection `complete=false` — this is expected masking, not a bug.
- Cross-site HTTPS to `.local` needs `dbvc_connected_allow_insecure_hub` + an `http_request_args` `sslverify=false` shim (self-signed certs).
- Bricks profiles are dash-form (`bricks-global-class-v1`, `bricks-variable-v1`) — beware `sed`/regex where `.` matches the dash.
- Browser checks used a **temporary token-gated auto-login mu-plugin** on the scratch (removed after). Admin user on the hub: id 6 (`agentadminuser1`).

---

## 9. Pointers

- **Design of record:** `docs/implementation/active/connected-environments-agency-control.md` → `## M9` (goal, ownership model, reuse map, Parts 1–7, Design & UX, readiness checklist, slices 0–7). Earlier `## M5`–`## M8` sections + `## Limitations and unverified gates` give the full prior context.
- **Tracking:** `docs/dropins/dbvc-connected-agency/tracking/tasks.json` — M9 entry `status: planned` with a dense note mirroring the plan + readiness.
- **Mockup:** `docs/implementation/active/mockups/m9-fleet-console-mockup.html` (fleet dashboard + 4-step publish flow + labels/ownership legend, light+dark). Also published (owner-private) at `https://claude.ai/artifact/Ej7uCtWVXqUTEGCY3Ak1e9`. Contrast was fixed at the token level; if you edit `src/connected-app` UI, keep this mockup in sync when the design settles.
- **Memory (auto-loaded index):** `MEMORY.md` → `connected-environments-branch-state.md` (the running log — every milestone, PR#, gotcha) and `visual-editor-branch-layout.md`.
- **v1 restore point** (before the M9 sprint): tag **`connected-environments-v1`** and branch **`backup/connected-environments-v1`** at `master 0ae2c9a` (pushed to origin).
- **Open v1 items** (deferred/non-blocking; most fold into M9): Bricks templates/categories → slice 6, trash/locked-flags niche; `create_new` apply → slice 4; non-laptop scheduler → M9 prereq; M7 media addenda (Bricks content media refs, fetch-by-hash over the 2 MiB inline cap, offload stored bytes from the `dbvc_ac_release_items.media` column); out-of-scope: bidirectional/merge parity, multisite, the emergency constant.

---

## 10. First moves for Slice 0

1. `git fetch origin && git checkout -b claude/m9-slice0-identity origin/master`.
2. Read `ComparisonService::compare()`, `InstanceLinkStore` (its link shape + `map_for_pair`), `BricksOptionCollectionObserver` (projection/`instance_uid`), and `DBVC_Bricks_Portability_Registry::get_domain_registry()` (`match_order`).
3. Design the auto-linker: given a source env + a target env + a domain, propose links by `match_order` (name/token), returning candidates for operator review; persist accepted links via `InstanceLinkStore`. Decide the surface (a hub action + REST write + CLI subcommand via `DBVC_Agency_CLI_Inspector`, mirroring how `sync-policy-set`/`environment-reassign` were added).
4. PHPUnit in `--group connected-environments`: two environments each holding an `fw-container`/`fw-button` under **different local ids**; assert the linker proposes the correct pairings by name/token, that accepting them makes `ComparisonService` read `synchronized`, and that nothing auto-links silently.
5. `composer agent-docs:refresh && composer agent-docs:check` (0 unmapped) → PR → merge → deploy → update `tasks.json` (mark Slice 0) + memory.
6. Live-verify net-zero on connector A + B (two real sites, different ids, link + compare synchronized), then clean up.

Good luck — the machinery is almost all there; M9 is mostly wiring, thin adapters, and UI on top of it.
