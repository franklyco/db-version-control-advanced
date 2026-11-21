<?php

namespace Dbvc\ConnectedProtocol;

/**
 * Design Review annotation for a change report (CR-6).
 *
 * Reads a VerticalFramework `vf-design-review/1` export (the file the theme's
 * Export card downloads) and attaches, per report row, what the review said
 * about that page or template: how many pins it took, how many are still
 * open, and whether its approver signed it off. The report stays the same
 * report; the annotation is a side note on each row and a short section at
 * the end. The theme is not touched and the export is never written back.
 *
 * Identity in the export is site-local (`post:19`, a template post id), so
 * this class matches by what both sides carry: an optional UID map a
 * site-aware caller supplies (the connector resolves `post:19` to that
 * post's `vf_object_uid`), then the entry title against the row's display
 * name when exactly one entry carries it. Only `wp.post:*` rows can be
 * annotated; design-system rows (classes, variables, components) have no
 * page to be pinned on and stay as they are.
 *
 * Pure: no WordPress, no I/O.
 */
final class DesignReviewAnnotation
{
    public const FORMAT = 'vf-design-review/1';
    public const POST_DOMAIN_PREFIX = 'wp.post:';
    public const TEMPLATE_DOMAIN = 'wp.post:bricks_template';
    public const MATCHED_BY_UID = 'uid';
    public const MATCHED_BY_TITLE = 'title';
    public const TEMPLATE_KEY_PREFIX = 'template:';
    /** The connector's alias domain for the service post type (a post domain without the `wp.post:` prefix). */
    public const SERVICE_DOMAIN = 'wp.service';

    /**
     * Read an export into the shape {@see annotate()} consumes.
     *
     * @param array<string, mixed> $export Decoded `vf-design-review/1` JSON.
     * @return array<string, mixed>|null Null when the document is not a round export of this format.
     */
    public static function read(array $export)
    {
        if ((string) ($export['format'] ?? '') !== self::FORMAT || ! isset($export['round']) || ! is_array($export['round'])) {
            return null;
        }
        $round = $export['round'];
        if (! isset($round['inventory']) || ! is_array($round['inventory'])) {
            return null;
        }
        $approvals = isset($round['approvals']) && is_array($round['approvals']) ? $round['approvals'] : [];
        $entries = [];
        foreach ($round['inventory'] as $row) {
            if (! is_array($row) || trim((string) ($row['key'] ?? '')) === '') {
                continue;
            }
            $key = trim((string) $row['key']);
            $approval = isset($approvals[$key]) && is_array($approvals[$key]) ? $approvals[$key] : null;
            $entries[$key] = [
                'key' => $key,
                'kind' => (string) ($row['kind'] ?? ''),
                'path' => (string) ($row['path'] ?? ''),
                'title' => self::clean_title($row['title'] ?? ''),
                'approved' => $approval !== null && (string) ($approval['at'] ?? '') !== '',
                'approved_at' => $approval !== null ? (string) ($approval['at'] ?? '') : '',
                'pins' => self::empty_pins(),
            ];
        }
        $templates = [];
        foreach (isset($round['pins']) && is_array($round['pins']) ? $round['pins'] : [] as $pin) {
            if (! is_array($pin)) {
                continue;
            }
            $status = (string) ($pin['status'] ?? 'open');
            $entry = trim((string) ($pin['entry'] ?? ''));
            if ($entry !== '' && isset($entries[$entry])) {
                $entries[$entry]['pins'] = self::count_pin($entries[$entry]['pins'], $status);
            }
            // A pin on a template is the template's, whichever page it was placed on.
            $template_id = (int) ($pin['template'] ?? 0);
            if ((string) ($pin['scope'] ?? '') === 'template' && $template_id > 0) {
                if (! isset($templates[$template_id])) {
                    $templates[$template_id] = ['id' => $template_id, 'title' => self::clean_title($pin['template_title'] ?? ''), 'pins' => self::empty_pins()];
                }
                if ($templates[$template_id]['title'] === '') {
                    $templates[$template_id]['title'] = self::clean_title($pin['template_title'] ?? '');
                }
                $templates[$template_id]['pins'] = self::count_pin($templates[$template_id]['pins'], $status);
            }
        }

        return [
            'site' => (string) ($export['site'] ?? ''),
            'generated' => (string) ($export['generated'] ?? ''),
            'round' => [
                'id' => (int) ($round['id'] ?? 0),
                'label' => (string) ($round['label'] ?? ''),
                'state' => (string) ($round['state'] ?? ''),
                'signed_off' => ! empty($round['signed_off']),
            ],
            'entries' => $entries,
            'templates' => $templates,
        ];
    }

