# Framework adapters

The standalone CLI discovers framework adapters from Composer metadata. An adapter package does not require a CLI source change or a central registry entry.

## Package metadata

An installed adapter package declares one or more integration classes under `extra.php-upgrade-preflight.framework-adapters`:

```json
{
  "name": "vendor/example-adapter",
  "require": {
    "php-upgrade-preflight/core": "^0.3"
  },
  "autoload": {
    "psr-4": {
      "Vendor\\ExampleAdapter\\": "src/"
    }
  },
  "extra": {
    "php-upgrade-preflight": {
      "framework-adapters": [
        "Vendor\\ExampleAdapter\\ExampleFrameworkIntegration"
      ]
    }
  }
}
```

`framework-adapters` must be a nonempty JSON list of nonempty, fully qualified class-name strings. Every advertised class must be autoloadable, instantiable without constructor arguments, and implement `PhpUpgradePreflight\Core\Framework\FrameworkIntegration`. Its `name()` must return a nonempty adapter name.

The required interface supplies framework detection, compatibility rules, and default source paths. An integration may additionally implement `FrameworkTransitionProvider` to contribute transition guidance, `PackageFamilyClassifier` to classify changed packages into adapter-defined families, and `SourceUsageVisitorProvider` to contribute framework-shaped source inspection.

Install the adapter in the same Composer project as `php-upgrade-preflight/cli`. Composer then supplies both its metadata and autoloader to `upgrade-intel`:

```bash
composer require php-upgrade-preflight/cli vendor/example-adapter
vendor/bin/upgrade-intel analyze --path=/work/app --target-php=8.2
```

## Discovery and activation

Discovery considers packages known to the running Composer installation. Packages that are not installed, or that do not declare the metadata key, do not register an adapter. Package names are processed in lexical order. The resulting integrations are ordered case-insensitively by adapter name, with the class name as the deterministic tie-breaker. Metadata declaration order therefore does not control cross-adapter execution; within one integration, compatibility rules retain the order returned by `rules()`.

With no `--framework` option, every discovered integration may inspect the target project and only integrations whose `detect()` result is positive become active. Explicit `--framework=NAME` selection is case-insensitive, activates only the requested installed adapters, and bypasses their automatic detection. Repeat the option to select multiple adapters.

Laravel keeps the same automatic behavior: the Laravel adapter detects `laravel/framework` or `illuminate/*` in the target project's root requirements or lock data. Its default source paths, rules, transition guidance, and package-family classification are unchanged by metadata-based registration.

## Invalid registrations and collisions

Discovery and registration fail at different granularities. A package whose adapter manifest cannot be read is skipped; a class the CLI was told to load is not.

### An unreadable manifest skips one package

A package's manifest is rejected when its `composer.json` cannot be read or parsed, when `extra.php-upgrade-preflight` is not an object, when `framework-adapters` is not a nonempty JSON list, or when an entry is not a nonempty class-name string without surrounding whitespace.

Discovery reads every installed package, so one broken dependency must not end the analysis of a project the user does control. The rejected package contributes no adapters, every other adapter still registers, `upgrade-intel` writes a diagnostic naming that package and the rejection to stderr, and the analysis still produces a report under the ordinary exit policy. A package with no `composer.json`, no `php-upgrade-preflight` extra key, or no `framework-adapters` entry advertises nothing and is not an error.

### A defective advertised class fails the run

Registration of an accepted manifest's classes stays fail-fast. The CLI does not choose a winner for an ambiguous or broken installation:

- an advertised class that cannot be autoloaded;
- a class that is not instantiable, or whose constructor requires arguments;
- a class that does not implement `FrameworkIntegration`;
- a blank adapter name, or a name with surrounding whitespace;
- the same integration class registered twice, including a case-only variant;
- two classes returning the same adapter name, including case-only variants;
- one Composer package registered with conflicting install paths.

Each is an installation or configuration defect: the CLI writes a diagnostic and returns exit code `1` because it cannot safely construct the analyzer.

### An unavailable explicit selection is an invalid invocation

A package that is not installed is simply absent from discovery. When `--framework` names an adapter no installed package provides, the CLI writes a diagnostic and returns exit code `2`. If discovery skipped any package, that diagnostic also names every skipped package and its rejection, because a skipped manifest never advertises its adapter names and the registry cannot prove which package owned the request. Remove the option, repair the named manifest, or install the adapter package in the CLI's Composer project.

