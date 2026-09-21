<?php

namespace Dbvc\AgencyControl\Enrollment;

use Dbvc\AgencyControl\Storage\EnvironmentRegistry;
use Dbvc\AgencyControl\Storage\InvitationStore;
use Dbvc\AgencyControl\Storage\Schema;
use Dbvc\ConnectedProtocol\ObservationEvent;
use Dbvc\ConnectedProtocol\Protocol;

/**
 * Hub-side enrollment.
 *
 * Authentication scheme (ADR-009 resolved): WordPress Application Passwords.
 * Each enrolled environment gets its own capability-less service user and
 * one application password whose UUID is bound to the enrollment record.
 * WordPress stores only the password hash; the plaintext is returned once,
 * inside the exchange response. Revocation deletes the application password
 * and marks the environment revoked. The legacy Bricks HMAC primitives are
 * not reused: they are client-role coupled and keep replay state in an
 * option array.
 */
final class EnrollmentService
{
    /**
     * @var InvitationStore
     */
    private $invitations;

    /**
     * @var EnvironmentRegistry
     */
    private $environments;

    public function __construct(?InvitationStore $invitations = null, ?EnvironmentRegistry $environments = null)
    {
        $this->invitations = $invitations ?: new InvitationStore();
        $this->environments = $environments ?: new EnvironmentRegistry();
    }

    /**
     * Administrator action: create a single-use invitation.
     *
     * @param array<string, mixed> $args agency_id, client_id, environment_label, environment_id (optional), ttl_seconds
     * @return array<string, mixed>|\WP_Error
     */
    public function invite(array $args)
    {
        $agency_id = (string) ($args['agency_id'] ?? 'studio');
        $client_id = (string) ($args['client_id'] ?? '');
        $environment_id = (string) ($args['environment_id'] ?? '');
        foreach (['agency_id' => $agency_id, 'client_id' => $client_id] as $key => $value) {
            if (! ObservationEvent::isIdentifier($value)) {
                return new \WP_Error('dbvc_agency_invalid_' . $key, sprintf('%s must be a bounded ASCII identifier.', $key), ['status' => 400]);
            }
        }
        if ($environment_id !== '') {
            if (! ObservationEvent::isIdentifier($environment_id)) {
                return new \WP_Error('dbvc_agency_invalid_environment_id', 'environment_id must be a bounded ASCII identifier.', ['status' => 400]);
            }
            if ($this->environments->find($environment_id) !== null) {
                return new \WP_Error('dbvc_agency_environment_exists', 'That environment_id is already enrolled.', ['status' => 409]);
            }
        }

        $created = $this->invitations->create([
            'agency_id' => $agency_id,
            'client_id' => $client_id,
            'environment_label' => (string) ($args['environment_label'] ?? ''),
            'environment_id' => $environment_id,
            'ttl_seconds' => (int) ($args['ttl_seconds'] ?? InvitationStore::DEFAULT_TTL_SECONDS),
            'created_by' => get_current_user_id() ?: null,
        ]);
        if ($created === null) {
            return new \WP_Error('dbvc_agency_invitation_failed', 'The invitation could not be stored.', ['status' => 500]);
        }

        return [
            'invitation_id' => $created['invitation_id'],
            'token' => $created['token'],
            'expires_at' => $created['expires_at'],
            'agency_id' => $agency_id,
            'client_id' => $client_id,
            'environment_id' => $environment_id,
            'hub_url' => home_url('/'),
        ];
    }

