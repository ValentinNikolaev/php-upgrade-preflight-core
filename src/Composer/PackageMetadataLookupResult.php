<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Composer;

use PhpUpgradePreflight\Core\Support\OutputExcerpt;

final class PackageMetadataLookupResult
{
    public const STATUS_INVALID = 'invalid';
    public const STATUS_FOUND = 'found';
    public const STATUS_NOT_FOUND = 'not_found';
    public const STATUS_UNVERIFIED = 'unverified';

    public const REASON_INVALID_PACKAGE = 'invalid_package';
    public const REASON_INVALID_CONSTRAINT = 'invalid_constraint';
    public const REASON_PACKAGE_FOUND = 'package_found';
    public const REASON_PACKAGE_NOT_FOUND = 'package_not_found';
    public const REASON_LOCAL_METADATA_UNAVAILABLE = 'local_metadata_unavailable';
    public const REASON_RESTRICTED_EXECUTION_UNAVAILABLE = 'restricted_execution_unavailable';
    public const REASON_PROCESS_TIMEOUT = 'process_timeout';
    public const REASON_PROCESS_FAILURE = 'process_failure';
    public const REASON_INVALID_PROJECT = 'invalid_project';
    public const REASON_MALFORMED_OUTPUT = 'malformed_output';
    public const REASON_OUTPUT_TOO_LARGE = 'output_too_large';

    private string $status;
    private string $reason;
    private string $package;
    private string $constraint;
    /** @var list<string> */
    private array $versions;
    /** @var list<string> */
    private array $matchingVersions;
    private int $availableVersionCount;
    private int $matchingVersionCount;
    private string $diagnostic;

    /**
     * @param list<string> $versions
     * @param list<string> $matchingVersions
     */
    public function __construct(
        string $status,
        string $reason,
        string $package,
        string $constraint,
        array $versions = [],
        array $matchingVersions = [],
        ?int $availableVersionCount = null,
        ?int $matchingVersionCount = null,
        string $diagnostic = ''
    ) {
        if (!in_array($status, [
            self::STATUS_INVALID,
            self::STATUS_FOUND,
            self::STATUS_NOT_FOUND,
            self::STATUS_UNVERIFIED,
        ], true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported package metadata lookup status "%s".', $status));
        }

        $availableVersionCount = $availableVersionCount ?? count($versions);
        $matchingVersionCount = $matchingVersionCount ?? count($matchingVersions);
        if ($availableVersionCount < count($versions) || $matchingVersionCount < count($matchingVersions)) {
            throw new \InvalidArgumentException('Package metadata version counts cannot be smaller than the retained version lists.');
        }
        if ($status !== self::STATUS_FOUND
            && ($versions !== [] || $matchingVersions !== [] || $availableVersionCount !== 0 || $matchingVersionCount !== 0)) {
            throw new \InvalidArgumentException('Only a found package may expose version metadata.');
        }

        $this->status = $status;
        $this->reason = $reason;
        $this->package = $package;
        $this->constraint = $constraint;
        $this->versions = array_values($versions);
        $this->matchingVersions = array_values($matchingVersions);
        $this->availableVersionCount = $availableVersionCount;
        $this->matchingVersionCount = $matchingVersionCount;
        $this->diagnostic = OutputExcerpt::bounded($diagnostic);
    }

    public function status(): string
    {
        return $this->status;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function package(): string
    {
        return $this->package;
    }

    public function constraint(): string
    {
        return $this->constraint;
    }

    /** @return list<string> */
    public function versions(): array
    {
        return $this->versions;
    }

    /** @return list<string> */
    public function matchingVersions(): array
    {
        return $this->matchingVersions;
    }

    public function availableVersionCount(): int
    {
        return $this->availableVersionCount;
    }

    public function matchingVersionCount(): int
    {
        return $this->matchingVersionCount;
    }

    public function hasMatchingVersion(): ?bool
    {
        return $this->status === self::STATUS_FOUND ? $this->matchingVersionCount > 0 : null;
    }

    public function diagnostic(): string
    {
        return $this->diagnostic;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'reason' => $this->reason,
            'package' => $this->package,
            'constraint' => $this->constraint,
            'versions' => $this->versions,
            'matching_versions' => $this->matchingVersions,
            'available_version_count' => $this->availableVersionCount,
            'matching_version_count' => $this->matchingVersionCount,
            'has_matching_version' => $this->hasMatchingVersion(),
            'versions_truncated' => $this->availableVersionCount > count($this->versions),
            'matching_versions_truncated' => $this->matchingVersionCount > count($this->matchingVersions),
            'diagnostic' => $this->diagnostic,
        ];
    }
}
