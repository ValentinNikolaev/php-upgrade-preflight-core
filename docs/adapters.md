# Framework adapters

The standalone CLI discovers framework adapters from Composer metadata. An adapter package does not require a CLI source change or a central registry entry.

## Package metadata

An installed adapter package declares one or more integration classes under `extra.php-upgrade-preflight.framework-adapters`:

```json
{
  "name": "vendor/example-adapter",
  "require": {
    "php-upgrade-preflight/core": "^0.2"
  },
  "autoload": {
    "psr-4": {
      "Vendor\\ExampleAdapter\\": "src/"
    }
  },
  "extra": {
    "php-upgrade-preflight": {
      "framework-adapters": [
        "Vendor\\ExampleAdapter\\ExampleFrameworkIntegration"
      ]
    }
  }
}
```

`framework-adapters` must be a nonempty JSON list of nonempty, fully qualified class-name strings. Every advertised class must be autoloadable, instantiable without constructor arguments, and implement `PhpUpgradePreflight\Core\Framework\FrameworkIntegration`. Its `name()` must return a nonempty adapter name.

The required interface supplies framework detection, compatibility rules, and default source paths. An integration may additionally implement `FrameworkTransitionProvider` to contribute transition guidance and `PackageFamilyClassifier` to classify changed packages into adapter-defined families.

Install the adapter in the same Composer project as `php-upgrade-preflight/cli`. Composer then supplies both its metadata and autoloader to `upgrade-intel`:

```bash
composer require php-upgrade-preflight/cli vendor/example-adapter
vendor/bin/upgrade-intel analyze --path=/work/app --target-php=8.2
```

## Discovery and activation

Discovery considers packages known to the running Composer installation. Packages that are not installed, or that do not declare the metadata key, do not register an adapter. Package names are processed in lexical order. The resulting integrations are ordered case-insensitively by adapter name, with the class name as the deterministic tie-breaker. Metadata declaration order therefore does not control cross-adapter execution; within one integration, compatibility rules retain the order returned by `rules()`.

With no `--framework` option, every discovered integration may inspect the target project and only integrations whose `detect()` result is positive become active. Explicit `--framework=NAME` selection is case-insensitive, activates only the requested installed adapters, and bypasses their automatic detection. Repeat the option to select multiple adapters.

Laravel keeps the same automatic behavior: the Laravel adapter detects `laravel/framework` or `illuminate/*` in the target project's root requirements or lock data. Its default source paths, rules, transition guidance, and package-family classification are unchanged by metadata-based registration.

## Invalid registrations and collisions

Registration is fail-fast. The CLI does not choose a winner for an ambiguous or broken installation.

- Repeating the same integration class, including a case-only variant, is an error.
- Two classes returning the same adapter name, including case-only variants, are an error.
- Malformed metadata, an advertised class that cannot be autoloaded, a non-instantiable class, a constructor that requires arguments, a class that does not implement `FrameworkIntegration`, or a blank adapter name is an installation/configuration error. Analysis stops rather than silently omitting that adapter.
- A package that is not installed is simply absent from discovery. If its adapter name is explicitly requested, the request fails as unavailable.

An unavailable explicit `--framework` value is an invalid invocation: the CLI writes a diagnostic naming the unavailable adapter and returns exit code `2`. Remove the option or install its adapter package in the CLI's Composer project. Discovery or registration defects are operational failures and return exit code `1` because the CLI cannot safely construct the analyzer.

## CLI and Artisan

Composer metadata generalizes standalone CLI registration; it does not replace Laravel package discovery. Installing `php-upgrade-preflight/laravel` still registers `upgrade:analyze` through its Laravel service provider, and that command still enables the Laravel integration directly. CLI and Artisan use the same analyzer pipeline, Laravel integration, request semantics, source-path defaults, report writers, and exit policy. The entry-point parity suite verifies equivalent canonical reports.

## Regression fixture

The repository's test-only `php-upgrade-preflight/test-adapter` package is deliberately outside CLI source. Its Composer metadata is the only production registration path. The `third-party-adapter` fixture proves automatic package detection, its `modules` default source path, a compatibility rule, and `test-vendor/*` package-family classification in a complete CLI analysis. It is test infrastructure and is not part of the published v0.2 package set.
