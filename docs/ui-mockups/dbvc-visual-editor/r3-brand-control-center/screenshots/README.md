# Screenshots &mdash; R3 Global Brand Control Center

## Status

**PNG capture is not planned.** Maintainer decision (2026-08-26): the 4 SVG wireframes below are the visual reference for this mockup. Do not queue a PNG capture pass, do not add a Puppeteer / CDP rig.

If PNGs ever prove necessary for a downstream deliverable, they are a manual browser-pass job following the R1 D4A-3 precedent (`Emulation.setDeviceMetricsOverride` at 1440&times;900 and 1280&times;720; see `docs/ui-mockups/dbvc-visual-editor/r1-media-manager/README.md` for the exact command shape). Do NOT use `--window-size` &mdash; it is clamped to a 500px minimum and crops rather than emulates.

## What is present

| File | Purpose |
|---|---|
| `01-happy-path-1440x900.svg` | Wireframe of the happy-path drawer at 1440&times;900. Shows admin bar (32px), drawer geometry (~480&times;~792), site backdrop to the right, toolbar strip with the new Sliders icon. |
| `02-happy-path-1280x720.svg` | Same as above at 1280&times;720. Confirms the drawer geometry does not change width between viewports (fixed 480px). |
| `03-drawer-and-panel-1440x900.svg` | Wireframe showing the drawer + main editor panel coexisting (state 08 in `states.html`). Illustrates the "design-tool sidebar + inspector" pattern described in schematic §5.1.1. |
| `04-states-gallery-overview.svg` | Overview of the 17-cell state gallery in `states.html`. |

## What is NOT present

- Per-state PNG captures at 1440&times;900 and 1280&times;720 for states 01&ndash;17. Not planned.
- Any mobile screenshot. **D-058 forbids mobile/tablet mockups.**

## Text-name notes

The wireframes reference the maintainer-picked drawer title "Global Brand Controls". The SVGs were authored before that rename and use the shortened form "Brand Controls" in tight header space to keep the wireframe legible &mdash; treat that as a labelling shorthand, not a proposed alternative title. The HTML pages (`index.html`, `states.html`) use the full "Global Brand Controls" title consistently.
