#!/usr/bin/env bash
set -euo pipefail

DB_NAME="${1-wordpress_test}"
DB_USER="${2-root}"
DB_PASS="${3-}"
DB_HOST="${4-localhost}"
WP_VERSION="${5-7.0.2}"
WP_TESTS_DIR="${WP_TESTS_DIR-/tmp/wordpress-tests-lib}"
WP_CORE_DIR="${WP_CORE_DIR-/tmp/wordpress}"

download() {
	if command -v curl >/dev/null 2>&1; then
		curl --fail --silent --show-error --location "$1" --output "$2"
	elif command -v wget >/dev/null 2>&1; then
		wget --quiet --output-document="$2" "$1"
	else
		echo "curl or wget is required." >&2
		exit 1
	fi
}

if [[ ! -d "$WP_CORE_DIR/wp-admin" ]]; then
	mkdir -p "$WP_CORE_DIR"
	tmp_archive="$(mktemp)"
	download "https://wordpress.org/wordpress-${WP_VERSION}.tar.gz" "$tmp_archive"
	tar --strip-components=1 -xzf "$tmp_archive" -C "$WP_CORE_DIR"
	rm -f "$tmp_archive"
fi

if [[ ! -d "$WP_TESTS_DIR/includes" || ! -d "$WP_TESTS_DIR/data" || ! -f "$WP_TESTS_DIR/wp-tests-config-sample.php" ]]; then
	tmp_develop_archive="$(mktemp)"
	tmp_develop_dir="$(mktemp -d)"
	download "https://github.com/WordPress/wordpress-develop/archive/refs/tags/${WP_VERSION}.tar.gz" "$tmp_develop_archive"
	tar --strip-components=1 -xzf "$tmp_develop_archive" -C "$tmp_develop_dir"
	mkdir -p "$WP_TESTS_DIR"
	rm -rf "$WP_TESTS_DIR/includes" "$WP_TESTS_DIR/data"
	cp -R "$tmp_develop_dir/tests/phpunit/includes" "$WP_TESTS_DIR/includes"
	cp -R "$tmp_develop_dir/tests/phpunit/data" "$WP_TESTS_DIR/data"
	cp "$tmp_develop_dir/wp-tests-config-sample.php" "$WP_TESTS_DIR/wp-tests-config-sample.php"
	rm -rf "$tmp_develop_archive" "$tmp_develop_dir"
fi

sed \
	-e "s/youremptytestdbnamehere/${DB_NAME}/" \
	-e "s/yourusernamehere/${DB_USER}/" \
	-e "s/yourpasswordhere/${DB_PASS}/" \
	-e "s|localhost|${DB_HOST}|" \
	-e "s|dirname( __FILE__ ) . '/src/'|'${WP_CORE_DIR}/'|" \
	"$WP_TESTS_DIR/wp-tests-config-sample.php" > "$WP_TESTS_DIR/wp-tests-config.php"

mysql_args=(--user="$DB_USER" --host="${DB_HOST%%:*}")
if [[ -n "$DB_PASS" ]]; then
	mysql_args+=(--password="$DB_PASS")
fi
if [[ "$DB_HOST" == *:* ]]; then
	mysql_args+=(--port="${DB_HOST##*:}")
fi
mysql "${mysql_args[@]}" --execute="DROP DATABASE IF EXISTS \`${DB_NAME}\`; CREATE DATABASE \`${DB_NAME}\` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

echo "Installed WordPress ${WP_VERSION} test suite in ${WP_TESTS_DIR}."
