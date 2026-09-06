<?php
declare(strict_types=1);
function sanitize_key($v){return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$v));}
function sanitize_title($v){return trim(preg_replace('/[^a-z0-9\-_]+/','-',strtolower((string)$v)),'-');}
function sanitize_text_field($v){return trim(strip_tags((string)$v));}
function wp_strip_all_tags($v){return strip_tags((string)$v);}function wp_kses_post($v){return(string)$v;}function esc_url_raw($v){return(string)$v;}function home_url($p=''){return'https://example.test'.('/'===substr($p,0,1)?$p:'/'.$p);}function absint($v){return abs((int)$v);}function get_bloginfo($k){return'ETG';}function apply_filters($tag,$v){return$v;}function _n($s,$p,$n,$d=null){return 1===$n?$s:$p;}function wp_get_attachment_image_url($id,$size){return'https://example.test/'.$id.'.jpg';}
function get_term($id,$taxonomy){$map=array(11=>'cairo',12=>'giza',21=>'day-tour');if(!isset($map[(int)$id])){return null;}$o=new stdClass();$o->term_id=(int)$id;$o->slug=$map[(int)$id];$o->taxonomy=$taxonomy;return$o;}
function get_term_by($field,$value,$taxonomy){return null;}
function expect_same($e,$a,$m){if($e!==$a){fwrite(STDERR,"FAIL: $m\nEXPECTED ".var_export($e,true)."\nACTUAL ".var_export($a,true)."\n");exit(1);}}
function expect_true($v,$m){if(!$v){fwrite(STDERR,"FAIL: $m\n");exit(1);}}
$base=dirname(__DIR__);
require_once $base.'/includes/JetSmartFilters/AjaxFilterStateParser.php';
require_once $base.'/includes/Content/GalleryComposer.php';
require_once $base.'/includes/Content/ContentComposer.php';
require_once $base.'/includes/Presentation/ContentSlotRegistry.php';
require_once $base.'/includes/Presentation/PresentationResolver.php';
use ETG\DynamicFilterSEOBridge\JetSmartFilters\AjaxFilterStateParser;
use ETG\DynamicFilterSEOBridge\Content\GalleryComposer;
use ETG\DynamicFilterSEOBridge\Content\ContentComposer;
use ETG\DynamicFilterSEOBridge\Presentation\ContentSlotRegistry;
use ETG\DynamicFilterSEOBridge\Presentation\PresentationResolver;

$parser=new AjaxFilterStateParser(array('location_jet','tour-types_jet'));
$state=$parser->parse(array(
 'provider'=>'jet-engine','query_id'=>'tours_query_archive','archive_path'=>'/tours-and-activities/',
 'current_query'=>array('tax_query'=>array('relation'=>'AND',array('taxonomy'=>'location_jet','field'=>'term_id','terms'=>array(11,12)),array('taxonomy'=>'tour-types_jet','field'=>'slug','terms'=>array('day-tour')),array('taxonomy'=>'tour-styles_jet','field'=>'slug','terms'=>array('luxury'))))
));
expect_same('etg.dfsb.ajax-filter-state.v1',$state['contract'],'ajax state contract');
expect_same(true,$state['active'],'ajax state active');
expect_same(false,$state['url_authority'],'ajax state never gains URL authority');
expect_same(false,$state['authorizing'],'ajax state non-authorizing');
expect_same('ajax',$state['state_transport'],'transport explicit');
expect_same(array('cairo','giza'),$state['filter_values']['location_jet'],'term IDs normalized to slugs');
expect_same('cairo',$state['filters']['location_jet'],'primary scalar preserved for backward-compatible profile scope');
expect_same(array('location_jet'),$state['multi_value_filters'],'multi-value taxonomy surfaced');
expect_true(isset($state['unknown_filters']['tour-styles_jet']),'unprofiled taxonomy stays visible but cannot enter safe filtered query');
expect_same(false,$state['filtered_query_complete'],'unknown taxonomy makes filtered query incomplete');
expect_same(2,count($state['filtered_query']['tax_query'])-1,'only allowed taxonomy clauses enter safe filtered query');

