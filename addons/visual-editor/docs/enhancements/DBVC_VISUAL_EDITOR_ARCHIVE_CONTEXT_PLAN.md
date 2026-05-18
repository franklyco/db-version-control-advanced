# DBVC Visual Editor Archive Context Plan

## Purpose

Add Visual Editor support for Bricks-rendered post type archive and taxonomy archive pages without weakening the current resolver-owned save model.

This is a new implementation tranche. It should not be folded into the existing singular-page owner model by treating every archive as `entityId = 0` or by guessing from the DOM. Archive pages need an explicit page context and source-owner model because the rendered fields can be owned by different backend targets:
- current taxonomy term data
- CPT archive option fields
- global/shared option fields
- query-loop post rows
- query-loop term rows

## Current Blockers

### Singular-only activation

Historical blocker: `PageContextResolver::resolve()` only marked `is_singular()` requests as supported. That blocked:
- admin-bar toggle activation on archives
- frontend asset loading on archives
- descriptor session persistence for archive requests

Affected entry points:
- `src/Context/PageContextResolver.php`
- `src/Context/EditModeState.php`
- `src/Registry/EditableRegistry.php`

Current implementation status:
- archive-aware page context is implemented for public CPT archives, the posts archive, and taxonomy archives
- archive descriptors are intentionally inspect-only in the first slice
- save contracts for archive term/options ownership are still pending

### ACF resolver assumes a page post owner

Historical blocker: `AcfFieldContextResolver::resolve()` exited early when `page_context.entityId <= 0`.

That is correct for the current singular-only model, but archive pages need these cases:
- taxonomy archive current owner: a real term ID
- CPT archive page owner: no post ID, but direct archive content is usually option-backed
- archive query-loop owner: concrete post or term from the Bricks loop context

Current implementation status:
- the resolver now keys provider context from the page entity type instead of assuming every supported page entity is a post
- taxonomy archive ACF term fields normalize Bricks provider numeric term IDs back to taxonomy-qualified ACF object IDs such as `service_tax_507`
- archive markers are forced to readonly until the next save-contract tranche

### Scope labels are too singular-centric

`AcfFieldContextResolver::resolveScope()` compares post owners against the page post ID. Archive support needs scope labels that can distinguish:
- `current_term`
- `archive_option`
- `shared_option`
- `related_post`
- `related_term`
- `inspect_only`

The UI does not need all of those as literal scope strings, but descriptor metadata must carry enough context for accurate badge labels and warnings.

## Bricks / Sync Evidence

The `wp-content/themes/vertical/sync/db-version-control-main/bricks_template/` archive templates show four distinct source categories.

### CPT archive option fields

Examples:
- `bricks_template-archive-services-2478.json`
- `bricks_template-hero-core-archives-2499.json`
- `bricks_template-archive-service-hero-backup-4521.json`
- `bricks_template-archive-features-20781.json`
- `bricks_template-flo-archive-features-26249.json`
- `bricks_template-archive-posts-120307.json`

Observed dynamic tags:
- `{acf_services_archive_label}`
- `{acf_services_archive_title}`
- `{acf_services_archive_subheader}`
- `{acf_services_archive_intro_section_image}`
- `{acf_treatments_archive_label}`
- `{acf_treatments_archive_title}`
- `{acf_global-cpt-features_archive_intro_section_header}`
- `{acf_global-cpt-features_archives_hero_section_image}`
- `{acf_post_type_archive_names_posts:array_value|title}`
- `{acf_post_type_archive_names_posts:array_value|url}`

Runtime ACF verification confirms representative CPT archive fields resolve against `option` / `options`:
- `services_archive_title` resolves as ACF `text` on `option`
- `treatments_archive_title` resolves as ACF `text` on `option`
- `services_archive_intro_section_image` resolves as ACF `image` on `option`
- `global-cpt-features_archive_intro_section_header` resolves as ACF `text` on `option`
- `post_type_archive_names_posts` resolves as ACF `link` on `option`

Implication: CPT archive editable content should not invent a fake archive post. Direct CPT archive fields should be treated as option-backed archive fields with explicit shared/option warnings.

### Taxonomy archive term fields

Examples:
- `bricks_template-archive-taxonomy-services-2992.json`
- `bricks_template-archive-taxonomy-service-areas-120111.json`
- `bricks_template-archive-posts-120307.json`
- `bricks_template-f3-feature-terms-single-21354.json`

