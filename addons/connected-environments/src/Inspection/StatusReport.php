<?php

namespace Dbvc\Connected\Inspection;

use Dbvc\Connected\Adapters\DomainRegistry;
use Dbvc\Connected\Capture\DirtyCapture;
use Dbvc\Connected\Identity\InstanceIdentity;
use Dbvc\Connected\Storage\JobStore;
use Dbvc\Connected\Storage\ObjectStore;
use Dbvc\Connected\Storage\OutboxStore;
use Dbvc\Connected\Storage\Schema;
use Dbvc\Connected\Storage\StateStore;

/**
 * Read-only developer inspection of connector state. Never returns secrets
 * (none exist yet) and never creates tables or options.
 */
final class StatusReport
{
    /**
     * @param string $gate_state RoleGate state supplied by the host.
     * @return array<string, mixed>
     */
    public static function build($gate_state)
    {
        $report = [
            'gate_state' => (string) $gate_state,
            'schema' => [
                'ready' => false,
                'version' => (int) get_option(Schema::OPTION_SCHEMA_VERSION, 0),
                'expected_version' => Schema::SCHEMA_VERSION,
                'transactional' => null,
            ],
            'identity' => null,
            'jobs' => null,
            'objects' => null,
            'outbox' => null,
            'identities' => null,
            'domains' => [],
            'scheduler' => [
                'hook' => DirtyCapture::CRON_HOOK,
                'next_run' => null,
                'processing_delay' => DirtyCapture::processing_delay(),
                'wp_cron_disabled' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
            ],
            'worker' => null,
        ];

        Schema::reset_memo();
        $report['schema']['ready'] = Schema::is_ready() && Schema::tables_exist();
        if (! $report['schema']['ready']) {
            return $report;
        }
        $report['schema']['transactional'] = Schema::is_transactional();

        $state = (new StateStore())->get();
        $report['identity'] = is_array($state) ? [
            'environment_id' => $state['environment_id'],
            'installation_epoch' => $state['installation_epoch'],
            'enrollment_state' => $state['enrollment_state'],
            'next_sequence' => $state['next_sequence'],
        ] : ['enrollment_state' => StateStore::ENROLLMENT_UNINITIALIZED];
        $report['worker'] = is_array($state) ? $state['worker_status'] : null;
        $report['connection'] = is_array($state) ? [
            'state' => $state['connection_state'],
            'hold_reason' => $state['hold_reason'],
            'hub_url' => $state['hub_url'],
            'agency_id' => $state['hub_agency_id'],
            'client_id' => $state['hub_client_id'],
            'principal' => $state['hub_principal'],
            'has_credential' => $state['has_hub_secret'],
            'enrolled_site_url' => $state['enrolled_site_url'],
            'delivery' => $state['delivery_status'],
            'next_delivery_run' => (function_exists('wp_next_scheduled') && wp_next_scheduled(DirtyCapture::DELIVERY_CRON_HOOK)) ? gmdate('c', (int) wp_next_scheduled(DirtyCapture::DELIVERY_CRON_HOOK)) : null,
            'inbox_cursor' => $state['inbox_cursor'],
            'inbox' => (new \Dbvc\Connected\Storage\InboxStore())->counts(),
            'last_poll' => $state['inbox_status'],
            'next_inbox_poll' => (function_exists('wp_next_scheduled') && wp_next_scheduled(DirtyCapture::INBOX_CRON_HOOK)) ? gmdate('c', (int) wp_next_scheduled(DirtyCapture::INBOX_CRON_HOOK)) : null,
            'release' => $state['release_status'],
            'preparations' => (new \Dbvc\Connected\Storage\PreparationStore())->counts(),
            'apply_enabled' => \DBVC_Connected_Environments_Addon::is_apply_enabled(),
            'operations' => (new \Dbvc\Connected\Storage\OperationStore())->counts(),
        ] : null;

        $report['jobs'] = (new JobStore())->counts();
        $report['objects'] = (new ObjectStore())->counts_by_domain();
        $report['outbox'] = (new OutboxStore())->counts();
        $report['identities'] = (new InstanceIdentity())->count();

        $jobs = new JobStore();
        foreach (DomainRegistry::observers() as $domain => $observer) {
            $capabilities = $observer->capabilities();
            $objects = $report['objects'][$domain] ?? ['total' => 0, 'present' => 0, 'absent' => 0, 'incomplete' => 0];
            $pending = $jobs->find($domain, DomainRegistry::COLLECTION_KEY);
            $report['domains'][$domain] = [
                'profile' => $capabilities['profile'],
                'source_option' => $capabilities['source_option'],
                'coverage' => $capabilities['report'] ? 'available' : 'unavailable',
                'reason' => $capabilities['reason'],
                // never_observed | pending | observed: an empty object set is not a clean claim.
                'observation' => is_array($pending) ? 'pending' : ($objects['total'] > 0 ? 'observed' : 'never_observed'),
                'objects' => $objects,
            ];
        }

        $next = function_exists('wp_next_scheduled') ? wp_next_scheduled(DirtyCapture::CRON_HOOK) : false;
        $report['scheduler']['next_run'] = $next ? gmdate('c', (int) $next) : null;

        return $report;
    }
}
