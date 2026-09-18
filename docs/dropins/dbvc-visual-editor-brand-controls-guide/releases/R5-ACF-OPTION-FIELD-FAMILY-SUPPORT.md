# R5 — ACF Option-Owner Field-Family Expansion

## Production outcome

R5 makes explicitly proven option-owned ACF field families available to registered controls. Current generic resolvers can represent option owners for many render-derived descriptors, but off-render discovery is presently proven only for the narrow Shared Globals relationship/post-object path. Each point release must therefore prove fresh descriptor creation, option ownership, and family behavior rather than infer support from a post/term/user resolver.

This is an ownership-expansion program, not a new arbitrary ACF editor. It should reuse existing family-specific controls, normalization, validation, mutation, stale checking, journaling, cache invalidation, and reload behavior.

R5 must ship as point releases rather than one large enablement. Unsupported owner/family combinations remain inspect-only.

## User problem

The Global & Brand Control Center is most useful when it can manage common option-owned values such as business information, links, rich text, logos, galleries, choices, and connected content—not only relationship/post-object fields.

## Primary personas

- Client content editor
- Business owner
- Marketing manager
- Agency onboarding specialist
- Site administrator

## Existing surfaces extended

- R3 registry
- R4 Global & Brand Control Center
- Existing main editor panel and field controls
- Existing ACF resolvers and mutation contracts
- Existing shared acknowledgement, journal, audit, and cache invalidation

## Program rule

For each family, first determine whether the generic contract already supports an options owner. The preferred implementation is to remove an unnecessary discovery restriction or extend an existing owner adapter—not to duplicate the field resolver for options.

If a field family cannot safely support option ownership under current contracts, leave it inspect-only and record the blocker. Do not create a raw `update_option()` escape hatch.

## Point-release sequence

### R5.1 — Scalar option fields

- `text`
- `textarea`
- `url`
- `email`
- `number`
- `range`

### R5.2 — Choice, link, and rich-text option fields

- `checkbox`
- `select`
- `radio`
- `button_group`
- `link`
- `wysiwyg`

### R5.3 — Media option fields

R5.3 is distinct from the R1–R2 Media Manager. It exposes explicitly registered **option-owned global image/gallery controls** in the Brand Control Center; it does not add option owners to Media Manager scans.

- `image`
- `gallery`

### R5.4 — Connected and taxonomy option fields

- `post_object`
- `relationship`
- `taxonomy`

`post_object` and `relationship` are the proven Shared Globals baseline. R5.4 should preserve and regression-test them under the registry-backed center. Taxonomy option ownership is conditional: enable it only if a dedicated descriptor/read/write/stale contract is proven; otherwise leave it inspect-only and document the blocker.

## Containers and nested paths

The following are not new editor families in this program:

- ACF groups
- existing repeater rows
- existing flexible-content rows/layouts
- nested combinations of those containers

If current descriptor and mutation systems already support editing existing nested subfields under options, verify that behavior for the newly enabled families. Do not add row or layout creation, deletion, duplication, or reordering.

## Common requirements for every family

Before enabling a family, document and test:

1. Exact ACF field-key resolution
2. Canonical option owner representation
3. Read behavior and formatted/raw value assumptions
4. Empty-value semantics
5. Client control rendering
6. Server validation and sanitization
7. Canonical value used for stale comparison
8. ACF update behavior
9. Nested-path preservation where applicable
10. Shared acknowledgement
11. Journal before/after representation
12. Cache invalidation
13. DOM patch versus Save and Reload behavior
14. Return-format variations that affect storage or preview
15. Feature support status shown in the center

## R5.1 — Scalar option fields

### In scope

- Reuse text-like controls for registered option-owned fields.
- Preserve ACF min/max/step and number/range semantics where current contracts support them.
- Validate URL and email server-side using current project conventions.
- Preserve intentional empty values.
- Add type-appropriate list summaries.

### Family-specific considerations

| Family | Required considerations |
|---|---|
| text | scalar normalization, max length if enforced, empty string semantics |
| textarea | line endings, safe summary truncation, no HTML assumptions unless existing contract allows it |
| url | allowed scheme, normalization policy, empty versus invalid |
| email | canonical validation, display escaping, no mail action side effects |
| number | numeric string versus numeric storage, decimal precision, min/max |
| range | min/max/step and existing ACF behavior |

### R5.1 acceptance criteria

- [ ] Every family opens in its existing field-specific control.
- [ ] Option ownership is visible and requires shared acknowledgement.
- [ ] Values save through existing ACF mutation logic rather than raw options writes.
- [ ] Stale checks use canonical values appropriate to the family.
- [ ] Invalid values are rejected without partial writes.
- [ ] Nested existing option paths work where current architecture claims support.
- [ ] Each mutation is journaled and cache behavior is verified.
- [ ] Existing post, term, and user ownership behavior has no regression.

## R5.2 — Choice, link, and rich-text option fields

### In scope

- Reuse existing choice-list validation.
- Support single and multi-value behavior only as currently proven.
- Reuse the structured ACF link editor.
- Reuse WordPress editor/TinyMCE behavior for WYSIWYG where available.
- Reuse the current editor asset lifecycle. Do not add another enqueue path; any change from the existing eager active-mode baseline is a separate measured optimization.

### Family-specific considerations

| Family | Required considerations |
|---|---|
| checkbox | choice allowlist, array ordering/normalization, empty selection |
| select | single versus multiple, null/empty behavior, allowed choices |
| radio | exact choice validation, no arbitrary values |
| button_group | same storage and validation constraints as current contract |
| link | URL, title, target; preserve object structure and supported target values |
| wysiwyg | visual/code modes, sanitization, autosave assumptions, safe list summary, lazy assets |

