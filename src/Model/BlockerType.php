<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Model;

/**
 * Owns the blocker type vocabulary: which types exist, whether each one blocks
 * resolution, and the generic guidance emitted for it.
 *
 * Types are registered here once. Analyzers resolve a type through
 * {@see self::fromString()}, which rejects unregistered values instead of
 * silently falling back to placeholder guidance.
 */
final class BlockerType
{
    public const PHP_PLATFORM_TOO_LOW = 'php-platform-too-low';
    public const PHP_PLATFORM_TOO_HIGH = 'php-platform-too-high';
    public const ROOT_CONSTRAINT_CONFLICT = 'root-constraint-conflict';
    public const TRANSITIVE_PACKAGE_CONFLICT = 'transitive-package-conflict';
    public const EXTENSION_MISSING = 'extension-missing';
    public const EXTENSION_VERSION_INCOMPATIBLE = 'extension-version-incompatible';
    public const EXTENSION_VERSION_UNKNOWN = 'extension-version-unknown';
    public const PACKAGE_NOT_FOUND = 'package-not-found';
    public const MINIMUM_STABILITY_CONFLICT = 'minimum-stability-conflict';
    public const REPLACE_PROVIDE_CONFLICT = 'replace-provide-conflict';
    public const UNKNOWN_COMPOSER_FAILURE = 'unknown-composer-failure';
    public const ABANDONED_PACKAGE = 'abandoned-package';

    /**
     * `{subject}` expands to the blocker subject and `{blocker}` to the blocking
     * package. A null summary means the type carries evidence-specific text that
     * only the detecting analyzer can write.
     */
    private const DEFINITIONS = [
        self::PHP_PLATFORM_TOO_LOW => [
            'blocks_resolution' => true,
            'summary' => 'The requested PHP platform is lower than a package requirement.',
            'options' => [
                'Raise the target PHP version.',
                'Select a version of `{blocker}` compatible with the target PHP.',
            ],
        ],
        self::PHP_PLATFORM_TOO_HIGH => [
            'blocks_resolution' => true,
            'summary' => 'The requested PHP platform is higher than a package supports.',
            'options' => [
                'Upgrade or replace `{blocker}` with a version that supports the target PHP.',
                'Select a supported PHP target.',
            ],
        ],
        self::ROOT_CONSTRAINT_CONFLICT => [
            'blocks_resolution' => true,
            'summary' => 'A root Composer constraint conflicts with the requested target.',
            'options' => [
                'Update the root constraint for `{subject}`.',
                'Choose a target compatible with the existing root constraint.',
            ],
        ],
        self::TRANSITIVE_PACKAGE_CONFLICT => [
            'blocks_resolution' => true,
            'summary' => 'A transitive package constraint blocks the requested target.',
            'options' => [
                'Upgrade or replace `{blocker}`.',
                'Choose a `{subject}` version compatible with the transitive constraint.',
            ],
        ],
        self::EXTENSION_MISSING => [
            'blocks_resolution' => true,
            'summary' => 'A required PHP extension is unavailable.',
            'options' => [
                'Install and enable `{subject}` for the target runtime.',
                'Choose package versions that do not require `{subject}`.',
            ],
        ],
        self::EXTENSION_VERSION_INCOMPATIBLE => [
            'blocks_resolution' => true,
            'summary' => 'The modeled PHP extension version does not satisfy a package requirement.',
            'options' => [
                'Use a target version of `{subject}` that satisfies the reported constraint.',
                'Choose package versions compatible with the modeled `{subject}` version.',
            ],
        ],
        self::EXTENSION_VERSION_UNKNOWN => [
            'blocks_resolution' => false,
            'summary' => 'The assumed extension is present, but its version compatibility is unknown.',
            'options' => [
                'Repeat the analysis with an exact version for `{subject}`.',
                'Verify `{subject}` constraints on the target runtime.',
            ],
        ],
        self::PACKAGE_NOT_FOUND => [
            'blocks_resolution' => true,
            'summary' => 'Composer could not find the requested package or version.',
            'options' => [
                'Verify the package name, constraint, and repositories for `{subject}`.',
                'Choose an available package version.',
            ],
        ],
        self::MINIMUM_STABILITY_CONFLICT => [
            'blocks_resolution' => true,
            'summary' => 'The requested package does not satisfy the project minimum stability.',
            'options' => [
                'Choose a release allowed by the project minimum stability.',
                'Explicitly allow the required stability only after reviewing the package.',
            ],
        ],
        self::REPLACE_PROVIDE_CONFLICT => [
            'blocks_resolution' => true,
            'summary' => 'Composer found conflicting replace, provide, or conflict rules.',
            'options' => [
                'Remove or replace `{blocker}`.',
                'Choose versions whose replace/provide rules can coexist.',
            ],
        ],
        self::UNKNOWN_COMPOSER_FAILURE => [
            'blocks_resolution' => true,
            'summary' => 'Composer failed, but the blocker type could not be classified.',
            'options' => [
                'Inspect the linked Composer evidence.',
                'Run `composer prohibits {subject} <constraint> --tree` in an isolated copy.',
            ],
        ],
        self::ABANDONED_PACKAGE => [
            'blocks_resolution' => false,
            'summary' => null,
            'options' => [],
        ],
    ];

