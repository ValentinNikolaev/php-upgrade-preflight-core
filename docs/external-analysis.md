# External analysis

External installation separates the analyzer's runtime dependencies from the target application. Use it when the application runs PHP 7.4, when its Composer constraints reject the tool, or when an audit must leave the repository untouched.

For the published v0.3 line, the supported external path is a Composer installation in a separate tools directory. There is no supported PHAR or published versioned container image. A user-supplied container may still isolate untrusted input, but it is an execution environment for the Composer-installed packages rather than a project release format.

v0.3.2 is the latest Packagist release, so the installation command below uses `^0.3` and the v0.3 behavior documented on this page is the published behavior.

## Directory layout

Keep tools, targets, and reports separate:

```text
/work/php-upgrade-tools
/work/legacy-app
/work/upgrade-reports
```

Create the tools installation with PHP 8.0 or later, without changing into the target directory:

```bash
mkdir -p /work/php-upgrade-tools /work/upgrade-reports
cd /work/php-upgrade-tools
composer require php-upgrade-preflight/cli:^0.3 php-upgrade-preflight/laravel:^0.3
```

Then run from the tools directory:

```bash
vendor/bin/upgrade-intel analyze \
  --path=/work/legacy-app \
  --from-php=7.4 \
  --target=laravel/framework:^9.0 \
  --target-php=8.1 \
  --framework=laravel \
  --output=/work/upgrade-reports/legacy-app.json
```

The `composer require` operation occurs only in `/work/php-upgrade-tools`. The command reads `/work/legacy-app` as target input and writes the report under `/work/upgrade-reports`; it does not install the CLI, Laravel adapter, or any other dependency into the PHP 7.4 application.

PowerShell accepts the same `--name=value` syntax:

```powershell
vendor\bin\upgrade-intel.bat analyze `
  --path=C:\work\legacy-app `
  --from-php=7.4 `
  --target=laravel/framework:^9.0 `
  --target-php=8.1 `
  --framework=laravel `
  --output=C:\work\upgrade-reports\legacy-app.json
```

Quote an entire option when a path contains spaces, for example `"--path=C:\work trees\legacy app"`.

## Runtime and Composer behavior

The executable uses the PHP interpreter and `composer` command available in the tool process environment. Scenario workspaces receive only the target's `composer.json` and `composer.lock`. The analyzer converts relative Composer path-repository URLs to absolute paths before running Composer in a workspace.

Composer scripts and plugins stay disabled in every scenario and diagnostic. The default compatible mode may use normal global configuration, credentials, proxy settings, caches, network access, platform extensions, and repository access. Restricted mode instead uses fresh analyzer-owned Composer state, scrubs controlled credential and proxy inputs, and requests best-effort offline operation; it is not an OS process or network sandbox. Run the tool in a container or restricted account when the target manifest is untrusted, and see [Composer execution policy](composer-execution.md) before choosing a mode.

`--from-php` records the known current runtime and enables staged probes. `--target-php` changes Composer's simulated platform in the temporary manifest. Neither option changes the PHP interpreter that runs the analyzer.

For reproducible target-platform decisions, pass a schema `1.0` JSON profile with `--target-platform-profile=/work/profiles/production.json`. A `complete` profile is closed-world for supported safely simulated PHP, extension, library, and PHP-subtype packages: unlisted packages in those classes are modeled absent. Composer 2.2 or newer is required; an older executable yields operational uncertainty rather than weakening the request. A `partial` profile and legacy named assumptions leave unlisted packages dependent on the analyzer host.

Schema `0.8` records analyzer, current, and target provenance plus the normalized profile under `platform.profile`. Canonical output includes the profile's SHA-256 digest and safe `file` provenance, never its local path. It also identifies Composer platform values bound to the actual executable as `toolchain_bound`. Profile completeness does not freeze repository metadata, downloads, credentials, network behavior, or the Composer executable, so isolate or pin those inputs separately when they matter.

## Redaction boundary

Before canonical JSON or Markdown is rendered, known project, output, local-repository, and temporary-workspace roots are replaced with stable path markers. Structured report values and bounded Composer output are then filtered for credential-bearing URLs, authorization values, named credential fields, and recognized token formats. The same credential filter protects CLI diagnostics, including debug diagnostics.

This boundary protects published output; it does not remove credentials from Composer's process environment or global configuration, block network access, or sanitize the target before its manifest is copied. `--debug` deliberately preserves those copied manifests and exposes exact temporary paths, so retained workspaces and debug reports are non-shareable. Pattern-based redaction cannot guarantee recognition of an unknown credential format. Use short-lived read-only credentials, isolate untrusted manifests, and inspect every artifact before sharing it.

## Verify immutability

Use your normal version-control check before and after an analysis:

```bash
git -C /work/legacy-app status --short
```

The command should show the same output both times. Avoid shell redirection into the project. The application's `--output` validation cannot protect a file opened directly by the shell with `>`.

Release CI exercises this boundary with `tests/fixtures/projects/laravel-7-to-9`: it installs the CLI and Laravel adapter together from release artifacts, requires `--framework=laravel`, verifies that the adapter contributes findings, runs with `--from-php=7.4`, places output outside the fixture, and compares a recursive SHA-256 digest before and after. The gate proves that an external PHP 8 analyzer can model a PHP 7.4 target without installing into or modifying that target.
