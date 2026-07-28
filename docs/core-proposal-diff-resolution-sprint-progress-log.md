# Core Proposal Diff Resolution Sprint Progress Log

This log is for short implementation updates during the Core Proposal Diff Resolution sprint.

Related sprint guide: `docs/core-proposal-diff-resolution-sprint.md`

## How To Use This Log

- Add one entry after each implemented phase, sub-phase, or meaningful work slice.
- Add newest entries at the top of `Progress Entries`.
- Keep the language short and plain enough to scan quickly.
- Do not mark planned work as done. Only record what was actually changed, verified, or left for follow-up.
- Update the related phase/sub-phase `Status` column in `docs/core-proposal-diff-resolution-sprint.md` at the same time.
- In the final response for each implementation round, mention the newest log entry and the next steps recorded here.

Each entry must include:
- Phase name/number.
- A 3 sentence summary of the applied solution.
- Dependents that were affected or updated.
- Dependents that need more automated QA or simple manual QA.
- A quick DBVC feature/page/area reference for manual checking.
- Next steps after that implementation round.

## Status Values

- `Not started`
- `In progress`
- `Blocked`
- `In review`
- `Done`

Use the same status wording in this file and in the main sprint guide.

## DBVC Manual QA References

Use these quick references in progress entries when they apply.

| Area | Manual QA reference |
| --- | --- |
| Main Proposal Review | WP Admin -> DBVC -> Export, `/wp-admin/admin.php?page=dbvc-export` |
| Snapshots & Diff | WP Admin -> DBVC -> Export -> Snapshots & Diff, `/wp-admin/admin.php?page=dbvc-export#dbvc-export-snapshots` |
| Configure / Import Defaults | WP Admin -> DBVC -> Export -> Configure, `/wp-admin/admin.php?page=dbvc-export` |
| Media Resolver | WP Admin -> DBVC -> Export -> Proposal Review media/resolver tools |
| Entity Editor | WP Admin -> DBVC -> Entity Editor, `/wp-admin/admin.php?page=dbvc-entity-editor` |
| Bricks Add-on | WP Admin -> DBVC -> Bricks, `/wp-admin/admin.php?page=addon-dbvc-bricks-addon` |
| Bricks Configure | WP Admin -> DBVC -> Bricks -> Configure, `/wp-admin/admin.php?page=addon-dbvc-bricks-addon&tab=configure` |
| WP-CLI proposal tools | `wp dbvc proposals list`, `wp dbvc proposals upload`, `wp dbvc proposals apply` |
| Logs / diagnostics | DBVC logging settings, activity log, Bricks diagnostics, and PHP/WP logs |

## Entry Template

Copy this template for each completed phase, sub-phase, or implementation slice.

```md
## YYYY-MM-DD - Phase X.Y - Short Title

- Status: Done | In progress | Blocked | In review
- Phase / sub-phase: Phase X - Name; X.Y - Name
- CPD items: CPD-###
- Conflict flags observed: CDF-###

Summary:
1. Sentence one: what changed.
2. Sentence two: how it behaves now.
3. Sentence three: what is still limited or what was confirmed.

Dependents affected or updated:
- Short list, or `None found`.

Dependents needing more QA:
- Automated: short list, or `None`.
- Manual: short list, or `None`.

Manual QA reference:
- DBVC area/page: short page name and admin path.

Verification:
- Automated checks run: command/result, or `Not run - reason`.
- Manual checks run: short result, or `Not run - reason`.

Next steps:
- Short list of the next implementation or QA actions.
```

## Progress Entries

## 2026-07-22 - Phase 5.1 - Canonical Apply Units and Safe Nested Merge

- Status: Done
- Phase / sub-phase: Phase 5 - Apply Semantics; 5.1 - align review decision paths with importer apply units
- CPD items: CPD-006
- Conflict flags observed: CDF-001, CDF-002, CDF-010, CDF-011, CDF-012, CDF-013, CDF-014, CDF-018, CDF-020, CDF-021, CDF-026

Summary:
1. Proposal Diff now shows the real apply unit for each changed field and keeps identity or unsupported rows read-only.
2. Mixed Accept and Keep choices inside nested post meta now merge safely, while complete-key removals share one clear choice.
3. A live refresh issue that erased masking reviews was found and fixed, leaving only term masking and declined-new summaries in the pending lane.

Dependents affected or updated:
- Core Proposal REST data, apply readiness, decision storage, the post importer, Proposal Review UI, and the generated admin build now use the same canonical paths.
- Live masking review decisions now survive entity-detail refresh and snapshot-driven cleanup even when a configured masked field is not a normal diff row.
- Post meta merge/removal, post-field masking, complete taxonomy assignment, timestamps, repeated meta rows, and local UID/ID preservation received focused coverage.
- The active LocalWP merge kept its separate Entity Editor, Bricks, Visual Editor, UID-fallback, and add-on changes intact; no manifest schema or public hook signature changed.

Dependents needing more QA:
- Automated: Phase 5.2 must wire term masking overrides into term import; Official Collections still has no direct promotion test in this checkout.
- Automated conflict watch: the active full suite still has five separate failures in Bricks language/disabled mode and Content Collector/settings tests. The 173-test shared-dependency matrix is green, so none is currently tied to Phase 5.1.
- Manual: verify one Official Collections promotion after a mixed nested-meta apply, and recheck Entity Editor deletion of a theme-backed raw-intake sync file.
- Manual environment conflict: Bricks Advanced Themer's bundled ACF formatter currently makes some `/wp/v2/pages` read/delete responses fail after the database action. Use Proposal Review snapshot/detail data for DBVC QA until that external LocalWP conflict is fixed.

