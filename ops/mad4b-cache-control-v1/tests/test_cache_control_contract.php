<?php
error_reporting(E_ALL);
define('ABSPATH','/');
define('WP_CONTENT_DIR','/tmp/MAD4B_NO_SOCKET_FIXTURE');
function add_action($hook,$cb){return true;}
function home_url($path='/'){return 'https://example.test'.$path;}
function wp_parse_url($value){return parse_url($value);}
require $argv[1];
$base='https://example.test';
$valid=['/','/ar/','/tours-and-activities/egypt-trip/','/es/tours-and-activities/egypt-trip/','/%D8%A7%D9%84%D9%85%D8%B5%D8%B1/'];
foreach($valid as $path) {
    if(MAD4B_Cache_Control_V1::path_from_url($base.$path)!==$path)
        throw new RuntimeException('Valid route rejected: '.$path);
}
$blocked=[
'https://external.example/tour/',
'https://example.test/checkout/',
'https://example.test/es/checkout/',
'https://example.test/ar/cart/',
'https://example.test/it/order-pay/',
'https://example.test/fr/my-account/',
'https://example.test/fr/wp-json/orders',
'https://example.test/wp-admin/',
'https://example.test/blog/?customer=123',
'https://example.test/../wp-admin/',
'https://example.test/tour//duplicate/',
'https://example.test/es/%2e%2e/checkout/',
'https://example.test/%252e%252e/checkout/',
'https://example.test/ar/checkout%2fsecret',
'https://example.test/ar/%00checkout/',
'https://example.test/ar/%ZZ-invalid/',
'https://example.test:8080/tour/',
'http://example.test/tour/',
];
foreach($blocked as $url) {
    if(MAD4B_Cache_Control_V1::path_from_url($url)!==null)
        throw new RuntimeException('Private or invalid route accepted');
}
foreach(['/checkout/','/es/checkout/','/wp-admin/','/../','/ar/wp-json/api/'] as $path){
    $result=MAD4B_Cache_Control_V1::socket_purge([$path]);
    if($result['status']!=='invalid_path')
        throw new RuntimeException('Socket accepted private route: '.$path);
}
echo "MAD4B_CACHE_CONTROL_PURE_CONTRACT_PASS valid=".count($valid).
    " blocked=".count($blocked)." socket_blocked=5\n";
