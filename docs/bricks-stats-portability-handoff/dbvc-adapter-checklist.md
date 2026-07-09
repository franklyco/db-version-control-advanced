# DBVC Bricks Portability Adapter Checklist

## Phase A - Inventory Scanner

- [ ] Add a DBVC scanner that can run on the current WordPress site without writing Bricks data.
- [ ] Read Bricks templates, template settings, template content, and native template taxonomies.
- [ ] Read Bricks global classes, variables, palettes, components, global elements, settings, theme styles, custom fonts, and support tables.
- [ ] Extract query loops and dynamic data tags from templates/components/global elements.
- [ ] Emit a normalized artifact list and reference graph.
- [ ] Persist scan output in a DBVC-owned artifact location or database table.

## Phase B - Portability Dependency Graph

- [ ] Build dependency bundles from selected templates.
- [ ] Include referenced classes, variables, palettes, components, global elements, fonts, dynamic tags, and linked templates.
- [ ] Detect Bricks `template` elements where `settings.template` points to another `bricks_template`.
- [ ] Detect popup/offcanvas interaction references to other Bricks templates.
- [ ] Emit a dependency ledger with include/remap policy, not only raw references.
- [ ] Embed compact `dbvc_portability.dependency_hints` in individual template JSON exports.
- [ ] Report unresolved references before any import.
- [ ] Compare source and destination artifacts by stable IDs, names, slugs, and Bricks IDs where available.
- [ ] Produce conflict states such as `destination_exists`, `missing_dependency`, and `requires_mapping`.

## Phase C - Source/Destination ID Ledger And Remapping

- [ ] Create an import-session mapping ledger for source artifact IDs to destination artifact IDs.
- [ ] Store mappings by artifact domain, source site, destination site, source artifact UID, destination artifact UID, and match strategy.
- [ ] Preview all remap operations before writing destination Bricks payloads.
- [ ] When a single-template JSON is imported without a full manifest, use embedded dependency hints for warnings and mapping prompts.
- [ ] Remap template IDs in `settings.template`, popup/offcanvas interactions, shortcode IDs, and known Bricks template-ID setting paths.
- [ ] Remap component/global-element IDs when destination IDs differ from source IDs.
- [ ] Preserve an audit record for every remapped path, old source ID, new destination ID, and dependency UID.
- [ ] Block import completion when a required dependency has no destination mapping.
- [ ] Re-scan destination imports and confirm no imported payload still references source-only IDs.

## Phase D - Frontend Coverage Gap Scanner

- [ ] Port the coverage target logic from Vertical Bricks Stats.
- [ ] Detect CPT single coverage from `postType` conditions.
- [ ] Detect CPT archive coverage from `archivePostTypes` conditions.
- [ ] Detect custom taxonomy archive coverage from taxonomy/terms conditions.
- [ ] Add DBVC-specific adapters for runtime route filters, ACF/options-held template IDs, and known fallback templates.
- [ ] Compare source coverage with destination coverage.

## Phase E - Operator Policy And Review State

- [ ] Decide where DBVC stores coverage policy and portability review state.
- [ ] Keep raw scanner facts separate from operator decisions.
- [ ] Support `needed`, `needs_template`, `not_needed`, `covered_manually`, and `deferred`.
- [ ] Include notes and linked template IDs.
- [ ] Audit policy changes.

## Phase F - Preview-First Writes

- [ ] Keep inventory and coverage scans read-only.
- [ ] Require preview before imports, mapping changes, or draft scaffold creation.
- [ ] Use WordPress APIs for posts, terms, meta, options, and files.
- [ ] Never update Bricks option payloads without a destination backup and explicit operator confirmation.
- [ ] Record before/after manifests for rollback or audit.

## Phase G - Admin UI Or CLI Surface

- [ ] Show current-site Bricks artifact inventory.
- [ ] Show dependency graph for selected templates.
- [ ] Show linked-template dependencies and unresolved remap requirements for selected templates.
- [ ] Show frontend coverage gaps.
- [ ] Show source/destination portability gaps.
- [ ] Add filters for domain, status, template type, coverage type, and unresolved dependencies.
- [ ] Add live filtering where possible, with server-side fallback.

## Phase H - QA

- [ ] Verify scans do not write Bricks options, Bricks template content meta, or global class/variable payloads.
- [ ] Verify a known CPT archive template is detected through `archivePostTypes`.
- [ ] Verify a missing CPT archive is reported as a coverage gap.
- [ ] Verify query loops are extracted from template element trees.
- [ ] Verify dependency bundles include referenced classes/variables/components.
- [ ] Verify a Bricks `template` element dependency is detected and remapped during import preview.
- [ ] Verify popup/offcanvas template dependencies are included or explicitly blocked.
- [ ] Verify unresolved references block or warn imports.
- [ ] Verify destination conflicts are reported before import.

## Useful Vertical Source References

```text
VF_Tools_Bricks_Stats::build_manifest()
VF_Tools_Bricks_Stats::scan_template_post()
VF_Tools_Bricks_Stats::build_frontend_coverage_report()
VF_Tools_Bricks_Stats::frontend_coverage_expected_targets()
VF_Tools_Bricks_Stats::frontend_coverage_detected_targets()
VF_Tools_Bricks_Stats::build_query_loop_artifacts()
VF_Tools_Bricks_Stats::build_unresolved_references()
```

## Notes For Tailoring In DBVC

- Replace Vertical-specific admin placement with DBVC's Bricks Portability UI.
- Replace `vf_bricks_details` with DBVC-owned review state unless DBVC explicitly needs to preserve Vertical metadata.
- Keep the scan manifest portable enough to store alongside DBVC exports/import plans.
- Treat frontend coverage as an import readiness signal, not only a site health report.
