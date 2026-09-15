# Field Context routing

Read the selected sample JSON and its sibling .context.json together. The sample establishes the JSON surface; Field Context explains a field's meaning, choices, safety, and value shape.

## What to author

- portable: normal content authoring is allowed when the field appears in the sample.
- scalar fields: write the matching scalar value.
- groups: write an object with the sampled logical keys.
- repeaters: write an array of row objects using sampled keys.
- flexible content: write an array of layout objects and include the exact acf_fc_layout value for each layout.
- post and relationship fields: prefer structured references containing the sampled post_type and slug.
- taxonomy fields: prefer structured references containing the sampled taxonomy and slug.

Use declared choices exactly. Do not infer choices, clone ownership, field hierarchy, or layout names from field labels or raw ACF JSON.

## What to leave alone

- site_specific: map it only with explicit target-site guidance; otherwise omit it or preserve the conservative blank shown by the sample.
- media_deferred: keep it empty or null for this v1 package.
- admin_or_editor: do not AI-author it unless the operator explicitly directs an editor-managed value.
- do_not_author: omit it even when the sample exposes it.
- unsupported image, file, and gallery values: leave them empty unless the current sample explicitly shows a supported logical representation.

## Section controls

If Field Context provides section_selection, it is the source of truth for selected frontend sections. Use its choices and section_group_map exactly. Do not substitute a guessed list of sections, raw builder data, or ACF group names.

## Compactness and provenance

Use the package-facing context only. Do not copy full Vertical provider maps, raw ACF field definitions, or Bricks layouts into the submission package.
