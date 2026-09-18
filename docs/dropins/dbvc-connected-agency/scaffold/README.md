# Scaffold status and integration

This directory contains inert starter templates. Each PHP file ends in `.php.stub`; none is a WordPress plugin. See [overlay-manifest.json](overlay-manifest.json) for proposed destinations after source reconciliation. There is no automatic installer or bootstrap patch.

## Implemented starter logic

- Independent module gates that return before runtime loading while off.
- Explicit `scaffold_only` runtime placeholders that register nothing even when enabled.
- Pure three-way and framework-status classification for validated comparable inputs.
- Pure routing policy using a trusted registry and verified operation-result context.
- Small save-side dirty signal port with a domain allowlist.
- Proposed operational-option exclusion predicate, not attached to an existing export filter.
- Read-only observer, transactional observation-store, transport, enrollment and receipt interfaces.

The unconfigured Bricks observer advertises no reporting/apply capability and returns incomplete/unavailable. Do not replace that with empty successful inventory results. Real storage adapters, canonicalizers, identity mapping, SQL implementations, auth, schedulers, endpoints and UI must be written from the current repository and fixtures.

## Integration order

1. Inspect actual DBVC source and instructions.
2. Map only the templates needed by the current slice into reviewed destinations.
3. Use existing namespace/autoload patterns; shared protocol types must load for either role independently.
4. Add bounded bootstrap includes to the existing plugin owner; no add-on activation hooks or extra plugin header.
5. Implement durable local stores and verified domain adapters before enabling capture.
6. Replace runtime composition placeholders, then register only implemented capabilities.
7. Run module-off/role isolation and relevant existing regression tests.

Pure classes do not validate arbitrary HTTP requests. Routing assumes a validated event and trusted registry; the verified-operation flag is derived by the hub from a matching operation receipt and expected hash. It must never be a request parameter.

`migrations/001-local-observation.sql.stub` is a DDL review draft for M1, not a runnable migration. Adapt it to actual prefix, DBVC database helper, supported engine/collation, storage formats and crash semantics.

`tests/php-contracts.php.stub` exercises the PHP starter classes without WordPress after copying templates into a temporary directory. Use the supplied harness runner; do not rename all files inside this docs folder.