Manual QA reference:
- DBVC area/page: [Main Proposal Review](https://dbvc-codexchanges.local/wp-admin/admin.php?page=dbvc-export), then open a proposal entity and compare Full Overview with the Meta section.

Verification:
- Source and LocalWP `composer test:proposal-diff-resolved`: 87 tests and 548 assertions passed; LocalWP randomized seed `20260722` passed with the same totals.
- Source full suite excluding pending contracts: 222 tests and 1,313 assertions passed.
- Active shared-dependency matrix: 173 tests and 2,301 assertions passed across Entity Editor, Bricks Portability/reference mapping, Configuration Portability, third-party portability, UID preservation, and Content Migration Phase 4.
- Pending lane: exactly 2 expected failures and 5 assertions remain for term masking and declined-new summary state.
- Active full diagnostic: 602 tests and 5,855 assertions with the same five unrelated failures listed above.
- Authenticated LocalWP QA showed 19 visible rows and 17 apply units, a read-only incoming ID, two removed children sharing one parent meta decision, 11 masking reviews surviving detail refresh, and no browser console errors.
- The disposable apply closed successfully, applied the accepted title and nested color, preserved the kept spacing and local ID, removed the accepted complete meta key, and left no test proposal, post, sync JSON, or ZIP behind.

Next steps:
- Implement Phase 5.2 / CPD-007 so term fields and term meta consume the same masking suppressions and overrides as post import.
- Keep Phase 4 / CPD-002 in review until its resolver-specific connected browser and CLI checks are closed.

## 2026-07-21 - Phase 4A.5 - Green Resolved Test Lane

- Status: Done
- Phase / sub-phase: Phase 4A - Cumulative Audit Remediation; 4A.5 - add a deterministic green regression lane
- CPD items: CPD-020; CPD-005 closeout
- Conflict flags observed: CDF-001, CDF-003, CDF-004, CDF-005, CDF-008, CDF-009, CDF-010, CDF-017, CDF-018, CDF-020, CDF-022, CDF-023

Summary:
1. Proposal Diff now has one green command for completed work and a separate command for the four unfinished Phase 5 checks.
2. The unfinished checks still run and fail visibly, so no known gap was hidden or removed to make the completed lane green.
3. Normal and shuffled test orders passed in both checkouts, including the previously flagged Content Migration boundary.

Dependents affected or updated:
- Composer now provides `test:proposal-diff-resolved` and `test:proposal-diff-pending`; the original broad Proposal Diff command remains available.
- Four existing test methods were labeled pending: post-field masking, nested meta apply behavior, term masking, and declined-new summary state.
- No production PHP, REST endpoint, importer, admin UI, WP-CLI command, build asset, Entity Editor, Configuration Portability, Media Hydration, Content Migration, transfer, or Bricks behavior changed.

Dependents needing more QA:
- Automated: when a Phase 5 contract is fixed, remove its pending label and confirm it joins the green resolved command.
- Automated/CI: this checkout has no CI workflow; use the resolved Composer command as the required job when CI is added.
- Manual: no new Proposal Review check is required for this test-only slice. Resolver-specific `reuse`, `map`, `download`, and `skip` browser QA remains tracked under CPD-002.

Manual QA reference:
- Commands: `composer test:proposal-diff-resolved`, `composer test:proposal-diff-pending`, and the broad `composer test:proposal-diff`.
- DBVC area/page for later behavior rounds: Main Proposal Review, `https://dbvc-codexchanges.local/wp-admin/admin.php?page=dbvc-export`.

Verification:
- Before labeling, the would-be resolved command ran all 77 tests and reproduced the four planned failures.
- Source and LocalWP resolved commands passed 73 tests and 481 assertions in both normal and randomized order using seed `20260721`.
- The pending command ran exactly four tests and returned exactly the same four failures, with 8 source assertions and 7 LocalWP assertions.
- The source full suite excluding only the four pending methods passed 208 tests and 1,246 assertions.
- Active LocalWP resolved Proposal Diff plus Content Migration passed 105 tests and 1,049 assertions in normal and randomized order; the old media rollback ordering failure did not reproduce.
- The connected Configuration Portability, UID, Entity Editor, third-party portability, Bricks Portability, and Bricks reference set passed 99 tests and 1,350 assertions.

Next steps:
- Begin Phase 5.1 / CPD-006 by aligning displayed review decisions with importer apply units for post fields and nested meta.
- Keep CPD-002 in review until the resolver-specific four-action browser and direct CLI checks are complete.

## 2026-07-20 - Phase 4A.4 - Non-Post Apply Gate

- Status: Done
- Phase / sub-phase: Phase 4A - Cumulative Audit Remediation; 4A.4 - add or enforce review gates for non-post domains
- CPD items: CPD-018
- Conflict flags observed: CDF-006, CDF-007, CDF-012, CDF-013, CDF-016, CDF-018, CDF-020, CDF-021

Summary:
1. Proposal Review now blocks options, option groups, and menus because it cannot yet show a trusted current-site baseline or safe field choices for them.
2. The same non-removable blocker and per-type counts appear in REST, Proposal Review, WP-CLI, and blocked-apply logs before any import starts.
3. Dedicated restore, menu, option-group, Configuration Portability, Entity Editor, and Bricks tools remain available outside Proposal Review.

Dependents affected or updated:
- Proposal list/readiness/entity responses now include `counts.unsupported_domains` with separate `options`, `options_group`, and `menus` totals.
- Proposal Review reuses its existing blocker badge, notice, Apply-button title, and apply modal, so no frontend build or CSS change was needed.
- REST apply returns HTTP 409, WP-CLI apply exits non-zero, and activity logs record `unsupported_domains` with the same counts.
- The proposal-only gate does not change the shared importer, manifest schema, options-group selection rules, menu importer, flat JSON router, Configuration Portability providers, auto-export hooks, Official Collections code, Entity Editor, or Bricks option artifacts.

Dependents needing more QA:
- Automated: no failing connected checks; add domain-specific tests only when option/menu review, snapshot, or rollback support is designed.
- Manual: use Configuration Portability, a selected ACF options-group import, and a normal menu import once with real site data; confirm their dedicated workflows still behave normally.
- Manual observation: keep Official Collections promotion flagged because this checkout has no direct automated promotion case for non-post proposal items.

Manual QA reference:
- DBVC area/page: Main Proposal Review, `https://dbvc-codexchanges.local/wp-admin/admin.php?page=dbvc-export`; Configuration Portability, `https://dbvc-codexchanges.local/wp-admin/admin.php?page=dbvc-configuration-portability`; CLI equivalent, `wp dbvc proposals apply <proposal-id>`.

Verification:
- Test-first coverage reproduced the bypass, then focused source and LocalWP contracts passed 3 tests and 21 assertions each, including all three domains, hash-override isolation, REST refusal, and post-backed FSE exclusion.
- The source Proposal Diff group ran 77 tests and 489 assertions and the LocalWP group ran 77 tests and 488 assertions, both with only the same four planned Phase 5 failures.
- LocalWP import-router coverage passed 2 tests and 29 assertions. The combined Configuration Portability, UID, Entity Editor, third-party portability, Bricks Portability, and Bricks reference set passed 99 tests and 1,350 assertions.
- Authenticated LocalWP REST QA reported one blocker with exact `1/1/1` domain counts and rejected apply with HTTP 409 without writing the sentinel option. WP-CLI apply returned the same category with exit code 1.
- Browser QA showed `Blocked (1)`, the full blocker notice, and a disabled Apply button with the matching reason; no console errors were recorded.
- A controlled administrator classic import processed two dedicated options/menu units with no errors, after which the sentinel, proposal, decisions, snapshots, ZIP, and proposal directory were removed.

Next steps:
- Implement Phase 4A.5 / CPD-020 as a deterministic green regression command for resolved Phase 0-4A contracts.
- Keep the four planned Phase 5 failures in a separate visible lane, then proceed to Phase 5.1.

## 2026-07-20 - Phase 4A.3 - ZIP Extraction Limits

- Status: Done
- Phase / sub-phase: Phase 4A - Cumulative Audit Remediation; 4A.3 - enforce ZIP extraction resource ceilings
- CPD items: CPD-019
- Conflict flags observed: CDF-004, CDF-006, CDF-007, CDF-016, CDF-017, CDF-018, CDF-019, CDF-023, CDF-024

Summary:
1. Proposal ZIP uploads now check entry count, each file's expanded size, total expanded size, and compression ratio before writing files.
2. Missing or inconsistent size details and budget violations return a clear upload error with safe limit details, while invalid filter values fall back to mandatory defaults.
3. Source, LocalWP, REST, WP-CLI, and connected package tests passed without changing the separate AI, classic import, Configuration Portability, transfer, or Bricks extractors.

Dependents affected or updated:
- Proposal Review REST upload and `wp dbvc proposals upload` share the updated importer and return matching rejection behavior.
- Upload and activity logging now receive the safe reason and numeric budget details, and site code can adjust positive ceilings through `dbvc_proposal_zip_resource_limits`.
- Proposal and transfer manifests keep their current schema; no package producer or consumer needed a format change.
- AI intake, classic ZIP import, Configuration Portability, Bricks Portability, and Bricks connected-site transport use separate extraction code. They were regression-tested but did not inherit this Proposal Diff filter.

Dependents needing more QA:
- Automated: none failing for the core proposal boundary; keep the separate AI, classic, Configuration Portability, and Bricks extractors flagged for their own resource-limit security review.
- Manual: upload the largest normal media-bundled proposal expected in production to confirm the defaults fit the intended operating range, then try a deliberately over-limit ZIP and confirm Proposal Review shows the rejection.

Manual QA reference:
- DBVC area/page: Main Proposal Review upload, `https://dbvc-codexchanges.local/wp-admin/admin.php?page=dbvc-export`; CLI equivalent: `wp dbvc proposals upload <proposal.zip>`.

Verification:
- Test-first coverage reproduced four missing budget checks and six missing invalid-stat checks; focused source and LocalWP intake suites then passed 32 tests and 206 assertions each, including invalid-filter fallback coverage.
- The source Proposal Diff group ran 75 tests and 472 assertions and the LocalWP group ran 75 tests and 471 assertions, both with only the same four planned Phase 5 failures.
- The Proposal/AI/import/transfer matrix passed 60 tests and 496 assertions; separate core, Configuration Portability, and Bricks package/extraction consumers passed 72 tests and 1,162 assertions.
- Bricks connected-site transport passed 40 tests and 231 assertions, and Content Migration passed 32 tests and 568 assertions.
- Authenticated REST QA rejected a 1,014:1 archive with HTTP 400 before registration, accepted a normal proposal with HTTP 200, and deleted it with HTTP 200. Live WP-CLI rejected the unsafe archive with exit code 1.

Next steps:
- Begin Phase 4A.4 / CPD-018 by inventorying and enforcing review and trusted-baseline gates for options, option groups, and menus.
- Follow with the resolved-phase green regression lane in Phase 4A.5 before Phase 5.1.

## 2026-07-20 - Phase 4A.2a - Proposal Media Bundle Cleanup

- Status: Done
- Phase / sub-phase: Phase 4A - Cumulative Audit Remediation; 4A.2a - remove proposal-owned media bundles during proposal deletion
- CPD items: CPD-022
- Conflict flags observed: CDF-003, CDF-004, CDF-005, CDF-006, CDF-016, CDF-023, CDF-025

Summary:
1. Live QA showed that deleting a proposal left its copied media bundle in sync storage.
2. Proposal deletion now removes only that proposal's bundle and reports or logs the cleanup result without touching neighboring bundles.
3. Source and LocalWP tests passed, and all five Codex-created leftover directories were removed.

Dependents affected or updated:
- Proposal Review REST deletion, its admin UI caller, and any WP-CLI caller now receive the additive `media_bundle_deleted` result.
- `BundleManager`, the backup manager boundary, decision/snapshot/mask cleanup, Media Resolver, transfer/Bricks media packages, and logging were checked as connected lifecycle surfaces.

Dependents needing more QA:
- Automated: none outstanding for this slice; the exact-target and neighboring-bundle regression is green in source and LocalWP.
- Manual: delete a disposable proposal that contains bundled media from Proposal Review and confirm its bundle directory disappears while another proposal remains available.

Manual QA reference:
- DBVC area/page: Main Proposal Review, `https://dbvc-codexchanges.local/wp-admin/admin.php?page=dbvc-export`; delete the disposable proposal from its proposal row or detail controls.

Verification:
- The new test failed before the implementation, then passed with 1 test and 8 assertions in both source and active LocalWP checkouts.
- The cumulative Proposal Diff group ran 63 tests and 411 assertions in source and 63 tests and 410 assertions in LocalWP, with only the same four planned Phase 5 failures.
- The connected package/import matrix passed 48 tests and 435 assertions; Content Migration remained green at 32 tests and 568 assertions.
- A live cleanup probe removed each of the five Codex-created Phase 4A bundle directories and confirmed none remained.

Next steps:
- Begin Phase 4A.3 / CPD-019 by enforcing ZIP entry-count, expanded-size, and compression-ratio limits before extraction.
- Continue with Phase 4A.4 and Phase 4A.5 before starting Phase 5.1.

## 2026-07-20 - Phase 4A.2 - Truthful Apply Outcomes

- Status: Done
- Phase / sub-phase: Phase 4A - Cumulative Audit Remediation; 4A.2 - make proposal closure and success depend on entity and media outcomes
- CPD items: CPD-017
- Conflict flags observed: CDF-003, CDF-004, CDF-005, CDF-012, CDF-015, CDF-016, CDF-018, CDF-022, CDF-023

Summary:
1. Proposal apply now waits for entity and required media results before it clears reviewer choices or closes the proposal.
2. A failed required media action keeps the proposal in draft, preserves its choices, records the failure, and returns an error instead of a success response.
3. Bundled downloads now reject mismatched file hashes before creating an attachment, and source, LocalWP, dependent, and authenticated checks passed.

Dependents affected or updated:
- The shared importer now returns one outcome for Proposal Review and classic restore, while only Proposal Review turns a failed outcome into the REST failure and draft-state contract.
- Media Reconciliation and Media Sync now contribute required-action failures to that outcome; bundled attachment registration validates its declared SHA-256 hash first.
- The admin Apply flow already displays rejected REST requests as Apply failed and keeps local choices; WP-CLI already sends the same `WP_Error` to `WP_CLI::error` for a non-zero exit.
- AI intake, normal Proposal ZIP intake, transfer packets, Configuration Portability, Bricks media packages, Content Migration, logging, and Official Collections were flagged because they share package, importer, media, or promotion boundaries. No dependent code change was required in this slice.

Dependents needing more QA:
- Automated: Official Collections promotion still has no direct caller/test in this checkout; keep it flagged when that workflow becomes active.
- Manual: optionally confirm the Apply failed notice with a disposable unavailable-download proposal and run the same proposal once through `wp dbvc proposals apply <proposal-id>` to observe the non-zero CLI result.

Manual QA reference:
- DBVC area/page: Main Proposal Review and its media resolver tools, `https://dbvc-codexchanges.local/wp-admin/admin.php?page=dbvc-export`; CLI equivalent: `wp dbvc proposals apply <proposal-id> --mode=partial`.

Verification:
- Focused source and LocalWP contracts passed the mixed entity-success/media-failure, reconciliation-exception restoration, and bundled hash-mismatch cases.
- After the cleanup regression was added, the source Proposal Diff group ran 63 tests and 411 assertions and the active group ran 63 tests and 410 assertions, both with only the same four planned Phase 5 failures.
- The Proposal ZIP, Media Resolver, AI Package, import router, and transfer packet matrix passed 48 tests and 435 assertions; Content Migration passed 32 tests and 568 assertions.
- Authenticated LocalWP REST QA observed `ready=true`, HTTP 409 `dbvc_proposal_apply_failed`, one media failure, preserved resolver choice, stored `draft` status, and proposal-record cleanup. The follow-up filesystem audit exposed the bundle cache addressed in Phase 4A.2a.
- PHP syntax and `git diff --check` passed in both source and active LocalWP copies. The proposal-list status read reproduced the existing CPD-013 latency but revealed no new error.

Next steps:
- Begin Phase 4A.3 / CPD-019 by adding ZIP entry-count, expanded-size, and compression-ratio limits before extraction.
- After Phase 4A.3, continue with non-post-domain gates in Phase 4A.4 and the resolved-phase green test lane in Phase 4A.5 before Phase 5.1.

## 2026-07-20 - Phase 4A.1a - Proposal and AI Upload Routing

- Status: Done
- Phase / sub-phase: Phase 4A - Cumulative Audit Remediation; 4A.1a - preserve Proposal Diff routing at the shared AI upload boundary
- CPD items: CPD-021
- Conflict flags observed: CDF-004, CDF-006, CDF-007, CDF-016, CDF-019, CDF-024

Summary:
1. The LocalWP AI upload check no longer treats every ZIP containing `manifest.json` as an AI package.
2. Legacy AI packages still work when their manifest carries AI-specific markers, while ordinary Proposal Diff ZIPs continue into Proposal Review.
3. Authenticated upload and browser checks passed, and the disposable AI report and workspace created while finding the conflict were removed.

Dependents affected or updated:
- The active LocalWP Proposal Review REST uploader and classic single-ZIP upload router share the corrected detector.
- AI intake validation keeps canonical `dbvc-ai-manifest.json` support and legacy AI package compatibility.
- WP-CLI proposal upload, transfer packet intake, upload logging, and retained AI reports/workspaces are flagged because they meet the same package-routing boundary.
- The source worktree has no AI Package subsystem, so this dependent code fix exists only in the active LocalWP checkout; the sprint docs track the boundary in both copies.

Dependents needing more QA:
- Automated: none currently failing for this slice; the combined package-routing and Content Migration regressions are green.
- Manual: use Upload to Sync Folder with one disposable core proposal ZIP and one canonical AI package to confirm each opens its intended review area.

Manual QA reference:
- DBVC area/page: Main Proposal Review and Import/Upload -> Upload, `https://dbvc-codexchanges.local/wp-admin/admin.php?page=dbvc-export`; AI package controls are under Configure -> AI + Integrations.

Verification:
- Detector test-first check reproduced the conflict before the fix, then the focused boundary coverage passed 2 tests and 10 assertions.
- Full AI Package workflow coverage passed 15 tests and 177 assertions.
- Combined Proposal ZIP, resolver, AI Package, classic router, and transfer coverage passed 47 tests and 429 assertions.
- Content Migration importer coverage passed 32 tests and 568 assertions after the routing fix.
- Authenticated REST upload registered the disposable core proposal normally instead of returning an AI intake state.
- Authenticated browser QA loaded 11 Proposal Review rows with no console errors, showed the normal ZIP intake, and kept the AI workbench hidden after cleanup.

Next steps:
- Begin Phase 4A.2 / CPD-017 so proposal closure and success reflect failed required media actions.

## 2026-07-20 - Phase 4A.1 - Resolver Decision Authority

- Status: Done
- Phase / sub-phase: Phase 4A - Cumulative Audit Remediation; 4A.1 - preserve live resolver authority
- CPD items: CPD-016
- Conflict flags observed: CDF-003, CDF-004, CDF-005, CDF-006, CDF-016, CDF-018, CDF-022, CDF-023

Summary:
1. Resolver choices carried inside an uploaded proposal now fill only attachments that do not already have a local proposal choice or site-wide rule.
2. Applying a proposal now uses the choices currently shown on this site instead of loading the older choices from the proposal file again.
3. Source, LocalWP, dependent, authenticated REST, and browser checks passed, including cleanup of the disposable proposal folder and temporary global rule; its separate media-bundle cache was later fixed in Phase 4A.2a.

Dependents affected or updated:
- Core proposal ZIP intake and the shared proposal/classic restore importer.
- Legacy manual and WP-CLI media imports keep their existing seed call, but it is now non-destructive and cannot import archived global rules.
- Media Reconciliation and Media Sync still receive the same normalized decision shape and proposal-over-global priority after the live choice snapshot is taken.
- Resolver CSV/settings, Media Hydration, Configuration Portability, transfer packets, Bricks Portability, Entity Editor, Content Migration, and logging were observed for conflicts; none required code changes in this slice.

Dependents needing more QA:
- Automated: none currently failing for this slice; keep the four planned Phase 5 Proposal Diff failures separate.
- Manual: resolver-rule CSV import/export can receive a simple follow-up spot check, but the explicit global-rule API import/delete round trip already passed.

Manual QA reference:
- DBVC area/page: Media Resolver in Main Proposal Review, `https://dbvc-codexchanges.local/wp-admin/admin.php?page=dbvc-export`; WP-CLI fallback, `wp dbvc proposals apply <disposable-id>`.

Verification:
- Source focused authority/media checks: 27 tests and 200 assertions passed; source non-Proposal coverage passed 135 tests and 765 assertions.
- Source Proposal Diff group: 59 tests and 379 assertions ran with only the same four planned Phase 5 failures.
- LocalWP focused authority/media checks: 27 tests and 200 assertions passed.
- LocalWP dependent matrix: Entity Editor, Media Hydration, Configuration Portability, transfer packets, Bricks Portability, and Bricks reference mapping passed 163 tests and 1,892 assertions.
- LocalWP Content Migration importer: 32 tests and 568 assertions passed.
- LocalWP Proposal Diff group: 59 tests and 378 assertions ran with only the same four planned Phase 5 failures.
- Live LocalWP smoke: the site returned HTTP 200 and the unauthenticated Proposal REST inventory remained protected with HTTP 401.
- Authenticated LocalWP workflow: an archived `download` seed loaded, a newer local `skip` remained selected before and after partial apply, archived global data stayed absent, an explicit global-rule import/delete round trip passed, the proposal closed, and its proposal folder was removed. The later Phase 4A.2 audit found and Phase 4A.2a removed the separate media-bundle cache.
- Authenticated browser QA: normal Proposal Review loaded 11 rows without console errors and no disposable AI report remained visible.

Next steps:
- Begin Phase 4A.2 / CPD-017 so failed required media actions cannot close a proposal or return a success result.
- Keep resolver CSV and direct WP-CLI apply as connected-site spot checks during later regression rounds.

## 2026-07-20 - Phase 4.1-4.2 - Media Resolver Decisions

- Status: In review
- Phase / sub-phase: Phase 4 - Media Resolution; 4.1 - authoritative decision bridge; 4.2 - action and outcome verification
- CPD items: CPD-002, with counter/reporting observation for CPD-012
- Conflict flags observed: CDF-003, CDF-004, CDF-005, CDF-006, CDF-014, CDF-015, CDF-016, CDF-018, CDF-022, CDF-023

Summary:
1. Proposal and global media choices now use one shared priority order before any attachment can be reused, mapped, downloaded, or skipped.
2. Each choice now changes the real apply result once, clears stale mappings where needed, validates selected attachments, and reports whether it worked and where the choice came from.
3. The update is merged into LocalWP and automated checks are green across connected media tools, while the signed-in visual apply check is still pending.

Dependents affected or updated:
- Core importer handoff between Media Reconciliation and Media Sync, including classic restore and direct legacy/WP-CLI media sync.
- Proposal Review REST response, apply notice/history, production admin bundle, WP-CLI summaries, resolver metrics, job results, and media activity logs.
- Existing response keys and the `dbvc_media_use_legacy_sync` filter were preserved; new actual-outcome fields are additive.
- Media Hydration was flagged because it shares `_wp_attached_file`, `vf_asset_uid`, `vf_file_hash`, and `_dbvc_original_attachment_id` with downloaded/reused attachments.
- Configuration Portability, transfer packets, and Bricks Portability were flagged because they share media options and bundle metadata.
- Entity Editor shares importer helpers but does not call reconciliation or consume resolver choices directly.

Dependents needing more QA:
- Automated conflict watch: the full LocalWP run added one order-dependent Content Migration media rollback failure to the established nine failures. It passes alone and directly after the Phase 4 tests, so keep tracking test-state cleanup outside the resolver implementation.
- Automated: add direct CLI output capture for `wp dbvc proposals apply` and legacy media import against a disposable proposal when a command fixture is available.
- Manual: while signed in, apply disposable proposals for proposal/global `reuse`, `map`, `download`, and `skip`; verify exactly one attachment is created only for download and the result line shows applied/failed counts.
- Manual: smoke resolver-rule CSV import/export and media settings for `auto`, `bundled`, and `remote`, with external downloads both allowed and blocked.

Manual QA reference:
- DBVC area/page: Media Resolver in Main Proposal Review, `https://dbvc-codexchanges.local/wp-admin/admin.php?page=dbvc-export`; Configure media settings on the same page; WP-CLI, `wp dbvc proposals apply <disposable-id>`.

Verification:
- Automated checks run: Phase 4 focused coverage passed 7 tests and 55 assertions in both the source worktree and LocalWP; source non-Proposal coverage passed 135 tests and 765 assertions.
- LocalWP dependent checks: Media Hydration, Configuration Portability, transfer packets, Entity Editor, Bricks Portability, and Bricks reference mapping passed 163 tests and 1,892 assertions.
- LocalWP Proposal Diff checks: 57 tests and 359 assertions ran with only the same four planned Phase 5 failures; all Phase 4 tests passed.
- Full LocalWP suite: 570 tests and 5,643 assertions ran with the established nine failures plus one order-dependent Content Migration media rollback failure. That rollback test passed alone with 21 assertions and passed after the resolver tests in an 8-test, 76-assertion run.
- Build/static checks: source JavaScript lint/build, LocalWP Proposal Review-only build, PHP/JavaScript syntax, and scoped `git diff --check` passed.
- Manual checks run: Proposal Review reached the LocalWP login page, but the in-app browser is not signed in; no real proposal was applied during this round.

Next steps:
- Complete the signed-in four-action Proposal Review check using disposable media/proposals, then move Phase 4.1-4.2 and CPD-002 from `In review` to `Done`.
- Begin Phase 5.1 nested apply semantics and Phase 5.2 term masking, which account for three of the four remaining core red contracts.
- Keep Phase 5.4 declined-new summary state as the fourth remaining core contract; continue to defer unrelated Bricks and Content Collector/settings failures to their own workstreams.

## 2026-07-19 - Phase 3.3 - LocalWP Integration QA

- Status: In review
- Phase / sub-phase: Phase 3 - Snapshot Truth; 3.3 - connected LocalWP integration and dependent QA
- CPD items: CPD-001, CPD-003, CPD-004, CPD-005, CPD-013
- Conflict flags observed: CDF-001, CDF-004, CDF-005, CDF-006, CDF-008, CDF-010, CDF-011, CDF-015, CDF-017, CDF-019, CDF-020

Summary:
1. Phases 0 through 3 were merged into the active LocalWP plugin while preserving its newer Entity Editor, Bricks, media hydration, and transfer-packet work.
2. Live QA fixed WP-CLI command loading, restored the documented `proposals list` name, and made the shared readiness permission check work correctly for trusted local CLI commands.
3. The copied build and connected dependents are stable against the tracked baselines, but the full proposal list takes about 92 seconds on this site's 11 proposals and authenticated browser QA is still pending.

Dependents affected or updated:
- Core WP-CLI command registration and the target checkout's media hydration command now register only after their classes load.
- Proposal readiness keeps `manage_options` protection for HTTP requests while allowing trusted WP-CLI list/apply execution without a fake permission blocker.
- The Bricks reference integration fixture now resolves its new-template decision before exercising Bricks warning policy; no Bricks runtime behavior was changed for Proposal Diff.
- Transfer-packet missing-payload coverage now expects the hardened proposal ZIP error and its sanitized reason/index details.
- Production admin assets were rebuilt in the LocalWP checkout while preserving its newer Entity Editor and Bricks UI changes.

Dependents needing more QA:
- Automated: add bounded performance coverage for proposal list/readiness work, then test `wp dbvc proposals apply` and `--recapture-snapshots` against a disposable proposal.
- Automated conflict watch: the two existing Bricks language/disabled-mode failures and three existing Content Collector/settings failures remain outside this slice; the four planned Phase 5 Proposal Diff failures remain the active core backlog.
- Manual: authenticate in the in-app browser and verify readiness badges, snapshot counts, entity drawer states, disabled Apply reasons, and no console errors.
- Manual: smoke a disposable proposal through snapshot recapture and blocked Apply, then check Entity Editor, Bricks portability, Official Collections, and activity logs for connected regressions.

Manual QA reference:
- DBVC area/page: Main Proposal Review and Snapshots & Diff, `https://dbvc-codexchanges.local/wp-admin/admin.php?page=dbvc-export`; Entity Editor, `/wp-admin/admin.php?page=dbvc-entity-editor`; Bricks, `/wp-admin/admin.php?page=addon-dbvc-bricks-addon`.

Verification:
- Automated checks run: production `npm run build`, Composer validation, PHP/JavaScript syntax, and clean source/target bootstrap smoke passed; lint produced no diagnostics but did not complete within the extended run window.
- Proposal Diff checks: 50 tests and 305 assertions ran in the source worktree with only the four planned Phase 5 failures; the active LocalWP checkout reported the same four contracts.
- Dependent checks: Entity Editor/third-party portability passed 18 tests and 142 assertions; transfer intake passed 1 test and 8 assertions; Bricks ran 214 tests and 2,046 assertions with its two existing failures.
- Full LocalWP suite: 563 tests and 5,603 assertions with nine tracked failures: two Bricks, three unrelated Content Collector/settings, and four planned Phase 5 Proposal Diff failures.
- Live checks: the site returned HTTP 200, unauthenticated Proposal REST returned 401, plugin version 1.8.9 was active, the small proposal readiness contract returned `ready`, and the 11-row CLI inventory returned coherent blocker categories in about 92 seconds.
- Manual checks run: browser QA could not start because the in-app browser had no attached tab; a user-authenticated browser session is still required and no proposal was applied or recaptured during this read-only round.

Next steps:
- Open and authenticate the LocalWP browser, then complete the Proposal Review visual/console checklist without applying a non-disposable proposal.
- Continue with Phase 4.1-4.2 resolver-decision consumption and action verification.
- Carry the measured proposal-list latency into Phase 7.2 alongside entity-table pagination/windowing work.

## 2026-07-19 - Phase 3.1-3.2 - Snapshot Truth

- Status: Done
- Phase / sub-phase: Phase 3 - Snapshot Truth; 3.1 - explicit snapshot states; 3.2 - recapture and apply enforcement
- CPD items: CPD-003
- Conflict flags observed: CDF-001, CDF-004, CDF-006, CDF-011, CDF-012, CDF-013, CDF-016, CDF-018, CDF-020, CDF-021

Summary:
1. Existing posts and terms now show whether their current-site snapshot is ready, missing, stale, being recaptured, or failed, while new entities clearly show that no snapshot is needed.
2. Missing or stale snapshots no longer create a false clean diff, and Proposal Apply stays blocked until every required baseline is trusted.
3. Entity and proposal recapture now work through the same REST, Proposal Review, upload, logging, and WP-CLI result path, with short failure reasons when capture cannot finish.

Dependents affected or updated:
- Snapshot Manager gained result and live-match checks while its existing void capture methods and `dbvc_enable_snapshot_capture` filter remain compatible.
- Proposal list/detail/readiness REST payloads, masking and field-decision readers, upload manifest summaries, Apply blockers, and snapshot activity records.
- Proposal Review readiness badges, entity Diff badges, drawer notices, recapture actions, upload warnings, and generated `build/admin-app.js`.
- `wp dbvc proposals list` now shows snapshot totals/untrusted counts, and `--recapture-snapshots` reports post/term capture and failure totals before rechecking readiness.
- Optional manifest key `snapshot_capture`; Entity Editor, classic restore, options/menu domains, and Bricks apply/portability do not opt into proposal snapshot gating.

Dependents needing more QA:
- Automated: run real `wp dbvc proposals list --recapture-snapshots=<id> --fail-on-pending` and blocked `wp dbvc proposals apply <id>` commands against a disposable LocalWP proposal.
- Manual: open a legacy proposal, recapture one post and one term, edit each local entity to trigger `Snapshot stale`, and verify Apply remains disabled until recapture.
- Manual conflict watch: promote a reviewed proposal through Official Collections and check copied snapshot/manifest data; smoke Entity Editor full replace and Bricks package apply/portability even though their 134 focused automated tests passed.
- Manual logging: disable `dbvc_enable_snapshot_capture` temporarily in a QA environment and confirm the upload warning plus `proposal_snapshot_capture_failed` activity entry are understandable.

Manual QA reference:
- DBVC area/page: Main Proposal Review and Snapshots & maintenance, `/wp-admin/admin.php?page=dbvc-export`; Entity Editor, `/wp-admin/admin.php?page=dbvc-entity-editor`; Bricks, `/wp-admin/admin.php?page=addon-dbvc-bricks-addon`; WP-CLI, `wp dbvc proposals list --recapture-snapshots=<id> --fail-on-pending`.

Verification:
- Automated checks run: the Proposal Diff group ran 49 tests and 300 assertions with all Phase 3 contracts passing and only the 4 planned Phase 5 failures remaining; JavaScript lint, PHP/JavaScript syntax checks, `git diff --check`, and the production admin build passed.
- Automated dependent checks: the focused Plugin Bootstrap, Entity Editor, and all Bricks suites passed 134 tests and 752 assertions; the full suite ran 183 tests and 1,052 assertions with the same 4 planned Phase 5 failures and no Phase 3 regression.
- Manual checks run: Not run - this worktree is not the active LocalWP plugin checkout, so browser, live WP-CLI, Official Collections, and activity-log checks remain listed above.

Next steps:
- Run the connected Proposal Review browser and live WP-CLI round for snapshot badges, stale transitions, recapture output, and blocked Apply behavior.
- Continue with Phase 4.1-4.2 to formalize and verify proposal/global media resolver actions through apply.
- Keep the four Phase 5 contracts unchanged: post-field masking, nested meta decisions, term masking overrides, and declined-new summary state.

## 2026-07-19 - Phase 2.1-2.2 - Apply Readiness

- Status: Done
- Phase / sub-phase: Phase 2 - Apply Readiness; 2.1 - server gate contract; 2.2 - REST, admin UI, WP-CLI, and logs
- CPD items: CPD-001
- Conflict flags observed: CDF-003, CDF-004, CDF-005, CDF-008, CDF-010, CDF-012, CDF-016, CDF-018, CDF-020

Summary:
1. Proposal apply now uses one server check for duplicate groups, unresolved media, masking fields, new entities, changed fields, hashes, and permissions.
2. Proposal Review, REST, WP-CLI, and blocked-apply logs now show the same blocker names and counts, and the hash override clears only the hash blocker.
3. Classic restore and connected Entity Editor or Bricks import paths were left outside this proposal-only gate, while snapshot enforcement remains the next phase.

Dependents affected or updated:
- Proposal list and entity REST responses, plus the new read-only `/proposals/{id}/readiness` route.
- Proposal Review table, blocker notices, Apply button, apply modal, and generated `build/admin-app.js` asset.
- `wp dbvc proposals list --fail-on-pending` and `wp dbvc proposals apply`, including non-zero blocked apply output with category names.
- Core file logging and activity records for `proposal_apply_blocked`.
- Proposal-specific and global resolver decisions, masking decisions, duplicate cleanup, new-entity review, and import-hash override state now feed the gate.

Dependents needing more QA:
- Automated: run a live WP-CLI command smoke for list/apply exit behavior when a disposable LocalWP proposal is available; keep Official Collections promotion under observation when snapshot enforcement lands.
- Manual: verify readiness badges, disabled reasons, blocker refresh after review actions, hash-only override, and the `proposal_apply_blocked` activity/log entry.
- Manual regression: open Entity Editor and Bricks apply/portability screens and confirm their actions do not show Proposal Review blockers.

Manual QA reference:
- DBVC area/page: Main Proposal Review, `/wp-admin/admin.php?page=dbvc-export`; WP-CLI, `wp dbvc proposals list --fail-on-pending` and `wp dbvc proposals apply <id>`; Entity Editor, `/wp-admin/admin.php?page=dbvc-entity-editor`; Bricks, `/wp-admin/admin.php?page=addon-dbvc-bricks-addon`.

Verification:
- Automated checks run: focused apply-readiness coverage passed 10 tests and 43 assertions; JavaScript lint and the production admin build passed; bootstrap smoke remained covered; the full suite ran 177 tests and 1,000 assertions with only the 5 tracked later-phase failures.
- Automated dependent checks: the full suite covered Entity Editor, Bricks, import routing, ZIP intake, resolver bridge, masking endpoints, and shared hooks without a new failure; a direct importer test confirmed classic `DBVC_Sync_Posts::import_backup()` is not proposal-gated.
- Manual checks run: Not run - this worktree is not the active LocalWP plugin checkout, so browser and live WP-CLI checks are listed above for the connected QA round.

Next steps:
- Implement Phase 3.1 explicit `available`, `missing`, `stale`, `recapturing`, `failed`, and `not_required` snapshot states without proposed-as-current fallback.
- Implement Phase 3.2 recapture outcomes and switch `counts.snapshots.enforced` on so required missing snapshots become a real apply blocker.
- Re-run Proposal Review browser QA, Entity Editor, Bricks apply/portability, WP-CLI, and activity logging after snapshot enforcement is connected.

## 2026-07-19 - Phase 1.1-1.2 - Intake Boundary

- Status: Done
- Phase / sub-phase: Phase 1 - Intake Boundary; 1.1 - ZIP validation; 1.2 - non-ZIP router regression coverage
- CPD items: CPD-004, with regression observation for CPD-008 and CPD-014
- Conflict flags observed: CDF-006, CDF-007, CDF-015, CDF-016, CDF-017, CDF-019, CDF-020

Summary:
1. Proposal ZIPs are now fully checked before extraction for unsafe paths, links, special or executable files, conflicting names, invalid layouts, and missing manifest payloads.
2. Valid root and single-folder bundles still import through the shared REST/WP-CLI helper, while rejected uploads return plain errors and write sanitized logging details.
3. Flat JSON routing for posts, terms, options, and menus still works with overwrite, dry-run, and manifest regeneration behavior covered by tests.

Dependents affected or updated:
- Proposal Review REST upload and `wp dbvc proposals upload`, which share `import_proposal_from_zip()`.
- Upload logging and activity records for rejected proposal archives.
- Manifest consumers, including payload path and file-presence checks before a proposal is copied into storage.
- Import router coverage for post, term, options, menu, overwrite, dry-run, and manifest generation paths.
- Fixture upload route registration smoke coverage; fixture behavior itself was not changed.

Dependents needing more QA:
- Automated: add a direct WP-CLI command smoke when the CLI harness is available; keep Bricks package/connected-site archive transport under observation because it has separate intake code.
- Manual: upload a normal exported proposal and an intentionally invalid ZIP in Proposal Review; confirm the normal file appears and the invalid file shows a short rejection without exposing its raw path.

Manual QA reference:
- DBVC area/page: Main Proposal Review upload, `/wp-admin/admin.php?page=dbvc-export`; WP-CLI, `wp dbvc proposals upload <zip>`; logs/diagnostics for `proposal_upload_rejected`.

Verification:
- Automated checks run: focused intake/router suite passed 20 tests and 155 assertions; bootstrap smoke passed 2 tests and 15 assertions; the full suite ran 169 tests and 958 assertions with only the 7 previously tracked red failures.
- Manual checks run: Not run - archive behavior was verified through real `ZipArchive`, REST helper, filesystem, manifest, and router integration tests.

Next steps:
- Implement Phase 2.1 as one server-side apply readiness contract with stable blocker categories and counts.
- Start by turning the pending-new and duplicate apply contract tests green without changing classic restore or Bricks apply behavior.
- Then wire the same readiness result into REST, admin UI, WP-CLI, and logs in Phase 2.2.

## 2026-07-19 - Phase 0.1-0.2 - Test Foundation

- Status: Done
- Phase / sub-phase: Phase 0 - Test Foundation; 0.1 - WP PHPUnit/bootstrap; 0.2 - P0/P1 contract coverage
- CPD items: CPD-001, CPD-002, CPD-003, CPD-004, CPD-005, CPD-006, CPD-007, CPD-008, CPD-009
- Conflict flags observed: CDF-001, CDF-003, CDF-004, CDF-005, CDF-006, CDF-008, CDF-009, CDF-010, CDF-013, CDF-014, CDF-016, CDF-017, CDF-018, CDF-019, CDF-020, CDF-021

Summary:
1. The WordPress test bootstrap now uses this checkout by default, loads its PHPUnit support package, and skips the production update checker only during tests.
2. A smoke suite now confirms that core Proposal Diff, Entity Editor, and Bricks services and routes load, while a focused contract suite records the current P0/P1 gaps.
3. The full suite now runs without bootstrap errors and stops at eight known Proposal Diff failures that later phases are expected to turn green.

Dependents affected or updated:
- Plugin bootstrap constants and Composer test commands.
- Entity Editor and Bricks add-on bootstrap/route registration smoke coverage.
- Proposal Review masking tests and resolver decision bridge coverage.

Dependents needing more QA:
- Automated: turn the eight red contracts green across ZIP intake, apply gates, snapshots, nested meta, post/term masking, duplicates, and declined new entities.
- Manual: after runtime fixes begin, smoke DBVC -> Export, Entity Editor, and Bricks pages for bootstrap or route regressions.

Manual QA reference:
- DBVC area/page: Main Proposal Review, `/wp-admin/admin.php?page=dbvc-export`; Entity Editor, `/wp-admin/admin.php?page=dbvc-entity-editor`; Bricks, `/wp-admin/admin.php?page=addon-dbvc-bricks-addon`.

Verification:
- Automated checks run: `vendor/bin/phpunit --filter PluginBootstrapSmokeTest` passed 2 tests and 14 assertions; `vendor/bin/phpunit --group proposal-diff` ran 15 tests with 8 expected red failures; the full suite ran 149 tests and 800 assertions with the same 8 failures and no bootstrap errors.
- Manual checks run: Not run - this slice changes test/bootstrap infrastructure and does not change the Proposal Review UI.

Next steps:
- Implement Phase 1.1 ZIP entry validation before extraction and turn the unsafe ZIP contract green.
- Run Phase 1.2 non-ZIP import router regression coverage before moving into apply readiness.
- Keep CPD-005 in review until browser smoke coverage is added during the connected UI phases.

## Current Log State

- Created: 2026-07-19
- Current sprint status: Phase 5.1 and CPD-006 are done; 87 resolved Proposal Diff tests are green while the two planned Phase 5.2/5.4 contracts remain visible in the pending lane. Phase 3.3, Phase 4, and CPD-002 remain in review for their connected resolver-specific QA.
- Next expected entry: Phase 5.2 / CPD-007 term masking parity, with CPD-002 review closure tracked separately.
