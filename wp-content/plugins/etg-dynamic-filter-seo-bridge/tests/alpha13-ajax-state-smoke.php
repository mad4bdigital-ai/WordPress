<?php
declare(strict_types=1);

namespace Elementor {
    class Controls_Manager { const SELECT='select'; const TEXT='text'; const NUMBER='number'; const SWITCHER='switcher'; }
    class Plugin { public static $instance; }
}
namespace Elementor\Modules\DynamicTags { class Module { const IMAGE_CATEGORY='image'; const GALLERY_CATEGORY='gallery'; } }
namespace Elementor\Core\DynamicTags {
    class Tag {
        protected $data=array(); protected $controls=array();
        public function __construct(array $data=array()){ $this->data=$data; $this->register_controls(); }
        protected function register_controls(){}
        public function add_control($id,array $args){$this->controls[$id]=$args;}
        public function get_settings($key=null){$settings=(array)($this->data['settings']??array());if(null===$key){return$settings;}if(array_key_exists($key,$settings)){return$settings[$key];}return$this->controls[$key]['default']??'';}
    }
    class Data_Tag extends Tag {}
}

namespace {
function sanitize_key($v){return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$v));}
function sanitize_title($v){return trim(preg_replace('/[^a-z0-9\-_]+/','-',strtolower((string)$v)),'-');}
function sanitize_text_field($v){return trim(strip_tags((string)$v));}
function wp_strip_all_tags($v){return strip_tags((string)$v);} function wp_kses_post($v){return(string)$v;}
function esc_url_raw($v){return(string)$v;} function esc_url($v){return(string)$v;} function esc_attr($v){return htmlspecialchars((string)$v,ENT_QUOTES);} function esc_html($v){return htmlspecialchars((string)$v,ENT_QUOTES);}
function home_url($p=''){return'https://example.test'.('/'===substr((string)$p,0,1)?$p:'/'.$p);} function absint($v){return abs((int)$v);} function get_bloginfo($k){return'ETG';} function apply_filters($tag,$v){return$v;} function _n($s,$p,$n,$d=null){return 1===$n?$s:$p;}
function wp_get_attachment_image_url($id,$size){return'https://example.test/media/'.(int)$id.'.jpg';}
function get_term($id,$taxonomy){$map=array(11=>'cairo',12=>'giza',21=>'day-tour');if(!isset($map[(int)$id])){return null;}$o=new \stdClass();$o->term_id=(int)$id;$o->slug=$map[(int)$id];$o->taxonomy=$taxonomy;return$o;}
function get_term_by($field,$value,$taxonomy){return null;} function get_terms($args){return array();} function get_term_meta($id,$key=null,$single=false){return array();}
$GLOBALS['etg_options']=array(); function get_option($k,$d=array()){return$GLOBALS['etg_options'][$k]??$d;} function update_option($k,$v,$autoload=false){$GLOBALS['etg_options'][$k]=$v;return true;}
function expect_same($e,$a,$m){if($e!==$a){fwrite(STDERR,"FAIL: $m\nEXPECTED ".var_export($e,true)."\nACTUAL ".var_export($a,true)."\n");exit(1);}}
function expect_true($v,$m){if(!$v){fwrite(STDERR,"FAIL: $m\n");exit(1);}}

$base=dirname(__DIR__);
require_once $base.'/includes/JetSmartFilters/AjaxFilterStateParser.php';
require_once $base.'/includes/Content/GalleryComposer.php';
require_once $base.'/includes/Content/ContentComposer.php';
require_once $base.'/includes/Presentation/ContentSlotRegistry.php';
require_once $base.'/includes/Presentation/PresentationResolver.php';
require_once $base.'/includes/Elementor/DynamicTags/DynamicTagRuntime.php';
require_once $base.'/includes/Elementor/DynamicTags/PreviewContextTrait.php';
require_once $base.'/includes/Elementor/DynamicTags/FilterValueTag.php';
foreach(array('FilterTitleTag','FilterIntroTag','FilterResultSummaryTag','FilterKeywordTag','FilterArchiveUrlTag','FilterCurrentUrlTag','InventoryValueTag','ContentSlotTag','TermFieldTag','TermSectionTag','FilterImageTag','FilterImageUrlTag','FilterGalleryTag') as $class){require_once $base.'/includes/Elementor/DynamicTags/'.$class.'.php';}
require_once $base.'/includes/Elementor/DynamicTagRegistrar.php';

use ETG\DynamicFilterSEOBridge\JetSmartFilters\AjaxFilterStateParser;
use ETG\DynamicFilterSEOBridge\Content\GalleryComposer;
use ETG\DynamicFilterSEOBridge\Content\ContentComposer;
use ETG\DynamicFilterSEOBridge\Presentation\ContentSlotRegistry;
use ETG\DynamicFilterSEOBridge\Presentation\PresentationResolver;
use ETG\DynamicFilterSEOBridge\Elementor\DynamicTagRegistrar;

