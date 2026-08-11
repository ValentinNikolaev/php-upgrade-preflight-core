<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Model;

final class ExtensionAssumptionSet
{
    /** @var list<ExtensionAssumption> */
    private array $assumptions;

    /** @param list<ExtensionAssumption> $assumptions */
    public function __construct(array $assumptions)
    {
        $indexed = [];
        foreach ($assumptions as $index => $assumption) {
            if (!$assumption instanceof ExtensionAssumption) {
                throw new \InvalidArgumentException(sprintf(
                    'Extension assumption at index %d must be an ExtensionAssumption.',
                    $index
                ));
            }

            if (isset($indexed[$assumption->name()])) {
                throw new \InvalidArgumentException(sprintf(
                    'Extension assumption for "%s" may only be specified once.',
                    $assumption->name()
                ));
            }

            $indexed[$assumption->name()] = $assumption;
        }

        ksort($indexed, SORT_STRING);
        $this->assumptions = array_values($indexed);
    }

    /**
     * @param list<string> $present
     * @param list<string> $absent
     */
    public static function fromInputs(array $present, array $absent): self
    {
        $assumptions = [];
        foreach ($present as $input) {
            $assumptions[] = ExtensionAssumption::fromPresenceInput($input);
        }
        foreach ($absent as $input) {
            $assumptions[] = ExtensionAssumption::fromAbsenceInput($input);
        }

        return new self($assumptions);
    }

    /** @return list<ExtensionAssumption> */
    public function all(): array
    {
        return $this->assumptions;
    }
}
