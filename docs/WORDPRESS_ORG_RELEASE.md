# WordPress.org release preparation

This procedure prepares a reviewable local tree. It does not submit a plugin, contact WordPress.org, run `svn commit`, or publish files.

## Accounts and access

A second maintainer must confirm the following before publication:

- a GitHub account with permission to review the release pull request, create tag `v1.2.1`, and create a GitHub Release;
- a valid, case-sensitive WordPress.org account for every username in `Contributors:`; `codegenie` must be confirmed by a human;
- plugin submission ownership or SVN commit access for slug `codegenie-pulse-connector` after WordPress.org approval;
- access to GitHub private vulnerability reporting for security coordination;
- access to Pulse production configuration, used only after the official WordPress.org download page is live.

Do not store any account password, SVN-specific password, API token, DSN or production configuration in this repository or a GitHub Actions secret for the preparation workflow.

## Pre-submission checklist

- [ ] Start from the reviewed and merged clean commit; `git status --short` is empty.
- [ ] Confirm the public release version is still 1.2.1 and no immutable 1.2.1 release already exists.
- [ ] `composer validate --strict`, locked install, `composer qa` and the documented integration matrix are green.
- [ ] Official Plugin Check strict reports zero errors against the exact installation ZIP.
- [ ] The installation ZIP is directly installable and contains one root directory: `codegenie-pulse-connector/`.
- [ ] The package includes the main plugin file, `includes/`, `uninstall.php`, `readme.txt`, `LICENSE` and `license.txt`.
- [ ] The package contains no GitHub files, tests, scripts, Composer files, vendor tree, caches, reports or WordPress.org source assets.
- [ ] GPL declaration is `GPLv2 or later` / `GPL-2.0-or-later` and all included code is compatible.
- [ ] Plugin header Version, version constant, build version and `Stable tag` are all 1.2.1.
- [ ] `Requires at least: 6.2`, `Requires PHP: 7.4` and `Tested up to: 7.0` match actual testing through WordPress 7.0.2.
- [ ] External-service disclosure names the selected Pulse installation, explicit consent and the official terms/privacy URLs.
- [ ] Privacy text matches the actual connection, error and deployment payloads and lists excluded data.
- [ ] Plugin name, requested slug and Codegenie/Pulse trademarks have owner approval and do not conflict with WordPress.org policy.
- [ ] Every `Contributors:` username is a valid WordPress.org account and has agreed to be listed.
- [ ] Public support responsibility is assigned; security reports route to GitHub private vulnerability reporting.
- [ ] Icons and banners are approved Codegenie brand assets, not generated approximations.
- [ ] The three screenshot captions in `readme.txt` match approved screenshots of settings, explicit approval and successful connection.
- [ ] Screenshots use only synthetic test data and show no secrets, tokens, nonces, cookies, email addresses, customer names, customer URLs or production dashboards.
- [ ] The two deterministic builds have identical hashes and the sidecar checksum files match the uploaded artifacts.
- [ ] A second maintainer reviews `dist/wordpress-org-svn-dry-run.report.txt`, file manifest and checksums.

## Reproducible SVN mapping

Build the exact installation artifact first, then run:

```sh
php scripts/prepare-wordpress-org.php dist/codegenie-pulse-connector-1.2.1.zip
```

The command performs no network request and invokes no SVN command. It creates:

```text
dist/wordpress-org-svn-dry-run/
├── assets/
├── tags/
│   └── 1.2.1/       # exact runtime contents of the installation ZIP
└── trunk/            # exact runtime contents of the installation ZIP
```

Only image entries marked `approved` with `privacy_review: approved` in `wordpress-org/assets/manifest.json` are copied to `assets/`. Source manifests and approval documentation are never copied to SVN. The shared validator rejects duplicate filenames, unsupported types or statuses, wrong PNG dimensions, missing approved files and assets without privacy approval. The script rejects ZIP files, development tooling and mismatched trunk/tag content, and writes a file manifest, SHA-256 list and human-blocker report next to the tree.

Required assets with `status: missing` are valid source metadata but remain a publication blocker. In that state the dry-run writes its report and exits with status 3. This is intentional. Supply and review each real asset one by one, then change only the reviewed entry to `approved`. Never bypass this result with a generated approximation or an empty image.

For an actual SVN checkout after WordPress.org approval, a maintainer copies the reviewed local `trunk/`, `tags/1.2.1/` and `assets/` contents to the corresponding top-level directories, reviews `svn status` and `svn diff`, and only then performs a separately authorized commit. Never copy the plugin ZIP itself to SVN.

## Publication order

1. Obtain human approval and merge the release pull request.
2. From the clean reviewed commit, create tag `v1.2.1`.
3. Require the tag-triggered `Prepare release artifacts` workflow to complete successfully.
4. Compare workflow artifacts and SHA-256 with the locally reviewed build.
5. Create the GitHub Release manually with the installation ZIP, source ZIP, both manifests, both `.sha256` files and `docs/releases/1.2.1.md` as release notes.
6. Submit the installation ZIP to WordPress.org, or—after initial approval—apply the reviewed dry-run mapping to the plugin SVN repository.
7. Complete any WordPress.org release-confirmation step and wait for directory/CDN processing.
8. Install the official download on a clean WordPress site and verify version, activation and the settings page.
9. Verify the official plugin page, download URL, stable tag, assets and checksum provenance.
10. Only after the official page and download work, set `MONITOR_WORDPRESS_PLUGIN_URL` in Pulse production to that official page.

Each step requires the preceding step to be green. The preparation workflow never performs steps 2, 5, 6 or 10.

## Rollback and immutable releases

- Before publication, discard the candidate artifacts and fix the same release branch if review finds a problem.
- A GitHub Release can be marked superseded or removed according to project policy, but published users may already possess its artifacts.
- Never rewrite or replace a published WordPress.org `tags/1.2.1/` directory.
- A defect discovered after WordPress.org publication receives a new patch version, normally 1.2.2, with a new tag, changelog, artifacts and checksums.
- For an installed-site rollback, deactivate 1.2.1 and reinstall the reviewed 1.2.0 ZIP without uninstalling. Uninstall removes settings and secrets and must not be used as rollback.
