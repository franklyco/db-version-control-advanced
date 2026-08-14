<?php

if (! defined('WPINC')) {
	die;
}

if (! class_exists('DBVC_Bricks_CLI_Inspector')) {
	/**
	 * Read-only preparation and formatting for the Bricks drift CLI.
	 */
	final class DBVC_Bricks_CLI_Inspector {
		private const DEFAULT_LIMIT = 25;
		private const MAX_LIMIT = 200;
		private const DEFAULT_MAX_CHANGES = 25;
		private const MAX_MAX_CHANGES = 200;
		private const MAX_MANIFEST_BYTES = 5242880;

		/**
		 * Inspect a stored package or local JSON manifest against current Bricks state.
		 *
		 * @param array $assoc_args Named command arguments.
		 * @return array|WP_Error
		 */
		public static function inspect(array $assoc_args) {
			$guard = self::guard_read_only_arguments($assoc_args);
			if (is_wp_error($guard)) {
				return $guard;
			}

			if (! class_exists('DBVC_Bricks_Addon') || ! class_exists('DBVC_Bricks_Drift')) {
				return new WP_Error('dbvc_bricks_cli_unavailable', 'The DBVC Bricks drift services are unavailable in this checkout.');
			}
			if (! DBVC_Bricks_Addon::is_enabled()) {
				return new WP_Error('dbvc_bricks_cli_disabled', 'The DBVC Bricks add-on is disabled. Enable it before inspecting drift.');
			}

			$source = self::resolve_manifest_source($assoc_args);
			if (is_wp_error($source)) {
				return $source;
			}

			$manifest = DBVC_Bricks_Addon::normalize_manifest_payload($source['manifest']);
			if (empty($manifest['artifacts']) || ! is_array($manifest['artifacts'])) {
				return new WP_Error('dbvc_bricks_cli_manifest_empty', 'The selected manifest does not contain any supported Bricks artifacts.');
			}

			$artifact_uid = sanitize_text_field((string) ($assoc_args['artifact-uid'] ?? ''));
			if ($artifact_uid !== '') {
				$manifest['artifacts'] = array_values(array_filter(
					$manifest['artifacts'],
					static function ($artifact) use ($artifact_uid) {
						return is_array($artifact) && (string) ($artifact['artifact_uid'] ?? '') === $artifact_uid;
					}
				));
				if ($manifest['artifacts'] === []) {
					return new WP_Error('dbvc_bricks_cli_artifact_missing', 'Artifact not found in the selected manifest: ' . $artifact_uid);
				}
			}

			$max_changes = self::bounded_integer($assoc_args['max-changes'] ?? self::DEFAULT_MAX_CHANGES, 1, self::MAX_MAX_CHANGES);
			$local_artifacts = DBVC_Bricks_Addon::resolve_local_artifacts_from_manifest($manifest);
			$result = DBVC_Bricks_Drift::scan($manifest, $local_artifacts, ['max_changes' => $max_changes]);
			if (is_wp_error($result)) {
				return $result;
			}
			if (! is_array($result)) {
				return new WP_Error('dbvc_bricks_cli_scan_invalid', 'The Bricks drift service returned an invalid result.');
			}

			$status = strtoupper(sanitize_key((string) ($assoc_args['status'] ?? '')));
			$allowed_statuses = ['', 'CLEAN', 'DIVERGED', 'OVERRIDDEN', 'PENDING_REVIEW'];
			if (! in_array($status, $allowed_statuses, true)) {
				return new WP_Error('dbvc_bricks_cli_status_invalid', 'Status must be clean, diverged, overridden, or pending_review.');
			}

			$rows = [];
			foreach ((array) ($result['artifacts'] ?? []) as $artifact) {
				if (! is_array($artifact)) {
					continue;
				}
				$row = self::compact_artifact_row($artifact);
				if ($status !== '' && $row['status'] !== $status) {
					continue;
				}
				$rows[] = $row;
			}

			$offset = self::bounded_integer($assoc_args['offset'] ?? 0, 0, PHP_INT_MAX);
			$limit = self::bounded_integer($assoc_args['limit'] ?? self::DEFAULT_LIMIT, 1, self::MAX_LIMIT);
			$total_matching = count($rows);
			$rows = array_slice($rows, $offset, $limit);
			$counts = self::normalize_counts((array) ($result['counts'] ?? []));
			$non_clean = $counts['diverged'] + $counts['overridden'] + $counts['pending_review'];
			$clean_with_path_differences = count(array_filter(
				(array) ($result['artifacts'] ?? []),
				static function ($artifact) {
					return is_array($artifact)
						&& strtoupper((string) ($artifact['status'] ?? '')) === 'CLEAN'
						&& (int) ($artifact['diff_summary']['total'] ?? 0) > 0;
				}
			));

			return [
				'source' => $source['source'],
				'source_reference' => $source['reference'],
				'package_id' => (string) ($result['package_id'] ?? $manifest['package_id'] ?? ''),
				'artifact_scope' => $artifact_uid !== '' ? $artifact_uid : 'all',
				'counts' => $counts,
				'non_clean' => $non_clean,
				'drift_detected' => $non_clean > 0,
				'clean_with_path_differences' => $clean_with_path_differences,
				'total_matching' => $total_matching,
				'returned' => count($rows),
				'offset' => $offset,
				'limit' => $limit,
				'has_more' => $offset + count($rows) < $total_matching,
				'artifacts' => $rows,
			];
		}

		/**
		 * Convert compact JSON rows to table-safe scalar rows.
		 *
		 * @param array $rows Compact artifact rows.
		 * @return array
		 */
		public static function table_rows(array $rows) {
			return array_map(
				static function ($row) {
					$changes = [];
					foreach ((array) ($row['path_differences'] ?? []) as $change) {
						if (! is_array($change)) {
							continue;
						}
						$changes[] = (string) ($change['path'] ?? '') . ':' . (string) ($change['type'] ?? '');
					}
					return [
						'artifact_uid' => (string) ($row['artifact_uid'] ?? ''),
						'artifact_type' => (string) ($row['artifact_type'] ?? ''),
						'status' => (string) ($row['status'] ?? ''),
						'path_difference_count' => (int) ($row['path_difference_count'] ?? 0),
						'path_differences' => implode(', ', array_filter($changes)),
						'truncated' => ! empty($row['path_differences_truncated']) ? 'yes' : 'no',
						'protected' => ! empty($row['protected']) ? 'yes' : 'no',
					];
				},
				$rows
			);
		}

		/**
		 * @param array $assoc_args Named command arguments.
		 * @return array|WP_Error
		 */
		private static function resolve_manifest_source(array $assoc_args) {
			$package_id = sanitize_text_field((string) ($assoc_args['package-id'] ?? ''));
			$file = trim((string) ($assoc_args['file'] ?? ''));
			if (($package_id === '' && $file === '') || ($package_id !== '' && $file !== '')) {
				return new WP_Error('dbvc_bricks_cli_source_required', 'Provide exactly one manifest source: --package-id=<id> or --file=<path>.');
			}

			if ($package_id !== '') {
				if (! class_exists('DBVC_Bricks_Packages')) {
					return new WP_Error('dbvc_bricks_cli_packages_unavailable', 'Stored Bricks packages are unavailable in this checkout.');
				}
				$package = DBVC_Bricks_Packages::get_package($package_id);
				if (! is_array($package)) {
					return new WP_Error('dbvc_bricks_cli_package_missing', 'Stored Bricks package not found: ' . $package_id);
				}
				return [
					'source' => 'stored_package',
					'reference' => $package_id,
					'manifest' => $package,
				];
			}

			$normalized_path = wp_normalize_path($file);
			if (! is_file($normalized_path) || ! is_readable($normalized_path)) {
				return new WP_Error('dbvc_bricks_cli_file_unreadable', 'Manifest file is not readable: ' . $normalized_path);
			}
			$size = filesize($normalized_path);
			if (is_int($size) && $size > self::MAX_MANIFEST_BYTES) {
				return new WP_Error('dbvc_bricks_cli_file_too_large', 'Manifest file exceeds the 5 MB inspection limit.');
			}
			$contents = file_get_contents($normalized_path);
			if (! is_string($contents)) {
				return new WP_Error('dbvc_bricks_cli_file_unreadable', 'Manifest file could not be read: ' . $normalized_path);
			}
			$manifest = json_decode($contents, true);
			if (! is_array($manifest)) {
				return new WP_Error('dbvc_bricks_cli_file_invalid', 'Manifest file is invalid JSON: ' . json_last_error_msg());
			}

			return [
				'source' => 'local_file',
				'reference' => $normalized_path,
				'manifest' => $manifest,
			];
		}

		/**
		 * @param array $assoc_args Named command arguments.
		 * @return true|WP_Error
		 */
		private static function guard_read_only_arguments(array $assoc_args) {
			foreach (['apply', 'write', 'mutate', 'include-raw-compare', 'raw'] as $flag) {
				if (array_key_exists($flag, $assoc_args)) {
					return new WP_Error('dbvc_bricks_cli_read_only', 'The Bricks drift CLI is read-only and rejects --' . $flag . '.');
				}
			}
			return true;
		}

		/**
		 * @param array $artifact Drift artifact row.
		 * @return array
		 */
		private static function compact_artifact_row(array $artifact) {
			$summary = isset($artifact['diff_summary']) && is_array($artifact['diff_summary']) ? $artifact['diff_summary'] : [];
			$protected = isset($artifact['protected_variant']) && is_array($artifact['protected_variant'])
				? ! empty($artifact['protected_variant']['is_protected'])
				: false;
			return [
				'artifact_uid' => (string) ($artifact['artifact_uid'] ?? ''),
				'artifact_type' => (string) ($artifact['artifact_type'] ?? ''),
				'artifact_label' => (string) ($artifact['artifact_label'] ?? ''),
				'status' => strtoupper((string) ($artifact['status'] ?? '')),
				'local_hash' => (string) ($artifact['local_hash'] ?? ''),
				'golden_hash' => (string) ($artifact['golden_hash'] ?? ''),
				'path_difference_count' => max(0, (int) ($summary['total'] ?? 0)),
				'path_differences' => array_values(array_filter((array) ($summary['changes'] ?? []), 'is_array')),
				'path_differences_truncated' => ! empty($summary['truncated']),
				'protected' => $protected,
			];
		}

		/**
		 * @param array $counts Drift counts.
		 * @return array
		 */
		private static function normalize_counts(array $counts) {
			return [
				'clean' => max(0, (int) ($counts['clean'] ?? 0)),
				'diverged' => max(0, (int) ($counts['diverged'] ?? 0)),
				'overridden' => max(0, (int) ($counts['overridden'] ?? 0)),
				'pending_review' => max(0, (int) ($counts['pending_review'] ?? 0)),
			];
		}

		/**
		 * @param mixed $value Raw number.
		 * @param int $minimum Minimum value.
		 * @param int $maximum Maximum value.
		 * @return int
		 */
		private static function bounded_integer($value, $minimum, $maximum) {
			return min($maximum, max($minimum, (int) $value));
		}
	}
}

