# Codex Prompt — R5 ACF Option-Field Family Support

Use this prompt for one R5 point release at a time. Replace `[POINT RELEASE]` and `[FAMILIES]` before starting.

```text
Implement [POINT RELEASE] of the DBVC Visual Editor Brand Controls program for these ACF option-owned field families:

[FAMILIES]

Read:
- docs/dropins/dbvc-visual-editor-brand-controls-guide/00-GOVERNING-DIRECTIVES.md
- docs/dropins/dbvc-visual-editor-brand-controls-guide/releases/R5-ACF-OPTION-FIELD-FAMILY-SUPPORT.md
- docs/dropins/dbvc-visual-editor-brand-controls-guide/quality/SECURITY-AND-DATA-SAFETY.md
- docs/dropins/dbvc-visual-editor-brand-controls-guide/quality/TEST-QA-RELEASE-GATES.md
- the current ACF support matrix, evidence log, decisions, risks, and tracker

First inspect the latest code and determine whether each generic field contract already supports options ownership. Prefer removing a discovery restriction or extending an existing owner adapter over duplicating field-family resolvers.

Do not infer off-render option support from a render-derived descriptor. At the reconciled checkpoint, Shared Globals proves only configured option-owned relationship/post_object discovery. Taxonomy option ownership remains conditional and must stay inspect-only unless its dedicated descriptor/read/write/stale contract is proven.

Requirements:
- only explicitly registered ACF option controls;
- exact field keys and canonical option owners;
- fresh server descriptors;
- existing family-specific editor, validation, sanitization, mutation, stale checking, acknowledgement, journaling, cache invalidation, and reload behavior;
- truthful inspect-only/unsupported handling for unsafe configurations;
- existing nested option paths only where current architecture already proves them;
- regression coverage for post, term, user, and other current owners.

Do not add generic update_option/meta fallbacks, auto-discovery of every option field, repeater/flexible structural editing, bulk saving, new media families, or functionality assigned to later point releases.

Use real VerticalFramework ACF JSON/catalog evidence for representative fixtures without hardcoding Vertical-specific field names into DBVC core.

Complete the family-specific matrix and tests before enabling the family. This point release must be independently production-ready and rollbackable. Update all tracking and release documentation.
```

Suggested substitutions:

- `R5.1` / `text, textarea, url, email, number, range`
- `R5.2` / `checkbox, select, radio, button_group, link, wysiwyg`
- `R5.3` / `image, gallery`
- `R5.4` / `post_object, relationship, taxonomy`
