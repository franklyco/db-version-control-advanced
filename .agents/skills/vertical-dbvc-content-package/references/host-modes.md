# Host modes and delivery boundaries

The same DBVC content contract can be used on several AI surfaces, but the deliverable must match the tools actually available on that surface.

## Local Codex or Claude Code

- This repository-local skill can read a current DBVC sample package, stage returned JSON files, run `dbvc_submission_preflight.py`, and create a ZIP only when the report is clean.
- A clean offline report supports the limited claim that the ZIP passed the local structural preflight. It does not prove DBVC will accept, translate, import, or publish it.
- DBVC upload, warning acceptance, translation, import, and publication always remain an operator action.

## ChatGPT web or mobile, skills-only plugin

- The source package is tracked at `distribution/chatgpt/vertical-dbvc-content/`.
- It can inspect available attachments, draft semantic-HTML content, and return the manifest and entity JSON as separately named artifacts or code blocks for review.
- It has no executable preflight helper, package-writing tool, DBVC connection, or authentication. It must not create a ZIP, label output upload-ready, or represent a structural review as a DBVC validation result.
- Move reviewed JSON and the original current sample package to the local companion workflow before producing an operator-uploadable ZIP.

## Future package-only MCP service

An optional future MCP service may expose package parsing, structural preflight, and ZIP assembly after its hosting, authentication, privacy, retention, and access controls are approved. Its scope must remain package-only: it must not upload to DBVC, accept warnings, import content, or publish WordPress content.

## Safe handoff language

Use `JSON draft for local preflight` for web/mobile output. Use `offline preflight clean; operator upload required` only after the local helper has reported no warnings or errors and created the ZIP.
