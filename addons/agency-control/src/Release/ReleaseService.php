<?php

namespace Dbvc\AgencyControl\Release;

use Dbvc\AgencyControl\Comparison\ComparisonService;
use Dbvc\AgencyControl\Framework\FrameworkStatusService;
use Dbvc\AgencyControl\Storage\EnvironmentRegistry;
use Dbvc\AgencyControl\Storage\MediaBlobStore;
use Dbvc\AgencyControl\Storage\PreparationStore;
use Dbvc\AgencyControl\Storage\ProjectionStore;
use Dbvc\AgencyControl\Storage\ReleaseStore;
use Dbvc\ConnectedProtocol\Canonicalizer;
use Dbvc\ConnectedProtocol\ObservationEvent;
use Dbvc\ConnectedProtocol\Protocol;

/**
 * Release manifests: a studio selects individual objects from one source
 * environment's current, complete, fresh projections; the manifest fixes
 * their expected after-hashes. The source connector then supplies each
 * object's canonical body (an explicitly authorized upload, verified
 * against the manifest hash), and the release seals with a digest once
 * every payload is present. A release never includes a whole domain option
 * as the patch for one object, and nothing here writes to any environment.
 */
final class ReleaseService
{
    /**
     * @var ReleaseStore
     */
    private $releases;

    /**
     * @var EnvironmentRegistry
     */
    private $environments;

    /**
     * @var ProjectionStore
     */
    private $projections;

    public function __construct()
    {
        $this->releases = new ReleaseStore();
        $this->environments = new EnvironmentRegistry();
        $this->projections = new ProjectionStore();
    }

    /**
     * @param string                          $source_environment_id
     * @param array<int, array<string, string>> $selections Each `domain` + `instance_uid`, optional `operation` (`replace` default, or
     *                                                     `delete` to release a verified absence: the source must report the object gone).
     * @param string                          $note
     * @return array<string, mixed>|\WP_Error
     */
    public function create($source_environment_id, array $selections, $note = '')
    {
        $source = $this->environments->find((string) $source_environment_id);
        if ($source === null) {
            return new \WP_Error('dbvc_agency_environment_not_found', 'Source environment not found.', ['status' => 404]);
        }
        if ($source['status'] !== EnvironmentRegistry::STATUS_ENABLED) {
            return new \WP_Error('dbvc_agency_environment_not_enabled', 'The source environment is not enabled.', ['status' => 409]);
        }
        if (! FrameworkStatusService::is_fresh($source)) {
            return new \WP_Error('dbvc_agency_environment_stale', 'The source environment\'s last contact is older than the freshness window; its projections cannot anchor a release.', ['status' => 409]);
        }
        if ($selections === [] || count($selections) > Protocol::MAX_RELEASE_ITEMS) {
            return new \WP_Error('dbvc_agency_invalid_selection', sprintf('Select 1 to %d objects.', Protocol::MAX_RELEASE_ITEMS), ['status' => 400]);
        }

        $items = [];
        $seen = [];
        foreach ($selections as $selection) {
            $domain = (string) ($selection['domain'] ?? '');
            $instance_uid = (string) ($selection['instance_uid'] ?? '');
            if (! ObservationEvent::isDomain($domain)) {
                return new \WP_Error('dbvc_agency_invalid_domain', 'Unknown domain: ' . $domain, ['status' => 400]);
            }
            if (! ObservationEvent::isIdentifier($instance_uid) || $instance_uid === ComparisonService::ORDER_INSTANCE_UID) {
                return new \WP_Error('dbvc_agency_invalid_identifier', 'instance_uid must name one object (collection order is not releasable).', ['status' => 400]);
            }
            if (isset($seen[$domain . '|' . $instance_uid])) {
                continue;
            }
            $seen[$domain . '|' . $instance_uid] = true;
            $operation = (string) ($selection['operation'] ?? ReleaseStore::OPERATION_REPLACE);
            if (! in_array($operation, [ReleaseStore::OPERATION_REPLACE, ReleaseStore::OPERATION_DELETE, ReleaseStore::OPERATION_CREATE], true)) {
                return new \WP_Error('dbvc_agency_invalid_operation', 'operation must be replace, delete or create.', ['status' => 400]);
            }
            // A create pushes an object the source has (the framework object) to a target that lacks it,
            // so its source projection must exist exactly like a replace; only the target side is absent.
            $projection = $this->current_object_projection($source, $domain, $instance_uid, $operation === ReleaseStore::OPERATION_DELETE);
            if (is_wp_error($projection)) {
                return $projection;
            }
            $items[] = [
                'domain' => $domain,
                'instance_uid' => $instance_uid,
                'profile' => (string) $projection['profile'],
                'operation' => $operation,
                'after_hash' => (string) $projection['semantic_hash'],
                'source_sequence' => (int) $projection['observed_sequence'],
            ];
        }

        $release = $this->releases->create([
            'release_uid' => 'rel-' . bin2hex(random_bytes(8)),
            'agency_id' => $source['agency_id'],
            'client_id' => $source['client_id'],
            'source_environment_id' => $source['environment_id'],
            'source_epoch' => $source['current_epoch'],
            'note' => (string) $note,
            'created_by' => get_current_user_id(),
        ], $items);
        if ($release === null) {
            return new \WP_Error('dbvc_agency_release_error', 'The release could not be recorded.', ['status' => 500]);
        }

        return ['release' => $release, 'items' => $this->releases->items($release['release_id'])];
    }

