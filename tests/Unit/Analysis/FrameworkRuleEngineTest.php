<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Tests\Unit\Analysis;

use PhpUpgradePreflight\Core\Analysis\FrameworkRuleEngine;
use PhpUpgradePreflight\Core\Framework\CompatibilityRule;
use PhpUpgradePreflight\Core\Framework\FrameworkDetection;
use PhpUpgradePreflight\Core\Framework\FrameworkIntegration;
use PhpUpgradePreflight\Core\Model\CompatibilityFinding;
use PhpUpgradePreflight\Core\Model\ComposerJson;
use PhpUpgradePreflight\Core\Model\ComposerLock;
use PhpUpgradePreflight\Core\Model\Evidence;
use PhpUpgradePreflight\Core\Model\EvidenceLedger;
use PhpUpgradePreflight\Core\Model\ProjectState;
use PhpUpgradePreflight\Core\Model\UpgradeRequest;
use PhpUpgradePreflight\Core\Model\UpgradeTarget;
use PHPUnit\Framework\TestCase;

final class FrameworkRuleEngineTest extends TestCase
{
    public function testItSelectsDetectedIntegrationsMergesPathsAndRunsRules(): void
    {
        $detected = new FixtureFrameworkIntegration('detected', true, ['app', 'config'], [
            new FixtureCompatibilityRule('Review detected framework.'),
            new FixtureCompatibilityRule(null),
        ]);
        $other = new FixtureFrameworkIntegration('other', false, ['src'], [
            new FixtureCompatibilityRule('Should not run.'),
        ]);
        $engine = new FrameworkRuleEngine([$detected, $other]);
        $request = $this->request();
        $project = $this->project();
        $evidence = new EvidenceLedger();

        $active = $engine->activeIntegrations($project, $request);
        $findings = $engine->evaluate($active, $project, $request, $evidence);

        self::assertSame([$detected], $active);
        self::assertSame(['app', 'config'], $engine->sourcePaths($project, $request, $active));
        self::assertCount(1, $findings);
        self::assertSame('Review detected framework.', $findings[0]->summary());
        self::assertSame(['framework-1'], $findings[0]->evidence());
        self::assertCount(1, $evidence->all());
    }

    public function testExplicitFrameworkSelectionIsCaseInsensitiveAndOverridesDetection(): void
    {
        $integration = new FixtureFrameworkIntegration('fixture', false, ['app'], []);
        $engine = new FrameworkRuleEngine([$integration]);
        $request = $this->request(['FIXTURE'], ['custom']);
        $project = $this->project();

        $active = $engine->activeIntegrations($project, $request);

        self::assertSame([$integration], $active);
        self::assertSame(['custom'], $engine->sourcePaths($project, $request, $active));
    }

    public function testMultipleActiveIntegrationsMergeOverlappingSourcePathsDeterministically(): void
    {
        $first = new FixtureFrameworkIntegration('first', true, ['app', 'config'], []);
        $second = new FixtureFrameworkIntegration('second', true, ['config', 'routes', 'app'], []);
        $engine = new FrameworkRuleEngine([$first, $second]);
        $request = $this->request();
        $project = $this->project();

        $active = $engine->activeIntegrations($project, $request);

        self::assertSame([$first, $second], $active);
        self::assertSame(['app', 'config', 'routes'], $engine->sourcePaths($project, $request, $active));
    }

    public function testItRejectsAnUnavailableRequestedIntegration(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('missing');

        (new FrameworkRuleEngine())->activeIntegrations($this->project(), $this->request(['missing']));
    }

    /** @param list<string> $frameworks @param list<string> $sourcePaths */
    private function request(array $frameworks = [], array $sourcePaths = []): UpgradeRequest
    {
        return new UpgradeRequest(__DIR__, [new UpgradeTarget('vendor/package', '^2.0')], null, null, $sourcePaths, $frameworks);
    }

    private function project(): ProjectState
    {
        return new ProjectState(__DIR__, new ComposerJson([]), new ComposerLock([]));
    }
}

final class FixtureFrameworkIntegration implements FrameworkIntegration
{
    private string $name;
    private bool $detected;
    /** @var list<string> */
    private array $sourcePaths;
    /** @var list<CompatibilityRule> */
    private array $rules;

    /** @param list<string> $sourcePaths @param list<CompatibilityRule> $rules */
    public function __construct(string $name, bool $detected, array $sourcePaths, array $rules)
    {
        $this->name = $name;
        $this->detected = $detected;
        $this->sourcePaths = $sourcePaths;
        $this->rules = $rules;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function detect(ProjectState $project): FrameworkDetection
    {
        return new FrameworkDetection($this->name, $this->detected);
    }

    public function rules(): iterable
    {
        yield from $this->rules;
    }

    public function defaultSourcePaths(ProjectState $project): array
    {
        return $this->sourcePaths;
    }
}

final class FixtureCompatibilityRule implements CompatibilityRule
{
    private ?string $summary;

    public function __construct(?string $summary)
    {
        $this->summary = $summary;
    }

    public function evaluate(ProjectState $project, UpgradeRequest $request, EvidenceLedger $evidence): ?CompatibilityFinding
    {
        if ($this->summary === null) {
            return null;
        }

        $evidenceId = $evidence->add('framework', Evidence::E2_PACKAGE_METADATA, 'Framework metadata matched.')->id();

        return new CompatibilityFinding('fixture', 'medium', $this->summary, [$evidenceId]);
    }
}
