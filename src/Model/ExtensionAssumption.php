<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Model;

final class ExtensionAssumption
{
    public const PRESENT = 'present';
    public const ABSENT = 'absent';
    public const REQUEST = 'request';
    public const COMPOSER_CONFIG = 'composer_config';

    private string $name;
    private string $state;
    private ?string $version;
    private string $provenance;

    private function __construct(string $name, string $state, ?string $version, string $provenance)
    {
        $name = strtolower(trim($name));
        if (preg_match('/^ext-[a-z0-9_.-]+$/', $name) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'Extension name "%s" must use Composer ext-name syntax.',
                $name
            ));
        }

        if (!in_array($state, [self::PRESENT, self::ABSENT], true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported extension state "%s".', $state));
        }

        if (!in_array($provenance, [self::REQUEST, self::COMPOSER_CONFIG], true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported extension provenance "%s".', $provenance));
        }

        if ($state === self::ABSENT && $version !== null) {
            throw new \InvalidArgumentException(sprintf('Absent extension "%s" cannot have a version.', $name));
        }

        $this->name = $name;
        $this->state = $state;
        $this->version = $version;
        $this->provenance = $provenance;
    }

    public static function fromPresenceInput(string $input): self
    {
        $input = trim($input);
        if ($input === '') {
            throw new \InvalidArgumentException('Extension presence assumption must not be empty.');
        }

        $parts = explode(':', $input, 2);
        $version = isset($parts[1]) ? trim($parts[1]) : null;
        if ($version === '') {
            throw new \InvalidArgumentException(sprintf('Extension version in "%s" must not be empty.', $input));
        }

        if ($version !== null && preg_match('/^v?\d+(?:\.\d+){0,3}(?:[-+][0-9A-Za-z.-]+)?$/', $version) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'Extension version "%s" for "%s" must be an exact version.',
                $version,
                $parts[0]
            ));
        }

        return new self($parts[0], self::PRESENT, $version, self::REQUEST);
    }

    public static function fromAbsenceInput(string $name): self
    {
        return new self($name, self::ABSENT, null, self::REQUEST);
    }

    public static function fromComposerConfig(string $name, string|false $value): self
    {
        return new self(
            $name,
            $value === false ? self::ABSENT : self::PRESENT,
            is_string($value) ? $value : null,
            self::COMPOSER_CONFIG
        );
    }

    public function name(): string
    {
        return $this->name;
    }

    public function state(): string
    {
        return $this->state;
    }

    public function version(): ?string
    {
        return $this->version;
    }

    public function provenance(): string
    {
        return $this->provenance;
    }

    public function isPresentWithoutVersion(): bool
    {
        return $this->state === self::PRESENT && $this->version === null;
    }

    /** @return array{name: string, state: string, version: ?string, provenance: string} */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'state' => $this->state,
            'version' => $this->version,
            'provenance' => $this->provenance,
        ];
    }
}
