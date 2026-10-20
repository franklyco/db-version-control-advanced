<?php

/**
 * Resolver-decision coverage for Proposal Diff media apply.
 *
 * @group proposal-diff
 * @group media
 */
class MediaResolverDecisionTest extends WP_UnitTestCase
{
    private $attachment_ids = [];
    private $temporary_directories = [];

    public function set_up(): void
    {
        parent::set_up();

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        delete_option('dbvc_resolver_decisions');
        DBVC_Media_Sync::cleanup();
        update_option(DBVC_Media_Sync::OPTION_ENABLED, '1');
        update_option(DBVC_Media_Sync::OPTION_ALLOW_EXTERNAL, '0');
        update_option(DBVC_Media_Sync::OPTION_TRANSPORT_MODE, 'auto');
    }

    public function tear_down(): void
    {
        foreach (array_unique($this->attachment_ids) as $attachment_id) {
            if (get_post($attachment_id)) {
                wp_delete_attachment($attachment_id, true);
            }
        }

        foreach (array_unique($this->temporary_directories) as $directory) {
            $this->delete_directory($directory);
        }

        delete_option('dbvc_resolver_decisions');
        DBVC_Media_Sync::cleanup();
        delete_option(DBVC_Media_Sync::OPTION_ENABLED);
        delete_option(DBVC_Media_Sync::OPTION_ALLOW_EXTERNAL);
        delete_option(DBVC_Media_Sync::OPTION_TRANSPORT_MODE);

        parent::tear_down();
    }

    public function test_effective_decisions_normalize_source_and_prefer_proposal_scope(): void
    {
        update_option('dbvc_resolver_decisions', [
            '__global' => [
                '44' => [
                    'action'    => 'map',
                    'target_id' => 91,
                ],
                '45' => [
                    'action' => 'download',
                ],
            ],
            'phase-4-precedence' => [
                '44' => [
                    'action' => 'skip',
                    'scope'  => 'global',
                ],
                '__summary' => ['total' => 1],
            ],
        ], false);

        $decisions = DBVC_Media_Sync::get_effective_resolver_decisions('phase-4-precedence');

        $this->assertSame('skip', $decisions['44']['action']);
        $this->assertSame('proposal', $decisions['44']['source']);
        $this->assertSame('proposal', $decisions['44']['scope']);
        $this->assertSame('download', $decisions['45']['action']);
        $this->assertSame('global', $decisions['45']['source']);
        $this->assertArrayNotHasKey('__summary', $decisions);
    }

    public function test_map_and_reuse_decisions_apply_valid_attachment_targets_once(): void
    {
        foreach (['map' => 51, 'reuse' => 52] as $action => $original_id) {
            DBVC_Media_Sync::cleanup();
            $target_id = $this->create_attachment($action . '-target');
            update_option('dbvc_resolver_decisions', [
                'phase-4-' . $action => [
                    (string) $original_id => [
                        'action'    => $action,
                        'target_id' => $target_id,
                    ],
                ],
            ], false);

            $stats = DBVC_Media_Sync::sync_manifest_media(
                $this->manifest('phase-4-' . $action, [
                    $this->media_entry($original_id, $action . '-missing.txt'),
                ]),
                ['proposal_id' => 'phase-4-' . $action]
            );

            $this->assertSame($target_id, DBVC_Media_Sync::get_local_id($original_id));
            $this->assertSame(1, $stats['resolver_decisions']['applied']);
            $this->assertSame(1, $stats['resolver_decisions'][$action]);
            $this->assertSame(0, $stats['resolver_decisions']['failed']);
            $this->assertSame('proposal', $stats['resolver_decisions']['results'][0]['source']);
            $this->assertSame(1, $stats['reused']);
        }
    }