Observed dynamic tags:
- `{term_name}`
- `{term_description}`
- `{term_url}`
- `{term_id}`
- `{term_meta:core_tax_group_term_styles_term_color_default}`
- `{acf_core_tax_group_term_h1}`
- `{acf_core_tax_group_term_title}`
- `{acf_core_tax_group_description}`
- `{acf_core_tax_group_description_short}`
- `{acf_core_tax_group_term_image}`
- `{acf_core_tax_group_term_image_secondary}`
- `{acf_vf_sa_group_content_vf_service_area_hero_image}`
- `{acf_vf_sa_group_geometry_vf_service_area_map}`

Sync term JSON confirms these values are stored as term data and term meta under taxonomy folders such as:
- `taxonomy/category/category-seo-425.json`
- `taxonomy/service_tax/service_tax-roofing-451.json`
- `taxonomy/service_area/service_area-sheffield-lake-435.json`

Runtime ACF verification confirms representative category term fields resolve against both taxonomy-qualified and generic ACF term object IDs:
- `core_tax_group_term_h1` resolves on `category_425` and `term_425`
- `core_tax_group_term_image` resolves on `category_425` and `term_425`

Implication: taxonomy archive support should make the queried term a first-class page entity, then allow ACF term fields through the existing term capability path when the Bricks provider resolves a stable term object ID.

### Shared/global option fields inside archive templates

Archive templates also render ordinary shared option fields unrelated to the archive owner.

Examples:
- `{acf_contact_section_title_a}`
- `{acf_brand_verbiage_custom_name_appointments}`
- `{acf_office_info_phone_number_primary:array_value|value}`
- `{acf_settings_elements_breadcrumbs_separator:text}`
- `{acf_navigation_popup_fullscreen_form_type}`
- `{acf_global_layout_style_border_thickness:value}`

Implication: these should keep the existing shared-option badge/acknowledgement flow. They are not archive-specific even when rendered on an archive.

### Archive query-loop row fields

Archive templates frequently contain Bricks query loops:
- post loops using the archive main query or explicit `post_type`
- term loops using `objectType: term`
- nested post loops filtered by current term IDs

Observed row tags:
- `{post_title}`
- `{post_url}`
- `{featured_image}`
- `{acf_service_basicinfo_card_description}`
- `{acf_service_basicinfo_service_featured}`
- `{term_name}`
- `{term_url}`
- `{term_id}`
- `{acf_core_tax_group_description_short}`

Implication: once archive page context is allowed, existing concrete loop-owner support should be reused where Bricks exposes a stable post/term owner. Do not make generic main-query loops writable until owner resolution is proven through `LoopContextResolver`.

## Target Context Model

Extend page context so the renderer can describe archives without pretending they are singular posts.

### Singular post/page/CPT

Existing shape remains valid:
- `entityType: post`
- `entityId: <post_id>`
- `postType: <post_type>`
- `isSingular: true`
- `isSupported: true`

### Post type archive

Recommended page context:
- `entityType: archive`
- `entityId: 0`
- `archiveType: post_type`
- `postType: <post_type>`
- `archiveKey: post_type:<post_type>`
- `isArchive: true`
- `isPostTypeArchive: true`
- `isTaxonomyArchive: false`
- `isSupported: true`

The page owner is the archive route. Direct ACF fields should still resolve to their actual source owner, usually `option`.

### Taxonomy archive

Recommended page context:
- `entityType: term`
- `entityId: <term_id>`
- `taxonomy: <taxonomy>`
- `archiveType: term`
- `archiveKey: term:<taxonomy>:<term_id>`
- `isArchive: true`
- `isPostTypeArchive: false`
- `isTaxonomyArchive: true`
- `isSupported: true`

The page owner is the queried term. ACF term fields should be current-owner fields, not related/shared fields.

## Implementation Phases

### Phase 0: archive context resolver groundwork

Status: implemented.

Files likely involved:
- `src/Context/PageContextResolver.php`
- `src/Context/EditModeState.php`
- `src/Registry/EditableRegistry.php`
- `src/Assets/AssetLoader.php`

