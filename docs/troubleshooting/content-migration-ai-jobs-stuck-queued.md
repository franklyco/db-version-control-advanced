# Content Migration: AI jobs stuck at `queued`

## Symptom

A crawled domain reports AI status `processing` indefinitely. Nothing advances, no error appears,
and the per-page status files under the add-on's storage directory sit at:

```json
{ "status": "queued", "mode": "pending", "message": "AI job queued." }
```

In the Explorer or Workbench domain list the domain's `dbvc_cc_ai_health` shows a non-zero
`counts.queued` that never falls.

## Cause

A build before the `dbvc_cc_flag_ai_mapping` boundary fix let the collector queue AI jobs while
that flag was off. The crawl's post-run AI refresh wrote a `queued` status file per page and
scheduled `dbvc_cc_ai_process_job` for each, but the AI module returns early when the flag is off,
so no listener was ever attached. Each event fired, found nothing, and was consumed. The status
files were left behind.

The health rollup reports `processing` whenever `counts.queued` or `counts.processing` is non-zero,
so residue from that defect is indistinguishable from work genuinely in flight.

Current builds cannot reach this state. With the flag off, queueing refuses up front with
`dbvc_cc_ai_disabled` and writes no status file; turning the flag off also unschedules any pending
events. The symptom only appears on a site that ran an affected build. If you see it on a current
build, that is a new defect — report it rather than applying the remedy below.

## Remedy

No repair tool is needed: queueing a fresh job overwrites the stale status file.

1. Confirm the flag is on. With it off the refresh refuses, and the message names the flag:

   ```bash
   wp option get dbvc_cc_flag_ai_mapping
   ```

   If it returns `0`, set it to `1` and re-check — the surface is off by choice, and the stranded
   files are simply the previous build's residue.

2. Re-queue the domain. Either finish a crawl from the Content Collector screen, which triggers the
   domain AI refresh, or call the REST route directly per branch:

   ```bash
   wp eval 'var_dump( DBVC_CC_AI_Service::get_instance()->dbvc_cc_queue_domain_refresh( "example.com" ) );'
   ```

   Every discovered page gets a new job id, a freshly written status file and a new scheduled
   event, so the stale `queued` rows are replaced rather than accumulated.

3. Let cron run, then re-read the rollup. `counts.queued` should fall to zero and the domain should
   report `healthy`, `stale` or `incomplete` — a real state rather than residue.

## If the rows do not clear

The refresh cannot miss a page the rollup counts: both walk the same discovery
(`discover_page_paths_in_subtree` over the domain directory), and a domain refresh passes an empty
path and no job cap, so the set it re-queues is exactly the set the rollup counted.

So a `queued` row that survives step 2 means that page's job failed to queue. The refresh reports
that rather than hiding it — `failed_jobs` is non-zero and `errors[]` carries a `path`, `code` and
`message` per page, and the crawl log shows the same. The usual code is
`dbvc_cc_ai_queue_write`, which means the status file could not be written: check ownership and
permissions on the add-on's storage directory under `wp-content/uploads`.

## See also

- `docs/implementation/proposed/content-collection-v3/README.md` — D-26 records this residue, and
  D-24/D-25 the neighbouring flag-boundary items.
