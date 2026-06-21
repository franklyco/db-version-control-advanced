# Explorer API Schema (Cytoscape.js)

Note:
- This document describes the current source plugin contract.
- For DBVC addon implementation order and guardrails, use:
  - `docs/CONTENT_COLLECTOR_ADDON_PLAYBOOK/CONTENT_COLLECTOR_ADDON_MANIFEST.json`
  - `docs/CONTENT_COLLECTOR_ADDON_PLAYBOOK/PHASE_PLAN.md`
  - `docs/CONTENT_COLLECTOR_ADDON_PLAYBOOK/GUARDRAILS.md`

Base namespace:

`/wp-json/content-collector/v1`

Auth/permissions:

- WP authenticated admin user with `manage_options`
- `X-WP-Nonce` header required for browser requests

## 0) Get Domains

`GET /explorer/domains`

Response:

```json
{
  "domains": [
    {
      "key": "example.com",
      "label": "example.com"
    }
  ]
}
```

## 1) Get Tree

`GET /explorer/tree`

Query params:

- `domain` (required)
- `depth` (optional, default from settings, min `1`, max `5`)
- `max_nodes` (optional, default from settings, min `100`, max `2000`)
- `include_files` (optional, default `false`)

Response shape:

```json
{
  "domain": "example.com",
  "generated_at": "2026-02-26T17:00:00Z",
  "scan_mode": "fresh",
  "totals": {
    "directories": 82,
    "pages": 64,
    "json_files": 64,
    "media_files": 510,
    "max_depth": 6
  },
  "cytoscape": {
    "nodes": [],
    "edges": []
  },
  "cache": {
    "hit": false,
    "key": "cc_x_tree_xxx",
    "ttl_remaining": 300
  },
  "warnings": []
}
```

## 2) Get Node Children (Lazy Expand)

`GET /explorer/node/children`

Query params:

- `domain` (required)
- `path` (optional; empty means root)
- `include_files` (optional)

Response shape:

```json
{
  "domain": "example.com",
  "path": "services",
  "generated_at": "2026-02-26T17:01:00Z",
  "children": {
    "nodes": [],
    "edges": []
  }
}
```

## 3) Get Node Detail

`GET /explorer/node`

Query params:

- `domain` (required)
- `path` (optional; empty returns domain node)

Response shape:

```json
{
  "domain": "example.com",
  "path": "services/web-design",
  "node": {
    "id": "page:services/web-design",
    "label": "web-design",
    "type": "page",
    "depth": 2,
    "cpt": "page",
    "json_file": "web-design.json",
    "json_exists": true,
    "image_count": 12,
    "artifact": {
      "source_url": "https://example.com/services/web-design/",
      "canonical_source_url": "https://example.com/services/web-design",
      "crawl_timestamp": "2026-02-26T15:40:00Z",
      "content_hash": "..."
    },
    "status": {
      "crawl": "success",
      "analysis": "queued|processing|done|fallback_done|failed|not_started",
      "sanitization": "queued|processing|done|fallback_done|failed|not_started",
      "export": "not_started",
      "ai_mode": "pending|ai|fallback|failed",
      "job_id": "cc_ai_abc123",
      "message": "AI job queued.",
      "updated_at": "2026-02-26T17:03:00Z"
    },
    "actions": {
      "can_rerun_ai": true,
      "can_rerun_ai_branch": true,
      "can_expand_branch": false,
      "can_open_source_url": true,
      "can_open_canonical_url": true
    }
  }
}
```

## 4) Get Node Content Preview

`GET /explorer/content`

Query params:

- `domain` (required)
- `path` (required)
- `mode` (`raw|sanitized`, optional)

Response shape:

