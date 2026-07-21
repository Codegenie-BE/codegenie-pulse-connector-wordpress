# Contributing

## Local setup

Requirements: Git, PHP 7.4 or newer with OpenSSL, DOM, JSON, mbstring and ZIP, and Composer 2.

```sh
composer install
composer qa
```

`composer qa` runs syntax checks, PHP 7.4 compatibility, WordPress Coding Standards, contract tests, repository-policy checks, and a Composer security audit. Development packages stay in `vendor/` and are never part of the plugin package.

For real WordPress integration tests on Linux/macOS, install MySQL and Subversion, then run:

```sh
bash scripts/install-wp-tests.sh wordpress_test root '' localhost 7.0
WP_TESTS_DIR=/tmp/wordpress-tests-lib vendor/bin/phpunit -c tests/integration/phpunit.xml.dist
```

## Change rules

- Preserve version `1.2.1`, text domain/plugin slug `codegenie-pulse-connector`, and connector ID `codegenie-pulse-connector-wordpress` unless a separately approved release changes them.
- Add focused tests for behavior changes.
- Use synthetic, non-personal test data and obvious placeholder tokens.
- Do not add runtime packages, background workers, telemetry, or external JavaScript.
- Update public-hook documentation when a hook changes.

See [docs/RELEASING.md](docs/RELEASING.md) for the release checklist.
