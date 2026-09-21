<?php

namespace Dbvc\AgencyControl\Storage;

use Dbvc\ConnectedProtocol\Canonicalizer;

/**
 * Explicit subscriptions. Client subscriptions bind a source environment
 * to a target environment for one domain; framework subscriptions bind one
 * instance on one environment to a studio definition. Both ends must sit in
 * the same agency/client; membership is never inferred from names or wire
 * fields.
 */
final class SubscriptionStore
{
    public const TYPE_CLIENT = 'client';
    public const TYPE_FRAMEWORK = 'framework';

    /**
     * @param array<string, mixed> $source Trusted environment row.
     * @param array<string, mixed> $target Trusted environment row.
     * @param string               $domain
     * @return string created|exists|error
     */
    public function subscribe_client(array $source, array $target, $domain)
    {
        return $this->insert([
            'subscription_type' => self::TYPE_CLIENT,
            'agency_id' => $source['agency_id'],
            'client_id' => $source['client_id'],
            'source_environment_id' => $source['environment_id'],
            'target_environment_id' => $target['environment_id'],
            'domain' => (string) $domain,
        ]);
    }

    /**
     * @param array<string, mixed> $environment Trusted environment row.
     * @param string               $domain
     * @param string               $instance_uid
     * @param string               $definition_uid
     * @return string created|exists|error
     */
    public function subscribe_framework(array $environment, $domain, $instance_uid, $definition_uid, $adopted_version = '', $channel = 'stable')
    {
        return $this->insert([
            'subscription_type' => self::TYPE_FRAMEWORK,
            'agency_id' => $environment['agency_id'],
            'client_id' => $environment['client_id'],
            'source_environment_id' => $environment['environment_id'],
            'target_environment_id' => '',
            'domain' => (string) $domain,
            'instance_uid' => (string) $instance_uid,
            'definition_uid' => (string) $definition_uid,
            'adopted_version' => (string) $adopted_version,
            'channel' => (string) ($channel !== '' ? $channel : 'stable'),
        ]);
    }

    /**
     * Explicit adoption change for a framework subscription.
     *
     * @param int    $subscription_id
     * @param string $adopted_version
     * @return bool
     */
    public function set_adopted_version($subscription_id, $adopted_version)
    {
        global $wpdb;

        $updated = $wpdb->update(
            Schema::table('subscriptions'),
            ['adopted_version' => (string) $adopted_version, 'updated_at' => current_time('mysql', true)],
            ['subscription_id' => (int) $subscription_id, 'subscription_type' => self::TYPE_FRAMEWORK],
            ['%s', '%s'],
            ['%d', '%s']
        );

        return $updated === 1;
    }

    /**
     * @param int $subscription_id
     * @return array<string, mixed>|null
     */
    public function get($subscription_id)
    {
        global $wpdb;

        $table = Schema::table('subscriptions');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE subscription_id = %d", (int) $subscription_id), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return is_array($row) ? $this->normalize($row) : null;
    }

    /**
     * Enabled framework subscriptions, optionally filtered.
     *
     * @param string|null $environment_id
     * @param string|null $definition_uid
     * @return array<int, array<string, mixed>>
     */
    public function framework_subscriptions($environment_id = null, $definition_uid = null)
    {
        global $wpdb;

        $table = Schema::table('subscriptions');
        $where = ['subscription_type = %s'];
        $values = [self::TYPE_FRAMEWORK];
        if ($environment_id !== null && $environment_id !== '') {
            $where[] = 'source_environment_id = %s';
            $values[] = (string) $environment_id;
        }
        if ($definition_uid !== null && $definition_uid !== '') {
            $where[] = 'definition_uid = %s';
            $values[] = (string) $definition_uid;
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE " . implode(' AND ', $where) . ' ORDER BY subscription_id', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $values
        ), ARRAY_A);

