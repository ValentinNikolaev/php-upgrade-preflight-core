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
        self::assertSame([], $engine->packageFamilyClassifiers($active));
        self::assertCount(1, $findings);
        self::assertSame('Review detected framework.', $findings[0]->summary());
        self::assertSame(['framework-1'], $findings[0]->evidence());
        self::assertCount(1, $evidence->all());
    }

    public function testExplicitFrameworkSelectionIsCaseInsensitiveAndOverridesDetection(): void
    {
        $integration = new FixtureFrameworkIntegration('fixture', false, ['app'], []);
        $engine = new FrameworkRuleEngine([$integration]);
        $request = $this->request(['FIXTURE'], ['FrameworkRuleEngineTest.php']);
        $project = $this->project();

        $active = $engine->activeIntegrations($project, $request);

        self::assertSame([$integration], $active);
        self::assertSame(['FrameworkRuleEngineTest.php'], $engine->sourcePaths($project, $request, $active));
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

    public function testItDeduplicatesRepeatedFindingsAndRetainsEveryHopAndEvidenceRecord(): void
    {
        $integration = new FixtureFrameworkIntegration('fixture', true, ['src'], [
            new FixtureCompatibilityRule('Review the shared constraint.', [['from_major' => 10, 'to_major' => 11]]),
            new FixtureCompatibilityRule('Review the shared constraint.', [['from_major' => 11, 'to_major' => 12]]),
        ]);
        $engine = new FrameworkRuleEngine([$integration]);
        $evidence = new EvidenceLedger();

        $findings = $engine->evaluate([$integration], $this->project(), $this->request(), $evidence);

        self::assertCount(1, $findings);
        self::assertCount(2, $findings[0]->evidence());
        self::assertSame([
            ['from_major' => 10, 'to_major' => 11],
            ['from_major' => 11, 'to_major' => 12],
        ], $findings[0]->appliesToHops());
    }

    public function testItSkipsAFailingRuleAndStillReportsTheRemainingFindings(): void
    {
        $integration = new FixtureFrameworkIntegration('fixture', true, ['app'], [
            new FixtureThrowingCompatibilityRule(),
            new FixtureCompatibilityRule('Review detected framework.'),
        ]);
        $engine = new FrameworkRuleEngine([$integration]);
        $evidence = new EvidenceLedger();
        $uncertainties = [];

        $findings = $engine->evaluate([$integration], $this->project(), $this->request(), $evidence, [], [], null, $uncertainties);

        self::assertCount(1, $findings);
        self::assertSame('Review detected framework.', $findings[0]->summary());
        self::assertSame([
            sprintf(
                'Framework adapter "fixture" rule "%s" failed and was skipped, so its findings are missing from this report (framework-1, framework-rule-1).',
                FixtureThrowingCompatibilityRule::class
            ),
        ], $uncertainties);
        $this->assertNoOrphanedEvidence($evidence, $findings, $uncertainties);
    }

    public function testItSkipsARuleWhoseFindingUsesAnUnsupportedSeverity(): void
    {
        $integration = new FixtureFrameworkIntegration('fixture', true, ['app'], [
            new FixtureCompatibilityRule('Adapter used its own severity vocabulary.', [], 'critical'),
            new FixtureCompatibilityRule('Review detected framework.'),
        ]);
        $engine = new FrameworkRuleEngine([$integration]);
        $evidence = new EvidenceLedger();
        $uncertainties = [];

        $findings = $engine->evaluate([$integration], $this->project(), $this->request(), $evidence, [], [], null, $uncertainties);

        self::assertCount(1, $findings);
        self::assertSame('Review detected framework.', $findings[0]->summary());
        self::assertSame([
            'framework' => 'fixture',
            'rule' => FixtureCompatibilityRule::class,
            'reason' => 'rule_failure',
            'error' => 'Unsupported framework finding severity "critical".',
        ], $this->evidenceContext($evidence, 'framework-rule-1'));
        $this->assertNoOrphanedEvidence($evidence, $findings, $uncertainties);
    }

    public function testItContainsAnIntegrationThatFailsWhileListingItsRules(): void
    {
        $broken = new FixtureBrokenRuleSetIntegration();
        $healthy = new FixtureFrameworkIntegration('healthy', true, ['app'], [
            new FixtureCompatibilityRule('Review healthy framework.'),
        ]);
        $engine = new FrameworkRuleEngine([$broken, $healthy]);
        $evidence = new EvidenceLedger();
        $uncertainties = [];

        $findings = $engine->evaluate([$broken, $healthy], $this->project(), $this->request(), $evidence, [], [], null, $uncertainties);

        self::assertSame(
            ['Review yielded framework.', 'Review healthy framework.'],
            array_map(static fn (CompatibilityFinding $finding): string => $finding->summary(), $findings)
        );
        self::assertSame([
            'Framework adapter "broken" failed while listing its compatibility rules, '
            . 'so its remaining rules were not evaluated (framework-1, framework-rule-1).',
        ], $uncertainties);
        self::assertSame([
            'framework' => 'broken',
            'rule' => null,
            'reason' => 'rule_set_failure',
            'error' => 'rule set failed',
        ], $this->evidenceContext($evidence, 'framework-rule-1'));
        $this->assertNoOrphanedEvidence($evidence, $findings, $uncertainties);
    }

    /** @return array<string, mixed> */
    private function evidenceContext(EvidenceLedger $evidence, string $id): array
    {
        foreach ($evidence->all() as $item) {
            if ($item->id() === $id) {
                return $item->context();
            }
        }

        self::fail(sprintf('Evidence "%s" was not registered.', $id));
    }

    /**
     * Applies the report's own reference rules: evidence is referenced by a finding
     * or named inside an uncertainty, and anything else is an orphan the assembled
     * report would reject.
     *
     * @param list<CompatibilityFinding> $findings
     * @param list<string> $uncertainties
     */
    private function assertNoOrphanedEvidence(EvidenceLedger $evidence, array $findings, array $uncertainties): void
    {
        $references = [];
        foreach ($findings as $finding) {
            $references = array_merge($references, $finding->evidence());
        }

        foreach ($evidence->all() as $item) {
            foreach ($uncertainties as $uncertainty) {
                $pattern = '/(?<![A-Za-z0-9_-])' . preg_quote($item->id(), '/') . '(?![A-Za-z0-9_-])/';
                if (preg_match($pattern, $uncertainty) === 1) {
                    $references[] = $item->id();
                    break;
                }
            }
        }

        $evidence->validateReferences($references);
        self::addToAssertionCount(1);
    }

    /**
     * @param list<string> $frameworks
     * @param list<string> $sourcePaths
     */
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

    /**
     * @param list<string> $sourcePaths
     * @param list<CompatibilityRule> $rules
     */
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
    /** @var list<array{from_major: int, to_major: int}> */
    private array $hops;
    private string $severity;

    /**
     * @param list<array{from_major: int, to_major: int}> $hops
     * @param string $severity Adapters may invent one the report models reject.
     */
    public function __construct(?string $summary, array $hops = [], string $severity = 'medium')
    {
        $this->summary = $summary;
        $this->hops = $hops;
        $this->severity = $severity;
    }

    public function evaluate(
        ProjectState $project,
        UpgradeRequest $request,
        EvidenceLedger $evidence,
        array $sourceUsages = []
    ): ?CompatibilityFinding {
        if ($this->summary === null) {
            return null;
        }

        $evidenceId = $evidence->add('framework', Evidence::E2_PACKAGE_METADATA, 'Framework metadata matched.')->id();

        return new CompatibilityFinding('fixture', $this->severity, $this->summary, [$evidenceId], $this->hops);
    }
}

/** Registers evidence before failing, the way a partially completed adapter rule does. */
final class FixtureThrowingCompatibilityRule implements CompatibilityRule
{
    public function evaluate(
        ProjectState $project,
        UpgradeRequest $request,
        EvidenceLedger $evidence,
        array $sourceUsages = []
    ): ?CompatibilityFinding {
        $evidence->add('framework', Evidence::E2_PACKAGE_METADATA, 'Framework metadata matched.');

        throw new \RuntimeException('rule failed');
    }
}

/** Fails part way through yielding its rules. */
final class FixtureBrokenRuleSetIntegration implements FrameworkIntegration
{
    public function name(): string
    {
        return 'broken';
    }

    public function detect(ProjectState $project): FrameworkDetection
    {
        return new FrameworkDetection('broken', true);
    }

    public function rules(): iterable
    {
        yield new FixtureCompatibilityRule('Review yielded framework.');

        throw new \RuntimeException('rule set failed');
    }

    public function defaultSourcePaths(ProjectState $project): array
    {
        return ['app'];
    }
}