Steps:
1. Add archive-aware detection to `PageContextResolver::resolve()`.
2. Support `is_post_type_archive()` for public post types that Bricks can render.
3. Support taxonomy archives via `is_tax()`, `is_category()`, and `is_tag()` when `get_queried_object()` is a `WP_Term`.
4. Add stable `archiveType`, `archiveKey`, `isArchive`, `isPostTypeArchive`, and `isTaxonomyArchive` fields.
5. Keep unsupported contexts disabled: search, date, author, home/blog index without a queried post type, 404, feeds, REST, Bricks Builder mode.
6. Update current-entity statusbar link behavior:
   - term archives should link to the term editor
   - CPT/archive option-backed descriptors can link to the relevant ACF options page when the field group exposes an options-page slug

Acceptance:
- Visual Editor can be toggled on supported CPT archive and taxonomy archive URLs.
- Descriptor sessions persist on archive requests.
- Singular pages are unchanged.

### Phase 1: inspect-only archive surfacing

Status: implemented for render-verified ACF and concrete post-field candidates that already flow through the existing instrumentation model.

Files likely involved:
- `src/Bricks/AcfFieldContextResolver.php`
- `src/Bricks/ElementInstrumentationService.php`
- `src/Resolvers/ResolverRegistry.php`
- `assets/js/overlay-app.js`
- `assets/css/overlay.css`

Steps:
1. Remove the blanket `page_context.entityId > 0` requirement from ACF resolution.
2. Replace it with a page-context helper that can supply:
   - singular post owner ID
   - taxonomy term owner object ID
   - no page post owner for CPT archives
3. Let Bricks provider object resolution run for archive contexts.
4. Add inspect-only descriptors for archive fields where source owner or save contract is not yet proven.
5. Preserve exact-render verification. Do not add text-content guessing.

Acceptance:
- Archive markers appear for render-verified exact ACF tags where the owner resolves.
- Unknown archive sources show `Inspect` with an explicit reason, not silent failure.

### Phase 2: taxonomy archive current-term ACF fields

Status: initial direct-field slice implemented. Taxonomy archive pages can save direct ACF fields owned by the queried term when the descriptor resolves to the current archive term and the field is not inside an archive query loop, repeater row, flexible row, gallery collection, relationship collection, or other collection-style owner.

Files likely involved:
- `src/Bricks/AcfFieldContextResolver.php`
- `src/Resolvers/ResolverRegistry.php`
- `src/Permissions/CapabilityManager.php`
- `src/Cache/CacheInvalidator.php`
- `src/Presentation/DescriptorSummaryBuilder.php`

Steps:
1. Treat taxonomy archive page entity as the current term owner. Implemented in `src/Context/PageContextResolver.php`.
2. Accept ACF object IDs in both supported term formats. Implemented through archive term normalization in `src/Bricks/AcfFieldContextResolver.php` and the direct save gate in `src/Resolvers/ResolverRegistry.php`:
   - `<taxonomy>_<term_id>`
   - `term_<term_id>` if the Bricks provider emits it
3. Update entity mapping if needed so `term_123` can map to `type=term` using `get_term(123)`. Implemented.
4. Classify matching term-owned ACF fields as current archive term fields. Implemented for direct text-like, choice, link, WYSIWYG, and image fields.
5. Keep term-loop descendants separate from the queried archive term. Current implementation keeps all active archive loop descendants inspect-only for this phase.

Acceptance:
- Direct ACF fields rendered from the queried taxonomy term can be edited on taxonomy archive pages.
- Badge/outline should read as current archive term or normal `Edit`, not `Related Term`, when the owner is the queried term.
- Cache invalidation calls `clean_term_cache()` for saved term fields through the existing `CacheInvalidator`.
- Repeater/flexible rows, active query-loop descendants, relationship/post-object/taxonomy collection fields, and galleries remain inspect-only until their dedicated contracts are enabled.

### Phase 3: archive option-backed fields

Status: initial direct-field slice implemented and broadened. Post type archives and taxonomy archives can save direct option-backed ACF fields when the descriptor resolves to the shared ACF options owner and the field is outside active archive query loops, repeater rows, flexible rows, gallery collections, relationship collections, or other collection-style owners. These fields use the existing shared-field acknowledgement and `manage_options` capability path.

Discovery model:
- Do not hardcode field name prefixes such as `services_archive_*` or `global-cpt-features_*`.
- Detect option-backed ACF fields from the resolved ACF owner (`option`, `options`, or page-specific `options_<slug>` style IDs).
- Detect the ACF field group options-page location from ACF field group rules (`options_page` / `options_page_key`) and add field-group/options-page metadata to descriptor source summaries.
- If an archive route causes Bricks to ask ACF for the current term object first, normalize fields that belong to options-page field groups back to the options owner before classification.

