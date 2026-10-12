<?php
/**
 * R5.later-perf-c verification probe (E-163) — serial vs batch session attach.
 *
 * Times `EditableRegistry::addDescriptorToSession()` called once per descriptor
 * (the pre-perf-c shape) against `addDescriptorsToSession()` for the same set,
 * using a real palette-parent descriptor plus its pre-minted leaves.
 *
 * READ-ONLY with respect to the live session: it copies the session transient
 * named by DBVC_PROBE_SESSION into a throwaway key, runs both variants against
 * the copy, compares `public_map` and the descriptor token set, and deletes the
 * copy in a `finally`. It does not modify the live session (the registry's own
 * TTL refresh on `loadSession()` may bump `refreshed_at`, as any editor request
 * does). All output goes to STDERR.
 *
 * Usage, from the site root inside the LocalWP site shell:
 *   DBVC_PROBE_SESSION=ves_xxxxxxxx wp eval '1;' --user=<editor-id> \
 *     --require=wp-content/plugins/db-version-control-main/docs/dropins/dbvc-visual-editor-brand-controls-guide/qa/R5-LATER-PERF-TTFB/cc-perfc-attach-probe.php
 *
 * Measured 2026-09-17 on the reference site (408-descriptor session, 19 leaves):
 *   getVisibleRecord() x9 warm ............ 0.3 ms  (was ~560 ms pre-memo)
 *   serial addDescriptorToSession() x20 ... 11 103 ms, 176 queries
 *   batch addDescriptorsToSession() x20 ...    970 ms,   4 queries
 *   public_map equal: yes · descriptor tokens equal: yes
 */
if (! defined('WP_CLI')) { return; }

$GLOBALS['p_q'] = 0;
WP_CLI::add_wp_hook('query', function ($q) { $GLOBALS['p_q']++; return $q; }, 1);

WP_CLI::add_wp_hook('init', function () {
    $rc = new ReflectionClass('DBVC_Visual_Editor_Addon');
    $p = $rc->getProperty('runtime');
    $p->setAccessible(true);
    $runtime = $p->getValue();
    if (! $runtime) { fwrite(STDERR, "runtime not booted\n"); return; }

    $registry = $runtime->getControlRegistry();
    $er = new ReflectionProperty($runtime, 'registry');
    $er->setAccessible(true);
    $sessions = $er->getValue($runtime);

    $sid = getenv('DBVC_PROBE_SESSION');
    $src = get_transient('dbvc_visual_editor_session_' . $sid);
    if (! is_array($src)) { fwrite(STDERR, "live session not found\n"); return; }

    $items = $registry->listControls(); // cold pass (ACF materialisation) — paid once
    $ids = [];
    foreach ($items as $it) {
        if (count($ids) < 9 && ($it['status'] ?? '') === 'available' && strpos($it['publicId'], 'vertical:') === 0) {
            $ids[] = $it['publicId'];
        }
    }
    $t0 = microtime(true);
    foreach ($ids as $id) { $registry->getVisibleRecord($id); }
    fwrite(STDERR, sprintf("getVisibleRecord() x%d warm (memoised)             %8.1f ms\n", count($ids), (microtime(true) - $t0) * 1000));

    $palette = $registry->getVisibleRecord('vertical:palette_vertical_global_palette');
    $desc = $registry->buildDescriptorForRecord($palette, $sid, isset($src['page_context']) ? $src['page_context'] : []);
    $leaves = [];
    foreach ($desc->source['leaves'] as $leaf) {
        $leaves[] = \Dbvc\VisualEditor\Registry\EditableDescriptor::fromArray($leaf['descriptor']);
    }
    fwrite(STDERR, "palette parent + " . count($leaves) . " leaves; live session serialized " . strlen(serialize($src)) . " B\n");

    $tmp = 'ves_perfc_probe_' . substr(md5(microtime()), 0, 8);
    $key = 'dbvc_visual_editor_session_' . $tmp;
    $copy = $src;
    $copy['session_id'] = $tmp;
    $copy['user_id'] = get_current_user_id();
    $copy['refreshed_at'] = time();
    try {
        // Serial (pre-perf-c behaviour): one attach per descriptor.
        set_transient($key, $copy, 600);
        $q0 = $GLOBALS['p_q']; $t0 = microtime(true);
        $ok = $sessions->addDescriptorToSession($tmp, $desc);
        foreach ($leaves as $l) { $ok = $sessions->addDescriptorToSession($tmp, $l) && $ok; }
        fwrite(STDERR, sprintf("serial addDescriptorToSession() x%d              %8.1f ms  queries=%d  ok=%s\n", 1 + count($leaves), (microtime(true) - $t0) * 1000, $GLOBALS['p_q'] - $q0, $ok ? 'y' : 'n'));
        $after_serial = get_transient($key);

        // Batch (perf-c): one call.
        set_transient($key, $copy, 600);
        $q0 = $GLOBALS['p_q']; $t0 = microtime(true);
        $res = $sessions->addDescriptorsToSession($tmp, array_merge([$desc], $leaves));
        fwrite(STDERR, sprintf("batch addDescriptorsToSession() x%d              %8.1f ms  queries=%d  attached=%d/%d\n", 1 + count($leaves), (microtime(true) - $t0) * 1000, $GLOBALS['p_q'] - $q0, count(array_filter($res)), count($res)));
        $after_batch = get_transient($key);

        $a = $sessions->loadSession($tmp, true);
        $serial_tokens = array_keys(json_decode(gzdecode(base64_decode($after_serial['descriptors_blob'])), true));
        $batch_tokens = array_keys(json_decode(gzdecode(base64_decode($after_batch['descriptors_blob'])), true));
        fwrite(STDERR, "public_map equal: " . (($after_serial['public_map'] ?? null) === ($after_batch['public_map'] ?? null) ? 'yes' : 'NO')
            . " · descriptor tokens equal: " . ($serial_tokens === $batch_tokens ? 'yes' : 'NO')
            . " · descriptors after batch: " . count($a['descriptors']) . "\n");
    } finally {
        delete_transient($key);
        fwrite(STDERR, "throwaway transient deleted: " . (get_transient($key) === false ? 'yes' : 'NO') . "\n");
    }
}, 10005);
