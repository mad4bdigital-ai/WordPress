<?php
declare(strict_types=1);

function sanitize_key($v){return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$v));}
function sanitize_title($v){return trim(preg_replace('/[^a-z0-9\-_]+/','-',strtolower((string)$v)),'-');}
function sanitize_text_field($v){return trim(strip_tags((string)$v));}
function get_term($id,$taxonomy){$map=array(11=>'cairo',12=>'giza',21=>'day-tour');if(!isset($map[(int)$id])){return null;}$o=new stdClass();$o->term_id=(int)$id;$o->slug=$map[(int)$id];$o->taxonomy=$taxonomy;return$o;}
function get_term_by($field,$value,$taxonomy){return null;}
function etg_expect($condition,$message){if(!$condition){fwrite(STDERR,"FAIL: $message\n");exit(1);}}
function etg_same($expected,$actual,$message){if($expected!==$actual){fwrite(STDERR,"FAIL: $message\nEXPECTED ".var_export($expected,true)."\nACTUAL ".var_export($actual,true)."\n");exit(1);}}
function etg_has($needle,$haystack,$message){etg_expect(false!==strpos($haystack,$needle),$message);}

$root=dirname(__DIR__);
require_once $root.'/includes/JetSmartFilters/AjaxFilterStateParser.php';
use ETG\DynamicFilterSEOBridge\JetSmartFilters\AjaxFilterStateParser;

$parser=new AjaxFilterStateParser(array('location_jet','tour-types_jet'));
$native=$parser->parse(array(
    'provider'=>'jet-engine','query_id'=>'tours_query_archive','archive_path'=>'/tours-and-activities/',
    'current_query'=>array('_tax_query_location_jet'=>array(11,12,'operator_AND')),
));
etg_same(true,$native['active'],'native JetSmartFilters currentQuery is active');
etg_same(true,$native['filtered_query_complete'],'supported native taxonomy query is complete');
etg_same(array('cairo','giza'),$native['filter_values']['location_jet'],'native term IDs normalize to slugs');
etg_same('AND',$native['filtered_query']['tax_query'][0]['operator'],'native operator marker is preserved safely');

$nativeSlug=$parser->parse(array('provider'=>'jet-engine','query_id'=>'tours_query_archive','archive_path'=>'/tours-and-activities/','current_query'=>array('_tax_query_location_jet'=>'africa-containent')));
etg_same(array('africa-containent'),$nativeSlug['filter_values']['location_jet'],'native permalink slug value is supported');

$nativeUnsupported=$parser->parse(array('provider'=>'jet-engine','query_id'=>'tours_query_archive','archive_path'=>'/tours-and-activities/','current_query'=>array('_tax_query_location_jet'=>array(11),'_meta_query_price'=>'100')));
etg_same(false,$nativeUnsupported['filtered_query_complete'],'native meta query fails closed');
etg_expect(in_array('native_meta_query',$nativeUnsupported['unsupported_filter_props'],true),'native meta reason explicit');

$unknownProp=$parser->parse(array('provider'=>'jet-engine','query_id'=>'tours_query_archive','archive_path'=>'/tours-and-activities/','current_query'=>array('_tax_query_location_jet'=>array(11),'future_filter_shape'=>'x')));
etg_same(false,$unknownProp['filtered_query_complete'],'unknown future query shapes fail closed');
etg_expect(in_array('unknown_query_prop_future_filter_shape',$unknownProp['unsupported_filter_props'],true),'unknown query property surfaced');

$tooMany=$parser->parse(array('provider'=>'jet-engine','query_id'=>'tours_query_archive','archive_path'=>'/tours-and-activities/','current_query'=>array('tax_query'=>array(array('taxonomy'=>'location_jet','field'=>'slug','terms'=>range(1,25))))));
etg_same(false,$tooMany['filtered_query_complete'],'term cap cannot silently truncate an authoritative presentation query');
etg_expect(in_array('terms_limit_exceeded:location_jet',$tooMany['malformed'],true),'term cap reason explicit');

$deep=array('taxonomy'=>'location_jet','field'=>'term_id','terms'=>array(11));for($i=0;$i<12;$i++){$deep=array('relation'=>'AND',0=>$deep);}
$deepState=$parser->parse(array('provider'=>'jet-engine','query_id'=>'tours_query_archive','archive_path'=>'/tours-and-activities/','current_query'=>array('tax_query'=>$deep)));
etg_same(false,$deepState['filtered_query_complete'],'deep query cannot be silently dropped');
etg_expect(in_array('query_depth_limit_exceeded',$deepState['malformed'],true),'depth cap reason explicit');

$js=file_get_contents($root.'/assets/js/ajax-filter-state.js');
$endpoint=file_get_contents($root.'/includes/Presentation/AjaxPresentationEndpoint.php');
$runtime=file_get_contents($root.'/includes/Elementor/DynamicTags/DynamicTagRuntime.php');
$bootstrap=file_get_contents($root.'/includes/Bootstrap.php');
$section=file_get_contents($root.'/includes/Elementor/DynamicTags/TermSectionTag.php');
foreach(array('boundGroups','restoreAttribute','syncAutoBindings','scheduleKey','data-etg-dfsb-live-section') as $needle){etg_has($needle,$js,'stale/reset hardening: '.$needle);}
etg_has("el.removeAttribute(name)",$js,'href/src created during AJAX is removed on reset');
etg_has('catalogTokenMeta',$endpoint,'REST response types are catalog-driven');
etg_has('short_description|descriptions|short_descriptions',$endpoint,'short-description HTML fallback typing is explicit');
etg_has('catalogCache',$runtime,'Dynamic Tag runtime caches catalog per request');
etg_has('$catalogInventory=new RuntimeInventory',$bootstrap,'presentation catalog uses separate cached topology inventory');
etg_has('$topology->discover(false)',$bootstrap,'presentation catalog does not force Elementor topology rescan');
etg_has('data-etg-dfsb-live-section',$section,'live term section has hidden-state wrapper');
etg_has('hidden="hidden"',$section,'empty live term section starts hidden');
etg_expect(false===strpos($js,'history.pushState')&&false===strpos($js,'history.replaceState'),'ETG bridge still does not mutate browser history');

echo "Alpha13 deep-audit regression smoke tests passed.\n";
