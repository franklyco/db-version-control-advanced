# Mockup Deliverable Contract

Store accepted static mockups under the current repository’s documentation convention, for example:

```text
docs/ui-mockups/dbvc-visual-editor/
├── r1-media-manager/
├── r4-global-brand-control-center/
└── r6-site-manager-workspace/
```

Use an existing convention if the repository already has one.

## Required files per mockup

### `index.html`

Primary representative layout with static fixture data.

### `styles.css`

Mockup-only, scoped CSS. It is not production CSS.

### `states.html`

Required runtime states not represented in the primary layout.

### `README.md`

Include:

- release/product goal;
- viewport assumptions;
- interaction notes;
- fixture disclaimer;
- known omissions;
- any mockup-only JavaScript;
- external asset/license notes, ideally none.

### `COMPONENT-NOTES.md`

For each visible component:

- user purpose;
- display data;
- available actions;
- responsive behavior;
- accessibility intent;
- state variations.

### Optional `mockup.js`

Only minimal static state toggling. No network requests, persistence, upload, or production event architecture.

## File and asset rules

- No minified source.
- No build step unless approved.
- No remote fonts, frameworks, icon kits, or images by default.
- Simple local/inline SVG placeholders only when needed and licensed.
- Avoid base64 blobs that hinder review.
- No real client data.
- Use obvious fixture content.
- Keep the mockup self-contained.
- Use a release-specific root namespace.

## State coverage

| State | R1 Media scan | R2 Media remediation | R4 Brand center | R6 Workspace |
|---|---:|---:|---:|---:|
| Populated/default | Required | Required | Required | Required |
| Loading | Required | Required | Required | Required |
| Empty | Required | Required | Required | Required |
| No search matches | Required | Required | Required | Required |
| Error/retry | Required | Required | Required | Required |
| Permission/unavailable | Required | Required | Required | Required |
| Request unavailable/expired | Required | Required | Required | Required |
| Long labels/content | Required | Required | Required | Required |
| Keyboard focus | Required | Required | Required | Required |
| Narrow viewport | Preserve the accepted D4A baseline; no new work under D-036 | Tabled by D-036 | Tabled by D-036 | Tabled by D-036 |
| Mobile/slide-over | Tabled by D-036 | Tabled by D-036 | Tabled by D-036 | Tabled by D-036 |
| Short handset height/reachability | Existing D4A evidence only; further work tabled | Tabled by D-036 | Tabled by D-036 | Tabled by D-036 |
| Scan progress/expiry | Required | Reference | N/A | N/A |
| Collapsed/expanded row | Required | Required | N/A | N/A |
| Stale/resolved since scan | Required | Required | N/A | N/A |
| Draft media selection | N/A | Required | N/A | N/A |
| Media modal layering notes | N/A | Required | When image field opens | Required coexistence |
| Per-field save failure | N/A | Required | N/A | N/A |
| Inspect-only/unsupported | Required | Required | Required | When applicable |
| Descriptor-loading transition | Reference | Required | Required | Reference |
| Object without frontend route | N/A | N/A | N/A | Required |
| Pagination/load more | Required | Required | When needed | Required |
| Coexistence with main panel | Reference | Required | Required | Required |

## Sign-off record

After review, create an integration note with:

### Accepted as shown

### Accepted with adaptation

### Rejected or deferred

The mockup is not production-approved merely because it renders correctly.
