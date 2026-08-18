# Release checklist

Run this checklist from a clean release-candidate commit. Set `VERSION` to an exact `MAJOR.MINOR.PATCH` value and derive `TAG=v$VERSION`, `SERIES=MAJOR.MINOR`, and `DEV_VERSION=$SERIES.x-dev`. Record command output and CI links in `docs/releases/v$VERSION.md` instead of writing release-specific values into this checklist.

The active `0.3.x` release line is prepared from `main`. Maintenance releases use their protected line: `0.2.x` for v0.2 and `0.1.x` for v0.1.

## Version and contract

- [ ] Confirm the requested release series is enabled by `ReleaseVerifier::ACTIVE_RELEASE_SERIES`.
- [ ] Confirm the approved release branch exists on `origin`, is protected, and contains the release-candidate commit (`0.1.x` for v0.1, `0.2.x` for v0.2, and `main` for the active v0.3 series).
- [ ] Confirm `ReportMetadata::TOOL_VERSION` is the exact `VERSION` and the release notes describe `ReportMetadata::SCHEMA_VERSION`.
- [ ] Confirm the active release schema matches `ReleaseVerifier::ACTIVE_SCHEMA_VERSION`.
- [ ] Confirm every package dependency on another project package uses `^$SERIES`.
- [ ] Confirm root path versions, root requirements, and every `dev-main` branch alias use `DEV_VERSION`.
- [ ] Move releasable changelog entries under a dated `[VERSION]` heading.
- [ ] Create `docs/releases/v$VERSION.md` with `# PHP Upgrade Preflight v$VERSION` as its first line.
- [ ] Validate links in README, package support metadata, schema docs, security policy, changelog, and release notes.

Run `composer release:verify -- VERSION` to enforce the release-series, tool-version, branch-alias, internal-constraint, changelog, and release-notes checks. Composer package versions come from tags; do not add `version` fields to package manifests.

## Deterministic quality gate

- [ ] Run `composer check` on every supported analyzer PHP version.
- [ ] Confirm the required Windows and Ubuntu jobs pass.
- [ ] Confirm the coverage, changed-code, selective-mutation, and representative-corpus budget gates pass.
- [ ] Confirm the release dependency audit reports no vulnerable locked packages.
- [ ] Export a machine-readable inventory of the locked release dependencies (CycloneDX/SPDX SBOM or `composer show --locked --format=json`) and retain it with the release evidence.
- [ ] Run `composer test:fixtures` and review every JSON and Markdown snapshot pair.
- [ ] Confirm fixture immutability assertions pass and `git status --short` shows no fixture changes.
- [ ] Enforce the applicable representative-corpus and staged-analysis budgets in the [v0.2 contract](v0.2-contract.md) or [v0.3 contract](v0.3-contract.md).
- [ ] Run normal and `--prefer-lowest` clean dependency installs for each package subtree on its declared PHP floor.
- [ ] Install the Laravel adapter against every advertised Illuminate host line and run the application-boot smoke.
- [ ] Confirm every host-line smoke verifies provider discovery, analyzer binding, command registration, and a harmless invocation.
- [ ] Confirm the test-only third-party adapter is discovered solely from Composer metadata and proves detection, default source paths, compatibility rules, and package-family classification without a CLI source registration.
- [ ] Confirm adapter discovery tests cover deterministic ordering, malformed metadata, duplicate class and case-insensitive name collisions, unavailable classes/packages, and explicit `--framework` failures.
- [ ] Confirm synthetic credentials are absent from JSON, Markdown, captured diagnostics, workflow logs, and generated ZIP entries.

Historical host matrices may need Composer's `--no-security-blocking` option when a released old framework has advisories. Limit that flag to ephemeral compatibility consumers; installability is not a security endorsement.

## Fresh-clone audit

- [ ] Clone the release candidate into a directory with no existing `vendor` tree.
- [ ] Run `composer install` and `composer check` in the documented environment.
- [ ] Install the CLI and framework adapters in a separate tools directory.
- [ ] Analyze the copied PHP 7.4 fixture from that tools installation in JSON and Markdown modes without installing anything into the target.
- [ ] Hash the fixture before and after and confirm byte-for-byte equality.
- [ ] Test an output path containing spaces on Windows and Unix.

The release workflow performs the clean install, deterministic gate, JSON and Markdown analysis, fixture digest comparison, and spaced-output-path audit from a second clone on both platforms.

## Package distribution

This repository is a monorepo. Packagist reads a package manifest from the root of each distribution repository, so publish `core`, `cli`, and `laravel` subtrees to their corresponding repositories before synchronization.

`tools/prepare-distribution.sh` rebuilds the three distribution working trees from the current checkout, and `tools/release-distribution.sh` commits, signs, and pushes them. Both are described in [the tools guide](../tools/README.md).

For v0.3 these Composer packages remain the only supported external distribution. The generated package ZIPs are Composer distribution artifacts, not a PHAR. Do not attach a PHAR or publish a project container image as a supported runtime; the development Docker files are outside the release surface.

- [ ] Split every package subtree with history preserved.
- [ ] Confirm each split contains its manifest, source, schema resources where applicable, license, shared readme, changelog, security policy, and documentation.
- [ ] Record each split commit, source monorepo commit, archive filename, SHA-256 digest, and dependency-inventory digest as artifact provenance.
- [ ] Run `composer validate --strict` at every split root.
- [ ] Create matching signed `TAG` tags on the monorepo and all distribution repositories from the approved commit.
- [ ] Update all Packagist packages and confirm GitHub synchronization.
- [ ] Install the released package constraints from Packagist in an empty directory.
- [ ] Confirm the generic CLI help, one analysis, framework package discovery, and target-project immutability from distribution artifacts.

Do not submit the monorepo root package to Packagist. Its path repositories exist only for development.

The workflow stamps the exact release version only into temporary archive manifests, verifies every checksum against the attached asset bytes, validates the dependency inventory and source-bound provenance, scans archives for seeded secrets, and installs every package ZIP in clean consumers. For a tag release it also downloads each matching signed distribution tag and compares the complete extracted payload with the expected split package, including shared documentation. The published quick-start check hashes a copied target before and after analysis so a successful command cannot hide target mutation. Distribution-repository updates and Packagist synchronization remain explicit maintainer actions.

## Publish

- [ ] Create the GitHub release from the signed `TAG` and attach release notes derived from the changelog.
- [ ] Confirm Packagist shows the expected metadata and exact version for every package.
- [ ] Run the README quick start using only published packages.
- [ ] Announce supported transitions, schema migration requirements, and known limitations.
- [ ] Record the workflow run, approved commit, signed tag verification, distribution split commits, archive checksums, dependency-inventory checksum, immutable-fixture checksum, release URL, and Packagist evidence in `docs/releases/v$VERSION.md`.

A manual `Release` run verifies and packages without publishing. A matching annotated tag publishes only after GitHub verifies its signature, confirms its commit is on `main`, `0.2.x`, or `0.1.x` according to the tag series, and all release gates pass. Historical v0.1.0 and v0.2.1 evidence remains retained.
