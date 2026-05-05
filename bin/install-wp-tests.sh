#!/usr/bin/env bash
#
# Installs the WordPress unit test suite locally.
# Usage: bin/install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [wp-version]
#
# Based on the official script from github.com/wp-cli/scaffold-command.

set -e

DB_NAME=$1
DB_USER=$2
DB_PASS=$3
DB_HOST=${4-localhost}
WP_VERSION=${5-latest}
WP_TESTS_DIR=${WP_TESTS_DIR-$(dirname "$0")/../tmp/wordpress-tests-lib}
WP_CORE_DIR=${WP_CORE_DIR-$(dirname "$0")/../tmp/wordpress/}
WP_TESTS_TABLE_PREFIX=${WP_TESTS_TABLE_PREFIX-wptests_}
WP_TESTS_DOMAIN=${WP_TESTS_DOMAIN-dbvc-phpunit.local}
WP_TESTS_EMAIL=${WP_TESTS_EMAIL-admin@dbvc-phpunit.local}
WP_TESTS_TITLE=${WP_TESTS_TITLE-DBVC PHPUnit}
WP_PHP_BINARY=${WP_PHP_BINARY-php}
DBVC_WP_TESTS_ALLOW_UNSAFE=${DBVC_WP_TESTS_ALLOW_UNSAFE-0}
PLUGIN_ROOT=$(cd "$(dirname "$0")/.." && pwd)

download() {
	if command -v curl >/dev/null; then
		curl -L "$1" > "$2"
	elif command -v wget >/dev/null; then
		wget -nv -O "$2" "$1"
	else
		echo "Error: curl or wget is required to download files." >&2
		exit 1
	fi
}

abort() {
	echo "Error: $1" >&2
	exit 1
}

detect_live_table_prefix() {
	local wp_config_path
	local prefix

	wp_config_path="${PLUGIN_ROOT}/../../../wp-config.php"
	if [ ! -f "$wp_config_path" ]; then
		return 0
	fi

	prefix=$(sed -n "s/^[[:space:]]*\\\$table_prefix[[:space:]]*=[[:space:]]*'\\([^']*\\)'.*/\\1/p" "$wp_config_path" | head -n 1)
	printf '%s' "$prefix"
}

if [ -z "$DB_NAME" ] || [ -z "$DB_USER" ] || [ -z "$DB_PASS" ]; then
	echo "Usage: $0 <db-name> <db-user> <db-pass> [db-host] [wp-version]" >&2
	exit 1
fi

if [ -z "$WP_TESTS_TABLE_PREFIX" ]; then
	abort "WP_TESTS_TABLE_PREFIX must not be empty."
fi

if [ "$WP_TESTS_TABLE_PREFIX" = "wp_" ]; then
	abort "WP_TESTS_TABLE_PREFIX must not use the live WordPress default prefix 'wp_'."
fi

LIVE_TABLE_PREFIX=$(detect_live_table_prefix)
if [ -n "$LIVE_TABLE_PREFIX" ] && [ "$WP_TESTS_TABLE_PREFIX" = "$LIVE_TABLE_PREFIX" ] && [ "$DBVC_WP_TESTS_ALLOW_UNSAFE" != "1" ]; then
	abort "WP_TESTS_TABLE_PREFIX matches the live site prefix '${LIVE_TABLE_PREFIX}'. Choose an isolated test prefix such as 'wptests_'."
fi

mkdir -p "$WP_CORE_DIR"
mkdir -p "$WP_TESTS_DIR"

if [ "$WP_VERSION" = "latest" ]; then
	ARCHIVE_URL="https://wordpress.org/latest.tar.gz"
else
	ARCHIVE_URL="https://wordpress.org/wordpress-$WP_VERSION.tar.gz"
fi

TMPFILE=$(mktemp)
download "$ARCHIVE_URL" "$TMPFILE"
tar --strip-components=1 -zxmf "$TMPFILE" -C "$WP_CORE_DIR"
rm -f "$TMPFILE"

if [ ! -f "$WP_CORE_DIR/wp-content/db.php" ]; then
	mkdir -p "$WP_CORE_DIR/wp-content"
	download "https://github.com/markoheijnen/wp-mysqli/raw/master/db.php" "$WP_CORE_DIR/wp-content/db.php"
fi

if [ ! -d "$WP_TESTS_DIR/includes" ]; then
    mkdir -p "$WP_TESTS_DIR"
    if command -v svn >/dev/null; then
        svn co --quiet https://develop.svn.wordpress.org/trunk/tests/phpunit/includes/ "$WP_TESTS_DIR/includes"
        svn co --quiet https://develop.svn.wordpress.org/trunk/tests/phpunit/data/ "$WP_TESTS_DIR/data"
    else
        echo "Error: svn is required to fetch the WordPress test suite. Please install Subversion and rerun." >&2
        exit 1
    fi
fi

cat > "$WP_TESTS_DIR/wp-tests-config.php" <<EOF
<?php
define( 'DB_NAME', '${DB_NAME}' );
define( 'DB_USER', '${DB_USER}' );
define( 'DB_PASSWORD', '${DB_PASS}' );
define( 'DB_HOST', '${DB_HOST}' );
define( 'WP_DB_NAME', '${DB_NAME}' );
define( 'WP_DEBUG', true );
define( 'WP_TESTS_DOMAIN', '${WP_TESTS_DOMAIN}' );
define( 'WP_TESTS_EMAIL', '${WP_TESTS_EMAIL}' );
define( 'WP_TESTS_TITLE', '${WP_TESTS_TITLE}' );
define( 'WP_PHP_BINARY', '${WP_PHP_BINARY}' );
\$table_prefix = '${WP_TESTS_TABLE_PREFIX}';
define( 'ABSPATH', '${WP_CORE_DIR}' );
EOF

mysqladmin create "${DB_NAME}" --user="${DB_USER}" --password="${DB_PASS}" --host="${DB_HOST}" --silent || true