$notIn=$parser->parse(array(
 'provider'=>'jet-engine','query_id'=>'tours_query_archive','archive_path'=>'/tours-and-activities/',
 'current_query'=>array('tax_query'=>array(array('taxonomy'=>'location_jet','field'=>'term_id','terms'=>array(11),'operator'=>'NOT IN')))
));
expect_same(true,$notIn['active'],'NOT IN remains visible as active blocked state');
expect_same(false,$notIn['filtered_query_complete'],'NOT IN cannot become authoritative filtered query');
expect_true(in_array('unsupported_tax_operator:not_in:location_jet',$notIn['malformed'],true),'NOT IN fails closed explicitly');
expect_same(array(),$notIn['filter_values'],'excluded terms never become positive presentation terms');
expect_same(array(),$notIn['filtered_query'],'NOT IN is removed from safe filtered query');

$crossOr=$parser->parse(array(
 'provider'=>'jet-engine','query_id'=>'tours_query_archive','archive_path'=>'/tours-and-activities/',
 'current_query'=>array('tax_query'=>array('relation'=>'OR',array('taxonomy'=>'location_jet','field'=>'term_id','terms'=>array(11)),array('taxonomy'=>'tour-types_jet','field'=>'term_id','terms'=>array(21))))
));
expect_same(false,$crossOr['filtered_query_complete'],'cross-taxonomy OR cannot be represented as authoritative presentation state');
expect_true(in_array('cross_taxonomy_or_unsupported',$crossOr['malformed'],true),'cross-taxonomy OR fails closed explicitly');

$nonTax=$parser->parse(array(
 'provider'=>'jet-engine','query_id'=>'tours_query_archive','archive_path'=>'/tours-and-activities/',
 'current_query'=>array(
   'tax_query'=>array(array('taxonomy'=>'location_jet','field'=>'term_id','terms'=>array(11))),
   'meta_query'=>array(array('key'=>'price','value'=>'100','compare'=>'>=')),
   'date_query'=>array(array('after'=>'2026-01-01')),
   's'=>'cairo'
 )
));
expect_same(array('date_query','meta_query','s'),$nonTax['unsupported_filter_props'],'meta/date/search filters are surfaced as unsupported');
expect_same(false,$nonTax['filtered_query_complete'],'non-taxonomy state blocks authoritative count/presentation');

$missing=$parser->parse(array(
 'query_id'=>'tours_query_archive','archive_path'=>'/tours-and-activities/',
 'current_query'=>array('tax_query'=>array(array('taxonomy'=>'location_jet','field'=>'term_id','terms'=>array(11))))
));
expect_true(in_array('missing_provider',$missing['malformed'],true),'missing provider is explicit malformed state');
expect_same(false,$missing['filtered_query_complete'],'missing provider cannot produce complete filtered query');

$slots=new ContentSlotRegistry();
$GLOBALS['etg_options']=array();
function get_option($k,$d=array()){return$GLOBALS['etg_options'][$k]??$d;}function update_option($k,$v,$autoload=false){$GLOBALS['etg_options'][$k]=$v;return true;}
$slots->save(array('id'=>'ajax_heading','label'=>'AJAX heading','enabled'=>true,'type'=>'text','template'=>'Explore {{terms:location:names}} — {{result_count}} tours'));
$content=new ContentComposer(new GalleryComposer());
$context=array('active'=>true,'in_scope'=>true,'scope_valid'=>true,'runtime_ready'=>true,'filters'=>array('location_jet'=>'cairo'),'state_transport'=>'ajax','provider_observation_matches_url'=>false,'provider_observation_matches_state'=>true,'filtered_query_complete'=>true,'unsupported_filter_props'=>array(),'terms'=>array('location'=>array('term_id'=>11,'name'=>'Cairo','slug'=>'cairo')),'term_sets'=>array('location'=>array(array('term_id'=>11,'name'=>'Cairo','slug'=>'cairo'),array('term_id'=>12,'name'=>'Giza','slug'=>'giza'))),'profile'=>array('require_post_type_binding'=>false,'taxonomy_rules'=>array('location_jet'=>array('role'=>'location','priority'=>10))),'post_type_binding'=>array(),'result_count'=>29,'request_path'=>'/tours-and-activities/','archive_path'=>'/tours-and-activities/','unknown_filters'=>array(),'malformed'=>array(),'missing_terms'=>array(),'translation_fallback'=>false,'language'=>'en');
$resolver=new PresentationResolver(function()use($context){return$context;},$content,new GalleryComposer(),$slots);
expect_same('Cairo, Giza',$resolver->value('terms:location:names',$context),'multi-term token renders AJAX selection');
expect_same('2',$resolver->value('terms:location:count',$context),'multi-term count token');
expect_same('Explore Cairo, Giza — 29 tours',$resolver->slot('ajax_heading',$context),'slot recomposes from AJAX term-set state');
$bad=$context;$bad['provider_observation_matches_state']=false;expect_same('',$resolver->value('terms:location:names',$bad),'AJAX presentation fails closed without provider state match');
$incomplete=$context;$incomplete['filtered_query_complete']=false;expect_same('',$resolver->value('terms:location:names',$incomplete),'AJAX presentation fails closed on incomplete filtered query');

