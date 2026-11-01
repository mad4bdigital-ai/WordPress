<?php
/**
 * MAD4B Staging non-privileged container writer.
 *
 * Stores review metadata ONLY in a dedicated private mount. It cannot
 * install PHP, upload source to GitHub, execute commands, grant privileges,
 * or change WordPress. It is not a fallback for a denied write.
 */
declare(strict_types=1);

const CONTRACT = 'mad4b.container-parity-review-proposal.v1';
const SITE = 'https://tours.dev.mad4b.com';
const SCOPE = 'review_only';
const OUTPUT_DIRECTORY = '/workspace/proposals';
const PATHS = [
    'ops/company-readiness-workflow/executor/vps-native-operator.py',
    'ops/mad4b-broker/vps-native-central-auto-dispatch.py',
    'ops/mad4b-broker/vps-native-central-dispatch-worker.py',
    'ops/mad4b-broker/systemd/mad4b-native-central-dispatch.service',
    'ops/mad4b-broker/systemd/mad4b-native-central-dispatch.timer',
];

function stop_with(string $message): never {
    fwrite(STDERR, "CONTAINER_PROPOSAL_DENIED: {$message}\n");
    exit(2);
}

if (PHP_SAPI !== 'cli' || !function_exists('posix_geteuid')
    || posix_geteuid() !== 33) {
    stop_with('EXPECTED_NON_ROOT_CONTAINER_UID_33');
}
if ($argc !== 1) {
    stop_with('NO_CLI_ARGUMENTS_ALLOWED');
}
$directory = OUTPUT_DIRECTORY;
if (is_link($directory) || !is_dir($directory)
    || realpath($directory) !== $directory
    || fileowner($directory) !== 33
    || (fileperms($directory) & 0077) !== 0) {
    stop_with('PRIVATE_UID33_REVIEW_MOUNT_REQUIRED');
}

$input = stream_get_contents(STDIN, 16385);
if (!is_string($input) || strlen($input) === 0 || strlen($input) > 16384) {
    stop_with('INPUT_LENGTH_INVALID');
}
try {
    $data = json_decode($input, true, 32, JSON_THROW_ON_ERROR);
} catch (JsonException $exception) {
    stop_with('JSON_INVALID');
}
if (!is_array($data) || array_keys($data) !== [
        'contract', 'site', 'environment', 'scope', 'source_branch',
        'source_head', 'entries',
    ]) {
    stop_with('EXACT_MANIFEST_FIELDS_REQUIRED');
}
if ($data['contract'] !== CONTRACT || $data['site'] !== SITE
    || $data['environment'] !== 'staging' || $data['scope'] !== SCOPE
    || $data['source_branch'] !== 'feature/central-local-native-handoff-20261101'
    || !is_string($data['source_head'])
    || preg_match('/^[a-f0-9]{40}$/D', $data['source_head']) !== 1
    || !is_array($data['entries']) || count($data['entries']) !== count(PATHS)) {
    stop_with('STAGING_REVIEW_SCOPE_INVALID');
}
foreach (PATHS as $index => $expected) {
    $entry = $data['entries'][$index] ?? null;
    if (!is_array($entry)
        || array_keys($entry) !== ['path', 'source_sha256', 'staging_sha256']
        || $entry['path'] !== $expected
        || !is_string($entry['staging_sha256'])
        || preg_match('/^[a-f0-9]{64}$/D', $entry['staging_sha256']) !== 1
        || ($entry['source_sha256'] !== null
            && (!is_string($entry['source_sha256'])
                || preg_match('/^[a-f0-9]{64}$/D', $entry['source_sha256']) !== 1))) {
        stop_with('REVIEW_ENTRY_UNAUTHORIZED');
    }
}
$record = [
    'contract' => CONTRACT,
    'site' => SITE,
    'environment' => 'staging',
    'scope' => SCOPE,
    'source_branch' => $data['source_branch'],
    'source_head' => $data['source_head'],
    'entries' => $data['entries'],
    'status' => 'REVIEW_REQUIRED_NO_PRIVILEGED_WRITE',
    'git_write_performed' => false,
    'wordpress_write_performed' => false,
    'shell_execution_performed' => false,
    'production_mutation' => false,
];
$output = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
$sha = hash('sha256', $output);
$path = $directory . '/parity-' . $sha . '.json';
$handle = @fopen($path, 'x');
if ($handle === false) {
    stop_with('DUPLICATE_OR_UNAVAILABLE');
}
try {
    if (!chmod($path, 0600) || fwrite($handle, $output) !== strlen($output)
        || !fflush($handle)) {
        throw new RuntimeException('PERSISTENCE_FAILED');
    }
    if (function_exists('fsync') && !fsync($handle)) {
        throw new RuntimeException('SYNC_FAILED');
    }
} catch (Throwable $exception) {
    fclose($handle);
    @unlink($path);
    stop_with('PERSISTENCE_FAILED');
}
fclose($handle);
echo json_encode([
    'contract' => CONTRACT,
    'state' => 'PRIVATE_REVIEW_PROPOSAL_SAVED',
    'proposal_sha256' => $sha,
    'source_parity_approved' => false,
    'privileged_code_uploaded' => false,
    'production_mutation' => false,
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
