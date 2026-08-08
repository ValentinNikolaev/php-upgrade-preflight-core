# Artisan reference

Installing `php-upgrade-preflight/laravel` registers the service provider through Laravel package discovery. The provider adds:

```text
php artisan upgrade:analyze [options]
```

The command defaults `--path` to the Laravel application's base path and always enables the Laravel adapter. Its targets, source paths, formats, output validation, debug behavior, and exit policy match the standalone CLI.

## Options

| Option | Meaning |
| --- | --- |
| `--path=PATH` | Project directory. Defaults to the current Laravel application. |
| `--target=PACKAGE:CONSTRAINT` | Requested package constraint. Repeat as needed. |
| `--target-php=VERSION` | Exact target PHP platform version. |
| `--from-php=VERSION` | Known current PHP version. |
| `--source=PATH` | File or directory inside the project. Repeat as needed. |
| `--format=json\|markdown` | Report format. Defaults to `json`. |
| `--output=PATH` | Report file outside the analyzed project. |
| `--debug` | Preserve temporary workspaces. |

Example:

```bash
php artisan upgrade:analyze \
  --from-php=7.4 \
  --target=laravel/framework:^8.0 \
  --target-php=8.0 \
  --format=markdown \
  --output=/work/reports/laravel-8.md
```

Artisan must boot before it can run the command. Use the external CLI when the current PHP interpreter cannot boot the application, the application has broken service providers, or installing the adapter would disturb the dependency graph.

The command returns `0` after writing any valid report, including a blocked result. It returns `2` for invalid invocation and `1` when it cannot produce a report.
