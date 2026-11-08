<?php
error_reporting(E_ALL);
define('ABSPATH', '/');
define('WP_CONTENT_DIR','/tmp/MAD4B_UNIT_TEST_NO_SOCKET_20261108');
function add_action($n,$cb){return true;}
// Simulate WPML rewriting home_url($path) in an admin POST context.
// The socket validator must NOT use home_url for already-previewed paths.
function home_url($path='/'){return 'https://example.test/ar'.($path[0]==='/'?$path:'/'.$path);}
function wp_parse_url($v){return parse_url($v);}
require $argv[1];
$paths=json_decode(file_get_contents($argv[2]),true,512,JSON_THROW_ON_ERROR);
if(count($paths)!==6)throw new RuntimeException('SAVED_BATCH_NOT_SIX_PATHS');
foreach($paths as $path){
    if(!MAD4B_Cache_Control_V1::is_safe_public_path($path))
        throw new RuntimeException('SAFE_PREVIEWED_PATH_WAS_REJECTED');
}
$result=MAD4B_Cache_Control_V1::socket_purge($paths);
if(($result['status']??'')!=='socket_unavailable')
    throw new RuntimeException('EXPECTED_VALID_BATCH_THEN_SAFE_NONEXISTENT_SOCKET');
$bad=[
    '/es/checkout/','/ar/cart/','/wp-admin/',
    '/%2e%2e/checkout/','/%252e%252e/checkout/','/a%2fb/',
    '/es/%63heckout/','/es/wp%2dadmin/'
];
foreach($bad as $path){
    if(MAD4B_Cache_Control_V1::is_safe_public_path($path))
        throw new RuntimeException('PRIVATE_PATH_ALLOWED');
    if(MAD4B_Cache_Control_V1::socket_purge([$path])['status']!=='invalid_path')
        throw new RuntimeException('PRIVATE_PATH_REACHED_SOCKET');
}
echo "SAVED_BATCH_NO_SOCKET_PASS six_valid=6 rejected=".count($bad).
 " wpml_home_rewrite_isolated=true cache_mutations=0\n";
