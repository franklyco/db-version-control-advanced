# Vertical Context

Status: Current
Last verified: 2026-06-23
Source of truth: `includes/Dbvc/AiPackage/SamplePackageBuilder.php`, `includes/Dbvc/AiPackage/TemplateBuilder.php`, `addons/content-migration/docs/MIGRATION_MAPPER_V2_VERTICAL_FIELD_CONTEXT_RUNTIME_HANDOFF.md`
Read when: a DBVC sample package includes Object Type Context or Field Context from Vertical.
Minimum context: `acf-authoring.md` and the sibling `.context.json` for the selected sample.

## Fast Path

Use package-facing context as authoring guidance. Do not embed or depend on full Vertical provider maps.

Object Type Context helps choose the right CPT or taxonomy. Field Context helps fill the right field with the right value shape.

## Current Contract

Compact sample context files use this package-facing shape:

```json
{
  "artifact_type": "dbvc_ai_sample_context",
  "artifact_schema_version": 1,
  "sample_json_path": "samples/posts/service.json",
  "object": {
    "kind": "post",
    "key": "service",
    "label": "Services",
    "context": "Service pages for client offerings.",
    "authoring_profile": "essentials",
    "routing_hint": "Use for service offering pages.",
    "section_authoring": {
      "available": true,
      "control_field": "section_selection",
      "field_context_required": true
    }
  },
  "fields": {
    "meta.hero_title": {
      "type": "text",
      "choices": [],
      "context": "Primary hero headline.",
      "cross_site_safety": "portable",
      "authoring_surface": "content",
      "authoring_priority": "design_expected"
    },
    "meta.section_selection": {
      "type": "checkbox",
      "choices": {
        "hero_section": "Hero",
        "faq_section": "FAQs"
      },
      "context": "Controls selected frontend sections.",
      "cross_site_safety": "portable",
      "authoring_surface": "operator_control",
      "authoring_priority": "design_expected",
      "section_selection": {
        "available": true,
        "source_of_truth": true,
        "default_values": ["hero_section"],
        "section_group_map": {
          "hero_section": "hero_section",
          "faq_section": "faq_group"
        }
      }
    }
  }
}
```

Minimal package-facing props are:

- object `kind`
- object `key`
- object `label`
- object `context`
- optional object `authoring_profile`
- optional object `routing_hint`
- optional object `section_authoring`
- field path
- field `type`
- field `choices`
- field `context`
- optional field `cross_site_safety`
- optional field `authoring_surface`
- optional field `authoring_priority`
- optional field `authoring_note`
- optional field `choice_meaning`
- optional field `shared_group_id`
- optional field `shared_group_label`
- optional field `section_selection`

## Authoring Rules

- Use Object Type Context to decide whether content belongs in `page`, `service`, another CPT, or a taxonomy.
- Use Field Context to decide field purpose, allowed values, value shape, and write safety.
- Prefer Field Context provider v2 authoring signals over DBVC heuristics when present.
- Treat `cross_site_safety: portable` as normal authorable content, `site_specific` as target-site mapping or blank-by-default, `media_deferred` as empty/null for v1, and `admin_or_editor` as not AI-authored unless explicitly requested.
- During AI submission intake, DBVC translates v2 safety as follows: `portable` imports normally, `site_specific` preserves supported values with a review warning, `media_deferred` leaves the field empty and records a deferred media warning, and `admin_or_editor` skips the submitted value with a warning.
- `authoring_priority: do_not_author` also causes DBVC to skip a submitted ACF value during translation unless a future explicit operator override is added.
- Use Field Context `section_selection` metadata as the source of truth for selected/default frontend sections.
- Do not add or consume a separate `essential_sections` list.
- Do not assume `section_selection` values equal ACF group names; use `section_selection.section_group_map` when present.
- Prefer field paths from `.context.json`, such as `meta.hero_title`.
- Do not infer clone ownership or hierarchy by parsing field names.
- Do not infer value shape from `resolved_purpose` alone.
- Do not read raw Vertical `acf-json` as DBVC runtime truth.
- Do not copy full provider maps into AI submission packages.

## Nuance

Object Type Context is additive object-level evidence. It does not replace Field Context, slot eligibility, value contracts, or DBVC validation.

Field Context owns field-level meaning, value shape, choices, references, container behavior, clone projection context, and write safety. If Field Context is degraded, missing, or unclear, use conservative values and expect DBVC to warn or block as needed.

Provider v2 safety is additive. For legacy/no-context packages, DBVC keeps its existing translator behavior, including blocking unsupported nonempty media fields when they are not explicitly marked `media_deferred`.

DBVC keeps provider maps request-local. Package-facing context should remain compact so browser AI sessions can load it without losing attention to the entity templates.

## Examples

Use this minimal context as guidance:

```json
{
  "object": {
    "kind": "post",
    "key": "service",
    "label": "Services",
    "context": "Use for individual client service offerings."
  },
  "fields": {
    "post_title": {
      "type": "string",
      "choices": [],
      "context": "Human-readable service name."
    },
    "meta.hero_heading": {
      "type": "text",
      "choices": [],
      "context": "Primary headline for the page hero."
    }
  }
}
```

## Maintenance Notes

Update this file when compact `.context.json` output, Vertical provider summaries, Object Type Context rules, Field Context rules, or value contract handling changes.

Related provider follow-up from real cross-site QA:

- [vertical-context-provider-feedback-2026-06-23.md](/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/docs/reference/import-authoring/vertical-context-provider-feedback-2026-06-23.md)
