<?php

/**
 * Taxonomy synchronization utilities for DB Version Control.
 *
 * @package DB Version Control
 * @since   1.3.0
 */

if (! defined('ABSPATH')) {
	exit;
}

class DBVC_Sync_Taxonomies
{
	/**
	 * Determine the base sync path for a given taxonomy.
	 *
	 * @since 1.3.0
	 */
	public static function get_taxonomy_sync_path($taxonomy)
	{
		$base = trailingslashit(dbvc_get_sync_path('taxonomy'));
		return trailingslashit($base . sanitize_key($taxonomy));
	}

	/**
	 * Ensure taxonomy directory is shielded.
	 *
	 * @since 1.3.0
	 */
	
	public static function delete_term_json_for_entity($term_id, $taxonomy = ''): void
	{
		$term_id = absint($term_id);
		if (! $term_id) {
			return;
		}

		if ($taxonomy === '') {
			$term = get_term($term_id);
			if (! $term || is_wp_error($term)) {
				return;
			}
			$taxonomy = $term->taxonomy;
		}

		$taxonomy = sanitize_key($taxonomy);
		if ($taxonomy === '') {
			return;
		}

		$folder = self::get_taxonomy_sync_path($taxonomy);
		if (! is_dir($folder)) {
			return;
		}

		$term = get_term($term_id, $taxonomy);
		$slug = ($term && ! is_wp_error($term)) ? sanitize_title($term->slug) : '';
		$uuid = get_term_meta($term_id, 'uuid', true);
		$uuid = is_string($uuid) ? trim($uuid) : '';
		$uid = get_term_meta($term_id, 'vf_object_uid', true);
		$uid = is_string($uid) ? trim($uid) : '';

	$files = glob(trailingslashit($folder) . '*.json');
	if (empty($files)) {
		if (class_exists('DBVC_Database')) {
			DBVC_Database::delete_entity_by_object('term:' . sanitize_key($taxonomy), $term_id);
		}
		return;
	}

		// 1) Match by UUID
		if ($uuid !== '') {
			$matches = [];
			foreach ($files as $file) {
				$raw = file_get_contents($file);
				if ($raw === false) {
					continue;
				}
				$payload = json_decode($raw, true);
				if (! is_array($payload)) {
					continue;
				}
				$payload_uuid = isset($payload['uuid']) ? (string) $payload['uuid'] : '';
				if ($payload_uuid === '' && isset($payload['meta']['uuid'])) {
					$payload_uuid = is_array($payload['meta']['uuid']) ? (string) ($payload['meta']['uuid'][0] ?? '') : (string) $payload['meta']['uuid'];
				}
				if ($payload_uuid !== '' && $payload_uuid === $uuid) {
					$matches[] = $file;
				}
			}
			if (count($matches) === 1) {
				@unlink($matches[0]);
				if (class_exists('DBVC_Database')) {
					DBVC_Database::delete_entity_by_uid($uuid);
				}
				return;
			}
		}

		// 2) Match by vf_object_uid
		if ($uid !== '') {
			$matches = [];
			foreach ($files as $file) {
				$raw = file_get_contents($file);
				if ($raw === false) {
					continue;
				}
				$payload = json_decode($raw, true);
				if (! is_array($payload)) {
					continue;
				}
				$payload_uid = isset($payload['vf_object_uid']) ? (string) $payload['vf_object_uid'] : '';
				if ($payload_uid !== '' && $payload_uid === $uid) {
					$matches[] = $file;
				}
			}
			if (count($matches) === 1) {
				@unlink($matches[0]);
				if (class_exists('DBVC_Database')) {
					DBVC_Database::delete_entity_by_uid($uid);
				}
				return;
			}
		}

		// 2b) Match by payload term_id + slug/taxonomy if filename deviates
		$payload_matches = [];
		foreach ($files as $file) {
			$raw = file_get_contents($file);
			if ($raw === false) {
				continue;
			}
			$payload = json_decode($raw, true);
			if (! is_array($payload)) {
				continue;
			}
			$payload_tax = isset($payload['taxonomy']) ? sanitize_key($payload['taxonomy']) : '';
			$payload_id  = isset($payload['term_id']) ? absint($payload['term_id']) : 0;
			$payload_slug = isset($payload['slug']) ? sanitize_title($payload['slug']) : '';

			if ($payload_tax !== '' && $payload_tax !== $taxonomy) {
				continue;
			}

			if ($payload_id && $payload_id === $term_id) {
				$payload_matches[] = $file;
				continue;
			}

			if ($slug !== '' && $payload_slug !== '' && $payload_slug === $slug) {
				$payload_matches[] = $file;
			}
		}
		if (count($payload_matches) === 1) {
			@unlink($payload_matches[0]);
			if (class_exists('DBVC_Database')) {
				DBVC_Database::delete_entity_by_object('term:' . sanitize_key($taxonomy), $term_id);
			}
			return;
		}

		$prefix = sanitize_file_name($taxonomy) . '-';
		$slug_id = ($slug !== '' && $term_id) ? $prefix . $slug . '-' . $term_id . '.json' : '';
		$id_name = $prefix . $term_id . '.json';
		$slug_name = ($slug !== '') ? $prefix . $slug . '.json' : '';

		// 3) slug_id
		if ($slug_id !== '' && file_exists($folder . $slug_id)) {
			@unlink($folder . $slug_id);
			if (class_exists('DBVC_Database')) {
				DBVC_Database::delete_entity_by_object('term:' . sanitize_key($taxonomy), $term_id);
			}
			return;
		}

		// 4) id
		if (file_exists($folder . $id_name)) {
			@unlink($folder . $id_name);
			if (class_exists('DBVC_Database')) {
				DBVC_Database::delete_entity_by_object('term:' . sanitize_key($taxonomy), $term_id);
			}
			return;
		}

		// 5) slug
	if ($slug_name !== '' && file_exists($folder . $slug_name)) {
		@unlink($folder . $slug_name);
		if (class_exists('DBVC_Database')) {
			DBVC_Database::delete_entity_by_object('term:' . sanitize_key($taxonomy), $term_id);
		}
		return;
	}

	if (class_exists('DBVC_Database')) {
		DBVC_Database::delete_entity_by_object('term:' . sanitize_key($taxonomy), $term_id);
	}
}

public static function ensure_directory_security($path)
	{
		if (function_exists('dbvc_is_sync_ftp_window_active') && dbvc_is_sync_ftp_window_active()) {
			return;
		}

		if (! is_dir($path)) {
			return;
		}

		$path          = trailingslashit($path);
		$htaccess_path = $path . '.htaccess';
		$index_path    = $path . 'index.php';

		if (! file_exists($htaccess_path)) {
			$rules = "# Block direct access to DBVC taxonomy files\nOrder allow,deny\nDeny from all\n\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n\nOptions -Indexes\n";
			file_put_contents($htaccess_path, $rules);
		}

		if (! file_exists($index_path)) {
			file_put_contents($index_path, "<?php\n// Silence is golden.\nexit;\n");
		}
	}