```json
{
  "domain": "example.com",
  "path": "services/web-design",
  "mode": "raw",
  "content": {
    "title": "Web Design",
    "source_url": "https://example.com/services/web-design/",
    "canonical_source_url": "https://example.com/services/web-design",
    "headings": [],
    "text_excerpt": [],
    "images": [],
    "section_count": 4,
    "sections": [
      {
        "id": "section-1",
        "order": 1,
        "parent_id": null,
        "level": 1,
        "heading_tag": "h1",
        "heading": "Web Design Services",
        "is_intro": false,
        "text_blocks": [
          "This is the hero description."
        ],
        "links": [
          {
            "type": "link",
            "text": "Learn more",
            "url": "https://example.com/services/web-design/",
            "is_cta": true
          }
        ],
        "ctas": [
          {
            "type": "link",
            "text": "Learn more",
            "url": "https://example.com/services/web-design/"
          }
        ],
        "images": [
          {
            "source_url": "https://example.com/wp-content/uploads/header.jpg",
            "local_filename": "header.jpg",
            "alt": "Header"
          }
        ]
      }
    ]
  },
  "analysis": {
    "status": "not_started|done|fallback_done",
    "post_type": "page",
    "post_type_confidence": 0.72,
    "categories": ["services"],
    "needs_review": false,
    "summary": "Short AI summary.",
    "reasoning": "Matched headings and body intent."
  },
  "metrics": {
    "headings_count": 6,
    "h1_count": 1,
    "primary_h1": "Web Design Services",
    "text_blocks_count": 22,
    "word_count": 812,
    "section_count": 4,
    "links_count": 9,
    "ctas_count": 3,
    "images_count": 5,
    "section_image_count": 4
  },
  "readiness": {
    "status": "ready|review|needs_work",
    "score": 3,
    "max": 4,
    "checks": [
      {
        "key": "structure",
        "label": "Heading hierarchy and text captured",
        "passed": true
      }
    ],
    "notes": [
      "AI analysis is not complete. Run AI before final export if mapping suggestions are needed."
    ]
  },
  "comparison": {
    "raw_excerpt": [
      "<h1>Web Design Services</h1>",
      "<p><span class=\"legacy\">Build a conversion-focused website for your business.</span></p>"
    ],
    "sanitized_excerpt": [
      "<h1>Web Design Services</h1>",
      "<p>Build a conversion-focused website for your business.</p>"
    ],
    "changed_lines": 1,
    "total_lines": 2
  },
  "pii_flags": {
    "emails_count": 1,
    "phones_count": 2,
    "forms_count": 1,
    "requires_legal_review": true
  }
}
```

## 5) Get Node Audit Trail

`GET /explorer/node/audit`

Query params:

- `domain` (required)
- `path` (optional; empty returns domain-level events)
- `limit` (optional, default `25`, min `5`, max `100`)

Response shape:

```json
{
  "domain": "example.com",
  "path": "services/web-design",
  "generated_at": "2026-03-01T12:30:00+00:00",
  "limit": 25,
  "summary": {
    "total": 4,
    "stage_counts": {
      "analysis": 2,
      "crawl": 1,
      "export": 1
    },
    "status_counts": {
      "failed": 1,
      "success": 3
    }
  },
  "events": [
    {
      "timestamp": "2026-03-01T12:29:30+00:00",
      "stage": "export",
      "status": "success",
      "path": "services/web-design",
      "page_url": "https://example.com/services/web-design/",
      "job_id": "cc_export_123abc",
      "message": "Exported page payload.",
      "failure_code": ""
    }
  ]
}
```

## 6) Create Export

`POST /export`

Body:

```json
{
  "domain": "example.com",
  "scope": {
    "mode": "subtree",
    "path": "services"
  },
  "format": "yaml",
  "include_assets": true,
  "use_ai": true,
  "redaction": {
    "emails": true,
    "phones": true,
    "forms": true
  }
}
```

Response (sync complete):

```json
{
  "job_id": "cc_export_xxxxxxxx",
  "status": "completed",
  "download_url": "https://.../contentcollector-exports/cc_export_xxxxxxxx.zip",
  "totals": {
    "pages": 64,
    "assets": 510,
    "warnings": 0,
    "errors": 0
  },
  "ai": {
    "status": "partial_fallback"
  }
}
```

