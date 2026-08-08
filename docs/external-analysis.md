# External analysis

External installation separates the analyzer's runtime dependencies from the target application. Use it when the application runs PHP 7.4, when its Composer constraints reject the tool, or when an audit must leave the repository untouched.

## Directory layout

Keep tools, targets, and reports separate:

```text
/work/php-upgrade-tools
/work/legacy-app
/work/upgrade-reports
```

Run from the tools directory:

```bash
vendor/bin/upgrade-intel analyze \
  --path=/work/legacy-app \
  --from-php=7.4 \
  --target=laravel/framework:^9.0 \
  --target-php=8.1 \
  --framework=laravel \
  --output=/work/upgrade-reports/legacy-app.json
```

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

## Verify immutability

Use your normal version-control check before and after an analysis:

```bash
git -C /work/legacy-app status --short
```

The command should show the same output both times. Avoid shell redirection into the project. The application's `--output` validation cannot protect a file opened directly by the shell with `>`.
