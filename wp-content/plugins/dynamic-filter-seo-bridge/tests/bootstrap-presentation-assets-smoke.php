<?php
namespace DynamicFilterSEOBridge\Presentation { final class AjaxPresentationEndpoint {public function assets():void {if(function_exists('jet_smart_filters'))$GLOBALS['enqueued']=true;}} }
namespace {
function add_action($name,$callback,$priority){$GLOBALS['hooks'][$name][$priority][]=$callback;}
function is_admin(){return false;}
function has_filter($name){return true;}
function wp_script_is($name,$state){return !empty($GLOBALS['enqueued']);}
function wp_unslash($v){return $v;}
function shared_presentation_catalog_url_context_v1($uri){return $uri==='/verified/'?['path'=>$uri]:null;}
function shared_presentation_catalog_contract_v1(){return $GLOBALS['contract'];}
function apply_filters($name,$value){return 'en';}
function sanitize_key($v){return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$v));}
function taxonomy_exists($v){return true;}
function plugin_dir_path($f){return dirname($f).'/';}
function plugins_url($path,$f){return '/plugin/'.$path;}
function wp_get_environment_type(){return 'production';}
function wp_enqueue_script(...$args){$GLOBALS['switches'][]=$args;}
function wp_add_inline_script(...$args){$GLOBALS['inline'][]=$args;}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
function check($v,$m){if(!$v)throw new RuntimeException($m);}
$root=dirname(__DIR__);define('DFSB_MAIN_FILE',$root.'/dynamic-filter-seo-bridge.php');define('DFSB_ASSET_VERSION','test');define('ARRAY_A','ARRAY_A');
require $root.'/includes/Runtime/AssetVersion.php';require $root.'/includes/Bootstrap.php';require $root.'/includes/WPML/ActiveFacetLanguageSwitcher.php';
$ref=new ReflectionClass(\DynamicFilterSEOBridge\Bootstrap::class);$b=$ref->newInstanceWithoutConstructor();$method=$ref->getMethod('registerPresentationAssets');$endpoint=new \DynamicFilterSEOBridge\Presentation\AjaxPresentationEndpoint();
$GLOBALS['hooks']=[];$method->invoke($b,$endpoint,true,['jet_engine'],['jet_smart_filters']);check(!$GLOBALS['hooks'],'blocked migration remains inert');
$method->invoke($b,$endpoint,false,[],[]);check(!$GLOBALS['hooks'],'no declared adapter remains inert');
$method->invoke($b,$endpoint,false,[],['jet_smart_filters']);$hooks=$GLOBALS['hooks']['wp_enqueue_scripts'];check(count($hooks[30])===1&&count($hooks[45])===1,'one original AJAX and WPML registration');
check(!function_exists('jet_smart_filters'),'vendor is not loaded at boot');$hooks[30][0]();check(empty($GLOBALS['enqueued']),'no vendor no script');
function installVendor(){function jet_smart_filters(){return true;}}installVendor();$hooks[30][0]();check($GLOBALS['enqueued'],'late vendor detected');
$GLOBALS['wpdb']=new class {public $prefix='wp_',$term_taxonomy='wp_term_taxonomy',$terms='wp_terms';public function prepare($sql,...$args){return $sql;}public function get_results($sql,$mode){return [['taxonomy'=>'destinations','trid'=>1,'language_code'=>'en','slug'=>'cairo'],['taxonomy'=>'destinations','trid'=>1,'language_code'=>'es','slug'=>'el-cairo']];}};
$GLOBALS['contract']=['taxonomies'=>['destinations']];$_SERVER['REQUEST_URI']='/verified/';$hooks[45][0]();check(empty($GLOBALS['switches']),'missing provider does not invent query ID');
$GLOBALS['contract']['provider']='jet-engine/custom_archive';$_SERVER['REQUEST_URI']='/unverified/';$hooks[45][0]();check(empty($GLOBALS['switches']),'unverified route remains inert');
$_SERVER['REQUEST_URI']='/verified/';$GLOBALS['enqueued']=false;$hooks[45][0]();check(empty($GLOBALS['switches']),'missing AJAX dependency remains inert');
$GLOBALS['enqueued']=true;$hooks[45][0]();check(count($GLOBALS['switches'])===1&&str_contains($GLOBALS['inline'][0][1],'jet-engine/custom_archive'),'exact observed provider');
echo "PASS: migration and profile gates, deferred native readiness, verified route, exact provider, AJAX dependency\n";
}
