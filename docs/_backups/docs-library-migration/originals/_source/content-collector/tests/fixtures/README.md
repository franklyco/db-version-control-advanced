# Fixture Notes

- `crawl/sample-page.html`
  - Representative legacy page input for crawler extraction and PII signal checks.
- `crawl/sample-page.expected.json`
  - Expected shape for provenance, processing policy, grouped section extraction, and compliance metadata.
- `export/manifest.expected.json`
  - Expected manifest contract for deterministic structured export bundles.
- `explorer/content-preview.expected.json`
  - Baseline explorer preview contract including `metrics`, `readiness`, and `comparison` blocks.
- `explorer/node-audit.expected.json`
  - Baseline node audit trail payload for selected path event history.
- `ai/batch-status.expected.json`
  - Baseline branch AI rerun batch rollup payload for status polling.
