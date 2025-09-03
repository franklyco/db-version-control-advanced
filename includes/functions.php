<?php

/**
 * Get the sync path for exports
 * 
 * @package   DB Version Control
 * @author    Robert DeVore <me@robertdevore.com>
 */

// If this file is called directly, abort.
if (! defined('WPINC')) {
	die;
}

// --- Masking helpers ---------------------------------------------------------
if (! function_exists('dbvc_mask_parse_list')) {
	/**
	 * Parse a comma/newline separated list into an array of patterns.
	 * Supports: exact strings, wildcards (*, ?) via fnmatch, and regex when wrapped in /.../ .
	 */
	function dbvc_mask_parse_list($raw) {
		$raw = (string) $raw;
		$parts = preg_split('/[,\r\n]+/', $raw);
		$out = [];
		foreach ($parts as $p) {
			$p = trim($p);
			if ($p !== '') $out[] = $p;
		}
		return $out;
	}
}

if (! function_exists('dbvc_mask_match')) {
	/**
	 * Match $target against $pattern.
	 * - If pattern starts/ends with /, treat as regex.
	 * - Else if contains * or ?, use fnmatch (case-insensitive).
	 * - Else exact compare (case-sensitive).
	 */
	function dbvc_mask_match($target, $pattern) {
		if ($pattern === '') return false;
		if (strlen($pattern) > 2 && $pattern[0] === '/' && substr($pattern, -1) === '/') {
			return (bool) @preg_match($pattern, $target);
		}
		if (strpbrk($pattern, '*?') !== false) {
			// Use fnmatch if available, fallback to regex-ish
			if (function_exists('fnmatch')) {
				return fnmatch($pattern, $target, FNM_CASEFOLD);
			}
			$regex = '/^' . str_replace(['\*','\?'], ['.*','.?'], preg_quote($pattern, '/')) . '$/i';
			return (bool) preg_match($regex, $target);
		}
		return $target === $pattern;
	}
}

if (! function_exists('dbvc_mask_should_remove_key')) {
	/** Check if a top-level meta key matches any exclude/mask pattern. */
	function dbvc_mask_should_remove_key($meta_key, array $patterns) {
		foreach ($patterns as $pat) {
			if (dbvc_mask_match($meta_key, $pat)) return true;
		}
		return false;
	}
}

if (! function_exists('dbvc_mask_should_remove_path')) {
	/**
	 * Check if a *path* (e.g. "_bricks_page_content_2.0.settings.signature") should be masked.
	 * Matches if either the full dot-path OR the final segment matches any provided subkey pattern.
	 */
	function dbvc_mask_should_remove_path($dot_path, $leaf_key, array $patterns) {
		foreach ($patterns as $pat) {
			if (dbvc_mask_match($dot_path, $pat)) return true;
			if (dbvc_mask_match($leaf_key, $pat)) return true;
		}
		return false;
	}
}

if (! function_exists('dbvc_mask_walk_value')) {
	/**
	 * Recursively walk a meta value (array|object|scalar) and remove or redact
	 * any entries whose dot-path or leaf key matches subkey rules.
	 *
	 * @param mixed  $value
	 * @param string $current_path Dot path from the meta key root
	 * @param array  $subkey_patterns
	 * @param string $action 'remove' or 'redact'
	 * @param string $placeholder replacement when redacting
	 * @return mixed
	 */
	function dbvc_mask_walk_value($value, $current_path, array $subkey_patterns, $action = 'remove', $placeholder = '***') {
		// Arrays
		if (is_array($value)) {
			foreach ($value as $k => $v) {
				$leaf_key   = is_int($k) ? (string)$k : (string)$k;
				$child_path = $current_path === '' ? $leaf_key : ($current_path . '.' . $leaf_key);

				// If the leaf key/path itself is a match, apply action
				if (dbvc_mask_should_remove_path($child_path, $leaf_key, $subkey_patterns)) {
					if ($action === 'remove') {
						unset($value[$k]);
						continue;
					}
					$value[$k] = $placeholder;
					continue;
				}

				// Recurse
				$value[$k] = dbvc_mask_walk_value($v, $child_path, $subkey_patterns, $action, $placeholder);
			}
			return $value;
		}

		// Objects
		if (is_object($value)) {
			foreach ($value as $k => $v) {
				$leaf_key   = (string)$k;
				$child_path = $current_path === '' ? $leaf_key : ($current_path . '.' . $leaf_key);

				if (dbvc_mask_should_remove_path($child_path, $leaf_key, $subkey_patterns)) {
					if ($action === 'remove') {
						unset($value->$k);
						continue;
					}
					$value->$k = $placeholder;
					continue;
				}

				$value->$k = dbvc_mask_walk_value($v, $child_path, $subkey_patterns, $action, $placeholder);
			}
			return $value;
		}

		// Scalars — only mask by *path* at parent level (handled above). Return as-is.
		return $value;
	}
}

