<?php
/**
 * DBVC Content Collection corpus evaluator.
 *
 * Reports gates G-1..G-5 from docs/implementation/proposed/content-collection-v3/README.md
 * against the checked-in corpus slots. G-1..G-3 are measured here with no WordPress runtime, using
 * the same DOMDocument/DOMXPath parser and the same selector literals as the shipped V2 code
 * (see selectors.php, which fails the run if those literals drift).
 *
 * G-4 and G-5 need a target schema and therefore a WordPress bootstrap; they are reported as
 * not_measured with the reason, never estimated.
 *
 * Usage:
 *   php addons/content-migration/tests/corpus/eval/run.php [--slot=<name>] [--json] [--no-fail]
 *   DBVC_CORPUS_FIXTURES=/path/to/fixtures php .../run.php
 */

if (PHP_SAPI !== 'cli') {
    die;
}

require_once __DIR__ . '/selectors.php';
require_once dirname(__DIR__, 3) . '/interpretation/dbvc-cc-interp-contracts.php';
require_once dirname(__DIR__, 3) . '/interpretation/dbvc-cc-interp-floor.php';
require_once dirname(__DIR__, 3) . '/interpretation/dbvc-cc-interp-prompt.php';
require_once dirname(__DIR__, 3) . '/interpretation/dbvc-cc-interp-model.php';
require_once dirname(__DIR__, 3) . '/interpretation/dbvc-cc-interp-service.php';
require_once __DIR__ . '/input-builder.php';

const DBVC_CORPUS_G2_TEXT_THRESHOLD = 0.98;
const DBVC_CORPUS_G3_HEAD_THRESHOLD = 1.0;
const DBVC_CORPUS_G4_AUTO_RESOLVE_THRESHOLD = 0.80;
const DBVC_CORPUS_G5_MAX_WRONG = 0.02;

$options = dbvc_corpus_parse_args($argv);

if ($options['self_test']) {
    exit(dbvc_corpus_self_test());
}

if ($options['verify_scorer']) {
    exit(dbvc_corpus_verify_scorer(dirname(__DIR__) . '/slots'));
}
$repository_root = dirname(__DIR__, 5);
$corpus_root = dirname(__DIR__) . '/slots';

$drift = dbvc_corpus_verify_rules_against_source($repository_root);
if ($drift !== []) {
    fwrite(STDERR, "ABORT: V2 extraction rules have drifted from selectors.php\n");
    foreach ($drift as $message) {
        fwrite(STDERR, '  - ' . $message . "\n");
    }
    exit(2);
}

$slots = dbvc_corpus_discover_slots($corpus_root, $options['slot']);
if ($slots === []) {
    fwrite(STDERR, "No corpus slots found under {$corpus_root}\n");
    exit(2);
}

$report = [
    'generated_at' => gmdate('c'),
    'rules_verified_against_source' => true,
    'slots' => [],
];

foreach ($slots as $slot_name => $slot_dir) {
    $report['slots'][$slot_name] = dbvc_corpus_evaluate_slot($slot_name, $slot_dir, $options);
}

if ($options['json']) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
} else {
    dbvc_corpus_print_report($report);
}

exit(dbvc_corpus_exit_code($report, $options['no_fail']));

/**
 * Is a named rule currently present in the shipped source?
 *
 * The run already aborts when a recorded literal has drifted, so reaching here means every rule
 * matched. This asks whether a particular capability-defining literal is one of them.
 *
 * @param string $rule_key
 * @return bool
 */
function dbvc_corpus_rules_contain($rule_key)
{
    return array_key_exists($rule_key, dbvc_corpus_rules());
}

// ---------------------------------------------------------------------------- slot evaluation

/**
 * @param string $slot_name
 * @param string $slot_dir
 * @return array<string, mixed>
 */
function dbvc_corpus_evaluate_slot($slot_name, $slot_dir, array $options = [])
{
    $profile = dbvc_corpus_read_json($slot_dir . '/profile.json');
    $expected_urls = dbvc_corpus_read_json($slot_dir . '/expected-urls.json');
    $expected_content = dbvc_corpus_read_json($slot_dir . '/expected-content.json');

    $fixtures_root = dbvc_corpus_resolve_fixtures_root($profile);
    $missing_fixtures = dbvc_corpus_missing_fixtures($fixtures_root, $profile);

    $result = [
        'profile' => [
            'purpose' => $profile['slot_purpose'] ?? '',
            'platform' => $profile['platform']['cms'] ?? '',
            'builder' => $profile['platform']['builder'] ?? '',
            'coverage' => $profile['coverage'] ?? [],
        ],
        'fixtures_root' => $fixtures_root,
        'missing_fixtures' => $missing_fixtures,
        'gates' => [],
    ];

    $result['gates']['G-1'] = dbvc_corpus_gate_discovery($expected_urls);

    if ($missing_fixtures !== []) {
        $reason = 'fixture HTML not available at ' . $fixtures_root
            . ' (set DBVC_CORPUS_FIXTURES); raw client HTML is intentionally not committed';
        $result['gates']['G-2'] = ['status' => 'not_measured', 'reason' => $reason];
        $result['gates']['G-3'] = ['status' => 'not_measured', 'reason' => $reason];
    } else {
        $result['gates']['G-2'] = dbvc_corpus_gate_capture($expected_content, $fixtures_root);
        $result['gates']['G-3'] = dbvc_corpus_gate_head($expected_content, $fixtures_root);
    }

    $mapping = dbvc_corpus_gate_mapping($slot_dir, $profile, $fixtures_root, $missing_fixtures, $options);
    $result['gates']['G-4'] = $mapping['G-4'];
    $result['gates']['G-5'] = $mapping['G-5'];

    return $result;
}

/**
 * G-1: >=95% of real content URLs found; 100% of spam and utility URLs rejected.
 *
 * @param array<string, mixed> $expected
 * @return array<string, mixed>
 */
function dbvc_corpus_gate_discovery(array $expected)
{
    $content_total = count($expected['content_urls'] ?? []);
    $strategies = $expected['discovery_strategy_recall'] ?? [];
    $rows = [];

    foreach ($strategies as $name => $data) {
        if (! is_array($data) || ! isset($data['found'])) {
            continue;
        }
        $recall = $content_total > 0 ? ((int) $data['found']) / $content_total : 0.0;
        $rows[$name] = [
            'found' => (int) $data['found'],
            'of' => $content_total,
            'recall' => round($recall, 4),
            'passes' => $recall >= 0.95,
            'misses' => $data['misses'] ?? [],
        ];
    }

    // Which strategy's recall counts as "shipped" depends on what the shipped code can consume.
    // Before V3 slice S2a that was a sitemap and nothing else. It now accepts an external URL list,
    // so a caller supplying the union is a supported configuration and the union is the shipped
    // ceiling. Detected from source rather than assumed, so this cannot silently self-grade.
    $accepts_external = dbvc_corpus_rules_contain('inventory_accepts_external_list');
    $shipped_key = $accepts_external ? 'union_map_and_link_graph' : 'sitemap_only';
    $shipped = $rows[$shipped_key] ?? null;

    return [
        'status' => $shipped === null ? 'not_measured' : ($shipped['passes'] ? 'pass' : 'fail'),
        'measures' => $accepts_external
            ? 'build_inventory() accepts an external URL list, so the union a caller can supply is '
                . 'the shipped ceiling. sitemap_only is retained below as the pre-S2a baseline.'
            : 'DBVC ships sitemap-only discovery, so sitemap_only is the shipped score.',
        'shipped_strategy' => $shipped_key,
        'shipped_recall' => $shipped['recall'] ?? null,
        'pre_s2a_sitemap_only_recall' => $rows['sitemap_only']['recall'] ?? null,
        'threshold' => 0.95,
        'by_strategy' => $rows,
        'must_reject_count' => count($expected['must_reject'] ?? []),
        'must_report_not_crawl' => array_map(
            static function ($row) {
                return $row['path'] ?? '';
            },
            $expected['must_report_not_crawl'] ?? []
        ),
    ];
}

