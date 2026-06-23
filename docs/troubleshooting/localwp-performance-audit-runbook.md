# LocalWP Performance Audit Runbook

Use this runbook when auditing a slow LocalWP WordPress site with Bricks, ACF, DBVC, or a similar dynamic-template stack. Replace the placeholders before running commands.

## Inputs To Collect

- `SITE_URL`: local HTTPS URL, for example `https://example.local`
- `SITE_ROOT`: LocalWP public path, for example `/Users/name/Documents/LocalWP/site/app/public`
- `PLUGIN_ROOT`: current repo/plugin path if working inside one
- `MYSQL_BIN`: Local MySQL client path
- `MYSQL_SOCKET`: Local MySQL socket path
- `DB_NAME`: usually `local`
- `DB_USER` / `DB_PASS`: usually `root` / `root`
- Target URLs:
  - homepage `/`
  - at least one known slow frontend page
  - REST index `/wp-json/`
  - relevant backend/admin URL if authenticated cookies are available

Safety rules:

- Touch only the requested LocalWP site.
- Start read-only. Do not mutate DB rows until the candidate rows and rollback path are clear.
- For every DB mutation, create an in-database backup table first.
- Use temporary probe files only when necessary, remove them before finishing, and verify they are gone.
- If a synced DBVC JSON file and live DB diverge, say so. Do not update both unless explicitly asked.

## 1. Baseline Timings

Run sequential timings. Avoid parallel curl for first baselines because LocalWP/PHP-FPM contention can distort results.

```bash
curl -ksS -o /dev/null -w 'home starttransfer=%{time_starttransfer} total=%{time_total} code=%{http_code}\n' "$SITE_URL/"
curl -ksS -o /dev/null -w 'slow_page starttransfer=%{time_starttransfer} total=%{time_total} code=%{http_code}\n' "$SITE_URL/path/to/slow-page/"
curl -ksS -o /dev/null -w 'rest starttransfer=%{time_starttransfer} total=%{time_total} code=%{http_code}\n' "$SITE_URL/wp-json/"
curl -ksS -o /dev/null -w 'wp_content_index starttransfer=%{time_starttransfer} total=%{time_total} code=%{http_code}\n' "$SITE_URL/wp-content/index.php"
```

Interpretation:

- Static/minimal PHP fast, REST slow: WordPress/plugin/theme bootstrap cost.
- REST moderate, Bricks pages much slower: template/render cost.
- TTFB is high but SQL time later proves low: PHP rendering or remote calls, not DB queries.

## 2. Basic WordPress And DB Context

Active theme and active plugins:

```bash
php -d memory_limit=1024M -r 'define("WP_USE_THEMES", false); require "'"$SITE_ROOT"'/wp-load.php"; printf("wp_load=%.3f\n", microtime(true)-($_SERVER["REQUEST_TIME_FLOAT"] ?? microtime(true))); echo "theme=".get_stylesheet()."\n"; foreach ((array) get_option("active_plugins", []) as $p) echo $p, "\n";'
```

Autoloaded options:

```bash
"$MYSQL_BIN" --socket="$MYSQL_SOCKET" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -e '
SELECT COUNT(*) AS autoload_count,
       ROUND(SUM(LENGTH(option_value))/1024/1024, 2) AS autoload_mb
FROM wp_options
WHERE autoload IN ("yes", "on", "auto", "auto-on");

SELECT option_name,
       ROUND(LENGTH(option_value)/1024/1024, 3) AS mb,
       LENGTH(option_value) AS bytes
FROM wp_options
WHERE autoload IN ("yes", "on", "auto", "auto-on")
ORDER BY LENGTH(option_value) DESC
LIMIT 20;
'
```

Check debug logs:

```bash
tail -100 "$SITE_ROOT/wp-content/debug.log"
```

Look for:

- repeated warnings on every request
- outbound API failures or timeouts
- plugin bootstrap logs on frontend requests
- Bricks warnings during render

## 3. ACF Field Bloat Audit

Read-only counts:

```bash
"$MYSQL_BIN" --socket="$MYSQL_SOCKET" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -e '
SELECT COUNT(*) AS acf_field_rows
FROM wp_posts
WHERE post_type = "acf-field";

SELECT post_name, COUNT(*) AS copies, MIN(ID) AS min_id, MAX(ID) AS max_id
FROM wp_posts
WHERE post_type = "acf-field"
GROUP BY post_name
HAVING COUNT(*) > 1
ORDER BY copies DESC
LIMIT 25;
'
```

If a single ACF field key has extreme duplicates, inspect parent distribution before deleting anything:

```bash
"$MYSQL_BIN" --socket="$MYSQL_SOCKET" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -e '
SELECT post_parent, COUNT(*) AS copies, MIN(ID) AS min_id, MAX(ID) AS max_id
FROM wp_posts
WHERE post_type = "acf-field"
  AND post_name = "FIELD_KEY_HERE"
GROUP BY post_parent
ORDER BY copies DESC;
'
```

