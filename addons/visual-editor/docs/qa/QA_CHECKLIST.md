# QA Checklist

## Activation
- [ ] Non-editor cannot activate visual edit mode
- [ ] Editor can activate visual edit mode
- [ ] Assets do not load when mode is off

## Instrumentation
- [ ] Supported nodes receive marker attributes
- [ ] Unsupported nodes do not receive false editable markers
- [ ] Marker token maps to descriptor correctly
- [ ] Related-post Bricks query-loop rows receive distinct per-row tokens
- [x] Native `relationship -> repeater` descendants expose parent relationship ancestry in descriptor source/path metadata
- [ ] Native `relationship -> flexible` descendants expose parent relationship ancestry once a live fixture exists
- [ ] Native `post_object -> repeater/flexible` live-fixture validation is paused; synthetic `post_object -> repeater` classification/read coverage exists
- [ ] Concrete queried term/user loop owners receive related-owner markers rather than generic shared markers
- [ ] Repeater row markers stay distinct per row and do not collide across nested related-post loops
- [ ] Direct flexible descendants with stable row + layout identity surface honest markers and path metadata
- [ ] Single dynamic tags wrapped by one empty HTML node still instrument when otherwise supported
- [x] Multi-tag Bricks text settings surface one readonly composite marker with child source summaries, not one marker per child token
- [x] Multi-tag Bricks text settings with one resolved field plus unsupported provider tags surface locked child rows and stay readonly
- [ ] Generic unsupported Bricks loop types do not receive false editable markers
- [ ] `universal_cta_options` Site Settings link-group fields stay read-only in Visual Editor

## Save path
- [ ] Save requires nonce
- [ ] Save requires capability
- [ ] Save rejects invalid token
- [ ] Save rejects unsupported descriptor
- [ ] Save sanitizes value
- [ ] Save persists correct value
- [ ] Audit entry is created
- [ ] Invalidation hook runs
- [ ] Related-post loop saves require explicit acknowledgement
- [ ] Related-post loop saves update the related post owner, not the current page post
- [ ] Related-term/user loop saves require explicit acknowledgement
- [ ] Related-term/user loop saves update the related loop owner, not the current page post
- [ ] Current-post repeater row saves mutate only the targeted row
- [ ] Related-post repeater row saves mutate only the targeted related-owner row
- [x] Native `relationship -> repeater` saves mutate only the targeted related owner and row path on the FAQ fixture
- [ ] Native `relationship -> flexible` saves mutate only the targeted related owner and layout path once a live fixture exists
- [ ] Native `post_object -> repeater/flexible` save validation is paused until this branch is explicitly resumed
- [ ] Repeater row saves do not live-update sibling rows incorrectly
- [ ] Row-backed grouped/nested saves reject missing group or nested repeater containers instead of creating new paths from stale descriptors
- [ ] Current-post flexible text-like/WYSIWYG/choice/link/image saves mutate only the targeted flexible row
- [ ] Related-post flexible text-like/WYSIWYG/choice/link/image saves mutate only the targeted related-owner flexible row
- [x] Composite-save REST requests reject related/shared child batches without explicit acknowledgement before any child write
- [x] Composite-save REST requests reject unsupported, readonly, missing, or structured child controls before any child write
- [x] Composite scalar batch saves persist same-value, changed-value, and restored child values through the composite-save route
- [x] Composite stale child baselines return `409` before any batch writes and preserve the externally changed value
- [x] Composite batch writes attempt rollback of earlier child writes when a later child write fails in the controlled mutation-service probe
- [x] Composite batch journal entries use one parent change set with one item per child mutation and include live failure/rollback row evidence

## UX
- [ ] Editable fields open expected input
- [ ] Unsupported fields show honest state
- [ ] Success and error messages render correctly
- [ ] Loop-owned fields show related-post ownership context in the modal
- [ ] Loop-owned term/user fields show related-owner context in the modal
- [ ] Repeater-backed fields show parent repeater and row metadata in the modal source summary
- [ ] Flexible-backed fields show parent flexible field, row, and layout metadata in the modal source summary
- [ ] Shared options-page fields keep shared warnings distinct from related-post loop warnings
- [ ] Composite text panels show reconstructed preview, original template, child source rows, and no save controls while `canBatchSave` remains false
- [ ] Composite text panels show batch-save preflight readiness, owner groups, acknowledgement types, and blocked child reasons while `canBatchSave` remains false
- [ ] Composite text panels expose scalar child inputs and `Save All` only when `canBatchSave` is true
- [ ] Composite text `Save All` requires related/shared acknowledgement in the panel before any save request
- [ ] Composite text no-reload saves patch the active marker from the template and returned child display values without cross-syncing sibling markers
- [ ] Review Fields `Open` opens zero-height/hidden composite descriptors through the token fallback path in live browser QA
