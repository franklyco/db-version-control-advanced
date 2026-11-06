# DBVC Weekly Status — 2026-10-23

Snapshot of repo state (GitHub + local) and the open work remaining to reach a clean slate.
Generated from `master` at `e667334c`, `git fetch --all` on 2026-10-23.

- **Active workstream:** Content Collection / Migration **V3** (the only live development).
- **Open PRs:** 3 (all content-migration, all `MERGEABLE`, none merged yet).
- **Connected Environments (M9 + post-M9 Phases A/B/C):** complete and live-verified; two optional/deferred items only.
- **Stale branches:** 4, all mapped to closed/merged PRs — safe to delete.

---

## 1. Open PRs — ready to land

> **Update (2026-10-23, later same day): all three merged** in order #191 → #190 → #189. `master` tip is now `f68ef900`; no open PRs remain.

All three are against `master`, mergeable, and carry their own test evidence. No GitHub CI is configured (`gh pr checks` reports "no checks") — verification is the repository PHPUnit suite + `composer agent-docs:check`, run in-branch.

| PR | Branch | Slice / scope | State | Notes |
|----|--------|---------------|-------|-------|
| [#191](https://github.com/franklyco/db-version-control/pull/191) | `claude/competent-easley-167942` | Test infra: fetch WP test library matching `WP_VERSION` | Mergeable | Fixes `Error: Interface "WP_Secrets_Store" not found` — `bin/install-wp-tests.sh` was pinned to trunk; now derives `WP_TESTS_TAG` from `WP_VERSION`. Verified on WP 7.1.3 (1449 tests reach the suite). Unblocks local test runs. |
| [#190](https://github.com/franklyco/db-version-control/pull/190) | `claude/cc-net-zero-os-metadata` | Test fix: exclude OS metadata from the G-7 net-zero uploads hash | Mergeable | Excludes `.DS_Store`/`Thumbs.db`/`desktop.ini`/`._*`. Restores the 4-failure baseline. Mutation-checked so the hash still tracks real media. The "proper fix" #189 deferred. |
| [#189](https://github.com/franklyco/db-version-control/pull/189) | `claude/cc-flag-boundary-hygiene` | **V3 S7** — feature-flag boundary hygiene (D-24…D-26) | Mergeable | Tail of #165/#188. Scopes `OPTION_FLAG_MAPPING_WORKBENCH` (D-24), moves a cron handler out of a constructor (D-25), documents stranded `queued` residue (D-26). **None was a live defect.** 272 content-migration tests OK; agent-docs clean. Carries the V3 guide update that adds D-24…D-26 + S7 (this doc update exists **only on this branch**, not on `master`). |

**Suggested merge order:** #191 (unblocks the harness) → #190 (restores the test baseline) → #189 (feature; its diagnosis of the 5th failure is what #190 fixes properly). All three touch or reference `ContentMigrationImportNetZeroTest`; #190 owns the edit, #189 only diagnosed it, so no real conflict.

---

## 2. Known-failing test baseline (pre-existing, tracked)

The full PHPUnit suite carries **4 long-standing failures** on `master`, independent of the open PRs and reproducible at `e667334c`:

- `BricksAddonPhase11Test`
- `BricksAddonPhase7Test`
- `CapabilityLandscapeTest`
- `ProposalDiffContractTest`

A transient 5th failure (`ContentMigrationImportNetZeroTest`) was traced to macOS `.DS_Store` churn in the shared test uploads tree — fixed by #190. These 4 are open cleanup work; nothing has triaged or scheduled them yet.

> Note: the shared WordPress test install lives under the **main checkout's** `tmp/wordpress/` and is shared by every worktree; it holds ~55,802 files / 959 MB of accumulated residue. Anything that hashes/counts files under uploads is sensitive to it.

---

## 3. Content Migration V3 — remaining slices (the real "clean slate" work)

Plan of record: [`docs/implementation/proposed/content-collection-v3/README.md`](implementation/proposed/content-collection-v3/README.md). (Its header still reads *"Status: Proposed. No code written against this guide yet"* — stale; §9.1 lists 10 delivered slices. Worth correcting on the next edit.)

### Delivered (§9.1)
S0, S1a, S1b, S2a, S2b, S4, S4b, S2c, S1c-a, S1c-b, S4c — plus D-22/§6.1e fixes. The pipeline now runs end to end: external artifacts ingest (D-21 closed), the model interpreter is wired and measured (D-20 closed, 100% field accuracy vs 6.7% lexical on slot 1), and G-7 net-zero import+rollback is proven on a 4,292-post disposable clone. **S7 lands with #189.**

### Remaining (§9.2, priority order)

| Pri | Slice | Scope | Done when |
|-----|-------|-------|-----------|
| **P1** | **S1d** | **One URL → many entities (D-19).** `recommended_target_object` is a single object; `/dental-team` must yield 5 `team_member` records and structurally can't. Make the recommendation a list, teach entity resolution to resolve a set per URL, give the exception queue set-review. Also widen the mapping index item set (§6.1c finding 2 — drops interpreter answers, can't reach `meta:_seo_robots`). **Contract change — cheapest before S3 authors more ground truth.** | `/dental-team` → 5 entities committed as 5 posts; `unjoined_recommendation_items` = 0 on slot 1 home. |
| **P1** | *(no slice id)* | **Unit-economics baseline.** Time one migration by hand; measure operator-minutes/page/slice. The only number that says whether this beats copy-paste. ~an afternoon. | Recorded per-page manual cost + per-slice operator-minutes. |
| **P2** | **S3** | **Corpus slots 2, 3, 5**, fix what they break, **then** recalibrate G-4's inherited 0.92 threshold. Absorbs D-22's still-open tie-break/six-row-cap half and §6.1c's `target_ref` spelling divergence. | G-1…G-5 hold on 4 profiles; G-4 threshold justified from ≥3. |
| **P2** | **S6a** | **Flip the runtime default (D-17).** `OPTION_RUNTIME_VERSION` still defaults to `RUNTIME_V1` — V2 is still opt-in after 32 phases, two runtimes maintained. Flip → freeze V1 → delete. | Fresh install runs V2; V1 gone, not dormant. |
| **P3** | **S8** | **Migration-planning artifacts** (missing scope, not defects): redirect map (old→new URL; cheapest high-visibility item, data already captured), `migration-plan.v1` dispositions, SEO continuity set, source-drift re-crawl report. Connect reviewer overrides → pattern learning. | Run emits redirect map + migration plan; re-crawl reports changed source pages. |
| **P3** | **S5** | **Scale & budget:** slot 5 (150+ pages), per-run budget ceiling w/ pre-run estimate + hard stop, concurrency, resumability. | G-8 holds at 150+ pages; G-6 determinism measured. |
| **P3** | **S6** | **Cleanup:** collapse the flag set (13 flags/66 settings keys), rename phase-numbered tests by behaviour (F-4), cap/rewrite `MIGRATION_MAPPER_V2_WORKING_STATE.md` (F-6), fix the stale `codex/content-addon-v2` branch anchor, populate-or-delete dead `media_refs` plumbing (D-10). | Flag count single digits; no test named after a phase number. |

### Still-open defects not yet scheduled into a slice above
- **D-3 (High)** — V2's `ai-context` / `initial-classification` services still stamp `model: "gpt-4o-mini"` + `prompt_version` on output no model produced. The new interpretation boundary fixed provenance (S1a); the old V2 services still lie. Ties to the "Connect / delete" verdict.
- **D-9** — segmenter still splits on every heading on the **in-WordPress capture path** (bypassed on the skill path; fix tracked as S4).
- **D-11** — `.htaccess` hardening not verified on a real nginx host.
- **D-16 (Medium)** — mapping coupled to the VerticalFramework theme; behaviour on other frameworks untested.
- **D-23 (Low)** — non-default port dropped from a media source URL (found by S4c, left unfixed by design).

---

## 4. Connected Environments — effectively clean

Per the running tracker, **M9 is complete + runtime-verified** (6e cross-site live-verify passed net-zero on the real 2-connector fleet, 2026-10-06), and post-M9 productionization **Phases A, B, C** are done:

- **Phase A** (scheduler #168 + media capacity A2-1 #171 / A2-2 #173–#175, fix #177) — complete, live-verified net-zero 2026-10-19.
- **Phase B** — roles REST (#179) + admin Roles panel (#180); governance confirmed a **non-goal**. Deployed-hub REST smoke green; visual click-through needs an admin login.
- **Phase C** — operator runbook shipped (#182).

**Open / deferred (optional, not blocking):**
- Larger-cohort live-verify (Phase C validate half) — **deferred by constraint**: the fleet is only 2 connectors; a 2-site rollout is already 6e-verified.
- **A2-3** media fs/object-store driver + chunked upload — only if scale demands.
- 7b roles admin-UI visual click-through — needs an admin login to confirm.

The 7 hub-write Bash allow-rules in `.claude/settings.local.json` can be removed now that 6e is done (noted in memory, not yet executed).

---

## 5. Stale branches — safe to delete

None represents live unmerged work; each maps to a closed or merged PR.

| Branch | PR | Why stale |
|--------|-----|-----------|
| `claude/m9-apply-order` | #123 **CLOSED** | Superseded — topological apply order shipped instead via #124 (`ReleaseStore::items()`); `ApplyOrder.php` never landed in `master`. |
| `claude/vigorous-franklin-21f980` | #160 **CLOSED** | Content-migration env-gate fix, superseded; branch is a stale master merge. |
| `codex/continue-entity-editor-implementation` | #4 **MERGED** (Feb 2026) | Left-over branch after merge. |
| `codex/visual-editor-r6-site-manager-workspace` | #9 **CLOSED** (Sep 2026) | R6 VE workspace; R6 work proceeds in the main checkout. Only agent-docs snapshot noise remains. |

**Recommendation:** `git push origin --delete` the four once confirmed. (Visual Editor R6 itself remains an active program per `docs/ROADMAP.md`, but it runs in the main checkout, not these branches.)

---

## 6. Path to a clean slate

1. ~~**Land the 3 open PRs** in order #191 → #190 → #189.~~ **Done 2026-10-23** — all merged, `master` at `f68ef900`.
2. **Triage the 4 known-failing tests** — decide fix vs. quarantine; they're the only red in the suite baseline.
3. **Content Migration V3 is where the remaining product work is.** The near-term unblock is **S1d** (P1, contract change — do before S3) and the **unit-economics baseline** (P1, an afternoon). Everything after (S3 → S6a → S8/S5/S6) is sequenced behind those two.
4. **Delete the 4 stale branches.**
5. **Doc hygiene:** correct the V3 README header ("Proposed / no code written" is false), and the S6/F-6 cleanup (flag collapse, behaviour-named tests, capped working-state doc) remains outstanding.
6. **Connected Environments** needs nothing to be clean; only optional A2-3 and the deferred larger-cohort verify remain, both gated on scale/fleet size.

---

## 7. Repo Development Overview — outstanding phases / slices by project

Built 2026-10-23 by sweeping the recently-touched implementation guides, planning docs, and `docs/ROADMAP.md`. Each row is a distinct project with its own status vocabulary; "outstanding" = anything not closed/shipped (open, WIP, in-progress, planned, pending sign-off, paused, deferred). Sources are the canonical guide per project.

### 7.1 Active — work in flight

| Project | Overall | Outstanding phases / slices | Source |
|---------|---------|------------------------------|--------|
| **Content Migration V3** | Pipeline wired end-to-end; S0–S4c + S7 delivered | **S1d** (one URL → many entities, D-19 — *next*, P1), **unit-economics baseline** (P1), **S3** (corpus slots 2/3/5 + recalibrate G-4), **S6a** (flip V1→V2 default, D-17), **S8** (migration-planning artifacts), **S5** (scale/budget), **S6** (cleanup). Open defects: **D-3, D-9, D-11, D-16, D-23**. | `docs/implementation/proposed/content-collection-v3/README.md` |
| **Visual Editor add-on** | R0–R5.7, R5.later-*, VE-prefs COMPLETE | **R6** maintainer sign-off pending (3 checklist rows); **R6.1** maintainer sign-off pending (rows 21–26); **R5.later-perf** → perf-c-2 / perf-d next + maintainer enable/measure; **R5.later-cache** PLANNED (not started); **R2 residual** = Media Index Phase 2 (cron reconciliation, unscheduled) + **D-049** real-browser/AT Media Manager QA on an authenticated runtime; mobile/responsive tabled program-wide (**D-036/D-058**). P0–P5 existing-path backlog remains as cross-cutting regression gates. | `addons/visual-editor/docs/enhancements/DBVC_VISUAL_EDITOR_PHASES.md` |
| **Admin app refactor** | Active — multi-epic modularization of the monolithic admin bundle | 11 epics with many open `[ ]` refactor + QA tasks; no end-state declared. Ongoing, incremental. | `docs/implementation/active/admin-app-refactor.md` |
| **Core Proposal Diff Resolution sprint** | Phases 0–7.1 + CPD-011/012 Done | **Phase 7.2 In progress** — large-proposal performance (a 755-entity proposal = 1.5 MB / 15.75 s), UI pagination controls, server-side entity pagination/windowing, and the narrow 390 px drawer table (716 px table in a 312 px panel). **CPD-013 In progress** (P3). *Related:* `ProposalDiffContractTest` is one of the 4 red baseline tests. | `docs/core-proposal-diff-resolution-sprint.md` + progress log |
| **Configuration portability tool** | Active — P5–P7 | **P5, P6, P7 all WIP** (apply/backup/rollback/runtime refresh; granular environment replacement; PHPUnit + runtime smoke). **P8 + CP-F1…CP-F8 deferred-planning**. | `docs/implementation/active/configuration-portability-tool-guide.md` |
| **Bricks add-on settings portability** | Active — Phases 20–22 done | **Phase 23 in progress** (query post/term include/exclude remapping, built-in link remapping, dynamic login/logout token remapping). **Phases 24–26 remaining** (domain/session rollups, richer path/action detail, idempotency + mixed-rollback hardening, live drill evidence). *(The Connected-Environments Bricks slices 6a–6e are separately complete under M9.)* | `addons/bricks/docs/BRICKS_ADDON_IMPLEMENTATION_CHECKLIST.md` |
| **WS Form reference portability** | Active — S1–S4 + S7 merged + live-verified | **S4b** Phase 2 (cross-site reference rewriting) **deferred**; **S5** (reference coverage beyond post meta — term meta, options, builder trees) **deferred**. WS Form Entity Editor provider mode = *initial-implemented*. | `docs/implementation/active/wsform-reference-portability.md` |
| **AI package workflow & import authoring reference** | Active | **P10** compact-context hardening; **P11** Agent Authoring Context Catalog + Connector refresh pipeline. | `docs/reference/import-authoring/README.md` |

### 7.2 Awaiting review / sign-off (code done)

| Project | Status | What's blocking closure |
|---------|--------|--------------------------|
| **Term entity polish** | needs-review | Term snapshots ship in manifests; a QA checklist (term diff drawers for tags/categories/custom taxonomies; `--recapture-snapshots` reopen/hydrate flows) awaits review sign-off. |
| **Visual Editor R6 / R6.1** | code complete + live-QA'd | Maintainer checklist sign-off only (see 7.1). |

### 7.3 Complete / shipped (no outstanding work)

- **Connected Environments & Agency Control** — M0–M9 complete + live-verified (2026-10-06); post-M9 **Phases A, B** complete; **Phase C** document-half shipped (#182). *Deferred-by-constraint:* larger-cohort live-verify (fleet is 2 connectors) and optional **A2-3** media fs/object-store driver + chunked upload (only at scale).
- **Connected Environments admin page** — shipped (A1–A7, 2026-09-21).
- **Bricks add-on language refresh** — completed.

### 7.4 Paused / superseded

- **Content Migration V2** — **paused**, superseded as a plan by V3 (2026-10-10). Code remains on `master` and is the default runtime until V3 **S6a** flips it (`OPTION_RUNTIME_VERSION` still defaults to V1). Two runtimes maintained until then.

### 7.5 Proposed / backlog (not started)

From `docs/ROADMAP.md`, all `proposed`: **Cross-site entity packets**, **Bricks portability drift manager**, **Bricks reference mapping**, **Media sync hydration**, **User documentation library**, **Post-field masking expansion**, **Third-party portability**. (The "Agency Control connected environments" proposed doc is now historical — M9 superseded it.)

### 7.6 Cross-cutting tech debt

- **4 red baseline tests**: `BricksAddonPhase7Test`, `BricksAddonPhase11Test`, `CapabilityLandscapeTest`, `ProposalDiffContractTest` (untriaged; `ProposalDiffContractTest` ties to the Proposal Diff sprint's Phase 7.2).
- **Doc hygiene**: V3 README header still says "Proposed / no code written" (false); S6/F-6 cleanup (flag collapse, behaviour-named tests, capped working-state doc) outstanding.
- **4 stale branches** left in place by choice (§5).
