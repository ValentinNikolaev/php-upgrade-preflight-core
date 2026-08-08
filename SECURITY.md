# Security policy

## Supported versions

The project accepts security fixes for the latest `0.1.x` release line. Development branches may change without a compatibility guarantee.

## Report a vulnerability

Use GitHub's private vulnerability reporting for this repository: [report a vulnerability](https://github.com/ValentinNikolaev/php-upgrade-preflight/security/advisories/new).

Include the affected version, operating system, PHP and Composer versions, reproduction steps, impact, and any proposed mitigation. Remove Composer credentials, private repository URLs, source code, and report evidence that you do not have permission to share.

Do not open a public issue before maintainers have assessed the report. The maintainer will acknowledge a complete report within five business days and coordinate disclosure after a fix or mitigation is available.

## Security model

PHP Upgrade Preflight runs Composer in temporary directories with scripts and plugins disabled. It still parses target-controlled Composer metadata and source code, and Composer may access declared repositories with credentials from the host environment.

Captured Composer output and CLI or Artisan failure diagnostics are redacted before storage or rendering. The redactor removes whole repository URLs, authorization payloads, named credential values, and common provider-token forms. Synthetic canaries gate JSON, Markdown, captured CI output, and release ZIP contents. This is a defense-in-depth boundary, not permission to use broad or long-lived credentials.

Run analysis of untrusted repositories in a disposable container or restricted account. Use scoped read-only credentials, limit network access, keep reports outside the target tree, and inspect debug workspaces before retaining or sharing them.
