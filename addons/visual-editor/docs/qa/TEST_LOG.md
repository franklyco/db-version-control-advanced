# Visual Editor Test Log

## 2026-04-28

### Confirmed manually in this thread
- Visual Editor addon enabled from DBVC config and loads on singular frontend views.
- Admin-bar toggle activates Visual Editor mode and the frontend status bar appears.
- Marker discovery, descriptor session bootstrap, and authenticated descriptor lookup work.
- Shared badge runtime now uses one active badge with lightweight session bootstrap and on-demand descriptor loading.
- Simple save flow works without reloading the page.
- The in-page editor panel works in place of the browser prompt.
- Guarded structured-field updates and recent Bricks link slices were reported working by the user.
- Live authenticated browser probing against `https://frameworkflo-live.local/vertical/dentists/` confirmed visible VE tokens on previously failing related-owner elements such as `.brxe-ozyswq` and `.brxe-zecvno`.
- Panel follow-up was intentionally paused after the recent grouped/flexible contract work so the next resume point is live save verification for nested grouped descendants, not more marker discovery.
- A targeted runtime inspection confirmed Bricks grouped ACF tags such as `acf_assortment_group_name` and `acf_assortment_group_media_image_primary` resolve as group descendants rather than flat leaf fields, and the direct grouped save path was hardened accordingly.
- A targeted runtime inspection confirmed native Bricks ACF loop roots resolve cleanly from real site data for `acf_process_section_process_steps` (repeater), `acf_related_faq_groups` (relationship), `acf_services_section_services_relationship` (relationship), and `acf_office_manager` (post_object).
- A targeted runtime inspection on page `24732` confirmed native grouped repeater roots must read from the full selector `process_section_process_steps`; the shorter duplicated parent name `process_steps` returns `null`, which explained why `guzahf` descendants were losing markers during render verification.
- A targeted runtime inspection on page `24732` confirmed Bricks duplicate repeater child tags such as `acf_process_steps_step_name` and `acf_process_steps_step_description` were carrying the wrong subfield keys (`field_658fc227...`) even though the real `process_section_process_steps` rows use `field_650b7ca2a58b1` / `field_650b7ca2a58b2`; the resolver now rebinds those duplicate child tags against the actual repeater container definition before classification and render verification.
- A targeted runtime inspection on page `24732` confirmed nested grouped repeater descendants such as `acf_step_duration_group_step_duration_value_1` carry the correct group-child key but were previously missing repeater-row context because their immediate Bricks ACF parent is `group`, not `repeater`; the resolver now inherits the native repeater container context for those grouped descendants and marks them as supported repeater-row fields.
- A resolver-level runtime probe confirmed grouped repeater descendants on page `24732` now read through raw row payloads using `group_key_path` as well as `group_path`; a synthetic descriptor for `acf_step_duration_group_step_duration_value_1` returned the real row value `"5"` when pointed at `process_section_process_steps` row 1.
- A pure in-memory verification probe confirmed the new row-rebind fallback can uniquely match rendered row text such as `Step Four`, rebind a repeater descriptor from row index `2` to `3`, rebuild the descriptor path summary as `row:4`, and regenerate a row-aware source group instead of dropping the marker.
- Direct inspection of the saved `/our-process/` VE session on `dbvc-codexchanges.local` confirmed that row-4 native repeater descendants were missing before the frontend badge layer; only rows `0,1,2` were being persisted for `figdnw`, `uygtdn`, `bonmng`, `jjabji`, and `wjpthy`, which isolated the issue to descriptor creation/seed collapse rather than panel rendering.
- An escalated WordPress runtime render probe on `/our-process/` showed the deeper cause for the stubborn row-4 failure: Bricks was exposing `loop_object_id = 3` on the fourth native repeater row, and VE was incorrectly treating that bare numeric index as a concrete related post because a real post with ID `3` exists in WordPress. That flipped row 4 to `scope=related`, and `render_element` stripped its markers even though `render_attributes` had added them.
- The follow-up runtime probe after the `LoopContextResolver` fix confirmed row 4 now stays `scope=current` and keeps `data-dbvc-ve` through `render_element` for `figdnw`, `uygtdn`, `jjabji`, `wjpthy`, and `bonmng`; only the genuinely empty `iuxcry` image and the unsupported mixed-string `lpfhov` stay unmarked on that row.

### Pending targeted smoke tests
- Bricks button and Bricks link elements backed by current-post ACF fields.
- Bricks ACF relationship/post-object query loops where loop rows render related-post `post_title`, `post_excerpt`, or safe direct ACF text-like fields.
- Related-post loop saves should require acknowledgement and mutate the related post owner, not the current page.
- Related-post loop modal should show loop ownership context clearly.
- Concrete queried term and user loop owners should surface `Related Term` / `Related User` context and save safe direct ACF fields against that loop owner.
- Current-post ACF repeater rows should mark and save text-like, WYSIWYG, link, and image descendants without cross-row sync bleed.
- Related-post ACF repeater rows nested inside Bricks relationship/post-object loops should show related-post ownership and save against the related owner.
- Exact single-tag content wrapped by one HTML node, such as `<p>{acf_faq_items_repeater_answer}</p>`, should still mark when the wrapped tag is otherwise save-capable.
- Direct flexible-content descendants should show parent flexible field, row, and layout context in the modal once surfaced.
- Current-post and related-post flexible text-like/WYSIWYG/choice/link/image descendants should save against the targeted flexible row only.
- Flexible descendants in Bricks loops where the loop object type is a fuller ACF path such as `acf_core_sections_flexible_layouts` but the rendered child tags are shortened to `acf_flexible_layouts_*` should still surface and resolve as editable when otherwise supported.
- Direct ACF fields inside Bricks custom query-editor post loops should still surface and resolve as editable when Bricks exposes a real `WP_Post` object or a CPT slug such as `benefit` instead of the literal loop type `post`.
- Nested ACF group descendants with the same leaf field names under different group roots should not cross-sync after save now that `group_path` and leaf selector identity participate in `source_group` / `sync_group` hashing.
- Direct grouped ACF fields should save through their full selector path, not only the ambiguous leaf field name, especially for grouped leaves such as `name` and `image_primary`.
- Bricks native ACF repeater loops such as `acf_process_section_process_steps` should surface/edit supported child text, WYSIWYG, link, and image fields when rendered through the native Bricks query loop rather than only through earlier custom-loop slices.
- The fourth native ACF repeater row on page `24732` should surface the same supported VE markers as the first three rows, including row-4 fields like `process_section_process_steps_3_step_label`, `process_section_process_steps_3_step_description`, and `process_section_process_steps_3_step_duration_group_step_duration_label`.
- Native repeater rows on page `24732` should no longer collapse when Bricks reuses the same loop signature for later rows; the saved VE session should persist row `3` descendants for `figdnw`, `uygtdn`, `bonmng`, `jjabji`, and `wjpthy`.
- Bricks native ACF relationship loops such as `acf_related_faq_groups` or `acf_services_section_services_relationship` should resolve the current related owner cleanly and allow supported fields on that owner to save through the explicit loop-owned contract path.
- Bricks native ACF post-object loops such as `acf_office_manager` should resolve the selected owner cleanly and allow supported fields on that owner to save through the explicit loop-owned contract path.
- Flexible gallery descendants should remain inspect-only until a collection-safe mutation path exists.
- `universal_cta_options` / Site Settings global-link fields must remain read-only in Visual Editor and must not save through the overlay.
- Unsupported generic loop/query shapes should stay unmarked or honestly locked.
