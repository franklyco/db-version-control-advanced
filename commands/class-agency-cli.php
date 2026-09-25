<?php

if (! defined('WPINC')) {
	die;
}

if (! class_exists('DBVC_Agency_CLI_Inspector')) {
	/**
	 * Hub (Agency Control) administration and read-only inspection. Testable
	 * without WP-CLI. Secrets (invitation tokens) appear only in the invite
	 * result, once.
	 */
	final class DBVC_Agency_CLI_Inspector {
		private const DEFAULT_LIMIT = 50;
		private const MAX_LIMIT = 500;

		/**
		 * @return array|WP_Error
		 */
		public static function status() {
			if (! class_exists('DBVC_Agency_Control_Addon')) {
				return new WP_Error('dbvc_agency_cli_unavailable', 'The Agency Control add-on is unavailable in this checkout.');
			}
			$gate = DBVC_Agency_Control_Addon::get_gate_state();
			$report = ['gate_state' => $gate, 'runtime' => DBVC_Agency_Control_Addon::get_runtime_state()];
			if ($gate !== \Dbvc\ConnectedProtocol\RoleGate::READY) {
				return $report;
			}
			$report['schema'] = [
				'ready' => true,
				'version' => (int) get_option(\Dbvc\AgencyControl\Storage\Schema::OPTION_SCHEMA_VERSION, 0),
				'transactional' => \Dbvc\AgencyControl\Storage\Schema::is_transactional(),
			];
			$report['protocol'] = \Dbvc\ConnectedProtocol\Protocol::describe();
			$report['environments'] = array_map([self::class, 'environment_row'], (new \Dbvc\AgencyControl\Storage\EnvironmentRegistry())->all());
			$report['events'] = (new \Dbvc\AgencyControl\Storage\EventStore())->counts();
			$report['sequences'] = (new \Dbvc\AgencyControl\Storage\EventStore())->sequence_summary();
			$report['deliveries'] = (new \Dbvc\AgencyControl\Storage\DeliveryStore())->summary();
			$report['reviews'] = (new \Dbvc\AgencyControl\Storage\ReviewStore())->counts();
			$report['baselines'] = (new \Dbvc\AgencyControl\Storage\BaselineStore())->count();
			$report['definitions'] = (new \Dbvc\AgencyControl\Storage\DefinitionStore())->count();
			$report['overrides'] = (new \Dbvc\AgencyControl\Storage\OverrideStore())->count();
			$report['releases'] = (new \Dbvc\AgencyControl\Storage\ReleaseStore())->counts();
			$report['preparations'] = (new \Dbvc\AgencyControl\Storage\PreparationStore())->counts();
			$report['approvals'] = (new \Dbvc\AgencyControl\Storage\ApprovalStore())->counts();
			$report['rollouts'] = (new \Dbvc\AgencyControl\Storage\RolloutStore())->counts();
			$report['freshness_seconds'] = \Dbvc\AgencyControl\Comparison\ComparisonService::freshness_seconds();

			return $report;
		}

		/**
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		public static function invite(array $assoc_args) {
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}

			return (new \Dbvc\AgencyControl\Enrollment\EnrollmentService())->invite([
				'agency_id' => (string) ($assoc_args['agency'] ?? 'studio'),
				'client_id' => (string) ($assoc_args['client'] ?? ''),
				'environment_label' => (string) ($assoc_args['label'] ?? ''),
				'environment_id' => (string) ($assoc_args['environment'] ?? ''),
				'ttl_seconds' => (int) ($assoc_args['ttl'] ?? \Dbvc\AgencyControl\Storage\InvitationStore::DEFAULT_TTL_SECONDS),
			]);
		}

		/**
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		public static function environments(array $assoc_args) {
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}
			$rows = (new \Dbvc\AgencyControl\Storage\EnvironmentRegistry())->all(
				isset($assoc_args['client']) ? (string) $assoc_args['client'] : null,
				self::bounded_integer($assoc_args['limit'] ?? self::DEFAULT_LIMIT, 1, self::MAX_LIMIT)
			);

			return ['returned' => count($rows), 'environments' => array_map([self::class, 'environment_row'], $rows)];
		}

		/**
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		public static function events(array $assoc_args) {
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}
			$rows = (new \Dbvc\AgencyControl\Storage\EventStore())->all(
				isset($assoc_args['environment']) ? (string) $assoc_args['environment'] : null,
				self::bounded_integer($assoc_args['limit'] ?? self::DEFAULT_LIMIT, 1, self::MAX_LIMIT),
				isset($assoc_args['instance']) ? (string) $assoc_args['instance'] : null
			);

			return [
				'counts' => (new \Dbvc\AgencyControl\Storage\EventStore())->counts(),
				'returned' => count($rows),
				'events' => array_map(static function ($row) {
					$body = is_array($row['body']) ? $row['body'] : [];
					return [
						'environment_id' => (string) $row['environment_id'],
						'installation_epoch' => (string) $row['installation_epoch'],
						'event_id' => (string) $row['event_id'],
						'sequence' => (int) $row['source_sequence'],
						'domain' => (string) $row['domain'],
						'instance_uid' => (string) $row['instance_uid'],
						'profile' => (string) $row['profile'],
						'exists' => isset($body['projection']['exists']) ? ($body['projection']['exists'] ? 'yes' : 'no') : '',
						'hash' => (string) ($body['projection']['hash'] ?? ''),
						'origin' => (string) ($body['origin'] ?? ''),
						'routing_state' => (string) $row['routing_state'],
						'batch_id' => (string) $row['batch_id'],
						'received_at' => (string) $row['received_at'],
					];
				}, $rows),
			];
		}

		/**
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		public static function projections(array $assoc_args) {
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}
			$rows = (new \Dbvc\AgencyControl\Storage\ProjectionStore())->all(
				isset($assoc_args['environment']) ? (string) $assoc_args['environment'] : null,
				self::bounded_integer($assoc_args['limit'] ?? self::DEFAULT_LIMIT, 1, self::MAX_LIMIT),
				isset($assoc_args['instance']) ? (string) $assoc_args['instance'] : null
			);

			return [
				'returned' => count($rows),
				'projections' => array_map(static function ($row) {
					return [
						'environment_id' => (string) $row['environment_id'],
						'installation_epoch' => (string) $row['installation_epoch'],
						'domain' => (string) $row['domain'],
						'instance_uid' => (string) $row['instance_uid'],
						'profile' => (string) $row['profile'],
						'exists' => (int) $row['object_exists'] === 1 ? 'yes' : 'no',
						'complete' => (int) $row['snapshot_complete'] === 1 ? 'yes' : 'no',
						'hash' => (string) $row['semantic_hash'],
						'observed_sequence' => (int) $row['observed_sequence'],
						'observed_at' => (string) $row['observed_at'],
					];
				}, $rows),
			];
		}

		/**
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		public static function subscribe(array $assoc_args) {
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}
			$domains = array_values(array_filter(array_map('trim', explode(',', (string) ($assoc_args['domains'] ?? '')))));

			return (new \Dbvc\AgencyControl\Enrollment\SubscriptionService())->subscribe_client((string) ($assoc_args['source'] ?? ''), (string) ($assoc_args['target'] ?? ''), $domains);
		}

		/**
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		public static function subscribe_framework(array $assoc_args) {
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}

			return (new \Dbvc\AgencyControl\Enrollment\SubscriptionService())->subscribe_framework(
				(string) ($assoc_args['environment'] ?? ''),
				(string) ($assoc_args['domain'] ?? ''),
				(string) ($assoc_args['instance'] ?? ''),
				(string) ($assoc_args['definition'] ?? ''),
				(string) ($assoc_args['adopted-version'] ?? ''),
				(string) ($assoc_args['channel'] ?? 'stable')
			);
		}

		/**
		 * @param array $assoc_args
		 * @param bool $enabled
		 * @return array|WP_Error
		 */
		public static function set_subscription_enabled(array $assoc_args, $enabled) {
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}

			return (new \Dbvc\AgencyControl\Enrollment\SubscriptionService())->set_enabled((int) ($assoc_args['id'] ?? 0), (bool) $enabled);
		}

		/**
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		public static function subscriptions(array $assoc_args) {
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}
			$rows = (new \Dbvc\AgencyControl\Storage\SubscriptionStore())->all(
				isset($assoc_args['environment']) ? (string) $assoc_args['environment'] : null,
				self::bounded_integer($assoc_args['limit'] ?? self::DEFAULT_LIMIT, 1, self::MAX_LIMIT)
			);

			return [
				'returned' => count($rows),
				'subscriptions' => array_map(static function ($row) {
					return [
						'subscription_id' => (int) $row['subscription_id'],
						'type' => (string) $row['subscription_type'],
						'client_id' => (string) $row['client_id'],
						'source' => (string) $row['source_environment_id'],
						'target' => (string) $row['target_environment_id'],
						'domain' => (string) $row['domain'],
						'instance_uid' => (string) $row['instance_uid'],
						'definition_uid' => (string) $row['definition_uid'],
						'adopted_version' => (string) ($row['adopted_version'] ?? ''),
						'channel' => (string) ($row['channel'] ?? ''),
						'enabled' => (int) $row['enabled'] === 1 ? 'yes' : 'no',
					];
				}, $rows),
			];
		}

		/**
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		public static function route(array $assoc_args) {
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}

			return (new \Dbvc\AgencyControl\Routing\RoutingWorker())->run(self::bounded_integer($assoc_args['limit'] ?? \Dbvc\AgencyControl\Routing\RoutingWorker::DEFAULT_LIMIT, 1, 1000));
		}

		/**
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		public static function deliveries(array $assoc_args) {
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}
			$store = new \Dbvc\AgencyControl\Storage\DeliveryStore();
			$rows = $store->all(isset($assoc_args['target']) ? (string) $assoc_args['target'] : null, self::bounded_integer($assoc_args['limit'] ?? self::DEFAULT_LIMIT, 1, self::MAX_LIMIT));

			return [
				'summary' => $store->summary(),
				'returned' => count($rows),
				'deliveries' => array_map(static function ($row) {
					return [
						'delivery_id' => (int) $row['delivery_id'],
						'event_row_id' => (int) $row['event_row_id'],
						'source' => (string) $row['source_environment_id'],
						'target' => (string) $row['target_environment_id'],
						'domain' => (string) $row['domain'],
						'state' => (string) $row['state'],
						'cancelled_reason' => (string) $row['cancelled_reason'],
						'policy_revision' => substr((string) $row['policy_revision'], 0, 12),
						'created_at' => (string) $row['created_at'],
						'acked_at' => (string) ($row['acked_at'] ?? ''),
					];
				}, $rows),
			];
		}

		/**
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		public static function reviews(array $assoc_args) {
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}
			$store = new \Dbvc\AgencyControl\Storage\ReviewStore();
			$rows = $store->all(
				isset($assoc_args['definition']) ? (string) $assoc_args['definition'] : null,
				self::bounded_integer($assoc_args['limit'] ?? self::DEFAULT_LIMIT, 1, self::MAX_LIMIT),
				isset($assoc_args['state']) ? sanitize_key((string) $assoc_args['state']) : null,
				isset($assoc_args['environment']) ? (string) $assoc_args['environment'] : null
			);

			return [
				'counts' => $store->counts(),
				'returned' => count($rows),
				'reviews' => array_map(static function ($row) {
					return [
						'review_item_id' => (int) $row['review_item_id'],
						'event_row_id' => (int) $row['event_row_id'],
						'event_sequence' => (int) $row['event_sequence'],
						'client_id' => (string) $row['client_id'],
						'environment_id' => (string) $row['environment_id'],
						'domain' => (string) $row['domain'],
						'instance_uid' => (string) $row['instance_uid'],
						'definition_uid' => (string) $row['definition_uid'],
						'state' => (string) $row['state'],
						'drift' => (string) $row['drift'],
						'version' => (string) $row['version_state'],
						'resolution' => (string) $row['resolution'],
						'note' => (string) $row['note'],
						'created_at' => (string) $row['created_at'],
						'classified_at' => (string) ($row['classified_at'] ?? ''),
						'resolved_at' => (string) ($row['resolved_at'] ?? ''),
					];
				}, $rows),
			];
		}

		/**
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		public static function review_classify(array $assoc_args) {
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}

			return (new \Dbvc\AgencyControl\Framework\ReviewResolutionService())->classify(
				isset($assoc_args['environment']) ? (string) $assoc_args['environment'] : null,
				isset($assoc_args['definition']) ? (string) $assoc_args['definition'] : null
			);
		}

		/**
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		public static function review_resolve(array $assoc_args) {
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}

			return (new \Dbvc\AgencyControl\Framework\ReviewResolutionService())->resolve((int) ($assoc_args['id'] ?? 0), (string) ($assoc_args['note'] ?? ''));
		}

		/**
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		public static function compare(array $assoc_args) {
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}
			$result = (new \Dbvc\AgencyControl\Comparison\ComparisonService())->compare((string) ($assoc_args['source'] ?? ''), (string) ($assoc_args['target'] ?? ''), isset($assoc_args['domain']) ? (string) $assoc_args['domain'] : null);
			if (is_wp_error($result)) {
				return $result;
			}
			$state = sanitize_key((string) ($assoc_args['state'] ?? ''));
			if ($state !== '') {
				$result['rows'] = array_values(array_filter($result['rows'], static function ($row) use ($state) {
					return $row['state'] === $state;
				}));
			}
			$result['returned'] = count($result['rows']);
			$result['rows'] = array_map(static function ($row) {
				$row['reasons'] = implode(',', (array) $row['reasons']);
				$row['source_hash'] = $row['source_hash'] === null ? '' : substr($row['source_hash'], 0, 12);
				$row['target_hash'] = $row['target_hash'] === null ? '' : substr($row['target_hash'], 0, 12);
				$row['baseline_hash'] = $row['baseline_hash'] === null ? '' : substr($row['baseline_hash'], 0, 12);
				$row['complete'] = $row['complete'] ? 'yes' : 'no';
				$row['fresh'] = $row['fresh'] ? 'yes' : 'no';
				$row['source_observed'] = $row['source_observed'] ? 'yes' : 'no';
				$row['target_observed'] = $row['target_observed'] ? 'yes' : 'no';
				$row['source_exists'] = $row['source_exists'] === null ? '' : ($row['source_exists'] ? 'yes' : 'no');
				$row['target_exists'] = $row['target_exists'] === null ? '' : ($row['target_exists'] ? 'yes' : 'no');
				return $row;
			}, $result['rows']);

			return $result;
		}

		/**
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		public static function baseline_confirm(array $assoc_args) {
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}

			return (new \Dbvc\AgencyControl\Comparison\BaselineService())->confirm(
				(string) ($assoc_args['source'] ?? ''),
				(string) ($assoc_args['target'] ?? ''),
				isset($assoc_args['domain']) ? (string) $assoc_args['domain'] : null,
				isset($assoc_args['instance']) ? (string) $assoc_args['instance'] : null,
				(string) ($assoc_args['note'] ?? ''),
				! empty($assoc_args['accept-absent'])
			);
		}

		/**
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		public static function baselines(array $assoc_args) {
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}
			$rows = (new \Dbvc\AgencyControl\Storage\BaselineStore())->all(self::bounded_integer($assoc_args['limit'] ?? self::DEFAULT_LIMIT, 1, self::MAX_LIMIT), isset($assoc_args['instance']) ? (string) $assoc_args['instance'] : null);

			return [
				'returned' => count($rows),
				'baselines' => array_map(static function ($row) {
					return [
						'baseline_id' => (int) $row['baseline_id'],
						'source' => (string) $row['source_environment_id'],
						'target' => (string) $row['target_environment_id'],
						'domain' => (string) $row['domain'],
						'source_instance_uid' => (string) $row['source_instance_uid'],
						'target_instance_uid' => (string) $row['target_instance_uid'],
						'profile' => (string) $row['profile'],
						'baseline_hash' => substr((string) $row['baseline_hash'], 0, 12),
						'source_sequence' => (int) $row['source_sequence'],
						'target_sequence' => (int) $row['target_sequence'],
						'confirmed_at' => (string) $row['confirmed_at'],
						'confirmed_by' => (int) $row['confirmed_by'],
						'note' => (string) $row['note'],
					];
				}, $rows),
			];
		}

		/**
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		public static function link_instance(array $assoc_args) {
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}

			return (new \Dbvc\AgencyControl\Comparison\BaselineService())->link([
				'domain' => (string) ($assoc_args['domain'] ?? ''),
				'source_environment_id' => (string) ($assoc_args['source'] ?? ''),
				'source_instance_uid' => (string) ($assoc_args['source-instance'] ?? ''),
				'target_environment_id' => (string) ($assoc_args['target'] ?? ''),
				'target_instance_uid' => (string) ($assoc_args['target-instance'] ?? ''),
				'note' => (string) ($assoc_args['note'] ?? ''),
			]);
		}

		/**
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		public static function unlink_instance(array $assoc_args) {
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}
			$deleted = (new \Dbvc\AgencyControl\Storage\InstanceLinkStore())->delete((int) ($assoc_args['id'] ?? 0));

			return $deleted ? ['link_id' => (int) $assoc_args['id'], 'deleted' => true] : new WP_Error('dbvc_agency_link_not_found', 'Link not found.');
		}

		/**
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		public static function links(array $assoc_args) {
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}
			$rows = (new \Dbvc\AgencyControl\Storage\InstanceLinkStore())->all(self::bounded_integer($assoc_args['limit'] ?? self::DEFAULT_LIMIT, 1, self::MAX_LIMIT));

			return ['returned' => count($rows), 'links' => $rows];
		}

		/**
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		public static function definition_publish(array $assoc_args) {
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}

			return (new \Dbvc\AgencyControl\Framework\FrameworkService())->publish([
				'agency_id' => (string) ($assoc_args['agency'] ?? 'studio'),
				'definition_uid' => (string) ($assoc_args['definition'] ?? ''),
				'version' => (string) ($assoc_args['version'] ?? ''),
				'channel' => (string) ($assoc_args['channel'] ?? 'stable'),
				'domain' => (string) ($assoc_args['domain'] ?? ''),
				'profile' => (string) ($assoc_args['profile'] ?? ''),
				'hash' => (string) ($assoc_args['hash'] ?? ''),
				'from_environment_id' => (string) ($assoc_args['from-environment'] ?? ''),
				'from_instance_uid' => (string) ($assoc_args['from-instance'] ?? ''),
				'from_version' => (string) ($assoc_args['from-version'] ?? ''),
				'version_order' => isset($assoc_args['order']) ? (int) $assoc_args['order'] : null,
				'desired' => ! empty($assoc_args['desired']),
				'note' => (string) ($assoc_args['note'] ?? ''),
			]);
		}

		/**
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		public static function definition_desire(array $assoc_args) {
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}

			return (new \Dbvc\AgencyControl\Framework\FrameworkService())->set_desired((string) ($assoc_args['agency'] ?? 'studio'), (string) ($assoc_args['definition'] ?? ''), (string) ($assoc_args['version'] ?? ''));
		}

		/**
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		public static function definitions(array $assoc_args) {
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}
			$rows = (new \Dbvc\AgencyControl\Storage\DefinitionStore())->all(
				isset($assoc_args['definition']) ? (string) $assoc_args['definition'] : null,
				self::bounded_integer($assoc_args['limit'] ?? self::DEFAULT_LIMIT, 1, self::MAX_LIMIT)
			);

			return [
				'returned' => count($rows),
				'definitions' => array_map(static function ($row) {
					return [
						'agency_id' => (string) $row['agency_id'],
						'definition_uid' => (string) $row['definition_uid'],
						'version' => (string) $row['version'],
						'order' => (int) $row['version_order'],
						'channel' => (string) $row['channel'],
						'desired' => (int) $row['desired'] === 1 ? 'yes' : 'no',
						'domain' => (string) $row['domain'],
						'profile' => (string) $row['profile'],
						'hash' => substr((string) $row['definition_hash'], 0, 12),
						'source' => $row['source_environment_id'] !== '' ? $row['source_environment_id'] . ':' . $row['source_instance_uid'] : '',
						'published_at' => (string) $row['published_at'],
						'published_by' => (int) $row['published_by'],
						'note' => (string) $row['note'],
					];
				}, $rows),
			];
		}

		/**
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		public static function adopt_version(array $assoc_args) {
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}

			return (new \Dbvc\AgencyControl\Framework\FrameworkService())->adopt_version((int) ($assoc_args['id'] ?? 0), (string) ($assoc_args['version'] ?? ''));
		}

		/**
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		public static function override_approve(array $assoc_args) {
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}

			return (new \Dbvc\AgencyControl\Framework\FrameworkService())->approve_override([
				'environment_id' => (string) ($assoc_args['environment'] ?? ''),
				'domain' => (string) ($assoc_args['domain'] ?? ''),
				'instance_uid' => (string) ($assoc_args['instance'] ?? ''),
				'definition_uid' => (string) ($assoc_args['definition'] ?? ''),
				'hash' => (string) ($assoc_args['hash'] ?? ''),
				'rationale' => (string) ($assoc_args['rationale'] ?? ''),
			]);
		}

		/**
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		public static function override_detach(array $assoc_args) {
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}

			return (new \Dbvc\AgencyControl\Framework\FrameworkService())->detach_override([
				'environment_id' => (string) ($assoc_args['environment'] ?? ''),
				'domain' => (string) ($assoc_args['domain'] ?? ''),
				'instance_uid' => (string) ($assoc_args['instance'] ?? ''),
				'definition_uid' => (string) ($assoc_args['definition'] ?? ''),
			]);
		}

		/**
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		public static function overrides(array $assoc_args) {
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}
			$rows = (new \Dbvc\AgencyControl\Storage\OverrideStore())->all(self::bounded_integer($assoc_args['limit'] ?? self::DEFAULT_LIMIT, 1, self::MAX_LIMIT));

			return [
				'returned' => count($rows),
				'overrides' => array_map(static function ($row) {
					return [
						'override_id' => (int) $row['override_id'],
						'environment_id' => (string) $row['environment_id'],
						'domain' => (string) $row['domain'],
						'instance_uid' => (string) $row['instance_uid'],
						'definition_uid' => (string) $row['definition_uid'],
						'definition_version' => (string) $row['definition_version'],
						'approved_hash' => substr((string) $row['approved_hash'], 0, 12),
						'policy_revision' => substr((string) $row['policy_revision'], 0, 12),
						'state' => (string) $row['state'],
						'approved_by' => (int) $row['approved_by'],
						'approved_at' => (string) $row['approved_at'],
						'updated_at' => (string) $row['updated_at'],
						'rationale' => (string) $row['rationale'],
					];
				}, $rows),
			];
		}

		/**
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		public static function framework_status(array $assoc_args) {
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}
			$result = (new \Dbvc\AgencyControl\Framework\FrameworkStatusService())->status(
				isset($assoc_args['environment']) ? (string) $assoc_args['environment'] : null,
				isset($assoc_args['definition']) ? (string) $assoc_args['definition'] : null
			);
			$drift = sanitize_key((string) ($assoc_args['drift'] ?? ''));
			if ($drift !== '') {
				$result['rows'] = array_values(array_filter($result['rows'], static function ($row) use ($drift) {
					return $row['drift'] === $drift;
				}));
			}
			$result['returned'] = count($result['rows']);
			$result['rows'] = array_map(static function ($row) {
				$row['reasons'] = implode(',', (array) $row['reasons']);
				foreach (['actual_hash', 'adopted_hash', 'desired_hash', 'override_hash'] as $key) {
					$row[$key] = $row[$key] === null ? '' : substr($row[$key], 0, 12);
				}
				$row['override_state'] = (string) $row['override_state'];
				$row['override_policy_revision'] = $row['override_policy_revision'] === null ? '' : substr($row['override_policy_revision'], 0, 12);
				$row['desired_version'] = (string) $row['desired_version'];
				$row['profile'] = (string) $row['profile'];
				$row['exists'] = $row['exists'] === null ? '' : ($row['exists'] ? 'yes' : 'no');
				$row['complete'] = $row['complete'] ? 'yes' : 'no';
				$row['fresh'] = $row['fresh'] ? 'yes' : 'no';
				return $row;
			}, $result['rows']);

			return $result;
		}

		/**
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		public static function release_create(array $assoc_args) {
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}
			$selections = [];
			foreach (['items' => 'replace', 'delete' => 'delete'] as $key => $operation) {
				foreach (array_filter(array_map('trim', explode(',', (string) ($assoc_args[$key] ?? '')))) as $spec) {
					$parts = explode(':', $spec, 2);
					if (count($parts) !== 2) {
						return new WP_Error('dbvc_agency_invalid_selection', 'Each item is domain:instance_uid, for example bricks.global_class:bricks-global-class-abcd1234.');
					}
					$selections[] = ['domain' => $parts[0], 'instance_uid' => $parts[1], 'operation' => $operation];
				}
			}
			$result = (new \Dbvc\AgencyControl\Release\ReleaseService())->create((string) ($assoc_args['source'] ?? ''), $selections, (string) ($assoc_args['note'] ?? ''));
			if (is_wp_error($result)) {
				return $result;
			}

			return ['release' => self::release_row($result['release']), 'items' => array_map([self::class, 'release_item_row'], $result['items'])];
		}

		/**
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		public static function release_withdraw(array $assoc_args) {
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}

			return (new \Dbvc\AgencyControl\Release\ReleaseService())->withdraw((string) ($assoc_args['release'] ?? ''));
		}

		/**
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		public static function releases(array $assoc_args) {
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}
			$store = new \Dbvc\AgencyControl\Storage\ReleaseStore();
			if (! empty($assoc_args['release'])) {
				$release = $store->find((string) $assoc_args['release']);
				if ($release === null) {
					return new WP_Error('dbvc_agency_release_not_found', 'Release not found.');
				}

				return ['release' => self::release_row($release), 'items' => array_map([self::class, 'release_item_row'], $store->items($release['release_id']))];
			}
			$rows = $store->all(isset($assoc_args['source']) ? (string) $assoc_args['source'] : null, self::bounded_integer($assoc_args['limit'] ?? self::DEFAULT_LIMIT, 1, self::MAX_LIMIT));

			return ['counts' => $store->counts(), 'returned' => count($rows), 'releases' => array_map([self::class, 'release_row'], $rows)];
		}

		/**
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		public static function prepare_request(array $assoc_args) {
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}
			$result = (new \Dbvc\AgencyControl\Release\PreparationService())->request((string) ($assoc_args['release'] ?? ''), (string) ($assoc_args['target'] ?? ''));

			return is_wp_error($result) ? $result : self::preparation_row($result, true);
		}

		/**
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		public static function preparations(array $assoc_args) {
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}
			$store = new \Dbvc\AgencyControl\Storage\PreparationStore();
			if (! empty($assoc_args['operation'])) {
				$row = $store->find((string) $assoc_args['operation']);

				return $row === null ? new WP_Error('dbvc_agency_preparation_not_found', 'Preparation not found.') : self::preparation_row($row, true);
			}
			$rows = $store->all(isset($assoc_args['release']) ? (string) $assoc_args['release'] : null, isset($assoc_args['target']) ? (string) $assoc_args['target'] : null, self::bounded_integer($assoc_args['limit'] ?? self::DEFAULT_LIMIT, 1, self::MAX_LIMIT));

			return ['counts' => $store->counts(), 'returned' => count($rows), 'preparations' => array_map(static function ($row) {
				return self::preparation_row($row, false);
			}, $rows)];
		}

		/**
		 * @param array $release
		 * @return array
		 */
		private static function release_row(array $release) {
			return [
				'release_uid' => (string) $release['release_uid'],
				'client_id' => (string) $release['client_id'],
				'source' => (string) $release['source_environment_id'],
				'source_epoch' => (string) $release['source_epoch'],
				'state' => (string) $release['state'],
				'items' => (int) $release['item_count'],
				'digest' => substr((string) $release['digest'], 0, 12),
				'note' => (string) $release['note'],
				'created_at' => (string) $release['created_at'],
				'sealed_at' => (string) ($release['sealed_at'] ?? ''),
			];
		}

		/**
		 * @param array $item
		 * @return array
		 */
		private static function release_item_row(array $item) {
			return [
				'domain' => (string) $item['domain'],
				'instance_uid' => (string) $item['instance_uid'],
				'profile' => (string) $item['profile'],
				'operation' => (string) ($item['operation'] ?? 'replace'),
				'after_hash' => substr((string) $item['after_hash'], 0, 12),
				'source_sequence' => (int) $item['source_sequence'],
				'payload' => (string) $item['payload_state'] . ((string) $item['payload_reason'] !== '' ? ' (' . (string) $item['payload_reason'] . ')' : ''),
			];
		}

		/**
		 * @param array $row
		 * @param bool $with_receipt
		 * @return array
		 */
		private static function preparation_row(array $row, $with_receipt) {
			$out = [
				'operation_id' => (string) $row['operation_id'],
				'release_uid' => (string) $row['release_uid'],
				'target' => (string) $row['target_environment_id'],
				'target_epoch' => (string) $row['target_epoch'],
				'state' => (string) $row['state'],
				'outcome' => (string) $row['outcome'],
				'ready' => (int) $row['items_ready'],
				'noop' => (int) $row['items_noop'],
				'blocked' => (int) $row['items_blocked'],
				'requested_at' => (string) $row['requested_at'],
				'received_at' => (string) ($row['received_at'] ?? ''),
				'expires_at' => (string) ($row['expires_at'] ?? ''),
				'receipt_digest' => substr((string) $row['receipt_digest'], 0, 12),
			];
			if ($with_receipt) {
				$out['receipt'] = $row['receipt'];
				$out['hub_notes'] = $row['hub_notes'];
			}

			return $out;
		}

		/**
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		public static function approve(array $assoc_args) {
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}
			$result = (new \Dbvc\AgencyControl\Release\ApprovalService())->approve((string) ($assoc_args['operation'] ?? ''), (string) ($assoc_args['note'] ?? ''));

			return is_wp_error($result) ? $result : self::approval_row($result, false);
		}

		public static function rollback(array $assoc_args) {
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}
			$result = (new \Dbvc\AgencyControl\Release\ApprovalService())->rollback((string) ($assoc_args['operation'] ?? ''), (string) ($assoc_args['note'] ?? ''));

			return is_wp_error($result) ? $result : self::approval_row($result, false);
		}

		/**
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		public static function revoke_approval(array $assoc_args) {
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}

			return (new \Dbvc\AgencyControl\Release\ApprovalService())->revoke((string) ($assoc_args['approval'] ?? ''), (string) ($assoc_args['reason'] ?? 'revoked'));
		}

		/**
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		public static function approvals(array $assoc_args) {
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}
			$store = new \Dbvc\AgencyControl\Storage\ApprovalStore();
			if (! empty($assoc_args['approval'])) {
				$row = $store->find((string) $assoc_args['approval']);

				return $row === null ? new WP_Error('dbvc_agency_approval_not_found', 'Approval not found.') : self::approval_row($row, true);
			}
			$rows = $store->all(isset($assoc_args['target']) ? (string) $assoc_args['target'] : null, self::bounded_integer($assoc_args['limit'] ?? self::DEFAULT_LIMIT, 1, self::MAX_LIMIT));

			return ['counts' => $store->counts(), 'returned' => count($rows), 'approvals' => array_map(static function ($row) {
				return self::approval_row($row, false);
			}, $rows)];
		}

		/**
		 * @param array $row
		 * @param bool $with_receipt
		 * @return array
		 */
		private static function approval_row(array $row, $with_receipt) {
			$out = [
				'approval_uid' => (string) $row['approval_uid'],
				'operation_id' => (string) $row['operation_id'],
				'kind' => (string) ($row['kind'] ?? 'apply'),
				'rolls_back_operation_id' => (string) ($row['rolls_back_operation_id'] ?? ''),
				'release_uid' => (string) $row['release_uid'],
				'target' => (string) $row['target_environment_id'],
				'state' => (string) $row['state'],
				'release_digest' => substr((string) $row['release_digest'], 0, 12),
				'receipt_digest' => substr((string) $row['receipt_digest'], 0, 12),
				'policy_revision' => substr((string) $row['policy_revision'], 0, 12),
				'approved_at' => (string) $row['approved_at'],
				'expires_at' => (string) $row['expires_at'],
				'execution_outcome' => (string) $row['execution_outcome'],
				'executed_at' => (string) ($row['executed_at'] ?? ''),
				'note' => (string) $row['note'],
			];
			if ($with_receipt) {
				$out['execution_receipt'] = $row['execution_receipt'];
			}

			return $out;
		}

		/**
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		public static function revoke(array $assoc_args) {
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}

			return (new \Dbvc\AgencyControl\Enrollment\EnrollmentService())->revoke((string) ($assoc_args['environment'] ?? ''));
		}

		/**
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		public static function release(array $assoc_args) {
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}

			return (new \Dbvc\AgencyControl\Enrollment\EnrollmentService())->release((string) ($assoc_args['environment'] ?? ''));
		}

		/**
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		public static function hold(array $assoc_args) {
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}

			return (new \Dbvc\AgencyControl\Enrollment\EnrollmentService())->hold((string) ($assoc_args['environment'] ?? ''), (string) ($assoc_args['reason'] ?? ''));
		}

		/**
		 * Invitations without their tokens (the hub stores only hashes): open, consumed and expired.
		 *
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		public static function invitations(array $assoc_args) {
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}
			$rows = (new \Dbvc\AgencyControl\Storage\InvitationStore())->all(self::bounded_integer($assoc_args['limit'] ?? self::DEFAULT_LIMIT, 1, self::MAX_LIMIT));
			$now = time();
			$invitations = [];
			foreach ($rows as $row) {
				$state = 'open';
				if ($row['consumed_at'] !== null && $row['consumed_at'] !== '') {
					$state = 'consumed';
				} elseif (strtotime((string) $row['expires_at'] . ' UTC') < $now) {
					$state = 'expired';
				}
				if (! empty($assoc_args['open']) && $state !== 'open') {
					continue;
				}
				$invitations[] = [
					'invitation_id' => (int) $row['invitation_id'],
					'agency_id' => (string) $row['agency_id'],
					'client_id' => (string) $row['client_id'],
					'environment_label' => (string) $row['environment_label'],
					'environment_id' => (string) $row['environment_id'],
					'state' => $state,
					'expires_at' => (string) $row['expires_at'],
					'consumed_at' => (string) ($row['consumed_at'] ?? ''),
					'consumed_environment_id' => (string) ($row['consumed_environment_id'] ?? ''),
					'created_at' => (string) $row['created_at'],
				];
			}

			return ['returned' => count($invitations), 'invitations' => $invitations];
		}

		/**
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		public static function rollout_create(array $assoc_args) {
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}
			$cohorts = self::parse_cohorts($assoc_args['cohorts'] ?? '');
			if ($cohorts === []) {
				return new WP_Error('dbvc_agency_rollout_no_cohorts', 'Provide --cohorts as semicolon-separated cohorts of comma-separated environment ids, canary first (e.g. env-canary;env-a,env-b).');
			}

			return self::rollout_status((new \Dbvc\AgencyControl\Release\RolloutService())->create((string) ($assoc_args['release'] ?? ''), $cohorts, (string) ($assoc_args['note'] ?? '')));
		}

		/**
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		public static function rollout_advance(array $assoc_args) {
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}

			return self::rollout_status((new \Dbvc\AgencyControl\Release\RolloutService())->advance((string) ($assoc_args['rollout'] ?? '')));
		}

		/**
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		public static function rollout_pause(array $assoc_args) {
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}

			return self::rollout_status((new \Dbvc\AgencyControl\Release\RolloutService())->pause((string) ($assoc_args['rollout'] ?? ''), (string) ($assoc_args['reason'] ?? 'operator')));
		}

		/**
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		public static function rollout_resume(array $assoc_args) {
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}

			return self::rollout_status((new \Dbvc\AgencyControl\Release\RolloutService())->resume((string) ($assoc_args['rollout'] ?? '')));
		}

		/**
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		public static function rollout_retry(array $assoc_args) {
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}

			return self::rollout_status((new \Dbvc\AgencyControl\Release\RolloutService())->retry((string) ($assoc_args['rollout'] ?? ''), (string) ($assoc_args['target'] ?? '')));
		}

		/**
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		public static function rollout_withdraw(array $assoc_args) {
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}

			return self::rollout_status((new \Dbvc\AgencyControl\Release\RolloutService())->withdraw((string) ($assoc_args['rollout'] ?? '')));
		}

		/**
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		public static function rollouts(array $assoc_args) {
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}
			$store = new \Dbvc\AgencyControl\Storage\RolloutStore();
			if (! empty($assoc_args['rollout'])) {
				$row = $store->find((string) $assoc_args['rollout']);

				return $row === null ? new WP_Error('dbvc_agency_rollout_not_found', 'Rollout not found.') : self::rollout_status(['rollout' => $row, 'targets' => $store->targets($row['rollout_id'])]);
			}
			$rows = $store->all(isset($assoc_args['release']) ? (string) $assoc_args['release'] : null, self::bounded_integer($assoc_args['limit'] ?? self::DEFAULT_LIMIT, 1, self::MAX_LIMIT));

			return ['counts' => $store->counts(), 'returned' => count($rows), 'rollouts' => array_map([self::class, 'rollout_row'], $rows)];
		}

		/**
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		public static function rollout_prune(array $assoc_args) {
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}
			$days = isset($assoc_args['days']) ? (int) $assoc_args['days'] : \Dbvc\AgencyControl\Release\RolloutService::RETENTION_DAYS_DEFAULT;

			return (new \Dbvc\AgencyControl\Release\RolloutService())->prune($days, ! empty($assoc_args['dry-run']) || ! empty($assoc_args['dry_run']));
		}

		/**
		 * Accept cohorts as a nested array (REST) or a "canary;a,b;c" string (CLI).
		 *
		 * @param mixed $raw
		 * @return array<int, array<int, string>>
		 */
		private static function parse_cohorts($raw) {
			$cohorts = [];
			if (is_array($raw)) {
				foreach ($raw as $entry) {
					$ids = is_array($entry) ? array_map('strval', $entry) : explode(',', (string) $entry);
					$cohorts[] = array_values(array_filter(array_map('trim', $ids), static function ($id) {
						return $id !== '';
					}));
				}
			} else {
				foreach (explode(';', (string) $raw) as $group) {
					$cohorts[] = array_values(array_filter(array_map('trim', explode(',', $group)), static function ($id) {
						return $id !== '';
					}));
				}
			}

			return array_values(array_filter($cohorts, static function ($cohort) {
				return $cohort !== [];
			}));
		}

		/**
		 * @param array|WP_Error $result Service status payload {rollout, targets}.
		 * @return array|WP_Error
		 */
		private static function rollout_status($result) {
			if (is_wp_error($result)) {
				return $result;
			}

			return [
				'rollout' => self::rollout_row($result['rollout']),
				'targets' => array_map([self::class, 'rollout_target_row'], $result['targets']),
			];
		}

		/**
		 * @param array $row
		 * @return array
		 */
		private static function rollout_row(array $row) {
			return [
				'rollout_uid' => (string) $row['rollout_uid'],
				'client_id' => (string) $row['client_id'],
				'release_uid' => (string) $row['release_uid'],
				'source' => (string) $row['source_environment_id'],
				'state' => (string) $row['state'],
				'cohort' => (int) $row['current_cohort'],
				'cohorts' => (int) $row['cohort_count'],
				'targets' => (int) $row['target_count'],
				'paused_reason' => (string) $row['paused_reason'],
				'note' => (string) $row['note'],
				'created_at' => (string) $row['created_at'],
				'updated_at' => (string) $row['updated_at'],
			];
		}

		/**
		 * @param array $row
		 * @return array
		 */
		private static function rollout_target_row(array $row) {
			return [
				'cohort' => (int) $row['cohort'],
				'position' => (int) $row['position'],
				'target' => (string) $row['target_environment_id'],
				'state' => (string) $row['state'],
				'operation_id' => (string) $row['operation_id'],
				'approval_uid' => (string) $row['approval_uid'],
				'outcome' => (string) $row['outcome'],
				'detail' => (string) $row['detail'],
				'updated_at' => (string) $row['updated_at'],
			];
		}

		/**
		 * @param array $row
		 * @return array
		 */
		private static function environment_row(array $row) {
			return [
				'environment_id' => (string) $row['environment_id'],
				'agency_id' => (string) $row['agency_id'],
				'client_id' => (string) $row['client_id'],
				'label' => (string) $row['label'],
				'status' => (string) $row['status'],
				'hold_reason' => (string) $row['hold_reason'],
				'current_epoch' => (string) $row['current_epoch'],
				'principal_user_id' => (int) $row['principal_user_id'],
				'enrolled_site_url' => (string) $row['enrolled_site_url'],
				'last_contact_at' => (string) ($row['last_contact_at'] ?? ''),
				'received_events' => (int) $row['received_events'],
				'max_received_sequence' => (int) $row['max_received_sequence'],
				'last_inbox_poll_at' => (string) ($row['last_inbox_poll_at'] ?? ''),
				'last_inbox_cursor' => (int) ($row['last_inbox_cursor'] ?? 0),
			];
		}

		/**
		 * @return true|WP_Error
		 */
		private static function require_ready() {
			if (! class_exists('DBVC_Agency_Control_Addon')) {
				return new WP_Error('dbvc_agency_cli_unavailable', 'The Agency Control add-on is unavailable in this checkout.');
			}
			$state = DBVC_Agency_Control_Addon::get_gate_state();
			if ($state !== \Dbvc\ConnectedProtocol\RoleGate::READY) {
				return new WP_Error('dbvc_agency_cli_not_ready', 'The Agency Control hub is not ready (gate state: ' . $state . ').');
			}

			return true;
		}

		/**
		 * @param mixed $value
		 * @param int $min
		 * @param int $max
		 * @return int
		 */
		private static function bounded_integer($value, $min, $max) {
			return max($min, min($max, (int) $value));
		}
	}
}