    public function test_skip_decision_clears_cached_id_and_url_mappings(): void
    {
        $target_id = $this->create_attachment('stale-target');
        $source_url = home_url('/wp-content/uploads/phase-4-stale.txt');
        update_option(DBVC_Media_Sync::MAP_OPTION, [
            'map' => ['61' => $target_id],
        ], false);
        update_option(DBVC_Media_Sync::URL_OPTION, [
            'map' => [$source_url => $target_id],
        ], false);
        update_option('dbvc_resolver_decisions', [
            'phase-4-skip' => [
                '61' => ['action' => 'skip'],
            ],
        ], false);

        $stats = DBVC_Media_Sync::sync_manifest_media(
            $this->manifest('phase-4-skip', [
                $this->media_entry(61, 'phase-4-stale.txt', $source_url),
            ]),
            ['proposal_id' => 'phase-4-skip']
        );

        $this->assertNull(DBVC_Media_Sync::get_local_id(61));
        $this->assertSame(1, $stats['resolver_decisions']['skip']);
        $this->assertSame(0, $stats['resolver']['metrics']['unresolved']);
        $this->assertSame(1, $stats['resolver']['metrics']['skipped']);

        $stored_urls = get_option(DBVC_Media_Sync::URL_OPTION, []);
        $this->assertArrayNotHasKey($source_url, $stored_urls['map'] ?? []);
    }

    public function test_invalid_map_target_fails_without_silent_fallback(): void
    {
        $page_id = self::factory()->post->create([
            'post_type'   => 'page',
            'post_status' => 'publish',
        ]);
        update_option('dbvc_resolver_decisions', [
            'phase-4-invalid-map' => [
                '71' => [
                    'action'    => 'map',
                    'target_id' => $page_id,
                ],
            ],
        ], false);

        $stats = DBVC_Media_Sync::sync_manifest_media(
            $this->manifest('phase-4-invalid-map', [
                $this->media_entry(71, 'invalid-map.txt'),
            ]),
            ['proposal_id' => 'phase-4-invalid-map']
        );

        $this->assertFalse(DBVC_Media_Sync::is_valid_resolver_target($page_id));
        $this->assertNull(DBVC_Media_Sync::get_local_id(71));
        $this->assertSame(1, $stats['resolver_decisions']['failed']);
        $this->assertSame('invalid_attachment_target', $stats['resolver_decisions']['results'][0]['reason']);
        $this->assertSame(1, $stats['resolver']['metrics']['unresolved']);
        $this->assertSame(1, $stats['errors']);

        $readiness = new ReflectionMethod('DBVC_Admin_App', 'resolver_decision_is_actionable');
        $readiness->setAccessible(true);
        $this->assertFalse($readiness->invoke(null, [
            'action'    => 'map',
            'target_id' => $page_id,
        ]));
    }

    public function test_reconciler_does_not_create_media_for_skip_map_or_reuse(): void
    {
        $proposal_id = 'phase-4-no-create';
        $backup_path = $this->create_bundle_backup($proposal_id, [
            'skip.txt'  => 'skip',
            'map.txt'   => 'map',
            'reuse.txt' => 'reuse',
        ]);
        $map_target = $this->create_attachment('map-target');
        $reuse_target = $this->create_attachment('reuse-target');
        update_option('dbvc_resolver_decisions', [
            $proposal_id => [
                '81' => ['action' => 'skip'],
                '82' => ['action' => 'map', 'target_id' => $map_target],
                '83' => ['action' => 'reuse', 'target_id' => $reuse_target],
            ],
        ], false);
        $manifest = $this->manifest($proposal_id, [
            $this->media_entry(81, 'skip.txt'),
            $this->media_entry(82, 'map.txt'),
            $this->media_entry(83, 'reuse.txt'),
        ]);
        $before = $this->count_attachments();

        $result = \Dbvc\Media\Reconciler::enqueue($proposal_id, $manifest, [
            'backup_path' => $backup_path,
            'resolver_decisions' => DBVC_Media_Sync::get_effective_resolver_decisions($proposal_id),
        ]);
        $this->track_reconcile_directory($result);

        $this->assertSame($before, $this->count_attachments());
        $this->assertSame(0, $result['created']);
        $this->assertSame(3, $result['decision_summary']['applied']);
        $this->assertSame(1, $result['decision_summary']['skip']);
        $this->assertSame(1, $result['decision_summary']['map']);
        $this->assertSame(1, $result['decision_summary']['reuse']);
        $this->assertArrayNotHasKey(81, $result['original_id_map']);
        $this->assertSame($map_target, $result['original_id_map'][82]);
        $this->assertSame($reuse_target, $result['original_id_map'][83]);
    }

