<?php

/**
 * Read-only DBVC capability discovery commands.
 *
 * @package DB_Version_Control
 */

if (! defined('WPINC')) {
	die;
}

if (! class_exists('DBVC_Capability_Repository')) {
	/**
	 * Read and query the packaged agent capability artifacts.
	 */
	class DBVC_Capability_Repository {
		/**
		 * Load the curated capability manifest.
		 *
		 * @return array|WP_Error
		 */
		public static function load_manifest() {
			return self::load_json(self::plugin_root() . '/docs/agents/manifest.json', 'manifest');
		}

		/**
		 * Load the generated source-discovery snapshot.
		 *
		 * @return array|WP_Error
		 */
		public static function load_snapshot() {
			return self::load_json(self::plugin_root() . '/docs/agents/generated/discovery-snapshot.json', 'discovery snapshot');
		}

		/**
		 * Return one capability record by stable ID.
		 *
		 * @param array  $records Capability records.
		 * @param string $id      Stable record ID.
		 * @return array|null
		 */
		public static function find_record(array $records, $id) {
			foreach ($records as $record) {
				if (is_array($record) && (string) ($record['id'] ?? '') === (string) $id) {
					return $record;
				}
			}
			return null;
		}

		/**
		 * Filter capability records using exact reviewed fields plus text search.
		 *
		 * @param array $records Capability records.
		 * @param array $filters Filter values.
		 * @return array
		 */
		public static function filter_records(array $records, array $filters) {
			$filtered = [];
			foreach ($records as $record) {
				if (! is_array($record)) {
					continue;
				}
				$opportunity = is_array($record['opportunity'] ?? null) ? $record['opportunity'] : [];
				$surface_types = [];
				foreach ((array) ($record['surfaces'] ?? []) as $surface) {
					if (is_array($surface) && ! empty($surface['type'])) {
						$surface_types[] = (string) $surface['type'];
					}
				}
				$exact = [
					'status'      => (string) ($record['status'] ?? ''),
					'category'    => (string) ($record['primary_category'] ?? ''),
					'safety'      => (string) ($record['safety']['classification'] ?? 'unknown'),
					'opportunity' => (string) ($opportunity['disposition'] ?? 'unreviewed'),
					'priority'    => (string) ($opportunity['priority'] ?? 'none'),
				];

				$matches = true;
				foreach ($exact as $key => $value) {
					if (! empty($filters[$key]) && (string) $filters[$key] !== $value) {
						$matches = false;
						break;
					}
				}
				if ($matches && ! empty($filters['surface']) && ! in_array((string) $filters['surface'], $surface_types, true)) {
					$matches = false;
				}
				if ($matches && ! empty($filters['search'])) {
					$search_parts = [
						$record['id'] ?? '',
						$record['title'] ?? '',
						$record['summary'] ?? '',
						$record['addon_or_owner'] ?? '',
						$opportunity['rationale'] ?? '',
						$opportunity['candidate_scope'] ?? '',
						$opportunity['next_action'] ?? '',
					];
					$search_parts = array_merge(
						$search_parts,
						(array) ($record['aliases'] ?? []),
						(array) ($record['tags'] ?? []),
						(array) ($record['known_gaps'] ?? []),
						(array) ($opportunity['excluded_operations'] ?? [])
					);
					$haystack = strtolower(implode(' ', array_map('strval', $search_parts)));
					if (false === strpos($haystack, strtolower((string) $filters['search']))) {
						$matches = false;
					}
				}
				if ($matches) {
					$filtered[] = $record;
				}
			}

			usort(
				$filtered,
				static function ($left, $right) {
					return strcmp((string) ($left['id'] ?? ''), (string) ($right['id'] ?? ''));
				}
			);
			return $filtered;
		}

		/**
		 * Convert one record into a compact list/show row.
		 *
		 * @param array $record Capability record.
		 * @return array
		 */
		public static function record_row(array $record) {
			$surface_types = [];
			foreach ((array) ($record['surfaces'] ?? []) as $surface) {
				if (is_array($surface) && ! empty($surface['type'])) {
					$surface_types[] = (string) $surface['type'];
				}
			}
			$surface_types = array_values(array_unique($surface_types));
			sort($surface_types);
			$opportunity = is_array($record['opportunity'] ?? null) ? $record['opportunity'] : [];

			return [
				'id'          => (string) ($record['id'] ?? ''),
				'status'      => (string) ($record['status'] ?? ''),
				'category'    => (string) ($record['primary_category'] ?? ''),
				'safety'      => (string) ($record['safety']['classification'] ?? 'unknown'),
				'surfaces'    => implode(',', $surface_types),
				'opportunity' => (string) ($opportunity['disposition'] ?? 'unreviewed'),
				'priority'    => (string) ($opportunity['priority'] ?? 'none'),
				'effort'      => (string) ($opportunity['effort'] ?? 'unknown'),
				'candidate_scope' => (string) ($opportunity['candidate_scope'] ?? ''),
				'excluded_operations' => implode(', ', (array) ($opportunity['excluded_operations'] ?? [])),
				'entrypoint'  => (string) ($record['runtime_entrypoint'] ?? ''),
				'title'       => (string) ($record['title'] ?? ''),
				'summary'     => (string) ($record['summary'] ?? ''),
			];
		}

		/**
		 * Reconcile enforced discovery IDs with manifest ownership.
		 *
		 * @param array $manifest Curated manifest.
		 * @param array $snapshot Discovery snapshot.
		 * @return array
		 */
		public static function coverage(array $manifest, array $snapshot) {
			$collections = ['cli_commands', 'rest_routes', 'admin_menus', 'admin_handlers', 'extension_points', 'settings', 'database_tables', 'scheduled_hooks'];
			$discovered = [];
			foreach ($collections as $collection) {
				foreach ((array) ($snapshot['collections'][$collection] ?? []) as $item) {
					if (is_array($item) && ! empty($item['discovery_id'])) {
						$discovered[(string) $item['discovery_id']] = true;
					}
				}
			}
			$owners = [];
			foreach ((array) ($manifest['records'] ?? []) as $record) {
				foreach ((array) ($record['surfaces'] ?? []) as $surface) {
					foreach ((array) ($surface['discovery_ids'] ?? []) as $discovery_id) {
						$owners[(string) $discovery_id][] = (string) ($record['id'] ?? 'unknown');
					}
				}
			}
			foreach ((array) ($manifest['ignored_discovery'] ?? []) as $ignored) {
				if (is_array($ignored) && ! empty($ignored['discovery_id'])) {
					$owners[(string) $ignored['discovery_id']][] = 'ignored_discovery';
				}
			}

			$unmapped = array_diff_key($discovered, $owners);
			$unknown = array_diff_key($owners, $discovered);
			$duplicates = [];
			foreach ($owners as $discovery_id => $mapped_owners) {
				if (count($mapped_owners) > 1) {
					$duplicates[$discovery_id] = $mapped_owners;
				}
			}

			return [
				'discovered' => count($discovered),
				'mapped'     => count(array_intersect_key($discovered, $owners)),
				'unmapped'   => count($unmapped),
				'unknown'    => count($unknown),
				'duplicates' => count($duplicates),
			];
		}

		/**
		 * Load one JSON artifact.
		 *
		 * @param string $path  Absolute path.
		 * @param string $label Human label.
		 * @return array|WP_Error
		 */
		private static function load_json($path, $label) {
			if (! is_readable($path)) {
				return new WP_Error('dbvc_capability_artifact_missing', sprintf('The DBVC capability %s is not readable: %s', $label, $path));
			}
			$contents = file_get_contents($path);
			if (false === $contents) {
				return new WP_Error('dbvc_capability_artifact_read_failed', sprintf('The DBVC capability %s could not be read.', $label));
			}
			$data = json_decode($contents, true);
			if (! is_array($data)) {
				return new WP_Error('dbvc_capability_artifact_invalid', sprintf('The DBVC capability %s is invalid JSON: %s', $label, json_last_error_msg()));
			}
			return $data;
		}

		/**
		 * Resolve the active plugin root.
		 *
		 * @return string
		 */
		private static function plugin_root() {
			return rtrim(defined('DBVC_PLUGIN_PATH') ? DBVC_PLUGIN_PATH : dirname(__DIR__) . '/', '/');
		}
	}
}

