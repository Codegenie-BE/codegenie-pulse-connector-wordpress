# Release process

## Contract check

The names deliberately differ:

- Git repository: `codegenie-pulse-connector-wordpress` — identifies this implementation and hosting project.
- WordPress plugin directory, installation slug, and text domain: `codegenie-pulse-connector` — the stable WordPress identity.
- Protocol connector ID: `codegenie-pulse-connector-wordpress` — the stable identifier exchanged with Codegenie Pulse.

Changing the repository name does not migrate installed plugin files. Changing the plugin slug/text domain breaks WordPress identity and translations. Changing the connector ID breaks protocol discovery. Do not rename any of them during a maintenance release.

## Reproducible build

1. Start from a clean clone and review `git status --short`.
2. Install the locked development dependencies with `composer install`.
3. Run `composer qa` and the WordPress 6.2/7.0.2 integration matrix on PHP 7.4, 8.3, and 8.5 (CI is the reference environment).
4. Confirm Plugin Check reports zero errors and review every warning.
5. Commit all approved source changes. The build intentionally refuses a dirty tree.
6. Run `composer build` twice. Both reported SHA-256 values must match.
7. Inspect both 1.2.1 file manifests and ZIPs manually.

The builder uses `git archive` with the committed tree and a fixed archive timestamp. The WordPress installation ZIP applies `.gitattributes` `export-ignore` rules and has one prefix: `codegenie-pulse-connector/`. A second source ZIP archives the same committed tree without those package exclusions under `codegenie-pulse-connector-wordpress-1.2.1-source/`. The builder checks both roots, required and forbidden files, ZIP integrity, file manifests, version contracts, and SHA-256.

Expected artifact:

```text
dist/codegenie-pulse-connector-1.2.1.zip
dist/codegenie-pulse-connector-1.2.1.files.txt
dist/codegenie-pulse-connector-1.2.1.sha256
dist/codegenie-pulse-connector-wordpress-1.2.1-source.zip
dist/codegenie-pulse-connector-wordpress-1.2.1-source.files.txt
dist/codegenie-pulse-connector-wordpress-1.2.1-source.sha256
```

The package must retain `LICENSE`, `license.txt`, `readme.txt`, `codegenie-pulse-connector.php`, `index.php`, `includes/`, and `uninstall.php`. It must not contain Git metadata, GitHub workflows, tests, scripts, Composer files/dependencies, reports, local configuration, or WordPress.org source assets.

The build does not create a tag and does not publish to GitHub Releases or WordPress.org. Those are separate, explicitly authorized maintainer actions.

## Release preparation workflow

`.github/workflows/release-prepare.yml` runs only for an explicit semantic version tag or a manual dry-run with an exact version input. It has read-only repository permissions, consumes no production secrets, runs the complete source and WordPress integration matrices, builds twice, verifies both ZIPs, runs official Plugin Check strict, creates the local WordPress.org SVN mapping, and uploads review artifacts. It never creates a tag or release and never writes to WordPress.org SVN.

For a manual preparation run, select `Prepare release artifacts` in GitHub Actions, choose `Run workflow`, and enter `1.2.1`. For the later human-created tag, the required tag name is `v1.2.1`; WordPress.org uses the numeric directory `tags/1.2.1/` without the `v` prefix.

## WordPress.org handoff

Follow [WORDPRESS_ORG_RELEASE.md](WORDPRESS_ORG_RELEASE.md) for accounts, the pre-submission checklist, network-free SVN dry-run, publication order and rollback. WordPress.org directory images belong only in top-level SVN `assets/`; runtime files belong directly in `trunk/` and `tags/1.2.1/`. Never place a ZIP in SVN and never rewrite a published WordPress.org tag.
