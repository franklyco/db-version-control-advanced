<?php

use Dbvc\Connected\Transport\HubClient;
use Dbvc\ConnectedProtocol\Protocol;

/**
 * A2-2c regression: the media pull GET must allow a response large enough for a
 * full-ceiling file (base64 is ×4/3), not the shared `get_json` cap
 * (`MAX_BATCH_BYTES` × 4 = 1 MiB) which truncated every fetch file (>2 MiB →
 * >2.7 MiB base64). Found by the live-verify; guarded here by intercepting the
 * HTTP request args with `pre_http_request` so no real request goes out.
 *
 * @group connected-environments
 */
class ConnectedHubClientMediaTest extends WP_UnitTestCase
{
    /**
     * @var array<string, mixed>
     */
    private $captured = [];

    public function set_up(): void
    {
        parent::set_up();
        $this->captured = [];
        add_filter('pre_http_request', [$this, 'capture'], 10, 3);
    }

    public function tear_down(): void
    {
        remove_filter('pre_http_request', [$this, 'capture'], 10);
        parent::tear_down();
    }

    /**
     * @param false|array<string,mixed> $preempt
     * @param array<string,mixed> $args
     * @param string $url
     * @return array<string,mixed>
     */
    public function capture($preempt, $args, $url)
    {
        unset($preempt);
        $this->captured = ['args' => $args, 'url' => $url];

        return [
            'response' => ['code' => 200, 'message' => 'OK'],
            'body' => wp_json_encode(['hash' => 'sha256:' . str_repeat('a', 64), 'bytes' => base64_encode('x'), 'filesize' => 1]),
            'headers' => [],
            'cookies' => [],
        ];
    }

    public function test_media_blob_get_allows_a_full_ceiling_response(): void
    {
        $res = HubClient::get_media_blob('https://hub.example', '/releases/r1/media/' . str_repeat('a', 64), ['username' => 'u', 'password' => 'p']);

        $this->assertTrue($res['ok'], 'the media GET decodes its (large) JSON body');
        $this->assertArrayHasKey('limit_response_size', $this->captured['args']);
        $this->assertGreaterThanOrEqual(
            Protocol::MAX_PULL_FILE_BYTES,
            (int) $this->captured['args']['limit_response_size'],
            'the pull GET must not truncate a full-ceiling media file'
        );
        $this->assertSame('GET', $this->captured['args']['method']);
    }

    public function test_plain_get_json_keeps_the_small_shared_limit(): void
    {
        HubClient::get_json('https://hub.example', '/inbox', [], ['username' => 'u', 'password' => 'p']);

        // Only the media-blob GET gets the large limit; the shared JSON client
        // stays capped at the small batch limit.
        $this->assertLessThan(Protocol::MAX_PULL_FILE_BYTES, (int) $this->captured['args']['limit_response_size']);
    }
}
