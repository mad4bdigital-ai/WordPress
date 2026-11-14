<?php
/**
 * Read-only WP-CLI function owner inspection.
 * Source expectations are prevalidated externally against the immutable Git
 * manifest. No admin action, grant, file write, or fallback source loader.
 */
if (!defined('ABSPATH') || !defined('WP_CLI') || !WP_CLI) {
    fwrite(STDERR, "WPCLI_ONLY\n");
    exit(1);
}
$payload=getenv('MAD4B_SITE_OWNER_EXPECTATIONS_B64');
$json=is_string($payload)?base64_decode($payload,true):false;
$input=is_string($json)?json_decode($json,true):null;
if (!is_array($input) || !is_string($input['site']??null)
    || !is_array($input['components']??null)) {
    fwrite(STDERR, "OWNER_EXPECTATIONS_INVALID\n");
    exit(1);
}
$result=[];
$all=true;
foreach($input['components'] as $item) {
    if(!is_array($item)
       || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/',(string)($item['symbol']??''))
       || !preg_match('/^[a-f0-9]{64}$/',(string)($item['sha256']??''))) {
        $all=false;continue;
    }
    $expected=(string)($item['runtime_path']??'');
    if(!str_starts_with($expected,'wp-content/') || str_contains($expected,'..')
       || str_contains($expected,'\\') || !str_ends_with($expected,'.php')) {
        $all=false;continue;
    }
    $symbol=$item['symbol'];
    $exists=function_exists($symbol);
    $file=$exists?(new ReflectionFunction($symbol))->getFileName():'';
    $root=wp_normalize_path(ABSPATH);
    $actual=wp_normalize_path((string)$file);
    $relative=$actual!=='' && str_starts_with($actual,$root)
        ?substr($actual,strlen($root)):'';
    $hash=is_file($file)?hash_file('sha256',$file):'';
    $pass=$exists && $relative===$expected
        && hash_equals((string)$item['sha256'],(string)$hash);
    $result[]=[
      'capability'=>(string)($item['capability']??''),
      'symbol'=>$symbol,
      'actual_path'=>$relative,
      'path_matches'=>$relative===$expected,
      'hash_matches'=>$hash===$item['sha256'],
      'pass'=>$pass
    ];
    if(!$pass)$all=false;
}
if(count($result)!==count($input['components']))$all=false;
echo wp_json_encode([
    'site'=>$input['site'],'home'=>home_url(),'status'=>$all?'PASS':'FAIL',
    'owners'=>$result,'source_only_readback'=>true
],JSON_UNESCAPED_SLASHES).PHP_EOL;
if(!$all)exit(1);
