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

if [ -z "$DB_NAME" ] || [ -z "$DB_USER" ] || [ -z "$DB_PASS" ]; then
	echo "Usage: $0 <db-name> <db-user> <db-pass> [db-host] [wp-version]" >&2
	exit 1
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
    }
fi

cat > "$WP_TESTS_DIR/wp-tests-config.php" <<EOF
<?php
define( 'DB_NAME', '${DB_NAME}' );
define( 'DB_USER', '${DB_USER}' );
define( 'DB_PASSWORD', '${DB_PASS}' );
define( 'DB_HOST', '${DB_HOST}' );
define( 'WP_DB_NAME', '${DB_NAME}' );
define( 'WP_DEBUG', true );
define( 'ABSPATH', '${WP_CORE_DIR}' );
EOF

mysqladmin create "${DB_NAME}" --user="${DB_USER}" --password="${DB_PASS}" --host="${DB_HOST}" --silent || true
