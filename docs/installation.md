# Installation

PHP Upgrade Preflight v0.2.0 requires PHP `^8.0` (PHP 8.0 through PHP 8.x) and Composer 2. Composer 2.2 or later is required when simulating an absent extension because earlier releases cannot hide platform packages through `config.platform`. On Composer 2.0 or 2.1, those target scenarios stop before workspace creation and report an operational uncertainty. Composer 2.4 or later enables locked `composer prohibits` diagnostics; older Composer 2 releases still run supported primary scenarios and record that the locked diagnostic is unavailable.

## Runtime compatibility

The three analyzer packages have a PHP 8.0 runtime floor. Composer selects compatible transitive versions at that floor; normal and `--prefer-lowest` consumer installs are checked separately from the deterministic test suite.

| Installed package                     | Host PHP floor | Compatibility gate                          |
|---------------------------------------|----------------|---------------------------------------------|
| Core or CLI                           | PHP 8.0        | Clean normal and lowest-dependency installs |
| Laravel adapter with Laravel 8        | PHP 8.0        | Clean install plus provider autoload        |
| Laravel adapter with Laravel 9        | PHP 8.0.2      | Tested on the latest PHP 8.0 patch          |
| Laravel adapter with Laravel 10       | PHP 8.1        | Clean install plus provider autoload        |
| Laravel adapter with Laravel 11 or 12 | PHP 8.2        | Clean temporary-application boot test       |
| Laravel adapter with Laravel 13       | PHP 8.3        | Clean temporary-application boot test       |

Laravel's own PHP requirement determines the effective floor when it is higher than the analyzer's PHP 8.0 floor. The adapter is host-installable on Laravel 8–13; analyze Laravel 7 from an external tools directory. Installability is checked independently from guidance coverage: the networked compatibility workflow creates a clean temporary application and boots package discovery plus the Artisan command on every Laravel 8–13 host line, at normal and lowest dependency resolution.

The transition catalog covers Laravel 7→8, the retained direct 7→9 path, and every adjacent transition from 8→9 through 12→13. Gapless adjacent packs can compose a multi-major guidance path within Laravel 7–13. Same-major requests, downgrades, ambiguous or unknown majors, targets outside that range, and requests whose first required hop is absent are unsupported. If a future or third-party catalog covers only a contiguous prefix, the report is `partially_supported` and guidance stops before the gap.

## Choose the packages

Install `php-upgrade-preflight/cli` for the standalone `upgrade-intel` executable. Add `php-upgrade-preflight/laravel` when you need Laravel detection and rules:

```bash
composer require --dev php-upgrade-preflight/cli:^0.2 php-upgrade-preflight/laravel:^0.2
```

Install only the Laravel package when you need the Artisan command and do not need the generic executable:

```bash
composer require --dev php-upgrade-preflight/laravel:^0.2
```

Install `php-upgrade-preflight/core` directly only when you are building another adapter or calling the PHP API.

## Project-local installation

A project-local `composer require` changes the project's manifest and lockfile as part of installation. The analyzer keeps the project read-only after installation, but the install step cannot meet a byte-for-byte audit requirement.

Project-local installation works when the project runs PHP 8.0 or later and its dependency graph accepts the analyzer packages. Composer exposes the executable as `vendor/bin/upgrade-intel` on Unix and `vendor\\bin\\upgrade-intel.bat` on Windows. Laravel package discovery registers the service provider and Artisan command.

## External installation

Use a separate tools directory for PHP 7 projects, dependency-constrained applications, or immutable audits:

```bash
mkdir php-upgrade-tools
cd php-upgrade-tools
composer require php-upgrade-preflight/cli:^0.2 php-upgrade-preflight/laravel:^0.2
vendor/bin/upgrade-intel --help
```

PowerShell:

```powershell
New-Item -ItemType Directory php-upgrade-tools
Set-Location php-upgrade-tools
composer require php-upgrade-preflight/cli:^0.2 php-upgrade-preflight/laravel:^0.2
vendor\bin\upgrade-intel.bat --help
```

Run the external tool with PHP 8.0 or later. Model the target project's current PHP through `--from-php` and its requested runtime through `--target-php`. The target application does not need to boot.

This Composer tools-directory installation is the only supported external delivery format for v0.2. Releases remain normal Composer packages, including checksum-verified package ZIPs used by release automation. Those ZIPs are Composer distribution artifacts; they are not a standalone PHAR. v0.2 does not publish or support a PHAR or a versioned container image. The repository `Dockerfile` and Compose configuration are for development and verification only.

Install every desired framework adapter in the tools directory alongside the CLI. Adapter packages register their integration classes through Composer metadata; see [Framework adapters](adapters.md). Nothing needs to be installed in the target project.

## Source checkout

Contributors can run the monorepo through Docker:

```bash
git clone https://github.com/ValentinNikolaev/php-upgrade-preflight.git
cd php-upgrade-preflight
docker compose build php
docker compose run --rm php composer install
docker compose run --rm php packages/cli/bin/upgrade-intel --help
```

Packagist releases come from the three package subtrees. The root `composer.json` exists for monorepo development and is not an installable distribution package.
