<?php
/** Stubs for a signed Elementor/JetEngine handler read, no WordPress writes. */
define('ABSPATH','/offline');
define('MAD4B_COMMERCE_RUNTIME_ENABLED',true);
$GLOBALS['activeTour']=99;
$GLOBALS['ajax']=true;
$GLOBALS['fakeLang']='es';
$GLOBALS['transportAllowed']=true;
$GLOBALS['fakeTransportTaxonomy']=true;
$GLOBALS['fakeMeta']=[
 99=>['tour_train_transport_status'=>'1','train_transport_services'=>[702]],
 702=>['trans_available_status'=>'1']];
function wp_doing_ajax(){return $GLOBALS['ajax'];}
function is_singular($type){return $type==='tours-and-activities';}
function is_admin(){return false;}
function is_user_logged_in(){return false;}
function is_preview(){return false;}
function get_queried_object_id(){return $GLOBALS['activeTour'];}
function get_post_type($id){return $id===99?'tours-and-activities':($id===702?'transportations':'');}
function get_post_status($id){return in_array($id,[99,702],true)?'publish':'draft';}
function get_post_meta($id,$key,$single=true){return $GLOBALS['fakeMeta'][$id][$key]??'';}
function metadata_exists($type,$id,$key){return array_key_exists($key,$GLOBALS['fakeMeta'][$id]??[]);}
function maybe_unserialize($raw){return $raw;}
function absint($value){return abs((int)$value);}
function home_url($path='/'){return 'https://source.example'.$path;}
function get_permalink($id){return $id===99?'https://source.example/es/tour-99/':'';}
function wp_parse_url($value,$component=-1){return parse_url($value,$component);}
function add_filter($name,$fn,$priority=10,$count=1){return true;}
function add_action($name,$fn,$priority=10,$count=1){return true;}
function apply_filters($name,$value,...$args){
 if($name==='wpml_default_language')return 'en';
 if($name==='wpml_object_id')return (int)($args[0]??0);
 if($name==='wpml_current_language')return $GLOBALS['fakeLang'];
 if($name==='wpml_post_language_details')return ['language_code'=>'es'];
 return $value;
}
function service_channel_allowed($a,$b){return $GLOBALS['transportAllowed'];}
function native_component_selection_public($id,$name){return $id===702&&$name==='transportations';}
class Jet_Engine_Listings_Ajax_Handlers {
 public function query_has_valid_signature($query){return ($query['signature_ok']??false)===true;}
 public function query_matches_widget_settings($query,$settings){
  return ($query['custom_query_id']??0)===($settings['custom_query_id']??null)
     &&($query['settings_ok']??false)===true;
 }
 public function query_has_valid_mutable_signature($query,$settings){
  return ($query['mutable_ok']??false)===true;
 }
}
function wp_unslash($v){return $v;}
class Shared_Product_Presentation_Transport_Context_v1 {
 public static function candidate($id,$cat){return ['ok'=>$GLOBALS['fakeTransportTaxonomy']&&$id===702&&$cat==='train'];}
 public static function binding($cat) {
  if($cat!=='train')return ['ok'=>false];
  return ['ok'=>true,'template_id'=>789,'widget_id'=>'abc123',
      'listing_id'=>888,'query_id'=>414];
 }
}
function check($v,$label){if(!$v){fwrite(STDERR,"FAIL ".$label.PHP_EOL);exit(2);}}
require dirname(__DIR__).'/wp-content/plugins/mad4b-commerce/modules/commerce/096-verified-tour-render-context.php';
require dirname(__DIR__).'/wp-content/mu-plugins/mad4b-tour-optional-sections-lazy.php';
$guard=MAD4B_Tour_Optional_Sections_Lazy_V1::class;
$scope=Shared_Product_Verified_Tour_Render_Context_v1::class;
$_SERVER['REQUEST_METHOD']='POST';
$_SERVER['REQUEST_URI']='/es/tour-99/';
$_SERVER['HTTP_HOST']='source.example';
$_SERVER['HTTP_REFERER']='https://source.example/es/tour-99/';
$_REQUEST=[
 'action'=>'jet_engine_ajax','handler'=>'get_listing',
 'listing_type'=>'elementor',
 'page_settings'=>['queried_id'=>'99|WP_Post','post_id'=>789,'element_id'=>'abc123'],
 'widget_settings'=>['_element_id'=>'tours_related_transportations_train',
    '_id'=>'abc123','lisitng_id'=>888,'custom_query_id'=>414,'custom_query'=>'yes'],
 'query'=>['query_id'=>414,'custom_query_id'=>414,
     'signature_ok'=>true,'mutable_ok'=>true,'settings_ok'=>true]
];
$nativeHandler=new Jet_Engine_Listings_Ajax_Handlers();
$GLOBALS['nativeHandler']=$nativeHandler;
function requestValid():void {Shared_Product_Verified_Tour_Render_Context_v1::end();}
function denials($reason):void {
 $scope=Shared_Product_Verified_Tour_Render_Context_v1::class;
 $guard=MAD4B_Tour_Optional_Sections_Lazy_V1::class;
 check($scope::current()===null,'no prior context for '.$reason);
 $guard::verify_native_transport_ajax($GLOBALS['nativeHandler']);
 check($scope::current()===null,'unverified request rejected '.$reason);
}
check($scope::current()===null,'no authority on load');
$guard::verify_native_transport_ajax($GLOBALS['nativeHandler']);
$trusted=$scope::current();
check(is_array($trusted)&&$trusted['tour_id']===99,'exact published Tour resolved only by native handler');
check($trusted['category']==='train','only matching training rail category resolved');
check($trusted['booking_authorized']===false,'transport display may not authorize booking');
check($trusted['purpose']==='signed_optional_transport_display','bounded presentation-only scope');
$scope::end();
check($scope::current()===null,'explicit scope pop cleared context');
$original=$_REQUEST;
$_REQUEST['handler']='other';denials('wrong handler');
$_REQUEST=$original;$_REQUEST['listing_type']='not-elementor';denials('wrong elementor owner');
$_REQUEST=$original;$_REQUEST['page_settings']['queried_id']='200|WP_Post';denials('wrong tour');
$_REQUEST=$original;$_REQUEST['page_settings']['post_id']=790;denials('wrong template');
$_REQUEST=$original;$_REQUEST['page_settings']['element_id']='wrong';denials('wrong widget reference');
$_REQUEST=$original;$_REQUEST['widget_settings']['_element_id']='tours_related_transportations_vehicles';denials('wrong category binding');
$_REQUEST=$original;$_REQUEST['widget_settings']['lisitng_id']=999;denials('wrong listing');
$_REQUEST=$original;$_REQUEST['widget_settings']['custom_query_id']=415;denials('wrong query');
$_REQUEST=$original;$_REQUEST['widget_settings']['custom_query']=false;denials('native custom query disabled');
$_REQUEST=$original;$_REQUEST['query']['query_id']=415;denials('wrong posted query');
$_REQUEST=$original;$_SERVER['HTTP_REFERER']='https://attacker.example/es/tour-99/';denials('cross origin referrer');
$_SERVER['HTTP_REFERER']='https://source.example/es/tour-99/';
$_SERVER['REQUEST_URI']='/wp-admin/admin-ajax.php';
$guard::verify_native_transport_ajax($nativeHandler);
check(($scope::current()['tour_id']??null)===99,
  'native AJAX endpoint binds Tour from signed source document and referer');
