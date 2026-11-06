# Content brief for a DBVC package

Collect only the fields that are relevant to the requested content. A current DBVC sample package is still required for a site-specific DBVC result; local offline preflight is separately required before a result may be described as upload-ready.

## Package scope

- Destination-site sample package: current file/path and date generated.
- Entity kind: blog post, page, named CPT, taxonomy term, or a mixed package.
- Entity key: post type or taxonomy key exactly as shown by the sample.
- Operation: create_only, update_only, or create_or_update.
- Requested status: draft by default; name a different status explicitly.
- Quantity: one entity or a named list of entities.

## Identity and organization

- New entity: desired title and slug.
- Update: supplied vf_object_uid and/or known destination ID.
- Post/CPT taxonomy assignments: taxonomy key and existing term slug(s).
- New term: taxonomy, name, slug, optional description, and parent slug when applicable.

## Editorial input

- Intended reader, service area or audience, goal, central claim, and call to action.
- Required facts, approved source material, mandatory phrases, and prohibited claims.
- Voice or brand constraints that the operator has approved.
- Desired outline, approximate length, useful internal/external links, and any FAQ or summary requirements.

## Site-specific fields

- Values for each authorable sample/meta field.
- Any supplied .context.json choices that must be selected exactly.
- Any field that must remain blank, be mapped by an editor, or be deferred for media.

## Explicit exclusions

Confirm whether the request excludes publishing, media upload, new taxonomy creation, or any current-site update. Do not infer authority to do those things.
