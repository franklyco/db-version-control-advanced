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

A page section renders only when the page's section_selection holds its choice. Copy written into a section that is switched off is never seen, which is how a whole client import once went invisible. So place copy before you write it:

1. **Place every block first.** For each block of approved copy, name the page, the section choice and the field path, for example `contact`, `faq_section`, `faq_group.header`. The section_group_map maps a choice to its field group (`faq_section` to `faq_group`).
2. **Switch on what the copy needs.** List every section a record writes into. hero_section is always on and need not be listed.
3. **Never disable a section the owner chose** unless the brief tells you to, and never disable hero_section.
4. **No section, no guess.** A block that fits no section choice, including a field group that has none (about_section is one), becomes a gap line, "needs a section". Never move it into a different section to make it fit.

Where the switch goes depends on the package:

- **VerticalFramework client content import** (`vf-client-content-import.v1`, a `post_type:*` record): put the choices in `layout.sections.enable`, beside `identity`. It is additive: the page keeps what it shows now, plus enable, minus disable. Never send section_selection as a field entry; on a page that already has sections the importer keeps it unchanged, so it switches nothing on. `layout` carries sections only, never a template, and never goes on an options record. Contract: the theme's `docs/client-content-import.md` §2 and §3.
- **DBVC submission package** (this skill's ZIP): there is no `layout` key. section_selection is a sampled field like any other, shaped exactly as the sample and its context show it, and DBVC's contract governs how it is written. Steps 1 to 4 still decide which choices it holds.

Site rules for choosing sections:

- **One home per topic.** A topic with its own page (FAQ, team, gallery, reviews, pricing, contact) appears elsewhere only as a teaser: a header, three to six records chosen for that page, and a link to the topic's page. Enabling faq_section anywhere but the FAQ page also means choosing its page_related_items; otherwise the section shows the global FAQ set and repeats the FAQ page.
- **No copy, no section.** Enable a section only when approved copy, real records or consented assets exist for it.
- **Records, not prose, for loop sections.** For FAQ, team, reviews, services, articles, gallery, case studies, insurance and benefits, the page fields hold only the section header and intro. Copy that reads like a record (a question and answer, a bio, a testimonial) is written as that record.

## Compactness and provenance

Use the package-facing context only. Do not copy full Vertical provider maps, raw ACF field definitions, or Bricks layouts into the submission package.
