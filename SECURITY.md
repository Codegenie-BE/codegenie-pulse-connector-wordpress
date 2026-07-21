# Security policy

## Supported version

Security fixes target the current `1.2.x` line. Older releases may be assessed, but are not guaranteed to receive fixes.

## Reporting a vulnerability

Do not open a public issue containing exploit details, credentials, DSNs, tokens, personal data, or private logs. Use GitHub's private vulnerability reporting for this repository. Include affected versions, reproduction steps, impact, and a minimal redacted proof of concept.

Maintainers should acknowledge a report within five working days, validate impact privately, and coordinate disclosure after a fix is available. Never use production secrets in a report.

## Supply-chain policy

- Runtime code has no Composer or JavaScript dependencies.
- Development dependencies are locked in `composer.lock` and audited in CI.
- GitHub Actions use supported major-version pins so security patches remain available; Dependabot proposes updates for review.
- Release packages are built from a clean commit, inspected, integrity-tested, and accompanied by SHA-256.
- Release publication and tagging are deliberate maintainer actions and are never performed by the build script.

