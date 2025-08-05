<?php
/**
 * Get the sync path for exports
 * 
 * @package   DB Version Control
 * @author    Robert DeVore <me@robertdevore.com>
 * @since     1.0.0
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
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'dbvc', 'DBVC_WP_CLI_Commands' );
}

class DBVC_WP_CLI_Commands {

	/**
	 * Export all posts to JSON.
	 *
	 * ## OPTIONS
	 * 
	 * [--batch-size=<number>]
	 * : Number of posts to process per batch. Use 0 to disable batching. Default: 50
	 *
	 * ## EXAMPLES
	 * wp dbvc export
	 * wp dbvc export --batch-size=100
	 * wp dbvc export --batch-size=0
     * 
     * @since  1.0.0
     * @return void
	 */
	public function export( $args, $assoc_args ) {
		$batch_size = isset( $assoc_args['batch-size'] ) ? absint( $assoc_args['batch-size'] ) : 50;
		$no_batch = ( 0 === $batch_size );
		
		// Export options and menus first (these are typically small)
        DBVC_Sync_Posts::export_options_to_json();
        DBVC_Sync_Posts::export_menus_to_json();
        
        if ( $no_batch ) {
			// Legacy behavior - export all at once.
			$post_types = DBVC_Sync_Posts::get_supported_post_types();
			$posts = get_posts( [
				'post_type'      => $post_types,
				'posts_per_page' => -1,
				'post_status'    => 'any',
			] );

			WP_CLI::log( "Exporting all posts at once (no batching)" );

			foreach ( $posts as $post ) {
				DBVC_Sync_Posts::export_post_to_json( $post->ID, $post );
			}
			
			WP_CLI::success( sprintf( 'All %d posts exported to JSON. Post types: %s', count( $posts ), implode( ', ', $post_types ) ) );
		} else {
			// Batch processing
			$offset = 0;
			$total_processed = 0;
			
			WP_CLI::log( "Starting batch export with batch size: {$batch_size}" );
			
			do {
				$result = DBVC_Sync_Posts::export_posts_batch( $batch_size, $offset );
				$total_processed += $result['processed'];
				$offset = $result['offset'];
				
				if ( $result['processed'] > 0 ) {
					WP_CLI::log( sprintf( 
						'Processed batch: %d posts | Total: %d/%d | Remaining: %d',
						$result['processed'],
						$total_processed,
						$result['total'],
						$result['remaining']
					) );
				}
				
				// Small delay to prevent overwhelming the server
				if ( $result['remaining'] > 0 ) {
					usleep( 100000 ); // 0.1 second
				}
				
			} while ( $result['remaining'] > 0 && $result['processed'] > 0 );
			
			$post_types = DBVC_Sync_Posts::get_supported_post_types();
			WP_CLI::success( sprintf( 
				'Batch export completed! Processed %d posts across post types: %s', 
				$total_processed,
				implode( ', ', $post_types )
			) );
		}
	}

	/**
	 * Import all JSON files into DB.
	 *
	 * ## OPTIONS
	 * 
	 * [--batch-size=<number>]
	 * : Number of files to process per batch. Use 0 to disable batching. Default: 50
	 *
	 * ## EXAMPLES
	 * wp dbvc import
	 * wp dbvc import --batch-size=25
	 * wp dbvc import --batch-size=0
     * 
     * @since  1.0.0
     * @return void
	 */
	public function import( $args, $assoc_args ) {
		WP_CLI::warning( 'This will overwrite existing data. Make sure you have a backup.' );
		
		$batch_size = isset( $assoc_args['batch-size'] ) ? absint( $assoc_args['batch-size'] ) : 50;
		$no_batch = ( 0 === $batch_size );
		
		// Import options and menus first
        DBVC_Sync_Posts::import_options_from_json();
        DBVC_Sync_Posts::import_menus_from_json();
        
        if ( $no_batch ) {
			// Legacy behavior - import all at once
			WP_CLI::log( "Importing all files at once (no batching)" );
			DBVC_Sync_Posts::import_all_json_files();
			WP_CLI::success( 'All JSON files imported to DB.' );
		} else {
			// Batch processing
			$offset = 0;
			$total_processed = 0;
			
			WP_CLI::log( "Starting batch import with batch size: {$batch_size}" );
			
			do {
				$result = DBVC_Sync_Posts::import_posts_batch( $batch_size, $offset );
				$total_processed += $result['processed'];
				$offset = $result['offset'];
				
				if ( $result['processed'] > 0 ) {
					WP_CLI::log( sprintf( 
						'Processed batch: %d files | Total: %d/%d | Remaining: %d',
						$result['processed'],
						$total_processed,
						$result['total'],
						$result['remaining']
					) );
				}
				
				// Small delay to prevent overwhelming the database
				if ( $result['remaining'] > 0 ) {
					usleep( 250000 ); // 0.25 second (imports are more intensive)
				}
				
			} while ( $result['remaining'] > 0 && $result['processed'] > 0 );
			
			WP_CLI::success( sprintf( 
				'Batch import completed! Processed %d files.',
				$total_processed
			) );
		}
	}
}
