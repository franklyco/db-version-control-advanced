# Vertical Context Provider Feedback From Cross-Site AI Package QA

Status: Historical feedback, reconciled with Vertical provider contract v2
Date: 2026-06-23
Source: Claude Code review of Butler Automation to frameworkflo-live AI sample-package authoring
Use in DBVC: reference only. DBVC should consume these signals when available, but the provider semantics below are owned by Vertical Field Context / Object Type Context.

Update after Vertical contract v2: do not implement a separate `essential_sections` field. The original feedback maps to Field Context's existing `section_selection` control and its `section_group_map`; Object Type Context exposes `section_authoring` to point DBVC at that Field Context source of truth.

## Purpose

The real cross-site authoring pass confirmed that DBVC's package pattern is sound:

- sample JSON owns value shape
- sibling `.context.json` owns authoring meaning
- object context helps route content to the right CPT or taxonomy
- field type, choices, and intent together produce much better AI-filled values

The gaps below should be addressed in Vertical's context provider so DBVC can package a smaller, safer, more useful Compact Authoring Context without hard-coding Vertical-specific field knowledge in DBVC.

## Provider Signals To Add

### 1. Field `cross_site_safety`

Add a field-level enum to every provider field context entry.

Allowed values:

- `portable`: safe to fill from source content on another site. Examples: text, textarea, wysiwyg, plain choices.
- `site_specific`: value is a source-site token, ID, theme token, global CTA key, form ID, palette choice, or other value that should not be copied blindly.
- `media_deferred`: image, file, gallery, or other media field that DBVC AI import v1 should leave null.
- `admin_or_editor`: operational/editorial controls, sorting flags, writer options, style overrides, or other non-content fields that should usually stay at defaults.

Recommended agent behavior:

| `cross_site_safety` | Default authoring behavior |
|---|---|
| `portable` | Fill from source content. |
| `site_specific` | Leave blank unless a target equivalent is known. |
| `media_deferred` | Set null / leave empty for v1. |
| `admin_or_editor` | Leave at sample default unless explicitly requested. |

DBVC consumption status on 2026-06-23:

- Compact packages include this field when the provider supplies it.
- AI submission translation imports `portable` normally.
- AI submission translation preserves supported `site_specific` values with review warnings.
- AI submission translation skips `media_deferred` values, records a deferred media descriptor, and avoids blocking the rest of the entity.
- AI submission translation skips `admin_or_editor` and `authoring_priority: do_not_author` values with warnings.

### 2. Object `authoring_profile`

Add an object-level authoring profile hint so DBVC can generate smaller cross-site samples without hiding the full theme contract.

Suggested values:

- `essentials`: hero, intro, SEO, CTA, FAQ, and other minimum useful content groups.
- `extended`: essentials plus benefits, process, team, services, reviews, case studies, articles, and similar supporting sections.
- `all`: everything registered by the theme.

Default recommendation for cross-site/fresh-site authoring: `essentials`.

### 3. Section authoring source of truth

Do not add a separate object-level `essential_sections` list.

Vertical provider contract v2 maps this need to:

- Object Type Context `data.section_authoring`
- Field Context `section_selection`
- Field Context `section_selection.section_group_map`

DBVC should use `section_selection.default_values` and `section_selection.section_group_map` when deciding which frontend sections are selected/default for compact authoring. Do not assume values such as `faq_section` are also ACF group names; use `section_group_map` when present.

### 4. Object routing hints

Object Type Context should include explicit routing guidance for ambiguous object types.

Known needed hint:

```json
{
  "key": "vertical",
  "routing_hint": "Use this CPT for industry or audience landing pages even when the source taxonomy is industry. Tag the vertical with its own industry term."
}
```

This reduces page-vs-vertical ambiguity during AI authoring.

### 5. Site-specific choice meaning

When a field has `cross_site_safety: "site_specific"`, include human-readable choice meaning when known.

Example:

```json
{
  "choices": ["cta_Ae6bvIZ4"],
  "choice_meaning": {
    "cta_Ae6bvIZ4": "Primary global CTA - Book a Consultation"
  }
}
```

Without meaning, DBVC should tell agents to blank the field. With meaning, agents can map to a target-site equivalent where one exists.

### 6. Admin/editor group classification

Mark groups and fields that are not content authoring surface.

Known candidates from QA:

- `core_allpostypes_controls`
- `writer_options`
- sorting controls
- style override controls
- `custom_wsform`
- `admin_page_settings`
- `admin_service_settings`
- premium/business-type/decorative admin flags

Suggested provider signal:

```json
{
  "authoring_surface": "admin_or_editor"
}
```

or equivalent, as long as DBVC can map it to `cross_site_safety: "admin_or_editor"`.

### 7. Shared group identities

Expose stable shared group IDs for groups repeated across CPTs so DBVC can factor duplicated context into a shared package file later.

Known repeated groups:

- `hero_section`
- `intro_section`
- `seo_group`
- `faq_group`
- `cta_section`
- `core_allpostypes_controls`

Suggested provider shape:

```json
{
  "shared_group_id": "vertical.hero_section",
  "shared_group_label": "Hero Section"
}
```

### 8. Term palette/token warnings

Mark taxonomy style token fields as site-specific.

Known example:

- `meta.core_tax_group.term_styles.term_color_default`

Expected provider guidance:

- `cross_site_safety: "site_specific"`
- context warning that theme palette tokens should be left blank on cross-site imports and selected by an operator in WordPress.

### 9. Requiredness and authoring priority

Separate DBVC import-contract required fields from Vertical design-expected fields.

Recommended provider-owned signal:

```json
{
  "authoring_priority": "design_expected"
}
```

DBVC owns hard import requiredness for fields such as `post_title`, `post_name`, `ID`, `term_id`, `taxonomy`, `name`, and `slug`. Vertical should identify design-expected fields such as hero headings or SEO descriptions so DBVC/agents can soft-warn instead of hard-failing.

### 10. Media field classification

Ensure image, file, gallery, and similar media fields carry an explicit media signal so DBVC can consistently emit `cross_site_safety: "media_deferred"` in v1.

Recommended provider signals:

- field type
- media family
- value shape
- authoring note: leave null for cross-site AI import v1

## Term Context Guidance

The compact term ACF shape from the QA pass was effective and should be preserved as the model for term context size.

Good term context groups:

- `core_tax_group`
  - `term_title`
  - `term_h1`
  - `intro_title`
  - `description`
  - `description_short`
  - `term_image`
  - `term_image_secondary`
- `term_seo_group`
- `term_cta_group`

Keep term context small, scannable, and fillable. Mark media fields as deferred and palette/style tokens as site-specific.

## Provider Output To Avoid

Do not make DBVC infer these behaviors from raw field names when Vertical can expose them directly:

- site-specific tokens or IDs without choice meaning
- admin/editor settings mixed into normal content context
- duplicated shared group definitions without stable shared IDs
- ambiguous object routing for vertical-like landing pages
- media field write safety based only on ACF type

## DBVC Consumption Notes

Once Vertical exposes the signals above, DBVC can:

- pass through compact safety and authoring hints into `.context.json`
- add an `essentials` package profile using Object Type Context `authoring_profile` and Field Context `section_selection`
- omit admin/editor fields from the default compact sample shape
- factor repeated group context into shared package artifacts
- warn on selected samples with no returned entities through manifest `target_intent`

DBVC should keep these as optional provider enrichments. If the provider is missing or degraded, DBVC should continue producing valid packages with conservative defaults.
