<?php
// Source-only fixture, no database or network.
define('ABSPATH', __DIR__);function add_action(...$a){}function add_filter(...$a){}
function get_option($key,$default=false){return $default;}function wp_get_environment_type(){return 'staging';}function wp_using_ext_object_cache(){return false;}function has_action(...$a){return false;}
$root=dirname(__DIR__,3).'/wp-content/plugins/mad4b-cache-lifecycle';require $root.'/includes/runtime.php';require $root.'/includes/control.php';
function check($ok,$msg){if(!$ok)throw new RuntimeException($msg);}
$v=MAD4B_Cache_Lifecycle_V1::VERSION;$c=MAD4B_Cache_Lifecycle_Control_V1::class;
check(preg_match('/^\s*\* Version: ([^\r\n]+)$/m',file_get_contents($root.'/mad4b-cache-lifecycle.php'),$m)&&$m[1]===$v,'header identity');
check($c::status()['version']===$v,'status identity');check($c::manifest([])[0]['implementation_version']===$v,'provider identity');
check(hash_file('sha256',$root.'/config/private-cache.htaccess')==='32e2c50189b52a66e6a53aafa5026f3e874b8879196d783bdb29e23a459e967a','original policy');
check(count($c::private_rule_lines()??[])>0,'policy readable');check(!str_contains(file_get_contents($root.'/includes/runtime.php'),'Plugin Name:'),'internal header removed');
echo "CACHE_RELEASE_CONTRACT: PASS 6\n";