        return array_map([$this, 'normalize'], is_array($rows) ? $rows : []);
    }

    /**
     * @param int  $subscription_id
     * @param bool $enabled
     * @return bool
     */
    public function set_enabled($subscription_id, $enabled)
    {
        global $wpdb;

        $updated = $wpdb->update(
            Schema::table('subscriptions'),
            ['enabled' => $enabled ? 1 : 0, 'updated_at' => current_time('mysql', true)],
            ['subscription_id' => (int) $subscription_id],
            ['%d', '%s'],
            ['%d']
        );

        return $updated === 1;
    }

    /**
     * @param string|null $environment_id Source environment filter.
     * @param int         $limit
     * @return array<int, array<string, mixed>>
     */
    public function all($environment_id = null, $limit = 200)
    {
        global $wpdb;

        $table = Schema::table('subscriptions');
        if ($environment_id !== null && $environment_id !== '') {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE source_environment_id = %s OR target_environment_id = %s ORDER BY subscription_id LIMIT %d", (string) $environment_id, (string) $environment_id, max(1, (int) $limit)), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        } else {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} ORDER BY subscription_id LIMIT %d", max(1, (int) $limit)), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        }

        return array_map([$this, 'normalize'], is_array($rows) ? $rows : []);
    }

    /**
     * Whether a client subscription (source → target, domain) is currently enabled.
     *
     * @return bool
     */
    public function client_subscription_enabled($source_environment_id, $target_environment_id, $domain)
    {
        global $wpdb;

        $table = Schema::table('subscriptions');
        $enabled = $wpdb->get_var($wpdb->prepare(
            "SELECT enabled FROM {$table} WHERE subscription_type = %s AND source_environment_id = %s AND target_environment_id = %s AND domain = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            self::TYPE_CLIENT,
            (string) $source_environment_id,
            (string) $target_environment_id,
            (string) $domain
        ));

        return (int) $enabled === 1;
    }

    /**
     * Trusted registry snapshot for routing one source environment's events,
     * in the shape consumed by RoutingPolicy, plus a stable revision hash.
     *
     * @param array<string, mixed> $source Trusted environment row.
     * @return array{registry: array<string, mixed>, revision: string}
     */
    public function registry_snapshot(array $source)
    {
        global $wpdb;

        $table = Schema::table('subscriptions');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE source_environment_id = %s ORDER BY subscription_id", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            (string) $source['environment_id']
        ), ARRAY_A);

        $environments = [];
        $registry_env = new EnvironmentRegistry();
        $add_env = static function (array $row) use (&$environments) {
            // A held target still accumulates pending deliveries (it cannot retrieve them until released);
            // only revocation removes it from routing.
            $environments[$row['environment_id']] = [
                'agency' => (string) $row['agency_id'],
                'client' => (string) $row['client_id'],
                'epoch' => (string) $row['current_epoch'],
                'enabled' => $row['status'] !== EnvironmentRegistry::STATUS_REVOKED,
                'revoked' => $row['status'] === EnvironmentRegistry::STATUS_REVOKED,
                'held' => $row['status'] === EnvironmentRegistry::STATUS_HELD,
            ];
        };
        $add_env($source);

        $client_subscriptions = [];
        $framework_subscriptions = [];
        foreach ((array) $rows as $row) {
            $row = $this->normalize($row);
            if ($row['subscription_type'] === self::TYPE_CLIENT) {
                if (! isset($environments[$row['target_environment_id']])) {
                    $target = $registry_env->find($row['target_environment_id']);
                    if ($target !== null) {
                        $add_env($target);
                    }
                }
                $client_subscriptions[] = [
                    'source' => $row['source_environment_id'],
                    'target' => $row['target_environment_id'],
                    'domains' => [$row['domain']],
                    'enabled' => $row['enabled'] === 1,
                ];
            } else {
                $framework_subscriptions[] = [
                    'agency' => $row['agency_id'],
                    'client' => $row['client_id'],
                    'environment' => $row['source_environment_id'],
                    'domain' => $row['domain'],
                    'instance_uid' => $row['instance_uid'],
                    'definition_uid' => $row['definition_uid'],
                    'enabled' => $row['enabled'] === 1,
                ];
            }
        }

        $registry = [
            'environments' => $environments,
            'client_subscriptions' => $client_subscriptions,
            'framework_subscriptions' => $framework_subscriptions,
        ];

        return ['registry' => $registry, 'revision' => Canonicalizer::hash(Canonicalizer::encode($registry))];
    }

    /**
     * @param array<string, mixed> $data
     * @return string
     */
    private function insert(array $data)
    {
        global $wpdb;

        $now = current_time('mysql', true);
        $suppress = $wpdb->suppress_errors();
        $inserted = $wpdb->insert(
            Schema::table('subscriptions'),
            [
                'subscription_type' => (string) $data['subscription_type'],
                'agency_id' => (string) $data['agency_id'],
                'client_id' => (string) $data['client_id'],
                'source_environment_id' => (string) $data['source_environment_id'],
                'target_environment_id' => (string) ($data['target_environment_id'] ?? ''),
                'domain' => (string) $data['domain'],
                'instance_uid' => (string) ($data['instance_uid'] ?? ''),
                'definition_uid' => (string) ($data['definition_uid'] ?? ''),
                'adopted_version' => (string) ($data['adopted_version'] ?? ''),
                'channel' => (string) ($data['channel'] ?? 'stable'),
                'enabled' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s']
        );
        $wpdb->suppress_errors($suppress);
        if ($inserted === 1) {
            return 'created';
        }

        return $wpdb->last_error !== '' && stripos($wpdb->last_error, 'duplicate') !== false ? 'exists' : 'error';
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalize(array $row)
    {
        $row['subscription_id'] = (int) $row['subscription_id'];
        $row['enabled'] = (int) $row['enabled'];

        return $row;
    }
}
