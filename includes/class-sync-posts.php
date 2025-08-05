<?php
/**
 * Get the sync path for exports
 *  
 * @package   DB Version Control
 * @author    Robert DeVore <me@robertdevore.com
 * @since     1.0.0
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * Get the sync path for exports
 * 
 * @package   DB Version Control
 * @author    Robert DeVore <me@robertdevore.com>
 * @since     1.0.0
 * @return string
 */
class DBVC_Sync_Posts {

	/**
	 * Get the selected post types for export/import.
	 * 
	 * @since  1.0.0
	 * @return array
	 */
	public static function get_supported_post_types() {
		$selected_types = get_option( 'dbvc_post_types', [] );

		// If no post types are selected, default to post, page, and FSE types.
		if ( empty( $selected_types ) ) {
			$selected_types = [ 'post', 'page', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation' ];
		}

		// Allow other plugins to modify supported post types.
		return apply_filters( 'dbvc_supported_post_types', $selected_types );
	}

    public static function import_all( $offset = 0, $smart_import = false ) {
        $path = dbvc_get_sync_path(); // Do not append 'posts' subfolder

        if ( ! is_dir( $path ) ) {
            error_log( '[DBVC] Import path not found: ' . $path );
            return;
        }

        $supported_types = self::get_supported_post_types();
        $processed = 0;

        foreach ( $supported_types as $post_type ) {
            $folder = trailingslashit( $path ) . sanitize_key( $post_type );
            if ( ! is_dir( $folder ) ) {
                continue;
            }

            $json_files = glob( $folder . '/' . sanitize_key( $post_type ) . '-*.json' );
            foreach ( $json_files as $filepath ) {
                self::import_post_from_json( $filepath, $smart_import );
                $processed++;
            }
        }

        return [
            'processed' => $processed,
            'remaining' => 0,
            'total'     => $processed,
            'offset'    => $offset + $processed,
        ];
    }

    public static function import_post_from_json( $filepath, $smart_import = false ) {
	if ( ! file_exists( $filepath ) ) {
		return;
	}

	$json = json_decode( file_get_contents( $filepath ), true );

	// Skip if not a valid post structure
	if (
		empty( $json )
		|| ! is_array( $json )
		|| ! isset( $json['ID'], $json['post_type'], $json['post_title'] )
	) {
		error_log("[DBVC] Skipped non-post JSON file: {$filepath}");
		return;
	}

	$existing = get_post( absint( $json['ID'] ) );

	// 🧠 Smart Import Check — skip unchanged posts
	if ( $smart_import && $existing ) {
		$hash_key = '_dbvc_import_hash';

		// Exclude hash key from hash computation to avoid false mismatches
		$meta = $json['meta'] ?? [];
		unset( $meta['_dbvc_import_hash'] );

		$new_hash = md5( serialize( [ $json['post_content'], $meta ] ) );
		$existing_hash = get_post_meta( $existing->ID, $hash_key, true );

		if ( $new_hash === $existing_hash ) {
			error_log("[DBVC] Skipping unchanged post ID {$json['ID']}");
			return;
		}
	}

	$post_array = [
		'ID'           => absint( $json['ID'] ),
		'post_title'   => sanitize_text_field( $json['post_title'] ),
		'post_content' => wp_kses_post( $json['post_content'] ?? '' ),
		'post_excerpt' => sanitize_textarea_field( $json['post_excerpt'] ?? '' ),
		'post_type'    => sanitize_text_field( $json['post_type'] ),
		'post_status'  => sanitize_text_field( $json['post_status'] ?? 'draft' ),
	];

	$post_id = wp_insert_post( $post_array );

	// Import post meta
	if ( ! is_wp_error( $post_id ) && isset( $json['meta'] ) && is_array( $json['meta'] ) ) {
		foreach ( $json['meta'] as $key => $values ) {
			if ( is_array( $values ) ) {
				foreach ( $values as $value ) {
					update_post_meta( $post_id, sanitize_text_field( $key ), maybe_unserialize( $value ) );
				}
			}
		}
	}

	// ✅ Save hash excluding its own key
	if ( isset( $json['ID'] ) && $post_id ) {
		$meta = $json['meta'] ?? [];
		unset( $meta['_dbvc_import_hash'] );

		$hash = md5( serialize( [ $json['post_content'], $meta ] ) );
		update_post_meta( $post_id, '_dbvc_import_hash', $hash );
	}

	error_log("[DBVC] Imported post ID {$post_id}");
}

/**
     * Process backups folder to uploads - up to 10 timestamped backups with auto delete oldest
     * 
     * @since  1.1.0
     * @return void
     */
public static function dbvc_create_backup_folder_and_copy_exports() {
	$export_dir  = dbvc_get_sync_path();
	$upload_dir  = wp_upload_dir();
	$sync_dir    = $upload_dir['basedir'] . '/sync';
	$backup_base = $sync_dir . '/db-version-control-backups';
	$timestamp   = date( 'm-d-Y-His' );
	$backup_path = $backup_base . '/' . $timestamp;

	error_log( '[DBVC] Attempting backup to: ' . $backup_path );

	// Ensure top-level /sync/ folder has .htaccess to disable indexing
	if ( is_dir( $sync_dir ) ) {
		$sync_htaccess = $sync_dir . '/.htaccess';
		if ( ! file_exists( $sync_htaccess ) ) {
			file_put_contents( $sync_htaccess, "Options -Indexes\n" );
			error_log( '[DBVC] Created .htaccess in /sync/ folder' );
		}
	}

	// Create backup folder
	if ( ! file_exists( $backup_path ) ) {
		if ( wp_mkdir_p( $backup_path ) ) {
			error_log( '[DBVC] Created backup folder: ' . $backup_path );

			// Add enhanced .htaccess file to restrict all access (Apache 2.2 + 2.4+ compatible)
			$htaccess = <<<HT
# Protect DBVC backup files from direct web access
Order allow,deny
Deny from all

<IfModule mod_authz_core.c>
    Require all denied
</IfModule>

Options -Indexes
HT;
			file_put_contents( $backup_path . '/.htaccess', $htaccess );
			error_log( '[DBVC] Created .htaccess in backup folder' );

			// Add index.php to prevent directory browsing
			$index_php = "<?php\n// Silence is golden.\nexit;";
			file_put_contents( $backup_path . '/index.php', $index_php );
			error_log( '[DBVC] Created index.php in backup folder' );
		} else {
			error_log( '[DBVC] ERROR: Failed to create backup folder: ' . $backup_path );
			return;
		}
	}

	// Copy JSON files from export directory recursively
	$json_files = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $export_dir, RecursiveDirectoryIterator::SKIP_DOTS )
	);

