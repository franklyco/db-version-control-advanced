# Discovery and Current-State Review

This review is mandatory before R1 and should be repeated in a smaller delta form before every later release.

## Objective

Produce an evidence-backed map of the current Visual Editor implementation and determine the smallest safe changes required for:

1. a frontend Media Manager that scans eligible live entities for empty image assignments;
2. direct image/gallery remediation through existing Visual Editor save contracts;
3. the registry-backed Brand Control Center program.

Do not begin feature implementation until this review identifies actual extension points, active uncommitted work, current test coverage, and any existing scanner or field catalog that should be reused.

## Step 1 — Establish repository state

From the DBVC repository root, record:

```bash
pwd
git status --short --branch
git log --oneline --decorate -n 15
git diff --stat
git diff --cached --stat
```

Also identify:

- the active Visual Editor branch;
- whether the August 1, 2026 snapshot branch is still relevant;
- uncommitted Visual Editor refinements;
- files changed by unrelated work;
- current PHP, WordPress, ACF, Bricks, and JavaScript support assumptions.

Do not run destructive Git commands.

## Step 2 — Inventory repository guidance and collected evidence

Search for documentation and machine-readable context before opening implementation files.

```bash
find . -maxdepth 5 -type f \
  \( -iname '*.md' -o -iname '*.json' -o -iname '*.yaml' -o -iname '*.yml' \) \
  | sort

rg -n --hidden --glob '!vendor/**' --glob '!node_modules/**' \
  'Visual Editor|Shared Globals|Media Health|Media Manager|missing image|featured image|gallery|descriptor|ACF|option page|field catalog|inventory|manifest|journal|Bricks' \
  docs context .context . 2>/dev/null
```

Prioritize:

- active implementation guides and prior drop-ins;
- architecture notes;
- field and function inventories;
- generated ACF catalogs;
- fixture inventories;
- browser QA reports;
- unresolved TODOs tied to image/gallery fields, Shared Globals, or option ownership.

## Step 3 — Map the current Visual Editor runtime

Locate and document actual files and symbols for:

### Bootstrap and mode lifecycle

- add-on registration;
- settings and enablement;
- admin-bar toggle;
- mode cookie and nonce handling;
- frontend-only and Bricks Builder exclusion checks;
- asset registration and enqueue behavior.

### Descriptor lifecycle

- marker creation;
- public token maps;
- server-side descriptor sessions;
- lazy hydration;
- session lifetime and keepalive;
- stale-value evidence;
- capability and nonce validation;
- descriptor creation for a source not currently rendered on the page, if supported.

### Existing UI surfaces

- toolbar and reserved overflow;
- Status;
- Review Fields;
- Go To Object;
- Shared Globals;
- main panel;
- shared acknowledgement;
- image and gallery editor controls;
- status refresh and scroll preservation;
- Media Library modal lifecycle and outside-click suppression.

### Existing data and save contracts

- WordPress featured-image resolver and mutation path;
- ACF image and gallery ownership for posts and terms;
- ACF ownership handling for users and options;
- nested group/repeater/flexible paths;
- raw versus formatted ACF value handling;
- attachment validation and return-format handling;
- `update_field()` and native featured-image calls;
- cache invalidation;
- DOM patch versus reload behavior;
- journal and audit calls;
- existing composite or multi-field preflight/rollback behavior.

### Tests and fixtures

- PHP unit or integration tests;
- JavaScript unit tests;
- browser/E2E tests;
- fixture builders;
- sample ACF image/gallery data;
- known gallery-marker and empty-source QA gaps.

## Step 4 — Search for existing media scanning or catalog behavior

Use actual symbols discovered in the repositories. Search conceptually for:

```bash
rg -n --hidden --glob '!vendor/**' --glob '!node_modules/**' \
  'Media Health|missing media|missing image|missing file|featured_image|_thumbnail_id|gallery|attachment exists|scan progress|scan session|batch scan|transient' .
```

Answer:

1. Does DBVC or VerticalFramework already enumerate public posts/CPTs/terms for scans?
2. Is there an existing Media Health or missing-file scanner that can supply entity traversal, progress, or result storage?
3. Does an ACF field catalog already map field groups and location rules to object types?
4. Can current descriptor factories create image/gallery descriptors for non-rendered owners?
5. Which image/gallery controls can already save for post and term owners?
6. Is there an existing request-batched job/session pattern?
7. Is there a current results-table component with sticky headers, pagination, virtualization, or row expansion?
8. How are attachment permissions and Media Library assets currently handled?
9. Can current composite save behavior support multiple dirty fields on one owner?
10. What existing code must not be duplicated?

