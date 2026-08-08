# Limitations and trust boundaries

PHP Upgrade Preflight predicts dependency and source impact. It does not perform an upgrade or prove that the upgraded application works.

## Dependency resolution

- Composer resolves scenarios against the repositories, credentials, extensions, and network available to the analyzer process.
- Scripts and plugins stay disabled. A project that depends on plugin behavior may resolve differently in its normal environment.
- A successful candidate lock proves that Composer found one dependency solution. It does not prove runtime compatibility.
- Partial platform and staged probes provide ordering evidence. Only full-target scenarios determine combined feasibility.
- Composer output changes can reduce blocker parsing confidence. The report retains bounded, redacted excerpts and uncertainty. Redaction deliberately removes whole URLs and known credential forms, so diagnostic text may be less specific.

## Source inspection

- The scanner parses PHP syntax and reports supported static symbol and configuration references.
- It does not execute the application, resolve container bindings, evaluate dynamic class names, or infer string-built symbols.
- Parse errors skip the malformed file and add evidence-linked uncertainty.
- Default scans exclude dependencies and common generated, cache, and build directories. Explicit paths inside the project can opt into an excluded directory.

## Framework guidance

- v0.1 ships Laravel rules for conservative Laravel 7 to 8 or 9 analysis.
- Encoded package ranges and maintainer links identify review work; they do not replace each package's upgrade guide.
- Skeleton findings identify locations to compare and carry low confidence. They are not confirmed incompatibilities.
- Ambiguous framework target ranges produce less guidance instead of guessing a target major.

## Read-only boundary

The analysis pipeline copies the target manifest and lockfile, then scans source files without writing them. A project-local `composer require` still changes the project during installation. Shell redirection can also write inside the project before the analyzer can validate the destination.

Debug mode preserves temporary copies. Cleanup failures report the leaked workspace path for manual removal.

## Untrusted projects

The tool reads Composer metadata and PHP source from the target. Composer scripts and plugins stay disabled, but Composer may contact declared repositories and use host credentials. Analyze untrusted repositories inside a disposable container or restricted account with scoped credentials and network access.

Secret redaction is deterministic and covered by synthetic canaries, but no pattern-based filter can recognize every future credential format. Use least-privilege credentials, review reports before sharing them, and report an unredacted form through the private security channel.
