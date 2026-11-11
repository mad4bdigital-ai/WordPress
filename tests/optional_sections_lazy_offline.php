<?php
/**
 * Source-only tour optional section eligibility. No WordPress loading/writes.
 * No live render or network request is made.
 */
namespace Jet_Engine\Query_Builder {
 class Manager {
  public static function instance(){return new self();}
  public function get_query_by_id($id) {
   return (object)['query_type'=>($id===414?'posts':'invalid')];
  }
 }
}
namespace {
define('ABSPATH','/offline');
$meta=[]; $types=[99=>'tours-and-activities',201=>'tours-and-activities',401=>'tours-and-activities',11=>'tours-and-activities',501=>'tours-and-activities',73=>'elementor_library',74=>'elementor_library',101=>'tours-and-activities',301=>'tours-and-activities',601=>'tours-and-activities',701=>'transportations',702=>'transportations',703=>'transportations'];
$status=[99=>'publish',201=>'publish',401=>'publish',11=>'publish',501=>'publish',73=>'publish',74=>'publish',101=>'publish',301=>'draft',601=>'publish',701=>'publish',702=>'publish',703=>'draft'];
$activeTour=99; $siteAdmin=false;$loggedIn=false;$preview=false;$ajax=false;
$mode='canary'; $editor=false;
$filters=[];
function add_filter($a,$b,$c=10,$d=1){global $filters;$filters[]=$a;return true;}
function add_action($name,$callback,$priority=10,$argc=1){return true;}
function is_singular($p=''){return $p==='tours-and-activities';}
function is_admin(){global $siteAdmin;return $siteAdmin;}
function get_queried_object_id(){global $activeTour;return $activeTour;}
function absint($v){return abs((int)$v);}
function get_post_type($p){global $types;return $types[$p]??'';}
function get_post_status($p){global $status;return $status[$p]??'draft';}
function is_user_logged_in(){global $loggedIn;return $loggedIn;}
function is_preview(){global $preview;return $preview;}
function wp_doing_ajax(){global $ajax;return $ajax;}
function metadata_exists($type,$post,$key){global $meta;return array_key_exists($key,$meta[$post]??[]);}
function get_post_meta($post,$key,$single=true){global $meta;return $meta[$post][$key]??'';}
function maybe_unserialize($raw){return $raw;}
function apply_filters($name,$v,...$args){return $name==='wpml_default_language'?'en':($name==='wpml_object_id'?$args[0]:$v);}
function get_option($name,$default=null){global $mode;return $name==='mad4b_tour_optional_lazy_mode'?$mode:$default;}
function check($v,$message){if(!$v){fwrite(STDERR,'FAIL: '.$message.PHP_EOL);exit(2);}}
class FakeWidget {
 public function __construct(private string $name, private array $settings){}
 public function get_name(){return $this->name;}
 public function get_settings($key=null){return $key===null?$this->settings:($this->settings[$key]??'');}
}
require dirname(__DIR__).'/wp-content/mu-plugins/mad4b-tour-optional-sections-lazy.php';
$class='MAD4B_Tour_Optional_Sections_Lazy_V1';
$meta[99]=['tour_extras_status'=>'1','tour_optional_extras'=>[601],
 'tour_vehicles_transport_status'=>'0','vehicles_transport_services'=>[701],
 'tour_train_transport_status'=>'1','train_transport_services'=>[702],
 'tour_air_transport_status'=>'0','air_transport_services'=>[],
 'extra_options_display_mode'=>'carousel'];
$meta[601]=['tour_mode'=>'activity','tour_public_status'=>'1'];
$meta[702]=['trans_available_status'=>'1'];
check($class::tour()===99,'tour scope is exact');
check($class::available(99,'extras'),'published related activity is eligible');
check(!$class::available(99,'vehicles'),'disabled vehicle slot is not eligible');
check($class::available(99,'train'),'published train with on status eligible');
check(!$class::available(99,'air'),'disabled flight is not eligible');
check(!$class::available(99,'unknown'),'unknown group denied');
check($class::widget_guard(true,new FakeWidget('jet-listing-grid',['_element_id'=>'current_tour_extras','_css_classes'=>'booking-component-extras']))===true,'active extra grid kept');
check($class::widget_guard(true,new FakeWidget('jet-listing-grid',['_element_id'=>'tours_related_transportations_vehicles','_css_classes'=>'booking-component-vehicles']))===false,'inactive vehicle grid hidden before render');
check($class::widget_guard(true,new FakeWidget('heading',[]))===true,'unrelated widget unchanged');
check($class::mode_guard(true,new FakeWidget('container',['css_classes'=>'shared-extra-standard-listing']))===false,'standard list hidden when carousel mode selected');
check($class::mode_guard(true,new FakeWidget('container',['css_classes'=>'shared-extra-feature-carousel']))===true,'carousel kept when mode selected');
check($class::mode_guard(true,new FakeWidget('container',['css_classes'=>'ordinary-content']))===true,'ordinary container unchanged');
$widget=['_element_id'=>'current_tour_extras','_css_classes'=>'booking-component-extras','custom_query'=>'yes','custom_query_id'=>414,'lisitng_id'=>73];
check(!$class::native_lazy(false,$widget),'no canary or active flag: native lazy remains off');
$_GET['mad4b_optional_lazy_canary']='1';
check($class::native_lazy(false,$widget),'valid source canary invokes native JetEngine lazy');
check($class::options([],$widget)['offset']==='650px','native lazy offset bounded');
$bad=$widget;$bad['custom_query_id']=415;
check(!$class::native_lazy(false,$bad),'unverified query type excluded');
check($class::native_lazy(true,$bad),'already-enabled native lazy remains active');
unset($_GET['mad4b_optional_lazy_canary']);
$mode='active';
check($class::native_lazy(false,$widget),'mode active permits native lazy on authorized grid');
$loggedIn=true;
check(!$class::native_lazy(false,$widget),'WordPress session/editing context excluded');
$loggedIn=false;$ajax=true;
check(!$class::native_lazy(false,$widget),'AJAX render context excluded');
$types[740]='jet-engine';
$meta[73]=['_elementor_data'=>json_encode([
  ['widgetType'=>'jet-listing-grid','settings'=>[
    'lisitng_id'=>740,'_css_classes'=>'booking-component-extras',
    '_element_id'=>'current_tour_extras'
  ],'elements'=>[]]
])];
$activeTour=401;
$_GET=[];
$mode='canary';
$ajax=false;
check(!$class::template_guard(true,new FakeWidget('template',['template_id'=>73])),
  'inactive Extras template suppressed before render');
check($class::stylesheet('<link href="test"/>','elementor-post-73')==='',
  'CSS of a blocked top-level template is skipped');
check($class::stylesheet('<link href="test"/>','elementor-post-740')==='',
  'CSS of nested listing inside blocked template is skipped');
check($class::stylesheet('<link href="test"/>','elementor-post-999')!=='',
  'unrelated Elementor CSS stays intact');
$class::record_visible_listing(new FakeWidget('jet-listing-grid',['lisitng_id'=>740]));
check($class::stylesheet('<link href="test"/>','elementor-post-740')!=='',
  'visible independent listing retains its CSS even if another template was hidden');
echo json_encode(['state'=>'PASS','cases'=>25,'source_only'=>true,
 'no_company_mutations'=>true,'all_optional_groups_predicated_on_availability'=>true,
 'native_jetengine_lazy_only'=>true],JSON_UNESCAPED_SLASHES).PHP_EOL;
}