## 7) Get Export Status

`GET /export/{job_id}`

Response: stored job payload (status, timestamps, manifest summary, download URL when ready).

## 8) Get Export Download Metadata

`GET /export/{job_id}/download`

Response shape:

```json
{
  "job_id": "cc_export_xxxxxxxx",
  "status": "completed",
  "download_url": "https://.../cc_export_xxxxxxxx.zip",
  "filename": "cc_export_xxxxxxxx.zip",
  "size_bytes": 123456
}
```

## 9) Queue Manual AI Rerun

`POST /ai/rerun`

Body:

```json
{
  "domain": "example.com",
  "path": "services/web-design",
  "run_now": false
}
```

Response shape:

```json
{
  "schema_version": "1.0",
  "job_id": "cc_ai_abc123def456",
  "status": "queued",
  "mode": "pending",
  "domain": "example.com",
  "path": "services/web-design",
  "analysis_file": "web-design.analysis.json",
  "sanitized_file": "web-design.sanitized.json",
  "message": "AI job queued."
}
```

## 10) Get AI Job Status

`GET /ai/status`

Query params:

- `batch_id` (optional; batch rollup polling for branch reruns)
- `job_id` (optional, preferred when available)
- `domain` + `path` (optional alternative when no `job_id`)

Response shape:

```json
{
  "schema_version": "1.0",
  "job_id": "cc_ai_abc123def456",
  "status": "queued|processing|completed|failed|not_started",
  "mode": "pending|ai|fallback|failed",
  "domain": "example.com",
  "path": "services/web-design",
  "source_url": "https://example.com/services/web-design/",
  "analysis_file": "web-design.analysis.json",
  "sanitized_file": "web-design.sanitized.json",
  "sanitized_html_file": "web-design.sanitized.html",
  "message": "AI processing in progress.",
  "error": null
}
```

## 11) Queue Branch AI Rerun

`POST /ai/rerun-branch`

Body:

```json
{
  "domain": "example.com",
  "path": "services",
  "run_now": false,
  "max_jobs": 150
}
```

Response shape:

```json
{
  "schema_version": "1.0",
  "batch_id": "cc_aib_123abc456def",
  "status": "queued",
  "trigger": "manual_branch_rerun",
  "requested_at": "2026-02-26T17:10:00Z",
  "domain": "example.com",
  "path": "services",
  "total_jobs": 18,
  "queued_jobs": 18,
  "failed_jobs": 0,
  "run_now": false,
  "max_jobs": 150,
  "jobs": [
    {
      "job_id": "cc_ai_abc123def456",
      "domain": "example.com",
      "path": "services/web-design",
      "status": "queued",
      "message": "AI job queued."
    }
  ],
  "errors": [],
  "message": "Queued 18 AI jobs for branch rerun. Queue errors: 0."
}
```

## 12) Get AI Batch Status (Branch Rollup)

`GET /ai/status?batch_id=cc_aib_123abc456def`

Response shape:

```json
{
  "schema_version": "1.0",
  "batch_id": "cc_aib_123abc456def",
  "status": "queued|processing|completed|completed_with_failures",
  "domain": "example.com",
  "path": "services",
  "requested_at": "2026-02-26T17:10:00Z",
  "total_jobs": 18,
  "processed_jobs": 9,
  "progress_percent": 50,
  "counts": {
    "not_started": 0,
    "queued": 3,
    "processing": 6,
    "completed_ai": 7,
    "completed_fallback": 2,
    "failed": 0,
    "unknown": 0
  },
  "jobs": [],
  "message": "Processed 9 of 18 AI jobs."
}
```

## Standard Error Payload

```json
{
  "code": "cc_invalid_request",
  "message": "Invalid domain parameter.",
  "data": {
    "status": 400
  }
}
```
