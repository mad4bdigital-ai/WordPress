<?php
/** Hermetic certificate-route encoding invariants, no WP nor external requests. */
declare(strict_types=1);
define('ABSPATH','/tmp/');
function add_action(...$args):void{}
function add_filter(...$args):void{}
require __DIR__.'/source-422-under-test.php';
$c=MAD4B_Public_Critical_CSS_Runtime_V1::class;
$test=static function(bool $ok,string $message):void {
    if(!$ok)throw new RuntimeException('FAIL '.$message);
    echo 'PASS '.$message."\n";
};
$raw='/ar/tours-and-activities/%D8%B1%D8%AD%D9%84%D8%A9-%D9%85%D9%86-%D8%A7%D9%84%D9%82%D8%A7%D9%87%D8%B1%D8%A9/';
$lower='/ar/tours-and-activities/%d8%b1%d8%ad%d9%84%d8%a9-%d9%85%d9%86-%d8%a7%d9%84%d9%82%d8%a7%d9%87%d8%b1%d8%a9/';
$test($c::normalized_escaped_path($raw)===$lower,'Uppercase escaped Arabic URL equals canonical lower-escaped route');
$test($c::normalized_escaped_path($lower)===$lower,'Canonical encoded route is idempotent');
$test($c::normalized_escaped_path('/Tours/Test/Slug/')==='/Tours/Test/Slug/','Normal slug case is preserved');
$test($c::normalized_escaped_path('/a/%2F/')==='/a/%2f/','No percent decode or path restructuring');
$test($c::normalized_escaped_path('/ar/%D8%a7MixedCase-USD/')==='/ar/%d8%a7MixedCase-USD/','Mixed hex casing normalizes without changing market-like content');
$test($c::normalized_escaped_path('/ar/path?lang=AR')==='/ar/path?lang=AR','Query-like literal passed to pure helper unchanged (caller parses path)');
