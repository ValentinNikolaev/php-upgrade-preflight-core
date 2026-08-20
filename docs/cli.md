# CLI reference

The standalone executable provides a non-interactive analysis command and an explicit interactive wizard:

```text
upgrade-intel analyze --target=package:constraint [options]
upgrade-intel wizard
```

`analyze` remains the stable interface for scripts and CI. It never prompts and accepts the documented `--name=value` options. `wizard` requires interactive stdin and stderr, gathers the same request values through prompts, prints a shell-copyable equivalent `analyze` command, and delegates to the same analyzer.

## Interactive wizard

Run the line-oriented workflow when constructing the flags manually is inconvenient:

```bash
upgrade-intel wizard
```

The wizard:

- validates a project directory containing readable Composer metadata;
- shows analyzer-runtime PHP separately from the current project PHP inferred from exact `config.platform.php` data;
- never silently chooses the analyzer runtime as an upgrade target;
- requires an explicit Composer execution policy with no security-sensitive default;
- supports PHP-only, package-only, and combined target selection;
- lists root `require` and `require-dev` packages and validates every selected target constraint;
- reviews the complete plan and equivalent command before analysis starts;
- defaults to Markdown in the terminal, supports explicit JSON, and can save an identical optional copy outside the project.

Package metadata lookup is selected once before package targets are entered:

1. `composer.json` only is the default and starts no Composer process;
2. local Composer cache requests offline lookup and treats a cache miss as `unverified`, not `not_found`;
3. configured project repositories may use network access and credentials inherited by compatible Composer execution.

The lookup distinguishes an invalid target, a package found with matching releases, a package found with no release matching the requested constraint, an explicit repository `not_found` result, and an operationally `unverified` result. An unavailable network, cache, authentication context, or malformed metadata never proves that a package does not exist. Repository lookup uses non-interactive Composer metadata commands with plugins and scripts disabled and the diagnostic timeout applied.

The metadata lookup source does not silently determine how the analysis itself runs. The wizard separately requires `restricted` or `compatible` Composer execution, offers no default for that security-sensitive choice, explains whether network access and inherited credentials may be used, and includes the selected `--composer-mode` in both the confirmation plan and equivalent command.

Prompts and other human interaction go to stderr. The wizard always keeps the selected canonical report on stdout and uses `--save-report` when an additional file copy is requested. EOF stops before analysis with code `2`; entering `cancel`, `quit`, or `q` stops before analysis with code `130`. When stdin or stderr is not a terminal, the wizard refuses to prompt and directs the caller to `analyze`.

## Options

| Option | Meaning |
| --- | --- |
| `--path=PATH` | Project directory. Defaults to the current directory. |
| `--target=PACKAGE:CONSTRAINT` | Requested package constraint. Repeat for multiple packages. |
| `--target-php=VERSION` | Exact target PHP platform version. |
| `--target-platform-profile=PATH` | JSON target-platform profile. May be supplied once. |
| `--from-php=VERSION` | Known current PHP version used for staging analysis. |
| `--with-extension=EXT[:VERSION]` | Assume `ext-name` is present, optionally at an exact version. Repeat as needed. |
| `--without-extension=EXT` | Assume `ext-name` is absent. Repeat as needed. |
| `--source=PATH` | File or directory to scan inside the project. Repeat as needed. |
| `--framework=NAME` | Installed framework adapter to enable. Repeat as needed. |
| `--format=json\|markdown` | Report format. Defaults to `json`. |
| `--output=PATH` | Write the report only to a file outside the analyzed project. |
| `--save-report=PATH` | Keep the report on stdout and save an identical file copy outside the project. |
| `--composer-mode=compatible\|restricted` | Composer execution policy. Defaults to `compatible`. |
| `--composer-executable=PATH` | Composer command or executable path. Defaults to `composer`; the exact value is not reported. |
| `--composer-version=CONSTRAINT` | Expected Composer version constraint. Defaults to `>=2.0.0 <3.0.0`. |
| `--composer-timeout=SECONDS` | Scenario timeout, 1–3600 seconds. Defaults to 300. |
| `--composer-diagnostic-timeout=SECONDS` | Diagnostic timeout, 1–900 seconds. Defaults to 60. |
| `--debug` | Preserve Composer workspaces and expose exact `temp_path` values; output is non-shareable. |
| `-h`, `--help` | Print command help. |

