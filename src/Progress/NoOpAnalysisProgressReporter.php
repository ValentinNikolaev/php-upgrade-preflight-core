<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Progress;

final class NoOpAnalysisProgressReporter implements AnalysisProgressReporter
{
    public function report(AnalysisProgressEvent $event): void
    {
    }
}
