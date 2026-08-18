# Limitations and trust boundaries

PHP Upgrade Preflight is a public-beta planning tool that predicts dependency and source impact. It does not perform an upgrade, certify application compatibility, establish production readiness, or prove that the upgraded application works.

## Dependency resolution

- Composer resolves scenarios against the repositories, credentials, network, and executable available to the analyzer process. A complete target-platform profile removes analyzer-host inheritance only for its supported safely simulated platform-package classes; unlisted values in those classes are modeled absent. It does not pin repository metadata, downloads, credentials, network behavior, or Composer executable behavior. Composer platform packages tied to the executable are labeled `toolchain_bound`, not claimed as safely simulated. Complete profiles and explicit absences require Composer 2.2 or newer.
- Compatible execution may inherit the host's global Composer configuration, authentication, proxy variables, cache, Git/SSH configuration, and network. Restricted execution replaces the Composer-layer configuration, authentication, proxy environment, and cache roots with analyzer-owned state and requests best-effort offline behavior. It does not provide process or OS network isolation; user-selected executables, helper subprocesses, system trust, and credentials embedded in project input remain residual boundaries.
- A restricted offline cache miss or unavailable repository metadata is operational uncertainty (`repository_metadata_unavailable`), not a dependency incompatibility blocker.
- A partial profile, original `config.platform`, and named `--with-extension` / `--without-extension` assumptions remain host-dependent for unlisted packages. Presence-only assumptions cannot prove versioned constraints, so sentinel-related failures remain non-blocking advisories. The canonical report states the profile completeness, closed-world status, toolchain-bound names, and every effective decision; consumers must not widen that guarantee.
- Scripts and plugins stay disabled. Installation, audit, interaction, and progress are also disabled. A project that depends on plugin behavior may resolve differently in its normal environment.
- A successful candidate lock proves that Composer found one dependency solution. It does not prove runtime or production compatibility.
- A lock entry whose package name is not a valid Composer package name, or which carries no readable version, cannot be indexed and is skipped. Skipped entries are excluded from locked packages, package changes, abandoned-package advisories, autoload ownership, and the framework findings derived from that entry's own requirements. Each omission is published as uncertainty naming the skipped entries rather than dropped silently; the named list is bounded to ten entries.
- Candidate lockfiles produced by direct scenarios and staged attempts report the same omissions separately, because a candidate lock is discarded with its workspace. Their published package counts and the package changes derived from them exclude the skipped entries, while the recorded candidate-lock hash still covers the whole file.
- Partial platform and staged probes provide ordering evidence. Only full-target scenarios determine combined feasibility, and only a complete profile can support the narrower cross-host platform-decision reproducibility claim.
- Composer output changes can reduce blocker parsing confidence. The report retains bounded, redacted excerpts and uncertainty. Redaction deliberately removes whole URLs and known credential forms, so diagnostic text may be less specific.

## Source inspection

- The scanner parses PHP syntax and reports supported static symbol and configuration references.
- It does not execute the application, resolve container bindings, evaluate dynamic class names, or infer string-built symbols.
- Composer PSR-4 and PSR-0 ownership uses deterministic longest-prefix matching for class-like symbols. Function and constant ownership requires an exact declaration from an available classmap or files entry. Root `autoload-dev` metadata is indexed, while dependency `autoload-dev` metadata remains root-only and is ignored.
- Missing classmap/files paths, unsupported metadata shapes, `eval`, `class_alias`, and registered dynamic autoloaders are reported as ownership uncertainty. Exact classmap/files indexing is skipped when inventory is empty and stops at a deterministic 2,000-file safety limit; exceeding it also adds uncertainty. Custom installer paths and runtime-generated symbols may therefore remain unresolved.
- Parse errors skip the malformed file and add evidence-linked uncertainty.
- Default scans exclude dependencies and common generated, cache, and build directories. Explicit paths inside the project can opt into an excluded directory.
- `source_inventory` is an observation list, not a change list. An item reaches `source_impact` only when it correlates with a selected final-target package change, an applicable framework rule, or both. Dynamic or unowned usages can therefore remain inventory-only, while a framework-correlated item may be actionable even when package ownership is unknown.
- Direct `source_impact` remains tied to the selected successful final-target scenario. Schema 0.8 stages separately project applicable framework findings and source impact for executed hops, but always from the original project snapshot. The analyzer does not simulate or claim that source edits were applied between stages.
- Framework-shaped source usages come from the active framework adapter, not from core. A project analyzed without an active Laravel integration receives no `service_provider`, `facade_alias`, `middleware_reference`, `console_command`, `config_reference`, `test_double`, or `deprecated_queue_dispatch` usages, because core does not interpret another framework's application skeleton. An adapter for another framework contributes its own collectors through the optional `SourceUsageVisitorProvider` extension point.

