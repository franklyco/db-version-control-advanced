# 04 — Interaction Flows

## Flow A — Standard safe review

Use when the page is mostly clean.

1. Open page in workbench.
2. Review top bar status and section readiness.
3. Click Hero section.
4. Inspector shows section summary.
5. Use **Accept all safe** if the section looks correct.
6. Repeat for other sections.
7. Review unmatched and warnings dock.
8. Mark page ready if thresholds are satisfied.

Expected operator burden: low.

## Flow B — Resolve ambiguous field assignment

1. Select a source block from the left pane.
2. Center pane scrolls to the recommended destination.
3. Inspector shows recommendation, reasons, and alternatives.
4. If wrong, click **Reassign**.
5. Open manual field picker.
6. Search by human label or semantic role.
7. Select new target slot.
8. Preview transform.
9. Confirm reassignment.

Expected operator burden: moderate, but fast.

## Flow C — Handle unmatched source

1. Open bottom dock to the unmatched tab.
2. Select an unmatched item.
3. Center pane highlights likely target section or leaves none highlighted.
4. Choose:
   - assign,
   - add to repeater,
   - keep unresolved,
   - discard as noise.
5. Confirm.

Expected operator burden: deliberate but safe.

## Flow D — Repeater review

Use for reviews, FAQs, services, gallery items, and similar repeated structures.

1. Open repeater section.
2. Switch to row stack or table view.
3. Inspect row-level proposals.
4. Merge or split rows if needed.
5. Add unmatched items into repeater rows.
6. Accept section.

## Flow E — Conflict resolution

1. Select slot or source block marked as conflict.
2. Inspector displays competing assignments.
3. Choose one target.
4. Clear the losing assignment.
5. Revalidate.

## Flow F — Intentional unresolved handling

1. Operator determines no safe assignment exists.
2. Click **Mark unresolved**.
3. Choose reason:
   - low evidence
   - ambiguous sibling
   - no valid target
   - extraction noise
   - contract mismatch
   - needs manual authoring
4. Item remains visible in unresolved surfaces.

## Flow G — Page readiness review

1. Open package/page readiness modal or summary surface.
2. Review:
   - unresolved count
   - blocked items
   - contract mismatches
   - override count
   - drift warnings
3. If acceptable, mark page ready.
4. Otherwise continue review.

## Interaction design guidance

### Primary interaction model
Prefer:
- click to inspect
- click to accept
- click or search to reassign

### Secondary interaction model
Allow drag-and-drop for:
- unmatched item into section
- unmatched item into repeater row
- repeater reordering
- media placement

Do not make drag-and-drop mandatory for standard acceptance or reassignment.
