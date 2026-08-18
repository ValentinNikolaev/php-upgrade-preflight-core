# Security policy

## Supported versions

The project accepts security fixes for the latest published `0.3.x` release line. The `0.2.x` and `0.1.x` lines are archival: their signed artifacts remain available for historical compatibility verification, and they receive no further fixes, security fixes included. Upgrade to `^0.3` rather than pinning an archival line. Development branches may change without a compatibility guarantee.

## Report a vulnerability

Use GitHub's private vulnerability reporting for this repository: [report a vulnerability](https://github.com/ValentinNikolaev/php-upgrade-preflight/security/advisories/new).

Include the affected version, operating system, PHP and Composer versions, reproduction steps, impact, and any proposed mitigation. Remove Composer credentials, private repository URLs, source code, and report evidence that you do not have permission to share.

Do not open a public issue before maintainers have assessed the report. The maintainer will acknowledge a complete report within five business days and coordinate disclosure after a fix or mitigation is available.

## Security model

PHP Upgrade Preflight runs Composer in temporary directories with scripts and plugins disabled. It still parses target-controlled Composer metadata and source code. Compatible mode may access declared repositories with credentials from the host environment. Restricted mode uses analyzer-owned Composer configuration, scrubs the controlled credential and proxy environment, and requests best-effort offline behavior, but it is not an OS process or network sandbox.

Captured Composer output and CLI or Artisan failure diagnostics are redacted before storage or rendering. The redactor removes whole repository URLs, authorization payloads, named credential values, and common provider-token forms. Synthetic canaries gate JSON, Markdown, captured CI output, and release ZIP contents. This is a defense-in-depth boundary, not permission to use broad or long-lived credentials.

Default canonical JSON and Markdown replace absolute local roots with `[PROJECT_ROOT]`, `[REPORT_OUTPUT]`, `[LOCAL_REPOSITORY]`, and `[ANALYZER_WORKSPACE]`. Exact project and source paths remain available for internal filesystem access, while source file locations in reports remain project-relative. Default cleanup failures expose only `[ANALYZER_WORKSPACE]`.

Exact `temp_path` values are exposed only with explicit `--debug`. Debug reports and retained workspaces are non-shareable artifacts. Credentials remain redacted even in debug mode.

Run analysis of untrusted repositories in a disposable container or restricted account. Use scoped read-only credentials, limit network access, keep reports outside the target tree, and do not retain debug artifacts longer than necessary.