### R5.2 acceptance criteria

- [ ] Values outside current ACF choices are rejected.
- [ ] Multi-value fields preserve supported order and unrelated values according to the existing contract.
- [ ] Link objects retain URL/title/target structure.
- [ ] WYSIWYG assets load only when needed.
- [ ] Media buttons remain absent if that is the current Visual Editor contract.
- [ ] Shared acknowledgement, stale checks, journal, and reload behavior are intact.
- [ ] An unsupported return/configuration variant is inspect-only rather than coerced.

## R5.3 — Media option fields

R5.3 is distinct from the R1–R2 Media Manager. It exposes explicitly registered **option-owned global image/gallery controls** in the Brand Control Center; it does not add option owners to Media Manager scans.

### In scope

- Registered ACF image option fields
- Registered ACF gallery option fields
- Existing native WordPress Media Library modal
- Attachment-first storage and validation
- Safe image/gallery summaries in the control center

### Family-specific considerations

| Family | Required considerations |
|---|---|
| image | valid local attachment, image MIME type, attachment existence, return-format independence, clear without delete |
| gallery | complete ordered attachment collection, add/replace/remove/reorder, hidden-value preservation, clear without delete |

### R5.3 acceptance criteria

- [ ] Only valid local Media Library image attachments are accepted.
- [ ] Image fields save the canonical attachment representation expected by the existing resolver.
- [ ] Gallery order is retained where storage supports it.
- [ ] Clearing a field does not delete an attachment.
- [ ] Full gallery values are not embedded in lightweight list records unnecessarily.
- [ ] The Media Library modal does not trigger outside-click panel closure.
- [ ] Shared acknowledgement, stale checks, journal, cache invalidation, and reload behavior are verified.
- [ ] Unsupported image/gallery configurations remain inspect-only.

## R5.4 — Connected and taxonomy option fields

### In scope

- Preserve existing option-owned relationship and post-object behavior.
- Route those fields through the registry-backed center.
- Add taxonomy option-field support only where the existing taxonomy mutation contract proves safe ownership and storage behavior.
- Keep collection preservation semantics intact.

### Family-specific considerations

| Family | Required considerations |
|---|---|
| post_object | single/multiple mode, allowed post types, ordered collections where meaningful, hidden ID preservation |
| relationship | mixed post types, subset replacement rules, search, reordering, hidden/excluded ID preservation |
| taxonomy | field return format, term selection, create/save/load settings, term set semantics, no false ordering promises |

### R5.4 acceptance criteria

- [ ] Existing relationship/post-object global workflows have no regression.
- [ ] Allowed object types and choices remain server-validated.
- [ ] Unrelated IDs in mixed collections are not silently removed.
- [ ] Taxonomy term sets save only through the proven contract.
- [ ] Taxonomy order is not represented as durable unless a separate ordering contract exists.
- [ ] Search results respect permissions and configured object types.
- [ ] Shared acknowledgement, stale checks, journal, cache invalidation, and reload behavior are verified.

## Registry and UI behavior during staged rollout

The center must represent support truthfully:

- A registered family enabled in the current point release is **Editable** when its descriptor proves writable.
- A registered family scheduled for a later point release is **Unsupported** or **Inspect only**, not silently hidden if product evidence favors visibility.
- The UI must not promise that “all option fields” are editable before R5.4 is complete.
- Existing supported relationship/post-object controls remain editable throughout.

Follow current product conventions when deciding whether unsupported controls are visible or omitted.

## ACF field registration rules

- Use exact field keys.
- Use canonical option owner/post ID.
- Do not use field names alone as durable identity.
- Do not auto-discover every option field.
- Do not permit clients to enter field keys or owners.
- A provider may register nested existing paths only when the server can prove and rehydrate them safely.
- Conditional logic in the backend does not automatically determine frontend visibility; registry inclusion remains explicit.

## VerticalFramework evidence use

Use the VerticalFramework ACF JSON and catalogs to select representative fixtures for each point release. Prefer real field shapes such as business details, hours, global links, logos, CTA content, or other existing options, but do not hardcode Vertical-specific names into DBVC core.

If a Vertical provider is already present, add registrations in a separate provider change. If not, use DBVC fixtures or settings to prove the generic contracts first.

## Testing matrix

For each family, cover at least:

| Dimension | Required cases |
|---|---|
| Owner | options/global owner; custom ACF option post ID if used by current repo |
| Path | direct field; existing nested group path; existing repeater/flex path only where already supported |
| Value | populated; empty; changed since descriptor hydration |
| Permission | authorized; unauthorized or hidden according to current convention |
| UI | list summary; panel open; validation error; save success; reload behavior |
| Operation | replace; clear where supported; reorder where supported |
| Audit | journal item; DBVC audit hook where available |
| Failure | missing field definition; deleted attachment/object; unsupported config; stale value |

Do not manufacture nested support that the current architecture does not claim. Record gaps explicitly.

## Performance requirements

- Full WYSIWYG, gallery, relationship, post-object, and taxonomy data remains lazy.
- List summaries use bounded data.
- Reuse field-definition caches or batching where present.
- Avoid querying each ACF field group separately per row.
- Large connected collections retain current usability and query limits.

## Program completion criteria

R5 is complete only when:

- [ ] Every currently writable ACF family has an evidence-backed option-owner disposition.
- [ ] Every safely supported family is enabled through the registry-backed center.
- [ ] Any unsupported configuration is clearly documented and inspect-only or unavailable.
- [ ] No generic option/meta mutation path was introduced.
- [ ] Every point release passed its own production gate.
- [ ] Existing owner types and Shared Globals workflows have no regression.
- [ ] The support matrix and user-facing documentation match actual behavior.
