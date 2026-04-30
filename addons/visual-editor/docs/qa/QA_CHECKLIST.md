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
- [ ] Repeater row markers stay distinct per row and do not collide across nested related-post loops
- [ ] Single dynamic tags wrapped by one empty HTML node still instrument when otherwise supported
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
- [ ] Current-post repeater row saves mutate only the targeted row
- [ ] Related-post repeater row saves mutate only the targeted related-owner row
- [ ] Repeater row saves do not live-update sibling rows incorrectly

## UX
- [ ] Editable fields open expected input
- [ ] Unsupported fields show honest state
- [ ] Success and error messages render correctly
- [ ] Loop-owned fields show related-post ownership context in the modal
- [ ] Repeater-backed fields show parent repeater and row metadata in the modal source summary
- [ ] Shared options-page fields keep shared warnings distinct from related-post loop warnings
