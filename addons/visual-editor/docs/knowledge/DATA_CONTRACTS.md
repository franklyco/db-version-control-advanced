# Data Contracts

## DOM marker contract

Example:
`data-dbvc-ve="ve_4f8a1d"`

Rules:
- token only
- no raw meta key
- no raw field key unless separately justified
- no direct client-authoritative save target
- optional non-sensitive render metadata such as `data-dbvc-ve-context="text"` or `data-dbvc-ve-context="link_href"` is allowed when needed so the overlay can compare and update the correct rendered projection
- non-sensitive loop ownership details may live in the server-side descriptor payload, but not in public DOM save targets

## Media Manager R1-C read contract

Media Manager scan state remains server-owned in the separate scan snapshot store. The protected REST surface is available only when the default-off Media Manager feature, the base Visual Editor capability, and active Visual Editor mode all pass.

The lifecycle projection contains only the view-model version, opaque scan/generation references, revision, state, progress, safe summary/error/timestamps, and allowed non-mutating lifecycle actions. It excludes stored groups, sources, traversal cursors, configuration fingerprints, storage metrics, owner targets, and field targets.

The list request accepts bounded `search` (100 characters), `entity` (`all`, `post`, `term`), `field` (`all`, `featured_image`, `acf_image`, `acf_gallery`), an allowlisted sort, an opaque group-reference cursor, and a limit from 1 to 50. Explicit scan reads require the matching generation and revision. The latest-scan route is the deliberate resume exception and returns the latest user/site-bound scan without client-supplied generation/revision.

Each list row contains only an opaque group reference, safe current entity label/family/type label, permitted frontend URL or `null`, missing count, family counts, scan timestamp, and `expand` availability. There is no exact filtered total; cursor paging finds at most one additional eligible row to report `hasMore`, keeping per-request capability checks bounded.

Expansion accepts an opaque group reference plus matching generation/revision, resolves the target only from the current user/site-bound snapshot, and rescans that one server-owned entity. Field records contain an opaque finding reference, safe label/context, family, one of `missing`, `changed`, `resolved_or_changed`, or `unavailable`, a descriptor status, and non-mutating action flags. They never expose an owner ID/subtype, ACF object ID, field key/name/selector/path, empty/configuration fingerprint, raw stored value, full descriptor, descriptor token, or mutation action.

## Media Manager R1-D shell contract

The localized `DBVCVisualEditorBootstrap.mediaManager` object contains only:

```json
{
  "enabled": true,
  "restBase": "/wp-json/dbvc/v1/visual-editor/media-manager",
  "indexList": true
}
```

`indexList` (R2-H Slice 2c, filter `dbvc_visual_editor_media_index_list_enabled`, default = `enabled`) opts the panel into opening from the durable Media Index instead of an on-demand scan; when absent/false the scan-only path is used. The config is present on active Visual Editor pages so the shared toolbar can decide whether to render the entry. The separate Media Manager CSS/JavaScript assets enqueue only when `enabled` is true. Their first production slice creates a non-modal `role="dialog"` shell with `aria-labelledby`, a toolbar trigger using `aria-controls`/`aria-expanded`/`aria-haspopup="dialog"`, close and Escape behavior, and trigger-focus restoration.

The overlay integration emits only `dbvc:visual-editor:media-manager:toggle` and `dbvc:visual-editor:media-manager:close` document events. The Media Manager module emits `dbvc:visual-editor:media-manager:opened`, `dbvc:visual-editor:media-manager:closed`, and `dbvc:visual-editor:media-manager:state-changed`. Event details contain only a copied safe client state and are not data or mutation authority.

The second and fourth production slices provide `DBVCVisualEditorApi.mediaManager.latest/start/list/group/next/retry/cancel`. The request helper supplies the existing REST nonce; explicit list/group/action calls carry the current opaque `scanRef`, `generation`, and `expectedRevision`. `group` also accepts only the allowlisted opaque `vemg_*` reference. The first panel open calls only `latest`; a scan starts, advances, or revalidates one group only after an explicit user action.

R2-H Slice 2c adds `DBVCVisualEditorApi.mediaManager.index(query)` (`GET .../media-manager/index` — same `search`/`entityFamily`/`fieldFamily`/`sort` surface as `list`, plus `offset` paging) and `indexExpand(entityRef)` (`POST .../media-manager/index/expand`, accepting only an allowlisted opaque `vemx_*` entity reference). When `indexList` is on, the first panel open calls `index` (not `latest`) and renders `vemx_`-keyed rows; expanding a row calls `indexExpand`, whose response carries a **detached per-entity snapshot** (`scan` + a working `vemg_` group) that the client adopts as the per-expansion identity so `descriptor`/`assign`/`replace` are unchanged. If the index request fails or returns no rows, the client automatically falls back to `latest` (scan mode); `start` always returns to scan mode. The client state exposes `source` (`"scan"`/`"index"`) and `expansion.itemKey` (the list-row ref) alongside the working `expansion.groupRef`.

`window.DBVCVisualEditorMediaManager` exposes `open`, `close`, `isOpen`, `getState`, `index`, `loadLatest`, `start`, `list`, `loadMore`, `expand`, `collapse`, `next`, `retry`, and `cancel`. `getState()` returns a copy of this safe client shape:

