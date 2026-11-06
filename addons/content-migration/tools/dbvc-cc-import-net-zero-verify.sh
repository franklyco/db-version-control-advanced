#!/usr/bin/env bash
#
# G-7 net-zero live verification: execute a Content Migration import against a
# disposable clone of a real WordPress site, roll it back, and prove the clone's
# posts, post meta, terms, term relationships and media library returned to
# their prior bytes.
#
# The clone is made here, written to here, and dropped here. Nothing in this
# script writes to the source site: it reads one mysqldump and copies WordPress
# core files. The one path it links rather than copies is wp-content/themes,
# read-only, so the clone can resolve a theme.
#
# This is the shell half of the proof. The WordPress half is
# dbvc-cc-import-net-zero-run.php, which drives the shipping commitment path.
#
# Usage:
#   addons/content-migration/tools/dbvc-cc-import-net-zero-verify.sh \
#     --source-docroot=/path/to/live/public \
#     --source-db=local --db-user=root --db-pass=root \
#     --db-socket='/path/to/mysqld.sock' \
#     --artifacts=/path/to/dbvc-artifacts \
#     --domain=example.com \
#     --work=/path/to/scratch
#
# Exit status: 0 net zero, 4 differences found, anything else a setup failure.

set -euo pipefail

SOURCE_DOCROOT=""
SOURCE_DB=""
DB_USER="root"
DB_PASS="root"
DB_SOCKET=""
DB_HOST=""
CLONE_DB="dbvc_s4c_clone"
ARTIFACTS=""
DOMAIN=""
WORK=""
MEDIA_PORT="8731"
PATHS=""
KEEP_CLONE="0"
PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"

for arg in "$@"; do
    case "$arg" in
        --source-docroot=*) SOURCE_DOCROOT="${arg#*=}" ;;
        --source-db=*) SOURCE_DB="${arg#*=}" ;;
        --db-user=*) DB_USER="${arg#*=}" ;;
        --db-pass=*) DB_PASS="${arg#*=}" ;;
        --db-socket=*) DB_SOCKET="${arg#*=}" ;;
        --db-host=*) DB_HOST="${arg#*=}" ;;
        --clone-db=*) CLONE_DB="${arg#*=}" ;;
        --artifacts=*) ARTIFACTS="${arg#*=}" ;;
        --domain=*) DOMAIN="${arg#*=}" ;;
        --work=*) WORK="${arg#*=}" ;;
        --media-port=*) MEDIA_PORT="${arg#*=}" ;;
        --paths=*) PATHS="${arg#*=}" ;;
        --plugin-dir=*) PLUGIN_DIR="${arg#*=}" ;;
        --keep-clone) KEEP_CLONE="1" ;;
        *) echo "Unknown argument: $arg" >&2; exit 1 ;;
    esac
done

for required in SOURCE_DOCROOT SOURCE_DB ARTIFACTS DOMAIN WORK; do
    if [[ -z "${!required}" ]]; then
        echo "Missing required --${required//_/-}" >&2
        exit 1
    fi
done

if [[ "$CLONE_DB" == "$SOURCE_DB" ]]; then
    echo "Refusing to run: the clone database must not be the source database." >&2
    exit 1
fi

CLONE="$WORK/clone"
STATE="$WORK/state"
MEDIA="$WORK/media"
mkdir -p "$WORK" "$STATE" "$MEDIA"

log() { echo "[net-zero] $*"; }

mysql_args=(-u"$DB_USER" -p"$DB_PASS")
if [[ -n "$DB_SOCKET" ]]; then
    mysql_args+=(--protocol=socket --socket="$DB_SOCKET")
elif [[ -n "$DB_HOST" ]]; then
    mysql_args+=(-h"$DB_HOST")
fi

my() { mysql "${mysql_args[@]}" "$@" 2>/dev/null; }

# The tables whose bytes the proof is about.
CONTENT_TABLES=(wp_posts wp_postmeta wp_terms wp_termmeta wp_term_taxonomy wp_term_relationships)
# The journal is an append-only audit record of the run, so it is reported
# separately rather than being expected to return to its prior bytes.
JOURNAL_TABLES=(wp_dbvc_cc_import_runs wp_dbvc_cc_import_run_actions)

