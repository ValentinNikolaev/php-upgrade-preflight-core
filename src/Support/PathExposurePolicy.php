<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Support;

use Symfony\Component\Filesystem\Path;

final class PathExposurePolicy
{
    public const PROJECT_ROOT = '[PROJECT_ROOT]';
    public const REPORT_OUTPUT = '[REPORT_OUTPUT]';
    public const LOCAL_REPOSITORY = '[LOCAL_REPOSITORY]';
    public const ANALYZER_WORKSPACE = '[ANALYZER_WORKSPACE]';

    /**
     * @param array<string, mixed> $canonical
     * @return array<string, mixed>
     */
    public static function sanitizeCanonicalReport(
        array $canonical,
        ?string $projectPath = null,
        ?string $outputPath = null,
        array $repositoryPaths = []
    ): array {
        $request = isset($canonical['request_summary']) && is_array($canonical['request_summary'])
            ? $canonical['request_summary']
            : [];
        $projectPath = $projectPath ?? (is_string($request['project_path'] ?? null) ? $request['project_path'] : null);
        $outputPath = $outputPath ?? (is_string($request['output_path'] ?? null) ? $request['output_path'] : null);

        $paths = [];
        if ($projectPath !== null && !self::isMarker($projectPath) && self::isAbsolutePath($projectPath)) {
            $paths[$projectPath] = self::PROJECT_ROOT;
        }
        if ($outputPath !== null && !self::isMarker($outputPath) && self::isAbsolutePath($outputPath)) {
            $paths[$outputPath] = self::REPORT_OUTPUT;
        }
        foreach ($repositoryPaths as $repositoryPath) {
            if ($repositoryPath !== ''
                && self::isRepositoryReference($repositoryPath)
                && !isset($paths[$repositoryPath])
            ) {
                $paths[$repositoryPath] = self::LOCAL_REPOSITORY;
            }
        }

        $canonical = self::redactPathsInArray($canonical, $paths, new \SplObjectStorage());

        if (isset($canonical['request_summary']) && is_array($canonical['request_summary'])) {
            $canonical['request_summary']['project_path'] = self::PROJECT_ROOT;
            if (($request['output_path'] ?? null) !== null) {
                $canonical['request_summary']['output_path'] = self::REPORT_OUTPUT;
            }
        }
        if (isset($canonical['project_state']) && is_array($canonical['project_state'])) {
            $canonical['project_state']['path'] = self::PROJECT_ROOT;
        }

        $sanitized = SensitiveOutputRedactor::redactStructured($canonical);
        if (!is_array($sanitized)) {
            throw new \LogicException('Canonical report sanitization must preserve the report array.');
        }

        return $sanitized;
    }

    /**
     * @param array<string, mixed> $composerData
     * @return list<string>
     */
    public static function localRepositoryPaths(array $composerData, string $projectPath): array
    {
        $repositories = $composerData['repositories'] ?? null;
        if (!is_array($repositories)) {
            return [];
        }

        $paths = [];
        foreach ($repositories as $repository) {
            if (!is_array($repository) || !is_string($repository['url'] ?? null)) {
                continue;
            }

            $type = is_string($repository['type'] ?? null) ? strtolower($repository['type']) : '';
            $path = $repository['url'];
            if ($path === '') {
                continue;
            }
            $filePath = self::localFileUrlPath($path);
            $isLocalReference = $filePath !== null
                || in_array($type, ['path', 'artifact'], true)
                || Path::isAbsolute($path)
                || str_starts_with($path, '~')
                || self::containsEnvironmentVariable($path);
            if (!$isLocalReference) {
                continue;
            }

            $candidates = [];
            if ($filePath !== null) {
                $candidates[] = $path;
                $candidates[] = $filePath;
            } elseif (str_starts_with($path, '~') || self::containsEnvironmentVariable($path)) {
                $candidates[] = $path;
                $expanded = self::expandLocalRepositoryPath($path);
                if (Path::isAbsolute($expanded)) {
                    $candidates[] = $expanded;
                }
            } elseif (Path::isAbsolute($path)) {
                $candidates[] = $path;
            } else {
                $candidates[] = Path::makeAbsolute($path, $projectPath);
            }

            foreach ($candidates as $candidate) {
                $wildcard = strcspn($candidate, '*?[');
                if ($wildcard < strlen($candidate)) {
                    $candidate = substr($candidate, 0, $wildcard);
                }
                $candidate = rtrim($candidate, '/\\');
                if ($candidate !== '') {
                    $paths[] = $candidate;
                }
            }
        }

        $paths = array_values(array_unique($paths));
        usort($paths, static fn (string $left, string $right): int => strlen($right) <=> strlen($left));

        return $paths;
    }

