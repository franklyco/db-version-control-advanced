# DBVC Agent Inspection Recipes

Status: Current read-only recipes for the active capability manifest.  
Boundary: These recipes inspect DBVC state and registration only. They do not authorize imports, applies, uploads, deletes, restores, exports, or remote writes.

Use the generated [command index](generated/index-by-command.md) and canonical `manifest.json` record before adapting a command. Recipe commands are deliberately narrow examples, not a second command-signature authority.

## Capability And Checkout Preflight

<!-- recipe: capability-checkout-preflight -->
<!-- safety: read_only -->
<!-- capability-records: cli.core.capabilities.inspect, admin.core.capability_landscape -->

Use before planning work against a DBVC checkout.

```bash
wp dbvc capabilities doctor --format=json
wp dbvc capabilities list --status=active --format=json
```

Stop when doctor reports an error, strict ownership is not complete, or the active checkout is not the intended client site. A baseline warning means some capabilities remain operation-level unverified; it is not permission to invoke them.

Load one relevant record before continuing:

```bash
wp dbvc capabilities show cli.core.import --format=json
```

Replace the example ID with the record selected from `list`. Read its safety, requirements, storage, warnings, verification, and known gaps before planning an action.

## Proposal Readiness Inspection

<!-- recipe: proposal-readiness-inspection -->
<!-- safety: read_only -->
<!-- capability-records: cli.core.capabilities.inspect, cli.core.proposals.list, proposal.core.inspect -->

Use to inspect one already identified staged proposal and its readiness blockers without applying, recapturing, or cleaning anything. The proposal ID must come from the task, authenticated administrator UI, or another already-reviewed source.

```bash
wp dbvc capabilities show cli.core.proposals.list --format=json
wp dbvc proposals list --id=<proposal-id> --fields=id,status,readiness,files,media,snapshot_untrusted,missing_hashes,decisions --fail-on-pending
```

Do not run the recipe as an unscoped all-proposal readiness scan: readiness expansion can be expensive on a site with many staged proposals. `--fail-on-pending` changes only the process exit status. Do not add `--recapture-snapshots` or `--cleanup-duplicates`; those flags cross the recipe's read-only boundary. Stop after reporting blockers and request separate authorization for remediation or apply work.

## Media Hydration Preflight

<!-- recipe: media-hydration-preflight -->
<!-- safety: read_only -->
<!-- capability-records: cli.core.capabilities.inspect, media.core.hydration -->

Use to inventory attachment/file state before deciding whether a mirror or hydration workflow is necessary.

```bash
wp dbvc capabilities show media.core.hydration --format=json
wp dbvc media inventory --limit=10 --format=json
```

Start with 10 items to keep agent context bounded. Pagination and filters may be selected from the generated command index after reviewing the first summary. Do not substitute `mirror_export` or `hydrate`: mirror export writes package artifacts, while hydrate requires an explicit dry-run or apply mode and has a separate authorization boundary.

## Resolver And Snapshot Context

<!-- recipe: resolver-snapshot-context -->
<!-- safety: read_only -->
<!-- capability-records: cli.core.capabilities.inspect, cli.core.resolver_rules.list, cli.core.snapshots.list -->

Use before proposal/media decisions or recovery planning to understand current reusable media rules and recent snapshot history.

```bash
wp dbvc resolver-rules list_ --fields=original_id,action,target_id,note
wp dbvc snapshots --limit=20
```

The callable resolver leaf is currently `list_`. Treat media IDs and rule targets as environment-scoped. Snapshot presence is not proof that a particular mutation has a complete or tested rollback path.

## Content Migration V2 Run Inspection

<!-- recipe: content-migration-run-inspection -->
<!-- safety: read_only -->
<!-- capability-records: cli.core.capabilities.inspect, cli.content_migration.runs.inspect, addon.content_migration.runtime_guard -->

Use to review existing Migration Mapper V2 run state before planning readiness, packaging, execution, import, or recovery work.

```bash
wp dbvc capabilities show cli.content_migration.runs.inspect --format=json
wp dbvc content-migration runs list --limit=25 --format=json
wp dbvc content-migration runs show <reviewed-run-id> --activity-limit=12 --format=json
```

