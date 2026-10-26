<?php
/** Static bootstrap source-contract for market services; does not boot WordPress. */
$root = dirname(__DIR__);
$bootstrap = file_get_contents($root . '/mad4b-site-control-plane.php');
if (!is_string($bootstrap)) { fwrite(STDERR, 'BOOTSTRAP_MISSING' . PHP_EOL); exit(1); }
$services = [
    'market-profiles' => 'MAD4B_SCP_Market_Profiles',
    'switcher-sources' => null,
    'market-context' => 'MAD4B_SCP_Market_Context',
    'market-preferences' => 'MAD4B_SCP_Market_Preferences',
    'season-calendar' => 'MAD4B_SCP_Season_Calendar',
    'source-hub' => 'MAD4B_SCP_Source_Hub',
    'public-holidays' => 'MAD4B_SCP_Public_Holidays',
];
$failures = 0;
$check = static function ($label, $condition) use (&$failures) {
    echo $label . '=' . ($condition ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$condition) $failures++;
};
foreach ($services as $slug => $class) {
    $include = "require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-" . $slug . ".php';";
    $check('UNIQUE_INCLUDE_' . strtoupper(str_replace('-', '_', $slug)),
        substr_count($bootstrap, $include) === 1
        && is_file($root . '/includes/class-mad4b-scp-' . $slug . '.php'));
    if ($class === null) continue;
    $boot = $class . '::boot();';
    $check('UNIQUE_BOOT_' . strtoupper(str_replace('-', '_', $slug)),
        substr_count($bootstrap, $boot) === 1);
}
$sequence = [
    'MAD4B_SCP_Authorization::boot();',
    'MAD4B_SCP_Market_Profiles::boot();',
    'MAD4B_SCP_Market_Context::boot();',
    'MAD4B_SCP_Market_Preferences::boot();',
];
$positions = array_map(static function ($call) use ($bootstrap) {
    return strpos($bootstrap, $call);
}, $sequence);
$check('CENTRAL_AUTHORIZATION_BOOT_PRECEDES_MARKET',
    !in_array(false, $positions, true) && $positions === array_values($positions)
    && $positions[0] < $positions[1] && $positions[1] < $positions[2] && $positions[2] < $positions[3]);
$check('GENERIC_EXECUTION_FENCE_NOT_REMOVED',
    strpos($bootstrap, "require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-execution-fence.php';") !== false);
$check('NO_DIRECT_MARKET_PRICE_ASSIGNMENT',
    strpos($bootstrap, "MAD4B_SCP_Market_Context::set_price(") === false);
exit($failures ? 1 : 0);