$parser=new AjaxFilterStateParser(array('location_jet','tour-types_jet'));
$state=$parser->parse(array('provider'=>'jet-engine','query_id'=>'tours_query_archive','archive_path'=>'/tours-and-activities/','current_query'=>array('tax_query'=>array('relation'=>'AND',array('taxonomy'=>'location_jet','field'=>'term_id','terms'=>array(11,12)),array('taxonomy'=>'tour-types_jet','field'=>'slug','terms'=>array('day-tour')),array('taxonomy'=>'tour-styles_jet','field'=>'slug','terms'=>array('luxury'))))));
expect_same('etg.dfsb.ajax-filter-state.v1',$state['contract'],'ajax state contract');
expect_same(false,$state['url_authority'],'ajax URL authority remains false');
expect_same(false,$state['authorizing'],'ajax state remains non-authorizing');
expect_same(array('cairo','giza'),$state['filter_values']['location_jet'],'term IDs normalize to slugs');
expect_true(isset($state['unknown_filters']['tour-styles_jet']),'unprofiled taxonomy remains visible');
expect_same(false,$state['filtered_query_complete'],'unknown taxonomy makes filtered query incomplete');

$notIn=$parser->parse(array('provider'=>'jet-engine','query_id'=>'tours_query_archive','archive_path'=>'/tours-and-activities/','current_query'=>array('tax_query'=>array(array('taxonomy'=>'location_jet','field'=>'term_id','terms'=>array(11),'operator'=>'NOT IN')))));
expect_same(false,$notIn['filtered_query_complete'],'NOT IN fails closed');
expect_true(in_array('unsupported_tax_operator:not_in:location_jet',$notIn['malformed'],true),'NOT IN reason explicit');
expect_same(array(),$notIn['filter_values'],'excluded terms are never positive presentation values');

$crossOr=$parser->parse(array('provider'=>'jet-engine','query_id'=>'tours_query_archive','archive_path'=>'/tours-and-activities/','current_query'=>array('tax_query'=>array('relation'=>'OR',array('taxonomy'=>'location_jet','field'=>'term_id','terms'=>array(11)),array('taxonomy'=>'tour-types_jet','field'=>'term_id','terms'=>array(21))))));
expect_same(false,$crossOr['filtered_query_complete'],'cross-taxonomy OR fails closed');
expect_true(in_array('cross_taxonomy_or_unsupported',$crossOr['malformed'],true),'cross-taxonomy OR reason explicit');

