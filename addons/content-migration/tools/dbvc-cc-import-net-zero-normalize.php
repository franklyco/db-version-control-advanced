<?php
/**
 * Normalizes a mysqldump so a before/after comparison measures row bytes rather
 * than the two things that provably cannot rewind.
 *
 * Modes:
 *   auto-increment  Replace `AUTO_INCREMENT=<n>` in CREATE TABLE with a constant.
 *                   An InnoDB counter advances when a row is inserted and does
 *                   not move back when it is deleted, so a rollback can never
 *                   restore it. The real values are reported separately.
 *   post-modified   Additionally blank wp_posts.post_modified and
 *                   post_modified_gmt. Used for the second, narrower comparison
 *                   so the strict and the "apart from post_modified" results are
 *                   both measured instead of one being assumed.
 *   postmeta-content
 *                   Emit post meta as content — one sorted
 *                   post_id|meta_key|meta_value line per row, with meta_id
 *                   dropped. Restoring a multi-value meta key deletes and re-adds
 *                   its rows, which reissues their meta_id, so this distinguishes
 *                   "the surrogate key moved" from "the meta content changed".
 *
 * Usage: php dbvc-cc-import-net-zero-normalize.php <mode> < dump.sql > normalized.sql
 */

$mode = isset($argv[1]) ? (string) $argv[1] : 'auto-increment';
if (! in_array($mode, ['auto-increment', 'post-modified', 'postmeta-content'], true)) {
    fwrite(STDERR, "Unknown mode: {$mode}\n");
    exit(1);
}

/**
 * Splits a SQL VALUES tuple into its top-level values, respecting quoting.
 *
 * @param string $tuple Inside of the parentheses.
 * @return array<int, string>
 */
function dbvc_cc_nz_split_tuple($tuple)
{
    $values = [];
    $buffer = '';
    $in_string = false;
    $length = strlen($tuple);
    for ($index = 0; $index < $length; $index++) {
        $character = $tuple[$index];
        if ($in_string) {
            if ($character === '\\' && $index + 1 < $length) {
                $buffer .= $character . $tuple[$index + 1];
                $index++;
                continue;
            }
            if ($character === "'") {
                $in_string = false;
            }
            $buffer .= $character;
            continue;
        }
        if ($character === "'") {
            $in_string = true;
            $buffer .= $character;
            continue;
        }
        if ($character === ',') {
            $values[] = $buffer;
            $buffer = '';
            continue;
        }
        $buffer .= $character;
    }
    $values[] = $buffer;

    return $values;
}

$handle = fopen('php://stdin', 'rb');
$out = fopen('php://stdout', 'wb');
$posts_columns = null;

if ($mode === 'postmeta-content') {
    $postmeta_columns = null;
    $lines = [];
    while (($line = fgets($handle)) !== false) {
        if (strpos($line, 'INSERT INTO `wp_postmeta` (') !== 0) {
            continue;
        }
        if ($postmeta_columns === null && preg_match('/INSERT INTO `wp_postmeta` \((.*?)\) VALUES /', $line, $matches)) {
            $postmeta_columns = array_map(
                static function ($column) {
                    return trim($column, " `");
                },
                explode(',', $matches[1])
            );
        }
        $open = strpos($line, ') VALUES (');
        if ($postmeta_columns === null || $open === false) {
            continue;
        }
        $rest = substr($line, $open + strlen(') VALUES ('));
        $close = strrpos($rest, ');');
        if ($close === false) {
            continue;
        }
        $values = dbvc_cc_nz_split_tuple(substr($rest, 0, $close));
        if (count($values) !== count($postmeta_columns)) {
            continue;
        }
        $row = array_combine($postmeta_columns, $values);
        $lines[] = ($row['post_id'] ?? '') . '|' . ($row['meta_key'] ?? '') . '|' . ($row['meta_value'] ?? '');
    }
    sort($lines, SORT_STRING);
    fwrite($out, implode("\n", $lines) . "\n");
    fclose($handle);
    fclose($out);
    exit(0);
}

while (($line = fgets($handle)) !== false) {
    $line = preg_replace('/AUTO_INCREMENT=\d+/', 'AUTO_INCREMENT=<normalized>', $line);

    if ($mode === 'post-modified' && strpos($line, 'INSERT INTO `wp_posts` (') === 0) {
        if ($posts_columns === null) {
            if (preg_match('/INSERT INTO `wp_posts` \((.*?)\) VALUES /', $line, $matches)) {
                $posts_columns = array_map(
                    static function ($column) {
                        return trim($column, " `");
                    },
                    explode(',', $matches[1])
                );
            }
        }
        $open = strpos($line, ') VALUES (');
        if ($posts_columns !== null && $open !== false) {
            $prefix = substr($line, 0, $open + strlen(') VALUES ('));
            $rest = substr($line, $open + strlen(') VALUES ('));
            $close = strrpos($rest, ');');
            if ($close !== false) {
                $tuple = substr($rest, 0, $close);
                $suffix = substr($rest, $close);
                $values = dbvc_cc_nz_split_tuple($tuple);
                if (count($values) === count($posts_columns)) {
                    foreach ($posts_columns as $position => $column) {
                        if ($column === 'post_modified' || $column === 'post_modified_gmt') {
                            $values[$position] = "'<normalized>'";
                        }
                    }
                    $line = $prefix . implode(',', $values) . $suffix;
                }
            }
        }
    }

    fwrite($out, $line);
}

fclose($handle);
fclose($out);