if (defined('WP_CLI') && WP_CLI && ! class_exists('DBVC_WP_CLI_Capabilities')) {
	/**
	 * Inspect the packaged DBVC capability library without invoking capabilities.
	 */
	class DBVC_WP_CLI_Capabilities extends WP_CLI_Command {
		/**
		 * List curated DBVC capability records.
		 *
		 * ## OPTIONS
		 *
		 * [--status=<status>]
		 * : Filter by manifest status.
		 *
		 * [--category=<category>]
		 * : Filter by primary category.
		 *
		 * [--safety=<classification>]
		 * : Filter by reviewed safety classification.
		 *
		 * [--surface=<surface>]
		 * : Filter by interface such as cli, rest, admin, or php.
		 *
		 * [--opportunity=<disposition>]
		 * : Filter by candidate, needs_review, covered_elsewhere, deferred, not_recommended, or unreviewed.
		 *
		 * [--priority=<priority>]
		 * : Filter by high, medium, low, or none.
		 *
		 * [--search=<text>]
		 * : Search IDs, titles, summaries, aliases, tags, gaps, and opportunity rationale.
		 *
		 * [--fields=<fields>]
		 * : Comma-separated output fields.
		 *
		 * [--format=<format>]
		 * : Output format accepted by WP-CLI. Default: table.
		 *
		 * ## EXAMPLES
		 *
		 * wp dbvc capabilities list --safety=read_only
		 * wp dbvc capabilities list --opportunity=candidate --priority=high --format=json
		 *
		 * @subcommand list
		 *
		 * @param array $args Positional arguments.
		 * @param array $assoc_args Named arguments.
		 * @return void
		 */
		public function list_($args, $assoc_args) {
			unset($args);
			$manifest = $this->manifest_or_error();
			$records = DBVC_Capability_Repository::filter_records((array) ($manifest['records'] ?? []), $assoc_args);
			$rows = array_map(['DBVC_Capability_Repository', 'record_row'], $records);
			$fields = $this->fields($assoc_args, ['id', 'status', 'category', 'safety', 'surfaces', 'opportunity', 'priority', 'title']);
			$format = sanitize_key((string) ($assoc_args['format'] ?? 'table'));
			\WP_CLI\Utils\format_items($format, $rows, $fields);
		}

		/**
		 * Show one canonical capability record.
		 *
		 * ## OPTIONS
		 *
		 * <id>
		 * : Stable manifest record ID.
		 *
		 * [--format=<format>]
		 * : table, json, or yaml. Default: table.
		 *
		 * ## EXAMPLES
		 *
		 * wp dbvc capabilities show addon.bricks.drift
		 * wp dbvc capabilities show cli.core.import --format=json
		 *
		 * @param array $args Positional arguments.
		 * @param array $assoc_args Named arguments.
		 * @return void
		 */
		public function show($args, $assoc_args) {
			$id = isset($args[0]) ? (string) $args[0] : '';
			if ('' === $id) {
				WP_CLI::error('Provide a capability record ID.');
			}
			$manifest = $this->manifest_or_error();
			$record = DBVC_Capability_Repository::find_record((array) ($manifest['records'] ?? []), $id);
			if (null === $record) {
				WP_CLI::error('Capability record not found: ' . $id);
			}
			$format = sanitize_key((string) ($assoc_args['format'] ?? 'table'));
			if ('json' === $format) {
				WP_CLI::line(wp_json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
				return;
			}
			$row = DBVC_Capability_Repository::record_row($record);
			\WP_CLI\Utils\format_items($format, [$row], array_keys($row));
		}

		/**
		 * Diagnose capability-library packaging, ownership, and runtime registration.
		 *
		 * This command does not dispatch any DBVC capability or write site data.
		 *
		 * ## OPTIONS
		 *
		 * [--format=<format>]
		 * : Output format accepted by WP-CLI. Default: table.
		 *
		 * [--fail-on-warnings]
		 * : Return a non-zero exit when advisory warnings exist.
		 *
		 * ## EXAMPLES
		 *
		 * wp dbvc capabilities doctor
		 * wp dbvc capabilities doctor --format=json --fail-on-warnings
		 *
		 * @param array $args Positional arguments.
		 * @param array $assoc_args Named arguments.
		 * @return void
		 */
		public function doctor($args, $assoc_args) {
			unset($args);
			$rows = [];
			$errors = 0;
			$warnings = 0;
			$manifest = DBVC_Capability_Repository::load_manifest();
			$snapshot = DBVC_Capability_Repository::load_snapshot();

			if (is_wp_error($manifest)) {
				$rows[] = $this->doctor_row('manifest', 'error', $manifest->get_error_message());
				++$errors;
			} else {
				$rows[] = $this->doctor_row('manifest', 'pass', sprintf('%d records; schema %s; enforcement %s', count((array) ($manifest['records'] ?? [])), (string) ($manifest['schema_version'] ?? 'unknown'), (string) ($manifest['coverage_enforcement'] ?? 'unknown')));
			}
			if (is_wp_error($snapshot)) {
				$rows[] = $this->doctor_row('discovery_snapshot', 'error', $snapshot->get_error_message());
				++$errors;
			} else {
				$rows[] = $this->doctor_row('discovery_snapshot', 'pass', sprintf('Fingerprint %s', (string) ($snapshot['repository']['source_fingerprint'] ?? 'missing')));
			}
			if (! is_wp_error($manifest) && ! is_wp_error($snapshot)) {
				$coverage = DBVC_Capability_Repository::coverage($manifest, $snapshot);
				$coverage_status = 0 === $coverage['unmapped'] && 0 === $coverage['unknown'] && 0 === $coverage['duplicates'] ? 'pass' : 'error';
				$rows[] = $this->doctor_row('strict_coverage', $coverage_status, sprintf('%d discovered; %d mapped; %d unmapped; %d unknown; %d duplicate owners', $coverage['discovered'], $coverage['mapped'], $coverage['unmapped'], $coverage['unknown'], $coverage['duplicates']));
				if ('error' === $coverage_status) {
					++$errors;
				}

				if (empty($manifest['baseline']['live_runtime_verified'])) {
					$rows[] = $this->doctor_row('runtime_baseline', 'warning', (string) ($manifest['baseline']['live_runtime_notes'] ?? 'The manifest baseline is not fully runtime verified.'));
					++$warnings;
				} else {
					$rows[] = $this->doctor_row('runtime_baseline', 'pass', 'Manifest baseline is marked live-runtime verified.');
				}
			}

			$rows[] = $this->doctor_row('active_checkout', 'pass', sprintf('%s (DBVC %s; WordPress %s)', rtrim((string) DBVC_PLUGIN_PATH, '/'), defined('DBVC_PLUGIN_VERSION') ? DBVC_PLUGIN_VERSION : 'unknown', get_bloginfo('version')));

			$server = rest_get_server();
			if (! did_action('rest_api_init')) {
				do_action('rest_api_init', $server);
			}
			$route_counts = ['dbvc/v1' => 0, 'dbvc_cc/v2' => 0];
			foreach (array_keys($server->get_routes()) as $route) {
				foreach (array_keys($route_counts) as $namespace) {
					if (0 === strpos((string) $route, '/' . $namespace)) {
						++$route_counts[$namespace];
					}
				}
			}
			$rows[] = $this->doctor_row('rest_registration', $route_counts['dbvc/v1'] > 0 ? 'pass' : 'error', sprintf('dbvc/v1=%d; dbvc_cc/v2=%d', $route_counts['dbvc/v1'], $route_counts['dbvc_cc/v2']));
			if ($route_counts['dbvc/v1'] < 1) {
				++$errors;
			}

			$addon_options = [
				'visual_editor'    => 'dbvc_addon_visual_editor_enabled',
				'bricks'           => 'dbvc_addon_bricks_enabled',
				'content_migration' => 'dbvc_cc_addon_enabled',
			];
			$addon_states = [];
			foreach ($addon_options as $label => $option_name) {
				$addon_states[] = $label . '=' . ('1' === (string) get_option($option_name, '0') ? 'enabled' : 'disabled');
			}
			$rows[] = $this->doctor_row('addon_gates', 'pass', implode('; ', $addon_states));

			$format = sanitize_key((string) ($assoc_args['format'] ?? 'table'));
			\WP_CLI\Utils\format_items($format, $rows, ['check', 'status', 'detail']);
			if ($errors > 0) {
				if ('table' === $format) {
					WP_CLI::error(sprintf('Capability doctor found %d error(s).', $errors));
				}
				WP_CLI::halt(1);
			}
			if ($warnings > 0 && \WP_CLI\Utils\get_flag_value($assoc_args, 'fail-on-warnings', false)) {
				if ('table' === $format) {
					WP_CLI::error(sprintf('Capability doctor found %d warning(s).', $warnings));
				}
				WP_CLI::halt(1);
			}
			if ('table' === $format) {
				WP_CLI::success(sprintf('Capability doctor passed with %d warning(s).', $warnings));
			}
		}

		/**
		 * Load the manifest or stop with an actionable error.
		 *
		 * @return array
		 */
		private function manifest_or_error() {
			$manifest = DBVC_Capability_Repository::load_manifest();
			if (is_wp_error($manifest)) {
				WP_CLI::error($manifest->get_error_message());
			}
			return $manifest;
		}

		/**
		 * Resolve selected output fields.
		 *
		 * @param array $assoc_args Named arguments.
		 * @param array $defaults   Default fields.
		 * @return array
		 */
		private function fields(array $assoc_args, array $defaults) {
			if (empty($assoc_args['fields'])) {
				return $defaults;
			}
			return array_values(array_filter(array_map('trim', explode(',', (string) $assoc_args['fields']))));
		}

		/**
		 * Build one doctor result row.
		 *
		 * @param string $check  Check name.
		 * @param string $status pass, warning, or error.
		 * @param string $detail Result detail.
		 * @return array
		 */
		private function doctor_row($check, $status, $detail) {
			return [
				'check'  => (string) $check,
				'status' => (string) $status,
				'detail' => (string) $detail,
			];
		}
	}

	WP_CLI::add_command('dbvc capabilities', 'DBVC_WP_CLI_Capabilities');
}
