<?php

if (! defined('WPINC')) {
	die;
}

if (! class_exists('DBVC_Proposal_CLI_Inspector')) {
	/**
	 * Read bounded proposal summaries without invoking REST callbacks or storage helpers that create files.
	 */
	final class DBVC_Proposal_CLI_Inspector {
		private const DEFAULT_LIMIT = 25;
		private const MAX_LIMIT = 100;
		private const MAX_MANIFEST_BYTES = 20971520;
		private const MAX_MANIFEST_ITEMS = 20000;
		private const DECISIONS_OPTION = 'dbvc_proposal_decisions';
		private const RESOLVER_DECISIONS_OPTION = 'dbvc_resolver_decisions';
		private const SNAPSHOT_STATES_OPTION = 'dbvc_proposal_snapshot_states';

		/**
		 * Return one exact proposal's bounded structural and readiness summary.
		 *
		 * @param string $proposal_id Exact proposal ID.
		 * @param array  $assoc_args Named arguments.
		 * @return array|WP_Error
		 */
		public static function show_proposal($proposal_id, array $assoc_args = []) {
			$guard = self::guard_arguments($assoc_args, ['format', 'fields', 'fail-on-blockers']);
			if (is_wp_error($guard)) {
				return $guard;
			}

			$context = self::load_proposal($proposal_id);
			if (is_wp_error($context)) {
				return $context;
			}

			$manifest = $context['manifest'];
			$items = self::manifest_items($manifest);
			$duplicates = self::duplicate_summary($items);
			$decisions = self::proposal_decision_summary($context['proposal_id']);
			$resolver = self::resolver_summary($context['proposal_id'], $manifest);
			$snapshots = self::snapshot_summary($context['proposal_id'], $items);
			$totals = isset($manifest['totals']) && is_array($manifest['totals']) ? $manifest['totals'] : [];
			$missing_hashes = max(0, (int) ($totals['missing_import_hash'] ?? 0));
			$known_blockers = [];
			if ($missing_hashes > 0) {
				$known_blockers['hashes'] = $missing_hashes;
			}
			if ($duplicates['groups'] > 0) {
				$known_blockers['duplicates'] = $duplicates['groups'];
			}

			return [
				'proposal' => [
					'id' => $context['proposal_id'],
					'schema' => self::safe_scalar($manifest['schema'] ?? '', 80),
					'status' => sanitize_key((string) ($manifest['status'] ?? 'unknown')),
					'generated_at' => self::safe_scalar($manifest['generated_at'] ?? '', 80),
				],
				'manifest' => [
					'item_count' => count($items),
					'type_counts' => self::type_counts($items),
					'file_size' => $context['manifest_size'],
					'sha256' => $context['manifest_sha256'],
					'modified_at' => $context['manifest_modified_at'],
					'declared_checksum' => self::safe_hash($manifest['checksum'] ?? ''),
				],
				'counts' => [
					'files' => max(0, (int) ($totals['files'] ?? count($items))),
					'media_items' => max(0, (int) ($totals['media_items'] ?? count((array) ($manifest['media_index'] ?? [])))),
					'missing_import_hash' => $missing_hashes,
				],
				'duplicates' => $duplicates,
				'decisions' => $decisions,
				'resolver' => $resolver,
				'snapshots' => $snapshots,
				'readiness' => [
					'mode' => 'bounded_read_only_preflight',
					'state' => $known_blockers === [] ? 'authoritative_review_required' : 'known_blockers',
					'authoritative_apply_ready' => null,
					'known_blocker_count' => array_sum($known_blockers),
					'known_blockers' => $known_blockers,
					'not_evaluated' => [
						'field_decision_completeness',
						'masking_values',
						'live_resolver_matching',
						'snapshot_trust_or_staleness',
						'new_entity_identity',
						'apply_permissions',
					],
				],
			];
		}

		/**
		 * Return a bounded page of sanitized entity summaries for one exact proposal.
		 *
		 * @param string $proposal_id Exact proposal ID.
		 * @param array  $assoc_args Named arguments.
		 * @return array|WP_Error
		 */
		public static function list_entities($proposal_id, array $assoc_args = []) {
			$guard = self::guard_arguments($assoc_args, ['entity-type', 'object-type', 'snapshot-state', 'limit', 'offset', 'format', 'fields']);
			if (is_wp_error($guard)) {
				return $guard;
			}

			$context = self::load_proposal($proposal_id);
			if (is_wp_error($context)) {
				return $context;
			}

			$entity_type_filter = sanitize_key((string) ($assoc_args['entity-type'] ?? ''));
			$object_type_filter = sanitize_key((string) ($assoc_args['object-type'] ?? ''));
			$snapshot_filter = sanitize_key((string) ($assoc_args['snapshot-state'] ?? ''));
			$decision_store = self::proposal_decisions($context['proposal_id']);
			$items = self::manifest_items($context['manifest']);
			$duplicate_index = self::duplicate_index($items);
			$offset = self::bounded_integer($assoc_args['offset'] ?? 0, 0, PHP_INT_MAX);
			$limit = self::bounded_integer($assoc_args['limit'] ?? self::DEFAULT_LIMIT, 1, self::MAX_LIMIT);
			$rows = [];
			$total = 0;

			foreach ($items as $index => $item) {
				if (! is_array($item)) {
					continue;
				}
				$entity_type = sanitize_key((string) ($item['item_type'] ?? 'post'));
				$object_type = self::object_type($item, $entity_type);
				$uid = self::entity_uid($item);
				$snapshot_state = $uid !== '' && in_array($entity_type, ['post', 'term'], true)
					? self::snapshot_artifact_state($context['proposal_id'], $uid, false)['state']
					: 'not_applicable';
				if ($entity_type_filter !== '' && $entity_type !== $entity_type_filter) {
					continue;
				}
				if ($object_type_filter !== '' && $object_type !== $object_type_filter) {
					continue;
				}
				if ($snapshot_filter !== '' && $snapshot_state !== $snapshot_filter) {
					continue;
				}
				if ($total >= $offset && count($rows) < $limit) {
					$rows[] = self::entity_row($context['proposal_id'], $item, $decision_store, $duplicate_index[$index] ?? null);
				}
				++$total;
			}

			return [
				'proposal_id' => $context['proposal_id'],
				'filters' => [
					'entity_type' => $entity_type_filter,
					'object_type' => $object_type_filter,
					'snapshot_state' => $snapshot_filter,
				],
				'total_matching' => $total,
				'returned' => count($rows),
				'offset' => $offset,
				'limit' => $limit,
				'has_more' => $offset + count($rows) < $total,
				'items' => $rows,
			];
		}

		/**
		 * Flatten proposal summary for table output.
		 *
		 * @param array $result Proposal summary.
		 * @return array
		 */
		public static function show_table_row(array $result) {
			return [
				'id' => (string) ($result['proposal']['id'] ?? ''),
				'status' => (string) ($result['proposal']['status'] ?? ''),
				'items' => (int) ($result['manifest']['item_count'] ?? 0),
				'media' => (int) ($result['counts']['media_items'] ?? 0),
				'missing_hashes' => (int) ($result['counts']['missing_import_hash'] ?? 0),
				'duplicate_groups' => (int) ($result['duplicates']['groups'] ?? 0),
				'decisions' => (int) ($result['decisions']['total'] ?? 0),
				'snapshot_files' => (int) ($result['snapshots']['present'] ?? 0),
				'readiness' => (string) ($result['readiness']['state'] ?? ''),
			];
		}

		/**
		 * @param array $assoc_args Named arguments.
		 * @param array $allowed Allowed argument keys.
		 * @return true|WP_Error
		 */
		private static function guard_arguments(array $assoc_args, array $allowed) {
			foreach (array_keys($assoc_args) as $argument) {
				if (! in_array((string) $argument, $allowed, true)) {
					return new WP_Error('dbvc_proposal_cli_read_only', 'The proposal inspector is read-only and rejects --' . sanitize_key((string) $argument) . '.');
				}
			}
			$format = sanitize_key((string) ($assoc_args['format'] ?? 'table'));
			if (! in_array($format, ['table', 'json'], true)) {
				return new WP_Error('dbvc_proposal_cli_format_invalid', 'Format must be table or json.');
			}
			return true;
		}

		/**
		 * Resolve and read one existing proposal without creating its storage root.
		 *
		 * @param string $proposal_id Exact proposal ID.
		 * @return array|WP_Error
		 */
		private static function load_proposal($proposal_id) {
			$proposal_id = trim((string) $proposal_id);
			if ($proposal_id === '' || strlen($proposal_id) > 190 || ! preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $proposal_id)) {
				return new WP_Error('dbvc_proposal_cli_id_invalid', 'Provide one exact proposal ID containing only letters, numbers, periods, underscores, or hyphens.');
			}

			$uploads = wp_get_upload_dir();
			$upload_base = isset($uploads['basedir']) ? (string) $uploads['basedir'] : '';
			$base = $upload_base !== '' ? trailingslashit($upload_base) . 'sync/db-version-control-backups' : '';
			$base_real = $base !== '' ? realpath($base) : false;
			if (! is_string($base_real) || ! is_dir($base_real)) {
				return new WP_Error('dbvc_proposal_cli_storage_missing', 'The existing proposal storage directory could not be found. No directory was created.');
			}

			$proposal_dir = realpath(trailingslashit($base_real) . $proposal_id);
			if (! is_string($proposal_dir) || ! is_dir($proposal_dir) || ! self::path_within($proposal_dir, $base_real)) {
				return new WP_Error('dbvc_proposal_cli_missing', 'The requested proposal directory could not be found.');
			}

			$manifest_path = trailingslashit($proposal_dir) . 'manifest.json';
			$manifest_real = realpath($manifest_path);
			if (! is_string($manifest_real) || ! is_file($manifest_real) || ! is_readable($manifest_real) || ! self::path_within($manifest_real, $proposal_dir)) {
				return new WP_Error('dbvc_proposal_cli_manifest_missing', 'The proposal manifest could not be found or read.');
			}

			$size = filesize($manifest_real);
			if (! is_int($size) || $size > self::MAX_MANIFEST_BYTES) {
				return new WP_Error('dbvc_proposal_cli_manifest_size', 'The proposal manifest exceeds the 20 MB inspection limit.');
			}
			$raw = file_get_contents($manifest_real);
			$manifest = is_string($raw) ? json_decode($raw, true) : null;
			if (! is_array($manifest)) {
				return new WP_Error('dbvc_proposal_cli_manifest_invalid', 'The proposal manifest is not valid JSON.');
			}
			$items = isset($manifest['items']) && is_array($manifest['items']) ? $manifest['items'] : [];
			if (count($items) > self::MAX_MANIFEST_ITEMS) {
				return new WP_Error('dbvc_proposal_cli_item_limit', 'The proposal exceeds the 20,000-item inspection limit.');
			}

			$modified = filemtime($manifest_real);
			return [
				'proposal_id' => $proposal_id,
				'manifest' => $manifest,
				'manifest_size' => $size,
				'manifest_sha256' => hash_file('sha256', $manifest_real),
				'manifest_modified_at' => $modified ? gmdate('c', $modified) : '',
			];
		}

		/**
		 * @param array $manifest Manifest.
		 * @return array
		 */
		private static function manifest_items(array $manifest) {
			return isset($manifest['items']) && is_array($manifest['items']) ? array_values($manifest['items']) : [];
		}

		/**
		 * @param array $items Manifest items.
		 * @return array
		 */
		private static function type_counts(array $items) {
			$counts = [];
			foreach ($items as $item) {
				$type = is_array($item) ? sanitize_key((string) ($item['item_type'] ?? 'post')) : 'unknown';
				$type = $type !== '' ? $type : 'unknown';
				$counts[$type] = (int) ($counts[$type] ?? 0) + 1;
			}
			ksort($counts);
			return $counts;
		}

		/**
		 * @param array $items Manifest items.
		 * @return array
		 */
		private static function duplicate_summary(array $items) {
			$index = self::duplicate_index($items);
			$groups = [];
			foreach ($index as $entry) {
				if (is_array($entry) && ! empty($entry['group_id'])) {
					$groups[$entry['group_id']] = (int) $entry['group_size'];
				}
			}
			return [
				'groups' => count($groups),
				'items' => array_sum($groups),
				'method' => 'manifest_identity_summary',
			];
		}

		/**
		 * @param array $items Manifest items.
		 * @return array<int,array{group_id:string,group_size:int}>
		 */
		private static function duplicate_index(array $items) {
			$keys = [];
			foreach ($items as $index => $item) {
				$key = is_array($item) ? self::identity_key($item) : '';
				if ($key !== '') {
					$keys[$key][] = $index;
				}
			}
			$result = [];
			foreach ($keys as $key => $indexes) {
				if (count($indexes) < 2) {
					continue;
				}
				$entry = ['group_id' => substr(hash('sha256', $key), 0, 12), 'group_size' => count($indexes)];
				foreach ($indexes as $index) {
					$result[$index] = $entry;
				}
			}
			return $result;
		}

		/**
		 * @param array $item Manifest item.
		 * @return string
		 */
		private static function identity_key(array $item) {
			$type = sanitize_key((string) ($item['item_type'] ?? 'post'));
			$uid = self::entity_uid($item);
			if ($uid !== '') {
				return $type . '|uid|' . $uid;
			}
			if ($type === 'post') {
				$post_type = sanitize_key((string) ($item['post_type'] ?? ''));
				$slug = sanitize_title((string) ($item['post_name'] ?? ''));
				return $post_type !== '' && $slug !== '' ? 'post|' . $post_type . '|slug|' . $slug : '';
			}
			if ($type === 'term') {
				$taxonomy = sanitize_key((string) ($item['term_taxonomy'] ?? ($item['taxonomy'] ?? '')));
				$slug = sanitize_title((string) ($item['term_slug'] ?? ($item['slug'] ?? '')));
				return $taxonomy !== '' && $slug !== '' ? 'term|' . $taxonomy . '|slug|' . $slug : '';
			}
			if ($type === 'third_party') {
				$provider = sanitize_key((string) ($item['provider'] ?? ($item['third_party_provider'] ?? '')));
				$object = sanitize_key((string) ($item['object_type'] ?? ($item['third_party_object_type'] ?? '')));
				$external_uid = self::safe_scalar($item['uid'] ?? ($item['third_party_uid'] ?? ''), 190);
				return $provider !== '' && $object !== '' && $external_uid !== '' ? 'third_party|' . $provider . '|' . $object . '|' . $external_uid : '';
			}
			return '';
		}

		/**
		 * @param string $proposal_id Proposal ID.
		 * @return array
		 */
		private static function proposal_decisions($proposal_id) {
			$store = get_option(self::DECISIONS_OPTION, []);
			return is_array($store) && isset($store[$proposal_id]) && is_array($store[$proposal_id]) ? $store[$proposal_id] : [];
		}

		/**
		 * @param string $proposal_id Proposal ID.
		 * @return array
		 */
		private static function proposal_decision_summary($proposal_id) {
			$decisions = self::proposal_decisions($proposal_id);
			$summary = ['accepted' => 0, 'kept' => 0, 'accepted_new' => 0, 'declined_new' => 0, 'total' => 0, 'entities_reviewed' => 0];
			foreach ($decisions as $entity_id => $entity_decisions) {
				if (! is_array($entity_decisions) || strpos((string) $entity_id, '__') === 0) {
					continue;
				}
				++$summary['entities_reviewed'];
				$counts = self::decision_counts($entity_decisions);
				foreach (['accepted', 'kept', 'accepted_new', 'declined_new', 'total'] as $key) {
					$summary[$key] += $counts[$key];
				}
			}
			return $summary;
		}

		/**
		 * @param array $decisions Entity decisions.
		 * @return array
		 */
		private static function decision_counts(array $decisions) {
			$counts = ['accepted' => 0, 'kept' => 0, 'accepted_new' => 0, 'declined_new' => 0, 'total' => 0];
			foreach ($decisions as $action) {
				$action = sanitize_key((string) $action);
				$key = ['accept' => 'accepted', 'keep' => 'kept', 'accept_new' => 'accepted_new', 'decline_new' => 'declined_new'][$action] ?? '';
				if ($key !== '') {
					++$counts[$key];
					++$counts['total'];
				}
			}
			return $counts;
		}

		/**
		 * @param string $proposal_id Proposal ID.
		 * @param array  $manifest Manifest.
		 * @return array
		 */
		private static function resolver_summary($proposal_id, array $manifest) {
			$store = get_option(self::RESOLVER_DECISIONS_OPTION, []);
			$proposal = is_array($store) && isset($store[$proposal_id]) && is_array($store[$proposal_id]) ? $store[$proposal_id] : [];
			$global = is_array($store) && isset($store['__global']) && is_array($store['__global']) ? $store['__global'] : [];
			$media = isset($manifest['media_index']) && is_array($manifest['media_index']) ? $manifest['media_index'] : [];
			$media_ids = [];
			foreach ($media as $entry) {
				if (is_array($entry) && isset($entry['original_id']) && (int) $entry['original_id'] > 0) {
					$media_ids[(string) (int) $entry['original_id']] = true;
				}
			}
			$actions = ['skip' => 0, 'download' => 0, 'map' => 0, 'reuse' => 0, 'other' => 0];
			$proposal_count = 0;
			$global_fallback_count = 0;
			foreach (array_keys($media_ids) as $original_id) {
				$decision = isset($proposal[$original_id]) && is_array($proposal[$original_id])
					? $proposal[$original_id]
					: (isset($global[$original_id]) && is_array($global[$original_id]) ? $global[$original_id] : null);
				if (! is_array($decision)) {
					continue;
				}
				isset($proposal[$original_id]) ? ++$proposal_count : ++$global_fallback_count;
				$action = sanitize_key((string) ($decision['action'] ?? ''));
				$actions[array_key_exists($action, $actions) ? $action : 'other']++;
			}
			$declared = max(count($media), (int) ($manifest['totals']['media_items'] ?? 0));
			$covered = $proposal_count + $global_fallback_count;
			return [
				'mode' => 'stored_decisions_only',
				'declared_media_items' => $declared,
				'media_items_with_original_id' => count($media_ids),
				'decision_coverage' => $covered,
				'without_stored_decision' => max(0, count($media_ids) - $covered),
				'proposal_decisions' => $proposal_count,
				'global_fallback_decisions' => $global_fallback_count,
				'actions' => $actions,
				'live_resolution_evaluated' => false,
			];
		}

		/**
		 * @param string $proposal_id Proposal ID.
		 * @param array  $items Manifest items.
		 * @return array
		 */
		private static function snapshot_summary($proposal_id, array $items) {
			$summary = ['eligible' => 0, 'present' => 0, 'missing' => 0, 'stored_failed' => 0, 'stored_recapturing' => 0, 'trust_evaluated' => false];
			$seen = [];
			foreach ($items as $item) {
				if (! is_array($item) || ! in_array(sanitize_key((string) ($item['item_type'] ?? 'post')), ['post', 'term'], true)) {
					continue;
				}
				$uid = self::entity_uid($item);
				if ($uid === '' || isset($seen[$uid])) {
					continue;
				}
				$seen[$uid] = true;
				++$summary['eligible'];
				$state = self::snapshot_artifact_state($proposal_id, $uid, false);
				$state['exists'] ? ++$summary['present'] : ++$summary['missing'];
				if ($state['stored_state'] === 'failed') {
					++$summary['stored_failed'];
				} elseif ($state['stored_state'] === 'recapturing') {
					++$summary['stored_recapturing'];
				}
			}
			return $summary;
		}

		/**
		 * @param string $proposal_id Proposal ID.
		 * @param string $uid Entity UID.
		 * @return array
		 */
		private static function snapshot_artifact_state($proposal_id, $uid, $include_hash = true) {
			$stored = get_option(self::SNAPSHOT_STATES_OPTION, []);
			$stored = is_array($stored) ? $stored : [];
			$stored_state = is_array($stored) && isset($stored[$proposal_id][$uid]['state'])
				? sanitize_key((string) $stored[$proposal_id][$uid]['state'])
				: '';
			$uploads = wp_get_upload_dir();
			$base = ! empty($uploads['basedir']) ? trailingslashit((string) $uploads['basedir']) . 'sync/db-version-control-snapshots' : '';
			$base_real = $base !== '' ? realpath($base) : false;
			$proposal_dir = is_string($base_real) ? realpath(trailingslashit($base_real) . sanitize_file_name($proposal_id)) : false;
			$file = is_string($proposal_dir) ? realpath(trailingslashit($proposal_dir) . sanitize_file_name($uid) . '.json') : false;
			$exists = is_string($base_real)
				&& is_string($proposal_dir)
				&& self::path_within($proposal_dir, $base_real)
				&& is_string($file)
				&& self::path_within($file, $proposal_dir)
				&& is_file($file)
				&& is_readable($file);
			return [
				'exists' => $exists,
				'state' => $exists ? 'present' : 'missing',
				'stored_state' => $stored_state,
				'sha256' => $exists && $include_hash ? hash_file('sha256', $file) : '',
			];
		}

		/**
		 * @param string $proposal_id Proposal ID.
		 * @param array  $item Manifest item.
		 * @param array  $decision_store Proposal decision store.
		 * @param array|null $duplicate Duplicate group metadata.
		 * @return array
		 */
		private static function entity_row($proposal_id, array $item, array $decision_store, $duplicate) {
			$entity_type = sanitize_key((string) ($item['item_type'] ?? 'post'));
			$uid = self::entity_uid($item);
			$source_id = 0;
			$object_type = self::object_type($item, $entity_type);
			if ($entity_type === 'term') {
				$source_id = absint($item['term_id'] ?? 0);
			} elseif ($entity_type === 'post') {
				$source_id = absint($item['post_id'] ?? 0);
			}
			$decisions = $uid !== '' && isset($decision_store[$uid]) && is_array($decision_store[$uid]) ? $decision_store[$uid] : [];
			$snapshot = $uid !== '' && in_array($entity_type, ['post', 'term'], true)
				? self::snapshot_artifact_state($proposal_id, $uid)
				: ['exists' => false, 'state' => 'not_applicable', 'stored_state' => '', 'sha256' => ''];

			return [
				'entity_uid' => self::safe_scalar($uid, 190),
				'entity_type' => $entity_type !== '' ? $entity_type : 'unknown',
				'object_type' => $object_type,
				'source_id' => $source_id,
				'manifest_hash' => self::safe_hash($item['hash'] ?? ''),
				'content_hash' => self::safe_hash($item['content_hash'] ?? ''),
				'has_import_hash' => ! empty($item['has_import_hash']),
				'media_reference_count' => self::media_reference_count($item),
				'decisions' => self::decision_counts($decisions),
				'snapshot_state' => $snapshot['state'],
				'snapshot_stored_state' => $snapshot['stored_state'],
				'snapshot_sha256' => $snapshot['sha256'],
				'duplicate_group_id' => is_array($duplicate) ? (string) $duplicate['group_id'] : '',
				'duplicate_group_size' => is_array($duplicate) ? (int) $duplicate['group_size'] : 0,
			];
		}

		/**
		 * @param array $item Manifest item.
		 * @return string
		 */
		private static function entity_uid(array $item) {
			foreach (['vf_object_uid', 'entity_uid', 'uid', 'third_party_uid'] as $key) {
				$value = self::safe_scalar($item[$key] ?? '', 190);
				if ($value !== '') {
					return $value;
				}
			}
			return '';
		}

		/**
		 * @param array  $item Manifest item.
		 * @param string $entity_type Normalized entity type.
		 * @return string
		 */
		private static function object_type(array $item, $entity_type) {
			if ($entity_type === 'term') {
				return sanitize_key((string) ($item['term_taxonomy'] ?? ($item['taxonomy'] ?? '')));
			}
			if ($entity_type === 'post') {
				return sanitize_key((string) ($item['post_type'] ?? ''));
			}
			return sanitize_key((string) ($item['object_type'] ?? ($item['third_party_object_type'] ?? $entity_type)));
		}

		/**
		 * @param array $item Manifest item.
		 * @return int
		 */
		private static function media_reference_count(array $item) {
			$refs = isset($item['media_refs']) && is_array($item['media_refs']) ? $item['media_refs'] : [];
			$ids = [];
			$iterator = function ($value) use (&$iterator, &$ids) {
				if (! is_array($value)) {
					return;
				}
				if (isset($value['original_id']) && (int) $value['original_id'] > 0) {
					$ids[(int) $value['original_id']] = true;
				}
				foreach ($value as $nested) {
					if (is_array($nested)) {
						$iterator($nested);
					}
				}
			};
			$iterator($refs);
			return count($ids);
		}

		/**
		 * @param mixed $value Scalar value.
		 * @param int   $limit Maximum bytes.
		 * @return string
		 */
		private static function safe_scalar($value, $limit) {
			$value = is_scalar($value) ? sanitize_text_field((string) $value) : '';
			return substr($value, 0, $limit);
		}

		/**
		 * @param mixed $value Hash value.
		 * @return string
		 */
		private static function safe_hash($value) {
			$value = self::safe_scalar($value, 160);
			return preg_match('/^(?:[a-z0-9_-]+:)?[a-f0-9]{16,128}$/i', $value) ? $value : '';
		}

		/**
		 * @param string $path Candidate path.
		 * @param string $base_dir Base path.
		 * @return bool
		 */
		private static function path_within($path, $base_dir) {
			$path = trailingslashit(wp_normalize_path((string) $path));
			$base_dir = trailingslashit(wp_normalize_path((string) $base_dir));
			return $path !== '' && strpos($path, $base_dir) === 0;
		}

		/**
		 * @param mixed $value Value.
		 * @param int   $minimum Minimum.
		 * @param int   $maximum Maximum.
		 * @return int
		 */
		private static function bounded_integer($value, $minimum, $maximum) {
			return min($maximum, max($minimum, (int) $value));
		}
	}
}

