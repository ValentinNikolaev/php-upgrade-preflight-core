<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Source;

/** Finite operational limits for project source discovery, reading, and retention. */
final class SourceScanLimits
{
    public const DEFAULT_MAX_FILES = 10000;
    public const DEFAULT_MAX_FILE_BYTES = 2097152;
    public const DEFAULT_MAX_TOTAL_BYTES = 67108864;
    public const DEFAULT_MAX_USAGES = 10000;

    private int $maxFiles;
    private int $maxFileBytes;
    private int $maxTotalBytes;
    private int $maxUsages;

    public function __construct(
        int $maxFiles = self::DEFAULT_MAX_FILES,
        int $maxFileBytes = self::DEFAULT_MAX_FILE_BYTES,
        int $maxTotalBytes = self::DEFAULT_MAX_TOTAL_BYTES,
        int $maxUsages = self::DEFAULT_MAX_USAGES
    ) {
        foreach (compact('maxFiles', 'maxFileBytes', 'maxTotalBytes', 'maxUsages') as $name => $value) {
            if ($value < 1) {
                throw new \InvalidArgumentException(sprintf('Source scan %s must be a positive integer.', $name));
            }
        }

        $this->maxFiles = $maxFiles;
        $this->maxFileBytes = $maxFileBytes;
        $this->maxTotalBytes = $maxTotalBytes;
        $this->maxUsages = $maxUsages;
    }

    public function maxFiles(): int
    {
        return $this->maxFiles;
    }

    public function maxFileBytes(): int
    {
        return $this->maxFileBytes;
    }

    public function maxTotalBytes(): int
    {
        return $this->maxTotalBytes;
    }

    public function maxUsages(): int
    {
        return $this->maxUsages;
    }
}
