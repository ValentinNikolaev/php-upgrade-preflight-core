# Project status and licensing

## Public beta

PHP Upgrade Preflight is a public beta. v0.2.1 is the latest published release. v0.3.0 is a locally verified release candidate whose signed tags, archives, cross-host release checks, Packagist synchronization, and published-package quick start are still pending. The public PHP API, CLI and Artisan surfaces, adapter extension points, package boundaries, and report semantics are still being proven before `1.0`.

Public beta is not a production-readiness claim. The analyzer produces decision-support evidence. It does not modify the target project, perform the upgrade, boot or execute the analyzed application, prove runtime compatibility, or guarantee a successful deployment. Users must review every report and validate any resulting upgrade with the application's own tests, runtime checks, security review, and deployment process.

## Planned v0.3.x compatibility commitment

After v0.3.0 is published and verified, patch releases in the v0.3.x line will preserve:

- the public PHP operation;
- CLI and Artisan behavior;
- required adapter interfaces and discovery metadata;
- the documented exit policy;
- report schema `0.8` compatibility;
- supported framework-transition and staged-analysis claims.

Patch releases may correct findings, evidence, diagnostics, security behavior, or documentation while retaining those contracts. This commitment does not describe an already published v0.3 package. Published schemas, signed artifacts, and archived compatibility evidence remain immutable.

## v0.2.x compatibility commitment

Within the released v0.2.x line, patch releases preserve:

- the public PHP operation;
- CLI and Artisan behavior;
- adapter discovery metadata;
- the documented exit policy;
- report schema `0.7` compatibility;
- supported framework-transition claims.

Bug fixes, security fixes, dependency maintenance, evidence corrections, and documentation changes may change individual findings or diagnostics while preserving those contracts. Published schemas, signed artifacts, and archived compatibility evidence remain immutable.

v0.2.x is an archival compatibility line rather than an actively developed one. Its released artifacts stay installable from Packagist and its schema `0.7` evidence stays immutable, but the line receives no new features. Once v0.3.0 is published, expect no routine bug fixes or security patches on v0.2.x either: fixes follow the latest published line, and the upgrade path is v0.3. Plan an upgrade rather than pinning `^0.2` indefinitely. v0.1.x is already archival on the same terms.

## v0.3 change boundary

The v0.3.0 release candidate prepares a new `0.MINOR` release during SemVer's initial-development phase. It introduces documented changes to request inputs, report shape, package constraints, and optional adapter extension points: schema `0.8`, explicit target-platform profiles, restricted Composer execution, and stage-scoped Composer evidence. Those features do not describe the currently published v0.2.x behavior.

The intentional v0.2→v0.3 changes are identified in the changelog, release notes, schema migration, and adapter migration guidance. Historical v0.2 schemas and signed compatibility artifacts remain available as immutable evidence rather than being rewritten for v0.3.

## Source-available licensing

PHP Upgrade Preflight is source-available software. It is not distributed or described as Open Source.

The [PolyForm Noncommercial License 1.0.0](../LICENSE) permits the uses defined there as noncommercial without a commercial license fee. Commercial use is not permitted under that license and requires a separate license from the copyright holder. Use the [commercial license request form](https://docs.google.com/forms/d/e/1FAIpQLSfUlJJnSoqgUuJnKUCGzQQpIeXZtz471iD_XiPTjdnODbooYw/viewform) to request licensing terms. Submitting the form does not grant a license or authorize commercial use.

This page describes the project's product and licensing position; it does not replace or modify the license. The license text controls if this summary and the license differ.
