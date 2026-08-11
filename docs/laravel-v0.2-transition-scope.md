# Laravel v0.2 transition scope

This document records the approved Laravel transition rule packs for v0.2.0. The implementation retains Laravel 7 to 8/9 behavior and provides one gapless adjacent path from Laravel 8 through Laravel 13.

The machine-readable decision and its exact upstream evidence are in [`laravel-v0.2-transition-matrix.json`](../tests/fixtures/contracts/laravel-v0.2-transition-matrix.json). Every reviewed upstream file is pinned to the official Git commit observed on 2026-08-08 so later branch edits cannot silently change the basis for this scope.

## Approved matrix

| Source | Target | Target constraint | Minimum target PHP | v0.2 decision          |
|-------:|-------:|-------------------|--------------------|------------------------|
|      8 |      9 | `^9.0`            | 8.0.2              | Add adjacent rule pack |
|      9 |     10 | `^10.0`           | 8.1.0              | Add adjacent rule pack |
|     10 |     11 | `^11.0`           | 8.2.0              | Add adjacent rule pack |
|     11 |     12 | `^12.0`           | 8.2.0              | Add adjacent rule pack |
|     12 |     13 | `^13.0`           | 8.3.0              | Add adjacent rule pack |

Laravel 7 to 8 and Laravel 7 to 9 remain frozen v0.1 compatibility paths. The latter is a retained direct path, not an adjacent rule pack. Multi-major support and the separation between direct Composer feasibility and hop guidance are defined in the [v0.2 contract](v0.2-contract.md).

The Laravel adapter host range is `^8.0|^9.0|^10.0|^11.0|^12.0|^13.0` for `illuminate/console` and `illuminate/support`. Normal and lowest-dependency Laravel 13 application boots are part of the dependency-compatibility workflow.

## Evidence reviewed

The review used the official upgrade guide, `laravel/framework` package manifest, and `laravel/laravel` application manifest for every new target. The JSON contract preserves the complete guide dependency maps and the exact selected manifest constraints.

| Target | Official guide                                        | Framework PHP | Application framework | Application tests  | High-signal scope evidence                                       |
|-------:|-------------------------------------------------------|---------------|-----------------------|--------------------|------------------------------------------------------------------|
|      9 | [8.x to 9.x](https://laravel.com/docs/9.x/upgrade)    | `^8.0.2`      | `^9.19`               | PHPUnit `^9.5.10`  | Collision 6, Ignition replacement, Flysystem 3                   |
|     10 | [9.x to 10.x](https://laravel.com/docs/10.x/upgrade)  | `^8.1`        | `^10.10`              | PHPUnit `^10.1`    | Composer 2.2, Collision 7, PHPUnit 10, Ignition 2                |
|     11 | [10.x to 11.x](https://laravel.com/docs/11.x/upgrade) | `^8.2`        | `^11.31`              | PHPUnit `^11.0.1`  | curl 7.34, first-party package migrations, optional new skeleton |
|     12 | [11.x to 12.x](https://laravel.com/docs/12.x/upgrade) | `^8.2`        | `^12.0`               | PHPUnit `^11.5.50` | PHPUnit 11, Pest 3, Carbon 3                                     |
|     13 | [12.x to 13.x](https://laravel.com/docs/13.x/upgrade) | `^8.3`        | `^13.17`              | PHPUnit `^12.5.12` | Tinker 3, PHPUnit 12, Pest 4, request-forgery middleware changes |

The target PHP column is an analysis input and does not raise the analyzer packages' shared PHP `^8.0` runtime floor. A project-local Laravel 13 host naturally runs PHP 8.3 or newer; an external analyzer may continue to model that target through Composer platform simulation.

## Laravel 13 decision

Laravel 12 to 13 is included in v0.2.0 scope because all three evidence layers are available and agree on a concrete target:

- the official guide explicitly covers 12.x to 13.x and requires `laravel/framework:^13.0`;
- the exact framework and application manifests require PHP `^8.3`;
- the guide and application manifest provide concrete test-tool migrations, including PHPUnit 12, while the guide adds implementable package and source checks.

The implemented 12 to 13 pack is deliberately bounded to these sources. It covers the target PHP and framework constraints, Boost 2, Tinker 3, PHPUnit 12, Pest 4, the application skeleton's Collision constraint, component-specific direct Symfony constraints from the pinned framework manifest, the documented legacy helper conflict, and exact references to the renamed request-forgery middleware. Distinct Symfony patch floors such as HTTP Foundation 7.4.13/8.0.13 and Process 7.4.5/8.0.5 remain distinct catalog facts. It does not turn every guide paragraph into a speculative source heuristic.

The fixture contract in [`laravel-v0.2-transition-cases.json`](../tests/fixtures/contracts/laravel-v0.2-transition-cases.json) assigns separate feasible and advisory-heavy or blocked full-analyzer cases to every approved adjacent path. Every adjacent acceptance case runs real Composer with networking disabled against a committed local path repository; the synthetic runner remains limited to ambiguity, missing-hop, and multi-major guidance cases that do not claim ecosystem feasibility. Synthetic candidate locks also reject target PHP or selected package versions that violate root constraints. Every framework finding is checked for exact hop attribution plus E1-E4 evidence, and CLI and Artisan entry points must render equivalent canonical JSON for every listed case.
