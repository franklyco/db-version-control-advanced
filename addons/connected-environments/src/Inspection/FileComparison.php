<?php

namespace Dbvc\Connected\Inspection;

use Dbvc\Connected\Adapters\BricksOptionCollectionObserver;
use Dbvc\Connected\Adapters\DomainRegistry;
use Dbvc\Connected\Storage\ObjectStore;
use Dbvc\ConnectedProtocol\BodyDiff;
use Dbvc\ConnectedProtocol\DesignReviewAnnotation;
use Dbvc\ConnectedProtocol\ReceiptValues;

/**
 * Hubless file mode of the change report (CR-5).
 *
 * Two read-only halves that need no hub and no transport:
 *
 * - {@see export()} on the site whose state is being reported (staging): the
 *   canonical bodies this connector already recorded in `dbvc_ce_objects`
 *   (the same projections the hub would receive) written as one JSON bundle.
 * - {@see compare()} on the other site (LocalWP): every object in the bundle
 *   is matched to this site's recorded projection by portable UID, then by
 *   trimmed display name inside the domain (the hub's instance-link rule),
 *   and each pair is diffed with {@see BodyDiff} exactly as a prepare receipt
 *   would be. Rendered values pass {@see ReceiptValues::format} (secret
 *   redaction, truncation); fields the DBVC export mask excludes were never
 *   projected, so they are not in the bundle.
 *
 * Both sides read `dbvc_ce_objects` only: the result is as fresh as the last
 * observation on each site (`wp dbvc connected process` first when in doubt).
 * There is no baseline, so a difference is reported as such — the file mode
 * cannot say which site changed. Moving the bundle between the sites (scp,
 * rsync, a shared folder) and scheduling are the operator's.
 */
final class FileComparison
{
    public const FORMAT = 'dbvc-connected-objects/1';

    public const LABEL_ONLY_IN_FILE = 'only_in_file';
    public const LABEL_DIFFERS = 'differs';
    public const LABEL_ONLY_HERE = 'only_here';
    public const LABEL_SAME = 'same';

    public const LABEL_TEXT = [
        self::LABEL_ONLY_IN_FILE => 'Only in the export',
        self::LABEL_DIFFERS => 'Differs',
        self::LABEL_ONLY_HERE => 'Only on this site',
        self::LABEL_SAME => 'Both match',
    ];

    public const MATCHED_BY_UID = 'uid';
    public const MATCHED_BY_NAME = 'name';

    public const MAX_MARKDOWN_PATHS = 10;

    /**
     * @var ObjectStore
     */
    private $objects;

    public function __construct(?ObjectStore $objects = null)
    {
        $this->objects = $objects ?: new ObjectStore();
    }

    // ---- export -------------------------------------------------------------

    /**
     * The bundle of this site's recorded, complete, existing objects.
     *
     * @param array<int, string> $domains Registered domains; [] means every registered domain.
     * @return array<string, mixed>|\WP_Error
     */
    public function export(array $domains = [])
    {
        $registered = DomainRegistry::domains();
        $domains = $domains === [] ? $registered : array_values(array_unique($domains));
        foreach ($domains as $domain) {
            if (! in_array($domain, $registered, true)) {
                return new \WP_Error('dbvc_connected_cli_domain', 'Unknown domain: ' . $domain);
            }
        }
        $observers = DomainRegistry::observers();
        $bundle = [
            'format' => self::FORMAT,
            'exported_at' => gmdate('c'),
            'site' => (string) home_url(),
            'domains' => [],
            'objects' => [],
            'skipped' => 0,
        ];
        foreach ($domains as $domain) {
            $observer = $observers[$domain] ?? null;
            $profile = $observer !== null ? (string) $observer->profile() : '';
            $rows = $profile !== '' ? $this->objects->get_for_domain($domain, $profile) : [];
            $count = 0;
            foreach ($rows as $row) {
                if ((string) $row['instance_uid'] === BricksOptionCollectionObserver::ORDER_INSTANCE_UID) {
                    continue;
                }
                if ((int) $row['object_exists'] !== 1 || (int) $row['snapshot_complete'] !== 1) {
                    $bundle['skipped']++;
                    continue;
                }
                $body = json_decode((string) $row['snapshot_body'], true);
                if (! is_array($body)) {
                    $bundle['skipped']++;
                    continue;
                }
                $bundle['objects'][] = [
                    'domain' => $domain,
                    'profile' => (string) $row['profile'],
                    'instance_uid' => (string) $row['instance_uid'],
                    'storage_key' => (string) $row['storage_key'],
                    'display_name' => (string) $row['display_name'],
                    'semantic_hash' => (string) $row['semantic_hash'],
                    'observed_at' => (string) $row['updated_at'],
                    'body' => $body,
                ];
                $count++;
            }
            $bundle['domains'][$domain] = ['profile' => $profile, 'count' => $count];
        }

        return $bundle;
    }

