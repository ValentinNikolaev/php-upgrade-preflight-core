<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Analysis;

use PhpUpgradePreflight\Core\Model\ProjectState;

/**
 * The single source of truth for the validation steps recommended by both the aggregate
 * plan and the staged plan.
 *
 * Identity, command, grading, and inclusion rules live here so the two projections cannot
 * drift apart. Purposes stay with each caller because they describe different scopes: the
 * aggregate report speaks about the whole upgrade, a stage about one framework hop.
 */
final class TestGuidanceCatalog
{
    public const COMPOSER_VALIDATION = 'composer-validation';
    public const PROJECT_TEST_SUITE = 'project-test-suite';
    public const PLATFORM_REQUIREMENTS = 'platform-requirements';
    public const FOCUSED_REGRESSIONS = 'focused-regressions';

    /**
     * @var list<array{
     *     id: string,
     *     command: string|null,
     *     grade: string,
     *     needs_test_script: bool,
     *     needs_findings: bool
     * }>
     */
    private const SPECS = [
        [
            'id' => self::COMPOSER_VALIDATION,
            'command' => 'composer validate --strict',
            'grade' => 'required',
            'needs_test_script' => false,
            'needs_findings' => false,
        ],
        [
            'id' => self::PROJECT_TEST_SUITE,
            'command' => 'composer test',
            'grade' => 'required',
            'needs_test_script' => true,
            'needs_findings' => false,
        ],
        [
            'id' => self::PLATFORM_REQUIREMENTS,
            'command' => 'composer check-platform-reqs',
            'grade' => 'required',
            'needs_test_script' => false,
            'needs_findings' => false,
        ],
        [
            'id' => self::FOCUSED_REGRESSIONS,
            'command' => null,
            'grade' => 'recommended',
            'needs_test_script' => false,
            'needs_findings' => true,
        ],
    ];

    /**
     * Resolves the applicable guidance items in report order.
     *
     * A command that depends on a Composer script is dropped when the project does not
     * declare it, because the analyzer must not invent a test command it cannot evidence.
     *
     * @return list<array{id: string, command: string|null, grade: string}>
     */
    public static function applicable(bool $hasTestScript, bool $hasFindings): array
    {
        $applicable = [];
        foreach (self::SPECS as $spec) {
            if ($spec['needs_findings'] && !$hasFindings) {
                continue;
            }

            $applicable[] = [
                'id' => $spec['id'],
                'command' => $spec['needs_test_script'] && !$hasTestScript ? null : $spec['command'],
                'grade' => $spec['grade'],
            ];
        }

        return $applicable;
    }

    public static function hasComposerScript(ProjectState $project, string $name): bool
    {
        $scripts = $project->composerJson()->data()['scripts'] ?? null;

        return is_array($scripts) && array_key_exists($name, $scripts);
    }
}
