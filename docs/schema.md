# JSON schema and compatibility

JSON reports use a versioned consumer contract. Tool releases and schema releases move independently:

```json
{
  "metadata": {
    "schema_version": "0.7",
    "tool": {
      "name": "php-upgrade-preflight",
      "version": "0.2.1"
    }
  }
}
```

Select a parser or validator through `metadata.schema_version`. Do not infer the report shape from `metadata.tool.version`.

## Current contract

PHP Upgrade Preflight v0.2.1 writes the strict Draft 2020-12 [`upgrade-report-v0.7.schema.json`](../packages/core/resources/schema/upgrade-report-v0.7.schema.json). It rejects unknown properties and defines scenario outcomes, structured blockers, platform provenance, package changes, framework-transition guidance, source inventory, actionable source impact, risk, effort, and uncertainties.

Historical schemas remain in the same directory for consumers that store older reports:

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

Historical reports are not rewritten. A consumer that accepts both v0.1 and v0.2 output must keep a schema `0.6` path and a schema `0.7` path rather than normalizing by tool-version string.

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
| `target_php` | Requested target PHP, otherwise `null`/`unknown`. |
| `extensions.assumptions[]` | Ordered present/absent assumptions from `composer_config` or `request`; request input overrides the same configured name. |
| `extensions.completeness` | `none`, `partial`, or schema-reserved `complete` coverage. |
| `extensions.unmodeled_provenance` | Where values not covered by assumptions came from. |

Version 0.2 command-line and Artisan inputs can model named extensions only. With no assumptions, extension provenance is `analyzer_runtime` and completeness is `none`. With any manifest or request assumptions, provenance is `mixed`, completeness is `partial`, and unlisted extensions still use `unmodeled_provenance: analyzer_runtime`. The schema reserves `complete`, but v0.2 has no option that accepts a complete extension inventory; do not reinterpret partial input as host-independent.

Extension assumptions are ordered by Composer extension name. `provenance: request` identifies CLI or Artisan `--with-extension` / `--without-extension` input; `provenance: composer_config` identifies the analyzed manifest's original `config.platform` entry. Request input overrides the same manifest entry, while duplicate request values are rejected. A present assumption with a `null` version models presence only and adds a version-compatibility uncertainty. Solver conflicts caused by its sentinel use the non-blocking `extension-version-unknown` blocker classification; exact modeled version conflicts use the blocking `extension-version-incompatible` classification. Reports never promote unlisted analyzer-host extension state to reproducible target evidence.

Do not map `transition.framework_guidance[].status` onto `resolution.status`. `supported`, `partially_supported`, and `unsupported` describe rule-pack coverage only. Composer feasibility and framework guidance coverage are independent; [the v0.2 contract](v0.2-contract.md) defines their composition.

## Validate a report

Use any Draft 2020-12 validator. The development suite validates reports with `opis/json-schema`:

```bash
composer test:core -- --filter UpgradeReportSchemaTest
```

Every finding references an ID in the top-level `evidence` collection. Consumers should preserve those references when transforming reports.
