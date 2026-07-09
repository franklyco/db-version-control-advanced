# Bricks Dependency Ledger And ID Remap Contract

## Purpose

Bricks template portability needs more than a list of templates. A selected `bricks_template` can depend on other Bricks artifacts inside its element tree, settings, interactions, classes, variables, shortcodes, components, global elements, fonts, dynamic tags, and query loops.

The dependency ledger is the transfer-grade graph DBVC should use to answer:

- What else must travel with this selected template?
- Which referenced artifacts are missing or ambiguous?
- Which source-site IDs must be remapped after import?
- Which Bricks payload paths must be updated before writing destination data?

Vertical Bricks Stats can produce this ledger for current-site visibility. DBVC Bricks Portability should produce and consume the same concept for source/destination transfer planning.

## References Versus Dependencies

`references.json` is the raw observed graph. It records that one artifact mentions another artifact.

`dependency-ledger.json` is the migration contract. It enriches the same edge with dependency type, include policy, remap policy, source location, destination mapping state, and import-blocking status.

Raw references should remain useful for stats and diagnostics. Dependency records should be used for transfer bundles, import previews, ID remaps, and post-import validation.

## Full Ledger Versus Single-Template Hints

DBVC should support two portability modes:

- Full manifest mode: use `dependency-ledger.json` as the authoritative source for transfer closure.
- Single-template mode: use dependency hints embedded in an individual DBVC JSON export to surface likely dependencies and remap needs when no separate manifest file is present.

Single-template hints are intentionally soft. They make rapid one-off migrations easier, but DBVC must re-resolve them against the source export and destination site before writing destination data.

## Dependency Record Shape

```json
{
  "dependency_uid": "template:940:element:tpiukz:template:4775",
  "source_artifact_uid": "template:940",
  "source_artifact_type": "bricks_template",
  "target_artifact_uid": "template:4775",
  "target_artifact_type": "bricks_template",
  "dependency_domain": "template",
  "dependency_type": "bricks_template_element_template",
  "location": {
    "post_id": 940,
    "meta_key": "_bricks_page_content_2",
    "element_id": "tpiukz",
    "element_name": "template",
    "setting_path": "settings.template"
  },
  "source_target_id": 4775,
  "target_lookup": {
    "source_post_type": "bricks_template",
    "source_slug": "",
    "source_title": "",
    "template_type": "",
    "content_hash": ""
  },
  "include_in_transfer": true,
  "remap_required": true,
  "remap_strategy": "source_id_to_destination_id",
  "destination_target_id": null,
  "status": "unresolved",
  "confidence": "high"
}
```

## Required Dependency Types

| Type | Target | Include In Transfer | Remap Required |
| --- | --- | --- | --- |
| `global_class_usage` | `global_class:<id>` | Yes | Usually yes when class IDs change. |
| `css_variable_usage` | `variable:<name>` | Yes | No when variable names remain stable. |
| `bricks_template_element_template` | `template:<post_id>` | Yes | Yes. |
| `bricks_popup_template_reference` | `template:<post_id>` | Yes | Yes. |
| `bricks_offcanvas_template_reference` | `template:<post_id>` | Yes | Yes. |
| `bricks_shortcode_template_reference` | `template:<post_id>` | Yes | Yes. |
| `bricks_template_setting_reference` | `template:<post_id>` | Yes | Yes. |
| `component_reference` | `component:<id>` | Yes | Yes when component IDs change. |
| `global_element_reference` | `global_element:<id>` | Yes | Yes when global element IDs change. |
| `custom_font_reference` | `custom_font:<id-or-family>` | Yes | Sometimes; file and family matching may be enough. |
| `dynamic_tag_reference` | `dynamic_tag:<tag>` | Report | No direct ID remap unless the tag points to moved fields. |
| `query_loop_object_dependency` | `object:<type-or-taxonomy>` | Report | No direct Bricks ID remap; destination object must exist. |

## Template Element Example

Observed source export:

```json
{
  "id": "tpiukz",
  "label": "Reviews",
  "name": "template",
  "settings": {
    "noRoot": true,
    "template": "4775"
  }
}
```

Ledger interpretation:

```text
source artifact: template:940
dependency type: bricks_template_element_template
target artifact: template:4775
location: _bricks_page_content_2 element tpiukz settings.template
transfer action: include target template or block/warn
remap action: replace 4775 with the destination template ID after import
```

## Portable Template Dependency Hints

Each individual `bricks_template` artifact or DBVC template JSON export may include a compact dependency/dependent snapshot:

