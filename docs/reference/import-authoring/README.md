# DBVC Import Authoring Reference

Status: Current
Last verified: 2026-06-22
Source of truth: `includes/Dbvc/AiPackage/*`, `docs/reference/import-identity-matching.md`
Read when: an AI agent or human is creating a DBVC AI submission package.
Minimum context: this file plus the task-specific files listed below.

This folder is the canonical reference for authoring DBVC AI import packages. It is intentionally small and current-facing. Do not use proposed implementation plans or archived docs as the first source for package shape.

## Fast Path

For new website content such as pages, service CPT entries, and terms, read:

1. `ai-agent-quickstart.md`
2. `package-layout.md`
3. `entity-shapes.md`
4. `examples/submission-manifest-create-only.json`
5. one entity example under `examples/`

If ACF fields are present, also read:

1. `acf-authoring.md`
2. `vertical-context.md` when the package includes Vertical context.

For rejected packages or maintainer work, read:

1. `validation-and-import-rules.md`
2. `source-code-map.md`
3. `maintenance-contract.md`

## Current Docs

| File | Use |
|---|---|
| `ai-agent-quickstart.md` | Smallest agent-facing rules for creating a returned DBVC package. |
| `package-layout.md` | Sample package vs returned submission package layouts and manifests. |
| `entity-shapes.md` | Required and optional post, CPT, page, and term JSON fields. |
| `acf-authoring.md` | ACF value authoring rules for `meta`. |
| `vertical-context.md` | Minimal package-facing Object Type Context and Field Context. |
| `validation-and-import-rules.md` | What DBVC validates, warns about, and blocks. |
| `source-code-map.md` | Maintainer map from docs contracts to runtime classes. |
| `maintenance-contract.md` | Required doc updates when import behavior changes. |
| `examples/` | Small structural examples. |

## Context Tiers

Tier 1, normal content generation:

- `ai-agent-quickstart.md`
- `package-layout.md`
- `entity-shapes.md`
- `examples/`

Tier 2, ACF or Vertical-heavy generation:

- `acf-authoring.md`
- `vertical-context.md`

Tier 3, validation/debugging/maintenance:

- `validation-and-import-rules.md`
- `source-code-map.md`
- `maintenance-contract.md`

## Maintenance Notes

Any change to DBVC AI package schema, entity JSON shape, ACF value handling, Vertical context packaging, identity matching, or submission validation must update this folder and the router links that point here.
