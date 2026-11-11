<?php
/**
 * Read-only WP-CLI regression for anonymous date/currency origin isolation.
 * wp eval-file /path/to/tests/cache/unified-date-origin-context-readonly.php --allow-root
 */
if (!defined('ABSPATH') || !class_exists('MAD4B_Cache_Lifecycle_V1', false)) {
    throw new RuntimeException('DATE_CACHE_LIFECYCLE_NOT_AVAILABLE');
}
$oldGet = $_GET;
$oldCookie = $_COOKIE;
$cases = [
    ['public_no_context', [], null],
    ['date_fixed_mode', ['mad4b_date_mode'=>'fixed'], 'personalized_query'],
    ['date_from', ['mad4b_date_from'=>'2026-12-15'], 'personalized_query'],
    ['date_to', ['mad4b_date_to'=>'2026-12-20'], 'personalized_query'],
    ['date_clear', ['mad4b_date_clear'=>'1'], 'personalized_query'],
    ['wcml_currency', ['wcmlc'=>'EUR'], 'personalized_query'],
    ['legacy_travel_date', ['travel_date'=>'2026-12-20'], 'personalized_query'],
    ['ordinary_tracking', ['utm_source'=>'audit'], null],
];
try {
    $_COOKIE = [];
    foreach ($cases as [$label,$get,$expected]) {
        $_GET = $get;
        $actual = MAD4B_Cache_Lifecycle_V1::context();
        if ($actual !== $expected) {
            throw new RuntimeException('DATE_CONTEXT_MISMATCH: '.$label);
        }
        echo 'PASS '.$label.PHP_EOL;
    }
    $_GET = [];
    $_COOKIE = ['wcml_client_currency'=>'EUR'];
    if (MAD4B_Cache_Lifecycle_V1::context() !== 'personalized_cookie') {
        throw new RuntimeException('COOKIE_CURRENCY_NOT_PRIVATE');
    }
    echo 'PASS cookie_currency'.PHP_EOL;
} finally {
    $_GET = $oldGet;
    $_COOKIE = $oldCookie;
}
echo 'PASS_ORIGIN_PRIVATE_CONTEXT_CONTRACT'.PHP_EOL;
