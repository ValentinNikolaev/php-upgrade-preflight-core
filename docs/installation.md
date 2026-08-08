# Installation

PHP Upgrade Preflight v0.1 requires PHP `^8.0` (PHP 8.0 through PHP 8.x) and Composer 2. Composer 2.4 or later enables locked `composer prohibits` diagnostics; older Composer 2 releases still run the primary scenarios and record that the locked diagnostic is unavailable.

## Runtime compatibility

The three analyzer packages have a PHP 8.0 runtime floor. Composer selects compatible transitive versions at that floor; normal and `--prefer-lowest` consumer installs are checked separately from the deterministic test suite.

| Installed package | Host PHP floor | Compatibility gate |
| --- | --- | --- |
| Core or CLI | PHP 8.0 | Clean normal and lowest-dependency installs |
| Laravel adapter with Laravel 8 | PHP 8.0 | Clean install plus provider autoload |
| Laravel adapter with Laravel 9 | PHP 8.0.2 | Tested on the latest PHP 8.0 patch |
| Laravel adapter with Laravel 10 | PHP 8.1 | Clean install plus provider autoload |
| Laravel adapter with Laravel 11 or 12 | PHP 8.2 | Declared host integration smoke test |

Laravel's own PHP requirement determines the effective floor when it is higher than the analyzer's PHP 8.0 floor. The v0.1 rule set analyzes upgrades to Laravel 8 and 9; installability with newer Laravel hosts only means the adapter and Artisan integration can coexist with those framework versions.

## Choose the packages

Install `php-upgrade-preflight/cli` for the standalone `upgrade-intel` executable. Add `php-upgrade-preflight/laravel` when you need Laravel detection and rules:

```bash
composer require --dev php-upgrade-preflight/cli php-upgrade-preflight/laravel
```

Install only the Laravel package when you need the Artisan command and do not need the generic executable:

```bash
composer require --dev php-upgrade-preflight/laravel
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
composer require php-upgrade-preflight/cli php-upgrade-preflight/laravel
vendor/bin/upgrade-intel --help
```

PowerShell:

```powershell
New-Item -ItemType Directory php-upgrade-tools
Set-Location php-upgrade-tools
composer require php-upgrade-preflight/cli php-upgrade-preflight/laravel
vendor\bin\upgrade-intel.bat --help
```

Run the external tool with PHP 8.0 or later. Model the target project's current PHP through `--from-php` and its requested runtime through `--target-php`. The target application does not need to boot.

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
