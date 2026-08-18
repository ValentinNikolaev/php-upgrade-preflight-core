# PHP Upgrade Preflight

> [!IMPORTANT]
> **Project status: Public beta.** PHP Upgrade Preflight is source-available software, free for noncommercial use under the [PolyForm Noncommercial License 1.0.0](LICENSE). Commercial use requires a separate license. It is not distributed as Open Source.

PHP Upgrade Preflight analyzes Composer-based PHP upgrades before you change the target project. It copies `composer.json` and `composer.lock` into temporary workspaces, runs isolated Composer scenarios, scans source files, and produces a canonical JSON report or a Markdown projection.

The v0.3 release candidate on `main` runs on PHP `^8.0` (PHP 8.0 through PHP 8.x). Its Laravel adapter retains Laravel 7 to 8 and direct 7 to 9 guidance plus adjacent Laravel 8 to 9 through 12 to 13 rule packs. Rooted `laravel/framework` projects can run sequential Composer evidence across every contiguous adjacent path from Laravel 7 through 13, while the direct final-target result remains independent.

The Laravel adapter can be installed alongside Laravel 8 on PHP 8.0, Laravel 9 on PHP 8.0.2, Laravel 10 on PHP 8.1, Laravel 11/12 on PHP 8.2, and Laravel 13 on PHP 8.3. Host installability and analyzed target requirements remain separate: an external analyzer may model a newer target through Composer platform simulation.

## Public beta and compatibility

The released v0.2.x line keeps the public PHP operation, CLI and Artisan behavior, adapter metadata, exit policy, schema `0.7` compatibility, and supported transition claims backward-compatible across patch releases. Bug fixes, security fixes, and evidence corrections may still change individual findings or diagnostics without changing those contracts.

The `main` branch is preparing v0.3.0 and identifies candidate reports as tool `0.3.0` with schema `0.8`; its Composer aliases remain `0.3.x-dev` with `^0.3` internal constraints. v0.3.0 is not yet a published package release. Released v0.2.1 remains the installable and security-supported line while signed tags, archives, Packagist synchronization, and the published-package quick start are pending.

Public beta is not a production-readiness claim. The analyzer provides decision-support evidence: it does not perform an upgrade, execute the analyzed application, prove runtime compatibility, or guarantee a successful deployment. Review every report and validate the resulting upgrade with the application's own test and deployment process. See [Project status and licensing](docs/project-status.md), [Versioning](docs/versioning.md), and [Limitations and trust boundaries](docs/limitations.md).

## Install

Install the CLI and Laravel adapter in a separate tools directory when the target project still runs PHP 7 or must remain byte-for-byte unchanged:

```bash
mkdir php-upgrade-tools
cd php-upgrade-tools
composer require php-upgrade-preflight/cli:^0.2 php-upgrade-preflight/laravel:^0.2
```

This separate Composer tools-directory installation is the supported external execution path for the currently published v0.2 line. v0.2 does not ship or support a PHAR or a versioned container image; the repository Docker files are development tooling, not release artifacts. The commands will switch to `^0.3` only after v0.3.0 tags and packages are published and verified.

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

### Five-minute offline demo

The repository includes a deterministic [Laravel 10 to 13 demo](examples/five-minute-demo/README.md) that uses real offline Composer solves from local path repositories. Its schema `0.8` report keeps direct-final, framework-guidance, and staged resolution separate; retains two simultaneous 10→11 blockers and their different lifecycles; carries selected candidate-state fingerprints through a feasible middle hop; and stops 12→13 on a distinct extension blocker plus an original-source incompatibility. Recursive before/after digests prove that the target stayed unchanged.

![Laravel 10 to 13 terminal demo](examples/five-minute-demo/laravel-10-to-13.gif)

## Read-only analysis

The analyzer treats the target project as immutable input. Composer runs only in disposable workspaces, with scripts and plugins disabled. Report destinations supplied through `--output` must sit outside the analyzed project. Tests snapshot every fixture file before analysis and verify the original bytes afterward.

