# Media Manager Field Eligibility and Scan Scope

## Purpose

Define what R1 may scan and report. The server—not the browser—determines entity and field eligibility.

## Default entity scope

### Included post-like objects

An object is eligible only when all applicable checks pass:

- It is a page, post, or discovered post type allowed by current DBVC policy.
- The post type is public/publicly relevant or explicitly approved by an existing plugin filter/provider.
- The object is in the live status used by the site, normally `publish`.
- The current user may edit the specific object.
- The object is not an attachment, revision, navigation menu item, autosave, internal configuration object, or another excluded type.

Do not hardcode VerticalFramework CPT names into DBVC core.

### Included terms

A term is eligible only when:

- its taxonomy is public/publicly relevant or explicitly approved;
- its taxonomy follows current public/show-UI and exclusion policy;
- the current user may edit the term;
- the taxonomy has applicable supported ACF image/gallery fields;
- the term has not been deleted, its owner representation is supported, and any exposed frontend route resolves safely.

Terms do not have a WordPress-native featured image in core. Only their applicable ACF fields are scanned unless the current project has an explicit native-like term-image adapter already proven.

### Excluded in v1

- users;
- ACF options/global owners;
- comments;
- attachments as target entities;
- drafts, pending, scheduled, private, trash, revisions, and autosaves;
- non-public taxonomies and internal objects;
- archives without a concrete owner;
- remote/headless records not represented by current DBVC owners.

A later product decision may broaden statuses or owners, but R1 must not assume it.

## Native featured-image eligibility

Report a missing native featured image only when:

- the owner is a supported post-like object;
- the post type supports thumbnails under current WordPress/theme configuration;
- the field is currently empty;
- the current user can edit the post;
- the post type is not excluded by current policy/provider evidence.

A post type supporting thumbnails does not prove that every object semantically requires an image. The initial product direction is to surface empty supported fields, so the UI should label findings as missing assignments rather than asserting content is invalid. Where an existing Site Assurance or field-policy provider distinguishes required/recommended/optional, preserve that as supplemental metadata without making it a dependency.

## ACF field eligibility

An ACF field is eligible only when all checks pass:

1. ACF is available and the field definition resolves by server-side identity.
2. The field belongs to an active field group applicable to the exact owner under current location rules.
3. The field type is `image` or `gallery`.
4. The owner type and path are already supported by the Visual Editor descriptor/mutation model.
5. The field is not disabled by an existing DBVC exclusion rule or provider.
6. The raw stored value represents an empty assignment.
7. The current user can edit the owner and may use the field contract.

Do not scan arbitrary meta keys that happen to contain attachment IDs or image URLs.

## Empty-value rules

Use raw/canonical storage evidence rather than rendered output equality.

### Featured image

Empty when the native thumbnail relationship is absent or resolves to no valid assigned ID according to the existing featured-image resolver.

### ACF image

Empty when the canonical raw value is absent/zero/empty according to the current ACF image contract. Do not rely on a formatted URL/array alone.

### ACF gallery

Empty when the canonical ordered attachment collection has no assigned IDs. Preserve the distinction between an empty gallery and a gallery containing an invalid attachment; invalid attachments belong to broader Media Health unless current code already classifies them safely.

## Nested field paths

### Direct and group fields

Initial R1 supports unconditional direct image/gallery fields and deterministic group-only descendants when active runtime definitions, exact location visibility, canonical owner, and group ancestry are all proven server-side.

### Existing repeater/flexible rows

Repeater, flexible-content, and mixed nested ancestry are deferred from initial R1. A later slice may include an existing-row media subfield only when:

- the parent field and row/layout path currently exist;
- the current Visual Editor can reconstruct and validate that exact path;
- scanning does not invent rows that are not stored;
- the field can be hydrated without relying on a rendered loop index alone.

Do not report fields in nonexistent rows or layouts. Do not create, delete, duplicate, or reorder rows.

### Conditional logic

Inspect current ACF APIs and project patterns during R0.

Initial R1 excludes and counts fields with conditional logic rather than attempting to determine an actionable empty assignment. A later slice may reconsider a field only after a server-side evaluator is proven against the exact owner/path and stale-state behavior.

Do not implement a broad custom ACF conditional-logic engine solely for R1.

## Field definitions and catalogs

R0 found no compatible per-entity media catalog. Build the narrow Visual Editor catalog from active runtime ACF definitions plus `acf_get_field_group_visibility()` for the exact owner screen. DBVC/Vertical catalogs may supply normalization lessons, fixture evidence, and friendly labels, but they are not runtime applicability authority or required dependencies.

Avoid calling expensive full field-object APIs independently for every entity when definitions can be grouped by post type, page template, taxonomy, or other stable location dimensions.

## Provider and filter extension points

Use existing extension conventions. A narrow provider/filter may allow integrations to:

- include or exclude post types/taxonomies;
- include or exclude specific field definitions;
- provide a friendly group/context label;
- supply required/recommended/optional metadata;
- reuse existing Media Health scan evidence.

Provider output is validated and never bypasses descriptor/capability checks.

Do not add a complex admin settings UI for field policy unless R0 proves one already exists or it is required to prevent unusable scan noise.

## Capability policy

At list time, hide objects the current user cannot safely know or edit according to current DBVC policy.

At row expansion and save time, repeat object-specific checks:

- post-like owners: current `edit_post`/mapped capability behavior;
- terms: current `edit_term`/taxonomy capability behavior;
- media upload: `upload_files` only governs upload availability, not object edit authority;
- attachment assignment: validate selected attachments using the existing field contract.

Do not rely only on the Visual Editor base capability.

## Scope acceptance criteria

- No arbitrary meta scanning is possible.
- No private or unauthorized entity title is disclosed.
- Post types and taxonomies are discovered and policy-filtered rather than hardcoded.
- Featured-image findings appear only for eligible post types.
- ACF findings map to active applicable field definitions.
- Initial nested findings are limited to deterministic group-only paths.
- Repeater/flexible/mixed and conditional paths are excluded/countable and fail closed.
- The initial scan remains limited to image and gallery assignments.
