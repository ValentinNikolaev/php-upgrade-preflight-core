<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Model;

final class EvidenceLedger implements EvidenceRecorder
{
    /** @var array<string, Evidence> */
    private array $evidence = [];

    /** @var array<string, int> */
    private array $nextSequence = [];

    /**
     * Registered evidence bucketed by the stored (already redacted) content that
     * addOnce() compares against, so deduplication does not rescan the ledger.
     *
     * @var array<string, list<Evidence>>
     */
    private array $contentIndex = [];

    /**
     * False once any registered evidence could not be keyed, which forces
     * addOnce() back to the exhaustive scan so its decision cannot change.
     */
    private bool $contentIndexComplete = true;

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
        string $confidence = Confidence::HIGH,
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
        $this->store($evidence);

        return $evidence;
    }

    /** @param array<string, mixed> $context */
    public function addOnce(
        string $namespace,
        string $class,
        string $summary,
        string $confidence = Confidence::HIGH,
        array $context = []
    ): Evidence {
        $key = self::contentKey($class, $summary, $confidence, $context);
        $candidates = $this->contentIndexComplete && $key !== null
            ? ($this->contentIndex[$key] ?? [])
            : $this->evidence;

        foreach ($candidates as $item) {
            if ($item->evidenceClass() === $class
                && $item->summary() === $summary
                && $item->confidence() === $confidence
                && $item->context() === $context
                && str_starts_with($item->id(), $namespace . '-')) {
                return $item;
            }
        }

        return $this->add($namespace, $class, $summary, $confidence, $context);
    }

    public function register(Evidence $evidence): void
    {
        if (trim($evidence->id()) === '') {
            throw new \InvalidArgumentException('Evidence IDs must not be empty.');
        }

        if (isset($this->evidence[$evidence->id()])) {
            throw new \InvalidArgumentException(sprintf('Evidence ID "%s" is already registered.', $evidence->id()));
        }

        $this->store($evidence);
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

    private function store(Evidence $evidence): void
    {
        $this->evidence[$evidence->id()] = $evidence;

        $key = self::contentKey(
            $evidence->evidenceClass(),
            $evidence->summary(),
            $evidence->confidence(),
            $evidence->context()
        );

        if ($key === null) {
            $this->contentIndexComplete = false;

            return;
        }

        $this->contentIndex[$key][] = $evidence;
    }

    /**
     * Bucket key for addOnce() deduplication.
     *
     * Identical values always produce the same key, so a bucket can never miss a
     * match that the exhaustive scan would have found. Distinct-but-equal objects
     * can share a key, so every candidate is still compared with the original
     * strict predicate. Values that cannot be serialized yield null.
     *
     * @param array<string, mixed> $context
     */
    private static function contentKey(string $class, string $summary, string $confidence, array $context): ?string
    {
        try {
            $serialized = serialize([$class, $summary, $confidence, $context]);
        } catch (\Throwable) {
            return null;
        }

        return hash('sha256', $serialized);
    }
}
