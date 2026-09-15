# Web and mobile delivery boundary

This skills-only plugin is intended for supported ChatGPT web, desktop, and mobile surfaces after the operator installs it through an approved marketplace path. It can draft and review JSON only.

## Required wording

For a site-specific result, label the response `JSON draft for local preflight`. State that the original current DBVC sample package and the returned JSON must go to the local companion skill before a ZIP can be assembled.

## Prohibited claims and actions

- Do not create or attach a ZIP.
- Do not call any result upload-ready, preflighted, DBVC-validated, imported, or published.
- Do not upload to DBVC, accept warnings, apply content, or request credentials.
- Do not claim an attachment was inspected if its contents were not available in the current conversation.

## Handoff to the local companion

Provide the intended canonical paths, exact JSON, operation, entity counts, source sample date/fingerprint when actually known, and a list of omitted or deferred fields. The local Codex/Claude Code companion is responsible for structural preflight and final ZIP assembly; DBVC remains responsible for its own intake and translation validation.