cleanup() {
    local status=$?
    if [[ -f "$MEDIA/server.pid" ]]; then
        kill "$(cat "$MEDIA/server.pid")" 2>/dev/null || true
        rm -f "$MEDIA/server.pid"
    fi
    if [[ "$KEEP_CLONE" != "1" ]]; then
        my -e "DROP DATABASE IF EXISTS \`$CLONE_DB\`;" || true
        rm -rf "$CLONE"
        log "clone dropped"
    else
        log "clone kept at $CLONE (database $CLONE_DB)"
    fi
    exit "$status"
}
trap cleanup EXIT

# --------------------------------------------------------------------------
# 1. Build the disposable clone.
# --------------------------------------------------------------------------
log "cloning $SOURCE_DB -> $CLONE_DB"
mysqldump "${mysql_args[@]}" --single-transaction --default-character-set=utf8mb4 \
    "$SOURCE_DB" > "$WORK/source.sql" 2>/dev/null
my -e "DROP DATABASE IF EXISTS \`$CLONE_DB\`; CREATE DATABASE \`$CLONE_DB\` DEFAULT CHARACTER SET utf8mb4;"
my "$CLONE_DB" < "$WORK/source.sql"

log "copying WordPress core from $SOURCE_DOCROOT"
rm -rf "$CLONE"
mkdir -p "$CLONE/wp-content/plugins" "$CLONE/wp-content/uploads" "$CLONE/wp-content/dbvc-sync"
cp -R "$SOURCE_DOCROOT/wp-admin" "$SOURCE_DOCROOT/wp-includes" "$CLONE/"
for file in index.php wp-activate.php wp-blog-header.php wp-comments-post.php wp-cron.php \
    wp-links-opml.php wp-load.php wp-login.php wp-mail.php wp-settings.php wp-signup.php \
    wp-trackback.php xmlrpc.php; do
    cp "$SOURCE_DOCROOT/$file" "$CLONE/"
done
ln -s "$SOURCE_DOCROOT/wp-content/themes" "$CLONE/wp-content/themes"
ln -s "$PLUGIN_DIR" "$CLONE/wp-content/plugins/$(basename "$PLUGIN_DIR")"

