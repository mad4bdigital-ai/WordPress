<?php
function sanitize_key($v){return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$v));}
function sanitize_title($v){return trim(preg_replace('/[^a-z0-9\-_]+/','-',strtolower((string)$v)),'-');}
function sanitize_text_field($v){return trim(strip_tags((string)$v));}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
$GLOBALS['etg_runtime_filter_availability']=array('wpml_permalink'=>true,'wpml_active_languages'=>true);
function has_filter($tag){return !empty($GLOBALS['etg_runtime_filter_availability'][$tag])?10:false;}
function get_post_types($args=array(),$output='names'){
	$t=(object)array('label'=>'Tours','publicly_queryable'=>true,'has_archive'=>true,'rewrite'=>array('slug'=>'tours-and-activities'));
	$p=(object)array('label'=>'Properties','publicly_queryable'=>true,'has_archive'=>true,'rewrite'=>array('slug'=>'properties'));
	return array('property'=>$p,'tour'=>$t);
}
function get_object_taxonomies($postType,$output='names'){return 'tour'===$postType?array('tour-types_jet','tour-styles_jet','location_jet'):array('property_city','property_type');}
function get_post_type_archive_link($postType){return 'https://example.test/'.('tour'===$postType?'tours-and-activities':'properties').'/';}
function get_taxonomies($args=array(),$output='names'){
	$make=function($label,$types,$hier=false,$slug=''){return (object)array('label'=>$label,'publicly_queryable'=>true,'hierarchical'=>$hier,'object_type'=>$types,'rewrite'=>array('slug'=>$slug));};
	return array(
		'location_jet'=>$make('Location',array('tour'),true,'location'),
		'property_city'=>$make('Property City',array('property'),true,'property-city'),
		'property_type'=>$make('Property Type',array('property'),true,'property-type'),
		'tour-styles_jet'=>$make('Tour Style',array('tour'),false,'tour-style'),
		'tour-types_jet'=>$make('Tour Type',array('tour'),false,'tour-type'),
	);
}
function apply_filters($tag,$value,...$args){
	if('wpml_permalink'===$tag){$url=(string)$value;$lang=(string)($args[0]??'');return 'fr'===$lang?str_replace('example.test/','example.test/fr/',$url):$url;}
	return $value;
}

require_once dirname(__DIR__).'/includes/Diagnostics/RuntimeInventory.php';
use ETG\DynamicFilterSEOBridge\Diagnostics\RuntimeInventory;
class InventoryQueryMock {public $id;public $query_id;private $type;private $args;public function __construct($id,$qid,$type,$args){$this->id=$id;$this->query_id=$qid;$this->type=$type;$this->args=$args;}public function get_query_type(){return $this->type;}public function get_query_args(){return $this->args;}}
function expect_true($v,$m){if(!$v){fwrite(STDERR,"FAIL: $m\n");exit(1);}}
function expect_same($e,$a,$m){if($e!==$a){fwrite(STDERR,"FAIL: $m\nEXPECTED ".var_export($e,true)."\nACTUAL ".var_export($a,true)."\n");exit(1);}}
$queries=function(){return array(
	new InventoryQueryMock(7,'tours_query_archive','posts',array('post_type'=>'tour','tax_query'=>array(array('taxonomy'=>'location_jet'),array('taxonomy'=>'tour-styles_jet')))),
	new InventoryQueryMock(8,'mixed_archive','posts',array('post_type'=>array('tour','product'))),
	new InventoryQueryMock(9,'unbounded_archive','posts',array('post_type'=>'any')),
	new InventoryQueryMock(10,'terms_lookup','terms',array('taxonomy'=>'location_jet')),
);};
$languages=function(){return array(
	'en'=>array('code'=>'en','default_locale'=>'en_US','native_name'=>'English','translated_name'=>'English','active'=>1,'url'=>'https://example.test/'),
	'fr'=>array('code'=>'fr','default_locale'=>'fr_FR','native_name'=>'Français','translated_name'=>'French','active'=>0,'url'=>'https://example.test/fr/'),
);};
$inventory=new RuntimeInventory($queries,$languages);
$one=$inventory->collect();$two=$inventory->collect();
expect_same('etg.dfsb.runtime-inventory.v2',$one['contract'],'contract');
expect_same(true,$one['evidence_complete'],'normal inventory evidence complete');
expect_same(array(),$one['availability_errors'],'normal inventory has no source availability errors');
expect_same(false,$one['authorizing'],'inventory cannot authorize');
expect_same(true,$one['read_only'],'inventory is read-only');
expect_same(false,$one['profile_mutation'],'inventory cannot mutate profiles');
expect_same(true,$one['inventory']['availability']['post_types']['available'],'post type source available');
expect_same(true,$one['inventory']['availability']['taxonomies']['available'],'taxonomy source available');
expect_same(true,$one['inventory']['availability']['languages']['available'],'language source available');
expect_same(true,$one['inventory']['availability']['query_builder']['available'],'query source available');
expect_same(true,$one['inventory']['availability']['archive_path_translations']['available'],'translated archive source available');