Before any cleanup:

- identify the keeper row
- verify whether children depend on duplicate rows
- verify ACF runtime still resolves the field
- create backup tables for candidate `wp_posts` rows and matching `wp_postmeta`

Do not clean ACF bloat as part of a general audit unless explicitly asked.

## 4. DBVC Transport Audit

If DBVC is active, verify whether normal page loads trigger remote transport, pull, onboarding, or update checks.

Search code:

```bash
rg -n "run_client_pull_tick|run_client_onboarding_tick|persist_local_transport_state|wp_remote_|pre_http_request|http_api_debug" "$PLUGIN_ROOT"
```

Runtime capture:

- Add a temporary probe or filter that records `pre_http_request` URLs during a frontend render.
- A normal frontend request should not call remote DBVC mothership endpoints.
- If it does, identify the bootstrap hook and move the remote work to explicit save/manual/cron contexts.

## 5. Split Request Time

Use a temporary HTTP probe when curl shows slow frontend pages. Put it somewhere directly reachable in the current site, such as a temporary file inside the active plugin being audited. Remove it before finishing.

The probe should report:

- total time
- `wp-load.php` time
- `wp()` main query time
- template-loader/render time
- query count and total SQL time via `SAVEQUERIES`
- outbound HTTP URLs via `pre_http_request`
- Bricks render areas via `bricks/frontend/before_render_data` and `after_render_data`
- Bricks loop durations via `bricks/query/before_loop` and `after_loop`
- final Bricks post query vars via `bricks/posts/query_vars`

Minimum probe shape:

```php
<?php
$target_uri = $_GET['uri'] ?? '/';
$public = '/absolute/path/to/app/public';

$_SERVER['HTTP_HOST'] = 'site.local';
$_SERVER['REQUEST_URI'] = $target_uri;
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTPS'] = 'on';
$_SERVER['SERVER_PORT'] = '443';

define('WP_USE_THEMES', true);
define('SAVEQUERIES', true);

$t0 = microtime(true);
require $public . '/wp-load.php';
$t_load = microtime(true);

$http = [];
add_filter('pre_http_request', function ($pre, $args, $url) use (&$http) {
    $http[] = [$url, $args['timeout'] ?? null];
    return $pre;
}, 1, 3);

$loops = [];
$stack = [];
add_action('bricks/query/before_loop', function ($query) use (&$stack) {
    $stack[spl_object_hash($query)] = [
        'start' => microtime(true),
        'element_id' => $query->element_id ?? '',
        'element_name' => $query->element_name ?? '',
        'object_type' => $query->object_type ?? '',
        'count' => $query->count ?? 0,
    ];
}, 1, 1);
add_action('bricks/query/after_loop', function ($query) use (&$stack, &$loops) {
    $hash = spl_object_hash($query);
    if (!isset($stack[$hash])) return;
    $entry = $stack[$hash];
    $entry['duration'] = microtime(true) - $entry['start'];
    $loops[] = $entry;
    unset($stack[$hash]);
}, 999, 1);

ob_start();
$t_wp0 = microtime(true);
wp();
$t_wp = microtime(true);
require_once WPINC . '/template-loader.php';
$t_template = microtime(true);
ob_end_clean();

global $wpdb;
$query_time = 0.0;
foreach ((array) ($wpdb->queries ?? []) as $q) {
    $query_time += (float) ($q[1] ?? 0);
}

header('Content-Type: text/plain');
printf("total=%.3f\n", microtime(true) - $t0);
printf("wp_load=%.3f\n", $t_load - $t0);
printf("wp_main=%.3f\n", $t_wp - $t_wp0);
printf("template=%.3f\n", $t_template - $t_wp);
printf("query_count=%d\n", count((array) ($wpdb->queries ?? [])));
printf("query_time=%.3f\n", $query_time);
printf("http_count=%d\n", count($http));
usort($loops, fn($a, $b) => $b['duration'] <=> $a['duration']);
foreach (array_slice($loops, 0, 12) as $i => $loop) {
    printf(
        "loop_%02d=%.3f element=%s#%s object=%s count=%d\n",
        $i + 1,
        $loop['duration'],
        $loop['element_name'],
        $loop['element_id'],
        $loop['object_type'],
        $loop['count']
    );
}
```

Interpretation:

- `wp_load` high and query time low: plugin/theme PHP bootstrap.
- `template` high and query time low: Bricks rendering/dynamic data volume.
- `http_count > 0`: inspect URLs and remove remote calls from normal page loads.
- one or two loop IDs dominate: map them back to Bricks templates and query settings.

## 6. Map Bricks Loop IDs Back To Templates

