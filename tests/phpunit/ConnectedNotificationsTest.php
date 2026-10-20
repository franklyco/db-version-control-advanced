<?php

use Dbvc\AgencyControl\Notifications\NotificationService;
use Dbvc\AgencyControl\Storage\Schema as HubSchema;

/**
 * M9 slice 7d-1 — outbound notification channels + enqueue/flush. Governance
 * events enqueue deduped pending rows per matching channel; flush() sends them
 * (webhook with an SSRF guard + optional HMAC, or email) and records the result.
 *
 * @group connected-environments
 */
class ConnectedNotificationsTest extends WP_UnitTestCase
{
    /** @var array<int, array<string, mixed>> */
    private $http_calls = [];

    public function set_up(): void
    {
        parent::set_up();
        add_filter('dbvc_agency_control_transaction_strategy', [$this, 'savepoint_strategy']);
        delete_option(HubSchema::OPTION_SCHEMA_VERSION);
        HubSchema::reset_memo();
        DBVC_Agency_Control_Addon::save_settings([DBVC_Agency_Control_Addon::OPTION_ENABLED => '1']);
        $this->http_calls = [];
    }

    public function tear_down(): void
    {
        DBVC_Agency_Control_Addon::save_settings([]);
        remove_filter('dbvc_agency_control_transaction_strategy', [$this, 'savepoint_strategy']);
        HubSchema::reset_memo();
        parent::tear_down();
    }

    public function savepoint_strategy(): string
    {
        return 'savepoint';
    }

    public function test_ssrf_guard(): void
    {
        $this->assertFalse(NotificationService::is_safe_webhook_url('http://example.com/hook'), 'http rejected');
        $this->assertFalse(NotificationService::is_safe_webhook_url('https://localhost/hook'));
        $this->assertFalse(NotificationService::is_safe_webhook_url('https://hub.local/hook'));
        $this->assertFalse(NotificationService::is_safe_webhook_url('https://127.0.0.1/hook'));
        $this->assertFalse(NotificationService::is_safe_webhook_url('https://10.1.2.3/hook'), 'private range');
        $this->assertFalse(NotificationService::is_safe_webhook_url('https://169.254.1.1/hook'), 'link-local');
        $this->assertFalse(NotificationService::is_safe_webhook_url('not a url'));
        $this->assertTrue(NotificationService::is_safe_webhook_url('https://93.184.216.34/hook'), 'public IP literal accepted');
    }

    public function test_channel_save_validates_target(): void
    {
        $svc = new NotificationService();
        $this->assertSame('dbvc_agency_invalid_channel', $svc->save_channel(['type' => 'webhook', 'target' => 'https://127.0.0.1/x'])->get_error_code());
        $this->assertSame('dbvc_agency_invalid_channel', $svc->save_channel(['type' => 'email', 'target' => 'not-an-email'])->get_error_code());
        $this->assertSame('dbvc_agency_invalid_channel', $svc->save_channel(['type' => 'carrier-pigeon', 'target' => 'x'])->get_error_code());

        $ch = $svc->save_channel(['type' => 'webhook', 'target' => 'https://93.184.216.34/hook', 'agency_id' => 'ag-1', 'events' => ['framework_rollout.completed'], 'secret' => 's3cret']);
        $this->assertIsArray($ch, is_wp_error($ch) ? $ch->get_error_message() : '');
        $this->assertSame('webhook', $ch['type']);
        $this->assertTrue($ch['has_secret'], 'secret presence is reported');
        $this->assertArrayNotHasKey('secret', $ch, 'the secret value is never returned');
        $this->assertSame(['framework_rollout.completed'], $ch['events']);
    }

    public function test_enqueue_dedupes_and_webhook_flush_sends_signed_payload(): void
    {
        $svc = new NotificationService();
        $ch = $svc->save_channel(['type' => 'webhook', 'target' => 'https://93.184.216.34/hook', 'agency_id' => 'ag-1', 'events' => [], 'secret' => 'shhh']);

        // Two enqueues of the same (event, dedupe_key) → one pending row.
        $this->assertSame(1, $svc->enqueue('ag-1', 'framework_rollout.completed', 'camp-1:completed', ['campaign_uid' => 'camp-1', 'state' => 'completed']));
        $this->assertSame(0, $svc->enqueue('ag-1', 'framework_rollout.completed', 'camp-1:completed', ['campaign_uid' => 'camp-1', 'state' => 'completed']), 'deduped');
        // A channel for another agency does not receive it.
        $svc->save_channel(['type' => 'webhook', 'target' => 'https://93.184.216.34/other', 'agency_id' => 'ag-2', 'events' => []]);
        $this->assertSame(1, $svc->enqueue('ag-1', 'framework_rollout.paused', 'camp-1:paused', []), 'only ag-1 + fleet-wide channels match');

        add_filter('pre_http_request', [$this, 'capture_http'], 10, 3);
        $flushed = $svc->flush(50);
        remove_filter('pre_http_request', [$this, 'capture_http'], 10);

        $this->assertSame(2, $flushed['sent'], wp_json_encode($flushed));
        $this->assertCount(2, $this->http_calls);
        $first = $this->http_calls[0];
        $this->assertSame('https://93.184.216.34/hook', $first['url']);
        $body = json_decode((string) $first['args']['body'], true);
        $this->assertSame('framework_rollout.completed', $body['event']);
        $this->assertSame('camp-1', $body['payload']['campaign_uid']);
        $this->assertStringStartsWith('sha256=', $first['args']['headers']['X-DBVC-Signature'], 'HMAC signature present when a secret is set');

        // Re-flush sends nothing (all rows are now sent).
        $this->assertSame(['sent' => 0, 'failed' => 0, 'skipped' => 0], $svc->flush(50));
        $log = $svc->log_entries(50, 'sent');
        $this->assertCount(2, $log);
    }

    public function test_email_channel_flush(): void
    {
        $svc = new NotificationService();
        $svc->save_channel(['type' => 'email', 'target' => 'ops@example.com', 'agency_id' => 'ag-1', 'events' => ['override.needs_rebase']]);
        $svc->enqueue('ag-1', 'override.needs_rebase', 'def-1:2', ['definition_uid' => 'def-1', 'version' => '2']);

        add_filter('pre_wp_mail', '__return_true');
        $flushed = $svc->flush(50);
        remove_filter('pre_wp_mail', '__return_true');

        $this->assertSame(1, $flushed['sent'], wp_json_encode($flushed));
    }

    /**
     * @param mixed                $pre
     * @param array<string, mixed> $args
     * @param string               $url
     * @return array<string, mixed>
     */
    public function capture_http($pre, $args, $url)
    {
        unset($pre);
        $this->http_calls[] = ['url' => $url, 'args' => $args];

        return ['response' => ['code' => 200, 'message' => 'OK'], 'body' => 'ok', 'headers' => []];
    }
}
