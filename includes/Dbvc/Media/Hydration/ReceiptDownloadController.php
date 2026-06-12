<?php

namespace Dbvc\Media\Hydration;

if (! defined('WPINC')) {
    die;
}

/**
 * Secure admin-post downloads for media hydration JSON receipts.
 */
final class ReceiptDownloadController
{
    public const ACTION = 'dbvc_media_hydration_receipt_download';
    public const NONCE_ACTION = 'dbvc_media_hydration_receipt_download';

    public static function init(): void
    {
        add_action('admin_post_' . self::ACTION, [self::class, 'handle_download']);
    }

    public static function handle_download(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to download media hydration receipts.', 'dbvc'), '', ['response' => 403]);
        }

        $receipt_id = isset($_GET['receipt_id']) ? sanitize_file_name(wp_unslash((string) $_GET['receipt_id'])) : '';
        if ($receipt_id === '') {
            wp_die(esc_html__('Invalid media hydration receipt ID.', 'dbvc'), '', ['response' => 400]);
        }

        if (! wp_verify_nonce((string) ($_GET['_wpnonce'] ?? ''), self::NONCE_ACTION . '_' . $receipt_id)) {
            wp_die(esc_html__('Invalid media hydration receipt download request.', 'dbvc'), '', ['response' => 403]);
        }

        $path = HydrationReceiptStore::resolve_receipt_path($receipt_id);
        if (is_wp_error($path)) {
            $error_data = $path->get_error_data();
            $status = is_array($error_data) && isset($error_data['status']) ? (int) $error_data['status'] : 404;
            wp_die(esc_html($path->get_error_message()), '', ['response' => $status]);
        }

        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        header('Content-Length: ' . (string) filesize($path));
        header('X-Content-Type-Options: nosniff');

        readfile($path);
        exit;
    }

    public static function download_url_for_receipt(string $receipt_id): string
    {
        $receipt_id = sanitize_file_name($receipt_id);
        if ($receipt_id === '') {
            return '';
        }

        return esc_url_raw(
            add_query_arg(
                [
                    'action' => self::ACTION,
                    'receipt_id' => $receipt_id,
                    '_wpnonce' => wp_create_nonce(self::NONCE_ACTION . '_' . $receipt_id),
                ],
                admin_url('admin-post.php')
            )
        );
    }
}
