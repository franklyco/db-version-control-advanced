<?php
/**
 * Handles AJAX requests.
 *
 * @package ContentCollector
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class DBVC_CC_Ajax_Controller {

    private static $instance;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('wp_ajax_' . DBVC_CC_Contracts::AJAX_ACTION_GET_URLS_FROM_SITEMAP, [$this, 'get_urls_from_sitemap']);
        add_action('wp_ajax_' . DBVC_CC_Contracts::AJAX_ACTION_PROCESS_SINGLE_URL, [$this, 'process_single_url']);
        add_action('wp_ajax_' . DBVC_CC_Contracts::AJAX_ACTION_DBVC_CC_TRIGGER_DOMAIN_AI_REFRESH, [$this, 'dbvc_cc_trigger_domain_ai_refresh']);
    }

    /**
     * AJAX handler to fetch and parse the sitemap(s).
     */
    public function get_urls_from_sitemap() {
        check_ajax_referer(DBVC_CC_Contracts::AJAX_NONCE_ACTION, 'nonce');

        if (!isset($_POST['sitemap_url']) || !filter_var($_POST['sitemap_url'], FILTER_VALIDATE_URL)) {
            wp_send_json_error(['message' => __('Invalid sitemap URL.', 'dbvc')]);
        }

        $sitemap_url = esc_url_raw($_POST['sitemap_url']);
        $crawl_overrides = $this->get_crawl_overrides_from_request();
        DBVC_CC_Artifact_Manager::log_event(
            [
                'stage'    => 'sitemap',
                'status'   => 'started',
                'page_url' => $sitemap_url,
                'message'  => 'Fetching sitemap URLs.',
            ]
        );

        $all_urls = [];
        $urls = DBVC_CC_Crawler_Service::parse_sitemap($sitemap_url, $all_urls, $crawl_overrides);

        if (is_wp_error($urls)) {
            DBVC_CC_Artifact_Manager::log_event(
                [
                    'stage'        => 'sitemap',
                    'status'       => 'error',
                    'page_url'     => $sitemap_url,
                    'failure_code' => $urls->get_error_code(),
                    'message'      => $urls->get_error_message(),
                ]
            );
            wp_send_json_error(['message' => $urls->get_error_message()]);
        }

        if (empty($urls)) {
            DBVC_CC_Artifact_Manager::log_event(
                [
                    'stage'    => 'sitemap',
                    'status'   => 'empty',
                    'page_url' => $sitemap_url,
                    'message'  => __('No URLs found in sitemap.', 'dbvc'),
                ]
            );
            wp_send_json_error(['message' => __('No URLs found in the sitemap.', 'dbvc')]);
        }

        DBVC_CC_Artifact_Manager::log_event(
            [
                'stage'     => 'sitemap',
                'status'    => 'success',
                'page_url'  => $sitemap_url,
                'url_count' => count($urls),
            ]
        );

        $dbvc_cc_domain_key = $this->dbvc_cc_extract_domain_key_from_urls($urls, $sitemap_url);
        wp_send_json_success(
            [
                'urls' => array_values($urls),
                'domain' => $dbvc_cc_domain_key,
            ]
        );
    }

    /**
     * AJAX handler to process a single URL.
     */
    public function process_single_url() {
        check_ajax_referer(DBVC_CC_Contracts::AJAX_NONCE_ACTION, 'nonce');

        if (!isset($_POST['page_url']) || !filter_var($_POST['page_url'], FILTER_VALIDATE_URL)) {
            wp_send_json_error(['message' => __('Invalid page URL provided.', 'dbvc')]);
        }

        $page_url = esc_url_raw($_POST['page_url']);
        $crawl_overrides = $this->get_crawl_overrides_from_request();
        DBVC_CC_Artifact_Manager::log_event(
            [
                'stage'    => 'crawl',
                'status'   => 'started',
                'page_url' => $page_url,
                'message'  => 'Processing page crawl.',
            ]
        );

        $crawler = new DBVC_CC_Crawler_Service($page_url, $crawl_overrides);
        $result = $crawler->process_page();

        if (is_wp_error($result)) {
            DBVC_CC_Artifact_Manager::log_event(
                [
                    'stage'        => 'crawl',
                    'status'       => 'error',
                    'page_url'     => $page_url,
                    'failure_code' => $result->get_error_code(),
                    'message'      => $result->get_error_message(),
                ]
            );
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success(['message' => sprintf(__('Successfully processed %s.', 'dbvc'), $page_url)]);
    }

    /**
     * Triggers a full-domain AI refresh across all collected page artifacts.
     *
     * @return void
     */
    public function dbvc_cc_trigger_domain_ai_refresh() {
        check_ajax_referer(DBVC_CC_Contracts::AJAX_NONCE_ACTION, 'nonce');

        $dbvc_cc_domain = isset($_POST['domain']) ? sanitize_text_field(wp_unslash((string) $_POST['domain'])) : '';
        if ($dbvc_cc_domain === '' && isset($_POST['source_url'])) {
            $dbvc_cc_domain = $this->dbvc_cc_extract_domain_key_from_urls(
                [(string) wp_unslash($_POST['source_url'])],
                ''
            );
        }
        $dbvc_cc_domain = preg_replace('/[^a-z0-9.-]/', '', strtolower((string) $dbvc_cc_domain));

        if ($dbvc_cc_domain === '') {
            wp_send_json_error(['message' => __('A valid domain is required to run AI refresh.', 'dbvc')]);
        }

        $dbvc_cc_result = DBVC_CC_AI_Service::get_instance()->dbvc_cc_queue_domain_refresh(
            $dbvc_cc_domain,
            'crawl_domain_refresh',
            get_current_user_id(),
            false
        );

        if (is_wp_error($dbvc_cc_result)) {
            DBVC_CC_Artifact_Manager::log_event(
                [
                    'stage'        => 'ai',
                    'status'       => 'error',
                    'page_url'     => '',
                    'path'         => '',
                    'failure_code' => $dbvc_cc_result->get_error_code(),
                    'message'      => $dbvc_cc_result->get_error_message(),
                ]
            );

            wp_send_json_error(['message' => $dbvc_cc_result->get_error_message()]);
        }

        $dbvc_cc_health = isset($dbvc_cc_result['domain_ai_health']) && is_array($dbvc_cc_result['domain_ai_health'])
            ? $dbvc_cc_result['domain_ai_health']
            : DBVC_CC_AI_Service::get_instance()->dbvc_cc_get_domain_ai_health($dbvc_cc_domain);
        $dbvc_cc_warning_badge = ! empty($dbvc_cc_health['warning_badge']);
        $dbvc_cc_warning_message = isset($dbvc_cc_health['warning_message']) ? sanitize_text_field((string) $dbvc_cc_health['warning_message']) : '';

        wp_send_json_success(
            [
                'message' => isset($dbvc_cc_result['message']) ? (string) $dbvc_cc_result['message'] : __('Domain AI refresh queued.', 'dbvc'),
                'domain' => $dbvc_cc_domain,
                'batch_id' => isset($dbvc_cc_result['batch_id']) ? sanitize_key((string) $dbvc_cc_result['batch_id']) : '',
                'total_jobs' => isset($dbvc_cc_result['total_jobs']) ? absint($dbvc_cc_result['total_jobs']) : 0,
                'failed_jobs' => isset($dbvc_cc_result['failed_jobs']) ? absint($dbvc_cc_result['failed_jobs']) : 0,
                'warning_badge' => $dbvc_cc_warning_badge,
                'warning_message' => $dbvc_cc_warning_message,
                'domain_ai_health' => $dbvc_cc_health,
            ]
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function get_crawl_overrides_from_request() {
        if (!isset($_POST['crawl_overrides'])) {
            return [];
        }

        $raw_overrides = wp_unslash($_POST['crawl_overrides']);
        if (!is_array($raw_overrides)) {
            return [];
        }

        return DBVC_CC_Crawler_Service::sanitize_crawl_overrides($raw_overrides);
    }

    /**
     * @param array<int, mixed> $urls
     * @param string            $fallback_url
     * @return string
     */
    private function dbvc_cc_extract_domain_key_from_urls(array $urls, $fallback_url = '') {
        $dbvc_cc_candidates = $urls;
        if ($fallback_url !== '') {
            $dbvc_cc_candidates[] = (string) $fallback_url;
        }

        foreach ($dbvc_cc_candidates as $dbvc_cc_candidate_url) {
            $dbvc_cc_host = wp_parse_url((string) $dbvc_cc_candidate_url, PHP_URL_HOST);
            if (! is_string($dbvc_cc_host) || $dbvc_cc_host === '') {
                continue;
            }

            $dbvc_cc_host = strtolower($dbvc_cc_host);
            $dbvc_cc_host = preg_replace('/^www\./', '', $dbvc_cc_host);
            $dbvc_cc_domain_key = preg_replace('/[^a-z0-9.-]/', '', $dbvc_cc_host);
            if ($dbvc_cc_domain_key !== '') {
                return $dbvc_cc_domain_key;
            }
        }

        return '';
    }
}