Select the exact run ID from the bounded list or authenticated V2 UI. `list` returns each domain's latest materialized run; `show` can inspect a historical run from existing journey logs and accepts `--domain=<exact-domain>` to bound large searches. Output excludes event messages, source URLs, raw values, artifact paths, and per-URL payloads. Stop after reporting run, inventory, stage, issue, and recent-activity summaries. Do not add readiness, create, visibility, rerun, fixture, package, execution, recovery, raw, download, apply, import, rollback, remote, or AI-queue behavior.

## Bricks Drift Inspection

<!-- recipe: bricks-drift-inspection -->
<!-- safety: read_only -->
<!-- capability-records: cli.core.capabilities.inspect, cli.bricks.drift.inspect, addon.bricks.drift -->

Use before Bricks package review or any proposed Bricks apply. The package ID or manifest file must already be identified by the task, authenticated administrator UI, or another reviewed local source.

```bash
wp dbvc capabilities show cli.bricks.drift.inspect --format=json
wp dbvc bricks drift --package-id=<reviewed-package-id> --limit=25 --format=json
```

For a reviewed local manifest, replace `--package-id` with `--file=<absolute-local-path>`. Use `--artifact-uid=<exact-uid>` to narrow a large package and `--fail-on-drift` when the calling automation needs a nonzero exit for any non-clean artifact. Fingerprint status controls clean/non-clean classification; informational path differences can remain on a clean artifact after canonicalization. The output includes hashes and path names but deliberately excludes raw artifact values. Stop after reporting drift; do not infer apply, package promotion, publishing, pull, restore, or remote authority from a clean result.

## Bricks Control-Plane Inspection

<!-- recipe: bricks-control-plane-inspection -->
<!-- safety: read_only -->
<!-- capability-records: cli.core.capabilities.inspect, cli.bricks.doctor, addon.bricks.control_plane -->

Use before Bricks configuration, package, proposal, remote, apply, or restore planning to confirm the add-on's current operating mode and schema assumptions.

```bash
wp dbvc capabilities show cli.bricks.doctor --format=json
wp dbvc bricks doctor --format=json
```

The result contains bounded status flags, UI-contract features, live theme-style/component schema shape and counts, deprecations, and runtime-health warnings. It omits site identity/URLs, raw Bricks values, stored UI diagnostic events, and package-delivery history. Use `--fail-on-warnings` only when automation should stop on health or schema warnings. Stop after inspection; the command does not authorize telemetry, settings, package, fleet, onboarding, command-queue, remote, proposal, apply, restore, or rollback operations.

## Entity Editor Cached Inspection

<!-- recipe: entity-editor-cached-inspection -->
<!-- safety: read_only -->
<!-- capability-records: cli.core.capabilities.inspect, cli.entity_editor.inspect, entity_editor.core.inspect -->

Use to inventory supported sync JSON entities and inspect one already-indexed file before planning an edit or import workflow. The command reads only an existing transient or disk index and never rebuilds it.

```bash
wp dbvc capabilities show cli.entity_editor.inspect --format=json
wp dbvc entity-editor list --max-age=900 --limit=25 --format=json
wp dbvc entity-editor inspect <reviewed-relative-path> --max-age=900 --format=json
```

Select the relative path from the bounded list output or authenticated Entity Editor UI. Listing exposes cache source, age, staleness, identity/match metadata, and duplicate classification. Inspect adds file size, SHA-256, modification time, and structural counts but no raw JSON values. If the cache is missing or older than the selected `--max-age`, stop: index rebuild writes transient and disk caches and requires a separate boundary. Do not substitute rebuild, download, raw content, lock takeover, save, merge, import, delete, or transfer-packet actions.

## Agent Stop Rules

Stop the recipe and report the boundary when any of these occur:

- the active checkout/site does not match the intended target;
- strict ownership or packaged-artifact checks fail;
- a record is non-current, unverified for the needed operation, or has unknown safety;
- the next command would add an apply, upload, import, export, delete, restore, cleanup, recapture, remote publish, or confirmation flag;
- required authorization, backup, rollback, nonce, credentials, or environment identity is absent.

Moving beyond a stop rule requires a separately reviewed task boundary. A successful inspection recipe never implies write authorization.