    // ---- compare ------------------------------------------------------------

    /**
     * Compare a bundle produced by {@see export()} elsewhere against this site.
     *
     * @param array<string, mixed> $bundle
     * @param array{domains?: array<int, string>, values?: bool, max_paths?: int} $options
     * @return array<string, mixed>|\WP_Error
     */
    public function compare(array $bundle, array $options = [])
    {
        if ((string) ($bundle['format'] ?? '') !== self::FORMAT) {
            return new \WP_Error('dbvc_connected_file_format', 'Not a DBVC connected objects export (expected format ' . self::FORMAT . ').');
        }
        if (! isset($bundle['objects']) || ! is_array($bundle['objects'])) {
            return new \WP_Error('dbvc_connected_file_format', 'The export carries no objects list.');
        }
        $values = ! array_key_exists('values', $options) || ! empty($options['values']);
        $max_paths = max(1, (int) ($options['max_paths'] ?? BodyDiff::DEFAULT_MAX_PATHS));
        $only = isset($options['domains']) && is_array($options['domains']) ? array_values(array_unique($options['domains'])) : [];

        $file_by_domain = [];
        foreach ($bundle['objects'] as $object) {
            if (! is_array($object) || ! isset($object['domain'], $object['instance_uid']) || ! is_array($object['body'] ?? null)) {
                continue;
            }
            $domain = (string) $object['domain'];
            if ($only !== [] && ! in_array($domain, $only, true)) {
                continue;
            }
            $file_by_domain[$domain][(string) $object['instance_uid']] = $object;
        }
        $domains = array_keys($file_by_domain);
        foreach ($only as $domain) {
            if (! isset($file_by_domain[$domain])) {
                $domains[] = $domain;
            }
        }
        sort($domains);

        $registered = DomainRegistry::domains();
        $observers = DomainRegistry::observers();
        $result = [
            'format' => self::FORMAT,
            'file' => ['site' => (string) ($bundle['site'] ?? ''), 'exported_at' => (string) ($bundle['exported_at'] ?? '')],
            'here' => (string) home_url(),
            'compared_at' => gmdate('c'),
            'values_included' => $values,
            'domains' => [],
            'counts' => array_fill_keys(array_keys(self::LABEL_TEXT), 0),
            'items' => [],
            'matches' => [],
            'notes' => [],
        ];

        foreach ($domains as $domain) {
            $file_rows = $file_by_domain[$domain] ?? [];
            $file_profile = $file_rows !== [] ? (string) (reset($file_rows)['profile'] ?? '') : '';
            if (! in_array($domain, $registered, true) || ! isset($observers[$domain])) {
                $result['domains'][$domain] = ['status' => 'unavailable', 'profile' => '', 'profile_in_file' => $file_profile, 'in_file' => count($file_rows), 'here' => 0];
                $result['notes'][] = 'Domain ' . $domain . ' is not registered on this site; its ' . count($file_rows) . ' exported object(s) were not compared.';
                continue;
            }
            $profile = (string) $observers[$domain]->profile();
            if ($file_profile !== '' && $file_profile !== $profile) {
                $result['notes'][] = 'Domain ' . $domain . ' was exported under profile ' . $file_profile . ' but this site projects ' . $profile . '; compared anyway.';
            }
            $here_rows = [];
            foreach ($this->objects->get_for_domain($domain, $profile) as $uid => $row) {
                if ((string) $uid === BricksOptionCollectionObserver::ORDER_INSTANCE_UID || (int) $row['object_exists'] !== 1) {
                    continue;
                }
                $here_rows[(string) $uid] = $row;
            }
            $counts = array_fill_keys(array_keys(self::LABEL_TEXT), 0);
            $pairs = self::pair($file_rows, $here_rows);
            foreach ($pairs as $pair) {
                $item = $this->item($domain, $pair['file'], $pair['here'], $pair['matched_by'], $values, $max_paths);
                $counts[$item['label']]++;
                $result['counts'][$item['label']]++;
                if ($item['label'] === self::LABEL_SAME) {
                    $result['matches'][] = ['domain' => $domain, 'instance_uid' => $item['instance_uid'], 'display_name' => $item['display_name'], 'matched_by' => $item['matched_by']];
                    continue;
                }
                $result['items'][] = $item;
            }
            $result['domains'][$domain] = ['status' => 'compared', 'profile' => $profile, 'profile_in_file' => $file_profile, 'in_file' => count($file_rows), 'here' => count($here_rows), 'counts' => $counts];
        }

        $rank = array_flip([self::LABEL_DIFFERS, self::LABEL_ONLY_IN_FILE, self::LABEL_ONLY_HERE, self::LABEL_SAME]);
        usort($result['items'], static function ($a, $b) use ($rank) {
            $by_label = $rank[$a['label']] <=> $rank[$b['label']];
            if ($by_label !== 0) {
                return $by_label;
            }
            $by_domain = strcmp($a['domain'], $b['domain']);
            if ($by_domain !== 0) {
                return $by_domain;
            }
            $by_name = strcasecmp($a['display_name'], $b['display_name']);

            return $by_name !== 0 ? $by_name : strcmp($a['instance_uid'], $b['instance_uid']);
        });
        $result['summary'] = self::summary($result['counts']);

        return $result;
    }

