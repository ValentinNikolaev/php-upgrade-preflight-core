# Project status and licensing

## Public beta

PHP Upgrade Preflight is a public beta. v0.3.3 is the latest published release, verified through signed tags in the monorepo and all three distribution repositories, checksum-bound archives, cross-host release checks, Packagist synchronization, and a published-package quick start. The public PHP API, CLI and Artisan surfaces, adapter extension points, package boundaries, and report semantics are still being proven before `1.0`.

Public beta is not a production-readiness claim. The analyzer produces decision-support evidence. It does not modify the target project, perform the upgrade, boot or execute the analyzed application, prove runtime compatibility, or guarantee a successful deployment. Users must review every report and validate any resulting upgrade with the application's own tests, runtime checks, security review, and deployment process.

## v0.3.x compatibility commitment

Within the released v0.3.x line, patch releases preserve:

- the public PHP operation;
- CLI and Artisan behavior;
- required adapter interfaces and discovery metadata;
- the documented exit policy;
- report schema `0.8` compatibility;
- supported framework-transition and staged-analysis claims.

Patch releases may correct findings, evidence, diagnostics, security behavior, or documentation while retaining those contracts. Published schemas, signed artifacts, and archived compatibility evidence remain immutable.

## v0.2.x compatibility commitment

Within the released v0.2.x line, patch releases preserve:

- the public PHP operation;
- CLI and Artisan behavior;
- adapter discovery metadata;
- the documented exit policy;
- report schema `0.7` compatibility;
- supported framework-transition claims.

Bug fixes, security fixes, dependency maintenance, evidence corrections, and documentation changes may change individual findings or diagnostics while preserving those contracts. Published schemas, signed artifacts, and archived compatibility evidence remain immutable.

v0.2.x is an archival compatibility line rather than an actively developed one. Its released artifacts stay installable from Packagist and its schema `0.7` evidence stays immutable, but now that the v0.3 line is published it receives no features, routine bug fixes, or security patches: fixes follow the latest published line, and the upgrade path is v0.3. Plan an upgrade rather than pinning `^0.2` indefinitely. v0.1.x is archival on the same terms.

## v0.3 change boundary

v0.3.0 is a new `0.MINOR` release under SemVer's initial-development phase. It introduced documented changes to request inputs, report shape, package constraints, and optional adapter extension points: schema `0.8`, explicit target-platform profiles, restricted Composer execution, and stage-scoped Composer evidence. Those features do not describe archived v0.2.x behavior.

The intentional v0.2→v0.3 changes are identified in the changelog, release notes, schema migration, and adapter migration guidance. Historical v0.2 schemas and signed compatibility artifacts remain available as immutable evidence rather than being rewritten for v0.3.

## Open Source licensing

PHP Upgrade Preflight is Open Source software under the [MIT License](../LICENSE). Commercial and noncommercial use, modification, and redistribution are permitted under the MIT terms; no separate commercial license exists or is required.

Releases up to and including v0.3.1 were published under the PolyForm Noncommercial License 1.0.0. Those signed artifacts remain immutable and stay governed by the license they shipped with. The MIT License applies to this repository and to every release published after v0.3.1.

This page describes the project's product and licensing position; it does not replace or modify the license. The license text controls if this summary and the license differ.