	/**
	 * Normalize filename mode (id|slug|slug_id) with fallback to global default.
	 *
	 * @since 1.3.0
	 */
	protected static function normalize_filename_mode($mode = null)
	{
		$allowed = apply_filters('dbvc_allowed_taxonomy_filename_formats', ['id', 'slug', 'slug_id']);
		if (! is_array($allowed) || empty($allowed)) {
			$allowed = ['id', 'slug', 'slug_id'];
		}

		if (is_string($mode) && in_array($mode, $allowed, true)) {
			return $mode;
		}

		$preferred = dbvc_get_taxonomy_filename_format();
		if (in_array($preferred, $allowed, true)) {
			return $preferred;
		}

		return in_array('id', $allowed, true) ? 'id' : reset($allowed);
	}

	/**
	 * Compute filename components for a taxonomy term.
	 *
	 * @since 1.3.0
	 * @return array{mode:string,filename:string,part:string}
	 */
	protected static function resolve_filename_components($term, $taxonomy, $mode = null)
	{
		$term_id = absint($term->term_id);
		$slug    = sanitize_title($term->slug);
		$mode    = self::normalize_filename_mode($mode);

		if ($mode === 'slug_id') {
			if ($slug !== '' && ! is_numeric($slug)) {
				$part = "{$slug}-{$term_id}";
			} else {
				$part = (string) $term_id;
				error_log("[DBVC] Term slug invalid for slug+ID filename, falling back to ID: {$taxonomy} {$term_id}.");
			}
		} elseif ($mode === 'slug') {
			if ($slug !== '' && ! is_numeric($slug)) {
				$part = $slug;
			} else {
				$part = (string) $term_id;
				error_log("[DBVC] Term slug invalid for slug filename, falling back to ID: {$taxonomy} {$term_id}.");
			}
		} else {
			$part = (string) $term_id;
		}

		$filename = sanitize_file_name($taxonomy . '-' . $part . '.json');

		return [
			'mode'     => $mode,
			'part'     => $part,
			'filename' => $filename,
		];
	}

