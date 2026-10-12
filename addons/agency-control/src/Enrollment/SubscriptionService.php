<?php

namespace Dbvc\AgencyControl\Enrollment;

use Dbvc\AgencyControl\Storage\DefinitionStore;
use Dbvc\AgencyControl\Storage\EnvironmentRegistry;
use Dbvc\AgencyControl\Storage\SubscriptionStore;
use Dbvc\ConnectedProtocol\ObservationEvent;

/**
 * Administrator-side subscription management with scope validation. Both
 * ends of a client subscription must be enrolled in the same agency/client;
 * an environment never subscribes to itself.
 */
final class SubscriptionService
{
    /**
     * @var SubscriptionStore
     */
    private $subscriptions;

    /**
     * @var EnvironmentRegistry
     */
    private $environments;

    public function __construct()
    {
        $this->subscriptions = new SubscriptionStore();
        $this->environments = new EnvironmentRegistry();
    }

    /**
     * @param string             $source_environment_id
     * @param string             $target_environment_id
     * @param array<int, string> $domains
     * @return array<string, mixed>|\WP_Error
     */
    public function subscribe_client($source_environment_id, $target_environment_id, array $domains)
    {
        $source = $this->environments->find((string) $source_environment_id);
        $target = $this->environments->find((string) $target_environment_id);
        if ($source === null || $target === null) {
            return new \WP_Error('dbvc_agency_environment_not_found', 'Both environments must be enrolled.', ['status' => 404]);
        }
        if ($source['environment_id'] === $target['environment_id']) {
            return new \WP_Error('dbvc_agency_self_subscription', 'An environment cannot subscribe to itself.', ['status' => 400]);
        }
        if ($source['agency_id'] !== $target['agency_id'] || $source['client_id'] !== $target['client_id']) {
            return new \WP_Error('dbvc_agency_scope_mismatch', 'Client subscriptions stay within one agency and client.', ['status' => 403]);
        }
        $domains = array_values(array_unique(array_map('strval', $domains)));
        if ($domains === []) {
            $domains = ObservationEvent::DOMAINS;
        }
        $results = [];
        foreach ($domains as $domain) {
            if (! ObservationEvent::isDomain($domain)) {
                return new \WP_Error('dbvc_agency_invalid_domain', 'Unknown domain: ' . $domain, ['status' => 400]);
            }
            $results[$domain] = $this->subscriptions->subscribe_client($source, $target, $domain);
        }

        return ['source' => $source['environment_id'], 'target' => $target['environment_id'], 'domains' => $results];
    }

    /**
     * @param string $environment_id
     * @param string $domain
     * @param string $instance_uid
     * @param string $definition_uid
     * @param string $adopted_version Published version this instance is declared to follow ('' = not yet adopted).
     * @param string $channel         Definition channel whose desired version applies (default `stable`).
     * @return array<string, mixed>|\WP_Error
     */
    public function subscribe_framework($environment_id, $domain, $instance_uid, $definition_uid, $adopted_version = '', $channel = 'stable')
    {
        $environment = $this->environments->find((string) $environment_id);
        if ($environment === null) {
            return new \WP_Error('dbvc_agency_environment_not_found', 'Environment not found.', ['status' => 404]);
        }
        if (! ObservationEvent::isDomain((string) $domain)) {
            return new \WP_Error('dbvc_agency_invalid_domain', 'Unknown domain: ' . $domain, ['status' => 400]);
        }
        if (! ObservationEvent::isIdentifier($instance_uid) || ! ObservationEvent::isIdentifier($definition_uid)) {
            return new \WP_Error('dbvc_agency_invalid_identifier', 'instance_uid and definition_uid must be bounded ASCII identifiers.', ['status' => 400]);
        }
        $channel = (string) $channel !== '' ? (string) $channel : 'stable';
        if (! ObservationEvent::isIdentifier($channel)) {
            return new \WP_Error('dbvc_agency_invalid_identifier', 'channel must be a bounded ASCII identifier.', ['status' => 400]);
        }
        $adopted_version = (string) $adopted_version;
        if ($adopted_version !== '') {
            $definition = (new DefinitionStore())->get($environment['agency_id'], (string) $definition_uid, $adopted_version);
            if ($definition === null) {
                return new \WP_Error('dbvc_agency_definition_not_found', 'adopted_version must name a published version of that definition.', ['status' => 404]);
            }
            if ($definition['domain'] !== (string) $domain) {
                return new \WP_Error('dbvc_agency_domain_mismatch', 'The definition version belongs to a different domain.', ['status' => 400]);
            }
        }

        return [
            'environment' => $environment['environment_id'],
            'domain' => (string) $domain,
            'instance_uid' => (string) $instance_uid,
            'definition_uid' => (string) $definition_uid,
            'adopted_version' => $adopted_version,
            'channel' => $channel,
            'result' => $this->subscriptions->subscribe_framework($environment, (string) $domain, (string) $instance_uid, (string) $definition_uid, $adopted_version, $channel),
        ];
    }

    /**
     * @param int  $subscription_id
     * @param bool $enabled
     * @return array<string, mixed>|\WP_Error
     */
    public function set_enabled($subscription_id, $enabled)
    {
        if (! $this->subscriptions->set_enabled((int) $subscription_id, (bool) $enabled)) {
            return new \WP_Error('dbvc_agency_subscription_not_found', 'Subscription not found or unchanged.', ['status' => 404]);
        }

        return ['subscription_id' => (int) $subscription_id, 'enabled' => (bool) $enabled];
    }
}
