<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Framework;

use PhpUpgradePreflight\Core\Model\ProjectState;

interface FrameworkIntegration
{
    public function name(): string;

    public function detect(ProjectState $project): FrameworkDetection;

    /** @return iterable<CompatibilityRule> */
    public function rules(): iterable;

    /** @return list<string> */
    public function defaultSourcePaths(ProjectState $project): array;
}
