<?php

namespace Dbvc\Connected\Enrollment;

use Dbvc\Connected\Capture\DirtyCapture;
use Dbvc\Connected\Lifecycle\SecretBox;
use Dbvc\Connected\Storage\OutboxStore;
use Dbvc\Connected\Storage\Schema;
use Dbvc\Connected\Storage\StateStore;
use Dbvc\Connected\Transport\HubClient;
use Dbvc\ConnectedProtocol\ObservationEvent;
use Dbvc\ConnectedProtocol\Protocol;

/**
 * Connector-side enrollment: exchange a hub invitation for an application-
 * password principal, adopt the hub-assigned epoch, supersede provisional
 * outbox history and request a fresh inventory under the accepted identity.
 * Old immutable events are never rewritten.
 */
final class EnrollmentService
{
    /**
     * @var StateStore
     */
    private $state;

    /**
     * @var OutboxStore
     */
    private $outbox;

    public function __construct(?StateStore $state = null, ?OutboxStore $outbox = null)
    {
        $this->state = $state ?: new StateStore();
        $this->outbox = $outbox ?: new OutboxStore();
    }

    /**
     * @param string $hub_url
     * @param string $token
     * @return array<string, mixed>|\WP_Error Summary without the secret.
     */
    public function exchange($hub_url, $token)
    {
        Schema::reset_memo();
        if (! Schema::is_ready()) {
            return new \WP_Error('dbvc_connected_schema_not_ready', 'Connector schema is not installed.');
        }
        if (! SecretBox::is_available()) {
            return new \WP_Error('dbvc_connected_secret_storage_unavailable', 'Neither libsodium nor OpenSSL is available to protect the hub credential.');
        }
        $base = HubClient::validate_hub_url($hub_url);
        if (is_wp_error($base)) {
            return $base;
        }
        $token = trim((string) $token);
        if ($token === '') {
            return new \WP_Error('dbvc_connected_token_required', 'An invitation token is required.');
        }

        $current = $this->state->get();
        if (! is_array($current)) {
            $current = $this->state->initialize_provisional();
        }
        if (! is_array($current)) {
            return new \WP_Error('dbvc_connected_identity_uninitialized', 'Local identity could not be initialized.');
        }

        $response = HubClient::post_json($base, '/enrollments/exchange', [
            'token' => $token,
            'environment_id' => (string) $current['environment_id'],
            'site_url' => home_url('/'),
            'plugin_version' => defined('DBVC_PLUGIN_VERSION') ? DBVC_PLUGIN_VERSION : '',
            'protocol_versions' => [Protocol::PROTOCOL_VERSION],
        ]);
        if (! $response['ok']) {
            return new \WP_Error($response['error_code'] !== '' ? $response['error_code'] : 'dbvc_connected_exchange_failed', 'Enrollment exchange failed: ' . $response['error'], ['status' => $response['status']]);
        }

        $body = $response['body'];
        $principal = isset($body['principal']) && is_array($body['principal']) ? $body['principal'] : [];
        $environment_id = (string) ($body['environment_id'] ?? '');
        $epoch = (string) ($body['installation_epoch'] ?? '');
        if (
            ! ObservationEvent::isIdentifier($environment_id) || ! ObservationEvent::isIdentifier($epoch)
            || empty($principal['username']) || empty($principal['password'])
            || ($principal['scheme'] ?? '') !== 'wp_application_password'
        ) {
            return new \WP_Error('dbvc_connected_exchange_invalid', 'The hub returned an incomplete enrollment.');
        }

        $recorded = $this->state->record_enrollment([
            'environment_id' => $environment_id,
            'installation_epoch' => $epoch,
            'hub_url' => $base,
            'agency_id' => (string) ($body['agency_id'] ?? ''),
            'client_id' => (string) ($body['client_id'] ?? ''),
            'principal' => (string) $principal['username'],
            'secret' => (string) $principal['password'],
            'site_url' => home_url('/'),
        ]);
        if (! $recorded) {
            return new \WP_Error('dbvc_connected_enrollment_store_failed', 'The enrollment could not be stored locally.');
        }

        $superseded = $this->outbox->supersede_other_epochs($epoch);
        $generations = [];
        if (class_exists('DBVC_Connected_Environments_Addon')) {
            $generations = \DBVC_Connected_Environments_Addon::request_reconciliation('', 'enrollment');
        }
        DirtyCapture::schedule_processing(DirtyCapture::processing_delay());
        DirtyCapture::schedule_inbox_poll(DirtyCapture::processing_delay());

        return [
            'environment_id' => $environment_id,
            'installation_epoch' => $epoch,
            'hub_url' => $base,
            'agency_id' => (string) ($body['agency_id'] ?? ''),
            'client_id' => (string) ($body['client_id'] ?? ''),
            'principal' => (string) $principal['username'],
            'connection_state' => StateStore::CONNECTION_ENROLLED,
            'superseded_events' => $superseded,
            'reconciliation' => $generations,
        ];
    }
}
