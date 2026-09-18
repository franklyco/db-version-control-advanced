# Frontend Media Manager — Product Specification

## Product statement

The Frontend Media Manager is a Visual Editor workspace that helps authorized users find and repair missing image assignments across live website content without repeatedly navigating to separate WordPress admin screens.

It is a frontend operational tool layered over the rendered site. It does not replace the WordPress Media Library, edit arbitrary metadata, or become a general Media Health suite.

## Primary user problem

A client or agency editor may have dozens of published pages, posts, CPT records, and taxonomy terms with one or more empty image fields. Today, they must discover those omissions manually, determine which backend object owns each field, open the correct editor, assign media, save, and return to the site.

The Media Manager consolidates that workflow into one searchable, filterable, scrollable table:

1. Run or refresh a scan.
2. Review every eligible live entity with missing image assignments.
3. Filter by entity type or field family.
4. Expand one entity.
5. Assign images through the native Media Library.
6. Save and verify the exact fields.
7. Remove resolved findings from the active result set.

## Primary personas

- Client content editor
- Agency content loader
- Site launch/onboarding specialist
- Agency quality-assurance reviewer
- Site administrator

## User-facing entry point

Preferred initial entry:

- the existing reserved/overflow Visual Editor control, if current architecture supports enabling it safely;
- otherwise the smallest current toolbar/popover entry consistent with existing patterns.

The eventual R6 Site Manager Workspace should expose Media Manager in its Review or Media section while retaining the earlier entry as a fallback during rollout.

Do not add a permanent toolbar button merely because the concept image shows room for one.

## Primary UI

A large popup, panel, or drawer over the live frontend with:

- title and scan summary;
- search;
- entity-type filters;
- field-family filters;
- published/live scope indicator;
- refresh scan action;
- internally scrollable table with sticky controls/header;
- compact entity rows;
- expandable nested field rows;
- native Media Library actions;
- save/revalidation feedback.

The table remains the dominant visual and interaction surface.

## Entity row model

Each collapsed row summarizes one entity:

- selection checkbox only if it has a valid release-scoped purpose;
- entity label;
- object type label;
- frontend location when permitted;
- number of missing fields;
- last modified or last scanned metadata;
- expand/open action;
- optional frontend/backend link according to current permissions.

A row expands through an explicit button. Do not make the entire row an inaccessible click target.

## Expanded row model

An expanded entity shows one field row per current missing assignment:

- user-facing field label;
- source family: featured image, ACF image, or ACF gallery;
- optional safe context such as parent group/layout label;
- current empty state;
- placeholder/preview area;
- `Choose Media` or `Manage Gallery`;
- upload access through WordPress Media Library when permitted;
- field-level save state;
- row-level save only when a safe same-owner coordinator is proven.

The user should not need to know an ACF key, meta key, owner ID, or nested storage path.

## Core workflows

### Workflow A — Run scan

1. User opens Media Manager.
2. The panel displays a previous valid snapshot or an unscanned state.
3. User starts or refreshes the scan.
4. The UI shows progress without blocking the site.
5. Results become available progressively or after the bounded scan completes.
6. Summary counts and filters update.

### Workflow B — Inspect findings

1. User searches or filters the table.
2. User expands an entity row.
3. The server rechecks the entity and its fields.
4. The row displays only findings still applicable to the user and source.
5. Changed/resolved/unavailable findings are identified and removed or marked appropriately.

### Workflow C — Assign image

1. User selects `Choose Media` or `Upload New`.
2. The native WordPress Media Library opens with the correct single/multiple image mode.
3. User chooses or uploads a permitted local image attachment.
4. The selection appears as an unsaved preview in the expanded row.
5. The interface clearly distinguishes draft selection from saved content.

### Workflow D — Save and verify

1. User saves one field.
2. The server performs fresh nonce, capability, descriptor, validation, and stale-value checks.
3. The assignment is written through the existing mutation contract.
4. DBVC journals/audits the mutation and clears relevant caches.
5. The exact finding is re-evaluated.
6. Resolved fields disappear from the row; an entity disappears when no findings remain.
7. A failed field save remains visible with an explicit error and no false resolved state.

## Scan summary and filters

Recommended summary data:

- eligible entities scanned;
- entities requiring attention;
- total missing assignments;
- featured-image count;
- ACF image count;
- ACF gallery count;
- scan completion/time/state.

Recommended filters:

- search by safe entity title/name;
- Pages;
- Posts;
- discovered public CPT labels;
- Terms;
- Featured Image;
- ACF Image;
- Gallery.

Do not create a complex saved-query system in the initial release.

## Table behavior

- The panel has a bounded internal scroll area.
- Header/filter controls remain available while rows scroll.
- Result retrieval is server-paginated, cursor-based, progressively loaded, or virtualized according to current architecture.
- The complete scan set is navigable; the browser does not need to render every row simultaneously.
- Expanding a row lazily retrieves fresh field details.
- Passive scan/status updates preserve table and expanded-row context where practical.
- Search requests cancel or ignore stale responses.

## Field support

### R1 scan/report

- Native featured image
- ACF image
- ACF gallery
- Supported post/page/CPT owners
- Supported taxonomy-term owners
- Unconditional top-level and deterministic group-only ACF paths in initial R1/R2

### R2 remediation

- Existing featured-image assignment contract
- Existing ACF image assignment contract
- Existing ACF gallery replacement contract
- Native Media Library selection/upload
- Current cache, journal, and reload/DOM update behavior

## Finding states

At minimum:

- Scanning
- Empty assignment
- Loading field details
- Writable
- Inspect only
- Changed since scan
- Resolved elsewhere
- Owner unavailable
- Permission changed
- Unsupported path/configuration
- Save in progress
- Saved and verified
- Save failed
- Partial row failure

## Efficiency goals

- No need to navigate to each backend editor.
- No full descriptor hydration for collapsed rows.
- No full-site scan during normal frontend page loads.
- One panel can process many entities sequentially.
- Search/filter state remains stable while fixing rows.
- Resolved rows disappear or visibly update without a full-page reload.

## Production non-goals

- Cross-entity bulk mutation
- Automatically filling missing fields
- Arbitrary metadata scanning
- Static Bricks image/background editing
- ACF file, video, or oEmbed fields
- Media optimization, alt text, file existence, duplicate, orphan, or usage analysis
- Attachment deletion
- Repeater/flexible structural operations
- Draft/private content workflow
- Creating content objects
- Custom media upload service

## Product-level acceptance criteria

- An authorized user can run a bounded scan from the frontend.
- Only eligible, permitted live entities appear.
- Every finding identifies an actual supported image-assignment source.
- The result set is searchable, filterable, and usable at large counts.
- Rows expand without loading all field descriptors in advance.
- Image/gallery assignments use the native Media Library.
- Saves use existing descriptor-authoritative contracts.
- A field populated after the scan is never silently overwritten.
- Successful updates are journaled, caches are invalidated, and findings are revalidated.
- Disabling Media Manager leaves existing Visual Editor behavior intact.
