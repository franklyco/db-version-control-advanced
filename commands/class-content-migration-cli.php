<?php

if (! defined('WPINC')) {
	die;
}

if (! class_exists('DBVC_CC_V2_CLI_Run_Inspector')) {
	/**
	 * Read existing Content Migration V2 run artifacts without materializing or writing them.
	 */
	final class DBVC_CC_V2_CLI_Run_Inspector {
		private const DEFAULT_LIMIT = 25;
		private const MAX_LIMIT = 100;
		private const DEFAULT_ACTIVITY_LIMIT = 12;
		private const MAX_ACTIVITY_LIMIT = 50;
		private const MAX_DOMAINS = 200;
		private const MAX_RUN_EVENTS = 10000;
		private const MAX_SCANNED_EVENTS = 100000;
		private const MAX_JSON_BYTES = 5242880;
		private const MAX_EVENT_LINE_BYTES = 1048576;

		/**
		 * Return a bounded page of latest V2 runs.
		 *
		 * @param array $assoc_args Named command arguments.
		 * @return array|WP_Error
		 */
		public static function list_runs(array $assoc_args) {
			$guard = self::guard_arguments($assoc_args, ['domain', 'status', 'search', 'include-hidden', 'limit', 'offset', 'format', 'fields']);
			if (is_wp_error($guard)) {
				return $guard;
			}

			$base_dir = self::storage_base_dir();
			if (is_wp_error($base_dir)) {
				return $base_dir;
			}
			$domain_filter = self::normalize_domain((string) ($assoc_args['domain'] ?? ''));
			if (isset($assoc_args['domain']) && $domain_filter === '') {
				return new WP_Error('dbvc_cc_cli_domain_invalid', 'Domain must contain only a valid host-style key.');
			}

			$domains = self::domain_keys($base_dir, $domain_filter);
			if (is_wp_error($domains)) {
				return $domains;
			}
			$include_hidden = ! empty($assoc_args['include-hidden']);
			$status_filter = sanitize_key((string) ($assoc_args['status'] ?? ''));
			$search = strtolower(sanitize_text_field((string) ($assoc_args['search'] ?? '')));
			$items = [];
			$scanned = 0;

			foreach ($domains as $domain) {
				$latest_path = self::domain_file($base_dir, $domain, DBVC_CC_V2_Contracts::STORAGE_JOURNEY_SUBDIR, DBVC_CC_V2_Contracts::STORAGE_JOURNEY_LATEST_FILE);
				$latest = self::read_json_file($latest_path);
				if (is_wp_error($latest)) {
					continue;
				}
				$latest_run_id = sanitize_text_field((string) ($latest['journey_id'] ?? ''));
				$log_path = self::domain_file($base_dir, $domain, DBVC_CC_V2_Contracts::STORAGE_JOURNEY_SUBDIR, DBVC_CC_V2_Contracts::STORAGE_JOURNEY_LOG_FILE);
				$run_events = self::read_run_events($log_path, $latest_run_id, $scanned);
				if (is_wp_error($run_events)) {
					return $run_events;
				}
				if ($run_events !== []) {
					$latest = DBVC_CC_V2_Domain_Journey_Materializer_Service::get_instance()->build_latest_state_for_events($domain, $run_events);
				}
				$item = self::list_item($base_dir, $domain, $latest);
				if ($item['run_id'] === '' || (! $include_hidden && $item['hidden'])) {
					continue;
				}
				if ($status_filter !== '' && $item['status'] !== $status_filter) {
					continue;
				}
				if ($search !== '' && strpos(strtolower($item['run_id'] . ' ' . $item['domain'] . ' ' . $item['status']), $search) === false) {
					continue;
				}
				$items[] = $item;
			}

			usort($items, static function ($left, $right) {
				$updated_compare = strcmp((string) ($right['updated_at'] ?? ''), (string) ($left['updated_at'] ?? ''));
				return $updated_compare !== 0 ? $updated_compare : strnatcasecmp((string) ($left['domain'] ?? ''), (string) ($right['domain'] ?? ''));
			});

			$offset = self::bounded_integer($assoc_args['offset'] ?? 0, 0, PHP_INT_MAX);
			$limit = self::bounded_integer($assoc_args['limit'] ?? self::DEFAULT_LIMIT, 1, self::MAX_LIMIT);
			$total = count($items);
			$items = array_slice($items, $offset, $limit);

			return [
				'runtime' => self::runtime_state(),
				'filters' => [
					'domain' => $domain_filter,
					'status' => $status_filter,
					'search' => $search,
					'include_hidden' => $include_hidden,
				],
				'total_matching' => $total,
				'returned' => count($items),
				'offset' => $offset,
				'limit' => $limit,
				'has_more' => $offset + count($items) < $total,
				'items' => $items,
			];
		}

		/**
		 * Return a bounded overview for one exact run.
		 *
		 * @param string $run_id Exact run ID.
		 * @param array  $assoc_args Named command arguments.
		 * @return array|WP_Error
		 */
		public static function show_run($run_id, array $assoc_args) {
			$guard = self::guard_arguments($assoc_args, ['domain', 'activity-limit', 'format', 'fields', 'fail-on-issues']);
			if (is_wp_error($guard)) {
				return $guard;
			}
			$run_id = sanitize_text_field((string) $run_id);
			if ($run_id === '' || ! preg_match('/^[A-Za-z0-9._-]+$/', $run_id)) {
				return new WP_Error('dbvc_cc_cli_run_id_invalid', 'Provide one exact Content Migration V2 run ID.');
			}

			$base_dir = self::storage_base_dir();
			if (is_wp_error($base_dir)) {
				return $base_dir;
			}
			$domain_filter = self::normalize_domain((string) ($assoc_args['domain'] ?? ''));
			if (isset($assoc_args['domain']) && $domain_filter === '') {
				return new WP_Error('dbvc_cc_cli_domain_invalid', 'Domain must contain only a valid host-style key.');
			}
			$resolved = self::resolve_run_events($base_dir, $run_id, $domain_filter);
			if (is_wp_error($resolved)) {
				return $resolved;
			}

			$materializer = DBVC_CC_V2_Domain_Journey_Materializer_Service::get_instance();
			$latest = $materializer->build_latest_state_for_events($resolved['domain'], $resolved['events']);
			$stage_summary = $materializer->build_stage_summary_for_events($resolved['domain'], $resolved['events']);
			$profile = self::run_profile($base_dir, $resolved['domain'], $run_id);
			$inventory = self::inventory_summary($base_dir, $resolved['domain'], $run_id, $latest, $resolved['events']);
			$stages = self::normalize_stages((array) ($stage_summary['stages'] ?? []));
			$stage_stats = self::normalize_numeric_map((array) ($stage_summary['stats'] ?? []));
			$counts = self::normalize_numeric_map((array) ($latest['counts'] ?? []));
			$action_counts = self::action_counts($latest);
			$issue_count = (int) ($stage_stats['failed_steps'] ?? 0)
				+ (int) ($stage_stats['blocked_steps'] ?? 0)
				+ (int) ($stage_stats['needs_review_steps'] ?? 0);
			$activity_limit = self::bounded_integer($assoc_args['activity-limit'] ?? self::DEFAULT_ACTIVITY_LIMIT, 1, self::MAX_ACTIVITY_LIMIT);

			return [
				'runtime' => self::runtime_state(),
				'run' => [
					'run_id' => $run_id,
					'domain' => $resolved['domain'],
					'status' => sanitize_key((string) ($latest['status'] ?? '')),
					'pipeline_version' => sanitize_text_field((string) ($latest['pipeline_version'] ?? '')),
					'started_at' => sanitize_text_field((string) ($latest['started_at'] ?? '')),
					'updated_at' => sanitize_text_field((string) ($latest['updated_at'] ?? '')),
					'schema_fingerprint' => sanitize_text_field((string) ($latest['latest_schema_fingerprint'] ?? '')),
					'hidden' => self::visibility($run_id)['hidden'],
				],
				'counts' => $counts,
				'profile' => $profile,
				'inventory' => $inventory,
				'stage_summary' => [
					'stats' => $stage_stats,
					'stages' => $stages,
				],
				'action_counts' => $action_counts,
				'issue_count' => $issue_count,
				'has_issues' => $issue_count > 0,
				'recent_activity' => self::recent_activity($resolved['events'], $activity_limit),
			];
		}

		/**
		 * Flatten a run overview for table output.
		 *
		 * @param array $result Show result.
		 * @return array
		 */
		public static function show_table_row(array $result) {
			$run = (array) ($result['run'] ?? []);
			$inventory = (array) ($result['inventory'] ?? []);
			$stats = (array) ($result['stage_summary']['stats'] ?? []);
			return [
				'run_id' => (string) ($run['run_id'] ?? ''),
				'domain' => (string) ($run['domain'] ?? ''),
				'status' => (string) ($run['status'] ?? ''),
				'updated_at' => (string) ($run['updated_at'] ?? ''),
				'url_count' => (int) ($inventory['url_count'] ?? 0),
				'total_events' => (int) ($stats['total_events'] ?? 0),
				'issue_count' => (int) ($result['issue_count'] ?? 0),
				'hidden' => ! empty($run['hidden']) ? 'yes' : 'no',
			];
		}

		/**
		 * @param array $assoc_args Named arguments.
		 * @param array $allowed Allowed keys.
		 * @return true|WP_Error
		 */
		private static function guard_arguments(array $assoc_args, array $allowed) {
			foreach (array_keys($assoc_args) as $argument) {
				if (! in_array((string) $argument, $allowed, true)) {
					return new WP_Error('dbvc_cc_cli_read_only', 'The Content Migration run inspector is read-only and rejects --' . sanitize_key((string) $argument) . '.');
				}
			}
			$format = sanitize_key((string) ($assoc_args['format'] ?? 'table'));
			if (! in_array($format, ['table', 'json'], true)) {
				return new WP_Error('dbvc_cc_cli_format_invalid', 'Format must be table or json.');
			}
			if (! class_exists('DBVC_CC_V2_Contracts') || ! class_exists('DBVC_CC_V2_Domain_Journey_Materializer_Service')) {
				return new WP_Error('dbvc_cc_cli_unavailable', 'Content Migration V2 run services are unavailable in this checkout.');
			}
			return true;
		}

		/**
		 * Resolve the configured storage root without asking WordPress to create it.
		 *
		 * @return string|WP_Error
		 */
		private static function storage_base_dir() {
			$uploads = function_exists('wp_get_upload_dir') ? wp_get_upload_dir() : wp_upload_dir(null, false);
			$upload_base = isset($uploads['basedir']) ? (string) $uploads['basedir'] : '';
			$options = class_exists('DBVC_CC_Settings_Service') ? DBVC_CC_Settings_Service::get_options() : [];
			$storage_path = sanitize_file_name((string) ($options['storage_path'] ?? DBVC_CC_Contracts::STORAGE_DEFAULT_PATH));
			$path = $upload_base !== '' ? trailingslashit($upload_base) . $storage_path : '';
			$real = $path !== '' ? realpath($path) : false;
			if (! is_string($real) || ! is_dir($real)) {
				return new WP_Error('dbvc_cc_cli_storage_missing', 'The existing Content Migration storage directory could not be found. No directory was created.');
			}
			return wp_normalize_path($real);
		}

		/**
		 * @param string $base_dir Storage root.
		 * @param string $domain_filter Optional exact domain.
		 * @return array|WP_Error
		 */
		private static function domain_keys($base_dir, $domain_filter = '') {
			$entries = @scandir($base_dir);
			if (! is_array($entries)) {
				return new WP_Error('dbvc_cc_cli_storage_unreadable', 'The Content Migration storage directory could not be read.');
			}
			$domains = [];
			foreach ($entries as $entry) {
				$domain = self::normalize_domain($entry);
				if ($domain === '' || $domain !== $entry || ($domain_filter !== '' && $domain !== $domain_filter)) {
					continue;
				}
				$domain_dir = realpath(trailingslashit($base_dir) . $domain);
				if (! is_string($domain_dir) || ! self::path_within($domain_dir, $base_dir) || ! is_dir(trailingslashit($domain_dir) . DBVC_CC_V2_Contracts::STORAGE_JOURNEY_SUBDIR)) {
					continue;
				}
				$domains[] = $domain;
				if (count($domains) > self::MAX_DOMAINS) {
					return new WP_Error('dbvc_cc_cli_domain_limit', 'More than 200 V2 domains exist. Narrow the command with --domain=<domain>.');
				}
			}
			sort($domains, SORT_NATURAL | SORT_FLAG_CASE);
			return $domains;
		}

		/**
		 * @param string $base_dir Storage root.
		 * @param string $run_id Exact run ID.
		 * @param string $domain_filter Optional exact domain.
		 * @return array|WP_Error
		 */
		private static function resolve_run_events($base_dir, $run_id, $domain_filter) {
			$domains = self::domain_keys($base_dir, $domain_filter);
			if (is_wp_error($domains)) {
				return $domains;
			}
			$scanned = 0;
			foreach ($domains as $domain) {
				$path = self::domain_file($base_dir, $domain, DBVC_CC_V2_Contracts::STORAGE_JOURNEY_SUBDIR, DBVC_CC_V2_Contracts::STORAGE_JOURNEY_LOG_FILE);
				$events = self::read_run_events($path, $run_id, $scanned);
				if (is_wp_error($events)) {
					return $events;
				}
				if ($events !== []) {
					return ['domain' => $domain, 'events' => $events];
				}
			}
			return new WP_Error('dbvc_cc_cli_run_missing', 'The requested Content Migration V2 run could not be found in existing journey logs.');
		}

		/**
		 * @param string $path NDJSON path.
		 * @param string $run_id Exact run ID.
		 * @param int    $scanned Shared scan counter.
		 * @return array|WP_Error
		 */
		private static function read_run_events($path, $run_id, &$scanned) {
			if (! is_file($path) || ! is_readable($path)) {
				return [];
			}
			$handle = fopen($path, 'rb');
			if (! is_resource($handle)) {
				return new WP_Error('dbvc_cc_cli_log_unreadable', 'A Content Migration journey log could not be opened.');
			}
			$events = [];
			while (($line = fgets($handle, self::MAX_EVENT_LINE_BYTES + 1)) !== false) {
				++$scanned;
				if ($scanned > self::MAX_SCANNED_EVENTS) {
					fclose($handle);
					return new WP_Error('dbvc_cc_cli_scan_limit', 'The run search exceeded 100,000 events. Retry with --domain=<domain>.');
				}
				if (strlen($line) > self::MAX_EVENT_LINE_BYTES) {
					fclose($handle);
					return new WP_Error('dbvc_cc_cli_event_too_large', 'A journey event exceeds the 1 MB inspection limit.');
				}
				$event = json_decode(trim($line), true);
				if (! is_array($event) || (string) ($event['journey_id'] ?? '') !== $run_id) {
					continue;
				}
				$events[] = $event;
				if (count($events) > self::MAX_RUN_EVENTS) {
					fclose($handle);
					return new WP_Error('dbvc_cc_cli_run_event_limit', 'The selected run exceeds the 10,000-event inspection limit.');
				}
			}
			fclose($handle);
			return $events;
		}

		/**
		 * @param string $base_dir Storage root.
		 * @param string $domain Domain key.
		 * @param array  $latest Latest state.
		 * @return array
		 */
		private static function list_item($base_dir, $domain, array $latest) {
			$run_id = sanitize_text_field((string) ($latest['journey_id'] ?? ''));
			$visibility = self::visibility($run_id);
			$profile = self::run_profile($base_dir, $domain, $run_id);
			$counts = self::normalize_numeric_map((array) ($latest['counts'] ?? []));
			$actions = self::action_counts($latest);
			return [
				'run_id' => $run_id,
				'domain' => $domain,
				'status' => sanitize_key((string) ($latest['status'] ?? '')),
				'updated_at' => sanitize_text_field((string) ($latest['updated_at'] ?? '')),
				'urls_discovered' => (int) ($counts['urls_discovered'] ?? 0),
				'urls_finalized' => (int) ($counts['urls_finalized'] ?? 0),
				'urls_failed' => (int) ($counts['urls_failed'] ?? 0),
				'urls_blocked' => (int) ($counts['urls_blocked'] ?? 0),
				'issue_count' => (int) ($counts['urls_failed'] ?? 0) + (int) ($counts['urls_blocked'] ?? 0),
				'rerunnable_url_count' => (int) ($actions['rerunnable_url_count'] ?? 0),
				'max_urls' => (int) ($profile['max_urls'] ?? 0),
				'hidden' => ! empty($visibility['hidden']),
			];
		}

		/**
		 * @param string $base_dir Storage root.
		 * @param string $domain Domain key.
		 * @param string $run_id Exact run ID.
		 * @return array
		 */
		private static function run_profile($base_dir, $domain, $run_id) {
			$path = self::domain_file($base_dir, $domain, DBVC_CC_V2_Contracts::STORAGE_JOURNEY_SUBDIR, DBVC_CC_V2_Contracts::STORAGE_RUN_PROFILE_FILE);
			$profile = self::read_json_file($path);
			if (is_wp_error($profile) || (string) ($profile['run_id'] ?? '') !== $run_id) {
				return [];
			}
			$request = isset($profile['request']) && is_array($profile['request']) ? $profile['request'] : [];
			$override_keys = array_slice(array_map('sanitize_key', array_keys((array) ($request['crawl_overrides'] ?? []))), 0, 20);
			return [
				'stored_at' => sanitize_text_field((string) ($profile['stored_at'] ?? '')),
				'max_urls' => absint($request['max_urls'] ?? 0),
				'force_rebuild' => ! empty($request['force_rebuild']),
				'sitemap_configured' => ! empty($request['sitemap_url']),
				'crawl_override_keys' => $override_keys,
			];
		}

		/**
		 * @return array
		 */
		private static function runtime_state() {
			return [
				'addon_enabled' => DBVC_CC_V2_Contracts::is_addon_enabled(),
				'runtime_version' => DBVC_CC_V2_Contracts::get_runtime_version(),
				'v2_selected' => DBVC_CC_V2_Contracts::is_v2_runtime_selected(),
			];
		}

		/**
		 * @param string $run_id Run ID.
		 * @return array
		 */
		private static function visibility($run_id) {
			return class_exists('DBVC_CC_V2_Run_Visibility_Service')
				? DBVC_CC_V2_Run_Visibility_Service::get_instance()->get_visibility_payload($run_id)
				: ['hidden' => false, 'hiddenAt' => ''];
		}

		/**
		 * @param array $latest Latest state.
		 * @return array
		 */
		private static function action_counts(array $latest) {
			if (! class_exists('DBVC_CC_V2_Run_Action_Summary_Service')) {
				return ['rerunnable_stage_count' => 0, 'rerunnable_url_count' => 0];
			}
			$summary = DBVC_CC_V2_Run_Action_Summary_Service::get_instance()->build_summary($latest);
			$counts = (array) ($summary['counts'] ?? []);
			return [
				'rerunnable_stage_count' => absint($counts['rerunnableStageCount'] ?? 0),
				'rerunnable_url_count' => absint($counts['rerunnableUrlCount'] ?? 0),
			];
		}

		/**
		 * @param string $base_dir Storage root.
		 * @param string $domain Domain key.
		 * @param string $run_id Exact run ID.
		 * @param array  $latest Latest state.
		 * @param array  $events Run events.
		 * @return array
		 */
		private static function inventory_summary($base_dir, $domain, $run_id, array $latest, array $events) {
			$path = self::domain_file($base_dir, $domain, DBVC_CC_V2_Contracts::STORAGE_INVENTORY_SUBDIR, DBVC_CC_V2_Contracts::STORAGE_URL_INVENTORY_FILE);
			$inventory = self::read_json_file($path);
			if (! is_wp_error($inventory) && (string) ($inventory['journey_id'] ?? '') === $run_id) {
				$stats = self::normalize_numeric_map((array) ($inventory['stats'] ?? []));
				return array_merge(['source' => 'stored_run_artifact'], $stats);
			}

			$rows = [];
			foreach ($events as $event) {
				if (! is_array($event)) {
					continue;
				}
				$step = sanitize_key((string) ($event['step_key'] ?? ''));
				if (! in_array($step, [DBVC_CC_V2_Contracts::STEP_URL_DISCOVERED, DBVC_CC_V2_Contracts::STEP_URL_SCOPE_DECIDED], true)) {
					continue;
				}
				$page_id = sanitize_text_field((string) ($event['page_id'] ?? ''));
				if ($page_id === '') {
					continue;
				}
				$rows[$page_id] = $rows[$page_id] ?? 'eligible';
				if ($step === DBVC_CC_V2_Contracts::STEP_URL_SCOPE_DECIDED) {
					$metadata = is_array($event['metadata'] ?? null) ? $event['metadata'] : [];
					$rows[$page_id] = sanitize_key((string) ($metadata['scope_status'] ?? 'eligible'));
				}
			}
			$url_count = count($rows);
			$eligible = count(array_filter($rows, static function ($status) { return $status === 'eligible'; }));
			return [
				'source' => 'materialized_events',
				'raw_url_count' => absint($latest['counts']['urls_discovered'] ?? $url_count),
				'url_count' => $url_count,
				'eligible_count' => $eligible,
				'out_of_scope_count' => max(0, $url_count - $eligible),
				'duplicate_count' => 0,
				'invalid_count' => 0,
			];
		}

		/**
		 * @param array $stages Raw stages.
		 * @return array
		 */
		private static function normalize_stages(array $stages) {
			$normalized = [];
			foreach (array_slice($stages, 0, 50) as $stage) {
				if (! is_array($stage)) {
					continue;
				}
				$normalized[] = [
					'step_key' => sanitize_key((string) ($stage['step_key'] ?? '')),
					'step_name' => sanitize_text_field((string) ($stage['step_name'] ?? '')),
					'status' => sanitize_key((string) ($stage['status'] ?? '')),
					'event_count' => absint($stage['event_count'] ?? 0),
					'last_finished_at' => sanitize_text_field((string) ($stage['last_finished_at'] ?? '')),
					'last_duration_ms' => absint($stage['last_duration_ms'] ?? 0),
					'warning_codes' => array_slice(array_map('sanitize_key', (array) ($stage['warning_codes'] ?? [])), 0, 20),
					'error_code' => sanitize_key((string) ($stage['error_code'] ?? '')),
				];
			}
			return $normalized;
		}

		/**
		 * @param array $events Run events.
		 * @param int   $limit Item limit.
		 * @return array
		 */
		private static function recent_activity(array $events, $limit) {
			$items = [];
			for ($index = count($events) - 1; $index >= 0 && count($items) < $limit; --$index) {
				$event = is_array($events[$index] ?? null) ? $events[$index] : [];
				$items[] = [
					'activity_id' => substr(hash('sha256', (string) ($event['journey_id'] ?? '') . '|' . $index . '|' . (string) ($event['finished_at'] ?? '')), 0, 12),
					'step_key' => sanitize_key((string) ($event['step_key'] ?? '')),
					'step_name' => sanitize_text_field((string) ($event['step_name'] ?? '')),
					'status' => sanitize_key((string) ($event['status'] ?? '')),
					'started_at' => sanitize_text_field((string) ($event['started_at'] ?? '')),
					'finished_at' => sanitize_text_field((string) ($event['finished_at'] ?? '')),
					'duration_ms' => absint($event['duration_ms'] ?? 0),
					'actor' => sanitize_key((string) ($event['actor'] ?? '')),
					'trigger' => sanitize_key((string) ($event['trigger'] ?? '')),
					'page_id' => sanitize_text_field((string) ($event['page_id'] ?? '')),
					'exception_state' => sanitize_key((string) ($event['exception_state'] ?? '')),
					'warning_codes' => array_slice(array_map('sanitize_key', (array) ($event['warning_codes'] ?? [])), 0, 20),
					'error_code' => sanitize_key((string) ($event['error_code'] ?? '')),
				];
			}
			return $items;
		}

		/**
		 * @param array $values Raw numeric map.
		 * @return array
		 */
		private static function normalize_numeric_map(array $values) {
			$normalized = [];
			foreach (array_slice($values, 0, 30, true) as $key => $value) {
				if (is_numeric($value)) {
					$normalized[sanitize_key((string) $key)] = max(0, (int) $value);
				}
			}
			ksort($normalized);
			return $normalized;
		}

		/**
		 * @param string $path JSON path.
		 * @return array|WP_Error
		 */
		private static function read_json_file($path) {
			if (! is_file($path) || ! is_readable($path)) {
				return new WP_Error('dbvc_cc_cli_artifact_missing', 'A requested Content Migration artifact is missing.');
			}
			$size = filesize($path);
			if (is_int($size) && $size > self::MAX_JSON_BYTES) {
				return new WP_Error('dbvc_cc_cli_artifact_too_large', 'A Content Migration JSON artifact exceeds the 5 MB inspection limit.');
			}
			$raw = file_get_contents($path);
			$decoded = is_string($raw) ? json_decode($raw, true) : null;
			return is_array($decoded) ? $decoded : new WP_Error('dbvc_cc_cli_artifact_invalid', 'A Content Migration JSON artifact is invalid.');
		}

		/**
		 * @return string
		 */
		private static function normalize_domain($domain) {
			$domain = strtolower(trim((string) $domain));
			return $domain !== '' && preg_match('/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/', $domain) ? $domain : '';
		}

		/**
		 * @param string $base_dir Storage root.
		 * @param string $domain Domain key.
		 * @param string $subdir Relative subdirectory.
		 * @param string $file File name.
		 * @return string
		 */
		private static function domain_file($base_dir, $domain, $subdir, $file) {
			return trailingslashit($base_dir) . $domain . '/' . $subdir . '/' . $file;
		}

		/**
		 * @return bool
		 */
		private static function path_within($path, $base_dir) {
			$path = wp_normalize_path((string) $path);
			$base_dir = trailingslashit(wp_normalize_path((string) $base_dir));
			return $path !== '' && strpos(trailingslashit($path), $base_dir) === 0;
		}

		/**
		 * @return int
		 */
		private static function bounded_integer($value, $minimum, $maximum) {
			return min($maximum, max($minimum, (int) $value));
		}
	}
}

