=== ContentCollector ===
Contributors: Gemini
Tags: crawl, scrape, content, migration, collector, sitemap
Requires at least: 5.8
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.10.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Crawl an external website's sitemap, collect content and images from each page, and save them into individual folders.

== Description ==

ContentCollector is a powerful tool for developers, content managers, and SEO specialists. It allows you to enter the XML sitemap of any external website, and the plugin will systematically crawl each URL it finds.

For every page, it extracts:

Meta Title, Description, OpenGraph, and Schema.org data.

All headings (h1-h6).

All text blocks (paragraphs and list items).

All images, which are downloaded locally.

The collected data is saved into a neatly organized folder structure within your WordPress uploads directory. Each page gets its own folder containing a structured JSON file of its text content and all of its downloaded images.

This is ideal for:

Content migration analysis.

Archiving a website.

SEO audits.

Competitive research.

== Installation ==

Upload the content-collector folder to the /wp-content/plugins/ directory.

Activate the plugin through the 'Plugins' menu in WordPress.

Go to the 'Content Collector' menu in your admin sidebar to configure the settings and start crawling.

== Changelog ==

= 1.10.0 =

Added branch-level AI rerun orchestration with batch queueing and progress rollup polling.

Added `POST /ai/rerun-branch` and batch status support via `GET /ai/status?batch_id=...`.

Added Explorer inspector action for “Rerun AI for Branch” with live progress details.

Extended node detail API payload with optional action hints (`node.actions`).

Added Explorer keyboard shortcuts for node actions and reruns (`F`, `I`, `E`, `B`, `R`, `Shift+R`).

= 1.9.0 =

Added a Node Action Center to Explorer with branch-focused controls: focus node, fit branch, isolate/clear isolation, bounded branch expansion, and collapse toggle.

Added quick source/canonical URL action buttons for selected nodes.

Extended explorer preview API with `metrics` and `readiness` blocks for migration triage signals.

Added inspector modules for `Content Signals` and `Migration Readiness` score/checklist output.

= 1.8.0 =

Added Explorer view controls for layout mode, type filter, CPT filter, collapse depth, and branch collapse/expand actions.

Added `Linear Grid` sitemap layout option alongside the existing pyramid tree view.

Added CPT suggestion metadata to explorer node payloads (from AI analysis artifacts) for filtering.

= 1.7.0 =

Added automatic grouped section extraction (`content.sections`) based on heading hierarchy and page flow.

Grouped text blocks, links/buttons (CTA-aware), and images into section-level structures for better migration organization.

Extended Explorer preview payload/UI to surface source links and full section-group accordions with structured section content.

Added section schema documentation for crawl artifacts.

= 1.6.0 =

Implemented `CC_AI_Service` for end-to-end OpenAI execution (classification + sanitization) with deterministic fallback outputs.

Added AI job lifecycle artifacts (`*.analysis.status.json`, `*.analysis.json`, `*.sanitized.json`, `*.sanitized.html`).

Added `GET /ai/status` endpoint for polling by `job_id` or `domain/path`.

Updated Explorer inspector to surface queued/processing/completed/failed AI status with polling after manual rerun.

Added OpenAI model/API key/request-timeout settings and synchronized export manifest AI model metadata.

Enhanced exports to include per-page AI status metadata and include sanitized/analysis payloads when available.

Added Explorer preview mode toggle (raw/sanitized) and AI suggestion summary in the node inspector.

= 1.5.0 =

Added export runtime endpoints and ZIP bundle generation for JSON, YAML, and Markdown outputs.

Added manifest generation with AI fallback status flags and deterministic export metadata.

Added manual "Rerun AI" endpoint and Explorer inspector action button.

Added Explorer export controls for domain/subtree export with selectable format and asset inclusion.

= 1.4.0 =

Added Explorer admin page with Cytoscape.js interactive sitemap visualization.

Added Explorer REST API endpoints for domains, tree data, lazy children loading, node metadata, and content preview.

Added Explorer caching controls (depth, max nodes, cache TTL) in plugin settings.

= 1.3.0 =

Added deterministic domain/path storage for idempotent recrawls.

Added crawl provenance metadata (canonical URL, crawl timestamp, content hash, prompt version).

Added redirect map and crawl index artifacts per domain.

Added structured observability logs and optional plugin-local Dev Mode artifact copies.

Added settings for collision policies, AI fallback mode, and PII redaction toggles.

= 1.2.0 =

Refactored plugin into a class-based, multi-file structure for better maintainability.

Added scraping for meta title, description, OpenGraph, and JSON-LD Schema.

JSON output structure is now more organized.

Added settings for custom User-Agent and request timeout.

Added log download and clear buttons.

= 1.1.0 =

Added settings for custom storage folder, request delay, and content filtering (exclude/focus).

Added an emergency "Stop Crawling" button.

Stored all settings in a single array in the database.

= 1.0.0 =

Initial release.
