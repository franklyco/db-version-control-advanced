<?php

namespace Dbvc\Media;

/**
 * Scoped logging helper for media workflows.
 *
 * Routes events to the DBVC logger when enabled and mirrors them to WP_Debug.
 */
final class Logger
{
    /**
     * Write a structured log entry under a specific media channel.
     *
     * @param string $channel
     * @param string $message
     * @param array  $context
     * @return void
     */
    public static function log(string $channel, string $message, array $context = []): void
    {
        $payload = array_merge(['channel' => $channel], $context);

        if (class_exists('\DBVC_Sync_Logger') && method_exists('\DBVC_Sync_Logger', 'log_media')) {
            \DBVC_Sync_Logger::log_media("{$channel}: {$message}", $payload);
        }

        if ((defined('WP_DEBUG') && WP_DEBUG) || (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG)) {
            error_log('[DBVC media][' . $channel . '] ' . $message . ' ' . wp_json_encode($payload));
        }
    }
}
