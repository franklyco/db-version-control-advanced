# ACF Authoring

Status: Current
Last verified: 2026-06-22
Source of truth: `includes/Dbvc/AiPackage/TemplateBuilder.php`, `includes/Dbvc/AiPackage/RulesService.php`, `includes/Dbvc/AiPackage/SubmissionPackageTranslator.php`
Read when: a returned DBVC package includes ACF values under `meta`.
Minimum context: `entity-shapes.md`, the selected sample JSON, and the sibling `.context.json`.

## Fast Path

Author ACF values under the entity's `meta` object using the same keys and logical shapes shown in the DBVC sample JSON.

Do not add ACF underscore reference meta. DBVC handles supported storage translation.

## Current Contract

The AI-facing logical ACF shapes are:

| ACF family | Authoring shape |
|---|---|
| scalar fields | scalar value |
| group | object |
| repeater | array of row objects |
| flexible content | array of layout objects with `acf_fc_layout` |
| clone | logically expanded target fields |
| `post_object` / `relationship` | slug-based post references where supported |
| `taxonomy` | slug-based term references where supported |

DBVC policy currently blocks non-empty unsupported media-like values for:

- `image`
- `file`
- `gallery`

## Authoring Rules

- Preserve the `meta` keys generated in the sample JSON.
- Use the sibling `.context.json` for field type, choices, and purpose.
- Use choices exactly when a field declares choices.
- Prefer structured post references with `post_type` and `slug`.
- Prefer structured term references with `taxonomy` and `slug`.
- Keep unsupported media-like fields empty or neutral.
- Do not invent raw ACF field keys, underscore reference meta, or storage-only rows.

## Nuance

ACF type alone is not enough to determine whether a value is safe to write. Field Context, value contracts, clone context, and object scope can change what is valid.

When unsure, preserve the key and use an empty conservative value rather than inventing a media object, local ID, or storage-level ACF structure.

## Examples

Illustrative logical shapes:

```json
{
  "meta": {
    "headline": "Primary service headline",
    "content_group": {
      "intro": "Short intro text"
    },
    "faq_items": [
      {
        "question": "What is included?",
        "answer": "A concise answer."
      }
    ],
    "sections": [
      {
        "acf_fc_layout": "text_section",
        "heading": "Section heading",
        "body": "Section body."
      }
    ]
  }
}
```

Replace these keys with keys from the generated sample package for the target site.

## Maintenance Notes

Update this file when supported ACF families, blocked media policy, relationship normalization, clone expansion, or storage translation changes.
