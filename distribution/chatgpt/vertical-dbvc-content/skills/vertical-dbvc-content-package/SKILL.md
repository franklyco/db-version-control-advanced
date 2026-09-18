---
name: vertical-dbvc-content-package
description: Draft or review DBVC submission JSON for a current VerticalFramework WordPress site when the user provides a DBVC sample package and content brief. Use for blog articles, pages, CPT records, and taxonomy terms in ChatGPT web or mobile. Do not create a ZIP, upload, import, publish, or claim output is upload-ready or preflighted.
---

# Vertical + DBVC content JSON draft

Create reviewable DBVC-shaped JSON for ordinary content entities: blog posts, pages, conventional CPT records, and taxonomy terms. This is a skills-only ChatGPT distribution. It can author and review JSON, but it has no local package writer, offline validator, DBVC connection, or authenticated import capability.

## Delivery boundary

Treat every result as a **JSON draft for local preflight**, never as an upload-ready package. Do not create a ZIP, say a file was validated, or imply that DBVC will accept the content. The operator must move the reviewed JSON and the original current sample package into the repository-local Codex/Claude Code companion workflow before an offline-preflighted ZIP can be created.

Never upload, import, apply, publish, accept a warning, or ask the user to expose credentials.

## Required input

Before authoring site-specific JSON, obtain:

1. A current DBVC-generated AI sample package from the destination site, attached in a form you can inspect. Read its root manifest, selected entity sample, and matching `.context.json` file.
2. A content brief: entity type/key, intended operation, desired status, titles/slugs, taxonomy assignments, content, facts, approved voice, and exclusions.
3. For an update, a known `vf_object_uid` or positive destination ID supplied by the operator.

If the attachment cannot be inspected, request the root sample manifest plus the relevant sample JSON and sibling context JSON. If those are not available, provide prose or a generic outline only and label it **not site-specific DBVC JSON**. Never invent a generic fingerprint or guessed ACF structure.

Use [references/input-brief.md](references/input-brief.md) to collect only missing decisions.

## Workflow

1. Establish the sample as the only authority for field names, object shape, `site_fingerprint`, available taxonomy values, and context routing. Read [references/dbvc-submission-contract.md](references/dbvc-submission-contract.md) and [references/field-context-routing.md](references/field-context-routing.md) before building output.

2. Determine the explicit operation:
   - New entities: `create_only`, `ID: 0` for posts/CPTs/pages or `term_id: 0` for terms, without `vf_object_uid`.
   - Known updates: `update_only`, with a supplied `vf_object_uid` whenever available.
   - Use `create_or_update` only when the operator explicitly permits both outcomes.

3. Author article content in `post_content` as semantic WordPress-safe HTML, matching the selected sample's fields. Use descriptive `h2`/`h3` headings, paragraphs, lists, and links where useful. Do not use Markdown as the body, duplicate the title as an `h1`, invent shortcodes, or add builder-only data. Default `post_status` to `draft` unless the brief says otherwise.

4. Apply Field Context conservatively. Use declared choices exactly. Omit uncertain, site-specific, media-deferred, admin/editor, and do-not-author fields rather than guessing. Do not author ACF underscore-reference meta, import histories, raw database values, raw Bricks data, or unsupported image/file/gallery values.

5. Return a transparent draft handoff, in this order:
   - a short scope statement with missing inputs, intended operation, and the phrase `JSON draft for local preflight`;
   - an intended package tree using the canonical names from the contract;
   - the exact `dbvc-ai-manifest.json` contents with the sample's fingerprint only when that fingerprint was actually available;
   - each entity as a separately named JSON artifact or fenced `json` block, with its intended canonical path;
   - a handoff checklist naming what the local companion must preflight.

6. End by stating that no ZIP, DBVC validation, upload, import, or publication occurred. Point the operator to the local companion skill for structural preflight and ZIP creation.

## Hard boundaries

- Never reuse an old fingerprint for a different site or silently repair a mismatch.
- Never invent `vf_object_uid`, DBVC histories, import hashes, ACF underscore-reference meta, or raw storage-only values.
- Never generate Bricks templates, `_bricks*` values, global classes/variables, media migration payloads, or arbitrary database exports.
- Never claim that source review proves the content will import. DBVC's own site-specific intake and translation remain authoritative.

## Supporting references

- [references/input-brief.md](references/input-brief.md): compact intake template for incomplete requests.
- [references/dbvc-submission-contract.md](references/dbvc-submission-contract.md): returned manifest and entity-path rules.
- [references/field-context-routing.md](references/field-context-routing.md): safe use of the compact Vertical Field Context.
- [references/web-mobile-boundary.md](references/web-mobile-boundary.md): delivery and handoff rules for this skills-only host.
