<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Composer;

abstract class JsonFileException extends \RuntimeException
{
    private string $path;

    public function __construct(string $path, string $message)
    {
        parent::__construct($message);
        $this->path = $path;
    }

    public function path(): string
    {
        return $this->path;
    }
}