cat > "$CLONE/wp-config.php" <<PHPCONFIG
<?php
define('DB_NAME', '$CLONE_DB');
define('DB_USER', '$DB_USER');
define('DB_PASSWORD', '$DB_PASS');
define('DB_HOST', '${DB_HOST:-localhost}${DB_SOCKET:+:$DB_SOCKET}');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');
define('AUTH_KEY', 'dbvc-net-zero-clone');
define('SECURE_AUTH_KEY', 'dbvc-net-zero-clone');
define('LOGGED_IN_KEY', 'dbvc-net-zero-clone');
define('NONCE_KEY', 'dbvc-net-zero-clone');
define('AUTH_SALT', 'dbvc-net-zero-clone');
define('SECURE_AUTH_SALT', 'dbvc-net-zero-clone');
define('LOGGED_IN_SALT', 'dbvc-net-zero-clone');
define('NONCE_SALT', 'dbvc-net-zero-clone');
define('DISABLE_WP_CRON', true);
define('AUTOMATIC_UPDATER_DISABLED', true);
define('WP_HTTP_BLOCK_EXTERNAL', true);
define('WP_ACCESSIBLE_HOSTS', '127.0.0.1,localhost');
define('WP_DEBUG', false);
\$table_prefix = 'wp_';
if (! defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
require_once ABSPATH . 'wp-settings.php';
PHPCONFIG

# Only the plugin under test loads; uploads and the plugin's own sync export are
# redirected inside the clone so every file the run touches is observable here.
php -r '
$active = serialize([$argv[1] . "/db-version-control.php"]);
$statements = [
    "UPDATE wp_options SET option_value=\"" . addslashes($active) . "\" WHERE option_name=\"active_plugins\";",
    "UPDATE wp_options SET option_value=\"twentytwentyfive\" WHERE option_name IN (\"template\",\"stylesheet\");",
    "UPDATE wp_options SET option_value=\"" . addslashes($argv[2]) . "/wp-content/uploads\" WHERE option_name=\"upload_path\";",
    "UPDATE wp_options SET option_value=\"http://dbvc-net-zero.invalid/wp-content/uploads\" WHERE option_name=\"upload_url_path\";",
    "UPDATE wp_options SET option_value=\"http://dbvc-net-zero.invalid\" WHERE option_name IN (\"siteurl\",\"home\");",
    "UPDATE wp_options SET option_value=\"wp-content/dbvc-sync\" WHERE option_name=\"dbvc_sync_path\";",
    "DELETE FROM wp_options WHERE option_name LIKE \"\\_transient%\" OR option_name LIKE \"\\_site\\_transient%\";",
];
echo implode("\n", $statements), "\n";
' "$(basename "$PLUGIN_DIR")" "$CLONE" | my "$CLONE_DB"

cat > "$WORK/boot.php" <<PHPBOOT
<?php
\$_SERVER['HTTP_HOST'] = 'dbvc-net-zero.invalid';
\$_SERVER['SERVER_NAME'] = 'dbvc-net-zero.invalid';
\$_SERVER['REQUEST_URI'] = '/';
define('WP_USE_THEMES', false);
require '$CLONE/wp-load.php';
require \$argv[1];
PHPBOOT

# --------------------------------------------------------------------------
# 2. Fixture media server. Captured image URLs are rewritten onto it so the
#    sideload is a real HTTP download that is offline and byte-deterministic.
# --------------------------------------------------------------------------
log "building fixture media"
php -r '
$art = $argv[1];
$root = $argv[2];
$paths = [];
foreach (glob($art . "/*.page-artifact.v1.json") as $file) {
    $decoded = json_decode((string) file_get_contents($file), true);
    foreach (($decoded["images"] ?? []) as $image) {
        if (! empty($image["url"])) { $paths[parse_url($image["url"], PHP_URL_PATH)] = true; }
    }
}
foreach (glob($art . "/*.elements.v2.json") as $file) {
    $decoded = json_decode((string) file_get_contents($file), true);
    foreach (($decoded["elements"] ?? []) as $element) {
        $src = $element["attributes"]["src"] ?? "";
        if ($src !== "" && strpos($src, "data:") !== 0) { $paths[parse_url($src, PHP_URL_PATH)] = true; }
    }
}
unset($paths[""], $paths[null]);
$index = 0;
foreach (array_keys($paths) as $path) {
    $target = $root . $path;
    @mkdir(dirname($target), 0777, true);
    $width = 320 + ($index % 7) * 11;
    $height = 240 + ($index % 5) * 13;
    $image = imagecreatetruecolor($width, $height);
    imagefilledrectangle($image, 0, 0, $width, $height, imagecolorallocate($image, ($index * 37) % 256, ($index * 59) % 256, ($index * 83) % 256));
    imagestring($image, 5, 8, 8, basename($path), imagecolorallocate($image, 255, 255, 255));
    imagejpeg($image, $target, 88);
    imagedestroy($image);
    $index++;
}
fwrite(STDERR, "[net-zero] fixture images: $index\n");
' "$ARTIFACTS" "$MEDIA/docroot"

php -S "127.0.0.1:$MEDIA_PORT" -t "$MEDIA/docroot" > "$MEDIA/server.log" 2>&1 &
echo $! > "$MEDIA/server.pid"
sleep 1

# --------------------------------------------------------------------------
# 3. Authoritative state capture.
# --------------------------------------------------------------------------
NORMALIZE="$PLUGIN_DIR/addons/content-migration/tools/dbvc-cc-import-net-zero-normalize.php"
STORAGE_ROOT="contentcollector"

capture_state() {
    local label="$1"
    local dir="$STATE/$label"
    mkdir -p "$dir"

    mysqldump "${mysql_args[@]}" --no-tablespaces --skip-comments --skip-dump-date \
        --skip-extended-insert --complete-insert --order-by-primary \
        --default-character-set=utf8mb4 --hex-blob \
        "$CLONE_DB" "${CONTENT_TABLES[@]}" 2>/dev/null > "$dir/content.sql"
    php "$NORMALIZE" auto-increment < "$dir/content.sql" > "$dir/content-rows.sql"
    php "$NORMALIZE" post-modified < "$dir/content.sql" > "$dir/content-rows-no-post-modified.sql"
    php "$NORMALIZE" postmeta-content < "$dir/content.sql" > "$dir/postmeta-content.txt"

    mysqldump "${mysql_args[@]}" --no-tablespaces --skip-comments --skip-dump-date \
        --skip-extended-insert --complete-insert --order-by-primary \
        --default-character-set=utf8mb4 --hex-blob \
        "$CLONE_DB" "${JOURNAL_TABLES[@]}" 2>/dev/null > "$dir/journal.sql"

    # The media library's files on disk, excluding the add-on's own storage root.
    ( cd "$CLONE/wp-content/uploads" && find . -type f -not -path "./$STORAGE_ROOT/*" -print0 \
        | sort -z | xargs -0 -r shasum -a 256 ) > "$dir/uploads-media.sha256" 2>/dev/null \
        || : > "$dir/uploads-media.sha256"
    # The add-on's own storage root: staged capture artifacts, hardening files and
    # append-only run logs. Reported, never gating.
    ( cd "$CLONE/wp-content/uploads" && find . -type f -path "./$STORAGE_ROOT/*" -print0 \
        | sort -z | xargs -0 -r shasum -a 256 ) > "$dir/uploads-storage.sha256" 2>/dev/null \
        || : > "$dir/uploads-storage.sha256"
    ( cd "$CLONE/wp-content/uploads" && find . -type d -not -path "./$STORAGE_ROOT*" | sort ) \
        > "$dir/uploads-dirs.txt" 2>/dev/null || : > "$dir/uploads-dirs.txt"
    ( cd "$CLONE/wp-content/dbvc-sync" && find . -type f -print0 | sort -z \
        | xargs -0 -r shasum -a 256 ) > "$dir/dbvc-sync.sha256" 2>/dev/null || : > "$dir/dbvc-sync.sha256"

    my "$CLONE_DB" -N -e "
        SELECT 'posts', COUNT(*) FROM wp_posts
        UNION ALL SELECT 'attachments', COUNT(*) FROM wp_posts WHERE post_type='attachment'
        UNION ALL SELECT 'postmeta', COUNT(*) FROM wp_postmeta
        UNION ALL SELECT 'terms', COUNT(*) FROM wp_terms
        UNION ALL SELECT 'term_taxonomy', COUNT(*) FROM wp_term_taxonomy
        UNION ALL SELECT 'term_relationships', COUNT(*) FROM wp_term_relationships
        UNION ALL SELECT 'termmeta', COUNT(*) FROM wp_termmeta;" > "$dir/rowcounts.txt"
    # Read the counters out of the dump's own DDL. information_schema's copy is
    # approximate in MySQL 8 and can be served stale, which would understate this.
    grep -E 'CREATE TABLE|AUTO_INCREMENT=' "$dir/content.sql" \
        | grep -oE '(CREATE TABLE `[a-z_]+`|AUTO_INCREMENT=[0-9]+)' > "$dir/auto-increment.txt"

    log "captured $label: $(tr '\n' ' ' < "$dir/rowcounts.txt")"
}

capture_state before

# --------------------------------------------------------------------------
# 4. Import and roll back through the shipping path.
# --------------------------------------------------------------------------
log "running import + rollback"
set +e
DBVC_CC_NET_ZERO_CONFIRM=1 php "$WORK/boot.php" \
    "$PLUGIN_DIR/addons/content-migration/tools/dbvc-cc-import-net-zero-run.php" -- \
    --domain="$DOMAIN" --artifacts="$ARTIFACTS" \
    --media-base="http://127.0.0.1:$MEDIA_PORT" \
    ${PATHS:+--paths="$PATHS"} \
    --report="$WORK/run-report.json" 2>"$WORK/run.log"
RUN_STATUS=$?
set -e
grep '^\[net-zero\]' "$WORK/run.log" || true
if [[ "$RUN_STATUS" -ne 0 ]]; then
    echo "Import runner failed with status $RUN_STATUS; see $WORK/run.log" >&2
    tail -20 "$WORK/run.log" >&2
    exit "$RUN_STATUS"
fi

capture_state after

# --------------------------------------------------------------------------
# 5. Diff.
# --------------------------------------------------------------------------
DIFF_DIR="$WORK/diff"
mkdir -p "$DIFF_DIR"
FOUND=0

changed_lines() { grep -c '^[+-][^+-]' "$1" 2>/dev/null || true; }

diff_gating() {
    local name="$1"
    local file="$2"
    if diff -u "$STATE/before/$file" "$STATE/after/$file" > "$DIFF_DIR/$name.diff"; then
        log "GATE $name: NET ZERO"
        return 0
    fi
    log "GATE $name: DIFFERS ($(changed_lines "$DIFF_DIR/$name.diff") changed lines) -> $DIFF_DIR/$name.diff"
    FOUND=1
    return 1
}

diff_reported() {
    local name="$1"
    local file="$2"
    local note="$3"
    diff -u "$STATE/before/$file" "$STATE/after/$file" > "$DIFF_DIR/$name.diff" || true
    log "reported $name ($note): $(changed_lines "$DIFF_DIR/$name.diff") changed lines -> $DIFF_DIR/$name.diff"
}

# Gating: the bytes G-7 is about.
#
# post_modified is the one content column a rollback cannot restore: the executor
# captures eight post columns for the before-state and post_modified is not among
# them, so the wp_update_post() that restores the row stamps it with the current
# time. It is named as an exclusion rather than dropped quietly, and the exclusion
# is kept honest by measuring both comparisons: the strict one is reported with
# its exact line count, and the gating one blanks only post_modified and
# post_modified_gmt. If the strict comparison differs while the gating one is net
# zero, post_modified is provably the only difference. Any other drift fails.
diff_gating rowcounts rowcounts.txt || true
diff_gating content-rows-no-post-modified content-rows-no-post-modified.sql || true
diff_gating postmeta-content postmeta-content.txt || true
diff_gating uploads-media uploads-media.sha256 || true
diff_reported content-rows-strict content-rows.sql "post_modified on the rows the import updated"
STRICT_LINES="$(changed_lines "$DIFF_DIR/content-rows-strict.diff")"
if [[ "${STRICT_LINES:-0}" -gt 0 ]]; then
    log "  strict content comparison differs on $STRICT_LINES lines (one before + one after per row) = post_modified + post_modified_gmt on $(( STRICT_LINES / 2 )) updated post rows"
    if grep -E '^[+-][^+-]' "$DIFF_DIR/content-rows-strict.diff" | grep -qv 'INSERT INTO `wp_posts`'; then
        log "  GATE content-rows-strict: a non-wp_posts line differs; post_modified is NOT the only drift"
        FOUND=1
    fi
fi

# Reported and named, never gating. Each one is justified in the guide's
# exclusions table; none of them is target-site content restored by a journal.
diff_reported journal journal.sql "append-only audit record of the run"
diff_reported auto-increment auto-increment.txt "InnoDB counters advance and never rewind"
diff_reported uploads-storage uploads-storage.sha256 "the add-on's own storage root and run logs"
diff_reported uploads-dirs uploads-dirs.txt "empty month directories a deleted attachment leaves behind"
diff_reported dbvc-sync dbvc-sync.sha256 "the plugin's own export-on-save, a separate subsystem"

if [[ "$FOUND" -eq 0 ]]; then
    log "RESULT: net zero across posts, post meta, terms, term relationships and media files"
    log "        with post_modified on the updated rows the one named exclusion"
    exit 0
fi

log "RESULT: differences found in a gating comparison; see $DIFF_DIR"
exit 4
