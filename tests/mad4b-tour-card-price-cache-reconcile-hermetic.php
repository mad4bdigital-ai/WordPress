<?php
/** Isolated proof of one-shot price-template cache reconciliation. */
declare(strict_types=1);
$mode=$argv[1]??'normal';
if(!in_array($mode,['normal','repeat','no-tag','no-host','host-failed','too-many','lock-held','commerce','commerce-repeat','off','symlink','source-update'],true))exit(4);
define('ABSPATH','/');
if(!in_array($mode,['commerce','commerce-repeat','off'],true))define('MAD4B_PRESENTATION_RUNTIME_ENABLED',true);
if(in_array($mode,['commerce','commerce-repeat'],true))define('MAD4B_COMMERCE_RUNTIME_ENABLED',true);
$GLOBALS['testing_mode']=$mode;
$GLOBALS['actions']=[];$GLOBALS['holds']=[];$GLOBALS['deleted']=[];$GLOBALS['purges']=[];
$GLOBALS['options']=[];$GLOBALS['lock']=null;$GLOBALS['cache']=[];
function add_action($h,$cb,$p=10){$GLOBALS['actions'][]=[$h,$cb,$p];}
function do_action($h,$v=[]){$GLOBALS['holds'][]=[$h,$v];}
function get_option($k,$default=false){return $GLOBALS['options'][$k]??$default;}
function update_option($k,$v,$autoload=null){$GLOBALS['options'][$k]=$v;return true;}
function wp_cache_get($k,$g=''){return $GLOBALS['cache'][$g][$k]??false;}
function wp_cache_set($k,$v,$g='',$ttl=0){$GLOBALS['cache'][$g][$k]=$v;return true;}
function wp_cache_add($k,$v,$g='',$ttl=0){if($GLOBALS['testing_mode']==='lock-held'||$GLOBALS['lock']!==null)return false;$GLOBALS['lock']=$k;return true;}
function wp_cache_delete($k,$g=''){if($GLOBALS['lock']===$k)$GLOBALS['lock']=null;return true;}
function delete_post_meta($id,$k){$GLOBALS['deleted'][]=[$id,$k];return true;}
function apply_filters($name,$value,...$args){
 if($name==='wpml_active_languages')return ['en'=>[], 'es'=>[], 'fr'=>[]];
 if($name==='wpml_default_language')return 'en';
 return $value;
}
class TestWpdb {
 public string $posts='wp_posts';public string $postmeta='wp_postmeta';
 public function esc_like($str){return $str;}
 public function prepare($s,...$a){return str_replace('%s',"'".(string)($a[0]??'')."'",$s);}
 public function get_col($s){
  if(!str_contains($s,"tour-component-price-"))throw new Exception('wrong_template_dependency_query');
  if($GLOBALS['testing_mode']==='too-many')return range(1,65);
  return [14962,39884];
 }
}
$GLOBALS['wpdb']=new TestWpdb();
if($mode!=='no-host'){
 class MAD4B_Cache_Control_V1{
  public static function socket_purge($paths){
   $GLOBALS['purges'][]=$paths;
   if($GLOBALS['testing_mode']==='host-failed')return ['status'=>'socket_unavailable'];
   return ['status'=>'ok','paths'=>count($paths),'versioned'=>count($paths)];
  }
 }
}
eval('namespace Elementor; class Plugin {public static $instance;}');
class TestTags {public function get_tags(){return $GLOBALS['testing_mode']==='no-tag'?[]:['tour-component-price-text'=>true];}}
\Elementor\Plugin::$instance=(object)['dynamic_tags'=>new TestTags()];
function tour_component_structured_price_value(){return 'From USD 70.00 / per person';}
$root=sys_get_temp_dir().'/mad4b-cache-priced-card-'.getmypid();
$dir=$root.'/mad4b-commerce/modules/presentation';$commerce=$root.'/mad4b-commerce/modules/commerce';
mkdir($dir,0700,true);mkdir($commerce,0700,true);
$orig=dirname(__DIR__).'/wp-content/plugins/mad4b-commerce/modules/presentation/shared-tour-card-price-recovery-v1.php';
$owner=$dir.'/shared-tour-card-price-recovery-v1.php';copy($orig,$owner);
$provider=$commerce.'/tour-component-structured-price-tags.php';file_put_contents($provider,'<?php // original provider fingerprint fixture');
if($mode==='symlink'){unlink($provider);symlink($owner,$provider);}
require $owner;
$registered=array_filter($GLOBALS['actions'],fn($a)=>$a[0]==='elementor/dynamic_tags/register'&&$a[1]==='mad4b_commerce_native_card_price_cache_reconcile_v1'&&$a[2]===90);
mad4b_commerce_native_card_price_cache_reconcile_v1(\Elementor\Plugin::$instance->dynamic_tags);
$first=['deleted'=>count($GLOBALS['deleted']),'purges'=>count($GLOBALS['purges']),
 'fingerprint'=>get_option('mad4b_tour_card_price_source_fingerprint_v1','')!=='',
 'paths'=>$GLOBALS['purges'][0]??[]];
if(in_array($mode,['repeat','commerce-repeat','no-host','host-failed','too-many'],true))mad4b_commerce_native_card_price_cache_reconcile_v1(\Elementor\Plugin::$instance->dynamic_tags);
if($mode==='source-update'){
 file_put_contents($provider,'<?php // original provider fingerprint fixture v2');
 mad4b_commerce_native_card_price_cache_reconcile_v1(\Elementor\Plugin::$instance->dynamic_tags);
}
$normal=in_array($mode,['normal','repeat','source-update','commerce','commerce-repeat'],true);
$pass=count($registered)===1;
if($normal){
 $pass=$pass && $first['deleted']===2 && $first['purges']===1 && $first['fingerprint']
  && $first['paths']===['/','/tours-and-activities/','/es/','/es/tours-and-activities/','/fr/','/fr/tours-and-activities/'];
 $expected=$mode==='source-update'?4:2;
 $pass=$pass && count($GLOBALS['deleted'])===$expected && count($GLOBALS['purges'])===($mode==='source-update'?2:1);
}else{
 $pass=$pass && !$first['fingerprint'];
 if(in_array($mode,['off','no-tag','lock-held','symlink'],true))
  $pass=$pass && !$first['deleted'] && !$first['purges'];
 if($mode==='too-many')$pass=$pass && !$first['deleted'] && !$first['purges'];
 if($mode==='no-host')$pass=$pass && $first['deleted']===0 && count($GLOBALS['purges'])===0;
 if($mode==='host-failed')$pass=$pass && $first['deleted']===2 && count($GLOBALS['purges'])===1;
 if(in_array($mode,['no-host','host-failed','too-many'],true))
  $pass=$pass && count($GLOBALS['cache']['mad4b-runtime']??[])===1;
}
$remove=function($x)use(&$remove){if(is_dir($x)&&!is_link($x)){foreach(scandir($x) as $f)if($f!=='.'&&$f!=='..')$remove($x.'/'.$f);rmdir($x);}else unlink($x);};$remove($root);
echo json_encode(['mode'=>$mode,'passed'=>$pass,'initial'=>$first,'total_deleted'=>count($GLOBALS['deleted']),
 'total_purges'=>count($GLOBALS['purges']),'hold_events'=>count($GLOBALS['holds'])],JSON_UNESCAPED_SLASHES),"\n";
exit($pass?0:1);
