# Preflight checklist

Use this checklist after the offline script reports valid and before handing the package to an operator.

1. The sample package is current and came from the same destination site.
2. The returned manifest is dbvc_ai_submission_package version 1.
3. source_sample_package.site_fingerprint exactly matches the sample root fingerprint.
4. intended_operation is explicit and matches the requested create/update authority.
5. The ZIP root contains dbvc-ai-manifest.json directly, not inside a copied sample folder.
6. Every post, page, or CPT is at entities/posts/<post_type>/<slug>.json.
7. Every term is at entities/terms/<taxonomy>/<slug>.json.
8. Entity path keys, payload object keys, and filename slugs align.
9. New entities use zero IDs and no invented UID; updates use known identity.
10. Formatted body copy is valid JSON string content using semantic HTML, not Markdown or raw builder data.
11. Meta, taxonomy, and ACF values follow the current sample and .context.json; blocked and deferred values are absent or blank.
12. Manifest counts equal actual entity files, and the offline report is valid with no warnings.
13. Every block of approved copy is placed as page, section choice and field path, its sections are switched on (field-context-routing.md, Section controls), and any block with no section is listed as a gap line, "needs a section".

The offline report checks a portable subset of DBVC's current package contract. Uploading the ZIP into DBVC is still an operator step, and DBVC's own intake/translation result is authoritative.
