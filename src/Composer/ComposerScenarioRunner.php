<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Composer;

use PhpUpgradePreflight\Core\Filesystem\TemporaryWorkspaceManager;
use PhpUpgradePreflight\Core\Model\ComposerLock;
use PhpUpgradePreflight\Core\Model\ProjectState;
use PhpUpgradePreflight\Core\Model\Scenario;
use PhpUpgradePreflight\Core\Model\ScenarioResult;
use PhpUpgradePreflight\Core\Model\UpgradeRequest;
use Symfony\Component\Process\Process;

final class ComposerScenarioRunner
{
    private TemporaryWorkspaceManager $workspaces;
    private JsonFileReader $reader;
    /** @var \Closure(list<string>, string): array{exit_code: int, stdout: string, stderr: string} */
    private \Closure $processRunner;

    /**
     * @param null|callable(list<string>, string): array{exit_code: int, stdout: string, stderr: string} $processRunner
     */
    public function __construct(
        ?TemporaryWorkspaceManager $workspaces = null,
        ?JsonFileReader $reader = null,
        ?callable $processRunner = null
    ) {
        $this->workspaces = $workspaces ?? new TemporaryWorkspaceManager();
        $this->reader = $reader ?? new JsonFileReader();
        $this->processRunner = $processRunner === null
            ? \Closure::fromCallable([$this, 'runProcess'])
            : \Closure::fromCallable($processRunner);
    }

    public function run(ProjectState $project, UpgradeRequest $request, Scenario $scenario): ScenarioResult
    {
        $tempPath = $this->workspaces->createFromProject($project->path);

        try {
            $this->applyTemporaryComposerChanges($tempPath, $request, $scenario);
            $command = $this->buildCommand($scenario);
            $process = ($this->processRunner)($command, $tempPath);

            $lock = null;
            $lockPath = $tempPath . DIRECTORY_SEPARATOR . 'composer.lock';
            if ($process['exit_code'] === 0 && is_file($lockPath)) {
                $lock = new ComposerLock($this->reader->read($lockPath));
            }

            return new ScenarioResult(
                $scenario,
                $process['exit_code'],
                $process['stdout'],
                $process['stderr'],
                $lock,
                $request->debug ? $tempPath : null
            );
        } catch (\Throwable $exception) {
            return new ScenarioResult($scenario, 1, '', $exception->getMessage(), null, $request->debug ? $tempPath : null);
        } finally {
            if (!$request->debug) {
                $this->workspaces->remove($tempPath);
            }
        }
    }

    /** @return list<string> */
    private function buildCommand(Scenario $scenario): array
    {
        $command = ['composer', 'update'];

        foreach ($scenario->targets->packageTargets() as $target) {
            $command[] = $target->package;
        }

        if ($scenario->withAllDependencies) {
            $command[] = '--with-all-dependencies';
        }

        if ($scenario->minimalChanges) {
            $command[] = '--minimal-changes';
        }

        $command[] = '--no-scripts';
        $command[] = '--no-plugins';
        $command[] = '--no-interaction';

        return $command;
    }

    /**
     * @param list<string> $command
     * @return array{exit_code: int, stdout: string, stderr: string}
     */
    private function runProcess(array $command, string $workingDirectory): array
    {
        $process = new Process($command, $workingDirectory, ['COMPOSER_NO_INTERACTION' => '1'], null, 300);
        $process->run();

        return [
            'exit_code' => $process->getExitCode() ?? 1,
            'stdout' => $process->getOutput(),
            'stderr' => $process->getErrorOutput(),
        ];
    }

    private function applyTemporaryComposerChanges(string $tempPath, UpgradeRequest $request, Scenario $scenario): void
    {
        $composerPath = $tempPath . DIRECTORY_SEPARATOR . 'composer.json';
        $data = $this->reader->read($composerPath);

        foreach ($scenario->targets->packageTargets() as $target) {
            $data['require'][$target->package] = $target->constraint;
        }

        if ($scenario->targets->targetPhp() !== null) {
            $data['config']['platform']['php'] = $scenario->targets->targetPhp();
        }

        file_put_contents($composerPath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    }
}
