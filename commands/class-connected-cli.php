<?php

if (! defined('WPINC')) {
	die;
}

if (! class_exists('DBVC_Connected_CLI_Inspector')) {
	/**
	 * Read-only preparation for the Connected Environments developer CLI, plus
	 * the explicit worker entrypoint. Testable without WP-CLI.
	 */
	final class DBVC_Connected_CLI_Inspector {
		private const DEFAULT_LIMIT = 50;
		private const MAX_LIMIT = 500;

		/**
		 * @return array|WP_Error
		 */
		public static function status() {
			if (! class_exists('DBVC_Connected_Environments_Addon')) {
				return new WP_Error('dbvc_connected_cli_unavailable', 'The Connected Environments add-on is unavailable in this checkout.');
			}

			$gate_state = DBVC_Connected_Environments_Addon::get_gate_state();
			$report = ['gate_state' => $gate_state, 'runtime_registered' => DBVC_Connected_Environments_Addon::is_runtime_registered()];
			if ($gate_state === \Dbvc\ConnectedProtocol\RoleGate::READY || $gate_state === \Dbvc\ConnectedProtocol\RoleGate::MIGRATION_REQUIRED) {
				$report = array_merge($report, \Dbvc\Connected\Inspection\StatusReport::build($gate_state));
			}
			if (class_exists('DBVC_Agency_Control_Addon')) {
				$report['hub'] = [
					'gate_state' => DBVC_Agency_Control_Addon::get_gate_state(),
					'runtime' => DBVC_Agency_Control_Addon::get_runtime_state(),
				];
			}

			return $report;
		}

		/**
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		public static function jobs(array $assoc_args) {
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}
			$limit = self::bounded_integer($assoc_args['limit'] ?? self::DEFAULT_LIMIT, 1, self::MAX_LIMIT);
			$rows = (new \Dbvc\Connected\Storage\JobStore())->all($limit);

			return [
				'counts' => (new \Dbvc\Connected\Storage\JobStore())->counts(),
				'returned' => count($rows),
				'jobs' => array_map(static function ($row) {
					return [
						'job_id' => (int) $row['job_id'],
						'domain' => (string) $row['domain'],
						'object_key' => (string) $row['object_key'],
						'generation' => (int) $row['generation'],
						'claimed_generation' => (int) $row['claimed_generation'],
						'attempts' => (int) $row['attempts'],
						'available_at' => (string) $row['available_at'],
						'leased' => $row['lease_until'] !== null && strtotime((string) $row['lease_until'] . ' UTC') >= time() ? 'yes' : 'no',
						'last_error_code' => (string) ($row['last_error_code'] ?? ''),
						'updated_at' => (string) $row['updated_at'],
					];
				}, $rows),
			];
		}

		/**
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		public static function objects(array $assoc_args) {
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}
			$domain = sanitize_text_field((string) ($assoc_args['domain'] ?? ''));
			if ($domain !== '' && ! in_array($domain, \Dbvc\Connected\Adapters\DomainRegistry::domains(), true)) {
				return new WP_Error('dbvc_connected_cli_domain', 'Unknown domain: ' . $domain);
			}
			$limit = self::bounded_integer($assoc_args['limit'] ?? self::DEFAULT_LIMIT, 1, self::MAX_LIMIT);
			$rows = (new \Dbvc\Connected\Storage\ObjectStore())->all($domain !== '' ? $domain : null, $limit);

			return [
				'counts' => (new \Dbvc\Connected\Storage\ObjectStore())->counts_by_domain(),
				'returned' => count($rows),
				'objects' => array_map(static function ($row) {
					return [
						'domain' => (string) $row['domain'],
						'profile' => (string) $row['profile'],
						'instance_uid' => (string) $row['instance_uid'],
						'storage_key' => (string) $row['storage_key'],
						'display_name' => (string) $row['display_name'],
						'exists' => (int) $row['object_exists'] === 1 ? 'yes' : 'no',
						'complete' => (int) $row['snapshot_complete'] === 1 ? 'yes' : 'no',
						'position' => $row['position'],
						'semantic_hash' => (string) $row['semantic_hash'],
						'observed_sequence' => (int) $row['observed_sequence'],
						'observed_epoch' => (string) $row['observed_epoch'],
						'problems' => implode(',', (array) $row['problems']),
						'updated_at' => (string) $row['updated_at'],
					];
				}, $rows),
			];
		}

		/**
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		public static function outbox(array $assoc_args) {
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}
			$state = sanitize_key((string) ($assoc_args['state'] ?? ''));
			$limit = self::bounded_integer($assoc_args['limit'] ?? self::DEFAULT_LIMIT, 1, self::MAX_LIMIT);
			$rows = (new \Dbvc\Connected\Storage\OutboxStore())->all($state !== '' ? $state : null, $limit);

			return [
				'counts' => (new \Dbvc\Connected\Storage\OutboxStore())->counts(),
				'returned' => count($rows),
				'events' => array_map(static function ($row) {
					$body = is_array($row['body']) ? $row['body'] : [];
					return [
						'event_id' => (string) $row['event_id'],
						'installation_epoch' => (string) $row['installation_epoch'],
						'sequence' => (int) $row['source_sequence'],
						'domain' => (string) $row['domain'],
						'instance_uid' => (string) $row['instance_uid'],
						'profile' => (string) ($body['projection']['profile'] ?? ''),
						'exists' => isset($body['projection']['exists']) ? ($body['projection']['exists'] ? 'yes' : 'no') : '',
						'complete' => isset($body['projection']['complete']) ? ($body['projection']['complete'] ? 'yes' : 'no') : '',
						'hash' => (string) ($body['projection']['hash'] ?? ''),
						'origin' => (string) ($body['origin'] ?? ''),
						'delivery_state' => (string) $row['delivery_state'],
						'body_digest' => (string) $row['body_digest'],
						'created_at' => (string) $row['created_at'],
					];
				}, $rows),
			];
		}

		/**
		 * Read-only observer inventory: current persisted members and whether
		 * they carry a sidecar identity. Never assigns identity.
		 *
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		public static function inventory(array $assoc_args) {
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}
			$domain = sanitize_text_field((string) ($assoc_args['domain'] ?? ''));
			$observer = \Dbvc\Connected\Adapters\DomainRegistry::observer_for($domain);
			if ($observer === null) {
				return new WP_Error('dbvc_connected_cli_domain', 'Provide --domain=<' . implode('|', \Dbvc\Connected\Adapters\DomainRegistry::domains()) . '>.');
			}
			$limit = self::bounded_integer($assoc_args['limit'] ?? self::DEFAULT_LIMIT, 1, self::MAX_LIMIT);
			$cursor = self::bounded_integer($assoc_args['cursor'] ?? 0, 0, PHP_INT_MAX);
			$inventory = $observer->inventory(['cursor' => $cursor, 'limit' => $limit]);
			$identity = new \Dbvc\Connected\Identity\InstanceIdentity();
			$map = $identity->lookup_many($domain, array_map(static function ($item) {
				return $item['storage_key'];
			}, $inventory['items']));

			return [
				'domain' => $domain,
				'profile' => $observer->profile(),
				'status' => $inventory['status'],
				'reason' => $inventory['reason'],
				'complete' => $inventory['complete'],
				'total' => $inventory['total'] ?? 0,
				'next_cursor' => $inventory['next_cursor'],
				'storage_fingerprint' => $inventory['storage_fingerprint'],
				'order_hash' => $inventory['order_hash'],
				'problems' => $inventory['problems'],
				'items' => array_map(static function ($item) use ($map) {
					return [
						'position' => $item['position'],
						'storage_key' => $item['storage_key'],
						'display_name' => $item['display_name'],
						'instance_uid' => isset($map[$item['storage_key']]) ? $map[$item['storage_key']]['instance_uid'] : '',
						'identity' => isset($map[$item['storage_key']]) ? 'mapped' : 'identity_missing',
						'complete' => $item['complete'] ? 'yes' : 'no',
						'hash' => $item['hash'],
					];
				}, $inventory['items']),
			];
		}

		/**
		 * Explicit reconciliation signal (management write; no content writes).
		 *
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		public static function reconcile(array $assoc_args) {
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}
			$domain = sanitize_text_field((string) ($assoc_args['domain'] ?? ''));
			if ($domain !== '' && ! in_array($domain, \Dbvc\Connected\Adapters\DomainRegistry::domains(), true)) {
				return new WP_Error('dbvc_connected_cli_domain', 'Unknown domain: ' . $domain);
			}

			return [
				'generations' => DBVC_Connected_Environments_Addon::request_reconciliation($domain, 'cli-reconcile'),
				'scheduled' => (bool) wp_next_scheduled(\Dbvc\Connected\Capture\DirtyCapture::CRON_HOOK),
			];
		}

		/**
		 * Enroll this environment with a hub using a single-use invitation token.
		 *
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		public static function enroll(array $assoc_args) {
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}
			$hub = trim((string) ($assoc_args['hub'] ?? ''));
			$token = trim((string) ($assoc_args['token'] ?? ''));
			if ($hub === '' || $token === '') {
				return new WP_Error('dbvc_connected_cli_arguments', 'Provide --hub=<url> and --token=<invitation token>.');
			}

			return (new \Dbvc\Connected\Enrollment\EnrollmentService())->exchange($hub, $token);
		}

		/**
		 * Clear a local delivery hold after the hub-side cause was reviewed.
		 *
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		public static function resume(array $assoc_args) {
			unset($assoc_args);
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}
			$store = new \Dbvc\Connected\Storage\StateStore();
			$state = $store->get();
			if (! is_array($state) || $state['enrollment_state'] !== \Dbvc\Connected\Storage\StateStore::ENROLLMENT_ENROLLED) {
				return new WP_Error('dbvc_connected_cli_not_enrolled', 'This environment is not enrolled with a hub.');
			}
			if (in_array($state['hold_reason'], ['credentials_unreadable', 'epoch_mismatch', 'dbvc_agency_environment_revoked', 'dbvc_agency_app_password_required', 'dbvc_agency_not_enrolled'], true)) {
				return new WP_Error('dbvc_connected_cli_reenroll_required', 'Hold reason "' . $state['hold_reason'] . '" requires a fresh enrollment (wp dbvc connected enroll).');
			}
			$store->set_connection_state(\Dbvc\Connected\Storage\StateStore::CONNECTION_ENROLLED, '');
			\Dbvc\Connected\Capture\DirtyCapture::schedule_delivery(\Dbvc\Connected\Capture\DirtyCapture::processing_delay());

			return ['connection_state' => \Dbvc\Connected\Storage\StateStore::CONNECTION_ENROLLED, 'previous_hold_reason' => $state['hold_reason']];
		}

		/**
		 * Run one outbound delivery batch now.
		 *
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		public static function deliver(array $assoc_args) {
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}
			$runtime = DBVC_Connected_Environments_Addon::runtime();
			if (! $runtime instanceof \Dbvc\Connected\Runtime) {
				return new WP_Error('dbvc_connected_cli_runtime', 'The connector runtime is not registered in this process.');
			}

			return $runtime->outbound()->run([
				'context' => 'cli',
				'limit' => self::bounded_integer($assoc_args['limit'] ?? \Dbvc\ConnectedProtocol\Protocol::MAX_BATCH_EVENTS, 1, \Dbvc\ConnectedProtocol\Protocol::MAX_BATCH_EVENTS),
			]);
		}

		/**
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		public static function inbox(array $assoc_args) {
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}
			$store = new \Dbvc\Connected\Storage\InboxStore();
			$rows = $store->all(self::bounded_integer($assoc_args['limit'] ?? self::DEFAULT_LIMIT, 1, self::MAX_LIMIT));

			return [
				'counts' => $store->counts(),
				'returned' => count($rows),
				'items' => array_map(static function ($row) {
					$body = is_array($row['body']) ? $row['body'] : [];
					return [
						'delivery_id' => (int) $row['delivery_id'],
						'source_environment_id' => (string) $row['source_environment_id'],
						'event_id' => (string) $row['event_id'],
						'sequence' => (int) $row['source_sequence'],
						'domain' => (string) $row['domain'],
						'instance_uid' => (string) $row['instance_uid'],
						'profile' => (string) $row['profile'],
						'exists' => isset($body['projection']['exists']) ? ($body['projection']['exists'] ? 'yes' : 'no') : '',
						'hash' => (string) ($body['projection']['hash'] ?? ''),
						'received_at' => (string) $row['received_at'],
						'acked_at' => (string) ($row['acked_at'] ?? ''),
					];
				}, $rows),
			];
		}

		/**
		 * Poll the hub inbox once (outbound request; stores before acknowledging; never applies content).
		 *
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		public static function poll(array $assoc_args) {
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}
			$runtime = DBVC_Connected_Environments_Addon::runtime();
			if (! $runtime instanceof \Dbvc\Connected\Runtime) {
				return new WP_Error('dbvc_connected_cli_runtime', 'The connector runtime is not registered in this process.');
			}

			return $runtime->inbox()->run([
				'context' => 'cli',
				'limit' => self::bounded_integer($assoc_args['limit'] ?? \Dbvc\ConnectedProtocol\Protocol::MAX_INBOX_ITEMS, 1, \Dbvc\ConnectedProtocol\Protocol::MAX_INBOX_ITEMS),
			]);
		}

		/**
		 * Run release work once: answer the hub's payload requests and prepare requests (dry runs; never writes content).
		 *
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		public static function release(array $assoc_args) {
			unset($assoc_args);
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}
			$runtime = DBVC_Connected_Environments_Addon::runtime();
			if (! $runtime instanceof \Dbvc\Connected\Runtime) {
				return new WP_Error('dbvc_connected_cli_runtime', 'The connector runtime is not registered in this process.');
			}

			return $runtime->release()->run(['context' => 'cli']);
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
			$store = new \Dbvc\Connected\Storage\PreparationStore();
			if (! empty($assoc_args['operation'])) {
				$row = $store->find((string) $assoc_args['operation']);

				return $row === null ? new WP_Error('dbvc_connected_preparation_not_found', 'No receipt for that operation id.') : $row;
			}
			$rows = $store->all(self::bounded_integer($assoc_args['limit'] ?? self::DEFAULT_LIMIT, 1, self::MAX_LIMIT));

			return [
				'counts' => $store->counts(),
				'returned' => count($rows),
				'preparations' => array_map(static function ($row) {
					return [
						'operation_id' => (string) $row['operation_id'],
						'release_uid' => (string) $row['release_uid'],
						'release_digest' => substr((string) $row['release_digest'], 0, 12),
						'outcome' => (string) $row['outcome'],
						'ready' => (int) $row['items_ready'],
						'noop' => (int) $row['items_noop'],
						'blocked' => (int) $row['items_blocked'],
						'prepared_at' => (string) $row['prepared_at'],
						'expires_at' => (string) $row['expires_at'],
						'reported_at' => (string) ($row['reported_at'] ?? ''),
						'report_error' => (string) $row['report_error'],
					];
				}, $rows),
			];
		}

		/**
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		public static function operations(array $assoc_args) {
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}
			$store = new \Dbvc\Connected\Storage\OperationStore();
			if (! empty($assoc_args['operation'])) {
				$row = $store->find((string) $assoc_args['operation']);
				if ($row === null) {
					return new WP_Error('dbvc_connected_operation_not_found', 'No journal row for that operation id.');
				}
				unset($row['before_image']); // The raw before image stays in the journal table; it is not for terminal output.

				return $row;
			}
			$rows = $store->all(self::bounded_integer($assoc_args['limit'] ?? self::DEFAULT_LIMIT, 1, self::MAX_LIMIT));

			return [
				'counts' => $store->counts(),
				'returned' => count($rows),
				'operations' => array_map(static function ($row) {
					$counts = is_array($row['execution_receipt']) ? (array) ($row['execution_receipt']['counts'] ?? []) : [];
					return [
						'operation_id' => (string) $row['operation_id'],
						'approval_uid' => (string) $row['approval_uid'],
						'release_uid' => (string) $row['release_uid'],
						'state' => (string) $row['state'],
						'outcome' => (string) $row['outcome'],
						'applied' => (int) ($counts['applied'] ?? 0),
						'stale' => (int) ($counts['stale'] ?? 0),
						'failed' => (int) ($counts['failed'] ?? 0) + (int) ($counts['compensated'] ?? 0),
						'started_at' => (string) $row['started_at'],
						'finished_at' => (string) ($row['finished_at'] ?? ''),
						'reported_at' => (string) ($row['reported_at'] ?? ''),
						'report_error' => (string) $row['report_error'],
					];
				}, $rows),
			];
		}

		/**
		 * Explicit worker run. Writes connector management state only.
		 *
		 * @param array $assoc_args
		 * @return array|WP_Error
		 */
		public static function process(array $assoc_args) {
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}
			$runtime = DBVC_Connected_Environments_Addon::runtime();
			if (! $runtime instanceof \Dbvc\Connected\Runtime) {
				return new WP_Error('dbvc_connected_cli_runtime', 'The connector runtime is not registered in this process.');
			}

			return $runtime->worker()->run([
				'context' => 'cli',
				'limit' => self::bounded_integer($assoc_args['limit'] ?? \Dbvc\Connected\Worker\ObservationWorker::DEFAULT_LIMIT, 1, self::MAX_LIMIT),
				'budget_seconds' => self::bounded_integer($assoc_args['budget'] ?? \Dbvc\Connected\Worker\ObservationWorker::DEFAULT_BUDGET_SECONDS, 1, 600),
			]);
		}

		/**
		 * @return true|WP_Error
		 */
		private static function require_ready() {
			if (! class_exists('DBVC_Connected_Environments_Addon')) {
				return new WP_Error('dbvc_connected_cli_unavailable', 'The Connected Environments add-on is unavailable in this checkout.');
			}
			$state = DBVC_Connected_Environments_Addon::get_gate_state();
			if ($state !== \Dbvc\ConnectedProtocol\RoleGate::READY) {
				return new WP_Error('dbvc_connected_cli_not_ready', 'The Connected Environments connector is not ready (gate state: ' . $state . ').');
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

if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI_Command') && ! class_exists('DBVC_WP_CLI_Connected')) {
	/**
	 * Inspect and drive the DBVC Connected Environments connector.
	 */
	class DBVC_WP_CLI_Connected extends WP_CLI_Command {
		/**
		 * Show connector gate, schema, identity, queue, outbox and scheduler state.
		 *
		 * ## OPTIONS
		 *
		 * [--format=<format>]
		 * : json only. Default: json.
		 *
		 * ## EXAMPLES
		 *
		 * wp dbvc connected status
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 */
		public function status($args, $assoc_args) {
			unset($args, $assoc_args);
			$this->emit_json(DBVC_Connected_CLI_Inspector::status());
		}

		/**
		 * List dirty markers (pending observation jobs).
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
		public function jobs($args, $assoc_args) {
			unset($args);
			$this->emit_rows(DBVC_Connected_CLI_Inspector::jobs($assoc_args), 'jobs', ['job_id', 'domain', 'object_key', 'generation', 'claimed_generation', 'attempts', 'available_at', 'leased', 'last_error_code'], $assoc_args);
		}

		/**
		 * List latest observed projections.
		 *
		 * ## OPTIONS
		 *
		 * [--domain=<domain>]
		 * : bricks.global_class or bricks.variable.
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
		public function objects($args, $assoc_args) {
			unset($args);
			$this->emit_rows(DBVC_Connected_CLI_Inspector::objects($assoc_args), 'objects', ['domain', 'profile', 'instance_uid', 'storage_key', 'display_name', 'exists', 'complete', 'position', 'observed_sequence', 'semantic_hash'], $assoc_args);
		}

		/**
		 * List immutable outbox events.
		 *
		 * ## OPTIONS
		 *
		 * [--state=<state>]
		 * : Delivery state filter, e.g. pending.
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
		public function outbox($args, $assoc_args) {
			unset($args);
			$this->emit_rows(DBVC_Connected_CLI_Inspector::outbox($assoc_args), 'events', ['event_id', 'sequence', 'domain', 'instance_uid', 'profile', 'exists', 'complete', 'hash', 'delivery_state'], $assoc_args);
		}

		/**
		 * Read the current persisted collection through the observer (read-only; assigns no identity).
		 *
		 * ## OPTIONS
		 *
		 * --domain=<domain>
		 * : bricks.global_class or bricks.variable.
		 *
		 * [--cursor=<offset>]
		 * : Zero-based offset. Default: 0.
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
		public function inventory($args, $assoc_args) {
			unset($args);
			$this->emit_rows(DBVC_Connected_CLI_Inspector::inventory($assoc_args), 'items', ['position', 'storage_key', 'display_name', 'instance_uid', 'identity', 'complete', 'hash'], $assoc_args);
		}

		/**
		 * Mark one or every supported domain dirty for reconciliation (events carry origin=reconciliation).
		 *
		 * ## OPTIONS
		 *
		 * [--domain=<domain>]
		 * : bricks.global_class or bricks.variable. Default: every supported domain.
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 */
		public function reconcile($args, $assoc_args) {
			unset($args);
			$this->emit_json(DBVC_Connected_CLI_Inspector::reconcile($assoc_args));
		}

		/**
		 * Enroll this environment with a studio hub (application-password principal; credential stored encrypted).
		 *
		 * ## OPTIONS
		 *
		 * --hub=<url>
		 * : Hub base URL (https; plain http only for the local environment type).
		 *
		 * --token=<token>
		 * : Single-use invitation token issued by `wp dbvc agency invite` on the hub.
		 *
		 * ## EXAMPLES
		 *
		 * wp dbvc connected enroll --hub=https://studio.example --token=inv-…
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 */
		public function enroll($args, $assoc_args) {
			unset($args);
			$this->emit_json(DBVC_Connected_CLI_Inspector::enroll($assoc_args));
		}

		/**
		 * Clear a local delivery hold (e.g. after `wp dbvc agency release` on the hub); some holds require re-enrollment.
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 */
		public function resume($args, $assoc_args) {
			unset($args);
			$this->emit_json(DBVC_Connected_CLI_Inspector::resume($assoc_args));
		}

		/**
		 * Send one batch of pending outbox events to the hub now (outbound only; never edits content).
		 *
		 * ## OPTIONS
		 *
		 * [--limit=<number>]
		 * : Maximum events per batch. Default and maximum: 50.
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 */
		public function deliver($args, $assoc_args) {
			unset($args);
			$this->emit_json(DBVC_Connected_CLI_Inspector::deliver($assoc_args));
		}

		/**
		 * List deliveries received from the hub (read-only).
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
		public function inbox($args, $assoc_args) {
			unset($args);
			$this->emit_rows(DBVC_Connected_CLI_Inspector::inbox($assoc_args), 'items', ['delivery_id', 'source_environment_id', 'event_id', 'sequence', 'domain', 'instance_uid', 'profile', 'exists', 'hash', 'acked_at'], $assoc_args);
		}

		/**
		 * Poll the hub inbox now (outbound only; stores deliveries before acknowledging; never applies content).
		 *
		 * ## OPTIONS
		 *
		 * [--limit=<number>]
		 * : Items per page. Default and maximum: 100.
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 */
		public function poll($args, $assoc_args) {
			unset($args);
			$this->emit_json(DBVC_Connected_CLI_Inspector::poll($assoc_args));
		}

		/**
		 * Run release work now: supply payloads for releases this environment sources, produce prepare receipts (dry runs) for releases targeting it, and — only when the apply gate is on — execute approved releases with conditional, journalled, verified writes.
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 */
		public function release($args, $assoc_args) {
			unset($args);
			$this->emit_json(DBVC_Connected_CLI_Inspector::release($assoc_args));
		}

		/**
		 * List prepare receipts this environment produced (read-only), or show one with --operation.
		 *
		 * ## OPTIONS
		 *
		 * [--operation=<id>]
		 * : Show the full receipt for one operation id.
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
				$this->emit_json(DBVC_Connected_CLI_Inspector::preparations($assoc_args));
				return;
			}
			$this->emit_rows(DBVC_Connected_CLI_Inspector::preparations($assoc_args), 'preparations', ['operation_id', 'release_uid', 'release_digest', 'outcome', 'ready', 'noop', 'blocked', 'prepared_at', 'expires_at', 'reported_at', 'report_error'], $assoc_args);
		}

		/**
		 * List the execution journal (approved releases applied here), or show one operation with --operation.
		 *
		 * ## OPTIONS
		 *
		 * [--operation=<id>]
		 * : Show one journal row with its steps and execution receipt (the raw before image is omitted).
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
		public function operations($args, $assoc_args) {
			unset($args);
			if (! empty($assoc_args['operation'])) {
				$this->emit_json(DBVC_Connected_CLI_Inspector::operations($assoc_args));
				return;
			}
			$this->emit_rows(DBVC_Connected_CLI_Inspector::operations($assoc_args), 'operations', ['operation_id', 'approval_uid', 'release_uid', 'state', 'outcome', 'applied', 'stale', 'failed', 'started_at', 'finished_at', 'reported_at'], $assoc_args);
		}

		/**
		 * Run one bounded observation batch now (management writes only; never edits Bricks content).
		 *
		 * ## OPTIONS
		 *
		 * [--limit=<number>]
		 * : Maximum dirty markers to claim. Default: 50; maximum: 500.
		 *
		 * [--budget=<seconds>]
		 * : Wall-clock budget. Default: 10; maximum: 600.
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 */
		public function process($args, $assoc_args) {
			unset($args);
			$this->emit_json(DBVC_Connected_CLI_Inspector::process($assoc_args));
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
			WP_CLI::log(sprintf('Returned %d row(s).', (int) ($result['returned'] ?? count((array) ($result[$rows_key] ?? [])))));
		}
	}

	WP_CLI::add_command('dbvc connected', 'DBVC_WP_CLI_Connected');
}
