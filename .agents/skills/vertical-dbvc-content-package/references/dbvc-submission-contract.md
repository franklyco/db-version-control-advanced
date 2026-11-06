# DBVC submission contract

The DBVC-generated sample package is input context. The returned package is a separate submission package. Do not return a sample package or copy its manifest unchanged.

## Canonical ZIP layout

The ZIP root contains the manifest directly:

    dbvc-ai-manifest.json
    entities/posts/<post_type>/<slug>.json
    entities/terms/<taxonomy>/<slug>.json

Optional operator-facing files may live under docs/ or reports/. Do not put returned entities under samples/.

## Returned manifest

Use this minimum shape and replace the placeholder fingerprint with the exact root site_fingerprint from the current sample package:

    {
      "package_type": "dbvc_ai_submission_package",
      "package_schema_version": 1,
      "source_sample_package": {
        "site_fingerprint": "copy-from-current-sample",
        "package_schema_version": 1
      },
      "intended_operation": "create_only",
      "counts": {
        "post_entities": 0,
        "term_entities": 0
      }
    }

Allowed intended_operation values are create_only, update_only, and create_or_update. Counts must equal the number of recognized entity JSON files in the returned package.

## Post, page, and CPT entity

Path:

    entities/posts/<post_type>/<slug>.json

Minimum entity:

    {
      "ID": 0,
      "post_type": "page",
      "post_title": "About Us",
      "post_name": "about-us"
    }

The path post_type must equal payload post_type, and the filename slug must equal post_name. Optional fields include post_status, post_content, post_excerpt, post_date, post_parent, menu_order, post_author, comment_status, ping_status, meta, tax_input, and vf_object_uid for a known update target.

## Taxonomy term entity

Path:

    entities/terms/<taxonomy>/<slug>.json

Minimum entity:

    {
      "term_id": 0,
      "taxonomy": "service_type",
      "name": "Web Design",
      "slug": "web-design"
    }

The path taxonomy must equal payload taxonomy, and the filename slug must equal slug. Optional fields include description, parent, parent_slug, meta, and vf_object_uid for a known update target.

## Values that must not be authored

- Net-new entities must have ID: 0 or term_id: 0 and no vf_object_uid.
- Do not add dbvc_post_history, _dbvc_import_hash, or dbvc_term_history to meta.
- Do not add parent_uid to a term.
- Do not add ACF underscore reference keys or storage-only rows.
- Do not use raw numeric IDs for relationships unless the sample explicitly supports them. Prefer structured slug-based post and term references.

The DBVC sample JSON and its .context.json always take priority over this compact reference if they establish a stricter field contract.
