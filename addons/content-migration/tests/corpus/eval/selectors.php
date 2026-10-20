<?php
/**
 * V2 extraction rules, declared once for the corpus evaluator.
 *
 * The evaluator must measure what the shipped code actually does, not an approximation of it.
 * Every rule below is paired with the source file and the exact literal it was taken from, and
 * dbvc_corpus_verify_rules_against_source() re-greps those literals on every run. If V2's
 * extraction changes, the evaluator fails loudly instead of silently reporting a stale baseline.
 */

if (PHP_SAPI !== 'cli') {
    die;
}

/**
 * @return array<string, array<string, mixed>>
 */
function dbvc_corpus_rules()
{
    return [
        // DBVC_CC_Element_Extractor_Service::extract_artifacts() - feeds elements.v2 -> sections.v2
        // -> ingestion-package.v2, i.e. everything the mapping layer ever sees.
        'elements_xpath' => [
            'value' => './/h1 | .//h2 | .//h3 | .//h4 | .//h5 | .//h6 | .//p | .//li | .//blockquote | .//a | .//button | .//figcaption | .//label',
            'source' => 'addons/content-migration/content-context/dbvc-cc-element-extractor-service.php',
        ],
        // DBVC_CC_V2_Page_Capture_Service::collect_text_blocks()
        'text_blocks_xpath' => [
            'value' => './/p | .//li',
            'source' => 'addons/content-migration/v2/capture/dbvc-cc-v2-page-capture-service.php',
        ],
        // DBVC_CC_V2_Page_Capture_Service::collect_headings()
        'headings_xpath' => [
            'value' => './/h1 | .//h2 | .//h3 | .//h4 | .//h5 | .//h6',
            'source' => 'addons/content-migration/v2/capture/dbvc-cc-v2-page-capture-service.php',
        ],
        // DBVC_CC_V2_Page_Capture_Service::collect_images() - reads only the src attribute.
        'images_xpath' => [
            'value' => './/img',
            'source' => 'addons/content-migration/v2/capture/dbvc-cc-v2-page-capture-service.php',
        ],
        'images_src_attribute' => [
            'value' => "\$src = trim((string) \$node->getAttribute('src'));",
            'source' => 'addons/content-migration/v2/capture/dbvc-cc-v2-page-capture-service.php',
        ],
        // DBVC_CC_V2_URL_Scope_Service::canonicalize_url() - drops any non-http(s) scheme, which
        // is what silently discards lazy-loaded data: URI placeholders.
        'canonical_scheme_guard' => [
            'value' => "if (! in_array(\$scheme, ['http', 'https'], true)) {",
            'source' => 'addons/content-migration/v2/discovery/dbvc-cc-v2-url-scope-service.php',
        ],
        // DBVC_CC_V2_URL_Inventory_Service::build_inventory(). Before V3 slice S2a this read
        // `$journey_id === '' || $sitemap_url === ''`, i.e. a sitemap was mandatory and discovery
        // could not start without one. It now accepts an external URL list as an alternative while
        // still refusing to run with neither, which is what this literal pins.
        'inventory_accepts_external_list' => [
            'value' => "if (\$journey_id === '' || (\$sitemap_url === '' && \$external_urls === [])) {",
            'source' => 'addons/content-migration/v2/discovery/dbvc-cc-v2-url-inventory-service.php',
        ],
    ];
}

/**
 * Head fields read by DBVC_CC_V2_Page_Capture_Service::extract_metadata().
 *
 * @return array<int, string>
 */
function dbvc_corpus_captured_head_fields()
{
    return ['title', 'meta_description', 'og_keys'];
}

/**
 * Head fields present in sources but never read by extract_metadata().
 *
 * @return array<int, string>
 */
function dbvc_corpus_uncaptured_head_fields()
{
    return ['meta_robots', 'canonical', 'lang', 'source_modified', 'twitter_keys'];
}

/**
 * @param string $repository_root
 * @return array<int, string> Drift messages; empty means every rule still matches source.
 */
function dbvc_corpus_verify_rules_against_source($repository_root)
{
    $drift = [];
    foreach (dbvc_corpus_rules() as $key => $rule) {
        $path = rtrim($repository_root, '/') . '/' . $rule['source'];
        if (! is_file($path)) {
            $drift[] = sprintf('%s: source file missing (%s)', $key, $rule['source']);
            continue;
        }
        $contents = (string) file_get_contents($path);
        if (strpos($contents, $rule['value']) === false) {
            $drift[] = sprintf(
                '%s: literal no longer present in %s. The evaluator is measuring a stale rule; '
                . 'update selectors.php to match the shipped code before trusting any score.',
                $key,
                $rule['source']
            );
        }
    }

    return $drift;
}

/**
 * Tag names in the elements.v2 selector set, for exact ancestor-based coverage measurement.
 * Must stay in step with rules()['elements_xpath'].
 *
 * @return array<int, string>
 */
function dbvc_corpus_element_tags()
{
    return ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'li', 'blockquote', 'a', 'button', 'figcaption', 'label'];
}

/**
 * Tag names reachable by page-artifact.v1 capture (collect_text_blocks + collect_headings).
 *
 * @return array<int, string>
 */
function dbvc_corpus_capture_tags()
{
    return ['p', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'];
}