## Step 5 — Establish eligible entity policy from current WordPress behavior

Inventory actual public content types and taxonomies on the development site.

Record at minimum:

| Object family | Candidate source | Public/live test | Edit capability test | Frontend route behavior | Initial scan status |
|---|---|---|---|---|---|
| Page | `WP_Query`/existing object service | `publish` | `edit_post` | permalink |  |
| Post | `WP_Query`/existing object service | `publish` | `edit_post` | permalink |  |
| Public CPT | discovered, not hardcoded | `publish` and policy | `edit_post` | type-specific |  |
| Public taxonomy term | existing term service | public taxonomy | `edit_term` | term link when valid |  |

Explicitly inspect exclusions such as attachments, revisions, navigation menu items, internal config CPTs, non-public taxonomies, and content types that intentionally do not use featured images.

## Step 6 — Build the Media Manager field support matrix

Use repository evidence rather than assumptions.

| Field source | Owner | Existing descriptor | Existing editor | Existing write | Empty detection | Nested path support | Tests | Decision |
|---|---|---:|---:|---:|---:|---:|---:|---|
| Native featured image | post/page/CPT |  |  |  |  | N/A |  |  |
| ACF image | post/page/CPT |  |  |  |  |  |  |  |
| ACF gallery | post/page/CPT |  |  |  |  |  |  |  |
| ACF image | term |  |  |  |  |  |  |  |
| ACF gallery | term |  |  |  |  |  |  |  |
| ACF image in group | supported owners |  |  |  |  |  |  |  |
| ACF image/gallery in existing repeater/flexible row | supported owners |  |  |  |  |  |  |  |

Record how ACF location rules, active field groups, conditional logic, field return formats, and raw empty values are represented.

## Step 7 — Inspect VerticalFramework evidence

Use this exact repository path:

```text
/Users/rhettbutler/Documents/LocalWP/frameworkflo-live/app/public/wp-content/themes/vertical
```

Begin with:

```bash
cd /Users/rhettbutler/Documents/LocalWP/frameworkflo-live/app/public/wp-content/themes/vertical
git status --short --branch
git log --oneline --decorate -n 12
find . -maxdepth 6 -type f \
  \( -iname '*.md' -o -iname '*.json' -o -iname '*.yaml' -o -iname '*.yml' \) \
  | sort
```

Search for:

```bash
rg -n --hidden --glob '!vendor/**' --glob '!node_modules/**' \
  'business_info|hours|global_links|logo|brand|CTA|site-settings|option page|acf_add_options|acf-json|field catalog|inventory|manifest|DBVC|Visual Editor|Media Health|missing file|missing image|featured image|gallery' .
```

Inspect at minimum:

- `acf-json/` field groups and location rules;
- image/gallery fields assigned to public posts, CPTs, and terms;
- field keys, names, labels, types, return formats, conditional logic, and nesting;
- any ACF catalog or generated inventory;
- existing Media Health or missing-file scanner logic;
- entity enumeration, scan progress, result storage, and admin/frontend reporting;
- option pages and fields relevant to later Brand Control Center releases;
- existing DBVC integration hooks.

Do not edit VerticalFramework during discovery.

## Step 8 — Trace current Shared Globals behavior

Search conceptually for:

```bash
rg -n --hidden --glob '!vendor/**' --glob '!node_modules/**' \
  'Shared Globals|settings_globals_default_posts|relationship|post_object|option-owned|options' .
```

Answer:

1. How are allowlisted fields configured?
2. Are registrations field-name based, field-key based, or mixed?
3. How is the option owner represented?
4. Which field families can currently be hydrated and saved when owned by options?
5. Which UI and endpoint assumptions are relationship-specific?
6. Is there already a registry-like structure?

## Step 9 — Build the ACF option support matrix