## Compatibility rules and evidence

Every rule returned by `rules()` implements `CompatibilityRule`: one evaluation returns at most one `CompatibilityFinding`, or `null` when the rule has nothing to report. A `HopAwareCompatibilityRule` is evaluated once per supported hop instead of once per analysis, but only when its framework also contributed transition guidance; without guidance it falls back to the plain evaluation.

Two canonical scales are validated when the model is constructed, so an unsupported value is a rejected finding rather than an invented report field. Both accept exactly `low`, `medium`, and `high`:

- severity grades a `CompatibilityFinding`;
- confidence grades evidence recorded through `EvidenceLedger::add()` and `addOnce()`, and defaults to `high`.

Core validates the same two scales for the fields it derives itself — source-impact severity, risk level, and effort confidence — so an adapter cannot widen them indirectly. Values such as `critical`, `info`, `none`, or numeric grades are rejected.

Findings are deduplicated by framework, severity, and summary, with their evidence references and hop references merged. Two adjacent rule packs that reach the same conclusion should therefore emit the same summary; a differing summary produces a second finding.

### Contained rule failures

Adapter rules are third-party input. A rule that throws — including one that builds a finding with an unsupported severity — degrades one report dimension instead of ending the analysis. The analyzer skips that rule, records `E2` evidence naming the adapter and the failing rule class, appends an uncertainty citing that evidence together with any evidence the rule registered before failing, and still produces every remaining rule, integration, and report section. The evidence `reason` distinguishes two cases:

- `rule_failure` — one rule threw while being evaluated; the integration's remaining rules still run.
- `rule_set_failure` — `rules()` itself, or the generator behind it, threw; that integration contributes no further rules.

Containment is not a supported mode of operation. The report states that the finding is missing, so anything that depended on it loses its signal. Cover both paths in the adapter's own conformance fixtures.

## CLI and Artisan

Composer metadata generalizes standalone CLI registration; it does not replace Laravel package discovery. Installing `php-upgrade-preflight/laravel` still registers `upgrade:analyze` through its Laravel service provider, and that command still enables the Laravel integration directly. CLI and Artisan use the same analyzer pipeline, Laravel integration, request semantics, source-path defaults, report writers, and exit policy. The entry-point parity suite verifies equivalent canonical reports.

## Regression fixture

The repository's test-only `php-upgrade-preflight/test-adapter` package is deliberately outside CLI source. Its Composer metadata is the only production registration path. The `third-party-adapter` fixture proves automatic package detection, its `modules` default source path, a compatibility rule, `test-vendor/*` package-family classification, and a deterministic staged plan in a complete CLI analysis.

The separate `php-upgrade-preflight/legacy-test-adapter` package keeps an old-style implementation that uses only the required v0.2 interfaces. Its package constraint explicitly permits Core `^0.3`; its fixture proves that detection and guidance still work while staged resolution is reported as unavailable. Neither test package is part of the three-package published v0.3.3 package set.

## Optional v0.3 staged targets

An adapter may implement `FrameworkStageTargetProvider` in addition to the unchanged required `FrameworkIntegration` contract. `FrameworkTransitionProvider` remains optional and supplies guidance independently from staged Composer feasibility. A provider has one method:

```php
public function planStages(
    ProjectState $project,
    UpgradeRequest $request,
    EvidenceLedger $evidence
): FrameworkStagePlan;
```

For an available plan, return one `FrameworkStageTarget` per ascending adjacent framework hop. Each stage must contain:

- a stable lowercase ID that is unchanged for the same framework hop;
- provider and framework identities equal to `FrameworkIntegration::name()`;
- exact, adapter-selected Composer package constraints in canonical package-name order;
- one exact analysis PHP version, also present as the stage's Composer PHP target;
- zero or more package-sorted root-remediation candidates, each with its own evidence;
- ledger-backed evidence for the package targets and PHP decision.

Return an empty plan with a canonical unavailable reason when there is no unambiguous, fully evidenced chain. Do not manufacture a stage from a broad target, a guidance gap, or a minimum PHP constraint. Plans must be contiguous and ordered from the detected source major toward the requested target. Duplicate stage IDs, duplicate remediation packages, conflicting package targets, undeclared remediation evidence, provider or framework mismatches, missing evidence, provider failures, and non-contiguous output are invalid. The analyzer records deterministic invalid-provider evidence and does not run Composer for that plan.