/**
 * G-2: capture fidelity, measured against the acquisition skill's artifacts.
 *
 * Re-pointed by decision Q5. These gates used to measure V2's in-WordPress extractors, which
 * cannot pass: one of G-2's three clauses requires every image with its alt text, and a page
 * builder renders hero and section imagery as CSS background-image, which exists in no HTML, no
 * srcset and no data-src. No amount of PHP reaches it. Measuring a path the product is retiring
 * produced a number nobody could act on, so the gate now measures the path it actually uses.
 *
 * The retired path is still measured and reported, clearly labelled and not gating, so the
 * historical figure stays visible without being mistaken for a work item.
 *
 * Clauses, all of which must hold:
 *   1. every required_text probe appears in the captured content
 *   2. every non-chrome ground-truth heading appears, at the right level
 *   3. every non-chrome ground-truth image appears, with alt text where the source has it
 *
 * The raw percentage clause is gone on purpose. Its denominator is visible text taken from the raw
 * HTML including site chrome, and the skill deliberately collects chrome once into site-level data
 * instead of repeating it per page, so the figure could never reach 100% however good the capture
 * was. It is reported as a diagnostic. The probes are the discriminating measure and always were.
 *
 * @param array<string, mixed> $expected
 * @param string               $fixtures_root
 * @return array<string, mixed>
 */
function dbvc_corpus_gate_capture(array $expected, $fixtures_root)
{
    $artifacts_dir = rtrim($fixtures_root, '/') . '/dbvc-artifacts';
    if (! is_dir($artifacts_dir)) {
        return [
            'status' => 'not_measured',
            'reason' => 'no acquisition artifacts at ' . $artifacts_dir
                . '. Run the skill\'s emit_artifacts.py against the capture first.',
        ];
    }

    $pages = [];
    $all_pass = true;

    foreach ($expected['pages'] ?? [] as $page) {
        $slug = (string) $page['slug'];
        $artifact_file = $artifacts_dir . '/' . $slug . '.page-artifact.v1.json';
        $elements_file = $artifacts_dir . '/' . $slug . '.elements.v2.json';
        if (! is_file($artifact_file) || ! is_file($elements_file)) {
            $pages[$slug] = ['pass' => false, 'error' => 'artifacts missing for this page'];
            $all_pass = false;
            continue;
        }

        $artifact = dbvc_corpus_read_json($artifact_file);
        $elements = dbvc_corpus_read_json($elements_file);

        // Everything the capture holds as text, including the inline markup it preserved.
        $haystack = (string) ($artifact['source_markdown'] ?? '');
        foreach ($elements['elements'] ?? [] as $element) {
            $haystack .= ' ' . (string) ($element['text'] ?? '');
            $haystack .= ' ' . (string) ($element['text_rich'] ?? '');
        }
        $haystack = dbvc_corpus_normalize($haystack);

        $missed_probes = [];
        foreach ($page['required_text'] ?? [] as $probe) {
            if (strpos($haystack, dbvc_corpus_normalize($probe)) === false) {
                $missed_probes[] = $probe;
            }
        }

        // Headings: ground truth must be present at the right level. Extras are not a failure;
        // the capture may legitimately surface a heading the ground truth did not probe for.
        // Keyed to a SET of levels, not one: the same text can legitimately appear at two levels
        // on one page - /services carries "Services" as both its h1 and a section h2 - and keeping
        // only the last occurrence made a correct capture look like a level mismatch.
        $captured_levels = [];
        foreach ($artifact['headings'] ?? [] as $heading) {
            $key = dbvc_corpus_normalize((string) $heading['text']);
            $captured_levels[$key][] = (int) $heading['level'];
        }
        $expected_headings = 0;
        $missed_headings = [];
        $wrong_level = [];
        foreach ($page['heading_tree'] ?? [] as $heading) {
            if (! empty($heading['is_site_chrome'])) {
                continue;
            }
            $expected_headings++;
            $key = dbvc_corpus_normalize((string) $heading['text']);
            if (! isset($captured_levels[$key])) {
                $missed_headings[] = $heading['text'];
                continue;
            }
            if (! in_array((int) $heading['level'], $captured_levels[$key], true)) {
                $wrong_level[] = sprintf(
                    '%s: expected h%d, captured at h%s',
                    $heading['text'],
                    (int) $heading['level'],
                    implode('/h', array_unique($captured_levels[$key]))
                );
            }
        }

        // Images: every non-chrome ground-truth image, from either mechanism. alt is required only
        // where the source has it - a CSS background has no alt text to capture.
        $captured_images = [];
        foreach ($artifact['images'] ?? [] as $image) {
            $captured_images[(string) $image['url']] = $image;
        }
        $expected_images = 0;
        $missed_images = [];
        $missed_alt = [];
        foreach ($page['images'] ?? [] as $image) {
            if (! empty($image['is_site_chrome']) || empty($image['url'])) {
                continue;
            }
            $expected_images++;
            $url = (string) $image['url'];
            if (! isset($captured_images[$url])) {
                $missed_images[] = basename($url);
                continue;
            }
            if (! empty($image['alt']) && empty($captured_images[$url]['alt'])) {
                $missed_alt[] = basename($url);
            }
        }

        // Retired-path diagnostic, not gating.
        $retired = dbvc_corpus_retired_path_coverage($page, $fixtures_root);

        $pass = $missed_probes === []
            && $missed_headings === []
            && $wrong_level === []
            && $missed_images === []
            && $missed_alt === [];
        $all_pass = $all_pass && $pass;

        $pages[$slug] = [
            'pass' => $pass,
            'required_text_probes' => count($page['required_text'] ?? []),
            'required_text_missed' => $missed_probes,
            'headings_expected' => $expected_headings,
            'headings_missed' => $missed_headings,
            'heading_level_mismatches' => $wrong_level,
            'images_expected' => $expected_images,
            'images_missed' => $missed_images,
            'images_missing_alt' => $missed_alt,
            'retired_php_path' => $retired,
        ];
    }

    return [
        'status' => $pages === [] ? 'not_measured' : ($all_pass ? 'pass' : 'fail'),
        'measures' => 'the acquisition skill\'s page-artifact.v1 and elements.v2 (decision Q5)',
        'pages' => $pages,
    ];
}

/**
 * What V2's retired in-WordPress extractors reach, for the record only.
 *
 * @param array<string, mixed> $page
 * @param string               $fixtures_root
 * @return array<string, mixed>
 */
function dbvc_corpus_retired_path_coverage(array $page, $fixtures_root)
{
    $path = rtrim($fixtures_root, '/') . '/' . (string) ($page['fixture'] ?? '');
    if (! is_file($path)) {
        return ['measured' => false];
    }

    $xpath = dbvc_corpus_xpath((string) file_get_contents($path));
    $total = dbvc_corpus_visible_text_length($xpath);
    $reached = dbvc_corpus_reachable_text_length($xpath, dbvc_corpus_element_tags());
    $images = dbvc_corpus_images_v2($xpath);

    return [
        'measured' => true,
        'text_coverage' => $total > 0 ? round($reached / $total, 4) : 0.0,
        'images_kept' => $images['kept'],
        'images_dropped_non_http_scheme' => $images['dropped_scheme'],
        'heading_levels_preserved' => false,
    ];
}

/**
 * G-3: every head field the source carries must survive capture, and an absent one must stay null.
 *
 * Re-pointed with G-2 by decision Q5: measured against the acquisition skill's page-artifact.v1
 * metadata block rather than V2's extract_metadata(), which reads four fields of the seven.
 *
 * @param array<string, mixed> $expected
 * @param string               $fixtures_root
 * @return array<string, mixed>
 */