    /**
     * @param array<string, mixed> $composerData
     * @return list<string>
     */
    public static function composerRepositoryReferences(array $composerData, string $projectPath): array
    {
        $references = self::localRepositoryPaths($composerData, $projectPath);
        $repositories = $composerData['repositories'] ?? null;
        if (!is_array($repositories)) {
            return $references;
        }

        foreach ($repositories as $repository) {
            if (is_array($repository) && is_string($repository['url'] ?? null) && $repository['url'] !== '') {
                $references[] = $repository['url'];
            }
        }

        $references = array_values(array_unique($references));
        usort($references, static fn (string $left, string $right): int => strlen($right) <=> strlen($left));

        return $references;
    }

    /** @param list<string> $repositoryPaths */
    public static function redactComposerText(
        string $value,
        ?string $projectPath = null,
        ?string $workspacePath = null,
        array $repositoryPaths = []
    ): string {
        $paths = [];
        if ($projectPath !== null && self::isAbsolutePath($projectPath)) {
            $paths[$projectPath] = self::PROJECT_ROOT;
        }
        if ($workspacePath !== null && self::isAbsolutePath($workspacePath)) {
            $paths[$workspacePath] = self::ANALYZER_WORKSPACE;
        }
        foreach ($repositoryPaths as $repositoryPath) {
            if ($repositoryPath !== '' && self::isRepositoryReference($repositoryPath)) {
                $paths[$repositoryPath] = self::LOCAL_REPOSITORY;
            } elseif ($repositoryPath !== '' && self::isRemoteUrl($repositoryPath)) {
                $paths[$repositoryPath] = SensitiveOutputRedactor::REDACTED_URL;
            }
        }

        return self::redactRemoteUrls(
            SensitiveOutputRedactor::redact(self::redactPathText($value, $paths))
        );
    }

    public static function workspaceForReport(?string $workspacePath, bool $debug): ?string
    {
        if ($workspacePath === null) {
            return null;
        }

        if (!$debug) {
            return self::ANALYZER_WORKSPACE;
        }

        $sanitized = SensitiveOutputRedactor::redactStructured($workspacePath);

        return is_string($sanitized) ? $sanitized : SensitiveOutputRedactor::REDACTED;
    }

    public static function operationalPath(string $path): string
    {
        return SensitiveOutputRedactor::redact($path);
    }

    /** @param array<string, string> $paths */
    public static function redactPaths(string $value, array $paths): string
    {
        /** @var array<string, array{marker: string, length: int}> $replacements */
        $replacements = [];
        foreach ($paths as $path => $marker) {
            foreach (self::pathVariants($path) as $variant) {
                $pattern = '~(?<![A-Za-z0-9_.-])'
                    . self::pathPatternBody($variant)
                    . '(?=$|[\\\\/\s<>"\'`,;:)&?=#\]}]|\.(?:\s|$))~'
                    . (self::isWindowsPath($variant) ? 'i' : '');
                $replacements[$pattern] = [
                    'marker' => $marker,
                    'length' => max(strlen($variant), $replacements[$pattern]['length'] ?? 0),
                ];
            }
        }

        uasort(
            $replacements,
            /**
             * @param array{marker: string, length: int} $left
             * @param array{marker: string, length: int} $right
             */
            static fn (array $left, array $right): int => $right['length'] <=> $left['length']
        );

        foreach ($replacements as $pattern => $replacement) {
            $redacted = preg_replace($pattern, $replacement['marker'], $value);
            if ($redacted === null) {
                return SensitiveOutputRedactor::REDACTED;
            }
            $value = $redacted;
        }

        return $value;
    }

