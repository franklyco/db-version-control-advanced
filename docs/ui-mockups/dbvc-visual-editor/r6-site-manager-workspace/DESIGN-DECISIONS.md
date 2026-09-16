# R6 mockup — design decisions

Every decision has a rationale and a rejected alternative, mapped to the release spec (`releases/R6-FRONTEND-SITE-MANAGER-WORKSPACE.md`) and the runtime contract (`releases/R6-WORKSPACE-STATE-CONTRACT.md`). Read alongside `COMPONENT-NOTES.md` and `WIRING-SCHEMATIC.md`. Format follows `../r4-expanded-brand-control-center/DESIGN-DECISIONS.md`.

## Pinned before the mockup (R6-B, 2026-09-15 — maintainer confirmed)

These were decided in the contract and are inherited, not re-litigated here: left-anchored 480px drawer in the BCC's slot with the BCC overlaying it (D-065); no users (D-066); no attachments / media via native library + Media Manager entry (D-067); existing route extended, honest `frontendUrl` (D-068); no recent list (D-069); `localStorage` scope (D-070); Escape precedence + non-modal landmark (D-071); `--dbvc-ve-z-workspace` 120005 + panel clamp inset (D-072); Go To Object popover stays as fallback (D-073).

## Decided during R6-C (D1 → D3)

### 1. Type navigation is a BCC-style tab strip, not Go To Object chips — **maintainer, D1**

- **What**: `__types` is a `role="tablist"` of pill tabs; selected = `--color-secondary` fill, exactly the R3/R4 drawer tab treatment.
- **Rationale**: the workspace is the drawer family's third member; sharing the tab language with the BCC makes the two surfaces read as siblings when stacked (S-11). Chips carry "filter" semantics; here the choice is exclusive navigation.
- **Rejected**: green `__filter` chips from the Go To Object popover (reference image 03 uses chips). They would import the popover's success-green into a surface that otherwise uses secondary/primary only.
- **Ref**: contract §4; component map §8.

### 2. Two always-visible actions per row (Open + Edit) — **maintainer, D1**

- **What**: routeable rows show a filled **Open** and an outlined **Edit ↗**; no hover-revealed controls.
- **Rationale**: keyboard discoverability — hover-only affordances are invisible to keyboard/AT users and to anyone scanning a 30-row page. Two 28px targets fit the 480px width with a 2-line title clamp.
- **Rejected**: primary-only with Edit on hover/focus (denser); a kebab menu (extra keystroke for the second-most-common action).
- **Ref**: spec §Navigation behavior; contract §4 row anatomy.

### 3. Rows without a frontend route get a single **Edit** — never an invented Open — plus an explicit note when the status alone would not explain it — **maintainer, D2**

- **What**: `hasFrontendRoute:false` → one primary-styled Edit (new tab). Draft/Pending/Scheduled/Private rows rely on the status chip text. Published posts on non-viewable types and terms in non-queryable taxonomies additionally show `__row-note` "No public page" / "No public archive".
- **Rationale**: "Represent objects without a frontend route honestly rather than inventing one" is an acceptance criterion. A Published chip next to a missing Open would otherwise read as a bug.
- **Rejected**: a disabled Open button (implies the route exists but is blocked); hiding such rows (they are still editable objects the user asked to navigate).
- **Ref**: spec §Object navigation rules; E-142 route honesty.

### 4. Current-object card shows Edit + "You are here", not Open — **maintainer, D2**

- **What**: `current-frontend` is not rendered when `currentObject.frontendUrl === location.href`.
- **Rationale**: Open would reload the page the user is already on; "You are here" answers the actual question. Production applies the same equality rule and renders Open only for the rare mismatch (e.g. canonical redirect landed elsewhere).
- **Rejected**: always rendering both (parity with rows) — noise on every page load.
- **Ref**: contract §3.1 `current-frontend` / `current-backend`.

### 5. Header uses the flippable chrome-header token instead of the BCC's white→light gradient

