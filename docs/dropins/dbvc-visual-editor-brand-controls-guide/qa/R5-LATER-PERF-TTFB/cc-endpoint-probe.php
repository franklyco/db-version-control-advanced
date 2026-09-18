<?php
/**
 * Read-only probe: attributes the Brand Control Center REST costs measured in the
 * R5.later-perf sweep (S3 list ≈ 3.5 s, S4 value-summaries ≈ 3.7 s/batch, S6 palette
 * open ≈ 13.8 s). Prints to STDERR. Never writes (no set_transient / options).
 */
if (! defined('WP_CLI')) { return; }
WP_CLI::add_wp_hook('dbvc_visual_editor_performance_profile_enabled', '__return_true');
WP_CLI::add_wp_hook('dbvc_visual_editor_performance_profile_log', '__return_false');
$GLOBALS['dbvc_probe_summary'] = null;
WP_CLI::add_wp_hook('dbvc_visual_editor_performance_profile_summary', function ($summary) { $GLOBALS['dbvc_probe_summary'] = $summary; }, 10, 1);

$GLOBALS['dbvc_probe_acf_load_field'] = 0;
WP_CLI::add_wp_hook('acf/load_field', function ($f) { $GLOBALS['dbvc_probe_acf_load_field']++; return $f; }, 1);
$GLOBALS['dbvc_probe_queries'] = 0;
WP_CLI::add_wp_hook('query', function ($q) { $GLOBALS['dbvc_probe_queries']++; return $q; }, 1);

function dbvc_probe_time($label, callable $fn) {
    $q0 = $GLOBALS['dbvc_probe_queries']; $a0 = $GLOBALS['dbvc_probe_acf_load_field']; $t0 = microtime(true);
    $r = $fn();
    $ms = (microtime(true) - $t0) * 1000;
    fwrite(STDERR, sprintf("%-58s %8.1f ms  queries=%-5d acf/load_field=%d\n", $label, $ms, $GLOBALS['dbvc_probe_queries'] - $q0, $GLOBALS['dbvc_probe_acf_load_field'] - $a0));
    return $r;
}