$scope::end();
$_SERVER['REQUEST_URI']='/es/tour-99/';
$GLOBALS['fakeLang']='en';denials('cross language');
$GLOBALS['fakeLang']='es';
$GLOBALS['transportAllowed']=false;denials('disabled transport service');
$GLOBALS['transportAllowed']=true;
$_REQUEST=$original;
$_REQUEST['query']['signature_ok']=false;denials('unsigned or modified native query denied');
$_REQUEST=$original;
$_REQUEST['query']['mutable_ok']=false;denials('invalid mutable query digest denied');
$_REQUEST=$original;
$_REQUEST['query']['settings_ok']=false;denials('query mismatches server-owned widget settings');
$_REQUEST=$original;
$guard::verify_native_transport_ajax(null);
check($scope::current()===null,'missing actual native JetEngine handler denied');
$_REQUEST=$original;
check(!$scope::begin_native_optional_transport(99,'unknown'),'invalid internal transport category rejected');
check(!$scope::begin_native_optional_transport(200,'train'),'unpublished Tour denied');
$GLOBALS['ajax']=false;
check(!$scope::begin_native_optional_transport(99,'train'),'nonAjax call cannot manufacture presentation context');
echo json_encode(['state'=>'PASS','cases'=>25,
 'native_scope'=>'presentation_only','site_modified'=>false,'browser_runtime_tested'=>false]).PHP_EOL;