if (! class_exists('DBVC_Bricks_CLI_Doctor')) {
	/**
	 * Side-effect-free Bricks control-plane inspection for WP-CLI.
	 */
	final class DBVC_Bricks_CLI_Doctor {
		private const MAX_WARNINGS = 50;
		private const MAX_LIST_ITEMS = 20;

		/**
		 * Build a bounded diagnostic envelope from existing read-only readers.
		 *
		 * @param array $assoc_args Named command arguments.
		 * @return array|WP_Error
		 */
		public static function inspect(array $assoc_args) {
			$guard = self::guard_arguments($assoc_args);
			if (is_wp_error($guard)) {
				return $guard;
			}
			if (! class_exists('DBVC_Bricks_Addon')) {
				return new WP_Error('dbvc_bricks_doctor_unavailable', 'The DBVC Bricks add-on services are unavailable in this checkout.');
			}

			$status = self::response_data(DBVC_Bricks_Addon::get_status(), 'status');
			$ui_contract = self::response_data(DBVC_Bricks_Addon::get_ui_contract(), 'UI contract');
			$schema = self::response_data(DBVC_Bricks_Addon::get_schema_verification(), 'schema verification');
			if (is_wp_error($status)) {
				return $status;
			}
			if (is_wp_error($ui_contract)) {
				return $ui_contract;
			}
			if (is_wp_error($schema)) {
				return $schema;
			}

			$deprecations = self::normalize_notices(DBVC_Bricks_Addon::get_deprecation_notices(), 'deprecation');
			$health_warnings = self::normalize_notices(DBVC_Bricks_Addon::get_runtime_health_warnings(), 'runtime_health');
			$normalized_schema = self::normalize_schema($schema);
			$schema_warnings = self::schema_warning_rows($normalized_schema);
			$warnings = array_slice(array_merge(
				! empty($status['enabled']) ? [] : [[
					'source' => 'status',
					'id' => 'addon_disabled',
					'code' => 'dbvc_bricks_addon_disabled',
					'severity' => 'warning',
					'title' => 'Bricks add-on disabled',
					'message' => 'The DBVC Bricks add-on is disabled on this site.',
				]],
				$health_warnings,
				$schema_warnings
			), 0, self::MAX_WARNINGS);

			$normalized_status = [
				'enabled' => ! empty($status['enabled']),
				'addon' => sanitize_key((string) ($status['addon'] ?? 'bricks')),
				'role' => sanitize_key((string) ($status['role'] ?? '')),
				'read_only' => ! empty($status['read_only']),
				'fleet_mode_enabled' => ! empty($status['fleet_mode_enabled']),
				'visibility' => sanitize_key((string) ($status['visibility'] ?? '')),
				'ui_contract_version' => sanitize_text_field((string) ($status['ui_contract_version'] ?? '')),
			];
			$normalized_ui_contract = [
				'ui_contract_version' => sanitize_text_field((string) ($ui_contract['ui_contract_version'] ?? '')),
				'features' => self::normalize_scalar_map((array) ($ui_contract['features'] ?? [])),
			];

			return [
				'ok' => $warnings === [],
				'summary' => [
					'enabled' => $normalized_status['enabled'],
					'role' => $normalized_status['role'],
					'read_only' => $normalized_status['read_only'],
					'visibility' => $normalized_status['visibility'],
					'ui_contract_version' => $normalized_ui_contract['ui_contract_version'],
					'theme_styles_present' => ! empty($normalized_schema['theme_styles']['present']),
					'components_present' => ! empty($normalized_schema['components']['present']),
					'warning_count' => count($warnings),
					'deprecation_count' => count($deprecations),
				],
				'status' => $normalized_status,
				'ui_contract' => $normalized_ui_contract,
				'schema' => $normalized_schema,
				'warnings' => $warnings,
				'deprecations' => $deprecations,
			];
		}

		/**
		 * Convert the diagnostic envelope to compact table rows.
		 *
		 * @param array $result Doctor result.
		 * @return array
		 */
		public static function table_rows(array $result) {
			$summary = (array) ($result['summary'] ?? []);
			$status = (array) ($result['status'] ?? []);
			$schema = (array) ($result['schema'] ?? []);
			$theme_styles = (array) ($schema['theme_styles'] ?? []);
			$components = (array) ($schema['components'] ?? []);
			$features = (array) ($result['ui_contract']['features'] ?? []);

			return [
				self::table_row('addon_status', ! empty($status['enabled']) ? 'pass' : 'warning', sprintf(
					'enabled=%s; role=%s; visibility=%s',
					! empty($status['enabled']) ? 'yes' : 'no',
					(string) ($status['role'] ?? ''),
					(string) ($status['visibility'] ?? '')
				)),
				self::table_row('operating_mode', 'pass', sprintf(
					'read_only=%s; fleet_mode=%s',
					! empty($status['read_only']) ? 'yes' : 'no',
					! empty($status['fleet_mode_enabled']) ? 'yes' : 'no'
				)),
				self::table_row('ui_contract', 'pass', sprintf(
					'version=%s; features=%d',
					(string) ($summary['ui_contract_version'] ?? ''),
					count($features)
				)),
				self::table_row('schema_theme_styles', empty($theme_styles['warnings']) ? 'pass' : 'warning', self::schema_detail($theme_styles, 'entry_count')),
				self::table_row('schema_components', empty($components['warnings']) ? 'pass' : 'warning', self::schema_detail($components, 'component_count')),
				self::table_row('runtime_health', empty($result['warnings']) ? 'pass' : 'warning', sprintf('%d warning(s)', (int) ($summary['warning_count'] ?? 0))),
				self::table_row('deprecations', empty($result['deprecations']) ? 'pass' : 'info', sprintf('%d notice(s)', (int) ($summary['deprecation_count'] ?? 0))),
			];
		}

		/**
		 * @param array $assoc_args Named command arguments.
		 * @return true|WP_Error
		 */
		private static function guard_arguments(array $assoc_args) {
			$allowed = ['format', 'fields', 'fail-on-warnings'];
			foreach (array_keys($assoc_args) as $argument) {
				if (! in_array((string) $argument, $allowed, true)) {
					return new WP_Error('dbvc_bricks_doctor_read_only', 'The Bricks doctor is read-only and rejects --' . sanitize_key((string) $argument) . '.');
				}
			}
			$format = sanitize_key((string) ($assoc_args['format'] ?? 'table'));
			if (! in_array($format, ['table', 'json'], true)) {
				return new WP_Error('dbvc_bricks_doctor_format_invalid', 'Format must be table or json.');
			}
			return true;
		}

		/**
		 * @param mixed $response REST response or array.
		 * @param string $label Reader label.
		 * @return array|WP_Error
		 */
		private static function response_data($response, $label) {
			if (is_wp_error($response)) {
				return $response;
			}
			if ($response instanceof WP_REST_Response) {
				$response = $response->get_data();
			}
			if (! is_array($response)) {
				return new WP_Error('dbvc_bricks_doctor_invalid_reader', 'The Bricks ' . $label . ' reader returned an invalid response.');
			}
			return $response;
		}

		/**
		 * @param array $schema Raw schema report.
		 * @return array
		 */
		private static function normalize_schema(array $schema) {
			return [
				'generated_at' => sanitize_text_field((string) ($schema['generated_at'] ?? '')),
				'theme_styles' => self::normalize_schema_section((array) ($schema['theme_styles'] ?? []), ['present', 'payload_type', 'entry_count', 'top_level_keys', 'warnings']),
				'components' => self::normalize_schema_section((array) ($schema['components'] ?? []), ['present', 'payload_type', 'component_count', 'label_coverage', 'slug_coverage', 'dominant_label_path', 'dominant_slug_path', 'warnings']),
				'migration_notes' => self::normalize_string_list((array) ($schema['migration_notes'] ?? [])),
			];
		}

		/**
		 * @param array $section Raw section.
		 * @param array $keys Allowed keys.
		 * @return array
		 */
		private static function normalize_schema_section(array $section, array $keys) {
			$normalized = [];
			foreach ($keys as $key) {
				if (! array_key_exists($key, $section)) {
					continue;
				}
				$value = $section[$key];
				if (in_array($key, ['warnings', 'top_level_keys'], true)) {
					$normalized[$key] = self::normalize_string_list((array) $value);
				} elseif (is_bool($value) || is_int($value) || is_float($value)) {
					$normalized[$key] = $value;
				} else {
					$normalized[$key] = sanitize_text_field((string) $value);
				}
			}
			$normalized['warnings'] = (array) ($normalized['warnings'] ?? []);
			return $normalized;
		}

		/**
		 * @param array $values Raw string values.
		 * @return array
		 */
		private static function normalize_string_list(array $values) {
			$normalized = [];
			foreach (array_slice(array_values($values), 0, self::MAX_LIST_ITEMS) as $value) {
				if (is_scalar($value)) {
					$normalized[] = sanitize_text_field((string) $value);
				}
			}
			return $normalized;
		}

		/**
		 * @param array $values Raw scalar map.
		 * @return array
		 */
		private static function normalize_scalar_map(array $values) {
			$normalized = [];
			foreach (array_slice($values, 0, self::MAX_LIST_ITEMS, true) as $key => $value) {
				if (is_bool($value) || is_int($value) || is_float($value)) {
					$normalized[sanitize_key((string) $key)] = $value;
				} elseif (is_scalar($value)) {
					$normalized[sanitize_key((string) $key)] = sanitize_text_field((string) $value);
				}
			}
			return $normalized;
		}

		/**
		 * @param array $notices Raw notices.
		 * @param string $source Notice source.
		 * @return array
		 */
		private static function normalize_notices(array $notices, $source) {
			$normalized = [];
			foreach (array_slice(array_values($notices), 0, self::MAX_WARNINGS) as $notice) {
				if (! is_array($notice)) {
					continue;
				}
				$normalized[] = [
					'source' => sanitize_key((string) $source),
					'id' => sanitize_key((string) ($notice['id'] ?? '')),
					'code' => sanitize_key((string) ($notice['code'] ?? '')),
					'severity' => sanitize_key((string) ($notice['severity'] ?? ($source === 'deprecation' ? 'info' : 'warning'))),
					'title' => sanitize_text_field((string) ($notice['title'] ?? '')),
					'message' => sanitize_text_field((string) ($notice['message'] ?? '')),
					'since' => sanitize_text_field((string) ($notice['since'] ?? '')),
					'remove_after' => sanitize_text_field((string) ($notice['remove_after'] ?? '')),
				];
			}
			return $normalized;
		}

		/**
		 * @param array $schema Normalized schema.
		 * @return array
		 */
		private static function schema_warning_rows(array $schema) {
			$rows = [];
			foreach (['theme_styles', 'components'] as $section) {
				foreach ((array) ($schema[$section]['warnings'] ?? []) as $warning) {
					$rows[] = [
						'source' => 'schema',
						'id' => sanitize_key($section . '_' . (string) $warning),
						'code' => sanitize_key((string) $warning),
						'severity' => 'warning',
						'title' => sanitize_text_field(str_replace('_', ' ', $section) . ' schema'),
						'message' => sanitize_text_field((string) $warning),
					];
				}
			}
			return $rows;
		}

		/**
		 * @param string $check Check name.
		 * @param string $status Check status.
		 * @param string $detail Check detail.
		 * @return array
		 */
		private static function table_row($check, $status, $detail) {
			return [
				'check' => (string) $check,
				'status' => (string) $status,
				'detail' => (string) $detail,
			];
		}

		/**
		 * @param array $section Schema section.
		 * @param string $count_key Count key.
		 * @return string
		 */
		private static function schema_detail(array $section, $count_key) {
			return sprintf(
				'present=%s; type=%s; count=%d; warnings=%d',
				! empty($section['present']) ? 'yes' : 'no',
				(string) ($section['payload_type'] ?? ''),
				(int) ($section[$count_key] ?? 0),
				count((array) ($section['warnings'] ?? []))
			);
		}
	}
}