    /**
     * Connector-facing exchange: consume the invitation, bind a principal,
     * assign the epoch, and return the credential exactly once.
     *
     * @param array<string, mixed> $request token, environment_id, site_url, protocol_versions, plugin_version
     * @return array<string, mixed>|\WP_Error
     */
    public function exchange(array $request)
    {
        $token = (string) ($request['token'] ?? '');
        if (strlen($token) < 20 || strlen($token) > 128 || ! preg_match('/^[A-Za-z0-9._:-]+$/', $token)) {
            return new \WP_Error('dbvc_agency_invitation_not_found', 'Invitation not found.', ['status' => 404]);
        }
        $invitation = $this->invitations->find_by_token($token);
        if ($invitation === null) {
            return new \WP_Error('dbvc_agency_invitation_not_found', 'Invitation not found.', ['status' => 404]);
        }
        if ($invitation['consumed_at'] !== null) {
            return new \WP_Error('dbvc_agency_invitation_consumed', 'Invitation already used.', ['status' => 409]);
        }
        if (strtotime((string) $invitation['expires_at'] . ' UTC') < time()) {
            return new \WP_Error('dbvc_agency_invitation_expired', 'Invitation expired.', ['status' => 410]);
        }

        $protocols = isset($request['protocol_versions']) && is_array($request['protocol_versions']) ? array_map('strval', $request['protocol_versions']) : [];
        if (! in_array(Protocol::PROTOCOL_VERSION, $protocols, true)) {
            return new \WP_Error('dbvc_agency_protocol_unsupported', 'The connector does not support ' . Protocol::PROTOCOL_VERSION . '.', ['status' => 409]);
        }

        $environment_id = (string) $invitation['environment_id'];
        if ($environment_id === '') {
            $environment_id = (string) ($request['environment_id'] ?? '');
            if (! ObservationEvent::isIdentifier($environment_id)) {
                return new \WP_Error('dbvc_agency_invalid_environment_id', 'Proposed environment_id must be a bounded ASCII identifier.', ['status' => 400]);
            }
        }
        if ($this->environments->find($environment_id) !== null) {
            return new \WP_Error('dbvc_agency_environment_id_taken', 'environment_id is already enrolled; enroll with a new identity.', ['status' => 409]);
        }

        $site_url = esc_url_raw((string) ($request['site_url'] ?? ''));

        if (! $this->invitations->consume((int) $invitation['invitation_id'], $environment_id)) {
            return new \WP_Error('dbvc_agency_invitation_consumed', 'Invitation already used.', ['status' => 409]);
        }

        Schema::ensure_role();
        $login = 'dbvc-env-' . substr(hash('sha256', $environment_id . '|' . bin2hex(random_bytes(8))), 0, 16);
        $user_id = wp_insert_user([
            'user_login' => $login,
            'user_pass' => wp_generate_password(64, true, true),
            'user_email' => $login . '@dbvc-connected.invalid',
            'display_name' => (string) ($invitation['environment_label'] !== '' ? $invitation['environment_label'] : $environment_id),
            'role' => Schema::ENVIRONMENT_ROLE,
        ]);
        if (is_wp_error($user_id)) {
            return new \WP_Error('dbvc_agency_principal_failed', 'Service user could not be created: ' . $user_id->get_error_message(), ['status' => 500]);
        }

        $password = \WP_Application_Passwords::create_new_application_password((int) $user_id, [
            'name' => 'DBVC Connected Environments: ' . $environment_id,
        ]);
        if (is_wp_error($password)) {
            $this->delete_user((int) $user_id);
            return new \WP_Error('dbvc_agency_principal_failed', 'Application password could not be created: ' . $password->get_error_message(), ['status' => 500]);
        }
        list($plaintext, $item) = $password;

        $epoch = 'enrolled-' . gmdate('Ymd') . '-' . bin2hex(random_bytes(6));
        $environment = $this->environments->create([
            'agency_id' => (string) $invitation['agency_id'],
            'client_id' => (string) $invitation['client_id'],
            'environment_id' => $environment_id,
            'label' => (string) $invitation['environment_label'],
            'current_epoch' => $epoch,
            'principal_user_id' => (int) $user_id,
            'principal_uuid' => (string) $item['uuid'],
            'site_url' => $site_url,
        ]);
        if ($environment === null) {
            $this->delete_user((int) $user_id);
            return new \WP_Error('dbvc_agency_environment_failed', 'Environment record could not be stored.', ['status' => 500]);
        }

        return [
            'environment_id' => $environment_id,
            'installation_epoch' => $epoch,
            'agency_id' => (string) $invitation['agency_id'],
            'client_id' => (string) $invitation['client_id'],
            'principal' => [
                'username' => $login,
                'password' => $plaintext,
                'uuid' => (string) $item['uuid'],
                'scheme' => 'wp_application_password',
            ],
            'hub' => Protocol::describe(),
        ];
    }

