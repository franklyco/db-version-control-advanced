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
		 * CR-5 hubless file mode, export half: this site's recorded objects as one JSON bundle.
		 *
		 * @param array $assoc_args domains (csv), out (path), force (bool)
		 * @return array|WP_Error
		 */
		public static function objects_export(array $assoc_args) {
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}
			$bundle = (new \Dbvc\Connected\Inspection\FileComparison())->export(self::csv_list($assoc_args['domains'] ?? ''));
			if (is_wp_error($bundle)) {
				return $bundle;
			}
			$out = (string) ($assoc_args['out'] ?? '');
			if ($out === '') {
				return $bundle;
			}
			$written = self::write_file($out, (string) wp_json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ! empty($assoc_args['force']));
			if (is_wp_error($written)) {
				return $written;
			}

			return [
				'written' => $out,
				'bytes' => $written,
				'objects' => count($bundle['objects']),
				'skipped' => (int) $bundle['skipped'],
				'domains' => $bundle['domains'],
				'exported_at' => $bundle['exported_at'],
			];
		}

		/**
		 * CR-5 hubless file mode, compare half: an exported bundle against this site's recorded objects.
		 *
		 * @param array $assoc_args file (path, required), domains (csv), values (bool, default on), max_paths
		 * @return array|WP_Error The comparison; `markdown` holds the rendered report.
		 */
		public static function compare_file(array $assoc_args) {
			$ready = self::require_ready();
			if (is_wp_error($ready)) {
				return $ready;
			}
			$file = (string) ($assoc_args['file'] ?? '');
			if ($file === '') {
				return new WP_Error('dbvc_connected_file_missing', 'Name the export file to compare.');
			}
			if (! is_file($file) || ! is_readable($file)) {
				return new WP_Error('dbvc_connected_file_missing', 'Export file not found or unreadable: ' . $file);
			}
			$decoded = json_decode((string) file_get_contents($file), true);
			if (! is_array($decoded)) {
				return new WP_Error('dbvc_connected_file_format', 'Export file is not valid JSON: ' . $file);
			}
			$options = ['domains' => self::csv_list($assoc_args['domains'] ?? ''), 'values' => ! array_key_exists('values', $assoc_args) || self::truthy($assoc_args['values'])];
			if (isset($assoc_args['max_paths'])) {
				$options['max_paths'] = self::bounded_integer($assoc_args['max_paths'], 1, \Dbvc\ConnectedProtocol\BodyDiff::DEFAULT_MAX_PATHS);
			}
			$result = (new \Dbvc\Connected\Inspection\FileComparison())->compare($decoded, $options);
			if (is_wp_error($result)) {
				return $result;
			}
			if ((string) ($assoc_args['design_review'] ?? '') !== '') {
				// CR-6: a Design Review export annotates the rows it knows about; ids resolve here.
				$review = self::read_design_review((string) $assoc_args['design_review']);
				if (is_wp_error($review)) {
					return $review;
				}
				$annotated = \Dbvc\ConnectedProtocol\DesignReviewAnnotation::annotate($result['items'], $review, (new \Dbvc\Connected\Inspection\DesignReviewResolver())->uid_map($review));
				$result['items'] = $annotated['items'];
				$result['design_review'] = $annotated['summary'];
			}
			$result['markdown'] = \Dbvc\Connected\Inspection\FileComparison::render_markdown($result);

			return $result;
		}

		/**
		 * Read a `vf-design-review/1` export file (CR-6).
		 *
		 * @param string $file
		 * @return array|WP_Error
		 */
		public static function read_design_review($file) {
			if (! is_file($file) || ! is_readable($file)) {
				return new WP_Error('dbvc_connected_design_review_missing', 'Design Review export not found or unreadable: ' . $file);
			}
			$decoded = json_decode((string) file_get_contents($file), true);
			$review = is_array($decoded) ? \Dbvc\ConnectedProtocol\DesignReviewAnnotation::read($decoded) : null;
			if ($review === null) {
				return new WP_Error('dbvc_connected_design_review_format', 'Not a ' . \Dbvc\ConnectedProtocol\DesignReviewAnnotation::FORMAT . ' export: ' . $file);
			}

			return $review;
		}

		/**
		 * @param mixed $value
		 * @return array<int, string>
		 */
		private static function csv_list($value) {
			return array_values(array_filter(array_map('trim', explode(',', (string) $value)), 'strlen'));
		}

		/**
		 * @param mixed $value
		 * @return bool
		 */
		private static function truthy($value) {
			return $value === true || in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
		}

		/**
		 * @param string $path
		 * @param string $contents
		 * @param bool $force Overwrite an existing file.
		 * @return int|WP_Error Bytes written.
		 */
		private static function write_file($path, $contents, $force) {
			if (file_exists($path) && ! $force) {
				return new WP_Error('dbvc_connected_file_exists', 'Refusing to overwrite ' . $path . ' (pass --force).');
			}
			$dir = dirname($path);
			if (! is_dir($dir) || ! is_writable($dir)) {
				return new WP_Error('dbvc_connected_file_unwritable', 'Directory not writable: ' . $dir);
			}
			$bytes = file_put_contents($path, $contents);
			if ($bytes === false) {
				return new WP_Error('dbvc_connected_file_unwritable', 'Could not write ' . $path);
			}

			return (int) $bytes;
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
		 * Write this site's recorded objects (canonical bodies from dbvc_ce_objects) as one JSON bundle for a hubless comparison elsewhere (CR-5).
		 *
		 * Read-only; nothing leaves the site except the file you move. Run `wp dbvc connected process` first if observations may be pending.
		 *
		 * ## OPTIONS
		 *
		 * [--domains=<domains>]
		 * : Comma-separated registered domains. Default: every registered domain.
		 *
		 * [--out=<path>]
		 * : Write the bundle to this file (refuses to overwrite without --force). Default: print JSON to stdout.
		 *
		 * [--force]
		 * : Overwrite an existing --out file.
		 *
		 * ## EXAMPLES
		 *
		 * wp dbvc connected objects-export --out=/tmp/staging-objects.json
		 * wp dbvc connected objects-export --domains=bricks.global_class,bricks.variable > staging.json
		 *
		 * @subcommand objects-export
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 */
		public function objects_export($args, $assoc_args) {
			unset($args);
			$result = DBVC_Connected_CLI_Inspector::objects_export($assoc_args);
			if (is_wp_error($result)) {
				WP_CLI::error($result->get_error_message());
			}
			if (isset($result['written'])) {
				WP_CLI::success(sprintf('Wrote %d object(s) to %s (%d bytes%s).', (int) $result['objects'], (string) $result['written'], (int) $result['bytes'], (int) $result['skipped'] > 0 ? sprintf(', %d incomplete or absent row(s) skipped', (int) $result['skipped']) : ''));
				return;
			}
			WP_CLI::line(wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
		}

		/**
		 * Compare an objects-export bundle from another site against this site's recorded objects and print the change report (CR-5 hubless file mode).
		 *
		 * Read-only, no hub, no transport. Objects pair by portable UID, then by display name; each pair is diffed path by path like a prepare receipt. Values are redacted and truncated like receipt values. There is no baseline: a difference says the sites differ, not which one changed.
		 *
		 * ## OPTIONS
		 *
		 * <file>
		 * : Path to a bundle written by `wp dbvc connected objects-export`.
		 *
		 * [--domains=<domains>]
		 * : Comma-separated domains to compare. Default: every domain in the file.
		 *
		 * [--values=<bool>]
		 * : Include rendered before/after values (this site → the export). Default: true.
		 *
		 * [--max-paths=<number>]
		 * : Diff rows per object before truncation. Default: 1000.
		 *
		 * [--design-review=<file>]
		 * : A VerticalFramework Design Review export (vf-design-review/1 JSON) whose pin counts and approvals annotate the page and template rows it covers (CR-6). Pages match by this site's UID (ids resolved here), then by title.
		 *
		 * [--format=<format>]
		 * : markdown or json. Default: markdown.
		 *
		 * [--out=<path>]
		 * : Write the report to this file instead of stdout (refuses to overwrite without --force).
		 *
		 * [--force]
		 * : Overwrite an existing --out file.
		 *
		 * ## EXAMPLES
		 *
		 * wp dbvc connected compare-file /tmp/staging-objects.json
		 * wp dbvc connected compare-file staging.json --format=json --values=false
		 *
		 * @subcommand compare-file
		 *
		 * @param array $args
		 * @param array $assoc_args
		 * @return void
		 */
		public function compare_file($args, $assoc_args) {
			$assoc_args['file'] = (string) ($args[0] ?? '');
			if (isset($assoc_args['max-paths'])) {
				$assoc_args['max_paths'] = $assoc_args['max-paths'];
			}
			if (isset($assoc_args['design-review'])) {
				$assoc_args['design_review'] = $assoc_args['design-review'];
			}
			$format = sanitize_key((string) ($assoc_args['format'] ?? 'markdown'));
			if (! in_array($format, ['markdown', 'json'], true)) {
				WP_CLI::error('Format must be markdown or json.');
			}
			$result = DBVC_Connected_CLI_Inspector::compare_file($assoc_args);
			if (is_wp_error($result)) {
				WP_CLI::error($result->get_error_message());
			}
			$markdown = (string) $result['markdown'];
			unset($result['markdown']);
			$output = $format === 'json' ? (string) wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : $markdown;
			$out = (string) ($assoc_args['out'] ?? '');
			if ($out !== '') {
				if (file_exists($out) && empty($assoc_args['force'])) {
					WP_CLI::error('Refusing to overwrite ' . $out . ' (pass --force).');
				}
				if (file_put_contents($out, $output) === false) {
					WP_CLI::error('Could not write ' . $out);
				}
				WP_CLI::success(sprintf('%s Report written to %s.', (string) $result['summary'], $out));
				return;
			}
			WP_CLI::line($output);
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