	/**
	 * Export a single term to JSON.
	 *
	 * @since 1.3.0
	 */
	protected static function export_term($term, $taxonomy, $mode)
	{
		$include_meta   = get_option('dbvc_tax_export_meta', '1') === '1';
		$include_parent = get_option('dbvc_tax_export_parent_slugs', '1') === '1';

		$entity_uid = self::ensure_term_uid($term->term_id, $taxonomy);

		$data = [
			'term_id'   => absint($term->term_id),
			'name'      => $term->name,
			'slug'      => $term->slug,
			'taxonomy'  => $taxonomy,
			'description' => $term->description,
			'vf_object_uid' => $entity_uid,
		];

		if ($include_parent) {
			$parent_id = absint($term->parent);
			$data['parent'] = $parent_id;
			if ($parent_id) {
				$parent = get_term($parent_id, $taxonomy);
				if ($parent && ! is_wp_error($parent)) {
					$data['parent_slug'] = $parent->slug;
					$parent_uid = self::ensure_term_uid($parent_id, $taxonomy);
					if ($parent_uid !== '') {
						$data['parent_uid'] = $parent_uid;
					}
				}
			}
		}

		if ($include_meta) {
			$meta = get_term_meta($term->term_id);
			if (function_exists('dbvc_sanitize_post_meta_safe')) {
				$meta = dbvc_sanitize_post_meta_safe($meta);
			} else {
				// Fallback: recursively maybe_unserialize values.
				$meta = array_map(static function ($values) {
					if (! is_array($values)) {
						$values = [$values];
					}
					foreach ($values as $index => $value) {
						if (is_string($value) && is_serialized($value)) {
							$values[$index] = maybe_unserialize($value);
						}
					}
					return $values;
				}, $meta);
			}
			$data['meta'] = $meta;
		}

		$data = apply_filters('dbvc_export_term_data', $data, $term, $taxonomy);

		if (get_option('dbvc_export_sort_meta', '0') === '1' && isset($data['meta']) && is_array($data['meta']) && function_exists('dbvc_sort_array_recursive')) {
			$data['meta'] = dbvc_sort_array_recursive($data['meta']);
		}
		if (function_exists('dbvc_normalize_for_json')) {
			$data = dbvc_normalize_for_json($data);
		}

		$filename = self::resolve_filename_components($term, $taxonomy, $mode);
		$path     = self::get_taxonomy_sync_path($taxonomy);

		if (! is_dir($path)) {
			if (! wp_mkdir_p($path)) {
				error_log('[DBVC] Failed to create taxonomy directory: ' . $path);
				return;
			}
		}
		self::ensure_directory_security($path);

		$file_path = $path . $filename['filename'];
		$file_path = apply_filters('dbvc_export_term_file_path', $file_path, $term, $taxonomy);

		if (! dbvc_is_safe_file_path($file_path)) {
			error_log('[DBVC] Unsafe taxonomy file path: ' . $file_path);
			return;
		}

		$json = wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
		if ($json === false) {
			error_log('[DBVC] Failed to encode taxonomy term: ' . $term->term_id);
			return;
		}

		file_put_contents($file_path, $json);
		do_action('dbvc_after_export_term', $term, $taxonomy, $file_path, $data);
	}

