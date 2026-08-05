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

    public function __construct(?TemporaryWorkspaceManager $workspaces = null, ?JsonFileReader $reader = null)
    {
        $this->workspaces = $workspaces ?? new TemporaryWorkspaceManager();
        $this->reader = $reader ?? new JsonFileReader();
    }

    public function run(ProjectState $project, UpgradeRequest $request, Scenario $scenario): ScenarioResult
    {
        $tempPath = $this->workspaces->createFromProject($project->path);

        try {
            $this->applyTemporaryComposerChanges($tempPath, $request, $scenario);
            $command = $this->buildCommand($scenario);
            $process = new Process($command, $tempPath, ['COMPOSER_NO_INTERACTION' => '1'], null, 300);
            $process->run();

            $lock = null;
            $lockPath = $tempPath . DIRECTORY_SEPARATOR . 'composer.lock';
            if ($process->getExitCode() === 0 && is_file($lockPath)) {
                $lock = new ComposerLock($this->reader->read($lockPath));
            }

            return new ScenarioResult(
                $scenario,
                $process->getExitCode() ?? 1,
                $process->getOutput(),
                $process->getErrorOutput(),
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

        foreach ($scenario->targets as $target) {
            if ($target->package !== 'php') {
                $command[] = $target->package;
            }
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

    private function applyTemporaryComposerChanges(string $tempPath, UpgradeRequest $request, Scenario $scenario): void
    {
        $composerPath = $tempPath . DIRECTORY_SEPARATOR . 'composer.json';
        $data = $this->reader->read($composerPath);

        foreach ($scenario->targets as $target) {
            if ($target->package === 'php') {
                $data['config']['platform']['php'] = $target->constraint;
                continue;
            }

            $data['require'][$target->package] = $target->constraint;
        }

        if ($request->targetPhp !== null) {
            $data['config']['platform']['php'] = $request->targetPhp;
        }

        file_put_contents($composerPath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    }
}