function dbvc_corpus_gate_head(array $expected, $fixtures_root)
{
    $artifacts_dir = rtrim($fixtures_root, '/') . '/dbvc-artifacts';
    $field_map = [
        'title' => 'title',
        'meta_description' => 'description',
        'meta_robots' => 'robots',
        'canonical' => 'canonical',
        'lang' => 'lang',
        'source_modified' => 'source_modified_at',
        'og_keys' => 'opengraph',
        'twitter_keys' => 'twitter',
    ];

    $pages = [];
    $all_pass = true;

    foreach ($expected['pages'] ?? [] as $page) {
        $slug = (string) $page['slug'];
        $artifact_file = $artifacts_dir . '/' . $slug . '.page-artifact.v1.json';
        if (! is_file($artifact_file)) {
            $pages[$slug] = ['pass' => false, 'error' => 'artifact missing for this page'];
            $all_pass = false;
            continue;
        }

        $metadata = dbvc_corpus_read_json($artifact_file)['metadata'] ?? [];
        $present = [];
        $captured = [];
        $dropped = [];
        $absent = [];
        $wrongly_invented = [];

        foreach ($field_map as $truth_key => $artifact_key) {
            $truth_value = $page['head_fields'][$truth_key] ?? null;
            $is_present = is_array($truth_value) ? $truth_value !== [] : ($truth_value !== null && $truth_value !== '');
            $actual = $metadata[$artifact_key] ?? null;
            $has_actual = is_array($actual) ? $actual !== [] : ($actual !== null && $actual !== '');

            if (! $is_present) {
                $absent[] = $truth_key;
                if ($has_actual) {
                    // An absent field must stay null. Inventing one is worse than dropping it.
                    $wrongly_invented[] = $truth_key;
                }
                continue;
            }

            $present[] = $truth_key;
            if ($has_actual) {
                $captured[] = $truth_key;
            } else {
                $dropped[] = $truth_key;
            }
        }

        $ratio = $present === [] ? 1.0 : count($captured) / count($present);
        $pass = $dropped === [] && $wrongly_invented === [];
        $all_pass = $all_pass && $pass;

        $pages[$slug] = [
            'pass' => $pass,
            'present_fields' => $present,
            'captured_fields' => $captured,
            'uncaptured_present_fields' => $dropped,
            'absent_in_source' => $absent,
            'wrongly_invented' => $wrongly_invented,
            'ratio' => round($ratio, 4),
        ];
    }

    return [
        'status' => $pages === [] ? 'not_measured' : ($all_pass ? 'pass' : 'fail'),
        'threshold' => DBVC_CORPUS_G3_HEAD_THRESHOLD,
        'measures' => 'the acquisition skill\'s page-artifact.v1 metadata block (decision Q5)',
        'pages' => $pages,
    ];
}

/**
 * G-4 auto-resolve rate and G-5 mapping precision, measured headless.
 *
 * The interpretation layer is WordPress-independent by design, so both the deterministic floor and
 * (once connected) the model can be scored here on identical inputs. Pages carrying a
 * structural_blocker are excluded rather than scored on an impossible task.
 *
 * @param string               $slot_dir
 * @param array<string, mixed> $profile
 * @param string               $fixtures_root
 * @param array<int, string>   $missing_fixtures
 * @return array<string, array<string, mixed>>
 */
function dbvc_corpus_gate_mapping($slot_dir, array $profile, $fixtures_root, array $missing_fixtures, array $options = [])
{
    $interpreter = (string) ($options['interpreter'] ?? DBVC_CC_Interp_Contracts::INTERPRETER_FLOOR);
    $use_model = $interpreter === DBVC_CC_Interp_Contracts::INTERPRETER_MODEL;

    if ($use_model) {
        $provider = (string) ($options['provider'] ?? '') !== ''
            ? (string) $options['provider']
            : DBVC_CC_Interp_Model::PROVIDER_ANTHROPIC;
        if (DBVC_CC_Interp_Model::resolve_api_key($provider) === '') {
            $constant = $provider === DBVC_CC_Interp_Model::PROVIDER_ANTHROPIC
                ? 'DBVC_CC_ANTHROPIC_API_KEY'
                : 'DBVC_CC_OPENAI_API_KEY';
            $reason = sprintf(
                'model interpreter requested but no API key is available for provider "%s". '
                . 'Export %s (or define it in wp-config.php) and re-run. Nothing is estimated in its absence.',
                $provider,
                $constant
            );

            return [
                'G-4' => ['status' => 'not_measured', 'reason' => $reason],
                'G-5' => ['status' => 'not_measured', 'reason' => $reason],
            ];
        }
    }
    $schema_file = $slot_dir . '/' . (string) ($profile['target_schema'] ?? 'target-schema.v1.json');
    $expected_file = $slot_dir . '/expected-mappings.json';

    if (! is_file($schema_file) || ! is_file($expected_file)) {
        $reason = 'this slot pins no target schema or expected mappings yet';

        return [
            'G-4' => ['status' => 'not_measured', 'reason' => $reason],
            'G-5' => ['status' => 'not_measured', 'reason' => $reason],
        ];
    }
    if ($missing_fixtures !== []) {
        $reason = 'fixture markdown not available at ' . $fixtures_root . ' (set DBVC_CORPUS_FIXTURES)';

        return [
            'G-4' => ['status' => 'not_measured', 'reason' => $reason],
            'G-5' => ['status' => 'not_measured', 'reason' => $reason],
        ];
    }

    $schema = dbvc_corpus_read_json($schema_file);
    $expected = dbvc_corpus_read_json($expected_file);

    $schema_errors = DBVC_CC_Interp_Contracts::validate_schema($schema);
    if ($schema_errors !== []) {
        $reason = 'target schema invalid: ' . implode('; ', $schema_errors);

        return [
            'G-4' => ['status' => 'not_measured', 'reason' => $reason],
            'G-5' => ['status' => 'not_measured', 'reason' => $reason],
        ];
    }

    $pages = [];
    $excluded = [];
    $totals = ['recommended' => 0, 'auto_accepted' => 0, 'correct' => 0, 'wrong' => 0,
        'unscorable' => 0, 'all_correct' => 0, 'all_wrong' => 0];

    foreach ($expected['pages'] ?? [] as $page_expectation) {
        $slug = (string) ($page_expectation['slug'] ?? '');
        if (isset($page_expectation['structural_blocker'])) {
            $excluded[$slug] = $page_expectation['structural_blocker'];
            continue;
        }
        $capture_file = $fixtures_root . '/pages/' . $slug . '.json';
        if (! is_file($capture_file)) {
            continue;
        }
        $input = dbvc_corpus_build_interpretation_input_from_capture(
            dbvc_corpus_read_json($capture_file),
            $slug
        );
        $input_errors = DBVC_CC_Interp_Contracts::validate_input($input);
        if ($input_errors !== []) {
            $pages[$slug] = ['error' => 'input invalid: ' . implode('; ', $input_errors)];
            continue;
        }

        $result = DBVC_CC_Interp_Service::interpret(
            $input,
            $schema,
            (string) ($page_expectation['expected_object_key'] ?? ''),
            [
                'use_model' => $use_model,
                'provider' => (string) ($options['provider'] ?? '') !== ''
                    ? (string) $options['provider']
                    : DBVC_CC_Interp_Model::PROVIDER_ANTHROPIC,
                'model' => (string) ($options['model'] ?? ''),
                'cache_dir' => sys_get_temp_dir() . '/dbvc-corpus-model-cache',
            ]
        );
        $result_errors = DBVC_CC_Interp_Contracts::validate_result($result);

        $scored = dbvc_corpus_score_recommendations($result, $page_expectation);
        $scored['provenance_violations'] = $result_errors;
        $scored['items_in'] = $result['stats']['items_in'];
        $scored['declined'] = $result['stats']['declined'];
        $scored['interpreter_used'] = (string) $result['interpreter'];
        $scored['model_attempt'] = $result['model_attempt'] ?? ['used' => false];
        $pages[$slug] = $scored;

        $totals['recommended'] += $scored['recommended'];
        $totals['auto_accepted'] += $scored['auto_accepted'];
        $totals['correct'] += $scored['auto_correct'];
        $totals['wrong'] += $scored['auto_wrong'];
        $totals['unscorable'] += $scored['auto_unscorable'];
        $totals['all_correct'] += $scored['all_correct'];
        $totals['all_wrong'] += $scored['all_wrong'];
    }

    $auto_rate = $totals['recommended'] > 0 ? $totals['auto_accepted'] / $totals['recommended'] : 0.0;
    $wrong_rate = $totals['auto_accepted'] > 0 ? $totals['wrong'] / $totals['auto_accepted'] : 0.0;

    $used = [];
    foreach ($pages as $page) {
        if (isset($page['interpreter_used'])) {
            $used[$page['interpreter_used']] = true;
        }
    }
    $common = [
        'interpreter' => $interpreter,
        'interpreter_used' => implode('+', array_keys($used)) ?: $interpreter,
        'interpreter_note' => $use_model
            ? 'model interpreter requested; per-page fallbacks are reported below'
            : 'deterministic floor only (pass --interpreter=model to measure the model)',
        'pages' => $pages,
        'excluded_pages' => $excluded,
        'totals' => $totals,
    ];

    return [
        'G-4' => array_merge($common, [
            'status' => $totals['recommended'] === 0 ? 'not_measured' : ($auto_rate >= DBVC_CORPUS_G4_AUTO_RESOLVE_THRESHOLD ? 'pass' : 'fail'),
            'auto_resolve_rate' => round($auto_rate, 4),
            'threshold' => DBVC_CORPUS_G4_AUTO_RESOLVE_THRESHOLD,
        ]),
        'G-5' => array_merge($common, [
            'status' => $totals['auto_accepted'] === 0
                ? 'not_measured'
                : ($wrong_rate <= DBVC_CORPUS_G5_MAX_WRONG ? 'pass' : 'fail'),
            'wrong_rate' => round($wrong_rate, 4),
            'threshold_max_wrong' => DBVC_CORPUS_G5_MAX_WRONG,
            'not_measured_reason' => $totals['auto_accepted'] === 0
                ? sprintf(
                    'the %s auto-accepted nothing, so precision has no denominator - which is '
                    . 'itself the G-4 result, not a gap in the harness',
                    implode('+', array_keys($used)) ?: $interpreter
                )
                : null,
        ]),
    ];
}

