# 05 · Diff Engine and Matching Rules

This is the most important file in the handoff.

A good UI cannot rescue a weak diff engine.

## Core approach

For each selected domain:

1. read source package domain data
2. read local site domain data
3. normalize both through the same normalizer
4. match objects based on domain-specific rules
5. classify drift
6. generate per-row decisions and bulk suggestions

## Recommended drift statuses

At the row/object level, support these statuses:

- `identical`
- `new_in_source`
- `missing_from_source`
- `value_changed`
- `same_name_different_id`
- `same_id_different_name`
- `added_props`
- `removed_props`
- `changed_props`
- `dependency_warning`
- `nonportable_warning`
- `conflict`
- `skipped`

At the domain level, support:

- `clean`
- `has_drift`
- `has_conflicts`
- `unsafe`
- `requires_attention`

## Matching strategy by domain

## 1) Bricks Settings
Type: singleton / whole-object

### Match rule
- entire domain is one object

### Drift rule
- compare normalized object by deep hash and path-level diff

### Apply options
- replace selected subkeys
- replace whole domain
- skip

### Recommendation
Prefer **subkey-aware compare** if feasible, otherwise phase this as whole-object compare with expandable path diff.

## 2) Color Palettes
Type: record set

### Likely match keys
- palette name
- slug / token if present
- fallback fingerprint of core identifying props

### Drift rule
- name exists only in source -> `new_in_source`
- name exists in both but normalized payload differs -> `value_changed`
- same semantic name but changed internal id -> `same_name_different_id`

### Apply options
- add
- replace
- keep target
- skip

## 3) Global Classes
Type: record set

### Recommended primary match order
1. exact normalized `name`
2. exact `id`
3. fingerprint similarity fallback only for warning, never auto-merge silently

### Why name first
For portability, class **name** is the semantic identity users care about.
Internal Bricks ids are useful, but cross-site portability should not assume ids stay canonical.

### Example classifications
- source class name `alt__btn` missing on target -> `new_in_source`
- target has `alt__btn` with different `id` and different settings -> `same_name_different_id`
- target has same `id` but renamed class -> `same_id_different_name`
- same name and same id but settings differ -> `value_changed`

### Object-level prop diff
Surface:
- changed settings paths
- added paths
- removed paths
- dependency refs changed

### Apply options
- add as new
- replace target by matched name
- replace target by matched id
- merge settings paths *(advanced)*
- keep target
- skip

### Recommendation
For MVP, avoid arbitrary deep merge for class settings.  
Use:
- add
- replace matched target object
- keep target
- skip

## 4) Global CSS Variables
Type: record set

### Recommended primary match order
1. variable token / name
2. id if present
3. no fuzzy auto-match

### Drift classification
- source-only token -> `new_in_source`
- same token, different value -> `value_changed`
- same token but different category linkage -> still `value_changed`
- same name, different id -> `same_name_different_id`

### Apply options
- add
- replace
- keep target
- skip

## 5) Pseudo Classes
Type: record set

### Match keys
- name / selector
- id secondarily

### Apply options
- add
- replace
- keep target
- skip

## 6) Theme Styles
Type: record set or nested singleton depending on Bricks structure

### Match keys
- style name
- id secondarily

### Drift handling
Need nested object diff and reference extraction.

### Apply options
- add
- replace
- keep target
- skip

## 7) Components
Type: record set

### Match keys
- component name
- slug/key if available
- id secondarily

### Additional checks
Extract dependencies:
- classes used
- variables used
- icons used
- images/media refs if any

### Special status
If imported component references missing dependencies on target, mark:
- `dependency_warning`

### Apply options
- add
- replace
- keep target
- skip

### Recommendation
Allow import despite dependency warnings, but require explicit acknowledgment.

## 8) Breakpoints
Type: singleton / ordered list

### Match rule
- compare canonical breakpoint definitions by ordered set
- compare labels, widths, keys, and enabled flags

### Apply options
- replace all breakpoints
- replace selected entries only if Bricks supports safe granular mutation
- keep target
- skip

### Recommendation
Treat breakpoints as high-risk. Use stronger warnings.

## Normalization rules

Normalization should remove noise such as:

- timestamps
- `modified`
- `user_id`
- ephemeral ordering if ordering is not semantically meaningful
- internal UI-only metadata
- generated values if derivable

Normalization should preserve:

- names
- ids
- semantic settings
- category linkage
- references
- order when order affects output
- nested style values

## Path-based diff output

Every row with actual drift should provide a path summary like:

```json
{
  "changed": [
    "_background.color.raw",
    "_typography.color.raw",
    "_border.radius.top"
  ],
  "added": [
    "iconGap"
  ],
  "removed": []
}
```

## Decision presets

Each drift row should get a suggested default:

- `new_in_source` -> suggested `add`
- `value_changed` -> suggested `replace_target`
- `same_name_different_id` -> suggested `replace_target_preserve_source_semantics`
- `dependency_warning` -> suggested `skip` or `review_required`
- `identical` -> `no_action`

## Non-destructive principle

Never auto-delete target-only objects unless the user explicitly chooses a delete/sync-down mode.

MVP should be **add/replace/keep/skip**, not full destructive synchronization.
