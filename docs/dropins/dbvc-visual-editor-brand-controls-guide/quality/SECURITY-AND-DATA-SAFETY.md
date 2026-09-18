# Security and Data-Safety Requirements

These requirements apply to R1–R6 and supplement the current Visual Editor security model. Current repository behavior remains authoritative where it is stricter.

## 1. Server authority

- Registry records and Media Manager findings authorize discovery only.
- Every edit requires a fresh or valid server-side descriptor.
- The browser never becomes authoritative for owner, field key, meta key, option ID, nested path, capability, or mutation adapter.
- Opaque references must be user/session/site-bound and expire according to current conventions.
- Missing/invalid references fail closed.

## 2. Protected requests

Every protected request must enforce:

- authenticated user;
- current Visual Editor/base feature access;
- nonce or current protected-request mechanism;
- object-specific capability where an object is involved;
- field-family capability/permission rules;
- request shape, length, pagination, and filter validation;
- rate/resource limits consistent with current architecture.

A list-time permission result does not replace save-time checks.

## 3. Media Manager enumeration safety

- Do not expose titles or routes for objects the user cannot safely access/edit under product policy.
- Discover public post types/taxonomies server-side; exclude internal types.
- Default to live/published objects.
- Do not accept a client-provided post type, taxonomy, owner ID, or field key without server validation/policy.
- Term and user enumeration concerns are distinct; v1 Media Manager excludes users.
- Search responses must be escaped and permission-filtered.

## 4. Field eligibility safety

- Use ACF definitions and current location rules, not arbitrary meta inspection.
- Do not treat matching field names as field identity.
- Prefer exact field keys internally.
- Nested fields require exact validated ancestry.
- Conditional/unsupported configurations fail closed or remain inspect-only.
- Static Bricks image settings are excluded.

## 5. Scan-session safety

- Bind scan snapshots to user and site/environment.
- Version snapshots by scanner/config schema where practical.
- Store only compact safe metadata and fingerprints.
- Do not store full nonces, reusable mutation payloads, or sensitive field values.
- Expired/invalid snapshots cannot be revived by the browser.
- Duplicate/stale batch requests must not corrupt or broaden results.
- Technical scan errors should be logged without exposing opaque references or content values unnecessarily.

## 6. Stale and concurrent updates

- Recheck owner, field definition, capability, and canonical current value when a row expands.
- Recheck again immediately before save.
- An `empty` finding must not overwrite a field that became non-empty.
- A gallery that acquired content must not be silently replaced or merged.
- A changed parent value invalidates a nested descriptor when required by current path semantics.
- Surface conflicts rather than auto-resolving them.

## 7. Attachment safety

- Reuse WordPress Media Library and current image/gallery validation.
- Validate attachment existence, local status, image MIME/type, and cardinality server-side.
- Do not accept arbitrary external URLs for image/gallery fields.
- Upload availability follows WordPress `upload_files` and core media nonces.
- DBVC does not process raw upload bytes in R2.
- Assignment, clearing, or replacement must not delete attachments.
- Gallery operations preserve exact submitted validated order and do not silently remove unrelated current IDs.

## 8. Mutation safety

- Use current featured-image and ACF image/gallery resolvers.
- Do not add generic `update_post_meta`, `update_term_meta`, `update_option`, or raw-meta fallbacks merely for Media Manager/Brand Center.
- Apply normal validation, sanitization, stale checks, acknowledgement, journaling, cache invalidation, and reload/DOM behavior.
- Initial R2 exposes no same-entity row save. Any later row contract requires full preflight, explicit per-field outcomes, and separate approval.
- Cross-entity bulk save is prohibited in R1/R2.

## 9. Registry and global-control safety

- Only explicitly registered controls are discoverable.
- Registry membership does not make a field writable.
- Sensitive settings, API keys, credentials, and arbitrary options remain excluded.
- Exact owner and field identity are server-side.
- Shared/related acknowledgements remain mandatory.
- Provider failures or version mismatches fail closed.

## 10. Output and DOM safety

- Escape all labels, summaries, routes, status text, and provider data using current project conventions.
- Sanitize user-entered search/filter values.
- Avoid raw HTML value summaries.
- Do not embed field keys, owner IDs, nested paths, nonces, or action payloads in predictable DOM IDs/data attributes.
- Scope CSS and prevent site/Bricks leakage.
- Bricks Builder/editor/iframe requests must not receive assets or markers.

## 11. Logging and audit safety

May log:

- scanner/provider identifier;
- release/feature version;
- object family/counts;
- error classification;
- duration/resource metrics;
- field family;
- canonical server-side audit identity through existing secure logs.

Do not log:

- nonces;
- full opaque references;
- credentials;
- sensitive option values;
- raw WYSIWYG content;
- private user data;
- uploaded file contents.

## 12. Security test requirements

At minimum cover:

- unauthenticated access;
- insufficient base capability;
- object-specific permission denial;
- term capability denial;
- upload capability denial;
- tampered scan/group/finding reference;
- expired snapshot;
- tampered filter/post-type/taxonomy values;
- field populated after scan;
- field definition/path changed;
- deleted/unpublished entity;
- invalid/deleted/non-image attachment;
- gallery cardinality/order validation;
- provider failure;
- Bricks Builder exclusion;
- XSS/escaping in labels, titles, and summaries.

## Security release gate

No release is ready when:

- a browser value can select an arbitrary field/owner;
- unauthorized object data leaks;
- scan work is unbounded;
- stale findings can overwrite newer assignments;
- media upload bypasses WordPress core;
- arbitrary options/meta become discoverable;
- builder isolation regresses;
- committed mutations are not audited.