| Family | Generic editor | Options read | Options write | Nested option paths | Tests | Gaps |
|---|---:|---:|---:|---:|---:|---|
| text |  |  |  |  |  |  |
| textarea |  |  |  |  |  |  |
| url |  |  |  |  |  |  |
| email |  |  |  |  |  |  |
| number |  |  |  |  |  |  |
| range |  |  |  |  |  |  |
| wysiwyg |  |  |  |  |  |  |
| checkbox |  |  |  |  |  |  |
| select |  |  |  |  |  |  |
| radio |  |  |  |  |  |  |
| button_group |  |  |  |  |  |  |
| link |  |  |  |  |  |  |
| image |  |  |  |  |  |  |
| gallery |  |  |  |  |  |  |
| post_object |  |  |  |  |  |  |
| relationship |  |  |  |  |  |  |
| taxonomy |  |  |  |  |  |  |

`group`, repeater, and flexible content remain path/container concerns; no structural row mutation is part of this program.

## Step 10 — Measure performance constraints

Inspect or measure:

- number of eligible entities by type;
- ACF field-definition lookups per object;
- whether location-rule matching can be cached by type/taxonomy;
- raw metadata query counts;
- capability and permalink lookup counts;
- cost of scanning 25, 100, 500, and 2,000 entities where fixtures permit;
- descriptor payload size for expanded rows;
- table performance with 100, 500, and 2,000 finding groups;
- Media Library/TinyMCE asset loading timing;
- stale scan response cancellation;
- current transient/session limits and expiry behavior.

Do not optimize speculatively. Record actual constraints and design R1 around them.

## Step 11 — Produce the R0 discovery report

Before feature code, produce a concise report containing:

1. Current branch and working-tree status
2. Current Visual Editor architecture and actual extension points
3. Existing scanner, catalog, and field-enumeration evidence
4. Eligible entity and field support matrices
5. Existing image/gallery/featured-image save contracts
6. Descriptor strategy for non-rendered sources
7. VerticalFramework evidence and exact paths
8. Existing Shared Globals flow and ACF option matrix
9. Test baseline and known gaps
10. Conflicts between this package and current code
11. Smallest corrected R1 and R2 plans
12. Risks, rollback strategy, and open decisions

Update:

- `tracking/EVIDENCE-LOG.md`
- `tracking/RISK-REGISTER.md`
- `tracking/DECISION-LOG.md`
- `tracking/MEDIA-SCAN-COVERAGE-MATRIX.md`
- `tracking/IMPLEMENTATION-TRACKER.md`

## Clarification policy

Search current code, docs, inventories, fixtures, and Git history before asking the user a question. Ask only when a product choice materially changes behavior and cannot be resolved from evidence. Do not ask for class names, field paths, or implementation details that can be discovered locally.

---

## R0 findings — 2026-08-14

Status: **Discovery complete and repository-reconciled; the corrected R1 plan is documented but implementation is not authorized. No production feature implementation was started.**

### Repository and runtime baseline

- Authoritative checkout: `/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main`.
- Active branch: `codex/visual-editor-linked-posts-plan`, HEAD `5db4b4094c0d834b3cf482adb095273387b59dc8` (`5db4b40`). The branch and `origin/codex/visual-editor-linked-posts-plan` have zero divergence.
- `git status --short --branch` reported only the synchronized branch header. The checkout was clean when this package reconciliation began; no newer local work was present or discarded.
- The current branch and any newer working-tree state, not an August 1 snapshot, are the implementation authority for a future R1. This reconciliation did not reset, restore, stash, or modify production feature code.
- Verified LocalWP provenance: site `dbvc-codexchanges.local`, Local site ID `4gScrLykQ`, exact checkout above, active theme `vertical`.
- Live runtime sample: WordPress 7.0.4, PHP 8.4.4, ACF 6.6.2 supplied by the active Bricks Advanced Themer integration, Bricks 2.3.8, DBVC 1.9.1.

### Current-state architecture summary

