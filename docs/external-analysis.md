# External analysis

External installation separates the analyzer's runtime dependencies from the target application. Use it when the application runs PHP 7.4, when its Composer constraints reject the tool, or when an audit must leave the repository untouched.

For v0.2, the supported external path is a Composer installation in a separate tools directory. There is no supported PHAR or published versioned container image. A user-supplied container may still isolate untrusted input, but it is an execution environment for the Composer-installed packages rather than a project release format.

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
composer require php-upgrade-preflight/cli:^0.2 php-upgrade-preflight/laravel:^0.2
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

Composer scripts and plugins stay disabled in every scenario and diagnostic. Composer still uses its normal global configuration, credentials, network access, platform extensions, and repository access. Run the tool in a container or restricted account when the target manifest is untrusted.

`--from-php` records the known current runtime and enables staged probes. `--target-php` changes Composer's simulated platform in the temporary manifest. Neither option changes the PHP interpreter that runs the analyzer.

Schema `0.7` records those distinctions in `platform`: analyzer PHP always has `runtime` provenance; current PHP comes from `--from-php`, the target's original `config.platform.php`, or remains `unknown`; target PHP comes from the request or remains `unknown`. Extension assumptions come from the target's original `config.platform` and the request, with request values taking precedence. Listed assumptions are deterministic, but every unlisted extension still comes from the analyzer host. Because the CLI cannot describe a complete extension inventory, `--with-extension` and `--without-extension` produce `completeness: partial`, not a claim of full host independence.

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
