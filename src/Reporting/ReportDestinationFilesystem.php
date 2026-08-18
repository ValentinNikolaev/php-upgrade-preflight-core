<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Reporting;

/**
 * Filesystem probing and writing required to validate and materialize a report destination.
 *
 * The port exists so the destination containment rule - report output must stay outside the
 * analyzed project - can be exercised against an in-memory double instead of a real tree.
 */
interface ReportDestinationFilesystem
{
    public function isDirectory(string $path): bool;

    public function isFile(string $path): bool;

    public function isWritable(string $path): bool;

    /**
     * True when the path exists in any form, including entries that are neither a regular
     * file nor a directory.
     */
    public function exists(string $path): bool;

    /** Returns the canonical path, or false when it cannot be resolved. */
    public function resolve(string $path): string|false;

    public function dumpFile(string $path, string $contents): void;
}
