# WS Form Reference Portability — implementation guide and slice ledger

**Audience:** the next Claude Code (or human) developer picking this up cold.
**Written:** 2026-10-08. **Master tip at creation:** `14b9865`. **Branch:** `claude/wsform-sync-export-issue-286143` (`4067522`).
**Status:** **S1–S4 merged to master as `eda9447`; S7 (include-trash reporting) in review. All live-verified (2026-10-08/12) — the exit gate is cleared and the ordering defect is fixed.** The feature is live cross-site verified, net-zero, across all three resolution paths and both import orchestrators. **S4 Phase 1 (WS Form internal ID preservation) is shipped, opt-in and live-verified.** Remaining: Phase 2 (cross-site reference rewriting) and Phase 3 (data-source / attachment mapping), both deliberately unscoped. The parallel brief's five corrections are recorded in `docs/requests.md`.

Read these first, in order:

1. This file — the slice ledger in §5 is the resume point.
2. [`includes/class-wsform-reference-remap.php`](../../../includes/class-wsform-reference-remap.php) — the whole design is ~300 lines and self-documenting.
3. `docs/agents/manifest.json` → record `portability.third_party.wsform_references` for current status, safety, storage, and known gaps.

---

## 1. The problem (exact)

Post meta that points at a WS Form form by **raw form ID** exported the literal ID with nothing to resolve it:

```json
"custom_wsform": ["2"],
"core_allpostypes_controls_objects_custom_wsform": ["3"]
```