    /**
     * Payloads supplied by the source environment's connector. Each body is
     * verified against the manifest hash before it is stored; a mismatch is
     * recorded (the object changed since the manifest was fixed) and the
     * release can no longer seal.
     *
     * @param array<string, mixed>              $source Trusted environment row of the caller.
     * @param string                            $release_uid
     * @param array<int, array<string, mixed>>  $payloads domain, instance_uid, profile, body (canonical string) | status=mismatch + current_hash
     * @return array<string, mixed>|\WP_Error
     */
    public function accept_payloads(array $source, $release_uid, array $payloads)
    {
        $release = $this->releases->find((string) $release_uid);
        if ($release === null || $release['source_environment_id'] !== $source['environment_id']) {
            return new \WP_Error('dbvc_agency_release_not_found', 'Release not found for this environment.', ['status' => 404]);
        }
        if ($release['state'] !== ReleaseStore::STATE_OPEN) {
            return new \WP_Error('dbvc_agency_release_not_open', 'The release no longer accepts payloads.', ['status' => 409]);
        }
        if ($release['source_epoch'] !== $source['current_epoch']) {
            return new \WP_Error('dbvc_agency_release_epoch_mismatch', 'The release was anchored to an earlier enrollment epoch.', ['status' => 409]);
        }
        if ($payloads === [] || count($payloads) > Protocol::MAX_PAYLOAD_ITEMS) {
            return new \WP_Error('dbvc_agency_invalid_body', sprintf('items must list 1 to %d payloads.', Protocol::MAX_PAYLOAD_ITEMS), ['status' => 400]);
        }

        $items = [];
        foreach ($this->releases->items($release['release_id']) as $item) {
            $items[$item['domain'] . '|' . $item['instance_uid'] . '|' . $item['profile']] = $item;
        }
        $outcomes = [];
        foreach ($payloads as $payload) {
            $key = (string) ($payload['domain'] ?? '') . '|' . (string) ($payload['instance_uid'] ?? '') . '|' . (string) ($payload['profile'] ?? '');
            $outcome = ['domain' => (string) ($payload['domain'] ?? ''), 'instance_uid' => (string) ($payload['instance_uid'] ?? ''), 'profile' => (string) ($payload['profile'] ?? '')];
            if (! isset($items[$key])) {
                $outcome['outcome'] = 'unknown_item';
                $outcomes[] = $outcome;
                continue;
            }
            $item = $items[$key];
            if ($item['payload_state'] !== ReleaseStore::PAYLOAD_REQUESTED) {
                $outcome['outcome'] = 'already_' . $item['payload_state'];
                $outcomes[] = $outcome;
                continue;
            }
            if (isset($payload['status']) && $payload['status'] === 'mismatch') {
                $this->releases->record_payload($item['release_item_id'], null, 'source_changed:' . substr((string) ($payload['current_hash'] ?? ''), 0, 12));
                $outcome['outcome'] = 'mismatch_recorded';
                $outcomes[] = $outcome;
                continue;
            }
            $body = $payload['body'] ?? null;
            if (! is_string($body) || $body === '' || strlen($body) > Protocol::MAX_PAYLOAD_BYTES) {
                $outcome['outcome'] = 'invalid_body';
                $outcomes[] = $outcome;
                continue;
            }
            if (Canonicalizer::hash($body) !== $item['after_hash']) {
                $this->releases->record_payload($item['release_item_id'], null, 'hash_mismatch');
                $outcome['outcome'] = 'hash_mismatch';
                $outcomes[] = $outcome;
                continue;
            }
            $decoded = json_decode($body, true);
            if ($decoded === null && $body !== 'null') {
                $outcome['outcome'] = 'invalid_body';
                $outcomes[] = $outcome;
                continue;
            }
            $media = $this->sanitize_media($payload['media'] ?? null, $payload['media_deferred'] ?? null);
            $this->releases->record_payload($item['release_item_id'], $body, '', $media['stored']);
            $outcome['outcome'] = 'stored';
            if ($media['count'] > 0 || $media['deferred'] > 0) {
                $outcome['media'] = ['stored' => $media['count'], 'deferred' => $media['deferred']];
            }
            $outcomes[] = $outcome;
        }

        $digest = $this->releases->seal($release['release_id']);
        $release = $this->releases->get($release['release_id']);

        return [
            'release_uid' => $release['release_uid'],
            'state' => $release['state'],
            'digest' => $release['digest'],
            'sealed' => $digest !== null,
            'outcomes' => $outcomes,
        ];
    }

