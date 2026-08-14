<?php

if (! defined('WPINC')) {
	die;
}

if (! class_exists('DBVC_Entity_Editor_CLI_Inspector')) {
	/**
	 * Read-only cached-index and structural inspection for Entity Editor files.
	 */
	final class DBVC_Entity_Editor_CLI_Inspector {
		private const TRANSIENT_KEY = 'dbvc_entity_editor_index_v1';
		private const DISK_CACHE_FILE = '.dbvc-entity-index.json';
		private const INDEX_TTL = 300;
		private const DEFAULT_LIMIT = 25;
		private const MAX_LIMIT = 200;
		private const MAX_CACHE_BYTES = 26214400;
		private const MAX_AGE_SECONDS = 31536000;

		/**
		 * List compact Entity Editor records from an existing cache.
		 *
		 * @param array $assoc_args Named command arguments.
		 * @return array|WP_Error
		 */
		public static function list_entities(array $assoc_args) {
			$guard = self::guard_read_only_arguments($assoc_args);
			if (is_wp_error($guard)) {
				return $guard;
			}

			$cached = self::load_cached_index($assoc_args);
			if (is_wp_error($cached)) {
				return $cached;
			}

			$filters = self::normalize_filters($assoc_args);
			if (is_wp_error($filters)) {
				return $filters;
			}

			$items = [];
			foreach ((array) ($cached['payload']['items'] ?? []) as $item) {
				if (! is_array($item)) {
					continue;
				}
				$compact = self::compact_item($item);
				if (! self::matches_filters($compact, $filters)) {
					continue;
				}
				$items[] = $compact;
			}

			$total_matching = count($items);
			$items = array_slice($items, $filters['offset'], $filters['limit']);

			return [
				'index' => self::index_metadata($cached),
				'filters' => [
					'kind' => $filters['kind'],
					'subtype' => $filters['subtype'],
					'provider' => $filters['provider'],
					'match' => $filters['match'],
					'duplicates' => $filters['duplicates'],
					'search' => $filters['search'],
				],
				'total_matching' => $total_matching,
				'returned' => count($items),
				'offset' => $filters['offset'],
				'limit' => $filters['limit'],
				'has_more' => $filters['offset'] + count($items) < $total_matching,
				'items' => $items,
			];
		}

		/**
		 * Inspect one indexed Entity Editor file without locks or raw-value output.
		 *
		 * @param string $relative_path Sync-relative indexed path.
		 * @param array  $assoc_args    Named command arguments.
		 * @return array|WP_Error
		 */
		public static function inspect_entity($relative_path, array $assoc_args = []) {
			$guard = self::guard_read_only_arguments($assoc_args);
			if (is_wp_error($guard)) {
				return $guard;
			}
			if (! class_exists('DBVC_Entity_Editor_Indexer')) {
				return new WP_Error('dbvc_entity_editor_cli_unavailable', 'The Entity Editor indexer is unavailable in this checkout.');
			}

			$relative_path = self::normalize_relative_path($relative_path);
			if ($relative_path === '') {
				return new WP_Error('dbvc_entity_editor_cli_path_required', 'Provide one sync-relative Entity Editor path.');
			}

			$cached = self::load_cached_index($assoc_args);
			if (is_wp_error($cached)) {
				return $cached;
			}

			$indexed_item = null;
			foreach ((array) ($cached['payload']['items'] ?? []) as $item) {
				if (is_array($item) && self::normalize_relative_path($item['relative_path'] ?? '') === $relative_path) {
					$indexed_item = $item;
					break;
				}
			}
			if (! is_array($indexed_item)) {
				return new WP_Error('dbvc_entity_editor_cli_path_not_indexed', 'The path is not present in the cached supported Entity Editor index: ' . $relative_path);
			}

			$loaded = DBVC_Entity_Editor_Indexer::load_entity_file_for_download($relative_path);
			if (is_wp_error($loaded)) {
				return $loaded;
			}
			$decoded = isset($loaded['decoded']) && is_array($loaded['decoded']) ? $loaded['decoded'] : [];
			$content = isset($loaded['content']) && is_string($loaded['content']) ? $loaded['content'] : '';
			$description = DBVC_Entity_Editor_Indexer::describe_payload($decoded, $relative_path);
			$entity = self::compact_item($indexed_item);
			foreach (['entity_kind', 'subtype', 'provider', 'object_type', 'title', 'uid', 'source_status'] as $field) {
				if (isset($description[$field]) && (string) $description[$field] !== '') {
					$entity[$field] = (string) $description[$field];
				}
			}
			if (isset($description['source_id']) && $description['source_id'] !== null) {
				$entity['source_id'] = (int) $description['source_id'];
			}

			return [
				'index' => self::index_metadata($cached),
				'entity' => $entity,
				'file' => [
					'relative_path' => $relative_path,
					'filename' => (string) ($loaded['filename'] ?? basename($relative_path)),
					'bytes' => strlen($content),
					'sha256' => 'sha256:' . hash('sha256', $content),
					'mtime' => max(0, (int) ($loaded['mtime'] ?? 0)),
					'mtime_gmt' => (string) ($loaded['mtime_gmt'] ?? ''),
					'top_level_key_count' => count($decoded),
					'meta_key_count' => isset($decoded['meta']) && is_array($decoded['meta']) ? count($decoded['meta']) : 0,
					'taxonomy_count' => self::taxonomy_count($decoded),
				],
			];
		}

		/**
		 * Convert compact rows into scalar WP-CLI table rows.
		 *
		 * @param array $items Compact items.
		 * @return array
		 */
		public static function table_rows(array $items) {
			return array_map(
				static function ($item) {
					return [
						'relative_path' => (string) ($item['relative_path'] ?? ''),
						'entity_kind' => (string) ($item['entity_kind'] ?? ''),
						'subtype' => (string) ($item['subtype'] ?? ''),
						'title' => (string) ($item['title'] ?? ''),
						'match_status' => (string) ($item['match_status'] ?? ''),
						'matched_id' => (int) ($item['matched_id'] ?? 0),
						'duplicate_status' => (string) ($item['duplicate_status'] ?? ''),
						'mtime_gmt' => (string) ($item['mtime_gmt'] ?? ''),
					];
				},
				$items
			);
		}

		/**
		 * @param array $assoc_args Named command arguments.
		 * @return array|WP_Error
		 */
		private static function load_cached_index(array $assoc_args) {
			$payload = get_transient(self::TRANSIENT_KEY);
			$source = 'transient';
			$cache_mtime = 0;

			if (! is_array($payload) || ! isset($payload['items']) || ! is_array($payload['items'])) {
				$payload = null;
				$source = 'disk';
				$sync_real = function_exists('dbvc_get_sync_path') ? realpath(dbvc_get_sync_path()) : false;
				if ($sync_real && is_dir($sync_real)) {
					$cache_path = wp_normalize_path(trailingslashit($sync_real) . self::DISK_CACHE_FILE);
					$real_cache = is_file($cache_path) ? realpath($cache_path) : false;
					$root_prefix = rtrim(wp_normalize_path($sync_real), '/') . '/';
					if ($real_cache && strpos(wp_normalize_path($real_cache), $root_prefix) === 0 && is_readable($real_cache)) {
						$size = filesize($real_cache);
						if (is_int($size) && $size > self::MAX_CACHE_BYTES) {
							return new WP_Error('dbvc_entity_editor_cli_cache_too_large', 'The Entity Editor disk index exceeds the 25 MB inspection limit.');
						}
						$contents = file_get_contents($real_cache);
						$decoded = is_string($contents) ? json_decode($contents, true) : null;
						if (is_array($decoded) && isset($decoded['items']) && is_array($decoded['items'])) {
							$payload = $decoded;
							$cache_mtime = (int) filemtime($real_cache);
						}
					}
				}
			}

			if (! is_array($payload)) {
				return new WP_Error(
					'dbvc_entity_editor_cli_index_missing',
					'No cached Entity Editor index is available. Index rebuild is a separate write boundary and was not performed.'
				);
			}

			$generated_at = (string) ($payload['generated_at'] ?? '');
			$generated_timestamp = $generated_at !== '' ? strtotime($generated_at) : false;
			if ($generated_timestamp === false && $cache_mtime > 0) {
				$generated_timestamp = $cache_mtime;
				$generated_at = gmdate('c', $cache_mtime);
			}
			$age_seconds = $generated_timestamp !== false ? max(0, time() - (int) $generated_timestamp) : null;

			if (isset($assoc_args['max-age'])) {
				$max_age = self::bounded_integer($assoc_args['max-age'], 1, self::MAX_AGE_SECONDS, 'max-age');
				if (is_wp_error($max_age)) {
					return $max_age;
				}
				if ($age_seconds === null || $age_seconds > $max_age) {
					return new WP_Error('dbvc_entity_editor_cli_index_stale', 'The cached Entity Editor index is older than --max-age. Rebuild was not performed.');
				}
			}

			return [
				'payload' => $payload,
				'source' => $source,
				'generated_at' => $generated_at,
				'age_seconds' => $age_seconds,
				'stale' => $age_seconds === null || $age_seconds > self::INDEX_TTL,
			];
		}

		/**
		 * @param array $cached Cached index envelope.
		 * @return array
		 */
		private static function index_metadata(array $cached) {
			$payload = (array) ($cached['payload'] ?? []);
			$stats = isset($payload['stats']) && is_array($payload['stats']) ? $payload['stats'] : [];
			return [
				'source' => (string) ($cached['source'] ?? ''),
				'generated_at' => (string) ($cached['generated_at'] ?? ''),
				'age_seconds' => $cached['age_seconds'],
				'stale' => ! empty($cached['stale']),
				'sync_root' => (string) ($payload['sync_root'] ?? ''),
				'stats' => [
					'scanned_files' => max(0, (int) ($stats['scanned_files'] ?? 0)),
					'indexed_files' => max(0, (int) ($stats['indexed_files'] ?? count((array) ($payload['items'] ?? [])))),
					'excluded_files' => max(0, (int) ($stats['excluded_files'] ?? 0)),
					'duplicate_groups' => max(0, (int) ($stats['duplicate_groups'] ?? 0)),
					'duplicate_files' => max(0, (int) ($stats['duplicate_files'] ?? 0)),
				],
			];
		}

		/**
		 * @param array $assoc_args Named command arguments.
		 * @return array|WP_Error
		 */
		private static function normalize_filters(array $assoc_args) {
			$kind = sanitize_key((string) ($assoc_args['kind'] ?? ''));
			if (! in_array($kind, ['', 'post', 'term', 'third_party'], true)) {
				return new WP_Error('dbvc_entity_editor_cli_kind_invalid', 'Kind must be post, term, or third_party.');
			}
			$match = sanitize_key((string) ($assoc_args['match'] ?? ''));
			if (! in_array($match, ['', 'matched', 'unmatched'], true)) {
				return new WP_Error('dbvc_entity_editor_cli_match_invalid', 'Match must be matched or unmatched.');
			}
			$duplicates = sanitize_key((string) ($assoc_args['duplicates'] ?? 'all'));
			if (! in_array($duplicates, ['all', 'unique', 'canonical', 'stale'], true)) {
				return new WP_Error('dbvc_entity_editor_cli_duplicates_invalid', 'Duplicates must be all, unique, canonical, or stale.');
			}
			$limit = self::bounded_integer($assoc_args['limit'] ?? self::DEFAULT_LIMIT, 1, self::MAX_LIMIT, 'limit');
			if (is_wp_error($limit)) {
				return $limit;
			}
			$offset = self::bounded_integer($assoc_args['offset'] ?? 0, 0, PHP_INT_MAX, 'offset');
			if (is_wp_error($offset)) {
				return $offset;
			}

			return [
				'kind' => $kind,
				'subtype' => sanitize_key((string) ($assoc_args['subtype'] ?? '')),
				'provider' => sanitize_key((string) ($assoc_args['provider'] ?? '')),
				'match' => $match,
				'duplicates' => $duplicates,
				'search' => strtolower(sanitize_text_field((string) ($assoc_args['search'] ?? ''))),
				'limit' => $limit,
				'offset' => $offset,
			];
		}

		/**
		 * @param array $item Indexed item.
		 * @return array
		 */
		private static function compact_item(array $item) {
			$matched_wp_id = isset($item['matched_wp']['id']) ? (int) $item['matched_wp']['id'] : 0;
			$matched_provider_id = isset($item['matched_provider_entity']['id']) ? (int) $item['matched_provider_entity']['id'] : 0;
			$matched_id = $matched_wp_id > 0 ? $matched_wp_id : $matched_provider_id;
			$duplicate_status = 'unique';
			if (! empty($item['is_duplicate'])) {
				$duplicate_status = ! empty($item['is_canonical_duplicate']) ? 'canonical' : 'stale';
			}

			return [
				'relative_path' => self::normalize_relative_path($item['relative_path'] ?? ''),
				'entity_kind' => sanitize_key((string) ($item['entity_kind'] ?? '')),
				'provider' => sanitize_key((string) ($item['provider'] ?? '')),
				'object_type' => sanitize_key((string) ($item['object_type'] ?? '')),
				'subtype' => sanitize_key((string) ($item['subtype'] ?? '')),
				'title' => (string) ($item['title'] ?? ''),
				'slug' => (string) ($item['slug'] ?? ''),
				'uid' => (string) ($item['uid'] ?? ''),
				'source_id' => max(0, (int) ($item['source_id'] ?? $item['payload_entity_id'] ?? 0)),
				'source_status' => sanitize_key((string) ($item['source_status'] ?? '')),
				'match_status' => $matched_id > 0 ? 'matched' : 'unmatched',
				'matched_id' => $matched_id,
				'matched_kind' => $matched_wp_id > 0 ? 'wordpress' : ($matched_provider_id > 0 ? 'provider' : ''),
				'duplicate_status' => $duplicate_status,
				'mtime' => max(0, (int) ($item['mtime'] ?? 0)),
				'mtime_gmt' => (string) ($item['mtime_gmt'] ?? ''),
			];
		}

		/**
		 * @param array $item Compact item.
		 * @param array $filters Normalized filters.
		 * @return bool
		 */
		private static function matches_filters(array $item, array $filters) {
			if ($filters['kind'] !== '' && $item['entity_kind'] !== $filters['kind']) {
				return false;
			}
			if ($filters['subtype'] !== '' && $item['subtype'] !== $filters['subtype']) {
				return false;
			}
			if ($filters['provider'] !== '' && $item['provider'] !== $filters['provider']) {
				return false;
			}
			if ($filters['match'] !== '' && $item['match_status'] !== $filters['match']) {
				return false;
			}
			if ($filters['duplicates'] !== 'all' && $item['duplicate_status'] !== $filters['duplicates']) {
				return false;
			}
			if ($filters['search'] !== '') {
				$haystack = strtolower(implode(' ', [
					$item['relative_path'],
					$item['entity_kind'],
					$item['provider'],
					$item['object_type'],
					$item['subtype'],
					$item['title'],
					$item['slug'],
					$item['uid'],
				]));
				if (strpos($haystack, $filters['search']) === false) {
					return false;
				}
			}
			return true;
		}

		/**
		 * @param array $assoc_args Named command arguments.
		 * @return true|WP_Error
		 */
		private static function guard_read_only_arguments(array $assoc_args) {
			foreach (['rebuild', 'refresh', 'download', 'raw', 'content', 'save', 'import', 'delete', 'merge', 'apply', 'force-takeover'] as $flag) {
				if (array_key_exists($flag, $assoc_args)) {
					return new WP_Error('dbvc_entity_editor_cli_read_only', 'The Entity Editor inspection CLI is read-only and rejects --' . $flag . '.');
				}
			}
			return true;
		}

		/**
		 * @param mixed  $value Raw integer.
		 * @param int    $minimum Minimum.
		 * @param int    $maximum Maximum.
		 * @param string $label Argument label.
		 * @return int|WP_Error
		 */
		private static function bounded_integer($value, $minimum, $maximum, $label) {
			if (filter_var($value, FILTER_VALIDATE_INT) === false) {
				return new WP_Error('dbvc_entity_editor_cli_integer_invalid', '--' . $label . ' must be an integer.');
			}
			$value = (int) $value;
			if ($value < $minimum || $value > $maximum) {
				return new WP_Error('dbvc_entity_editor_cli_integer_range', sprintf('--%s must be between %d and %d.', $label, $minimum, $maximum));
			}
			return $value;
		}

		/**
		 * @param mixed $relative_path Raw path.
		 * @return string
		 */
		private static function normalize_relative_path($relative_path) {
			$path = str_replace('\\', '/', ltrim(trim((string) $relative_path), '/'));
			return strpos($path, '..') === false && substr($path, -5) === '.json' ? $path : '';
		}

		/**
		 * @param array $decoded Entity payload.
		 * @return int
		 */
		private static function taxonomy_count(array $decoded) {
			foreach (['tax_input', 'taxonomies', 'terms'] as $key) {
				if (isset($decoded[$key]) && is_array($decoded[$key])) {
					return count($decoded[$key]);
				}
			}
			return 0;
		}
	}
}

