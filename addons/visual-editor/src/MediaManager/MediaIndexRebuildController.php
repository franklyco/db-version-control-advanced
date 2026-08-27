<?php

namespace Dbvc\VisualEditor\MediaManager;

/**
 * R2-H Phase 1 (Slice 4b-2) — topology/exclusion rebuild triggers.
 *
 * A completed cross-user index depends on the structural eligible set (which post
 * types/taxonomies are public/show-UI and not excluded, which ACF media-field
 * definitions apply to them). When any of those change, the whole index must be
 * rebuilt — an entity previously excluded may now be eligible, a field previously
 * present may be gone, etc.
 *
 * This controller wires the trigger surface:
 *
 * - `acf/update_field_group` / `acf/delete_field_group` (ACF field-group save/delete)
 * - `update_option_{OPTION_EXCLUDED_POST_TYPES}` / `_{OPTION_EXCLUDED_TAXONOMIES}`
 *   (Media-Manager exclusion-option changes; plus add/delete siblings)
 * - `wp_loaded` (priority 20): a compact topology fingerprint over the sorted set of
 *   public+show-UI post types and taxonomies. When it drifts from the stored value
 *   the controller triggers a rebuild — this catches post-type/taxonomy
 *   (de)registration without firing on every request.
 *
 * The rebuild itself is atomic — see {@see MediaIndexStore::beginRebuild()},
 * {@see MediaIndexBuilder::runChunk()}, and {@see MediaIndexStore::completeRebuild()}.
 * A trigger fired while a rebuild is already running is a no-op; the eventual swap
 * and prune pick up the change, and if the topology drifts again the fingerprint
 * check on the next request will kick off a fresh rebuild.
 */
final class MediaIndexRebuildController
{
    private const OPTION_TOPOLOGY_FINGERPRINT = 'dbvc_visual_editor_media_index_topology_fingerprint';

    /**
     * @var MediaIndexStore
     */
    private $store;

    /**
     * @var MediaIndexBuilder
     */
    private $builder;

    /**
     * @var MediaIndexScheduler
     */
    private $scheduler;

    public function __construct(
        MediaIndexStore $store,
        MediaIndexBuilder $builder,
        MediaIndexScheduler $scheduler
    ) {
        $this->store = $store;
        $this->builder = $builder;
        $this->scheduler = $scheduler;
    }

    /**
     * @return void
     */
    public function register()
    {
        add_action('acf/update_field_group', [$this, 'onAcfFieldGroupChanged'], 20);
        add_action('acf/delete_field_group', [$this, 'onAcfFieldGroupChanged'], 20);
        add_action('acf/trash_field_group', [$this, 'onAcfFieldGroupChanged'], 20);
        add_action('acf/untrash_field_group', [$this, 'onAcfFieldGroupChanged'], 20);

        foreach ($this->exclusionOptions() as $option_name) {
            add_action('update_option_' . $option_name, [$this, 'onExclusionOptionChanged'], 20, 2);
            add_action('add_option_' . $option_name, [$this, 'onExclusionOptionAdded'], 20, 2);
            add_action('delete_option_' . $option_name, [$this, 'onExclusionOptionDeleted'], 20);
        }

        // Priority 20 so post-type/taxonomy registration on `init` and normal `wp_loaded`
        // work has finished. On every request we compute a cheap fingerprint and
        // compare — only a real change kicks off a rebuild.
        add_action('wp_loaded', [$this, 'checkTopologyFingerprint'], 20);
    }

    /**
     * @return void
     */
    public function unregister()
    {
        remove_action('acf/update_field_group', [$this, 'onAcfFieldGroupChanged'], 20);
        remove_action('acf/delete_field_group', [$this, 'onAcfFieldGroupChanged'], 20);
        remove_action('acf/trash_field_group', [$this, 'onAcfFieldGroupChanged'], 20);
        remove_action('acf/untrash_field_group', [$this, 'onAcfFieldGroupChanged'], 20);

        foreach ($this->exclusionOptions() as $option_name) {
            remove_action('update_option_' . $option_name, [$this, 'onExclusionOptionChanged'], 20);
            remove_action('add_option_' . $option_name, [$this, 'onExclusionOptionAdded'], 20);
            remove_action('delete_option_' . $option_name, [$this, 'onExclusionOptionDeleted'], 20);
        }

        remove_action('wp_loaded', [$this, 'checkTopologyFingerprint'], 20);
    }

    /**
     * @param mixed $group
     * @return void
     */
    public function onAcfFieldGroupChanged($group)
    {
        unset($group);
        $this->triggerRebuild('acf_field_group_changed');
    }

    /**
     * @param mixed $old_value
     * @param mixed $new_value
     * @return void
     */
    public function onExclusionOptionChanged($old_value, $new_value)
    {
        if ((string) $old_value === (string) $new_value) {
            return;
        }
        $this->triggerRebuild('exclusion_option_updated');
    }