$nonTax=$parser->parse(array('provider'=>'jet-engine','query_id'=>'tours_query_archive','archive_path'=>'/tours-and-activities/','current_query'=>array('tax_query'=>array(array('taxonomy'=>'location_jet','field'=>'term_id','terms'=>array(11))),'meta_query'=>array(array('key'=>'price','value'=>'100','compare'=>'>=')),'date_query'=>array(array('after'=>'2026-01-01')),'s'=>'cairo'));
expect_same(array('date_query','meta_query','s'),$nonTax['unsupported_filter_props'],'unsupported meta/date/search surfaced');
expect_same(false,$nonTax['filtered_query_complete'],'unsupported non-tax state blocks complete query');

$slots=new ContentSlotRegistry();$defaults=$slots->all();foreach(array('hero_title','hero_intro','location_section','tour_type_section','style_section','results_summary') as $slotId){expect_true(isset($defaults[$slotId]),'built-in slot exists: '.$slotId);}expect_same(array(),$GLOBALS['etg_options'],'built-in slots do not mutate WordPress options');
$slots->save(array('id'=>'ajax_heading','label'=>'AJAX heading','enabled'=>true,'type'=>'text','template'=>'Explore {{terms:location:names}} — {{result_count}} tours'));

$context=array('active'=>true,'in_scope'=>true,'scope_valid'=>true,'runtime_ready'=>true,'filters'=>array('location_jet'=>'cairo'),'state_transport'=>'ajax','provider_observation_matches_url'=>false,'provider_observation_matches_state'=>true,'filtered_query_complete'=>true,'unsupported_filter_props'=>array(),'terms'=>array('location'=>array('term_id'=>11,'name'=>'Cairo','slug'=>'cairo','description'=>'Cairo tours','short_description'=>'','image_id'=>77,'gallery_ids'=>array(77,78))),'term_sets'=>array('location'=>array(array('term_id'=>11,'name'=>'Cairo','slug'=>'cairo'),array('term_id'=>12,'name'=>'Giza','slug'=>'giza'))),'profile'=>array('require_post_type_binding'=>false,'taxonomy_rules'=>array('location_jet'=>array('role'=>'location','priority'=>10,'gallery_priority'=>10))),'post_type_binding'=>array(),'result_count'=>29,'request_path'=>'/tours-and-activities/','archive_path'=>'/tours-and-activities/','unknown_filters'=>array(),'malformed'=>array(),'missing_terms'=>array(),'translation_fallback'=>false,'language'=>'en','profile_id'=>'tours','provider'=>'jet-engine','query_id'=>'tours_query_archive');
$gallery=new GalleryComposer();$content=new ContentComposer($gallery);$resolver=new PresentationResolver(function()use($context){return$context;},$content,$gallery,$slots);
expect_same('Cairo, Giza',$resolver->value('terms:location:names',$context),'multi-term token renders selected names');
expect_same('Explore Cairo, Giza — 29 tours',$resolver->slot('ajax_heading',$context),'slot recomposes AJAX term set');
expect_same(77,(int)$resolver->image('priority',$context)['id'],'primary image resolves from governed term context');
expect_same(2,count($resolver->gallery('combined',$context,9)),'gallery resolves governed attachment set');
$bad=$context;$bad['provider_observation_matches_state']=false;expect_same('',$resolver->value('terms:location:names',$bad),'AJAX presentation fails closed on provider mismatch');

class FakeElementorManager {public $tags=array();public function register_group($n,array $s){}public function register($tag){$this->tags[$tag->get_name()]=array('class'=>get_class($tag),'instance'=>$tag);}public function create_tag($name,array $settings=array()){if(!isset($this->tags[$name])){return null;}$class=$this->tags[$name]['class'];return new $class(array('settings'=>$settings,'id'=>'probe'));}}
$catalogProvider=function(){return array('tokens'=>array('title'=>array('label'=>'Filter Title','type'=>'text'),'term:location:name'=>array('label'=>'Location Name','type'=>'text'),'term:location:description'=>array('label'=>'Location Description','type'=>'html'),'image_id'=>array('label'=>'Primary Image ID','type'=>'image'),'image_url'=>array('label'=>'Primary Image URL','type'=>'url')));};
$manager=new FakeElementorManager();(new DynamicTagRegistrar($resolver,$slots,$catalogProvider,function($url)use($context){return$context;}))->register($manager);
$tagNames=array('etg-filter-title','etg-filter-intro','etg-filter-result-summary','etg-filter-keyword','etg-filter-archive-url','etg-filter-current-url','etg-inventory-value','etg-dynamic-content-slot','etg-filter-term-field','etg-filter-term-section','etg-filter-image','etg-filter-image-url','etg-filter-gallery');
foreach($tagNames as $name){expect_true(isset($manager->tags[$name]),'tag registered: '.$name);$tag=$manager->create_tag($name,array());expect_true(is_object($tag),'Elementor one-argument recreation succeeds: '.$name);}
expect_same('Cairo',$manager->create_tag('etg-filter-title')->get_value(),'recreated title tag keeps runtime resolver');
$imageTag=$manager->create_tag('etg-filter-image',array('mode'=>'priority'));expect_same(77,(int)$imageTag->get_value()['id'],'recreated image tag works');

$js=file_get_contents($base.'/assets/js/ajax-filter-state.js');$endpoint=file_get_contents($base.'/includes/Presentation/AjaxPresentationEndpoint.php');$builder=file_get_contents($base.'/includes/Context/FilterContextBuilder.php');$indexing=file_get_contents($base.'/includes/SEO/IndexingPolicy.php');$metadata=file_get_contents($base.'/includes/RankMath/MetadataAdapter.php');$registrar=file_get_contents($base.'/includes/Elementor/DynamicTagRegistrar.php');
expect_true(false!==strpos($js,'ajaxFilters/updated')&&false!==strpos($js,'group.currentQuery'),'browser consumes JetSmartFilters runtime state');
expect_true(false===strpos($js,'history.pushState')&&false===strpos($js,'history.replaceState'),'AJAX bridge never mutates browser history');
expect_true(false!==strpos($js,'AbortController')&&false!==strpos($js,'data-etg-dfsb-group'),'AJAX concurrency and multi-provider scoping retained');
expect_true(false!==strpos($endpoint,"'url_authority' => false")&&false!==strpos($endpoint,"'seo_mutation' => false"),'REST response preserves non-SEO boundary');
expect_true(false!==strpos($endpoint,'token_not_allowlisted')&&false!==strpos($endpoint,'slot_not_allowlisted'),'REST requests remain allowlisted');
expect_true(false!==strpos($builder,'blocked_incomplete_ajax_query'),'result count blocks incomplete AJAX query');
expect_true(false!==strpos($indexing,'ajax_state_non_authorizing'),'indexing policy denies AJAX authority');
expect_true(false!==strpos($metadata,"'ajax'===(string)"),'Rank Math ignores AJAX-only context');
expect_true(false===strpos($registrar,'new class')&&false!==strpos($registrar,'DynamicTagRuntime::configure'),'Elementor registration uses named classes and request-scoped runtime');
foreach(glob($base.'/includes/Elementor/DynamicTags/*.php') as $file){expect_true(false===strpos(file_get_contents($file),'function __construct'),'Dynamic Tag classes must not require constructor injection: '.basename($file));}
expect_true(is_file($base.'/includes/Admin/AdminAssets.php')&&is_file($base.'/assets/css/admin-alpha13.css'),'Alpha13 admin UX assets present');

echo "Alpha13 AJAX runtime, Elementor lifecycle and UX smoke tests passed.\n";
}
