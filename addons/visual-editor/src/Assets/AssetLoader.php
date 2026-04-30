<?php

namespace Dbvc\VisualEditor\Assets;

use Dbvc\VisualEditor\Context\EditModeState;
use Dbvc\VisualEditor\Context\PageContextResolver;
use Dbvc\VisualEditor\Registry\EditableRegistry;

final class AssetLoader
{
    /**
     * @var string
     */
    private $bootstrap_file;

    /**
     * @var EditModeState
     */
    private $edit_mode;

    /**
     * @var EditableRegistry
     */
    private $registry;

    /**
     * @var PageContextResolver
     */
    private $page_context;

    public function __construct($bootstrap_file, EditModeState $edit_mode, EditableRegistry $registry, PageContextResolver $page_context)
    {
        $this->bootstrap_file = (string) $bootstrap_file;
        $this->edit_mode = $edit_mode;
        $this->registry = $registry;
        $this->page_context = $page_context;
    }

    /**
     * @return void
     */
    public function register()
    {
        add_action('wp_enqueue_scripts', [$this, 'enqueue'], 100);
    }

    /**
     * @return void
     */
    public function unregister()
    {
        remove_action('wp_enqueue_scripts', [$this, 'enqueue'], 100);
    }

    /**
     * @return void
     */
    public function enqueue()
    {
        if (! $this->edit_mode->shouldLoadFrontendAssets()) {
            return;
        }

        $base_url = plugin_dir_url($this->bootstrap_file);
        $session_id = $this->registry->getSessionId();
        $style_version = $this->resolveAssetVersion('assets/css/overlay.css');
        $api_version = $this->resolveAssetVersion('assets/js/api-client.js');
        $overlay_version = $this->resolveAssetVersion('assets/js/overlay-app.js');
        $overlay_dependencies = ['dbvc-visual-editor-api-client'];

        if (function_exists('wp_enqueue_editor')) {
            wp_enqueue_editor();
        }

        if (function_exists('wp_enqueue_media')) {
            wp_enqueue_media();
        }

        if (wp_script_is('wp-editor', 'registered') || wp_script_is('wp-editor', 'enqueued')) {
            $overlay_dependencies[] = 'wp-editor';
        }

        if (wp_script_is('media-editor', 'registered') || wp_script_is('media-editor', 'enqueued')) {
            $overlay_dependencies[] = 'media-editor';
        }

        wp_enqueue_style(
            'dbvc-visual-editor-overlay',
            $base_url . 'assets/css/overlay.css',
            [],
            $style_version
        );

        wp_enqueue_script(
            'dbvc-visual-editor-api-client',
            $base_url . 'assets/js/api-client.js',
            [],
            $api_version,
            true
        );

        wp_enqueue_script(
            'dbvc-visual-editor-overlay',
            $base_url . 'assets/js/overlay-app.js',
            $overlay_dependencies,
            $overlay_version,
            true
        );

        wp_localize_script(
            'dbvc-visual-editor-overlay',
            'DBVCVisualEditorBootstrap',
            [
                'active' => true,
                'restBase' => esc_url_raw(rest_url('dbvc/v1/visual-editor')),
                'nonce' => wp_create_nonce('wp_rest'),
                'sessionId' => $session_id,
                'pageContext' => $this->page_context->resolve(),
                'supportsWpEditor' => function_exists('wp_enqueue_editor') && (wp_script_is('wp-editor', 'enqueued') || wp_script_is('wp-editor', 'done') || wp_script_is('wp-editor', 'to_do')),
                'supportsWpMedia' => function_exists('wp_enqueue_media') && wp_script_is('media-editor', 'enqueued'),
                'strings' => [
                    'modeActive' => __('Visual Editor active', 'dbvc'),
                    'supportedCount' => __('marked fields', 'dbvc'),
                    'zeroMarkers' => __('No supported or inspectable nodes were detected on this page yet.', 'dbvc'),
                    'sessionUnavailable' => __('Markers were found, but the descriptor session was unavailable for this request.', 'dbvc'),
                    'panelTitle' => __('Edit field', 'dbvc'),
                    'panelSave' => __('Save', 'dbvc'),
                    'panelInspectOnly' => __('Inspect only', 'dbvc'),
                    'panelCancel' => __('Close', 'dbvc'),
                    'panelEmpty' => __('No field is selected yet.', 'dbvc'),
                    'panelLoading' => __('Loading field details…', 'dbvc'),
                    'panelSaving' => __('Saving…', 'dbvc'),
                    'panelReady' => __('Select a marker to inspect or edit it.', 'dbvc'),
                    'panelSaved' => __('Saved successfully.', 'dbvc'),
                    'panelSource' => __('Source', 'dbvc'),
                    'panelSourceDetails' => __('Source details', 'dbvc'),
                    'panelSaveContract' => __('Save contract', 'dbvc'),
                    'panelSaveContractDetail' => __('Contract detail', 'dbvc'),
                    'panelSourceLabel' => __('Label', 'dbvc'),
                    'panelSourceExpression' => __('Dynamic tag', 'dbvc'),
                    'panelSourceRepeater' => __('acf repeater', 'dbvc'),
                    'panelSourceFlexible' => __('acf flexible', 'dbvc'),
                    'panelScopeReadonly' => __('inspect only', 'dbvc'),
                    'panelScopeRelated' => __('related post', 'dbvc'),
                    'panelScopeRelatedTerm' => __('related term', 'dbvc'),
                    'panelScopeRelatedUser' => __('related user', 'dbvc'),
                    'panelScopeRelatedOption' => __('related option', 'dbvc'),
                    'panelScopeRelatedGeneric' => __('related item', 'dbvc'),
                    'panelScopeShared' => __('shared target', 'dbvc'),
                    'panelScopeSharedPost' => __('shared post', 'dbvc'),
                    'panelScopeSharedTerm' => __('shared term', 'dbvc'),
                    'panelScopeSharedUser' => __('shared user', 'dbvc'),
                    'panelScopeSharedOption' => __('shared option', 'dbvc'),
                    'panelScopeSharedGeneric' => __('shared item', 'dbvc'),
                    'panelRepeater' => __('repeater', 'dbvc'),
                    'panelFlexible' => __('flexible', 'dbvc'),
                    'panelLayout' => __('layout', 'dbvc'),
                    'panelRow' => __('row', 'dbvc'),
                    'panelLoop' => __('loop', 'dbvc'),
                    'panelEntityPost' => __('post', 'dbvc'),
                    'panelEntityOption' => __('option', 'dbvc'),
                    'panelEntityTerm' => __('term', 'dbvc'),
                    'panelEntityUser' => __('user', 'dbvc'),
                    'panelNoOptions' => __('No choices were available for this field.', 'dbvc'),
                    'panelRichTextBold' => __('Bold', 'dbvc'),
                    'panelRichTextItalic' => __('Italic', 'dbvc'),
                    'panelRichTextParagraph' => __('Paragraph', 'dbvc'),
                    'panelRichTextBullets' => __('Bullets', 'dbvc'),
                    'panelRichTextNumbers' => __('Numbers', 'dbvc'),
                    'panelRichTextVisual' => __('Visual', 'dbvc'),
                    'panelRichTextCode' => __('Code', 'dbvc'),
                    'panelLinkUrl' => __('Link URL', 'dbvc'),
                    'panelLinkTitle' => __('Link title', 'dbvc'),
                    'panelLinkSameTab' => __('Open in same tab', 'dbvc'),
                    'panelLinkNewTab' => __('Open in new tab', 'dbvc'),
                    'panelMediaUrl' => __('Media Library image URL', 'dbvc'),
                    'panelMediaId' => __('Attachment ID', 'dbvc'),
                    'panelMediaUrlHint' => __('Paste a local Media Library image URL to resolve this field to an attachment ID.', 'dbvc'),
                    'panelMediaChoose' => __('Choose from Media Library', 'dbvc'),
                    'panelMediaReplace' => __('Replace from Media Library', 'dbvc'),
                    'panelMediaClear' => __('Clear image', 'dbvc'),
                    'panelMediaFrameTitle' => __('Select image', 'dbvc'),
                    'panelMediaFrameButton' => __('Use this image', 'dbvc'),
                    'panelGallerySingle' => __('1 gallery image', 'dbvc'),
                    'panelGalleryCount' => __('gallery images', 'dbvc'),
                    'panelNoMedia' => __('No media is currently set.', 'dbvc'),
                    'panelRenderedValue' => __('Rendered value', 'dbvc'),
                    'panelResolvedValue' => __('Resolved source value', 'dbvc'),
                    'panelMismatch' => __('This marker is visible, but saving is disabled because the resolved backend value does not match the rendered page value yet.', 'dbvc'),
                    'panelSharedScopeAck' => __('I understand this updates a shared field and may affect other pages.', 'dbvc'),
                    'panelSharedScopeAckPost' => __('I understand this updates a shared post-owned field and may affect other pages.', 'dbvc'),
                    'panelSharedScopeAckTerm' => __('I understand this updates a shared taxonomy term field and may affect other pages.', 'dbvc'),
                    'panelSharedScopeAckUser' => __('I understand this updates a shared user field and may affect other pages.', 'dbvc'),
                    'panelSharedScopeAckOption' => __('I understand this updates a shared Site Settings value and may affect other pages.', 'dbvc'),
                    'panelSharedScopeAckGeneric' => __('I understand this updates a shared field and may affect other pages.', 'dbvc'),
                    'panelSharedScopeSave' => __('Save shared field', 'dbvc'),
                    'panelSharedScopeSavePost' => __('Save shared post', 'dbvc'),
                    'panelSharedScopeSaveTerm' => __('Save shared term', 'dbvc'),
                    'panelSharedScopeSaveUser' => __('Save shared user', 'dbvc'),
                    'panelSharedScopeSaveOption' => __('Save shared option', 'dbvc'),
                    'panelSharedScopeSaveGeneric' => __('Save shared item', 'dbvc'),
                    'panelSharedScopeRequired' => __('Acknowledge the shared scope warning before saving this field.', 'dbvc'),
                    'panelSharedScopeRequiredPost' => __('Acknowledge the shared-post warning before saving this field.', 'dbvc'),
                    'panelSharedScopeRequiredTerm' => __('Acknowledge the shared-term warning before saving this field.', 'dbvc'),
                    'panelSharedScopeRequiredUser' => __('Acknowledge the shared-user warning before saving this field.', 'dbvc'),
                    'panelSharedScopeRequiredOption' => __('Acknowledge the shared-option warning before saving this field.', 'dbvc'),
                    'panelSharedScopeRequiredGeneric' => __('Acknowledge the shared-item warning before saving this field.', 'dbvc'),
                    'panelRelatedScopeAck' => __('I understand this updates the related post shown in this Bricks query loop, not the current page.', 'dbvc'),
                    'panelRelatedScopeAckTerm' => __('I understand this updates the related term shown in this Bricks query loop, not the current page.', 'dbvc'),
                    'panelRelatedScopeAckUser' => __('I understand this updates the related user shown in this Bricks query loop, not the current page.', 'dbvc'),
                    'panelRelatedScopeAckOption' => __('I understand this updates the related option source shown in this Bricks query loop, not the current page.', 'dbvc'),
                    'panelRelatedScopeAckGeneric' => __('I understand this updates a related item shown in this Bricks query loop, not the current page.', 'dbvc'),
                    'panelRelatedScopeSave' => __('Save related post', 'dbvc'),
                    'panelRelatedScopeSaveTerm' => __('Save related term', 'dbvc'),
                    'panelRelatedScopeSaveUser' => __('Save related user', 'dbvc'),
                    'panelRelatedScopeSaveOption' => __('Save related option', 'dbvc'),
                    'panelRelatedScopeSaveGeneric' => __('Save related item', 'dbvc'),
                    'panelRelatedScopeRequired' => __('Acknowledge the related-post warning before saving this field.', 'dbvc'),
                    'panelRelatedScopeRequiredTerm' => __('Acknowledge the related-term warning before saving this field.', 'dbvc'),
                    'panelRelatedScopeRequiredUser' => __('Acknowledge the related-user warning before saving this field.', 'dbvc'),
                    'panelRelatedScopeRequiredOption' => __('Acknowledge the related-option warning before saving this field.', 'dbvc'),
                    'panelRelatedScopeRequiredGeneric' => __('Acknowledge the related-item warning before saving this field.', 'dbvc'),
                    'editLabel' => __('Edit', 'dbvc'),
                    'inspectLabel' => __('Inspect', 'dbvc'),
                    'badgeRelated' => __('Related Post', 'dbvc'),
                    'badgeRelatedTerm' => __('Related Term', 'dbvc'),
                    'badgeRelatedUser' => __('Related User', 'dbvc'),
                    'badgeRelatedOption' => __('Related Option', 'dbvc'),
                    'badgeRelatedGeneric' => __('Related', 'dbvc'),
                    'badgeShared' => __('Shared', 'dbvc'),
                    'badgeSharedPost' => __('Shared Post', 'dbvc'),
                    'badgeSharedTerm' => __('Shared Term', 'dbvc'),
                    'badgeSharedUser' => __('Shared User', 'dbvc'),
                    'badgeSharedOption' => __('Shared Option', 'dbvc'),
                    'badgeSharedGeneric' => __('Shared Item', 'dbvc'),
                    'descriptorMissing' => __('Descriptor not found.', 'dbvc'),
                    'sessionMissing' => __('Visual Editor session not found for this page.', 'dbvc'),
                    'notEditable' => __('This field is not editable in the current MVP slice.', 'dbvc'),
                    'saveFailed' => __('Save failed.', 'dbvc'),
                    'saveSucceeded' => __('Saved successfully.', 'dbvc'),
                ],
            ]
        );
    }

    /**
     * @param string $relative_path
     * @return string
     */
    private function resolveAssetVersion($relative_path)
    {
        $path = dirname($this->bootstrap_file) . '/' . ltrim((string) $relative_path, '/');
        $mtime = is_readable($path) ? filemtime($path) : false;

        if ($mtime !== false) {
            return (string) $mtime;
        }

        return defined('DBVC_PLUGIN_VERSION') ? DBVC_PLUGIN_VERSION : '1.0.0';
    }
}
