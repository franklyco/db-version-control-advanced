<?php

/**
 * Ingest a page artifact set produced outside WordPress.
 *
 * V3 splits the add-on into acquisition (outside PHP), interpretation (a model) and commitment
 * (PHP). This service is the joint between the first two. Before it existed, `page-artifact.v1`
 * was written by exactly one thing — the in-WordPress capture service Q5 retired — and the
 * acquisition skill's artifacts had nowhere to go. See D-21 in the V3 guide.
 *
 * It is deliberately a *file* boundary, not a function call: the producer writes JSON to a
 * directory, this service validates it against the contracts and hands it to the orchestrator,
 * and neither side holds a reference to the other. That is what lets either be replaced.
 *
 * Validation is strict and loud on purpose. An external producer's mistakes would otherwise
 * surface as mapping defects a long way downstream, with no stack trace pointing back here.
 */

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_V2_External_Artifact_Ingest_Service
{
    /**
     * Artifact types this service accepts, keyed by the bundle slot they fill.
     */
    public const EXPECTED_TYPES = [
        'raw_artifact' => 'page-artifact.v1',
        'elements' => 'elements.v2',
        'sections' => 'sections.v2',
        'ingestion_package' => 'ingestion-package.v2',
    ];

    /**
     * @var DBVC_CC_V2_External_Artifact_Ingest_Service|null
     */
    private static $instance = null;

    /**
     * Staged directories, keyed by journey id.
     *
     * @var array<string, string>
     */
    private $staged = [];

    /**
     * @return DBVC_CC_V2_External_Artifact_Ingest_Service
     */
    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Register the supply filter. Inert until something calls stage().
     *
     * @return void
     */
    public function register()
    {
        add_filter(
            DBVC_CC_V2_Contracts::FILTER_EXTERNAL_PAGE_ARTIFACTS,
            [$this, 'supply'],
            10,
            4
        );
    }

    /**
     * Point a run at a directory of externally produced artifacts.
     *
     * Validates every bundle in the directory up front rather than per page, so a malformed set
     * fails before the run writes anything. A run that half-ingests is worse than one that
     * refuses to start.
     *
     * @param string $journey_id
     * @param string $dir
     * @return array<string, mixed>|WP_Error
     */
    public function stage($journey_id, $dir)
    {
        $journey_id = (string) $journey_id;
        if ($journey_id === '') {
            return new WP_Error(
                'dbvc_cc_v2_ingest_journey_required',
                __('Staging an external artifact directory needs a journey id.', 'dbvc')
            );
        }

        $dir = wp_normalize_path((string) $dir);
        if ($dir === '' || ! is_dir($dir)) {
            return new WP_Error(
                'dbvc_cc_v2_ingest_dir_missing',
                sprintf(__('No such external artifact directory: %s', 'dbvc'), $dir)
            );
        }

        $slugs = $this->discover_slugs($dir);
        if ($slugs === []) {
            return new WP_Error(
                'dbvc_cc_v2_ingest_dir_empty',
                sprintf(
                    __('No %s files found in %s. Run the acquisition skill emitter first.', 'dbvc'),
                    '*.' . self::EXPECTED_TYPES['raw_artifact'] . '.json',
                    $dir
                )
            );
        }

        $staged = [];
        foreach ($slugs as $slug) {
            $bundle = $this->read_bundle($dir, $slug);
            if (is_wp_error($bundle)) {
                return $bundle;
            }

            $staged[$slug] = [
                'source_url' => isset($bundle['raw_artifact']['source_url'])
                    ? (string) $bundle['raw_artifact']['source_url']
                    : '',
                'normalized_url' => isset($bundle['raw_artifact']['normalized_url'])
                    ? (string) $bundle['raw_artifact']['normalized_url']
                    : '',
                'content_hash' => isset($bundle['raw_artifact']['content_hash'])
                    ? (string) $bundle['raw_artifact']['content_hash']
                    : '',
                'element_count' => isset($bundle['elements']['element_count'])
                    ? (int) $bundle['elements']['element_count']
                    : 0,
            ];
        }

        $this->staged[$journey_id] = $dir;

        return [
            'journeyId' => $journey_id,
            'directory' => $dir,
            'pages' => $staged,
            'pageCount' => count($staged),
        ];
    }

    /**
     * Is this directory one a REST caller may point a run at?
     *
     * A filesystem path arriving over HTTP is a different risk from one typed at a shell. WP-CLI
     * already runs as somebody with shell access, so restricting paths there protects nothing; an
     * authenticated REST request is a narrower credential and should not be able to walk the disk
     * or learn what exists from error messages. So REST is closed by default and the operator
     * opens exactly the root they use:
     *
     *   define('DBVC_CC_INGEST_ROOT', '/Users/me/Documents/captures');
     *
     * or the `dbvc_cc_v2_ingest_allowed_roots` filter for more than one.
     *
     * @param string $dir
     * @return bool
     */
    public function is_path_permitted($dir)
    {
        $dir = wp_normalize_path((string) $dir);
        if ($dir === '') {
            return false;
        }

        $real = realpath($dir);
        if ($real === false) {
            return false;
        }
        $real = trailingslashit(wp_normalize_path($real));

        foreach ($this->get_allowed_roots() as $root) {
            $root_real = realpath($root);
            if ($root_real === false) {
                continue;
            }

            $root_real = trailingslashit(wp_normalize_path($root_real));
            if (strpos($real, $root_real) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    public function get_allowed_roots()
    {
        $roots = [];
        if (defined('DBVC_CC_INGEST_ROOT') && is_string(DBVC_CC_INGEST_ROOT) && DBVC_CC_INGEST_ROOT !== '') {
            $roots[] = (string) DBVC_CC_INGEST_ROOT;
        }

        /**
         * Directories a REST caller may stage an external artifact set from.
         *
         * Empty by default: nothing is reachable over HTTP until an operator says so.
         *
         * @param array<int, string> $roots
         */
        $roots = apply_filters('dbvc_cc_v2_ingest_allowed_roots', $roots);

        $clean = [];
        foreach ((array) $roots as $root) {
            $root = wp_normalize_path((string) $root);
            if ($root !== '') {
                $clean[] = $root;
            }
        }

        return $clean;
    }

    /**
     * Forget a staged directory. Used by tests and by the CLI once a run finishes.
     *
     * @param string $journey_id
     * @return void
     */
    public function unstage($journey_id)
    {
        unset($this->staged[(string) $journey_id]);
    }

    /**
     * @param string $journey_id
     * @return string
     */
    public function get_staged_directory($journey_id)
    {
        $journey_id = (string) $journey_id;

        return isset($this->staged[$journey_id]) ? $this->staged[$journey_id] : '';
    }

    /**
     * Supply a bundle for one row, or null to let the in-WordPress capture path run.
     *
     * Returning null rather than an error for an unstaged run is deliberate: this filter is
     * always registered, and a run that was never pointed at a directory must behave exactly as
     * it did before this service existed.
     *
     * @param array<string, mixed>|null $bundle
     * @param string                    $journey_id
     * @param array<string, mixed>      $row
     * @param array<string, mixed>      $options
     * @return array<string, mixed>|WP_Error|null
     */
    public function supply($bundle, $journey_id, array $row, array $options = [])
    {
        unset($options);

        if ($bundle !== null) {
            return $bundle;
        }

        $dir = $this->get_staged_directory($journey_id);
        if ($dir === '') {
            return null;
        }

        $slug = $this->resolve_slug($dir, $row);
        if ($slug === '') {
            return new WP_Error(
                'dbvc_cc_v2_ingest_page_missing',
                sprintf(
                    __('The staged artifact directory has no page for %s. Capture it, or remove the URL from the run.', 'dbvc'),
                    isset($row['normalized_url']) ? (string) $row['normalized_url'] : (string) ($row['source_url'] ?? '')
                )
            );
        }

        $read = $this->read_bundle($dir, $slug);
        if (is_wp_error($read)) {
            return $read;
        }

        return $this->decorate_for_row($read, $journey_id, $row, $slug);
    }

    /**
     * @param string $dir
     * @return array<int, string>
     */
    private function discover_slugs($dir)
    {
        $suffix = '.' . self::EXPECTED_TYPES['raw_artifact'] . '.json';
        $found = glob(trailingslashit($dir) . '*' . $suffix);
        if (! is_array($found)) {
            return [];
        }

        $slugs = [];
        foreach ($found as $path) {
            $slug = substr(basename($path), 0, -strlen($suffix));
            if (is_string($slug) && $slug !== '') {
                $slugs[] = $slug;
            }
        }

        sort($slugs);

        return $slugs;
    }

    /**
     * Match a run row to a staged page by slug, then by normalized URL, then by path.
     *
     * Slug first because the emitter derives it from the same URL the inventory did. The URL
     * fallbacks exist because a producer may legitimately slugify differently.
     *
     * @param string               $dir
     * @param array<string, mixed> $row
     * @return string
     */
    private function resolve_slug($dir, array $row)
    {
        $candidate = isset($row['slug']) ? (string) $row['slug'] : '';
        if ($candidate !== '' && $this->bundle_exists($dir, $candidate)) {
            return $candidate;
        }

        $wanted = [];
        foreach (['normalized_url', 'source_url'] as $key) {
            if (! empty($row[$key])) {
                $wanted[] = $this->comparable_url((string) $row[$key]);
            }
        }
        if ($wanted === []) {
            return '';
        }

        foreach ($this->discover_slugs($dir) as $slug) {
            $artifact = $this->read_json($this->bundle_path($dir, $slug, 'raw_artifact'));
            if (is_wp_error($artifact) || ! is_array($artifact)) {
                continue;
            }

            foreach (['normalized_url', 'source_url'] as $key) {
                if (empty($artifact[$key])) {
                    continue;
                }

                if (in_array($this->comparable_url((string) $artifact[$key]), $wanted, true)) {
                    return $slug;
                }
            }
        }

        return '';
    }

    /**
     * Compare URLs without being defeated by a trailing slash or a scheme difference.
     *
     * @param string $url
     * @return string
     */
    private function comparable_url($url)
    {
        $url = strtolower(trim((string) $url));
        $url = preg_replace('#^https?://#', '', $url);
        $url = preg_replace('#^www\.#', '', (string) $url);

        return rtrim((string) $url, '/');
    }

    /**
     * @param string $dir
     * @param string $slug
     * @return bool
     */
    private function bundle_exists($dir, $slug)
    {
        return is_file($this->bundle_path($dir, $slug, 'raw_artifact'));
    }

    /**
     * @param string $dir
     * @param string $slug
     * @param string $part
     * @return string
     */
    private function bundle_path($dir, $slug, $part)
    {
        return trailingslashit($dir) . $slug . '.' . self::EXPECTED_TYPES[$part] . '.json';
    }

    /**
     * Read and validate every part of one page's bundle.
     *
     * @param string $dir
     * @param string $slug
     * @return array<string, mixed>|WP_Error
     */
    private function read_bundle($dir, $slug)
    {
        $bundle = [];
        foreach (array_keys(self::EXPECTED_TYPES) as $part) {
            $path = $this->bundle_path($dir, $slug, $part);
            $decoded = $this->read_json($path);
            if (is_wp_error($decoded)) {
                return $decoded;
            }

            $expected_type = self::EXPECTED_TYPES[$part];
            $actual_type = isset($decoded['artifact_type']) ? (string) $decoded['artifact_type'] : '';
            if ($actual_type !== $expected_type) {
                return new WP_Error(
                    'dbvc_cc_v2_ingest_artifact_type_mismatch',
                    sprintf(
                        __('%s declares artifact_type "%s" but this slot requires "%s".', 'dbvc'),
                        basename($path),
                        $actual_type,
                        $expected_type
                    )
                );
            }

            $bundle[$part] = $decoded;
        }

        $invalid = $this->validate_bundle($bundle, $slug);
        if (is_wp_error($invalid)) {
            return $invalid;
        }

        return $bundle;
    }

    /**
     * @param string $path
     * @return array<string, mixed>|WP_Error
     */
    private function read_json($path)
    {
        if (! is_file($path) || ! is_readable($path)) {
            return new WP_Error(
                'dbvc_cc_v2_ingest_file_unreadable',
                sprintf(__('Missing or unreadable external artifact: %s', 'dbvc'), $path)
            );
        }

        $raw = file_get_contents($path);
        if (! is_string($raw) || trim($raw) === '') {
            return new WP_Error(
                'dbvc_cc_v2_ingest_file_empty',
                sprintf(__('External artifact is empty: %s', 'dbvc'), $path)
            );
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return new WP_Error(
                'dbvc_cc_v2_ingest_file_not_json',
                sprintf(
                    __('External artifact is not a JSON object: %s (%s)', 'dbvc'),
                    $path,
                    json_last_error_msg()
                )
            );
        }

        return $decoded;
    }

    /**
     * Contract checks that span the bundle.
     *
     * @param array<string, mixed> $bundle
     * @param string               $slug
     * @return WP_Error|null
     */
    public function validate_bundle(array $bundle, $slug = '')
    {
        $raw = isset($bundle['raw_artifact']) && is_array($bundle['raw_artifact']) ? $bundle['raw_artifact'] : [];

        foreach (['source_url', 'normalized_url', 'content_hash'] as $required) {
            if (empty($raw[$required]) || ! is_string($raw[$required])) {
                return new WP_Error(
                    'dbvc_cc_v2_ingest_raw_incomplete',
                    sprintf(
                        __('The page-artifact.v1 for "%s" is missing a usable %s.', 'dbvc'),
                        $slug,
                        $required
                    )
                );
            }
        }

        foreach (['metadata', 'headings', 'images', 'links'] as $required_array) {
            if (! isset($raw[$required_array]) || ! is_array($raw[$required_array])) {
                return new WP_Error(
                    'dbvc_cc_v2_ingest_raw_incomplete',
                    sprintf(
                        __('The page-artifact.v1 for "%s" is missing the %s block.', 'dbvc'),
                        $slug,
                        $required_array
                    )
                );
            }
        }

        $elements = isset($bundle['elements']) && is_array($bundle['elements']) ? $bundle['elements'] : [];
        if (! isset($elements['elements']) || ! is_array($elements['elements'])) {
            return new WP_Error(
                'dbvc_cc_v2_ingest_elements_incomplete',
                sprintf(__('The elements.v2 artifact for "%s" carries no elements array.', 'dbvc'), $slug)
            );
        }

        // A capture that produced no elements is a capture failure, not an empty page. Refusing
        // here is what stops a silent zero-content import — the failure mode F-1 warns about.
        if ($elements['elements'] === []) {
            return new WP_Error(
                'dbvc_cc_v2_ingest_elements_empty',
                sprintf(
                    __('The elements.v2 artifact for "%s" is empty. A page that captured no text is a capture failure; fix the capture rather than importing nothing.', 'dbvc'),
                    $slug
                )
            );
        }

        $sections = isset($bundle['sections']) && is_array($bundle['sections']) ? $bundle['sections'] : [];
        if (! isset($sections['sections']) || ! is_array($sections['sections'])) {
            return new WP_Error(
                'dbvc_cc_v2_ingest_sections_incomplete',
                sprintf(__('The sections.v2 artifact for "%s" carries no sections array.', 'dbvc'), $slug)
            );
        }

        return null;
    }

    /**
     * Stamp the run's identity onto a staged bundle and record where it came from.
     *
     * The producer does not know the run's journey id or the inventory's page id, so those are
     * applied here rather than trusted from the file. Provenance is recorded so a reviewer
     * looking at a recommendation can tell which producer captured the page — the D-3 principle
     * applied to acquisition.
     *
     * @param array<string, mixed> $bundle
     * @param string               $journey_id
     * @param array<string, mixed> $row
     * @param string               $slug
     * @return array<string, mixed>
     */
    private function decorate_for_row(array $bundle, $journey_id, array $row, $slug)
    {
        $journey_id = (string) $journey_id;
        $page_id = isset($row['page_id']) ? (string) $row['page_id'] : '';
        $path = isset($row['path']) ? (string) $row['path'] : '';

        foreach (array_keys(self::EXPECTED_TYPES) as $part) {
            if (! isset($bundle[$part]) || ! is_array($bundle[$part])) {
                continue;
            }

            $bundle[$part]['journey_id'] = $journey_id;
            if ($page_id !== '') {
                $bundle[$part]['page_id'] = $page_id;
            }
        }

        if ($path !== '') {
            $bundle['raw_artifact']['path'] = $path;
        }

        $bundle['capture_source'] = [
            'producer' => 'external',
            'service' => 'DBVC_CC_V2_External_Artifact_Ingest_Service',
            'slug' => (string) $slug,
            'directory' => $this->get_staged_directory($journey_id),
            'ingested_at' => gmdate('c'),
        ];

        return $bundle;
    }
}
