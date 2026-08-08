# CLI reference

The standalone command accepts one subcommand and `--name=value` options:

```text
upgrade-intel analyze --target=package:constraint [options]
```

## Options

| Option | Meaning |
| --- | --- |
| `--path=PATH` | Project directory. Defaults to the current directory. |
| `--target=PACKAGE:CONSTRAINT` | Requested package constraint. Repeat for multiple packages. |
| `--target-php=VERSION` | Exact target PHP platform version. |
| `--from-php=VERSION` | Known current PHP version used for staging analysis. |
| `--source=PATH` | File or directory to scan inside the project. Repeat as needed. |
| `--framework=NAME` | Installed framework adapter to enable. Repeat as needed. |
| `--format=json\|markdown` | Report format. Defaults to `json`. |
| `--output=PATH` | Report file outside the analyzed project. Defaults to stdout. |
| `--debug` | Preserve Composer workspaces and report their paths. |
| `-h`, `--help` | Print command help. |

Supply at least one package target or `--target-php`. `--target=php:8.1` and `--target-php=8.1` are equivalent; if you use both, they must normalize to the same exact PHP version.

The parser accepts only the documented forms. Write `--path=value`, not `--path value`. The `--debug` flag takes no value.

## Framework selection

The CLI activates installed adapters when Composer metadata detects their framework. Use `--framework=laravel` to request Laravel analysis explicitly. An explicit request fails with exit code `2` when the adapter package is not installed.

## Streams and exit codes

Reports go to stdout unless `--output` is set. Diagnostics go to stderr.

| Code | Meaning |
| --- | --- |
| `0` | Help or a completed canonical report. Inspect `resolution.status`. |
| `1` | An internal or operational failure prevented report production. |
| `2` | Invalid command syntax, paths, targets, format, framework, or output destination. |

A solver-blocked upgrade is valid analysis output and returns `0`.

## Examples

PHP-only analysis:

```bash
upgrade-intel analyze --path=/work/app --from-php=7.4 --target-php=8.1
```

Multiple package targets and Markdown output:

```bash
upgrade-intel analyze \
  --path=/work/app \
  --target=laravel/framework:^9.0 \
  --target=laravel/passport:^10.0 \
  --target-php=8.1 \
  --format=markdown \
  --output=/work/reports/app.md
```
