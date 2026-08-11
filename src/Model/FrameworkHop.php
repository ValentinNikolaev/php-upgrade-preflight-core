<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Model;

final class FrameworkHop
{
    public const SUPPORTED = 'supported';
    public const UNSUPPORTED = 'unsupported';

    private int $fromMajor;
    private int $toMajor;
    private string $status;
    private ?string $rulePack;
    /** @var list<string> */
    private array $evidence;

    /** @param list<string> $evidence */
    public function __construct(int $fromMajor, int $toMajor, string $status, ?string $rulePack, array $evidence)
    {
        if ($fromMajor < 0 || $toMajor <= $fromMajor) {
            throw new \InvalidArgumentException('A framework hop must move between non-negative ascending majors.');
        }
        if (!in_array($status, [self::SUPPORTED, self::UNSUPPORTED], true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported framework-hop status "%s".', $status));
        }
        if (($status === self::SUPPORTED) !== ($rulePack !== null && trim($rulePack) !== '')) {
            throw new \InvalidArgumentException('A supported framework hop requires a rule pack and an unsupported hop must not name one.');
        }
        if ($evidence === []) {
            throw new \InvalidArgumentException('A framework hop must reference evidence.');
        }

        $this->fromMajor = $fromMajor;
        $this->toMajor = $toMajor;
        $this->status = $status;
        $this->rulePack = $rulePack;
        $this->evidence = array_values(array_unique($evidence));
    }

    public function fromMajor(): int
    {
        return $this->fromMajor;
    }

    public function toMajor(): int
    {
        return $this->toMajor;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function isSupported(): bool
    {
        return $this->status === self::SUPPORTED;
    }

    /** @return list<string> */
    public function evidence(): array
    {
        return $this->evidence;
    }

    /** @return array{from_major: int, to_major: int, status: string, rule_pack: ?string, evidence: list<string>} */
    public function toArray(): array
    {
        return [
            'from_major' => $this->fromMajor,
            'to_major' => $this->toMajor,
            'status' => $this->status,
            'rule_pack' => $this->rulePack,
            'evidence' => $this->evidence,
        ];
    }

    /** @return array{from_major: int, to_major: int} */
    public function reference(): array
    {
        return [
            'from_major' => $this->fromMajor,
            'to_major' => $this->toMajor,
        ];
    }
}