	/**
	 * Ensure a term has a persistent entity UID.
	 *
	 * @since 1.4.0
	 *
	 * @param int    $term_id
	 * @param string $taxonomy
	 * @return string
	 */
	public static function ensure_term_uid($term_id, $taxonomy = '')
	{
		$term_id = absint($term_id);
		if (! $term_id) {
			return '';
		}

		if ($taxonomy === '') {
			$term = get_term($term_id);
			if (! $term || is_wp_error($term)) {
				return '';
			}
			$taxonomy = $term->taxonomy;
		}

		$taxonomy = sanitize_key($taxonomy);
		if ($taxonomy === '') {
			return '';
		}

		$uid = get_term_meta($term_id, 'vf_object_uid', true);
		if (! is_string($uid) || $uid === '') {
			$uid = wp_generate_uuid4();
			update_term_meta($term_id, 'vf_object_uid', $uid);
		}

		self::sync_term_entity_registry($uid, $term_id, $taxonomy);

		return $uid;
	}

	/**
	 * Hooked entry point to ensure a UID exists whenever a term changes.
	 *
	 * @since 1.4.0
	 *
	 * @param int    $term_id
	 * @param int    $term_taxonomy_id
	 * @param string $taxonomy
	 * @return void
	 */
	public static function ensure_term_uid_on_change($term_id, $term_taxonomy_id = 0, $taxonomy = '')
	{
		unset($term_taxonomy_id); // Silence unused param; taxonomy slug is provided by hook.
		if ($taxonomy === '' || ! taxonomy_exists($taxonomy)) {
			$term = get_term($term_id);
			if ($term && ! is_wp_error($term)) {
				$taxonomy = $term->taxonomy;
			}
		}

		self::ensure_term_uid($term_id, $taxonomy);
	}

	/**
	 * Persist term entity mapping to the registry table.
	 *
	 * @since 1.4.0
	 *
	 * @param string $entity_uid
	 * @param int    $term_id
	 * @param string $taxonomy
	 * @return void
	 */
	protected static function sync_term_entity_registry($entity_uid, $term_id, $taxonomy)
	{
		if ($entity_uid === '' || ! class_exists('DBVC_Database')) {
			return;
		}

		DBVC_Database::upsert_entity([
			'entity_uid'    => $entity_uid,
			'object_id'     => (int) $term_id,
			'object_type'   => 'term:' . sanitize_key($taxonomy),
			'object_status' => null,
		]);
	}

	/**
	 * Export all selected taxonomies.
	 *
	 * @since 1.3.0
	 */
	public static function export_selected_taxonomies()
	{
		$selected = dbvc_get_selected_taxonomies();
		if (empty($selected)) {
			return;
		}

		$mode = dbvc_get_taxonomy_filename_format();

		foreach ($selected as $taxonomy) {
			$taxonomy = sanitize_key($taxonomy);
			if (! taxonomy_exists($taxonomy)) {
				continue;
			}

			$terms = get_terms([
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
			]);

			if (is_wp_error($terms) || empty($terms)) {
				continue;
			}

			foreach ($terms as $term) {
				self::export_term($term, $taxonomy, $mode);
			}
		}
	}