    /**
     * Accept one large media blob streamed from the source over the dedicated
     * fetch-by-hash pull channel (post-M9 A-2). The hub trusts nothing the source
     * claims: the principal must own the release as its source, the release must
     * still be open, the declared hash must be one this release's items actually
     * reference, the body must be within the pull ceiling, and its sha256 must
     * match the declared hash. On success the raw bytes are stored
     * content-addressed (deduped) in the blob store; a release target later pulls
     * them by hash at apply time.
     *
     * @param array<string, mixed> $source
     * @param string $release_uid
     * @param string $hash  `sha256:<64 hex>`.
     * @param string $bytes Raw file bytes (not base64).
     * @return array<string, mixed>|\WP_Error
     */
    public function accept_media_blob(array $source, $release_uid, $hash, $bytes)
    {
        $release = $this->releases->find((string) $release_uid);
        if ($release === null || $release['source_environment_id'] !== $source['environment_id']) {
            return new \WP_Error('dbvc_agency_release_not_found', 'Release not found for this environment.', ['status' => 404]);
        }
        // Media may be uploaded while the release is open OR after it seals: the
        // payload POST that carries the fetch descriptor can itself seal the
        // release (all payloads received), so the follow-up blob upload lands
        // post-seal. This is safe — the blob is content-addressed and already
        // referenced by the sealed manifest's item hash, so it is never part of
        // the release digest. A withdrawn release accepts nothing.
        if ($release['state'] === ReleaseStore::STATE_WITHDRAWN) {
            return new \WP_Error('dbvc_agency_release_not_open', 'The release no longer accepts media.', ['status' => 409]);
        }
        if (! preg_match('/^sha256:[a-f0-9]{64}$/', (string) $hash)) {
            return new \WP_Error('dbvc_agency_invalid_hash', 'A sha256:<hex> content hash is required.', ['status' => 400]);
        }
        if (! is_string($bytes) || $bytes === '') {
            return new \WP_Error('dbvc_agency_invalid_body', 'Empty media body.', ['status' => 400]);
        }
        $ceiling = self::pull_ceiling();
        if (strlen($bytes) > $ceiling) {
            return new \WP_Error('dbvc_agency_media_too_large', sprintf('Media exceeds the pull ceiling of %d bytes.', $ceiling), ['status' => 413]);
        }
        if ('sha256:' . hash('sha256', $bytes) !== (string) $hash) {
            return new \WP_Error('dbvc_agency_media_hash_mismatch', 'The uploaded bytes do not match the declared content hash.', ['status' => 422]);
        }
        if (! $this->hash_in_release((int) $release['release_id'], (string) $hash)) {
            return new \WP_Error('dbvc_agency_media_unreferenced', 'That content hash is not referenced by this release.', ['status' => 403]);
        }
        $stored = MediaBlobStore::store((string) $hash, $bytes, '', strlen($bytes));

        return ['stored' => (bool) $stored, 'hash' => (string) $hash, 'bytes' => strlen($bytes)];
    }

