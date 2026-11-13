<?php
/** Pure PHP, read-only runtime ownership evidence tests. */
declare(strict_types=1);
error_reporting(E_ALL);
$root=sys_get_temp_dir().'/mad4b-owner-test-'.bin2hex(random_bytes(5));
$pluginDir=$root.'/wp-content/plugins';$muDir=$root.'/wp-content/mu-plugins';
if(!mkdir($pluginDir.'/mad4b-example/src',0700,true) || !mkdir($muDir,0700,true))throw new RuntimeException('fixture dirs');
define('ABSPATH',$root.'/');define('WP_CLI',true);
define('WP_PLUGIN_DIR',$pluginDir);define('WPMU_PLUGIN_DIR',$muDir);
function home_url(string $path='/'):string{return 'https://example.invalid'.$path;}
function wp_json_encode($data,int $flags=0):string{return json_encode($data,$flags|JSON_THROW_ON_ERROR);}
$old=$muDir.'/357-example.php';$new=$pluginDir.'/mad4b-example/src/bridge.php';
file_put_contents($old,'<?php class TestLegacyModule { public static function handler():void {} }');
file_put_contents($new,'<?php class TestPluginModule { public static function handler():void {} }');
file_put_contents($pluginDir.'/mad4b-example/mad4b-portability.json',json_encode([
  'contract'=>'mad4b.plugin-portability-profile.v1','plugin'=>'mad4b-example',
  'company_specific_legacy_mu_replacements'=>[[
    'legacy_file'=>'357-example.php','replacement'=>'src/bridge.php',
    'reconcile_contract'=>'mad4b.test-owner-v1']],
],JSON_THROW_ON_ERROR));
require $old;require $new;
$observer=getenv('MAD4B_OBSERVER_SOURCE_FILE')?:dirname(__DIR__).'/runtime_owner_observer.php';
if(!is_file($observer))throw new RuntimeException('missing observer');
$GLOBALS['wp_filter']=[];
ob_start();require $observer;$first=json_decode(ob_get_clean(),true,512,JSON_THROW_ON_ERROR);
if($first['relations'][0]['state']!=='OWNER_UNPROVEN')throw new RuntimeException('no hook evidence must not infer owner');
$set=function(bool $legacy,bool $canonical):void {
  $hooks=[];
  if($legacy)$hooks[]=['function'=>['TestLegacyModule','handler']];
  if($canonical)$hooks[]=['function'=>['TestPluginModule','handler']];
  $GLOBALS['wp_filter']=['init'=>(object)['callbacks'=>[10=>$hooks]]];
};
foreach([
  'legacy'=>[true,false,'LEGACY_HOOK_OWNER_OBSERVED'],
  'canonical'=>[false,true,'PLUGIN_HOOK_OWNER_OBSERVED'],
  'both'=>[true,true,'DUAL_HOOK_OWNERS_CONFLICT'],
  'none'=>[false,false,'OWNER_UNPROVEN'],
] as $case=>$inputs){
    [$legacy,$canonical,$wanted]=$inputs;$set($legacy,$canonical);
    $result=mad4b_company_owner_observe_v1();
    $entry=$result['relations'][0];
    if($entry['state']!==$wanted)throw new RuntimeException($case.' wrong state '.$entry['state']);
    if($result['mutation_performed']!==false || $entry['functional_parity_certified']!==false ||
       $entry['signed_transition_authority']!==false)throw new RuntimeException('read-only contract broken');
    if(!$entry['legacy']['file_present'] || !$entry['canonical']['file_present'])throw new RuntimeException('files lost');
    echo 'PASS '.$case.' '.$entry['state'].PHP_EOL;
}
$files=glob($root.'/wp-content/plugins/mad4b-example/*');
if(!is_file($old)||!is_file($new))throw new RuntimeException('read-only probe modified files');
$paths=[$pluginDir.'/mad4b-example/mad4b-portability.json',$old,$new];
foreach($paths as $p)unlink($p);
rmdir(dirname($new));rmdir(dirname(dirname($new)));rmdir($pluginDir);rmdir($muDir);rmdir($root.'/wp-content');rmdir($root);
echo 'PASS runtime-owner-observer 4/4 observational states'.PHP_EOL;