| Concern | Current implementation | R0 conclusion |
|---|---|---|
| Bootstrap/lifecycle | `Bootstrap/Addon.php` wires capability, page/loop context, descriptor registry, resolvers, validation, mutation, journal, cache, assets, hooks, and REST routes. `FrontendRuntimeGuard` excludes builder/admin/ineligible contexts. | Reuse the existing add-on lifecycle and guard. Add Media Manager services through the same composition root. |
| Settings/policy | `addons/visual-editor/bootstrap.php` owns enablement, exclusion lists, Shared Globals names, and settings migration. Defaults exclude `bricks_template`, `template_tag`, and `template_bundle`. | Add a separately default-off Media Manager feature flag and filters here; do not create a second settings subsystem. |
| Descriptor authority | `EditableRegistry` stores user-bound transient sessions with opaque public IDs and private descriptor payloads. `ResolverRegistry` and Bricks instrumentation create descriptors from proven render context. | Existing sessions are authoritative for rendered fields, but they are not a scan-result store. Preserve descriptor authority and use a separate user/blog-bound scan snapshot. |
| Off-render descriptors | `SharedGlobalFieldsController` is the only proven toolbar/off-render precedent. It manually creates descriptors for allowlisted option-owned `relationship`/`post_object` fields and attaches them to the active session. | There is no generic safe image/gallery descriptor factory for arbitrary off-page post or term owners. R1 must not synthesize writable descriptors. R2 needs a narrow finding-to-fresh-descriptor bridge. |
| Entity discovery | `ObjectSearchController` searches public/show-UI posts and taxonomies, applies Visual Editor exclusions and capability checks, and caps results for navigation. | Reuse its eligibility/capability policy conventions, not the navigation controller or its 30-result query contract. |
| ACF applicability | ACF 6.6.2 exposes `acf_get_field_group_visibility()` and its registered/core/custom location matchers. DBVC has field normalizers in AI/content-migration domains, but none is an exact, reusable per-entity Visual Editor media catalog. | Implement a narrow Visual Editor ACF media catalog using active runtime groups plus exact location visibility. Cache definitions and type/taxonomy-stable results where evidence permits. Do not couple R1 to AI Package or Content Migration artifacts. |
| Featured image | `PostFeaturedImageResolver` reads `get_post_thumbnail_id()`, validates local image attachments, and writes with `set_post_thumbnail()`/`delete_post_thumbnail()`. | Reuse read/write semantics. R1 reports empty assignments only for supported, eligible, editable post types; it must not imply the field is required. |
| ACF image/gallery | `AcfImageResolver`, `AcfGalleryResolver`, and `AbstractAcfResolver` read raw values, normalize attachment IDs, validate local image attachments, and write through `update_field()`. | Reuse in R2 only after a fresh descriptor proves owner, field, path, value, and capability. R1 may reuse their normalization rules in a read-only scanner service. |
| Nested ACF paths | `AbstractAcfResolver` supports direct/group selectors and existing repeater/flexible rows when stable row/layout/group provenance is already present in a descriptor. Term/user/option owners are represented by canonical ACF object IDs. | Render-derived nested mutation is real; generic off-page nested discovery is not. Initial R1 should support top-level and deterministic group-only paths. Existing repeater/flexible rows require a separate proven path-enumeration slice before inclusion. No structural row creation. |
| Save/staleness | Single-field `MutationService::mutate()` rereads the old value but does not accept an expected-old value. Composite batch mutation has expected-value preflight and compensation, but its public contract is collection/composite-specific. | R2 requires a new narrow expected-empty precondition before any media write. Do not reuse the composite route as a generic Save Row contract. Defer Save Row. |
| Journal/audit/cache | Mutation flows use `ChangeJournalRecorder`, the Visual Editor journal store/tables, DBVC activity/sync logging, and owner-specific `CacheInvalidator`. | Reuse without a parallel audit log. R1 is read-only and should log only diagnostic scan lifecycle events if needed; R2 mutations must enter the existing journal/audit/cache path. |
| REST surface | Existing routes cover sessions, descriptor hydration, object/reference search, Shared Globals, collection seed, composite save, and single save. | No scan start/progress/results/cancel or row-revalidation contract exists. Add a cohesive Media Manager controller and routes in R1. |
| Frontend UI | `overlay-app.js`/`overlay.css` provide the toolbar, upward popovers, field index, search states, panel, focus/layering, loading/status messages, and `wp.media` frames. | Reuse the existing frontend shell and interaction conventions. There is no reusable large-table/pagination/virtualization component; build a scoped Media Manager view rather than importing admin React UI. |
| Asset loading | When Visual Editor mode is active, `AssetLoader` currently enqueues `wp_enqueue_editor()` and `wp_enqueue_media()` eagerly. | R1 must add no new media/editor enqueue, but the handoff assumption that those assets are absent in scan-only mode is false. Deferred asset optimization is separate from R1. |
| Tests | Focused Visual Editor PHP instrumentation and runtime/manual QA scripts exist. There is no dedicated Visual Editor JavaScript unit or browser automation harness. The repository PHP suite has 6 deterministic failures out of 684 tests, and full JavaScript lint did not complete. | Add focused PHP tests for policy/catalog/snapshot/controller contracts. Keep browser QA explicit; do not invent a broad harness inside R1. Record inherited failures versus regressions and use focused source lint/syntax checks when full lint remains incomplete. Existing save probes are not R0-safe because some mutate fixtures. |

