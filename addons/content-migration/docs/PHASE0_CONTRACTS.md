# Phase 0 Contract References

This phase establishes addon boundaries, bootstrap wiring, and centralized contracts.

## Canonical Contract File
- `addons/content-migration/shared/dbvc-cc-contracts.php`

## Guardrail Alignment
- Prefix isolation: all new symbols are `dbvc_cc_*` or `DBVC_CC_*`.
- No runtime coupling to `_source/content-collector`: enforced by runtime guard.
- Feature-gating scaffolding: global kill switch plus per-capability flags.
- Dry-run and idempotency policy anchors: constants defined for Phase 4 implementation.
- Contract version anchor: `DBVC_CC_Contracts::ADDON_CONTRACT_VERSION`.

## External References
- `_source/content-collector/docs/CONTENT_COLLECTOR_ADDON_PLAYBOOK/CONTENT_COLLECTOR_ADDON_MANIFEST.json`
- `_source/content-collector/docs/CONTENT_COLLECTOR_ADDON_PLAYBOOK/GUARDRAILS.md`
- `_source/content-collector/docs/CONTENT_COLLECTOR_ADDON_PLAYBOOK/PHASE_PLAN.md`
- `_source/content-collector/docs/CONTENT_COLLECTOR_ADDON_PLAYBOOK/HANDOFF.md`
