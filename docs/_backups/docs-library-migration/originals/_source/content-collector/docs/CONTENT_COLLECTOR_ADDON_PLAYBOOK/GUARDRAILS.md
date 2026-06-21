# DBVC Addon Guardrails (Breakage Mitigation)

Use these rules during Content Collector absorption into DBVC to avoid regressions across separate codebases.

## Rule Set

### G01: Prefix Isolation
- All new identifiers must be DBVC-addon-prefixed (`dbvc_cc_`): class names, functions, hooks, options, transients, REST route bases, script/style handles, and admin slugs.
- No unprefixed global symbols may be introduced.

### G02: No Runtime Coupling to Drop-In Source
- `./content-collector` is source-reference only during migration.
- DBVC runtime must not `require` or bootstrap source plugin files in production execution paths.

### G03: Module Boundary Enforcement
- Keep implementation modular under addon module folders.
- No cross-module direct filesystem access to internal module data.
- Cross-module behavior must use service contracts/interfaces.

### G04: Contract Lock and Versioning
- Explorer, AI, and Export payload schemas are contract-locked.
- Any schema change requires:
  1. version bump
  2. fixture updates
  3. changelog entry

### G05: Feature Flag Gating
- Gate each major capability independently: collector, explorer, ai mapping, import plan, import execute, export.
- Include one global kill switch to disable addon runtime safely.

### G06: Dry-Run Gate Before Writes
- No import writes are allowed without a successful dry-run plan and explicit execution confirmation.
- Dry-run output must include collisions, validation failures, and rewrite plan.

### G07: Idempotent Write Rule
- All write operations must upsert by deterministic external ID.
- Re-runs must not create duplicate posts, terms, users, media, or mapping records.

### G08: Non-Destructive Default Rule
- Default behavior is additive/upsert-only.
- Deletes or destructive overwrites require explicit policy enablement.

### G09: Collision Policy Enforcement
- Slug, taxonomy, user, media, and meta collisions must resolve via configured policies only.
- No implicit fallback behavior that bypasses configured policy.

### G10: Security Parity Rule
- Preserve capability checks, nonce checks, sanitization, and path guards on all endpoints and writes.
- Keep directory hardening behavior (`index.php`, `.htaccess`) in storage roots.

### G11: Secrets Hygiene Rule
- API keys/tokens must never appear in logs, exports, error payloads, or UI plaintext.
- Sensitive values must be masked in status/debug output.

### G12: Performance Budget Rule
- Enforce hard limits for traversal depth, node count, AI batch size, and timeouts.
- Heavy operations must be async and resumable.

### G13: Observability Rule
- Every stage emits structured events with correlation IDs, stage, status, path/object, and failure code.
- Logs must support per-object diagnostics and stage rollups.

### G14: Regression Gate Rule
- Merge/deploy is blocked unless all pass:
  1. payload fixture parity
  2. route smoke tests
  3. DBVC core regression suite

### G15: Rollback Rule
- Each phase release must include rollback path via feature flags.
- Rollback must preserve data and restore prior DBVC behavior.

## Verification Checklist
1. Prefix audit passes for new identifiers.
2. No runtime imports from `./content-collector` in active DBVC execution.
3. Module boundaries respected (no direct cross-module file coupling).
4. Contract fixtures updated and passing.
5. Feature flags documented and tested.
6. Dry-run required prior to commit import.
7. Idempotent rerun test passes.
8. Collision policies enforced in tests.
9. Security checks verified on all transport layers.
10. Secrets absent from logs/exports.
11. Performance limits enforced.
12. Structured observability events emitted.
13. Regression gates green.
14. Rollback flag path validated.
