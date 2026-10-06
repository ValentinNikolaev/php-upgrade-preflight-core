<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Model;

final class TestGuidance
{
    private string $name;
    private string $purpose;
    private ?string $command;
    private string $priority;

    public function __construct(string $name, string $purpose, ?string $command, string $priority)
    {
        $this->name = $name;
        $this->purpose = $purpose;
        $this->command = $command;
        $this->priority = $priority;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function purpose(): string
    {
        return $this->purpose;
    }

    public function command(): ?string
    {
        return $this->command;
    }

    public function priority(): string
    {
        return $this->priority;
    }

    /** @return array{name: string, purpose: string, command: ?string, priority: string} */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'purpose' => $this->purpose,
            'command' => $this->command,
            'priority' => $this->priority,
        ];
    }
}