if (defined('WP_CLI') && WP_CLI && ! class_exists('DBVC_WP_CLI_Content_Migration_Runs')) {
	/**
	 * Inspect existing Content Migration V2 run artifacts without modifying them.
	 */
	class DBVC_WP_CLI_Content_Migration_Runs extends WP_CLI_Command {
		/**
		 * List bounded latest V2 runs.
		 *
		 * ## OPTIONS
		 *
		 * [--domain=<domain>]
		 * : Restrict to one exact stored domain key.
		 *
		 * [--status=<status>]
		 * : Restrict to one exact run status.
		 *
		 * [--search=<term>]
		 * : Match run ID, domain, or status.
		 *
		 * [--include-hidden]
		 * : Include runs hidden for the current WordPress user.
		 *
		 * [--limit=<number>]
		 * : Maximum rows. Default: 25; maximum: 100.
		 *
		 * [--offset=<number>]
		 * : Zero-based row offset.
		 *
		 * [--fields=<fields>]
		 * : Comma-separated table fields.
		 *
		 * [--format=<format>]
		 * : table or json. Default: table.
		 *
		 * ## EXAMPLES
		 *
		 * wp dbvc content-migration runs list --limit=25 --format=json
		 * wp dbvc content-migration runs list --domain=example.com --include-hidden
		 *
		 * @subcommand list
		 * @param array $args Positional arguments.
		 * @param array $assoc_args Named arguments.
		 * @return void
		 */
		public function list_($args, $assoc_args) {
			unset($args);
			$result = DBVC_CC_V2_CLI_Run_Inspector::list_runs($assoc_args);
			if (is_wp_error($result)) {
				WP_CLI::error($result->get_error_message());
			}
			$format = sanitize_key((string) ($assoc_args['format'] ?? 'table'));
			if ($format === 'json') {
				WP_CLI::line(wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
				return;
			}
			$fields = ['run_id', 'domain', 'status', 'updated_at', 'urls_discovered', 'urls_finalized', 'issue_count', 'hidden'];
			if (! empty($assoc_args['fields'])) {
				$fields = array_values(array_filter(array_map('trim', explode(',', (string) $assoc_args['fields']))));
			}
			$rows = array_map(static function ($row) {
				$row['hidden'] = ! empty($row['hidden']) ? 'yes' : 'no';
				return $row;
			}, (array) ($result['items'] ?? []));
			\WP_CLI\Utils\format_items('table', $rows, $fields);
			WP_CLI::log(sprintf('Returned %d of %d matching V2 run(s).', (int) $result['returned'], (int) $result['total_matching']));
		}

		/**
		 * Show one exact V2 run summary and overview.
		 *
		 * ## OPTIONS
		 *
		 * <run-id>
		 * : Exact Content Migration V2 run ID.
		 *
		 * [--domain=<domain>]
		 * : Restrict the lookup to one exact stored domain key.
		 *
		 * [--activity-limit=<number>]
		 * : Recent activity rows. Default: 12; maximum: 50.
		 *
		 * [--fields=<fields>]
		 * : Comma-separated table fields.
		 *
		 * [--format=<format>]
		 * : table or json. Default: table.
		 *
		 * [--fail-on-issues]
		 * : Return exit code 1 when failed, blocked, or needs-review stages exist.
		 *
		 * ## EXAMPLES
		 *
		 * wp dbvc content-migration runs show <run-id> --format=json
		 * wp dbvc content-migration runs show <run-id> --domain=example.com --fail-on-issues
		 *
		 * @param array $args Positional arguments.
		 * @param array $assoc_args Named arguments.
		 * @return void
		 */
		public function show($args, $assoc_args) {
			$run_id = isset($args[0]) ? (string) $args[0] : '';
			$result = DBVC_CC_V2_CLI_Run_Inspector::show_run($run_id, $assoc_args);
			if (is_wp_error($result)) {
				WP_CLI::error($result->get_error_message());
			}
			$format = sanitize_key((string) ($assoc_args['format'] ?? 'table'));
			if ($format === 'json') {
				WP_CLI::line(wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
			} else {
				$row = DBVC_CC_V2_CLI_Run_Inspector::show_table_row($result);
				$fields = array_keys($row);
				if (! empty($assoc_args['fields'])) {
					$fields = array_values(array_filter(array_map('trim', explode(',', (string) $assoc_args['fields']))));
				}
				\WP_CLI\Utils\format_items('table', [$row], $fields);
				WP_CLI::log(sprintf('Overview includes %d bounded stage(s) and %d recent activity item(s).', count((array) $result['stage_summary']['stages']), count((array) $result['recent_activity'])));
			}
			if (\WP_CLI\Utils\get_flag_value($assoc_args, 'fail-on-issues', false) && ! empty($result['has_issues'])) {
				WP_CLI::halt(1);
			}
		}
	}

	WP_CLI::add_command('dbvc content-migration runs', 'DBVC_WP_CLI_Content_Migration_Runs');
}
