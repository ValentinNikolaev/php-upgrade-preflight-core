<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Composer;

use PhpUpgradePreflight\Core\Model\ComposerExecutionConfiguration;
use PhpUpgradePreflight\Core\Model\ComposerJson;
use PhpUpgradePreflight\Core\Model\ProjectState;
use PhpUpgradePreflight\Core\Model\Scenario;
use PhpUpgradePreflight\Core\Model\TargetPlatform;
use Symfony\Component\Filesystem\Path;

/**
 * Owns everything written into, or exported to, an analyzer-owned Composer
 * workspace. The analyzed project is never touched: every manifest change is
 * applied to the temporary copy only.
 */
final class ScenarioWorkspacePreparer
{
    public function seedProjectState(string $tempPath, ProjectState $project): void
    {
        $files = [
            'composer.json' => $project->composerJson()->data(),
            'composer.lock' => $project->composerLock()->data(),
        ];
        foreach ($files as $name => $data) {
            $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
            if (@file_put_contents($tempPath . DIRECTORY_SEPARATOR . $name, $encoded) === false) {
                throw new \RuntimeException(sprintf('Unable to seed the temporary %s.', $name));
            }
        }
    }

    /** @param array<string, string>|null $analyzerPlatformPackages */
    public function applyTemporaryComposerChanges(
        string $tempPath,
        ProjectState $project,
        Scenario $scenario,
        TargetPlatform $platform,
        ?array $analyzerPlatformPackages = null
    ): ComposerJson {
        $composerPath = $tempPath . DIRECTORY_SEPARATOR . 'composer.json';
        $data = $project->composerJson()->data();

        foreach ($scenario->targets()->packageTargets() as $target) {
            if (isset($data['require-dev']) && is_array($data['require-dev']) && array_key_exists($target->package(), $data['require-dev'])
                && (!isset($data['require']) || !is_array($data['require']) || !array_key_exists($target->package(), $data['require']))) {
                $data['require-dev'][$target->package()] = $target->constraint();
                continue;
            }

            if (!isset($data['require']) || !is_array($data['require'])) {
                $data['require'] = [];
            }

            $data['require'][$target->package()] = $target->constraint();
        }

        $platformOverrides = $platform->composerPlatformOverrides($analyzerPlatformPackages ?? []);
        if ($scenario->targets()->targetPhp() !== null) {
            $platformOverrides = ['php' => $scenario->targets()->targetPhp()] + $platformOverrides;
        }

        if ($platformOverrides !== []) {
            $data = $this->withPlatformOverrides($data, $platformOverrides, $project->path());
        }

        $candidateManifest = new ComposerJson($data);
        $data = $this->absolutePathRepositories($data, $project->path());

        $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
        if (@file_put_contents($composerPath, $encoded) === false) {
            throw new \RuntimeException('Unable to write the temporary Composer manifest.');
        }

        return $candidateManifest;
    }

    /**
     * Builds the child-process environment. In restricted mode this also creates
     * the analyzer-owned Composer state inside the working directory, so it can
     * fail before any Composer process is started.
     *
     * @return array<string, string|false>
     */
    public function processEnvironment(
        ComposerExecutionConfiguration $execution,
        string $workingDirectory
    ): array {
        $environment = [
            'COMPOSER_NO_INTERACTION' => '1',
            'COMPOSER_NO_AUDIT' => '1',
        ];
        if (!$execution->isRestricted()) {
            return $environment;
        }

        $state = $workingDirectory . DIRECTORY_SEPARATOR . '.php-upgrade-preflight-composer';
        $composerHome = $state . DIRECTORY_SEPARATOR . 'home';
        $cache = $state . DIRECTORY_SEPARATOR . 'cache';
        $xdgConfig = $state . DIRECTORY_SEPARATOR . 'xdg-config';
        $xdgData = $state . DIRECTORY_SEPARATOR . 'xdg-data';
        $xdgCache = $state . DIRECTORY_SEPARATOR . 'xdg-cache';
        foreach ([$composerHome, $cache, $xdgConfig, $xdgData, $xdgCache] as $directory) {
            if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
                throw new \RuntimeException('Unable to create analyzer-owned restricted Composer state.');
            }
        }
        foreach (['config.json', 'auth.json'] as $file) {
            if (@file_put_contents($composerHome . DIRECTORY_SEPARATOR . $file, "{}\n") === false) {
                throw new \RuntimeException('Unable to initialize analyzer-owned restricted Composer configuration.');
            }
        }

