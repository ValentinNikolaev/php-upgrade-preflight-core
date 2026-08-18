<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Analysis;

use PhpUpgradePreflight\Core\Model\Blocker;
use PhpUpgradePreflight\Core\Model\StageBlockerEntry;

/**
 * The lifecycle record of every blocker observed while a staged chain runs.
 *
 * One entry exists per blocker identity. Repeated observations extend that
 * entry's history instead of duplicating it, a changed constraint supersedes the
 * earlier identity, and an attempt that produced feasibility evidence resolves
 * the entries it no longer reports.
 *
 * Insertion order is the serialized order of the report's blocker registry, so
 * entries are keyed by identity and their first-seen order is tracked separately.
 */
final class StageBlockerRegistry
{
    /** @var array<string, StageBlockerEntry> */
    private array $entries = [];
    /** @var list<string> */
    private array $order = [];

    /**
     * @param list<Blocker> $blockers
     * @return list<string> the registry ids observed by this attempt
     */
    public function observe(
        string $stageId,
        int $attempt,
        string $scenario,
        array $blockers,
        string $attemptEvidence,
        bool $attemptProducedFeasibilityEvidence
    ): array {
        $observed = [];

        foreach ($blockers as $blocker) {
            $candidate = StageBlockerEntry::detected($stageId, $attempt, $scenario, $blocker, [$attemptEvidence]);
            $identity = $candidate->identityKey();
            $observed[$identity] = true;

            if (isset($this->entries[$identity])) {
                $this->entries[$identity] = $this->entries[$identity]->isActive()
                    ? $this->entries[$identity]->withObservation($attempt, $scenario, $blocker)
                    : $this->entries[$identity]->withReappearance($attempt, $scenario, $blocker);
                continue;
            }

            foreach ($this->entries as $existingIdentity => $existing) {
                if ($existingIdentity === $identity
                    || !$existing->isActive()
                    || $existing->supersessionKey() !== $candidate->supersessionKey()) {
                    continue;
                }
                $this->entries[$existingIdentity] = $existing->withLifecycle(
                    StageBlockerEntry::SUPERSEDED,
                    $attempt,
                    $scenario,
                    [$attemptEvidence]
                );
            }

            $this->entries[$identity] = $candidate;
            $this->order[] = $identity;
        }

        if ($attemptProducedFeasibilityEvidence) {
            foreach ($this->entries as $identity => $entry) {
                if (!$entry->isActive() || $entry->stageId() !== $stageId) {
                    continue;
                }
                if (isset($observed[$identity])) {
                    continue;
                }
                $this->entries[$identity] = $entry->withLifecycle(
                    StageBlockerEntry::RESOLVED,
                    $attempt,
                    $scenario,
                    [$attemptEvidence]
                );
            }
        }

        $ids = [];
        foreach ($observed as $identity => $_) {
            $ids[] = $this->entries[$identity]->id();
        }

        return $ids;
    }

    public function hasActiveBlocking(string $stageId): bool
    {
        foreach ($this->entries as $entry) {
            if ($entry->stageId() === $stageId && $entry->isActive() && $entry->isBlocking()) {
                return true;
            }
        }

        return false;
    }

    /** @return list<StageBlockerEntry> */
    public function ordered(): array
    {
        return array_map(
            fn (string $identity): StageBlockerEntry => $this->entries[$identity],
            $this->order
        );
    }
}
