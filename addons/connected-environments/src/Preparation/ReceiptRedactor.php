<?php

namespace Dbvc\Connected\Preparation;

use Dbvc\ConnectedProtocol\BodyDiff;
use Dbvc\ConnectedProtocol\Protocol;
use Dbvc\ConnectedProtocol\ReceiptValues;

/**
 * Target-side decision about what a prepare receipt may say about this
 * site's values (CR-1, design decision D6).
 *
 * A receipt always carries hashes. It carries the rendered `before_value`
 * (this site) and `after_value` (the release) of every changed path only when
 * this site has opted in through {@see OPTION}: the values travel to the hub
 * and are kept with the preparation, so the site that owns them decides.
 * Every value passes {@see ReceiptValues::format} (secret-key redaction,
 * truncation) before it leaves. Fields the DBVC export mask excludes never
 * reach the diff at all: the observers drop them from the projection and the
 * Preparer reports them under `masked_fields`.
 *
 * A receipt that would exceed {@see Protocol::MAX_RECEIPT_BYTES} with values
 * is sent without them (`values.omitted = size`), never truncated mid-JSON.
 */
final class ReceiptRedactor
{
    public const OPTION = 'dbvc_connected_receipt_values';

    public const OMITTED_DISABLED = 'disabled';
    public const OMITTED_SIZE = 'size';

    /**
     * Whether this site includes its values in prepare receipts. Default off.
     *
     * @return bool
     */
    public static function enabled()
    {
        $stored = get_option(self::OPTION, false);
        $enabled = $stored === true || $stored === 1 || $stored === '1' || $stored === 'on';
        /**
         * Whether prepare receipts leaving this site carry rendered before/after values.
         *
         * @param bool $enabled
         */
        return (bool) apply_filters('dbvc_connected_receipt_values', $enabled);
    }

    /**
     * Attach rendered values to every changed path of a patch.
     *
     * @param array<string, mixed> $patch  {strategy, changed_paths[]}
     * @param array<string, mixed> $before This site's canonical body ([] for a creation).
     * @param array<string, mixed> $after  The release body ([] for a removal).
     * @return array<string, mixed>
     */
    public static function attach_values(array $patch, array $before, array $after)
    {
        if (! isset($patch['changed_paths']) || ! is_array($patch['changed_paths'])) {
            return $patch;
        }
        foreach ($patch['changed_paths'] as &$row) {
            if (! is_array($row) || ! isset($row['path'])) {
                continue;
            }
            $path = (string) $row['path'];
            list($before_present, $before_value) = BodyDiff::value_at($before, $path);
            list($after_present, $after_value) = BodyDiff::value_at($after, $path);
            $row['before_value'] = ReceiptValues::format($before_value, $path, $before_present);
            $row['after_value'] = ReceiptValues::format($after_value, $path, $after_present);
        }
        unset($row);

        return $patch;
    }

    /**
     * Strip every rendered value from a receipt (keeps hashes and paths).
     *
     * @param array<string, mixed> $receipt
     * @return array<string, mixed>
     */
    public static function strip_values(array $receipt)
    {
        foreach ((array) ($receipt['items'] ?? []) as $index => $item) {
            if (! is_array($item) || ! isset($item['patch']['changed_paths']) || ! is_array($item['patch']['changed_paths'])) {
                continue;
            }
            foreach ($item['patch']['changed_paths'] as $row_index => $row) {
                if (is_array($row)) {
                    unset($row['before_value'], $row['after_value']);
                    $receipt['items'][$index]['patch']['changed_paths'][$row_index] = $row;
                }
            }
        }

        return $receipt;
    }

    /**
     * Enforce the receipt size bound: a receipt with values that encodes over
     * {@see Protocol::MAX_RECEIPT_BYTES} loses its values and says so.
     *
     * @param array<string, mixed> $receipt
     * @param bool                 $values_included
     * @return array<string, mixed>
     */
    public static function finalize(array $receipt, $values_included)
    {
        $receipt['values'] = ['included' => (bool) $values_included, 'omitted' => $values_included ? null : self::OMITTED_DISABLED];
        if (! $values_included) {
            return $receipt;
        }
        $encoded = json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (is_string($encoded) && strlen($encoded) <= Protocol::MAX_RECEIPT_BYTES) {
            return $receipt;
        }
        $receipt = self::strip_values($receipt);
        $receipt['values'] = ['included' => false, 'omitted' => self::OMITTED_SIZE];

        return $receipt;
    }
}