    private const UNKNOWN_BLOCKING_PACKAGE = 'the blocking package';

    private string $value;
    private bool $blocksResolution;
    private ?string $summary;
    /** @var list<string> */
    private array $optionTemplates;

    /** @param list<string> $optionTemplates */
    private function __construct(string $value, bool $blocksResolution, ?string $summary, array $optionTemplates)
    {
        $this->value = $value;
        $this->blocksResolution = $blocksResolution;
        $this->summary = $summary;
        $this->optionTemplates = $optionTemplates;
    }

    public static function fromString(string $type): self
    {
        $definition = self::DEFINITIONS[$type] ?? null;
        if ($definition === null) {
            throw new \InvalidArgumentException(sprintf('Unsupported blocker type "%s".', $type));
        }

        return new self(
            $type,
            $definition['blocks_resolution'],
            $definition['summary'],
            $definition['options']
        );
    }

    public static function isSupported(string $type): bool
    {
        return isset(self::DEFINITIONS[$type]);
    }

    /** @return list<string> */
    public static function supportedTypes(): array
    {
        return array_keys(self::DEFINITIONS);
    }

    /**
     * Unregistered types are reported as blocking so that an unmodelled Composer
     * failure can never quietly downgrade the report verdict.
     */
    public static function blocksResolutionFor(string $type): bool
    {
        return !self::isSupported($type) || self::fromString($type)->blocksResolution();
    }

    public function value(): string
    {
        return $this->value;
    }

    public function blocksResolution(): bool
    {
        return $this->blocksResolution;
    }

    public function summary(): string
    {
        $summary = $this->summary;
        if ($summary === null) {
            throw $this->evidenceSpecificGuidance();
        }

        return $summary;
    }

    /** @return list<string> */
    public function options(string $subject, ?string $blockingPackage = null): array
    {
        // A null summary marks the types whose guidance only the detecting analyzer can write.
        if ($this->summary === null) {
            throw $this->evidenceSpecificGuidance();
        }

        $replacements = [
            '{subject}' => $subject,
            '{blocker}' => $blockingPackage ?? self::UNKNOWN_BLOCKING_PACKAGE,
        ];

        return array_values(array_map(
            static fn (string $template): string => strtr($template, $replacements),
            $this->optionTemplates
        ));
    }

    private function evidenceSpecificGuidance(): \LogicException
    {
        return new \LogicException(sprintf(
            'Blocker type "%s" carries evidence-specific guidance that the detecting analyzer must supply.',
            $this->value
        ));
    }
}