    /**
     * Builds the pattern body for one path variant.
     *
     * A single value can carry a path with mixed separators: Composer echoes the
     * project path exactly as the caller spelled it, and callers routinely join a
     * Windows root with forward slashes. Quoting the variant verbatim would only
     * redact the separator spelling the variant happens to use, so interior
     * separators match any run, which also covers the doubled and escaped forms
     * found in JSON and Composer transcripts.
     *
     * The leading separators of a rooted path stay literal. A flexible run there
     * would reach backwards into a `file://` scheme and swallow its slashes, and
     * {@see pathVariants} already supplies every spelling of that prefix.
     */
    private static function pathPatternBody(string $path): string
    {
        $root = '';
        if (preg_match('~^[\\\\/]+~', $path, $matches) === 1) {
            $root = $matches[0];
            $path = substr($path, strlen($root));
        }

        $segments = array_filter(
            explode('/', str_replace('\\', '/', $path)),
            static fn (string $segment): bool => $segment !== ''
        );

        return preg_quote($root, '~') . implode('[\\\\/]+', array_map(
            static fn (string $segment): string => preg_quote($segment, '~'),
            $segments
        ));
    }

    /**
     * @param array<string, mixed> $value
     * @param array<string, string> $paths
     * @return array<string, mixed>
     */
    private static function redactPathsInArray(
        array $value,
        array $paths,
        \SplObjectStorage $seen
    ): array {
        $redacted = [];
        foreach ($value as $key => $item) {
            $redactedKey = is_string($key) ? self::redactPathText($key, $paths) : $key;
            $redacted[$redactedKey] = self::redactPathsInValue($item, $paths, $seen);
        }

        return $redacted;
    }

    /** @param array<string, string> $paths */
    private static function redactPathsInValue(
        mixed $value,
        array $paths,
        \SplObjectStorage $seen
    ): mixed {
        if (is_string($value)) {
            return self::redactPathText($value, $paths);
        }
        if (is_array($value)) {
            return self::redactPathsInArray($value, $paths, $seen);
        }
        if (!is_object($value)) {
            return $value;
        }
        if ($seen->contains($value)) {
            return SensitiveOutputRedactor::REDACTED;
        }
        $seen->attach($value);

        try {
            if ($value instanceof \JsonSerializable) {
                return self::redactPathsInValue($value->jsonSerialize(), $paths, $seen);
            }

            return (object) self::redactPathsInArray(get_object_vars($value), $paths, $seen);
        } catch (\Throwable) {
            return SensitiveOutputRedactor::REDACTED;
        } finally {
            $seen->detach($value);
        }
    }

    /** @param array<string, string> $paths */
    private static function redactPathText(string $value, array $paths): string
    {
        $value = self::redactPaths($value, $paths);
        $redacted = preg_replace_callback(
            '~(?<![A-Za-z0-9])file:(?://|(?:\\\\+/){2})[^\s<>"\']+~i',
            static function (array $matches): string {
                foreach ([
                    self::PROJECT_ROOT,
                    self::REPORT_OUTPUT,
                    self::LOCAL_REPOSITORY,
                    self::ANALYZER_WORKSPACE,
                ] as $marker) {
                    if (str_contains($matches[0], $marker)) {
                        return $matches[0];
                    }
                }

                return self::LOCAL_REPOSITORY;
            },
            $value
        );

        return $redacted ?? SensitiveOutputRedactor::REDACTED;
    }

    /** @return list<string> */
    private static function pathVariants(string $path): array
    {
        $path = rtrim($path, '/\\');
        if ($path === '' || preg_match('/^[A-Za-z]:$/', $path) === 1) {
            return [];
        }

        $forwardSlashes = str_replace('\\', '/', $path);
        $backslashes = str_replace('/', '\\', $path);
        $encodedForwardSlashes = implode('/', array_map('rawurlencode', explode('/', $forwardSlashes)));
        $encodedWithDriveColon = str_ireplace('%3A', ':', $encodedForwardSlashes);
        $lowercaseEscapes = preg_replace_callback(
            '/%[0-9A-F]{2}/',
            static fn (array $matches): string => strtolower($matches[0]),
            $encodedForwardSlashes
        ) ?? $encodedForwardSlashes;

        return array_values(array_unique([
            $path,
            $forwardSlashes,
            $backslashes,
            str_replace('/', '\\/', $forwardSlashes),
            str_replace('\\', '\\\\', $backslashes),
            $encodedForwardSlashes,
            $encodedWithDriveColon,
            $lowercaseEscapes,
        ]));
    }