## Framework guidance

- v0.3 retains Laravel 7→8 and direct 7→9 rules and includes every adjacent rule pack from 8→9 through 12→13. Gapless adjacent packs can compose multi-major guidance within Laravel 7–13. Same-major requests, downgrades, ambiguous or unknown majors, a source or target outside the catalog, and requests whose first hop is missing are unsupported. A covered prefix before a later gap is only `partially_supported`, and guidance never crosses the gap.
- Framework support describes rule-pack coverage, not dependency feasibility. In schema 0.8, `transition.framework_guidance[].status`, direct `resolution.status`, and `staged_resolution.status` are three independent dimensions and may disagree without error.
- Staged Composer solving supports a single rooted `laravel/framework` target across every contiguous adjacent path from Laravel 7 through 13, including a 7→9 request executed as evidenced 7→8 and 8→9 stages. Illuminate-component-only projects and mixed Laravel-family target sets remain honest `staged_resolution.execution_state: skipped` results. Staged solving also skips on a provider conflict, ambiguous endpoint, unsupported range, missing adjacent target or rule pack, or when neither the exact final target PHP nor the exact current PHP satisfies adapter metadata.
- The Laravel 13 pack is limited to exact package metadata and explicit maintainer guidance; unencoded guide changes remain manual review work.
- Encoded package ranges and maintainer links identify review work; they do not replace each package's upgrade guide.
- Skeleton findings identify locations to compare and carry low confidence. They are not confirmed incompatibilities.
- Laravel 11's streamlined application skeleton is optional for upgraded Laravel 10 applications; the adapter does not report the retained Laravel 10 structure as required migration work.
- The Laravel 11 curl rule can prove an explicitly absent `ext-curl` assumption. A present PHP extension version does not prove the linked libcurl runtime version, so deployment verification remains necessary.
- Ambiguous framework target ranges produce less guidance instead of guessing a target major.
- A framework adapter that fails is contained rather than fatal. A provider that throws while contributing source collectors, a collector or compatibility rule that throws mid-analysis, a rule returning a severity outside the `low`/`medium`/`high` vocabulary, and an installed package whose `extra.php-upgrade-preflight` manifest is malformed are all skipped and named as evidence-backed uncertainty. The report is still produced, so guidance can be incomplete for a reason recorded only in `uncertainties`; read them before treating an absence of findings as a clean result.

## Report projection

- JSON is the canonical report and Markdown is a projection of it. A field the report does not carry is rendered as not recorded rather than as a default, so an absent Composer execution policy, timeout, inherited-credential state, stage duration, or staged verdict is missing evidence and not an analyzer conclusion.
- Composer output excerpts are bounded and redacted before serialization. The report does not mark which excerpts were shortened or whether redaction failed on a specific excerpt, so excerpt text is partial evidence rather than a complete transcript. Consult the original Composer output when an excerpt is the basis of a decision.
- A Composer diagnostic carries an explicit `outcome` alongside its exit status, so a probe timeout, a missing Composer executable, and a genuine non-zero result are distinguishable. A diagnostic that did not run proves nothing about the packages it would have examined.

## Read-only boundary

The analysis pipeline copies the target manifest and lockfile, then scans source files without writing them. A project-local `composer require` still changes the project during installation. Shell redirection can also write inside the project before the analyzer can validate the destination.

Exact project and source paths remain available only for internal filesystem access. Default canonical JSON and Markdown replace absolute roots with `[PROJECT_ROOT]`, `[REPORT_OUTPUT]`, `[LOCAL_REPOSITORY]`, and `[ANALYZER_WORKSPACE]`; source file locations remain project-relative.

Debug mode preserves temporary copies and exposes exact `temp_path` values, making debug reports and retained workspaces non-shareable. Those workspaces contain the copied Composer manifest and lock data; output redaction does not sanitize files retained on disk. Default cleanup failures expose only `[ANALYZER_WORKSPACE]`. Credentials remain redacted in rendered output and diagnostics in every mode.

## Untrusted projects

The tool reads Composer metadata and PHP source from the target. Composer scripts and plugins stay disabled, but Composer may contact declared repositories and use host credentials. Analyze untrusted repositories inside a disposable container or restricted account with scoped credentials and network access.

Secret redaction is deterministic and covered by synthetic canaries, but no pattern-based filter can recognize every future credential format. Use least-privilege credentials, review reports before sharing them, and report an unredacted form through the private security channel.