```json
{
  "hasLoaded": true,
  "request": { "status": "success", "action": "" },
  "presentation": "complete",
  "scan": {},
  "query": {},
  "items": [],
  "pagination": { "hasMore": false, "nextCursor": "" },
  "results": { "status": "success", "error": null, "scrollTop": 0 },
  "expansion": { "groupRef": "", "status": "idle", "row": null, "error": null },
  "error": null
}
```

Presentation maps `scanning`, `failed`, `canceled`, `complete`, and `invalidated` to `scanning`, `error`, `canceled`, `complete`, and `invalidated`. Latest/list `404 media_scan_expired_or_invalid` maps to `no_scan`; generation/revision/busy/superseded conflicts map to the request-level `stale` presentation without replacing the newest accepted scan. A monotonic request sequence and same-generation revision comparison suppress older responses.

The third production slice renders safe list `items` as collapsed semantic rows. Search, entity family, field family, and sort always trigger a bounded first-page server request; the browser does not locally filter or sort authoritative data. `loadMore` sends the opaque next cursor, appends only previously unseen opaque group references, and preserves internal table scroll. A first-page replacement clears the cursor result set, expansion, and scroll. The table exposes safe labels, family/count summaries, timestamps, and an allowlisted HTTP(S) frontend link only. It never writes an owner/field/path/fingerprint into the DOM. The R1-C REST response remains authoritative.

The fourth production slice allows one row expansion at a time. A real button owns `aria-expanded` and `aria-controls`; its labeled region presents independent loading, request-error, provider-unavailable, current, changed, and resolved-or-changed states. The client calls only `group(scan, groupRef)`, validates that the returned scan identity and group match the current list, normalizes allowlisted field/status/descriptor-status properties, strips unknown keys, and suppresses a slower expansion response after collapse or a newer row request. Expansion never changes global list request state and a group failure never removes the loaded rows. `hydrateDescriptor` and `assignMedia` remain forced false; R1 does not call `wp.media`, issue a descriptor, or expose a mutation action.

## Media Manager R2-A descriptor bridge contract

`POST /wp-json/dbvc/v1/visual-editor/media-manager/scans/{scan_ref}/groups/{group_ref}/findings/{finding_ref}/descriptor` exchanges one opaque finding for one fresh standard descriptor. It is available only when the default-off Media Manager feature, the base Visual Editor capability, and active Visual Editor mode all pass, and it requires the current `generation` and `expectedRevision`.

The server resolves the owner and field only from the current user/site-bound snapshot group and finding. No client-supplied owner ID, field key/name, ACF object ID, selector, or path becomes authority. The bridge rechecks owner eligibility/status/capability, rescans the single owner to reconfirm field applicability and the current empty value by fingerprint, and only then mints one fresh `EditableDescriptor` for the `post_featured_image`, `acf_image`, or `acf_gallery` family through the existing registry, persisted in a fresh user-bound session via `EditableRegistry::persistDetachedDescriptor()`.

The response body contains `scan` identity (opaque `scanRef`/`generation`/`revision`/`state`), a `finding` block with the opaque `findingRef`/`groupRef`, `family`, a safe `label`, one of `writable`/`changed`/`resolved`/`unavailable`, and a `descriptorStatus`, and — only when writable — a `descriptor` block with `input` (`image`/`gallery`), `family`, and `expectedState: "empty"`. Because the R2-C save re-resolves the target server-side, the descriptor is not persisted and no opaque token or session id is returned; the client needs only the input kind and family to open the media frame. It never exposes an owner ID, ACF object ID, field key/name/selector/path, empty fingerprint, or raw value. `availableActions.assignMedia`, `openMediaLibrary`, and `save` remain false: R2-A opens no Media Library frame, hydrates no value, and mutates nothing. Tampered/malformed references, stale generation/revision, expired snapshots, populated-after-scan (`resolved`), changed empty evidence (`changed`), and lost eligibility/capability (`unavailable`) all fail closed.

## Media Manager R2-B staged-selection contract

R2-B adds a native Media Library selection affordance inside the `dbvc-ve-media-manager__detail-panel`. It is client-only interaction over the R2-A bridge and never writes content.

For each field still reported `missing`, and only when the localized `supportsWpMedia` flag and `window.wp.media` are both present, the panel renders an `assign-media` control (`Choose image` / `Choose gallery images`). Activating it POSTs the R2-A finding-descriptor route. On a `writable` descriptor the app opens `window.wp.media` with the standard configuration — `library: { type: 'image' }`, `multiple: false` for featured/ACF image and `multiple: true` for ACF gallery — and the core upload tab appears only when WordPress itself grants `upload_files`. The localized `canUpload` bootstrap flag mirrors `current_user_can('upload_files')` so the panel can show an upload-unavailable hint (R2-D) while still offering existing-media selection. A non-`writable` result (`changed`/`resolved`/`unavailable`) surfaces a status notice and never opens the frame.

