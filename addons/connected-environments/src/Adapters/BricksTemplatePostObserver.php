<?php

namespace Dbvc\Connected\Adapters;

use Dbvc\Connected\Preparation\BricksMediaReferences;
use Dbvc\Connected\Preparation\MediaReferences;
use Dbvc\Connected\Preparation\ReferenceTokens;

/**
 * Bricks-aware observer for the `bricks_template` post type (M9 slice 6d).
 *
 * A Bricks template is an ordinary DBVC-tracked post (identity via
 * `vf_object_uid`, taxonomies and fields handled by {@see PostTypeObserver}),
 * but its builder element tree lives in post meta (`_bricks_page_content_2`,
 * and the header/footer variants) and references global classes and nested
 * components by site-local id — exactly the `_cssGlobalClasses` / `cid` /
 * `parentComponent` shape a component carries. This observer tokenizes those
 * references to portable name tokens before the projection hash and resolves
 * them back to local ids at apply, reusing {@see ReferenceTokens} and the
 * shared {@see BricksReferenceMaps}, so a template converges across the fleet
 * by hash even though every site stores different local class/component ids.
 *
 * The projection is scoped to the Bricks template meta keys by default, so a
 * framework template push carries portable builder content and not a client's
 * site-specific bookkeeping (sync-plugin versions, SEO meta, custom fields).
 */
final class BricksTemplatePostObserver extends PostTypeObserver
{
    use BricksReferenceMaps;

    /** The Bricks post type this observer is pinned to. */
    public const POST_TYPE = 'bricks_template';

    /**
     * Portable builder content meta. Other meta (e.g. `_brickssync_versions`,
     * `rank_math_*`, site-specific custom fields) is left out of the projection
     * so it is never pushed across the fleet.
     *
     * @var array<int, string>
     */
    private const TEMPLATE_META = [
        '_bricks_page_content_2',
        '_bricks_page_header_2',
        '_bricks_page_footer_2',
        '_bricks_template_settings',
        '_bricks_template_type',
        '_bricks_editor_mode',
    ];

    /**
     * @param array<string, mixed> $definition
     */
    public function __construct(array $definition = [])
    {
        parent::__construct($definition);
        // Scope to portable builder content unless the operator set an explicit allow-list.
        if ($this->meta_allow_keys === []) {
            $this->meta_allow_keys = self::TEMPLATE_META;
        }
    }

    /**
     * Tokenize the element-tree class/component references before hashing. The
     * whole canonical body is walked; references only occur inside the Bricks
     * content meta, so other fields are untouched.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function tokenize_references(array $data)
    {
        // Non-portable editor-only state: the builder preview post id/type point at
        // a source-site post that is meaningless on the target. Drop them from the
        // projection so they neither travel nor register as cross-site drift.
        $data = self::strip_editor_preview($data);
        // Structured media: rewrite each element-tree image object's local id to a
        // content-hash token (its bytes ride the media channel; see collect_transport_media).
        $data = BricksMediaReferences::tokenize($data);

        return ReferenceTokens::tokenize($data, $this->bricks_class_id_to_name(), $this->bricks_component_id_to_name());
    }

    /**
     * Resolve the element-tree reference tokens back to this site's local ids
     * before writing the template's meta.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    protected function detokenize_references(array $body)
    {
        $body = BricksMediaReferences::detokenize($body);

        return ReferenceTokens::detokenize($body, $this->bricks_class_name_to_id(), $this->bricks_component_name_to_id());
    }

    /**
     * A template's media rides the post media channel: the base string-referenced
     * media ({@see MediaReferences}) PLUS the structured element-tree image
     * references ({@see BricksMediaReferences}), deduped by content hash.
     *
     * @param array<string, mixed> $body Tokenized canonical body.
     * @return array{media: array<int, array<string, mixed>>, deferred: array<int, array<string, mixed>>}
     */
    public function collect_transport_media(array $body)
    {
        $base = MediaReferences::collect_for_transport($body);
        $bricks = BricksMediaReferences::collect_for_transport($body);
        $media = $base['media'];
        $seen = [];
        foreach ($media as $item) {
            if (isset($item['hash'])) {
                $seen[(string) $item['hash']] = true;
            }
        }
        foreach ($bricks['media'] as $item) {
            $hash = (string) ($item['hash'] ?? '');
            if ($hash === '' || ! isset($seen[$hash])) {
                $media[] = $item;
                $seen[$hash] = true;
            }
        }

        return ['media' => $media, 'deferred' => array_merge($base['deferred'], $bricks['deferred'])];
    }

    /**
     * Recursively remove the Bricks builder preview keys from any node.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function strip_editor_preview(array $data)
    {
        $out = [];
        foreach ($data as $key => $value) {
            if ($key === 'templatePreviewPostId' || $key === 'templatePreviewType') {
                continue;
            }
            $out[$key] = is_array($value) ? self::strip_editor_preview($value) : $value;
        }

        return $out;
    }
}
