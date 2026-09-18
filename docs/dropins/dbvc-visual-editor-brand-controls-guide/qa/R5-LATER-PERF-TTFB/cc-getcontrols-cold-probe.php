<?php
/** Read-only: attribute the cold first getControls() pass of the Vertical provider. STDERR only. */
if (! defined('WP_CLI')) { return; }
$GLOBALS['p_acf'] = 0; WP_CLI::add_wp_hook('acf/load_field', function ($f) { $GLOBALS['p_acf']++; return $f; }, 1);
$GLOBALS['p_q'] = 0; $GLOBALS['p_qlog'] = []; WP_CLI::add_wp_hook('query', function ($q) { $GLOBALS['p_q']++; if (count($GLOBALS['p_qlog']) < 400) { $GLOBALS['p_qlog'][] = substr(preg_replace('/\s+/', ' ', $q), 0, 90); } return $q; }, 1);
function p_line($label, $t0, $q0, $a0) { fwrite(STDERR, sprintf("%-52s %8.1f ms  queries=%-4d acf/load_field=%d\n", $label, (microtime(true) - $t0) * 1000, $GLOBALS['p_q'] - $q0, $GLOBALS['p_acf'] - $a0)); }
WP_CLI::add_wp_hook('init', function () {
    $rc = new ReflectionClass('DBVC_Visual_Editor_Addon'); $p = $rc->getProperty('runtime'); $p->setAccessible(true); $runtime = $p->getValue();
    $registry = $runtime->getControlRegistry();
    $rp = new ReflectionProperty($registry, 'providers'); $rp->setAccessible(true); $vertical = $rp->getValue($registry)['vertical'];
    $lr = new ReflectionMethod($vertical, 'loadRecords'); $lr->setAccessible(true);
    $t0 = microtime(true); $q0 = $GLOBALS['p_q']; $a0 = $GLOBALS['p_acf']; $raw = $lr->invoke($vertical); p_line('loadRecords() cold (json file)', $t0, $q0, $a0);
    $cp = new ReflectionMethod($vertical, 'countPaletteMembers'); $cp->setAccessible(true);
    $t0 = microtime(true); $q0 = $GLOBALS['p_q']; $a0 = $GLOBALS['p_acf']; $cp->invoke($vertical, $raw); p_line('countPaletteMembers() cold', $t0, $q0, $a0);
    $mr = new ReflectionMethod($vertical, 'mapRecord'); $mr->setAccessible(true);
    $me = new ReflectionMethod($vertical, 'maybeExpandRepeaterRecord'); $me->setAccessible(true);
    // first record cold
    $t0 = microtime(true); $q0 = $GLOBALS['p_q']; $a0 = $GLOBALS['p_acf']; $m0 = $mr->invoke($vertical, $raw[0]); p_line('mapRecord(#0) — first ACF touch', $t0, $q0, $a0);
    $t0 = microtime(true); $q0 = $GLOBALS['p_q']; $a0 = $GLOBALS['p_acf']; $mapped = []; foreach ($raw as $i => $r) { $mapped[$i] = $mr->invoke($vertical, $r); } p_line('mapRecord(all 400) after first', $t0, $q0, $a0);
    $qmark = count($GLOBALS['p_qlog']);
    $t0 = microtime(true); $q0 = $GLOBALS['p_q']; $a0 = $GLOBALS['p_acf']; $n = 0; $slow = [];
    foreach ($raw as $i => $r) { if ($mapped[$i] === null) continue; $s = microtime(true); $qq = $GLOBALS['p_q']; $me->invoke($vertical, $r, $mapped[$i], (string) ($r['field_key'] ?? ''), strtolower((string) ($r['field_type'] ?? '')), []); $d = (microtime(true) - $s) * 1000; if ($d > 40) { $slow[] = [$r['field_name'] ?? '?', round($d), $GLOBALS['p_q'] - $qq]; } $n++; }
    p_line("maybeExpandRepeaterRecord(all $n)", $t0, $q0, $a0);
    foreach (array_slice($slow, 0, 12) as $s) { fwrite(STDERR, "   slow: {$s[0]} {$s[1]} ms queries={$s[2]}\n"); }
    $t0 = microtime(true); $q0 = $GLOBALS['p_q']; $a0 = $GLOBALS['p_acf']; $vertical->getControls(); p_line('getControls() warm', $t0, $q0, $a0);
    // query shapes
    $shapes = []; foreach ($GLOBALS['p_qlog'] as $q) { $k = preg_replace('/\d+/', 'N', preg_replace("/'[^']*'/", "'?'", $q)); $k = substr($k, 0, 70); $shapes[$k] = ($shapes[$k] ?? 0) + 1; } arsort($shapes);
    fwrite(STDERR, "\nquery shapes (top 8 of " . $GLOBALS['p_q'] . "):\n"); foreach (array_slice($shapes, 0, 8, true) as $k => $c) { fwrite(STDERR, sprintf("  %4d  %s\n", $c, $k)); }
}, 10005);
