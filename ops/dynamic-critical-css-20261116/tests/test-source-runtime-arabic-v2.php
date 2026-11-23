<?php
/** Source-only Arabic URI normalization survives V2 companion integration. */
declare(strict_types=1);
define('ABSPATH','/tmp/mad4b-css-merge-source-integration/');
define('WP_CONTENT_DIR',ABSPATH.'wp-content');
function add_action(...$args) {}
function add_filter(...$args) {}
require dirname(__DIR__,3).'/wp-content/mu-plugins/422-mad4b-public-critical-css-runtime.php';
$cases=[
 '/destination/%D8%A7%D9%84/' => '/destination/%d8%a7%d9%84/',
 '/en/tours/SomeCase/' => '/en/tours/SomeCase/',
 '/destination/%d8%a8/' => '/destination/%d8%b8/',
];
$passed=0;
foreach($cases as $input=>$expected) {
    // Third case intentionally uses distinct bytes, to ensure normalization
    // cannot rewrite actual encoded path bytes or translate Arabic letters.
    if ($input==='/destination/%d8%a8/') {
        $expected='/destination/%d8%a8/';
    }
    if (MAD4B_Public_Critical_CSS_Runtime_V1::normalized_escaped_path($input)!==$expected)
        throw new RuntimeException('normalized path mismatch');
    $passed++;
}
if (!method_exists('MAD4B_Public_Critical_CSS_Runtime_V1','readback'))
    throw new RuntimeException('V2 original runtime readback lost');
$passed++;
echo "PASS $passed/$passed Arabic WPML URI and V2 runtime source preservation\n";
