<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_V2_Ingestion_Package_Service
{
    /**
     * @var DBVC_CC_V2_Ingestion_Package_Service|null
     */
    private static $instance = null;

    /**
     * @return DBVC_CC_V2_Ingestion_Package_Service
     */
    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * @param array<string, mixed> $raw_artifact
     * @param array<string, mixed> $elements_payload
     * @param array<string, mixed> $sections_payload
     * @return array<string, mixed>
     */
    public function build_artifact(array $raw_artifact, array $elements_payload, array $sections_payload)
    {
        $legacy_scraped_data = [
            'page_name' => isset($raw_artifact['metadata']['title']) ? (string) $raw_artifact['metadata']['title'] : '',
            'slug' => isset($raw_artifact['slug']) ? (string) $raw_artifact['slug'] : '',
            'meta' => [
                'description' => isset($raw_artifact['metadata']['description']) ? (string) $raw_artifact['metadata']['description'] : '',
            ],
            'provenance' => [
                'content_hash' => isset($raw_artifact['content_hash']) ? (string) $raw_artifact['content_hash'] : '',
            ],
        ];

        $payload = DBVC_CC_Ingestion_Package_Service::build_artifact(
            $legacy_scraped_data,
            $elements_payload,
            $sections_payload,
            [],
            [],
            isset($raw_artifact['source_url']) ? (string) $raw_artifact['source_url'] : ''
        );

        $payload['journey_id'] = isset($raw_artifact['journey_id']) ? (string) $raw_artifact['journey_id'] : '';
        $payload['page_id'] = isset($raw_artifact['page_id']) ? (string) $raw_artifact['page_id'] : '';

        return $payload;
    }
}
