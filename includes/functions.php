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
