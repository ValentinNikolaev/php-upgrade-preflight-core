# Changelog

This project follows [Semantic Versioning](https://semver.org/). Report schema versions follow a separate compatibility policy documented in [docs/schema.md](docs/schema.md).

## [Unreleased]

## [0.1.0] - 2026-08-08

### Added

- Isolated Composer baseline, exact-target, all-dependencies, minimal-change, platform, and staged scenarios.
- Structured solver outcomes, blocker grouping, lock diffs, abandoned-package advisories, and candidate-lock fingerprints.
- Parser-based PHP source impact with evidence for symbols, configuration, middleware, providers, commands, and test doubles.
- Laravel 7 to 8/9 detection and conservative compatibility rules for common first-party, test, Symfony, and legacy packages.
- Canonical JSON schema v0.6, Markdown reports, generic CLI, and Laravel Artisan command.
- Six approved fixture reports in JSON and Markdown, with Windows and Unix CI coverage.
- External-analysis, schema, limitation, troubleshooting, contribution, security, and release documentation.
- Normal and lowest-dependency consumer install gates for PHP 8.0 and Laravel 8/9/10, plus declared Laravel 11/12 host integrations.
- Fresh-clone read-only audits on Windows and Linux, tested release metadata verification, reproducible package archives, and signed-tag-gated GitHub release publication.
- Constructor-level Composer output redaction plus synthetic credential, token, private-URL, report, log, and release-archive leak gates.
- Checksum-verified release-archive consumers that install all three ZIPs, run the CLI, analyze an immutable fixture, and boot Laravel package discovery before publication.
- Composer-installed CLI entry-point discovery for both generated proxy and standard `vendor/` package layouts.

[Unreleased]: https://github.com/ValentinNikolaev/php-upgrade-preflight/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/ValentinNikolaev/php-upgrade-preflight/releases/tag/v0.1.0
