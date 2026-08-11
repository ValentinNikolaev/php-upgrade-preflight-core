# Artisan reference

Installing `php-upgrade-preflight/laravel` registers the service provider through Laravel package discovery. The provider adds:

```text
php artisan upgrade:analyze [options]
```

The command defaults `--path` to the Laravel application's base path and always enables the Laravel adapter. Its targets, source paths, formats, output validation, debug behavior, and exit policy match the standalone CLI.

Composer-metadata discovery generalizes adapter registration for the standalone CLI only. Laravel package discovery still registers this Artisan command through the service provider. Both entry points use the same Laravel integration and analyzer pipeline, so Laravel detection, rules, default source paths, package-family classification, report content, and exit semantics remain in parity.

## Options

| Option | Meaning |
| --- | --- |
| `--path=PATH` | Project directory. Defaults to the current Laravel application. |
| `--target=PACKAGE:CONSTRAINT` | Requested package constraint. Repeat as needed. |
| `--target-php=VERSION` | Exact target PHP platform version. |
| `--from-php=VERSION` | Known current PHP version. |
| `--with-extension=EXT[:VERSION]` | Assume `ext-name` is present, optionally at an exact version. Repeat as needed. |
| `--without-extension=EXT` | Assume `ext-name` is absent. Repeat as needed. |
| `--source=PATH` | File or directory inside the project. Repeat as needed. |
| `--format=json\|markdown` | Report format. Defaults to `json`. |
| `--output=PATH` | Report file outside the analyzed project. |
| `--debug` | Preserve temporary workspaces and expose exact `temp_path` values; output is non-shareable. |

Extension options have the same validation and provenance semantics as the standalone CLI. A name may be supplied only once across both options. Exact versions and absences affect only temporary Composer workspaces. Absence simulation requires Composer 2.2 or newer and stops affected scenarios before workspace creation on an older detected version. Presence without a version remains visible as uncertainty; related constraint failures are non-blocking `extension-version-unknown` advisories. Every unlisted host extension also remains explicit uncertainty in the canonical report.

Request assumptions override same-name entries from the application's original `config.platform`. In v0.2 they still form only a partial extension model: `platform.extensions` records `completeness: partial` and identifies the analyzer runtime as the source for every unlisted extension. No Artisan option claims a complete, host-independent extension inventory.

By default, canonical JSON and Markdown replace absolute local roots with `[PROJECT_ROOT]`, `[REPORT_OUTPUT]`, `[LOCAL_REPOSITORY]`, and `[ANALYZER_WORKSPACE]`. The analyzer still uses exact project and source paths internally, while reported source files remain project-relative. A cleanup failure reports only `[ANALYZER_WORKSPACE]` unless `--debug` was supplied. Explicit debug mode preserves workspaces and exposes exact `temp_path` values, so debug reports and retained workspaces are non-shareable. Credential redaction remains active in every mode.

Redaction covers report fields, bounded Composer output, and diagnostics, but it does not prevent Composer from reading credentials or contacting declared repositories. A retained debug workspace contains copied manifests. Use scoped credentials and review all output before sharing it.

Example:

```bash
php artisan upgrade:analyze \
  --from-php=8.1 \
  --target=laravel/framework:^11.0 \
  --target-php=8.2 \
  --format=markdown \
  --output=/work/reports/laravel-11.md
```

Artisan must boot before it can run the command. The v0.2 adapter is host-installable with Laravel 8–13; use the external CLI for Laravel 7, when the current PHP interpreter cannot boot the application, when service providers are broken, or when installing the adapter would disturb the dependency graph.

Laravel guidance covers 7→8, the retained direct 7→9 path, and adjacent 8→9 through 12→13 packs. Gapless adjacent packs compose multi-major guidance. Same-major requests, downgrades, ambiguous or unknown majors, catalog boundaries, and requests whose first required hop is missing are unsupported. A covered prefix before a later gap is `partially_supported`, and guidance stops there.

Schema `0.7` records raw AST observations in `source_inventory`; `source_impact` contains only observations correlated with a selected package change, an applicable Laravel rule, or both. Read `resolution.status` separately from `transition.framework_guidance[].status`: Composer feasibility and rule-pack coverage do not imply one another.

The command returns `0` after writing any valid report, including a blocked result. It returns `2` for invalid invocation and `1` when it cannot produce a report.
