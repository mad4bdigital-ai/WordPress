<?php
/** Original MCP Adapter release local layout and source evidence, no HTTP/write. */
define('ABSPATH',__DIR__.'/');
define('WP_PLUGIN_DIR',sys_get_temp_dir().'/mad4b-mcp-plugin-test-'.getmypid());
function wp_normalize_path($s){return str_replace('\\','/',(string)$s);}
function trailingslashit($s){return rtrim($s,'/').'/';}
function get_file_data($path,$headers,$context=''){
 $content=file_get_contents($path);
 return ['Version'=>preg_match('/Version:\\s*([^\\n]+)/',$content,$m)?trim($m[1]):''];
}
function get_option($key,$default=[]){return $GLOBALS['options'][$key]??$default;}
function source_check($yes,$reason){
 if(!$yes){fwrite(STDERR,'FAIL: '.$reason.PHP_EOL);exit(1);}
 $GLOBALS['passed']=($GLOBALS['passed']??0)+1;
}
$base=WP_PLUGIN_DIR.'/mcp-adapter';
$files=[
 'mcp-adapter.php'=>"<?php\n/* Plugin Name: Official MCP Adapter\nVersion: 0.7.1\n */\n",
 'includes/Plugin.php'=>'<?php /* Stub */',
 'includes/Core/McpAdapter.php'=>'<?php /* Native source */',
 'includes/Core/McpServer.php'=>'<?php /* Native REST */'
];
foreach($files as $name=>$body){
 $file=$base.'/'.$name;
 if(!is_dir(dirname($file)))mkdir(dirname($file),0700,true);
 file_put_contents($file,$body);
}
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-mcp-adapter-dynamic-integrity.php';
$c='MAD4B_SCP_MCP_Adapter_Dynamic_Integrity';
$GLOBALS['options']=[];
$good=$c::inspect('mcp-adapter/mcp-adapter.php','0.7.1');
source_check($good['ready']===true && $good['state']==='updated_release_layout_ready_source_unverified'
 && $good['source_verified']===false && $good['runtime_write_authorized']===false
 && !$good['external_fetch_performed'],'Local dynamic adapter layout must be parsed without inventing upstream official authority');
$file_hashes=[];
foreach(array_keys($files) as $filename)
 $file_hashes[$filename]=hash_file('sha256',$base.'/'.$filename);
ksort($file_hashes);
$GLOBALS['options'][$c::CACHE_OPTION]=[
 'version'=>'0.7.1','upstream_sha256'=>str_repeat('b',64),
 'verified_hashes'=>$file_hashes,'verified_at'=>time()
];
$forged=$c::inspect('mcp-adapter/mcp-adapter.php','0.7.1');
source_check($forged['source_verified']===false && $forged['official_package_verified']===false,
 'A WordPress option containing locally fabricated matching hashes cannot certify upstream distribution');
source_check($forged['runtime_write_authorized']===false
 &&$forged['external_fetch_performed']===false,
 'Read-only cached plugin evidence never initiates remote download or execution');
source_check($forged['cached_receipt_present']===true
 &&$forged['state']==='cached_installed_layout_consistent_source_unverified',
 'Local cached digest comparison remains available as non-authorizing diagnostics');
$GLOBALS['options'][$c::CACHE_OPTION]['verified_hashes']['includes/Plugin.php']=str_repeat('0',64);
$drift=$c::inspect('mcp-adapter/mcp-adapter.php','0.7.1');
source_check(!$drift['ready']&&$drift['state']==='official_package_file_mismatch',
 'Local installed files drifting from a prior cached manifest are held for review');
$GLOBALS['options']=array();
$bad=$c::inspect('mcp-adapter/mcp-adapter.php','0.7.2');
source_check($bad['state']==='installed_version_identity_mismatch',
 'Plugin header mismatch cannot certify dynamically');
foreach(['../../escape.php','another/main.php','mcp-adapter/../mcp-adapter.php',
 '/var/www/mcp-adapter.php'] as $path){
 $row=$c::inspect($path,'0.7.1');
 source_check(!$row['ready'] && !$row['source_verified'],
 'Plugin path traversal cannot adopt arbitrary file as original plugin');
}
foreach(['0.7','0.7.1beta','0.7.1;system','v0.7.1','01.1.1'] as $version){
 $row=$c::inspect('mcp-adapter/mcp-adapter.php',$version);
 source_check(!$row['ready'],'Invalid semantic version cannot become layout proof');
}
$future=$c::inspect('mcp-adapter/mcp-adapter.php','4.0.0');
source_check(!$future['source_verified'],'Future version cannot become certified without exact installed identity');
unlink($base.'/includes/Core/McpServer.php');
$missing=$c::inspect('mcp-adapter/mcp-adapter.php','0.7.1');
source_check(!$missing['ready'] && $missing['state']==='runtime_core_file_missing',
 'Missing original Core class blocks local compatibility');
file_put_contents($base.'/includes/Core/McpServer.php',$files['includes/Core/McpServer.php']);
foreach(array_reverse(array_keys($files)) as $name)unlink($base.'/'.$name);
rmdir($base.'/includes/Core');rmdir($base.'/includes');rmdir($base);rmdir(WP_PLUGIN_DIR);
echo 'PASS: MCP_DYNAMIC_INTEGRITY '.($GLOBALS['passed']??0).
 ' original SemVer, plugin-layout and no-upstream-authorization cases'.PHP_EOL;
