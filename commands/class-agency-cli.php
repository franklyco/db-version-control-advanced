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
			// Annotate each row with the pair's approved-override ("locked") state so
			// the Compare view can show which objects are protected and offer inline
			// lock/unlock, resolving through the same identity the engine uses.
			$override_store = new \Dbvc\AgencyControl\Storage\OverrideStore();
			$source_locked = $override_store->locked_for_environment((string) ($assoc_args['source'] ?? ''));
			$target_locked = $override_store->locked_for_environment((string) ($assoc_args['target'] ?? ''));
			$result['rows'] = array_map(function ($row) use ($source_locked, $target_locked) {
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
				$source_key = $row['domain'] . '|' . $row['source_instance_uid'];
				$target_key = $row['domain'] . '|' . $row['target_instance_uid'];
				$row['source_override'] = isset($source_locked[$source_key]) ? (string) $source_locked[$source_key]['state'] : '';
				$row['target_override'] = isset($target_locked[$target_key]) ? (string) $target_locked[$target_key]['state'] : '';
				$row['target_override_definition'] = isset($target_locked[$target_key]) ? (string) $target_locked[$target_key]['definition_uid'] : '';
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
		 * Propose cross-site identity links by name/token (M9 slice 0). Read-only.
		 *
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		public static function link_proposals(array $assoc_args) {
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}

			return (new \Dbvc\AgencyControl\Comparison\InstanceLinkProposer())->propose(
				(string) ($assoc_args['source'] ?? ''),
				(string) ($assoc_args['target'] ?? ''),
				isset($assoc_args['domain']) ? (string) $assoc_args['domain'] : null
			);
		}

		/**
		 * Accept proposed identity links (M9 slice 0): --all, an explicit
		 * --source-instance/--target-instance pair, or a `pairs` array (REST).
		 * Only a pair that is still a current proposal is created.
		 *
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		public static function link_accept(array $assoc_args) {
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}
			$domain = isset($assoc_args['domain']) && (string) $assoc_args['domain'] !== '' ? (string) $assoc_args['domain'] : null;
			if (! empty($assoc_args['all'])) {
				$pairs = 'all';
			} elseif (isset($assoc_args['pairs']) && is_array($assoc_args['pairs'])) {
				$pairs = $assoc_args['pairs'];
			} elseif ((string) ($assoc_args['source-instance'] ?? '') !== '' && (string) ($assoc_args['target-instance'] ?? '') !== '') {
				$pairs = [[
					'domain' => (string) ($assoc_args['domain'] ?? ''),
					'source_instance_uid' => (string) $assoc_args['source-instance'],
					'target_instance_uid' => (string) $assoc_args['target-instance'],
				]];
			} else {
				return new WP_Error('dbvc_agency_link_accept_no_pairs', 'Provide --all, a --source-instance/--target-instance pair (with --domain), or a pairs array.', ['status' => 400]);
			}

			return (new \Dbvc\AgencyControl\Comparison\InstanceLinkProposer())->accept(
				(string) ($assoc_args['source'] ?? ''),
				(string) ($assoc_args['target'] ?? ''),
				$pairs,
				$domain
			);
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
		 * Fleet rollup for the hub dashboard (M9 slice 3): one row per enrolled
		 * client environment with a composed site status, framework version
		 * distance, locked-override count and freshness. Read-only.
		 *
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		public static function fleet(array $assoc_args) {
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}

			return (new \Dbvc\AgencyControl\Fleet\FleetService())->overview(
				isset($assoc_args['agency']) ? (string) $assoc_args['agency'] : null,
				isset($assoc_args['client']) ? (string) $assoc_args['client'] : null,
				isset($assoc_args['segment']) ? (string) $assoc_args['segment'] : null
			);
		}

		/**
		 * Set, add, or remove an environment's segment tags (M9 slice 5).
		 *
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		public static function environment_tag(array $assoc_args) {
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}
			$environment = (string) ($assoc_args['environment'] ?? '');
			$tags = array_filter(array_map('trim', explode(',', (string) ($assoc_args['tags'] ?? ''))), static function ($t) {
				return $t !== '';
			});
			$mode = (string) ($assoc_args['mode'] ?? 'set');
			$service = new \Dbvc\AgencyControl\Enrollment\SegmentService();
			if ($mode === 'add') {
				return $service->add_tags($environment, $tags);
			}
			if ($mode === 'remove') {
				return $service->remove_tags($environment, $tags);
			}

			return $service->set_tags($environment, $tags);
		}

		/**
		 * List segments (distinct environment tags) with their environment counts.
		 *
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		public static function segments(array $assoc_args) {
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}

			return (new \Dbvc\AgencyControl\Enrollment\SegmentService())->segments(
				isset($assoc_args['agency']) ? (string) $assoc_args['agency'] : null,
				isset($assoc_args['client']) ? (string) $assoc_args['client'] : null
			);
		}

		/**
		 * Publish a framework version (M9 slice 4b): stamp one version label
		 * across a golden source's objects, each an immutable per-object
		 * definition version read from the source's current projection, and
		 * mark each desired unless --no-desire. Objects arrive as a structured
		 * `objects` array (REST), a JSON `--objects`, or a compact
		 * `--items=definition_uid:domain:instance_uid:profile,...`.
		 *
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		public static function framework_publish(array $assoc_args) {
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}
			$objects = self::parse_framework_objects($assoc_args);
			if (is_wp_error($objects)) {
				return $objects;
			}

			return (new \Dbvc\AgencyControl\Framework\FrameworkPublishService())->publish([
				'agency_id' => (string) ($assoc_args['agency'] ?? 'studio'),
				'source_environment_id' => (string) ($assoc_args['source'] ?? ''),
				'version' => (string) ($assoc_args['version'] ?? ''),
				'channel' => (string) ($assoc_args['channel'] ?? 'stable'),
				'note' => (string) ($assoc_args['note'] ?? ''),
				'desire' => empty($assoc_args['no-desire']),
				'objects' => $objects,
			]);
		}

		/**
		 * Read-only rollout plan for a framework version (M9 slice 4b): the
		 * action a rollout of the given (or all desired) definitions would take
		 * on each subscribing environment, honouring approved overrides. Applies
		 * nothing.
		 *
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		public static function framework_plan(array $assoc_args) {
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}

			return (new \Dbvc\AgencyControl\Framework\FrameworkPublishService())->plan([
				'agency_id' => (string) ($assoc_args['agency'] ?? 'studio'),
				'definitions' => isset($assoc_args['definitions']) ? $assoc_args['definitions'] : (isset($assoc_args['definition']) ? (string) $assoc_args['definition'] : ''),
				'cohorts' => isset($assoc_args['cohorts']) ? $assoc_args['cohorts'] : '',
				'client_id' => isset($assoc_args['client']) ? (string) $assoc_args['client'] : '',
				'segment' => isset($assoc_args['segment']) ? (string) $assoc_args['segment'] : '',
			]);
		}

		/**
		 * Normalise the objects of a framework publish from a structured array
		 * (REST), a JSON string, or a compact definition_uid:domain:instance:profile list.
		 *
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		private static function parse_framework_objects(array $assoc_args) {
			if (isset($assoc_args['objects']) && is_array($assoc_args['objects'])) {
				return $assoc_args['objects'];
			}
			if (isset($assoc_args['objects']) && is_string($assoc_args['objects']) && trim((string) $assoc_args['objects']) !== '') {
				$decoded = json_decode((string) $assoc_args['objects'], true);
				if (! is_array($decoded)) {
					return new WP_Error('dbvc_agency_invalid_objects', '--objects must be a JSON array of {definition_uid, domain, instance_uid, profile}.', ['status' => 400]);
				}

				return $decoded;
			}
			$objects = [];
			foreach (array_filter(array_map('trim', explode(',', (string) ($assoc_args['items'] ?? '')))) as $spec) {
				// definition_uid:domain:instance_uid:profile, where the domain may itself
				// contain a colon (wp.bricks:components, wp.post:page). definition_uid,
				// instance_uid and profile never do, so anchor on the ends and treat
				// everything between as the (possibly multi-segment) domain.
				$parts = explode(':', $spec);
				if (count($parts) < 4) {
					return new WP_Error('dbvc_agency_invalid_objects', 'Each item is definition_uid:domain:instance_uid:profile.', ['status' => 400]);
				}
				$definition_uid = (string) array_shift($parts);
				$profile = (string) array_pop($parts);
				$instance_uid = (string) array_pop($parts);
				$domain = implode(':', $parts);
				if ($definition_uid === '' || $domain === '' || $instance_uid === '' || $profile === '') {
					return new WP_Error('dbvc_agency_invalid_objects', 'Each item is definition_uid:domain:instance_uid:profile.', ['status' => 400]);
				}
				$objects[] = ['definition_uid' => $definition_uid, 'domain' => $domain, 'instance_uid' => $instance_uid, 'profile' => $profile];
			}

			return $objects;
		}

		/**
		 * Start a framework rollout campaign (M9 slice 4c): enrol the fleet for a
		 * published framework version. Builds nothing yet — advance drives it.
		 *
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		public static function framework_rollout_start(array $assoc_args) {
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}

			return (new \Dbvc\AgencyControl\Framework\FrameworkRolloutService())->start([
				'agency_id' => (string) ($assoc_args['agency'] ?? 'studio'),
				'definitions' => isset($assoc_args['definitions']) ? $assoc_args['definitions'] : '',
				'channel' => (string) ($assoc_args['channel'] ?? 'stable'),
				'targets' => isset($assoc_args['targets']) ? $assoc_args['targets'] : '',
				'cohorts' => isset($assoc_args['cohorts']) ? $assoc_args['cohorts'] : '',
				'segment' => isset($assoc_args['segment']) ? (string) $assoc_args['segment'] : '',
				'note' => (string) ($assoc_args['note'] ?? ''),
			]);
		}

		/**
		 * Resume a paused framework rollout campaign (after retrying/accepting the failed targets).
		 *
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		public static function framework_rollout_resume(array $assoc_args) {
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}

			return (new \Dbvc\AgencyControl\Framework\FrameworkRolloutService())->resume((string) ($assoc_args['campaign'] ?? ''));
		}

		/**
		 * Reset a failed target in a framework rollout campaign to pending so the driver re-attempts it.
		 *
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		public static function framework_rollout_retry(array $assoc_args) {
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}

			return (new \Dbvc\AgencyControl\Framework\FrameworkRolloutService())->retry((string) ($assoc_args['campaign'] ?? ''), (string) ($assoc_args['target'] ?? ''));
		}

		/**
		 * Advance a framework rollout campaign one re-entrant step.
		 *
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		public static function framework_rollout_advance(array $assoc_args) {
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}

			return (new \Dbvc\AgencyControl\Framework\FrameworkRolloutService())->advance((string) ($assoc_args['campaign'] ?? ''));
		}

		/**
		 * Run every hub scheduler tick once (parity + framework rollouts), scan
		 * drift and flush notifications. The single entrypoint for an external
		 * scheduler.
		 *
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		public static function scheduler_run(array $assoc_args) {
			unset($assoc_args);
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}

			return \Dbvc\AgencyControl\Scheduler\SchedulerRunner::run_all();
		}

		/**
		 * Report hub scheduler health (last run, age, overdue flag, next WP-Cron
		 * run). A pure read — reports regardless of gate state so a stalled or
		 * never-run scheduler is still visible.
		 *
		 * @param array $assoc_args
		 * @return array
		 */
		public static function scheduler_status(array $assoc_args) {
			unset($assoc_args);

			return \Dbvc\AgencyControl\Scheduler\SchedulerRunner::health();
		}

		/**
		 * Read one framework rollout campaign's status.
		 *
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		public static function framework_rollout(array $assoc_args) {
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}

			return (new \Dbvc\AgencyControl\Framework\FrameworkRolloutService())->status((string) ($assoc_args['campaign'] ?? ''));
		}

		/**
		 * List framework rollout campaigns.
		 *
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		public static function framework_rollouts(array $assoc_args) {
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}

			return (new \Dbvc\AgencyControl\Framework\FrameworkRolloutService())->all(
				isset($assoc_args['agency']) ? (string) $assoc_args['agency'] : null,
				self::bounded_integer($assoc_args['limit'] ?? self::DEFAULT_LIMIT, 1, self::MAX_LIMIT)
			);
		}

		/**
		 * Withdraw a running framework rollout campaign (withdraws each target's
		 * in-flight release/rollout; landed changes are not rolled back here).
		 *
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		public static function framework_rollout_withdraw(array $assoc_args) {
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}

			return (new \Dbvc\AgencyControl\Framework\FrameworkRolloutService())->withdraw((string) ($assoc_args['campaign'] ?? ''));
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
			$selections = self::parse_release_selections($assoc_args);
			if (is_wp_error($selections)) {
				return $selections;
			}
			$result = (new \Dbvc\AgencyControl\Release\ReleaseService())->create((string) ($assoc_args['source'] ?? ''), $selections, (string) ($assoc_args['note'] ?? ''));
			if (is_wp_error($result)) {
				return $result;
			}

			return ['release' => self::release_row($result['release']), 'items' => array_map([self::class, 'release_item_row'], $result['items'])];
		}

		/**
		 * Parse a release's --items (replace) and --delete selection lists into
		 * {domain, instance_uid, operation} rows. The spec is domain:instance_uid,
		 * but a domain may itself contain a colon (wp.bricks:components, wp.post:page),
		 * so the instance_uid is the final colon-separated segment and the domain is
		 * everything before it — never a naive split on the first colon.
		 *
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		private static function parse_release_selections(array $assoc_args) {
			$selections = [];
			foreach (['items' => 'replace', 'delete' => 'delete'] as $key => $operation) {
				foreach (array_filter(array_map('trim', explode(',', (string) ($assoc_args[$key] ?? '')))) as $spec) {
					$parts = explode(':', $spec);
					$instance_uid = count($parts) >= 2 ? (string) array_pop($parts) : '';
					$domain = implode(':', $parts);
					if ($domain === '' || $instance_uid === '') {
						return new WP_Error('dbvc_agency_invalid_selection', 'Each item is domain:instance_uid, for example bricks.global_class:bricks-global-class-abcd1234 or wp.bricks:components:wp-bricks-components-abcd1234.');
					}
					$selections[] = ['domain' => $domain, 'instance_uid' => $instance_uid, 'operation' => $operation];
				}
			}

			return $selections;
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
		 * M9 slice 7a: one JSON snapshot of the agency governance trail — the
		 * event-sourced stores (environments, framework definitions, releases,
		 * approvals, rollouts, framework rollouts, reviews, overrides), each section
		 * scoped by the same per-read filters it accepts. Read-only audit/handoff
		 * artifact; it never writes. (The main plugin's dbvc_activity_log is a
		 * different, non-agency log and is deliberately not included.)
		 *
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		public static function audit_export(array $assoc_args) {
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}
			$sections = ['environments', 'definitions', 'releases', 'approvals', 'rollouts', 'framework_rollouts', 'reviews', 'overrides'];
			$out = [];
			foreach ($sections as $section) {
				$result = self::$section($assoc_args);
				$out[$section] = is_wp_error($result)
					? ['error' => $result->get_error_code(), 'message' => $result->get_error_message()]
					: $result;
			}

			return [
				'package_type' => 'dbvc_agency_audit_export',
				'package_schema_version' => 1,
				'generated_at' => gmdate('c'),
				'filters' => array_intersect_key($assoc_args, array_flip(['source', 'agency', 'client', 'environment', 'definition', 'release', 'target', 'state', 'limit'])),
				'sections' => $out,
			];
		}

		/**
		 * M8 parity: create or update a per-pair sync policy.
		 *
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		public static function sync_policy_set(array $assoc_args) {
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}
			$args = [
				'source' => (string) ($assoc_args['source'] ?? ''),
				'target' => (string) ($assoc_args['target'] ?? ''),
			];
			foreach (['mode', 'conflict_policy', 'direction', 'cadence', 'note'] as $key) {
				if (array_key_exists($key, $assoc_args)) {
					$args[$key] = (string) $assoc_args[$key];
				}
			}
			foreach (['scope' => 'scope_domains', 'include' => 'include_uids', 'exclude' => 'exclude_uids'] as $flag => $field) {
				if (array_key_exists($flag, $assoc_args)) {
					$args[$field] = array_values(array_filter(array_map('trim', explode(',', (string) $assoc_args[$flag]))));
				}
			}
			foreach (['create_new', 'propagate_deletions', 'enabled'] as $key) {
				if (array_key_exists($key, $assoc_args)) {
					$args[$key] = filter_var($assoc_args[$key], FILTER_VALIDATE_BOOLEAN);
				}
			}
			if (array_key_exists('max_objects', $assoc_args)) {
				$args['max_objects'] = (int) $assoc_args['max_objects'];
			}
			$result = (new \Dbvc\AgencyControl\Parity\SyncPolicyService())->set($args);

			return is_wp_error($result) ? $result : ['policy' => $result];
		}

		/**
		 * M8 parity: one policy (source+target) or a list.
		 *
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		public static function sync_policies(array $assoc_args) {
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}
			$service = new \Dbvc\AgencyControl\Parity\SyncPolicyService();
			if (! empty($assoc_args['source']) && ! empty($assoc_args['target'])) {
				$one = $service->get((string) $assoc_args['source'], (string) $assoc_args['target']);
				if ($one === null) {
					return new WP_Error('dbvc_agency_sync_policy_not_found', 'No sync policy for that pair.');
				}

				return ['policy' => $one];
			}
			$rows = $service->all([
				'agency' => isset($assoc_args['agency']) ? (string) $assoc_args['agency'] : null,
				'client' => isset($assoc_args['client']) ? (string) $assoc_args['client'] : null,
				'limit' => self::bounded_integer($assoc_args['limit'] ?? self::DEFAULT_LIMIT, 1, self::MAX_LIMIT),
			]);

			return ['returned' => count($rows), 'policies' => $rows];
		}

		/**
		 * M8 parity: delete a pair's sync policy.
		 *
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		public static function sync_policy_delete(array $assoc_args) {
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}

			return (new \Dbvc\AgencyControl\Parity\SyncPolicyService())->delete((string) ($assoc_args['source'] ?? ''), (string) ($assoc_args['target'] ?? ''));
		}

		/**
		 * M8 parity: read-only parity view (comparison counts + a dry-run proposal).
		 *
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		public static function parity(array $assoc_args) {
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}

			return (new \Dbvc\AgencyControl\Parity\SyncDriver())->parity((string) ($assoc_args['source'] ?? ''), (string) ($assoc_args['target'] ?? ''));
		}

		/**
		 * M8 parity: auto-propose a release for the pair's outgoing objects (assisted).
		 *
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		public static function sync_now(array $assoc_args) {
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}
			$result = (new \Dbvc\AgencyControl\Parity\SyncDriver())->sync_now(
				(string) ($assoc_args['source'] ?? ''),
				(string) ($assoc_args['target'] ?? ''),
				filter_var($assoc_args['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN)
			);
			if (is_wp_error($result)) {
				return $result;
			}
			if (isset($result['release']) && is_array($result['release'])) {
				$result['release'] = self::release_row($result['release']);
			}

			return $result;
		}

		/**
		 * M8 parity: drive an auto policy one reconcile step (propose -> seal -> rollout -> apply -> clear).
		 *
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		public static function sync_advance(array $assoc_args) {
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}
			$result = (new \Dbvc\AgencyControl\Parity\SyncDriver())->advance(
				(string) ($assoc_args['source'] ?? ''),
				(string) ($assoc_args['target'] ?? '')
			);
			if (is_wp_error($result)) {
				return $result;
			}
			if (isset($result['release']) && is_array($result['release'])) {
				$result['release'] = self::release_row($result['release']);
			}

			return $result;
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
				$receipt = $row['receipt'];
				if (is_array($receipt) && isset($row['release_id'])) {
					$receipt = self::annotate_receipt_incoming_values($receipt, (int) $row['release_id']);
				}
				$out['receipt'] = $receipt;
				$out['hub_notes'] = $row['hub_notes'];
			}

			return $out;
		}

		/**
		 * Attach the incoming (source) value at each changed path of a prepare
		 * receipt so the operator can review the actual values before approving
		 * (M9 slice 2). Source-only: the hub holds the target's hash, not its
		 * content, so the before side stays the hash the receipt already records.
		 * Loads the sealed payload bodies for the release, then delegates to the
		 * pure {@see apply_incoming_values()}.
		 *
		 * @param array $receipt
		 * @param int   $release_id
		 * @return array
		 */
		private static function annotate_receipt_incoming_values(array $receipt, $release_id) {
			$bodies = [];
			foreach ((new \Dbvc\AgencyControl\Storage\ReleaseStore())->items($release_id, true) as $item) {
				$decoded = json_decode((string) ($item['payload'] ?? ''), true);
				if (is_array($decoded)) {
					$bodies[(string) $item['domain'] . '|' . (string) $item['instance_uid']] = $decoded;
				}
			}

			return self::apply_incoming_values($receipt, $bodies);
		}

		/**
		 * Pure enrichment: for each receipt item's `patch.changed_paths`, add the
		 * incoming value from the matching payload body (keyed "domain|instance_uid").
		 * Secret-ish keys are redacted and long/structured values truncated. Public
		 * for unit testing; takes decoded bodies so it needs no database.
		 *
		 * @param array<string, mixed>              $receipt
		 * @param array<string, array<string,mixed>> $bodies "domain|instance_uid" => decoded body
		 * @return array<string, mixed>
		 */
		public static function apply_incoming_values(array $receipt, array $bodies) {
			if (! isset($receipt['items']) || ! is_array($receipt['items'])) {
				return $receipt;
			}
			foreach ($receipt['items'] as &$item) {
				if (! is_array($item) || ! isset($item['patch']['changed_paths']) || ! is_array($item['patch']['changed_paths'])) {
					continue;
				}
				$body = $bodies[(string) ($item['domain'] ?? '') . '|' . (string) ($item['instance_uid'] ?? '')] ?? null;
				foreach ($item['patch']['changed_paths'] as &$changed) {
					if (! is_array($changed) || ! isset($changed['path'])) {
						continue;
					}
					$changed['after_value'] = $body === null
						? null
						: self::format_incoming_value(self::value_at_path($body, (string) $changed['path']), (string) $changed['path']);
				}
				unset($changed);
			}
			unset($item);

			return $receipt;
		}

		/**
		 * Read a dot-path (the shape the connector's patch records) out of a decoded
		 * body; numeric segments index arrays. Returns null when any segment is absent.
		 *
		 * @param mixed  $body
		 * @param string $path
		 * @return mixed
		 */
		private static function value_at_path($body, $path) {
			$node = $body;
			foreach (explode('.', $path) as $segment) {
				if (is_array($node) && array_key_exists($segment, $node)) {
					$node = $node[$segment];
					continue;
				}
				return null;
			}

			return $node;
		}

		/**
		 * Render one incoming value for review: redact secret-ish keys, stringify
		 * scalars/booleans, JSON-encode structures, and truncate long values (CSS
		 * and element trees can be large).
		 *
		 * @param mixed  $value
		 * @param string $path
		 * @return string
		 */
		private static function format_incoming_value($value, $path) {
			foreach (explode('.', strtolower($path)) as $segment) {
				if (preg_match('/pass(word)?|secret|token|api[-_]?key|private[-_]?key|nonce|credential|(^|_)auth($|_)/', $segment)) {
					return '[redacted]';
				}
			}
			if ($value === null) {
				return '(absent)';
			}
			if (is_bool($value)) {
				return $value ? 'true' : 'false';
			}
			$string = is_scalar($value) ? (string) $value : (string) wp_json_encode($value);
			$string = trim(preg_replace('/\s+/', ' ', $string));
			if (mb_strlen($string) > 200) {
				return mb_substr($string, 0, 200) . '… (' . mb_strlen($string) . ' chars)';
			}

			return $string;
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
		 * M9 slice 7c: set a maintenance-freeze window on one environment.
		 *
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		public static function freeze_set(array $assoc_args) {
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}

			return (new \Dbvc\AgencyControl\Governance\FreezeService())->set(
				(string) ($assoc_args['environment'] ?? ''),
				(string) ($assoc_args['from'] ?? ''),
				(string) ($assoc_args['until'] ?? ''),
				(string) ($assoc_args['reason'] ?? '')
			);
		}

		/**
		 * M9 slice 7c: clear the maintenance-freeze window on one environment.
		 *
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		public static function freeze_clear(array $assoc_args) {
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}

			return (new \Dbvc\AgencyControl\Governance\FreezeService())->clear((string) ($assoc_args['environment'] ?? ''));
		}

		/**
		 * M9 slice 7b (completion): list the non-administrator users holding each
		 * hub capability (publisher / approver). Read-only.
		 *
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		public static function roles(array $assoc_args) {
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}

			return (new \Dbvc\AgencyControl\Governance\RoleService())->assignments(
				self::bounded_integer($assoc_args['limit'] ?? self::DEFAULT_LIMIT, 1, self::MAX_LIMIT)
			);
		}

		/**
		 * M9 slice 7b (completion): grant one hub capability (publisher|approver)
		 * to a non-administrator user.
		 *
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		public static function role_grant(array $assoc_args) {
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}

			return (new \Dbvc\AgencyControl\Governance\RoleService())->grant(
				(string) ($assoc_args['user'] ?? ''),
				(string) ($assoc_args['role'] ?? '')
			);
		}

		/**
		 * M9 slice 7b (completion): revoke one hub capability (publisher|approver)
		 * from a user.
		 *
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		public static function role_revoke(array $assoc_args) {
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}

			return (new \Dbvc\AgencyControl\Governance\RoleService())->revoke(
				(string) ($assoc_args['user'] ?? ''),
				(string) ($assoc_args['role'] ?? '')
			);
		}

		/**
		 * M9 slice 7d: create/update an outbound notification channel.
		 *
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		public static function notification_channel_set(array $assoc_args) {
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}
			$events = array_values(array_filter(array_map('trim', explode(',', (string) ($assoc_args['events'] ?? '')))));

			return (new \Dbvc\AgencyControl\Notifications\NotificationService())->save_channel([
				'channel_uid' => (string) ($assoc_args['channel'] ?? ''),
				'type' => (string) ($assoc_args['type'] ?? 'webhook'),
				'target' => (string) ($assoc_args['target'] ?? ''),
				'agency_id' => (string) ($assoc_args['agency'] ?? ''),
				'events' => $events,
				'secret' => (string) ($assoc_args['secret'] ?? ''),
				'enabled' => ! array_key_exists('disabled', $assoc_args),
			]);
		}

		/**
		 * M9 slice 7d: delete a notification channel.
		 *
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		public static function notification_channel_delete(array $assoc_args) {
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}

			return (new \Dbvc\AgencyControl\Notifications\NotificationService())->delete_channel((string) ($assoc_args['channel'] ?? ''));
		}

		/**
		 * M9 slice 7d: list notification channels (secrets masked).
		 *
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		public static function notifications(array $assoc_args) {
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}

			return ['channels' => (new \Dbvc\AgencyControl\Notifications\NotificationService())->channels(isset($assoc_args['agency']) ? (string) $assoc_args['agency'] : null)];
		}

		/**
		 * M9 slice 7d: read the notification delivery log.
		 *
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		public static function notification_log(array $assoc_args) {
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}

			return ['log' => (new \Dbvc\AgencyControl\Notifications\NotificationService())->log_entries(
				self::bounded_integer($assoc_args['limit'] ?? self::DEFAULT_LIMIT, 1, self::MAX_LIMIT),
				isset($assoc_args['status']) ? (string) $assoc_args['status'] : null
			)];
		}

		/**
		 * M9 slice 7d: send pending notifications now (also runs on the hourly tick).
		 *
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		public static function notification_flush(array $assoc_args) {
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}

			return (new \Dbvc\AgencyControl\Notifications\NotificationService())->flush(self::bounded_integer($assoc_args['limit'] ?? 25, 1, self::MAX_LIMIT));
		}

		/**
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		public static function environment_reassign(array $assoc_args) {
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}

			return (new \Dbvc\AgencyControl\Enrollment\EnrollmentService())->reassign(
				(string) ($assoc_args['environment'] ?? ''),
				(string) ($assoc_args['client'] ?? ''),
				(string) ($assoc_args['agency'] ?? '')
			);
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
		 * Export the agency governance trail as one JSON snapshot (audit/handoff).
		 *
		 * Bundles the event-sourced stores (environments, definitions, releases,
		 * approvals, rollouts, framework rollouts, reviews, overrides); each section
		 * honours the same filters its own read accepts. Read-only.
		 *
		 * ## OPTIONS
		 *
		 * [--agency=<id>]
		 * : Filter sections that support an agency scope.
		 *
		 * [--client=<id>]
		 * : Filter sections that support a client scope.
		 *
		 * [--source=<env>]
		 * : Filter releases by source environment.
		 *
		 * [--environment=<id>]
		 * : Filter sections that support an environment scope.
		 *
		 * [--limit=<number>]
		 * : Maximum rows per section. Default: 50; maximum: 500.
		 *
		 * @subcommand audit-export
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 */
		public function audit_export($args, $assoc_args) {
			unset($args);
			$this->emit_json(DBVC_Agency_CLI_Inspector::audit_export($assoc_args));
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
		 * Propose cross-site identity links by name/token match (read-only).
		 *
		 * ## OPTIONS
		 *
		 * --source=<environment_id>
		 * : Source environment.
		 *
		 * --target=<environment_id>
		 * : Target environment (same agency and client as the source).
		 *
		 * [--domain=<domain>]
		 * : Restrict to one domain (e.g. bricks.global_class). Default: all.
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 * @subcommand link-proposals
		 */
		public function link_proposals($args, $assoc_args) {
			unset($args);
			$this->emit_json(DBVC_Agency_CLI_Inspector::link_proposals($assoc_args));
		}

		/**
		 * Accept proposed identity links; only current, unambiguous proposals are created.
		 *
		 * ## OPTIONS
		 *
		 * --source=<environment_id>
		 * : Source environment.
		 *
		 * --target=<environment_id>
		 * : Target environment.
		 *
		 * [--all]
		 * : Accept every current proposal for the pair.
		 *
		 * [--domain=<domain>]
		 * : Domain filter, and the domain of an explicit pair.
		 *
		 * [--source-instance=<uid>]
		 * : Source instance UID of a single explicit pair (with --target-instance and --domain).
		 *
		 * [--target-instance=<uid>]
		 * : Target instance UID of a single explicit pair.
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 * @subcommand link-accept
		 */
		public function link_accept($args, $assoc_args) {
			unset($args);
			$this->emit_json(DBVC_Agency_CLI_Inspector::link_accept($assoc_args));
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
		 * Fleet rollup for the hub dashboard: one row per enrolled client environment
		 * with a composed status, framework version distance, locked-override count and freshness.
		 *
		 * ## OPTIONS
		 *
		 * [--agency=<id>]
		 * : Restrict to one agency.
		 *
		 * [--client=<id>]
		 * : Restrict to one client.
		 *
		 * [--segment=<tag>]
		 * : Restrict to sites carrying this segment tag.
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 * @subcommand fleet
		 */
		public function fleet($args, $assoc_args) {
			unset($args);
			$this->emit_json(DBVC_Agency_CLI_Inspector::fleet($assoc_args));
		}

		/**
		 * Set, add, or remove an environment's segment tags (e.g. production, a vertical, a tier).
		 *
		 * ## OPTIONS
		 *
		 * --environment=<id>
		 * : Environment identifier.
		 *
		 * --tags=<list>
		 * : Comma-separated tag slugs ([a-z0-9_-], lowercased).
		 *
		 * [--mode=<mode>]
		 * : set (replace, default), add, or remove.
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 * @subcommand environment-tag
		 */
		public function environment_tag($args, $assoc_args) {
			unset($args);
			$this->emit_json(DBVC_Agency_CLI_Inspector::environment_tag($assoc_args));
		}

		/**
		 * List segments (distinct environment tags) with their environment counts.
		 *
		 * ## OPTIONS
		 *
		 * [--agency=<id>]
		 * : Restrict to one agency.
		 *
		 * [--client=<id>]
		 * : Restrict to one client.
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 * @subcommand segments
		 */
		public function segments($args, $assoc_args) {
			unset($args);
			$this->emit_json(DBVC_Agency_CLI_Inspector::segments($assoc_args));
		}

		/**
		 * Publish a framework version: stamp one version label across a golden source's objects and mark each desired.
		 *
		 * ## OPTIONS
		 *
		 * --source=<environment_id>
		 * : Golden source environment the objects are read from.
		 *
		 * --version=<version>
		 * : Version label stamped across every object (each object's own version history).
		 *
		 * --items=<list>
		 * : Comma-separated definition_uid:domain:instance_uid:profile objects (the domain may itself contain a colon, e.g. wp.bricks:components). Or pass --objects=<json>.
		 *
		 * [--objects=<json>]
		 * : JSON array of {definition_uid, domain, instance_uid, profile}; overrides --items.
		 *
		 * [--agency=<id>]
		 * : Agency identifier. Default: studio.
		 *
		 * [--channel=<channel>]
		 * : Channel. Default: stable.
		 *
		 * [--no-desire]
		 * : Publish without marking the versions desired on their channel.
		 *
		 * [--note=<text>]
		 * : Short note.
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 * @subcommand framework-publish
		 */
		public function framework_publish($args, $assoc_args) {
			unset($args);
			$this->emit_json(DBVC_Agency_CLI_Inspector::framework_publish($assoc_args));
		}

		/**
		 * Read-only rollout plan for a framework version: the action a rollout would take on each subscribing environment, honouring approved overrides. Applies nothing.
		 *
		 * ## OPTIONS
		 *
		 * [--definitions=<list>]
		 * : Comma-separated definition UIDs to plan. Default: all definitions with a desired version.
		 *
		 * [--cohorts=<list>]
		 * : Comma-separated environment IDs to restrict targets to. Default: all subscribers.
		 *
		 * [--agency=<id>]
		 * : Agency identifier. Default: studio.
		 *
		 * [--client=<id>]
		 * : Restrict to one client.
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 * @subcommand framework-plan
		 */
		public function framework_plan($args, $assoc_args) {
			unset($args);
			$this->emit_json(DBVC_Agency_CLI_Inspector::framework_plan($assoc_args));
		}

		/**
		 * Start a framework rollout campaign: publish once, land everywhere. Enrols the same-client fleet for a published framework version; each target rides its own override-aware release + single-target rollout.
		 *
		 * ## OPTIONS
		 *
		 * [--definitions=<list>]
		 * : Comma-separated definition UIDs. Default: all definitions with a desired version on the channel.
		 *
		 * [--targets=<list>]
		 * : Comma-separated target environment IDs. Default: every subscriber under the agency.
		 *
		 * [--cohorts=<json>]
		 * : Ordered staged rollout as a JSON array of target-id arrays, canary first, e.g. [["canary-env"],["env-a","env-b"]]. Overrides --targets; the fleet pauses if a cohort fails.
		 *
		 * [--agency=<id>]
		 * : Agency identifier. Default: studio.
		 *
		 * [--channel=<channel>]
		 * : Channel. Default: stable.
		 *
		 * [--note=<text>]
		 * : Short note.
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 * @subcommand framework-rollout-start
		 */
		public function framework_rollout_start($args, $assoc_args) {
			unset($args);
			$this->emit_json(DBVC_Agency_CLI_Inspector::framework_rollout_start($assoc_args));
		}

		/**
		 * Resume a paused framework rollout campaign (after retrying or accepting the failed targets).
		 *
		 * ## OPTIONS
		 *
		 * --campaign=<uid>
		 * : Campaign UID.
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 * @subcommand framework-rollout-resume
		 */
		public function framework_rollout_resume($args, $assoc_args) {
			unset($args);
			$this->emit_json(DBVC_Agency_CLI_Inspector::framework_rollout_resume($assoc_args));
		}

		/**
		 * Reset a failed target in a framework rollout campaign to pending so the driver re-attempts it.
		 *
		 * ## OPTIONS
		 *
		 * --campaign=<uid>
		 * : Campaign UID.
		 *
		 * --target=<environment_id>
		 * : The failed target environment to retry.
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 * @subcommand framework-rollout-retry
		 */
		public function framework_rollout_retry($args, $assoc_args) {
			unset($args);
			$this->emit_json(DBVC_Agency_CLI_Inspector::framework_rollout_retry($assoc_args));
		}

		/**
		 * Advance a framework rollout campaign one re-entrant step (build → seal → rollout → verify per target).
		 *
		 * ## OPTIONS
		 *
		 * --campaign=<uid>
		 * : Campaign UID from framework-rollout-start.
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 * @subcommand framework-rollout-advance
		 */
		public function framework_rollout_advance($args, $assoc_args) {
			unset($args);
			$this->emit_json(DBVC_Agency_CLI_Inspector::framework_rollout_advance($assoc_args));
		}

		/**
		 * Run every hub scheduler tick once (parity + framework rollouts), then scan drift and flush notifications. The single entrypoint for an external scheduler: set define('DISABLE_WP_CRON', true) and add a system crontab line such as `* * * * * cd <wp> && wp dbvc agency scheduler-run` so ticks no longer depend on a page being visited. Idempotent and safe at any cadence.
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 * @subcommand scheduler-run
		 */
		public function scheduler_run($args, $assoc_args) {
			unset($args);
			$this->emit_json(DBVC_Agency_CLI_Inspector::scheduler_run($assoc_args));
		}

		/**
		 * Report hub scheduler health: each tick's last run time, age, overdue flag, and next WP-Cron run. overdue=true means a tick has not run within two hours — check that WP-Cron is firing or that your external scheduler-run cron is installed.
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 * @subcommand scheduler-status
		 */
		public function scheduler_status($args, $assoc_args) {
			unset($args);
			$this->emit_json(DBVC_Agency_CLI_Inspector::scheduler_status($assoc_args));
		}

		/**
		 * Show one framework rollout campaign's status (per-target release/rollout state).
		 *
		 * ## OPTIONS
		 *
		 * --campaign=<uid>
		 * : Campaign UID.
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 * @subcommand framework-rollout
		 */
		public function framework_rollout($args, $assoc_args) {
			unset($args);
			$this->emit_json(DBVC_Agency_CLI_Inspector::framework_rollout($assoc_args));
		}

		/**
		 * List framework rollout campaigns.
		 *
		 * ## OPTIONS
		 *
		 * [--agency=<id>]
		 * : Restrict to one agency.
		 *
		 * [--limit=<number>]
		 * : Maximum rows. Default: 50; maximum: 500.
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 * @subcommand framework-rollouts
		 */
		public function framework_rollouts($args, $assoc_args) {
			unset($args);
			$this->emit_json(DBVC_Agency_CLI_Inspector::framework_rollouts($assoc_args));
		}

		/**
		 * Withdraw a running framework rollout campaign (withdraws each target's in-flight release/rollout; landed changes are not rolled back here — use reviewed rollback).
		 *
		 * ## OPTIONS
		 *
		 * --campaign=<uid>
		 * : Campaign UID.
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 * @subcommand framework-rollout-withdraw
		 */
		public function framework_rollout_withdraw($args, $assoc_args) {
			unset($args);
			$this->emit_json(DBVC_Agency_CLI_Inspector::framework_rollout_withdraw($assoc_args));
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
		 * : Comma-separated domain:instance_uid selections to replace on targets. The domain may itself contain a colon (e.g. wp.bricks:components:wp-bricks-components-abcd1234); the instance_uid is the final colon-separated segment.
		 *
		 * [--delete=<list>]
		 * : Comma-separated domain:instance_uid selections whose verified absence on the source is released as a deletion. Same domain:instance_uid form as --items (colon-bearing domains supported).
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
		 * Set a maintenance-freeze window on an environment (suppresses new applies
		 * while active; the connector apply gate stays authoritative).
		 *
		 * ## OPTIONS
		 *
		 * --environment=<id>
		 * : Environment identifier.
		 *
		 * --from=<datetime>
		 * : Window start, parsed as UTC (e.g. 2026-10-06T00:00:00Z).
		 *
		 * --until=<datetime>
		 * : Window end (UTC); must be after --from.
		 *
		 * [--reason=<text>]
		 * : Optional operator note.
		 *
		 * @subcommand freeze-set
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 */
		public function freeze_set($args, $assoc_args) {
			unset($args);
			$this->emit_json(DBVC_Agency_CLI_Inspector::freeze_set($assoc_args));
		}

		/**
		 * Clear an environment's maintenance-freeze window.
		 *
		 * ## OPTIONS
		 *
		 * --environment=<id>
		 * : Environment identifier.
		 *
		 * @subcommand freeze-clear
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 */
		public function freeze_clear($args, $assoc_args) {
			unset($args);
			$this->emit_json(DBVC_Agency_CLI_Inspector::freeze_clear($assoc_args));
		}

		/**
		 * List the non-administrator users holding a hub capability (publisher / approver). Administrators hold both via manage_options and are not listed.
		 *
		 * ## OPTIONS
		 *
		 * [--limit=<n>]
		 * : Maximum number of users to scan. Default 50, max 500.
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 * @subcommand roles
		 */
		public function roles($args, $assoc_args) {
			unset($args);
			$this->emit_json(DBVC_Agency_CLI_Inspector::roles($assoc_args));
		}

		/**
		 * Grant one hub capability (publisher or approver) to a non-administrator user, so an operator can delegate framework publishing or client approving without granting full manage_options.
		 *
		 * ## OPTIONS
		 *
		 * --user=<ref>
		 * : Target user as a numeric ID, login, or email.
		 *
		 * --role=<role>
		 * : Which capability to grant: publisher (dbvc_agency_publish) or approver (dbvc_agency_approve).
		 * ---
		 * options:
		 *   - publisher
		 *   - approver
		 * ---
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 * @subcommand role-grant
		 */
		public function role_grant($args, $assoc_args) {
			unset($args);
			$this->emit_json(DBVC_Agency_CLI_Inspector::role_grant($assoc_args));
		}

		/**
		 * Revoke one hub capability (publisher or approver) from a user.
		 *
		 * ## OPTIONS
		 *
		 * --user=<ref>
		 * : Target user as a numeric ID, login, or email.
		 *
		 * --role=<role>
		 * : Which capability to revoke: publisher (dbvc_agency_publish) or approver (dbvc_agency_approve).
		 * ---
		 * options:
		 *   - publisher
		 *   - approver
		 * ---
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 * @subcommand role-revoke
		 */
		public function role_revoke($args, $assoc_args) {
			unset($args);
			$this->emit_json(DBVC_Agency_CLI_Inspector::role_revoke($assoc_args));
		}

		/**
		 * Create or update an outbound notification channel (webhook or email).
		 *
		 * ## OPTIONS
		 *
		 * --type=<type>
		 * : webhook or email.
		 *
		 * --target=<target>
		 * : An https URL (webhook, public host only) or an email address.
		 *
		 * [--channel=<uid>]
		 * : Channel uid to update; a new channel is created when omitted.
		 *
		 * [--agency=<id>]
		 * : Scope to one agency; omit for fleet-wide.
		 *
		 * [--events=<list>]
		 * : Comma-separated event kinds; omit for all events.
		 *
		 * [--secret=<secret>]
		 * : Optional webhook HMAC secret (signs the X-DBVC-Signature header).
		 *
		 * [--disabled]
		 * : Create the channel disabled.
		 *
		 * @subcommand notification-channel-set
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 */
		public function notification_channel_set($args, $assoc_args) {
			unset($args);
			$this->emit_json(DBVC_Agency_CLI_Inspector::notification_channel_set($assoc_args));
		}

		/**
		 * Delete a notification channel.
		 *
		 * ## OPTIONS
		 *
		 * --channel=<uid>
		 * : Channel uid.
		 *
		 * @subcommand notification-channel-delete
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 */
		public function notification_channel_delete($args, $assoc_args) {
			unset($args);
			$this->emit_json(DBVC_Agency_CLI_Inspector::notification_channel_delete($assoc_args));
		}

		/**
		 * List notification channels (secrets masked).
		 *
		 * ## OPTIONS
		 *
		 * [--agency=<id>]
		 * : Filter by agency.
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 */
		public function notifications($args, $assoc_args) {
			unset($args);
			$this->emit_json(DBVC_Agency_CLI_Inspector::notifications($assoc_args));
		}

		/**
		 * Read the notification delivery log.
		 *
		 * ## OPTIONS
		 *
		 * [--status=<status>]
		 * : Filter by status (pending|sent|failed|skipped).
		 *
		 * [--limit=<number>]
		 * : Maximum rows. Default: 50; maximum: 500.
		 *
		 * @subcommand notification-log
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 */
		public function notification_log($args, $assoc_args) {
			unset($args);
			$this->emit_json(DBVC_Agency_CLI_Inspector::notification_log($assoc_args));
		}

		/**
		 * Send pending notifications now.
		 *
		 * ## OPTIONS
		 *
		 * [--limit=<number>]
		 * : Maximum rows to send. Default: 25; maximum: 500.
		 *
		 * @subcommand notification-flush
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 */
		public function notification_flush($args, $assoc_args) {
			unset($args);
			$this->emit_json(DBVC_Agency_CLI_Inspector::notification_flush($assoc_args));
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
		 * Move an environment to a different client (and optionally agency) so it can pair with that client's fleet.
		 *
		 * Two environments only compare and sync when they share the same agency AND client. Reassign before you
		 * baseline or subscribe the environment; parity data keyed to the old client is left dormant, not migrated.
		 *
		 * ## OPTIONS
		 *
		 * --environment=<id>
		 * : Environment identifier.
		 *
		 * --client=<id>
		 * : The client_id to move the environment to.
		 *
		 * [--agency=<id>]
		 * : The agency_id to move to. Keeps the current agency when omitted.
		 *
		 * @subcommand environment-reassign
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 */
		public function environment_reassign($args, $assoc_args) {
			unset($args);
			$this->emit_json(DBVC_Agency_CLI_Inspector::environment_reassign($assoc_args));
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
		 * Create or update a per-pair parity sync policy.
		 *
		 * ## OPTIONS
		 *
		 * --source=<id>
		 * : Source environment identifier.
		 *
		 * --target=<id>
		 * : Target environment identifier.
		 *
		 * [--mode=<mode>]
		 * : manual, assisted or auto. Default: manual.
		 *
		 * [--conflict_policy=<policy>]
		 * : hold, source_wins or skip. Default: hold.
		 *
		 * [--scope=<domains>]
		 * : Comma-separated domains to cover (empty = all subscribed).
		 *
		 * [--include=<uids>]
		 * : Comma-separated instance UIDs to include (empty = all).
		 *
		 * [--exclude=<uids>]
		 * : Comma-separated instance UIDs to exclude.
		 *
		 * [--create_new=<bool>]
		 * : Propose creating source objects absent on the target. Default: off.
		 *
		 * [--propagate_deletions=<bool>]
		 * : Propose removing baselined objects deleted on the source. Default: off.
		 *
		 * [--max_objects=<n>]
		 * : Cap objects proposed per run. Default: 25.
		 *
		 * [--cadence=<cadence>]
		 * : Scheduler cadence hint. Default: manual.
		 *
		 * [--enabled=<bool>]
		 * : Enable the policy. Default: on.
		 *
		 * [--note=<note>]
		 * : Optional note.
		 *
		 * @subcommand sync-policy-set
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 */
		public function sync_policy_set($args, $assoc_args) {
			unset($args);
			$this->emit_json(DBVC_Agency_CLI_Inspector::sync_policy_set($assoc_args));
		}

		/**
		 * List parity sync policies, or one by --source and --target.
		 *
		 * ## OPTIONS
		 *
		 * [--source=<id>]
		 * : Source environment identifier (with --target, returns one policy).
		 *
		 * [--target=<id>]
		 * : Target environment identifier.
		 *
		 * [--agency=<id>]
		 * : Filter by agency.
		 *
		 * [--client=<id>]
		 * : Filter by client.
		 *
		 * [--limit=<n>]
		 * : Maximum rows.
		 *
		 * @subcommand sync-policies
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 */
		public function sync_policies($args, $assoc_args) {
			unset($args);
			$this->emit_json(DBVC_Agency_CLI_Inspector::sync_policies($assoc_args));
		}

		/**
		 * Delete a pair's parity sync policy.
		 *
		 * ## OPTIONS
		 *
		 * --source=<id>
		 * : Source environment identifier.
		 *
		 * --target=<id>
		 * : Target environment identifier.
		 *
		 * @subcommand sync-policy-delete
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 */
		public function sync_policy_delete($args, $assoc_args) {
			unset($args);
			$this->emit_json(DBVC_Agency_CLI_Inspector::sync_policy_delete($assoc_args));
		}

		/**
		 * Read-only parity view for a pair: comparison counts plus a dry-run proposal.
		 *
		 * ## OPTIONS
		 *
		 * --source=<id>
		 * : Source environment identifier.
		 *
		 * --target=<id>
		 * : Target environment identifier.
		 *
		 * @subcommand parity
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 */
		public function parity($args, $assoc_args) {
			unset($args);
			$this->emit_json(DBVC_Agency_CLI_Inspector::parity($assoc_args));
		}

		/**
		 * Auto-propose a release for the pair's outgoing objects (assisted parity).
		 *
		 * ## OPTIONS
		 *
		 * --source=<id>
		 * : Source environment identifier.
		 *
		 * --target=<id>
		 * : Target environment identifier.
		 *
		 * [--dry_run=<bool>]
		 * : Preview the proposal without creating a release.
		 *
		 * @subcommand sync-now
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 */
		public function sync_now($args, $assoc_args) {
			unset($args);
			$this->emit_json(DBVC_Agency_CLI_Inspector::sync_now($assoc_args));
		}

		/**
		 * Drive an auto sync policy one reconcile step (cron calls this per policy).
		 *
		 * ## OPTIONS
		 *
		 * --source=<id>
		 * : Source environment identifier.
		 *
		 * --target=<id>
		 * : Target environment identifier.
		 *
		 * @subcommand sync-advance
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 */
		public function sync_advance($args, $assoc_args) {
			unset($args);
			$this->emit_json(DBVC_Agency_CLI_Inspector::sync_advance($assoc_args));
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
