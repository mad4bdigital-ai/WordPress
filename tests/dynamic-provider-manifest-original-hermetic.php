<?php
namespace Elementor { class Plugin {} }
namespace Elementor\Core\Files\CSS {
 class Post {
  public static $read_calls=0,$value='body{color:blue}';
  public function __construct($id){}
  public function get_content(){self::$read_calls++;return self::$value;}
 }
}
namespace {
define('ABSPATH',__DIR__.'/');
define('ELEMENTOR_VERSION','3.40.0');
class WP_Error {
 private $code;
 function __construct($code,$message=''){$this->code=$code;}
 function get_error_code(){return $this->code;}
}
function is_wp_error($v){return $v instanceof WP_Error;}
function sanitize_key($v){return preg_replace('/[^a-z0-9_-]/','',strtolower($v));}
function sanitize_text_field($v){return trim($v);}
function absint($v){return abs((int)$v);}
function add_filter($name,$callback,$priority=10,$args=1){$GLOBALS['filters'][$name]=$callback;}
function apply_filters($name,$value){return $GLOBALS['manifest_source']??$value;}
function get_option($name){return $name==='elementor_active_kit'?99:null;}
function get_post($id){return in_array($id,[11,99],true)?(object)['ID'=>$id]:null;}
function get_post_meta($id,$key,$single=true){
 if($key==='_elementor_data')return '[{"id":"widget1"}]';
 if($key==='_elementor_css')return ['status'=>'inline','css'=>$GLOBALS['stored_css']];
 return '';
}
function manifest_check($yes,$why){
 if(!$yes){fwrite(STDERR,'FAIL: '.$why.PHP_EOL);exit(1);}
 $GLOBALS['checks']=($GLOBALS['checks']??0)+1;
}
$GLOBALS['filters']=[];
$GLOBALS['stored_css']='body{color:blue}';
$GLOBALS['manifest_source']=[];
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-dynamic-provider-contract.php';
$c='MAD4B_SCP_Dynamic_Provider_Contract';
$b='MAD4B_SCP_Elementor_CSS_Existing_Provider_Bridge';
manifest_check(count($GLOBALS['filters'])===2,
 'Original Elementor manifests and verification hooked only into the existing owner');
$manifest=$b::manifest([]);
manifest_check(count($manifest)===1
 &&$manifest[0]['existing_registry_identity']==='elementor'
 &&$manifest[0]['provider_id']==='elementor_native_css',
 'Original native Elementor CSS provider reuses existing Elementor registry, not a new executor');
$normalized=$c::normalize($manifest[0]);
manifest_check(is_array($normalized)
 &&$normalized['manifest_presence_authorizes']===false
 &&$normalized['enforceable_deadline_supported']===false,
 'Original provider manifest never grants a native CSS rebuild or unverified hard deadline');
$ok=$b::validate([],['post_id'=>11],[],null);
manifest_check($ok===[] &&\Elementor\Core\Files\CSS\Post::$read_calls===1,
 'Exact native Elementor CSS preview and stored inline CSS agree without issuing update()');
$GLOBALS['stored_css']='body{color:red}';
$diff=$b::validate([],['post_id'=>11],[],null);
manifest_check(count($diff)===1 &&$diff[0]['code']==='native_css_generated_drift'
 &&$diff[0]['write_authorized']===false
 &&$diff[0]['repairable']===false
 &&strlen($diff[0]['expected_css_sha256'])===64,
 'Drift is reported as nonauthorizing proof, not direct Elementor CSS mutation');
$GLOBALS['stored_css']='body{color:blue}';
manifest_check($b::validate([],['post_id'=>0],[],null)===[],
 'No CSS background regeneration without exact post ID');
foreach([
 ['provider_id'=>new WP_Error('unknown')],
 ['provider_id'=>['array','id']],
 ['existing_registry_identity'=>new WP_Error('unknown')],
 ['existing_registry_identity'=>['bad']],
 ['supports'=>[['unknown'=>'non_string']]],
 ['supports'=>[new WP_Error('unknown')]],
 ['side_effects'=>[['effect_scope'=>new WP_Error('unknown')]]],
 ['implementation_version'=>new WP_Error('unknown')],
 ['soft_elapsed_budget_ms'=>new WP_Error('unknown')]
] as $wrong){
 $source=array_replace($manifest[0],$wrong);
 manifest_check(is_wp_error($c::normalize($source)),
  'Provider manifests with error-shaped or non-scalar metadata cannot crash PHP or claim runtime support');
}
foreach([new WP_Error('missing_deadline'),1,'true',null] as $unknown){
 $source=$manifest[0];
 $source['enforceable_deadline_supported']=$unknown;
 $n=$c::normalize($source);
 manifest_check($n['enforceable_deadline_supported']===false
  &&$n['manifest_presence_authorizes']===false,
  'Only boolean true can claim an enforceable native provider deadline');
}
$GLOBALS['manifest_source']=[$manifest[0],array_replace($manifest[0],['provider_id'=>'second_provider'])];
$set=$c::manifests();
manifest_check(count($set)===2
 &&$set[0]['provider_id']==='elementor_native_css'
 &&$set[1]['provider_id']==='second_provider',
 'Dynamic registry preserves independent registered providers in deterministic order');
echo 'PASS: DYNAMIC_PROVIDER_MANIFEST_ORIGINAL '.($GLOBALS['checks']??0).
 ' provider schema, native Elementor CSS and read-only evidence cases'.PHP_EOL;
}
