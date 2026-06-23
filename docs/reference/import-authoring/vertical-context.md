# Vertical Context

Status: Current
Last verified: 2026-06-22
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
    "context": "Service pages for client offerings."
  },
  "fields": {
    "meta.hero_title": {
      "type": "text",
      "choices": [],
      "context": "Primary hero headline."
    }
  }
}
```

Minimal package-facing props are:

- object `kind`
- object `key`
- object `label`
- object `context`
- field path
- field `type`
- field `choices`
- field `context`

## Authoring Rules

- Use Object Type Context to decide whether content belongs in `page`, `service`, another CPT, or a taxonomy.
- Use Field Context to decide field purpose, allowed values, value shape, and write safety.
- Prefer field paths from `.context.json`, such as `meta.hero_title`.
- Do not infer clone ownership or hierarchy by parsing field names.
- Do not infer value shape from `resolved_purpose` alone.
- Do not read raw Vertical `acf-json` as DBVC runtime truth.
- Do not copy full provider maps into AI submission packages.

## Nuance

Object Type Context is additive object-level evidence. It does not replace Field Context, slot eligibility, value contracts, or DBVC validation.

Field Context owns field-level meaning, value shape, choices, references, container behavior, clone projection context, and write safety. If Field Context is degraded, missing, or unclear, use conservative values and expect DBVC to warn or block as needed.

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
