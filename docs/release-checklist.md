# v0.1 release checklist

Run this checklist from a clean release candidate commit. Record command output or CI links in the release notes.

## Version and contract

- [ ] Confirm `ReportMetadata::TOOL_VERSION` is `0.1.0` and the current schema stays `0.6`.
- [ ] Confirm every package dependency on another project package uses `^0.1`.
- [ ] Confirm all package manifests use `0.1.x-dev` as the `dev-main` branch alias.
- [ ] Confirm the release verifier rejects every series except patch releases on `0.1.x`.
- [ ] Move any remaining changelog entries under `0.1.0` and confirm the release date.
- [ ] Validate links in README, package support metadata, schema docs, security policy, and changelog.

Run `composer release:verify -- 0.1.0` to enforce the version, branch-alias, internal-constraint, changelog, and release-notes checks above. Composer package versions come from tags; do not add `version` fields to package manifests.

## Deterministic quality gate

- [ ] Run `composer check` on PHP 8.0 through PHP 8.5.
- [ ] Confirm the Windows PHP 8.3 and Ubuntu jobs pass.
- [ ] Run `composer test:fixtures` and review all six JSON and Markdown snapshot pairs.
- [ ] Confirm fixture immutability assertions pass and `git status --short` shows no fixture changes.
- [ ] Run normal and `--prefer-lowest` clean dependency installs for each package subtree on its declared PHP floor.
- [ ] Install the Laravel adapter against supported Illuminate 8, 9, and 10 applications; also smoke-test the declared 11 and 12 constraints.
- [ ] Confirm every Laravel host-line smoke boots the application and verifies provider discovery, analyzer binding, command registration, and the harmless no-target invocation.
- [ ] Confirm the synthetic Composer credential fixture is absent from JSON, Markdown, captured diagnostics, and all generated ZIP entries.

The historical Laravel host matrix uses Composer's `--no-security-blocking` option so published advisories do not prevent installability and application-boot checks. This flag is limited to ephemeral compatibility consumers; a passing compatibility job is not a security endorsement of an old Laravel release, and advisory review remains a separate maintainer decision.

## Fresh-clone audit

- [ ] Clone the release candidate into a new directory with no existing `vendor` tree.
- [ ] Run `composer install` and `composer check` in the documented Docker environment.
- [ ] Install the CLI and Laravel adapter in a separate tools directory.
- [ ] Analyze a copied documented fixture in JSON and Markdown modes.
- [ ] Hash or snapshot the fixture before and after, then confirm byte-for-byte equality.
- [ ] Test an output path containing spaces on Windows and Unix.

The `Release` workflow performs the clean install, deterministic gate, JSON and Markdown analysis, fixture digest comparison, and spaced-output-path audit from a second clone on both Windows and Linux.

## Package distribution

This repository is a monorepo. Packagist reads a package manifest from the root of each distribution repository, so publish the `core`, `cli`, and `laravel` subtrees to their corresponding package repositories before submission.

- [ ] Split `packages/core`, `packages/cli`, and `packages/laravel` with history preserved.
- [ ] Confirm each split root contains its `composer.json`, source, schema resources where applicable, license, readme, changelog, and security information.
- [ ] Run `composer validate --strict` at the root of each split.
- [ ] Create matching signed `v0.1.0` tags on all three package repositories from the approved monorepo commit.
- [ ] Submit or update all three Packagist packages and enable GitHub synchronization.
- [ ] Install `php-upgrade-preflight/cli:^0.1` and `php-upgrade-preflight/laravel:^0.1` from Packagist in an empty tools directory.
- [ ] Confirm `vendor/bin/upgrade-intel --help` and Laravel package discovery work from distribution archives.

Do not submit the monorepo root package to Packagist. Its path repositories support development and cannot resolve as dependency repositories for consumers.

The release workflow stages each package with its license and shared README, changelog, security, and documentation files, then produces validated Composer archives and SHA-256 checksums. The distribution-repository split, signed tags, and Packagist synchronization remain explicit maintainer actions because they require access to separate repositories.

Before upload or publication, the workflow stamps the release version only into each temporary archive manifest, verifies `SHA256SUMS`, scans archive contents for synthetic secret canaries, and installs `core`, `cli`, and `laravel` from the ZIPs in three clean consumers. The consumer gate runs `upgrade-intel --help`, one canonical JSON analysis with a before/after fixture digest, and the Laravel package-discovery boot harness.

## Publish

- [ ] Create the GitHub release from the signed `v0.1.0` tag and attach release notes derived from the changelog.
- [ ] Confirm Packagist shows the license, authors, keywords, homepage, support links, and `0.1.0` for each package.
- [ ] Run the README quick start using only published packages.
- [ ] Announce any known limitations and link to the schema compatibility policy.

A manual `Release` workflow run verifies and packages a version without publishing. Pushing a matching annotated tag publishes the GitHub release only after GitHub verifies its signature, its commit is confirmed on `main`, and the deterministic suite, dependency matrix, and fresh-clone audits pass.

## Release evidence

- [ ] Manual `Release` workflow URL: pending.
- [ ] Approved release commit: pending.
- [ ] `release-archives` artifact and independently verified `SHA256SUMS`: pending.
- [ ] Archive-installed fixture SHA-256 before and after: pending.
- [ ] Published GitHub release URL: pending.
- [ ] Packagist `core`, `cli`, and `laravel` install evidence: pending.
