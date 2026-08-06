<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Framework;

interface PackageFamilyClassifier
{
    /** @return list<string> */
    public function packageFamilies(string $packageName): array;
}
