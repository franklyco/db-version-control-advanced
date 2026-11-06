---
name: vertical-dbvc-content-package
description: Create or review a DBVC AI submission package for a current VerticalFramework WordPress site. Use when generating blog articles, pages, CPT entries, or taxonomy terms as upload-ready JSON from a DBVC sample package; do not use to upload, import, apply, or generate Bricks builder layouts.
---

# Vertical + DBVC content package

Produce a clean DBVC AI submission ZIP containing fully authored JSON entities for one current VerticalFramework WordPress site. The deliverable is ready for an operator to upload through DBVC's AI Package Intake screen after review. Never perform the upload, warning acceptance, translation, or import.

This skill covers ordinary content entities: blog posts, pages, conventional CPT records, and taxonomy terms. It does not cover Bricks templates, raw builder JSON, global classes or variables, media migration, or arbitrary database exports.

## Host modes

This local Codex/Claude Code distribution includes the offline Python preflight and can create a final ZIP only after that preflight is clean. The separately tracked skills-only ChatGPT web/mobile plugin deliberately supports JSON authoring and review only: it cannot create a validated ZIP or call a result upload-ready. Do not mix those delivery claims between hosts. Read [references/host-modes.md](references/host-modes.md) before choosing a handoff.

## Required inputs

Before claiming a package is upload-ready, obtain all of the following:

1. A current DBVC-generated AI sample ZIP from the destination site.
2. A content brief that names the entity type, requested content, taxonomy assignments, and target operation.
3. For updates, a known DBVC-supplied object UID or positive destination ID.
4. Any approved brand, editorial, legal, source, and publication-status constraints.

If the sample package is absent, do not invent a generic Vertical schema or a site fingerprint. Ask the operator to generate a current DBVC AI sample package, or provide draft content only and label it as not package-ready.

When the brief is incomplete, use [references/input-brief.md](references/input-brief.md) to gather only the missing decisions.

## Authoring workflow

1. Inspect the source sample package before drafting. Read its root manifest, the selected sample JSON, and the matching sibling .context.json file. The generated sample and compact context are the authority for object keys, fields, value shapes, choices, and the source site fingerprint.

2. Decide the operation before creating files:
   - For net-new entities, use create_only with ID: 0 for posts/CPTs/pages or term_id: 0 for terms. Do not include vf_object_uid.
   - For known changes to existing entities, use update_only and include the supplied vf_object_uid whenever available. A positive destination ID may be used only when it is known for that destination.
   - Use create_or_update only when the brief explicitly permits both outcomes.

3. Write content in the entity JSON, not as a Markdown article beside it:
   - Use post_title, post_name, post_excerpt, post_content, tax_input, and meta only when the sample exposes those fields.
   - Put formatted article/body copy in post_content as semantic WordPress-safe HTML. Use descriptive H2/H3 headings, paragraphs, lists, and links where appropriate. Do not put Markdown in post_content, duplicate the title as an H1, invent shortcodes, or add presentation-only builder data.
   - Keep summaries genuinely shorter than the body. Default post_status to draft unless the operator explicitly requests another status.
   - Match the filename slug to post_name or slug exactly.
   - Preserve the JSON field names and logical value shapes from the sample. Omit fields the sample does not expose rather than guessing.

4. Apply Field Context conservatively. Read [references/field-context-routing.md](references/field-context-routing.md) whenever a selected sample has a .context.json file or ACF-backed meta. Use context choices exactly, preserve normal portable content, and leave uncertain, site-specific, media-deferred, editor-only, or do-not-author fields blank or omitted as instructed there.

5. Assemble the returned package, not a modified copy of the sample:

       dbvc-ai-manifest.json
       entities/posts/<post_type>/<slug>.json
       entities/terms/<taxonomy>/<slug>.json

   Use the manifest and entity rules in [references/dbvc-submission-contract.md](references/dbvc-submission-contract.md). Copy the sample manifest's root site_fingerprint into source_sample_package.site_fingerprint. Set counts to the actual number of post and term entity JSON files.

6. Stage the package in a new directory and run the bundled preflight before creating the final ZIP:

       python3 scripts/dbvc_submission_preflight.py \
         --sample /path/to/current-dbvc-sample.zip \
         --package /path/to/submission-staging \
         --report /path/to/preflight-report.json \
         --zip-output /path/to/dbvc-submission.zip

   Run the command from this skill directory, or use the script's absolute path. The script writes the ZIP only for a clean valid report. Fix every reported warning or error before calling the result upload-ready. The preflight is intentionally structural; DBVC remains authoritative for intake and translation.

7. Deliver the ZIP, the JSON source directory when requested, and the preflight report. State:
   - the operation and entity counts;
   - whether the preflight was clean;
   - that an operator must upload it to DBVC and review DBVC's own validation result;
   - that no upload, warning override, or import was performed.

Use [references/preflight-checklist.md](references/preflight-checklist.md) as the final human review before delivery.

## Hard boundaries

- Never reuse an old sample fingerprint for a different site or silently repair a fingerprint mismatch.
- Never invent vf_object_uid values, ACF underscore reference meta, DBVC histories, import hashes, or raw storage-only values.
- Never author non-empty image, file, or gallery ACF values unless the current sample and context explicitly provide a supported logical structure.
- Never generate _bricks*, Bricks template layouts, provider maps, or raw ACF JSON as ordinary article/CPT content.
- Never claim that offline preflight proves a package will import. DBVC can still warn or block during site-specific translation.
- Never upload, import, apply, publish, or accept a DBVC warning without an explicit operator action outside this skill.

## Supporting references

- [references/input-brief.md](references/input-brief.md): compact intake template when the requested content is underspecified.
- [references/dbvc-submission-contract.md](references/dbvc-submission-contract.md): canonical returned-manifest and entity-path rules.
- [references/field-context-routing.md](references/field-context-routing.md): how to use DBVC's compact Vertical Object Type Context and Field Context safely.
- [references/preflight-checklist.md](references/preflight-checklist.md): final package-review checklist and interpretation of the offline report.
- [references/host-modes.md](references/host-modes.md): safe boundaries for local Codex/Claude Code, skills-only ChatGPT, and a future package-only MCP service.
