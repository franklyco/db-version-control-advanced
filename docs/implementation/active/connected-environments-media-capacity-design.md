# Connected Environments — media capacity design (post-M9 Phase A, item 2)

**Status: COMPLETE (2026-10-19). A2-1 (storage offload) PR #171; A2-2a (hub routes) PR #173; A2-2b (source upload) PR #174; A2-2c (target pull on apply) PR #175 — master `3fb492ae`.** Decisions locked (§5). Large media (over the 2 MiB inline cap, up to the 25 MiB pull ceiling) now transfers source → hub → target over the dedicated streamed channel, with bytes held out of the `release_items` column; `A2-3` (filesystem/object-store driver, chunked upload) remains an optional scale-out. **LIVE-VERIFIED net-zero on the real fleet 2026-10-19:** a 3.3 MiB image streamed connectedsite-a → hub over `.local` HTTPS via the real `PUT …/media/{sha256}` (stored in `media_blobs`, re-hash match), then pulled hub → butler via the real `GET` and sideloaded byte-identically (re-hash match); teardown restored the hub to baseline (environments 2, projections 2979, events 2985, all else 0, `media_blobs` 0 — GC reclaimed the orphan). The live-verify **found a real defect** (PR #177): the A2-2c puller used `HubClient::get_json`, whose `MAX_BATCH_BYTES×4` = 1 MiB response cap truncated every fetch file (a blob is base64-wrapped ×4/3); fixed with `HubClient::get_media_blob` sized to the pull ceiling. This class of bug (a `wp_remote_request` response-size limit) is only reachable over real HTTP, which is exactly why the live-verify was run.

**Companion:** build record is the M9 guide `connected-environments-agency-control.md`; roadmap row is `docs/ROADMAP.md` → "Connected Environments — post-M9 productionization". Item 1 (reliable scheduler) shipped in PR #168.

---

## 1. Why / goals

Two capacity limits exist in the M7 media transport. Neither is a correctness bug — both degrade gracefully today — but both cap how much real-site media a fleet push can carry.

1. **Oversize media is never transported.** Any referenced attachment larger than the per-file cap (or that would exceed the per-item total, or the per-item count) is listed as `deferred` at the source and its bytes are dropped; at apply an unresolved media token **fails closed** (`media_unresolved`), so the object is refused rather than applied with a dangling reference. There is no channel to carry or later fetch those bytes.
2. **Carried bytes live base64 in a DB column.** Every transported file is stored base64 in `dbvc_ac_release_items.media` (LONGTEXT) on the hub and travels inline in the item payload JSON. At fleet scale (many releases × many images) this bloats the hottest agency table and every payload round-trip.

**Goal:** let a fleet push carry larger media reliably, and stop the release-items table from carrying megabytes of base64 — **without changing the guarded apply / journal / rollback semantics or any release digest.**

## 2. Current state (facts, with refs)

- **Caps** — `includes/Dbvc/ConnectedProtocol/Protocol.php`: `MAX_MEDIA_FILE_BYTES = 2097152` (2 MiB/file), `MAX_MEDIA_TOTAL_BYTES = 4194304` (4 MiB/item), `MAX_MEDIA_ITEMS = 25`.
- **Source collection** — `MediaReferences::collect_for_transport()` (and `collect_for_hashes()`) gather a descriptor + base64 `bytes` per referenced attachment, under the three caps. Over a cap → `deferred` (descriptor + reason: `too_large` / `total_exceeded` / `too_many` / `source_missing` / `unreadable`); bytes not carried, never silently dropped.
- **Transport** — `ReleaseWorker` puts `media` (and `media_deferred`) on the payload the **source** connector POSTs to the hub route `POST /releases/{release_uid}/payloads`.
- **Hub storage** — `ReleaseService` validates each entry (sha256 re-hash, cap re-check) and stores `{media:[{…,bytes:base64}], deferred:[…]}` in the `media longtext` column of `dbvc_ac_release_items` (the `payload longtext` column alongside holds the object body).
- **Target apply** — the sealed release is delivered to the target in `$manifest['media']`; `Applier` calls `MediaMaterializer::materialize($carried_media)` (post path ~`:324`, collection/option path ~`:794`). `MediaMaterializer` base64-decodes, rejects `too_large` / `invalid_bytes`, **re-hashes against the declared sha256**, sideloads via `wp_upload_bits`, stamps `_dbvc_media_hash`; `discard()` removes sideloaded attachments on every non-persisting return and on reviewed rollback (net-zero).
- **Identity / digest safety** — media is content-addressed: the body carries `dbvc-bmedia:<sha256>` (and `dbvc-media:<sha256>`) tokens, so the **projection/body hash already encodes each file by its content hash**. The sealed release digest is over the manifest items (domain/instance/profile/`after_hash`/operation), **not** over the media bytes. **⇒ Moving bytes out of the storage column changes no projection hash and no release digest.** This is the property that makes the offload safe.
- **Topology** — connectors never talk peer-to-peer; both the source and the target authenticate to the **hub** (application-password principals on the connector machine-REST; the hub brokers). Large-media transport must preserve this: the hub is the broker.
- **Retention** — `RolloutService::prune` / `RolloutStore::prune` + `release-withdraw` clean up finished releases; blob GC must hook here.