    /**
     * Attach a `design_review` note to each report row (null when the review
     * says nothing about it) and summarise the match.
     *
     * @param array<int, array<string, mixed>> $items   Report rows: each has `domain`, `instance_uid`, `display_name`.
     * @param array<string, mixed>             $review  From {@see read()}.
     * @param array<string, string>            $uid_map Entry key (`post:19`, `template:7`) → instance uid, from a site-aware resolver.
     * @return array{items: array<int, array<string, mixed>>, summary: array<string, mixed>}
     */
    public static function annotate(array $items, array $review, array $uid_map = [])
    {
        $entries = isset($review['entries']) && is_array($review['entries']) ? $review['entries'] : [];
        $templates = isset($review['templates']) && is_array($review['templates']) ? $review['templates'] : [];
        $by_uid = [];
        foreach ($uid_map as $key => $uid) {
            if ((string) $uid !== '') {
                $by_uid[(string) $uid] = (string) $key;
            }
        }
        $page_titles = self::unique_titles(array_filter($entries, static function ($entry) {
            return ! in_array($entry['kind'], ['template', 'design'], true);
        }));
        $template_titles = self::unique_titles(array_filter($entries, static function ($entry) {
            return $entry['kind'] === 'template';
        }));
        $template_pin_titles = self::unique_titles($templates);

        $matched_keys = [];
        $by = [self::MATCHED_BY_UID => 0, self::MATCHED_BY_TITLE => 0];
        $out = [];
        foreach ($items as $item) {
            $item['design_review'] = null;
            $domain = (string) ($item['domain'] ?? '');
            if (! self::is_post_domain($domain)) {
                $out[] = $item;
                continue;
            }
            $uid = (string) ($item['instance_uid'] ?? '');
            $name = self::clean_title($item['display_name'] ?? '');
            $is_template = $domain === self::TEMPLATE_DOMAIN;
            $note = null;

            if ($uid !== '' && isset($by_uid[$uid])) {
                $key = $by_uid[$uid];
                if (strpos($key, self::TEMPLATE_KEY_PREFIX) === 0 && isset($templates[(int) substr($key, strlen(self::TEMPLATE_KEY_PREFIX))])) {
                    $note = self::template_note($templates[(int) substr($key, strlen(self::TEMPLATE_KEY_PREFIX))], self::MATCHED_BY_UID);
                } elseif (isset($entries[$key])) {
                    $note = self::entry_note($entries[$key], self::MATCHED_BY_UID);
                }
            }
            if ($note === null && $name !== '') {
                if ($is_template) {
                    if (isset($template_titles[$name])) {
                        $note = self::entry_note($entries[$template_titles[$name]], self::MATCHED_BY_TITLE);
                    } elseif (isset($template_pin_titles[$name])) {
                        $note = self::template_note($templates[$template_pin_titles[$name]], self::MATCHED_BY_TITLE);
                    }
                } elseif (isset($page_titles[$name])) {
                    $note = self::entry_note($entries[$page_titles[$name]], self::MATCHED_BY_TITLE);
                }
            }
            if ($note !== null) {
                $matched_keys[$note['entry']] = true;
                $by[$note['matched_by']]++;
            }
            $item['design_review'] = $note;
            $out[] = $item;
        }

        $unmatched = [];
        foreach ($entries as $key => $entry) {
            if (! isset($matched_keys[$key]) && $entry['kind'] !== 'design') {
                $unmatched[] = $entry['title'] !== '' ? $entry['title'] : $key;
            }
        }

        return [
            'items' => $out,
            'summary' => [
                'site' => (string) ($review['site'] ?? ''),
                'generated' => (string) ($review['generated'] ?? ''),
                'round' => isset($review['round']) && is_array($review['round']) ? $review['round'] : ['id' => 0, 'label' => '', 'state' => '', 'signed_off' => false],
                'entries' => count($entries),
                'matched' => count($matched_keys),
                'matched_by' => $by,
                'unmatched' => $unmatched,
            ],
        ];
    }

    /**
     * One line for a row's note: "3 pins (1 open) · approved".
     *
     * @param array<string, mixed>|null $note
     * @return string
     */
    public static function describe($note)
    {
        if (! is_array($note)) {
            return '';
        }
        $pins = $note['pins'];
        $text = $pins['total'] === 0 ? 'no pins' : sprintf('%d pin%s', $pins['total'], $pins['total'] === 1 ? '' : 's');
        if ($pins['open'] > 0) {
            $text .= sprintf(' (%d open)', $pins['open']);
        }
        if (array_key_exists('approved', $note)) {
            $text .= $note['approved'] ? ' · approved' : ' · not approved';
        }

        return $text;
    }

