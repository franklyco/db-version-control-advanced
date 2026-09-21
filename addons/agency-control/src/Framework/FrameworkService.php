<?php

namespace Dbvc\AgencyControl\Framework;

use Dbvc\AgencyControl\Comparison\ComparisonService;
use Dbvc\AgencyControl\Storage\DefinitionStore;
use Dbvc\AgencyControl\Storage\EnvironmentRegistry;
use Dbvc\AgencyControl\Storage\OverrideStore;
use Dbvc\AgencyControl\Storage\ProjectionStore;
use Dbvc\AgencyControl\Storage\SubscriptionStore;
use Dbvc\ConnectedProtocol\ObservationEvent;

/**
 * Explicit studio actions on framework definitions: publish an immutable
 * version (hash supplied, or read from one environment's current complete
 * projection), mark a channel's desired version, adopt a version on a
 * framework subscription, approve or detach an override. None of these
 * touch content on any environment, and none promote a client edit: an
 * approval records that a specific observed hash is acceptable, nothing more.
 */
final class FrameworkService
{
    /**
     * @var DefinitionStore
     */
    private $definitions;

    /**
     * @var OverrideStore
     */
    private $overrides;

    /**
     * @var SubscriptionStore
     */
    private $subscriptions;

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
        $this->definitions = new DefinitionStore();
        $this->overrides = new OverrideStore();
        $this->subscriptions = new SubscriptionStore();
        $this->environments = new EnvironmentRegistry();
        $this->projections = new ProjectionStore();
    }

    /**
     * @param array<string, mixed> $args agency_id, definition_uid, version, domain, profile, channel, hash,
     *                                   from_environment_id, from_instance_uid, from_version, version_order, desired, note
     * @return array<string, mixed>|\WP_Error
     */
    public function publish(array $args)
    {
        $agency_id = (string) ($args['agency_id'] ?? 'studio');
        $definition_uid = (string) ($args['definition_uid'] ?? '');
        $version = (string) ($args['version'] ?? '');
        $channel = (string) ($args['channel'] ?? '');
        $channel = $channel !== '' ? $channel : 'stable';
        foreach (['agency_id' => $agency_id, 'definition_uid' => $definition_uid, 'version' => $version, 'channel' => $channel] as $key => $value) {
            if (! ObservationEvent::isIdentifier($value)) {
                return new \WP_Error('dbvc_agency_invalid_identifier', $key . ' must be a bounded ASCII identifier.', ['status' => 400]);
            }
        }

        $source = $this->resolve_source($agency_id, $definition_uid, $args);
        if (is_wp_error($source)) {
            return $source;
        }

        if (isset($args['version_order']) && $args['version_order'] !== '' && $args['version_order'] !== null) {
            $version_order = (int) $args['version_order'];
            if ($version_order < 1) {
                return new \WP_Error('dbvc_agency_invalid_version_order', 'version_order must be a positive integer supplied by the studio.', ['status' => 400]);
            }
        } else {
            $version_order = $this->definitions->next_version_order($agency_id, $definition_uid);
        }

        $result = $this->definitions->publish([
            'agency_id' => $agency_id,
            'definition_uid' => $definition_uid,
            'version' => $version,
            'version_order' => $version_order,
            'channel' => $channel,
            'domain' => $source['domain'],
            'profile' => $source['profile'],
            'definition_hash' => $source['hash'],
            'desired' => 0,
            'source_environment_id' => $source['environment_id'],
            'source_instance_uid' => $source['instance_uid'],
            'published_by' => get_current_user_id(),
            'note' => (string) ($args['note'] ?? ''),
        ]);
        if ($result === 'exists') {
            return new \WP_Error('dbvc_agency_definition_exists', 'Definition versions are immutable; publish a new version instead.', ['status' => 409]);
        }
        if ($result === 'order_exists') {
            return new \WP_Error('dbvc_agency_version_order_taken', 'Another version on this channel already has that order; version distance must be unambiguous.', ['status' => 409]);
        }
        if ($result !== 'created') {
            return new \WP_Error('dbvc_agency_definition_error', 'Definition version could not be recorded.', ['status' => 500]);
        }

        $response = [
            'agency_id' => $agency_id,
            'definition_uid' => $definition_uid,
            'version' => $version,
            'version_order' => $version_order,
            'channel' => $channel,
            'domain' => $source['domain'],
            'profile' => $source['profile'],
            'definition_hash' => $source['hash'],
            'source' => $source['environment_id'] !== '' ? ['environment_id' => $source['environment_id'], 'instance_uid' => $source['instance_uid']] : null,
            'desired' => false,
            'rebase_reviews' => 0,
        ];
        if (! empty($args['desired'])) {
            $desired = $this->set_desired($agency_id, $definition_uid, $version);
            if (is_wp_error($desired)) {
                return $desired;
            }
            $response['desired'] = true;
            $response['rebase_reviews'] = $desired['rebase_reviews'];
        }

        return $response;
    }

    /**
     * Make a published version the desired one on its channel. A change of
     * desired version flags every approved override on that channel for
     * rebase review; it never rewrites or removes an override.
     *
     * @param string $agency_id
     * @param string $definition_uid
     * @param string $version
     * @return array<string, mixed>|\WP_Error
     */
    public function set_desired($agency_id, $definition_uid, $version)
    {
        $definition = $this->definitions->get((string) $agency_id, (string) $definition_uid, (string) $version);
        if ($definition === null) {
            return new \WP_Error('dbvc_agency_definition_not_found', 'Definition version not found.', ['status' => 404]);
        }
        $previous = $this->definitions->desired($definition['agency_id'], $definition['definition_uid'], $definition['channel']);
        if ($previous !== null && $previous['version'] === $definition['version']) {
            return ['definition_uid' => $definition['definition_uid'], 'channel' => $definition['channel'], 'version' => $definition['version'], 'changed' => false, 'rebase_reviews' => 0];
        }
        if (! $this->definitions->set_desired($definition['agency_id'], $definition['definition_uid'], $definition['channel'], $definition['version'])) {
            return new \WP_Error('dbvc_agency_definition_error', 'Desired version could not be recorded.', ['status' => 500]);
        }

        $flagged = 0;
        foreach ($this->subscriptions->framework_subscriptions(null, $definition['definition_uid']) as $subscription) {
            if ($subscription['channel'] !== $definition['channel'] || $subscription['agency_id'] !== $definition['agency_id']) {
                continue;
            }
            $override = $this->overrides->find($subscription['source_environment_id'], $subscription['domain'], $subscription['instance_uid'], $subscription['definition_uid']);
            if ($override !== null && $override['state'] === OverrideStore::STATE_APPROVED) {
                $flagged += $this->overrides->set_state($override['override_id'], OverrideStore::STATE_NEEDS_REBASE_REVIEW) ? 1 : 0;
            }
        }

        return [
            'definition_uid' => $definition['definition_uid'],
            'channel' => $definition['channel'],
            'version' => $definition['version'],
            'previous_version' => $previous !== null ? $previous['version'] : null,
            'changed' => true,
            'rebase_reviews' => $flagged,
        ];
    }

    /**
     * Explicit adoption of a published version by one framework subscription.
     *
     * @param int    $subscription_id
     * @param string $version
     * @return array<string, mixed>|\WP_Error
     */
    public function adopt_version($subscription_id, $version)
    {
        $subscription = $this->subscriptions->get((int) $subscription_id);
        if ($subscription === null || $subscription['subscription_type'] !== SubscriptionStore::TYPE_FRAMEWORK) {
            return new \WP_Error('dbvc_agency_subscription_not_found', 'Framework subscription not found.', ['status' => 404]);
        }
        $definition = $this->definitions->get($subscription['agency_id'], $subscription['definition_uid'], (string) $version);
        if ($definition === null) {
            return new \WP_Error('dbvc_agency_definition_not_found', 'Definition version not found for this subscription\'s definition.', ['status' => 404]);
        }
        if ($definition['domain'] !== $subscription['domain']) {
            return new \WP_Error('dbvc_agency_domain_mismatch', 'The definition version belongs to a different domain than the subscription.', ['status' => 400]);
        }
        if ($subscription['adopted_version'] === $definition['version']) {
            return ['subscription_id' => (int) $subscription_id, 'adopted_version' => $definition['version'], 'changed' => false];
        }
        if (! $this->subscriptions->set_adopted_version((int) $subscription_id, $definition['version'])) {
            return new \WP_Error('dbvc_agency_subscription_error', 'Adopted version could not be recorded.', ['status' => 500]);
        }

        return ['subscription_id' => (int) $subscription_id, 'previous_version' => $subscription['adopted_version'], 'adopted_version' => $definition['version'], 'changed' => true];
    }

    /**
     * Approve an exact hash for one subscribed instance. The hash is supplied
     * explicitly or read from the environment's current complete, fresh
     * projection; the approval freezes the policy revision it was made under.
     *
     * @param array<string, mixed> $args environment_id, domain, instance_uid, definition_uid, rationale, hash
     * @return array<string, mixed>|\WP_Error
     */
    public function approve_override(array $args)
    {
        $subscription = $this->find_subscription($args);
        if (is_wp_error($subscription)) {
            return $subscription;
        }
        $rationale = trim((string) ($args['rationale'] ?? ''));
        if ($rationale === '') {
            return new \WP_Error('dbvc_agency_rationale_required', 'An override approval records why the deviation is acceptable.', ['status' => 400]);
        }
        $environment = $this->environments->find($subscription['source_environment_id']);
        if ($environment === null) {
            return new \WP_Error('dbvc_agency_environment_not_found', 'Environment not found.', ['status' => 404]);
        }

        $hash = (string) ($args['hash'] ?? '');
        if ($hash !== '') {
            if (! self::is_hash($hash)) {
                return new \WP_Error('dbvc_agency_invalid_hash', 'hash must be a lowercase 64-character hex digest.', ['status' => 400]);
            }
        } else {
            $profile = $this->profile_for($subscription);
            if ($profile === null) {
                return new \WP_Error('dbvc_agency_definition_not_found', 'No published definition version names this subscription\'s profile; supply the hash explicitly.', ['status' => 404]);
            }
            $observed = $this->observed_hash($environment, $subscription['domain'], $subscription['instance_uid'], $profile);
            if (is_wp_error($observed)) {
                return $observed;
            }
            $hash = $observed;
        }

        $snapshot = $this->subscriptions->registry_snapshot($environment);
        $recorded = $this->overrides->approve([
            'agency_id' => $subscription['agency_id'],
            'client_id' => $subscription['client_id'],
            'environment_id' => $subscription['source_environment_id'],
            'domain' => $subscription['domain'],
            'instance_uid' => $subscription['instance_uid'],
            'definition_uid' => $subscription['definition_uid'],
            'definition_version' => $subscription['adopted_version'],
            'approved_hash' => $hash,
            'policy_revision' => $snapshot['revision'],
            'rationale' => $rationale,
            'approved_by' => get_current_user_id(),
        ]);
        if (! $recorded) {
            return new \WP_Error('dbvc_agency_override_error', 'Override could not be recorded.', ['status' => 500]);
        }
        $override = $this->overrides->find($subscription['source_environment_id'], $subscription['domain'], $subscription['instance_uid'], $subscription['definition_uid']);

        return [
            'override_id' => $override !== null ? $override['override_id'] : 0,
            'environment_id' => $subscription['source_environment_id'],
            'domain' => $subscription['domain'],
            'instance_uid' => $subscription['instance_uid'],
            'definition_uid' => $subscription['definition_uid'],
            'definition_version' => $subscription['adopted_version'],
            'approved_hash' => $hash,
            'policy_revision' => $snapshot['revision'],
            'state' => OverrideStore::STATE_APPROVED,
        ];
    }

    /**
     * @param array<string, mixed> $args environment_id, domain, instance_uid, definition_uid
     * @return array<string, mixed>|\WP_Error
     */
    public function detach_override(array $args)
    {
        $override = $this->overrides->find((string) ($args['environment_id'] ?? ''), (string) ($args['domain'] ?? ''), (string) ($args['instance_uid'] ?? ''), (string) ($args['definition_uid'] ?? ''));
        if ($override === null) {
            return new \WP_Error('dbvc_agency_override_not_found', 'Override not found.', ['status' => 404]);
        }
        if ($override['state'] === OverrideStore::STATE_DETACHED) {
            return ['override_id' => $override['override_id'], 'state' => $override['state'], 'changed' => false];
        }
        $this->overrides->set_state($override['override_id'], OverrideStore::STATE_DETACHED);

        return ['override_id' => $override['override_id'], 'previous_state' => $override['state'], 'state' => OverrideStore::STATE_DETACHED, 'changed' => true];
    }

    /**
     * @param string $value
     * @return bool
     */
    public static function is_hash($value)
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/', $value) === 1;
    }

    /**
     * @param string               $agency_id
     * @param string               $definition_uid
     * @param array<string, mixed> $args
     * @return array{domain: string, profile: string, hash: string, environment_id: string, instance_uid: string}|\WP_Error
     */
    private function resolve_source($agency_id, $definition_uid, array $args)
    {
        $from_version = (string) ($args['from_version'] ?? '');
        if ($from_version !== '') {
            $existing = $this->definitions->get($agency_id, $definition_uid, $from_version);
            if ($existing === null) {
                return new \WP_Error('dbvc_agency_definition_not_found', 'from_version does not name a published version of this definition.', ['status' => 404]);
            }

            return ['domain' => $existing['domain'], 'profile' => $existing['profile'], 'hash' => $existing['definition_hash'], 'environment_id' => $existing['source_environment_id'], 'instance_uid' => $existing['source_instance_uid']];
        }

        $domain = (string) ($args['domain'] ?? '');
        if (! in_array($domain, ObservationEvent::DOMAINS, true)) {
            return new \WP_Error('dbvc_agency_invalid_domain', 'Unknown domain: ' . $domain, ['status' => 400]);
        }
        $profile = (string) ($args['profile'] ?? '');
        if (! ObservationEvent::isIdentifier($profile)) {
            return new \WP_Error('dbvc_agency_invalid_identifier', 'profile must be a bounded ASCII identifier.', ['status' => 400]);
        }

        $from_environment = (string) ($args['from_environment_id'] ?? '');
        if ($from_environment !== '') {
            $instance_uid = (string) ($args['from_instance_uid'] ?? '');
            if (! ObservationEvent::isIdentifier($instance_uid)) {
                return new \WP_Error('dbvc_agency_invalid_identifier', 'from_instance_uid must be a bounded ASCII identifier.', ['status' => 400]);
            }
            $environment = $this->environments->find($from_environment);
            if ($environment === null) {
                return new \WP_Error('dbvc_agency_environment_not_found', 'Source environment not found.', ['status' => 404]);
            }
            if ($environment['agency_id'] !== $agency_id) {
                return new \WP_Error('dbvc_agency_scope_mismatch', 'The source environment belongs to another agency.', ['status' => 403]);
            }
            $hash = $this->observed_hash($environment, $domain, $instance_uid, $profile);
            if (is_wp_error($hash)) {
                return $hash;
            }

            return ['domain' => $domain, 'profile' => $profile, 'hash' => $hash, 'environment_id' => $environment['environment_id'], 'instance_uid' => $instance_uid];
        }

        $hash = (string) ($args['hash'] ?? '');
        if (! self::is_hash($hash)) {
            return new \WP_Error('dbvc_agency_invalid_hash', 'Supply hash (64 hex), from_environment_id + from_instance_uid, or from_version.', ['status' => 400]);
        }

        return ['domain' => $domain, 'profile' => $profile, 'hash' => $hash, 'environment_id' => '', 'instance_uid' => ''];
    }

    /**
     * The hash an environment currently reports for one object: only from a
     * complete projection in its current epoch, while contact is fresh and
     * the object exists.
     *
     * @param array<string, mixed> $environment
     * @param string               $domain
     * @param string               $instance_uid
     * @param string               $profile
     * @return string|\WP_Error
     */
    private function observed_hash(array $environment, $domain, $instance_uid, $profile)
    {
        if ($environment['status'] !== EnvironmentRegistry::STATUS_ENABLED) {
            return new \WP_Error('dbvc_agency_environment_not_enabled', 'The environment is not enabled.', ['status' => 409]);
        }
        if (! FrameworkStatusService::is_fresh($environment)) {
            return new \WP_Error('dbvc_agency_environment_stale', 'The environment\'s last contact is older than the freshness window.', ['status' => 409]);
        }
        $projection = $this->projections->get($environment['environment_id'], $environment['current_epoch'], $domain, $instance_uid, $profile);
        if ($projection === null) {
            return new \WP_Error('dbvc_agency_projection_not_found', 'No current projection for that instance and profile.', ['status' => 404]);
        }
        if ((int) $projection['snapshot_complete'] !== 1) {
            return new \WP_Error('dbvc_agency_projection_incomplete', 'The current projection is incomplete.', ['status' => 409]);
        }
        if ((int) $projection['object_exists'] !== 1) {
            return new \WP_Error('dbvc_agency_projection_absent', 'The object is reported absent on that environment.', ['status' => 409]);
        }

        return (string) $projection['semantic_hash'];
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>|\WP_Error
     */
    private function find_subscription(array $args)
    {
        $environment_id = (string) ($args['environment_id'] ?? '');
        $domain = (string) ($args['domain'] ?? '');
        $instance_uid = (string) ($args['instance_uid'] ?? '');
        $definition_uid = (string) ($args['definition_uid'] ?? '');
        foreach ($this->subscriptions->framework_subscriptions($environment_id, $definition_uid) as $subscription) {
            if ($subscription['domain'] === $domain && $subscription['instance_uid'] === $instance_uid) {
                return $subscription;
            }
        }

        return new \WP_Error('dbvc_agency_subscription_not_found', 'No framework subscription binds that instance to that definition.', ['status' => 404]);
    }

    /**
     * @param array<string, mixed> $subscription
     * @return string|null
     */
    private function profile_for(array $subscription)
    {
        if ($subscription['adopted_version'] !== '') {
            $adopted = $this->definitions->get($subscription['agency_id'], $subscription['definition_uid'], $subscription['adopted_version']);
            if ($adopted !== null) {
                return $adopted['profile'];
            }
        }
        $desired = $this->definitions->desired($subscription['agency_id'], $subscription['definition_uid'], $subscription['channel']);

        return $desired !== null ? $desired['profile'] : null;
    }
}