## 3. Design overview

Introduce a **content-addressed blob store on the hub**, keyed by sha256, that holds media bytes out of the `release_items` row, plus a **streamed upload (source → hub)** and **streamed pull (hub → target)** so large files travel without ever being base64-inlined. Small media keeps inlining exactly as today (the 6e-verified path is untouched), so the change is additive and opt-in by size.

```
SOURCE                         HUB                                  TARGET
  observe/collect               dbvc_ac_media_blobs (sha256 → bytes)   apply
  │  small (≤ inline cap):      │  (+ refcount, mime, size)             │
  │    inline base64 ───────────┤ stored in release_items.media         │
  │  large (> inline cap):      │                                       │
  │    streamed octet-stream ──▶│ stored as a blob row/driver           │
  │    PUT …/media/{sha256}     │                                       │
  │                             │◀── GET …/media/{sha256} (streamed) ───┤ pull-by-hash
  │                             │     re-hash, then MediaMaterializer    │  only when the
                                                                         │  descriptor has no
                                                                         │  inline bytes
```

- **Small media (≤ inline threshold):** unchanged — inline base64 in the item payload, stored in `release_items.media`, materialized from the carried bytes. No behavior change.
- **Large media (> inline threshold, ≤ pull ceiling):** source streams the raw bytes to the hub blob store once (deduped by hash); the item descriptor carries the hash + a `fetch` flag but **no** `bytes`; the target, when it hits a descriptor with no inline bytes, streams the blob from the hub by hash, re-hashes, then materializes through the **unchanged** `MediaMaterializer` path.
- **Fail-closed preserved:** if the pull fails or the re-hash mismatches, the descriptor stays unresolved → `media_unresolved` (exactly today's refusal). No partial/corrupt apply is possible — every byte is verified against its sha256 before sideload.

## 4. Component design

### 4.1 Hub blob store (the offload)
A small **storage-driver interface** (`MediaBlobStore`) with `put(hash, stream|bytes, mime, size): void`, `has(hash): bool`, `open(hash): stream`, `addref(hash)/release(hash): void`, `gc(): int`. Two drivers:
- **`DbMediaBlobStore` (default, recommended first):** a new `dbvc_ac_media_blobs` table — `hash varbinary(71) PK` (`sha256:…`), `mime`, `filesize`, `refcount int`, `created_at`, `bytes LONGBLOB`. Raw bytes (not base64 → −33% + no decode). Keeps the project's "state lives in `dbvc_ac_*`, transactional, backed up with the DB, clean net-zero" properties; bytes leave the hot `release_items` row but stay in MySQL.
- **`FsMediaBlobStore` (opt-in scale-out):** content-addressed files under `wp-content/uploads/dbvc-agency-media/<ab>/<sha256>`, direct web access denied, served only via the authenticated REST stream. Takes bytes fully out of MySQL; **must be included in the hub's backups**; is the path to object storage (S3/R2) later via the same interface.

Driver chosen by a filter/constant (`dbvc_agency_media_blob_store`), default `db`. `release_items.media` keeps the descriptor array; each large entry stores `{hash, mime, filesize, filename, refs, fetch:true}` and no `bytes`.

### 4.2 Source → hub upload (streamed)
New hub route `PUT /releases/{release_uid}/media/{sha256}` (or a `multipart`/octet-stream POST): body is the raw file, size-bounded by `MAX_PULL_FILE_BYTES`, the hub computes sha256 while streaming to the driver and **rejects on mismatch** or over-size (clean rejection, never stored). Idempotent: a hash already present just `addref`s. Auth: the existing source-principal of that release; the hash must belong to the release's item set (a token in some item body), else 403. `ReleaseWorker` on the source uploads large files here instead of inlining them; small files inline as today.

### 4.3 Hub → target pull (streamed)
New hub route `GET /releases/{release_uid}/media/{sha256}` streams the blob. Auth: the **target** principal of that release (the environment the release is being prepared/applied to); the hash must belong to the release; otherwise 403/404. No public URLs. `MediaMaterializer` gains an optional resolver callback: for a descriptor with `fetch:true` and no `bytes`, it pulls the blob by hash, re-hashes (existing check), then runs the **unchanged** sideload. Everything else in materialize/discard/journal/rollback is identical.

### 4.4 Caps
- Keep `MAX_MEDIA_FILE_BYTES` (2 MiB) as the **inline** threshold — small media still travels in one round-trip.
- Add `MAX_PULL_FILE_BYTES` (proposed `26214400` = 25 MiB, matching the Bricks add-on's media cap) as the ceiling for the pull channel; `MAX_MEDIA_TOTAL_BYTES` stops applying to pull-channel files (they aren't inlined, so per-item JSON stays small) but a new per-release aggregate cap can bound total fleet transfer.
- Caps become filterable but **must stay consistent fleet-wide**: the source-upload bound and the target-accept bound must match, or the target's re-check rejects (a clean refusal, never corruption). Documented as an operator invariant.

### 4.5 Security
Content-addressed (sha256) end to end → a tampered or wrong blob fails the re-hash and is rejected (poisoning-proof). Authenticated REST only; the blob store denies direct web access; upload is size-bounded and hash-verified before it is ever persisted; the hub authorizes that the requesting principal owns the release and that the hash is actually referenced by that release. No SSRF surface (the hub serves only its own stored bytes; it never fetches a URL). Masked/privacy fields are unaffected (media bytes are not masked content).

### 4.6 Back-compat & migration
- Small media and **every existing release** are unaffected (no `bytes`-absent descriptors appear unless a source opts into the large path). The 6e-verified apply path runs unchanged for inline media.
- Additive schema only (`dbvc_ac_media_blobs`); `release_items.media` descriptors gain optional `fetch`/drop optional `bytes`. No destructive migration; no digest change (see §2).
- Roll-forward only: a target connector that predates the pull channel simply can't resolve a `fetch:true` descriptor → `media_unresolved` (its existing fail-closed), so a mixed-version fleet degrades safely rather than breaking.

### 4.7 Retention / GC
Blobs are **ref-counted by hash** (shared across releases/items). `ReleaseService::create` / payload-store `addref`s; `RolloutService::prune`, `release-withdraw`, and release deletion `release()`; a blob at refcount 0 is GC'd (immediately on release, or swept by the existing hourly tick — now reliably driven by `scheduler-run`). A `wp dbvc agency media-gc` CLI (dry-run + execute) for manual sweeps and audit.

## 5. Decisions (locked 2026-10-19)

1. **Blob store driver default — `db` (LONGBLOB table).** Ship the `MediaBlobStore` driver interface + a `DbMediaBlobStore` default (keeps the transactional / backup / net-zero story; bytes leave the hot `release_items` row). `FsMediaBlobStore` (fully out of MySQL) is an opt-in scale-out behind the same interface, built only if DB size becomes the bottleneck (A2-3).
2. **Pull ceiling `MAX_PULL_FILE_BYTES` = 25 MiB** (`26214400`, matching the Bricks add-on). Filterable; must stay consistent fleet-wide (source-upload bound == target-accept bound). The 2 MiB inline threshold (`MAX_MEDIA_FILE_BYTES`) is unchanged.
3. **Chunked upload — deferred.** A single streamed octet-stream body up to the 25 MiB ceiling; chunking is a future extension only if a real file exceeds it.
4. **Pull-channel scope — all media domains.** The channel is domain-agnostic (`wp.service` / `wp.post:*` / Bricks collection + template media alike); no per-domain special-casing.

## 6. Slicing plan (each its own small PR, built only after approval)

- **A2-1 — hub blob store + offload (no capacity change yet): SHIPPED 2026-10-19 (PR #171, master `b16d4981`).** `MediaBlobStoreDriver` interface + `DbMediaBlobStore` (LONGBLOB, raw bytes) behind the `MediaBlobStore` facade + `dbvc_agency_media_blob_store` filter; `dbvc_ac_media_blobs` schema (v19→v20, additive); **mark-and-sweep** `MediaBlobStore::gc()` (simpler and self-correcting vs refcount — never removes a live blob) run best-effort on the framework rollout tick. `ReleaseService` offloads bytes on payload-receive (descriptor-only in the column, inline fallback on store failure); `PreparationService` + `ApprovalService` hydrate bytes from the store so `MediaMaterializer` is unchanged. Digests unchanged; the 37-test existing media suites pass unchanged (offload transparent) + `ConnectedMediaBlobStoreTest` (4); full group green (216).
- **A2-2 — streamed upload + pull channel + raised cap: SHIPPED 2026-10-19**, in three sub-slices: **A2-2a (PR #173)** the hub `PUT`/`GET releases/{uid}/media/{sha256}` routes with release-scoped authz + `MAX_PULL_FILE_BYTES` (25 MiB) + the hydrate-split (a `fetch` descriptor is never inlined); **A2-2b (PR #174)** `MediaReferences::collect_for_hashes` emits a `fetch` descriptor for a file over the inline cap, `ReleaseWorker` streams the bytes via `HubClient::put_bytes`, and the hub intake accepts fetch descriptors (`accept_media_blob` allows open-or-sealed); **A2-2c (PR #175)** `MediaMaterializer` pulls a `fetch` descriptor by hash (re-hash + sideload, fail-closed), the `Applier` builds the per-apply puller. Full group green (232). Fleet-consistency note: the source upload bound and target accept bound are both `MAX_PULL_FILE_BYTES` and must match fleet-wide; a mismatch is a clean hash-verified rejection.
- **A2-3 (optional, deferred) — `FsMediaBlobStore`** and/or chunked upload, only if scale demands.

## 7. Test plan
- Blob store: put/has/open/dedupe/refcount/GC; LONGBLOB round-trip; raw-vs-base64 size.
- Offload: a release that today inlines media still applies byte-identically with bytes served from the store; digest unchanged; net-zero rollback still discards sideloaded attachments.
- Pull channel: a > inline-cap file uploads, stores once, pulls on apply, re-hashes, materializes; a tampered pull → `media_unresolved` (fail-closed); an over-ceiling upload → clean rejection; a predate-the-channel target → `media_unresolved`.
- Auth: wrong principal / unreferenced hash → 403/404; no public access.
- Live-verify (scratch clones only, never real client content): one large image pushed source → hub → target end-to-end, net-zero teardown — mirroring the 6e recipe.

## 8. Risks
- Touches the most sensitive, recently live-verified code (apply/materialize) and hub storage → mitigated by (a) splitting storage-offload (A2-1) from the apply-path pull (A2-2), (b) keeping the small-media path byte-for-byte unchanged, (c) no digest change, (d) fail-closed preserved, (e) scratch-clone live-verify before it ever runs near real content.
- DB growth under the `db` driver at very large scale → the driver interface makes `fs`/object-storage a later swap with no contract change.
- Operator must keep caps consistent fleet-wide and (for `fs`) back up the blob directory → documented invariants.