    public function test_forced_download_is_created_once_and_consumed_by_media_sync(): void
    {
        $proposal_id = 'phase-4-download';
        $original_id = 91;
        $relative_path = 'phase-4/source.txt';
        $existing_id = $this->create_attachment('existing-download-target');
        update_post_meta($existing_id, '_wp_attached_file', $relative_path);
        update_option('dbvc_resolver_decisions', [
            $proposal_id => [
                (string) $original_id => ['action' => 'download'],
            ],
        ], false);
        $backup_path = $this->create_bundle_backup($proposal_id, [
            $relative_path => 'forced replacement',
        ]);
        $manifest = $this->manifest($proposal_id, [
            $this->media_entry($original_id, 'source.txt', '', $relative_path),
        ]);
        $before = $this->count_attachments();

        $reconcile = \Dbvc\Media\Reconciler::enqueue($proposal_id, $manifest, [
            'backup_path' => $backup_path,
            'resolver_decisions' => DBVC_Media_Sync::get_effective_resolver_decisions($proposal_id),
        ]);
        $this->track_reconcile_directory($reconcile);
        $downloaded_id = $reconcile['original_id_map'][$original_id] ?? 0;
        if ($downloaded_id) {
            $this->attachment_ids[] = $downloaded_id;
        }

        $this->assertSame(1, $reconcile['created']);
        $this->assertSame(1, $reconcile['decision_summary']['download']);
        $this->assertNotSame($existing_id, $downloaded_id);
        $this->assertSame('attachment', get_post_type($downloaded_id));
        $this->assertSame($before + 1, $this->count_attachments());
        $this->assertNotEmpty(get_attached_file($downloaded_id));

        $stats = DBVC_Media_Sync::sync_manifest_media($manifest, [
            'proposal_id'    => $proposal_id,
            'manifest_dir'   => $backup_path,
            'media_reconcile'=> $reconcile,
        ]);

        $this->assertSame($before + 1, $this->count_attachments());
        $this->assertSame($downloaded_id, DBVC_Media_Sync::get_local_id($original_id));
        $this->assertSame(1, $stats['downloaded']);
        $this->assertSame(1, $stats['resolver_decisions']['download']);
        $this->assertSame(0, $stats['resolver_decisions']['failed']);
    }

    public function test_forced_download_rejects_bundle_hash_mismatch_without_attachment(): void
    {
        $proposal_id = 'phase-4-bundle-hash-mismatch';
        $original_id = 96;
        $relative_path = 'phase-4/mismatch.txt';
        $backup_path = $this->create_bundle_backup($proposal_id, [
            $relative_path => 'tampered bundle contents',
        ]);
        $entry = $this->media_entry($original_id, 'mismatch.txt', '', $relative_path);
        $entry['file_hash'] = 'sha256:' . hash('sha256', 'expected bundle contents');
        $manifest = $this->manifest($proposal_id, [$entry]);
        update_option('dbvc_resolver_decisions', [
            $proposal_id => [
                (string) $original_id => ['action' => 'download'],
            ],
        ], false);
        $before = $this->count_attachments();

        $reconcile = \Dbvc\Media\Reconciler::enqueue($proposal_id, $manifest, [
            'backup_path' => $backup_path,
            'allow_remote'=> false,
            'resolver_decisions' => DBVC_Media_Sync::get_effective_resolver_decisions($proposal_id),
        ]);
        $this->track_reconcile_directory($reconcile);

        $this->assertSame($before, $this->count_attachments());
        $this->assertSame(0, $reconcile['decision_summary']['applied'] ?? null);
        $this->assertSame(1, $reconcile['decision_summary']['failed'] ?? null);
        $this->assertSame(0, $reconcile['decision_summary']['pending'] ?? null);
        $this->assertSame('bundle_hash_mismatch', $reconcile['decision_results'][0]['reason'] ?? null);
        $this->assertSame(1, $reconcile['unresolved'] ?? null);
    }