	foreach ( $json_files as $file ) {
		if ( $file->getExtension() !== 'json' ) {
			continue;
		}

		$relative_path = str_replace( $export_dir, '', $file->getPathname() );
		$dest_path     = $backup_path . '/' . ltrim( $relative_path, '/' );

		// Ensure destination subdirectory exists
		$dest_dir = dirname( $dest_path );
		if ( ! file_exists( $dest_dir ) ) {
			wp_mkdir_p( $dest_dir );
		}

		$copy_result = copy( $file->getPathname(), $dest_path );
		if ( $copy_result ) {
			error_log( "[DBVC] Copied {$relative_path} to {$dest_path}" );
		} else {
			error_log( "[DBVC] ERROR: Failed to copy {$relative_path} to {$dest_path}" );
		}
	}

	// Cleanup: Keep only 10 latest backup folders
	$folders = glob( $backup_base . '/*', GLOB_ONLYDIR );
	usort( $folders, function ( $a, $b ) {
		return filemtime( $b ) <=> filemtime( $a );
	});

	$old_folders = array_slice( $folders, 10 );
	foreach ( $old_folders as $old_folder ) {
		dbvc_delete_folder_recursive( $old_folder );
		error_log( "[DBVC] Deleted old backup folder: $old_folder" );
	}
}



    /**
     * Export a single post to JSON file.
     * 
     * @param int    $post_id Post ID.
     * @param object $post    Post object.
     * 
     * @since  1.0.0
     * @return void
     */
	public static function export_post_to_json( $post_id, $post ) {
		// Validate inputs.
		if ( ! is_numeric( $post_id ) || $post_id <= 0 ) {
			return;
		}

		if ( ! is_object( $post ) || ! isset( $post->post_type ) ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		// For FSE content, allow draft status as templates can be in draft.
		$allowed_statuses = [ 'publish' ];
		if ( in_array( $post->post_type, [ 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation' ], true ) ) {
			$allowed_statuses[] = 'draft';
			$allowed_statuses[] = 'auto-draft';
		}

		if ( ! in_array( $post->post_status, $allowed_statuses, true ) ) {
			return;
		}

		$supported_types = self::get_supported_post_types();
		if ( ! in_array( $post->post_type, $supported_types, true ) ) {
			return;
		}

		// Check if user has permission to read this post type (skip for WP-CLI).
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			$post_type_obj = get_post_type_object( $post->post_type );
			if ( ! $post_type_obj || ! current_user_can( $post_type_obj->cap->read_post, $post_id ) ) {
				return;
			}
		}

		$data = [
			'ID'           => absint( $post_id ),
			'post_title'   => sanitize_text_field( $post->post_title ),
			'post_content' => wp_kses_post( $post->post_content ),
			'post_excerpt' => sanitize_textarea_field( $post->post_excerpt ),
			'post_type'    => sanitize_text_field( $post->post_type ),
			'post_status'  => sanitize_text_field( $post->post_status ),
			'post_name'    => sanitize_text_field( $post->post_name ),
			'meta'         => self::sanitize_post_meta( get_post_meta( $post_id ) ),
		];

		// Add FSE-specific data.
		if ( in_array( $post->post_type, [ 'wp_template', 'wp_template_part' ], true ) ) {
			$data['theme']  = get_stylesheet();
			$data['slug']   = $post->post_name;
			$data['source'] = get_post_meta( $post_id, 'origin', true ) ?: 'custom';
		}

		// Allow other plugins to modify the export data
		$data = apply_filters( 'dbvc_export_post_data', $data, $post_id, $post );
		
		// Sanitize the final data
		$data = dbvc_sanitize_json_data( $data );

        $path = dbvc_get_sync_path( $post->post_type );

		if ( ! is_dir( $path ) ) {
			if ( ! wp_mkdir_p( $path ) ) {
				error_log( 'DBVC: Failed to create directory: ' . $path );
				return;
			}
		}

		$file_path = $path . sanitize_file_name( $post->post_type . '-' . $post_id . '.json' );
		
		// Allow other plugins to modify the file path.
		$file_path = apply_filters( 'dbvc_export_post_file_path', $file_path, $post_id, $post );
		
		// Validate the final file path
		if ( ! dbvc_is_safe_file_path( $file_path ) ) {
			error_log( 'DBVC: Unsafe file path detected: ' . $file_path );
			return;
		}

		$json_content = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		if ( false === $json_content ) {
			error_log( 'DBVC: Failed to encode JSON for post ' . $post_id );
			return;
		}
		
		$result = file_put_contents( $file_path, $json_content );
		if ( false === $result ) {
			error_log( 'DBVC: Failed to write file: ' . $file_path );
			return;
		}

		// Allow other plugins to perform additional actions after export.
		do_action( 'dbvc_after_export_post', $post_id, $post, $file_path );
	}

    /**
     * Import all JSON files for supported post types.
     * 
     * @since  1.0.0
     * @return void
     */
	public static function import_all_json_files() {
        $supported_types = self::get_supported_post_types();
        
        foreach ( $supported_types as $post_type ) {
            $path  = dbvc_get_sync_path( $post_type );
            $files = glob( $path . '*.json' );
            
            if ( empty( $files ) ) {
                continue;
            }
            
            foreach ( $files as $file ) {
                $json = json_decode( file_get_contents( $file ), true );
                if ( empty( $json ) ) {
                    continue;
                }

                $post_id = wp_insert_post( [
                    'ID'           => $json['ID'],
                    'post_title'   => $json['post_title'],
                    'post_content' => $json['post_content'],
                    'post_excerpt' => $json['post_excerpt'],
                    'post_type'    => $json['post_type'],
                    'post_status'  => $json['post_status'],
                ] );

                if ( ! is_wp_error( $post_id ) && isset( $json['meta'] ) ) {
                    foreach ( $json['meta'] as $key => $values ) {
                        foreach ( $values as $value ) {
                            update_post_meta( $post_id, $key, maybe_unserialize( $value ) );
                        }
                    }
                }
            }
        }
	}

    /**
     * Export options to JSON file.
     * 
     * @since  1.0.0
     * @return void
     */
    public static function export_options_to_json() {
		// Check user capabilities for options export (skip for WP-CLI)
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}
		}

        $all_options = wp_load_alloptions();
        $excluded_keys = [
            'siteurl', 'home', 'blogname', 'blogdescription',
            'admin_email', 'users_can_register', 'start_of_week', 'upload_path',
            'upload_url_path', 'cron', 'recently_edited', 'rewrite_rules',
            // Security-sensitive options
            'auth_key', 'auth_salt', 'logged_in_key', 'logged_in_salt',
            'nonce_key', 'nonce_salt', 'secure_auth_key', 'secure_auth_salt',
            'secret_key', 'db_version', 'initial_db_version',
        ];

        // Allow other plugins to modify excluded keys
        $excluded_keys = apply_filters( 'dbvc_excluded_option_keys', $excluded_keys );

        $filtered = array_diff_key( $all_options, array_flip( $excluded_keys ) );
        
        // Sanitize options data
        $filtered = self::sanitize_options_data( $filtered );
        
        // Allow other plugins to modify the options data before export
        $filtered = apply_filters( 'dbvc_export_options_data', $filtered );

        $path = dbvc_get_sync_path();
        if ( ! is_dir( $path ) ) {
            if ( ! wp_mkdir_p( $path ) ) {
				error_log( 'DBVC: Failed to create directory: ' . $path );
				return;
			}
        }

        $file_path = $path . 'options.json';
        
        // Allow other plugins to modify the options file path.
        $file_path = apply_filters( 'dbvc_export_options_file_path', $file_path );
        
        // Validate file path
		if ( ! dbvc_is_safe_file_path( $file_path ) ) {
			error_log( 'DBVC: Unsafe file path detected: ' . $file_path );
			return;
		}

		$json_content = wp_json_encode( $filtered, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		if ( false === $json_content ) {
			error_log( 'DBVC: Failed to encode options JSON' );
			return;
		}

        $result = file_put_contents( $file_path, $json_content );
		if ( false === $result ) {
			error_log( 'DBVC: Failed to write options file: ' . $file_path );
			return;
		}
        
        // Allow other plugins to perform additional actions after options export
        do_action( 'dbvc_after_export_options', $file_path, $filtered );
    }

	/**
	 * Sanitize post meta data.
	 * 
	 * @param array $meta_data Raw meta data.
	 * 
	 * @since  1.0.0
	 * @return array Sanitized meta data.
	 */
	private static function sanitize_post_meta( $meta_data ) {
	$sanitized = [];

	// Define keywords that suggest the value may contain HTML
	$allow_html_if_key_contains = [
		'section',
		'description',
		'wysiwyg',
		'text',
		'textarea',
		'details',
		'content',
		'info',
        'header',
	];

	foreach ( $meta_data as $key => $values ) {
		$key = sanitize_text_field( $key );
		$sanitized[$key] = [];

		foreach ( $values as $value ) {
			if ( is_serialized( $value ) ) {
				$unserialized = maybe_unserialize( $value );
				$sanitized[ $key ][] = dbvc_sanitize_json_data( $unserialized );
			} else {
				// Check if key contains any of the wildcard keywords
				$allow_html = false;
				foreach ( $allow_html_if_key_contains as $match ) {
					if ( stripos( $key, $match ) !== false ) {
						$allow_html = true;
						break;
					}
				}

				if ( $allow_html ) {
					$sanitized[$key][] = wp_kses_post( $value );
				} else {
					$sanitized[$key][] = sanitize_textarea_field( $value );
				}
			}
		}
	}

	return $sanitized;
}


	/**
	 * Sanitize options data.
	 * 
	 * @param array $options_data Raw options data.
	 * 
	 * @since  1.0.0
	 * @return array Sanitized options data.
	 */
	private static function sanitize_options_data( $options_data ) {
		$sanitized = [];
		
		foreach ( $options_data as $key => $value ) {
			$key = sanitize_text_field( $key );
			
			if ( is_serialized( $value ) ) {
				$unserialized = maybe_unserialize( $value );
				$sanitized[ $key ] = dbvc_sanitize_json_data( $unserialized );
			} else {
				$sanitized[ $key ] = dbvc_sanitize_json_data( $value );
			}
		}
		
		return $sanitized;
	}

    /**
     * Import options from JSON file.
     * 
     * @since  1.0.0
     * @return void
     */
    public static function import_options_from_json() {
        $file_path = dbvc_get_sync_path() . 'options.json';
        if ( ! file_exists( $file_path ) ) {
            return;
        }

        $options = json_decode( file_get_contents( $file_path ), true );
        if ( empty( $options ) ) {
            return;
        }

        foreach ( $options as $key => $value ) {
            update_option( $key, maybe_unserialize( $value ) );
        }
    }

    /**
     * Export all menus to JSON file.
     * 
     * @since  1.0.0
     * @return void
     */
    public static function export_menus_to_json() {
    $menus = wp_get_nav_menus();
    $data  = [];

    foreach ( $menus as $menu ) {
        $items = wp_get_nav_menu_items( $menu->term_id, [ 'post_status' => 'any' ] );
        $formatted_items = [];

        foreach ( $items as $item ) {
            $post_array = get_post( $item->ID, ARRAY_A );
            $meta       = get_post_meta( $item->ID );
            $original_id = isset( $meta['_dbvc_original_id'][0] ) ? (int) $meta['_dbvc_original_id'][0] : (int) $item->object_id;

            $post_array['db_id']            = $item->db_id;
            $post_array['menu_item_parent'] = $item->menu_item_parent;
            $post_array['object_id']        = $item->object_id;
            $post_array['object']           = $item->object;
            $post_array['type']             = $item->type;
            $post_array['type_label']       = $item->type_label;
            $post_array['title']            = $item->title;
            $post_array['url']              = $item->url;
            $post_array['target']           = $item->target;
            $post_array['attr_title']       = $item->attr_title;
            $post_array['description']      = $item->description;
			$post_array['classes'] = is_array( $item->classes ) ? $item->classes : explode( ' ', $item->classes );
            $post_array['xfn']              = $item->xfn;
            $post_array['original_id']      = $original_id;
            $post_array['meta']             = $meta;

            $formatted_items[] = $post_array;
        }

        $menu_data = [
            'name'      => $menu->name,
            'slug'      => $menu->slug,
            'locations' => array_keys(
                array_filter(
                    get_nav_menu_locations(),
                    fn( $id ) => $id === $menu->term_id
                )
            ),
            'items'     => $formatted_items,
        ];

        $data[] = $menu_data;
    }

    $path = dbvc_get_sync_path();
    if ( ! is_dir( $path ) ) {
        wp_mkdir_p( $path );
    }

    file_put_contents(
        $path . 'menus.json',
        wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
    );

    error_log( '[DBVC] Exported menus to menus.json' );
}




    /**
     * Import menus from JSON file.
     * 
     * @since  1.0.0
     * @return void
     */
    public static function import_menus_from_json() {
    $file = dbvc_get_sync_path() . 'menus.json';
    if ( ! file_exists( $file ) ) {
        error_log( '[DBVC] Menus JSON file not found at: ' . $file );
        return;
    }

    $menus = json_decode( file_get_contents( $file ), true );
    if ( ! is_array( $menus ) ) {
        error_log( '[DBVC] Invalid JSON format in menus.json' );
        return;
    }

    global $wpdb;

    // Build post ID map for remapping referenced objects
    $post_map = [];
    $imported_ids = $wpdb->get_results(
        "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_dbvc_original_id'"
    );
    foreach ( $imported_ids as $row ) {
        $post_map[ $row->meta_value ] = $row->post_id;
    }

    foreach ( $menus as $menu_data ) {
        if ( ! isset( $menu_data['name'] ) || ! is_array( $menu_data['items'] ?? null ) ) {
            error_log( '[DBVC] Skipped invalid menu structure.' );
            continue;
        }

        $existing_menu = wp_get_nav_menu_object( $menu_data['name'] );
        $menu_id = $existing_menu ? $existing_menu->term_id : wp_create_nav_menu( $menu_data['name'] );

        if ( is_wp_error( $menu_id ) ) {
            error_log( '[DBVC] Failed to create/reuse menu "' . $menu_data['name'] . '": ' . $menu_id->get_error_message() );
            continue;
        }

        if ( $existing_menu ) {
            $old_items = wp_get_nav_menu_items( $menu_id );
            if ( $old_items ) {
                foreach ( $old_items as $old_item ) {
                    wp_delete_post( $old_item->ID, true );
                }
                error_log( "[DBVC] Cleared existing menu items for '{$menu_data['name']}'" );
            }
        }

        $created_items = [];     // old_id => new_id
        $pending_parents = [];   // child => original parent

        foreach ( $menu_data['items'] as $item ) {
            $original_id = (int)( $item['db_id'] ?? 0 );
            $object_id   = (int)( $item['object_id'] ?? 0 );
            $type        = $item['type'] ?? '';
            $object      = $item['object'] ?? '';

            $mapped_object_id = 0;

            // Handle only post_type or taxonomy object ID mapping
            if ( in_array( $type, ['post_type', 'taxonomy'], true ) ) {
                $mapped_object_id = $post_map[ $object_id ] ?? $object_id;
                if ( ! get_post_status( $mapped_object_id ) && $type === 'post_type' ) {
                    error_log( "[DBVC] Skipping menu item due to missing post object ID: $mapped_object_id" );
                    continue;
                }
            }

            $classes = is_array( $item['classes'] ) ? implode( ' ', $item['classes'] ) : (string) $item['classes'];

            $item_args = [
                'menu-item-title'      => $item['title'] ?? '',
                'menu-item-object'     => $object,
                'menu-item-object-id'  => $mapped_object_id,
                'menu-item-type'       => $type,
                'menu-item-status'     => 'publish',
                'menu-item-url'        => $item['url'] ?? '',
                'menu-item-classes'    => $classes,
                'menu-item-xfn'        => $item['xfn'] ?? '',
                'menu-item-target'     => $item['target'] ?? '',
                'menu-item-attr-title' => $item['attr_title'] ?? '',
                'menu-item-description'=> $item['description'] ?? '',
                'menu-item-position'   => $item['menu_order'] ?? 0,
                'menu-item-parent-id'  => 0,
            ];

            $item_id = wp_update_nav_menu_item( $menu_id, 0, $item_args );

if ( is_wp_error( $item_id ) ) {
    error_log( '[DBVC] Failed to add menu item "' . $item['title'] . '": ' . $item_id->get_error_message() );
    continue;
}

$created_items[ $original_id ] = $item_id;
update_post_meta( $item_id, '_dbvc_original_id', $original_id );

// ✅ Restore all other meta fields
if ( ! empty( $item['meta'] ) && is_array( $item['meta'] ) ) {
    foreach ( $item['meta'] as $meta_key => $meta_values ) {
        if ( $meta_key === '_dbvc_original_id' ) {
            continue;
        }
        delete_post_meta( $item_id, $meta_key );
        foreach ( $meta_values as $meta_value ) {
            add_post_meta( $item_id, $meta_key, maybe_unserialize( $meta_value ) );
        }
    }
}

if ( ! empty( $item['menu_item_parent'] ) ) {
    $pending_parents[] = [
        'child_id'           => $item_id,
        'original_parent_id' => $item['menu_item_parent'],
    ];
}

            error_log( '[DBVC] Imported menu item ID: ' . $item_id );
        }

        foreach ( $pending_parents as $pending ) {
            $child_id  = $pending['child_id'];
            $parent_id = $created_items[ $pending['original_parent_id'] ] ?? 0;

            if ( $parent_id ) {
                wp_update_post( [
                    'ID'          => $child_id,
                    'post_parent' => $parent_id,
                ] );
                update_post_meta( $child_id, '_menu_item_menu_item_parent', $parent_id );
                error_log( "[DBVC] Set parent for menu item ID $child_id to $parent_id" );
            }
        }

        if ( isset( $menu_data['locations'] ) && is_array( $menu_data['locations'] ) ) {
            $locations = get_nav_menu_locations();
            foreach ( $menu_data['locations'] as $loc ) {
                $locations[ $loc ] = $menu_id;
                error_log( '[DBVC] Set menu "' . $menu_data['name'] . '" to location "' . $loc . '"' );
            }
            set_theme_mod( 'nav_menu_locations', $locations );
        }
    }
}





    /**
     * Export posts in batches for better performance.
     * 
     * @param int $batch_size Number of posts to process per batch.
     * @param int $offset     Starting offset for the batch.
     * 
     * @since  1.0.0
     * @return array Results with processed count and remaining count.
     */
    public static function export_posts_batch( $batch_size = 50, $offset = 0 ) {
        $supported_types = self::get_supported_post_types();
        
        $posts = get_posts( [
            'post_type'      => $supported_types,
            'posts_per_page' => $batch_size,
            'offset'         => $offset,
            'post_status'    => 'any',
        ] );
        
        $processed = 0;
        foreach ( $posts as $post ) {
            self::export_post_to_json( $post->ID, $post );
            $processed++;
        }
        
        // Get total count for progress tracking
        $total_posts = self::wp_count_posts_by_type( $supported_types );
        $remaining = max( 0, $total_posts - ( $offset + $processed ) );
        
        return [
            'processed' => $processed,
            'remaining' => $remaining,
            'total'     => $total_posts,
            'offset'    => $offset + $processed,
        ];
    }

    /**
     * Import posts in batches for better performance.
     * 
     * @param int $batch_size Number of files to process per batch.
     * @param int $offset     Starting offset for the batch.
     * 
     * @since  1.0.0
     * @return array Results with processed count and remaining count.
     */
    public static function import_posts_batch( $batch_size = 50, $offset = 0 ) {
        $supported_types = self::get_supported_post_types();
        $all_files = [];
        
        // Collect all JSON files from all post type directories
        foreach ( $supported_types as $post_type ) {
            $path = dbvc_get_sync_path( $post_type );
            $files = glob( $path . '*.json' );
            if ( ! empty( $files ) ) {
                $all_files = array_merge( $all_files, $files );
            }
        }
        
        // Process batch
        $batch_files = array_slice( $all_files, $offset, $batch_size );
        $processed = 0;
        
        foreach ( $batch_files as $file ) {
            $json = json_decode( file_get_contents( $file ), true );
            if ( empty( $json ) ) {
                continue;
            }
            
            // Validate required fields
            if ( ! isset( $json['ID'], $json['post_type'], $json['post_title'] ) ) {
                continue;
            }
            
            $post_id = wp_insert_post( [
                'ID'           => absint( $json['ID'] ),
                'post_title'   => sanitize_text_field( $json['post_title'] ),
                'post_content' => wp_kses_post( $json['post_content'] ?? '' ),
                'post_excerpt' => sanitize_textarea_field( $json['post_excerpt'] ?? '' ),
                'post_type'    => sanitize_text_field( $json['post_type'] ),
                'post_status'  => sanitize_text_field( $json['post_status'] ?? 'draft' ),
            ] );
            
            if ( ! is_wp_error( $post_id ) && isset( $json['meta'] ) && is_array( $json['meta'] ) ) {
                foreach ( $json['meta'] as $key => $values ) {
                    if ( is_array( $values ) ) {
                        foreach ( $values as $value ) {
                            update_post_meta( $post_id, sanitize_text_field( $key ), maybe_unserialize( $value ) );
                        }
                    }
                }
            }
            
            $processed++;
        }
        
        $total_files = count( $all_files );
        $remaining = max( 0, $total_files - ( $offset + $processed ) );
        
        return [
            'processed' => $processed,
            'remaining' => $remaining,
            'total'     => $total_files,
            'offset'    => $offset + $processed,
        ];
    }

    /**
     * Get total count of posts for all supported post types.
     * 
     * @param array $post_types Post types to count.
     * 
     * @since  1.0.0
     * @return int Total post count.
     */
    private static function wp_count_posts_by_type( $post_types ) {
        $total = 0;
        
        foreach ( $post_types as $post_type ) {
            $counts = wp_count_posts( $post_type );
            if ( $counts ) {
                foreach ( $counts as $status => $count ) {
                    $total += $count;
                }
            }
        }
        
        return $total;
    }

    /**
     * Export FSE theme data to JSON.
     * 
     * @since  1.1.0
     * @return void
     */
	public static function export_fse_theme_data() {
		// Check if WordPress is fully loaded.
		if ( ! did_action( 'wp_loaded' ) ) {
			return;
		}

        $existing = get_post( absint( $json['ID'] ) );

        if ( $smart_import && $existing ) {
            $hash_key = '_dbvc_import_hash';
            $new_hash = md5( serialize( [ $json['post_content'], $json['meta'] ?? [] ] ) );
            $existing_hash = get_post_meta( $existing->ID, $hash_key, true );

            if ( $new_hash === $existing_hash ) {
                return; // Skip unchanged post
            }
        }


		if ( ! wp_is_block_theme() ) {
			return;
		}

		// Skip during admin page loads to prevent conflicts.
		if ( is_admin() && ! wp_doing_ajax() && ! defined( 'WP_CLI' ) ) {
			return;
		}

		// Check user capabilities for FSE export (skip for WP-CLI).
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			if ( ! current_user_can( 'edit_theme_options' ) ) {
				return;
			}
		}

		$theme_data = [
			'theme_name' => get_stylesheet(),
			'custom_css' => wp_get_custom_css(),
		];

		// Safely get theme JSON data - only if the system is ready.
		if ( class_exists( 'WP_Theme_JSON_Resolver' ) ) {
			try {
				// Additional check to ensure the theme JSON system is initialized.
				if ( did_action( 'init' ) && ! is_admin() ) {
					$theme_json_resolver = WP_Theme_JSON_Resolver::get_merged_data();
					if ( $theme_json_resolver && method_exists( $theme_json_resolver, 'get_raw_data' ) ) {
						$theme_data['theme_json'] = $theme_json_resolver->get_raw_data();
					} else {
						$theme_data['theme_json'] = [];
					}
				} else {
					// Skip theme JSON during admin loads.
					$theme_data['theme_json'] = [];
				}
			} catch ( Exception $e ) {
				error_log( 'DBVC: Failed to get theme JSON data: ' . $e->getMessage() );
				$theme_data['theme_json'] = [];
			} catch ( Error $e ) {
				error_log( 'DBVC: Fatal error getting theme JSON data: ' . $e->getMessage() );
				$theme_data['theme_json'] = [];
			}
		} else {
			$theme_data['theme_json'] = [];
		}

		// Allow other plugins to modify FSE theme data.
		$theme_data = apply_filters( 'dbvc_export_fse_theme_data', $theme_data );

		$path = dbvc_get_sync_path( 'theme' );
		if ( ! is_dir( $path ) ) {
			if ( ! wp_mkdir_p( $path ) ) {
				error_log( 'DBVC: Failed to create theme directory: ' . $path );
				return;
			}
		}

		$file_path = $path . 'theme-data.json';
		
		// Allow other plugins to modify the FSE theme file path.
		$file_path = apply_filters( 'dbvc_export_fse_theme_file_path', $file_path );
		
		// Validate file path.
		if ( ! dbvc_is_safe_file_path( $file_path ) ) {
			error_log( 'DBVC: Unsafe file path detected: ' . $file_path );
			return;
		}

		$json_content = wp_json_encode( $theme_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		if ( false === $json_content ) {
			error_log( 'DBVC: Failed to encode FSE theme JSON' );
			return;
		}

		$result = file_put_contents( $file_path, $json_content );
		if ( false === $result ) {
			error_log( 'DBVC: Failed to write FSE theme file: ' . $file_path );
			return;
		}

		do_action( 'dbvc_after_export_fse_theme_data', $file_path, $theme_data );
	}

	/**
	 * Import FSE theme data from JSON.
	 * 
	 * @since  1.1.0
	 * @return void
	 */
	public static function import_fse_theme_data() {
		// Check user capabilities for FSE import.
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return;
		}

		$file_path = dbvc_get_sync_path( 'theme' ) . 'theme-data.json';
		if ( ! file_exists( $file_path ) ) {
			return;
		}

		$theme_data = json_decode( file_get_contents( $file_path ), true );
		if ( empty( $theme_data ) ) {
			return;
		}

		// Import custom CSS.
		if ( isset( $theme_data['custom_css'] ) && ! empty( $theme_data['custom_css'] ) ) {
			wp_update_custom_css_post( $theme_data['custom_css'] );
		}

		// Allow other plugins to handle additional FSE import data.
		do_action( 'dbvc_after_import_fse_theme_data', $theme_data );
	}



}
