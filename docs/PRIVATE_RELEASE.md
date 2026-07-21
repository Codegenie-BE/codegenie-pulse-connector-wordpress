# Private release procedure

This procedure prepares Codegenie Pulse Connector 1.2.1 for controlled private testing. It does not authorize a Git tag, GitHub Release, WordPress.org upload, SVN write, or production deployment.

## Build evidence

Use one reviewed, clean commit and record its full SHA:

```bash
git status --short
git rev-parse HEAD
composer validate --strict
composer install --no-interaction --prefer-dist
composer audit --locked --no-interaction
composer qa
composer build
```

Run the WordPress matrix from `.github/workflows/qa.yml` and require the `QA and reproducible package` workflow to be green for the same SHA. That workflow runs source QA on PHP 7.4, 8.3 and 8.5, WordPress integration tests on every supported matrix combination, two deterministic builds, package validation and official Plugin Check strict. Missing WordPress.org directory assets do not block this private package route.

The private handoff consists of these files:

```text
dist/codegenie-pulse-connector-1.2.1.zip
dist/codegenie-pulse-connector-1.2.1.files.txt
dist/codegenie-pulse-connector-1.2.1.sha256
dist/codegenie-pulse-connector-wordpress-1.2.1-source.zip
dist/codegenie-pulse-connector-wordpress-1.2.1-source.files.txt
dist/codegenie-pulse-connector-wordpress-1.2.1-source.sha256
```

Verify the installation ZIP before transfer:

```bash
sha256sum --check dist/codegenie-pulse-connector-1.2.1.sha256
php scripts/verify-package.php dist/codegenie-pulse-connector-1.2.1.zip
```

Verify the source archive separately:

```bash
sha256sum --check dist/codegenie-pulse-connector-wordpress-1.2.1-source.sha256
php scripts/verify-source-package.php dist/codegenie-pulse-connector-wordpress-1.2.1-source.zip 1.2.1
```

On Windows, compare `Get-FileHash -Algorithm SHA256` with the corresponding `.sha256` value.

## Private installation and candidate connection

1. Use a new non-production WordPress site containing synthetic data only.
2. In **Plugins > Add New Plugin > Upload Plugin**, select `codegenie-pulse-connector-1.2.1.zip` and activate it.
3. Confirm the installed directory is `codegenie-pulse-connector/`, the displayed version is 1.2.1, and no outbound Pulse request occurred during installation or activation.
4. Use only the Pulse candidate origin and candidate commit approved for this test. Never use production credentials.
5. Start the connection in that isolated Pulse candidate, review the WordPress consent screen, and approve it as an administrator.
6. Exercise one synthetic error and one synthetic deployment. Verify reconnect, downgrade/entitlement loss, deactivation and uninstall while checking both sides for secret-free logs.

Do not copy a DSN, request token, verification token, site proof, nonce, cookie or authorization value into chat, screenshots, CI output, reports or committed fixtures.

## Rollback

For a private test site, deactivate 1.2.1 and reinstall the previously approved installation ZIP. Do not downgrade by rewriting this ZIP. Existing settings remain on deactivation; uninstall deliberately removes only the connector's options, transients and encrypted secrets. Export the test database before uninstall if lifecycle evidence must be retained.

If 1.2.1 has already been published as an immutable artifact, do not replace it. Prepare a new patch version through the normal reviewed release process.

## Publication prohibition

These artifacts are private QA inputs. Do not upload them to GitHub Releases, WordPress.org, WordPress.org SVN, a public download location, or a production Pulse installation without a separate explicit human authorization.