if (defined('WP_CLI') && WP_CLI && ! class_exists('DBVC_WP_CLI_Entity_Editor')) {
	/**
	 * Inspect the cached DBVC Entity Editor landscape without mutations or downloads.
	 */
	class DBVC_WP_CLI_Entity_Editor extends WP_CLI_Command {
		/**
		 * List cached Entity Editor metadata.
		 *
		 * ## OPTIONS
		 *
		 * [--kind=<kind>]
		 * : Filter by post, term, or third_party.
		 *
		 * [--subtype=<subtype>]
		 * : Filter by exact post type, taxonomy, or provider subtype.
		 *
		 * [--provider=<provider>]
		 * : Filter by exact third-party provider.
		 *
		 * [--match=<state>]
		 * : Filter by matched or unmatched.
		 *
		 * [--duplicates=<state>]
		 * : Filter by all, unique, canonical, or stale. Default: all.
		 *
		 * [--search=<text>]
		 * : Search paths and indexed identity metadata.
		 *
		 * [--max-age=<seconds>]
		 * : Stop if the cached index is older than this many seconds.
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
		 * [--fail-if-empty]
		 * : Return exit code 1 when no rows match.
		 *
		 * ## EXAMPLES
		 *
		 * wp dbvc entity-editor list --kind=post --match=unmatched --limit=25
		 * wp dbvc entity-editor list --duplicates=stale --max-age=900 --format=json
		 *
		 * @subcommand list
		 *
		 * @param array $args Positional arguments.
		 * @param array $assoc_args Named arguments.
		 * @return void
		 */
		public function list_($args, $assoc_args) {
			unset($args);
			$result = DBVC_Entity_Editor_CLI_Inspector::list_entities($assoc_args);
			$this->render_result($result, $assoc_args, false);
			if (! empty($assoc_args['fail-if-empty']) && empty($result['total_matching'])) {
				WP_CLI::halt(1);
			}
		}

		/**
		 * Inspect one indexed file without returning raw JSON values.
		 *
		 * ## OPTIONS
		 *
		 * <relative-path>
		 * : Exact sync-relative path from the cached Entity Editor index.
		 *
		 * [--max-age=<seconds>]
		 * : Stop if the cached index is older than this many seconds.
		 *
		 * [--fields=<fields>]
		 * : Comma-separated fields for table output.
		 *
		 * [--format=<format>]
		 * : table or json. Default: table.
		 *
		 * ## EXAMPLES
		 *
		 * wp dbvc entity-editor inspect page/page-about-42.json --format=json
		 *
		 * @param array $args Positional arguments.
		 * @param array $assoc_args Named arguments.
		 * @return void
		 */
		public function inspect($args, $assoc_args) {
			$relative_path = isset($args[0]) ? (string) $args[0] : '';
			$result = DBVC_Entity_Editor_CLI_Inspector::inspect_entity($relative_path, $assoc_args);
			$this->render_result($result, $assoc_args, true);
		}

		/**
		 * @param array|WP_Error $result Inspection result.
		 * @param array          $assoc_args Named arguments.
		 * @param bool           $single Whether this is one-file inspection.
		 * @return void
		 */
		private function render_result($result, array $assoc_args, $single) {
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

			if ($single) {
				$entity = (array) ($result['entity'] ?? []);
				$file = (array) ($result['file'] ?? []);
				$rows = [[
					'relative_path' => (string) ($file['relative_path'] ?? ''),
					'entity_kind' => (string) ($entity['entity_kind'] ?? ''),
					'subtype' => (string) ($entity['subtype'] ?? ''),
					'title' => (string) ($entity['title'] ?? ''),
					'match_status' => (string) ($entity['match_status'] ?? ''),
					'matched_id' => (int) ($entity['matched_id'] ?? 0),
					'bytes' => (int) ($file['bytes'] ?? 0),
					'sha256' => (string) ($file['sha256'] ?? ''),
					'meta_key_count' => (int) ($file['meta_key_count'] ?? 0),
					'taxonomy_count' => (int) ($file['taxonomy_count'] ?? 0),
				]];
				$fields = ['relative_path', 'entity_kind', 'subtype', 'title', 'match_status', 'matched_id', 'bytes', 'sha256', 'meta_key_count', 'taxonomy_count'];
			} else {
				$rows = DBVC_Entity_Editor_CLI_Inspector::table_rows((array) ($result['items'] ?? []));
				$fields = ['relative_path', 'entity_kind', 'subtype', 'title', 'match_status', 'matched_id', 'duplicate_status', 'mtime_gmt'];
			}
			if (! empty($assoc_args['fields'])) {
				$fields = array_values(array_filter(array_map('trim', explode(',', (string) $assoc_args['fields']))));
			}
			\WP_CLI\Utils\format_items('table', $rows, $fields);
			$index = (array) ($result['index'] ?? []);
			WP_CLI::log(sprintf(
				'Cached index source: %s; age: %s second(s); stale: %s.',
				(string) ($index['source'] ?? 'unknown'),
				$index['age_seconds'] === null ? 'unknown' : (string) $index['age_seconds'],
				! empty($index['stale']) ? 'yes' : 'no'
			));
		}
	}

	WP_CLI::add_command('dbvc entity-editor', 'DBVC_WP_CLI_Entity_Editor');
}