    /**
     * Serve one media blob by hash to a party of the release over the pull
     * channel. The principal must be a target the release was prepared for (or
     * the source), and the hash must be referenced by the release. Returns the
     * raw bytes (the controller base64-wraps them in its JSON response) or an
     * error.
     *
     * @param array<string, mixed> $environment
     * @param string $release_uid
     * @param string $hash
     * @return string|\WP_Error raw bytes
     */
    public function serve_media_blob(array $environment, $release_uid, $hash)
    {
        $release = $this->releases->find((string) $release_uid);
        if ($release === null) {
            return new \WP_Error('dbvc_agency_release_not_found', 'Release not found.', ['status' => 404]);
        }
        if (! preg_match('/^sha256:[a-f0-9]{64}$/', (string) $hash)) {
            return new \WP_Error('dbvc_agency_invalid_hash', 'A sha256:<hex> content hash is required.', ['status' => 400]);
        }
        $is_source = $release['source_environment_id'] === $environment['environment_id'];
        $is_target = (new PreparationStore())->all((string) $release_uid, (string) $environment['environment_id'], 1) !== [];
        if (! $is_source && ! $is_target) {
            return new \WP_Error('dbvc_agency_media_forbidden', 'This environment is not a party to that release.', ['status' => 403]);
        }
        if (! $this->hash_in_release((int) $release['release_id'], (string) $hash)) {
            return new \WP_Error('dbvc_agency_media_unreferenced', 'That content hash is not referenced by this release.', ['status' => 404]);
        }
        $bytes = MediaBlobStore::bytes((string) $hash);
        if (! is_string($bytes) || $bytes === '') {
            return new \WP_Error('dbvc_agency_media_absent', 'That media blob has not been uploaded.', ['status' => 404]);
        }

        return $bytes;
    }

