# Versioning

PHP Upgrade Preflight uses Semantic Versioning for tool and package releases, with all three packages released in lockstep. Report schemas have their own version and compatibility policy described in [Schema and compatibility](schema.md).

## Public beta policy

PHP Upgrade Preflight is a public beta while its public PHP API, CLI and Artisan contracts, adapter extension points, package boundaries, and report semantics are still being proven. Public beta is not a production-readiness claim. Analyzer output is decision-support evidence, not a guarantee that an upgraded application will run correctly or deploy successfully. See [Project status and licensing](project-status.md) and [Limitations and trust boundaries](limitations.md).

The currently published v0.2.x line preserves its documented public operation, CLI and Artisan behavior, adapter metadata, exit policy, schema `0.7` compatibility, and transition claims across patch releases. After v0.3.0 is published and verified, v0.3.x patch releases will preserve the corresponding required adapter interfaces, schema `0.8`, and staged-analysis claims.

The v0.3.0 release candidate prepares a new `0.MINOR` release. It introduces schema `0.8`, an optional stage-target provider, productionized sequential Composer results, and schema `1.0` partial or complete target-platform profiles. Historical v0.2 schemas and signed compatibility artifacts remain immutable, and every intentional migration is documented in the candidate release notes and migration guides.

## The `0.x` phase

The project will keep major version `0` while its public PHP API, CLI contract, package boundaries, and report semantics are still being proven. Before `1.0`:

- patch releases such as `0.1.1` contain backward-compatible bug fixes, security fixes, documentation corrections, test maintenance, and release/build changes;
- minor releases such as `0.2.0` contain backward-compatible features and any intentional breaking changes;
- every breaking change must still be called out prominently in the changelog and migration notes.

This follows the practical meaning of SemVer's initial-development rule: compatibility is not promised across `0.MINOR` lines. Composer constraints therefore keep project-package dependencies on the same minor line, currently `^0.3` on `main`.

## Active release line

Release automation on `main` is prepared for the candidate `0.3.x` release line and requires schema `0.8`, `0.3.x-dev` aliases, and `^0.3` internal constraints. The published and security-supported `0.2.x` line is retained on the `0.2.x` maintenance branch; v0.1.x remains on `0.1.x`. A maintenance release is prepared from its own branch and verifier policy, never by weakening the active `main` verifier.

The signed v0.1.0 release and its schema `0.6` artifacts remain immutable historical contracts. A security or maintenance release on the retired `0.1.x` line requires an explicit coordinated policy change on its maintenance branch; it is not prepared from `main` and does not weaken the archived compatibility checks.

## v0.2 release and v0.3 candidate identity

The v0.2.1 maintenance baseline identifies reports as tool `0.2.1` with schema `0.7`; v0.2.0 reports use the same schema. Root path repositories and package branch aliases on that line use `0.2.x-dev`, while internal package constraints use `^0.2`. Composer derives exact package releases from matching Git tags; package manifests do not declare a `version` field.

The v0.3.0 candidate identifies reports as tool `0.3.0` with schema `0.8`. The `main` branch aliases and root path versions remain `0.3.x-dev`, while internal constraints remain `^0.3`; those aliases describe Composer's development branch, not a published package version. Exact release versions will come from matching verified Git tags and never use a `-dev` suffix. Future v0.2 maintenance releases retain their historical schema and `0.2.x` policy on the maintenance branch.

## When to release `1.0`

Version `1.0.0` is an explicit stability commitment, not a calendar milestone. It is appropriate when the public PHP API, CLI behavior, package split, and supported schema policy are mature enough that future breaking changes can wait for a new major release.

A future PHP 9-only runtime could be part of that decision because dropping PHP 8 would be a breaking change, but PHP 9 does not automatically require project version `1.0`. If the project is still intentionally experimental, that runtime change may instead ship in a clearly documented later `0.MINOR` release.

After `1.0`, backward-compatible features increment minor, fixes increment patch, and breaking changes increment major.

## Release sources

Composer package versions come from Git tags; package manifests deliberately do not contain a `version` field. Release verification requires the following values to agree:

- `ReportMetadata::TOOL_VERSION`;
- the changelog release heading and release-notes filename;
- every `dev-main` branch alias and root path-repository development version;
- internal package constraints such as `php-upgrade-preflight/core:^0.3`;
- the report schema expected for the active release line (`0.8` for v0.3.x).

The monorepo GitHub release and the three distribution repositories must use the same `vMAJOR.MINOR.PATCH` tag.