A selection is staged in client state as `state.expansion.selections[findingRef] = { family, input, items, saved: false }` and re-rendered in place with an `Unsaved selection` badge, thumbnail preview, a `Replace`/`Clear selection` pair, and a polite live announcement. The client keeps no descriptor token or session id (the R2-C save re-resolves server-side); the public `getState()` selection summary exposes only `{ family, input, count, saved:false }`, and the owner id, field key/name/selector, ACF object id, and empty fingerprint never enter the DOM. Staged selections are discarded when the row collapses, a different row expands, or the scan is refreshed. No save, mutation, journal write, or cache invalidation occurs in R2-B; those remain R2-C.

## Media Manager R2-C assignment save contract

`POST /wp-json/dbvc/v1/visual-editor/media-manager/scans/{scan_ref}/groups/{group_ref}/findings/{finding_ref}/assignment` writes the staged selection to the field. It is available only under the same default-off Media Manager feature, base capability, and active-mode gates, and requires the current `generation` and `expectedRevision` plus the selection: `attachmentId` (image) or `attachmentIds` (gallery).

The server re-runs the full R2-A revalidation immediately before writing. If the finding is no longer writable — the owner lost eligibility, the field was populated after the scan (`resolved`), or its empty evidence changed (`changed`) — the request fails closed with `409 media_assignment_stale` and nothing is written. The write target is the freshly re-minted descriptor resolved only from the snapshot; the client never supplies the owner/field. The value is validated for cardinality and, through the existing resolver, for attachment MIME/type; a non-image or empty selection is rejected without a write.

On success the assignment runs through the shared `MutationService`: the resolver save (featured image, ACF image, or ACF gallery), journal/audit, and cache invalidation. The service then rereads the group with `expandGroup` and returns `assignment` (opaque `findingRef`/`groupRef`, `family`, `status: "saved"`, `attachmentCount`, opaque `changeSetId`) and the fresh `row` (updated `counts` and per-field statuses, with the saved field now `resolved_or_changed`). The response carries no raw owner id, field key/name/selector, ACF object id, or attachment path beyond the counts and opaque references. The client reconciles the expanded field, the row's missing count, and the scan summary from this reread and marks a fully resolved row in place — with no list/scan reload.

## Media Manager R2-F entity media inventory contract (Slice 1)