```json
{
  "dbvc_portability": {
    "dependency_hints": {
      "schema": "dbvc-bricks-template-dependency-hints.v1",
      "generated_at": "2026-07-02T12:00:00-04:00",
      "source_site_uid": "officialflourishblueprint.local",
      "source_artifact_uid": "template:940",
      "scope": "direct",
      "is_authoritative": false,
      "dependencies": [
        {
          "dependency_uid": "template:940:element:tpiukz:template:4775",
          "dependency_type": "bricks_template_element_template",
          "target_artifact_uid": "template:4775",
          "target_id": 4775,
          "target_label": "Reviews",
          "target_slug": "reviews",
          "location": {
            "element_id": "tpiukz",
            "element_name": "template",
            "setting_path": "settings.template"
          },
          "include_in_transfer": true,
          "remap_required": true
        }
      ],
      "dependents": [
        {
          "source_artifact_uid": "template:1234",
          "source_id": 1234,
          "source_label": "Service Single",
          "reference_type": "bricks_template_element_template",
          "location_summary": "element abc settings.template"
        }
      ],
      "counts": {
        "dependencies": 1,
        "dependents": 1,
        "remap_required": 1,
        "unresolved": 0
      }
    }
  }
}
```

Definitions:

- `dependencies`: artifacts this template uses and may need to bring with it.
- `dependents`: artifacts that use this template; useful for impact review, replacement planning, and cleanup, but not normally required to transfer this template.
- `is_authoritative`: always `false` for embedded hints unless the JSON package also contains the complete dependency ledger and source artifact set.

Do not store the full graph in `vf_bricks_details`. If Vertical needs per-template human decisions, store only review state such as ignored dependency UIDs, intentional external dependencies, notes, reviewer, and reviewed manifest ID.

## Source/Destination Mapping Ledger

DBVC should maintain a separate mapping ledger for each import session:

```json
{
  "mapping_uid": "session:abc:template:4775",
  "session_id": "abc",
  "source_site_uid": "officialflourishblueprint.local",
  "destination_site_uid": "frameworkflo-live.local",
  "artifact_domain": "template",
  "source_artifact_uid": "template:4775",
  "source_id": 4775,
  "destination_artifact_uid": "template:108204",
  "destination_id": 108204,
  "match_strategy": "created_from_source",
  "confidence": "high",
  "created_at": "2026-07-02T12:00:00-04:00"
}
```

Recommended match strategies:

```text
created_from_source
matched_by_export_uid
matched_by_slug_and_type
matched_by_title_type_hash
operator_selected
external_existing
deferred_unmapped
```

## Remap Workflow

1. Scan source site and produce artifacts, references, and dependency ledger.
2. Select one or more source templates for transfer.
3. Build a transitive dependency bundle from selected templates.
4. Resolve every required target to either included, destination-existing, operator-selected, intentionally external, or blocked.
5. Import/create destination artifacts in dependency-safe order.
6. Write source-to-destination mapping ledger entries.
7. Run a remap preview against copied Bricks payloads.
8. Replace source IDs with destination IDs at known paths such as `settings.template`, popup/offcanvas interaction settings, shortcode IDs, component IDs, and global element IDs.
9. Write destination payloads only after preview approval.
10. Re-scan destination and verify imported records no longer reference source-only IDs.

## Import Blocking Rules

Block or require explicit operator override when:

- a required dependency target is missing from the source export
- a required target has multiple ambiguous destination matches
- a required template ID appears in a known remap path but no destination mapping exists
- an interaction references a popup/offcanvas template that is not included or mapped
- a component/global element dependency cannot be mapped and is still referenced by imported payloads

Warnings are acceptable when:

- a dynamic tag references a field that appears to be intentionally site-specific
- a query loop targets an object type that exists on source but not destination and the operator plans to create that object later
- a font family can be matched by family/file name even if the source post ID changes

## Bricks Stats UI Expectations

Vertical Bricks Stats should surface dependency-ledger information as current-site diagnostics:

- dependency count per template
- direct dependency list in the template row details
- unresolved dependency warnings
- dependency type and source location
- whether each dependency would require ID remapping in a DBVC transfer

Bricks Stats should not perform cross-site remapping. DBVC owns source/destination mapping, transfer previews, and write execution.

## DBVC Bricks Portability Expectations

DBVC should use the ledger to:

- auto-include required dependencies in transfer bundles
- preview direct and transitive dependencies before import
- create source/destination mapping entries after artifact creation or matching
- remap IDs in copied Bricks JSON before destination writes
- keep an audit trail of every remapped path
- block imports that would leave source-only template/component/global-element IDs in destination payloads

For single-template DBVC JSON imports without a full manifest, DBVC should use embedded dependency hints to:

- warn that additional templates/classes/components may be needed
- pre-select likely dependencies when they are present in the same package
- prompt the operator to map known destination artifacts
- build a minimal remap preview for known paths
- request a full scan/manifest when hints are stale, ambiguous, or incomplete
