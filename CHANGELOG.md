# Changelog

This project follows [Semantic Versioning](https://semver.org/). Report schema versions follow a separate compatibility policy documented in [docs/schema.md](docs/schema.md).

## [Unreleased]

## [0.3.0] - 2026-08-18

### Added

- Froze the signed v0.2.1 compatibility surface and canonical reports, created the `0.2.x` maintenance line, and locked the machine-readable v0.3 contract with schema `0.8` migration evidence.
- Added schema `1.0` partial and complete target-platform profiles with closed-world semantics for safely simulated PHP, extension, library, PHP-subtype, and Composer-platform package classes.
- Added compatible and restricted Composer execution policies with redacted provenance, bounded timeouts, analyzer-owned restricted configuration, controlled credential and proxy scrubbing, and best-effort offline operation.
- Added bounded staged Composer analysis across every contiguous rooted `laravel/framework` path from 7→8 through 12→13, deterministic remediation attempts, carried candidate state, blocker lifecycle tracking, exact request-backed stage PHP selection, and an offline five-minute demo fixture.
- Added stage-scoped package changes, source impact, blockers, risk, effort, tests, recommendations, and fingerprint-linked evidence under report schema `0.8` while preserving direct-final resolution independently.
- Added the optional framework-neutral stage-target provider, third-party and legacy-adapter conformance fixtures, CLI/Artisan parity coverage, worst-case staged budgets, and selective mutation protection.
- Added the optional `SourceUsageVisitorProvider` extension point and its `SourceUsageCollector` contract, letting an adapter contribute framework-shaped source inspection for the projects where it is active while core keeps owning only the shape of a usage record.
- Added a required `outcome` to every Composer diagnostic in report schema `0.8`, drawn from the same vocabulary scenario results already use, so a diagnostic timeout, a missing Composer binary, and a genuine non-zero `prohibits` exit are no longer indistinguishable in canonical output.
- Added a shared `ReportWriter` contract and format resolver in core so the generic CLI and the Artisan command cannot drift into rendering the same `--format` value differently.

### Changed

- Declared the project a source-available public beta, clarified the noncommercial PolyForm licensing model, documented the v0.2.x compatibility commitment and v0.3 change boundary, and made explicit that analyzer output is not a production-readiness or runtime-compatibility guarantee.
- Declared the v0.2.x line archival rather than actively supported: its published artifacts stay installable and its schema `0.7` evidence stays immutable, but once v0.3.0 is published the line receives no features and no routine bug or security fixes, and the upgrade path is v0.3. Documented alongside it the limits a reader needs to trust a v0.3 report: framework-shaped source usages now come from the active adapter rather than core, a failing adapter or rule is contained as uncertainty instead of ending the run, Markdown renders unrecorded fields as absent rather than defaulted, and bounded Composer output excerpts do not mark truncation or a failed redaction pass.
- Published a commercial-license request form and limited external contributions to documentation-only changes until a legally reviewed contributor license agreement or other suitable inbound license grant is adopted; bug reports, private security reports, and product feedback remain welcome.
- Advanced the active release line to `0.3.x`, report metadata to tool `0.3.0`, and the canonical report schema to `0.8`; `main` keeps `0.3.x-dev` aliases and `^0.3` internal constraints while released v0.2.1 artifacts remain immutable.
- Kept the PHP `^8.0` runtime floor, the three-package release set, normal/lowest Laravel 8–13 host-installability coverage, and the separate Composer tools-directory delivery model.
- Cut repeated continuous-integration work by superseding in-flight pull-request runs, caching PHPStan result caches per configuration and the PHP-CS-Fixer cache, reusing Composer archive downloads across the compatibility matrix, and folding workflow linting into the static-analysis runner; release runs entered through `workflow_call` are never cancelled, and Packagist metadata is still fetched fresh on every compatibility job so ecosystem drift stays observable.
- Kept the developer image's apt and PHP-extension layer cacheable by declaring `USER_ID` and `GROUP_ID` after it, so a host UID other than 1000 no longer forces a full rebuild, and excluded nested vendor directories from the Docker build context.
- Moved Laravel application-skeleton source inspection out of core and into the Laravel adapter, so core no longer hardcodes another framework's provider bootstrap, kernel property names, facade alias table, or configuration helpers. Laravel analysis is unchanged; a project analyzed without an active Laravel integration no longer receives `service_provider`, `facade_alias`, `middleware_reference`, `console_command`, `config_reference`, `test_double`, or `deprecated_queue_dispatch` usages it could not interpret.
- Stopped the Markdown projection from substituting invented values for report fields it was not given. A document without Composer execution provenance or staged resolution now says the schema did not record them instead of printing a fabricated compatible-mode policy, hardcoded timeouts, inherited-credential status, or a `skipped`/`unknown` staged verdict that reads as an analyzer conclusion. Side-effect state, stage durations, source-snapshot notes, and finding identifiers are likewise projected or reported absent rather than defaulted.
- Clamped the configured Composer diagnostic timeout to the staged scenario ceiling, so a diagnostic budget above 300 seconds can no longer extend a staged run past its declared per-stage limit. Default configurations are unaffected.
- Declared `symfony/console` in the Laravel package, which previously relied on `illuminate/console` supplying it transitively, and dropped the unused `symfony/finder` requirement from core.
- Widened core's `symfony/filesystem` and `symfony/process` requirements to include `^8.0`, matching the Symfony 8 branch the Laravel adapter already advertises through `symfony/console`; core uses only `Path`, `Filesystem::dumpFile()`, and the array-command `Process` constructor, all unchanged in Symfony 8, and the PHP `^8.0` runtime floor is unaffected because Symfony 8 requires PHP 8.4.
- Isolated every source-usage collector in its own traversal over the once-parsed syntax tree. An adapter collector returning `DONT_TRAVERSE_CHILDREN` or `STOP_TRAVERSAL` previously suppressed those nodes for core's own visitor too, so `source_usages`, source impact, risk, and effort depended on which adapters happened to be installed.
- Narrowed `ReportFileWriter` to a `ReportDestinationFilesystem` port and made `ReportAssembler::assemble()` take actionable source impact explicitly, removing a second construction path that produced findings without ownership or package-metadata evidence. Both are source-breaking for anyone constructing those classes directly: `ReportFileWriter::__construct` no longer accepts a `Symfony\Component\Filesystem\Filesystem`, and `assemble()`'s actionable-source-impact argument is now required and earlier in the list.
- Made `PackageRef` reject names that are not valid Composer package names. Reading a lockfile is unaffected — an unusable entry is skipped and reported as uncertainty — but code constructing a `PackageRef` directly with an invalid name now receives an `InvalidArgumentException` instead of a silently malformed reference.
- Gave the vocabularies that reach the canonical report a single owner and validated them where the model is constructed: the `low`/`medium`/`high` severity scale, the identical confidence scale, the blocker type catalog, and the solver-relation vocabulary. Framework-finding severity, source-impact severity, risk level, effort confidence, and evidence confidence were previously validated in some models and accepted unchecked in others. An unsupported grade or an unregistered blocker type is now an `InvalidArgumentException` at construction rather than an out-of-vocabulary value in the report.
- Enforced `EffortEstimate` invariants: the range and every component range must be exactly two non-negative integer hours in ascending order, confidence must be a supported grade, and assumptions must be strings. Analyzer-produced estimates are unchanged; code constructing the model directly with an inverted, negative, or malformed range now receives an `InvalidArgumentException` instead of serializing it into the report.
- Moved package and constraint normalization into `UpgradeTarget` itself, so every construction site — including adapter-supplied stage and remediation targets — receives the same lowercased package name, trimmed constraint, and rejection of a target Composer could not resolve. `UpgradeTargetSet` keeps only deduplication, PHP-target merging, contradiction rejection, and canonical ordering.
- Decomposed the phases that staged analysis had turned into orchestration monoliths into named collaborators: `StagePlanResolver`, `StageExecutor`, `StageBlockerRegistry`, and `StageAttemptPlanner` behind the staged orchestrator; `ScenarioWorkspacePreparer`, `ScenarioOutcomeClassifier`, and `CandidateLockFileReader` behind the Composer scenario runner; `LaravelRuleFactory`, `LaravelTransitionAssessor`, `LaravelStagePlanner`, and `LaravelFrameworkDetector` behind the Laravel integration; `AdapterManifestReader` and a table-driven `CommandLineOptions` behind CLI discovery and option parsing; a shared `TestGuidanceCatalog` for the guidance staged and non-staged reports had been emitting from copy-pasted code; and a narrow `EvidenceRecorder` writing surface over `EvidenceLedger`. Public entry points, request handling, and adapter interfaces are unchanged, and these splits produce no report change of their own.
- Removed `PhpUpgradePreflight\Core\Source\ContextualSourceUsageVisitor` from the core package; its behaviour now lives in `PhpUpgradePreflight\Laravel\Source\LaravelSourceUsageVisitor` and reaches the scanner through the optional `SourceUsageVisitorProvider` port. Adapters contributing their own source inspection should implement that interface rather than depending on the removed class.
- Removed the unreachable `LegacyLaravelPackageRule`, whose behaviour was a strict subset of the catalog-driven advisory rule and which no rule definition could construct.

### Fixed

- Reported a failure while preparing an analyzer-owned workspace as a workspace failure rather than a Composer process failure, so a report no longer states that Composer failed when no Composer process was ever started.
- Skipped locked packages whose names violate Composer's package-name grammar instead of aborting the analysis, keeping a malformed lockfile an input that still yields a canonical report.
- Surfaced Composer metadata-probe cleanup failures as uncertainty instead of discarding them, which previously left a temporary directory behind and silently degraded Composer version and platform detection.
- Wrote simulated `config.platform` values onto the key casing the analyzed manifest already declares. A project configuring a case variant such as `"PHP"` previously had a lowercase `php` key added beside it, and the resulting contradiction failed every scenario as a workspace failure.
- Reported a `composer.json` whose `config` or `config.platform` is not an object as invalid JSON naming the manifest, instead of aborting workspace preparation and blaming the analysis environment.
- Published a contradictory `config.platform` through the canonical input-failure report, including when `composer.lock` is missing or unreadable, instead of letting the rejection escape the project loader as an unstructured error.
- Contained a framework adapter that fails while contributing source-usage collectors — a throwing provider, a yielded value that is not a `SourceUsageCollector`, or a collector that throws mid-traversal. The adapter is skipped with evidence and an uncertainty naming it, rather than ending the analysis with no report after every Composer scenario already succeeded.
- Contained a framework compatibility rule that throws or returns a severity outside the `low`/`medium`/`high` vocabulary. The rule is skipped and recorded as evidence-backed uncertainty naming the adapter and rule, and the remaining findings are still reported.
- Stopped one installed Composer package with a malformed `extra.php-upgrade-preflight` manifest from ending the whole run. Discovery skips that package and names it on stderr, valid adapters still load, and an explicitly requested `--framework` whose package was skipped still fails with an error naming it.
- Published the entries a candidate or staged Composer lockfile could not index as uncertainty, so a package missing from `package_count` and `package_changes` is no longer dropped without explanation.
- Reported Composer lock entries carrying no readable version as uncertainty instead of letting them vanish from locked packages, package changes, and the framework findings derived from their own requirements.
- Reported a malformed package name appearing in both `packages` and `packages-dev` once, and counted only distinct names in the bounded "(and N more)" suffix.
- Rendered the canonical diagnostic `outcome` in the Markdown projection alongside the exit code, so a probe timeout, a missing Composer binary, and an ordinary non-zero `composer prohibits` exit are distinguishable in Markdown as they already were in JSON.
- Kept every stage-remediation evidence identifier referenced by the plan that minted it, so a rule catalog carrying several guidance entries for one package on one stage no longer orphans evidence and skips the entire staged chain as `invalid_stage_plan`.
- Applied `max_attempts_per_stage` on every branch of the staged attempt vocabulary, so the no-remediation path can no longer bypass the published attempt cap.
- Restored the coverage ratchet, which aborted before comparing anything because twelve modules extracted during this release were added to the critical-module list without matching baseline entries.
- Made candidate-state fingerprints identify content instead of location. A stage's manifest and lock digests previously absorbed Composer's `content-hash` of the manifest the analyzer writes into its own workspace, which carries the absolute path repositories that workspace needs, and the host separators left after an exposure marker. The same project analyzed from two directories, or on Linux and on Windows, reported different state chains, so the digests a consumer is told to follow across stages — and the demo script that checks its result against the checked-in report — only matched on the machine that produced them. The digests now exclude that derived hash and read one separator spelling; `candidate_lock.sha256` and `candidate_lock.content_hash` still report exactly what Composer wrote.
- Redacted a private path spelled with mixed separators, such as a Windows root joined to forward-slashed segments. Composer echoes a project path exactly as the caller wrote it, and the fully backslashed and fully forward-slashed spellings the redactor recognized did not match that form, so an analyzed project's absolute path could reach a report's Composer output excerpts on Windows.

### Security

- Added restricted Composer execution that removes controlled inherited credentials and proxy settings, uses fresh analyzer-owned Composer state, requests offline behavior, and reports repository cache misses as operational uncertainty without claiming OS-level isolation.
- Retained credential and path redaction, synthetic secret canaries, read-only target checks, dependency audits, commit-pinned actions, archive checksums, dependency inventory, and source/build provenance gates.

## [0.2.1] - 2026-08-11

### Fixed

- Canonicalized coverage source paths on Windows so the deterministic coverage ratchet accepts equivalent long and short filesystem paths without weakening stale-coverage detection.
- Generated Composer artifact-repository JSON with escaped, slash-normalized paths and provisioned `ext-fileinfo` for the Windows release-archive Laravel consumer.
- Removed seeded release-verifier canaries from package test sources while retaining independent provider-token redaction specimens.

### Changed

- Published-package verification now requires Core, CLI, and Laravel to resolve to the exact source and distribution commits behind their verified signed tags, preventing a version-only Packagist check from accepting an immutable stale reference.
- Reports identify the patch release as `0.2.1`; schema `0.7`, `0.2.x-dev` branch aliases, and `^0.2` internal constraints are unchanged.

### Security

- Added a package-source canary boundary test and exact installed-reference verification for the Packagist quick start.

## [0.2.0] - 2026-08-11

### Added

- A machine-checked v0.1 compatibility contract covering the public PHP operation, generic CLI arguments and defaults, exit policy, schema `0.6`, and all six Laravel JSON/Markdown report approvals archived from the signed `v0.1.0` release.
- The commit-pinned Laravel v0.2 transition scope through Laravel 13, with exact official guide, framework-manifest, and application-manifest evidence.
- Schema `0.7` with complete/partial platform provenance, explicit unmodeled-host uncertainty, a distinct source inventory, actionable framework-correlated source impact, and framework guidance independent of Composer feasibility.
- Typed transition assessments for the retained Laravel 7 to 8 and direct 7 to 9 rule packs; every emitted framework finding is scoped to a supported assessed hop.
- A versioned, typed Laravel rule catalog for transition applicability, PHP and package constraints, official evidence sources, and skeleton patterns, with test-time validation of keys, sources, SemVer, coverage gaps, and conflicting advice.
- A machine-checked v0.2 transition contract with contiguous-prefix partial-support semantics, development-version policy, `0.6` consumer migration, historical schema checksums, and representative-corpus quality budgets.
- Offline Composer fixtures for required, missing, disabled, compatible, and incompatible extension-platform scenarios, including request provenance and immutable path-repository coverage.
- A seeded report-privacy verifier for canonical JSON, Markdown, evidence, exception rendering, debug output, command diagnostics, and CI logs on Linux and Windows quality jobs.
- Static symbol ownership from root and locked-package PSR-4, PSR-0, classmap, and files autoload metadata, with typed exact declarations, constant case sensitivity, bounded scanning, explicit ambiguity, and dynamic-loader uncertainty.
- Adjacent Laravel 8→9, 9→10, 10→11, 11→12, and 12→13 rule packs with fixture coverage for target PHP, Composer metadata, curl availability, official dependency and first-party package migrations, PHPUnit/Pest requirements, Carbon 3, removed queue-dispatch APIs, legacy helper conflicts, and request-forgery middleware references.
- Separate feasible and advisory-heavy or blocked full-analyzer fixtures for every approved adjacent Laravel path, with real offline Composer resolution against a committed path repository, plus modular Illuminate roots, ambiguous source and target evidence, missing-hop behavior, supported multi-major composition, evidence-class checks, and CLI/Artisan canonical JSON parity.
- Composer-metadata framework-adapter discovery, with a test-only third-party package proving automatic detection, default source paths, compatibility rules, and package-family classification without CLI source registration.

### Changed

- Reports identify the released tool as `0.2.0`; path packages and branch aliases remain on the `0.2.x-dev` development line, with internal package constraints on `^0.2`.
- The release checklist is version-neutral; release-specific values and evidence live in versioned release notes.
- Release verification now enables the `0.2.x` line from `main` and checks the exact tool version, schema `0.7`, branch aliases, root path versions, internal constraints, changelog, and release notes together.
- Release publication verifies exact artifact checksums and source-bound provenance, compares the complete signed distribution-tag payloads with their expected package splits, and proves that the published quick start does not mutate its analyzed target.
- Default shareable reports replace project, output, local-repository, and analyzer-workspace roots with stable policy markers; exact sanitized workspace paths require explicit non-shareable debug mode.
- Source-change plans, risk, and effort now use weighted actionable package/framework correlations rather than every raw scanner observation.
- Repeated framework findings from composed adjacent rule packs are merged while retaining all hop references and evidence records.
- Direct Symfony guidance now preserves component-specific constraints from pinned Laravel manifests instead of widening every component to one target-major range.
- Deterministic Laravel transition fixtures now reject candidate locks that violate root package constraints or the simulated target PHP; modular and ambiguous-source feasible cases use ranges that genuinely admit their simulated Laravel 13 locks.
- v0.2 supports one external execution path: Composer packages installed in a separate tools directory; no PHAR or versioned container image is shipped or supported.

### Security

- Expanded recursive redaction for URL user information, authorization and Composer auth values, common provider tokens, escaped JSON, multiline diagnostics, and credential-bearing evidence before serialization.
- Sanitized filesystem failures, cleanup exception chains, CLI and Artisan diagnostics, and local repository output before they can reach reports or CI logs.

## [0.1.0] - 2026-08-08

### Added

- Isolated Composer baseline, exact-target, all-dependencies, minimal-change, platform, and staged scenarios.
- Structured solver outcomes, blocker grouping, lock diffs, abandoned-package advisories, and candidate-lock fingerprints.
- Parser-based PHP source impact with evidence for symbols, configuration, middleware, providers, commands, and test doubles.
- Laravel 7 to 8/9 detection and conservative compatibility rules for common first-party, test, Symfony, and legacy packages.
- Canonical JSON schema v0.6, Markdown reports, generic CLI, and Laravel Artisan command.
- Six approved fixture reports in JSON and Markdown, with Windows and Unix CI coverage.
- External-analysis, schema, limitation, troubleshooting, contribution, security, and release documentation.
- Normal and lowest-dependency consumer install gates for PHP 8.0 and Laravel 8/9/10, plus declared Laravel 11/12 host integrations.
- Fresh-clone read-only audits on Windows and Linux, tested release metadata verification, reproducible package archives, and signed-tag-gated GitHub release publication.
- Constructor-level Composer output redaction plus synthetic credential, token, private-URL, report, log, and release-archive leak gates.
- Checksum-verified release-archive consumers that install all three ZIPs, run the CLI, analyze an immutable fixture, and boot Laravel package discovery before publication.
- Composer-installed CLI entry-point discovery for both generated proxy and standard `vendor/` package layouts.

[Unreleased]: https://github.com/ValentinNikolaev/php-upgrade-preflight/compare/v0.3.0...HEAD
[0.3.0]: https://github.com/ValentinNikolaev/php-upgrade-preflight/releases/tag/v0.3.0
[0.2.1]: https://github.com/ValentinNikolaev/php-upgrade-preflight/releases/tag/v0.2.1
[0.2.0]: https://github.com/ValentinNikolaev/php-upgrade-preflight/releases/tag/v0.2.0
[0.1.0]: https://github.com/ValentinNikolaev/php-upgrade-preflight/releases/tag/v0.1.0
