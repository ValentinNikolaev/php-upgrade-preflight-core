# Composer execution policy

Every schema 0.8 report records the Composer executable selection mode, detected version, expected version constraint, timeout policy, environment and network modes, repository-source mode, global-state inheritance, and disabled side effects under `composer_execution`. Exact executable paths and environment values are never serialized.

## Compatible mode

`compatible` is the default. It preserves the behavior needed by projects whose private repositories depend on the analyzer host's Composer configuration, credentials, proxy settings, cache, Git or SSH setup, and network access. Reports label that inheritance explicitly. Solver evidence from this mode depends on that host state and should not be described as cross-host reproducible.

## Restricted mode

`restricted` creates fresh analyzer-owned Composer home, cache, and XDG directories inside each temporary scenario workspace. It writes empty `config.json` and `auth.json` files, sets `COMPOSER_AUTH` to an empty object, removes the standard upper- and lowercase HTTP proxy variables plus Git/SSH askpass variables from the child environment, disables terminal prompts, and sets `COMPOSER_DISABLE_NETWORK=1` to request Composer's best-effort offline behavior.

The controlled sources are:

- Composer global `config.json` and `auth.json`;
- `COMPOSER_HOME`, `COMPOSER_AUTH`, and the Composer cache directory;
- XDG config, data, and cache roots;
- `HTTP_PROXY`, `HTTPS_PROXY`, `ALL_PROXY`, and `NO_PROXY`, including lowercase variants;
- Git and SSH askpass environment variables and Git terminal prompting.

Restricted mode does not remove repository URLs or credentials embedded in the analyzed `composer.json`; those are project input and remain available inside the temporary workspace. Use a sanitized manifest when that distinction matters.

The residual boundaries are the user-selected Composer executable, Composer and helper executable behavior, Git/SSH helper subprocesses, system trust stores, repository data already present in project input, and OS-level process and network isolation. `COMPOSER_DISABLE_NETWORK=1` is Composer's best-effort switch, not a firewall. Run the analyzer in a separately restricted account, container, or network sandbox when these boundaries are unacceptable.

Scripts and plugins are disabled in both modes. Analysis also disables installation, audit, interaction, and progress output. A project that requires plugin behavior can therefore resolve differently from its normal installation.

If restricted mode cannot obtain repository metadata from its fresh offline cache, the scenario outcome is `repository_metadata_unavailable`. That is operational uncertainty and leaves resolution unknown; it is not evidence that the requested dependency set is incompatible.

## Version and timeout policy

The default expected Composer range is `>=2.0.0 <3.0.0`, the scenario timeout is 300 seconds, and the diagnostic timeout is 60 seconds. A detected executable outside the configured range stops before creating a scenario workspace. A missing executable and a timeout remain distinct structured outcomes. Complete target-platform profiles still independently require Composer 2.2 or newer.
