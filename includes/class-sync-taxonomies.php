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
	protected static function ensure_directory_security($path)
	{
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

		$data = [
			'term_id'   => absint($term->term_id),
			'name'      => $term->name,
			'slug'      => $term->slug,
			'taxonomy'  => $taxonomy,
			'description' => $term->description,
		];

		if ($include_parent) {
			$parent_id = absint($term->parent);
			$data['parent'] = $parent_id;
			if ($parent_id) {
				$parent = get_term($parent_id, $taxonomy);
				if ($parent && ! is_wp_error($parent)) {
					$data['parent_slug'] = $parent->slug;
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
		}

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

		do_action('dbvc_after_import_term', $term_id, $taxonomy, $data);
	}
}