if (defined('WP_CLI') && WP_CLI && ! class_exists('DBVC_WP_CLI_Bricks')) {
	/**
	 * Inspect DBVC Bricks add-on state without applying changes.
	 */
	class DBVC_WP_CLI_Bricks extends WP_CLI_Command {
		/**
		 * Inspect Bricks add-on status, UI contract, and live schema health.
		 *
		 * This command does not read stored UI diagnostic events or dispatch any
		 * settings, package, fleet, remote, proposal, apply, or restore operation.
		 *
		 * ## OPTIONS
		 *
		 * [--format=<format>]
		 * : table or json. Default: table.
		 *
		 * [--fields=<fields>]
		 * : Comma-separated fields for table output.
		 *
		 * [--fail-on-warnings]
		 * : Return exit code 1 when a health or schema warning exists.
		 *
		 * ## EXAMPLES
		 *
		 * wp dbvc bricks doctor
		 * wp dbvc bricks doctor --format=json --fail-on-warnings
		 *
		 * @param array $args Positional arguments.
		 * @param array $assoc_args Named arguments.
		 * @return void
		 */
		public function doctor($args, $assoc_args) {
			unset($args);
			$result = DBVC_Bricks_CLI_Doctor::inspect($assoc_args);
			if (is_wp_error($result)) {
				WP_CLI::error($result->get_error_message());
			}

			$format = sanitize_key((string) ($assoc_args['format'] ?? 'table'));
			if (! in_array($format, ['table', 'json'], true)) {
				WP_CLI::error('Format must be table or json.');
			}
			if ($format === 'json') {
				WP_CLI::line(wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
			} else {
				$rows = DBVC_Bricks_CLI_Doctor::table_rows($result);
				$fields = ['check', 'status', 'detail'];
				if (! empty($assoc_args['fields'])) {
					$fields = array_values(array_filter(array_map('trim', explode(',', (string) $assoc_args['fields']))));
				}
				\WP_CLI\Utils\format_items('table', $rows, $fields);
				WP_CLI::log(sprintf(
					'Bricks doctor completed with %d warning(s) and %d deprecation notice(s).',
					(int) ($result['summary']['warning_count'] ?? 0),
					(int) ($result['summary']['deprecation_count'] ?? 0)
				));
			}

			if (\WP_CLI\Utils\get_flag_value($assoc_args, 'fail-on-warnings', false) && ! empty($result['summary']['warning_count'])) {
				WP_CLI::halt(1);
			}
		}

		/**
		 * Compare a stored package or local manifest with current Bricks artifacts.
		 *
		 * ## OPTIONS
		 *
		 * [--package-id=<id>]
		 * : Inspect one locally stored Bricks package. Mutually exclusive with --file.
		 *
		 * [--file=<path>]
		 * : Inspect one local JSON manifest up to 5 MB. Mutually exclusive with --package-id.
		 *
		 * [--artifact-uid=<uid>]
		 * : Restrict comparison to one exact artifact UID.
		 *
		 * [--status=<status>]
		 * : Filter rows by clean, diverged, overridden, or pending_review.
		 *
		 * [--max-changes=<number>]
		 * : Maximum changed paths retained per artifact. Default: 25; maximum: 200.
		 *
		 * [--limit=<number>]
		 * : Maximum rows returned. Default: 25; maximum: 200.
		 *
		 * [--offset=<number>]
		 * : Zero-based row offset. Default: 0.
		 *
		 * [--fields=<fields>]
		 * : Comma-separated fields for table output.
		 *
		 * [--format=<format>]
		 * : table or json. Default: table.
		 *
		 * [--fail-on-drift]
		 * : Return exit code 1 when any analyzed artifact is non-clean.
		 *
		 * ## EXAMPLES
		 *
		 * wp dbvc bricks drift --package-id=pkg_20260810 --format=json
		 * wp dbvc bricks drift --file=/tmp/bricks-manifest.json --artifact-uid=option:bricks_global_classes --fail-on-drift
		 *
		 * @param array $args Positional arguments.
		 * @param array $assoc_args Named arguments.
		 * @return void
		 */
		public function drift($args, $assoc_args) {
			unset($args);
			$result = DBVC_Bricks_CLI_Inspector::inspect($assoc_args);
			if (is_wp_error($result)) {
				WP_CLI::error($result->get_error_message());
			}

			$format = sanitize_key((string) ($assoc_args['format'] ?? 'table'));
			if (! in_array($format, ['table', 'json'], true)) {
				WP_CLI::error('Format must be table or json.');
			}

			if ($format === 'json') {
				WP_CLI::line(wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
			} else {
				$rows = DBVC_Bricks_CLI_Inspector::table_rows((array) ($result['artifacts'] ?? []));
				$fields = ['artifact_uid', 'artifact_type', 'status', 'path_difference_count', 'path_differences', 'truncated', 'protected'];
				if (! empty($assoc_args['fields'])) {
					$fields = array_values(array_filter(array_map('trim', explode(',', (string) $assoc_args['fields']))));
				}
				\WP_CLI\Utils\format_items('table', $rows, $fields);
				WP_CLI::log(sprintf(
					'Analyzed %d artifact(s): %d clean; %d non-clean. Returned %d of %d matching row(s).',
					array_sum((array) $result['counts']),
					(int) ($result['counts']['clean'] ?? 0),
					(int) ($result['non_clean'] ?? 0),
					(int) ($result['returned'] ?? 0),
					(int) ($result['total_matching'] ?? 0)
				));
			}

			if (! empty($assoc_args['fail-on-drift']) && ! empty($result['drift_detected'])) {
				WP_CLI::halt(1);
			}
		}
	}

	WP_CLI::add_command('dbvc bricks', 'DBVC_WP_CLI_Bricks');
}