Composer stdout, stderr, diagnostics, and command failure messages pass through a deterministic secret boundary before they can reach reports or console diagnostics. Credential-bearing URLs, authorization values, common token forms, and named credential fields are replaced with stable redaction markers; release CI also scans generated reports and archives with synthetic canaries.

Redaction is a publication safeguard, not an execution sandbox. Composer can still read its configured credentials and contact declared repositories, retained debug workspaces contain copied manifests, and no pattern-based filter can recognize every credential format. Use scoped credentials, isolate untrusted projects, and review reports before sharing them.

The analyzer keeps exact project and source paths for internal filesystem access. Default shareable JSON and Markdown replace absolute local roots with stable markers: `[PROJECT_ROOT]` for the analyzed project, `[REPORT_OUTPUT]` for the report destination, `[LOCAL_REPOSITORY]` for local Composer repositories, and `[ANALYZER_WORKSPACE]` for analyzer-owned temporary roots. Reported source files remain project-relative.

`--debug` preserves temporary workspaces and exposes exact `temp_path` values. Debug reports and retained workspaces are therefore non-shareable artifacts. Without `--debug`, cleanup failures report only `[ANALYZER_WORKSPACE]`. Credential redaction remains active in every mode.

## Reports

JSON is the canonical report. The v0.3.0 release candidate produces schema `0.8`; published v0.2.1 produces schema `0.7`, and v0.1 produced schema `0.6`. Reports contain:

- scenario commands, solver outcomes, diagnostics, and candidate-lock fingerprints;
- safe Composer execution provenance, including compatible/restricted mode, version expectation, timeouts, inherited state, and offline policy;
- platform provenance with explicit host-dependence uncertainty, package changes, structured blockers, raw source inventory, Composer-autoload-owned actionable source impact, framework transition guidance, and hop-scoped framework findings;
- independent direct-final and staged Composer resolution, including adjacent stages, attempts, selected-state fingerprints, blocker lifecycles, stage-scoped changes, and original-snapshot source findings;
- staged actions, test guidance, risk, effort, uncertainty, and linked evidence.

Schema `0.8` adds required `staged_resolution` without changing the meaning of schema 0.7's direct `resolution` or framework guidance. See [JSON schema and compatibility](docs/schema.md) and the [v0.3 staged-analysis contract](docs/v0.3-contract.md) before consuming multiple versions.

Laravel guidance is supported for 7→8, the retained direct 7→9 path, and every adjacent hop from 8→9 through 12→13. A multi-major upgrade is supported when the catalog provides every required adjacent hop; advice stops at the first gap. Ambiguous or unknown majors, same-major requests, downgrades, a source or target outside Laravel 7–13, and requests whose first required hop is missing are unsupported. Schema 0.8 consumers must read `transition.framework_guidance[].status`, `resolution.status`, and `staged_resolution.status` separately.

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
- [Composer execution policy](docs/composer-execution.md)
- [JSON schema and compatibility](docs/schema.md)
- [Project status and licensing](docs/project-status.md)
- [v0.2 report and transition contract](docs/v0.2-contract.md)
- [v0.3 staged-analysis contract](docs/v0.3-contract.md)
- [v0.3.0 release-candidate notes](docs/releases/v0.3.0.md)
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

## License and commercial use

Copyright 2026 Valentin Nikolaev. PHP Upgrade Preflight is source-available software, free for noncommercial use under the [PolyForm Noncommercial License 1.0.0](LICENSE). Commercial use requires a separate license from the copyright holder. [Request a commercial license](https://docs.google.com/forms/d/e/1FAIpQLSfUlJJnSoqgUuJnKUCGzQQpIeXZtz471iD_XiPTjdnODbooYw/viewform). The project is not distributed as Open Source. The license text controls if this summary and the license differ.
