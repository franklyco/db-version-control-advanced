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
- Flexible gallery descendants should remain inspect-only until a collection-safe mutation path exists.
- `universal_cta_options` / Site Settings global-link fields must remain read-only in Visual Editor and must not save through the overlay.
- Unsupported generic loop/query shapes should stay unmarked or honestly locked.
