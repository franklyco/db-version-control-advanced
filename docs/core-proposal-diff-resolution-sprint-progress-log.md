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

## 2026-07-27 - Phase 7.1 - Canonical Status Labels and Counters

- Status: Done
- Phase / sub-phase: Phase 7 - UI Clarity; 7.1 - normalize status labels and counters
- CPD items: CPD-012
- Conflict flags observed: CDF-001, CDF-005, CDF-008, CDF-015, CDF-020, CDF-027

Summary:
1. Proposal Review now uses the same seven plain-language status counts in REST, the proposal list, entity table, drawer, Tools, Apply confirmation, filters, and WP-CLI.
2. Live QA found and fixed the duplicate filter returning unrelated rows, a blank Duplicate groups option, and a partial build that had removed the Entity Editor and Content Collector bundles.
3. Legacy count fields still work, all declared admin bundles are restored, and the remaining large-proposal speed and narrow-table limits stay in Phase 7.2.

Dependents affected or updated:
- Proposal readiness/list/detail REST payloads, status filters, generated Proposal Review assets, masking guidance, README workflow notes, and WP-CLI proposal-list custom fields.
- Entity Editor and Content Collector V2 compiled assets were restored by full builds; their distinct active-checkout source was preserved.
- Bricks Portability, Configuration Portability, third-party portability, UID matching, Content Migration, media hydration, masking, and transfer tools were regression-tested without production changes.

Dependents needing more QA:
- Automated: none open for Phase 7.1; source/live Proposal Diff lanes and both active dependent matrices are green.
- Manual: repeat the seven-label review on a future proposal containing every status at once, because existing retained proposals covered the labels across several proposal states rather than one combined fixture.
- Manual: Phase 7.2 must address the measured 14-16 second large-filter requests and horizontal diff/table overflow at 390 px; these were verified but were not caused by the label changes.

