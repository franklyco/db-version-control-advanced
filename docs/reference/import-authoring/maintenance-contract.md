# Maintenance Contract

Status: Current
Last verified: 2026-06-22
Source of truth: `docs/_meta/doc-governance.md`
Read when: changing DBVC AI package, import, ACF, Vertical context, or validation behavior.
Minimum context: `source-code-map.md`.

## Fast Path

Do not change package behavior without updating the reference docs and router links in the same pass.

## Current Contract

Update this import-authoring library when changing any of these areas:

- AI package schema
- sample package layout
- submission package layout
- `dbvc-ai-manifest.json`
- `SCHEMA_COMPACT.json`
- sample `.context.json`
- post/CPT/page entity fields
- term entity fields
- identity matching
- ACF value handling
- relationship reference handling
- media-like ACF policy
- Vertical Object Type Context
- Vertical Field Context
- validation states or severities
- blocked fields or blocked meta keys
- submission translation or import behavior

## Authoring Rules

When this folder changes, check whether these routers also need updates:

- `docs/agent-entrypoints.md`
- `docs/reference/README.md`
- `docs/roadmap.md`
- `docs/requests.md`

When runtime behavior changes, verify the docs against:

- `includes/Dbvc/AiPackage/*`
- `docs/reference/import-identity-matching.md`
- relevant module-local Content Migration docs when Vertical context is involved

## Nuance

This folder should remain current and concise. Move implementation history, unresolved plans, and broad design notes to implementation docs or requests rather than expanding the reference docs into planning material.

## Examples

If `SubmissionPackageValidator` starts accepting a new top-level entity field, update:

1. `entity-shapes.md`
2. `validation-and-import-rules.md`
3. examples if the new field changes normal authoring
4. `docs/agent-entrypoints.md` only if the read path changes

## Maintenance Notes

This file mirrors the repo-level governance rule. Keep both copies aligned.