    private static function isWindowsPath(string $path): bool
    {
        return preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1
            || str_starts_with($path, '\\\\')
            || str_starts_with($path, '//');
    }

    private static function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }

    private static function isRepositoryReference(string $path): bool
    {
        return self::isAbsolutePath($path)
            || preg_match('~^file:(?://|(?:\\\\+/){2})~i', $path) === 1
            || preg_match('/^~[\\\\\/]/', $path) === 1
            || preg_match('/\$(?:\{[A-Za-z_][A-Za-z0-9_]*\}|[A-Za-z_][A-Za-z0-9_]*)|%[A-Za-z_][A-Za-z0-9_]*%/', $path) === 1;
    }

    private static function isRemoteUrl(string $value): bool
    {
        return preg_match('~^[A-Za-z][A-Za-z0-9+.-]*://~', $value) === 1
            && !str_starts_with(strtolower($value), 'file://');
    }

    private static function redactRemoteUrls(string $value): string
    {
        $redacted = preg_replace_callback(
            '~(?<![A-Za-z0-9])([A-Za-z][A-Za-z0-9+.-]*):(?://|(?:\\\\+/){2})[^\s<>"\']+~i',
            static fn (array $matches): string => strtolower($matches[1]) === 'file'
                ? $matches[0]
                : SensitiveOutputRedactor::REDACTED_URL,
            $value
        );

        return $redacted ?? SensitiveOutputRedactor::REDACTED;
    }

    private static function containsEnvironmentVariable(string $path): bool
    {
        return preg_match('/\$(?:\{[A-Za-z_][A-Za-z0-9_]*\}|[A-Za-z_][A-Za-z0-9_]*)|%[A-Za-z_][A-Za-z0-9_]*%/', $path) === 1;
    }

    private static function expandLocalRepositoryPath(string $path): string
    {
        $expanded = preg_replace_callback(
            '/\$\{([A-Za-z_][A-Za-z0-9_]*)\}|\$([A-Za-z_][A-Za-z0-9_]*)|%([A-Za-z_][A-Za-z0-9_]*)%/',
            static function (array $matches): string {
                $name = $matches[1] !== '' ? $matches[1] : ($matches[2] !== '' ? $matches[2] : $matches[3]);
                $value = getenv($name);

                return is_string($value) && $value !== '' ? $value : $matches[0];
            },
            $path
        ) ?? $path;

        if (preg_match('/^~(?=[\\\\\/])/', $expanded) === 1) {
            $baseDirectory = getenv('HOME');
            if (!is_string($baseDirectory) || $baseDirectory === '') {
                $baseDirectory = getenv('USERPROFILE');
            }
            if (is_string($baseDirectory) && $baseDirectory !== '') {
                $expanded = rtrim($baseDirectory, '/\\') . substr($expanded, 1);
            }
        }

        return $expanded;
    }

    private static function localFileUrlPath(string $url): ?string
    {
        $normalized = preg_replace('~\\\\+/~', '/', $url);
        if ($normalized === null || !str_starts_with(strtolower($normalized), 'file://')) {
            return null;
        }

        $parts = parse_url($normalized);
        if (!is_array($parts) || !is_string($parts['path'] ?? null)) {
            return null;
        }

        $path = rawurldecode($parts['path']);
        $host = is_string($parts['host'] ?? null) ? $parts['host'] : '';
        if ($host !== '' && strtolower($host) !== 'localhost') {
            $path = '//' . $host . '/' . ltrim($path, '/');
        } elseif (preg_match('~^/[A-Za-z]:/~', $path) === 1) {
            $path = substr($path, 1);
        }

        return $path === '' ? null : $path;
    }

    private static function isMarker(string $path): bool
    {
        return in_array($path, [
            self::PROJECT_ROOT,
            self::REPORT_OUTPUT,
            self::LOCAL_REPOSITORY,
            self::ANALYZER_WORKSPACE,
        ], true);
    }
}
