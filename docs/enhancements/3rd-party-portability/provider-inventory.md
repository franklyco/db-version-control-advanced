# 3rd Party Portability Provider Inventory

Last checked: 2026-05-19

## Local Form Providers

Detected form provider family:

- `ws-form-pro` - WS Form PRO
- `ws-form-option` - WS Form PRO Option Management
- `ws-form-post` - WS Form PRO Post Management
- `ws-form-stripe-elements` - WS Form PRO Stripe Elements
- `ws-form-openai` - WS Form PRO OpenAI

Common form providers not detected in this LocalWP plugin set:

- Gravity Forms
- Fluent Forms
- Ninja Forms
- Contact Form 7
- Formidable Forms
- Caldera Forms

## Current Provider Status

WS Form is the active first provider.

Implemented first-pass support:

- WS Form form definition export/import.
- WS Form non-sensitive settings export/import.
- Manifest classification as `item_type=third_party`.
- Upload routing into `third-party/ws-form/`.
- Partial backup restore for `third_party` manifest entries.
- Read-only probe in `scripts/check-third-party-portability.php`.

Deferred WS Form areas:

- Submissions.
- Stats.
- Style entities as first-class portable records.
- Add-on-specific entity contracts beyond what is already represented in form meta/settings.
- Any action/provider credentials, which remain excluded and locally preserved.

## Next Provider Candidates

No second standalone WordPress form plugin is installed locally. Do not implement another forms provider until plugin files and storage are available for inspection.

Practical next candidates from the installed plugin set, if scope expands beyond forms:

- Rank Math SEO settings and schema templates.
- HappyFiles folders and media organization data.
- Admin Columns Pro column sets.
- WP All Import / WP All Export templates and settings.
- Bricks ecosystem plugin settings that are not already covered by DBVC Bricks portability.

Each candidate needs its own discovery pass before implementation:

1. Locate custom tables, options, custom post types, taxonomies, and file artifacts.
2. Identify sensitive settings.
3. Determine stable owner/matching keys.
4. Prefer plugin APIs over direct table writes.
5. Ship inspect/export first when save contracts are not proven.