Only one active adapter may provide stage targets in v0.3. If several detected or explicitly selected adapters implement the provider, ordinary rules and guidance still run, but staged solving is skipped with the providers listed in lexical order. Adapters must not attempt to win this collision through metadata order.

### Platform and PHP evidence

The analysis PHP value is a Composer simulation input, not a deployment recommendation. It must be an exact value supported by request evidence, such as exact current PHP or exact target-profile PHP, and it must satisfy the adapter's documented requirement for that hop. Record both the value and its provenance in evidence. A minimum such as `^8.2` is a compatibility boundary, not evidence that `8.2.0` is the application's intended platform, so a provider must return `analysis_php_unavailable` when no safe exact value exists.

Platform-profile completeness remains a Core request/report property. An adapter may use effective profile decisions when selecting a stage, but must not claim that host-derived partial inputs are closed-world evidence or that toolchain-bound Composer platform values were simulated. Never add host values that contradict the request profile.

### Guidance and source scope

Detection, direct final-target Composer resolution, transition guidance, and staged resolution are independent report dimensions. A stage provider does not turn guidance into feasibility, and Composer success does not prove source or runtime compatibility. Continue to implement `FrameworkTransitionProvider` when users need a documented hop matrix, including explicit unsupported or partially supported gaps.

`defaultSourcePaths()` must return project-relative paths and should stay bounded to the framework's conventional application source. Rules inspect the original source snapshot for every stage; the analyzer does not apply source changes between stages. Stage-aware rules should attach exact hop references, while global inventory remains a record of the original project. Do not execute or boot the analyzed application to improve detection.

### Privacy and trust boundary

Treat manifest data, source text, Composer diagnostics, repository URLs, credentials, and local paths as untrusted input. Put only the minimum reproducible fact in evidence context. Do not emit authentication data, query-string credentials, absolute project or workspace paths, raw environment variables, or unnecessary source excerpts. Core applies publication-boundary redaction, but adapters remain responsible for not creating new secret-bearing fields or bypassing the canonical report writers.

Provider output is metadata consumed by analyzer-owned temporary workspaces. It must never edit the original `composer.json`, `composer.lock`, source tree, or `vendor/`; must not run its own unbounded solver process; and must not claim that analyzer-only remediation candidates were applied to the project.

### Conformance fixtures

Adapter authors should keep committed, offline fixtures for at least:

- metadata-only discovery with no CLI source registration;
- stable IDs and identical canonical output across repeated runs;
- exact target constraints and PHP provenance for every supported adjacent hop;
- canonical ordering plus identical-duplicate normalization and conflicting-duplicate rejection;
- missing targets, ambiguous transitions, guidance gaps, and unavailable exact PHP;
- invalid evidence, provider identity, framework identity, ordering, and adjacency;
- collision with a second active provider;
- source-snapshot immutability and privacy redaction;
- an old-style implementation test when supporting migration from v0.2.

Use committed local Composer `path` repositories or a deterministic runner for solver tests. Snapshot every input byte before analysis and compare it afterward. The repository's test adapter, legacy adapter, orchestrator conformance tests, and Laravel CLI/Artisan parity suite are reference fixtures, not production packages.

The exact PHP value must come from request evidence and satisfy adapter metadata. Laravel prefers the exact final target PHP and falls back to the exact current PHP only when no compatible final value is available; it never derives an exact value from a minimum constraint. Its provider covers rooted `laravel/framework` adjacent paths from 7 through 13. A direct 7→9 request becomes two staged solves, while its direct guidance and direct final-target resolution remain separate report dimensions.

## Optional source-usage visitors

Core's source inspection is framework-neutral. It records imports, inheritance, interface and trait references, attributes, instantiations, static calls, property and constant access, and function calls. It does not know what a service provider, a facade alias, a middleware entry, or a configuration key is, because those are framework concepts and only the owning adapter can interpret them.

An adapter that needs framework-shaped source signal implements `SourceUsageVisitorProvider` in addition to the unchanged required `FrameworkIntegration` contract:

```php
public function sourceUsageVisitors(string $relativeFile): iterable;
```

The analyzer calls this once per scanned file, passing the project-relative path with forward slashes, and expects fresh `PhpUpgradePreflight\Core\Source\SourceUsageCollector` instances. A collector is an ordinary `nikic/php-parser` node visitor that also reports what it observed:

