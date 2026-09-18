# Minimal Claude Code Prompt - R1-D4A Media Manager Corrections

R1-D1 through R1-D4 are complete. The static mockup is accepted as visual direction with required adaptations; it is not ready for production translation.

Open Claude Code at the DBVC plugin repository root and paste the current D4A prompt supplied by Codex. The shortest canonical read set is:

1. `AGENTS.md`
2. `addons/visual-editor/AGENTS.md`
3. `releases/R1-MEDIA-MANAGER-SCAN-AND-REPORT.md`, starting at the R1-D delivery/review checkpoint
4. `ui-ux/MOCKUP-TO-PRODUCTION-INTEGRATION.md`, starting at the R1-D reviewed translation contract
5. tracking decisions D-030 through D-032, evidence E-040, and risks RK-033 through RK-035
6. the current R1-C fixture and the delivered mockup files directly affected by the active correction sub-phase

Use backend source only to resolve an exact contract question:

- `addons/visual-editor/src/MediaManager/MediaScanReadModel.php`
- `addons/visual-editor/src/MediaManager/MediaScanCoordinator.php`
- `addons/visual-editor/src/Rest/Controllers/MediaManagerController.php`

Run D4A as three stopped sub-phases:

1. **D4A-1 contract and fixture:** correct list sort/page/cursor/timestamp evidence, add complete safe expansion fields, document the server/view-state adapter, distinguish group request errors from provider-unavailable row projections, and synchronize affected static markup/docs. Update the existing fixture entry/hash in the package manifest/checksum. Stop for review.
2. **D4A-2 responsive and interaction:** fix short-height/mobile reachability, practical mobile targets, pending/final announcements, singular copy, and ARIA/order agreement. Stop for review.
3. **D4A-3 validation and handback:** re-run structural/constraint checks, verify 1440x900, 900-class, 390x844, 375x667, and 320x568 geometry/reachability, refresh screenshots/docs, and return exact residual limitations. Stop for Codex sign-off.

Do not write production PHP/JavaScript/CSS, tests, generated agent docs, routes, descriptors, Media Library actions, or mutations in D4A. Do not reset, restore, stash, clean, stage, commit, or push.
