<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Support;

use PhpUpgradePreflight\Core\Support\PathExposurePolicy;
use PHPUnit\Framework\TestCase;

final class PathExposurePolicyTest extends TestCase
{
    public function testCanonicalReportsReplaceCallerOwnedAbsolutePaths(): void
    {
        $project = 'C:\\work trees\\private-project';
        $output = 'C:\\reports\\private-project.json';
        $canonical = [
            'request_summary' => [
                'project_path' => $project,
                'output_path' => $output,
            ],
            'project_state' => ['path' => str_replace('\\', '/', $project)],
            'uncertainties' => [sprintf('Failure in "%s\\composer.json".', $project)],
            'symbol' => 'App\\Http\\Kernel',
        ];

        $sanitized = PathExposurePolicy::sanitizeCanonicalReport($canonical, $project, $output);
        $encoded = json_encode($sanitized, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        self::assertSame(PathExposurePolicy::PROJECT_ROOT, $sanitized['request_summary']['project_path']);
        self::assertSame(PathExposurePolicy::REPORT_OUTPUT, $sanitized['request_summary']['output_path']);
        self::assertSame(PathExposurePolicy::PROJECT_ROOT, $sanitized['project_state']['path']);
        self::assertStringContainsString('[PROJECT_ROOT]\\composer.json', $sanitized['uncertainties'][0]);
        self::assertStringNotContainsString('private-project', $encoded);
        self::assertSame('App\\Http\\Kernel', $sanitized['symbol']);
    }

    public function testRelativeOutputNameCannotBecomeAGlobalReplacementPattern(): void
    {
        $canonical = [
            'request_summary' => [
                'project_path' => 'C:\\project',
                'output_path' => 'a',
            ],
            'project_state' => ['path' => 'C:\\project'],
            'uncertainties' => ['Package analysis remains available.'],
        ];

        $sanitized = PathExposurePolicy::sanitizeCanonicalReport($canonical, 'C:\\project', 'a');

        self::assertSame(PathExposurePolicy::REPORT_OUTPUT, $sanitized['request_summary']['output_path']);
        self::assertSame('Package analysis remains available.', $sanitized['uncertainties'][0]);
    }

    public function testRelativeImportedProjectNameCannotBecomeAGlobalReplacementPattern(): void
    {
        $canonical = [
            'request_summary' => [
                'project_path' => 'a',
                'output_path' => null,
            ],
            'project_state' => ['path' => 'a'],
            'uncertainties' => ['Package analysis remains available.'],
        ];

        $sanitized = PathExposurePolicy::sanitizeCanonicalReport($canonical);

        self::assertSame(PathExposurePolicy::PROJECT_ROOT, $sanitized['request_summary']['project_path']);
        self::assertSame('Package analysis remains available.', $sanitized['uncertainties'][0]);
    }

    public function testComposerTextReplacesKnownLocalRootsAndCredentials(): void
    {
        $project = 'C:\\projects\\application';
        $workspace = 'C:\\temp\\php-upgrade-preflight-deadbeef';
        $repository = 'C:\\projects\\packages';
        $text = implode("\n", [
            'Project manifest: ' . $project . '\\composer.json',
            'Workspace: ' . str_replace('\\', '/', $workspace) . '/composer.json',
            'Repository: ' . $repository . '\\private-package',
            'Downloading https://user:password@example.invalid/private-package.zip',
        ]);

        $sanitized = PathExposurePolicy::redactComposerText(
            $text,
            $project,
            $workspace,
            [$repository]
        );

        self::assertStringContainsString(PathExposurePolicy::PROJECT_ROOT, $sanitized);
        self::assertStringContainsString(PathExposurePolicy::ANALYZER_WORKSPACE, $sanitized);
        self::assertStringContainsString(PathExposurePolicy::LOCAL_REPOSITORY, $sanitized);
        self::assertStringContainsString('[REDACTED_URL]', $sanitized);
        self::assertStringNotContainsString('password', $sanitized);
    }

    public function testWindowsPathsAreCaseInsensitiveAndJsonEscapesAreCoveredOnEveryHost(): void
    {
        $project = 'C:\\Work Trees\\Private-Project';
        $text = implode("\n", [
            'Case variant: c:\\work trees\\PRIVATE-PROJECT\\composer.json',
            'JSON escaped: C:\\\\Work Trees\\\\Private-Project\\\\composer.lock',
            'Slash escaped: C:\\/Work Trees\\/Private-Project\\/composer.json',
        ]);

        $sanitized = PathExposurePolicy::redactComposerText($text, $project);

        self::assertSame(3, substr_count($sanitized, PathExposurePolicy::PROJECT_ROOT));
        self::assertStringNotContainsStringIgnoringCase('private-project', $sanitized);
    }

    public function testPathPrefixesDoNotCorruptPackageNamesOrSiblingPaths(): void
    {
        $text = implode("\n", [
            'Manifest: /app/composer.json',
            'File URL: file:///app/composer.lock',
            'Repository: /repo/package/composer.json',
            'Package vendor/application remains visible.',
            'Sibling /application remains visible.',
            'Sibling /repository remains visible.',
            'Callback: https://host.invalid/?root=/app&repository=/repo#result',
        ]);

        $sanitized = PathExposurePolicy::redactComposerText($text, '/app', null, ['/repo']);

        self::assertSame(3, substr_count($sanitized, PathExposurePolicy::PROJECT_ROOT));
        self::assertSame(2, substr_count($sanitized, PathExposurePolicy::LOCAL_REPOSITORY));
        self::assertStringContainsString('vendor/application', $sanitized);
        self::assertStringContainsString('/application', $sanitized);
        self::assertStringContainsString('/repository', $sanitized);
    }

    public function testEncodedPathsAssociativeKeysAndObjectsAreSanitized(): void
    {
        $project = '/home/alice/private project';
        $secret = 'opaque-object-authorization-secret';
        $canonical = [
            'request_summary' => [
                'project_path' => $project,
                'output_path' => null,
            ],
            'project_state' => ['path' => $project],
            'uncertainties' => ['File URL: file:///home/alice/private%20project/composer.json'],
            'evidence' => [[
                'context' => [
                    $project . '/secret.txt' => new class ($project, $secret) {
                        public string $path;
                        public string $authorization;

                        public function __construct(string $path, string $authorization)
                        {
                            $this->path = $path . '/composer.json';
                            $this->authorization = $authorization;
                        }
                    },
                ],
            ]],
        ];

        $sanitized = PathExposurePolicy::sanitizeCanonicalReport($canonical, $project);
        $encoded = json_encode($sanitized, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString($project, $encoded);
        self::assertStringNotContainsString('private%20project', $encoded);
        self::assertStringNotContainsString($secret, $encoded);
        self::assertStringContainsString('[PROJECT_ROOT]/secret.txt', $encoded);
        self::assertStringContainsString('file://[PROJECT_ROOT]/composer.json', $encoded);
        self::assertStringContainsString('[REDACTED]', $encoded);
    }

    public function testOnlyDebugReportsExposeTheSanitizedWorkspacePath(): void
    {
        $workspace = 'C:\\temp\\php-upgrade-preflight-debug';

        self::assertSame(
            PathExposurePolicy::ANALYZER_WORKSPACE,
            PathExposurePolicy::workspaceForReport($workspace, false)
        );
        self::assertSame($workspace, PathExposurePolicy::workspaceForReport($workspace, true));
        self::assertNull(PathExposurePolicy::workspaceForReport(null, true));
    }

    public function testUnknownLocalFileUrlsFailClosedWithoutRepositoryMetadata(): void
    {
        $localPath = '/private/vendor/repository/replacement';
        $canonical = [
            'request_summary' => [
                'project_path' => '/app/project',
                'output_path' => null,
            ],
            'project_state' => ['path' => '/app/project'],
            'evidence' => [[
                'context' => ['abandoned_alternative' => 'file://' . $localPath],
            ]],
        ];

        $sanitized = PathExposurePolicy::sanitizeCanonicalReport($canonical, '/app/project');
        $encoded = json_encode($sanitized, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString($localPath, $encoded);
        self::assertSame(
            PathExposurePolicy::LOCAL_REPOSITORY,
            $sanitized['evidence'][0]['context']['abandoned_alternative']
        );
    }
}
