# 09 — Data Contracts and Status Model

This document describes suggested UI-level data contracts. These can be derived from the existing DBVC backend contracts if needed.

## Page-level workbench payload

Suggested shape:

```json
{
  "pageId": "source-page-001",
  "sourceUrl": "/services/general-dentistry",
  "targetObject": {
    "type": "post",
    "subtype": "service",
    "id": "target-service-123",
    "label": "General Dentistry"
  },
  "pageStatus": "needs_review",
  "sections": [],
  "sourceBlocks": [],
  "unmatchedBlocks": [],
  "warnings": [],
  "conflicts": [],
  "audit": []
}
```

## Section status enum

Recommended values:
- `not_started`
- `partially_reviewed`
- `safe_auto`
- `needs_review`
- `blocked`
- `ready`

## Slot status enum

Recommended values:
- `empty`
- `recommended`
- `accepted`
- `manually_reassigned`
- `unresolved`
- `blocked`
- `invalid_contract`
- `conflict`
- `overridden`

## Source block status enum

Recommended values:
- `unused`
- `recommended`
- `accepted`
- `assigned_elsewhere`
- `unresolved`
- `discarded`
- `conflict`

## Unresolved reason enum

Suggested starter values:
- `low_evidence`
- `ambiguous_sibling`
- `no_valid_target`
- `contract_mismatch`
- `extraction_noise`
- `needs_manual_authoring`

## Suggested section payload

```json
{
  "id": "section-hero",
  "label": "Hero",
  "sectionType": "hero",
  "status": "needs_review",
  "readinessScore": 0.75,
  "unresolvedCount": 1,
  "warningCount": 0,
  "slots": []
}
```

## Suggested slot payload

```json
{
  "id": "slot-hero-title",
  "label": "Hero Title",
  "technicalPath": "group.hero_section.title",
  "fieldType": "text",
  "status": "recommended",
  "confidence": 0.94,
  "valuePreview": "Comprehensive Family Dentistry in Columbus",
  "assignedSourceBlockId": "src-001",
  "alternativeSourceBlockIds": ["src-002", "src-005"],
  "validation": {
    "isValid": true,
    "warnings": []
  },
  "contractSummary": {
    "valueType": "string",
    "writable": true
  }
}
```

## Suggested source block payload

```json
{
  "id": "src-001",
  "type": "heading",
  "preview": "Comprehensive Family Dentistry in Columbus",
  "rawValue": "Comprehensive Family Dentistry in Columbus",
  "normalizedValue": "Comprehensive Family Dentistry in Columbus",
  "inferredSection": "hero",
  "headingPath": ["Hero"],
  "domPath": "body > main > section:nth-child(1) > h1",
  "sourceUrl": "/services/general-dentistry",
  "status": "recommended",
  "confidence": 0.97,
  "recommendedTargetSlotIds": ["slot-hero-title"],
  "warnings": []
}
```

## UI adapter guidance

If the backend currently returns a more field-centric recommendation model, the UI adapter can derive:

- sections from field groups / branch paths / semantic role
- slot labels from current field metadata
- source evidence previews from extracted content units
- unresolved tray by filtering unused or unassignable source units

Do not block the workbench build on a perfect backend contract if a useful adapter can be built safely.