$js=file_get_contents($base.'/assets/js/ajax-filter-state.js');
$endpoint=file_get_contents($base.'/includes/Presentation/AjaxPresentationEndpoint.php');
$adapter=file_get_contents($base.'/includes/SEO/JetEngineResultCountAdapter.php');
$builder=file_get_contents($base.'/includes/Context/FilterContextBuilder.php');
$indexing=file_get_contents($base.'/includes/SEO/IndexingPolicy.php');
$metadata=file_get_contents($base.'/includes/RankMath/MetadataAdapter.php');
$shortcodes=file_get_contents($base.'/includes/Elementor/Shortcodes.php');
$bootstrap=file_get_contents($base.'/includes/Bootstrap.php');

expect_true(false!==strpos($js,"ajaxFilters/updated"),'client subscribes official JetSmartFilters AJAX update event');
expect_true(false!==strpos($js,'group.currentQuery'),'client reads JetSmartFilters runtime currentQuery');
expect_true(false===strpos($js,'history.pushState')&&false===strpos($js,'history.replaceState'),'AJAX-only bridge never mutates browser URL/history');
expect_true(false!==strpos($js,'filters_cleared')&&false!==strpos($js,'restoreInitial'),'cleared AJAX filters restore original server-rendered presentation');
expect_true(false!==strpos($js,'sequences[key]')&&false!==strpos($js,'timers[key]'),'AJAX provider groups use isolated debounce and stale-response guards');
expect_true(false!==strpos($js,'AbortController')&&false!==strpos($js,'controllers[key].abort()'),'old AJAX presentation requests are actively cancelled');
expect_true(false!==strpos($js,"data-etg-dfsb-group"),'multi-provider presentation bindings are group scoped');
expect_true(false!==strpos($js,"data-etg-dfsb-target"),'explicit URL/image attribute live target is supported without changing SEO authority');
expect_true(false!==strpos($js,'var initialized = false'),'duplicate bridge initialization is guarded');

expect_true(false!==strpos($endpoint,"'url_authority' => false")&&false!==strpos($endpoint,"'seo_mutation' => false"),'REST response declares non-SEO boundary');
expect_true(false!==strpos($endpoint,"! function_exists( 'jet_smart_filters' )"),'front-end AJAX bridge is only enqueued when JetSmartFilters exists');
expect_true(false!==strpos($endpoint,'token_not_allowlisted')&&false!==strpos($endpoint,'slot_not_allowlisted'),'anonymous REST token/slot requests are allowlisted');
expect_true(false!==strpos($endpoint,'unsupported_filter_state'),'unsupported or incomplete filter state blocks REST presentation');
expect_true(false!==strpos($endpoint,'X-Robots-Tag')&&false!==strpos($endpoint,'no-store'),'REST response is non-indexable and non-cache-authoritative');

expect_true(false!==strpos($builder,'blocked_incomplete_ajax_query'),'result count is blocked when AJAX query representation is incomplete');
expect_true(false!==strpos($adapter,'provider_observation_matches_state')&&false!==strpos($adapter,'resolveFilteredQuery'),'count adapter supports runtime AJAX state without pretending URL observation');
expect_true(false!==strpos($indexing,'ajax_state_non_authorizing'),'indexing policy explicitly denies AJAX state authority');
expect_true(false!==strpos($metadata,"'ajax'===(string)"),'Rank Math adapter explicitly ignores AJAX state');
expect_true(false!==strpos($shortcodes,'data-etg-dfsb-token')&&false!==strpos($shortcodes,'data-etg-dfsb-slot')&&false!==strpos($shortcodes,'data-etg-dfsb-group'),'dynamic shortcodes participate in live group-scoped bindings');
expect_true(false!==strpos($bootstrap,'$catalogProvider')&&false!==strpos($bootstrap,'new AjaxPresentationEndpoint'),'REST token allowlist is wired to Inventory Content Catalog');

echo "Alpha13 AJAX runtime-state hardening smoke tests passed.\n";
