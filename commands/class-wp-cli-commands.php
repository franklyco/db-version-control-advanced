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
	 * [--chunk-size=<number>]
	 * : Run export in resumable chunks of this size.
	 *
	 * [--job-id=<number>]
	 * : Resume a previously created chunked export job.
	 *
	 * [--baseline=<id|latest>]
	 * : Export only posts changed since the specified snapshot ID (use "latest" for the most recent full export).
	 *
	 * ## EXAMPLES
	 * wp dbvc export
	 * wp dbvc export --batch-size=100
	 * wp dbvc export --batch-size=0
	 * wp dbvc export --baseline=123
	 * wp dbvc export --baseline=latest
     * 
     * @since  1.0.0
     * @return void
	 */
	public function export( $args, $assoc_args ) {
		$batch_size  = isset( $assoc_args['batch-size'] ) ? absint( $assoc_args['batch-size'] ) : 50;
		$no_batch    = ( 0 === $batch_size );
		$import_mode = function_exists( 'dbvc_get_import_filename_format' )
			? dbvc_get_import_filename_format()
			: dbvc_get_export_filename_format();
		
		// Export options and menus first (these are typically small)
        DBVC_Sync_Posts::export_options_to_json();
        DBVC_Sync_Posts::export_menus_to_json();
        if ( class_exists( 'DBVC_Sync_Taxonomies' ) ) {
            DBVC_Sync_Taxonomies::export_selected_taxonomies();
        }

        $chunk_size = isset( $assoc_args['chunk-size'] ) ? absint( $assoc_args['chunk-size'] ) : 0;
        if ( $chunk_size > 0 ) {
            if ( isset( $assoc_args['baseline'] ) ) {
                WP_CLI::warning( 'Ignoring --baseline flag while running chunked export.' );
            }
            $this->export_chunked( $chunk_size, $assoc_args );
            return;
        }

        if ( isset( $assoc_args['baseline'] ) ) {
            $baseline_arg = $assoc_args['baseline'];
            $baseline_id  = null;
            if ( 'latest' !== $baseline_arg ) {
                $baseline_id = absint( $baseline_arg );
            }

            WP_CLI::log( 'Running diff export...' );
            $result = DBVC_Sync_Posts::export_posts_diff( $baseline_id, $import_mode );
            if ( is_wp_error( $result ) ) {
                WP_CLI::error( $result->get_error_message() );
                return;
            }

            $counts = $result['counts'];
            WP_CLI::success(
                sprintf(
                    'Diff export completed. Created: %d, Updated: %d, Unchanged: %d. Snapshot ID: %d',
                    (int) ($counts['created'] ?? 0),
                    (int) ($counts['updated'] ?? 0),
                    (int) ($counts['unchanged'] ?? 0),
                    (int) ($result['snapshot_id'] ?? 0)
                )
            );
            return;
        }
		
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
		
		$batch_size  = isset( $assoc_args['batch-size'] ) ? absint( $assoc_args['batch-size'] ) : 50;
		$no_batch    = ( 0 === $batch_size );
		$import_mode = function_exists( 'dbvc_get_import_filename_format' )
			? dbvc_get_import_filename_format()
			: dbvc_get_export_filename_format();
		
		// Import options and menus first
        DBVC_Sync_Posts::import_options_from_json();
        DBVC_Sync_Posts::import_menus_from_json();
        if ( class_exists( 'DBVC_Sync_Taxonomies' ) ) {
            DBVC_Sync_Taxonomies::import_taxonomies();
        }
        
        $import_success_message = '';
        $processed_total         = null;

        if ( $no_batch ) {
			// Legacy behavior - import all at once
			WP_CLI::log( "Importing all files at once (no batching)" );
			$import_result = DBVC_Sync_Posts::import_all_json_files( $import_mode );
			if ( is_array( $import_result ) ) {
				$processed_total = isset( $import_result['processed'] ) ? (int) $import_result['processed'] : null;
			}
			$import_success_message = __( 'All JSON files imported to DB.', 'dbvc' );
		} else {
			// Batch processing
			$offset = 0;
			$total_processed = 0;
			
			WP_CLI::log( "Starting batch import with batch size: {$batch_size}" );
			
			do {
				$result = DBVC_Sync_Posts::import_posts_batch( $batch_size, $offset, $import_mode );
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
			
			$import_success_message = sprintf(
				/* translators: %d: number of processed files */
				__( 'Batch import completed! Processed %d files.', 'dbvc' ),
				$total_processed
			);

			$processed_total = $total_processed;
		}

		$media_stats = $this->maybe_sync_media_after_import();

		if ( class_exists( 'DBVC_Sync_Logger' ) && DBVC_Sync_Logger::is_import_logging_enabled() ) {
			DBVC_Sync_Logger::log_import( 'WP-CLI import completed', [
				'mode'             => $no_batch ? 'all' : 'batch',
				'processed'        => $processed_total,
				'batch_size'       => $no_batch ? null : $batch_size,
				'media_run'        => class_exists( 'DBVC_Media_Sync' ) ? DBVC_Media_Sync::is_enabled() : false,
				'media_downloaded' => is_array( $media_stats ) ? (int) ( $media_stats['downloaded'] ?? 0 ) : null,
				'media_errors'     => is_array( $media_stats ) ? (int) ( $media_stats['errors'] ?? 0 ) : null,
			] );
		}

		if ( class_exists( 'DBVC_Database' ) ) {
			$snapshot_id = DBVC_Database::insert_snapshot( [
				'type'      => 'cli_import',
				'sync_path' => function_exists( 'dbvc_get_sync_path' ) ? dbvc_get_sync_path() : '',
				'notes'     => wp_json_encode( [
					'mode'             => $no_batch ? 'all' : 'batch',
					'processed'        => $processed_total,
					'batch_size'       => $no_batch ? null : $batch_size,
					'media_stats'      => $media_stats,
					'timestamp'        => current_time( 'mysql', true ),
					'source'           => 'cli',
				] ),
			] );

			DBVC_Database::log_activity(
				'cli_import_completed',
				'info',
				'WP-CLI import completed',
				[
					'snapshot_id'    => $snapshot_id,
					'mode'           => $no_batch ? 'all' : 'batch',
					'processed'      => $processed_total,
					'batch_size'     => $no_batch ? null : $batch_size,
					'media_stats'    => $media_stats,
				]
			);
		}

		if ( $import_success_message ) {
			WP_CLI::success( $import_success_message );
		}
	}

	/**
	 * Run media synchronization via manifest if enabled.
	 *
	 * @return array|null Statistics array when media sync runs.
	 */
	private function maybe_sync_media_after_import() {
		if (
			! class_exists( 'DBVC_Media_Sync' )
			|| ! class_exists( 'DBVC_Backup_Manager' )
			|| ! function_exists( 'dbvc_get_sync_path' )
			|| ! DBVC_Media_Sync::is_enabled()
		) {
			return null;
		}

		$manifest_path = trailingslashit( dbvc_get_sync_path() ) . DBVC_Backup_Manager::MANIFEST_FILENAME;

		if ( ! file_exists( $manifest_path ) || ! is_readable( $manifest_path ) ) {
			WP_CLI::log( __( 'Media sync skipped: manifest.json not found in the sync directory.', 'dbvc' ) );
			return null;
		}

		$manifest_raw  = file_get_contents( $manifest_path );
		$manifest_data = json_decode( $manifest_raw, true );

		if ( ! is_array( $manifest_data ) ) {
			WP_CLI::warning( __( 'Media sync skipped: manifest.json could not be decoded.', 'dbvc' ) );
			return null;
		}

		$proposal_id = isset( $manifest_data['backup_name'] ) ? $manifest_data['backup_name'] : 'cli';
		DBVC_Sync_Posts::import_resolver_decisions_from_manifest( $manifest_data, sanitize_text_field( (string) $proposal_id ) );

		$media_stats = DBVC_Media_Sync::sync_manifest_media( $manifest_data, [
			'proposal_id' => $manifest_data['backup_name'] ?? ( $manifest_data['generated_at'] ?? 'cli' ),
		] );
		if ( ! is_array( $media_stats ) ) {
			return null;
		}

		$summary = [];

		if ( ! empty( $media_stats['downloaded'] ) ) {
			$summary[] = sprintf(
				/* translators: %d: number of downloaded media files */
				__( '%d media downloaded', 'dbvc' ),
				(int) $media_stats['downloaded']
			);
		}
		if ( ! empty( $media_stats['reused'] ) ) {
			$summary[] = sprintf(
				/* translators: %d: number of media files reused */
				__( '%d media reused', 'dbvc' ),
				(int) $media_stats['reused']
			);
		}

		$resolver_summary = [];
		if ( ! empty( $media_stats['resolver']['metrics']['reused'] ) ) {
			$resolver_summary[] = sprintf(
				/* translators: %d: number of media resolved via resolver */
				__( '%d resolved via resolver', 'dbvc' ),
				(int) $media_stats['resolver']['metrics']['reused']
			);
		}
		if ( isset( $media_stats['resolver']['metrics']['unresolved'] ) && $media_stats['resolver']['metrics']['unresolved'] > 0 ) {
			$resolver_summary[] = sprintf(
				/* translators: %d: number of media unresolved by resolver */
				__( '%d unresolved by resolver', 'dbvc' ),
				(int) $media_stats['resolver']['metrics']['unresolved']
			);
		}
		if ( ! empty( $media_stats['resolver']['conflicts'] ) ) {
			$resolver_summary[] = sprintf(
				/* translators: %d: number of resolver conflicts */
				__( '%d resolver conflicts', 'dbvc' ),
				count( (array) $media_stats['resolver']['conflicts'] )
			);
		}

		if ( ! empty( $summary ) ) {
			WP_CLI::log(
				sprintf(
					__( 'Media sync: %s', 'dbvc' ),
					implode( ', ', $summary )
				)
			);
		} else {
			WP_CLI::log( __( 'Media sync: no downloads required.', 'dbvc' ) );
		}

		if ( ! empty( $resolver_summary ) ) {
			WP_CLI::log(
				sprintf(
					__( 'Media resolver: %s', 'dbvc' ),
					implode( ', ', $resolver_summary )
				)
			);
		}

		if ( ! empty( $media_stats['errors'] ) ) {
			WP_CLI::warning(
				sprintf(
					/* translators: %d: number of failed media downloads */
					__( '%d media downloads failed. Check the DBVC log for details.', 'dbvc' ),
					(int) $media_stats['errors']
				)
			);
		}

		if ( ! empty( $media_stats['blocked'] ) ) {
			WP_CLI::warning(
				sprintf(
					/* translators: %d: number of blocked media downloads */
					__( '%d media sources were blocked by current download restrictions.', 'dbvc' ),
					(int) $media_stats['blocked']
				)
			);
		}

		if (
			isset( $media_stats['resolver']['metrics']['unresolved'] )
			&& (int) $media_stats['resolver']['metrics']['unresolved'] > 0
		) {
			WP_CLI::warning(
				sprintf(
					/* translators: %d: number of unresolved media after resolver */
					__( '%d media remain unresolved after resolver pass.', 'dbvc' ),
					(int) $media_stats['resolver']['metrics']['unresolved']
				)
			);
		}

		if ( ! empty( $media_stats['resolver']['conflicts'] ) ) {
			WP_CLI::warning(
				sprintf(
					/* translators: %d: number of resolver conflicts detected */
					__( '%d resolver conflicts detected. Review before applying.', 'dbvc' ),
					count( (array) $media_stats['resolver']['conflicts'] )
				)
			);
		}

		return $media_stats;
	}

	/**
	 * Execute a chunked export pass with job tracking.
	 *
	 * @param int   $chunk_size
	 * @param array $assoc_args
	 * @return void
	 */
	private function export_chunked( $chunk_size, $assoc_args ) {
		if ( ! class_exists( 'DBVC_Database' ) ) {
			WP_CLI::error( 'Chunked exports require the DBVC database layer.' );
		}

		if ( $chunk_size <= 0 ) {
			WP_CLI::error( 'Chunk size must be greater than zero.' );
		}

		$job_id = isset( $assoc_args['job-id'] ) ? absint( $assoc_args['job-id'] ) : 0;
		$job    = null;
		$context = [];

		if ( $job_id ) {
			$job = DBVC_Database::get_job( $job_id );
			if ( ! $job ) {
				WP_CLI::error( sprintf( 'Job %d was not found.', $job_id ) );
			}
			if ( $job->job_type !== 'export_chunked' ) {
				WP_CLI::error( sprintf( 'Job %d is not an export job.', $job_id ) );
			}

			$context = $job->context ? json_decode( $job->context, true ) : [];
			if ( ! is_array( $context ) ) {
				$context = [];
			}

			if ( isset( $context['chunk_size'] ) && (int) $context['chunk_size'] !== $chunk_size ) {
				WP_CLI::log( sprintf( 'Overriding chunk size from %d to %d for this run.', (int) $context['chunk_size'], $chunk_size ) );
				$context['chunk_size'] = $chunk_size;
			} else {
				$context['chunk_size'] = $chunk_size;
			}

			if ( isset( $job->status ) && 'done' === $job->status ) {
				WP_CLI::success( sprintf( 'Job %d is already complete.', $job_id ) );
				return;
			}
		} else {
			$total = DBVC_Sync_Posts::get_total_export_post_count();
			if ( $total <= 0 ) {
				WP_CLI::success( 'No posts to export.' );
				return;
			}

			$context = [
				'offset'     => 0,
				'chunk_size' => $chunk_size,
				'total'      => $total,
				'processed'  => 0,
			];

			$job_id = DBVC_Database::create_job( 'export_chunked', $context, 'running' );
			DBVC_Database::log_activity(
				'export_chunk_job_created',
				'info',
				'Chunked export job created',
				[
					'job_id'     => $job_id,
					'total'      => $total,
					'chunk_size' => $chunk_size,
				]
			);

			WP_CLI::log( sprintf( 'Created export job %d for %d posts.', $job_id, $total ) );
		}

		$offset   = isset( $context['offset'] ) ? (int) $context['offset'] : 0;
		$total    = isset( $context['total'] ) ? (int) $context['total'] : DBVC_Sync_Posts::get_total_export_post_count();
		$processed_before = isset( $context['processed'] ) ? (int) $context['processed'] : 0;

		WP_CLI::log( sprintf( 'Processing chunk (job %d) starting at offset %d (chunk size %d)...', $job_id, $offset, $chunk_size ) );

		$result = DBVC_Sync_Posts::export_posts_batch( $chunk_size, $offset );

		$context['offset']    = $result['offset'];
		$context['processed'] = $processed_before + $result['processed'];
		$context['remaining'] = $result['remaining'];
		$context['total']     = $total > 0 ? $total : $result['total'];

		$progress = ($context['total'] > 0)
			? min( 1, $context['offset'] / $context['total'] )
			: 1;

		$status = $result['remaining'] > 0 ? 'running' : 'done';

		DBVC_Database::update_job( $job_id, [
			'status'   => $status,
			'progress' => $progress,
		], $context );

		DBVC_Database::log_activity(
			'export_chunk_processed',
			'info',
			'Chunk processed during export',
			[
				'job_id'    => $job_id,
				'processed' => $result['processed'],
				'offset'    => $context['offset'],
				'remaining' => $result['remaining'],
				'progress'  => $progress,
			]
		);

		if ( $result['processed'] > 0 ) {
			WP_CLI::log(
				sprintf(
					'Chunk complete. Processed %d posts this run. Progress: %d/%d.',
					$result['processed'],
					$context['offset'],
					$context['total']
				)
			);
		} else {
			WP_CLI::log( 'No posts processed in this chunk.' );
		}

		if ( 'done' === $status ) {
			if ( class_exists( 'DBVC_Backup_Manager' ) ) {
				DBVC_Backup_Manager::generate_manifest( dbvc_get_sync_path() );
			}

			$snapshot_id = null;
			if ( class_exists( 'DBVC_Database' ) ) {
				$snapshot_id = DBVC_Database::insert_snapshot([
					'type'      => 'chunked_export',
					'sync_path' => dbvc_get_sync_path(),
					'notes'     => wp_json_encode([
						'job_id'    => $job_id,
						'total'     => $context['total'],
						'processed' => $context['processed'],
						'timestamp' => current_time( 'mysql', true ),
					]),
				]);
			}

			DBVC_Database::log_activity(
				'export_chunk_completed',
				'success',
				'Chunked export completed',
				[
					'job_id'      => $job_id,
					'snapshot_id' => $snapshot_id,
					'total'       => $context['total'],
				]
			);

			WP_CLI::success( sprintf( 'Chunked export job %d completed. Manifest regenerated.', $job_id ) );
		} else {
			WP_CLI::log(
				sprintf(
					'Chunks remaining: %d posts left. Re-run with --job-id=%d to continue.',
					$result['remaining'],
					$job_id
				)
			);
		}
	}

	/**
	 * List stored DBVC snapshots.
	 *
	 * ## OPTIONS
	 *
	 * [--type=<type>]
	 * : Filter snapshots by type (e.g., full_export, diff_export, chunked_export, manual_import).
	 *
	 * [--limit=<number>]
	 * : Number of snapshots to list. Default: 20.
	 *
	 * [--offset=<number>]
	 * : Offset for pagination.
	 *
	 * ## EXAMPLES
	 *
	 * wp dbvc snapshots
	 * wp dbvc snapshots --type=full_export --limit=10
	 *
	 * @param array $args
	 * @param array $assoc_args
	 * @return void
	 */
	public function snapshots( $args, $assoc_args ) {
		if ( ! class_exists( 'DBVC_Database' ) ) {
			WP_CLI::error( 'Snapshot storage is not available.' );
		}

		$type   = isset( $assoc_args['type'] ) ? sanitize_key( $assoc_args['type'] ) : '';
		$limit  = isset( $assoc_args['limit'] ) ? absint( $assoc_args['limit'] ) : 20;
		$offset = isset( $assoc_args['offset'] ) ? absint( $assoc_args['offset'] ) : 0;

		$snapshots = DBVC_Database::get_snapshots( [
			'type'   => $type,
			'limit'  => $limit,
			'offset' => $offset,
		] );

		if ( empty( $snapshots ) ) {
			WP_CLI::log( 'No snapshots found.' );
			return;
		}

		$items = [];
		foreach ( $snapshots as $snapshot ) {
			$notes = [];
			if ( ! empty( $snapshot->notes ) ) {
				$decoded = json_decode( $snapshot->notes, true );
				if ( is_array( $decoded ) ) {
					$notes = $decoded;
				}
			}

			$counts_summary = '';
			if ( isset( $notes['counts'] ) && is_array( $notes['counts'] ) ) {
				$parts = [];
				foreach ( $notes['counts'] as $label => $value ) {
					$parts[] = "{$label}: {$value}";
				}
				$counts_summary = implode( ', ', $parts );
			} elseif ( isset( $notes['posts_exported'] ) ) {
				$counts_summary = 'posts_exported: ' . (int) $notes['posts_exported'];
			} elseif ( isset( $notes['posts_imported'] ) ) {
				$counts_summary = 'posts_imported: ' . (int) $notes['posts_imported'];
			}

			$user_name = '';
			if ( ! empty( $snapshot->initiated_by ) ) {
				$user = get_userdata( $snapshot->initiated_by );
				$user_name = $user ? $user->user_login : $snapshot->initiated_by;
			}

			$items[] = [
				'id'       => (int) $snapshot->id,
				'type'     => $snapshot->type,
				'created'  => $snapshot->created_at,
				'user'     => $user_name,
				'counts'   => $counts_summary,
				'source'   => isset( $notes['source'] ) ? $notes['source'] : '',
			];
		}

		\WP_CLI\Utils\format_items( 'table', $items, [ 'id', 'type', 'created', 'user', 'counts', 'source' ] );
	}
}