    /**
     * Administrator action: revoke credentials and delivery authority together.
     *
     * @param string $environment_id
     * @return array<string, mixed>|\WP_Error
     */
    public function revoke($environment_id)
    {
        $environment = $this->environments->find((string) $environment_id);
        if ($environment === null) {
            return new \WP_Error('dbvc_agency_environment_not_found', 'Environment not found.', ['status' => 404]);
        }
        \WP_Application_Passwords::delete_application_password($environment['principal_user_id'], $environment['principal_uuid']);
        $this->environments->update($environment['environment_id'], ['status' => EnvironmentRegistry::STATUS_REVOKED, 'hold_reason' => 'revoked']);

        return ['environment_id' => $environment['environment_id'], 'status' => EnvironmentRegistry::STATUS_REVOKED];
    }

    /**
     * Administrator action: hold an enabled environment pending review. The hub
     * refuses its observation batches (409) and keeps its deliveries pending until
     * release; the reason is recorded as `operator:<note>`.
     *
     * @param string $environment_id
     * @param string $reason
     * @return array<string, mixed>|\WP_Error
     */
    public function hold($environment_id, $reason = '')
    {
        $environment = $this->environments->find((string) $environment_id);
        if ($environment === null) {
            return new \WP_Error('dbvc_agency_environment_not_found', 'Environment not found.', ['status' => 404]);
        }
        if ($environment['status'] === EnvironmentRegistry::STATUS_REVOKED) {
            return new \WP_Error('dbvc_agency_environment_revoked', 'A revoked environment must re-enroll.', ['status' => 409]);
        }
        if ($environment['status'] === EnvironmentRegistry::STATUS_HELD) {
            return new \WP_Error('dbvc_agency_environment_already_held', 'Environment is already held: ' . $environment['hold_reason'], ['status' => 409]);
        }
        $note = preg_replace('/[^\x20-\x7E]/', '', trim((string) $reason));
        $hold_reason = substr('operator' . ($note !== '' ? ':' . $note : ''), 0, 128);
        $this->environments->update($environment['environment_id'], [
            'status' => EnvironmentRegistry::STATUS_HELD,
            'hold_reason' => $hold_reason,
        ]);

        return ['environment_id' => $environment['environment_id'], 'status' => EnvironmentRegistry::STATUS_HELD, 'hold_reason' => $hold_reason];
    }

    /**
     * Administrator action: clear a hold after review. Revoked environments stay revoked.
     *
     * @param string $environment_id
     * @return array<string, mixed>|\WP_Error
     */
    public function release($environment_id)
    {
        $environment = $this->environments->find((string) $environment_id);
        if ($environment === null) {
            return new \WP_Error('dbvc_agency_environment_not_found', 'Environment not found.', ['status' => 404]);
        }
        if ($environment['status'] === EnvironmentRegistry::STATUS_REVOKED) {
            return new \WP_Error('dbvc_agency_environment_revoked', 'A revoked environment must re-enroll.', ['status' => 409]);
        }
        $this->environments->update($environment['environment_id'], [
            'status' => EnvironmentRegistry::STATUS_ENABLED,
            'hold_reason' => '',
            // A site-URL hold is released by accepting the URL the site last reported; other holds keep the enrolled URL.
            'enrolled_site_url' => (string) $environment['last_site_url'] !== '' ? (string) $environment['last_site_url'] : (string) $environment['enrolled_site_url'],
        ]);

        return ['environment_id' => $environment['environment_id'], 'status' => EnvironmentRegistry::STATUS_ENABLED];
    }

    /**
     * @param int $user_id
     * @return void
     */
    private function delete_user($user_id)
    {
        if (! function_exists('wp_delete_user')) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
        }
        wp_delete_user((int) $user_id);
    }
}