    /**
     * @param string $option_name
     * @param mixed  $value
     * @return void
     */
    public function onExclusionOptionAdded($option_name, $value)
    {
        unset($option_name, $value);
        $this->triggerRebuild('exclusion_option_added');
    }

    /**
     * @return void
     */
    public function onExclusionOptionDeleted()
    {
        $this->triggerRebuild('exclusion_option_deleted');
    }

    /**
     * Compute the current topology fingerprint and compare against the stored one.
     * On drift, store the new fingerprint and trigger a rebuild.
     *
     * On a brand-new site (no stored fingerprint) we prime the option WITHOUT
     * triggering a rebuild — the first-run builder is already responsible for the
     * initial population.
     *
     * @return void
     */
    public function checkTopologyFingerprint()
    {
        $current = $this->computeTopologyFingerprint();
        $stored = (string) get_option(self::OPTION_TOPOLOGY_FINGERPRINT, '');

        if ($stored === '') {
            update_option(self::OPTION_TOPOLOGY_FINGERPRINT, $current, false);

            return;
        }

        if ($current === $stored) {
            return;
        }

        update_option(self::OPTION_TOPOLOGY_FINGERPRINT, $current, false);
        $this->triggerRebuild('topology_changed');
    }

    /**
     * Kick off an atomic rebuild: mint a fresh building generation, reset the build
     * state so the builder starts from the top, and arm the scheduler drain. A
     * no-op when a rebuild is already in flight — the running rebuild will finish
     * and swap, and if the topology drifts again the next fingerprint check will
     * kick off a fresh rebuild.
     *
     * @param string $reason Short reason string for diagnostics/telemetry hooks.
     * @return bool True if a rebuild was started, false when skipped.
     */
    public function triggerRebuild($reason)
    {
        if ($this->store->hasActiveRebuild()) {
            /**
             * Fires when a rebuild trigger was skipped because a rebuild is already
             * running. Lets integrators observe missed triggers without changing
             * behavior.
             *
             * @param string $reason Short trigger reason.
             */
            do_action('dbvc_visual_editor_media_index_rebuild_skipped', sanitize_key((string) $reason));

            return false;
        }

        $this->store->beginRebuild();
        $this->builder->reset();
        $this->scheduler->ensureBuildScheduled();

        /**
         * Fires when a fresh atomic rebuild has been started (building generation
         * minted, drain armed). Consumers should not do heavy work in-hook.
         *
         * @param string $reason Short trigger reason.
         */
        do_action('dbvc_visual_editor_media_index_rebuild_started', sanitize_key((string) $reason));

        return true;
    }

    /**
     * @return string
     */
    private function computeTopologyFingerprint()
    {
        $post_types = array_values(get_post_types(['public' => true, 'show_ui' => true], 'names'));
        $taxonomies = array_values(get_taxonomies(['public' => true, 'show_ui' => true], 'names'));
        sort($post_types);
        sort($taxonomies);

        $excluded_post_types = $this->readExcludedList(self::optionExcludedPostTypes());
        $excluded_taxonomies = $this->readExcludedList(self::optionExcludedTaxonomies());
        sort($excluded_post_types);
        sort($excluded_taxonomies);

        $payload = wp_json_encode([
            'post_types' => $post_types,
            'taxonomies' => $taxonomies,
            'excluded_post_types' => $excluded_post_types,
            'excluded_taxonomies' => $excluded_taxonomies,
        ]);

        return substr(hash('sha256', is_string($payload) ? $payload : ''), 0, 32);
    }

    /**
     * @param string $option_name
     * @return array<int, string>
     */
    private function readExcludedList($option_name)
    {
        $raw = (string) get_option($option_name, '');
        if ($raw === '') {
            return [];
        }
        $parts = preg_split('/[\s,]+/', $raw) ?: [];

        return array_values(array_filter(array_map('sanitize_key', $parts)));
    }

    /**
     * @return array<int, string>
     */
    private function exclusionOptions()
    {
        return [self::optionExcludedPostTypes(), self::optionExcludedTaxonomies()];
    }

    /**
     * @return string
     */
    private static function optionExcludedPostTypes()
    {
        return class_exists('\\DBVC_Visual_Editor_Addon')
            ? \DBVC_Visual_Editor_Addon::OPTION_EXCLUDED_POST_TYPES
            : 'dbvc_visual_editor_excluded_post_types';
    }

    /**
     * @return string
     */
    private static function optionExcludedTaxonomies()
    {
        return class_exists('\\DBVC_Visual_Editor_Addon')
            ? \DBVC_Visual_Editor_Addon::OPTION_EXCLUDED_TAXONOMIES
            : 'dbvc_visual_editor_excluded_taxonomies';
    }
}
