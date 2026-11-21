<?php
/** Hermetic plugin identity readback cannot authorize writes or follow symlinks. */
error_reporting(E_ALL);define('ABSPATH',__DIR__.'/');
$root=sys_get_temp_dir().'/mad4b-provider-'.bin2hex(random_bytes(6));mkdir($root,0700);mkdir($root.'/vendor',0700);define('WP_PLUGIN_DIR',$root);
$f=$root.'/vendor/main.php';file_put_contents($f,"<?php\n/* exact fixture */\n");
register_shutdown_function(static function()use($root,$f){@unlink($f);@unlink($root.'/vendor/alias.php');@rmdir($root.'/vendor');@rmdir($root);});
function get_plugins(){return array('vendor/main.php'=>array('Version'=>'2.8.1'),'vendor/alias.php'=>array('Version'=>'2.8.1'));}
function get_option($name,$default=array()){return $GLOBALS['active'];}
function sanitize_key($s){return preg_replace('/[^a-z0-9_-]/','',strtolower($s));}
function wp_json_encode($v,$f=0){return json_encode($v,$f);}
function ok($check,$what){if(!$check){fwrite(STDERR,'FAIL: '.$what."\n");exit(1);}}
class MockAdapter {public static $version='2.8.1';public function runtime_version(){return self::$version;}}
require dirname(__DIR__).'/includes/class-mad4b-scp-provider-installed-artifact.php';
$cls='MAD4B_SCP_Provider_Installed_Artifact';$GLOBALS['active']=array();$contract=array('plugin_file'=>'vendor/main.php');
$x=$cls::observe('provider-name',array(),null,$contract);ok(!$x['bound']&&!$x['write_authorized'],'Inactive plugin does not bind');
$GLOBALS['active']=array('vendor/main.php');$x=$cls::observe('provider-name',array(),new MockAdapter(),$contract);
ok($x['state']==='exact_active_plugin_observed'&&$x['bound'],'Exactly one active plugin is observed');
ok($x['main_file_sha256']===hash_file('sha256',$f)&&$x['installed_version']==='2.8.1','Hash actual installed main file');
ok($x['runtime_identity_only']&&$x['external_package_certified']===false&&$x['behavioral_verified']===false&&$x['write_authorized']===false,'Identity does not certify package or write');
MockAdapter::$version='1.9.0';$x=$cls::observe('provider-name',array(),new MockAdapter(),$contract);
ok($x['state']==='adapter_version_mismatch'&&!$x['bound'],'Adapter version conflict fails closed');MockAdapter::$version='2.8.1';
$x=$cls::observe('provider',array(),null,array('plugin_file'=>'../vendor/main.php'));
ok(!$x['bound'],'Reject traversal');
$x=$cls::observe('provider',array('artifact_plugin_files'=>array_fill(0,9,'invalid/different'.$f.'.php')));
ok(!$x['bound'],'Reject excessive candidates');
$x=$cls::observe('provider',array(),null,array('plugin_file'=>'vendor/alias.php'));
ok(!$x['bound'],'Reject not-active alternate main file');
$other=tempnam(sys_get_temp_dir(),'outside-plugin-');file_put_contents($other,'<?php /* no */');symlink($other,$root.'/vendor/alias.php');register_shutdown_function(static function()use($other){@unlink($other);});
$GLOBALS['active']=array('vendor/alias.php');$x=$cls::observe('provider',array(),null,array('plugin_file'=>'vendor/alias.php'));
ok(!$x['bound'],'Reject symlink escaping WordPress plugin root');
echo "PASS: active provider identity exact hash, mismatch, traversal, symlink and no writes (9 checks)\n";
