# Versioning

PHP Upgrade Preflight uses Semantic Versioning for tool and package releases, with all three packages released in lockstep. Report schemas have their own version and compatibility policy described in [Schema and compatibility](schema.md).

## The `0.x` phase

The project will keep major version `0` while its public PHP API, CLI contract, package boundaries, and report semantics are still being proven. Before `1.0`:

- patch releases such as `0.1.1` contain backward-compatible bug fixes, security fixes, documentation corrections, test maintenance, and release/build changes;
- minor releases such as `0.2.0` contain backward-compatible features and any intentional breaking changes;
- every breaking change must still be called out prominently in the changelog and migration notes.

This follows the practical meaning of SemVer's initial-development rule: compatibility is not promised across `0.MINOR` lines. Composer constraints therefore keep project-package dependencies on the same minor line, currently `^0.2`.

## Active release line

Release automation is enabled for the active `0.2.x` release line. The v0.2.0 release candidate was approved as the coordinated minor release that introduces schema `0.7`, expanded Laravel transition guidance, platform provenance, and actionable source-impact semantics. The verifier rejects `0.1.x`, `0.3.x`, `1.x`, and every other series even when their files are otherwise internally consistent.

The signed v0.1.0 release and its schema `0.6` artifacts remain immutable historical contracts. A security or maintenance release on the retired `0.1.x` line requires an explicit coordinated policy change on its maintenance branch; it is not prepared from `main` and does not weaken the archived compatibility checks.

## v0.2 release and development identity

The current v0.2.1 release identifies reports as tool `0.2.1` with schema `0.7`; v0.2.0 reports use the same schema. Root path repositories and package branch aliases use `0.2.x-dev`, while internal package constraints use `^0.2`. Composer derives exact package releases from matching Git tags; package manifests do not declare a `version` field.

The branch aliases describe Composer's `dev-main` line, not the version embedded in release reports. Future v0.2 patch releases update the exact tool version, dated changelog entry, and release notes together while retaining `0.2.x-dev` aliases and `^0.2` internal constraints. Exact release tags never use a `-dev` suffix.

## When to release `1.0`

Version `1.0.0` is an explicit stability commitment, not a calendar milestone. It is appropriate when the public PHP API, CLI behavior, package split, and supported schema policy are mature enough that future breaking changes can wait for a new major release.

A future PHP 9-only runtime could be part of that decision because dropping PHP 8 would be a breaking change, but PHP 9 does not automatically require project version `1.0`. If the project is still intentionally experimental, that runtime change may instead ship in a clearly documented later `0.MINOR` release.

After `1.0`, backward-compatible features increment minor, fixes increment patch, and breaking changes increment major.

## Release sources

Composer package versions come from Git tags; package manifests deliberately do not contain a `version` field. Release verification requires the following values to agree:

- `ReportMetadata::TOOL_VERSION`;
- the changelog release heading and release-notes filename;
- every `dev-main` branch alias and root path-repository development version;
- internal package constraints such as `php-upgrade-preflight/core:^0.2`;
- the report schema expected for the active release line (`0.7` for v0.2.x).

The monorepo GitHub release and the three distribution repositories must use the same `vMAJOR.MINOR.PATCH` tag.