### DBVC scanner/catalog evidence

- DBVC has no existing scanner for empty native featured-image or ACF image/gallery assignments across public entities.
- `includes/Dbvc/Media/Hydration/LibraryInventoryService.php` and related hydration manifest code inspect attachments/files, not empty owner fields.
- Content Migration's `DBVC_CC_Target_Field_Catalog_Service` recursively inventories fields for mapping artifacts, but it is domain-private, broad, partly heuristic, and explicitly does not provide safe object-specific nested-media coverage. Reuse its traversal lessons, not the runtime service.
- `includes/Dbvc/AiPackage/AcfDiscoveryService.php` normalizes runtime/local JSON definitions, including nested structures, but its location summary is package-oriented rather than exact per-object applicability. It is evidence for a possible later shared pure normalizer, not an R1 dependency.
- `EditableRegistry` has useful opaque ID, TTL, user-binding, compression, and session-touch mechanics. A Media scan snapshot should follow those conventions but stay separate because its lifecycle, paging, cancellation, site binding, and non-authoritative findings differ from descriptor sessions.

### VerticalFramework evidence

Vertical was inspected read-only on branch `Features+Tools-Enhancements`, HEAD `b565153`, with unrelated active Site Assurance, GardenAI, Context Broker, and media-tool work preserved.

- `admin/class-vf-tools-media-alt-ai.php` scans attachments for oversize, missing alt, orphan parents, and backup usage into the non-autoloaded `vf_media_health_state` option. It is attachment-centric and not a missing-assignment scanner.
- `admin/media-unused-cleanup/class-vf-media-unused-cleanup-scanner.php` builds an attachment alias/reference catalog across posts, postmeta, termmeta, and options, persists custom run/items, and rechecks selected attachments before destructive work. Its stale reinspection and bounded-run ideas are useful, but its attachment-first heuristic contract is not reusable for empty field assignments.
- `admin/media-inventory/class-vf-media-inventory-scanner.php` and its repository implement bounded filesystem directory/file batches and site-bound custom-table jobs. This is a filesystem inventory, not an entity/field scanner.
- The current `acf-json/field-groups/` contains 48 JSON groups. Direct source-JSON traversal found 129 explicit media fields: 113 image and 16 gallery; 5 top-level, 105 group-only, and 19 under repeaters. Runtime expansion on the DBVC site found 171 media definitions because active ACF resolves clone/flexible projections and runtime state.
- `functions/plugins/acf/field-context/` provides a rich semantic field catalog with key/name paths, clone context, caches, and runtime lookup helpers. It calls `acf_get_field_groups()` with filter criteria and does not replace exact `acf_get_field_group_visibility()` checks for each entity.
- `config/context-broker/acf-field-group-manifest-registry.json` is a curated 23-group agent/context registry, not an exhaustive runtime field inventory.
- `functions/plugins/acf/acf.php` registers the Site Settings options page and subpages. These registrations and the ACF JSON are later Brand Control Center evidence only; R1 excludes option-owned fields.
- No live Vertical-to-DBVC Visual Editor integration hook was found. A backup export contains DBVC option names, but backup data is not an executable integration.
- Therefore DBVC can and should ship R1 independently. Vertical artifacts may inform fixtures and terminology, never runtime availability or authority.

### Read-only runtime baseline

The R0 probe loaded the exact LocalWP site PHP/MySQL runtime and performed no writes.