Search synced JSON and live DB:

```bash
rg -n 'LOOP_ID_HERE' "$SITE_ROOT/wp-content/themes" "$SITE_ROOT/wp-content/plugins"

"$MYSQL_BIN" --socket="$MYSQL_SOCKET" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -e '
SELECT p.ID, p.post_title, p.post_type, p.post_status, pm.meta_key, LENGTH(pm.meta_value) AS bytes
FROM wp_postmeta pm
INNER JOIN wp_posts p ON p.ID = pm.post_id
WHERE pm.meta_key LIKE "_bricks%"
  AND pm.meta_value LIKE "%LOOP_ID_HERE%"
ORDER BY p.post_type, p.ID, pm.meta_key;
'
```

For each heavy loop:

- capture parent/child element IDs
- capture `settings.query`
- determine whether it is native Bricks query settings or Query Editor PHP
- capture final runtime query vars via `bricks/posts/query_vars`
- compare SQL time versus render time

## 7. Query Efficiency Checklist

For Bricks post loops:

- Remove redundant `tax_query` or `meta_query` clauses if output does not change.
- Prefer exact string meta comparisons over numeric `CAST()` when the value is stored as a numeric string and exact equality is enough.
- Add `no_found_rows => true` when there is no pagination.
- Add `ignore_sticky_posts => true` for CPT/menu loops.
- Add `update_post_term_cache => false` only if the rendered loop items do not use terms.
- Keep `update_post_meta_cache` enabled when loop items render ACF/meta fields.
- Avoid `fields => ids` unless Bricks rendering has been proven compatible.
- If a nested loop runs once per term, test whether a custom renderer can query all posts once and group in PHP.

For Bricks mega menus:

- Check whether the same mega menu template renders in desktop nav, offcanvas nav, sticky header, popup, or multiple responsive surfaces.
- Detach or simplify the heavy menu temporarily, measure, then restore.
- Prefer a lightweight mobile/offcanvas menu instead of rendering the full desktop mega menu everywhere.
- Consider lazy-loading mega-menu panels on first hover/click.
- Consider a cached shortcode/custom renderer for stable menu content.

## 8. Plugin Bootstrap Scan

If `wp_load` is high, temporarily exclude active plugins one at a time or in logical groups. Always back up and restore `active_plugins`.

Use a backup option:

```php
$backup_option = 'perf_audit_backup_active_plugins_YYYYMMDD';
$plugins = (array) get_option('active_plugins', []);
update_option($backup_option, $plugins, false);
```

For each test case:

- update `active_plugins` to the original list minus the test plugin/group
- request the load-only probe
- restore the original list in `finally`

Treat probe failures as invalid, not as performance wins. Verify final state:

```php
echo count((array) get_option('active_plugins', []));
```

## 9. Safe DB Mutation Pattern

When asked to update a live Bricks template in DB:

1. Identify active published template rows. Avoid revisions and `_brickssync_versions` unless explicitly asked.
2. Back up exact rows first:

```sql
CREATE TABLE IF NOT EXISTS perf_backup_YYYYMMDD_template_meta LIKE wp_postmeta;
DELETE FROM perf_backup_YYYYMMDD_template_meta
WHERE post_id = TEMPLATE_ID
  AND meta_key IN ("_bricks_page_content_2", "_brickssync_versions");
INSERT INTO perf_backup_YYYYMMDD_template_meta
SELECT *
FROM wp_postmeta
WHERE post_id = TEMPLATE_ID
  AND meta_key IN ("_bricks_page_content_2", "_brickssync_versions");
```

3. Update the active `_bricks_page_content_2` array.
4. If `update_post_meta()` is intercepted by Bricks metadata hooks and does not persist, use `$wpdb->update()` against the known `meta_id` after backup.
5. Flush object cache.
6. Validate:
   - saved meta has expected element query settings
   - runtime final query vars changed as intended
   - loop counts/output IDs are unchanged unless a behavior change was intended
   - live curl timing is captured
7. Remove temporary scripts.

Rollback pattern:

```sql
UPDATE wp_postmeta live
INNER JOIN perf_backup_YYYYMMDD_template_meta backup
        ON backup.meta_id = live.meta_id
SET live.meta_value = backup.meta_value
WHERE live.meta_id = TARGET_META_ID;
```

## 10. Finish Report Template

Report:

- Site URL and target pages tested.
- Baseline timings.
- Whether static PHP, REST, `wp_load`, SQL, template render, or remote HTTP dominated.
- Active plugins/theme context.
- ACF bloat counts and whether cleanup was performed.
- DBVC transport findings if DBVC is active.
- Bricks heavy loops by template, element ID, object type, count, and duration.
- Any DB mutations made, backup table names, and rollback instructions.
- Files or temp probes created and confirmation they were removed.
- Remaining bottleneck and next recommended fix.