if (! function_exists('dbvc_mask_apply_to_meta')) {
	/**
	 * Apply masking/exclusion rules to the entire $meta array.
	 *
	 * Options (stored as strings in wp_options):
	 * - dbvc_mask_meta_keys   (comma/newline list, supports wildcards/regex)
	 * - dbvc_mask_subkeys     (comma/newline list of subpaths or leaf keys)
	 * - dbvc_mask_action      ('remove' or 'redact')
	 * - dbvc_mask_placeholder (string for 'redact' mode, default '***')
	 */
	function dbvc_mask_apply_to_meta(array $meta) {
		$keys_raw   = (string) get_option('dbvc_mask_meta_keys', '');
		$subs_raw   = (string) get_option('dbvc_mask_subkeys', '');
		$action     = get_option('dbvc_mask_action', 'remove');
		$placeholder= (string) get_option('dbvc_mask_placeholder', '***');

		$key_patterns = dbvc_mask_parse_list($keys_raw);
		$sub_patterns = dbvc_mask_parse_list($subs_raw);

		// 1) Remove/redact whole meta keys
		foreach ($meta as $mkey => $mval) {
			if (dbvc_mask_should_remove_key($mkey, $key_patterns)) {
				if ($action === 'remove') {
					unset($meta[$mkey]);
					continue;
				}
				$meta[$mkey] = $placeholder;
			}
		}

		// 2) Walk remaining meta for subkey/path masking
		if (! empty($sub_patterns)) {
			foreach ($meta as $mkey => $mval) {
				$root_path = $mkey; // root of dot-path is the meta key
				$meta[$mkey] = dbvc_mask_walk_value($mval, $root_path, $sub_patterns, $action, $placeholder);
			}
		}

		return $meta;
	}
}

// Helper: recursive string replace for arrays/objects
if ( ! function_exists( 'dbvc_recursive_str_replace' ) ) {
	function dbvc_recursive_str_replace( $search, $replace, $value ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $k => $v ) {
				$value[ $k ] = dbvc_recursive_str_replace( $search, $replace, $v );
			}
			return $value;
		}
		if ( is_object( $value ) ) {
			foreach ( $value as $k => $v ) {
				$value->$k = dbvc_recursive_str_replace( $search, $replace, $v );
			}
			return $value;
		}
		return is_string( $value ) ? str_replace( $search, $replace, $value ) : $value;
	}
}

/**
 * Get the sync path for exports
 * 
 * @param string $subfolder Optional subfolder name
 * 
 * @since  1.0.0
 * @return string
 */
function dbvc_get_sync_path($subfolder = '')
{
	$custom_path = get_option('dbvc_sync_path', '');

	if (! empty($custom_path)) {
		// Validate and sanitize the custom path
		$custom_path = dbvc_validate_sync_path($custom_path);
		if (false === $custom_path) {
			// Fall back to default if invalid
			$base_path = DBVC_PLUGIN_PATH . 'sync/';
		} else {
			// Remove leading slash and treat as relative to ABSPATH
			$custom_path = ltrim($custom_path, '/');
			$base_path = trailingslashit(ABSPATH) . $custom_path;
		}
	} else {
		// Default to plugin's sync folder
		$base_path = DBVC_PLUGIN_PATH . 'sync/';
	}

	$base_path = trailingslashit($base_path);

	if (! empty($subfolder)) {
		// Sanitize subfolder name
		$subfolder = sanitize_file_name($subfolder);
		$base_path .= trailingslashit($subfolder);
	}

	return $base_path;
}