Files likely involved:
- `src/Bricks/AcfFieldContextResolver.php`
- `src/Resolvers/ResolverRegistry.php`
- `src/Presentation/DescriptorSummaryBuilder.php`
- `assets/js/overlay-app.js`

Steps:
1. Allow direct option-backed ACF fields to resolve on CPT and taxonomy archive pages. Implemented for descriptors resolving to canonical ACF `option`/`options` and page-specific options object IDs such as `options_<slug>`.
2. Add archive-specific summary metadata when an option field is rendered from a CPT archive route:
   - `page.archiveType = post_type`
   - `page.postType = service|treatment|feature|post`
   - `owner.type = option`
   - `sourceContext = archive_option`
3. Keep the existing option capability and acknowledgement requirement. Implemented through the existing `shared_field` contract.
4. Prefer badge copy such as `Archive Option` or `Shared Option` only if it can be done without introducing a new scope branch that bypasses existing safeguards.
5. Keep repeater/flexible option rows, active archive query-loop descendants, relationship/post-object/taxonomy collection fields, and galleries inspect-only until their dedicated contracts are enabled.

Acceptance:
- `{acf_services_archive_title}`, `{acf_global-cpt-features_archive_*}`, and similar option-backed archive fields can be edited on CPT and taxonomy archives when they resolve to ACF options.
- Panel copy clearly says the value is stored in ACF options and may affect other templates/contexts.
- Source details show the field group/options-page slug when ACF exposes it.
- Save requires the existing shared-field acknowledgement, and cache invalidation clears options/alloptions through the existing `CacheInvalidator`.

### Phase 4: native term fields and Bricks archive tags

Status: initial queried-term slice implemented and broadened to concrete term-loop descendants beyond archive-only contexts. `{term_name}` and `{term_description}` are detected as native `term_field` sources on taxonomy archive pages and save through `src/Resolvers/TermFieldResolver.php` using `wp_update_term()` for the queried archive term. When a Bricks query loop exposes a concrete term owner, those same native term fields use the loop-owned related save path with acknowledgement on archive and non-archive pages/templates. Derived native values now surface inspect-only through `src/Resolvers/NativeReadonlyResolver.php`.

Files likely involved:
- `src/Resolvers/TermFieldResolver.php`
- `src/Resolvers/ResolverRegistry.php`
- `src/Bricks/DynamicDataInspector.php`
- `src/Bricks/ElementInstrumentationService.php`

Candidate fields:
- `{term_name}`
- `{term_description}`
- `{term_url}` inspect-only unless slug/permalink editing is deliberately supported
- `{archive_title}` inspect-only or derived
- `{term_meta:*}` inspect-first, then writable only for scalar term meta with a safe contract

Steps:
1. Keep `{archive_title}` inspect-only because it is derived presentation.
2. Add a dedicated term resolver for `term_name` and `term_description`. Implemented for the queried taxonomy archive term and concrete Bricks term-loop owners, including non-archive page/template loops.
3. Treat `term_url` as read-only unless a dedicated slug editor is designed. Implemented as inspect-only.
4. Treat `term_meta:*` separately from ACF term fields; do not write arbitrary term meta without an allowlist.
5. Treat `{term_id}`, `{post_url}`, and `{archive_title}` as inspect-only derived values. Implemented for exact single-tag render bindings and direct link-control URL bindings for `post_url` / `term_url`.

Acceptance:
- Native term title/description support is explicit and auditable.
- Derived archive titles do not pretend to be writable fields.
- Saves use the existing term capability check and term cache invalidation path.
- Derived native tags show readonly panel messaging and do not create save contracts.

### Phase 5: archive query-loop descendants

Status: initial concrete-owner slice implemented. Archive page descendants no longer become read-only solely because the page route is an archive when the descriptor resolves to the same concrete loop owner exported by `LoopContextResolver`. This reuses the existing loop-owned field, repeater row, flexible layout, post title/excerpt/featured image, ACF term/post/user field, and related acknowledgement contracts. Generic/non-concrete loop rows remain inspect-only.

Files likely involved:
- `src/Bricks/LoopContextResolver.php`
- `src/Bricks/NativeAcfQueryResolver.php`
- `src/Bricks/AcfFieldContextResolver.php`
- `src/Resolvers/ResolverRegistry.php`

