<?php
/** Original Visual SEO strategy context gate; no alt/tag writes or AI calls. */
define('ABSPATH',__DIR__.'/');
class WP_Error{function __construct($code,$msg='',$data=[]){}}
function absint($v){return is_numeric($v)?abs((int)$v):0;}
function get_post($id){return $id===12?(object)['ID'=>12,'post_status'=>'publish',
 'post_type'=>'tours-and-activities','post_title'=>'Cruise Tour']:null;}
function apply_filters($name,$value,...$args){return $value;}
function get_the_terms($id,$tax){return [];}
class MAD4B_SCP_Context_Authority{
 static function profile(){return [];}
 static function review_policy(){return ['mode'=>'human_only'];}
}
class MAD4B_SCP_Brand_Experience_Quality{static function brand_context(){return [];}}
class MAD4B_SCP_Site_Profile {
 static $status=['site_uuid'=>'11111111-1111-4111-8111-111111111111',
  'origin_match'=>true,'deployment_binding_match'=>true,'environment_match'=>true,
  'environment'=>'staging'];
 static function status(){return self::$status;}
}
function vseo_check($yes,$why){if(!$yes){fwrite(STDERR,'FAIL '.$why.PHP_EOL);exit(1);}$GLOBALS['n']=($GLOBALS['n']??0)+1;}
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-visual-seo-strategy.php';
$c='MAD4B_SCP_Visual_SEO_Strategy';
$valid=$c::inspect(12,'featured');
vseo_check(($valid['ready']??false)===true &&($valid['proposed_text_written']??null)===false,
 'A verified published original tour remains a read-only Visual SEO planning source');
foreach(['site_uuid','origin_match','deployment_binding_match','environment_match'] as $field){
 foreach([new WP_Error('site_unknown'),1,'true',null,false] as $bad){
  MAD4B_SCP_Site_Profile::$status=['site_uuid'=>'11111111-1111-4111-8111-111111111111',
   'origin_match'=>true,'deployment_binding_match'=>true,'environment_match'=>true,
   'environment'=>'staging'];
  MAD4B_SCP_Site_Profile::$status[$field]=$bad;
  $r=$c::inspect(12,'featured');
  vseo_check(($r['ready']??null)===false,
   'Unverified original visual source cannot be considered SEO context ready');
 }
}
echo 'PASS: VISUAL_SEO_STRATEGY_ORIGINAL '.($GLOBALS['n']??0).
 ' source context, image position and no-write strategy cases'.PHP_EOL;
