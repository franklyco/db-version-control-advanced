<?php
namespace DBVC;

class DBVC_MenuImporter {
    /**
     * Import menus and restore hierarchy + meta.
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
        $post_map = [];
        $imported_ids = $wpdb->get_results(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_dbvc_original_id'"
        );
        foreach ( $imported_ids as $row ) {
            $post_map[ $row->meta_value ] = $row->post_id;
        }

        foreach ( $menus as $menu_data ) {
            if ( ! isset( $menu_data['name'] ) || ! is_array( $menu_data['items'] ?? null ) ) continue;

            $existing_menu = wp_get_nav_menu_object( $menu_data['name'] );
            $menu_id = $existing_menu ? $existing_menu->term_id : wp_create_nav_menu( $menu_data['name'] );

            if ( is_wp_error( $menu_id ) ) {
                error_log( '[DBVC] Failed to create/reuse menu "' . $menu_data['name'] . '"' );
                continue;
            }

            if ( $existing_menu ) {
                foreach ( wp_get_nav_menu_items( $menu_id ) as $old_item ) {
                    wp_delete_post( $old_item->ID, true );
                }
            }

            $id_map = [];
            $meta_map = [];
            foreach ( $menu_data['items'] as $item ) {
                $original_id = (int)( $item['ID'] ?? 0 );
                $object_id   = isset( $item['object_id'] ) ? (int)( $post_map[ $item['object_id'] ] ?? $item['object_id'] ) : 0;

                $new_id = wp_update_nav_menu_item( $menu_id, 0, [
                    'menu-item-title'      => $item['title'] ?? '',
                    'menu-item-object'     => $item['object'] ?? '',
                    'menu-item-object-id'  => $object_id,
                    'menu-item-type'       => $item['type'] ?? '',
                    'menu-item-status'     => 'publish',
                    'menu-item-url'        => $item['url'] ?? '',
                    'menu-item-attr-title' => $item['attr_title'] ?? '',
                    'menu-item-description'=> $item['description'] ?? '',
                    'menu-item-target'     => $item['target'] ?? '',
                    'menu-item-xfn'        => $item['xfn'] ?? '',
                    'menu-item-classes'    => implode( ' ', $item['classes'] ?? [] ),
                ]);

                if ( ! is_wp_error( $new_id ) ) {
                    $id_map[ $original_id ]  = $new_id;
                    $meta_map[ $new_id ] = $item['meta'] ?? [];
                }
            }

            foreach ( $menu_data['items'] as $item ) {
                $original_id = (int)( $item['ID'] ?? 0 );
                $parent_original_id = (int)( $item['menu_item_parent'] ?? 0 );

                if ( $parent_original_id && isset( $id_map[ $original_id ], $id_map[ $parent_original_id ] ) ) {
                    wp_update_post([
                        'ID'          => $id_map[ $original_id ],
                        'post_parent' => $id_map[ $parent_original_id ],
                    ]);
                }

                // Restore custom meta if available
                $new_id = $id_map[ $original_id ] ?? 0;
                if ( $new_id && ! empty( $meta_map[ $new_id ] ) ) {
                    foreach ( $meta_map[ $new_id ] as $key => $val ) {
                        update_post_meta( $new_id, $key, maybe_unserialize( $val ) );
                    }
                }
            }

            if ( isset( $menu_data['locations'] ) && is_array( $menu_data['locations'] ) ) {
                $locations = get_nav_menu_locations();
                foreach ( $menu_data['locations'] as $loc ) {
                    $locations[ $loc ] = $menu_id;
                }
                set_theme_mod( 'nav_menu_locations', $locations );
            }
        }
    }

    /**
     * Export menus and preserve all meta.
     */
    public static function export_menus_to_json() {
        $menus = wp_get_nav_menus();
        $data  = [];

        foreach ( $menus as $menu ) {
            $items = wp_get_nav_menu_items( $menu->term_id );
            $item_array = [];

            foreach ( $items as $item ) {
                $meta = get_post_meta( $item->ID );
                $flat_meta = [];
                foreach ( $meta as $k => $v ) {
                    $flat_meta[ $k ] = maybe_serialize( $v[0] ?? '' );
                }

                $arr = (array) $item;
                $arr['meta'] = $flat_meta;
                $item_array[] = $arr;
            }

            $menu_data = [
                'name'      => $menu->name,
                'slug'      => $menu->slug,
                'locations' => array_keys( array_filter( get_nav_menu_locations(), fn( $id ) => $id === $menu->term_id ) ),
                'items'     => $item_array,
            ];

            $data[] = apply_filters( 'dbvc_export_menu_data', $menu_data, $menu );
        }

        $data = apply_filters( 'dbvc_export_menus_data', $data );

        $path = dbvc_get_sync_path();
        if ( ! is_dir( $path ) ) wp_mkdir_p( $path );

        $file_path = apply_filters( 'dbvc_export_menus_file_path', $path . 'menus.json' );

        file_put_contents( $file_path, wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

        do_action( 'dbvc_after_export_menus', $file_path, $data );
    }
}
