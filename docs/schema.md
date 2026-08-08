# JSON schema and compatibility

JSON reports use a versioned consumer contract. Tool releases and schema releases move independently:

```json
{
  "metadata": {
    "schema_version": "0.6",
    "tool": {
      "name": "php-upgrade-preflight",
      "version": "0.1.0"
    }
  }
}
```

Select a parser or validator through `metadata.schema_version`. Do not infer the report shape from `metadata.tool.version`.

## Current contract

The current strict Draft 2020-12 schema is [`upgrade-report-v0.6.schema.json`](../packages/core/resources/schema/upgrade-report-v0.6.schema.json). It rejects unknown properties and defines scenario outcomes, structured blockers, evidence references, transition data, guidance, risk, effort, and uncertainties.

Historical schemas remain in the same directory for consumers that store older reports:

- [v0.5](../packages/core/resources/schema/upgrade-report-v0.5.schema.json)
- [v0.4](../packages/core/resources/schema/upgrade-report-v0.4.schema.json)
- [v0.3](../packages/core/resources/schema/upgrade-report-v0.3.schema.json)
- [v0.2](../packages/core/resources/schema/upgrade-report-v0.2.schema.json)

## Compatibility policy

Patch releases may correct findings, scenario selection, evidence, or wording while retaining schema `0.6`. Consumers must tolerate different array contents that still validate against the schema.

A report-shape change requires a new schema version and a new schema file. Existing schema files remain immutable. The project keeps canonical schema snapshots and six full fixture report snapshots under test.

Markdown has no independent contract. It projects the canonical report for human review and may change its presentation in a patch release.

Composer `stdout_excerpt` and `stderr_excerpt` values are bounded and redacted before they enter the canonical model. Stable markers such as `[REDACTED]`, `[REDACTED_TOKEN]`, and `[REDACTED_URL]` replace sensitive values without changing the schema shape.

## Validate a report

Use any Draft 2020-12 validator. The development suite validates reports with `opis/json-schema`:

```bash
composer test:core -- --filter UpgradeReportSchemaTest
```

Every finding references an ID in the top-level `evidence` collection. Consumers should preserve those references when transforming reports.
