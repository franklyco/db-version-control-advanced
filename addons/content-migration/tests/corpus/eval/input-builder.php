<?php
/**
 * Builds interpretation-input.v1 from a slot's external fixtures.
 *
 * This is a stand-in. In the V3 design the acquisition skill (slice S2) emits this document; until
 * it exists, the evaluator derives it from the captured markdown and head metadata so G-4 and G-5
 * can be measured today. It deliberately lives in the evaluator, not in the interpretation module,
 * so the thing being measured never depends on the thing doing the measuring.
 *
 * Client copy is never committed: this reads the external fixture root at run time.
 */

if (PHP_SAPI !== 'cli') {
    die;
}

/**
 * @param string               $markdown_path
 * @param array<string, mixed> $meta
 * @param string               $slug
 * @param string               $source_url
 * @return array<string, mixed>
 */
function dbvc_corpus_build_interpretation_input($markdown_path, array $meta, $slug, $source_url)
{
    $markdown = (string) file_get_contents($markdown_path);
    $items = [];
    $heading_context = [];
    $order = 0;

    foreach (preg_split('/\n\s*\n/', $markdown) ?: [] as $block) {
        foreach (explode("\n", $block) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $kind = null;
            $text = $line;

            if (preg_match('/^(#{1,6})\s+(.*)$/', $line, $m)) {
                $level = strlen($m[1]);
                $text = dbvc_corpus_strip_markdown($m[2]);
                $kind = DBVC_CC_Interp_Contracts::KIND_HEADING;
                foreach (array_keys($heading_context) as $existing) {
                    if ((int) substr($existing, 1) >= $level) {
                        unset($heading_context[$existing]);
                    }
                }
                $heading_context['h' . $level] = $text;
            } elseif (preg_match('/^!\[([^\]]*)\]\(([^)\s]+)/', $line, $m)) {
                // An image item carries its filename and alt text as the mappable evidence.
                $kind = DBVC_CC_Interp_Contracts::KIND_IMAGE;
                $text = trim(basename((string) $m[2]) . ' ' . $m[1]);
            } elseif (preg_match('/^\[([^\]]+)\]\(([^)\s]+)/', $line, $m) && strlen(trim($m[1])) < 40) {
                $kind = DBVC_CC_Interp_Contracts::KIND_CTA;
                $text = dbvc_corpus_strip_markdown($m[1]);
            } else {
                $kind = DBVC_CC_Interp_Contracts::KIND_BODY;
                $text = dbvc_corpus_strip_markdown(preg_replace('/^[-*+]\s+/', '', $line) ?: $line);
            }

            if ($text === '') {
                continue;
            }

            $order++;
            $items[] = [
                'item_id' => sprintf('it_%03d', $order),
                'kind' => $kind,
                'text' => $text,
                'order' => $order,
                'heading_context' => array_values($heading_context),
            ];
        }
    }

    // Head metadata is mappable content too - it is what D-8 showed V2 throws away.
    foreach ([
        'title' => $meta['title'] ?? null,
        'description' => $meta['description'] ?? null,
        'robots' => $meta['robots'] ?? null,
        'canonical' => $meta['canonical'] ?? null,
    ] as $key => $value) {
        if ($value === null || $value === '') {
            continue;
        }
        $order++;
        $items[] = [
            'item_id' => sprintf('it_%03d', $order),
            'kind' => DBVC_CC_Interp_Contracts::KIND_HEAD_META,
            'text' => (string) $value,
            'order' => $order,
            'heading_context' => ['head:' . $key],
        ];
    }

    return [
        'artifact_type' => DBVC_CC_Interp_Contracts::INPUT_TYPE,
        'page_slug' => (string) $slug,
        'source_url' => (string) $source_url,
        'built_by' => 'corpus evaluator stand-in (S2 acquisition skill will emit this)',
        'items' => $items,
    ];
}

/**
 * @param string $text
 * @return string
 */
function dbvc_corpus_strip_markdown($text)
{
    $value = (string) $text;
    $value = preg_replace('/!\[([^\]]*)\]\([^)]*\)/', '$1', $value) ?? $value;
    $value = preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $value) ?? $value;
    $value = preg_replace('/\*\*([^*]+)\*\*|__([^_]+)__/', '$1$2', $value) ?? $value;
    $value = preg_replace('/(?<![a-z0-9])[*_]([^*_]+)[*_](?![a-z0-9])/i', '$1', $value) ?? $value;
    $value = str_replace(['\\\\', '\\|'], [' ', '|'], $value);
    // A link split across source lines leaves a dangling bracket or a half-written target. That is
    // an artifact of reading markdown line by line, not something the page contains, so it must not
    // reach the interpreter and be scored as a failure to recognise site chrome.
    $value = preg_replace('/\]\([^)]*$/', '', $value) ?? $value;
    $value = preg_replace('/^\[|\[$/', '', $value) ?? $value;
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

    return trim($value);
}

/**
 * Build interpretation-input.v1 from a capture.py capture file.
 *
 * The acquisition skill emits interpretation-input.v1 itself; this keeps the evaluator independent
 * of that emitter so the thing being measured never depends on the thing doing the measuring.
 *
 * @param array<string, mixed> $capture
 * @param string               $slug
 * @return array<string, mixed>
 */
function dbvc_corpus_build_interpretation_input_from_capture(array $capture, $slug)
{
    $temporary = tempnam(sys_get_temp_dir(), 'dbvc-corpus-md-');
    file_put_contents($temporary, (string) ($capture['markdown'] ?? ''));
    $input = dbvc_corpus_build_interpretation_input(
        $temporary,
        isset($capture['metadata']) && is_array($capture['metadata']) ? $capture['metadata'] : [],
        $slug,
        (string) ($capture['url'] ?? '')
    );
    @unlink($temporary);

    return $input;
}