expect_same(false,$one['inventory']['completeness']['post_types']['truncated'],'small post type inventory complete');
expect_same(false,$one['inventory']['completeness']['taxonomies']['truncated'],'small taxonomy inventory complete');
expect_same(false,$one['inventory']['completeness']['languages']['truncated'],'small language inventory complete');
expect_same(false,$one['inventory']['completeness']['query_builder']['truncated'],'small query inventory complete');
expect_same(4,$one['inventory']['completeness']['query_builder']['observed_count'],'query observed count');
expect_same(4,$one['inventory']['completeness']['query_builder']['included_count'],'query included count');
expect_same(0,$one['inventory']['query_builder']['identity_conflict_count'],'no identity conflicts');
expect_same($one['snapshot_fingerprint'],$two['snapshot_fingerprint'],'fingerprint excludes collection timestamp');
expect_true(isset($one['inventory']['post_types']['tour']),'tour discovered');
expect_same(array('location_jet','tour-styles_jet','tour-types_jet'),$one['inventory']['post_types']['tour']['taxonomies'],'taxonomy relation sorted');
expect_same('/fr/tours-and-activities/',$one['inventory']['post_types']['tour']['archive_paths']['fr'],'WPML archive path observed');
$q=array();foreach($one['inventory']['query_builder']['queries'] as $item){$q[$item['custom_query_id']]=$item;}
expect_same('injected_test_provider',$one['inventory']['query_builder']['source'],'injected source declared');
expect_same(array('tour'),$q['tours_query_archive']['post_types'],'exact query post type');
expect_same(array('location_jet','tour-styles_jet'),$q['tours_query_archive']['taxonomies'],'structural query taxonomies only');
expect_same(true,$q['tours_query_archive']['post_type_bounded'],'bounded posts query');
expect_same(false,$q['unbounded_archive']['post_type_bounded'],'post_type any is unbounded');
expect_true(!isset($q['tours_query_archive']['query_args']),'raw query args never exported');
expect_true(!isset($one['inventory']['post_types']['tour']['enabled']),'discovery has no profile enable authority');

$duplicateQueries=function(){return array(
    new InventoryQueryMock(12,'shared_archive','posts',array('post_type'=>'tour')),
    new InventoryQueryMock(11,'shared_archive','posts',array('post_type'=>'property')),
    new InventoryQueryMock(13,'z_archive','posts',array('post_type'=>'tour')),
);};
$duplicates=(new RuntimeInventory($duplicateQueries,$languages))->collect();
expect_same(1,$duplicates['inventory']['query_builder']['identity_conflict_count'],'duplicate custom query ID detected');
expect_same('shared_archive',$duplicates['inventory']['query_builder']['identity_conflicts'][0]['identity_key'],'collision identity exposed');
expect_same('shared_archive',$duplicates['inventory']['query_builder']['queries'][0]['identity_key'],'queries sorted deterministically before output');

$reverseQueries=function() use ($duplicateQueries){return array_reverse($duplicateQueries());};
$duplicatesReversed=(new RuntimeInventory($reverseQueries,$languages))->collect();
expect_same($duplicates['snapshot_fingerprint'],$duplicatesReversed['snapshot_fingerprint'],'query input order does not change fingerprint');

$manyQueries=function(){
    $items=array();
    for($i=104;$i>=0;$i--){$items[]=new InventoryQueryMock(1000+$i,'q'.str_pad((string)$i,3,'0',STR_PAD_LEFT),'posts',array('post_type'=>'tour'));}
    return $items;
};
$truncated=(new RuntimeInventory($manyQueries,$languages))->collect();
expect_same(true,$truncated['inventory']['completeness']['query_builder']['truncated'],'query overflow declared truncated');
expect_same(105,$truncated['inventory']['completeness']['query_builder']['observed_count'],'query overflow observed count');
expect_same(RuntimeInventory::MAX_QUERIES,$truncated['inventory']['completeness']['query_builder']['included_count'],'query overflow included count bounded');
expect_same('q000',$truncated['inventory']['query_builder']['queries'][0]['identity_key'],'sorting occurs before query slicing');

$invalidQuery=(new RuntimeInventory(function(){return false;},$languages))->collect();
expect_same(RuntimeInventory::UNAVAILABLE_CONTRACT,$invalidQuery['contract'],'invalid query provider produces unavailable contract');
expect_same(false,$invalidQuery['evidence_complete'],'invalid query provider cannot be complete evidence');
expect_true(in_array('query_builder_unavailable',$invalidQuery['availability_errors'],true),'invalid query provider is explicit availability blocker');
expect_same(false,$invalidQuery['inventory']['query_builder']['available'],'invalid query result is not treated as empty available inventory');

$invalidLanguages=(new RuntimeInventory($queries,function(){return null;}))->collect();
expect_same(RuntimeInventory::UNAVAILABLE_CONTRACT,$invalidLanguages['contract'],'invalid language provider produces unavailable contract');
expect_true(in_array('languages_unavailable',$invalidLanguages['availability_errors'],true),'language source unavailable is explicit');
expect_true(in_array('archive_path_translations_unavailable',$invalidLanguages['availability_errors'],true),'language outage also blocks translated archive evidence');

$GLOBALS['etg_runtime_filter_availability']['wpml_permalink']=false;
$missingPermalink=(new RuntimeInventory($queries,$languages))->collect();
expect_same(RuntimeInventory::UNAVAILABLE_CONTRACT,$missingPermalink['contract'],'missing WPML permalink authority produces unavailable contract');
expect_true(in_array('archive_path_translations_unavailable',$missingPermalink['availability_errors'],true),'translated permalink outage is explicit');
expect_true(!isset($missingPermalink['inventory']['post_types']['tour']['archive_paths']['fr']),'original archive path is never fabricated as translated evidence');
expect_same('/tours-and-activities/',$missingPermalink['inventory']['post_types']['tour']['archive_paths']['current'],'native current archive evidence remains visible');
$GLOBALS['etg_runtime_filter_availability']['wpml_permalink']=true;

$emptyQueries=(new RuntimeInventory(function(){return array();},$languages))->collect();
expect_same(RuntimeInventory::CONTRACT,$emptyQueries['contract'],'empty but valid Query Builder inventory remains available evidence');
expect_same(true,$emptyQueries['inventory']['query_builder']['available'],'empty query array is distinguishable from unavailable source');

fwrite(STDOUT,"Runtime inventory smoke tests passed.\n");