Expanding an entity resolves the full supported-media inventory live: the existing empty findings **plus** already-populated fields. The populated fields are reported only in the detail panel — the top-level results list and its counts stay empty-focused (a populated field never adds to a row's `missingCount`).

A populated field is projected with `status: "assigned"`, `descriptorStatus: "assigned"`, and a sanitized `preview` object: `{ url, alt, count }` — an `http(s)` thumbnail URL (empty if unresolvable), alt text, and, for galleries, the attachment count (a single thumbnail is shown for now). The preview is the only value exposed; the field key/name/selector, owner id, ACF object id, path, and raw stored value never appear. The expansion `counts` gain a `populated` total.

A field that was empty at scan and is now populated (for example, just saved) is reported **once**: its existing `resolved_or_changed` finding carries the merged `preview`; the inventory pass skips it to avoid a duplicate. Slice 1 is read-only — populated fields carry `availableActions.replace = false` and no assign control; thumbnail rendering (Slice 2) and replace (Slice 3) follow.

## Media Manager R2-F thumbnail presentation (Slice 2)

Each detail-panel field renders as `[thumbnail | content]`: a left-aligned square thumbnail (rounded, full field-item height) followed by the heading/meta/controls. A populated field shows its `preview.url` thumbnail with `loading="lazy"`/`decoding="async"`; an empty field shows the same wrap with an accent-color background placeholder (a single swap point for a future default image); a gallery shows one thumbnail plus a `+{count}` badge. Thumbnails render only inside the already-lazy detail panel, so nothing is fetched for collapsed rows. Slice 2 is presentation only — no new REST or mutation surface.

## Media Manager R2-F replace contract (Slice 3)

Each replaceable populated field additionally carries an opaque `valueRef` — a `vemv_[a-f0-9]{24}` fingerprint of the field's current stored value under the snapshot generation — and `availableActions.replace = true`. The `valueRef` is the **expected-current-value** token; it encodes no attachment id or path and is the only per-value identifier the client receives. Non-replaceable families expose `valueRef: ""` and `replace: false`.

`POST /wp-json/dbvc/v1/visual-editor/media-manager/scans/{scan_ref}/groups/{group_ref}/findings/{finding_ref}/replacement` overwrites a populated field. It is available only under the same default-off Media Manager feature, base capability, and active-mode gates, and requires the current `generation` and `expectedRevision`, the `expectedValueRef`, and the selection (`attachmentId` for an image or `attachmentIds` for a gallery). Unlike the assign flow there is no descriptor pre-call: the client opens `wp.media` directly and the endpoint performs all revalidation at save time.

`MediaFindingDescriptorBridge::resolveReplaceable` re-resolves the owner only from the snapshot, rescans in inventory mode, confirms the field is still populated, and `hash_equals`-compares the freshly computed value fingerprint against `expectedValueRef`. Every non-writable outcome is a hard error and nothing is written: `409 media_replace_stale` (current value differs from what the client read), `409 media_replace_not_populated` (field emptied/removed since the read — the client should use the assign path instead), `400 media_replace_value_ref_invalid` (malformed ref), `409 media_replace_unavailable` (owner lost eligibility/could not revalidate), or `403 media_finding_forbidden` (object capability lost). The write target is the freshly re-minted descriptor (`expectedState: "populated"`); the client never supplies the owner/field.

On success the replace runs through the same shared `MutationService`/`applyMutation` pipeline as R2-C (resolver overwrite, journal/audit, cache invalidation), then rereads with `expandGroup` and returns `assignment` (opaque `findingRef`/`groupRef`, `family`, `status: "replaced"`, `attachmentCount`, opaque `changeSetId`) and the fresh `row` — the field stays `assigned` with a new `preview` and a new `valueRef`. The resolver overwrites the field reference only; **no attachment is ever deleted**. The response carries no raw owner id, field key/name/selector, ACF object id, or attachment path beyond the counts and opaque references. The client reconciles the field/preview in place with no list/scan reload.

## Editable descriptor contract

```json
{
  "token": "ve_4f8a1d",
  "status": "editable",
  "scope": "shared_entity",
  "page": {
    "type": "post",
    "id": 245,
    "subtype": "page",
    "url": "https://example.com/services/"
  },
  "owner": {
    "type": "option",
    "id": "option",
    "subtype": "option",
    "acf_object_id": "option",
    "scope": "shared_entity",
    "isCurrentPageEntity": false,
    "isLoopOwned": false
  },
  "loop": {
    "active": false
  },
  "path": {
    "containerType": "",
    "rootFieldName": "cta_link",
    "fieldName": "cta_link",
    "isNested": false,
    "segments": [
      {
        "type": "field",
        "fieldName": "cta_link",
        "fieldKey": "field_64abc123"
      }
    ],
    "summary": "field:cta_link"
  },
  "mutation": {
    "version": 2,
    "kind": "structured",
    "target": "field",
    "contract": "shared_field",
    "renderContext": "text",
    "loopOwned": false,
    "requiresJournal": true,
    "status": "editable"
  },
  "entity": {
    "type": "option",
    "id": "option",
    "subtype": "option",
    "acf_object_id": "option"
  },
  "render": {
    "template_id": 251,
    "element_id": "x9k2lm",
    "element_name": "heading",
    "setting_key": "text",
    "attribute_key": "_root",
    "context": "text",
    "source_group": "vesg_6f89e4b7a0c1",
    "sync_group": "veg_18fe4a7c0e2b",
    "display_key": "title",
    "display_mode": "text",
    "render_verified": true,
    "rendered_text": "Contact Sales",
    "resolved_text": "Contact Sales"
  },
  "source": {
    "type": "acf_field",
    "expression": "{acf_cta_link:title}",
    "expression_args": ["title"],
    "field_name": "cta_link",
    "field_key": "field_64abc123",
    "field_type": "link",
    "return_format": "array",
    "media_size": ""
  },
  "resolver": {
    "name": "acf_link",
    "version": 1
  },
  "ui": {
    "label": "CTA Link",
    "input": "link",
    "warning": "This field resolves to a shared options-level ACF target. Saving here affects every frontend context using that option value."
  }
}
```

Repeater-backed descriptors use the same contract with row metadata added to `source`, for example:

```json
{
  "source": {
    "type": "acf_repeater_subfield",
    "field_name": "faq_answer",
    "field_key": "field_66bd749f71065",
    "field_type": "wysiwyg",
    "container_type": "repeater",
    "parent_field_name": "faq_group_items_repeater",
    "parent_field_key": "field_66bd748d71063",
    "row_index": 0
  }
}
```

Nested repeater descendants under a repeater root keep that same contract shape, but add explicit nested repeater row ancestry instead of pretending the innermost repeater is the root field, for example:

```json
{
  "source": {
    "type": "acf_repeater_subfield",
    "field_name": "object",
    "field_key": "field_66008b11459b4",
    "field_selector": "_price_item_repeater_quantities_object",
    "field_type": "text",
    "container_type": "repeater",
    "parent_field_name": "_price_item_repeater",
    "parent_field_key": "field_65cdb31d98459",
    "row_index": 0,
    "nested_repeater_path": [
      {
        "field_name": "quantities",
        "field_key": "field_66008aae459b2",
        "field_selector": "_price_item_repeater_quantities",
        "row_index": 1
      }
    ]
  }
}
```

Nested group descendants can add explicit ancestry to that same `source` payload when Bricks exposes `parent_group_names`, for example:

```json
{
  "source": {
    "type": "acf_flexible_subfield",
    "field_name": "settings_flexible_controls_styles_color_a",
    "field_selector": "settings_flexible_controls_styles_color_a",
    "leaf_field_name": "color_a",
    "leaf_field_key": "field_67241d00bc38d",
    "field_key": "field_67241d00bc38d",
    "field_type": "text",
    "container_type": "flexible_content",
    "parent_field_name": "settings_flexible_controls",
    "parent_field_key": "field_67241b70bc38a",
    "row_index": 0,
    "layout_key": "layout_67241b3fbc389",
    "layout_name": "styles_block",
    "group_path": ["styles"],
    "is_nested_group": true
  }
}
```

Direct flexible-content descendants now use the same descriptor contract shape with layout metadata added to `source`, for example:

```json
{
  "source": {
    "type": "acf_flexible_subfield",
    "field_name": "details",
    "field_key": "field_672419f1bfe48",
    "field_type": "wysiwyg",
    "container_type": "flexible_content",
    "parent_field_name": "alternative_flexible_layouts",
    "parent_field_key": "field_672419f0bfe12",
    "row_index": 0,
    "layout_key": "layout_6605e29de3f7e",
    "layout_name": "criteria_cost"
  }
}
```

Direct current-owner connected-item query roots now use a dedicated collection descriptor family, for example:

```json
{
  "source": {
    "type": "acf_collection_field",
    "expression": "query.objectType:acf_related_faq_groups",
    "field_name": "related_faq_groups",
    "field_key": "field_66abc123",
    "field_selector": "related_faq_groups",
    "field_type": "relationship",
    "reference_post_types": ["faq_group"],
    "reference_multiple": true,
    "reference_min": 0,
    "reference_max": 0,
    "native_query_kind": "relationship",
    "native_query_selector": "related_faq_groups",
    "native_query_object_type": "acf_related_faq_groups"
  },
  "mutation": {
    "version": 2,
    "kind": "collection",
    "target": "field",
    "contract": "relationship_collection",
    "renderContext": "query_collection",
    "requiresJournal": true
  }
}
```

For the same `query_collection` family, the collection contract name is scope-aware:
- current-owner roots: `relationship_collection` / `post_object_collection`
- current-owner derived Bricks query subsets: `relationship_collection_filtered_subset` / `post_object_collection_filtered_subset`
- explicit current-owner seed from a proven shared fallback: `relationship_collection_seed_from_fallback` / `post_object_collection_seed_from_fallback`
- loop-owned related-post roots: `loop_owned_relationship_collection` / `loop_owned_post_object_collection`
- shared-owner roots: `shared_relationship_collection` / `shared_post_object_collection`

Derived Bricks post loops use the same `acf_collection_field` family only after the final post query is proven to match one current-owner ACF source field. They carry `query_source = derived_bricks_query`, `query_target_post_type`, `query_target_post_type_label`, `query_result_ids`, `query_full_value_ids`, `query_preserved_ids`, and `query_subset_write_mode = replace_target_post_type_subset` for filtered subsets. Bricks native dynamic include/post__in controls additionally carry query-ID provenance such as `query_id_source`, `query_id_setting_source`, `query_id_setting_key`, `query_dynamic_tags`, and `query_dynamic_field_hints`; static/manual native ID lists and opaque native final-ID lists without saved ACF dynamic-tag evidence are not writable because they do not identify an editable source field. Simple Query Editor `get_field('field_name')` calls can add `query_editor_field_hints` for current-owner matching, while option and explicit-object reads are split into `query_editor_option_field_hints` and `query_editor_explicit_field_hints` for fallback provenance. Mixed/`any` derived post queries can omit `query_target_post_type` and use `query_collection_write_mode = replace_full_collection` only when `query_result_ids` exactly equal `query_full_value_ids`, which falls back to the standard full `relationship_collection` / `post_object_collection` contract. The filtered-subset contract rereads the full ACF field at save time, rejects stale target-subset conflicts, replaces only IDs matching `query_target_post_type`, and preserves all non-target IDs in mixed relationship fields such as `page_related_items`.

Nested grouped collection fields may also carry `field_selector_raw` when the exact ACF selector differs from the normalized `field_name`, for example `benefits_section_benefitsContent_related_items`. Descriptor source summaries expose that selector as `selector:{field_selector_raw}` so panel/status metadata shows the exact trusted selector used by the resolver.

Empty current-owner derived post loops are allowed only when the query summary still proves the source field. The descriptor carries `query_result_empty = true`, an empty `query_result_ids` list, a concrete `query_target_post_type`, and the same `relationship_collection_filtered_subset` / `post_object_collection_filtered_subset` contract. A loop also counts as empty for this contract when the raw `post__in` list is non-empty but every raw ID is outside the proven target post type; those raw IDs are preserved as non-target IDs during save. Because Bricks may not render the loop element when there are no rows, the descriptor can be registered synthetically from the captured `bricks/posts/query_vars` evidence. The frontend marker may be a hidden span injected after `<!--brx-loop-start-{element_id}-->` or after a `.brx-query-trail[data-query-element-id="{element_id}"]` placeholder, used only to anchor the existing container-level badge. Saves still reread the full field, reject stale target-subset conflicts, preserve non-target IDs, and reload after save.

Custom Query Editor fallback branches that resolve to `post__in` but cannot prove a current-owner field now stay non-writable unless they exactly match one option-owned ACF relationship/post_object fallback field. Exact option-owned matches surface as `scope = shared_entity`, `source_context = shared_option_fallback`, and `query_branch_state = shared_option_fallback_exact_match`; exact target-CPT subsets use `shared_relationship_collection_filtered_subset` / `shared_post_object_collection_filtered_subset`, while exact full-field matches use `shared_relationship_collection` / `shared_post_object_collection`. Unmatched Query Editor `post__in` branches surface as `status = readonly`, `source.type = derived_query_collection`, `query_branch_state = query_editor_post_in_unmatched`, and `query_collection_write_mode = inspect_only`. Recent/default branches, ambiguous matches, and partial windows remain deferred.

The seed-current-field action is not a normal save on the shared fallback descriptor. When an exact shared fallback descriptor also carries `source.query_seed_current_field.enabled = true`, the frontend may call the dedicated collection-seed REST route. The backend then revalidates that the current page has exactly one hinted ACF `relationship` / `post_object` field, confirms the current field has no existing target items for that branch, builds a synthetic current-owner `query_collection` descriptor, and runs it through the normal mutation service with `relationship_collection_seed_from_fallback` / `post_object_collection_seed_from_fallback`. Direct and nested-group seed targets are both allowed only when the flattened selector and grouped metadata are proven; `field_selector_raw`, leaf field identity, `group_path`, and `group_key_path` are preserved in the synthetic descriptor before mutation. This keeps fallback seeding explicit and prevents silently overwriting current-page connected items.

Locked Query Editor fallback branches use `ui.input = reference_collection_preview`. That frontend control consumes the same readonly queried-item payload returned by `native_readonly`, groups items by object/CPT type, and does not mount reference search or mutation controls. This keeps branch evidence inspectable without making a non-writable source look editable.

Nested current-owner connected-item roots keep that same `acf_collection_field` family, but add explicit container ancestry when the query root lives under repeater/flexible row chains, for example:

```json
{
  "source": {
    "type": "acf_collection_field",
    "expression": "query.objectType:acf_related_services",
    "field_name": "related_services",
    "field_key": "field_66abc999",
    "field_selector": "related_services",
    "field_type": "relationship",
    "container_type": "repeater",
    "parent_field_name": "sections_repeater",
    "parent_field_key": "field_66aaa111",
    "parent_field_selector": "sections_repeater",
    "row_index": 0,
    "container_ancestry": [
      {
        "type": "repeater",
        "field_name": "sections_repeater",
        "field_key": "field_66aaa111",
        "field_selector": "sections_repeater",
        "row_index": 0
      },
      {
        "type": "flexible_content",
        "field_name": "layout_rows",
        "field_key": "field_66aaa222",
        "field_selector": "layout_rows",
        "row_index": 1,
        "layout_name": "services_block",
        "layout_key": "layout_66aaa223"
      }
    ],
    "group_path": ["settings"],
    "group_key_path": ["field_66aaa224"]
  },
  "mutation": {
    "version": 2,
    "kind": "collection",
    "target": "row",
    "contract": "relationship_collection",
    "renderContext": "query_collection",
    "requiresJournal": true
  }
}
```

The repeater row identity is also formalized in `path`, for example:

```json
{
  "path": {
    "containerType": "repeater",
    "rootFieldName": "faq_group_items_repeater",
    "fieldName": "faq_answer",
    "rowIndex": 0,
    "isNested": true,
    "segments": [
      {
        "type": "repeater",
        "fieldName": "faq_group_items_repeater",
        "fieldKey": "field_66bd748d71063",
        "index": 0
      },
      {
        "type": "field",
        "fieldName": "faq_answer",
        "fieldKey": "field_66bd749f71065"
      }
    ],
    "summary": "repeater:faq_group_items_repeater / row:1 / field:faq_answer"
  },
  "mutation": {
    "version": 2,
    "kind": "scalar",
    "target": "row",
    "contract": "repeater_row",
    "requiresJournal": true
  }
}
```

When a repeater descendant lives inside another repeater row, `path.nestedRepeaterPath` and extra repeater segments preserve that inner row chain explicitly, for example:

```json
{
  "path": {
    "containerType": "repeater",
    "rootFieldName": "_price_item_repeater",
    "fieldName": "object",
    "rowIndex": 0,
    "nestedRepeaterPath": [
      {
        "fieldName": "quantities",
        "fieldKey": "field_66008aae459b2",
        "fieldSelector": "_price_item_repeater_quantities",
        "rowIndex": 1
      }
    ],
    "segments": [
      {
        "type": "repeater",
        "fieldName": "_price_item_repeater",
        "fieldKey": "field_65cdb31d98459",
        "index": 0
      },
      {
        "type": "repeater",
        "fieldName": "quantities",
        "fieldKey": "field_66008aae459b2",
        "fieldSelector": "price_item_repeater_quantities",
        "index": 1
      },
      {
        "type": "field",
        "fieldName": "object",
        "fieldKey": "field_66008b11459b4"
      }
    ],
    "summary": "repeater:_price_item_repeater / row:1 / repeater:quantities / row:2 / field:object"
  }
}
```

Flexible row identity is formalized in the same `path` contract, for example:

```json
{
  "path": {
    "containerType": "flexible_content",
    "rootFieldName": "alternative_flexible_layouts",
    "fieldName": "details",
    "rowIndex": 0,
    "layoutKey": "layout_6605e29de3f7e",
    "layoutName": "criteria_cost",
    "isNested": true,
    "segments": [
      {
        "type": "flexible_content",
        "fieldName": "alternative_flexible_layouts",
        "fieldKey": "field_672419f0bfe12",
        "index": 0,
        "layoutKey": "layout_6605e29de3f7e",
        "layoutName": "criteria_cost"
      },
      {
        "type": "field",
        "fieldName": "details",
        "fieldKey": "field_672419f1bfe48"
      }
    ],
    "summary": "flexible:alternative_flexible_layouts / row:1 / layout:criteria_cost / field:details"
  },
  "mutation": {
    "version": 2,
    "kind": "scalar",
    "target": "layout",
    "contract": "flexible_layout",
    "requiresJournal": true
  }
}
```

When a supported repeater/flexible descendant also belongs to nested ACF groups, `path.groupPath`, `path.groupKeyPath`, and extra `group` segments carry that ancestry explicitly, for example:

```json
{
  "path": {
    "containerType": "flexible_content",
    "rootFieldName": "settings_flexible_controls",
    "fieldName": "settings_flexible_controls_styles_color_a",
    "rowIndex": 0,
    "layoutKey": "layout_67241b3fbc389",
    "layoutName": "styles_block",
    "groupPath": ["styles"],
    "groupKeyPath": ["field_67241bf0bc38b"],
    "segments": [
      {
        "type": "flexible_content",
        "fieldName": "settings_flexible_controls",
        "fieldKey": "field_67241b70bc38a",
        "index": 0,
        "layoutKey": "layout_67241b3fbc389",
        "layoutName": "styles_block"
      },
      {
        "type": "group",
        "fieldName": "styles",
        "fieldKey": "field_67241bf0bc38b"
      },
      {
        "type": "field",
        "fieldName": "settings_flexible_controls_styles_color_a",
        "fieldKey": "field_67241d00bc38d"
      }
    ],
    "summary": "flexible:settings_flexible_controls / row:1 / layout:styles_block / group:styles / field:settings_flexible_controls_styles_color_a"
  }
}
```

In the current slice:
- `flexible_layout` is writable only for text-like, WYSIWYG, choice, link, and image flexible descendants on the current post owner
- `loop_owned_flexible_layout` is writable only for text-like, WYSIWYG, choice, link, and image flexible descendants on the concrete related post currently rendered by the loop
- `flexible_layout`, `loop_owned_flexible_layout`, and `shared_flexible_layout` now also cover gallery descendants when Bricks is rendering a direct gallery collection, and the shared contract now applies consistently across shared post/term/user/option owners
- live `source_group` and `sync_group` identity for nested group descendants must include `group_path` plus leaf selector identity so same leaf names under different group roots do not collide

`scope` values currently used:
- `current_entity`
- `shared_entity`
- `related_entity`

`status` values currently used:
- `editable`
- `readonly`
- `unsupported`

## Save request contract

```json
{
  "token": "ve_4f8a1d",
  "value": {
    "url": "https://example.com/contact",
    "title": "Contact Sales",
    "target": "_blank"
  },
  "acknowledgeSharedScope": true,
  "nonce": "..."
}
```

Scalar fields continue to submit a scalar `value`.

Choice-style fields may submit:
- a string for single-value controls
- an array of strings for checkbox or multi-select controls

Link fields submit an object with:
- `url`
- `title`
- `target`

Image fields may submit an object with:
- `attachmentId`
- `url`

`attachmentId` is the canonical save target for image fields. A pasted local Media Library URL is only used as a fallback lookup when no attachment ID is supplied. This same attachment-aware contract now applies to both direct ACF image fields and direct WordPress `{featured_image}` bindings rendered by Bricks as an image or background image source.

Gallery fields may submit:
- an ordered array of attachment IDs
- or an object with `attachmentIds`, `ids`, or `items`

In the current slice, gallery saves normalize to an ordered unique list of local Media Library image attachment IDs and replace the full collection value in one write.

In the current slice, the backend only accepts image changes that resolve to an existing local Media Library attachment, and the normalized image value returned after save includes image-specific render metadata such as:
- `attachmentId`
- `renderUrl`
- `fullUrl`
- `renderAttributes.src`
- `renderAttributes.srcset`
- `renderAttributes.sizes`

For `shared_entity` descriptors, `acknowledgeSharedScope` must be truthy or the save request is rejected.
The same acknowledgement gate is also used for `related_entity` descriptors so the editor must explicitly confirm that the save targets a related post shown in the loop, not the current page.

## Save response contract

```json
{
  "ok": true,
  "token": "ve_4f8a1d",
  "status": "saved",
  "descriptorVersion": 2,
  "changeSetId": 148,
  "value": {
    "url": "https://example.com/contact",
    "title": "Contact Sales",
    "target": "_blank"
  },
  "displayValue": "Contact Sales",
  "displayMode": "text",
  "displayCandidates": [
    {
      "key": "url",
      "value": "https://example.com/contact",
      "mode": "text"
    },
    {
      "key": "title",
      "value": "Contact Sales",
      "mode": "text"
    }
  ],
  "sourceGroup": "vesg_6f89e4b7a0c1",
  "syncGroup": "veg_18fe4a7c0e2b",
  "pageContext": {
    "type": "post",
    "id": 245
  },
  "ownerContext": {
    "type": "term",
    "id": 19,
    "scope": "shared_entity"
  },
  "pathContext": {
    "summary": "field:cta_link"
  },
  "mutationContract": {
    "version": 2,
    "kind": "structured",
    "target": "field",
    "contract": "shared_field"
  },
  "saveContractSummary": {
    "name": "shared_field",
    "label": "shared field",
    "detail": "shared field / structured / field / text",
    "writable": true,
    "requiresAcknowledgement": true,
    "acknowledgementType": "shared"
  },
  "entitySummary": {
    "title": "Dental Implants",
    "typeLabel": "Service Category"
  },
  "sourceSummary": {
    "label": "CTA Link",
    "summary": "acf_field / cta_link / term:19"
  },
  "saveSummary": {
    "title": "Saved Dental Implants",
    "detail": "Service Category / Source: CTA Link / shared term target"
  },
  "message": "Saved successfully."
}
```

## Session bootstrap response

The authenticated session bootstrap endpoint returns the public descriptor map for the current page by default.

It can also return hydrated descriptor payloads when explicit warmup is requested, but that is no longer the default interactive mode.

```json
{
  "ok": true,
  "sessionId": "ves_ab12cd34ef56",
  "descriptors": {
    "ve_4f8a1d": {
      "token": "ve_4f8a1d",
      "label": "CTA Link",
      "input": "link",
      "status": "editable",
      "scope": "shared_entity",
      "entity": {
        "type": "term",
        "id": 19,
        "subtype": "service_category"
      }
    }
  },
  "descriptorHydrations": {}
}
```

The lightweight public-map `entity` summary is only intended for badge and panel labeling. It is not a mutable save target.

The new durable journal layer is intentionally separate from the request/session bootstrap contract:
- transient sessions remain the source of truth for per-request marker lookup
- `dbvc_ve_change_sets` and `dbvc_ve_change_items` store committed mutation history, not live token caches

The save-contract layer is also separate from public marker/session summaries:
- the browser can read the contract summary for UI messaging
- the backend still treats the mutation contract as authoritative when deciding whether a save path is enabled

When warmup hydration is explicitly requested:

```json
{
  "ok": true,
  "sessionId": "ves_ab12cd34ef56",
  "descriptors": {
    "ve_4f8a1d": {
      "token": "ve_4f8a1d",
      "label": "CTA Link",
      "input": "link",
      "status": "editable"
    }
  },
  "descriptorHydrations": {
    "ve_4f8a1d": {
      "descriptor": {
        "token": "ve_4f8a1d"
      },
      "currentValue": {
        "url": "https://example.com/contact",
        "title": "Contact Sales",
        "target": "_blank"
      },
      "displayValue": "Contact Sales",
      "displayMode": "text",
      "acknowledgementType": "shared",
      "canEdit": true,
      "requiresSharedScopeAck": true,
      "editMessage": "",
      "noticeSummary": {
        "title": "Editing Dental Implants",
        "detail": "Service Category / Source: CTA Link / shared term target"
      },
      "entitySummary": {
        "title": "Dental Implants",
        "typeLabel": "Service Category",
        "frontendLink": {
          "label": "Frontend - Service Category Content Editor",
          "url": "https://example.com/service-category/dental-implants/"
        },
        "backendLink": {
          "label": "Backend - Service Category Full Editor",
          "url": "https://example.com/wp-admin/term.php?taxonomy=service_category&tag_ID=19"
        }
      },
      "sourceSummary": {
        "label": "CTA Link",
        "type": "acf_field",
        "fieldName": "cta_link",
        "parentFieldName": "",
        "expression": "{acf_cta_link:array_value|url}",
        "summary": "acf_field / cta_link / term:19"
      }
    }
  }
}
```

`noticeSummary` is intended for the panel notice/status area so inspect-only and locked fields can name the exact entity and source context without forcing the client to infer it from generic message strings.

## Verification note

Descriptors are only persisted for nodes that survive render verification.

If a Bricks node is initially identified as an editable candidate but its rendered text does not match any resolver-approved display projection, the marker is removed before the response reaches the browser and the descriptor is dropped from the session.

For link-attribute markers, the same rule applies against the rendered attribute value such as `href` instead of the node text content.

For structured field types such as links and checkbox/select-like values, the descriptor stores the matched `render.display_key` so the live overlay can keep updating the same visible projection after save.

For image-source markers, the descriptor can also store `source.media_size` so the resolver can compare and live-update the same Bricks-rendered image size while still editing the underlying attachment-backed field safely.

Inspect-only descriptors may remain visible even when the rendered page value is only one derived projection of a more complex backend field value. In those cases the descriptor still carries `status = readonly`, and the overlay must not treat the descriptor as saveable even if it can inspect the raw value.

Descriptors also carry a session-local `render.sync_group` so repeated markers for the same resolved field projection can update together after a successful save without exposing the raw backend target in the DOM.

Descriptors now also carry a broader `render.source_group` so structured saves can update other matched projections of the same resolved field, such as an ACF link title marker and a separate ACF link URL marker on the same page.

For repeater row descendants, both `render.source_group` and `render.sync_group` include the parent repeater identity and row index so a save on one row does not live-update sibling rows of the same field.

For repeater-style Bricks collection links, `render.attribute_key` can hold a deterministic anchor key such as `a-0`, which lets the server verify and update each marker against the correct rendered anchor tag inside one element response.

For related-post loop rows, `render.loop_signature` keeps descriptor identity stable per loop row so repeated Bricks element UIDs do not collide across different related posts in the same query.

## Audit entry contract

At minimum:
- token
- entity type
- entity id
- field name
- old value
- new value
- changed by user id
- timestamp
- page URL if available
- template id / element id if available
