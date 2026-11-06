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

if [ -z "$WP_TESTS_DIR" ] || [ -z "$WP_CORE_DIR" ]; then
	abort "WP_TESTS_DIR and WP_CORE_DIR must not be empty."
fi

if [ "$WP_TESTS_TABLE_PREFIX" = "wp_" ]; then
	abort "WP_TESTS_TABLE_PREFIX must not use the live WordPress default prefix 'wp_'."
fi

LIVE_TABLE_PREFIX=$(detect_live_table_prefix)
if [ -n "$LIVE_TABLE_PREFIX" ] && [ "$WP_TESTS_TABLE_PREFIX" = "$LIVE_TABLE_PREFIX" ] && [ "$DBVC_WP_TESTS_ALLOW_UNSAFE" != "1" ]; then
	abort "WP_TESTS_TABLE_PREFIX matches the live site prefix '${LIVE_TABLE_PREFIX}'. Choose an isolated test prefix such as 'wptests_'."
fi

# The test library must match the core version under test. Trunk's includes
# reference core APIs that stable releases do not define, so a trunk test suite
# cannot bootstrap against a tagged release. Mirrors the tag resolution in
# github.com/wp-cli/scaffold-command.
if [[ $WP_VERSION =~ ^[0-9]+\.[0-9]+-(beta|RC)[0-9]+$ ]]; then
	WP_TESTS_TAG="branches/${WP_VERSION%-*}"
elif [[ $WP_VERSION =~ ^[0-9]+\.[0-9]+$ ]]; then
	WP_TESTS_TAG="branches/$WP_VERSION"
elif [[ $WP_VERSION =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
	if [[ $WP_VERSION =~ ^[0-9]+\.[0-9]+\.0$ ]]; then
		# WordPress tags the first release of a major series as x.y, not x.y.0.
		WP_TESTS_TAG="tags/${WP_VERSION%.0}"
	else
		WP_TESTS_TAG="tags/$WP_VERSION"
	fi
elif [ "$WP_VERSION" = "nightly" ] || [ "$WP_VERSION" = "trunk" ]; then
	WP_TESTS_TAG="trunk"
elif [ "$WP_VERSION" = "latest" ]; then
	# http serves a single offer; https serves several and we only want one.
	VERSION_CHECK_FILE=$(mktemp)
	download "http://api.wordpress.org/core/version-check/1.7/" "$VERSION_CHECK_FILE"
	LATEST_VERSION=$(grep -o '"version":"[^"]*' "$VERSION_CHECK_FILE" | head -n 1 | sed 's/"version":"//')
	rm -f "$VERSION_CHECK_FILE"
	if [ -z "$LATEST_VERSION" ]; then
		abort "Could not determine the latest WordPress version from api.wordpress.org."
	fi
	WP_TESTS_TAG="tags/$LATEST_VERSION"
else
	abort "Unrecognised WordPress version '${WP_VERSION}'. Use x.y, x.y.z, x.y-beta1, x.y-RC1, latest, nightly, or trunk."
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

WP_TESTS_TAG_FILE="$WP_TESTS_DIR/.wp-tests-tag"
INSTALLED_TESTS_TAG=""
if [ -f "$WP_TESTS_TAG_FILE" ]; then
	INSTALLED_TESTS_TAG=$(cat "$WP_TESTS_TAG_FILE")
fi

if [ ! -d "$WP_TESTS_DIR/includes" ] || [ "$INSTALLED_TESTS_TAG" != "$WP_TESTS_TAG" ]; then
	if ! command -v svn >/dev/null; then
		echo "Error: svn is required to fetch the WordPress test suite. Please install Subversion and rerun." >&2
		exit 1
	fi

	# A library left behind by an earlier run may point at another tag or trunk.
	rm -rf "$WP_TESTS_DIR/includes" "$WP_TESTS_DIR/data"
	mkdir -p "$WP_TESTS_DIR"
	svn co --quiet "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/tests/phpunit/includes/" "$WP_TESTS_DIR/includes"
	svn co --quiet "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/tests/phpunit/data/" "$WP_TESTS_DIR/data"
	printf '%s\n' "$WP_TESTS_TAG" > "$WP_TESTS_TAG_FILE"
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
