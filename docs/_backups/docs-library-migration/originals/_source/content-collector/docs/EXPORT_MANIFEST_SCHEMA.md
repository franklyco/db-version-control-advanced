# Export Manifest Schema

Note:
- This document describes the current source plugin manifest contract.
- For DBVC addon rollout, validation gates, and migration sequencing, use:
  - `docs/CONTENT_COLLECTOR_ADDON_PLAYBOOK/CONTENT_COLLECTOR_ADDON_MANIFEST.json`
  - `docs/CONTENT_COLLECTOR_ADDON_PLAYBOOK/PHASE_PLAN.md`
  - `docs/CONTENT_COLLECTOR_ADDON_PLAYBOOK/GUARDRAILS.md`

This schema is the canonical metadata contract for every export bundle (`json`, `yaml`, or `md`).

## File Name

`manifest.json` (always included in the export package root)

## JSON Structure

```json
{
  "schema_version": "1.0",
  "export_id": "cc_export_8f1a",
  "generated_at": "2026-02-26T17:20:00Z",
  "plugin_version": "1.10.0",
  "domain": "example.com",
  "scope": {
    "mode": "subtree",
    "path": "services"
  },
  "format": "yaml",
  "include_assets": true,
  "deterministic_mode": true,
  "ai": {
    "requested": true,
    "fallback_mode": true,
    "model": "gpt-4o-mini",
    "prompt_version": "v1",
    "status": "partial_fallback",
    "counts": {
      "not_started": 2,
      "queued": 0,
      "processing": 0,
      "done": 48,
      "fallback_done": 14,
      "failed": 0
    },
    "rerun_available": true
  },
  "policies": {
    "slug_collision": "append-path-hash",
    "taxonomy_collision": "match-existing-review",
    "redaction": {
      "emails": true,
      "phones": true,
      "forms": true
    }
  },
  "totals": {
    "pages": 64,
    "assets": 510,
    "warnings": 3,
    "errors": 1
  },
  "files": [
    {
      "path": "content/services/web-design.yaml",
      "type": "page",
      "source_url": "https://example.com/services/web-design/",
      "canonical_source_url": "https://example.com/services/web-design",
      "content_hash": "sha256:...",
      "checksum_sha256": "sha256:...",
      "size_bytes": 12874,
      "ai": {
        "status": "done",
        "mode": "ai",
        "job_id": "cc_ai_123abc",
        "analysis_file": "web-design.analysis.json",
        "sanitized_file": "web-design.sanitized.json",
        "sanitized_html_file": "web-design.sanitized.html",
        "message": "AI processing completed successfully."
      },
      "pii_flags": {
        "emails_count": 1,
        "phones_count": 0,
        "forms_count": 1,
        "requires_legal_review": true
      },
      "status": "exported",
      "error": null
    }
  ],
  "redirect_map": {
    "file": "redirects/redirect-map.json",
    "count": 64
  },
  "observability": {
    "log_file": "logs/events.ndjson",
    "crawl_events": 140,
    "ai_events": 78,
    "export_events": 16
  }
}
```

## Required Fields

- `schema_version`
- `export_id`
- `generated_at`
- `plugin_version`
- `domain`
- `scope`
- `format`
- `deterministic_mode`
- `ai.fallback_mode`
- `policies`
- `totals`
- `files`

## Deterministic Export Rules

- `files[]` must be sorted by `canonical_source_url`.
- Checksums are required for each exported content file.
- If AI is in progress at export time, `ai.status` should be `in_progress`.
- If AI outputs include fallback pages, `ai.status` should be `fallback_success` or `partial_fallback`.
- Non-AI deterministic exports must remain valid for import.

## YAML and Markdown Packaging

- YAML export:
  - Each page artifact in `content/**/*.yaml`.
  - Shared `manifest.json`.
- Markdown export:
  - Each page artifact in `content/**/*.md` with front matter.
  - Shared `manifest.json`.
- JSON export:
  - Either one-file aggregate or per-page JSON files.
  - Shared `manifest.json` is still required.