    /**
     * Whether any item in the release references the given content hash in its
     * media descriptors.
     *
     * @param int $release_id
     * @param string $hash
     * @return bool
     */
    private function hash_in_release($release_id, $hash)
    {
        foreach ($this->releases->items((int) $release_id, true) as $item) {
            if (! isset($item['media']) || ! is_string($item['media']) || $item['media'] === '') {
                continue;
            }
            $decoded = json_decode($item['media'], true);
            if (! is_array($decoded) || ! isset($decoded['media']) || ! is_array($decoded['media'])) {
                continue;
            }
            foreach ($decoded['media'] as $descriptor) {
                if (is_array($descriptor) && isset($descriptor['hash']) && (string) $descriptor['hash'] === (string) $hash) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Effective pull-channel ceiling (filterable, never below the inline cap).
     *
     * @return int
     */
    private static function pull_ceiling()
    {
        $ceiling = (int) apply_filters('dbvc_agency_max_pull_file_bytes', Protocol::MAX_PULL_FILE_BYTES);

        return $ceiling > 0 ? $ceiling : Protocol::MAX_PULL_FILE_BYTES;
    }

    /**
     * Validate a source's media bundle before it is stored on the item. The
     * hub trusts nothing the source claims: each entry's base64 bytes are
     * decoded, size-capped and re-hashed against its declared content hash,
     * and the per-item total and count caps are enforced here. Anything that
     * fails is recorded as `deferred` (descriptor kept, bytes dropped),
     * alongside the source's own deferred entries. Returns the JSON to store
     * (null when there is nothing) and the stored/deferred counts.
     *
     * @param mixed $media           Source-supplied media entries.
     * @param mixed $source_deferred Source-supplied deferred entries.
     * @return array{stored: string|null, count: int, deferred: int}
     */
    private function sanitize_media($media, $source_deferred)
    {
        $stored = [];
        $deferred = [];
        foreach (is_array($source_deferred) ? $source_deferred : [] as $entry) {
            if (is_array($entry) && isset($entry['ref'])) {
                $deferred[] = [
                    'ref' => (string) $entry['ref'],
                    'hash' => isset($entry['hash']) && is_string($entry['hash']) ? $entry['hash'] : null,
                    'reason' => isset($entry['reason']) ? (string) $entry['reason'] : 'deferred',
                ];
            }
        }
        $total = 0;
        foreach (is_array($media) ? $media : [] as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $hash = isset($entry['hash']) && is_string($entry['hash']) ? $entry['hash'] : '';
            $refs = [];
            foreach (is_array($entry['refs'] ?? null) ? $entry['refs'] : [] as $ref) {
                if (is_string($ref) && $ref !== '') {
                    $refs[] = $ref;
                }
            }
            $primary_ref = $refs !== [] ? $refs[0] : ('hash:' . substr($hash, 0, 20));
            // A fetch entry (a large file over the inline cap) carries no bytes —
            // the source streams them to the media pull channel separately. Keep
            // the descriptor with fetch:true; the blob arrives via
            // accept_media_blob and the target pulls it by hash at apply.
            if (! empty($entry['fetch'])) {
                if (! preg_match('/^sha256:[a-f0-9]{64}$/', $hash)) {
                    $deferred[] = ['ref' => $primary_ref, 'hash' => $hash !== '' ? $hash : null, 'reason' => 'invalid'];
                    continue;
                }
                if (count($stored) >= Protocol::MAX_MEDIA_ITEMS) {
                    $deferred[] = ['ref' => $primary_ref, 'hash' => $hash, 'reason' => 'too_many'];
                    continue;
                }
                $stored[] = [
                    'source_id' => (int) ($entry['source_id'] ?? 0),
                    'hash' => $hash,
                    'mime' => isset($entry['mime']) ? (string) $entry['mime'] : '',
                    'filename' => isset($entry['filename']) ? (string) $entry['filename'] : '',
                    'relpath' => isset($entry['relpath']) ? (string) $entry['relpath'] : '',
                    'filesize' => (int) ($entry['filesize'] ?? 0),
                    'url' => isset($entry['url']) ? (string) $entry['url'] : '',
                    'title' => isset($entry['title']) ? (string) $entry['title'] : '',
                    'alt' => isset($entry['alt']) ? (string) $entry['alt'] : '',
                    'caption' => isset($entry['caption']) ? (string) $entry['caption'] : '',
                    'refs' => $refs,
                    'fetch' => true,
                ];
                continue;
            }
            if (! preg_match('/^sha256:[a-f0-9]{64}$/', $hash) || ! is_string($entry['bytes'] ?? null)) {
                $deferred[] = ['ref' => $primary_ref, 'hash' => $hash !== '' ? $hash : null, 'reason' => 'invalid'];
                continue;
            }
            $bytes = base64_decode((string) $entry['bytes'], true);
            if ($bytes === false || $bytes === '') {
                $deferred[] = ['ref' => $primary_ref, 'hash' => $hash, 'reason' => 'invalid'];
                continue;
            }
            if (strlen($bytes) > Protocol::MAX_MEDIA_FILE_BYTES) {
                $deferred[] = ['ref' => $primary_ref, 'hash' => $hash, 'reason' => 'too_large'];
                continue;
            }
            if ('sha256:' . hash('sha256', $bytes) !== $hash) {
                $deferred[] = ['ref' => $primary_ref, 'hash' => $hash, 'reason' => 'hash_mismatch'];
                continue;
            }
            if (count($stored) >= Protocol::MAX_MEDIA_ITEMS) {
                $deferred[] = ['ref' => $primary_ref, 'hash' => $hash, 'reason' => 'too_many'];
                continue;
            }
            if ($total + strlen($bytes) > Protocol::MAX_MEDIA_TOTAL_BYTES) {
                $deferred[] = ['ref' => $primary_ref, 'hash' => $hash, 'reason' => 'total_exceeded'];
                continue;
            }
            $total += strlen($bytes);
            $descriptor = [
                'source_id' => (int) ($entry['source_id'] ?? 0),
                'hash' => $hash,
                'mime' => isset($entry['mime']) ? (string) $entry['mime'] : '',
                'filename' => isset($entry['filename']) ? (string) $entry['filename'] : '',
                'relpath' => isset($entry['relpath']) ? (string) $entry['relpath'] : '',
                'filesize' => strlen($bytes),
                'url' => isset($entry['url']) ? (string) $entry['url'] : '',
                'title' => isset($entry['title']) ? (string) $entry['title'] : '',
                'alt' => isset($entry['alt']) ? (string) $entry['alt'] : '',
                'caption' => isset($entry['caption']) ? (string) $entry['caption'] : '',
                'refs' => $refs,
            ];
            // Offload the bytes to the content-addressed blob store (A2-2a) so the
            // hot release_items.media column carries only the descriptor. If the
            // store write fails, keep the bytes inline so nothing is lost; the read
            // side (prepare/approve) hydrates from the store or the inline copy.
            if (! \Dbvc\AgencyControl\Storage\MediaBlobStore::store($hash, $bytes, $descriptor['mime'], $descriptor['filesize'])) {
                $descriptor['bytes'] = (string) $entry['bytes'];
            }
            $stored[] = $descriptor;
        }
        if ($stored === [] && $deferred === []) {
            return ['stored' => null, 'count' => 0, 'deferred' => 0];
        }

        return ['stored' => wp_json_encode(['media' => $stored, 'deferred' => $deferred]), 'count' => count($stored), 'deferred' => count($deferred)];
    }

    /**
     * @param string $release_uid
     * @return array<string, mixed>|\WP_Error
     */
    public function withdraw($release_uid)
    {
        $release = $this->releases->find((string) $release_uid);
        if ($release === null) {
            return new \WP_Error('dbvc_agency_release_not_found', 'Release not found.', ['status' => 404]);
        }
        if ($release['state'] === ReleaseStore::STATE_WITHDRAWN) {
            return ['release_uid' => $release['release_uid'], 'state' => $release['state'], 'changed' => false];
        }
        $this->releases->set_state($release['release_id'], ReleaseStore::STATE_WITHDRAWN);

        return ['release_uid' => $release['release_uid'], 'previous_state' => $release['state'], 'state' => ReleaseStore::STATE_WITHDRAWN, 'changed' => true];
    }

    /**
     * @param array<string, mixed> $source
     * @param string               $domain
     * @param string               $instance_uid
     * @param bool                 $deletion  A deletion item needs a verified absence (tombstone) instead of a present object.
     * @return array<string, mixed>|\WP_Error
     */
    private function current_object_projection(array $source, $domain, $instance_uid, $deletion = false)
    {
        $match = null;
        foreach ($this->projections->all($source['environment_id'], 5000) as $row) {
            if ($row['installation_epoch'] !== $source['current_epoch'] || $row['domain'] !== $domain || $row['instance_uid'] !== $instance_uid) {
                continue;
            }
            $match = $row;
            break;
        }
        if ($match === null) {
            return new \WP_Error('dbvc_agency_projection_not_found', sprintf('%s/%s has no current projection on %s.', $domain, $instance_uid, $source['environment_id']), ['status' => 404]);
        }
        if ((int) $match['snapshot_complete'] !== 1) {
            return new \WP_Error('dbvc_agency_projection_incomplete', sprintf('%s/%s is reported incomplete (masked or unreadable fields); it cannot be released.', $domain, $instance_uid), ['status' => 409]);
        }
        if ($deletion) {
            if ((int) $match['object_exists'] !== 0 || (string) $match['semantic_hash'] !== ComparisonService::absent_hash()) {
                return new \WP_Error('dbvc_agency_projection_present', sprintf('%s/%s still exists on the source; only a verified absence can be released as a deletion.', $domain, $instance_uid), ['status' => 409]);
            }

            return $match;
        }
        if ((int) $match['object_exists'] !== 1) {
            return new \WP_Error('dbvc_agency_projection_absent', sprintf('%s/%s is reported absent on the source; select it with operation=delete to release the deletion.', $domain, $instance_uid), ['status' => 409]);
        }

        return $match;
    }
}
