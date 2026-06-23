# UI States and Copy

## Overlay states

### Idle
Markers visible on supported nodes.

### Hover
Show compact affordance:
- Edit
- Inspect
- Unsupported

### Loading descriptor
Message:
- Loading field details…

### Editable
Show:
- field label
- scope chip
- owner badge when the source owner is not the current page post
- input
- save / cancel

### Read-only
Message:
- This value is inspectable but not editable here.

### Derived
Message:
- This value is generated from other content and cannot be edited directly.

### Unsupported
Message:
- This content source is not yet supported by Visual Editor.

### Saving
Message:
- Saving…

### Save success
Message:
- Saved successfully.

### Save error
Message:
- Save failed. The value was not applied.

## Scope chips

- Current Page
- Related Post
- Related Term
- Loop Item
- Global
- Term
- Derived
- Locked

## Border / badge color system

Preserve the existing outline/border differentiator across source scopes.

- Current Page: teal
- Global / shared: amber
- Related non-current entity: coral / red
- Derived / locked: slate / muted

Rule:
- the marker outline color stays the primary source cue
- the badge accent must reuse the same scope color
- the modal scope chip and notice accent must also reuse that scope color
- do not introduce a new unrelated badge color that conflicts with the source-scope border system
