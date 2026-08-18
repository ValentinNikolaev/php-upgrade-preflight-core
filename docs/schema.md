# JSON schema and compatibility

JSON reports use a versioned consumer contract. Tool releases and schema releases move independently:

```json
{
  "metadata": {
    "schema_version": "0.8",
    "tool": {
      "name": "php-upgrade-preflight",
      "version": "0.3.1"
    }
  }
}
```

Select a parser or validator through `metadata.schema_version`. Do not infer the report shape from `metadata.tool.version`.

## Current contract

The published v0.3 line writes the strict Draft 2020-12 [`upgrade-report-v0.8.schema.json`](../packages/core/resources/schema/upgrade-report-v0.8.schema.json). It rejects unknown properties and adds required staged execution, adjacent-stage attempts, selected-state fingerprints, a lifecycle-preserving blocker registry, nullable target-platform-profile fields, and Composer execution provenance to the schema 0.7 shape. Schema `0.8` became a published package contract with v0.3.0.

`request_summary.composer_execution` records the safe requested policy without an executable path. Top-level `composer_execution` adds the detected Composer version, version-match result, repository and global-state inheritance, timeout policy, disabled side effects, offline request, and the explicit fact that process/OS isolation was not supplied. Restricted repository metadata misses use the operational `repository_metadata_unavailable` scenario outcome.

Historical schemas remain in the same directory for consumers that store older reports:

- [v0.7](../packages/core/resources/schema/upgrade-report-v0.7.schema.json)
- [v0.6](../packages/core/resources/schema/upgrade-report-v0.6.schema.json)
- [v0.5](../packages/core/resources/schema/upgrade-report-v0.5.schema.json)
- [v0.4](../packages/core/resources/schema/upgrade-report-v0.4.schema.json)
- [v0.3](../packages/core/resources/schema/upgrade-report-v0.3.schema.json)
- [v0.2](../packages/core/resources/schema/upgrade-report-v0.2.schema.json)

## Compatibility policy

Patch releases may correct findings, scenario selection, evidence, or wording while retaining their schema version. Consumers must tolerate different array contents that still validate against that schema.

A report-shape change requires a new schema version and a new schema file. Existing schema files remain immutable. The project keeps canonical schema snapshots and six full fixture report snapshots under test.

Markdown has no independent contract. It projects the canonical report for human review and may change its presentation in a patch release.

Composer `stdout_excerpt` and `stderr_excerpt` values are bounded and redacted before they enter the canonical model. Stable markers such as `[REDACTED]`, `[REDACTED_TOKEN]`, and `[REDACTED_URL]` replace sensitive values without changing the schema shape.

Historical reports are not rewritten. A multi-version consumer must retain separate schema `0.6`, `0.7`, and `0.8` paths rather than normalizing by tool-version string.

## Migrating from 0.7 to 0.8

Schema 0.8 adds required top-level `composer_execution` and `staged_resolution` objects, required `request_summary.composer_execution`, plus required nullable `request_summary.target_platform_profile` and `platform.profile` fields. It also adds a required `outcome` to every Composer diagnostic. It does not change the meaning of `resolution.status`, `transition.framework_guidance`, direct-final `transition.package_changes`, or the schema 0.7 source fields. A `null` profile preserves legacy named partial-platform behavior; it does not mean an empty complete profile.

| 0.7 | 0.8 |
| --- | --- |
| No adjacent Composer-stage result | `staged_resolution` records execution state, independent resolution status, provider, stages, blocker registry, stop reason, budgets, and evidence |
| Composer process policy was implicit | `request_summary.composer_execution` records the requested safe policy and top-level `composer_execution` records redacted detected version, inheritance, timeouts, disabled side effects, and network policy |
| Final-target blockers only | `staged_resolution.blocker_registry[]` retains attempt- and stage-scoped lifecycle history |
| No selected intermediate project state | Each stage links predecessor, input, and selected output manifest/lock/platform/execution-policy fingerprints |
| Framework findings are hop-scoped | Executed stages project applicable findings and stable source-impact IDs while explicitly naming `original_project` as the inspected snapshot; full staged findings are de-duplicated in `staged_resolution.source_impact` |
| No versioned target-platform profile | `request_summary.target_platform_profile` records safe input metadata and `platform.profile` records the normalized effective profile or `null` |
| A diagnostic carried only an exit code | Every `diagnostics[]` entry carries a required `outcome` from the same vocabulary as `resolution.scenarios[].outcome`, so a probe timeout, a missing Composer binary, and an ordinary non-zero `composer prohibits` exit are distinguishable |