- **What**: `__header` background = `--dbvc-ve-color-chrome-header-background`; `__sticky` and `__sections` = `--dbvc-ve-color-drawer-background`.
- **Rationale**: those tokens are redefined by `overlay.css` in dark mode (R5.later-darkmode.b); the BCC gradient hardcodes `--color-surface` (not flipped) and stays white on a dark drawer — a residual `.d`-class rough edge the workspace should not inherit.
- **Rejected**: copying `control-center.css` line 104 verbatim for pixel parity in light mode. The two headers still match in light mode (both resolve to the light-lavender family).
- **Ref**: component map R6 addendum; `overlay.css:125–160`.

### 6. Dark-mode primary actions and selected tabs use the accent (lavender) instead of navy

- **What**: under dark tokens, `__action--primary` and `[aria-selected="true"]` fill with `--dbvc-ve-color-accent` and dark text.
- **Rationale**: brand-intent tokens are deliberately not flipped in production (darkmode.c), so navy-on-navy-drawer loses the affordance entirely. The accent is already a brand token; no new colour is introduced.
- **Rejected**: keeping navy (fails contrast against the 0.94-dark drawer); introducing a dedicated dark-primary token (new colour — contract forbids).
- **Ref**: styles.css §4; contract §10 "zero new colors".

### 7. Four rule-level dark overrides for controls whose light value is `--color-surface`

- **What**: `__search`, `__action--secondary`, `__status-chip`, `__load-more` / `__retry` switch to `--surface-muted` under dark tokens.
- **Rationale**: identical to what R5.later-darkmode.b did for BCC chrome; `--color-surface` stays white by design (darkmode.c). Until a `.d` tokenisation slice exists, R6-D-1 ships the same four rules inside the production media block.
- **Rejected**: waiting for darkmode.d (would ship the workspace with white inputs on a dark drawer).

### 8. Status chip carries text always; colour is reinforcement — and the TERM chip stays

- **What**: `__status-chip--{statusKey}` renders the server `status` label in uppercase; success/warning/info tints by family. Term rows keep the "Term" chip even though `typeLabel` already says the taxonomy.
- **Rationale**: no colour-only meaning (spec §Laptop/desktop and accessible behavior). Keeping the chip for terms preserves a uniform row grammar (type · status) across the interleaved "All" list; the redundancy is one 4-letter chip.
- **Rejected**: dropping the chip for terms (breaks the grammar; D2 review flagged this as a *candidate* — recorded here as **deferred to production review**, not adopted).

### 9. Empty / error blocks never wipe rows; errors are `role="alert"`

- **What**: S-08 keeps the previous page visible under an alert block with Retry; S-09b (mode inactive) uses a warning-tinted variant with "Refresh page".
- **Rationale**: contract §9 S-08 "previous rows remain (never wipe on error)"; a 403 is not an error the user caused, so it is warning-toned and explains the safe route.
- **Rejected**: replacing the list with the error (loses navigation context); inline status text only (not announced assertively).

### 10. Tools section as a second tab, not a footer block

- **What**: `__sections` tablist switches the whole body between Navigate and Tools.
- **Rationale**: the drawer height budget at 1280×720 (~612px) cannot afford a persistent 5-entry tool block under a scrolling list; a tab keeps every entry at full target size. Unavailable tools stay visible-but-disabled with the reason (S-18b).
- **Rejected**: persistent footer block (steals ~230px from results); tools inside the header as icon buttons (no room for the availability reason).

### 11. Type strip gets a scroll-edge fade

- **What**: `mask-image` fade on the right edge of `__types`.
- **Rationale**: at 480px the eight fixture types overflow; without a cue the strip reads as ending at "Categories" (D2 review).
- **Rejected**: wrapping to two rows (contract says never wrap; costs sticky-strip height); a "more" overflow menu (hides types).

### 12. Proposed production token correction: dark `--dbvc-ve-color-text-subtle` 0.52 → 0.58

- **What**: the mockup's dark block ships 0.58.
- **Rationale**: axe (D4) measured the live dark value at **4.45:1** on `--surface-muted` for 11px meta text (`__overline`, `__row-type`) — just under AA. 0.58 clears 4.5:1. Same class of finding as the R1-D4A light `--text-subtle` defect.
- **Rejected**: bumping meta text to 12px (touches every drawer); using `--text-muted` for meta (loses the muted/subtle hierarchy).
- **Action**: R6-D-1 (or a darkmode.d slice) corrects `overlay.css:~147`; regression-test the BCC too.

