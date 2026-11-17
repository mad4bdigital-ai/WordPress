<?php
/** No network or WordPress DB: exercise actual production class logic twice. */
declare(strict_types=1);
define('ABSPATH','/fixture/');
$GLOBALS['checks']=0;
$GLOBALS['active_languages']=['en'=>[],'es'=>[],'fr'=>[]];
$GLOBALS['term_map']=[
  'tour-types'=>[192=>['tour-types','day-tours'],193=>['tour-types','nile-cruises'],194=>['tour-types','packages']],
  'destinations'=>[19=>['destinations','cairo'],20=>['destinations','luxor']]
];
$GLOBALS['memberships']=[
  11=>['tour-types'=>[192],'destinations'=>[19]],
  12=>['tour-types'=>[193],'destinations'=>[20]],
  13=>['tour-types'=>[192],'destinations'=>[20]],
  14=>['tour-types'=>[194],'destinations'=>[19]],
];
$GLOBALS['start_price']=[11=>25,12=>530,13=>45,14=>1110];
function check($test,$title){if(!$test){fwrite(STDERR,"FAIL $title\n");exit(1);}$GLOBALS['checks']++;echo "PASS $title\n";}
class WP_Error {
 private string $code;
 function __construct($code,$message=''){$this->code=(string)$code;}
 function get_error_code(){return $this->code;}
}
function is_wp_error($x){return $x instanceof WP_Error;}
class MAD4B_Portable_MU_Context{static function enabled($id){return true;}}
function add_action(...$x){}
function add_filter(...$x){}
function rest_ensure_response($payload){return new class($payload) {
 public array $payload; public array $headers=[];
 public function __construct($data){$this->payload=$data;}
 public function header($key,$val){$this->headers[$key]=$val;}
};}
function is_admin(){return false;}
function is_preview(){return false;}
function is_post_type_archive($x){return true;}
function wp_get_environment_type(){return 'staging';}
function wp_parse_url($uri,$component=-1){return $component===-1?parse_url($uri):parse_url($uri,$component);}
function get_post_type_archive_link($pt){return 'https://tours.dev.mad4b.com/tours-and-activities/';}
function trailingslashit($s){return rtrim($s,'/').'/';}
function get_option($key,$default=null){
 if($key==='mad4b_scp_brand_context_profile_v1')return ['site_uuid'=>'e5e64bca-ed62-48d2-b2b3-dff693cd702a'];
 if($key==='icl_sitepress_settings')return ['active_languages'=>['en','es','fr']];
 return $default;
}
function home_url($uri){return 'https://tours.dev.mad4b.com'.$uri;}
function apply_filters($key,$value,...$args){
 if($key==='wpml_active_languages')return $GLOBALS['active_languages'];
 if($key==='wpml_current_language')return 'en';
 if($key==='wpml_object_id')return in_array((int)$value,[34607,35788,35786],true)?34607:$value;
 return $value;
}
function get_term($id,$tax){
 $v=$GLOBALS['term_map'][$tax][$id]??null;
 return $v?(object)['term_id'=>$id,'taxonomy'=>$v[0],'slug'=>$v[1]]:null;
}
function get_term_by($field,$slug,$tax){
 foreach($GLOBALS['term_map'][$tax]??[] as $id=>$v)if($v[1]===$slug)return get_term($id,$tax);
 return null;
}
function get_term_children($id,$tax){return [];}
function wp_get_object_terms($ids,$tax,$args=[]){
 $out=[];
 foreach($ids as $id)foreach(($GLOBALS['memberships'][$id][$tax]??[]) as $term)
  $out[]=(object)['term_id'=>$term,'object_id'=>$id];
 return $out;
}
function get_post_meta($id,$key,$single=true){return $key==='start_price'?($GLOBALS['start_price'][(int)$id]??''):'';}
$source=$argv[1]??'';
check(is_file($source),'actual source path supplied');
require $source;
$class='MAD4B_Archive_Hero_Price_V1';
check(class_exists($class,false),'live PHP class loaded');
check($class::route_language('/es/tours-and-activities/','en')==='es','Spanish path maps to active language');
check($class::route_language('/fr/tours-and-activities/','en')==='fr','third activated language is supported dynamically');
check($class::route_language('/de/tours-and-activities/','en')==='en','inactive language prefix does not override fallback');
check($class::route_language('/tours-and-activities/','en')==='en','default language remains English');
$ids=[11,12,13,14];
check($class::scoped_tour_ids('/es/tours-and-activities/',$ids)===$ids,'Spanish archive path resolves all eligible tours');
check($class::scoped_tour_ids('/tours-and-activities/jsf/jet-engine:tours_query_archive/tax/tour-types:day-tours/',$ids)===[11,13],'pretty filter path keeps matching taxonomy');
check($class::scoped_tour_ids_by_query($ids,'{}')===$ids,'no active filters retains full eligible catalog');
check($class::scoped_tour_ids('/tours-and-activities/jsf/jet-engine:tours_query_archive/tax/destinations:cairo/',$ids)===[11,14],
  'native destination pretty path retains its filtered membership');