        return array_merge($environment, [
            'COMPOSER' => false,
            'COMPOSER_HOME' => $composerHome,
            'COMPOSER_CACHE_DIR' => $cache,
            'COMPOSER_AUTH' => '{}',
            'COMPOSER_DISABLE_NETWORK' => '1',
            'XDG_CONFIG_HOME' => $xdgConfig,
            'XDG_DATA_HOME' => $xdgData,
            'XDG_CACHE_HOME' => $xdgCache,
            'HTTP_PROXY' => false,
            'HTTPS_PROXY' => false,
            'ALL_PROXY' => false,
            'NO_PROXY' => false,
            'http_proxy' => false,
            'https_proxy' => false,
            'all_proxy' => false,
            'no_proxy' => false,
            'GIT_ASKPASS' => false,
            'SSH_ASKPASS' => false,
            'GIT_TERMINAL_PROMPT' => '0',
        ]);
    }

    /**
     * Writes the simulated platform values into the temporary manifest.
     *
     * Composer matches `config.platform` names case-insensitively, so the rewrite
     * reuses whatever casing the project already declared for a package. Adding a
     * lowercase key beside an existing case variant would manufacture a
     * contradiction that {@see ComposerJson} rightly rejects, and every scenario
     * would fail preparation because of a manifest the analyzer wrote itself.
     *
     * A manifest whose `config` or `config.platform` is not an object cannot hold
     * those values at all. That is invalid project input, not a broken analysis
     * environment, so it is reported as such instead of fataling.
     *
     * @param array<string, mixed> $data
     * @param array<string, string|false> $overrides
     * @return array<string, mixed>
     */
    private function withPlatformOverrides(array $data, array $overrides, string $projectPath): array
    {
        $config = $data['config'] ?? [];
        if (!is_array($config) || $this->isNonEmptyList($config)) {
            throw $this->invalidManifest($projectPath, 'its "config" section is not an object');
        }

        $platform = $config['platform'] ?? [];
        if (!is_array($platform) || $this->isNonEmptyList($platform)) {
            throw $this->invalidManifest($projectPath, 'its "config.platform" section is not an object');
        }

        foreach ($overrides as $package => $value) {
            $keys = $this->declaredPlatformKeys($platform, (string) $package);
            if ($keys === []) {
                $keys = [$package];
            }

            foreach ($keys as $key) {
                $platform[$key] = $value;
            }
        }

        $config['platform'] = $platform;
        $data['config'] = $config;

        return $data;
    }

    /** @param array<array-key, mixed> $value */
    private function isNonEmptyList(array $value): bool
    {
        return $value !== [] && array_keys($value) === range(0, count($value) - 1);
    }

    /**
     * Every key the project already uses for one platform package. A manifest may
     * legally declare several case variants as long as they agree, so all of them
     * have to move together.
     *
     * @param array<array-key, mixed> $platform
     * @return list<array-key>
     */
    private function declaredPlatformKeys(array $platform, string $package): array
    {
        $keys = [];
        foreach (array_keys($platform) as $name) {
            if (is_string($name) && strtolower(trim($name)) === $package) {
                $keys[] = $name;
            }
        }

        return $keys;
    }

    private function invalidManifest(string $projectPath, string $reason): InvalidJsonException
    {
        return new InvalidJsonException(
            $projectPath . DIRECTORY_SEPARATOR . 'composer.json',
            sprintf('Composer file "composer.json" cannot be analyzed because %s.', $reason)
        );
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function absolutePathRepositories(array $data, string $projectPath): array
    {
        if (!isset($data['repositories']) || !is_array($data['repositories'])) {
            return $data;
        }

        foreach ($data['repositories'] as $key => $repository) {
            if (!is_array($repository)
                || !in_array($repository['type'] ?? null, ['path', 'artifact'], true)
                || !isset($repository['url'])
                || !is_string($repository['url'])
            ) {
                continue;
            }

            $url = $repository['url'];
            if ($url === '' || Path::isAbsolute($url) || str_starts_with($url, '~') || $this->containsEnvironmentVariable($url)) {
                continue;
            }

            $repository['url'] = Path::makeAbsolute($url, $projectPath);
            $data['repositories'][$key] = $repository;
        }

        return $data;
    }

    private function containsEnvironmentVariable(string $path): bool
    {
        return preg_match('/\$(?:\{[A-Za-z_][A-Za-z0-9_]*\}|[A-Za-z_][A-Za-z0-9_]*)|%[A-Za-z_][A-Za-z0-9_]*%/', $path) === 1;
    }
}