    /**
     * Markdown lines for the end of a report.
     *
     * @param array<string, mixed> $summary From {@see annotate()}.
     * @return array<int, string>
     */
    public static function markdown_lines(array $summary)
    {
        $round = $summary['round'];
        $label = $round['label'] !== '' ? $round['label'] : 'Design review';
        $state = $round['state'] !== '' ? ' (' . $round['state'] . ($round['signed_off'] ? ', signed off' : '') . ')' : '';
        $lines = ['', '## Design Review', sprintf('%s%s · %d page%s reviewed · %d matched to a report row.', $label, $state, $summary['entries'], $summary['entries'] === 1 ? '' : 's', $summary['matched'])];
        if ($summary['site'] !== '' || $summary['generated'] !== '') {
            $lines[] = trim('Export from ' . $summary['site'] . ($summary['generated'] !== '' ? ' taken ' . $summary['generated'] : '')) . '.';
        }
        if (! empty($summary['unmatched'])) {
            $lines[] = 'Reviewed but not in this report: ' . implode(', ', $summary['unmatched']) . '.';
        }

        return $lines;
    }

    /**
     * @return array{total: int, open: int, resolved: int, orphaned: int}
     */
    private static function empty_pins()
    {
        return ['total' => 0, 'open' => 0, 'resolved' => 0, 'orphaned' => 0];
    }

    /**
     * @param array{total: int, open: int, resolved: int, orphaned: int} $pins
     * @param string                                                     $status
     * @return array{total: int, open: int, resolved: int, orphaned: int}
     */
    private static function count_pin(array $pins, $status)
    {
        $pins['total']++;
        $bucket = in_array($status, ['resolved', 'orphaned'], true) ? $status : 'open';
        $pins[$bucket]++;

        return $pins;
    }

    /**
     * Title → key for titles that exactly one row carries.
     *
     * @param array<int|string, array<string, mixed>> $rows Keyed rows with a `title`.
     * @return array<string, int|string>
     */
    private static function unique_titles(array $rows)
    {
        $seen = [];
        foreach ($rows as $key => $row) {
            $title = self::clean_title($row['title'] ?? '');
            if ($title === '') {
                continue;
            }
            $seen[$title][] = $key;
        }
        $unique = [];
        foreach ($seen as $title => $keys) {
            if (count($keys) === 1) {
                $unique[$title] = $keys[0];
            }
        }

        return $unique;
    }

    /**
     * @param array<string, mixed> $entry
     * @param string               $matched_by
     * @return array<string, mixed>
     */
    private static function entry_note(array $entry, $matched_by)
    {
        return [
            'entry' => $entry['key'],
            'kind' => $entry['kind'],
            'title' => $entry['title'],
            'path' => $entry['path'],
            'approved' => $entry['approved'],
            'approved_at' => $entry['approved_at'],
            'pins' => $entry['pins'],
            'matched_by' => $matched_by,
        ];
    }

    /**
     * A template known only through its pins (not an inventory entry): pins, no approval.
     *
     * @param array<string, mixed> $template
     * @param string               $matched_by
     * @return array<string, mixed>
     */
    private static function template_note(array $template, $matched_by)
    {
        return [
            'entry' => self::TEMPLATE_KEY_PREFIX . $template['id'],
            'kind' => 'template',
            'title' => $template['title'],
            'path' => '',
            'pins' => $template['pins'],
            'matched_by' => $matched_by,
        ];
    }

    /**
     * Whether a report row's domain holds posts the review can speak about:
     * `wp.post:<type>` and the connector's `wp.service` alias.
     *
     * @param string $domain
     * @return bool
     */
    public static function is_post_domain($domain)
    {
        $domain = (string) $domain;

        return $domain === self::SERVICE_DOMAIN || strpos($domain, self::POST_DOMAIN_PREFIX) === 0;
    }

    /**
     * Titles compare after entity decoding and whitespace folding: the theme
     * exports `Crowns &#038; Bridges` for a post titled `Crowns & Bridges`.
     *
     * @param mixed $title
     * @return string
     */
    public static function clean_title($title)
    {
        $title = html_entity_decode((string) $title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $title = preg_replace('/\s+/u', ' ', $title);

        return trim((string) $title);
    }
}