    /**
     * Match exported objects to this site's: by instance UID first, then the
     * leftovers by trimmed display name when the name is unique on both sides.
     *
     * @param array<string, array<string, mixed>> $file_rows Keyed by instance_uid.
     * @param array<string, array<string, mixed>> $here_rows Keyed by instance_uid.
     * @return array<int, array{file: array<string, mixed>|null, here: array<string, mixed>|null, matched_by: string|null}>
     */
    private static function pair(array $file_rows, array $here_rows)
    {
        $pairs = [];
        $unmatched_file = [];
        foreach ($file_rows as $uid => $file) {
            if (isset($here_rows[$uid])) {
                $pairs[] = ['file' => $file, 'here' => $here_rows[$uid], 'matched_by' => self::MATCHED_BY_UID];
                unset($here_rows[$uid]);
                continue;
            }
            $unmatched_file[$uid] = $file;
        }
        $by_name_here = [];
        foreach ($here_rows as $uid => $row) {
            $name = trim((string) $row['display_name']);
            if ($name !== '') {
                $by_name_here[$name][] = $uid;
            }
        }
        $by_name_file = [];
        foreach ($unmatched_file as $uid => $row) {
            $name = trim((string) ($row['display_name'] ?? ''));
            if ($name !== '') {
                $by_name_file[$name][] = $uid;
            }
        }
        foreach ($unmatched_file as $uid => $file) {
            $name = trim((string) ($file['display_name'] ?? ''));
            $candidates = $by_name_here[$name] ?? [];
            if ($name !== '' && count($candidates) === 1 && count($by_name_file[$name]) === 1 && isset($here_rows[$candidates[0]])) {
                $pairs[] = ['file' => $file, 'here' => $here_rows[$candidates[0]], 'matched_by' => self::MATCHED_BY_NAME];
                unset($here_rows[$candidates[0]]);
                continue;
            }
            $pairs[] = ['file' => $file, 'here' => null, 'matched_by' => null];
        }
        foreach ($here_rows as $row) {
            $pairs[] = ['file' => null, 'here' => $row, 'matched_by' => null];
        }

        return $pairs;
    }

    /**
     * @param string                    $domain
     * @param array<string, mixed>|null $file
     * @param array<string, mixed>|null $here
     * @param string|null               $matched_by
     * @param bool                      $values
     * @param int                       $max_paths
     * @return array<string, mixed>
     */
    private function item($domain, $file, $here, $matched_by, $values, $max_paths)
    {
        $here_body = $here !== null ? json_decode((string) $here['snapshot_body'], true) : null;
        $here_body = is_array($here_body) ? $here_body : [];
        $file_body = $file !== null && is_array($file['body']) ? $file['body'] : [];
        $here_hash = $here !== null ? (string) $here['semantic_hash'] : null;
        $file_hash = $file !== null ? (string) ($file['semantic_hash'] ?? '') : null;
        $here_complete = $here === null || (int) $here['snapshot_complete'] === 1;

        if ($file === null) {
            $label = self::LABEL_ONLY_HERE;
        } elseif ($here === null) {
            $label = self::LABEL_ONLY_IN_FILE;
        } elseif ($here_complete && $here_hash === $file_hash) {
            $label = self::LABEL_SAME;
        } else {
            $label = self::LABEL_DIFFERS;
        }

        $paths = [];
        $truncated = false;
        if ($label !== self::LABEL_SAME) {
            // before = this site, after = the export: the receipt convention (this site → incoming).
            $diff = BodyDiff::paths($here_body, $file_body, ['max_paths' => $max_paths]);
            $truncated = $diff['truncated'];
            foreach ($diff['changed_paths'] as $row) {
                $path = (string) $row['path'];
                if ($values) {
                    list($before_present, $before_value) = BodyDiff::value_at($here_body, $path);
                    list($after_present, $after_value) = BodyDiff::value_at($file_body, $path);
                    $row['before_value'] = ReceiptValues::format($before_value, $path, $before_present);
                    $row['after_value'] = ReceiptValues::format($after_value, $path, $after_present);
                }
                $paths[] = $row;
            }
        }
        $display_name = $here !== null ? (string) $here['display_name'] : (string) ($file['display_name'] ?? '');

        return [
            'domain' => $domain,
            'instance_uid' => $here !== null ? (string) $here['instance_uid'] : (string) $file['instance_uid'],
            'file_instance_uid' => $file !== null ? (string) $file['instance_uid'] : null,
            'display_name' => $display_name,
            'label' => $label,
            'label_text' => self::LABEL_TEXT[$label],
            'matched_by' => $matched_by,
            'here_hash' => $here_hash,
            'file_hash' => $file_hash,
            'here_complete' => $here_complete,
            'paths' => $paths,
            'path_count' => count($paths),
            'truncated' => $truncated,
        ];
    }

