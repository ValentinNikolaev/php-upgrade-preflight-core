<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Analysis;

use PhpUpgradePreflight\Core\Model\Blocker;
use PhpUpgradePreflight\Core\Model\ComposerLock;
use PhpUpgradePreflight\Core\Model\Evidence;
use PhpUpgradePreflight\Core\Model\EvidenceLedger;

final class AbandonedPackageDetector
{
    /**
     * @param array<string, string> $requestedConstraints
     * @return list<Blocker>
     */
    public function detect(ComposerLock $lock, EvidenceLedger $evidence, array $requestedConstraints = []): array
    {
        $packages = $lock->packages();
        ksort($packages, SORT_STRING);
        $blockers = [];

        foreach ($packages as $package) {
            if (!$package->isAbandoned()) {
                continue;
            }

            $alternative = $package->abandonedAlternative();
            $alternativeType = $package->abandonedAlternativeType();
            $replacementPackage = $package->replacementPackage();
            $evidenceId = $evidence->add(
                'lock-metadata',
                Evidence::E2_PACKAGE_METADATA,
                sprintf('Composer lock metadata marks %s as abandoned.', $package->name()),
                'high',
                [
                    'package' => $package->name(),
                    'locked_version' => $package->version(),
                    'direct' => $package->isDirect(),
                    'abandoned_alternative' => $alternative,
                    'abandoned_alternative_type' => $alternativeType,
                ]
            )->id();

            if ($replacementPackage !== null) {
                $options = [sprintf('Replace `%s` with `%s`.', $package->name(), $replacementPackage)];
            } elseif ($alternative !== null) {
                $options = [sprintf('Review the recommended alternative for `%s`: %s.', $package->name(), $alternative)];
            } else {
                $options = [sprintf('Replace or remove `%s`.', $package->name())];
            }

            $blockers[] = new Blocker(
                'abandoned-package',
                $package->name(),
                'Composer lock metadata marks this package as abandoned.',
                'high',
                [$evidenceId],
                $requestedConstraints[$package->name()] ?? null,
                null,
                $package->version(),
                null,
                [$package->name()],
                $options
            );
        }

        return $blockers;
    }
}