| Metric | Result |
|---|---:|
| Eligible public/show-UI published post objects after current exclusions | 220 across 26 post types |
| Public/show-UI terms after current exclusions (`hide_empty=false`) | 234 across 15 taxonomies |
| Empty featured-image assignments on thumbnail-supporting objects | 101 |
| Active ACF field groups | 48 |
| Runtime media definitions | 149 image, 22 gallery |
| Runtime path shapes | 4 top-level, 105 group-only, 21 repeater, 37 flexible, 4 other nested |
| Conditional media definitions | 10 |
| Applicable post/media definition pairs | 1,582 |
| Applicable term/media definition pairs | 537 |
| Post enumeration | 189.77 ms |
| Term enumeration | 11.99 ms |
| Post group-visibility evaluation | 86.80 ms |
| Term group-visibility evaluation | 90.26 ms |
| Total discovery probe | 612.62 ms |

These timings measure enumeration and field-group applicability, not raw-value reads, finding persistence, REST transport, table rendering, or authorization filtering. They are a safe sizing baseline, not an R1 performance acceptance result.

Terms have no publish status. For R1, “live term” must mean: registered public/show-UI taxonomy, not excluded, concrete non-error term, valid frontend term link where exposed, and current user can edit the term. Posts/CPTs require `publish`, public/show-UI type, non-exclusion, and `edit_post` for the object.

### Confirmed media coverage boundary

- **R1 initial scan-supported:** native featured-image emptiness; unconditional top-level ACF image/gallery; unconditional deterministic group-only ACF image/gallery; post/page/public-CPT owners; public taxonomy-term owners after exact location and capability checks.
- **R1 report-only terminology:** “empty assignment,” not “required image” or “broken content.” Invalid non-empty attachment references must be classified separately and are outside the named initial scope unless explicitly approved.
- **R1 deferred until separately proven:** conditional fields whose active state cannot be evaluated server-side; repeater/flexible/mixed existing-row paths; option/user owners; arbitrary meta; private/draft entities; structural row mutation.
- **R2 mutation-supported only after tests:** fresh top-level/group-only descriptors for posts and current/public term owners, single-field expected-empty preflight, existing image/gallery/featured resolvers, existing journal/audit/cache, targeted reread.
- **R2 deferred:** same-row multi-save, cross-entity bulk save, conditional unknowns, new repeater/layout rows, or any finding used directly as authority.

### Corrected R1 implementation slices

1. **R1-A — policy and field catalog**
   - Add a default-off feature flag.
   - Extract/reuse Visual Editor exclusion and capability conventions.
   - Add public post/term eligibility services and an exact ACF media applicability catalog using `acf_get_field_group_visibility()`.
   - Implement raw empty-value normalization shared with the existing media resolvers; record unsupported path/conditional counts.
2. **R1-B — bounded scanner and snapshot**
   - Add user-triggered, request-batched entity traversal with explicit cursor/generation state.
   - Add a separate user- and blog-bound transient snapshot with TTL, cancel/restart, dedupe, summary counts, opaque finding references, and fail-closed ownership checks.
   - Do not create descriptors during scanning.
3. **R1-C — safe read model and row revalidation**
   - Add paged/searchable/filterable result reads.
   - Recheck entity status/capability, field applicability, and raw emptiness when a row expands.
   - Return display records plus non-authoritative opaque references; retain unsupported/stale/error states explicitly.
4. **R1-D — frontend table**
   - Add a Media Manager entry to the existing toolbar/overflow shell.
   - Build a scoped, internally scrollable, sticky-header table with search, filters, summary, paging, semantic row expansion, loading/empty/error/stale states, and keyboard/focus support.
   - No media selection or save controls in R1.
5. **R1-E — hardening and release gate**
   - Focused PHP contract tests, REST permission tests, manual authenticated frontend/browser QA, builder isolation, multi-user/session expiry, representative performance, feature-flag fallback, and documentation/agent-surface maintenance.

Static mockup work should follow the accepted R1-C read model rather than block policy/catalog work. The production view remains authoritative.

### Likely R1 files

Existing files likely to change:

- `addons/visual-editor/bootstrap.php`
- `addons/visual-editor/src/Bootstrap/Addon.php`
- `addons/visual-editor/src/Rest/Routes.php`
- `addons/visual-editor/src/Assets/AssetLoader.php` only if a new localized feature/config value is necessary
- `addons/visual-editor/assets/js/overlay-app.js`
- `addons/visual-editor/assets/css/overlay.css`
- current Visual Editor README/changelog/QA/agent docs required by maintenance rules

Likely new files, with names adaptable to current conventions at implementation time:

