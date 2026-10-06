<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Model;

final class StageTestGuidance
{
    private string $stageId;
    private string $name;
    private string $purpose;
    private ?string $command;
    private string $priority;

    public function __construct(
        string $stageId,
        string $name,
        string $purpose,
        ?string $command,
        string $priority
    ) {
        if (!in_array($priority, ['required', 'recommended'], true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported test priority "%s".', $priority));
        }

        $this->stageId = $stageId;
        $this->name = $name;
        $this->purpose = $purpose;
        $this->command = $command;
        $this->priority = $priority;
    }

    /** @return array{stage_id: string, name: string, purpose: string, command: ?string, priority: string} */
    public function toArray(): array
    {
        return [
            'stage_id' => $this->stageId,
            'name' => $this->name,
            'purpose' => $this->purpose,
            'command' => $this->command,
            'priority' => $this->priority,
        ];
    }
}
