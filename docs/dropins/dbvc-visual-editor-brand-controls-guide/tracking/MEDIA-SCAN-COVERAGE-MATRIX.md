# Media Scan Coverage Matrix

Complete during R0 and maintain through R1/R2.

## Entity coverage

| Entity family/type | Public/live rule | Capability rule | Featured image | ACF image | ACF gallery | Nested paths | Excluded cases | Evidence/tests |
|---|---|---|---:|---:|---:|---:|---|---|
| Page | Public/show-UI type; `publish`; not excluded | Base Visual Editor permission plus `edit_post(ID)` at enumerate/list/expand | R1 yes when type supports thumbnail; 21/36 empty on current fixture | R1 top/group | R1 top/group | Defer rows/flexible | Draft/private/trash, capability failure, conditional unknown | Runtime probe; `EligibilityPolicy`; `VisualEditorMediaManagerR1ATest` |
| Post | Public/show-UI type; `publish`; not excluded | Same | R1 yes; 0/7 empty | R1 top/group | R1 top/group | Defer rows/flexible | Same | Runtime probe |
| Public CPT: discovered | Public/show-UI type; `publish`; not excluded; never hardcoded | Same | R1 yes when supported, labeled optional empty assignment | R1 top/group | R1 top/group | Defer rows/flexible | Attachment, `bricks_template`, non-public/internal/configured exclusions | Runtime probe found 24 CPTs plus page/post; R1-A policy fixtures |
| Representative CPT `service` | Same | Same | 0/43 empty | R1 top/group | R1 top/group | Defer rows/flexible | Same | Runtime probe; Vertical services ACF JSON |
| Representative CPT `benefit` | Same | Same | 34/45 empty | R1 top/group | R1 top/group | Defer rows/flexible | Same | Runtime probe |
| Representative CPT `listing` | Same | Same | 1/2 empty | R1 top/group | R1 top/group | Defer rows/flexible | Same | Runtime probe; listings ACF JSON |
| Public term: discovered | Concrete term in public/show-UI taxonomy; not excluded; route valid where exposed; terms have no publish status | Base permission plus `edit_term(ID)` at enumerate/list/expand | N/A | R1 top/group after exact taxonomy location match | R1 top/group after exact taxonomy location match | Defer rows/flexible | `template_tag`, `template_bundle`, non-public taxonomies, capability/link errors | Runtime probe found 234 terms/15 taxonomies; R1-A exact term-screen/capability fixtures |
| Representative terms `service_area` / `listing_location` | Same | Same | N/A | R1 top/group | R1 top/group | Defer rows/flexible | Same | 33 and 3 terms respectively; exact Vertical ACF JSON groups |

Add rows for representative current site types without hardcoding them into DBVC core.

## ACF path coverage

| Path shape | Scan supported | Descriptor supported | Write supported | Stale check | Tests | Decision/limitation |
|---|---:|---:|---:|---:|---:|---|
| Top-level image | R1 initial | Yes when rendered | Yes via `AcfImageResolver` | Current single save: no expected-old; R2 adds expected-empty | R1-A catalog/value fixtures plus existing resolver/manual QA | Include unconditional active fields after exact location match |
| Top-level gallery | R1 initial | Yes when rendered | Yes via `AcfGalleryResolver` | Same | R1-A catalog/value fixtures plus gallery save script/manual QA | Include unconditional active fields |
| Group > image/gallery | R1 initial when deterministic canonical group path is provable | Yes when render metadata proves flattened selector/group path | Yes through `AbstractAcfResolver` | Same | R1-A nested group-only catalog fixture; existing render-derived QA | Include only deterministic group-only ancestry |
| Repeater existing row > image/gallery | Deferred from initial R1 | Yes when rendered with stable row ancestry | Yes for existing proven row | Composite/path-specific checks only | Existing render-derived scripts; no generic scan tests | Needs generic raw-row enumeration and exact finding identity |
| Nested repeater existing row | Deferred | Limited render-proven cases | Limited existing-row paths | Not general | Sparse/manual | No initial R1/R2 coverage |
| Flexible existing layout > image/gallery | Deferred from initial R1 | Yes when rendered with stable row/layout | Yes for supported media descendants, including shared owners | Contract-specific, not scan expected-empty | Current docs/runtime QA; no generic scan tests | Runtime has 37 expanded media definitions; needs clone/layout fixtures |
| Mixed group/repeater/flexible ancestry | Deferred | Some render-proven cases | Some existing-row cases | Not general | Sparse/manual | Fail closed until enumerator and resolver share one canonical path contract |
| Conditional field active | Deferred unless server state is explicitly proven | Render path may prove a value | Resolver can write a descriptor | No generic condition stale check | None | Initial R1 does not evaluate conditional logic |
| Conditional field inactive/unknown | No; count as unsupported | N/A | No | N/A | R1-A catalog fixture | Prevent optional/hidden field noise |

## Empty-value evidence

