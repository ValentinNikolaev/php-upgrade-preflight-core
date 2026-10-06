<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Model;

/**
 * The narrow evidence-writing surface an analysis phase or framework rule needs.
 *
 * EvidenceLedger additionally offers registration, enumeration, and reference
 * validation. Collaborators that only record evidence should depend on this
 * interface so ledger ownership stays with the analyzer.
 */
interface EvidenceRecorder
{
    /** @param array<string, mixed> $context */
    public function add(
        string $namespace,
        string $class,
        string $summary,
        string $confidence = Confidence::HIGH,
        array $context = []
    ): Evidence;

    /** @param array<string, mixed> $context */
    public function addOnce(
        string $namespace,
        string $class,
        string $summary,
        string $confidence = Confidence::HIGH,
        array $context = []
    ): Evidence;
}