check($class::scoped_tour_ids_by_query($ids,'{"_tax_query_tour-types":"192"}')===[11,13],'AJAX term-id filter reflects current query');
check($class::scoped_tour_ids_by_query($ids,'{"_tax_query_tour-types":"192,193"}')===[11,12,13],'OR terms within same taxonomy');
check($class::scoped_tour_ids_by_query($ids,'{"_tax_query_tour-types":"192","_tax_query_destinations":"20"}')===[13],'AND across taxonomy filters');
check($class::scoped_tour_ids_by_query($ids,'{"_meta_query_start_price|range":"30_60"}')===[13],'native indexed price range membership');
check($class::scoped_tour_ids_by_query($ids,'{"_meta_query_start_price|range":"30|600","_tax_query_tour-types":"193"}')===[12],'range and taxonomy combine');
check($class::scoped_tour_ids_by_query($ids,'{"_sort_standard":"price_asc"}')===$ids,'sort does not change min-price membership');
check($class::scoped_tour_ids_by_query($ids,'{"_tax_query_tour-types":"999999"}') instanceof WP_Error,'unknown term fails closed');
check($class::scoped_tour_ids_by_query($ids,'{"_meta_query_start_price|range":"30<script>90"}') instanceof WP_Error,'invalid range fails closed');
check($class::scoped_tour_ids_by_query($ids,'{"_date_query_departure":"2026-11-18"}') instanceof WP_Error,'unsupported date filter cannot show unrelated global min');
check($class::scoped_tour_ids_by_query($ids,'{"_tax_query_tour-types":["192","193"]}')===[11,12,13],'array term IDs supported for native JSF');
check($class::scoped_tour_ids_by_query($ids,'{"_tax_query_tour-types":"192","evil":{"data":true}}') instanceof WP_Error,'untrusted arbitrary WP query is rejected');
$nodes=[
 ['id'=>'root','elements'=>[
  ['id'=>'b512fea','widgetType'=>'text-editor','settings'=>['editor'=>'Empezar desde: 0$','jedv_enabled'=>'yes','__dynamic__'=>['editor'=>'old']]],
 ]],
];
$patched=$class::patch_elementor($nodes,35788);
$settings=$patched[0]['elements'][0]['settings'];
check(str_contains($settings['editor'],'hidden aria-live'),'translated Elementor price slot removes hardcoded 0$');
check($settings['jedv_enabled']==='','translated dynamic widget no longer re-enables stale pricing');
check($class::patch_elementor($nodes,999)===$nodes,'unrelated Elementor template unaffected');
$price_widget=new class {
 public function get_id(){return 'b512fea';}
 public function get_name(){return 'text-editor';}
};
$unrelated_widget=new class {
 public function get_id(){return 'not-the-price-widget';}
 public function get_name(){return 'text-editor';}
};
$seal=$class::render_price_widget('<div>Empezar desde: 0$</div>',$price_widget);
check(str_contains($seal,'mad4b-archive-price-value')&&str_contains($seal,'hidden aria-live'),
 'Final rendered Elementor widget is sealed with a hidden accessible price slot');
check(!str_contains($seal,'0$'),
 'WPML dynamic runtime cannot output a translated hardcoded zero price');
check($class::render_price_widget('<div>Keep regular text</div>',$unrelated_widget)==='<div>Keep regular text</div>',
 'Unrelated archive Elementor text editor is unchanged');
check($class::translated_price_string('Start from','start_from','es')==='Empezar desde',
 'Spanish CTA has an approved default copy when no WPML string is configured');
check($class::translated_price_string('Start from','start_from','fr')==='À partir de',
 'French CTA uses active locale copy without imposing a language whitelist');
check($class::translated_price_string('Start from','start_from','ar')==='يبدأ من',
 'Arabic CTA has a localized fallback');
check($class::translated_price_string('Start from','start_from','pt')==='Start from',
 'A future active language without a translation safely keeps source text');
$ref=new ReflectionMethod($class,'public_price_response');
$response=$ref->invoke(null,['status'=>'unavailable']);
check($response->headers['Cache-Control']==='private, no-store, no-cache, max-age=0',
 'Unavailable filter response must never be cached publicly');
check($response->headers['Vary']==='Cookie',
 'REST minimum pricing respects currency and market cookie variations');
$best=$class::price_state([11,13],static fn($id)=>['amount'=>$id===11?32:70,'currency'=>'USD','unit'=>'per_person']);
check($best['status']==='available'&&$best['best']['amount']===32.0,'minimum price uses certified rate/direct quote, not index price');
check($class::price_state([],static fn($id)=>[])['status']==='on_request','zero-results state never advertises global minimum');
check($class::price_state(range(1,131),static fn($id)=>['amount'=>1,'currency'=>'USD'])['status']==='unavailable',
 'more than 130 candidates cannot silently advertise a truncated minimum');
echo 'PASS: '.$GLOBALS['checks'].' PHP source-contract assertions\n';