    public function test_direct_sync_uses_local_bundle_when_remote_source_is_blocked(): void
    {
        $proposal_id = 'phase-4-direct-bundle';
        $original_id = 101;
        $relative_directory = 'phase-4-direct-' . wp_generate_uuid4();
        $relative_path = $relative_directory . '/asset.txt';
        $bundle_directory = trailingslashit(dbvc_get_sync_path()) . $relative_directory;
        wp_mkdir_p($bundle_directory);
        file_put_contents(trailingslashit($bundle_directory) . 'asset.txt', 'local bundle');
        $this->temporary_directories[] = $bundle_directory;
        update_option('dbvc_resolver_decisions', [
            $proposal_id => [
                (string) $original_id => ['action' => 'download'],
            ],
        ], false);
        $manifest = $this->manifest($proposal_id, [
            $this->media_entry(
                $original_id,
                'asset.txt',
                'https://blocked.example/asset.txt',
                $relative_path
            ),
        ]);

        $stats = DBVC_Media_Sync::sync_manifest_media($manifest, [
            'proposal_id' => $proposal_id,
        ]);
        $downloaded_id = DBVC_Media_Sync::get_local_id($original_id);
        if ($downloaded_id) {
            $this->attachment_ids[] = $downloaded_id;
        }

        $this->assertSame('attachment', get_post_type($downloaded_id));
        $this->assertSame(1, $stats['downloaded']);
        $this->assertSame(1, $stats['blocked']);
        $this->assertSame(1, $stats['resolver_decisions']['download']);
        $this->assertSame(0, $stats['resolver_decisions']['failed']);
    }

    private function create_attachment(string $title): int
    {
        $attachment_id = self::factory()->post->create([
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'post_title'     => $title,
            'post_mime_type' => 'text/plain',
        ]);
        $this->attachment_ids[] = $attachment_id;
        return $attachment_id;
    }

    private function manifest(string $proposal_id, array $media_index): array
    {
        return [
            'backup_name' => $proposal_id,
            'items'       => [],
            'media_index' => $media_index,
            'totals'      => [
                'media_items' => count($media_index),
            ],
        ];
    }

    private function media_entry(
        int $original_id,
        string $filename,
        string $source_url = '',
        string $relative_path = ''
    ): array {
        if ($source_url === '') {
            $source_url = home_url('/wp-content/uploads/' . ltrim($relative_path ?: $filename, '/'));
        }
        if ($relative_path === '') {
            $relative_path = $filename;
        }

        return [
            'original_id'   => $original_id,
            'asset_uid'     => 'phase-4-asset-' . $original_id,
            'filename'      => $filename,
            'relative_path' => $relative_path,
            'bundle_path'   => $relative_path,
            'source_url'    => $source_url,
            'mime_type'     => 'text/plain',
        ];
    }

    private function create_bundle_backup(string $proposal_id, array $files): string
    {
        $base = trailingslashit(get_temp_dir()) . $proposal_id . '-' . wp_generate_uuid4();
        $bundle = trailingslashit($base) . 'media-bundle';
        wp_mkdir_p($bundle);

        foreach ($files as $relative_path => $contents) {
            $target = trailingslashit($bundle) . ltrim($relative_path, '/');
            wp_mkdir_p(dirname($target));
            file_put_contents($target, $contents);
        }

        $this->temporary_directories[] = $base;
        return $base;
    }

    private function track_reconcile_directory(array $result): void
    {
        if (! empty($result['bundle_dir'])) {
            $this->temporary_directories[] = $result['bundle_dir'];
        }
    }

    private function count_attachments(): int
    {
        return count(get_posts([
            'post_type'      => 'attachment',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ]));
    }

    private function delete_directory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $items = scandir($directory);
        if (! is_array($items)) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $directory . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->delete_directory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($directory);
    }
}
