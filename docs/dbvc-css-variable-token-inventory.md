# DBVC CSS Variable / Token Inventory

Generated: 2026-06-26

## Scope

This inventory parses the installed DBVC plugin source at:

- `/Users/rhettbutler/Documents/LocalWP/frameworkflo-live/app/public/wp-content/plugins/db-version-control-main`

Included source types: `*.css`, `*.scss`, `*.php`, and `*.js`.

Excluded from counts: generated `build/` assets, `node_modules/`, `vendor/`, `docs/`, `sync/`, and backups. This keeps the frequency counts focused on current source styling rather than compiled duplicates, package examples, documentation prose, or historical archives.

Matching rules:

- Definition count: custom property declarations matching `--token:`.
- Reference count: CSS variable reads matching `var(--token)`.
- Total frequency: definitions + `var()` references in parsed source files.

## Summary

- Unique CSS custom property tokens: `132`
- Total definitions: `159`
- Total `var()` references: `862`
- Total counted occurrences: `1021`
- Referenced-only tokens: `4`
- Defined-only tokens: `11`

### Source Files With CSS Tokens

| File | Definitions | References | Unique Tokens |
| --- | ---: | ---: | ---: |
| `addons/content-migration/v2/admin-app/style.css` | 8 | 67 | 9 |
| `addons/visual-editor/assets/css/overlay.css` | 84 | 593 | 85 |
| `src/admin-app/index.js` | 0 | 2 | 2 |
| `src/admin-app/style.css` | 67 | 200 | 38 |

### Referenced-Only Tokens

| Token | Surface | References | Use Case |
| --- | --- | ---: | --- |
| `--dbvc-cc-v2-text` | Content Collector V2 app | 1 | Content Collector V2 scoped token. |
| `--dbvc-color-border` | Main DBVC admin app | 8 | Main admin app border color token. |
| `--dbvc-font-family-monospace` | Main DBVC admin app | 1 | Referenced monospace font-family token; no source definition found in parsed DBVC files. |
| `--dbvc-ve-section-badge-offset` | Visual Editor overlay | 1 | Optional Visual Editor section badge offset custom property with fallback. |

### Defined-Only Tokens

| Token | Surface | Definitions | Use Case |
| --- | --- | ---: | --- |
| `--dbvc-color-gray-600` | Main DBVC admin app | 1 | Main admin app color token. |
| `--dbvc-color-text-light` | Main DBVC admin app | 1 | Main admin app text color token. |
| `--modal-frame-animation-duration` | Unscoped / external | 2 | CSS custom property token. |
| `--dbvc-ve-border--dark-s` | Visual Editor overlay | 1 | Visual Editor reusable border token. |
| `--dbvc-ve-border--muted-s` | Visual Editor overlay | 1 | Visual Editor reusable border token. |
| `--dbvc-ve-color-background-dark` | Visual Editor overlay | 1 | Visual Editor background color token. |
| `--dbvc-ve-color-secondary-rgb` | Visual Editor overlay | 1 | RGB channel companion for Visual Editor `secondary` color opacity/mixing. |
| `--dbvc-ve-color-shared` | Visual Editor overlay | 1 | Visual Editor shared/global context state color. |
| `--dbvc-ve-font--l` | Visual Editor overlay | 1 | Visual Editor font scale token. |
| `--dbvc-ve-font--m` | Visual Editor overlay | 1 | Visual Editor font scale token. |
| `--dbvc-ve-font-weight--normal` | Visual Editor overlay | 1 | Visual Editor font weight token. |

## Full Inventory

