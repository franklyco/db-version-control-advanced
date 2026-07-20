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
- Current sprint status: Phase 4.1-4.2 is merged and automated LocalWP/dependent QA is complete; Phase 3.3, Phase 4, CPD-002, and CPD-005 remain in review for authenticated browser QA.
- Next expected entry: signed-in Phase 3/4 Proposal Review QA completion or Phase 5.1-5.2 apply semantics and term masking.
