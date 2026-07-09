# Bricks Stats To DBVC Bricks Portability Handoff

## Purpose

This package gives DBVC implementation work a compact handoff from Vertical's Bricks Stats tool. The goal is to help DBVC build similar Bricks Portability functionality for:

- current-site Bricks artifact inventory
- where templates/classes/variables/components/fonts/query loops are used
- which Bricks artifacts a selected template depends on for transfer completeness
- missing frontend template coverage, such as CPT archive or single coverage gaps
- source/destination ID mapping and Bricks payload remapping needs
- pre-import and post-import comparison of Bricks objects
- operator review states that should not mutate Bricks data directly

The source implementation lives in the Vertical child theme:

```text
admin/class-vf-tools-bricks-stats.php
docs/bricks-builder/context/bricks-stats-admin-implementation-guide.md
```

This handoff is intentionally portable. DBVC should use the scanner concepts and manifest contracts, not depend directly on Vertical admin classes.

## Current Vertical Feature Summary

Admin route:

```text
Flourish -> Tools -> Bricks Stats
admin.php?page=flourish-tools&tab=bricks-stats
```

Primary scan action:

```text
admin_post_vf_bricks_stats_scan
```

Generated files:

```text
wp-content/vf-bricks-stats/manifests/<manifest-id>/manifest.json
wp-content/vf-bricks-stats/manifests/<manifest-id>/summary.json
wp-content/vf-bricks-stats/manifests/<manifest-id>/artifacts.json
wp-content/vf-bricks-stats/manifests/<manifest-id>/references.json
wp-content/vf-bricks-stats/manifests/<manifest-id>/query-loops.json
wp-content/vf-bricks-stats/manifests/<manifest-id>/frontend-coverage.json
wp-content/vf-bricks-stats/manifests/<manifest-id>/dependency-ledger.json
wp-content/vf-bricks-stats/manifests/<manifest-id>/unresolved-references.json
wp-content/vf-bricks-stats/manifests/<manifest-id>/scan-log.json
```

Important safety boundary:

- Scans are read-only.
- Controlled writes are limited to WordPress taxonomy relationships and Vertical-owned `vf_bricks_details`.
- Scans do not mutate Bricks options, Bricks element JSON, global classes, variables, components, fonts, or theme styles.

## Main Concepts For DBVC

1. **Artifact Manifest**
   A normalized list of Bricks and related site artifacts. DBVC can use this as the basis for source-site inventory, destination-site inventory, and portability diffs.

2. **Reference Graph**
   Directed records showing where one artifact references another. This is the key DBVC portability primitive for deciding what must travel together.

3. **Dependency Ledger**
   A transfer-grade graph that promotes raw references into include/remap decisions. This is where linked templates, popup/offcanvas template references, global class dependencies, component/global-element dependencies, and source-site IDs become actionable import requirements.

4. **Frontend Coverage**
   A coverage report that compares public custom objects against published/private Bricks template conditions. DBVC can use this to identify missing template coverage before or after imports.

5. **Operator Review Metadata**
   Vertical stores review metadata in `vf_bricks_details`. DBVC may need its own review state instead of copying this exact meta key.

6. **Policy Overrides**
   Planned Vertical work will add coverage policies such as `not_needed` and `covered_manually`. DBVC should plan for similar per-site operator decisions.

## Package Files

- `artifact-manifest-contract.md` - normalized artifact/reference/manifest shapes and scanner source map.
- `dependency-ledger-contract.md` - transfer dependency, source/destination mapping, and ID remap contract.
- `frontend-coverage-contract.md` - coverage gap rules and DBVC portability use cases.
- `dbvc-adapter-checklist.md` - suggested DBVC implementation phases and QA steps.

## Recommended DBVC Read Order

1. Read this `README.md`.
2. Read `artifact-manifest-contract.md`.
3. Read `dependency-ledger-contract.md`.
4. Read `frontend-coverage-contract.md`.
5. Read `dbvc-adapter-checklist.md`.
6. Compare against DBVC's current Bricks Portability feature docs and adapt names/storage to DBVC conventions.

## Open Decisions For DBVC

- Whether DBVC should store scan manifests as local plugin artifacts, database records, or both.
- Whether DBVC should expose a WordPress admin UI, CLI-only scanner, REST endpoint, or all three.
- Whether review/coverage policy state belongs to the source site, destination site, DBVC migration session, or remote snapshot.
- Whether DBVC should import Vertical's `vf_bricks_details` as source metadata or map it to DBVC-owned review state.
- How DBVC should handle site-specific runtime template routes that are not native Bricks conditions.
- How DBVC should store source/destination artifact mappings across repeated imports and rollback attempts.