	/**
	 * Import terms from JSON files.
	 *
	 * @since 1.3.0
	 */
	public static function import_taxonomies($selected = null)
	{
		$selected = $selected === null ? dbvc_get_selected_taxonomies() : array_map('sanitize_key', (array) $selected);
		if (empty($selected)) {
			return;
		}

		foreach ($selected as $taxonomy) {
			$taxonomy = sanitize_key($taxonomy);
			if (! taxonomy_exists($taxonomy)) {
				continue;
			}

			$path = self::get_taxonomy_sync_path($taxonomy);
			if (! is_dir($path)) {
				continue;
			}

			$files = glob($path . '*.json');
			if (empty($files)) {
				continue;
			}

			foreach ($files as $file) {
				self::import_term_from_file($file, $taxonomy);
			}
		}
	}

	/**
	 * Normalize existing term JSON files by ensuring vf_object_uid + history metadata.
	 *
	 * @since 1.4.0
	 *
	 * @param array|null $selected Optional list of taxonomies to scan.
	 * @return array{processed:int,updated:int,skipped:int,errors:int}
	 */
	public static function normalize_term_json_files($selected = null)
	{
		$stats = [
			'processed' => 0,
			'updated'   => 0,
			'skipped'   => 0,
			'errors'    => 0,
		];

		$base = trailingslashit(dbvc_get_sync_path('taxonomy'));
		if (! is_dir($base)) {
			return $stats;
		}

		$taxonomies = [];
		if ($selected === null) {
			$entries = array_diff(scandir($base), ['.', '..']);
			foreach ($entries as $entry) {
				if (is_dir($base . $entry)) {
					$taxonomies[] = sanitize_key($entry);
				}
			}
		} else {
			$taxonomies = array_map('sanitize_key', (array) $selected);
		}

		if (empty($taxonomies)) {
			return $stats;
		}

		foreach ($taxonomies as $taxonomy) {
			if ($taxonomy === '') {
				continue;
			}
			$path = trailingslashit($base . $taxonomy);
			if (! is_dir($path)) {
				continue;
			}

			$files = glob($path . '*.json');
			if (empty($files)) {
				continue;
			}

			foreach ($files as $file_path) {
				$stats['processed']++;
				$contents = file_get_contents($file_path);
				if ($contents === false) {
					$stats['errors']++;
					continue;
				}

				$data = json_decode($contents, true);
				if (! is_array($data) || empty($data['slug'])) {
					$stats['skipped']++;
					continue;
				}

				$changed = false;
				$slug = sanitize_title($data['slug']);
				$incoming_uid = isset($data['vf_object_uid']) ? trim((string) $data['vf_object_uid']) : '';
				$resolved_uid = $incoming_uid;
				$term = null;

				if ($slug !== '' && taxonomy_exists($taxonomy)) {
					$term = get_term_by('slug', $slug, $taxonomy);
				}

				if ($term && ! is_wp_error($term)) {
					$term_uid = self::ensure_term_uid($term->term_id, $taxonomy);
					if ($term_uid !== '' && $term_uid !== $resolved_uid) {
						$resolved_uid = $term_uid;
						$changed = true;
					}
				} elseif ($resolved_uid === '') {
					$resolved_uid = wp_generate_uuid4();
					$changed = true;
				}

				if ($resolved_uid !== $incoming_uid) {
					$data['vf_object_uid'] = $resolved_uid;
					$changed = true;
				}

				if (! isset($data['meta']) || ! is_array($data['meta'])) {
					$data['meta'] = [];
					$changed = true;
				}

				$history = [
					'normalized_from' => 'local-json',
					'original_term_id'=> isset($data['term_id']) ? absint($data['term_id']) : ($term ? (int) $term->term_id : 0),
					'original_slug'   => $slug,
					'taxonomy'        => $taxonomy,
					'normalized_at'   => current_time('mysql'),
					'normalized_by'   => get_current_user_id(),
					'json_filename'   => basename($file_path),
					'status'          => ($term && ! is_wp_error($term)) ? 'existing' : 'unknown',
					'vf_object_uid'   => $resolved_uid,
				];

				if (! isset($data['meta']['dbvc_term_history']) || empty($data['meta']['dbvc_term_history'])) {
					$data['meta']['dbvc_term_history'] = [$history];
					$changed = true;
				}

				if ($changed) {
					$new_json = wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
					if ($new_json === false) {
						$stats['errors']++;
						continue;
					}
					file_put_contents($file_path, $new_json);
					$stats['updated']++;
				}
			}
		}

		return $stats;
	}

