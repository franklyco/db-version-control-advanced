# 09 · Security, Validation, and Permissions

## Required capability model

This feature should be restricted to trusted roles only.

Recommended capability:
- a DBVC-specific manage capability if available
- fallback: `manage_options`

Do not expose raw import/apply/rollback to general editors by default.

## Nonce and REST protection

If using REST endpoints:

- require logged-in authenticated requests
- verify capability server-side on every request
- use nonces as needed for admin calls
- never trust client-submitted decisions without server-side validation

## Upload validation

On package upload:

- enforce `.zip` extension
- inspect actual archive contents, not only filename
- limit file size
- reject unexpected files
- reject path traversal filenames
- reject malformed JSON
- verify checksums
- verify package version compatibility

## Data validation

For each domain:

- ensure option payload shape matches expected schema
- reject unknown critical structure when parser cannot safely continue
- surface a helpful error instead of guessing

## Safe parsing rule

Do not assume Bricks internal structure is perfectly stable across versions.

Instead:
- version the parser/normalizer
- detect unsupported structures
- mark affected domain as non-applicable or unsupported

## Output escaping

Because the tool will display raw JSON-like diff details in admin:

- escape all rendered labels
- do not render untrusted raw content as HTML
- treat package notes and site labels as untrusted

## Backup protection

Backups may contain sensitive styling/business config and internal structure.

Store them:
- outside public access if possible
- behind DBVC private file serving or deny rules
- with checksum validation

## Logging guidance

Log enough for support, but not raw giant payloads in every log line.

Good log targets:
- job start/end
- domain compare summary
- apply summary
- validation failure reason
- rollback result

Avoid:
- dumping full options into debug logs by default

## High-risk domains

Mark these as high-risk or advanced:

- breakpoints
- icon sets
- custom icons
- font faces
- any domain with media/file references

Require stronger warnings before apply.

## Compatibility validation

Before apply, compare:
- source Bricks version
- target Bricks version
- package normalization version
- DBVC feature version

Mismatch should not always block, but it should warn or block when parser compatibility is unknown.
