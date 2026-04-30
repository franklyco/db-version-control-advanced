# Data Contracts

## DOM marker contract

Example:
`data-dbvc-ve="ve_4f8a1d"`

Rules:
- token only
- no raw meta key
- no raw field key unless separately justified
- no direct client-authoritative save target
- optional non-sensitive render metadata such as `data-dbvc-ve-context="text"` or `data-dbvc-ve-context="link_href"` is allowed when needed so the overlay can compare and update the correct rendered projection
- non-sensitive loop ownership details may live in the server-side descriptor payload, but not in public DOM save targets

## Editable descriptor contract

```json
{
  "token": "ve_4f8a1d",
  "status": "editable",
  "scope": "shared_entity",
  "entity": {
    "type": "option",
    "id": "option",
    "subtype": "option",
    "acf_object_id": "option"
  },
  "render": {
    "template_id": 251,
    "element_id": "x9k2lm",
    "element_name": "heading",
    "setting_key": "text",
    "attribute_key": "_root",
    "context": "text",
    "source_group": "vesg_6f89e4b7a0c1",
    "sync_group": "veg_18fe4a7c0e2b",
    "display_key": "title",
    "display_mode": "text",
    "render_verified": true,
    "rendered_text": "Contact Sales",
    "resolved_text": "Contact Sales"
  },
  "source": {
    "type": "acf_field",
    "expression": "{acf_cta_link:title}",
    "expression_args": ["title"],
    "field_name": "cta_link",
    "field_key": "field_64abc123",
    "field_type": "link",
    "return_format": "array",
    "media_size": ""
  },
  "resolver": {
    "name": "acf_link",
    "version": 1
  },
  "ui": {
    "label": "CTA Link",
    "input": "link",
    "warning": "This field resolves to a shared options-level ACF target. Saving here affects every frontend context using that option value."
  }
}
```

Repeater-backed descriptors use the same contract with row metadata added to `source`, for example:

```json
{
  "source": {
    "type": "acf_repeater_subfield",
    "field_name": "faq_answer",
    "field_key": "field_66bd749f71065",
    "field_type": "wysiwyg",
    "container_type": "repeater",
    "parent_field_name": "faq_group_items_repeater",
    "parent_field_key": "field_66bd748d71063",
    "row_index": 0
  }
}
```

`scope` values currently used:
- `current_entity`
- `shared_entity`
- `related_entity`

`status` values currently used:
- `editable`
- `readonly`
- `unsupported`

## Save request contract

```json
{
  "token": "ve_4f8a1d",
  "value": {
    "url": "https://example.com/contact",
    "title": "Contact Sales",
    "target": "_blank"
  },
  "acknowledgeSharedScope": true,
  "nonce": "..."
}
```

Scalar fields continue to submit a scalar `value`.

Choice-style fields may submit:
- a string for single-value controls
- an array of strings for checkbox or multi-select controls

Link fields submit an object with:
- `url`
- `title`
- `target`

Image fields may submit an object with:
- `attachmentId`
- `url`

`attachmentId` is the canonical save target for image fields. A pasted local Media Library URL is only used as a fallback lookup when no attachment ID is supplied.

In the current slice, the backend only accepts image changes that resolve to an existing local Media Library attachment, and the normalized image value returned after save includes image-specific render metadata such as:
- `attachmentId`
- `renderUrl`
- `fullUrl`
- `renderAttributes.src`
- `renderAttributes.srcset`
- `renderAttributes.sizes`

For `shared_entity` descriptors, `acknowledgeSharedScope` must be truthy or the save request is rejected.
The same acknowledgement gate is also used for `related_entity` descriptors so the editor must explicitly confirm that the save targets a related post shown in the loop, not the current page.

## Save response contract

```json
{
  "ok": true,
  "token": "ve_4f8a1d",
  "status": "saved",
  "value": {
    "url": "https://example.com/contact",
    "title": "Contact Sales",
    "target": "_blank"
  },
  "displayValue": "Contact Sales",
  "displayMode": "text",
  "displayCandidates": [
    {
      "key": "url",
      "value": "https://example.com/contact",
      "mode": "text"
    },
    {
      "key": "title",
      "value": "Contact Sales",
      "mode": "text"
    }
  ],
  "sourceGroup": "vesg_6f89e4b7a0c1",
  "syncGroup": "veg_18fe4a7c0e2b",
  "message": "Saved successfully."
}
```

## Session bootstrap response

The authenticated session bootstrap endpoint can also return hydrated descriptor payloads for the current page so the overlay can cache editable field data up front.

```json
{
  "ok": true,
  "sessionId": "ves_ab12cd34ef56",
  "descriptors": {
    "ve_4f8a1d": {
      "token": "ve_4f8a1d",
      "label": "CTA Link",
      "input": "link",
      "status": "editable"
    }
  },
  "descriptorHydrations": {
    "ve_4f8a1d": {
      "descriptor": {
        "token": "ve_4f8a1d"
      },
      "currentValue": {
        "url": "https://example.com/contact",
        "title": "Contact Sales",
        "target": "_blank"
      },
      "displayValue": "Contact Sales",
      "displayMode": "text",
      "acknowledgementType": "shared",
      "canEdit": true,
      "requiresSharedScopeAck": true,
      "editMessage": ""
    }
  }
}
```

## Verification note

Descriptors are only persisted for nodes that survive render verification.

If a Bricks node is initially identified as an editable candidate but its rendered text does not match any resolver-approved display projection, the marker is removed before the response reaches the browser and the descriptor is dropped from the session.

For link-attribute markers, the same rule applies against the rendered attribute value such as `href` instead of the node text content.

For structured field types such as links and checkbox/select-like values, the descriptor stores the matched `render.display_key` so the live overlay can keep updating the same visible projection after save.

For image-source markers, the descriptor can also store `source.media_size` so the resolver can compare and live-update the same Bricks-rendered image size while still editing the underlying attachment-backed field safely.

Inspect-only descriptors may remain visible even when the rendered page value is only one derived projection of a more complex backend field value. In those cases the descriptor still carries `status = readonly`, and the overlay must not treat the descriptor as saveable even if it can inspect the raw value.

Descriptors also carry a session-local `render.sync_group` so repeated markers for the same resolved field projection can update together after a successful save without exposing the raw backend target in the DOM.

Descriptors now also carry a broader `render.source_group` so structured saves can update other matched projections of the same resolved field, such as an ACF link title marker and a separate ACF link URL marker on the same page.

For repeater row descendants, both `render.source_group` and `render.sync_group` include the parent repeater identity and row index so a save on one row does not live-update sibling rows of the same field.

For repeater-style Bricks collection links, `render.attribute_key` can hold a deterministic anchor key such as `a-0`, which lets the server verify and update each marker against the correct rendered anchor tag inside one element response.

For related-post loop rows, `render.loop_signature` keeps descriptor identity stable per loop row so repeated Bricks element UIDs do not collide across different related posts in the same query.

## Audit entry contract

At minimum:
- token
- entity type
- entity id
- field name
- old value
- new value
- changed by user id
- timestamp
- page URL if available
- template id / element id if available