	/**
	 * Import a single term from a JSON file.
	 *
	 * @since 1.3.0
	 */
	protected static function import_term_from_file($file_path, $taxonomy)
	{
		$contents = file_get_contents($file_path);
		if ($contents === false) {
			return;
		}

		$data = json_decode($contents, true);
		if (! is_array($data) || empty($data['slug'])) {
			error_log('[DBVC] Invalid taxonomy JSON: ' . $file_path);
			return;
		}

		$incoming_uid = isset($data['vf_object_uid']) ? trim((string) $data['vf_object_uid']) : '';
		$slug        = sanitize_title($data['slug']);
		$name        = sanitize_text_field($data['name'] ?? $slug);
		$description = isset($data['description']) ? wp_kses_post($data['description']) : '';

		$term = get_term_by('slug', $slug, $taxonomy);
		$args = [
			'description' => $description,
		];

		if (! empty($data['parent_slug']) && taxonomy_exists($taxonomy)) {
			$parent_slug = sanitize_title($data['parent_slug']);
			$parent_term = get_term_by('slug', $parent_slug, $taxonomy);
			if ($parent_term) {
				$args['parent'] = $parent_term->term_id;
			}
		} elseif (! empty($data['parent'])) {
			$parent_id = absint($data['parent']);
			if ($parent_id && get_term($parent_id, $taxonomy)) {
				$args['parent'] = $parent_id;
			}
		}

		$created = false;
		if ($term && ! is_wp_error($term)) {
			wp_update_term($term->term_id, $taxonomy, ['name' => $name] + $args);
			$term_id = $term->term_id;
		} else {
			$insert = wp_insert_term($name, $taxonomy, ['slug' => $slug] + $args);
			if (is_wp_error($insert)) {
				error_log('[DBVC] Failed to insert term ' . $slug . ' in ' . $taxonomy . ': ' . $insert->get_error_message());
				return;
			}
			$term_id = $insert['term_id'];
			$created = true;
		}

		$effective_uid = $incoming_uid;
		if ($effective_uid !== '') {
			update_term_meta($term_id, 'vf_object_uid', $effective_uid);
			self::sync_term_entity_registry($effective_uid, $term_id, $taxonomy);
		} else {
			$effective_uid = self::ensure_term_uid($term_id, $taxonomy);
		}

		$data['vf_object_uid'] = $effective_uid;

		$history = [
			'imported_from'    => 'local-json',
			'original_term_id' => isset($data['term_id']) ? absint($data['term_id']) : 0,
			'original_slug'    => $slug,
			'taxonomy'         => $taxonomy,
			'imported_at'      => current_time('mysql'),
			'imported_by'      => get_current_user_id(),
			'json_filename'    => basename($file_path),
			'status'           => $created ? 'imported' : 'existing',
			'vf_object_uid'    => $effective_uid,
		];

		update_term_meta($term_id, 'dbvc_term_history', $history);
		if (! isset($data['meta']) || ! is_array($data['meta'])) {
			$data['meta'] = [];
		}
		$data['meta']['dbvc_term_history'] = [$history];

		if (isset($data['meta']) && is_array($data['meta'])) {
			foreach ($data['meta'] as $meta_key => $values) {
				if (! is_array($values)) {
					$values = [$values];
				}

				delete_term_meta($term_id, $meta_key);
				foreach ($values as $value) {
					add_term_meta($term_id, $meta_key, maybe_unserialize($value));
				}
			}
		}

		$new_json = wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
		if ($new_json !== false) {
			file_put_contents($file_path, $new_json);
		}

		do_action('dbvc_after_import_term', $term_id, $taxonomy, $data);
	}
}
