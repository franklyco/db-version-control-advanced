# Mockup-to-Production Integration

## Principle

Translate approved visual intent into current DBVC architecture. Do not promote static mockup markup, styles, or demo behavior into a parallel frontend framework.

## Integration workflow

1. Compare mockup regions with current Visual Editor components.
2. Map each display property to a verified safe server read model.
3. Map each action to an approved current request/descriptor flow.
4. Identify mockup-only behavior and reject/defer it.
5. Reuse current loading/status/error/focus primitives.
6. Implement markup with semantic/accessibility requirements.
7. Translate styles into current scoped variables/classes.
8. Test with site CSS, admin bar, toolbar, main panel, and Media Library.
9. Verify builder isolation.
10. Record deviations.

## Media Manager-specific rules

- The table list response contains no full writable descriptors.
- Expansion requests fresh server details.
- Do not use raw ACF field keys in DOM IDs or click handlers.
- Do not implement the concept image’s `Save selected` across entities.
- `Upload New` opens WordPress core Media Library/upload; it is not a custom uploader.
- If compact embedded field controls would duplicate current image/gallery logic, open the existing main panel instead.
- Do not add a new media/editor enqueue for the read-only R1 panel; current active Visual Editor mode already carries those assets.
- Use a bounded rendered row count even if the scan contains all results.
- Preserve scroll/filter state after targeted finding updates.
- Ensure the media modal is the topmost layer and closes before the Media Manager.

## R1-D reviewed translation contract

The Claude D1-D4 static mockup is accepted as visual direction, not as production markup, CSS, JavaScript, fixture authority, or a verified runtime contract. D4A completed the required contract, responsive, interaction, screenshot, and documentation corrections on 2026-08-16. Production translation may now proceed in the bounded slices below.

The D4A axe pass is qualified evidence, not accessibility sign-off. It found a shared production palette defect in `--dbvc-ve-color-text-subtle`; correct and regression-test the authoritative `overlay.css` token before treating the translated shell or existing Visual Editor surfaces as contrast-clean.

### Contract reconciliation

- `MediaScanReadModel` is authoritative for list order, cursor paging, and expansion fields.
- `entity_asc` is alphabetical. The reviewed fixture's scan-time-descending item order cannot be presented with `entity_asc` or `aria-sort="ascending"`.
- A page containing all six matching rows under limit 20 must use `hasMore: false` and an empty cursor. If the mockup needs a Load more state, use a contract-valid partial first page rather than over-reporting pagination.
- `missing_desc` and `scanned_desc` are valid allowlisted sort keys; their ascending pairs are also supported.
- Expansion freshness compares per-finding empty fingerprints. The client must render server status and must not derive `current` from `modifiedGmt`.
- Changed, resolved-or-changed, and provider-unavailable expansion responses include safe `fields[]` projections. Do not treat the abbreviated static fixture states as the full production response.

### Scan and request state mapping

Keep backend states and client presentation explicit. Friendly UI labels/classes may differ only through a documented adapter:

| Backend evidence | Client presentation |
|---|---|
| `scanning` | running/in-progress |
| `failed` | error; expose actions strictly from `canRetry` and `canCancel` |
| `canceled` | canceled |
| `complete` | complete |
| `invalidated` | configuration changed; require a fresh scan |
| latest/list 404 `media_scan_expired_or_invalid` | no current scan / expired-unavailable; do not claim it was never run |
| group 404 `media_scan_group_unavailable` | expansion request unavailable; refresh the list/scan |
| row `status: unavailable` with `fields[]` | provider revalidation failed safely; render the returned fields and safe error |

Generation/revision conflicts and superseded requests are request states, not row statuses. Suppress stale responses and preserve the newest accepted state.

### Responsive and focus correction

Current production priority is normal laptop and desktop use. The D4A/slice-1 narrow-width protections below remain a regression floor, but additional mobile-friendly layouts, responsive cards, touch refinements, real-device optimization, and mobile-specific QA are tabled until explicitly reauthorized. Do not spend current R1 slices extending the mobile design.

The desktop arrangement may keep the result table as its internal scroll region while the remaining chrome fits. D4A and production slice 1 already established a single reachable narrow/short-height shell as the current regression floor. The table slice must not create a new document-width overflow or clipped shell, but it is not required to design or verify mobile table/card behavior. The fuller 390x844, 375x667, and 320x568 table/expansion matrix is deferred until responsive/mobile work is reauthorized.

Retain the existing practical close target. Mobile-specific row/filter/link target refinement is deferred. Announce loading before an expansion response and announce the final revalidated state only after it arrives. Handle singular/plural count copy and keep `aria-sort` synchronized with the order returned by the server.

## Brand/Workspace rules

- Registered control rows open the existing main panel.
- Registry/list data is not mutation authority.
- Workspace object navigation reuses current Go To Object policy.
- Media Manager integration in R6 opens the existing module; do not copy its scan/table logic into the drawer.

## CSS requirements

- Use current Visual Editor root namespace.
- No global resets.
- Avoid broad selectors.
- Reuse current tokens for type, spacing, radius, shadows, and z-index where available.
- Test against aggressive site/theme CSS.
- Respect reduced motion and focus visibility.
- Ensure sticky table regions do not obscure focus or content.

## JavaScript/state requirements

- Use current event/request/state conventions.
- Ignore/cancel stale scan/search responses.
- Clean up event listeners/media frames on close/teardown.
- Do not use mockup JavaScript as the production store.
- Maintain clear state separation: scan snapshot, expanded row, draft selection, save status.
- Closing the panel must not save drafts.
- Add a narrow Media Manager API/state module rather than copying `mockup.js` or substantially enlarging the existing overlay state monolith.
- Keep the toolbar integration seam small: it may open/close the module and restore focus, while scan/query/row state remains owned by the Media Manager module.

## Prohibited shortcuts

- Copy mockup JS into production.
- Make list rows submit values directly.
- Embed raw owner/field/path targets in markup.
- Add inline editing not approved by the release.
- Import a CSS framework for one panel.
- Build a custom upload drop zone.
- Keep obsolete v1 release docs alongside revised instructions.
- Remove old interfaces before fallback testing.
- Treat a static success screen as backend integration evidence.

## Production sign-off

Complete only when:

- visual result matches accepted intent;
- data flow remains descriptor-authoritative;
- all required states exist;
- accessibility and focus/layering work;
- large-list and scan performance pass;
- fallback/rollback paths are documented;
- no mockup-only action expanded scope.
