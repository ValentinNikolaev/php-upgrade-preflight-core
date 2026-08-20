<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Progress;

interface AnalysisProgressReporter
{
    /** Analyzer entry points contain reporter failures so observation cannot change analysis behavior. */
    public function report(AnalysisProgressEvent $event): void;
}
