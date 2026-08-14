# DBVC Agent Reference Taxonomy

Status: Initial controlled vocabulary for Phase 1/2. Additions require schema and generator updates.

## Record IDs

Use lowercase dotted identifiers that remain stable when display labels or categories change.

Examples:

- `cli.core.import`
- `rest.core.proposals.apply`
- `addon.bricks.drift_scan`
- `setting.core.media_transport`

Do not encode a temporary phase number, source line, or implementation class into an ID.

## Primary Categories

- `import_export`
- `cli_automation`
- `proposal_review`
- `media_resolver`
- `identity_entities`
- `snapshots_backups`
- `entity_editor`
- `settings_configuration`
- `api_extensions`
- `addon_bricks`
- `addon_content_migration`
- `observability`
- `internal_foundation`

Each record has exactly one primary category. Cross-cutting discovery belongs in tags.

## Namespaced Tags

| Namespace | Allowed initial values | Meaning |
|---|---|---|
| `surface:` | `cli`, `rest`, `admin`, `php`, `hook`, `ajax`, `admin_post`, `cron`, `filesystem`, `database` | Exposure or invocation surface |
| `operation:` | `inspect`, `list`, `preview`, `validate`, `export`, `import`, `upload`, `download`, `route`, `compare`, `apply`, `delete`, `restore`, `configure`, `diagnose`, `generate` | Material operation |
| `object:` | `post`, `term`, `media`, `menu`, `option`, `acf_options`, `bricks_template`, `package`, `proposal`, `snapshot` | Primary object or artifact |
| `scope:` | `core`, `addon:bricks`, `addon:content_migration`, `source_reference` | Runtime ownership or reference boundary |
| `risk:` | `read_only`, `filesystem_write`, `wordpress_write`, `remote_write`, `destructive`, `requires_backup` | Material consequence |
| `workflow:` | `client_onboarding`, `site_migration`, `proposal_review`, `deployment`, `recovery`, `development` | Common task context |
| `status:` | Manifest status values | Reviewed status mirror for index lookup |

Tag arrays must be unique and sorted lexically by the generator.

## Query-Only Filters

`composer agent-docs:query -- ...` accepts manifest tags and these derived filters that are not stored as tags:

- `status:<manifest-status>`
- `category:<primary-category>`
- `safety:<record-safety-classification>`
- `id:<record-id>`
- `opportunity:<disposition>`
- `priority:<high|medium|low|none>`
- `effort:<small|medium|large|unknown>`
- `recommended:<cli|rest|admin|php|docs|none>`

Unprefixed terms search record IDs and aliases. Use `safety:read_only` when the entire returned record must be read-only. A `risk:read_only` tag can also belong to a `mixed` record with separate write behavior.

Facet links are derived from primary category and status. Non-active records link to both their subject facet and `staged-planned-and-absent.md` when those differ.

## Status Values

- `active`
- `experimental`
- `planned`
- `source_reference`
- `deprecated`
- `absent_current_checkout`
- `unknown_requires_verification`

Repository discovery alone may create only `unknown_requires_verification` suggestions. A human review is required before another status is assigned.

## Opportunity Metadata

Opportunity metadata records a reviewed implementation judgment; it is not mechanically inferred from the presence of REST without CLI.

- `candidate`: a bounded enhancement has concrete agent/operator value.
- `covered_elsewhere`: another mapped record already provides the relevant interface.
- `deferred`: potentially useful, but a known dependency or sequencing boundary comes first.
- `not_recommended`: parity would increase risk or complexity without enough value.
- `needs_review`: the record groups behavior that must be split or examined before prioritization.

Every reviewed opportunity also records priority, estimated effort, recommended interface, rationale, and review date. A `candidate` must additionally declare one bounded `candidate_scope` plus explicit `excluded_operations`; these are safety boundaries, not authorization to implement or invoke the candidate. Optional `next_action` and `related_record` fields keep the queue actionable without turning the manifest into an implementation guide. Records without this object remain `unreviewed` in generated and administrator views.

## Alias Rules

Aliases capture natural-language and historical terminology without creating duplicate records.

- Include exact command names when relevant, such as `wp dbvc import`.
- Include likely task language, such as `headless import` or `attachment reconciliation`.
- Preserve historical names only when they improve discovery.
- Do not use aliases to merge capabilities with different safety or authorization boundaries.

## Grouping Rules

Not every discovered implementation item receives an independent record.

- Group REST methods when they form one inseparable workflow and share risk/authority.
- Keep read-only and write operations separate when agents may safely invoke only one side.
- Group settings keys under an owning behavior when individual keys have no useful standalone meaning.
- Keep source-reference, planned, and checkout-absent items separate from active runtime records.
- Record ignored discovery items explicitly with a reason; never silently drop a public surface.

Each manifest `surface` keeps a human-facing `identifier` plus a `discovery_ids` array. The array maps a grouped capability back to every exact source-discovery item used for drift coverage.
