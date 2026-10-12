<?php

namespace Dbvc\AgencyControl\Routing;

/**
 * Pure routing policy for a VALIDATED event and a TRUSTED registry snapshot.
 * Adapted from the package's starter: not authentication, request validation,
 * persistence or a REST handler. The verified-operation flag is derived by the
 * hub from a matching operation receipt; it is never a request parameter.
 */
final class RoutingPolicy
{
    /**
     * @param array<string, mixed> $event                        Validated observation body.
     * @param string               $principal_environment        Authenticated source environment id.
     * @param array<string, mixed> $registry                     environments, client_subscriptions, framework_subscriptions.
     * @param bool                 $verified_expected_operation  True only when the hub matched a trusted operation receipt.
     * @return array{client_targets: array<int, string>, framework_review: array<int, string>}
     * @throws \InvalidArgumentException sender_authority_forbidden|enrollment_mismatch
     */
    public static function decide(array $event, $principal_environment, array $registry, $verified_expected_operation = false)
    {
        foreach (['agency_id', 'client_id', 'recipients', 'audience'] as $key) {
            if (array_key_exists($key, $event)) {
                throw new \InvalidArgumentException('sender_authority_forbidden');
            }
        }

        $environments = isset($registry['environments']) && is_array($registry['environments']) ? $registry['environments'] : [];
        $source = $environments[$principal_environment] ?? null;
        if (
            ! is_array($source) || empty($source['enabled']) || ! empty($source['revoked'])
            || ($event['environment_id'] ?? null) !== $principal_environment
            || ($event['installation_epoch'] ?? null) !== ($source['epoch'] ?? null)
        ) {
            throw new \InvalidArgumentException('enrollment_mismatch');
        }

        $object = isset($event['object']) && is_array($event['object']) ? $event['object'] : [];
        $domain = (string) ($object['domain'] ?? '');
        $instance_uid = (string) ($object['instance_uid'] ?? '');

        $targets = [];
        foreach ((array) ($registry['client_subscriptions'] ?? []) as $subscription) {
            $target_id = (string) ($subscription['target'] ?? '');
            $target = $environments[$target_id] ?? null;
            if (
                ! empty($subscription['enabled'])
                && ($subscription['source'] ?? null) === $principal_environment
                && is_array($target) && ! empty($target['enabled']) && empty($target['revoked'])
                && $target_id !== $principal_environment
                && ($target['agency'] ?? null) === ($source['agency'] ?? null)
                && ($target['client'] ?? null) === ($source['client'] ?? null)
                && in_array($domain, (array) ($subscription['domains'] ?? []), true)
            ) {
                $targets[] = $target_id; // Online state intentionally does not suppress a delivery.
            }
        }

        $review = [];
        if (! $verified_expected_operation) {
            foreach ((array) ($registry['framework_subscriptions'] ?? []) as $subscription) {
                if (
                    ! empty($subscription['enabled'])
                    && ($subscription['agency'] ?? null) === ($source['agency'] ?? null)
                    && ($subscription['client'] ?? null) === ($source['client'] ?? null)
                    && ($subscription['environment'] ?? null) === $principal_environment
                    && ($subscription['domain'] ?? null) === $domain
                    && ($subscription['instance_uid'] ?? null) === $instance_uid
                ) {
                    $review[] = (string) $subscription['definition_uid'];
                }
            }
        }

        $targets = array_values(array_unique($targets));
        sort($targets, SORT_STRING);
        $review = array_values(array_unique($review));
        sort($review, SORT_STRING);

        return ['client_targets' => $targets, 'framework_review' => $review];
    }
}