| Token | Surface | Use Case | Status | Definitions | References | Total | Definition Locations | Reference Locations |
| --- | --- | --- | --- | ---: | ---: | ---: | --- | --- |
| `--dbvc-cc-v2-accent` | Content Collector V2 app | Content Collector V2 accent/callout token. | Defined + referenced | 1 | 14 | 15 | `addons/content-migration/v2/admin-app/style.css`:8 (1) | `addons/content-migration/v2/admin-app/style.css`:64-1107 (14) |
| `--dbvc-cc-v2-accent-soft` | Content Collector V2 app | Content Collector V2 accent/callout token. | Defined + referenced | 1 | 2 | 3 | `addons/content-migration/v2/admin-app/style.css`:9 (1) | `addons/content-migration/v2/admin-app/style.css`:73, 652 (2) |
| `--dbvc-cc-v2-border` | Content Collector V2 app | Content Collector V2 border color token. | Defined + referenced | 1 | 21 | 22 | `addons/content-migration/v2/admin-app/style.css`:5 (1) | `addons/content-migration/v2/admin-app/style.css`:27-1252 (21) |
| `--dbvc-cc-v2-ink` | Content Collector V2 app | Content Collector V2 text color token. | Defined + referenced | 1 | 5 | 6 | `addons/content-migration/v2/admin-app/style.css`:3 (1) | `addons/content-migration/v2/admin-app/style.css`:11, 111, 218, 846, 863 (5) |
| `--dbvc-cc-v2-muted` | Content Collector V2 app | Content Collector V2 text color token. | Defined + referenced | 1 | 18 | 19 | `addons/content-migration/v2/admin-app/style.css`:4 (1) | `addons/content-migration/v2/admin-app/style.css`:59-1277 (18) |
| `--dbvc-cc-v2-panel` | Content Collector V2 app | Content Collector V2 panel/surface token. | Defined + referenced | 1 | 2 | 3 | `addons/content-migration/v2/admin-app/style.css`:6 (1) | `addons/content-migration/v2/admin-app/style.css`:29, 141 (2) |
| `--dbvc-cc-v2-shadow` | Content Collector V2 app | Content Collector V2 elevation/shadow token. | Defined + referenced | 1 | 3 | 4 | `addons/content-migration/v2/admin-app/style.css`:10 (1) | `addons/content-migration/v2/admin-app/style.css`:30, 142, 950 (3) |
| `--dbvc-cc-v2-surface` | Content Collector V2 app | Content Collector V2 panel/surface token. | Defined + referenced | 1 | 1 | 2 | `addons/content-migration/v2/admin-app/style.css`:7 (1) | `addons/content-migration/v2/admin-app/style.css`:38 (1) |
| `--dbvc-cc-v2-text` | Content Collector V2 app | Content Collector V2 scoped token. | Referenced only | 0 | 1 | 1 | - | `addons/content-migration/v2/admin-app/style.css`:1063 (1) |
| `--dbvc-background-blur-filter` | Main DBVC admin app | Main admin app glass/blur filter token. | Defined + referenced | 1 | 1 | 2 | `src/admin-app/style.css`:32 (1) | `src/admin-app/style.css`:2016 (1) |
| `--dbvc-color-accent-blue` | Main DBVC admin app | Main admin app accent/status color token. | Defined + referenced | 1 | 21 | 22 | `src/admin-app/style.css`:11 (1) | `src/admin-app/style.css`:69-2152 (21) |
| `--dbvc-color-accent-gold` | Main DBVC admin app | Main admin app accent/status color token. | Defined + referenced | 1 | 1 | 2 | `src/admin-app/style.css`:21 (1) | `src/admin-app/style.css`:664 (1) |
| `--dbvc-color-background-blur` | Main DBVC admin app | Main admin app surface/background token. | Defined + referenced | 1 | 1 | 2 | `src/admin-app/style.css`:31 (1) | `src/admin-app/style.css`:2017 (1) |
| `--dbvc-color-border` | Main DBVC admin app | Main admin app border color token. | Referenced only | 0 | 8 | 8 | - | `src/admin-app/style.css`:1452-1718 (8) |
| `--dbvc-color-border-default` | Main DBVC admin app | Main admin app border color token. | Defined + referenced | 1 | 19 | 20 | `src/admin-app/style.css`:9 (1) | `src/admin-app/style.css`:56-2073 (19) |
| `--dbvc-color-border-muted` | Main DBVC admin app | Main admin app border color token. | Defined + referenced | 1 | 6 | 7 | `src/admin-app/style.css`:10 (1) | `src/admin-app/style.css`:342, 360, 735, 1310, 1326, 2153 (6) |
| `--dbvc-color-danger` | Main DBVC admin app | Main admin app danger/destructive state color. | Defined + referenced | 1 | 2 | 3 | `src/admin-app/style.css`:16 (1) | `src/admin-app/style.css`:233, 2125 (2) |
| `--dbvc-color-danger-strong` | Main DBVC admin app | Main admin app danger/destructive state color. | Defined + referenced | 1 | 2 | 3 | `src/admin-app/style.css`:17 (1) | `src/admin-app/style.css`:657, 1994 (2) |
| `--dbvc-color-gray-600` | Main DBVC admin app | Main admin app color token. | Defined only | 1 | 0 | 1 | `src/admin-app/style.css`:23 (1) | - |
| `--dbvc-color-panel-muted` | Main DBVC admin app | Main admin app surface/background token. | Defined + referenced | 1 | 3 | 4 | `src/admin-app/style.css`:15 (1) | `src/admin-app/style.css`:734, 1436, 2093 (3) |
| `--dbvc-color-success` | Main DBVC admin app | Main admin app success state color. | Defined + referenced | 1 | 2 | 3 | `src/admin-app/style.css`:18 (1) | `src/admin-app/style.css`:1278, 1279 (2) |
| `--dbvc-color-success-dark` | Main DBVC admin app | Main admin app success state color. | Defined + referenced | 1 | 3 | 4 | `src/admin-app/style.css`:24 (1) | `src/admin-app/style.css`:1108, 1120, 1814 (3) |
| `--dbvc-color-success-pale` | Main DBVC admin app | Main admin app success state color. | Defined + referenced | 1 | 2 | 3 | `src/admin-app/style.css`:20 (1) | `src/admin-app/style.css`:671, 2039 (2) |
| `--dbvc-color-success-soft` | Main DBVC admin app | Main admin app success state color. | Defined + referenced | 1 | 4 | 5 | `src/admin-app/style.css`:19 (1) | `src/admin-app/style.css`:754, 1106, 1118, 1273 (4) |
| `--dbvc-color-success-text` | Main DBVC admin app | Main admin app text color token. | Defined + referenced | 1 | 3 | 4 | `src/admin-app/style.css`:25 (1) | `src/admin-app/style.css`:224, 673, 766 (3) |
| `--dbvc-color-surface-highlight` | Main DBVC admin app | Main admin app surface/background token. | Defined + referenced | 1 | 6 | 7 | `src/admin-app/style.css`:12 (1) | `src/admin-app/style.css`:72, 258, 266, 349, 378, 580 (6) |
| `--dbvc-color-surface-muted` | Main DBVC admin app | Main admin app surface/background token. | Defined + referenced | 1 | 12 | 13 | `src/admin-app/style.css`:13 (1) | `src/admin-app/index.js`:2764 (1)<br>`src/admin-app/style.css`:327-1948 (11) |
| `--dbvc-color-surface-table` | Main DBVC admin app | Main admin app surface/background token. | Defined + referenced | 1 | 10 | 11 | `src/admin-app/style.css`:14 (1) | `src/admin-app/style.css`:473-1910 (10) |
| `--dbvc-color-text-dark` | Main DBVC admin app | Main admin app text color token. | Defined + referenced | 1 | 2 | 3 | `src/admin-app/style.css`:3 (1) | `src/admin-app/style.css`:1107, 1119 (2) |
| `--dbvc-color-text-light` | Main DBVC admin app | Main admin app text color token. | Defined only | 1 | 0 | 1 | `src/admin-app/style.css`:4 (1) | - |
| `--dbvc-color-text-muted` | Main DBVC admin app | Main admin app text color token. | Defined + referenced | 1 | 25 | 26 | `src/admin-app/style.css`:7 (1) | `src/admin-app/style.css`:143-2119 (25) |
| `--dbvc-color-text-primary` | Main DBVC admin app | Main admin app text color token. | Defined + referenced | 1 | 18 | 19 | `src/admin-app/style.css`:6 (1) | `src/admin-app/style.css`:138-2143 (18) |
| `--dbvc-color-text-subtle` | Main DBVC admin app | Main admin app text color token. | Defined + referenced | 1 | 8 | 9 | `src/admin-app/style.css`:8 (1) | `src/admin-app/index.js`:2791 (1)<br>`src/admin-app/style.css`:97-1767 (7) |
| `--dbvc-color-warning-amber` | Main DBVC admin app | Main admin app warning state color. | Defined + referenced | 1 | 2 | 3 | `src/admin-app/style.css`:22 (1) | `src/admin-app/style.css`:1819, 1972 (2) |
| `--dbvc-color-white` | Main DBVC admin app | Main admin app white/base surface color. | Defined + referenced | 1 | 24 | 25 | `src/admin-app/style.css`:5 (1) | `src/admin-app/style.css`:120-2211 (24) |
| `--dbvc-font-family-monospace` | Main DBVC admin app | Referenced monospace font-family token; no source definition found in parsed DBVC files. | Referenced only | 0 | 1 | 1 | - | `src/admin-app/style.css`:568 (1) |
| `--dbvc-toggle-thumb-offset` | Main DBVC admin app | CSS custom property token. | Defined + referenced | 1 | 1 | 2 | `src/admin-app/style.css`:1733 (1) | `src/admin-app/style.css`:1741 (1) |
| `--dbvc-badge-bg-alpha` | Main admin app / badges | Main admin app badge background alpha token. | Defined + referenced | 1 | 1 | 2 | `src/admin-app/style.css`:33 (1) | `src/admin-app/style.css`:1100 (1) |
| `--dbvc-badge-border-darken` | Main admin app / badges | Main admin app badge border darkening token. | Defined + referenced | 1 | 1 | 2 | `src/admin-app/style.css`:34 (1) | `src/admin-app/style.css`:1101 (1) |
| `--dbvc-badge-h` | Main admin app / badges | Main admin app per-badge HSL override token. | Defined + referenced | 11 | 3 | 14 | `src/admin-app/style.css`:1097-1173 (11) | `src/admin-app/style.css`:1100, 1101, 1102 (3) |
| `--dbvc-badge-l` | Main admin app / badges | Main admin app per-badge HSL override token. | Defined + referenced | 11 | 3 | 14 | `src/admin-app/style.css`:1099-1175 (11) | `src/admin-app/style.css`:1100, 1101, 1102 (3) |
| `--dbvc-badge-s` | Main admin app / badges | Main admin app per-badge HSL override token. | Defined + referenced | 11 | 3 | 14 | `src/admin-app/style.css`:1098-1174 (11) | `src/admin-app/style.css`:1100, 1101, 1102 (3) |
| `--dbvc-badge-text-darken` | Main admin app / badges | Main admin app badge text darkening token. | Defined + referenced | 1 | 1 | 2 | `src/admin-app/style.css`:35 (1) | `src/admin-app/style.css`:1102 (1) |
| `--dbvc-proposal-table-header-height` | Main admin app / proposal UI | Main admin app proposal table sizing token. | Defined + referenced | 1 | 1 | 2 | `src/admin-app/style.css`:28 (1) | `src/admin-app/style.css`:1308 (1) |
| `--dbvc-proposal-table-row-height` | Main admin app / proposal UI | Main admin app proposal table sizing token. | Defined + referenced | 1 | 1 | 2 | `src/admin-app/style.css`:27 (1) | `src/admin-app/style.css`:1308 (1) |
| `--dbvc-proposal-table-visible-rows` | Main admin app / proposal UI | Main admin app proposal table sizing token. | Defined + referenced | 1 | 1 | 2 | `src/admin-app/style.css`:26 (1) | `src/admin-app/style.css`:1308 (1) |
| `--modal-frame-animation-duration` | Unscoped / external | CSS custom property token. | Defined only | 2 | 0 | 2 | `src/admin-app/style.css`:1534, 1542 (2) | - |
| `--dbvc-ve-badge-color-bg--related` | Visual Editor overlay | Visual Editor badge color token. | Defined + referenced | 1 | 7 | 8 | `addons/visual-editor/assets/css/overlay.css`:12 (1) | `addons/visual-editor/assets/css/overlay.css`:797-2508 (7) |
| `--dbvc-ve-border--dark-s` | Visual Editor overlay | Visual Editor reusable border token. | Defined only | 1 | 0 | 1 | `addons/visual-editor/assets/css/overlay.css`:76 (1) | - |
| `--dbvc-ve-border--light-s` | Visual Editor overlay | Visual Editor reusable border token. | Defined + referenced | 1 | 3 | 4 | `addons/visual-editor/assets/css/overlay.css`:75 (1) | `addons/visual-editor/assets/css/overlay.css`:1955, 1965, 2012 (3) |
| `--dbvc-ve-border--muted-s` | Visual Editor overlay | Visual Editor reusable border token. | Defined only | 1 | 0 | 1 | `addons/visual-editor/assets/css/overlay.css`:78 (1) | - |
| `--dbvc-ve-border--surface-s` | Visual Editor overlay | Visual Editor reusable border token. | Defined + referenced | 1 | 6 | 7 | `addons/visual-editor/assets/css/overlay.css`:77 (1) | `addons/visual-editor/assets/css/overlay.css`:1819, 1882, 2034, 2053, 2089, 2114 (6) |
| `--dbvc-ve-border-radius--l` | Visual Editor overlay | Visual Editor radius token. | Defined + referenced | 1 | 18 | 19 | `addons/visual-editor/assets/css/overlay.css`:74 (1) | `addons/visual-editor/assets/css/overlay.css`:109-2115 (18) |
| `--dbvc-ve-border-radius--m` | Visual Editor overlay | Visual Editor radius token. | Defined + referenced | 1 | 4 | 5 | `addons/visual-editor/assets/css/overlay.css`:73 (1) | `addons/visual-editor/assets/css/overlay.css`:231, 547, 894, 2048 (4) |
| `--dbvc-ve-border-radius--s` | Visual Editor overlay | Visual Editor radius token. | Defined + referenced | 1 | 39 | 40 | `addons/visual-editor/assets/css/overlay.css`:72 (1) | `addons/visual-editor/assets/css/overlay.css`:206-2229 (39) |
| `--dbvc-ve-box-shadow` | Visual Editor overlay | Visual Editor shadow/elevation token. | Defined + referenced | 1 | 3 | 4 | `addons/visual-editor/assets/css/overlay.css`:79 (1) | `addons/visual-editor/assets/css/overlay.css`:111, 1958, 1970 (3) |
| `--dbvc-ve-box-shadow--button` | Visual Editor overlay | Visual Editor shadow/elevation token. | Defined + referenced | 1 | 1 | 2 | `addons/visual-editor/assets/css/overlay.css`:80 (1) | `addons/visual-editor/assets/css/overlay.css`:128 (1) |
| `--dbvc-ve-box-shadow--panel` | Visual Editor overlay | Visual Editor shadow/elevation token. | Defined + referenced | 1 | 3 | 4 | `addons/visual-editor/assets/css/overlay.css`:82 (1) | `addons/visual-editor/assets/css/overlay.css`:233, 896, 2036 (3) |
| `--dbvc-ve-box-shadow--soft` | Visual Editor overlay | Visual Editor shadow/elevation token. | Defined + referenced | 1 | 3 | 4 | `addons/visual-editor/assets/css/overlay.css`:81 (1) | `addons/visual-editor/assets/css/overlay.css`:208, 549, 1885 (3) |
| `--dbvc-ve-color--empty` | Visual Editor overlay | Visual Editor color token. | Defined + referenced | 1 | 6 | 7 | `addons/visual-editor/assets/css/overlay.css`:13 (1) | `addons/visual-editor/assets/css/overlay.css`:2399, 2405, 2406, 2424, 2460, 2464 (6) |
| `--dbvc-ve-color-accent` | Visual Editor overlay | Visual Editor base palette color. | Defined + referenced | 1 | 13 | 14 | `addons/visual-editor/assets/css/overlay.css`:6 (1) | `addons/visual-editor/assets/css/overlay.css`:77-2362 (13) |
| `--dbvc-ve-color-accent-rgb` | Visual Editor overlay | RGB channel companion for Visual Editor `accent` color opacity/mixing. | Defined + referenced | 1 | 1 | 2 | `addons/visual-editor/assets/css/overlay.css`:7 (1) | `addons/visual-editor/assets/css/overlay.css`:205 (1) |
| `--dbvc-ve-color-background-dark` | Visual Editor overlay | Visual Editor background color token. | Defined only | 1 | 0 | 1 | `addons/visual-editor/assets/css/overlay.css`:20 (1) | - |
| `--dbvc-ve-color-background-dark--opacity-m` | Visual Editor overlay | Visual Editor background color token. | Defined + referenced | 1 | 3 | 4 | `addons/visual-editor/assets/css/overlay.css`:21 (1) | `addons/visual-editor/assets/css/overlay.css`:110, 126, 157 (3) |
| `--dbvc-ve-color-background-light` | Visual Editor overlay | Visual Editor background color token. | Defined + referenced | 1 | 9 | 10 | `addons/visual-editor/assets/css/overlay.css`:18 (1) | `addons/visual-editor/assets/css/overlay.css`:1821-2358 (9) |
| `--dbvc-ve-color-background-light--opacity-m` | Visual Editor overlay | Visual Editor background color token. | Defined + referenced | 1 | 1 | 2 | `addons/visual-editor/assets/css/overlay.css`:19 (1) | `addons/visual-editor/assets/css/overlay.css`:1957 (1) |
| `--dbvc-ve-color-border` | Visual Editor overlay | Visual Editor border color token. | Defined + referenced | 1 | 18 | 19 | `addons/visual-editor/assets/css/overlay.css`:53 (1) | `addons/visual-editor/assets/css/overlay.css`:230-1738 (18) |
| `--dbvc-ve-color-border-muted` | Visual Editor overlay | Visual Editor border color token. | Defined + referenced | 1 | 18 | 19 | `addons/visual-editor/assets/css/overlay.css`:54 (1) | `addons/visual-editor/assets/css/overlay.css`:250-1930 (18) |
| `--dbvc-ve-color-dark` | Visual Editor overlay | Visual Editor base palette color. | Defined + referenced | 1 | 8 | 9 | `addons/visual-editor/assets/css/overlay.css`:10 (1) | `addons/visual-editor/assets/css/overlay.css`:15-1396 (8) |
| `--dbvc-ve-color-dark-rgb` | Visual Editor overlay | RGB channel companion for Visual Editor `dark` color opacity/mixing. | Defined + referenced | 1 | 5 | 6 | `addons/visual-editor/assets/css/overlay.css`:11 (1) | `addons/visual-editor/assets/css/overlay.css`:21, 176, 1348, 1389, 1398 (5) |
| `--dbvc-ve-color-error` | Visual Editor overlay | Visual Editor error/destructive state color. | Defined + referenced | 1 | 3 | 4 | `addons/visual-editor/assets/css/overlay.css`:34 (1) | `addons/visual-editor/assets/css/overlay.css`:191, 2022, 2352 (3) |
| `--dbvc-ve-color-error-bg` | Visual Editor overlay | Visual Editor error/destructive state color. | Defined + referenced | 1 | 2 | 3 | `addons/visual-editor/assets/css/overlay.css`:36 (1) | `addons/visual-editor/assets/css/overlay.css`:1408, 1839 (2) |
| `--dbvc-ve-color-error-border` | Visual Editor overlay | Visual Editor border color token. | Defined + referenced | 1 | 1 | 2 | `addons/visual-editor/assets/css/overlay.css`:37 (1) | `addons/visual-editor/assets/css/overlay.css`:2336 (1) |
| `--dbvc-ve-color-error-border-strong` | Visual Editor overlay | Visual Editor border color token. | Defined + referenced | 1 | 3 | 4 | `addons/visual-editor/assets/css/overlay.css`:38 (1) | `addons/visual-editor/assets/css/overlay.css`:567, 916, 1407 (3) |
| `--dbvc-ve-color-error-dark` | Visual Editor overlay | Visual Editor error/destructive state color. | Defined + referenced | 1 | 3 | 4 | `addons/visual-editor/assets/css/overlay.css`:35 (1) | `addons/visual-editor/assets/css/overlay.css`:1402, 1409, 1664 (3) |
| `--dbvc-ve-color-focus` | Visual Editor overlay | Visual Editor focus ring color. | Defined + referenced | 1 | 8 | 9 | `addons/visual-editor/assets/css/overlay.css`:26 (1) | `addons/visual-editor/assets/css/overlay.css`:1348-2146 (8) |
| `--dbvc-ve-color-info` | Visual Editor overlay | Visual Editor info state color. | Defined + referenced | 1 | 2 | 3 | `addons/visual-editor/assets/css/overlay.css`:44 (1) | `addons/visual-editor/assets/css/overlay.css`:1022, 1439 (2) |
| `--dbvc-ve-color-info-bg` | Visual Editor overlay | Visual Editor info state color. | Defined + referenced | 1 | 3 | 4 | `addons/visual-editor/assets/css/overlay.css`:45 (1) | `addons/visual-editor/assets/css/overlay.css`:1021, 1182, 1438 (3) |
| `--dbvc-ve-color-info-border` | Visual Editor overlay | Visual Editor border color token. | Defined + referenced | 1 | 1 | 2 | `addons/visual-editor/assets/css/overlay.css`:46 (1) | `addons/visual-editor/assets/css/overlay.css`:1020 (1) |
| `--dbvc-ve-color-info-border-strong` | Visual Editor overlay | Visual Editor border color token. | Defined + referenced | 1 | 2 | 3 | `addons/visual-editor/assets/css/overlay.css`:47 (1) | `addons/visual-editor/assets/css/overlay.css`:1181, 1436 (2) |
| `--dbvc-ve-color-inspect-border` | Visual Editor overlay | Visual Editor border color token. | Defined + referenced | 1 | 1 | 2 | `addons/visual-editor/assets/css/overlay.css`:52 (1) | `addons/visual-editor/assets/css/overlay.css`:912 (1) |
| `--dbvc-ve-color-light` | Visual Editor overlay | Visual Editor base palette color. | Defined + referenced | 1 | 5 | 6 | `addons/visual-editor/assets/css/overlay.css`:8 (1) | `addons/visual-editor/assets/css/overlay.css`:14, 18, 24, 75, 2196 (5) |
| `--dbvc-ve-color-light-rgb` | Visual Editor overlay | RGB channel companion for Visual Editor `light` color opacity/mixing. | Defined + referenced | 1 | 1 | 2 | `addons/visual-editor/assets/css/overlay.css`:9 (1) | `addons/visual-editor/assets/css/overlay.css`:19 (1) |
| `--dbvc-ve-color-primary` | Visual Editor overlay | Visual Editor base palette color. | Defined + referenced | 1 | 17 | 18 | `addons/visual-editor/assets/css/overlay.css`:2 (1) | `addons/visual-editor/assets/css/overlay.css`:85-2327 (17) |
| `--dbvc-ve-color-primary-rgb` | Visual Editor overlay | RGB channel companion for Visual Editor `primary` color opacity/mixing. | Defined + referenced | 1 | 1 | 2 | `addons/visual-editor/assets/css/overlay.css`:3 (1) | `addons/visual-editor/assets/css/overlay.css`:26 (1) |
| `--dbvc-ve-color-secondary` | Visual Editor overlay | Visual Editor base palette color. | Defined + referenced | 1 | 25 | 26 | `addons/visual-editor/assets/css/overlay.css`:4 (1) | `addons/visual-editor/assets/css/overlay.css`:687-2363 (25) |
| `--dbvc-ve-color-secondary-rgb` | Visual Editor overlay | RGB channel companion for Visual Editor `secondary` color opacity/mixing. | Defined only | 1 | 0 | 1 | `addons/visual-editor/assets/css/overlay.css`:5 (1) | - |
| `--dbvc-ve-color-shared` | Visual Editor overlay | Visual Editor shared/global context state color. | Defined only | 1 | 0 | 1 | `addons/visual-editor/assets/css/overlay.css`:48 (1) | - |
| `--dbvc-ve-color-shared-bg` | Visual Editor overlay | Visual Editor shared/global context state color. | Defined + referenced | 1 | 1 | 2 | `addons/visual-editor/assets/css/overlay.css`:49 (1) | `addons/visual-editor/assets/css/overlay.css`:1833 (1) |
| `--dbvc-ve-color-shared-border` | Visual Editor overlay | Visual Editor border color token. | Defined + referenced | 1 | 7 | 8 | `addons/visual-editor/assets/css/overlay.css`:51 (1) | `addons/visual-editor/assets/css/overlay.css`:459-2344 (7) |
| `--dbvc-ve-color-shared-surface` | Visual Editor overlay | Visual Editor surface/panel color token. | Defined + referenced | 1 | 1 | 2 | `addons/visual-editor/assets/css/overlay.css`:50 (1) | `addons/visual-editor/assets/css/overlay.css`:461 (1) |
| `--dbvc-ve-color-success` | Visual Editor overlay | Visual Editor success state color. | Defined + referenced | 1 | 15 | 16 | `addons/visual-editor/assets/css/overlay.css`:39 (1) | `addons/visual-editor/assets/css/overlay.css`:178-1935 (15) |
| `--dbvc-ve-color-success-bg` | Visual Editor overlay | Visual Editor success state color. | Defined + referenced | 1 | 5 | 6 | `addons/visual-editor/assets/css/overlay.css`:41 (1) | `addons/visual-editor/assets/css/overlay.css`:326, 593, 806, 1177, 1618 (5) |
| `--dbvc-ve-color-success-border` | Visual Editor overlay | Visual Editor border color token. | Defined + referenced | 1 | 7 | 8 | `addons/visual-editor/assets/css/overlay.css`:42 (1) | `addons/visual-editor/assets/css/overlay.css`:324-1617 (7) |
| `--dbvc-ve-color-success-chip` | Visual Editor overlay | Visual Editor success state color. | Defined + referenced | 1 | 1 | 2 | `addons/visual-editor/assets/css/overlay.css`:43 (1) | `addons/visual-editor/assets/css/overlay.css`:842 (1) |
| `--dbvc-ve-color-success-dark` | Visual Editor overlay | Visual Editor success state color. | Defined + referenced | 1 | 1 | 2 | `addons/visual-editor/assets/css/overlay.css`:40 (1) | `addons/visual-editor/assets/css/overlay.css`:843 (1) |
| `--dbvc-ve-color-surface` | Visual Editor overlay | Visual Editor surface/panel color token. | Defined + referenced | 1 | 39 | 40 | `addons/visual-editor/assets/css/overlay.css`:22 (1) | `addons/visual-editor/assets/css/overlay.css`:97-2362 (39) |
| `--dbvc-ve-color-surface-glass` | Visual Editor overlay | Visual Editor surface/panel color token. | Defined + referenced | 1 | 6 | 7 | `addons/visual-editor/assets/css/overlay.css`:25 (1) | `addons/visual-editor/assets/css/overlay.css`:207, 232, 548, 895, 1884, 2035 (6) |
| `--dbvc-ve-color-surface-muted` | Visual Editor overlay | Visual Editor surface/panel color token. | Defined + referenced | 1 | 24 | 25 | `addons/visual-editor/assets/css/overlay.css`:24 (1) | `addons/visual-editor/assets/css/overlay.css`:251-2300 (24) |
| `--dbvc-ve-color-surface-rgb` | Visual Editor overlay | RGB channel companion for Visual Editor `surface` color opacity/mixing. | Defined + referenced | 1 | 7 | 8 | `addons/visual-editor/assets/css/overlay.css`:23 (1) | `addons/visual-editor/assets/css/overlay.css`:25-2001 (7) |
| `--dbvc-ve-color-text-dark` | Visual Editor overlay | Visual Editor text color token. | Defined + referenced | 1 | 35 | 36 | `addons/visual-editor/assets/css/overlay.css`:15 (1) | `addons/visual-editor/assets/css/overlay.css`:234-2254 (35) |
| `--dbvc-ve-color-text-light` | Visual Editor overlay | Visual Editor text color token. | Defined + referenced | 1 | 3 | 4 | `addons/visual-editor/assets/css/overlay.css`:14 (1) | `addons/visual-editor/assets/css/overlay.css`:2015, 2154, 2189 (3) |
| `--dbvc-ve-color-text-muted` | Visual Editor overlay | Visual Editor text color token. | Defined + referenced | 1 | 24 | 25 | `addons/visual-editor/assets/css/overlay.css`:16 (1) | `addons/visual-editor/assets/css/overlay.css`:209-2301 (24) |
| `--dbvc-ve-color-text-subtle` | Visual Editor overlay | Visual Editor text color token. | Defined + referenced | 1 | 11 | 12 | `addons/visual-editor/assets/css/overlay.css`:17 (1) | `addons/visual-editor/assets/css/overlay.css`:410-1633 (11) |
| `--dbvc-ve-color-warning` | Visual Editor overlay | Visual Editor warning state color. | Defined + referenced | 1 | 8 | 9 | `addons/visual-editor/assets/css/overlay.css`:27 (1) | `addons/visual-editor/assets/css/overlay.css`:196-2295 (8) |
| `--dbvc-ve-color-warning-bg` | Visual Editor overlay | Visual Editor warning state color. | Defined + referenced | 1 | 7 | 8 | `addons/visual-editor/assets/css/overlay.css`:30 (1) | `addons/visual-editor/assets/css/overlay.css`:1042-2285 (7) |
| `--dbvc-ve-color-warning-bg-soft` | Visual Editor overlay | Visual Editor warning state color. | Defined + referenced | 1 | 3 | 4 | `addons/visual-editor/assets/css/overlay.css`:31 (1) | `addons/visual-editor/assets/css/overlay.css`:447, 496, 1009 (3) |
| `--dbvc-ve-color-warning-border` | Visual Editor overlay | Visual Editor border color token. | Defined + referenced | 1 | 8 | 9 | `addons/visual-editor/assets/css/overlay.css`:32 (1) | `addons/visual-editor/assets/css/overlay.css`:445-2331 (8) |
| `--dbvc-ve-color-warning-border-strong` | Visual Editor overlay | Visual Editor border color token. | Defined + referenced | 1 | 1 | 2 | `addons/visual-editor/assets/css/overlay.css`:33 (1) | `addons/visual-editor/assets/css/overlay.css`:1040 (1) |
| `--dbvc-ve-color-warning-dark` | Visual Editor overlay | Visual Editor warning state color. | Defined + referenced | 1 | 2 | 3 | `addons/visual-editor/assets/css/overlay.css`:29 (1) | `addons/visual-editor/assets/css/overlay.css`:1043, 1508 (2) |
| `--dbvc-ve-color-warning-strong` | Visual Editor overlay | Visual Editor warning state color. | Defined + referenced | 1 | 6 | 7 | `addons/visual-editor/assets/css/overlay.css`:28 (1) | `addons/visual-editor/assets/css/overlay.css`:448, 483, 497, 1010, 1834, 2180 (6) |
| `--dbvc-ve-filter-backdrop--blur` | Visual Editor overlay | Visual Editor backdrop filter token. | Defined + referenced | 1 | 4 | 5 | `addons/visual-editor/assets/css/overlay.css`:83 (1) | `addons/visual-editor/assets/css/overlay.css`:112, 1959, 1971, 2037 (4) |
| `--dbvc-ve-font--body` | Visual Editor overlay | Visual Editor font scale token. | Defined + referenced | 1 | 5 | 6 | `addons/visual-editor/assets/css/overlay.css`:62 (1) | `addons/visual-editor/assets/css/overlay.css`:258, 1192, 1243, 1945, 2059 (5) |
| `--dbvc-ve-font--caption` | Visual Editor overlay | Visual Editor font scale token. | Defined + referenced | 1 | 24 | 25 | `addons/visual-editor/assets/css/overlay.css`:63 (1) | `addons/visual-editor/assets/css/overlay.css`:210-2043 (24) |
| `--dbvc-ve-font--l` | Visual Editor overlay | Visual Editor font scale token. | Defined only | 1 | 0 | 1 | `addons/visual-editor/assets/css/overlay.css`:59 (1) | - |
| `--dbvc-ve-font--m` | Visual Editor overlay | Visual Editor font scale token. | Defined only | 1 | 0 | 1 | `addons/visual-editor/assets/css/overlay.css`:60 (1) | - |
| `--dbvc-ve-font--meta` | Visual Editor overlay | Visual Editor font scale token. | Defined + referenced | 1 | 24 | 25 | `addons/visual-editor/assets/css/overlay.css`:64 (1) | `addons/visual-editor/assets/css/overlay.css`:329-2119 (24) |
| `--dbvc-ve-font--overline` | Visual Editor overlay | Visual Editor font scale token. | Defined + referenced | 1 | 9 | 10 | `addons/visual-editor/assets/css/overlay.css`:65 (1) | `addons/visual-editor/assets/css/overlay.css`:180-2241 (9) |
| `--dbvc-ve-font--s` | Visual Editor overlay | Visual Editor font scale token. | Defined + referenced | 1 | 1 | 2 | `addons/visual-editor/assets/css/overlay.css`:61 (1) | `addons/visual-editor/assets/css/overlay.css`:946 (1) |
| `--dbvc-ve-font-icon--l` | Visual Editor overlay | Visual Editor icon font sizing token. | Defined + referenced | 1 | 2 | 3 | `addons/visual-editor/assets/css/overlay.css`:68 (1) | `addons/visual-editor/assets/css/overlay.css`:1984, 1985 (2) |
| `--dbvc-ve-font-icon--m` | Visual Editor overlay | Visual Editor icon font sizing token. | Defined + referenced | 1 | 2 | 3 | `addons/visual-editor/assets/css/overlay.css`:67 (1) | `addons/visual-editor/assets/css/overlay.css`:1827, 1828 (2) |
| `--dbvc-ve-font-icon--s` | Visual Editor overlay | Visual Editor icon font sizing token. | Defined + referenced | 1 | 2 | 3 | `addons/visual-editor/assets/css/overlay.css`:66 (1) | `addons/visual-editor/assets/css/overlay.css`:1817, 1818 (2) |
| `--dbvc-ve-font-weight--bold` | Visual Editor overlay | Visual Editor font weight token. | Defined + referenced | 1 | 18 | 19 | `addons/visual-editor/assets/css/overlay.css`:70 (1) | `addons/visual-editor/assets/css/overlay.css`:211-2044 (18) |
| `--dbvc-ve-font-weight--heavy` | Visual Editor overlay | Visual Editor font weight token. | Defined + referenced | 1 | 20 | 21 | `addons/visual-editor/assets/css/overlay.css`:71 (1) | `addons/visual-editor/assets/css/overlay.css`:181-2242 (20) |
| `--dbvc-ve-font-weight--normal` | Visual Editor overlay | Visual Editor font weight token. | Defined only | 1 | 0 | 1 | `addons/visual-editor/assets/css/overlay.css`:69 (1) | - |
| `--dbvc-ve-notice-dot--color-bg` | Visual Editor overlay | Visual Editor notice dot color token. | Defined + referenced | 1 | 1 | 2 | `addons/visual-editor/assets/css/overlay.css`:85 (1) | `addons/visual-editor/assets/css/overlay.css`:2014 (1) |
| `--dbvc-ve-section-badge-offset` | Visual Editor overlay | Optional Visual Editor section badge offset custom property with fallback. | Referenced only | 0 | 1 | 1 | - | `addons/visual-editor/assets/css/overlay.css`:2521 (1) |
| `--dbvc-ve-size-icon-height` | Visual Editor overlay | Visual Editor toolbar/icon sizing token. | Defined + referenced | 1 | 2 | 3 | `addons/visual-editor/assets/css/overlay.css`:58 (1) | `addons/visual-editor/assets/css/overlay.css`:1977, 2383 (2) |
| `--dbvc-ve-size-icon-width` | Visual Editor overlay | Visual Editor toolbar/icon sizing token. | Defined + referenced | 1 | 2 | 3 | `addons/visual-editor/assets/css/overlay.css`:57 (1) | `addons/visual-editor/assets/css/overlay.css`:1976, 2382 (2) |
| `--dbvc-ve-size-toolbar-height` | Visual Editor overlay | Visual Editor toolbar/icon sizing token. | Defined + referenced | 1 | 3 | 4 | `addons/visual-editor/assets/css/overlay.css`:56 (1) | `addons/visual-editor/assets/css/overlay.css`:1953, 1964, 2377 (3) |
| `--dbvc-ve-size-toolbar-width` | Visual Editor overlay | Visual Editor toolbar/icon sizing token. | Defined + referenced | 1 | 2 | 3 | `addons/visual-editor/assets/css/overlay.css`:55 (1) | `addons/visual-editor/assets/css/overlay.css`:1963, 2376 (2) |
| `--dbvc-ve-transparent--l` | Visual Editor overlay | Visual Editor `color-mix()` transparent stop token. | Defined + referenced | 1 | 2 | 3 | `addons/visual-editor/assets/css/overlay.css`:84 (1) | `addons/visual-editor/assets/css/overlay.css`:1968, 2197 (2) |

## Notes

- The Visual Editor overlay is the most mature token surface, with a full `--dbvc-ve-*` palette plus sizing, typography, border, shadow, and state tokens.
- The main admin app uses `--dbvc-*` colors and badge/table variables, but it still contains some direct hard-coded colors outside the custom-property system.
- Content Collector V2 has a compact scoped shell token set under `--dbvc-cc-v2-*`; older Content Migration and Bricks Portability styles mostly use direct WordPress-admin colors and are not represented in this custom-property table unless they define or read custom properties.
- `--dbvc-font-family-monospace` and `--dbvc-ve-section-badge-offset` are referenced with fallbacks/assumptions but were not defined in the parsed source files.