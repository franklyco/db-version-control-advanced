<?php
/**
 * Get the sync path for exports
 * 
 * @package   DB Version Control
 * @author    Robert DeVore <me@robertdevore.com>
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
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
function dbvc_get_sync_path( $subfolder = '' ) {
	$custom_path = get_option( 'dbvc_sync_path', '' );
	
	if ( ! empty( $custom_path ) ) {
		// Validate and sanitize the custom path
		$custom_path = dbvc_validate_sync_path( $custom_path );
		if ( false === $custom_path ) {
			// Fall back to default if invalid
			$base_path = DBVC_PLUGIN_PATH . 'sync/';
		} else {
			// Remove leading slash and treat as relative to ABSPATH
			$custom_path = ltrim( $custom_path, '/' );
			$base_path = trailingslashit( ABSPATH ) . $custom_path;
		}
	} else {
		// Default to plugin's sync folder
		$base_path = DBVC_PLUGIN_PATH . 'sync/';
	}
	
	$base_path = trailingslashit( $base_path );
	
	if ( ! empty( $subfolder ) ) {
		// Sanitize subfolder name
		$subfolder = sanitize_file_name( $subfolder );
		$base_path .= trailingslashit( $subfolder );
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
function dbvc_validate_sync_path( $path ) {
	if ( empty( $path ) ) {
		return '';
	}
	
	// Remove any null bytes
	$path = str_replace( chr( 0 ), '', $path );
	
	// Check for directory traversal attempts
	if ( strpos( $path, '..' ) !== false ) {
		return false;
	}
	
	// Check for other potentially dangerous characters
	$dangerous_chars = [ '<', '>', '"', '|', '?', '*', chr( 0 ) ];
	foreach ( $dangerous_chars as $char ) {
		if ( strpos( $path, $char ) !== false ) {
			return false;
		}
	}
	
	// Normalize slashes
	$path = str_replace( '\\', '/', $path );
	
	// Remove any double slashes
	$path = preg_replace( '#/+#', '/', $path );
	
	// Ensure path is within allowed boundaries (wp-content or plugin directory)
	$allowed_prefixes = [
		'wp-content/',
		'wp-content/plugins/',
		'wp-content/uploads/',
		'wp-content/themes/',
	];
	
	$is_allowed = false;
	foreach ( $allowed_prefixes as $prefix ) {
		if ( strpos( ltrim( $path, '/' ), $prefix ) === 0 ) {
			$is_allowed = true;
			break;
		}
	}
	
	// Also allow relative paths within the plugin directory
	if ( ! $is_allowed && strpos( $path, '/' ) !== 0 ) {
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
function dbvc_sanitize_json_data( $data ) {
	if ( is_array( $data ) ) {
		return array_map( 'dbvc_sanitize_json_data', $data );
	}
	
	if ( is_string( $data ) ) {
		// Remove any null bytes and other control characters
		$data = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $data );
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
function dbvc_is_safe_file_path( $file_path ) {
	// Check for null bytes
	if ( strpos( $file_path, chr( 0 ) ) !== false ) {
		return false;
	}
	
	// Check for directory traversal
	if ( strpos( $file_path, '..' ) !== false ) {
		return false;
	}
	
	// Ensure file is within WordPress directory structure
	$wp_path = realpath( ABSPATH );
	$resolved_path = realpath( dirname( $file_path ) );
	
	if ( false === $resolved_path || strpos( $resolved_path, $wp_path ) !== 0 ) {
		return false;
	}
	
	// Check file extension
	$allowed_extensions = [ 'json' ];
	$extension = pathinfo( $file_path, PATHINFO_EXTENSION );
	
	return in_array( strtolower( $extension ), $allowed_extensions, true );
}


/**
 * Remove the current site URL from content to make exports portable.
 */
function dbvc_remove_site_url_from_content( $value ) {
    if ( is_string( $value ) ) {
        $site_url = home_url();
        $value = str_replace( $site_url, '', $value );
        $value = str_replace( esc_url_raw( $site_url ), '', $value );
    }
    return $value;
}
