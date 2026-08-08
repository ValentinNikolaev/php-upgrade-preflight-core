# PHP Upgrade Preflight

PHP Upgrade Preflight analyzes Composer-based PHP upgrades before you change the target project. It copies `composer.json` and `composer.lock` into temporary workspaces, runs isolated Composer scenarios, scans source files, and produces a canonical JSON report or a Markdown projection.

The v0.1 package line supports PHP `^8.0` (PHP 8.0 through PHP 8.x). Its first framework adapter covers Laravel 7 projects targeting Laravel 8 or 9, including PHP platform changes and common Laravel package constraints.

The Laravel adapter can be installed alongside Laravel 8 and 9 on PHP 8.0, Laravel 10 on PHP 8.1, and the additionally declared Laravel 11/12 host integrations on PHP 8.2. These host-installability ranges do not expand the v0.1 upgrade-rule scope beyond Laravel 8/9 targets.

## Install

Install the CLI and Laravel adapter in a separate tools directory when the target project still runs PHP 7 or must remain byte-for-byte unchanged:

```bash
mkdir php-upgrade-tools
cd php-upgrade-tools
composer require php-upgrade-preflight/cli php-upgrade-preflight/laravel
```

You can install both packages as development dependencies in a project that already runs PHP 8.0 or later:

```bash
composer require --dev php-upgrade-preflight/cli php-upgrade-preflight/laravel
```

See [Installation](docs/installation.md) and [External analysis](docs/external-analysis.md) for package choices, Windows commands, and the PHP 7.4 workflow.

## Run an analysis

```bash
vendor/bin/upgrade-intel analyze \
  --path=/projects/legacy-app \
  --from-php=7.4 \
  --target=laravel/framework:^9.0 \
  --target-php=8.1 \
  --framework=laravel \
  --format=json \
  --output=/projects/reports/legacy-app.json
```

Laravel applications with the adapter installed also expose an Artisan command:

```bash
php artisan upgrade:analyze \
  --from-php=7.4 \
  --target=laravel/framework:^9.0 \
  --target-php=8.1 \
  --format=markdown \
  --output=/projects/reports/legacy-app.md
```

The analyzer returns `0` after producing a valid report, including reports whose `resolution.status` is `blocked` or `unknown`. Automation should read that field instead of treating the process exit code as upgrade feasibility.

## Read-only analysis

The analyzer treats the target project as immutable input. Composer runs only in disposable workspaces, with scripts and plugins disabled. Report destinations supplied through `--output` must sit outside the analyzed project. Tests snapshot every fixture file before analysis and verify the original bytes afterward.

Composer stdout, stderr, diagnostics, and command failure messages pass through a deterministic secret boundary before they can reach reports or console diagnostics. Credential-bearing URLs, authorization values, common token forms, and named credential fields are replaced with stable redaction markers; release CI also scans generated reports and archives with synthetic canaries.

`--debug` preserves temporary workspaces for investigation. A debug run therefore leaves copied Composer files on disk and reports each path.

## Reports

JSON is the canonical report. The current contract uses schema version `0.6`, independent of the tool version. Reports contain:

- scenario commands, solver outcomes, diagnostics, and candidate-lock fingerprints;
- package changes, structured blockers, source usage, and framework findings;
- staged actions, test guidance, risk, effort, uncertainty, and linked evidence.

Six application-shaped Laravel fixtures have approved JSON and Markdown snapshots in [`packages/laravel/tests/Snapshots`](packages/laravel/tests/Snapshots). CI runs the same suite on Ubuntu and Windows.

## Packages

- `php-upgrade-preflight/core` contains the analysis pipeline and report contract.
- `php-upgrade-preflight/cli` provides `upgrade-intel analyze`.
- `php-upgrade-preflight/laravel` provides Laravel detection, rules, and `upgrade:analyze`.

## Documentation

- [Installation](docs/installation.md)
- [External analysis](docs/external-analysis.md)
- [CLI reference](docs/cli.md)
- [Artisan reference](docs/artisan.md)
- [JSON schema and compatibility](docs/schema.md)
- [Limitations and trust boundaries](docs/limitations.md)
- [Troubleshooting](docs/troubleshooting.md)
- [Contributing](CONTRIBUTING.md)
- [Security policy](SECURITY.md)
- [Changelog](CHANGELOG.md)
- [Versioning policy](docs/versioning.md)

## Development

The Docker environment uses PHP 8.3 while Composer resolves development dependencies against PHP 8.0.30, the runtime floor.

```bash
docker compose build php
docker compose run --rm php composer install
docker compose run --rm php composer check
```

`composer check` validates every package manifest, runs PHPUnit, performs static analysis, and checks formatting. See [CONTRIBUTING.md](CONTRIBUTING.md) for focused test commands and snapshot updates.

## License

Copyright 2026 Valentin Nikolaev. The [PolyForm Noncommercial License 1.0.0](LICENSE) permits noncommercial use. Commercial use requires a separate license from the copyright holder.