/**
 * Validate sync path to prevent directory traversal and other security issues.
 * 
 * @param string $path The path to validate.
 * 
 * @since  1.0.0
 * @return string|false Validated path or false if invalid.
 */
function dbvc_validate_sync_path($path)
{
	if (empty($path)) {
		return '';
	}

	// Remove any null bytes
	$path = str_replace(chr(0), '', $path);

	// Check for directory traversal attempts
	if (strpos($path, '..') !== false) {
		return false;
	}

	// Check for other potentially dangerous characters
	$dangerous_chars = ['<', '>', '"', '|', '?', '*', chr(0)];
	foreach ($dangerous_chars as $char) {
		if (strpos($path, $char) !== false) {
			return false;
		}
	}

	// Normalize slashes
	$path = str_replace('\\', '/', $path);

	// Remove any double slashes
	$path = preg_replace('#/+#', '/', $path);

	// Ensure path is within allowed boundaries (wp-content or plugin directory)
	$allowed_prefixes = [
		'wp-content/',
		'wp-content/plugins/',
		'wp-content/uploads/',
		'wp-content/themes/',
	];

	$is_allowed = false;
	foreach ($allowed_prefixes as $prefix) {
		if (strpos(ltrim($path, '/'), $prefix) === 0) {
			$is_allowed = true;
			break;
		}
	}

	// Also allow relative paths within the plugin directory
	if (! $is_allowed && strpos($path, '/') !== 0) {
		$is_allowed = true;
	}

	return $is_allowed ? $path : false;
}

/**
 * Sanitize JSON file content before writing.
 * 
 * @param mixed $data The data to sanitize.
 * 
 * @since  1.0.0
 * @return mixed Sanitized data.
 */
function dbvc_sanitize_json_data($data)
{
	if (is_array($data)) {
		return array_map('dbvc_sanitize_json_data', $data);
	}

	if (is_string($data)) {
		// Remove any null bytes and other control characters
		$data = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $data);
	}

	return $data;
}

/**
 * Check if a file path is safe for writing.
 * 
 * @param string $file_path The file path to check.
 * 
 * @since  1.0.0
 * @return bool True if safe, false otherwise.
 */
function dbvc_is_safe_file_path($file_path)
{
	// Check for null bytes
	if (strpos($file_path, chr(0)) !== false) {
		return false;
	}

	// Check for directory traversal
	if (strpos($file_path, '..') !== false) {
		return false;
	}

	// Ensure file is within WordPress directory structure
	$wp_path = realpath(ABSPATH);
	$resolved_path = realpath(dirname($file_path));

	if (false === $resolved_path || strpos($resolved_path, $wp_path) !== 0) {
		return false;
	}

	// Check file extension
	$allowed_extensions = ['json'];
	$extension = pathinfo($file_path, PATHINFO_EXTENSION);

	return in_array(strtolower($extension), $allowed_extensions, true);
}


/**
 * Remove the current site URL from content to make exports portable.
 */
function dbvc_remove_site_url_from_content($value)
{
	if (is_string($value)) {
		$site_url = home_url();
		$value = str_replace($site_url, '', $value);
		$value = str_replace(esc_url_raw($site_url), '', $value);
	}
	return $value;
}

/**
 * Recursively delete all files/folders inside a directory, but keep the directory itself.
 * Returns array [deleted:int, failed:int].
 */
/**
 * Recursively delete all contents in a directory, optionally deleting the directory itself.
 * Returns array [deleted:int, failed:int, deleted_root:bool].
 */