if (defined('WP_CLI') && WP_CLI && ! class_exists('DBVC_WP_CLI_Proposal_Inspector')) {
	/**
	 * Bounded read-only proposal summary commands.
	 */
	final class DBVC_WP_CLI_Proposal_Inspector {
		/**
		 * Show one exact proposal's bounded structural preflight.
		 *
		 * ## OPTIONS
		 *
		 * <proposal-id>
		 * : Exact proposal directory ID.
		 *
		 * [--fields=<fields>]
		 * : Comma-separated table fields.
		 *
		 * [--format=<format>]
		 * : table or json. Default: table.
		 *
		 * [--fail-on-blockers]
		 * : Return exit code 1 when the bounded preflight finds known blockers.
		 *
		 * ## EXAMPLES
		 *
		 * wp dbvc proposals show <proposal-id> --format=json
		 *
		 * @param array $args Positional arguments.
		 * @param array $assoc_args Named arguments.
		 * @return void
		 */
		public static function show($args, $assoc_args) {
			$proposal_id = isset($args[0]) ? (string) $args[0] : '';
			$result = DBVC_Proposal_CLI_Inspector::show_proposal($proposal_id, $assoc_args);
			if (is_wp_error($result)) {
				WP_CLI::error($result->get_error_message());
			}
			$format = sanitize_key((string) ($assoc_args['format'] ?? 'table'));
			if ($format === 'json') {
				WP_CLI::line(wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
			} else {
				$row = DBVC_Proposal_CLI_Inspector::show_table_row($result);
				$fields = ! empty($assoc_args['fields'])
					? array_values(array_filter(array_map('trim', explode(',', (string) $assoc_args['fields']))))
					: array_keys($row);
				\WP_CLI\Utils\format_items('table', [$row], $fields);
			}
			if (\WP_CLI\Utils\get_flag_value($assoc_args, 'fail-on-blockers', false) && (int) $result['readiness']['known_blocker_count'] > 0) {
				WP_CLI::halt(1);
			}
		}

		/**
		 * List bounded sanitized entities for one exact proposal.
		 *
		 * ## OPTIONS
		 *
		 * <proposal-id>
		 * : Exact proposal directory ID.
		 *
		 * [--entity-type=<type>]
		 * : Restrict to one manifest item type.
		 *
		 * [--object-type=<type>]
		 * : Restrict to one post type, taxonomy, or provider object type.
		 *
		 * [--snapshot-state=<state>]
		 * : Restrict to present, missing, or not_applicable.
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
		 * wp dbvc proposals entities <proposal-id> --limit=25 --format=json
		 *
		 * @param array $args Positional arguments.
		 * @param array $assoc_args Named arguments.
		 * @return void
		 */
		public static function entities($args, $assoc_args) {
			$proposal_id = isset($args[0]) ? (string) $args[0] : '';
			$result = DBVC_Proposal_CLI_Inspector::list_entities($proposal_id, $assoc_args);
			if (is_wp_error($result)) {
				WP_CLI::error($result->get_error_message());
			}
			$format = sanitize_key((string) ($assoc_args['format'] ?? 'table'));
			if ($format === 'json') {
				WP_CLI::line(wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
				return;
			}
			$fields = ['entity_uid', 'entity_type', 'object_type', 'source_id', 'has_import_hash', 'media_reference_count', 'snapshot_state', 'duplicate_group_size'];
			if (! empty($assoc_args['fields'])) {
				$fields = array_values(array_filter(array_map('trim', explode(',', (string) $assoc_args['fields']))));
			}
			\WP_CLI\Utils\format_items('table', (array) $result['items'], $fields);
			WP_CLI::log(sprintf('Returned %d of %d matching proposal entities.', (int) $result['returned'], (int) $result['total_matching']));
		}
	}

}