WP_CLI::add_wp_hook('init', function () {
    $rc = new ReflectionClass('DBVC_Visual_Editor_Addon');
    $p = $rc->getProperty('runtime'); $p->setAccessible(true);
    $runtime = $p->getValue();
    if (! $runtime) { fwrite(STDERR, "runtime not booted\n"); return; }
    $registry = $runtime->getControlRegistry();
    fwrite(STDERR, "user=" . wp_get_current_user()->user_login . " providers=" . implode(',', $registry->providerIds()) . "\n\n");

    $items = dbvc_probe_time('listControls() #1 (cold)', function () use ($registry) { return $registry->listControls(); });
    fwrite(STDERR, "  items=" . count($items) . "\n");
    dbvc_probe_time('listControls() #2 (same request)', function () use ($registry) { return $registry->listControls(); });

    $rp = new ReflectionProperty($registry, 'providers'); $rp->setAccessible(true);
    $providers = $rp->getValue($registry);
    $vertical = isset($providers['vertical']) ? $providers['vertical'] : null;
    if ($vertical) {
        dbvc_probe_time('vertical->getControls() #3', function () use ($vertical) { return $vertical->getControls(); });
        $lr = new ReflectionMethod($vertical, 'loadRecords'); $lr->setAccessible(true);
        $raw = dbvc_probe_time('vertical->loadRecords() (memoised)', function () use ($lr, $vertical) { return $lr->invoke($vertical); });
        fwrite(STDERR, "  raw curated records=" . count($raw) . "\n");
        $mr = new ReflectionMethod($vertical, 'mapRecord'); $mr->setAccessible(true);
        $n = 0; $t0 = microtime(true); $a0 = $GLOBALS['dbvc_probe_acf_load_field']; $q0 = $GLOBALS['dbvc_probe_queries'];
        foreach ($raw as $r) { $mr->invoke($vertical, $r); if (++$n >= 50) break; }
        fwrite(STDERR, sprintf("%-58s %8.1f ms  queries=%-5d acf/load_field=%d\n", 'vertical->mapRecord() x50', (microtime(true) - $t0) * 1000, $GLOBALS['dbvc_probe_queries'] - $q0, $GLOBALS['dbvc_probe_acf_load_field'] - $a0));
        $me = new ReflectionMethod($vertical, 'maybeExpandRepeaterRecord'); $me->setAccessible(true);
        $n = 0; $t0 = microtime(true); $a0 = $GLOBALS['dbvc_probe_acf_load_field']; $q0 = $GLOBALS['dbvc_probe_queries'];
        foreach ($raw as $r) { $m = $mr->invoke($vertical, $r); if ($m === null) continue; $me->invoke($vertical, $r, $m, (string) ($r['field_key'] ?? ''), strtolower((string) ($r['field_type'] ?? '')), []); if (++$n >= 50) break; }
        fwrite(STDERR, sprintf("%-58s %8.1f ms  queries=%-5d acf/load_field=%d\n", 'mapRecord+maybeExpandRepeaterRecord() x50', (microtime(true) - $t0) * 1000, $GLOBALS['dbvc_probe_queries'] - $q0, $GLOBALS['dbvc_probe_acf_load_field'] - $a0));
    }

    $sg = isset($providers['shared_globals']) ? $providers['shared_globals'] : null;
    if ($sg) { dbvc_probe_time('shared_globals->getControls()', function () use ($sg) { return $sg->getControls(); }); }

    $palette = dbvc_probe_time("getVisibleRecord('vertical:palette_vertical_global_palette')", function () use ($registry) { return $registry->getVisibleRecord('vertical:palette_vertical_global_palette'); });
    $leaf = null; $text = null;
    foreach ($items as $it) {
        if (! $leaf && isset($it['parentPublicId']) && $it['parentPublicId'] === 'vertical:palette_vertical_global_palette') { $leaf = $it['publicId']; }
        if (! $text && isset($it['fieldFamily']) && $it['fieldFamily'] === 'text' && strpos($it['publicId'], 'vertical:') === 0 && ($it['status'] ?? '') === 'available') { $text = $it['publicId']; }
    }
    fwrite(STDERR, "  sample leaf=$leaf text=$text\n");
    $leafRec = $leaf ? dbvc_probe_time("getVisibleRecord(leaf)", function () use ($registry, $leaf) { return $registry->getVisibleRecord($leaf); }) : null;
    $textRec = $text ? dbvc_probe_time("getVisibleRecord(text)", function () use ($registry, $text) { return $registry->getVisibleRecord($text); }) : null;

    if ($leafRec) {
        dbvc_probe_time('buildDescriptorForRecord(leaf) #1', function () use ($registry, $leafRec) { return $registry->buildDescriptorForRecord($leafRec, 'probe_no_session', []); });
        dbvc_probe_time('buildDescriptorForRecord(leaf) #2', function () use ($registry, $leafRec) { return $registry->buildDescriptorForRecord($leafRec, 'probe_no_session', []); });
        dbvc_probe_time('buildValueSummaryForRecord(leaf)', function () use ($registry, $leafRec) { return $registry->buildValueSummaryForRecord($leafRec, 'probe_no_session'); });
    }
    if ($textRec) {
        dbvc_probe_time('buildDescriptorForRecord(text)', function () use ($registry, $textRec) { return $registry->buildDescriptorForRecord($textRec, 'probe_no_session', []); });
        dbvc_probe_time('buildValueSummaryForRecord(text)', function () use ($registry, $textRec) { return $registry->buildValueSummaryForRecord($textRec, 'probe_no_session'); });
    }
    if ($palette) {
        $d = dbvc_probe_time('buildDescriptorForRecord(palette parent, 19 leaves)', function () use ($registry, $palette) { return $registry->buildDescriptorForRecord($palette, 'probe_no_session', []); });
        fwrite(STDERR, "  leaves=" . (is_object($d) && isset($d->source['leaves']) ? count($d->source['leaves']) : 'n/a') . "\n");
    }

    // Session transient (read-only): what addDescriptorToSession() pays per call.
    $sid = getenv('DBVC_PROBE_SESSION') ?: '';
    if ($sid !== '') {
        $key = 'dbvc_visual_editor_session_' . $sid;
        $payload = dbvc_probe_time("get_transient(session $sid)", function () use ($key) { return get_transient($key); });
        if (is_array($payload)) {
            $descs = isset($payload['descriptors']) && is_array($payload['descriptors']) ? $payload['descriptors'] : [];
            fwrite(STDERR, "  descriptors in session=" . count($descs) . " public_map=" . (isset($payload['public_map']) ? count($payload['public_map']) : 0) . " serialized=" . strlen(serialize($payload)) . " bytes\n");
            $resolved = dbvc_probe_time('EditableDescriptor::fromArray() x all', function () use ($descs) { $o = []; foreach ($descs as $t => $i) { if (is_array($i)) { $o[$t] = \Dbvc\VisualEditor\Registry\EditableDescriptor::fromArray($i); } } return $o; });
            $er = new ReflectionProperty($runtime, 'registry'); $er->setAccessible(true); $sessions = $er->getValue($runtime);
            dbvc_probe_time('EditableRegistry::exportPublicMap(all)', function () use ($sessions, $resolved) { return $sessions->exportPublicMap($resolved); });
            dbvc_probe_time('serialize(session payload)', function () use ($payload) { return strlen(serialize($payload)); });
            dbvc_probe_time("loadSession($sid, true)", function () use ($sessions, $sid) { return $sessions->loadSession($sid, true); });
        } else { fwrite(STDERR, "  session transient not found\n"); }
    }

    add_action('shutdown', function () {
        $s = $GLOBALS['dbvc_probe_summary'];
        if (is_array($s)) { fwrite(STDERR, "\n[profiler summary]\n" . wp_json_encode($s, JSON_PRETTY_PRINT) . "\n"); }
    }, 0);
}, 10005);
