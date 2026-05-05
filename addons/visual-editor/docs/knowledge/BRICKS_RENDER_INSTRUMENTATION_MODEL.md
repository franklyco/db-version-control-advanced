# Bricks Render Instrumentation Model

## Goal

Instrument Bricks-rendered elements during render so the frontend overlay can identify supported editable nodes without guessing from final DOM alone.

## Core pattern

1. A request enters visual editor mode.
2. Bricks hooks are registered for this request.
3. Supported elements receive lightweight marker attributes such as `data-dbvc-ve`.
4. A request-scoped descriptor registry stores the full mapping for each marker token.
5. The overlay uses the token to fetch safe descriptor details.

## Why this model

This model is safer and more durable than:
- DOM scraping
- selector-only targeting
- reverse mapping from visible text
- client-submitted arbitrary meta keys

## Descriptor creation requirements

A descriptor should be created only if:
- the request is in visual editor mode
- the current user can edit
- the element contains a supported source mapping
- the resolver registry can classify the source

## Descriptor statuses

- `editable`
- `readonly`
- `derived`
- `unsupported`
- `locked`

## Rendering rule

The DOM marker must remain lightweight and stable. The registry is the authority.