Scenario and diagnostic outcomes share one vocabulary: `success`, `solver_failure`, `validation_failure`, `composer_missing`, `repository_metadata_unavailable`, `timeout`, `invalid_json`, `lockfile_missing`, `process_failure`, `cleanup_failure`, and `workspace_failure`. A diagnostic reports `success` when the probe itself ran, including when it exits non-zero because the prohibited relation was found — that non-zero exit is the evidence the probe exists to capture. Only unusable execution downgrades the outcome. The Markdown projection renders the same value beside each scenario and each diagnostic.

`invalid_json` covers unusable project Composer input rather than a broken analysis environment. Besides `composer.json` or `composer.lock` that is not valid JSON, it reports a manifest whose `config.platform` declares contradictory duplicate package names, and a manifest whose `config` or `config.platform` is not an object and therefore cannot carry the simulated platform values.

A candidate-state fingerprint identifies the manifest and lock content a stage carried in or out, not the directory the project was analyzed in. Its manifest and lock digests read the same sanitized values the report exposes, so an analyzer-owned exposure marker stands in for every private root and the segments after a marker are digested with `/` separators. The lock digest excludes Composer's derived `content-hash`: that value restates the manifest the analyzer wrote into its own workspace, including the absolute path repositories the workspace needs to resolve, and the manifest is already digested on its own. Two hosts analyzing one project therefore report the same fingerprints, while `scenarios[].candidate_lock.sha256` and `candidate_lock.content_hash` stay exactly what Composer wrote in that workspace and are local to it.

The required `staged_resolution.budgets` object reports the normative hop, attempt, scenario, Composer-process, per-scenario timeout, per-stage timeout, aggregate timeout, memory, JSON-size, and Markdown-size caps. In schema 0.8, `max_composer_processes` is `128` and `stage_timeout_seconds` is `900`; consumers must not infer either value from the scenario or aggregate limits.

For a 0.7/0.8 consumer:

1. Dispatch on `metadata.schema_version`.
2. Keep existing 0.7 direct-resolution and guidance reads unchanged.
3. For 0.8 only, read `staged_resolution.execution_state` before interpreting its independent `status`.
4. Treat `blocker_registry` as an ordered array even when empty or when it contains one entry.
5. Advance through stages only when the stage has a selected attempt and its output fingerprint matches the next stage's input fingerprint.
6. Read profile identity from its canonical SHA-256 digest and safe `php_api` or `file` provenance. A profile path is never part of canonical output.
7. For a non-null profile, use `closed_world` and `effective[]`; do not infer completeness from the legacy `platform.extensions` projection.
8. For each 0.8 stage, read its exact `analysis_php`, `platform`, `composer_execution`, `duration_ms`, and `evidence` together with the candidate-state fingerprints. The stage context digests must equal the corresponding input fingerprint digests.
9. Do not infer an empty staged result or a null profile for schema 0.7; field absence identifies the older contract.
10. Resolve `staged_resolution.stages[].source_impact` IDs through the unique `staged_resolution.source_impact` registry. `stage_ids` records every correlated stage without repeating occurrences or evidence objects.
11. Keep top-level `transition.package_changes` and `source_impact` as direct-final evidence. Stage package changes and staged source-impact references are separate and never overwrite them.
12. Read each plan stage's `stage_id` and stop at the first blocked, unknown, skipped, or missing stage; no later transition has recommendation evidence.

The minimal canonical 0.8 fixture is [`tests/fixtures/contracts/v0.3/minimal-report-v0.8.json`](../tests/fixtures/contracts/v0.3/minimal-report-v0.8.json). A machine-readable dual-consumer example is [`v0.7-to-v0.8-consumer-migration.json`](../tests/fixtures/contracts/v0.3/v0.7-to-v0.8-consumer-migration.json). The complete staged semantics are locked in the [v0.3 contract](v0.3-contract.md).

