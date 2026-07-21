# Repository instructions

These instructions apply to the complete repository.

- Preserve the public contracts documented in `README.md`: plugin name, version, slug, text domain, connector ID, minimum WordPress and minimum PHP.
- Do not introduce runtime Composer dependencies, queues, cron jobs, telemetry, or external JavaScript.
- Keep runtime changes separate from repository, test, and release-tooling changes. Explain and test every runtime change.
- Never commit `vendor/`, `dist/`, local reports, credentials, real DSNs, tokens, or personal data.
- Run `composer qa` before proposing a change. Run the WordPress integration suite for changes that depend on WordPress internals.
- Build release packages only from a clean commit with `composer build`. Do not publish, tag, or upload a release as part of the build.
- The WordPress package must contain one root directory named `codegenie-pulse-connector/`.