Form IDs are per-site autoincrement values. Import matches forms by `dbvc_portability_uid` and falls back to `db_create()` when a uid is unmatched ([`class-third-party-portability.php:561-569`](../../../includes/class-third-party-portability.php#L561)). The reference therefore arrived on the target site pointing at whatever form happened to hold that ID — or at nothing.

No remap existed anywhere in `includes/` or `addons/`. The only post-meta remap code in core is the `@WIP` block at `class-sync-posts.php:769-828`, which is **entirely commented out**.

### This is not the bug it was first reported as

The original report was "only one WSForm (form-item) object gets exported." That premise was a conflation of two separate things, and **the WS Form export was never broken**:

- `sync/form-item/` is a **Vertical-framework CPT**, not the WS Form export. It held one file because the site holds exactly one `form-item` post (ID 23306, `publish`; every other status 0).
- WS Form forms export to `third-party/ws-form/forms/`, and **all 13 non-trash forms were present**. A dry-run rebuild of `export_wsform_forms()` produced 13 payloads with 13 unique filenames, no nulls, no collisions.

Two unrelated real problems surfaced while confirming that, and are tracked in §6 as S5/S6.

---

## 2. Design of record: sidecar, not tokenization

Export records each referenced form's portable uid in an **additive** `dbvc_wsform_refs` block beside `meta`. Import resolves that uid to the local form ID.

**The raw ID is deliberately not replaced.** This is the one design decision to not relitigate without reading the reasoning:

> Bricks tokenization (M7/M9 `ReferenceTokens`) works because DBVC owns both ends of the element tree. Here the consumer is **ACF reading the value at render time**. A token reaching a site that could not detokenize it would render literally as `dbvc-ref:ws_form:<uid>` — strictly worse than a wrong ID. With a sidecar, an unresolvable reference survives exactly as exported and is reported instead of corrupting output.

Consequences to preserve:

- Only an **unambiguous positive integer** is ever rewritten. Empty, non-numeric and composite values are left alone.
- ACF's `_`-prefixed companion keys hold field keys, not values, and **can never be candidates**.
- An unresolved uid is reported, never written.
- Import rewrites the post's sync JSON to the resolved local ID, matching what `vf_object_uid` and `dbvc_post_history` already do in the same function. This is intentional: it prevents permanent diff drift between file and database.

---

## 3. Working agreement

- Branch `claude/<short-name>` from **`origin/master`** — `git fetch origin` first, never from a stale worktree.
- **One small reviewable PR per slice.**
- After any **source** edit: `composer agent-docs:refresh` then `composer agent-docs:check` — must print **`0 unmapped`** (strict). Run `refresh` as the **last** step before committing.
- **CI does not run.** `.github/workflows/agent-docs.yml` has its `pull_request`/`push` triggers deliberately disabled (private repo, metered Actions). Validate locally; PRs merge past un-run checks. Do not wait on or poll CI.
- Attribution per the session's own system reminder — that governs over this file.
- **Update §5 of this doc in the same PR as the slice.** That is the point of this file.

### Safety constraints (hard rules)

- **Never run an import against real client content.** `frameworkflo-live.local` is read-only for this work. Use the disposable clones `dbvc-connectedsite-a` / `dbvc-connectedsite-butler`.
- **Net-zero after every live-verify** — remove fixtures, restore gates, prove it with before/after counts.
- The live site's plugin copy at `frameworkflo-live/.../plugins/db-version-control-main` is a **working checkout on branch `addon-enhancements` with 72 dirty paths**. It is not this repo's worktree. Do not develop there. See §7.

---

## 4. Environment and recipes

LocalWP on this Mac. No global `wp`, and the worktree has no `vendor/`.

```bash
PHP="/Users/rhettbutler/Library/Application Support/Local/lightning-services/php-8.3.0+1/bin/darwin/bin/php"
MYSQL="/Users/rhettbutler/Library/Application Support/Local/lightning-services/mysql-8.0.16+6/bin/darwin/bin/mysql"
```

| Site | Local site id | mysqld.sock path fragment |
|---|---|---|
| `frameworkflo-live.local` (REAL — read only) | `smixn_vNA` | `~/Library/Application Support/Local/run/smixn_vNA/mysql/mysqld.sock` |
| `dbvc-connectedsite-a.local` (disposable) | `4pwRYxV15` | `.../run/4pwRYxV15/mysql/mysqld.sock` |
| `dbvc-connectedsite-butler.local` (disposable) | `jEFL3lXs8` | `.../run/jEFL3lXs8/mysql/mysqld.sock` |

**PHPUnit** — the worktree has no `vendor/`; symlink the main checkout's, run, then **remove the symlink** (`.gitignore` has `/vendor/`, which does not match a symlink, so it shows as untracked):

```bash
ln -sfn /Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/vendor vendor
PATH="$(dirname "$MYSQL"):$PATH" php vendor/bin/phpunit --testsuite "DBVC Plugin" --do-not-cache-result
rm -f vendor
```

**Always pass `--do-not-cache-result`.** With caching on, PHPUnit reorders to run previously-failed tests first, and several connected-environments suites are order-dependent — you will get a different, misleading failure set and waste a turn chasing a regression you did not cause.

**Baseline to compare against (HEAD `14b9865`, caching disabled):** 7 failures — `BricksAddonPhase11Test`, `BricksAddonPhase7Test`, `CapabilityLandscapeTest`, `ContentCollectorV2Phase29Test`, `ContentCollectorV2Phase32Test`, `ContentMigrationPhase37W0SettingsTest`, `ProposalDiffContractTest`. Plus 6 `VisualEditorMediaManagerR2ATest` errors that **pass in isolation** — order-dependent flake, not a regression.

**Read-only probe against a live site** (loads WP, supplies the provider filters so an unmodified site checkout is never touched):

```bash
# Full working example: scratchpad probe3.php pattern.
# define('DB_HOST', 'localhost:<socket path>'); then require wp-load.php,
# then require the worktree's class-wsform-reference-remap.php and add the
# four dbvc_wsform_reference_* filters from your own uid/label tables.
```

---

## 5. Slice ledger — **update this table every turn**

| Slice | Scope | Status | PR | Evidence |
|---|---|---|---|---|
| **S1** | Sidecar export + uid-resolving import, registry, 5 filters, setting, UI toggle, manifest record | **shipped** (code complete, not live-verified) | [#142](https://github.com/franklyco/db-version-control-main/pull/142) | 12 focused PHPUnit cases pass; full suite = baseline 7, no new; `agent-docs:check` 64/601/0; export path exercised read-only on `frameworkflo-live.local` — 102 posts scanned, 101 correctly emit nothing, post 23306 emits both refs with uids identical to the existing form exports |
| **S2** | **Cross-site live-verify A→B — the exit gate** | **PASSED** 2026-10-08 | #142 | Adversarial pairing (both sites hold a form at id 2 labelled "Contact Form" with different uids). Post-before-form: reported unresolved, raw id preserved, **did not bind to the target's unrelated form 2**. Form import: created at new local id 9, target form 2 untouched. Post re-import: meta remapped 2→9, `dbvc_post_history` recorded the pair, sync file converged to `["9"]`. Teardown net-zero — all 9 counters back to baseline on both sites, target's form-2 sync file restored byte-identical, 4 patched files reverted with 0 tracked drift |
| **S2b** | Same-uid-different-id remap + same-id `unchanged` path | **PASSED** 2026-10-09 | #142 | A id 21 → B id 2 remapped with **no duplicate form created**; A id 3 → B id 3 reported `unchanged` with nothing written. **Surfaced and fixed a real defect** — see below. Net-zero |
| **S3** | Import ordering: third-party entities before the post loop | **PASSED** 2026-10-10 | #142 | S2's "converges on re-import" reading was too generous: **both** orchestrators ran third-party import *after* the post loop, so a first-ever import left every referencing post unresolved. `import_all()` and the WP-CLI batched path now run it first. Proven live with a before/after on identical input — same package, `'21'`+unresolved before, `remapped:[{21→11}]` on the first pass after. Static ordering test guards both call sites |
| **S4** | WS Form internal ID preservation, Phase 1 (opt-in) | **SHIPPED + live-verified** 2026-10-10 | #142 | Live matrix on form 1 (12 fields, 48 real submissions): default OFF renumbered `1..73 → 144..155` and orphaned **479** submission meta keys, reproducing the original defect on real data; preserve ON kept IDs byte-identical with **0** orphans; the collision guard refused a foreign field ID (83, owned by form 7), named it, and mutated nothing. 13 unit cases. Net-zero via a table dump/restore |
| **S4b** | Phase 2 cross-site reference rewriting / Phase 3 data-source mapping | deferred | — | Phase 2 should consume S1's uid substrate rather than build a second one |
| **S5** | Reference coverage beyond post meta (term meta, options, builder trees) | deferred | — | — |
| **S6** | Follow-ups found while diagnosing (stale ws-form export, manifest gap) | partly answered by S5/S7 | — | — |
| **S5** | Export-run testing of the merged build on a disposable clone | **PASSED** 2026-10-12 | — | Sidecar emitted on real content with uids matching the forms' own exports; 22 forms exported with unique filenames and uid+source_id on every payload; **S6's stale-export claim confirmed live** — deleting a form file and creating a chunked job restores it, resuming the same job does not |
| **S7** | `include_trash` silent no-op → skip reporting | **SHIPPED** 2026-10-12 | PR open | Found by S5. 25 forms considered, 22 written, 3 trashed skipped and now *named* with reason `trash_unreadable`; `forms_considered == forms_written + forms_skipped`. 8 unit cases |

**`live_runtime_verified` is now `true` in the manifest record**, set 2026-10-08 on the strength of the S2 evidence above. Revert it to `false` if the import path changes materially before the next live pass.

---

## 6. Slices in detail

### S2 — cross-site live-verify A→B (PASSED 2026-10-08)

**Result: PASSED, net-zero.** Everything S1 verified was the export half, read-only; S2 closed the import half, the same gap M9 slice 6e closed for Bricks.

**The pairing chosen was adversarial on purpose.** A form 2 and B form 2 are *both* labelled "Contact Form" at the *same* id, with **different** uids (`2cc328b7…` vs `9cc6a1fb…`). An id-based or label-based matcher would silently bind A's reference to B's unrelated form. It did not.

| Phase | Expected | Observed |
|---|---|---|
| Post imported, form absent | unresolved; raw id survives; no bind to B's form 2 | `unresolved:[{uid:2cc328b7…, source_form_id:2}]`; `custom_wsform=["2"]`; B form 2 untouched |
| Form imported | new local id, not 2 | created at **id 9**; B's own form 2 unchanged |
| Post re-imported | meta → 9; history records the pair | `custom_wsform=["9"]`; `remapped:[{2→9}]`; sync file converged to `["9"]` |
| Teardown | net-zero both sites | all 9 counters back to baseline; B's form-2 sync file byte-identical; 4 patched files reverted, 0 tracked drift |


**Conditions are unusually good.** Measured 2026-10-08:

| | `connectedsite-a` | `connectedsite-butler` |
|---|---|---|
| Running | yes | yes |
| WS Form Pro | present | present |
| `wp_wsf_form` rows | 25 (15 with uids) | 6 (6 with uids) |
| Occupied form IDs | 1–8, … | 1, 2, 3, 6, 7, 8 |
| DBVC checkout | `master` @ `dd08137`, 564 dirty | `master` @ `dd08137`, 5667 dirty |

**Shared uids between A and B: zero.** This is what makes the test clean rather than contrived — import one of A's forms into B and it lands at a **fresh ID ≥ 9**, so `source_form_id ≠ local_form_id` is guaranteed. That is precisely the condition the remap exists for.

**Steps.**

1. Deploy branch `claude/wsform-sync-export-issue-286143` to A and B. Both run their own DBVC at `dd08137` **without** S1's code, and both are heavily dirty — plan this against the WIP before touching it. Record what you changed so teardown can restore it.
2. On A: enable `dbvc_third_party_portability_wsform_forms`, export. Confirm the form JSON and the post JSON both appear, and that the post payload carries `dbvc_wsform_refs` with the form's uid.
3. Import the **form** into B first. Record B's new local ID (expected ≥ 9).
4. Import the **post** into B. Assert: the registry meta key now holds **B's** local ID, not A's; the sidecar uid is unchanged; `dbvc_post_history.wsform_reference_remap.remapped` names the source→local pair.
5. Negative case: import a post whose sidecar uid exists on neither side. Assert the raw ID **survives untouched** and lands in `unresolved`.
6. Ordering case: import the post **before** the form. Assert the raw ID survives, is reported unresolved, and that a second pass after the form import converges. **This is what decides S3's shape** — if convergence on re-import is good enough, S3 may reduce to documentation.
7. Teardown to net-zero on A and B; prove with before/after row counts. Restore the deployed plugin state.
8. Update the manifest record (`live_runtime_verified`, `verified_date`, `evidence_types`, notes), `composer agent-docs:refresh && check`, and §5 here.

**Deployment method that worked, reuse it.** All four files S1 touches (`db-version-control.php`, `includes/hooks.php`, `includes/class-sync-posts.php`, `includes/class-third-party-portability.php`) were **clean** on both clones even though the checkouts carry 564 / 5,667 dirty paths elsewhere — so an anchor-based patch plus `git checkout --` gives provable restoration. Verify anchor uniqueness before mutating, lint after, and skip `admin-page.php` (UI is not needed).

**Measurement trap:** record dirty counts with the *same* flags before and after. `git status --short` collapses untracked directories; `--untracked-files=all` expands them. Mixing the two showed a phantom +14 on both sites.

### S2b — same uid, different local id (PASSED 2026-10-09)

The steady-state case S2 did not cover: a form already propagated between sites whose local IDs have drifted. Found naturally — site A is a clone of `frameworkflo-live` with shifted ids, so A id 21 "Contact Form" carries the same uid as `frameworkflo-live` form 2.

Setup: align B's form-2 uid to A's form-21 uid, and B's form-3 uid to A's form-3 uid. One fixture post then exercises both remaining paths.

| Path | Expected | Observed |
|---|---|---|
| uid at a **different** id (A 21 → B 2) | remap, no duplicate form | `custom_wsform=["2"]`, `remapped:[{21→2}]`, B form count unchanged at 6 |
| uid at the **same** id (A 3 → B 3) | `unchanged`, no write | `core_…=["3"]`, `unchanged:[{form_id:3}]` |

**It caught a defect that 12 green unit tests had missed.** `get_wsform_form_uid()` minted *and persisted* a uid row for a form ID absent locally. The trigger is ordinary: `dbvc_handle_post_status_transition` ([`hooks.php:183`](../../../includes/hooks.php#L183)) auto-exports when the import **creates** the post, which happens *before* `apply_import_refs` rewrites the meta — so the export filter saw A's id 21 and wrote `wsf_form_meta(parent_id=21, uid=<new>)` on a site with no form 21.

Two harms, the second worse than litter:

1. Orphan rows accumulate in `wsf_form_meta`.
2. The uid is **claimed** against id 21. If B later creates a form that lands there, `find_wsform_form_id_for_uid()` resolves to it — a false identity match, and a future export would emit that uid in a sidecar.

Fixed by gating on a new `wsform_form_exists()` check; two regression tests added; re-verified live (orphan rows **1 → 0**). The unit tests missed it because they stub the provider seams, so no real uid minting occurred — a reminder that the seams that make this testable also hide writes.

### S3 — import ordering (PASSED 2026-10-10)

**S2's reading was too generous.** "It converges on the next import pass" was true but masked the real defect: **both** orchestrators ran the third-party import *after* the post loop, so a first-ever import of a sync folder left every referencing post unresolved. Convergence required a second full import run — which nothing tells the operator to do.

| Orchestrator | Was | Now |
|---|---|---|
| `DBVC_Sync_Posts::import_all()` | third-party last | third-party first, before `suppress_export_on_import` + the post loop |
| WP-CLI batched import | third-party after the `do/while` | third-party before the loop, outside it |
| `import_all_json_files()` | — | delegates to `import_all()`, so covered |
| `import_posts_batch()` | never handled third-party | unchanged; its caller orders it |

**Before/after on identical input**, the strongest evidence available: the same two-file package (one post referencing A's form 21, plus that form) imported into B.

- Pre-fix: form created at id 10 *after* the post → `custom_wsform='21'`, `unresolved:[{uid:c7077159…}]`.
- Post-fix: form created at id 11 *before* the post → `custom_wsform='11'`, `remapped:[{21→11}]`, `unresolved` empty.

**Bounded live method worth reusing:** point `dbvc_sync_path` at a scratch folder holding only the entities under test, run the real `import_all()`, then restore the option. Two files instead of thousands, real code path, no full-site import.

A static ordering test (`test_third_party_import_is_ordered_before_the_post_loop`) asserts the source offsets in both call sites so a future refactor cannot silently reintroduce it.

### S4 — WS Form internal ID preservation, Phase 1 (SHIPPED 2026-10-10)

Opt-in: `dbvc_third_party_portability_wsform_preserve_ids` (default **off**) plus a `dbvc_third_party_wsform_preserve_ids` filter and an admin checkbox. Renumbering stays the default because it is the right behaviour when *cloning* a form to a new identity; preservation is right when *updating* a matched one.

**Why it matters, measured.** Submission meta keys are `field_<id>`, and `#field(id)` references are stored by ID. Live on form 1 (12 fields, 48 submissions, 687 `submit_meta` rows):

| Mode | Field IDs | Orphaned submission keys |
|---|---|---|
| Default (off) | renumbered `1..73` → `144..155` | **479** |
| Preserve on | byte-identical | **0** |

**The guard is the load-bearing part.** WS Form's child tables share one global `AUTO_INCREMENT` space with no per-form namespace, and `db_update_insert()` (WS Form Pro 1.10.87, `includes/core/class-ws-form-core.php:139-147`) probes only *whether* an ID exists, never whether this form owns it. Unguarded, `$new = false` would UPDATE a foreign row and drag it into the imported form. Verified live: injecting field 83 (owned by form 7) returned `WP_Error dbvc_wsform_id_collision`, named it, and left both forms intact.

Ownership uses **set arithmetic, not a join** — `collisions = (incoming ∩ existing_in_table) − owned_by_target`. A nested section can carry `group_id = 0` and be reachable only through its parent chain, so a `field → section → group → form` join under-reports ownership and would wave through a real collision.

Order of operations, which is not negotiable: the guard runs **before** `db_import_reset()`, because that call destroys the target's tree (WS Form Pro `includes/core/class-ws-form-form.php:1108-1145` deletes only *this* form's meta and groups) and a collision discovered afterwards would leave nothing to roll back to. The write is wrapped in a transaction when every WS Form table reports InnoDB, and degrades to guard-only otherwise rather than claiming a rollback it cannot deliver. `AUTO_INCREMENT` watermarks are bumped after `COMMIT` since DDL is not transactional, and a failure there is logged, not fatal.

**Correction to the brief, found while implementing.** With `$new = false` the form level adopts `$form_object->id` — the *source* form ID (WS Form Pro `includes/core/class-ws-form-form.php:1686`). The brief's step list never normalizes it, and its claim that a missing uid match carries "no collision risk for the form row itself" is wrong: an unmatched import would target a foreign local form row. DBVC therefore sets `$form_object->id = $existing_id` and **restricts preservation to uid-matched forms**, falling back to renumbering otherwise.

All five corrections to the brief are recorded in [`docs/requests.md`](../../requests.md) rather than edited into another repository's doc.

**Live-verify method:** `mysqldump` the eleven `wp_wsf_*` tables first. Test 1 deliberately destroys submission linkage to demonstrate the default, so a byte-level restore is the only honest way back. Restore between every mode and confirm the ID fingerprint matches.

### S7 — `include_trash` was a silent no-op (SHIPPED 2026-10-12)

Found by the S5 export run, not by reading code. Site A had `include_trash = 1` and 25 forms; the export wrote **22** and said nothing.

The chain: `get_wsform_form_ids(true)` *does* return trashed forms, and `get_wsform_summary()` counts them in the UI — but `build_wsform_form_export_payload()` calls `db_read()`, which **throws `Unable to read form`** for a trashed form. DBVC caught the Throwable, returned null, and `continue`d. The returned count could not express the loss, so a setting advertised behaviour it cannot deliver and the operator had no way to know.

Confirmed on **both** WS Form 1.10.53 (clone) and **1.10.87** (`frameworkflo-live`, read-only), so it is not version-specific.

**Fix is reporting, not forcing.** Untrashing a form to read it would mutate site state during an export, so instead:

- `export_selected_entities()` still returns a file count — no caller breaks — and now also fills a report readable via `get_last_wsform_export_report()`.
- Every skip is classified: `trash_unreadable`, `unreadable`, `unsafe_path`, `encode_failed`, `write_failed`, each with an operator-facing message. Only an *unreadable* trashed form is reclassified, so a write failure on a trashed form is not mislabelled as the WS Form limitation.
- `forms_considered == forms_written + forms_skipped` — the invariant that makes silent loss impossible.
- The admin notice lists each skipped form by label, ID and status; skips are also logged.
- The checkbox now reads "**Attempt to** include trashed WS Form forms" with the limitation stated beneath it.

The setting is kept rather than removed: it still correctly widens which forms are attempted, and removing a public setting is a breaking change for anyone who set it.

### S5 — coverage beyond post meta

Term meta, options, and builder element trees are unscanned. Extend via `dbvc_wsform_reference_meta_keys` where the carrier is post meta; anything else needs its own seam.

Current spread on `frameworkflo-live` (measured 2026-10-08): the registry key sits on **103 non-revision posts across 14 post types** — feature, service, page, post, case-study, alternative, product, team-member, vertical, location, faq, gallery-cpt, form-item — plus 109 revisions. Only **2** carry a value today (forms 2 and 3, both on post 23306). Low blast radius now; grows with content.

### S6 — follow-ups found while diagnosing

- **Stale WS Form export on the chunked path.** `export_selected_entities()` runs only when a chunked job is *created* — `class-export-manager.php:74` is inside the `else` branch for `$job_id === null`. Any **resumed** chunk skips it, so WS Form silently goes stale. The full "Export all content" path (`admin-page.php:1186`) does run it every time. Observed on `frameworkflo-live`: form files dated Jul 2/Jul 7 against `bricks-registry-options.json` at Oct 8.
- **`manifest.json` ws-form gap.** The sync-folder manifest lists **12** ws-form paths; `ws-form-90003-listing-inquiry-contextual.json` is missing. The manifest regenerates only when a chunked export reaches `done` (`class-export-manager.php:152`), so it predates the Jul 7 writes. Anything driving off the manifest rather than `.dbvc-entity-index.json` (which has all 14 entries) will not see form 90003.

---

## 7. Accumulated gotchas

- **Two DBVC checkouts exist and are easy to confuse.** This repo's worktrees under `.claude/worktrees/`, and the live site's plugin copy at `frameworkflo-live/.../plugins/db-version-control-main` on `addon-enhancements` with 72 dirty paths. Line anchors match in both. Confirm which one you are editing.
- **`docs/roadmap.md` is really `docs/ROADMAP.md`.** macOS is case-insensitive, so an edit to the lowercase path silently lands on the tracked uppercase file and `git diff --numstat <lowercase>` prints nothing. Use the real name.
- **Never rewrite `docs/agents/manifest.json` with a JSON dump.** It uses **4-space** indent; `json.dumps(indent=2)` reformats all 406 KB (8,666/8,536 lines). Splice the serialized record into the existing text and assert pre-existing records stayed byte-identical. A correct single-record append is **+130/−0**.
- **`verification.repository_commit` must equal the manifest `baseline.repository_commit`**, not your HEAD, or `agent-docs:check` fails. Name the real checkout in `verification.notes` instead.
- **`discovery-snapshot.json` churns enormously for small hook additions** — discovery IDs embed a line-derived hash, so inserting lines in `hooks.php` rewrites every entry below. 5 new filters produced 759 changed lines. Expected; it is generated.
- **Scope a cleanup revert to the files you touched.** Restoring site A after S7 I ran `git checkout -- .`, which discarded **559 pre-existing uncommitted modifications** that had nothing to do with this work (the `tmp/wordpress/` test install, `dbvc-backup.log`, `.phpunit.result.cache`). Files were not deleted, just reverted to their committed copies, and the clone is disposable — but it was state I had promised to leave alone. Revert by explicit path, never `.`.
- **Anchor-based deployment carries only what you taught it.** It silently omitted the S3 reorder, then the whole of Phase 1 — each time producing a confident wrong verdict. Prefer the verified wholesale copy in `s4/deploy2.sh`: it asserts the target's committed base is byte-identical to the worktree's for every file before copying, so the copy introduces your changes and nothing else. Both clones' bases matched `master` exactly, which is what makes it safe here.
- **A silently fatal deploy.** Applying the deployment patch twice to the same clone duplicated the accessor methods — `Cannot redeclare get_wsform_form_uid()`. The harness printed *nothing* and exited **0**, because the fatal went to the site's `debug.log`. Always revert before redeploying; the deployer now refuses a second apply. When a bootstrap harness produces no output at all, read `wp-content/debug.log` before assuming anything else.
- **A deployer that carries only some of the change proves only some of it.** The first S3 live run reported FAIL because the patch script carried the five S1 edits but not the S3 reorder, so it measured pre-fix behaviour. That accidentally produced the before-half of the before/after, but verify what the target is actually running before trusting a verdict.
- **Harness false passes.** The S2b fixture post got a new ID on re-run while the import script still named the old file. The import failed, the assertion loop ran over zero posts, and the harness printed `OVERALL: PASS`. Derive filenames from the export output and fail loudly on an empty result set — a verification harness that cannot fail is worse than none.
- **Net-zero discipline earns its cost.** The orphan-uid defect was invisible to green unit tests, a correct-looking remap, and a passing assertion set. Only the `+1 wsf_form_meta` / `+1 uid_rows` delta against baseline exposed it.
- **Export filter priority matters.** Masking runs on `dbvc_export_post_data` at priority 20 (`hooks.php`). The sidecar runs at **30** so a reference stripped by masking emits no entry. Do not lower it.

---

## 8. Pointers

- Implementation: [`includes/class-wsform-reference-remap.php`](../../../includes/class-wsform-reference-remap.php)
- Export hook: [`includes/hooks.php`](../../../includes/hooks.php) (`dbvc_export_post_data`, priority 30)
- Import call site: [`includes/class-sync-posts.php`](../../../includes/class-sync-posts.php) in `import_post_from_json`, after the apply gate
- Promoted accessors: [`includes/class-third-party-portability.php`](../../../includes/class-third-party-portability.php)
- Tests: [`tests/phpunit/WSFormReferenceRemapTest.php`](../../../tests/phpunit/WSFormReferenceRemapTest.php)
- Capability record: `docs/agents/manifest.json` → `portability.third_party.wsform_references`
- Facet: [`docs/agents/facets/core-import-export.md`](../../agents/facets/core-import-export.md)
- Live-verify discipline and the A/B clone recipe: [`docs/implementation/active/connected-environments-m9-handoff.md`](connected-environments-m9-handoff.md) §§2–4