/**
 * Match each recommendation to an expectation by source-text probe, then classify it.
 *
 * @param array<string, mixed> $result
 * @param array<string, mixed> $expectation
 * @return array<string, mixed>
 */
function dbvc_corpus_score_recommendations(array $result, array $expectation)
{
    $recommendations = $result['recommendations'] ?? [];
    $auto_correct = 0;
    $auto_wrong = 0;
    $auto_unscorable = 0;
    $all_correct = 0;
    $all_wrong = 0;
    $wrong_detail = [];
    $expectations_hit = [];

    // Every recommendation is classified, not only the auto-accepted ones. Precision (G-5) scores
    // the auto-accepted subset, because those are what no reviewer ever sees. But an interpreter
    // that picks the right field with low confidence and one that picks the wrong field need
    // opposite fixes, and only the all-recommendations view separates them.
    foreach ($recommendations as $recommendation) {
        $auto_accepted = empty($recommendation['requires_review']);
        $evidence = strtolower((string) ($recommendation['source_evidence'] ?? ''));
        $target = (string) ($recommendation['target_ref'] ?? '');
        $verdict = null;

        foreach ($expectation['mappings'] ?? [] as $mapping) {
            $probe = strtolower((string) ($mapping['source_probe'] ?? ''));
            if ($probe !== '' && strpos($evidence, $probe) !== false) {
                $expectations_hit[$probe] = true;
                $verdict = $target === (string) $mapping['target_ref'] ? 'correct' : 'wrong';
                if ($verdict === 'wrong') {
                    $wrong_detail[] = [
                        'probe' => $mapping['source_probe'],
                        'expected' => $mapping['target_ref'],
                        'got' => $target,
                        'confidence' => $recommendation['confidence'] ?? null,
                        'auto_accepted' => $auto_accepted,
                        'why' => 'wrong field',
                    ];
                }
                break;
            }
        }

        if ($verdict === null) {
            foreach ($expectation['must_not_map'] ?? [] as $forbidden) {
                $probe = strtolower((string) ($forbidden['source_probe'] ?? ''));
                if ($probe !== '' && strpos($evidence, $probe) !== false) {
                    $verdict = 'wrong';
                    $wrong_detail[] = [
                        'probe' => $forbidden['source_probe'],
                        'expected' => $forbidden['belongs_to'] ?? '(nothing - not content)',
                        'got' => $target,
                        'why' => 'must_not_map: ' . (string) ($forbidden['reason'] ?? ''),
                    ];
                    break;
                }
            }
        }

        if ($verdict === null) {
            foreach ($expectation['expected_unmapped'] ?? [] as $gap) {
                $probe = strtolower((string) ($gap['source_probe'] ?? ''));
                if ($probe !== '' && strpos($evidence, $probe) !== false) {
                    $verdict = 'wrong';
                    $wrong_detail[] = [
                        'probe' => $gap['source_probe'],
                        'expected' => '(declined - no fitting field)',
                        'got' => $target,
                        'why' => 'forced an expected_unmapped item into a field',
                    ];
                    break;
                }
            }
        }

        if ($verdict === 'correct') {
            $all_correct++;
            if ($auto_accepted) {
                $auto_correct++;
            }
        } elseif ($verdict === 'wrong') {
            $all_wrong++;
            if ($auto_accepted) {
                $auto_wrong++;
            }
        } elseif ($auto_accepted) {
            $auto_unscorable++;
        }
    }

    $expected_total = count($expectation['mappings'] ?? []);

    return [
        'recommended' => count($recommendations),
        'auto_accepted' => count(array_filter(
            $recommendations,
            static function ($recommendation) {
                return empty($recommendation['requires_review']);
            }
        )),
        'auto_correct' => $auto_correct,
        'auto_wrong' => $auto_wrong,
        'auto_unscorable' => $auto_unscorable,
        'wrong_detail' => $wrong_detail,
        'all_correct' => $all_correct,
        'all_wrong' => $all_wrong,
        'all_accuracy' => ($all_correct + $all_wrong) > 0
            ? round($all_correct / ($all_correct + $all_wrong), 4)
            : 0.0,
        'expected_mappings' => $expected_total,
        'expected_mappings_hit' => count($expectations_hit),
        'expectation_recall' => $expected_total > 0 ? round(count($expectations_hit) / $expected_total, 4) : 0.0,
    ];
}

// ---------------------------------------------------------------------------- DOM measurement

/**
 * @param string $html
 * @return DOMXPath
 */
function dbvc_corpus_xpath($html)
{
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    @$doc->loadHTML($html);
    libxml_clear_errors();

    return new DOMXPath($doc);
}

/**
 * Visible text excludes script/style/noscript/head, matching what a reader sees.
 *
 * @param DOMXPath $xpath
 * @return int
 */
function dbvc_corpus_visible_text_length(DOMXPath $xpath)
{
    $nodes = $xpath->query(
        '//text()[not(ancestor::script) and not(ancestor::style) and not(ancestor::noscript) and not(ancestor::head)]'
    );
    $length = 0;
    if ($nodes instanceof DOMNodeList) {
        foreach ($nodes as $node) {
            $length += strlen(dbvc_corpus_normalize($node->textContent));
        }
    }

    return $length;
}

/**
 * Visible text characters that sit inside at least one element matching $tags.
 *
 * This is deliberately an ancestor test over text nodes, not a sum of element textContent:
 * the selector set matches both a <p> and an <a> nested inside it, so summing textContent
 * double-counts link-dense regions and can exceed 100%. Every character is counted once here,
 * so the ratio is a true fraction of what a reader sees.
 *
 * @param DOMXPath          $xpath
 * @param array<int,string> $tags
 * @return int
 */
function dbvc_corpus_reachable_text_length(DOMXPath $xpath, array $tags)
{
    $ancestors = [];
    foreach ($tags as $tag) {
        $ancestors[] = 'ancestor::' . $tag;
    }
    $query = '//text()[not(ancestor::script) and not(ancestor::style) and not(ancestor::noscript)'
        . ' and not(ancestor::head)][' . implode(' or ', $ancestors) . ']';

    $nodes = $xpath->query($query);
    $length = 0;
    if ($nodes instanceof DOMNodeList) {
        foreach ($nodes as $node) {
            $length += strlen(dbvc_corpus_normalize($node->textContent));
        }
    }

    return $length;
}

/**
 * How many times over the selector set repeats the same characters, because nested matches each
 * become their own element in elements.v2. 1.0 means no duplication.
 *
 * @param DOMXPath          $xpath
 * @param string            $query
 * @param array<int,string> $tags
 * @return float
 */
function dbvc_corpus_duplication_factor(DOMXPath $xpath, $query, array $tags)
{
    $reachable = dbvc_corpus_reachable_text_length($xpath, $tags);
    if ($reachable === 0) {
        return 0.0;
    }
    $summed = 0;
    foreach (dbvc_corpus_texts_for($xpath, $query) as $text) {
        $summed += strlen($text);
    }

    return round($summed / $reachable, 3);
}

/**
 * @param DOMXPath $xpath
 * @param string   $query
 * @return string
 */
function dbvc_corpus_text_blob_for(DOMXPath $xpath, $query)
{
    return implode(' ', dbvc_corpus_texts_for($xpath, $query));
}

/**
 * @param DOMXPath $xpath
 * @param string   $query
 * @return array<int, string>
 */
function dbvc_corpus_texts_for(DOMXPath $xpath, $query)
{
    $nodes = $xpath->query($query, $xpath->document);
    $texts = [];
    $seen = [];
    if (! ($nodes instanceof DOMNodeList)) {
        return $texts;
    }
    foreach ($nodes as $node) {
        if (! ($node instanceof DOMElement)) {
            continue;
        }
        $text = dbvc_corpus_normalize($node->textContent);
        if ($text === '') {
            continue;
        }
        $key = $node->getNodePath() . '|' . hash('sha256', $text);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $texts[] = $text;
    }

    return $texts;
}

