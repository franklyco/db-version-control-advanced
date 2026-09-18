# Codex Prompt — R2 Frontend Media Manager Direct Remediation

```text
Implement R2 of the updated DBVC Visual Editor program: Frontend Media Manager Direct Remediation.

R1 must be production-ready. Read:
- 00-GOVERNING-DIRECTIVES.md
- media-manager/MEDIA-MANAGER-PRODUCT-SPEC.md
- media-manager/TABLE-AND-ROW-INTERACTION-SPEC.md
- media-manager/MUTATION-STALE-DATA-AND-REVALIDATION.md
- releases/R2-MEDIA-MANAGER-DIRECT-REMEDIATION.md
- quality/SECURITY-AND-DATA-SAFETY.md
- quality/MEDIA-MANAGER-TEST-MATRIX.md
- quality/TEST-QA-RELEASE-GATES.md
- current R1 evidence, decisions, risks, coverage, and tracker
under:
docs/dropins/dbvc-visual-editor-brand-controls-guide/

Begin with a release-specific discovery delta. Confirm the actual R1 finding/group references, row hydration flow, existing featured-image/image/gallery descriptor and editor controls, WordPress Media Library lifecycle, stale checks, journal/cache behavior, and any current composite same-owner save pattern.

Required outcome:
- exchange current opaque findings for fresh standard descriptors;
- recheck owner/status/capability/field applicability/empty state;
- use native WordPress Media Library for choosing and uploading images;
- reuse current featured-image, ACF image, and ACF gallery mutation contracts;
- clearly stage unsaved selections;
- provide field-level save;
- block writes when a field/gallery changed after scan;
- validate attachments server-side;
- journal/audit, invalidate caches, and rerun targeted finding checks;
- update counts/rows while preserving table context;
- complete media-modal focus/layering, security, browser, performance, and rollback tests.

Do not create a custom uploader, delete attachments, add ACF file/video/oEmbed support, edit static Bricks images, accept client-authoritative field targets, or implement same-entity Save Row or cross-entity Save Selected. The current composite route is collection-specific and is not a general media batch contract.

Use the existing main panel rather than duplicating image/gallery editor logic if embedding controls in the table would fork current behavior. Update the Claude mockup/state set only after the production action contract is verified.

Update all tracking files and stop after the R2 release gate.
```
