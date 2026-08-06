<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Model;

final class EvidenceLedger
{
    /** @var array<string, Evidence> */
    private array $evidence = [];

    /** @var array<string, int> */
    private array $nextSequence = [];

    /** @param list<Evidence> $evidence */
    public function __construct(array $evidence = [])
    {
        foreach ($evidence as $item) {
            $this->register($item);
        }
    }

    /** @param array<string, mixed> $context */
    public function add(
        string $namespace,
        string $class,
        string $summary,
        string $confidence = 'high',
        array $context = []
    ): Evidence {
        $namespace = trim($namespace);

        if (preg_match('/^[a-z][a-z0-9_-]*$/', $namespace) !== 1) {
            throw new \InvalidArgumentException(sprintf('Evidence namespace "%s" is invalid.', $namespace));
        }

        $sequence = $this->nextSequence[$namespace] ?? 1;

        do {
            $id = $namespace . '-' . $sequence;
            ++$sequence;
        } while (isset($this->evidence[$id]));

        $this->nextSequence[$namespace] = $sequence;
        $evidence = new Evidence($id, $class, $summary, $confidence, $context);
        $this->evidence[$id] = $evidence;

        return $evidence;
    }

    public function register(Evidence $evidence): void
    {
        if (trim($evidence->id) === '') {
            throw new \InvalidArgumentException('Evidence IDs must not be empty.');
        }

        if (isset($this->evidence[$evidence->id])) {
            throw new \InvalidArgumentException(sprintf('Evidence ID "%s" is already registered.', $evidence->id));
        }

        $this->evidence[$evidence->id] = $evidence;
    }

    public function has(string $id): bool
    {
        return isset($this->evidence[$id]);
    }

    /** @return list<Evidence> */
    public function all(): array
    {
        return array_values($this->evidence);
    }

    /** @param list<string> $references */
    public function validateReferences(array $references): void
    {
        $referenced = [];

        foreach ($references as $reference) {
            if (!$this->has($reference)) {
                throw new \LogicException(sprintf('Evidence reference "%s" does not exist in the ledger.', $reference));
            }

            $referenced[$reference] = true;
        }

        $orphans = array_values(array_diff(array_keys($this->evidence), array_keys($referenced)));

        if ($orphans !== []) {
            throw new \LogicException(sprintf('Orphaned evidence is not allowed: %s.', implode(', ', $orphans)));
        }
    }
}