/**
 * @param DOMXPath $xpath
 * @return array<int, array<string, mixed>>
 */
function dbvc_corpus_headings(DOMXPath $xpath)
{
    $rules = dbvc_corpus_rules();
    $nodes = $xpath->query($rules['headings_xpath']['value'], $xpath->document);
    $out = [];
    $seen = [];
    if ($nodes instanceof DOMNodeList) {
        foreach ($nodes as $node) {
            if (! ($node instanceof DOMElement)) {
                continue;
            }
            $text = dbvc_corpus_normalize($node->textContent);
            // collect_headings() dedupes by text alone and discards the level.
            if ($text === '' || isset($seen[$text])) {
                continue;
            }
            $seen[$text] = true;
            $out[] = ['text' => $text];
        }
    }

    return $out;
}

/**
 * Replays collect_images(): read src, absolutise, then drop anything canonicalize_url() rejects.
 *
 * @param DOMXPath $xpath
 * @return array{kept:int, dropped_scheme:int, dropped_empty:int}
 */
function dbvc_corpus_images_v2(DOMXPath $xpath)
{
    $rules = dbvc_corpus_rules();
    $nodes = $xpath->query($rules['images_xpath']['value'], $xpath->document);
    $kept = [];
    $dropped_scheme = 0;
    $dropped_empty = 0;

    if ($nodes instanceof DOMNodeList) {
        foreach ($nodes as $node) {
            if (! ($node instanceof DOMElement)) {
                continue;
            }
            $src = trim((string) $node->getAttribute('src'));
            if ($src === '') {
                $dropped_empty++;
                continue;
            }
            $scheme = strtolower((string) parse_url($src, PHP_URL_SCHEME));
            if ($scheme !== '' && ! in_array($scheme, ['http', 'https'], true)) {
                $dropped_scheme++;
                continue;
            }
            $kept[$src] = true;
        }
    }

    return [
        'kept' => count($kept),
        'dropped_scheme' => $dropped_scheme,
        'dropped_empty' => $dropped_empty,
    ];
}

/**
 * @param string $text
 * @return string
 */
function dbvc_corpus_normalize($text)
{
    $value = preg_replace('/\s+/u', ' ', (string) $text);

    return trim(is_string($value) ? $value : '');
}

// ---------------------------------------------------------------------------- plumbing

/**
 * @param array<int, string> $argv
 * @return array<string, mixed>
 */
function dbvc_corpus_parse_args(array $argv)
{
    $options = ['slot' => '', 'json' => false, 'no_fail' => false, 'self_test' => false,
        'interpreter' => DBVC_CC_Interp_Contracts::INTERPRETER_FLOOR, 'provider' => '', 'model' => '',
        'verify_scorer' => false];
    foreach (array_slice($argv, 1) as $arg) {
        if (strpos($arg, '--slot=') === 0) {
            $options['slot'] = substr($arg, 7);
        } elseif ($arg === '--json') {
            $options['json'] = true;
        } elseif ($arg === '--no-fail') {
            $options['no_fail'] = true;
        } elseif ($arg === '--self-test') {
            $options['self_test'] = true;
        } elseif ($arg === '--verify-scorer') {
            $options['verify_scorer'] = true;
        } elseif (strpos($arg, '--interpreter=') === 0) {
            $options['interpreter'] = substr($arg, 14);
        } elseif (strpos($arg, '--provider=') === 0) {
            $options['provider'] = substr($arg, 11);
        } elseif (strpos($arg, '--model=') === 0) {
            $options['model'] = substr($arg, 8);
        }
    }

    return $options;
}

/**
 * @param string $corpus_root
 * @param string $filter
 * @return array<string, string>
 */
function dbvc_corpus_discover_slots($corpus_root, $filter)
{
    $slots = [];
    foreach (glob($corpus_root . '/*/*', GLOB_ONLYDIR) ?: [] as $dir) {
        if (! is_file($dir . '/profile.json')) {
            continue;
        }
        $name = basename(dirname($dir)) . '/' . basename($dir);
        if ($filter !== '' && strpos($name, $filter) === false) {
            continue;
        }
        $slots[$name] = $dir;
    }
    ksort($slots);

    return $slots;
}

/**
 * @param array<string, mixed> $profile
 * @return string
 */
function dbvc_corpus_resolve_fixtures_root(array $profile)
{
    $env = getenv($profile['fixtures']['root_env'] ?? 'DBVC_CORPUS_FIXTURES');
    $root = is_string($env) && $env !== '' ? $env : (string) ($profile['fixtures']['default_root'] ?? '');
    if (strpos($root, '~') === 0) {
        $home = getenv('HOME');
        $root = (is_string($home) ? $home : '') . substr($root, 1);
    }

    return rtrim($root, '/');
}

/**
 * @param string               $fixtures_root
 * @param array<string, mixed> $profile
 * @return array<int, string>
 */
function dbvc_corpus_missing_fixtures($fixtures_root, array $profile)
{
    $missing = [];
    foreach ($profile['fixtures']['required_files'] ?? [] as $relative) {
        if (! is_file($fixtures_root . '/' . $relative)) {
            $missing[] = $relative;
        }
    }

    return $missing;
}

/**
 * @param string $path
 * @return array<string, mixed>
 */
function dbvc_corpus_read_json($path)
{
    if (! is_file($path)) {
        fwrite(STDERR, "Missing corpus file: {$path}\n");
        exit(2);
    }
    $decoded = json_decode((string) file_get_contents($path), true);
    if (! is_array($decoded)) {
        fwrite(STDERR, "Corpus file is not valid JSON: {$path}\n");
        exit(2);
    }

    return $decoded;
}

/**
 * Calibrates the measuring instrument. Run with --verify-scorer.
 *
 * S1b's whole purpose is to produce a trustworthy model number. A scorer that silently mis-matched
 * probes would produce a confident wrong answer about whether the model works - the exact failure
 * this project exists to stop making. So: synthesise the response a perfect interpreter would give,
 * taken straight from expected-mappings.json, push it through the real orchestrator and the real
 * scorer, and require a perfect score back.
 *
 * This measures the harness. It is NOT a model measurement and must never be reported as one.
 *
 * @param string $corpus_root
 * @return int
 */
