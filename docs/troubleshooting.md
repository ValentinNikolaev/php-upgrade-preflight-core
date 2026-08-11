# Troubleshooting

## `Unable to find Composer autoload.php`

Run the executable installed by Composer, normally `vendor/bin/upgrade-intel` on Unix or `vendor\\bin\\upgrade-intel.bat` on Windows. If you are using a source checkout, run `composer install` at the repository root first.

## Composer is missing

The analyzer launches `composer` from `PATH`. Confirm that the same shell can run:

```bash
composer --version
```

External analysis needs Composer in the tools environment, not in the target project.

## The target project runs PHP 7.4

Install the CLI in a separate directory running PHP 8.0 or later. Pass `--from-php=7.4` and the target runtime through `--target-php`. Do not install the analyzer into the PHP 7.4 application.

Version 0.2 packages also require PHP 8.0 or later. Install `php-upgrade-preflight/cli:^0.2` and the required adapter in the separate tools directory; the target application itself does not need to boot.

## Laravel rules are unavailable

Install `php-upgrade-preflight/laravel` beside the CLI. An explicit `--framework=laravel` request returns exit code `2` when the adapter is absent.

For a v0.2 tools installation, require `php-upgrade-preflight/laravel:^0.2` and run `composer show php-upgrade-preflight/laravel` from that tools directory. The adapter is project-locally installable on Laravel 8–13. Analyze Laravel 7 externally because the v0.2 adapter's host constraints begin at Laravel 8.

## Laravel guidance is partial or unsupported

Read `transition.framework_guidance[].uncertainties` before changing the target. A covered prefix followed by a missing hop is `partially_supported`; guidance deliberately stops at that gap. A missing first hop, ambiguous or unknown source/target major, same-major request, downgrade, or source or target outside Laravel 7–13 is `unsupported`. The shipped Laravel catalog covers 7→8, direct 7→9, and every adjacent hop from 8→9 through 12→13, so an unexpected gap usually means the source or target could not be resolved to one major.

Do not use `resolution.status` to override this result. Composer feasibility and framework guidance coverage are independent.

## The report says extension provenance is partial

This is expected when `config.platform` or `--with-extension` / `--without-extension` models only named extensions. Listed values are deterministic, while unlisted extensions still come from the analyzer runtime. Version 0.2 does not accept a complete extension inventory, so it cannot claim full host independence. Compare `platform.extensions.assumptions`, `completeness`, and `unmodeled_provenance` when two hosts disagree.

## A schema 0.6 consumer rejects a v0.2 report

Version 0.2 writes schema `0.7`. Select the parser through `metadata.schema_version`, update raw-source reads from schema 0.6 `source_impact` to schema 0.7 `source_inventory`, and treat schema 0.7 `source_impact` as grouped actionable findings. Also accept the new required `platform` field and `transition.framework_guidance`. Historical schema 0.6 reports remain valid and are not rewritten.

## The output path is rejected

`--output` must name a file outside the analyzed project, and its parent directory must already exist and be writable. This rule preserves the input tree. Create a sibling report directory and retry.

## Relative path repositories fail

The analyzer rewrites ordinary relative `path` repository URLs against the target project before running Composer. URLs containing environment variables or `~` remain untouched. Use an absolute path or define the required variable in the analyzer process when Composer cannot resolve one of those URLs. Default JSON and Markdown show the resolved local repository root as `[LOCAL_REPOSITORY]`.

## Private packages cannot be downloaded

Composer uses the analyzer process's normal global authentication and environment. Configure scoped credentials in that environment. Captured output redacts repository URLs, authorization values, common tokens, and named credential fields, but you should still use short-lived, read-only credentials and inspect a report before sharing it.

If a credential form is not redacted, stop sharing the report, revoke or rotate the credential, and use the private vulnerability-reporting channel. Include only a synthetic reproduction.

## A scenario times out

Composer scenarios have a five-minute timeout. Check repository availability, authentication prompts, and network access. Composer runs non-interactively. Re-run with `--debug` only when you can safely retain copied manifests for inspection.

## Temporary workspace cleanup fails

In default mode, the report records `cleanup_failure` and only the `[ANALYZER_WORKSPACE]` marker. Re-run with explicit `--debug` only when you need the exact `temp_path` for inspection or manual removal. Debug reports and retained workspaces are non-shareable artifacts, and debug runs preserve workspaces by design. Credentials remain redacted in every mode.

## The command returns `0` for a blocked upgrade

Exit code `0` means the analyzer produced a report. Read `resolution.status` for `feasible`, `feasible_with_changes`, `blocked`, or `unknown` behavior. Exit codes `1` and `2` mean no valid analysis report was produced.

## Windows and Unix reports differ

Default canonical JSON and Markdown use `[PROJECT_ROOT]`, `[REPORT_OUTPUT]`, `[LOCAL_REPOSITORY]`, and `[ANALYZER_WORKSPACE]` instead of host-specific absolute roots. Source file locations remain project-relative. The analyzer still uses native exact project and source paths internally, but report consumers should compare the stable markers and relative locations.