| Family | Canonical read method | Empty representations | Invalid/non-empty distinction | Tests |
|---|---|---|---|---|
| Featured image | `get_post_thumbnail_id(ID)` | `0`/no `_thumbnail_id` | Positive but nonexistent/non-image attachment is invalid reference, not an empty requirement claim | Existing resolver behavior; add R1 empty/invalid policy tests |
| ACF image | Raw `get_field(field_key, acf_object_id, false)` using exact field/path owner | `false`, `null`, `''`, `0`, empty array after raw normalization | Nonempty URL/array/ID that cannot resolve to a local image is invalid/unsupported, not silently empty | `MediaAssignmentValueClassifier`; `VisualEditorMediaManagerR1ATest`; `AcfImageResolver` |
| ACF gallery | Raw ACF read preserving order | `false`, `null`, `''`, empty array | A nonempty list with invalid/mixed IDs is an invalid/partial reference state; R1 must not call it empty | `MediaAssignmentValueClassifier`; mixed/invalid R1-A fixtures; `AcfGalleryResolver` |

## ACF option-owner support baseline

“Yes” below means the existing resolver can read/write an option-owned field **after a render-derived or manually proven descriptor exists**. It does not mean Shared Globals or a generic registry can discover that family off-page.

| Family | Generic editor | Options read | Options write | Nested option paths | Dedicated tests | Current gap |
|---|---:|---:|---:|---:|---:|---|
| text | Yes | Yes | Yes | Render-proven group/repeater/flexible | No | No generic off-render registry |
| textarea | Yes | Yes | Yes | Render-proven | No | Same |
| url | Yes | Yes | Yes | Render-proven | No | Same |
| email | Yes | Yes | Yes | Render-proven | No | Same |
| number | Yes | Yes | Yes | Render-proven | No | Same |
| range | Yes | Yes | Yes | Render-proven | No | Same |
| wysiwyg | Yes | Yes | Yes | Render-proven | No | Existing acknowledgement/reload path needs option fixtures |
| checkbox | Yes | Yes | Yes | Render-proven | No | No generic off-render discovery |
| select | Yes | Yes | Yes | Render-proven | No | Same |
| radio | Yes | Yes | Yes | Render-proven | No | Same |
| button_group | Yes | Yes | Yes | Render-proven | No | Same |
| link | Yes | Yes | Yes | Render-proven | No | Same |
| image | Yes | Yes | Yes | Render-proven, including flexible media descendants | No dedicated option-owner test | R5 needs fresh option descriptor/stale fixtures |
| gallery | Yes | Yes | Yes | Render-proven, including flexible media descendants | No dedicated option-owner test | Same plus ordered stale conflict |
| post_object | Yes for proven projection/collection | Yes | Yes for proven collection | Limited; flexible connected descendants not in scalar/media set | Shared Globals runtime probe only | Shared Globals allowlist only; field-name configured |
| relationship | Yes for proven projection/collection | Yes | Yes for proven collection | Limited as above | Shared Globals runtime probe only | Shared Globals only; no generic registry |
| taxonomy | Yes for proven projection/collection contexts | Conditional | Conditional | Not proven for generic option-owned nested paths | No | Not supported by Shared Globals; needs family-specific contract |

Container fields (`group`, `repeater`, `flexible_content`, clone projections) remain path concerns. No structural row mutation is authorized.

## Performance samples

| Fixture/site | Candidate objects | Fields evaluated | Findings | Chunk size | Requests | Duration | Peak/query notes |
|---|---:|---:|---:|---:|---:|---:|---|
| Current `dbvc-codexchanges.local` applicability baseline | 454 (220 posts/CPTs + 234 terms) | 2,119 applicable definition pairs | 101 featured empties; ACF raw empties not scanned | Diagnostic one-request probe only | 1 | 612.62 ms total | Enumeration + visibility only; 48 groups/171 media definitions; no persistence/REST/auth/raw-read measurement |
| R1 small acceptance fixture | TBD | TBD | TBD | 25 proposed starting point | TBD | TBD | Measure queries, memory, raw reads, snapshot/payload |
| R1 current-site acceptance | 454 current baseline, remeasure | TBD | TBD | Tune from 25/100 evidence | TBD | TBD | Must include capability filtering and complete scanner |
| R1-E synthetic read-model scale | 100/500/2,000 groups sharing one eligible owner | 1 finding/group | 100/500/2,000 | Read page 50 | 1 list projection/cohort | 3.759/6.732/25.425 ms in combined run | 2,000: 0 extra queries, 6,291,456 allocated-memory bytes, 120,475 stored bytes, 24,833 response bytes; isolates snapshot/read/payload, not full candidate/raw-read/browser cost |
| Large multi-owner/runtime | 2,000 target owners where practical | TBD | TBD | Bounded at 50 candidates/request and 50 rows/response | TBD | TBD | Complete candidate traversal/raw ACF reads, authenticated REST/browser integration, stale cancellation, and production SLO remain open |