Supply at least one package target, `--target-php`, or `--target-platform-profile`. `--target=php:8.1` and `--target-php=8.1` are equivalent; if you use both, they must normalize to the same exact PHP version. A profile with exact PHP may supply the target by itself.

The parser accepts only the documented forms. Write `--path=value`, not `--path value`. The `--debug` flag takes no value.

Compatible Composer execution may inherit global configuration, credentials, proxies, caches, and network access. Restricted execution uses fresh analyzer-owned Composer configuration and cache state, scrubs the controlled credential/proxy environment, and requests Composer's best-effort offline behavior. It is not an OS network sandbox. See [Composer execution policy](composer-execution.md) for the threat model and residual boundaries.

By default, canonical JSON and Markdown replace absolute local roots with `[PROJECT_ROOT]`, `[REPORT_OUTPUT]`, `[LOCAL_REPOSITORY]`, and `[ANALYZER_WORKSPACE]`. The analyzer still uses exact project and source paths internally, while reported source files remain project-relative. A cleanup failure reports only `[ANALYZER_WORKSPACE]` unless `--debug` was supplied. Explicit debug mode preserves workspaces and exposes exact `temp_path` values, so debug reports and retained workspaces are non-shareable. Credential redaction remains active in every mode.

Redaction applies to report fields, bounded Composer output, and diagnostics. It does not prevent Composer from reading host credentials or contacting repositories, and it does not sanitize copied manifests in a retained debug workspace. Treat redaction as a last publication boundary and inspect artifacts before sharing them.

Extension names use Composer's `ext-name` form. Matching repeated assumptions collapse deterministically; different versions or presence/absence for the same extension are contradictory and rejected. Exact versions and absences are written only to analyzer-owned temporary Composer manifests. Absence simulation requires Composer 2.2 or newer; older detected versions stop the affected target scenarios before a workspace is created and leave resolution unknown. Presence without a version uses a conservative temporary presence sentinel. A constraint failure involving that sentinel is reported as the non-blocking `extension-version-unknown` advisory, not as reproducible evidence that the extension is missing. Unlisted extensions still come from the analyzer runtime and are labeled as host-dependent in `platform.extensions` and `uncertainties`.

`--target-platform-profile` accepts a UTF-8 JSON object with schema version `1.0`, `partial` or `complete` completeness, and a `packages` object. Package values are exact versions or `false` for absence:

```json
{
  "schema_version": "1.0",
  "completeness": "complete",
  "packages": {
    "php": "8.3.0",
    "ext-curl": "8.3.0",
    "ext-xdebug": false,
    "lib-curl": "8.6.0",
    "php-64bit": "8.3.0",
    "composer-plugin-api": "2.6.0"
  }
}
```

The supported classes are `php`, `ext-*`, `lib-*`, PHP subtypes such as `php-64bit`, and Composer platform packages. JSON object keys must be unique. The profile is parsed and request/profile conflicts are rejected before Composer runs. Precedence is request option, then profile, then the original `config.platform`: an equal request/profile value is accepted and reported with request provenance; matching same-layer duplicates collapse; contradictory values are rejected; and lower-priority project configuration is overridden, preserving the existing request-over-project behavior. Complete profiles are mutually exclusive with presence-only extension assumptions because those assumptions cannot satisfy an exact closed-world claim.

`platform.profile` records the profile schema, `partial` or `complete` completeness, canonical SHA-256 digest, safe provenance (`file`, never the input path), modeled classes, closed-world flag, toolchain-bound package names, and every sorted effective decision. A complete profile treats every unlisted package in a supported safely simulated class as absent. A partial profile retains analyzer-host values for unlisted packages and is visibly host-dependent. Composer packages tied to the executable itself, including `composer`, `composer-plugin-api`, and `composer-runtime-api`, are reported as `toolchain_bound`; the profile records them but does not claim that `config.platform` safely simulates them.

Composer 2.2 or newer is required for complete closed-world profiles. With Composer 2.0 or 2.1, analysis stops before workspace creation with an operationally unknown result; the analyzer never downgrades a complete request to partial. Profile completeness covers only the declared platform-package classes. Repository contents, network access, credentials, and the Composer executable remain separate inputs and can still change resolution. `composer_execution` records those execution-policy dimensions independently.

### Build and validate a target-platform profile

