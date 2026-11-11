<?php
/**
 * Hermetic early-MU forward-compatibility regression.
 *
 * Synthesizes the known structural reference layout in a temporary directory.
 * Future version labels must neither require an installed-version allowlist nor
 * become write certification. This is NOT a future upstream ABI certification.
 * No WordPress boot, production filesystem mutation, grants, or network.
 */
declare(strict_types=1);

function wp_normalize_path(string $path): string {
    return str_replace('\\', '/', $path);
}

$root = dirname(__DIR__);
$source = file_get_contents($root . '/bootstrap/mad4b-mcp-adapter-mu-bootstrap.php');
if (!is_string($source)) {
    throw new RuntimeException('MU source unavailable');
}
$start = strpos($source, "/**\n * PERMANENT FORWARD-UPDATE BEHAVIOR");
$end = false === $start ? false : strpos($source, "\n\$mad4b_mcp_mu_critical_hashes =", $start);
if (false === $start || false === $end || $end <= $start) {
    throw new RuntimeException('MU dynamic selection source markers changed');
}
$block = substr($source, $start, $end - $start);

$catalog = json_decode(
    (string) file_get_contents($root . '/config/certified-provider-profiles.json'),
    true,
    512,
    JSON_THROW_ON_ERROR
);
$references = $catalog['providers']['mcp_adapter'] ?? array();
$versions = array();
foreach ($references as $version => $profile) {
    if (is_array($profile)
        && !empty($profile['critical_files'])
        && !empty($profile['runtime_symbols'])
        && preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D', (string) $version)) {
        $versions[] = (string) $version;
    }
}
if (empty($versions)) {
    throw new RuntimeException('No complete reference profile');
}
usort($versions, 'version_compare');
$reference_version = end($versions);
$reference = $references[$reference_version];
$temp = sys_get_temp_dir() . '/mad4b-mu-fixture-' . bin2hex(random_bytes(8));
$plugin_root = $temp . '/mcp-adapter/';
if (!mkdir($plugin_root, 0700, true)) {
    throw new RuntimeException('Unable to create isolated fixture');
}

$write_fixture = static function (string $relative) use ($plugin_root): void {
    $relative = str_replace('\\', '/', $relative);
    if ($relative === '' || $relative[0] === '/' || strpos($relative, '..') !== false
        || !preg_match('#^[a-zA-Z0-9._/-]+$#D', $relative)) {
        throw new RuntimeException('Invalid reference fixture path');
    }
    $file = $plugin_root . $relative;
    if (!is_dir(dirname($file)) && !mkdir(dirname($file), 0700, true)) {
        throw new RuntimeException('Unable to create fixture directory');
    }
    if (false === file_put_contents($file, "<?php\n/* structural-only fixture */\n")) {
        throw new RuntimeException('Unable to populate fixture');
    }
};
$paths = array_keys($reference['critical_files']);
foreach ($reference['runtime_symbols'] as $symbol) {
    if (!is_array($symbol) || empty($symbol['file'])) {
        throw new RuntimeException('Malformed runtime symbol profile');
    }
    $paths[] = (string) $symbol['file'];
}
$paths = array_values(array_unique($paths));

$checks = 0;
$assert = static function (bool $condition, string $name) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException('FAIL ' . $name);
    }
    $checks++;
};
$run = static function (string $version, array $profiles) use ($block, $plugin_root): array {
    $mad4b_mcp_mu_adapter_version = $version;
    $mad4b_mcp_mu_profiles_catalog = $profiles;
    $mad4b_mcp_mu_selected_profile = array();
    $mad4b_mcp_mu_status = array();
    $mad4b_mcp_mu_root = $plugin_root;
    eval($block);
    return array(
        'dynamic' => !empty($mad4b_mcp_mu_status['adapter_profile_dynamic']),
        'profile_source' => $mad4b_mcp_mu_status['adapter_profile_source'] ?? '',
        'reference' => $mad4b_mcp_mu_status['dynamic_reference_version'] ?? '',
        'needs_readback' => !empty($mad4b_mcp_mu_status['dynamic_requires_runtime_class_readback']),
        'symbols' => count($mad4b_mcp_mu_selected_profile['runtime_symbols'] ?? array()),
        'critical' => count($mad4b_mcp_mu_selected_profile['critical_files'] ?? array()),
    );
};

try {
    foreach ($paths as $path) {
        $write_fixture((string) $path);
    }
    $parts = array_map('intval', explode('.', $reference_version));
    $future = array(
        $parts[0] . '.' . $parts[1] . '.' . ($parts[2] + 1),
        ($parts[0] + 1) . '.0.0',
        ($parts[0] + 12) . '.2.7',
        ($parts[0] + 1) . '.0.0-beta.1',
    );
    foreach ($future as $version) {
        $observed = $run($version, $catalog);
        $assert($observed['dynamic'], 'forward_layout_' . $version);
        $assert($observed['profile_source'] === 'installed_compatible_layout', 'layout_source_' . $version);
        $assert($observed['reference'] === $reference_version, 'nearest_known_reference_' . $version);
        $assert($observed['needs_readback'], 'runtime_readback_required_' . $version);
        $assert($observed['symbols'] === count($reference['runtime_symbols']), 'complete_symbols_' . $version);
        $assert($observed['critical'] === count($reference['critical_files']), 'complete_critical_files_' . $version);
    }
    foreach (array('0.0.1', '2.0/invalid', str_repeat('9', 81)) as $version) {
        $assert(!$run($version, $catalog)['dynamic'], 'not_dynamic_' . substr($version, 0, 25));
    }
    $assert(!$run($future[1], array('providers' => array('mcp_adapter' => array())))['dynamic'], 'missing_profile_denied');
    $first_file = (string) array_key_first($reference['critical_files']);
    unlink($plugin_root . $first_file);
    $assert(!$run($future[1], $catalog)['dynamic'], 'missing_reference_file_denied');
    $write_fixture($first_file);
    $bad = $catalog;
    $bad['providers']['mcp_adapter'][$reference_version]['runtime_symbols'][0]['file'] = '../escaped.php';
    $assert(!$run($future[1], $bad)['dynamic'], 'path_traversal_denied');
    echo json_encode(array(
        'state' => 'PASS',
        'contract' => 'mad4b.mcp-mu-forward-structural-fixture.v1',
        'checks' => $checks,
        'reference_profile_only' => $reference_version,
        'runtime_version_gate' => false,
        'future_abi_certified' => false,
        'production_mutation' => false,
        'network' => false,
    ), JSON_UNESCAPED_SLASHES) . PHP_EOL;
} finally {
    $walker = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($temp, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($walker as $file) {
        if ($file->isDir()) {
            rmdir($file->getPathname());
        } else {
            unlink($file->getPathname());
        }
    }
    rmdir($temp);
}
