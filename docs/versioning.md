# Versioning

PHP Upgrade Preflight uses Semantic Versioning for tool and package releases, with all three packages released in lockstep. Report schemas have their own version and compatibility policy described in [Schema and compatibility](schema.md).

## The `0.x` phase

The project will keep major version `0` while its public PHP API, CLI contract, package boundaries, and report semantics are still being proven. Before `1.0`:

- patch releases such as `0.1.1` contain backward-compatible bug fixes, security fixes, documentation corrections, test maintenance, and release/build changes;
- minor releases such as `0.2.0` contain backward-compatible features and any intentional breaking changes;
- every breaking change must still be called out prominently in the changelog and migration notes.

This follows the practical meaning of SemVer's initial-development rule: compatibility is not promised across `0.MINOR` lines. Composer constraints therefore keep project-package dependencies on the same minor line, for example `^0.1`.

## Temporary patch-only release lock

Release automation is currently locked to the `0.1.x` line. Until the v0.2.0 release candidate is explicitly approved, every maintenance release must increment only the patch component; `0.2.0`, `1.0.0`, and any other release series fail the release metadata gate even if their files are otherwise internally consistent.

This lock does not cancel the v0.2.0 roadmap. Unlocking `0.2.0` is an intentional release-policy change made together with its approved contract, version metadata, changelog, release notes, package constraints, and tag plan.

## When to release `1.0`

Version `1.0.0` is an explicit stability commitment, not a calendar milestone. It is appropriate when the public PHP API, CLI behavior, package split, and supported schema policy are mature enough that future breaking changes can wait for a new major release.

A future PHP 9-only runtime could be part of that decision because dropping PHP 8 would be a breaking change, but PHP 9 does not automatically require project version `1.0`. If the project is still intentionally experimental, that runtime change may instead ship in a clearly documented later `0.MINOR` release.

After `1.0`, backward-compatible features increment minor, fixes increment patch, and breaking changes increment major.

## Release sources

Composer package versions come from Git tags; package manifests deliberately do not contain a `version` field. Release verification requires the following values to agree:

- `ReportMetadata::TOOL_VERSION`;
- the changelog release heading and release-notes filename;
- every `dev-main` branch alias and root path-repository development version;
- internal package constraints such as `php-upgrade-preflight/core:^0.1`.

The monorepo GitHub release and the three distribution repositories must use the same `vMAJOR.MINOR.PATCH` tag.