function dbvc_delete_sync_contents($base_dir, $delete_root = false)
{
	$deleted = 0;
	$failed = 0;
	$deleted_root = false;

	if (! is_string($base_dir) || $base_dir === '') return [$deleted, $failed, $deleted_root];
	$base_dir = wp_normalize_path($base_dir);
	if (! is_dir($base_dir)) return [$deleted, $failed, $deleted_root];

	// Safety rails
	$abs = wp_normalize_path(ABSPATH);
	if (strpos($base_dir, $abs) !== 0) return [$deleted, $failed, $deleted_root];

	$it = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($base_dir, FilesystemIterator::SKIP_DOTS),
		RecursiveIteratorIterator::CHILD_FIRST
	);

	foreach ($it as $path) {
		$normalized = wp_normalize_path($path->getPathname());

		// Never allow deleting these anchors
		$forbid = [
			$abs,
			wp_normalize_path(WP_CONTENT_DIR),
			wp_normalize_path(WP_PLUGIN_DIR),
			wp_normalize_path(get_theme_root()),
			wp_normalize_path(get_stylesheet_directory()),
			wp_normalize_path(get_template_directory()),
		];
		if (in_array($normalized, $forbid, true)) {
			$failed++;
			continue;
		}

		if ($path->isDir()) {
			if (@rmdir($normalized)) {
				$deleted++;
			} else {
				$failed++;
			}
		} else {
			if (@unlink($normalized)) {
				$deleted++;
			} else {
				$failed++;
			}
		}
	}

	// Optionally remove the root folder itself (with extra guard)
	if ($delete_root) {
		if (dbvc_is_safe_to_delete_root($base_dir, $abs)) {
			if (@rmdir($base_dir)) {
				$deleted_root = true;
			} else {
				$failed++;
			}
		}
	}

	return [$deleted, $failed, $deleted_root];
}

/**
 * Extra guard before deleting the root of the sync path.
 * Require reasonable depth under ABSPATH and forbid critical anchors.
 */
function dbvc_is_safe_to_delete_root($dir, $abs = null)
{
	$dir = wp_normalize_path($dir);
	$abs = $abs ? wp_normalize_path($abs) : wp_normalize_path(ABSPATH);

	if (strpos($dir, $abs) !== 0) return false;

	$forbid = [
		$abs,
		wp_normalize_path(WP_CONTENT_DIR),
		wp_normalize_path(WP_PLUGIN_DIR),
		wp_normalize_path(get_theme_root()),
		wp_normalize_path(get_stylesheet_directory()),
		wp_normalize_path(get_template_directory()),
	];
	if (function_exists('wp_upload_dir')) {
		$up = wp_upload_dir(null, false);
		if (! empty($up['basedir'])) $forbid[] = wp_normalize_path($up['basedir']);
	}
	if (in_array($dir, $forbid, true)) return false;

	// Require at least 3 segments under ABSPATH (e.g. wp-content/themes/vertical/sync/db-version-control-main)
	$rel = trim(str_replace($abs, '', $dir), '/');
	$segments = array_values(array_filter(explode('/', $rel)));
	return count($segments) >= 3;
}


/**
 * Create a zip of the sync folder contents and stream it to the browser.
 * Returns the absolute path to the temporary zip (caller may delete), or WP_Error.
 *
 * NOTE: This mirrors Tab 2 behavior but writes to a temp file first so
 * we can delete the source after the stream (download-then-delete flow).
 */
function dbvc_build_sync_zip($source_dir)
{
	if (! class_exists('ZipArchive')) {
		return new WP_Error('zip_missing', __('ZipArchive is not available on this server.', 'dbvc'));
	}

	$source_dir = wp_normalize_path($source_dir);
	if (! is_dir($source_dir)) {
		return new WP_Error('no_dir', __('Sync directory does not exist.', 'dbvc'));
	}

	// Ensure trailing slash so relative paths are correct
	$base = trailingslashit($source_dir);

	$tmp = wp_tempnam('dbvc-sync.zip');
	@unlink($tmp);

	$zip = new ZipArchive();
	if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
		return new WP_Error('zip_open_fail', __('Failed to create ZIP.', 'dbvc'));
	}

	$it = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($source_dir, FilesystemIterator::SKIP_DOTS),
		RecursiveIteratorIterator::SELF_FIRST
	);

	foreach ($it as $file) {
		$path  = wp_normalize_path($file->getPathname());
		// Remove the base path (with trailing slash); no +1 needed
		$local = ltrim(str_replace($base, '', $path), '/');

		if ($local === '') {
			continue; // don't add the root directory itself
		}

		if ($file->isDir()) {
			$zip->addEmptyDir($local);
		} else {
			$zip->addFile($path, $local);
		}
	}

	$zip->close();
	return $tmp;
}
