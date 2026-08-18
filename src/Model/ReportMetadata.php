<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Model;

final class ReportMetadata
{
    public const SCHEMA_VERSION = '0.8';
    public const TOOL_NAME = 'php-upgrade-preflight';
    public const TOOL_VERSION = '0.3.0';

    public function schemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    public function toolName(): string
    {
        return self::TOOL_NAME;
    }

    public function toolVersion(): string
    {
        return self::TOOL_VERSION;
    }

    /** @return array{schema_version: string, tool: array{name: string, version: string}} */
    public function toArray(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'tool' => [
                'name' => self::TOOL_NAME,
                'version' => self::TOOL_VERSION,
            ],
        ];
    }
}
