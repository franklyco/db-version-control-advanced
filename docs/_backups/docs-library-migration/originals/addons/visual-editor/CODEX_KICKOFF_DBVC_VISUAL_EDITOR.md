Please read and follow `AGENTS.md` first, then review:
- `README.md`
- `ARCHITECTURE.md`
- `docs/handoffs/DBVC_VISUAL_EDITOR_HANDOFF.md`
- `docs/knowledge/HOOK_USAGE_STRATEGY.md`
- `docs/knowledge/DATA_CONTRACTS.md`

Task:
Implement the DBVC Visual Editor addon as a new DBVC addon with a narrow, safe MVP.

Context:
This addon has not been implemented yet. The current package is a scaffold plus architectural guidance only.

Primary objective:
Build the first working vertical slice of a frontend visual editor for Bricks + ACF content that:
1. activates only for authorized editors,
2. instruments supported Bricks-rendered elements with lightweight marker attributes,
3. maintains a request-scoped descriptor registry server-side,
4. exposes authenticated REST endpoints for descriptor lookup and save,
5. supports at least one end-to-end editable field path,
6. logs changes and invalidates relevant caches/hook points.

Critical architecture rules:
- Do not make the DOM the source of truth.
- Do not let the client submit arbitrary meta keys.
- Use `data-dbvc-ve="<token>"` style handles, not raw save payloads.
- Use resolver classes for save behavior.
- Keep MVP to current singular entity and text-like fields only.

Recommended implementation order:
1. Confirm actual DBVC addon bootstrap conventions in the real repo.
2. Adapt `bootstrap.php` and `src/Bootstrap/Addon.php` to those conventions.
3. Implement edit mode detection and asset gating.
4. Implement Bricks hook registrar using:
   - `bricks/element/render_attributes` as primary
   - `bricks/frontend/render_element` as fallback
5. Implement descriptor registry and minimal descriptor contract.
6. Support one Bricks heading/text element mapping to one ACF text field.
7. Implement REST routes:
   - session/bootstrap
   - descriptor detail
   - save
8. Implement validation, sanitization, mutation, audit, and cache invalidation hooks.
9. Add basic overlay UI states and save feedback.
10. Document the final adapted architecture in the repo.

Definition of done for this pass:
- the first end-to-end editable field path works on a singular Bricks-rendered post/CPT
- unsupported content is not falsely editable
- save path is capability checked, nonce checked, and descriptor verified
- docs are updated to match actual implementation
- validation report is included

Completion report format:
- Scope completed
- Files created
- Files updated
- Validation level
- Checks run
- Checks not run
- Residual risk / next recommended slice