Steps:
1. Reuse existing concrete loop-owner handling after archive page context is enabled.
   - Implemented for concrete post owners and concrete term owners.
   - ACF descriptors add source context such as `archive_loop_post`, `archive_loop_term`, or non-archive `loop_term` for panel/debug summaries.
2. Validate Bricks loop owner resolution for:
   - post loops on CPT archives
   - post loops filtered by current taxonomy term
   - term loops inside taxonomy archive templates
3. Keep generic main-query loops inspect-only until the loop context proves a concrete owner per row. Implemented by requiring exported `has_concrete_owner` and matching descriptor entity.
4. Keep related-owner acknowledgement for loop-owned saves. Implemented through existing `loop_owned_*` save contracts.

Acceptance:
- Post-loop row fields on archive pages behave like existing related post loop rows.
- Term-loop row fields behave like existing related term rows where save contracts already exist.

### Phase 6: QA matrix

Required test URLs / templates:
- CPT archive: `service` using `Archive-Services`
- CPT archive: `treatment` using shared service/treatment archive templates
- CPT archive: `feature` using `Archive-Features` or `FLO-Archive-Features`
- post archive/category archive using `Archive-Posts`
- taxonomy archive: `service_tax`
- taxonomy archive: `treatment_tax`
- taxonomy archive: `service_area`
- taxonomy archive: `feature_tax`

Required assertions:
- toggle appears only on supported archive contexts
- builder mode remains disabled
- session persists and hydrates descriptors on archives
- exact ACF option fields show as shared/archive option fields
- exact ACF term fields on queried term show as current term fields
- query-loop descendants remain owner-specific
- save succeeds only after capability and acknowledgement checks
- cache invalidation is correct for option, post, and term owners
- unsupported derived tags show inspect-only messaging

## Concrete Code Path

Recommended order:
1. Expand `PageContextResolver` first. This is the switch that enables all archive testing without changing write behavior.
2. Add archive page metadata to descriptor V2 payloads and summaries before widening save behavior.
3. Loosen `AcfFieldContextResolver` from singular-only to archive-aware object resolution.
4. Add `term_123` mapping support if runtime provider output requires it.
5. Ship inspect-only archive markers first.
6. Enable taxonomy archive ACF term saves.
7. Enable archive option-backed ACF saves with explicit option warning and options-page field-group discovery.
8. Add native term field resolver only after ACF term fields are stable.
9. Re-run archive query-loop row tests and widen only the cases that already have concrete owners.

## Risks / Guardrails

- Do not infer CPT archive owner from `get_the_ID()`; on archives this may be a loop row or preview artifact.
- Do not treat a post type archive as a writable post.
- Do not bypass option warnings for CPT archive fields just because the route feels current to the user.
- Do not add broad arbitrary `term_meta:*` writes in the same tranche as ACF term fields.
- Do not use DOM text matching to decide archive ownership.
- Keep search/date/author archives out of scope until there is a real owner model.
- Keep Bricks Builder mode disabled exactly as it is today.

## Deep-Dive Verification Summary

Current understanding is concrete enough to implement:
- the activation failure is caused by singular-only page context
- taxonomy archives have a real current owner: the queried `WP_Term`
- CPT archive content in the reviewed templates is option-backed, not post-backed
- shared/global option fields must stay shared even when rendered on archives
- archive query loops should reuse the existing concrete loop-owner machinery instead of getting a separate archive-specific save path

Implemented first slice:
- archive context support
- taxonomy archive ACF object normalization
- readonly archive descriptor classification
- archive metadata in descriptor page/source payloads
- direct queried-term ACF saves on taxonomy archives
- direct option-backed ACF saves on CPT and taxonomy archives through shared-field acknowledgement
- options-page field group metadata in source details
- native queried-term `{term_name}` and `{term_description}` saves through the dedicated term resolver
- concrete archive loop-owner descendants only use existing loop-owned contracts, with non-concrete loops still inspect-only
- derived native tags such as `{post_url}`, `{term_url}`, `{term_id}`, and `{archive_title}` surface through readonly descriptors only

Next implementation slice:
- runtime test derived readonly tags in archive templates and link controls
- keep broad `{term_meta:*}` and non-concrete archive query-loop descendants out of save scope until dedicated resolvers/contracts exist