The analyzer does not guess or generate a complete profile from its own host. Inventory the actual target environment first—for example, use `composer show --platform`, the target image or host build manifest, and the deployment team's extension/library configuration—then construct the schema `1.0` JSON explicitly. Treat `composer show --platform` as inventory input, not as a file that can be passed directly: normalize names to Composer platform-package names, record exact versions where the profile claims presence, and use `false` only for verified absence.

Use `partial` when the inventory is intentionally incomplete. Use `complete` only when every supported safely simulated class has been considered and every unlisted value should be modeled absent. Values tied to the selected Composer executable (`composer`, `composer-plugin-api`, and `composer-runtime-api`) remain `toolchain_bound` even when listed.

Validation occurs before Composer creates a scenario workspace. The command rejects malformed JSON, unknown schema versions or completeness values, unsupported package names, non-exact versions, contradictory entries, and request/profile conflicts. A successful report repeats the canonical profile SHA-256 and normalized decisions under `platform.profile`; compare those values with the intended deployment inventory before relying on the result.

## Framework selection

The CLI discovers installed adapters from their `extra.php-upgrade-preflight.framework-adapters` Composer metadata. Without `--framework`, each discovered adapter performs automatic target detection. Laravel continues to detect `laravel/framework` or `illuminate/*` requirements and lock entries. Use `--framework=laravel` to request Laravel analysis explicitly and bypass detection.

Explicit names are case-insensitive. An explicit request fails with exit code `2` when no installed adapter has that name. An installed package whose adapter metadata cannot be read is skipped with a diagnostic on stderr, so one unrelated dependency cannot end the run; adapter name or class collisions still fail the analysis rather than selecting a winner. The complete registration contract and deterministic ordering rules are documented in [Framework adapters](adapters.md).

The v0.3 Laravel catalog supports 7→8, the retained direct 7→9 path, and adjacent 8→9 through 12→13 packs. Gapless adjacent packs compose multi-major guidance; ambiguous or unknown majors, same-major requests, downgrades, catalog boundaries, and a missing first hop are unsupported. A covered prefix followed by a missing hop is `partially_supported`, and findings never cross that gap. These guidance statuses do not change `resolution.status`, which describes only final-target Composer scenarios.

## Report interpretation

Schema `0.8` preserves the schema 0.7 separation of raw `source_inventory` and actionable direct `source_impact`, then adds `staged_resolution`. Each executed stage records attempts, the selected candidate-state chain, stage package changes, blocker lifecycle, and findings projected from the labeled original source snapshot.

Read `resolution.status`, `transition.framework_guidance[].status`, and `staged_resolution.status` independently. Check `staged_resolution.execution_state` before interpreting staged status; a skipped unknown is not a Composer blocker. See [JSON schema and compatibility](schema.md) for the 0.7→0.8 migration.

## Streams and exit codes

Reports go to stdout unless legacy file-only `--output` is set. `--save-report` preserves stdout and adds an identical file copy; it cannot be combined with `--output`. Save diagnostics go to stderr.

For a terminal-attached stderr, the default analyzer also emits durable phase and Composer-scenario status lines. Solver conflicts are labeled `blocked`, Composer validation failures `invalid`, timeouts `timed-out`, unavailable repository metadata `unverified`, and other operational failures `failed`. Redirected diagnostics remain free of cursor control and progress output, and report stdout is unchanged.

| Code | Meaning |
| --- | --- |
| `0` | Help or a completed canonical report. Inspect the direct and staged status fields. |
| `1` | An internal or operational failure prevented report production. |
| `2` | Invalid command syntax, paths, targets, format, framework, or output destination. |
| `130` | The interactive wizard was cancelled before analysis. |

A solver-blocked upgrade is valid analysis output and returns `0`.

## Examples

PHP-only analysis:

```bash
upgrade-intel analyze --path=/work/app --from-php=7.4 --target-php=8.1
```

Target extension assumptions:

```bash
upgrade-intel analyze \
  --path=/work/app \
  --target-php=8.2 \
  --with-extension=ext-intl:72.1 \
  --with-extension=ext-json \
  --without-extension=ext-xdebug
```

Complete target-platform profile:

```bash
upgrade-intel analyze \
  --path=/work/app \
  --target=laravel/framework:^12.0 \
  --target-platform-profile=/work/profiles/php-8.3-production.json
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