- `addons/visual-editor/src/MediaManager/EligibilityPolicy.php`
- `addons/visual-editor/src/MediaManager/AcfMediaFieldCatalog.php`
- `addons/visual-editor/src/MediaManager/MediaScanService.php`
- `addons/visual-editor/src/MediaManager/MediaScanSnapshotStore.php`
- `addons/visual-editor/src/Rest/Controllers/MediaManagerController.php`
- focused `tests/phpunit/VisualEditorMediaManager*Test.php` files

R2, not R1, is expected to add a finding-to-descriptor bridge and narrow expected-empty media mutation contract.

### Test strategy

- Pure/focused PHP tests for eligibility, exclusions, exact field-group visibility, raw empty normalization, path classification, finding identity, snapshot ownership/expiry/generation, paging/filtering, and no descriptor creation during R1.
- REST tests for nonce/base capability, per-object `edit_post`/`edit_term`, user/blog snapshot isolation, private/draft exclusion, stale/cancelled scans, row revalidation, and response field minimization.
- Resolver regression tests proving existing featured-image/image/gallery read semantics remain unchanged. R2 adds post/term, top-level/group-only, expected-empty conflict, journal/audit/cache, and targeted reread mutation tests.
- Authenticated browser QA for toolbar coexistence, internal scrolling, sticky header, 100/500/2,000 synthetic row rendering where practical, search/filter/paging, keyboard expansion/focus/Escape, host CSS isolation, repeated open/close, and Bricks Builder absence at supported laptop/desktop viewports. Additional touch/mobile QA is tabled by D-036.
- Performance acceptance must measure the complete scanner: raw reads, queries, chunk duration/memory, snapshot size, REST payload, cancelled/stale response behavior, and frontend render cost. The R0 612.62 ms probe is only the baseline.
- Run repository-focused syntax/unit checks and `composer agent-docs:check` whenever the new public REST/settings/add-on surfaces are implemented. Do not run mutating legacy fixture probes as routine R1 validation.

R0 validation completed:

- `vendor/bin/phpunit --filter VisualEditorElementInstrumentationTest --do-not-cache-result` — 7 tests, 15 assertions, passed.
- Repository PHP suite baseline supplied at the reconciled checkpoint — 6 deterministic failures out of 684 tests; not rerun during this documentation-only refresh and not represented as green.
- Full repository JavaScript lint baseline — did not complete; not rerun during this documentation-only refresh and no full-lint pass is claimed.
- Package SHA-256 verification — all checksum entries passed after regenerated manifest metadata.
- Package manifest validation — 45 declared content entries, 45 actual entries, zero byte/hash mismatches.
- `composer agent-docs:check` was not run because R0 added no public CLI, REST, admin, hook, setting, table, scheduled hook, add-on, or safety contract; R1 must run it when those surfaces change.

### Handoff assumptions corrected

1. The package filename `UPDATE-NOTES.md` is actually `PACKAGE-UPDATE-NOTES.md`.
2. Existing Visual Editor work is substantially ahead of the package baseline, including media/gallery resolvers, nested group/repeater/flexible mutation, option ownership, Shared Globals, composite preflight, journal/audit/cache, and current toolbar UI.
3. No reusable missing-assignment scanner exists in DBVC or Vertical. Vertical scanners are attachment/filesystem/reference systems.
4. Exact ACF applicability is available from active ACF location visibility, but neither DBVC's package catalogs nor Vertical Field Context is a drop-in per-entity matcher.
5. Existing nested support is descriptor/render-proven; it does not prove generic off-page nested scanning.
6. Current single-field save has no expected-old-value argument. Composite stale preflight is not a general Media Manager Save Row contract.
7. Media/editor assets are already eagerly loaded whenever Visual Editor mode is active.
8. Public descriptor summaries currently include non-authoritative owner identifiers for authorized users. The invariant is that IDs/findings are not mutation authority, not that no identifier may ever reach the browser.
9. Thumbnail support is not proof that a featured image is required. The current fixture would otherwise label 101 optional empties as defects.
10. Taxonomy terms are not published; their live policy must be defined through taxonomy visibility, object validity, route behavior, and capability.
11. R1 needs a separate scan snapshot, not repurposing the descriptor session as a result database.
12. The five-slice R1 sequence is now the reconciled planning baseline. No implementation authority is inferred from R0 completion or documentation adaptation; R1 still requires an explicit implementation crossing line.