function dbvc_corpus_verify_scorer($corpus_root)
{
    $slots = dbvc_corpus_discover_slots($corpus_root, '');
    $failures = [];
    $checked = 0;

    foreach ($slots as $slot_name => $slot_dir) {
        $profile = dbvc_corpus_read_json($slot_dir . '/profile.json');
        $schema_file = $slot_dir . '/' . (string) ($profile['target_schema'] ?? 'target-schema.v1.json');
        $expected_file = $slot_dir . '/expected-mappings.json';
        $fixtures_root = dbvc_corpus_resolve_fixtures_root($profile);
        if (! is_file($schema_file) || ! is_file($expected_file)
            || dbvc_corpus_missing_fixtures($fixtures_root, $profile) !== []) {
            continue;
        }

        $schema = dbvc_corpus_read_json($schema_file);
        $expected = dbvc_corpus_read_json($expected_file);
        foreach ($expected['pages'] ?? [] as $page_expectation) {
            $slug = (string) ($page_expectation['slug'] ?? '');
            $capture_file = $fixtures_root . '/pages/' . $slug . '.json';
            if (isset($page_expectation['structural_blocker']) || ! is_file($capture_file)) {
                continue;
            }
            $input = dbvc_corpus_build_interpretation_input_from_capture(
                dbvc_corpus_read_json($capture_file),
                $slug
            );

            // The oracle: for each expectation, map the item whose text contains the probe.
            $mappings = [];
            foreach ($page_expectation['mappings'] ?? [] as $expectation) {
                $probe = strtolower((string) $expectation['source_probe']);
                foreach ($input['items'] as $item) {
                    if ($probe !== '' && strpos(strtolower((string) $item['text']), $probe) !== false) {
                        $mappings[] = [
                            'item_id' => $item['item_id'],
                            'target_ref' => (string) $expectation['target_ref'],
                            'confidence' => 0.97,
                            'rationale' => 'oracle',
                        ];
                        break;
                    }
                }
            }
            if ($mappings === []) {
                $failures[] = "{$slot_name}/{$slug}: oracle matched no items at all - the probes or the input builder are broken";
                continue;
            }

            $payload = (string) json_encode(['mappings' => $mappings, 'declined' => []]);
            $result = DBVC_CC_Interp_Service::interpret($input, $schema, (string) $page_expectation['expected_object_key'], [
                'api_key' => 'oracle',
                'provider' => DBVC_CC_Interp_Model::PROVIDER_ANTHROPIC,
                'transport' => static function () use ($payload) {
                    return [
                        'status' => 200,
                        'body' => (string) json_encode([
                            'model' => 'oracle',
                            'content' => [['type' => 'text', 'text' => $payload]],
                        ]),
                    ];
                },
            ]);

            if (($result['interpreter'] ?? '') !== DBVC_CC_Interp_Contracts::INTERPRETER_MODEL) {
                $failures[] = "{$slot_name}/{$slug}: the oracle response was rejected ("
                    . (string) ($result['model_attempt']['reason'] ?? '?') . ': '
                    . (string) ($result['model_attempt']['detail'] ?? '') . ')';
                continue;
            }

            $scored = dbvc_corpus_score_recommendations($result, $page_expectation);
            $checked++;

            if ($scored['auto_wrong'] !== 0) {
                $failures[] = sprintf(
                    '%s/%s: scorer marked %d oracle mappings wrong; it cannot be trusted to score a real model',
                    $slot_name,
                    $slug,
                    $scored['auto_wrong']
                );
                foreach (array_slice($scored['wrong_detail'], 0, 3) as $wrong) {
                    $failures[] = sprintf('    probe "%s": expected %s, scored against %s',
                        substr((string) $wrong['probe'], 0, 40), (string) $wrong['expected'], (string) $wrong['got']);
                }
            }
            if ($scored['auto_correct'] < count($mappings)) {
                $failures[] = sprintf(
                    '%s/%s: oracle produced %d mappings but only %d scored correct',
                    $slot_name,
                    $slug,
                    count($mappings),
                    $scored['auto_correct']
                );
            }
            printf(
                "  %s/%s: oracle %d mappings -> %d correct, %d wrong, %d unscorable (recall %.1f%%)\n",
                $slot_name,
                $slug,
                count($mappings),
                $scored['auto_correct'],
                $scored['auto_wrong'],
                $scored['auto_unscorable'],
                100 * $scored['expectation_recall']
            );
        }
    }

    if ($checked === 0) {
        fwrite(STDERR, "verify-scorer: nothing to check (no slot has fixtures plus a pinned schema)\n");

        return 2;
    }
    if ($failures === []) {
        echo "verify-scorer: the scorer returns a perfect score for a perfect interpreter.\n";
        echo "               This calibrates the harness. It is NOT a model measurement.\n";

        return 0;
    }

    fwrite(STDERR, "verify-scorer FAILED\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, '  - ' . $failure . "\n");
    }

    return 1;
}

/**
 * Proves the evaluator's own guards are live. Run with --self-test.
 *
 * The D-3 guard is the one worth a regression test: V2 shipped artifacts stamped with a model name
 * for output no model produced, and nothing caught it for 32 phases. If this assertion ever stops
 * failing on bad input, the guard has been silently removed.
 *
 * @return int
 */
