# Target Architecture and Boundaries

This document describes conceptual responsibilities. Codex must map them onto current DBVC abstractions rather than creating these names verbatim.

## Product boundary

The target is a **source-aware frontend site-management workspace**. It is not a page builder, generic metadata editor, or replacement for WordPress Media Library.

The two primary flows are distinct but share the same descriptor and mutation authority.

### Media Manager flow

```text
Eligible public objects + ACF definitions
        ↓
Bounded scan coordinator
        ↓
User-bound scan snapshot / finding read model
        ↓
Scrollable Media Manager table
        ↓
Expand one entity and request fresh field descriptors
        ↓
Native WordPress Media Library selection/upload
        ↓
Existing Visual Editor image/gallery/featured-image contracts
        ↓
Journal, cache invalidation, targeted revalidation, UI update
```

### Brand Control Center flow

```text
VerticalFramework or other providers
    register approved controls and metadata
                 ↓
DBVC control registry
    validates, normalizes, filters, and discovers
                 ↓
Global & Brand Control Center
    lists controls and requests descriptors lazily
                 ↓
Existing Visual Editor descriptor + panel system
                 ↓
Existing mutation, cache, journal, and audit systems
```

Neither a scan finding nor a registry record contains mutation authority.

## Reconciled current extension points

The following mapping is authoritative for planning as of DBVC commit `5db4b40`; re-verify symbols before implementation:

| Responsibility | Current evidence | R1/R2 adaptation |
|---|---|---|
| Composition/runtime guard | `Bootstrap/Addon.php`; `FrontendRuntimeGuard` | Wire additive Media Manager services/routes through the existing composition root and preserve Builder exclusion. |
| Entity policy precedent | `ObjectSearchController`; Visual Editor exclusion/capability settings | Reuse policy conventions, not its capped navigation query as a scanner. |
| Descriptor authority | `EditableRegistry`; `ResolverRegistry`; render instrumentation | Keep descriptor sessions separate from non-authoritative scan snapshots. R1 creates no writable descriptor. |
| Off-render precedent | `SharedGlobalFieldsController` | Narrow, option-owned relationship/post-object precedent only; not a generic media descriptor factory. |
| Media reads/writes | `PostFeaturedImageResolver`; `AcfImageResolver`; `AcfGalleryResolver`; `AbstractAcfResolver` | Reuse canonical empty/value normalization in R1 and fresh descriptors plus existing mutations in R2. |
| Mutation/stale handling | `MutationService`; `CompositeSaveController` | Single save lacks expected-old input; composite preflight is collection-specific. Add an expected-empty bridge in R2 and defer `Save Row`. |
| Journal/cache/audit | `ChangeJournalRecorder`; journal store; `CacheInvalidator`; DBVC activity/sync logging | Reuse these paths for R2; do not add a parallel mutation log. |
| REST | `Rest/Routes.php` and existing controllers | A cohesive Media Manager route/controller family is new; scan references must never become mutation targets. |
| Frontend shell/assets | `assets/js/overlay-app.js`; `assets/css/overlay.css`; `AssetLoader` | Extend the current vanilla shell. No data-grid exists. Active mode already enqueues editor/media assets; R1 adds no new enqueue. |

No existing DBVC or Vertical service can be reused wholesale for empty owner-field scanning. R1 therefore adds the smallest request-batched coordinator and a separate ephemeral user/blog-bound snapshot while reusing established capability, transient, REST, and UI conventions.

## Responsibility split

| Concern | Recommended owner |
|---|---|
| Media scan orchestration and read model | DBVC Visual Editor |
| Generic eligible-object policy | DBVC Visual Editor using WordPress APIs and current plugin policy |
| ACF field definition and location evaluation | Existing ACF/DBVC catalog or smallest reusable DBVC adapter |
| Existing Media Health/missing-file evidence | VerticalFramework provider or shared adapter if already present |
| Missing image-assignment finding logic | DBVC Visual Editor |
| Native Media Library selection/upload | WordPress core media modal |
| Authoritative descriptors | Existing DBVC Visual Editor descriptor system |
| Image/gallery/featured-image mutations | Existing DBVC resolvers and adapters |
| Registry contract and validation | DBVC Visual Editor |
| Site-specific global control definitions | VerticalFramework or another provider |
| Journaling and auditing | Existing DBVC systems |
| Cache invalidation | Existing DBVC systems |
| Frontend workspace shell | DBVC Visual Editor |

## Media Manager conceptual components

Use existing classes/services where possible. These are responsibilities, not mandated class names.

### 1. Eligible object provider

Produces a bounded list of candidate entities after server-side filtering for:

- supported object family;
- live/public state;
- current-user edit authority;
- post type or taxonomy policy;
- exclusions such as attachments, revisions, nav menu items, and internal types.

It must not preload full ACF descriptors or attachment metadata.

### 2. Applicable field catalog

Determines which supported image fields apply to an entity using:

- native featured-image support;
- active ACF field groups;
- current ACF location rules;
- field type and nested-path support;
- current exclusion hooks/policy if one already exists.

It must never accept a browser-supplied field key as authority.

### 3. Scan coordinator

Processes candidates in bounded chunks. R0 found no compatible scanner or job/session store, so implement the smallest user-triggered request-batched flow that:

- starts or refreshes a scan;
- records progress;
- accumulates compact findings;
- allows the client to request the next batch;
- expires safely;
- can be canceled or abandoned without leaving persistent jobs.

Do not introduce a permanent custom table or background daemon unless discovery proves current scale requires it and an existing DBVC pattern supports it.

### 4. Scan snapshot and finding read model

A compact, user-bound result snapshot can contain safe display metadata and opaque references. It should support:

- entity counts;
- finding counts by family;
- filters and search;
- sorting/pagination;
- scan progress/state;
- stale/invalidated state;
- a safe route to hydrate one expanded entity.

The snapshot is not the source of truth for mutation.

### 5. Descriptor bridge

When an entity row expands, the server:

1. rechecks the entity and current user;
2. re-evaluates applicable fields;
3. rechecks whether each field is still empty;
4. issues or hydrates standard Visual Editor descriptors for supported findings;
5. marks unsupported or changed findings honestly.

This is the bridge from scan evidence to existing field editors and save contracts.

### 6. Media modal adapter

Reuse current WordPress Media Library integration:

- single image selection for featured image and ACF image;
- multiple ordered image selection for gallery;
- local image attachment validation;
- upload tab only for users with WordPress upload permission;
- focus and outside-click behavior compatible with the Media Manager panel.

Do not build a custom upload endpoint.

### 7. Targeted revalidator

After a successful assignment, reread the exact owner/field and determine whether the finding is resolved. Update the snapshot and counters only after verification.

## Media Manager safe finding record

The exact runtime shape must follow existing conventions. A list-level record may conceptually include:

| Property | Purpose |
|---|---|
| Opaque finding-group reference | Locate a server-side scan entry without exposing a storage path |
| Entity label | Safe display title/name |
| Entity family/type label | Page, post, CPT label, or term taxonomy label |
| Permitted frontend route | Optional, capability-filtered |
| Missing count | Number of findings in the scan snapshot |
| Finding-family counts | Featured image, ACF image, gallery |
| Updated timestamp | Safe display metadata |
| Scan status | Current, scanning, changed, unavailable, or error |
| Available actions | Expand, open frontend, refresh finding; no mutation payload |

An expanded field record may add:

| Property | Purpose |
|---|---|
| Opaque field/finding reference | Request fresh descriptor hydration |
| Display label | Human-readable field label |
| Family | Featured image, ACF image, or gallery |
| Safe path/context label | Optional human context; never authority |
| Empty-state summary | No image assigned or zero images |
| Descriptor status | Writable, inspect-only, changed, unavailable |
| Available actions | Choose media, upload via core modal, manage gallery, save |

Do not serialize raw owner IDs, field keys, nested row paths, option keys, or nonce material merely to make client wiring easier. If current UI already exposes some source details to privileged users, keep them separate from authoritative action payloads.

## Brand Control Registry contract

The registry remains narrow, declarative, allowlisted, and provider-aware. A normalized control may represent:

| Attribute | Purpose |
|---|---|
| Stable provider-scoped control ID | Discovery identity, not mutation target |
| Label and description | Client-facing context |
| Category and group | Organization |
| Source type and owner hint | Server-side descriptor resolution input |
| Field family | UI/status hint |
| Scope | Current/shared/related/global semantics |
| Required capability | Visibility hint; enforced again on action |
| Acknowledgement level | Existing shared/related behavior |
| Preview capability | Declared, not assumed |
| Usage provider | Optional future evidence |
| Mutation adapter identity | Server-owned resolver mapping |
| Reversibility status | Honest operational status |

Registry membership does not create write authority.

## Frontend panel architecture

Media Manager, Brand Control Center, and Site Manager Workspace should share current Visual Editor interaction and styling patterns where practical:

- one scoped root;
- consistent layering/z-index policy;
- toolbar entry and fallback;
- internal loading/error status renderer;
- shared search/filter primitives if they already exist;
- bounded scroll regions;
- focus restoration;
- compatibility with the movable main editor panel and Media Library modal.

Do not create a new frontend framework solely for these panels.

## Persistence guidance

### Media scan snapshots

Use a separate small expiring snapshot keyed and validated by blog, user, opaque scan reference, and generation. Follow useful `EditableRegistry` conventions for transient lifetime and opaque identifiers without storing findings in the descriptor registry or inheriting its descriptor-session lifecycle.

Do not add a custom database table for R1 without measured necessity. Store only compact finding metadata; authoritative field descriptors remain in their existing session system.

### UI state

Use current session/local storage conventions for panel open state, filters, and scroll position when safe. UI persistence must not preserve mutation authority.

### Registry

Prefer runtime registration and existing settings compatibility. Avoid mandatory migrations if current Shared Globals configuration can be adapted safely.

## Request and mutation boundaries

Conceptually separate:

1. scan start/refresh;
2. scan progress/next chunk;
3. result list/filter/page;
4. entity-row hydration;
5. standard descriptor save;
6. targeted revalidation.

Actual endpoint or action names must follow the repository. Do not combine scan listing and arbitrary field mutation into one permissive handler.

## Cross-repository boundary

DBVC must work without VerticalFramework.

VerticalFramework may provide:

- field catalogs;
- media eligibility/exclusion metadata;
- existing scan results;
- brand-control registrations;
- labels/categories.

DBVC must validate provider output and fail closed. Do not directly couple to a LocalWP path or hardcode Vertical-specific field names in DBVC core.

## Explicitly deferred architecture

Do not add in R1–R6:

- generalized workflow/queue tables;
- cross-entity transaction orchestration;
- attachment deletion or replacement propagation;
- arbitrary meta discovery;
- Bricks JSON mutation;
- design-token adapters;
- multi-site synchronization;
- real-time collaboration;
- AI correction services.

## Architectural acceptance criteria

- Media findings and registry records remain non-authoritative.
- Every edit reaches the existing descriptor/resolver/mutation pipeline.
- Scanning is user-triggered, bounded, and does not block ordinary frontend loads.
- Result tables can represent the complete scan through pagination/virtualization without rendering all rows at once.
- Unsupported or stale findings fail closed.
- WordPress core handles upload and media selection.
- DBVC remains functional when VerticalFramework providers are absent.
- Feature-level rollback restores prior Visual Editor behavior without content migration.