if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI_Command') && ! class_exists('DBVC_WP_CLI_Agency')) {
	/**
	 * Administer and inspect the DBVC Agency Control hub.
	 */
	class DBVC_WP_CLI_Agency extends WP_CLI_Command {
		/**
		 * Show hub gate, schema, protocol, environments and receipt counters.
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 */
		public function status($args, $assoc_args) {
			unset($args, $assoc_args);
			$this->emit_json(DBVC_Agency_CLI_Inspector::status());
		}

		/**
		 * Create a single-use enrollment invitation (the token is printed once).
		 *
		 * ## OPTIONS
		 *
		 * --client=<id>
		 * : Client identifier (bounded ASCII).
		 *
		 * [--agency=<id>]
		 * : Agency identifier. Default: studio.
		 *
		 * [--label=<label>]
		 * : Human label for the environment.
		 *
		 * [--environment=<id>]
		 * : Fix the environment identifier; otherwise the connector proposes one.
		 *
		 * [--ttl=<seconds>]
		 * : Invitation lifetime. Default: 3600; maximum: 604800.
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 */
		public function invite($args, $assoc_args) {
			unset($args);
			$this->emit_json(DBVC_Agency_CLI_Inspector::invite($assoc_args));
		}

		/**
		 * List enrolled environments (no secrets).
		 *
		 * ## OPTIONS
		 *
		 * [--client=<id>]
		 * : Filter by client.
		 *
		 * [--limit=<number>]
		 * : Maximum rows. Default: 50; maximum: 500.
		 *
		 * [--fields=<fields>]
		 * : Comma-separated fields for table output.
		 *
		 * [--format=<format>]
		 * : table or json. Default: table.
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 */
		public function environments($args, $assoc_args) {
			unset($args);
			$this->emit_rows(DBVC_Agency_CLI_Inspector::environments($assoc_args), 'environments', ['environment_id', 'client_id', 'label', 'status', 'hold_reason', 'current_epoch', 'received_events', 'max_received_sequence', 'last_contact_at'], $assoc_args);
		}

		/**
		 * List received observation events.
		 *
		 * ## OPTIONS
		 *
		 * [--environment=<id>]
		 * : Filter by environment.
		 *
		 * [--instance=<uid>]
		 * : Filter by instance UID.
		 *
		 * [--limit=<number>]
		 * : Maximum rows. Default: 50; maximum: 500.
		 *
		 * [--fields=<fields>]
		 * : Comma-separated fields for table output.
		 *
		 * [--format=<format>]
		 * : table or json. Default: table.
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 */
		public function events($args, $assoc_args) {
			unset($args);
			$this->emit_rows(DBVC_Agency_CLI_Inspector::events($assoc_args), 'events', ['environment_id', 'event_id', 'sequence', 'domain', 'instance_uid', 'profile', 'exists', 'hash', 'origin', 'routing_state'], $assoc_args);
		}

		/**
		 * List latest projections per environment object.
		 *
		 * ## OPTIONS
		 *
		 * [--environment=<id>]
		 * : Filter by environment.
		 *
		 * [--instance=<uid>]
		 * : Filter by instance UID.
		 *
		 * [--limit=<number>]
		 * : Maximum rows. Default: 50; maximum: 500.
		 *
		 * [--fields=<fields>]
		 * : Comma-separated fields for table output.
		 *
		 * [--format=<format>]
		 * : table or json. Default: table.
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 */
		public function projections($args, $assoc_args) {
			unset($args);
			$this->emit_rows(DBVC_Agency_CLI_Inspector::projections($assoc_args), 'projections', ['environment_id', 'domain', 'instance_uid', 'profile', 'exists', 'complete', 'hash', 'observed_sequence'], $assoc_args);
		}

		/**
		 * Subscribe a target environment to a source environment's observations (same agency and client).
		 *
		 * ## OPTIONS
		 *
		 * --source=<id>
		 * : Source environment identifier.
		 *
		 * --target=<id>
		 * : Target environment identifier.
		 *
		 * [--domains=<list>]
		 * : Comma-separated domains. Default: every supported domain.
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 */
		public function subscribe($args, $assoc_args) {
			unset($args);
			$this->emit_json(DBVC_Agency_CLI_Inspector::subscribe($assoc_args));
		}

		/**
		 * Bind one instance on an environment to a studio framework definition for review.
		 *
		 * ## OPTIONS
		 *
		 * --environment=<id>
		 * : Environment identifier.
		 *
		 * --domain=<domain>
		 * : Observation domain, e.g. bricks.global_class.
		 *
		 * --instance=<uid>
		 * : Instance UID on that environment.
		 *
		 * --definition=<uid>
		 * : Studio definition UID.
		 *
		 * [--adopted-version=<version>]
		 * : Published version this instance is declared to follow.
		 *
		 * [--channel=<channel>]
		 * : Definition channel whose desired version applies. Default: stable.
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 * @subcommand subscribe-framework
		 */
		public function subscribe_framework($args, $assoc_args) {
			unset($args);
			$this->emit_json(DBVC_Agency_CLI_Inspector::subscribe_framework($assoc_args));
		}

		/**
		 * Disable a subscription (pending deliveries are cancelled at retrieval).
		 *
		 * ## OPTIONS
		 *
		 * --id=<subscription_id>
		 * : Subscription identifier from `subscriptions`.
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 */
		public function unsubscribe($args, $assoc_args) {
			unset($args);
			$this->emit_json(DBVC_Agency_CLI_Inspector::set_subscription_enabled($assoc_args, false));
		}

		/**
		 * Re-enable a subscription.
		 *
		 * ## OPTIONS
		 *
		 * --id=<subscription_id>
		 * : Subscription identifier from `subscriptions`.
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 * @subcommand enable-subscription
		 */
		public function enable_subscription($args, $assoc_args) {
			unset($args);
			$this->emit_json(DBVC_Agency_CLI_Inspector::set_subscription_enabled($assoc_args, true));
		}

		/**
		 * List subscriptions.
		 *
		 * ## OPTIONS
		 *
		 * [--environment=<id>]
		 * : Filter by source or target environment.
		 *
		 * [--limit=<number>]
		 * : Maximum rows. Default: 50; maximum: 500.
		 *
		 * [--fields=<fields>]
		 * : Comma-separated fields for table output.
		 *
		 * [--format=<format>]
		 * : table or json. Default: table.
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 */
		public function subscriptions($args, $assoc_args) {
			unset($args);
			$this->emit_rows(DBVC_Agency_CLI_Inspector::subscriptions($assoc_args), 'subscriptions', ['subscription_id', 'type', 'client_id', 'source', 'target', 'domain', 'instance_uid', 'definition_uid', 'enabled'], $assoc_args);
		}

		/**
		 * Route pending received events into deliveries and review items now.
		 *
		 * ## OPTIONS
		 *
		 * [--limit=<number>]
		 * : Maximum events. Default: 200; maximum: 1000.
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 */
		public function route($args, $assoc_args) {
			unset($args);
			$this->emit_json(DBVC_Agency_CLI_Inspector::route($assoc_args));
		}

		/**
		 * List deliveries per target environment.
		 *
		 * ## OPTIONS
		 *
		 * [--target=<id>]
		 * : Filter by target environment.
		 *
		 * [--limit=<number>]
		 * : Maximum rows. Default: 50; maximum: 500.
		 *
		 * [--fields=<fields>]
		 * : Comma-separated fields for table output.
		 *
		 * [--format=<format>]
		 * : table or json. Default: table.
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 */
		public function deliveries($args, $assoc_args) {
			unset($args);
			$this->emit_rows(DBVC_Agency_CLI_Inspector::deliveries($assoc_args), 'deliveries', ['delivery_id', 'event_row_id', 'source', 'target', 'domain', 'state', 'cancelled_reason', 'created_at', 'acked_at'], $assoc_args);
		}

		/**
		 * List framework review items (raw observations awaiting comparison).
		 *
		 * ## OPTIONS
		 *
		 * [--definition=<uid>]
		 * : Filter by definition UID.
		 *
		 * [--environment=<id>]
		 * : Filter by environment.
		 *
		 * [--state=<state>]
		 * : observed, classified or resolved.
		 *
		 * [--limit=<number>]
		 * : Maximum rows. Default: 50; maximum: 500.
		 *
		 * [--fields=<fields>]
		 * : Comma-separated fields for table output.
		 *
		 * [--format=<format>]
		 * : table or json. Default: table.
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 */
		public function reviews($args, $assoc_args) {
			unset($args);
			$this->emit_rows(DBVC_Agency_CLI_Inspector::reviews($assoc_args), 'reviews', ['review_item_id', 'environment_id', 'domain', 'instance_uid', 'definition_uid', 'event_sequence', 'state', 'drift', 'version', 'resolution', 'created_at'], $assoc_args);
		}

		/**
		 * Three-way comparison of a source and target environment (hash level; read-only).
		 *
		 * ## OPTIONS
		 *
		 * --source=<id>
		 * : Source environment identifier.
		 *
		 * --target=<id>
		 * : Target environment identifier.
		 *
		 * [--domain=<domain>]
		 * : Restrict to one domain.
		 *
		 * [--state=<state>]
		 * : Filter rows: synchronized, outgoing, incoming, converged, conflict, baseline_required, unknown.
		 *
		 * [--fields=<fields>]
		 * : Comma-separated fields for table output.
		 *
		 * [--format=<format>]
		 * : table or json. Default: table.
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 */
		public function compare($args, $assoc_args) {
			unset($args);
			$result = DBVC_Agency_CLI_Inspector::compare($assoc_args);
			if (! is_wp_error($result) && sanitize_key((string) ($assoc_args['format'] ?? 'table')) === 'table') {
				WP_CLI::log(sprintf('%s → %s · counts %s · source fresh %s · target fresh %s', $result['source']['environment_id'], $result['target']['environment_id'], wp_json_encode($result['counts']), $result['source']['fresh'] ? 'yes' : 'no', $result['target']['fresh'] ? 'yes' : 'no'));
			}
			$this->emit_rows($result, 'rows', ['domain', 'source_instance_uid', 'target_instance_uid', 'pairing', 'state', 'source_observed', 'target_observed', 'reasons', 'baseline_hash', 'source_hash', 'target_hash'], $assoc_args);
		}

		/**
		 * Record baselines for objects whose source and target currently agree (explicit operator action).
		 *
		 * ## OPTIONS
		 *
		 * --source=<id>
		 * : Source environment identifier.
		 *
		 * --target=<id>
		 * : Target environment identifier.
		 *
		 * [--domain=<domain>]
		 * : Restrict to one domain.
		 *
		 * [--instance=<uid>]
		 * : Restrict to one source instance UID.
		 *
		 * [--note=<text>]
		 * : Short note stored with the baseline.
		 *
		 * [--accept-absent]
		 * : Also record an absent baseline for objects verified absent on exactly one side (they then classify as outgoing/incoming).
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 * @subcommand baseline-confirm
		 */
		public function baseline_confirm($args, $assoc_args) {
			unset($args);
			$this->emit_json(DBVC_Agency_CLI_Inspector::baseline_confirm($assoc_args));
		}

		/**
		 * List confirmed baselines.
		 *
		 * ## OPTIONS
		 *
		 * [--instance=<uid>]
		 * : Only baselines whose source or target instance is this UID.
		 *
		 * [--limit=<number>]
		 * : Maximum rows. Default: 50; maximum: 500.
		 *
		 * [--fields=<fields>]
		 * : Comma-separated fields for table output.
		 *
		 * [--format=<format>]
		 * : table or json. Default: table.
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 */
		public function baselines($args, $assoc_args) {
			unset($args);
			$this->emit_rows(DBVC_Agency_CLI_Inspector::baselines($assoc_args), 'baselines', ['baseline_id', 'source', 'target', 'domain', 'source_instance_uid', 'target_instance_uid', 'profile', 'baseline_hash', 'source_sequence', 'target_sequence', 'confirmed_at'], $assoc_args);
		}

		/**
		 * Declare that an instance on the source environment is the same lineage as an instance on the target.
		 *
		 * ## OPTIONS
		 *
		 * --domain=<domain>
		 * : Observation domain.
		 *
		 * --source=<id>
		 * : Source environment identifier.
		 *
		 * --source-instance=<uid>
		 * : Instance UID on the source.
		 *
		 * --target=<id>
		 * : Target environment identifier.
		 *
		 * --target-instance=<uid>
		 * : Instance UID on the target.
		 *
		 * [--note=<text>]
		 * : Short note.
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 * @subcommand link-instance
		 */
		public function link_instance($args, $assoc_args) {
			unset($args);
			$this->emit_json(DBVC_Agency_CLI_Inspector::link_instance($assoc_args));
		}

		/**
		 * Remove an instance link.
		 *
		 * ## OPTIONS
		 *
		 * --id=<link_id>
		 * : Link identifier from `links`.
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 * @subcommand unlink-instance
		 */
		public function unlink_instance($args, $assoc_args) {
			unset($args);
			$this->emit_json(DBVC_Agency_CLI_Inspector::unlink_instance($assoc_args));
		}

		/**
		 * List instance links.
		 *
		 * ## OPTIONS
		 *
		 * [--limit=<number>]
		 * : Maximum rows. Default: 50; maximum: 500.
		 *
		 * [--fields=<fields>]
		 * : Comma-separated fields for table output.
		 *
		 * [--format=<format>]
		 * : table or json. Default: table.
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 */
		public function links($args, $assoc_args) {
			unset($args);
			$this->emit_rows(DBVC_Agency_CLI_Inspector::links($assoc_args), 'links', ['link_id', 'domain', 'source_environment_id', 'source_instance_uid', 'target_environment_id', 'target_instance_uid', 'created_at', 'note'], $assoc_args);
		}

		/**
		 * Publish an immutable framework definition version (hash supplied, read from one environment's current projection, or copied from a published version).
		 *
		 * ## OPTIONS
		 *
		 * --definition=<uid>
		 * : Studio definition UID.
		 *
		 * --version=<version>
		 * : Version label (opaque; ordering comes from --order or the publish sequence, never from the label).
		 *
		 * [--agency=<id>]
		 * : Agency identifier. Default: studio.
		 *
		 * [--channel=<channel>]
		 * : Channel this version is published to. Default: stable.
		 *
		 * [--domain=<domain>]
		 * : Observation domain (required unless --from-version).
		 *
		 * [--profile=<profile>]
		 * : Projection profile, e.g. bricks-global-class-v1 (required unless --from-version).
		 *
		 * [--hash=<sha256>]
		 * : Definition hash (64 hex).
		 *
		 * [--from-environment=<id>]
		 * : Read the hash from this environment's current complete projection.
		 *
		 * [--from-instance=<uid>]
		 * : Instance UID on --from-environment.
		 *
		 * [--from-version=<version>]
		 * : Copy domain, profile and hash from this published version of the same definition.
		 *
		 * [--order=<n>]
		 * : Explicit version order (positive integer). Default: one past the highest published order.
		 *
		 * [--desired]
		 * : Also mark this version desired on its channel (flags approved overrides for rebase review).
		 *
		 * [--note=<text>]
		 * : Short note.
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 * @subcommand definition-publish
		 */
		public function definition_publish($args, $assoc_args) {
			unset($args);
			$this->emit_json(DBVC_Agency_CLI_Inspector::definition_publish($assoc_args));
		}

		/**
		 * Mark a published version desired on its channel (approved overrides on that channel become needs_rebase_review).
		 *
		 * ## OPTIONS
		 *
		 * --definition=<uid>
		 * : Studio definition UID.
		 *
		 * --version=<version>
		 * : Published version label.
		 *
		 * [--agency=<id>]
		 * : Agency identifier. Default: studio.
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 * @subcommand definition-desire
		 */
		public function definition_desire($args, $assoc_args) {
			unset($args);
			$this->emit_json(DBVC_Agency_CLI_Inspector::definition_desire($assoc_args));
		}

		/**
		 * List published definition versions.
		 *
		 * ## OPTIONS
		 *
		 * [--definition=<uid>]
		 * : Filter by definition UID.
		 *
		 * [--limit=<number>]
		 * : Maximum rows. Default: 50; maximum: 500.
		 *
		 * [--fields=<fields>]
		 * : Comma-separated fields for table output.
		 *
		 * [--format=<format>]
		 * : table or json. Default: table.
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 */
		public function definitions($args, $assoc_args) {
			unset($args);
			$this->emit_rows(DBVC_Agency_CLI_Inspector::definitions($assoc_args), 'definitions', ['definition_uid', 'version', 'order', 'channel', 'desired', 'domain', 'profile', 'hash', 'source', 'published_at'], $assoc_args);
		}

		/**
		 * Declare which published version a framework subscription follows.
		 *
		 * ## OPTIONS
		 *
		 * --id=<subscription_id>
		 * : Framework subscription identifier from `subscriptions`.
		 *
		 * --version=<version>
		 * : Published version label.
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 * @subcommand adopt-version
		 */
		public function adopt_version($args, $assoc_args) {
			unset($args);
			$this->emit_json(DBVC_Agency_CLI_Inspector::adopt_version($assoc_args));
		}

		/**
		 * Approve an exact hash for one subscribed instance (from its current projection unless --hash is given).
		 *
		 * ## OPTIONS
		 *
		 * --environment=<id>
		 * : Environment identifier.
		 *
		 * --domain=<domain>
		 * : Observation domain.
		 *
		 * --instance=<uid>
		 * : Instance UID on that environment.
		 *
		 * --definition=<uid>
		 * : Studio definition UID the instance is subscribed to.
		 *
		 * --rationale=<text>
		 * : Why this deviation is acceptable.
		 *
		 * [--hash=<sha256>]
		 * : Approve this exact hash instead of the currently observed one.
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 * @subcommand override-approve
		 */
		public function override_approve($args, $assoc_args) {
			unset($args);
			$this->emit_json(DBVC_Agency_CLI_Inspector::override_approve($assoc_args));
		}

		/**
		 * Detach an override (the instance is again compared against its adopted version).
		 *
		 * ## OPTIONS
		 *
		 * --environment=<id>
		 * : Environment identifier.
		 *
		 * --domain=<domain>
		 * : Observation domain.
		 *
		 * --instance=<uid>
		 * : Instance UID on that environment.
		 *
		 * --definition=<uid>
		 * : Studio definition UID.
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 * @subcommand override-detach
		 */
		public function override_detach($args, $assoc_args) {
			unset($args);
			$this->emit_json(DBVC_Agency_CLI_Inspector::override_detach($assoc_args));
		}

		/**
		 * List overrides.
		 *
		 * ## OPTIONS
		 *
		 * [--limit=<number>]
		 * : Maximum rows. Default: 50; maximum: 500.
		 *
		 * [--fields=<fields>]
		 * : Comma-separated fields for table output.
		 *
		 * [--format=<format>]
		 * : table or json. Default: table.
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 */
		public function overrides($args, $assoc_args) {
			unset($args);
			$this->emit_rows(DBVC_Agency_CLI_Inspector::overrides($assoc_args), 'overrides', ['override_id', 'environment_id', 'domain', 'instance_uid', 'definition_uid', 'definition_version', 'approved_hash', 'state', 'approved_at'], $assoc_args);
		}

		/**
		 * Framework status per enabled framework subscription (drift and version state; reporting only).
		 *
		 * ## OPTIONS
		 *
		 * [--environment=<id>]
		 * : Filter by environment.
		 *
		 * [--definition=<uid>]
		 * : Filter by definition UID.
		 *
		 * [--drift=<state>]
		 * : Only rows in this drift state (clean, local_drift, approved_override, override_changed, unknown).
		 *
		 * [--fields=<fields>]
		 * : Comma-separated fields for table output.
		 *
		 * [--format=<format>]
		 * : table or json. Default: table.
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 * @subcommand framework-status
		 */
		public function framework_status($args, $assoc_args) {
			unset($args);
			$this->emit_rows(DBVC_Agency_CLI_Inspector::framework_status($assoc_args), 'rows', ['environment_id', 'domain', 'instance_uid', 'definition_uid', 'channel', 'adopted_version', 'desired_version', 'drift', 'version', 'override_state', 'fresh', 'reasons'], $assoc_args);
		}

		/**
		 * Stamp open framework review items with the drift/version state the framework status report assigns; items that are superseded, clean/current or approved-and-current are resolved automatically.
		 *
		 * ## OPTIONS
		 *
		 * [--environment=<id>]
		 * : Filter by environment.
		 *
		 * [--definition=<uid>]
		 * : Filter by definition UID.
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 * @subcommand review-classify
		 */
		public function review_classify($args, $assoc_args) {
			unset($args);
			$this->emit_json(DBVC_Agency_CLI_Inspector::review_classify($assoc_args));
		}

		/**
		 * Resolve one review item explicitly (records the operator and a note; changes no content).
		 *
		 * ## OPTIONS
		 *
		 * --id=<review_item_id>
		 * : Review item identifier from `reviews`.
		 *
		 * --note=<text>
		 * : Why the item needs no further action.
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 * @subcommand review-resolve
		 */
		public function review_resolve($args, $assoc_args) {
			unset($args);
			$this->emit_json(DBVC_Agency_CLI_Inspector::review_resolve($assoc_args));
		}

		/**
		 * Create an immutable release manifest from a source environment's current projections (payloads are then collected from that environment's connector).
		 *
		 * ## OPTIONS
		 *
		 * --source=<id>
		 * : Source environment identifier.
		 *
		 * [--items=<list>]
		 * : Comma-separated domain:instance_uid selections to replace on targets.
		 *
		 * [--delete=<list>]
		 * : Comma-separated domain:instance_uid selections whose verified absence on the source is released as a deletion.
		 *
		 * [--note=<text>]
		 * : Short note.
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 * @subcommand release-create
		 */
		public function release_create($args, $assoc_args) {
			unset($args);
			$this->emit_json(DBVC_Agency_CLI_Inspector::release_create($assoc_args));
		}

		/**
		 * Withdraw a release (outstanding prepare requests are cancelled at retrieval).
		 *
		 * ## OPTIONS
		 *
		 * --release=<uid>
		 * : Release identifier.
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 * @subcommand release-withdraw
		 */
		public function release_withdraw($args, $assoc_args) {
			unset($args);
			$this->emit_json(DBVC_Agency_CLI_Inspector::release_withdraw($assoc_args));
		}

		/**
		 * List releases, or show one with its items via --release.
		 *
		 * ## OPTIONS
		 *
		 * [--release=<uid>]
		 * : Show one release with its items.
		 *
		 * [--source=<id>]
		 * : Filter by source environment.
		 *
		 * [--limit=<number>]
		 * : Maximum rows. Default: 50; maximum: 500.
		 *
		 * [--fields=<fields>]
		 * : Comma-separated fields for table output.
		 *
		 * [--format=<format>]
		 * : table or json. Default: table.
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 */
		public function releases($args, $assoc_args) {
			unset($args);
			if (! empty($assoc_args['release'])) {
				$this->emit_json(DBVC_Agency_CLI_Inspector::releases($assoc_args));
				return;
			}
			$this->emit_rows(DBVC_Agency_CLI_Inspector::releases($assoc_args), 'releases', ['release_uid', 'client_id', 'source', 'state', 'items', 'digest', 'note', 'created_at', 'sealed_at'], $assoc_args);
		}

		/**
		 * Ask a target environment to dry-run a sealed release (the target's connector produces the receipt; nothing is written).
		 *
		 * ## OPTIONS
		 *
		 * --release=<uid>
		 * : Sealed release identifier.
		 *
		 * --target=<id>
		 * : Target environment identifier.
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 * @subcommand prepare-request
		 */
		public function prepare_request($args, $assoc_args) {
			unset($args);
			$this->emit_json(DBVC_Agency_CLI_Inspector::prepare_request($assoc_args));
		}

		/**
		 * List prepare requests and receipts, or show one receipt with --operation.
		 *
		 * ## OPTIONS
		 *
		 * [--operation=<id>]
		 * : Show one preparation with its receipt and hub notes.
		 *
		 * [--release=<uid>]
		 * : Filter by release.
		 *
		 * [--target=<id>]
		 * : Filter by target environment.
		 *
		 * [--limit=<number>]
		 * : Maximum rows. Default: 50; maximum: 500.
		 *
		 * [--fields=<fields>]
		 * : Comma-separated fields for table output.
		 *
		 * [--format=<format>]
		 * : table or json. Default: table.
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 */
		public function preparations($args, $assoc_args) {
			unset($args);
			if (! empty($assoc_args['operation'])) {
				$this->emit_json(DBVC_Agency_CLI_Inspector::preparations($assoc_args));
				return;
			}
			$this->emit_rows(DBVC_Agency_CLI_Inspector::preparations($assoc_args), 'preparations', ['operation_id', 'release_uid', 'target', 'state', 'outcome', 'ready', 'noop', 'blocked', 'requested_at', 'received_at', 'expires_at'], $assoc_args);
		}

		/**
		 * Approve a received prepare receipt for execution on its target (binds release + receipt digests, target epoch and policy revision; expires with the receipt).
		 *
		 * ## OPTIONS
		 *
		 * --operation=<id>
		 * : Prepare operation id whose receipt is ready or a no-op.
		 *
		 * [--note=<text>]
		 * : Short note.
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 */
		public function approve($args, $assoc_args) {
			unset($args);
			$this->emit_json(DBVC_Agency_CLI_Inspector::approve($assoc_args));
		}

		/**
		 * Roll back a consumed (executed) operation: creates a reviewed rollback the target restores on its next poll (its journalled before image, guarded by the operation's after fingerprint; a container that moved on yields a restore conflict, never an overwrite).
		 *
		 * ## OPTIONS
		 *
		 * --operation=<id>
		 * : The applied operation id to reverse.
		 *
		 * [--note=<text>]
		 * : Short note.
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 */
		public function rollback($args, $assoc_args) {
			unset($args);
			$this->emit_json(DBVC_Agency_CLI_Inspector::rollback($assoc_args));
		}

		/**
		 * Plan a fleet rollout: stage a sealed release across ordered cohorts of targets, canary first. No target is written yet — advance drives the per-target prepare/approve steps and gates each cohort on the prior one verifying.
		 *
		 * ## OPTIONS
		 *
		 * --release=<uid>
		 * : Sealed release identifier.
		 *
		 * --cohorts=<spec>
		 * : Semicolon-separated cohorts of comma-separated environment ids, canary first (e.g. env-canary;env-a,env-b).
		 *
		 * [--note=<text>]
		 * : Short note.
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 * @subcommand rollout-create
		 */
		public function rollout_create($args, $assoc_args) {
			unset($args);
			$this->emit_json(DBVC_Agency_CLI_Inspector::rollout_create($assoc_args));
		}

		/**
		 * Drive a rollout one step: request prepares, approve received receipts, read execution outcomes, and open the next cohort once the current one has fully verified. A single target failure pauses the rollout. Safe to call repeatedly.
		 *
		 * ## OPTIONS
		 *
		 * --rollout=<uid>
		 * : Rollout identifier.
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 * @subcommand rollout-advance
		 */
		public function rollout_advance($args, $assoc_args) {
			unset($args);
			$this->emit_json(DBVC_Agency_CLI_Inspector::rollout_advance($assoc_args));
		}

		/**
		 * Pause a running rollout so later cohorts do not start.
		 *
		 * ## OPTIONS
		 *
		 * --rollout=<uid>
		 * : Rollout identifier.
		 *
		 * [--reason=<text>]
		 * : Short reason. Default: operator.
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 * @subcommand rollout-pause
		 */
		public function rollout_pause($args, $assoc_args) {
			unset($args);
			$this->emit_json(DBVC_Agency_CLI_Inspector::rollout_pause($assoc_args));
		}

		/**
		 * Resume a paused rollout and advance it. A failed target in the current cohort re-pauses it until retried or withdrawn.
		 *
		 * ## OPTIONS
		 *
		 * --rollout=<uid>
		 * : Rollout identifier.
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 * @subcommand rollout-resume
		 */
		public function rollout_resume($args, $assoc_args) {
			unset($args);
			$this->emit_json(DBVC_Agency_CLI_Inspector::rollout_resume($assoc_args));
		}

		/**
		 * Reset one failed target back to pending so the next advance re-prepares it. The rollout stays paused until resumed.
		 *
		 * ## OPTIONS
		 *
		 * --rollout=<uid>
		 * : Rollout identifier.
		 *
		 * --target=<id>
		 * : Target environment identifier to retry.
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 * @subcommand rollout-retry
		 */
		public function rollout_retry($args, $assoc_args) {
			unset($args);
			$this->emit_json(DBVC_Agency_CLI_Inspector::rollout_retry($assoc_args));
		}

		/**
		 * Withdraw a rollout. Targets already applied are not reverted; use rollback for that.
		 *
		 * ## OPTIONS
		 *
		 * --rollout=<uid>
		 * : Rollout identifier.
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 * @subcommand rollout-withdraw
		 */
		public function rollout_withdraw($args, $assoc_args) {
			unset($args);
			$this->emit_json(DBVC_Agency_CLI_Inspector::rollout_withdraw($assoc_args));
		}

		/**
		 * List rollouts, or show one with its per-target progress via --rollout.
		 *
		 * ## OPTIONS
		 *
		 * [--rollout=<uid>]
		 * : Show one rollout with its per-target rows.
		 *
		 * [--release=<uid>]
		 * : Filter by release.
		 *
		 * [--limit=<number>]
		 * : Maximum rows. Default: 50; maximum: 500.
		 *
		 * [--fields=<fields>]
		 * : Comma-separated fields for table output.
		 *
		 * [--format=<format>]
		 * : table or json. Default: table.
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 */
		public function rollouts($args, $assoc_args) {
			unset($args);
			if (! empty($assoc_args['rollout'])) {
				$this->emit_json(DBVC_Agency_CLI_Inspector::rollouts($assoc_args));
				return;
			}
			$this->emit_rows(DBVC_Agency_CLI_Inspector::rollouts($assoc_args), 'rollouts', ['rollout_uid', 'client_id', 'release_uid', 'source', 'state', 'cohort', 'cohorts', 'targets', 'paused_reason', 'created_at'], $assoc_args);
		}

		/**
		 * Retention: delete finished rollouts (completed, withdrawn or failed) older than the window, with their target rows. Running and paused rollouts are never removed.
		 *
		 * ## OPTIONS
		 *
		 * [--days=<number>]
		 * : Age threshold in days. Default: 30. 0 prunes every finished rollout.
		 *
		 * [--dry-run]
		 * : Report how many would be pruned without deleting.
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 * @subcommand rollout-prune
		 */
		public function rollout_prune($args, $assoc_args) {
			unset($args);
			$this->emit_json(DBVC_Agency_CLI_Inspector::rollout_prune($assoc_args));
		}

		/**
		 * Revoke an unconsumed approval.
		 *
		 * ## OPTIONS
		 *
		 * --approval=<uid>
		 * : Approval identifier.
		 *
		 * [--reason=<text>]
		 * : Short reason.
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 * @subcommand revoke-approval
		 */
		public function revoke_approval($args, $assoc_args) {
			unset($args);
			$this->emit_json(DBVC_Agency_CLI_Inspector::revoke_approval($assoc_args));
		}

		/**
		 * List approvals and execution outcomes, or show one with its execution receipt via --approval.
		 *
		 * ## OPTIONS
		 *
		 * [--approval=<uid>]
		 * : Show one approval with its execution receipt.
		 *
		 * [--target=<id>]
		 * : Filter by target environment.
		 *
		 * [--limit=<number>]
		 * : Maximum rows. Default: 50; maximum: 500.
		 *
		 * [--fields=<fields>]
		 * : Comma-separated fields for table output.
		 *
		 * [--format=<format>]
		 * : table or json. Default: table.
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 */
		public function approvals($args, $assoc_args) {
			unset($args);
			if (! empty($assoc_args['approval'])) {
				$this->emit_json(DBVC_Agency_CLI_Inspector::approvals($assoc_args));
				return;
			}
			$this->emit_rows(DBVC_Agency_CLI_Inspector::approvals($assoc_args), 'approvals', ['approval_uid', 'operation_id', 'release_uid', 'target', 'state', 'approved_at', 'expires_at', 'execution_outcome', 'executed_at'], $assoc_args);
		}

		/**
		 * Revoke an environment's credential and delivery authority.
		 *
		 * ## OPTIONS
		 *
		 * --environment=<id>
		 * : Environment identifier.
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 */
		public function revoke($args, $assoc_args) {
			unset($args);
			$this->emit_json(DBVC_Agency_CLI_Inspector::revoke($assoc_args));
		}

		/**
		 * Release a held environment after review (revoked environments must re-enroll).
		 *
		 * ## OPTIONS
		 *
		 * --environment=<id>
		 * : Environment identifier.
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 */
		public function release($args, $assoc_args) {
			unset($args);
			$this->emit_json(DBVC_Agency_CLI_Inspector::release($assoc_args));
		}

		/**
		 * Hold an enabled environment pending review: its batches are refused until released.
		 *
		 * ## OPTIONS
		 *
		 * --environment=<id>
		 * : Environment identifier.
		 *
		 * [--reason=<text>]
		 * : Short operator note recorded as the hold reason.
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 */
		public function hold($args, $assoc_args) {
			unset($args);
			$this->emit_json(DBVC_Agency_CLI_Inspector::hold($assoc_args));
		}

		/**
		 * List enrollment invitations (never their tokens).
		 *
		 * ## OPTIONS
		 *
		 * [--open]
		 * : Only invitations that are neither consumed nor expired.
		 *
		 * [--limit=<n>]
		 * : Maximum rows. Default: 50.
		 *
		 * [--fields=<fields>]
		 * : Comma-separated fields for table output.
		 *
		 * [--format=<format>]
		 * : table or json. Default: table.
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 */
		public function invitations($args, $assoc_args) {
			unset($args);
			$this->emit_rows(DBVC_Agency_CLI_Inspector::invitations($assoc_args), 'invitations', ['invitation_id', 'client_id', 'environment_label', 'environment_id', 'state', 'expires_at', 'consumed_environment_id'], $assoc_args);
		}

		/**
		 * @param array|WP_Error $result
		 * @return void
		 */
		private function emit_json($result) {
			if (is_wp_error($result)) {
				WP_CLI::error($result->get_error_message());
			}
			WP_CLI::line(wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
		}

		/**
		 * @param array|WP_Error $result
		 * @param string $rows_key
		 * @param array $default_fields
		 * @param array $assoc_args
		 * @return void
		 */
		private function emit_rows($result, $rows_key, array $default_fields, array $assoc_args) {
			if (is_wp_error($result)) {
				WP_CLI::error($result->get_error_message());
			}
			$format = sanitize_key((string) ($assoc_args['format'] ?? 'table'));
			if (! in_array($format, ['table', 'json'], true)) {
				WP_CLI::error('Format must be table or json.');
			}
			if ($format === 'json') {
				WP_CLI::line(wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
				return;
			}
			$fields = $default_fields;
			if (! empty($assoc_args['fields'])) {
				$fields = array_values(array_filter(array_map('trim', explode(',', (string) $assoc_args['fields']))));
			}
			\WP_CLI\Utils\format_items('table', (array) ($result[$rows_key] ?? []), $fields);
			WP_CLI::log(sprintf('Returned %d row(s).', (int) ($result['returned'] ?? 0)));
		}
	}

	WP_CLI::add_command('dbvc agency', 'DBVC_WP_CLI_Agency');
}
