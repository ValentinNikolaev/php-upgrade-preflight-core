<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Support;

use PhpUpgradePreflight\Tests\Support\JsonSnapshotNormalizer;
use PHPUnit\Framework\TestCase;

final class JsonSnapshotNormalizerTest extends TestCase
{
    public function testItNormalizesProjectAndInferredTemporaryPathsAcrossJsonValues(): void
    {
        $projectPath = 'C:\\work trees\\php-upgrade-preflight';
        $tempPath = 'C:\\Users\\tester\\AppData\\Local\\Temp\\php-upgrade-preflight-a1b2c3d4';
        $json = json_encode([
            'request_summary' => [
                'project_path' => str_replace('\\', '/', $projectPath),
            ],
            'project_state' => [
                'path' => $projectPath,
            ],
            'resolution' => [
                'scenarios' => [[
                    'stderr_excerpt' => sprintf('Could not read "%s\\composer.json".', $tempPath),
                    'temp_path' => $tempPath,
                ]],
            ],
            'source_impact' => [[
                'file' => $projectPath . '\\app\\Http\\Kernel.php',
            ]],
            'class_name' => 'App\\Http\\Kernel',
            'metadata' => (object) [],
        ], JSON_THROW_ON_ERROR);

        self::assertSame(<<<'JSON'
{
    "request_summary": {
        "project_path": "<PROJECT_PATH>"
    },
    "project_state": {
        "path": "<PROJECT_PATH>"
    },
    "resolution": {
        "scenarios": [
            {
                "stderr_excerpt": "Could not read \"<TEMP_DIR>/composer.json\".",
                "temp_path": "<TEMP_DIR>"
            }
        ]
    },
    "source_impact": [
        {
            "file": "<PROJECT_PATH>/app/Http/Kernel.php"
        }
    ],
    "class_name": "App\\Http\\Kernel",
    "metadata": {}
}
JSON
            . "\n", JsonSnapshotNormalizer::normalize($json, $projectPath));
    }

    public function testItNormalizesExplicitTemporaryDirectoriesThatAreOnlyPresentInDiagnostics(): void
    {
        $projectPath = '/workspace/project';
        $tempPath = '/tmp/php-upgrade-preflight-deadbeef';
        $json = json_encode([
            'stderr_excerpt' => sprintf('Failure in %s\\composer.json while loading App\\Composer', $tempPath),
            'temp_path' => null,
        ], JSON_THROW_ON_ERROR);

        $normalized = JsonSnapshotNormalizer::normalize($json, $projectPath, [$tempPath]);

        self::assertStringContainsString(
            'Failure in <TEMP_DIR>/composer.json while loading App\\\\Composer',
            $normalized
        );
        self::assertStringNotContainsString('deadbeef', $normalized);
    }

    public function testItRejectsInvalidJson(): void
    {
        $this->expectException(\JsonException::class);

        JsonSnapshotNormalizer::normalize('{', '/workspace/project');
    }
}