## Path exposure and report privacy

Default canonical JSON and Markdown replace absolute local roots with stable markers:

| Marker | Meaning |
| --- | --- |
| `[PROJECT_ROOT]` | Analyzed project root. |
| `[REPORT_OUTPUT]` | Report destination root. |
| `[LOCAL_REPOSITORY]` | Local Composer repository root. |
| `[ANALYZER_WORKSPACE]` | Analyzer-owned temporary workspace root. |

The analyzer still uses exact project and source paths internally, while source file locations in reports remain project-relative. Exact `temp_path` values are exposed only when `--debug` is explicit; those debug reports and retained workspaces are non-shareable artifacts. In default mode, cleanup failures expose only `[ANALYZER_WORKSPACE]`. Credential redaction remains active in every mode, including debug.

The redaction boundary covers canonical report values, Composer excerpts, evidence context, exception-derived diagnostics, and CLI or Artisan diagnostics. It does not remove credentials from Composer's environment or global configuration, stop repository access, sanitize copied manifests in retained debug workspaces, or guarantee recognition of future credential formats. Use scoped credentials and review generated artifacts before publication.

## Migrating from 0.6 to 0.7

Dispatch on `metadata.schema_version`; do not infer shape from the tool version. The migration is structural:

| 0.6 | 0.7 |
| --- | --- |
| `source_impact[]` was raw parser inventory | Raw observations move to `source_inventory[]` |
| No actionable source object | `source_impact[]` contains relevance, reason, severity, ownership, affected package, exact occurrences, and evidence |
| PHP values were spread across request/project fields | `platform` records analyzer, current, target, and extension provenance, including extension-model completeness and the source of unmodeled values |
| No framework coverage status | `transition.framework_guidance[]` records support and ordered hops separately from Composer resolution; findings add `applies_to_hops` |

For a dual-version consumer:

1. Dispatch on `metadata.schema_version` before reading any changed field.
2. For schema `0.6`, continue treating `source_impact[]` as raw source usage records.
3. For schema `0.7`, read that same raw record shape from required `source_inventory[]`. Do not copy every inventory item into the new `source_impact`.
4. Read schema `0.7` `source_impact[]` as grouped actionable findings. Each finding has `affected_package`, `ownership`, `relevance`, `reason`, `severity`, one or more exact `occurrences`, and evidence references.
5. Read PHP and extension provenance from the required top-level `platform`; retain `request_summary` and `project_state` for the request and original Composer inputs.
6. Read framework coverage from `transition.framework_guidance[]` and hop scope from `framework_findings[].applies_to_hops`. Never derive either from Composer resolution.

Schema `0.7` source impact is intentionally narrower than the schema `0.6` field with the same name. A raw usage becomes actionable only when it correlates with a removed, upgraded, downgraded, major-jump, or same-version source/distribution-reference package change selected from the successful final-target scenario, an applicable framework rule, or both. `relevance` identifies `package_change`, `framework_rule`, or `package_change_and_framework_rule`. Repeated uses of the same symbol and usage type are grouped while every distinct file and line remains in `occurrences`. Inventory-only observations do not affect source-change planning, risk, or effort.

Ownership comes from supported static Composer autoload metadata without loading target code. `exact` means one best mapping, `ambiguous` means equally specific candidates, and `unknown` means no safe owner was established. An `affected_package` of `null` is deliberate uncertainty, not permission for a consumer to infer an owner. Class-like symbols can use PSR-4 or PSR-0 longest-prefix matching; function and constant ownership needs a same-kind declaration from an available classmap or files mapping.

### Platform provenance

The `platform` object separates modeled target state from analyzer-host state:

