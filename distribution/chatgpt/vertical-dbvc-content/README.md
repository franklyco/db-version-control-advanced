# Vertical DBVC Content — ChatGPT plugin source

This is a version-controlled, skills-only plugin source package for authoring and reviewing DBVC content JSON in supported ChatGPT surfaces. It is intentionally not installed, published, connected to DBVC, or able to create an uploadable ZIP.

## What it does

- Drafts semantic-HTML blog posts, pages, conventional CPT records, and taxonomy terms as DBVC-shaped JSON.
- Uses an attached, current DBVC AI sample package as the target site's field and fingerprint authority.
- Produces a clear handoff for the repository-local Codex/Claude Code companion skill to preflight and package.

## What it never does

- Upload, import, apply, publish, or accept a DBVC warning.
- Invent destination identity, a site fingerprint, ACF reference meta, raw Bricks data, or unsupported media mappings.
- Claim that a JSON draft is upload-ready, preflighted, validated by DBVC, or packaged as a ZIP.

## Release status

The plugin is a private-source, skills-only implementation. It needs a marketplace/install decision before it can be used in ChatGPT web, desktop, or mobile. A future package-only MCP service is a separately gated enhancement; it must be approved for hosting, authentication, privacy, retention, and access controls before implementation.

For the current tested local workflow, use `.agents/skills/vertical-dbvc-content-package/` (or its `.claude/skills/` link) with a current sample package and its offline preflight helper.
