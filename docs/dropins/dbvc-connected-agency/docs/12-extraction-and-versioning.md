# Extraction and versioning

## Separation to preserve now

- Each add-on has an owned bootstrap, namespace, settings, migrations and runtime registration.
- Shared contracts contain only small portable types and deterministic logic; no dependency on a module's enabled state.
- DBVC-specific service calls live behind adapters whose consumers do not depend on admin controllers or arbitrary paths.
- The hub consumes the same versioned wire protocol regardless of packaging. Same-repository development does not imply equal deployed versions.
- All durable identities and store ownership have explicit migration semantics. A future folder move must not generate new client identities or reset replay state.

## Independent version dimensions

| Version | Governs |
|---|---|
| DBVC distribution | Plugin release/install/update compatibility |
| Connector/hub module capability version | Available behavior and migration support |
| Protocol major/schema version | Accepted wire shapes and operation semantics |
| Canonicalization profile | Comparable object meaning and hash bytes |
| Storage schema version | Table migration/downgrade compatibility |
| Framework definition version | Immutable approved design-system content |

Older hub/newer client and newer hub/older client must have fixtures. Unsupported new behavior should be held while still-compatible reporting continues. Unknown storage versions hold the affected module; do not run old writers against a newer schema. A code rollback is not automatically a database rollback.

## When to extract

Use evidence: hub releases must ship independently, distribution includes non-DBVC consumers, external customers need a separate product, or measured hosting/queue needs require a service boundary. Repository size or hypothetical future scale alone is insufficient.

Suggested progression: in-plugin add-ons → independently packaged hub plugin in the same repository → separate repository/service only if needed. The connector can remain a DBVC add-on while the hub becomes independent.

## Extraction rehearsal

Before extraction, run a composition test where hub code receives adapters/contracts explicitly and no connector runtime is loaded. Check plugin-path/URL construction, text domains, capability ownership, migration registration, cron cleanup, resource packaging, credential binding and table prefixes. Search for direct imports from Visual Editor and source references back into `docs/dropins`.

The first standalone product may deliberately depend on DBVC. Removing that dependency is a separate decision requiring stable libraries or service APIs, not copying engines. Preserve existing table names until a migration needs to change them. During dual installation only one owner may register each route/job/table migration. Disable the legacy owner before activating the replacement, with an upgrade handoff that preserves credentials, cursors and operation receipts.

## DBVC V2

A V2 label can package the mature capability later, but it should not justify a blanket rewrite now. Keep manual site tools, reporting, and later connected apply using common verified engine services. Keep the Visual Editor optional unless a future product decision has its own concrete rationale and migration plan.
