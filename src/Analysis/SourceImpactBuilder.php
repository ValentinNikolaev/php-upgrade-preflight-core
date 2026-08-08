<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Analysis;

use PhpUpgradePreflight\Core\Model\CompatibilityFinding;
use PhpUpgradePreflight\Core\Model\SourceImpactFinding;
use PhpUpgradePreflight\Core\Model\SourceUsage;

final class SourceImpactBuilder
{
    /**
     * @param list<SourceUsage> $inventory
     * @param list<CompatibilityFinding> $frameworkFindings
     * @return list<SourceImpactFinding>
     */
    public function build(array $inventory, array $frameworkFindings): array
    {
        $impact = [];

        foreach ($inventory as $usage) {
            $matchingFindings = array_values(array_filter(
                $frameworkFindings,
                static fn (CompatibilityFinding $finding): bool => array_intersect(
                    $usage->evidence(),
                    $finding->evidence()
                ) !== []
            ));

            if ($matchingFindings === []) {
                continue;
            }

            $frameworks = [];
            $evidence = $usage->evidence();
            $severity = 'low';
            foreach ($matchingFindings as $finding) {
                $frameworks[] = $finding->framework();
                $evidence = array_merge($evidence, $finding->evidence());
                if ($this->severityRank($finding->severity()) > $this->severityRank($severity)) {
                    $severity = $finding->severity();
                }
            }

            $frameworks = array_values(array_unique($frameworks));
            sort($frameworks, SORT_STRING);
            $impact[] = new SourceImpactFinding(
                null,
                'unknown',
                'framework_rule',
                sprintf(
                    'Referenced by active %s compatibility guidance; package ownership has not been established.',
                    implode(', ', $frameworks)
                ),
                $severity,
                [$usage],
                $evidence
            );
        }

        return $impact;
    }

    private function severityRank(string $severity): int
    {
        return ['low' => 1, 'medium' => 2, 'high' => 3][$severity] ?? 1;
    }
}