Manual QA reference:
- DBVC area/page: [Main Proposal Review](https://dbvc-codexchanges.local/wp-admin/admin.php?page=dbvc-export) -> select a proposal -> compare the list, entity badges, drawer, Tools, filter menu, and Apply confirmation.
- Dependent pages: [Entity Editor](https://dbvc-codexchanges.local/wp-admin/admin.php?page=dbvc-entity-editor) and [Bricks Add-on](https://dbvc-codexchanges.local/wp-admin/admin.php?page=addon-dbvc-bricks-addon).
- CLI parity: `wp dbvc proposals list --fields=id,field_needs_review,meta_needs_review,media_needs_review,resolver_conflicts,masking_candidates,duplicates,new_entities_pending`.

Verification:
- Source and LocalWP resolved Proposal Diff lanes each passed 103 tests and 963 assertions; both pending lanes reported no tests.
- The active dependent matrix passed 197 tests and 2,452 assertions across Entity Editor, Bricks, Configuration Portability, third-party portability, UID preservation, and Content Migration.
- The additional masking, media, transfer, and configuration-export matrix passed 68 tests and 545 assertions.
- Authenticated REST checks returned HTTP 200 and exact status parity for all seven filters; WP-CLI returned the same seven counts without changing proposal decisions or content.
- Browser QA passed proposal list, entity table, populated drawer, Tools, Apply confirmation, filter options, Entity Editor, and Bricks Portability with no console errors from these surfaces.
- Full source and active builds restored every declared entry point. PHP/JavaScript syntax and generated-asset checks passed.

Next steps:
- Proceed to Phase 7.2 / CPD-013 for proposal/entity pagination, server windowing, filter latency, and narrow table/drawer behavior.
- Keep CPD-002 in review for its existing resolver-specific connected apply QA.
- Use full `npm run build` in future rounds so dependent admin entry points are not removed.

## 2026-07-26 - Phase 6.2 - Bounded Proposal Diff Views

- Status: Done
- Phase / sub-phase: Phase 6 - Diff Review Depth; 6.2 - add first-class raw diff and mode-specific drawer views
- CPD items: CPD-011
- Conflict flags observed: CDF-006, CDF-009, CDF-014, CDF-015, CDF-018, CDF-020

Summary:
1. The entity drawer now switches between Changed, All Fields, and Raw JSON using server-owned view payloads.
2. All Fields includes supported unchanged fields, while Raw JSON keeps each preview at 20,000 bytes, caps its value-free index at 1,000 rows, and links to the complete downloads.
3. Canonical decisions and apply units stay unchanged, and live QA found and fixed keyboard focus loss after a mode refresh.

Dependents affected or updated:
- Proposal entity detail and raw-download REST routes, snapshot context, decision pruning, readiness counts, canonical apply paths, the Proposal Review drawer, and its generated assets.
- Requests without a `view` parameter keep the legacy full payload shape; Changed, All Fields, and Raw JSON use the new bounded contract.
- Shared admin styling remains scoped to the raw drawer. Entity Editor, Bricks portability/reference/drift, Configuration Portability, third-party portability, UID matching, and Content Migration were observed through the connected dependent matrix rather than changed.

Dependents needing more QA:
- Automated: none open for CPD-011; both resolved lanes and the expanded active dependent matrix are green.
- Manual: use a real deeply nested Bricks proposal and real term/media-backed proposal as future conflict watches for CDF-009 and CDF-020, because the disposable live fixture covered post, meta, and taxonomy data.
- Manual: the existing sticky proposal-table header can cover rows at 390 px. Keep that verified table issue under Phase 7.2/CDF-015; the Phase 6.2 drawer itself stacked cleanly without horizontal overflow.

Manual QA reference:
- DBVC area/page: [Main Proposal Review](https://dbvc-codexchanges.local/wp-admin/admin.php?page=dbvc-export) -> select a proposal with a trusted snapshot -> open an entity -> switch between Changed, All Fields, and Raw JSON.

Verification:
- Source and LocalWP resolved Proposal Diff lanes each passed 102 tests and 904 assertions; both pending lanes reported no tests.
- The active dependent matrix passed 191 tests and 2,388 assertions across Entity Editor, Bricks portability/reference/drift/truncation, Configuration Portability, third-party portability, UID preservation, and Content Migration.
- Authenticated REST QA preserved the legacy payload, returned 4 Changed rows, 27 All Fields rows with 23 unchanged, two 20,000-byte raw previews, a four-row value-free index, and HTTP 200 full downloads.
- The 1,105-change fixture returned 1,000 displayed rows and 105 omitted rows in both bounded modes while retaining canonical actionable totals and apply paths.
- Browser QA passed Changed, All Fields, Raw JSON, stable selectors, desktop and 390 px drawer layout, clean console output, initial focus, mode-refresh focus recovery, Escape close, and entity-row focus return.
- PHP and JavaScript syntax checks and source/active production builds passed. Cleanup removed both posts, the category term, proposal and snapshot directories, and proposal-scoped options with zero residue.

Next steps:
- Proceed to Phase 7.1 / CPD-012 for canonical status labels and counters.
- Keep Phase 7.2 `In progress` for proposal/entity pagination, windowing, and the verified narrow sticky-header issue.
- Keep CPD-002 in its existing review lane for resolver-specific connected apply QA.

## 2026-07-24 - Phase 6.1 - Classified Diff Contract

- Status: Done
- Phase / sub-phase: Phase 6 - Diff Review Depth; 6.1 - introduce classified diff payloads and stable change IDs
- CPD items: CPD-010
- Conflict flags observed: CDF-006, CDF-009, CDF-011, CDF-014, CDF-015, CDF-018, CDF-019, CDF-020, CDF-021

Summary:
1. Each field difference now arrives with a stable ID and a clear Added, Removed, Changed, or Unchanged label.
2. Oversized values and long row sets stay bounded, while complete decision paths remain available and reviewers can download the full current or proposed JSON.
3. The LocalWP API and drawer passed with a disposable trusted snapshot; the full inline raw panels remain the separate Phase 6.2 limit.

Dependents affected or updated:
- Proposal entity detail/raw REST routes, snapshot comparison, decision pruning, readiness counts, and the generated Proposal Review drawer.
- Post fields, nested meta, taxonomy sections, masking/ignore behavior, and canonical importer apply paths.
- Shared admin assets used beside Entity Editor, Bricks portability/drift/reference tools, Configuration Portability, third-party portability, UID matching, and Content Migration.

Dependents needing more QA:
- Automated: Phase 6.2 must cover explicit `changed`, `all`, and bounded `raw` drawer modes, including a live response over the 1,000-row display limit.
- Manual: after Phase 6.2, recheck drawer focus/keyboard behavior and raw-mode layout at desktop and narrow widths.
- Manual conflict watch: use a deeply nested Bricks proposal and a term/taxonomy proposal to inspect labels and sections, even though their focused dependent tests are green.

Manual QA reference:
- DBVC area/page: [Main Proposal Review](https://dbvc-codexchanges.local/wp-admin/admin.php?page=dbvc-export) -> select a proposal with a trusted snapshot -> select an entity to open its diff drawer.

Verification:
- Source and LocalWP resolved Proposal Diff lanes each passed 100 tests and 829 assertions; both pending lanes reported no tests.
- The expanded active dependent matrix passed 191 tests and 2,388 assertions, including Entity Editor, Bricks portability/reference/drift/truncation, Configuration Portability, third-party portability, UID preservation, and Content Migration.
- Connected REST returned a trusted six-row diff with one Added, one Removed, four Changed, stable IDs, complete apply paths, and bounded 6.2/6.3 KB values; both raw downloads returned HTTP 200 attachment JSON.
- Browser QA rendered Added, Removed, and Changed row classes plus both raw links with no warning or error. PHP/JavaScript syntax, source/active production builds, and generated bundle checks passed.
- The disposable page, proposal, snapshot, option entries, and directories were removed; a direct residue check returned zero posts and no proposal or snapshot directory.

Next steps:
- Implement Phase 6.2 / CPD-011 as explicit `changed`, `all`, and bounded `raw` drawer modes.
- Keep Phase 7.2 `In progress` for proposal pagination controls and large-entity server pagination/windowing.
- Keep CPD-002 in its existing review lane for resolver-specific connected apply QA.

## 2026-07-24 - Phase 5A.1-5A.2 - Verified Runtime Follow-Ups

- Status: Done
- Phase / sub-phase: Phase 5A - Verified Runtime Follow-Ups; 5A.1 - preserve notice ownership; 5A.2 - bound proposal inventory readiness work
- CPD items: CPD-013 partial; CDF-027 resolution
- Conflict flags observed: CDF-001, CDF-004, CDF-005, CDF-008, CDF-009, CDF-015, CDF-018, CDF-027

Summary:
1. Proposal Review now loads a bounded summary list first instead of calculating every proposal's full readiness before showing any rows.
2. Selecting one proposal runs its authoritative checks, keeps Apply disabled while they load, and WP-CLI can use `--id` for the same bounded lookup.
3. DBVC status messages no longer use site-wide WordPress notice classes, so the Admin Notices tool cannot change React-owned nodes.

Dependents affected or updated:
- Proposal list REST parameters and pagination metadata, selected-proposal readiness, Apply guards, list badges, refresh behavior, and `wp dbvc proposals list`.
- Duplicate, new-entity, snapshot, field, masking, resolver, transfer, and Bricks summaries that feed readiness.
- Shared Proposal Review status blocks, including upload, masking, transfer, and Bricks states compiled into the same admin app.

Dependents needing more QA:
- Automated: add server-side pagination/windowing coverage for the large entity endpoint/table and performance smoke fixtures with more than 20 proposals and more than 1,000 entities.
- Automated: measure unfiltered multi-page WP-CLI behavior and retain exact gate parity after decision, snapshot, duplicate, masking, and resolver updates.
- Manual: force a Proposal REST error and retry after future Admin Notices or DBVC status-component changes; the selector collision is removed, but this remains the direct regression scenario.
- Manual: once pagination controls exist, check selection, refresh, filters, keyboard navigation, Entity Editor styling, and a large Bricks proposal across pages.

Manual QA reference:
- DBVC area/page: [Main Proposal Review](https://dbvc-codexchanges.local/wp-admin/admin.php?page=dbvc-export); WP-CLI, `wp dbvc proposals list --id=<proposal-id>`.

Verification:
- Profiling identified full gates as the dominant list cost, with additional masking, field, snapshot, resolver, new-entity, duplicate, and Bricks work repeated across all 11 proposals.
- The default authenticated list improved from 193.22 seconds to 11.18 seconds and returned deferred readiness; filtered full readiness took 14.85 seconds, `/readiness` took 13.64 seconds, and their complete gate payloads matched.
- A live bounded WP-CLI lookup returned the same five blocker categories for the 755-entity proposal. Its entity endpoint remained 1.5 MB and 15.75 seconds, which keeps entity scaling open under Phase 7.2.
- Browser QA captured `Select to check`, then `Checking...` with Apply disabled, then the loaded blocker state. With the site's notice manager enabled, the DBVC root had zero `.notice` nodes, no injected controls, and no console warning, error, or `removeChild` failure.
- After disposable QA cleanup, Proposal Review returned to the original 11 rows and the fixture was absent.

Next steps:
- Continue Phase 7.2 later with visible proposal pagination controls and server-side entity pagination/windowing.
- Treat the unfiltered all-proposal CLI path as intentionally complete but potentially expensive until the remaining scaling slice lands.
- Proceed now to Phase 6.1, recorded in the entry above.

## 2026-07-24 - Phase 5.4 - Declined New Entity Resolution

- Status: Done
- Phase / sub-phase: Phase 5 - Apply Semantics; 5.4 - treat declined new entities as resolved skip states
- CPD items: CPD-009
- Conflict flags observed: CDF-004, CDF-010, CDF-012, CDF-013, CDF-016, CDF-027

Summary:
1. New posts and terms now stay clearly accepted, declined, or pending across review, apply, and refresh.
2. A decline resolves the proposal, removes that entity from masking work, skips its import with a clear reason, and survives automatic decision cleanup so forced reapply cannot bring it back.
3. Connected LocalWP checks passed and the disposable data was removed; the slow proposal list and the Admin Notices page conflict remain assigned to later UI work.

Dependents affected or updated:
- Proposal summaries, entity list/detail responses, readiness counts, masking candidates, pending-only bulk accept, apply results, logs, and the generated admin bundle.
- The shared post/term importer, `dbvc_force_reapply_new_posts`, auto-clear behavior, and the local archived-decline option `dbvc_proposal_declined_new_entities`.
- REST and WP-CLI now receive structured reviewer-skip details and decline totals.
- Official Collections code was not changed; its promotion path still has no direct caller or automated test in this checkout.

Dependents needing more QA:
- Automated: add a direct Official Collections promotion case and a real WP-CLI apply command case for a disposable declined post and term.
- Automated conflict watch: the 173-test dependent set still exposes one existing Content Migration order leak; the failing method and its complete 32-test class pass in isolation.
- Manual: after Phase 7.2 improves list speed, load the declined proposal in Proposal Review and confirm its Ready state, entity badges, and skip history without a fetch timeout.
- Manual conflict watch: retest Proposal Review error/retry behavior with the site's Admin Notices manager enabled after CDF-027 is resolved.

Manual QA reference:
- DBVC area/page: [Main Proposal Review](https://dbvc-codexchanges.local/wp-admin/admin.php?page=dbvc-export); WP-CLI, `wp dbvc proposals list` and `wp dbvc proposals apply <id>`.

Verification:
- Source and LocalWP resolved Proposal Diff lanes: 97 tests and 697 assertions passed; both pending lanes reported no tests.
- Active dependent matrix: 173 tests completed with the known Content Migration order-dependent failure; the affected method passed alone with 21 assertions and its class passed 32 tests and 568 assertions.
- Static/build checks: PHP and JavaScript syntax, both production builds, and `git diff --check` passed. Repository-wide JavaScript lint was stopped after eight minutes without completing because it processes the large generated/transpiled bundles.
- Connected LocalWP QA: one new post and one new term were declined, readiness showed zero pending and zero masking blockers, apply returned two reviewer skips, archived declines survived auto-clear, forced reapply remained safe, and cleanup reported no residue.
- Authenticated REST returned HTTP 200 with the same two resolved declines, but the list required 193.22 seconds. Browser QA fixed the formatter crash that hid fetch errors and separately identified the Admin Notices DOM ownership conflict under CDF-027.

Next steps:
- Implement Phase 6.1 / CPD-010 classified diff payloads and stable change IDs.
- Keep CPD-002 in review for its remaining resolver-specific connected QA.
- Retain proposal-list latency under Phase 7.2 and the Admin Notices conflict under CDF-027.

## 2026-07-24 - Phase 5.3 - Canonical Duplicate Detection and Cleanup

- Status: Done
- Phase / sub-phase: Phase 5 - Apply Semantics; 5.3 - unify duplicate detection across upload, list, UI gate, REST, CLI, and cleanup
- CPD items: CPD-008
- Conflict flags observed: CDF-003, CDF-004, CDF-006, CDF-011, CDF-013, CDF-020

Summary:
1. Proposal upload, list, readiness, duplicate reports, UI cleanup, and WP-CLI now use one typed duplicate inventory.
2. Stable group and entry IDs keep post and term duplicates separate, and cleanup updates files and the manifest as one recoverable operation.
3. Connected QA cleared disposable post and term duplicates without residue; real site proposals were listed but not changed.

Dependents affected or updated:
- Proposal REST/list/readiness responses, ZIP upload rejection, duplicate modal actions, the generated admin bundle, and WP-CLI cleanup now share canonical IDs and counts.
- Term duplicates without a UID use their term ID, while future typed entities can use UID, generic entity ID, or scoped slug fallback.
- Cleanup preserves shared payload paths, updates manifest totals, and refreshes CLI readiness before `--fail-on-pending`.

Dependents needing more QA:
- Automated: inject a manifest-commit failure to exercise file rollback, and add a future Bricks/artifact duplicate fixture.
- Automated conflict watch: the 173-test dependent set passes in normal order; one Content Migration rollback test failed only in randomized order and passed alone, so its test-state cleanup remains a separate watch point.
- Manual: review one real duplicate proposal and choose its canonical files before running `wp dbvc proposals list --cleanup-duplicates`; current live proposals include groups with 10-18 duplicates.

Manual QA reference:
- DBVC area/page: [Main Proposal Review](https://dbvc-codexchanges.local/wp-admin/admin.php?page=dbvc-export), select a proposal with duplicates and open its duplicate cleanup dialog.

Verification:
- Source and LocalWP resolved Proposal Diff lanes: 94 tests and 626 assertions passed.
- Focused duplicate contract: 4 tests and 53 assertions passed in source and LocalWP.
- Active dependent matrix: 173 tests and 2,301 assertions passed in normal order; the randomized order observation was isolated and reproduced as test-order dependent.
- Connected LocalWP QA verified three list/report/readiness groups, stable IDs, shared-UID post/term separation, term-ID fallback, legacy ambiguity blocking, specific and CLI-style bulk cleanup, manifest totals, no residue, ZIP rejection, and full fixture removal.
- Read-only live `wp dbvc proposals list --fields=id,duplicate_count,readiness` completed. Destructive CLI cleanup was not run against existing site proposals.

Next steps:
- Implement Phase 5.4 / CPD-009 so accepted and declined new posts and terms are both resolved states, with declined entities recorded as reviewer skips.
- Keep Phase 4 / CPD-002 in review until its resolver-specific connected browser and CLI checks are closed.

## 2026-07-24 - Phase 5.2 - Term Masking Parity

- Status: Done
- Phase / sub-phase: Phase 5 - Apply Semantics; 5.2 - wire term masking overrides into term and term-meta import
- CPD items: CPD-007
- Conflict flags observed: CDF-002, CDF-014, CDF-016, CDF-018, CDF-020, CDF-021

Summary:
1. Term imports now honor the same masking suppressions and replacement values as post meta.
2. Nested term values merge safely, and the import result and term log show how many fields were suppressed or replaced.
3. Ignore and revert already worked at review time and now have explicit term coverage alongside the completed import path.

Dependents affected or updated:
- The proposal term importer now receives the existing per-entity masking bundle and uses the shared safe meta planner.
- Proposal import responses add `term_masking` counters, and term-only logging records the exact affected paths when its setting is enabled.
- Masking option names, stores, REST request/response shapes, manifest schema, and public hook signatures did not change.

Dependents needing more QA:
- Automated: Official Collections still has no direct term-promotion test in this checkout.
- Automated conflict watch: Entity Editor, Bricks portability/reference mapping, configuration portability, third-party portability, UID preservation, and migration tests are green.
- Manual: recheck one term proposal in Proposal Review after changing the configured masking defaults or term-import logging setting.
- Environment: the saved `CodexDev` account was absent from the current LocalWP database, so connected QA used another current administrator without changing site authentication.

Manual QA reference:
- DBVC area/page: [Main Proposal Review](https://dbvc-codexchanges.local/wp-admin/admin.php?page=dbvc-export), open a term entity, then review its masking fields and Meta section.

Verification:
- Source and LocalWP resolved Proposal Diff lanes: 91 tests and 578 assertions passed.
- Source full suite excluding the one remaining pending contract: 226 tests and 1,343 assertions passed.
- Active randomized dependent matrix with seed `20260724`: 173 tests and 2,301 assertions passed.
- Pending lane: exactly 1 expected failure and 2 assertions remain for the Phase 5.4 declined-new summary state.
- Connected LocalWP QA reviewed two nested term fields, restored them to pending with Revert, reapplied them, preserved `local-token`, stored `REDACTED`, verified 1/1/1 entity/override/suppression counters and term logging, and removed the disposable proposal and term.

Next steps:
- Implement Phase 5.3 / CPD-008 by consolidating post and term duplicate detection across upload, reporting, readiness, REST, CLI, and cleanup.
- Keep Phase 4 / CPD-002 in review until its resolver-specific connected browser and CLI checks are closed.

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

- Status: Done
- Phase / sub-phase: Phase 3 - Snapshot Truth; 3.3 - connected LocalWP integration and dependent QA
- CPD items: CPD-001, CPD-003, CPD-004, CPD-005, CPD-013
- Conflict flags observed: CDF-001, CDF-004, CDF-005, CDF-006, CDF-008, CDF-010, CDF-011, CDF-015, CDF-017, CDF-019, CDF-020

Summary:
1. Phases 0 through 3 were merged into the active LocalWP plugin while preserving its newer Entity Editor, Bricks, media hydration, and transfer-packet work.
2. Live QA fixed WP-CLI command loading, restored the documented `proposals list` name, and made the shared readiness permission check work correctly for trusted local CLI commands.
3. The copied build and connected dependents remain stable; the pending browser check and proposal-list follow-up were completed on 2026-07-24, while unfinished entity scaling moved to Phase 7.2.

Dependents affected or updated:
- Core WP-CLI command registration and the target checkout's media hydration command now register only after their classes load.
- Proposal readiness keeps `manage_options` protection for HTTP requests while allowing trusted WP-CLI list/apply execution without a fake permission blocker.
- The Bricks reference integration fixture now resolves its new-template decision before exercising Bricks warning policy; no Bricks runtime behavior was changed for Proposal Diff.
- Transfer-packet missing-payload coverage now expects the hardened proposal ZIP error and its sanitized reason/index details.
- Production admin assets were rebuilt in the LocalWP checkout while preserving its newer Entity Editor and Bricks UI changes.

Dependents needing more QA:
- Automated: add bounded performance coverage for proposal list/readiness work, then test `wp dbvc proposals apply` and `--recapture-snapshots` against a disposable proposal.
- Automated conflict watch: the two existing Bricks language/disabled-mode failures and three existing Content Collector/settings failures remain outside this slice; the four planned Phase 5 Proposal Diff failures remain the active core backlog.
- Manual: authenticated readiness badges, snapshot state, disabled Apply reasons, classified drawer states, and console behavior are now verified. Resolver-specific four-action apply QA remains with Phase 4 / CPD-002.
- Manual: Entity Editor and Bricks portability remain conflict-watch surfaces for later UI/build slices; their expanded automated dependent matrix is green.

Manual QA reference:
- DBVC area/page: Main Proposal Review and Snapshots & Diff, `https://dbvc-codexchanges.local/wp-admin/admin.php?page=dbvc-export`; Entity Editor, `/wp-admin/admin.php?page=dbvc-entity-editor`; Bricks, `/wp-admin/admin.php?page=addon-dbvc-bricks-addon`.

Verification:
- Automated checks run: production `npm run build`, Composer validation, PHP/JavaScript syntax, and clean source/target bootstrap smoke passed; lint produced no diagnostics but did not complete within the extended run window.
- Proposal Diff checks: 50 tests and 305 assertions ran in the source worktree with only the four planned Phase 5 failures; the active LocalWP checkout reported the same four contracts.
- Dependent checks: Entity Editor/third-party portability passed 18 tests and 142 assertions; transfer intake passed 1 test and 8 assertions; Bricks ran 214 tests and 2,046 assertions with its two existing failures.
- Full LocalWP suite: 563 tests and 5,603 assertions with nine tracked failures: two Bricks, three unrelated Content Collector/settings, and four planned Phase 5 Proposal Diff failures.
- Live checks: the site returned HTTP 200, unauthenticated Proposal REST returned 401, plugin version 1.8.9 was active, the small proposal readiness contract returned `ready`, and the 11-row CLI inventory returned coherent blocker categories in about 92 seconds.
- Manual checks run: closure update on 2026-07-24 verified bounded list loading, deferred/checking/loaded readiness, disabled Apply reasons, trusted snapshot detail, classified diff rows, notice ownership, and a clean browser console.

Next steps:
- Continue Phase 4.1-4.2 resolver-decision consumption and action verification under CPD-002.
- Complete the remaining Phase 7.2 admin pagination controls and entity-table server pagination/windowing.

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
- Current sprint status: Phase 7.1 / CPD-012 is done. Source and LocalWP each pass 103 resolved Proposal Diff tests and 963 assertions, the pending lane is empty, and the active dependent matrices pass 197 tests and 2,452 assertions plus 68 tests and 545 assertions.
- Open tracked work: Phase 4 / CPD-002 remains in review for resolver-specific connected apply QA; Phase 7.2 / CPD-013 is in progress for admin pagination controls, large-entity server pagination/windowing, measured large-filter latency, and narrow table/drawer behavior.
- Next expected entry: Phase 7.2 / CPD-013 proposal and entity list scaling.
