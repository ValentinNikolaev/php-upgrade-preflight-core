<?php
declare(strict_types=1);

/**
 * Opt-in live evaluation helper, intended only for Docker's /evaluation mount.
 * Reads pinned apps, loads separately selected tools and creates new reports.
 * Networked Composer results are not runtime or deterministic-gate evidence.
 */

use PhpUpgradePreflight\Core\Analysis\DefaultUpgradeAnalyzer;
use PhpUpgradePreflight\Core\Model\ComposerExecutionConfiguration;
use PhpUpgradePreflight\Core\Model\ExtensionAssumption;
use PhpUpgradePreflight\Core\Model\UpgradeRequest;
use PhpUpgradePreflight\Core\Model\UpgradeTarget;
use PhpUpgradePreflight\Laravel\LaravelFrameworkIntegration;

function treeDigest(string $root): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        static fn (SplFileInfo $entry): bool => $entry->getFilename() !== '.git'
    ));
    foreach ($iterator as $entry) {
        if ($entry->isFile()) {
            $relative = substr($entry->getPathname(), strlen($root) + 1);
            $files[$relative] = hash_file('sha256', $entry->getPathname());
        }
    }
    ksort($files, SORT_STRING);
    return ['files' => count($files), 'sha256' => hash('sha256', json_encode($files, JSON_THROW_ON_ERROR))];
}

$cases = [
    'crater' => ['from_php' => '7.4.0', 'target_php' => '8.1.0', 'target' => '^9.0'],
    'bookstack10' => ['from_php' => '8.1.0', 'target_php' => '8.2.0', 'target' => '^11.0'],
    'bookstack12' => ['from_php' => '8.2.0', 'target_php' => '8.3.0', 'target' => '^13.0'],
    'bookstack' => ['from_php' => '8.1.0', 'target_php' => '8.2.0', 'target' => '^11.0'],
    'lychee' => ['from_php' => '8.3.0', 'target_php' => '8.3.0', 'target' => '^13.0'],
];
if ($argc !== 4 || !isset($cases[$argv[2]]) || preg_match('/\A[a-z][a-z0-9]*(?:-[a-z0-9]+)*\z/', $argv[3]) !== 1 || strlen($argv[3]) > 64) {
    fwrite(STDERR, "Invalid evaluation arguments. Usage: php evaluate.php <autoload.php> <known-case> <lowercase-hyphenated-label>\n");
    exit(2);
}
$caseName = $argv[2];
$label = $argv[3];
$case = $cases[$caseName];
$root = '/evaluation/apps/' . $caseName;
$destination = '/evaluation/reports/' . $label . '-' . $caseName;
foreach ([$destination . '.json', $destination . '-summary.json'] as $path) {
    if (file_exists($path) || is_link($path)) {
        fwrite(STDERR, "Evaluation report already exists; choose a new label.\n");
        exit(2);
    }
}
if (!is_dir($root) || !is_dir('/evaluation/reports') || !is_file($argv[1])) {
    fwrite(STDERR, "Evaluation requires a readable autoloader and existing /evaluation/apps/<case> and /evaluation/reports directories.\n");
    exit(2);
}
require $argv[1];
$before = treeDigest($root);
$extensions = [];
foreach (['bcmath', 'curl', 'exif', 'fileinfo', 'gd', 'imagick', 'mbstring', 'openssl', 'pdo', 'xml', 'zip'] as $name) {
    $extensions[] = ExtensionAssumption::fromPresenceInput('ext-' . $name);
}
$execution = new ComposerExecutionConfiguration('composer', ComposerExecutionConfiguration::DEFAULT_EXPECTED_VERSION, 45, 15, 'compatible');
$request = new UpgradeRequest($root, [new UpgradeTarget('laravel/framework', $case['target'])], $case['from_php'], $case['target_php'], [], ['laravel'], 'json', null, false, $extensions, null, $execution);
$started = microtime(true);
$report = (new DefaultUpgradeAnalyzer([new LaravelFrameworkIntegration()]))->analyzeUpgrade($request)->toArray();
$duration = microtime(true) - $started;
$after = treeDigest($root);
if ($before !== $after) {
    throw new RuntimeException('Evaluation input changed: ' . $caseName);
}
file_put_contents($destination . '.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
$manifest = json_decode(file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
$lock = json_decode(file_get_contents($root . '/composer.lock'), true, 512, JSON_THROW_ON_ERROR);
$lockedFramework = null;
foreach (array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []) as $package) {
    if ($package['name'] === 'laravel/framework') {
        $lockedFramework = $package['version'];
    }
}
$summary = [
    'case' => $caseName, 'label' => $label, 'metadata' => $report['metadata'],
    'input' => $before, 'input_after' => $after, 'immutable' => true,
    'locked_framework' => $lockedFramework, 'request' => $case,
    'elapsed_seconds' => round($duration, 3), 'report_bytes' => filesize($destination . '.json'),
    'direct' => $report['resolution']['status'],
    'guidance' => $report['transition']['framework_guidance'] ?? [],
    'staged' => ['status' => $report['staged_resolution']['status'], 'execution_state' => $report['staged_resolution']['execution_state'], 'stop_reason' => $report['staged_resolution']['stop_reason']],
    'findings' => $report['framework_findings'] ?? [],
    'blockers' => $report['blockers'] ?? [],
    'uncertainties' => $report['uncertainties'],
    'root_requirements' => array_merge($manifest['require'] ?? [], $manifest['require-dev'] ?? []),
];
file_put_contents($destination . '-summary.json', json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
echo json_encode(['case' => $caseName, 'label' => $label, 'direct' => $summary['direct'], 'staged' => $summary['staged'], 'immutable' => true, 'seconds' => round($duration, 3)], JSON_THROW_ON_ERROR) . "\n";
