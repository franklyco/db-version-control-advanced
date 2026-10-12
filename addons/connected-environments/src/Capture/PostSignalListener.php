<?php

namespace Dbvc\Connected\Capture;

use Dbvc\Connected\Adapters\DomainRegistry;

/**
 * Save-side dirty hints for post-backed domains (`wp.service`). Hooks
 * `save_post`, `deleted_post` and the post-meta hooks; each callback checks
 * the post type and records one per-object marker (`post:<ID>`). It never
 * reads content, exports, or calls the network.
 */
final class PostSignalListener
{
    /**
     * @var DirtyCapture
     */
    private $capture;

    /**
     * @var array<string, string> post type => domain.
     */
    private $watched;

    public function __construct(DirtyCapture $capture)
    {
        $this->capture = $capture;
        $this->watched = DomainRegistry::watched_post_types();
    }

    /**
     * @return void
     */
    public function register()
    {
        if ($this->watched === []) {
            return;
        }
        add_action('save_post', [$this, 'on_save_post'], 20, 2);
        add_action('deleted_post', [$this, 'on_deleted_post'], 10, 2);
        add_action('updated_post_meta', [$this, 'on_post_meta'], 10, 3);
        add_action('added_post_meta', [$this, 'on_post_meta'], 10, 3);
        add_action('deleted_post_meta', [$this, 'on_post_meta'], 10, 3);
    }

    /**
     * @return void
     */
    public function unregister()
    {
        remove_action('save_post', [$this, 'on_save_post'], 20);
        remove_action('deleted_post', [$this, 'on_deleted_post'], 10);
        remove_action('updated_post_meta', [$this, 'on_post_meta'], 10);
        remove_action('added_post_meta', [$this, 'on_post_meta'], 10);
        remove_action('deleted_post_meta', [$this, 'on_post_meta'], 10);
    }

    /**
     * @param int      $post_id
     * @param \WP_Post $post
     * @return void
     */
    public function on_save_post($post_id, $post = null)
    {
        if (! $post instanceof \WP_Post || wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }
        $this->handle($post->post_type, (int) $post_id, 'save_post');
    }

    /**
     * @param int      $post_id
     * @param \WP_Post $post
     * @return void
     */
    public function on_deleted_post($post_id, $post = null)
    {
        $post_type = $post instanceof \WP_Post ? $post->post_type : '';
        $this->handle($post_type, (int) $post_id, 'deleted_post');
    }

    /**
     * @param mixed $meta_id
     * @param int   $object_id
     * @param string $meta_key
     * @return void
     */
    public function on_post_meta($meta_id, $object_id, $meta_key = '')
    {
        unset($meta_id);
        if (in_array((string) $meta_key, ['_edit_lock', '_edit_last', 'vf_object_uid'], true)) {
            return;
        }
        $this->handle((string) get_post_type((int) $object_id), (int) $object_id, 'post_meta');
    }

    /**
     * @param string $post_type
     * @param int    $post_id
     * @param string $source_hook
     * @return void
     */
    private function handle($post_type, $post_id, $source_hook)
    {
        if ($post_id <= 0 || ! isset($this->watched[(string) $post_type])) {
            return;
        }
        $this->capture->signal($this->watched[(string) $post_type], 'post:' . $post_id, [
            'origin' => 'human',
            'source_hook' => $source_hook,
        ]);
    }
}
