<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Progress;

final class AnalysisPhase
{
    public const PROJECT_LOADING = 'project-loading';
    public const COMPOSER_FEASIBILITY = 'composer-feasibility';
    public const STAGED_RESOLUTION = 'staged-resolution';
    public const SOURCE_SCAN = 'source-scan';
    public const FRAMEWORK_EVALUATION = 'framework-evaluation';
    public const REPORT_ASSEMBLY = 'report-assembly';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::PROJECT_LOADING,
            self::COMPOSER_FEASIBILITY,
            self::STAGED_RESOLUTION,
            self::SOURCE_SCAN,
            self::FRAMEWORK_EVALUATION,
            self::REPORT_ASSEMBLY,
        ];
    }

    public static function assertKnown(string $phase): void
    {
        if (!in_array($phase, self::all(), true)) {
            throw new \InvalidArgumentException(sprintf('Unknown analysis phase "%s".', $phase));
        }
    }
}