```php
/** @return list<array{symbol: string, usage_type: string, line: int}> */
public function usages(): array;
```

Core owns the shape of a usage record; the adapter owns the `usage_type` vocabulary. Choose stable, lowercase, underscore-separated values, and make sure the adapter's own rules are the only consumers. Core will not interpret, rank, or special-case an adapter's usage types.

Rules to observe:

- Contributed collectors run only for integrations that are **active** for the analyzed project. A project that does not use the framework never receives its vocabulary, and a second adapter never inherits another adapter's heuristics.
- Return a new collector per call. Collectors are stateful for the duration of one file and must not be shared across files or runs.
- Names are already resolved when a collector runs; the analyzer applies `NameResolver` before traversal.
- Ordering is part of the report contract. Within one file, core's framework-neutral usages are reported first, then each active integration's contributed usages in integration order, then in the order the provider yielded its collectors. Collectors never interleave: each one traverses the file alone.
- Report a symbol and an exact line for every usage, so the analyzer can attach `E3` project-source evidence. Usages without a resolvable symbol should be omitted rather than guessed.
- Inspect the source snapshot only. Do not read outside the analyzed project, execute it, or boot the framework to improve detection.

Laravel implements this port. `LaravelSourceUsageVisitor` recognizes the Laravel application skeleton and emits `service_provider`, `facade_alias`, `middleware_reference`, `console_command`, `config_reference`, `test_double`, and `deprecated_queue_dispatch`. Its skeleton and high-signal rules are the only consumers of those values.

### Traversal isolation

The analyzer parses each file once and gives every collector its own `NodeTraverser` over those shared statements. A traverser applies one traversal-control decision to its whole visitor list, so a collector that returns `DONT_TRAVERSE_CHILDREN`, `DONT_TRAVERSE_CURRENT_AND_CHILDREN`, or `STOP_TRAVERSAL` prunes only its own traversal. Core's framework-neutral inventory is never truncated by an adapter, and one adapter never truncates another. The framework-neutral records in `source_inventory` are therefore identical no matter which adapters are installed; a contributed collector can only add records to that list.

Collectors must still leave the AST alone: return the node unchanged (or `null`), and never replace or remove nodes. The parsed statements are shared to avoid re-parsing per collector, so a rewriting collector corrupts the input of every collector that runs after it. Core traverses first and is unaffected either way.

### Contained failures

A defective adapter degrades one report dimension instead of ending the analysis, the same way an invalid staged plan does. The analyzer skips an integration's entire contribution for the current file, records `E2` evidence naming the adapter, adds an uncertainty referencing that evidence, stops consulting that integration for the rest of the scan, and completes the report. Three failures are contained this way, distinguished by the evidence `reason`:

- `provider_failure` — `sourceUsageVisitors()`, or the generator body behind it, threw.
- `invalid_collector` — a yielded value was not a `SourceUsageCollector`.
- `collector_failure` — a collector threw while traversing, or while returning `usages()`.

Containment is not a supported mode of operation. The report says the framework-shaped inventory is incomplete, so rules that depend on the missing vocabulary lose their signal. Cover the failure paths in the adapter's own conformance fixtures.

## Migrating an old-style adapter

The required v0.2 PHP interfaces were not extended. An unchanged implementation can migrate by testing against v0.3 and releasing with a Composer constraint that explicitly permits Core v0.3, for example `"php-upgrade-preflight/core": "^0.3"` or a deliberately supported range. An adapter still pinned to Core `^0.2` is not install-compatible with the v0.3 package line and must not be described as such.

Without `FrameworkStageTargetProvider`, the migrated adapter continues to contribute detection, rules, source paths, optional transition guidance, and optional package-family classification. The report honestly uses `stage_target_provider_unavailable`; it does not infer staged feasibility from the adapter's guidance.

Without `SourceUsageVisitorProvider`, the source inventory contains only core's framework-neutral usage types for that project. Nothing is inferred on the adapter's behalf, so a rule that needs framework-shaped signal must either contribute a collector or read the project state directly.

## Post-v0.3 adapter roadmap

Symfony is the first adapter candidate after the optional staged-target contract has production evidence. v0.3 does not add a Symfony or CodeIgniter package, a fourth distribution repository, or another published adapter.
