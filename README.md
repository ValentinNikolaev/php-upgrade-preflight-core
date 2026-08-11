# PHP Upgrade Preflight

PHP Upgrade Preflight analyzes Composer-based PHP upgrades before you change the target project. It copies `composer.json` and `composer.lock` into temporary workspaces, runs isolated Composer scenarios, scans source files, and produces a canonical JSON report or a Markdown projection.

Version 0.2.0 supports PHP `^8.0` (PHP 8.0 through PHP 8.x). Its Laravel adapter retains Laravel 7 to 8 and direct 7 to 9 analysis and adds adjacent Laravel 8 to 9, 9 to 10, 10 to 11, 11 to 12, and 12 to 13 rule packs, including target-platform, official-package, test-tool, and selected high-signal source checks.

The Laravel adapter can be installed alongside Laravel 8 on PHP 8.0, Laravel 9 on PHP 8.0.2, Laravel 10 on PHP 8.1, Laravel 11/12 on PHP 8.2, and Laravel 13 on PHP 8.3. Host installability and analyzed target requirements remain separate: an external analyzer may model a newer target through Composer platform simulation.

## Install

Install the CLI and Laravel adapter in a separate tools directory when the target project still runs PHP 7 or must remain byte-for-byte unchanged:

```bash
mkdir php-upgrade-tools
cd php-upgrade-tools
composer require php-upgrade-preflight/cli:^0.2 php-upgrade-preflight/laravel:^0.2
```

This separate Composer tools-directory installation is the supported external execution path for v0.2. v0.2 does not ship or support a PHAR or a versioned container image; the repository Docker files are development tooling, not release artifacts.

You can install both packages as development dependencies in a project that already runs PHP 8.0 or later:

```bash
composer require --dev php-upgrade-preflight/cli:^0.2 php-upgrade-preflight/laravel:^0.2
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

Redaction is a publication safeguard, not an execution sandbox. Composer can still read its configured credentials and contact declared repositories, retained debug workspaces contain copied manifests, and no pattern-based filter can recognize every credential format. Use scoped credentials, isolate untrusted projects, and review reports before sharing them.

The analyzer keeps exact project and source paths for internal filesystem access. Default shareable JSON and Markdown replace absolute local roots with stable markers: `[PROJECT_ROOT]` for the analyzed project, `[REPORT_OUTPUT]` for the report destination, `[LOCAL_REPOSITORY]` for local Composer repositories, and `[ANALYZER_WORKSPACE]` for analyzer-owned temporary roots. Reported source files remain project-relative.

`--debug` preserves temporary workspaces and exposes exact `temp_path` values. Debug reports and retained workspaces are therefore non-shareable artifacts. Without `--debug`, cleanup failures report only `[ANALYZER_WORKSPACE]`. Credential redaction remains active in every mode.

## Reports

JSON is the canonical report. Version 0.2.0 produces schema `0.7` reports; the v0.1 package line produced schema `0.6`. Reports contain:

- scenario commands, solver outcomes, diagnostics, and candidate-lock fingerprints;
- platform provenance with explicit host-dependence uncertainty, package changes, structured blockers, raw source inventory, Composer-autoload-owned actionable source impact, framework transition guidance, and hop-scoped framework findings;
- staged actions, test guidance, risk, effort, uncertainty, and linked evidence.

Schema `0.7` moves the raw parser observations formerly stored in `source_impact` to `source_inventory`. Its new `source_impact` contains only findings correlated with a selected package change or an applicable framework rule. The new top-level `platform` object records where analyzer, current, target, and extension values came from. See [JSON schema and compatibility](docs/schema.md) before consuming both 0.6 and 0.7 reports.

Laravel guidance is supported for 7→8, the retained direct 7→9 path, and every adjacent hop from 8→9 through 12→13. A multi-major upgrade is supported when the catalog provides every required adjacent hop; advice stops at the first gap. Ambiguous or unknown majors, same-major requests, downgrades, a source or target outside Laravel 7–13, and requests whose first required hop is missing are unsupported. Framework guidance coverage is independent of final-target Composer feasibility, so consumers must read both `transition.framework_guidance[].status` and `resolution.status`.

Six application-shaped Laravel fixtures have approved JSON and Markdown snapshots in [`packages/laravel/tests/Snapshots`](packages/laravel/tests/Snapshots). CI runs the same suite on Ubuntu and Windows.

## Packages

- `php-upgrade-preflight/core` contains the analysis pipeline and report contract.
- `php-upgrade-preflight/cli` provides `upgrade-intel analyze`.
- `php-upgrade-preflight/laravel` provides Laravel detection, rules, and `upgrade:analyze`.

Third-party adapter packages register themselves through Composer metadata, so they can provide detection, source paths, rules, and package-family classification without editing the CLI. See [Framework adapters](docs/adapters.md).

## Documentation

- [Installation](docs/installation.md)
- [External analysis](docs/external-analysis.md)
- [Framework adapters](docs/adapters.md)
- [CLI reference](docs/cli.md)
- [Artisan reference](docs/artisan.md)
- [JSON schema and compatibility](docs/schema.md)
- [v0.2 report and transition contract](docs/v0.2-contract.md)
- [Laravel v0.2 transition scope](docs/laravel-v0.2-transition-scope.md)
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

`composer check` is the offline, deterministic gate: it validates every package manifest, runs the unit, integration, and smoke PHPUnit suites, performs static analysis, and checks formatting. Live compatibility installs and dependency audits run in separate workflows. See [CONTRIBUTING.md](CONTRIBUTING.md) for the documented `test:unit`, `test:integration`, `test:smoke`, and `test:all` commands, focused package tests, and snapshot updates.

## License

Copyright 2026 Valentin Nikolaev. The [PolyForm Noncommercial License 1.0.0](LICENSE) permits noncommercial use. Commercial use requires a separate license from the copyright holder.
