# Laravel coverage review

This review covers the existing Laravel 7–13 transition catalog without extending schema `0.8` or the v0.3 staging contract. It is a completion audit, not a claim that every Laravel application or ecosystem package is compatible.

The [compatibility baseline](compatibility-baseline.json) records the verified published references, preserved contracts, gap dispositions, and bounded feedback review. The [real-application evaluation](application-evaluation.md) records pinned public inputs, measured outcomes, corrected findings, and maintainer follow-up.

## Guide accounting

The [legacy ledger](legacy-guide-audit.json) accounts for all 163 headings in the pinned Laravel 8, 9, and 10 upgrade guides. The [modern ledger](modern-guide-audit.json) accounts for all 136 headings in the pinned Laravel 11, 12, and 13 guides. Headings inside code fences are excluded. Each substantive entry links its source, identifies the relevant catalog rules, or records a concrete manual verification action.

The classifications mean:

- `implemented`: a bounded metadata or static-source check exists; runtime behavior is still unproved.
- `patch-correction`: this completion work fixes a demonstrated omission or misleading finding within an existing supported transition.
- `manual-review`: the analyzer lacks the database, deployment, configuration, or application execution context needed to prove the change.
- `contract-dependent`: custom implementations and inherited methods need human review; an interface reference alone is not a missing-method finding.
- `not-applicable`: editorial content, optional tooling, or an optional skeleton migration does not establish incompatibility.

These ledgers supplement the [original transition decision](../laravel-v0.2-transition-scope.md); they do not replace its frozen machine-readable evidence. The newer Laravel 13 guide adds conditional session serialization advice: projects adopting the new skeleton's JSON configuration must review stored session data. Existing PHP serialization is not itself an incompatibility.

## Corrected guidance

Package ranges are review evidence, not an independent solver. PHPUnit and Collision upgrades that are optional must not be presented as mandatory merely because a fresh application skeleton uses newer versions. The ledgers distinguish framework-supported ranges, guide recommendations, and skeleton defaults. Composer must still solve the actual project's combined constraints.

The completion corrections also cover guide-mentioned Guzzle and Socialite ranges, the Nexmo-to-Vonage and SwiftMailer-Postmark replacements, `spatie/once` removal, and supported Pusher ranges. Rooted package checks do not promise automatic ecosystem-wide migrations.

Exact source rules identify the removed global `elixir` helper, Laravel's removed serializable-closure classes, the removed `MocksApplicationServices` trait, and `HasVersion7Uuids`. They use parser-derived symbol identities and usage types; unused imports and unrelated same-short-name classes do not establish those removals. Elixir calls within namespaces require an explicit global name or resolved global function import; unresolved namespaced fallback calls remain manual review. Queue dispatch advice matches Laravel's Bus facade rather than arbitrary facades named Bus.

Laravel 13 retains `VerifyCsrfToken` and `ValidateCsrfToken` as deprecated aliases. Exact direct references receive medium-severity review guidance, not a high-severity removal claim. Test exclusions, origin checks, and deployment behavior separately.

## Work that remains manual

Read the per-section action in the ledger for database-engine versions, migration column attributes and spatial types, cache key continuity, authentication contract changes, custom container/schema implementations, dynamic names, and middleware behavior. Static findings cannot establish database state, container resolution, inherited implementation correctness, or application runtime compatibility.

Retaining a Laravel 10 application skeleton while upgrading to Laravel 11 is supported by the upstream guide. The analyzer must not require a fresh Laravel 11 skeleton.

## Existing contract exclusions and user workflow

Illuminate-only projects and mixed framework/component targets can use direct Composer analysis, but v0.3 skips staged solving. Keep every intended rooted component target in the request; do not drop conflicting targets to obtain a staged result. Same-major upgrades likewise use direct analysis without supported framework-hop guidance or staging. Downgrades, ambiguous endpoints, and targets outside the catalog have explicit guidance/staging limits.

For a supported staged path, request one rooted `laravel/framework` target across contiguous ascending majors. Inspect direct resolution, framework guidance, and staged resolution separately; a successful dimension does not make the others successful. Every stage inspects the original source snapshot, not imagined edits from previous stages.

The new [excluded-transition fixtures](../../tests/fixtures/laravel-exclusions/README.md) exercise these boundaries with real offline Composer evidence and CLI/Artisan parity. Family-wide rooted targets, same-major version identity, and Laravel/Symfony package ownership remain explicit v0.4 decisions—not implemented patch support.
