<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_V2_Structured_Extraction_Service
{
    /**
     * @var DBVC_CC_V2_Structured_Extraction_Service|null
     */
    private static $instance = null;

    /**
     * @return DBVC_CC_V2_Structured_Extraction_Service
     */
    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * @param array<string, mixed>     $raw_artifact
     * @param DOMXPath                 $xpath
     * @param array<int, DOMNode>      $context_nodes
     * @param array<string, mixed>     $options
     * @return array<string, array<string, mixed>>
     */
    public function build_artifacts(array $raw_artifact, DOMXPath $xpath, array $context_nodes, array $options = [])
    {
        $source_url = isset($raw_artifact['source_url']) ? (string) $raw_artifact['source_url'] : '';
        $journey_id = isset($raw_artifact['journey_id']) ? (string) $raw_artifact['journey_id'] : '';
        $page_id = isset($raw_artifact['page_id']) ? (string) $raw_artifact['page_id'] : '';

        $elements_bundle = DBVC_CC_Element_Extractor_Service::extract_artifacts($xpath, $context_nodes, $source_url, $options);
        $elements_payload = isset($elements_bundle['elements']) && is_array($elements_bundle['elements']) ? $elements_bundle['elements'] : [];
        $scrub_report_payload = isset($elements_bundle['scrub_report']) && is_array($elements_bundle['scrub_report']) ? $elements_bundle['scrub_report'] : [];

        $elements_payload['journey_id'] = $journey_id;
        $elements_payload['page_id'] = $page_id;
        $elements_payload['stats'] = [
            'element_count' => isset($elements_payload['element_count']) ? (int) $elements_payload['element_count'] : 0,
            'warning_count' => isset($scrub_report_payload['warnings']) && is_array($scrub_report_payload['warnings']) ? count($scrub_report_payload['warnings']) : 0,
        ];

        $sections_payload = DBVC_CC_Section_Segmenter_Service::build_artifact($elements_payload, $source_url, $options);
        $sections_payload['journey_id'] = $journey_id;
        $sections_payload['page_id'] = $page_id;
        $sections_payload['stats'] = [
            'section_count' => isset($sections_payload['section_count']) ? (int) $sections_payload['section_count'] : 0,
            'is_partial' => ! empty($sections_payload['processing']['is_partial']),
        ];

        $ingestion_payload = DBVC_CC_V2_Ingestion_Package_Service::get_instance()->build_artifact(
            $raw_artifact,
            $elements_payload,
            $sections_payload
        );

        return [
            'elements' => $elements_payload,
            'sections' => $sections_payload,
            'ingestion_package' => $ingestion_payload,
            'scrub_report' => $scrub_report_payload,
        ];
    }
}