### 13. `grid` glyph for the Workspace toolbar button; first in the dock

- **What**: four rounded squares (inline SVG in `index.html` / `states.html`), inserted before Review Fields when `workspace.enabled`.
- **Rationale**: matches the reference concept's "manager" glyph; the dock order mirrors the reference toolbar (Workspace is the entry point to everything else). No existing `renderToolbarIcon` key fits (`layers` = Review Fields, `search` = Go To Object).
- **Rejected**: reusing `more`/`globe`; appending at the end of the dock.
- **Action**: R6-D-1 adds `grid` to `renderToolbarIcon`.

### 14. Kind filter as a segmented radiogroup; sort as a native select; "Best match" search-scoped — **R6.1-b, 2026-09-16 (maintainer: "Content", no most-used, view state)**

- **What**: one 32px `__controls` row between search and the type strip: `__segmented` `role="radiogroup"` **All · Content · Taxonomies** (roving ←/→, same component as the Preferences popover) and a native `<select>` for **Recently updated · Title A → Z · Title Z → A · Newest first · Oldest first**, plus **Best match** only while a search term is present. The kind is not new state — it is `activeType.objectType`; picking a kind narrows the strip to that kind and the strip's own "All" tab means "all types of this kind"; picking a type tab implies its kind. Sort persists with the view state (`dbvc-ve-workspace:v1.sort`); `relevance` never persists. The status line names the order when it is not the default (S-20).
- **Rationale**: the live site has 41 types in one strip; a three-state kind filter maps 1:1 onto the read model's existing `objectType` and halves the strip without inventing a second taxonomy of filters. A select is the densest accessible control for six options at 480px and reads correctly to AT; a second tab strip or a popover would compete with the type strip. "Content" rather than "Posts" because the status chip already says Page / Service — users think of those as content. Auto-switching to Best match on typing (and back on clear) keeps today's search behaviour without a hidden mode; an explicit non-default choice is respected while searching.
- **Rejected**: "Most used" for taxonomies only (`count DESC` — a browse default, not a navigation intent; would give the two kinds different vocabularies); `menu_order` / `comment_count` / author; sort as a viewer preference in the Preferences popover (it is a view setting, not a preference); a dropdown menu for kind (three options do not need a menu).
- **Action**: R6.1-b ships it in `workspace-app.js` / `workspace.css` with the `workspaceKind*` / `workspaceSort*` i18n family; the mockup copy of the controls CSS uses the mockup's pre-darkmode.d primitives (secondary / accent) while production uses `--emphasis-*` / `--control-surface`.

## Accepted / adapted / rejected — candidate list for Codex sign-off (D4)

### Accepted as shown
- Drawer shell, header, current-object card, section tabs, sticky search + type strip, status line, row anatomy, footer, empty/error blocks, Tools list (decisions 1–4, 8–11, 13).
- S-01…S-19 state set as the R6-D jsdom target list.
- Dark-mode token approach (decision 5–7).

### Accepted with adaptation (for R6-D)
- `__row-note` copy and the `current-frontend` equality rule are proposals; final i18n strings are approved in R6-D-1 via the `workspace*` key family.
- Static `.is-focus-visible` and `.is-motion-off` are mockup-only; production uses the real pseudo-class/media query.
- The sibling-surface stand-ins (`__bcc*`, `__mm*`, `__panel*`) are illustrative; production stacking is verified in real Chrome during R6-E.
- Skeleton shimmer is optional in production; the shape (3 rows, tab pills) is the contract.

### Rejected or deferred
- Per-type counts, Recently Opened, Pinned, Users/Media rows, "Open Fields", ⌘K, Publish (reference images) — out of scope, unchanged.
- Dropping the TERM chip — deferred to production review (decision 8).
- A visible sliver of the workspace under the BCC (D3 review question) — not adopted; would require unequal drawer widths and reopens D-065.
- Fixing the toolbar count badge contrast (white on `--color-primary`, 2.95:1) — **production defect outside R6 scope**; recorded for the toolbar owner (see README §Known limitations).