    /**
     * @param array<string, int> $counts
     * @return string
     */
    private static function summary(array $counts)
    {
        $open = $counts[self::LABEL_DIFFERS] + $counts[self::LABEL_ONLY_IN_FILE] + $counts[self::LABEL_ONLY_HERE];
        if ($open === 0) {
            return $counts[self::LABEL_SAME] === 0 ? 'Nothing to compare.' : 'Both sites match (' . $counts[self::LABEL_SAME] . ' object' . ($counts[self::LABEL_SAME] === 1 ? '' : 's') . ').';
        }
        $parts = [];
        foreach ([self::LABEL_DIFFERS, self::LABEL_ONLY_IN_FILE, self::LABEL_ONLY_HERE] as $label) {
            if ($counts[$label] > 0) {
                $parts[] = $counts[$label] . ' ' . strtolower(self::LABEL_TEXT[$label]);
            }
        }

        return $open . ' object' . ($open === 1 ? '' : 's') . ' to look at: ' . implode(', ', $parts) . '; ' . $counts[self::LABEL_SAME] . ' match.';
    }

    // ---- render -------------------------------------------------------------

    /**
     * Markdown rendering of a comparison, laid out like the hub report's export.
     *
     * @param array<string, mixed> $result
     * @return string
     */
    public static function render_markdown(array $result)
    {
        $from = $result['file']['site'] !== '' ? $result['file']['site'] : 'export';
        $lines = ['# Change report · ' . $from . ' → ' . $result['here'] . ' (file mode)', ''];
        $lines[] = $result['summary'] . ($result['file']['exported_at'] !== '' ? ' Export taken ' . $result['file']['exported_at'] . '.' : '') . ' Values read this site → the export.';
        $groups = [];
        foreach ($result['items'] as $item) {
            $groups[$item['label']][] = $item;
        }
        foreach ([self::LABEL_DIFFERS, self::LABEL_ONLY_IN_FILE, self::LABEL_ONLY_HERE] as $label) {
            if (empty($groups[$label])) {
                continue;
            }
            $lines[] = '';
            $lines[] = '## ' . self::LABEL_TEXT[$label] . ' (' . count($groups[$label]) . ')';
            foreach ($groups[$label] as $item) {
                $name = $item['display_name'] !== '' ? $item['display_name'] : $item['instance_uid'];
                $suffix = $item['matched_by'] === self::MATCHED_BY_NAME ? ' · matched by name' : '';
                $suffix .= $item['here_complete'] ? '' : ' · incomplete here';
                if (! empty($item['design_review'])) {
                    $suffix .= ' · Design Review: ' . DesignReviewAnnotation::describe($item['design_review']);
                }
                $lines[] = '- **' . $name . '** (' . $item['domain'] . ') · ' . $item['path_count'] . ' path' . ($item['path_count'] === 1 ? '' : 's') . $suffix;
                foreach (array_slice($item['paths'], 0, self::MAX_MARKDOWN_PATHS) as $path) {
                    $before = isset($path['before_value']) ? (string) $path['before_value'] : null;
                    $after = isset($path['after_value']) ? (string) $path['after_value'] : null;
                    $values = $before !== null || $after !== null ? ': ' . ($before ?? '?') . ' → ' . ($after ?? '?') : '';
                    $lines[] = '  - ' . (string) $path['path'] . ' (' . (string) $path['operation'] . ')' . $values;
                }
                if (count($item['paths']) > self::MAX_MARKDOWN_PATHS) {
                    $lines[] = '  - … ' . (count($item['paths']) - self::MAX_MARKDOWN_PATHS) . ' more';
                }
                if (! empty($item['truncated'])) {
                    $lines[] = '  - (diff truncated)';
                }
            }
        }
        if (! empty($result['design_review'])) {
            $lines = array_merge($lines, DesignReviewAnnotation::markdown_lines($result['design_review']));
        }
        if (! empty($result['notes'])) {
            $lines[] = '';
            $lines[] = '## Notes';
            foreach ($result['notes'] as $note) {
                $lines[] = '- ' . $note;
            }
        }

        return implode("\n", $lines) . "\n";
    }
}