function dbvc_corpus_self_test()
{
    $failures = [];

    // 1. Floor-produced output carrying a model key must be rejected.
    $bad = [
        'artifact_type' => DBVC_CC_Interp_Contracts::RESULT_TYPE,
        'recommendations' => [[
            'target_ref' => 'acf:page_hero:heading',
            'provenance' => [
                'interpreter' => DBVC_CC_Interp_Contracts::INTERPRETER_FLOOR,
                'confidence_basis' => DBVC_CC_Interp_Contracts::BASIS_LEXICAL_OVERLAP,
                'model' => 'gpt-4o-mini',
            ],
        ]],
    ];
    if (DBVC_CC_Interp_Contracts::validate_result($bad) === []) {
        $failures[] = 'D-3 guard did not reject floor output carrying provenance.model';
    }

    // 2. Floor output claiming a model-reported confidence basis must be rejected.
    $bad2 = $bad;
    unset($bad2['recommendations'][0]['provenance']['model']);
    $bad2['recommendations'][0]['provenance']['confidence_basis'] = DBVC_CC_Interp_Contracts::BASIS_MODEL_REPORTED;
    if (DBVC_CC_Interp_Contracts::validate_result($bad2) === []) {
        $failures[] = 'D-3 guard did not reject a floor-produced model_reported confidence basis';
    }

    // 3. Honest floor output must pass.
    $good = $bad;
    unset($good['recommendations'][0]['provenance']['model']);
    if (DBVC_CC_Interp_Contracts::validate_result($good) !== []) {
        $failures[] = 'honest floor provenance was rejected';
    }

    // 4. The provenance builder must not invent model keys for the floor.
    $provenance = DBVC_CC_Interp_Contracts::provenance(
        DBVC_CC_Interp_Contracts::INTERPRETER_FLOOR,
        DBVC_CC_Interp_Contracts::BASIS_LEXICAL_OVERLAP,
        ['model' => 'gpt-4o-mini', 'provider' => 'openai']
    );
    if (array_key_exists('model', $provenance) || array_key_exists('provider', $provenance)) {
        $failures[] = 'provenance() leaked model details onto floor output even when passed them';
    }

    // ---- the model path and every fallback branch, with a fake transport: no key, no network ----

    $schema = [
        'schema' => DBVC_CC_Interp_Contracts::SCHEMA_TYPE,
        'objects' => [[
            'object_key' => 'page',
            'label' => 'Page',
            'fields' => [
                ['field_ref' => 'acf:page_hero:heading', 'label' => 'Hero heading', 'type' => 'text',
                    'purpose' => 'Headline in the hero band.', 'value_contract' => ['shape' => 'scalar_string']],
                ['field_ref' => 'acf:page_intro:body', 'label' => 'Intro body', 'type' => 'wysiwyg',
                    'purpose' => 'Opening body copy.', 'value_contract' => ['shape' => 'html']],
            ],
        ]],
    ];
    $input = [
        'artifact_type' => DBVC_CC_Interp_Contracts::INPUT_TYPE,
        'page_slug' => 'selftest',
        'items' => [
            ['item_id' => 'it_001', 'kind' => DBVC_CC_Interp_Contracts::KIND_HEADING, 'text' => 'Creating Healthy Smiles'],
            ['item_id' => 'it_002', 'kind' => DBVC_CC_Interp_Contracts::KIND_BODY, 'text' => 'We welcome you to the practice.'],
        ],
    ];

    $anthropic_envelope = static function ($payload) {
        return [
            'status' => 200,
            'body' => (string) json_encode([
                'model' => 'claude-sonnet-5-5',
                'content' => [['type' => 'text', 'text' => $payload]],
                'usage' => ['input_tokens' => 1200, 'output_tokens' => 90],
            ]),
        ];
    };
    $good_payload = (string) json_encode([
        'mappings' => [
            ['item_id' => 'it_001', 'target_ref' => 'acf:page_hero:heading', 'confidence' => 0.95, 'rationale' => 'the page headline'],
            ['item_id' => 'it_002', 'target_ref' => 'acf:page_intro:body', 'confidence' => 0.80, 'rationale' => 'opening copy'],
        ],
        'declined' => [],
    ]);
    $base = ['api_key' => 'selftest-key', 'provider' => DBVC_CC_Interp_Model::PROVIDER_ANTHROPIC];

    // 5. Success: the model is used, provenance carries model details, and the result validates.
    $ok = DBVC_CC_Interp_Service::interpret($input, $schema, 'page', $base + [
        'transport' => static function () use ($anthropic_envelope, $good_payload) {
            return $anthropic_envelope($good_payload);
        },
    ]);
    if (($ok['interpreter'] ?? '') !== DBVC_CC_Interp_Contracts::INTERPRETER_MODEL) {
        $failures[] = 'model success path did not report the model interpreter';
    }
    if (($ok['recommendations'][0]['provenance']['prompt_version'] ?? '') !== DBVC_CC_Interp_Prompt::PROMPT_VERSION) {
        $failures[] = 'model output is missing the prompt version in provenance';
    }
    if (DBVC_CC_Interp_Contracts::validate_result($ok) !== []) {
        $failures[] = 'valid model output was rejected by validate_result';
    }
    // The auto-accept gate must be the same one the floor uses, or G-4 is not a comparison.
    if (! empty($ok['recommendations'][0]['requires_review']) || empty($ok['recommendations'][1]['requires_review'])) {
        $failures[] = 'model recommendations did not honour the 0.92 auto-accept gate (0.95 accept, 0.80 review)';
    }

    // 6. A code fence around the JSON must not break parsing.
    $fenced = DBVC_CC_Interp_Service::interpret($input, $schema, 'page', $base + [
        'transport' => static function () use ($anthropic_envelope, $good_payload) {
            return $anthropic_envelope("```json\n" . $good_payload . "\n```");
        },
    ]);
    if (($fenced['interpreter'] ?? '') !== DBVC_CC_Interp_Contracts::INTERPRETER_MODEL) {
        $failures[] = 'a code-fenced JSON response was not parsed';
    }

    // 7. A hallucinated field_ref must reject the WHOLE response and fall back.
    $hallucinated = (string) json_encode(['mappings' => [
        ['item_id' => 'it_001', 'target_ref' => 'acf:page_hero:heading', 'confidence' => 0.99, 'rationale' => 'ok'],
        ['item_id' => 'it_002', 'target_ref' => 'acf:invented:field', 'confidence' => 0.99, 'rationale' => 'invented'],
    ]]);
    $bad_shape = DBVC_CC_Interp_Service::interpret($input, $schema, 'page', $base + [
        'transport' => static function () use ($anthropic_envelope, $hallucinated) {
            return $anthropic_envelope($hallucinated);
        },
    ]);
    if (($bad_shape['interpreter'] ?? '') !== DBVC_CC_Interp_Contracts::INTERPRETER_FLOOR
        || ($bad_shape['model_attempt']['reason'] ?? '') !== DBVC_CC_Interp_Model::SKIP_INVALID_SHAPE) {
        $failures[] = 'a hallucinated target_ref did not fall back with invalid_response_shape';
    }
    if (DBVC_CC_Interp_Contracts::validate_result($bad_shape) !== []) {
        $failures[] = 'the post-fallback floor result leaked model provenance';
    }

    // 8. Transport failure falls back and says so.
    $http_error = DBVC_CC_Interp_Service::interpret($input, $schema, 'page', $base + [
        'transport' => static function () {
            return ['status' => 500, 'body' => 'upstream exploded'];
        },
    ]);
    if (($http_error['model_attempt']['reason'] ?? '') !== DBVC_CC_Interp_Model::SKIP_TRANSPORT_ERROR) {
        $failures[] = 'an HTTP 500 did not fall back with transport_error';
    }

    // 9. A missing key falls back with its own distinct reason, not a generic error.
    $no_key = DBVC_CC_Interp_Service::interpret($input, $schema, 'page', [
        'provider' => DBVC_CC_Interp_Model::PROVIDER_ANTHROPIC,
        'api_key' => '',
        'transport' => static function () {
            return ['status' => 200, 'body' => '{}'];
        },
    ]);
    if (($no_key['model_attempt']['reason'] ?? '') !== DBVC_CC_Interp_Model::SKIP_NO_KEY) {
        $failures[] = 'a missing API key did not fall back with no_api_key';
    }

    // 10. The budget ceiling refuses before spending anything.
    $over_budget = DBVC_CC_Interp_Service::interpret($input, $schema, 'page', $base + [
        'max_prompt_chars' => 10,
        'transport' => static function () {
            return ['status' => 200, 'body' => 'should never be called'];
        },
    ]);
    if (($over_budget['model_attempt']['reason'] ?? '') !== DBVC_CC_Interp_Model::SKIP_OVER_BUDGET) {
        $failures[] = 'the prompt budget ceiling did not refuse an oversized prompt';
    }

    // 11. Caching: an identical payload must not call the transport twice.
    $cache_dir = sys_get_temp_dir() . '/dbvc-selftest-cache-' . getmypid();
    $calls = 0;
    $counting = static function () use ($anthropic_envelope, $good_payload, &$calls) {
        $calls++;

        return $anthropic_envelope($good_payload);
    };
    $first = DBVC_CC_Interp_Service::interpret($input, $schema, 'page', $base + ['transport' => $counting, 'cache_dir' => $cache_dir]);
    $second = DBVC_CC_Interp_Service::interpret($input, $schema, 'page', $base + ['transport' => $counting, 'cache_dir' => $cache_dir]);
    if ($calls !== 1) {
        $failures[] = sprintf('model cache did not prevent a second call (transport hit %d times)', $calls);
    }
    if (empty($second['model_attempt']['from_cache']) || ! empty($first['model_attempt']['from_cache'])) {
        $failures[] = 'model cache did not report from_cache correctly';
    }
    foreach (glob($cache_dir . '/*.json') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($cache_dir);

    // 12. Two different resolved models must not share a cache entry. The key was built from the
    //     'model' option as passed, which is empty whenever a caller relies on the provider
    //     default - so every default shared one entry and changing the default silently replayed
    //     the previous model's answer. That happened for real when the OpenAI default moved off
    //     gpt-4o, and the run reported the old model's score.
    $cache_dir_models = sys_get_temp_dir() . '/dbvc-selftest-modelcache-' . getmypid();
    $served = [];
    $per_model = static function ($model, $target) use ($anthropic_envelope, &$served) {
        return static function () use ($anthropic_envelope, $model, $target, &$served) {
            $served[] = $model;

            return $anthropic_envelope((string) json_encode(['mappings' => [[
                'item_id' => 'it_001',
                'target_ref' => $target,
                'confidence' => 0.95,
                'rationale' => $model,
            ]]]));
        };
    };

    $first = DBVC_CC_Interp_Service::interpret($input, $schema, 'page', $base + [
        'model' => 'model-one',
        'transport' => $per_model('model-one', 'acf:page_hero:heading'),
        'cache_dir' => $cache_dir_models,
    ]);
    $second = DBVC_CC_Interp_Service::interpret($input, $schema, 'page', $base + [
        'model' => 'model-two',
        'transport' => $per_model('model-two', 'acf:page_intro:body'),
        'cache_dir' => $cache_dir_models,
    ]);

    if ($served !== ['model-one', 'model-two']) {
        $failures[] = 'a second model reused the first model\'s cached answer (served: '
            . implode(', ', $served) . ')';
    }
    if (($first['recommendations'][0]['target_ref'] ?? '') === ($second['recommendations'][0]['target_ref'] ?? '')) {
        $failures[] = 'two models returned the same mapping, so the cache was not keyed per model';
    }
    foreach (glob($cache_dir_models . '/*.json') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($cache_dir_models);

    if ($failures === []) {
        echo "self-test: 12 assertions passed\n";
        echo "  D-3 provenance guards are live (4)\n";
        echo "  model path, code fence, and all five fallback branches exercised with a fake transport (6)\n";
        echo "  response caching prevents a duplicate paid call, and is keyed per resolved model (2)\n";

        return 0;
    }

    fwrite(STDERR, "self-test FAILED\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, '  - ' . $failure . "\n");
    }

    return 1;
}

/**
 * @param array<string, mixed> $report
 * @param bool                 $no_fail
 * @return int
 */
function dbvc_corpus_exit_code(array $report, $no_fail)
{
    if ($no_fail) {
        return 0;
    }
    foreach ($report['slots'] as $slot) {
        foreach ($slot['gates'] as $gate) {
            if (($gate['status'] ?? '') === 'fail') {
                return 1;
            }
        }
    }

    return 0;
}

/**
 * @param array<string, mixed> $report
 * @return void
 */
function dbvc_corpus_print_report(array $report)
{
    $line = str_repeat('=', 78);
    echo "{$line}\n DBVC Content Collection - corpus baseline\n";
    echo " generated {$report['generated_at']}  |  V2 extraction rules verified against source\n{$line}\n\n";

    foreach ($report['slots'] as $name => $slot) {
        echo "SLOT  {$name}\n";
        echo "      {$slot['profile']['purpose']}\n";
        echo "      {$slot['profile']['platform']} / {$slot['profile']['builder']}\n";
        $coverage = $slot['profile']['coverage'];
        if ($coverage !== []) {
            printf(
                "      ground truth for %d of %d content URLs (%d pending capture)\n",
                $coverage['pages_with_ground_truth'] ?? 0,
                $coverage['content_urls_total'] ?? 0,
                $coverage['pages_pending_capture'] ?? 0
            );
        }
        if ($slot['missing_fixtures'] !== []) {
            echo "      fixtures MISSING at {$slot['fixtures_root']}\n";
        }
        echo "\n";

        foreach ($slot['gates'] as $gate_id => $gate) {
            $status = strtoupper((string) $gate['status']);
            $badge = $gate['status'] === 'pass' ? 'PASS' : ($gate['status'] === 'fail' ? 'FAIL' : '----');
            printf("  [%s] %s  %s\n", $badge, $gate_id, dbvc_corpus_gate_title($gate_id));
            // A gate can be unmeasurable and still have something worth printing: G-5 has no
            // denominator precisely because G-4 was zero, which is a finding, not a gap.
            if ($gate['status'] === 'not_measured' && ! isset($gate['pages'])) {
                $reason = (string) ($gate['reason'] ?? $gate['not_measured_reason'] ?? 'no reason recorded');
                echo '        not measured: ' . wordwrap($reason, 64, "\n                      ") . "\n\n";
                continue;
            }
            foreach (dbvc_corpus_gate_detail_lines($gate_id, $gate) as $detail) {
                echo '        ' . $detail . "\n";
            }
            echo "\n";
        }
    }
}

/**
 * @param string $gate_id
 * @return string
 */
function dbvc_corpus_gate_title($gate_id)
{
    $titles = [
        'G-1' => 'Discovery         >=95% content URLs, 100% spam rejected',
        'G-2' => 'Capture fidelity  all probed content, heading levels, images with alt',
        'G-3' => 'Head metadata     100% of present fields captured',
        'G-4' => 'Auto-resolve rate >=80% sections mapped with no reviewer touch',
        'G-5' => 'Mapping precision <=2% wrong auto-accepted mappings',
    ];

    return $titles[$gate_id] ?? $gate_id;
}

/**
 * @param string               $gate_id
 * @param array<string, mixed> $gate
 * @return array<int, string>
 */
function dbvc_corpus_gate_detail_lines($gate_id, array $gate)
{
    $lines = [];

    if ($gate_id === 'G-1') {
        $lines[] = sprintf(
            'shipped recall (%s): %.1f%%  threshold 95.0%%',
            $gate['shipped_strategy'],
            100 * (float) $gate['shipped_recall']
        );
        $lines[] = wordwrap((string) $gate['measures'], 68, "\n        ");
        $lines[] = sprintf(
            'pre-S2a sitemap-only recall was %.1f%% - the blocker S2a removed',
            100 * (float) $gate['pre_s2a_sitemap_only_recall']
        );
        foreach ($gate['by_strategy'] as $strategy => $row) {
            $misses = $row['misses'] === [] ? '' : '  misses: ' . implode(', ', $row['misses']);
            $lines[] = sprintf(
                '  %-26s %d/%d  %5.1f%%  %s%s',
                $strategy,
                $row['found'],
                $row['of'],
                100 * $row['recall'],
                $row['passes'] ? 'pass' : 'FAIL',
                $misses
            );
        }
        $lines[] = sprintf('must reject: %d URLs   must report not crawl: %s',
            $gate['must_reject_count'], implode(', ', $gate['must_report_not_crawl']));

        return $lines;
    }

    if ($gate_id === 'G-2') {
        $lines[] = 'measures ' . (string) ($gate['measures'] ?? '');
        foreach ($gate['pages'] as $slug => $page) {
            if (isset($page['error'])) {
                $lines[] = sprintf('%-22s ERROR %s', $slug, $page['error']);
                continue;
            }
            $lines[] = sprintf(
                '%-22s %-4s probes %d/%d   headings %d/%d   images %d/%d',
                $slug,
                $page['pass'] ? 'pass' : 'FAIL',
                $page['required_text_probes'] - count($page['required_text_missed']),
                $page['required_text_probes'],
                $page['headings_expected'] - count($page['headings_missed']),
                $page['headings_expected'],
                $page['images_expected'] - count($page['images_missed']),
                $page['images_expected']
            );
            foreach ([
                'required_text_missed' => 'missing content',
                'headings_missed' => 'missing heading',
                'heading_level_mismatches' => 'wrong level',
                'images_missed' => 'missing image',
                'images_missing_alt' => 'missing alt',
            ] as $field => $label) {
                foreach ($page[$field] ?? [] as $item) {
                    $text = (string) $item;
                    $lines[] = '    ' . $label . ': '
                        . (strlen($text) > 56 ? substr($text, 0, 56) . '...' : $text);
                }
            }
        }
        $lines[] = '';
        $lines[] = 'retired in-WordPress path, reported not gated (decision Q5):';
        foreach ($gate['pages'] as $slug => $page) {
            $retired = $page['retired_php_path'] ?? [];
            if (empty($retired['measured'])) {
                continue;
            }
            $lines[] = sprintf(
                '  %-22s body text %5.1f%%   images kept %d of %d (%d dropped as a lazyload data: URI)',
                $slug,
                100 * (float) $retired['text_coverage'],
                (int) $retired['images_kept'],
                (int) $retired['images_kept'] + (int) $retired['images_dropped_non_http_scheme'],
                (int) $retired['images_dropped_non_http_scheme']
            );
        }

        return $lines;
    }

    if ($gate_id === 'G-3') {
        $lines[] = 'measures ' . (string) ($gate['measures'] ?? '');
        foreach ($gate['pages'] as $slug => $page) {
            if (isset($page['error'])) {
                $lines[] = sprintf('%-22s ERROR %s', $slug, $page['error']);
                continue;
            }
            $lines[] = sprintf(
                '%-22s %-4s %d/%d present fields captured',
                $slug,
                $page['pass'] ? 'pass' : 'FAIL',
                count($page['captured_fields']),
                count($page['present_fields'])
            );
            if ($page['uncaptured_present_fields'] !== []) {
                $lines[] = '    present but dropped: ' . implode(', ', $page['uncaptured_present_fields']);
            }
            if ($page['wrongly_invented'] !== []) {
                $lines[] = '    INVENTED for an absent field: ' . implode(', ', $page['wrongly_invented']);
            }
            if ($page['absent_in_source'] !== []) {
                $lines[] = '    absent in source, correctly null: ' . implode(', ', $page['absent_in_source']);
            }
        }

        return $lines;
    }

    if ($gate_id === 'G-4' || $gate_id === 'G-5') {
        $lines[] = 'interpreter: ' . $gate['interpreter'] . ' — ' . $gate['interpreter_note'];
        if ($gate_id === 'G-4') {
            $lines[] = sprintf(
                'auto-resolve rate: %d/%d = %.1f%%   threshold %.0f%%',
                $gate['totals']['auto_accepted'],
                $gate['totals']['recommended'],
                100 * $gate['auto_resolve_rate'],
                100 * $gate['threshold']
            );
        } else {
            $lines[] = sprintf(
                'wrong auto-accepted: %d/%d = %.1f%%   ceiling %.0f%%',
                $gate['totals']['wrong'],
                $gate['totals']['auto_accepted'],
                100 * $gate['wrong_rate'],
                100 * $gate['threshold_max_wrong']
            );
        }
        foreach ($gate['pages'] as $slug => $page) {
            if (isset($page['error'])) {
                $lines[] = $slug . ': ' . $page['error'];
                continue;
            }
            $lines[] = sprintf(
                '%s: %d items in, %d declined as chrome, %d recommended, %d auto-accepted',
                $slug,
                $page['items_in'],
                $page['declined'],
                $page['recommended'],
                $page['auto_accepted']
            );
            $lines[] = sprintf(
                '  found the right source item   : %d/%d probes = %.1f%%',
                $page['expected_mappings_hit'],
                $page['expected_mappings'],
                100 * $page['expectation_recall']
            );
            $lines[] = sprintf(
                '  picked the right target field : %d correct / %d wrong = %.1f%% accuracy',
                $page['all_correct'],
                $page['all_wrong'],
                100 * $page['all_accuracy']
            );
            if ($page['provenance_violations'] !== []) {
                foreach ($page['provenance_violations'] as $violation) {
                    $lines[] = '  PROVENANCE: ' . $violation;
                }
            }
        }
        foreach ($gate['excluded_pages'] as $slug => $blocker) {
            $lines[] = sprintf('%s: EXCLUDED — %s', $slug, $blocker['code']);
            $lines[] = '  ' . wordwrap((string) $blocker['detail'], 68, "\n  ");
        }
        if (! empty($gate['not_measured_reason'])) {
            $lines[] = 'note: ' . wordwrap((string) $gate['not_measured_reason'], 68, "\n      ");
        }

        return $lines;
    }


    return $lines;
}