| Field | Meaning |
| --- | --- |
| `analyzer` | PHP executing the analyzer; provenance is always `runtime`. |
| `current_php` | `--from-php`, otherwise the original `config.platform.php`, otherwise `null`/`unknown`. |
| `target_php` | Requested target PHP, otherwise exact profile PHP, otherwise `null`/`unknown`. |
| `extensions.assumptions[]` | Ordered effective present/absent assumptions from `request`, `profile`, `composer_config`, or `closed_world` precedence. |
| `extensions.completeness` | `none`, `partial`, or `complete` coverage. |
| `extensions.unmodeled_provenance` | Where values not covered by assumptions came from; `null` for a complete profile. |
| `profile` | Nullable versioned profile metadata, supported classes, closed-world flag, toolchain-bound package names, and sorted effective decisions. |

Without a target-platform profile, the legacy command-line and Artisan inputs model named extensions only. With no assumptions, extension provenance is `analyzer_runtime` and completeness is `none`. With manifest, request, or partial-profile assumptions, completeness is `partial` and unlisted extensions still use `unmodeled_provenance: analyzer_runtime`. A complete profile makes the extension projection `complete`, models unlisted safely simulated extensions as absent with `closed_world` provenance, and sets `unmodeled_provenance` to `null`.

Extension assumptions are ordered by Composer extension name. `provenance: request` identifies CLI or Artisan `--with-extension` / `--without-extension` input; `profile`, `composer_config`, and `closed_world` identify the other effective sources. Request input overrides the same profile or manifest entry. Matching request duplicates collapse deterministically; contradictory values are rejected. A present assumption with a `null` version models presence only and adds a version-compatibility uncertainty. Solver conflicts caused by its sentinel use the non-blocking `extension-version-unknown` blocker classification; exact modeled version conflicts use the blocking `extension-version-incompatible` classification. Reports never promote unlisted analyzer-host extension state to reproducible target evidence.

### Target-platform profile projection

`request_summary.target_platform_profile` contains only `schema_version`, `completeness`, canonical `sha256`, and safe `provenance`. `platform.profile` repeats those fields and adds `supported_classes`, `closed_world`, the fixed sorted `toolchain_bound` classification (`composer`, `composer-plugin-api`, and `composer-runtime-api`), and sorted `effective` decisions. Each decision has exactly `name`, `class`, `state`, nullable `version`, source `provenance`, and `simulation`:

```json
{
  "schema_version": "1.0",
  "completeness": "complete",
  "sha256": "8dbb9d1a3f59085813bb56e2b339ede7893ed508933fed87c784e83cfcd218b5",
  "provenance": "file",
  "supported_classes": ["php", "extension", "library", "php_subtype", "composer_platform"],
  "closed_world": true,
  "toolchain_bound": ["composer", "composer-plugin-api", "composer-runtime-api"],
  "effective": [
    {
      "name": "composer-plugin-api",
      "class": "composer_platform",
      "state": "present",
      "version": "2.6.0",
      "provenance": "profile",
      "simulation": "toolchain_bound"
    },
    {
      "name": "ext-curl",
      "class": "extension",
      "state": "absent",
      "version": null,
      "provenance": "closed_world",
      "simulation": "composer_config"
    },
    {
      "name": "php",
      "class": "php",
      "state": "present",
      "version": "8.3.0",
      "provenance": "profile",
      "simulation": "composer_config"
    }
  ]
}
```

Profile provenance is `php_api` or `file`; exact local paths stay behind the path-exposure boundary. Effective provenance is `request`, `composer_config`, `profile`, or `closed_world`. Simulation is `composer_config` when a decision is applied to an analyzer-owned manifest and `toolchain_bound` when the value is tied to the Composer executable and cannot be simulated safely. A present extension with `version: null` preserves a request's presence-only uncertainty; the internal Composer sentinel is never reported as an exact target version. Complete implies `closed_world: true`; partial implies `false` and remains analyzer-host dependent. Composer 2.2 or newer is required before a complete profile can be applied.

Do not map `transition.framework_guidance[].status` onto `resolution.status`. `supported`, `partially_supported`, and `unsupported` describe rule-pack coverage only. Composer feasibility and framework guidance coverage are independent; [the v0.2 contract](v0.2-contract.md) defines their composition.

## Validate a report

Use any Draft 2020-12 validator. The development suite validates reports with `opis/json-schema`:

```bash
composer test:core -- --filter UpgradeReportSchemaTest
```

Every finding references an ID in the top-level `evidence` collection. Consumers should preserve those references when transforming reports.
