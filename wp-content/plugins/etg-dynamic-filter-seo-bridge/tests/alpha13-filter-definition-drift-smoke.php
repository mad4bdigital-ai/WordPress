<?php
declare(strict_types=1);

function sanitize_key($value){return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$value));}
function sanitize_text_field($value){return trim(strip_tags((string)$value));}
function absint($value){return abs((int)$value);}
function wp_json_encode($value,$flags=0){return json_encode($value,$flags);}
function etg_filter_drift_expect($condition,string $message):void{if(!$condition){fwrite(STDERR,"FAIL: $message\n");exit(1);}}
function etg_filter_drift_same($expected,$actual,string $message):void{if($expected!==$actual){fwrite(STDERR,"FAIL: $message\nEXPECTED ".var_export($expected,true)."\nACTUAL ".var_export($actual,true)."\n");exit(1);}}

$GLOBALS['etg_filter_posts']=array();
$GLOBALS['etg_filter_meta']=array();
function get_post($id){return $GLOBALS['etg_filter_posts'][(int)$id]??null;}
function get_post_meta($id,$key,$single=true){return $GLOBALS['etg_filter_meta'][(int)$id][$key]??'';}

$root=dirname(__DIR__);
require_once $root.'/includes/JetSmartFilters/FilterDefinitionInspector.php';
require_once $root.'/includes/Diagnostics/RuntimeInventory.php';
require_once $root.'/includes/Diagnostics/InventoryReconciler.php';

use ETG\DynamicFilterSEOBridge\JetSmartFilters\FilterDefinitionInspector;
use ETG\DynamicFilterSEOBridge\Diagnostics\RuntimeInventory;
use ETG\DynamicFilterSEOBridge\Diagnostics\InventoryReconciler;

$templateProvider=function(){return array(
    array('id'=>30843,'data'=>array(
        array('id'=>'location-filter','elType'=>'widget','widgetType'=>'jet-smart-filters-checkboxes','settings'=>array('filter_id'=>'16204','query_id'=>'tours_query_archive','content_provider'=>'jet-engine')),
        array('id'=>'guide-language-filter','elType'=>'widget','widgetType'=>'jet-smart-filters-checkboxes','settings'=>array('filter_id'=>'15034','query_id'=>'tours_query_archive','content_provider'=>'jet-engine')),
    )),
);};
$filterProvider=function(int $filterId):array{
    if(16204===$filterId){return array(
        '_data_source'=>'taxonomies','_source_taxonomy'=>'location_jet','_query_var'=>'related_children*46',
        '_is_custom_query_var'=>'1','_custom_query_var'=>'_tax_query::location_jet','_query_builder_query'=>'11',
    );}
    if(15034===$filterId){return array(
        '_data_source'=>'taxonomies','_source_taxonomy'=>'guide-languages_jet','_query_var'=>'_tax_query::guides-language',
        '_is_custom_query_var'=>'','_custom_query_var'=>'','_query_builder_query'=>'14',
    );}
    return array();
};

$inspection=(new FilterDefinitionInspector($templateProvider,$filterProvider))->inspect();
etg_filter_drift_same('etg.dfsb.jet-smart-filters-definition-inspection.v1',$inspection['contract'],'legacy inspection contract remains stable');
etg_filter_drift_same('etg.dfsb.jet-smart-filters-diagnostic.v2',$inspection['diagnostic_contract'],'diagnostic extension is explicit');
etg_filter_drift_same(true,$inspection['available'],'injected sources are available');
etg_filter_drift_same(2,$inspection['surface_count'],'both surfaces observed');
etg_filter_drift_same(2,$inspection['candidate_surface_count'],'both are definition candidates');
etg_filter_drift_same(2,$inspection['resolved_surface_count'],'both identities resolve');
etg_filter_drift_same(0,$inspection['unresolved_surface_count'],'no identity unresolved');
etg_filter_drift_same(array('resolved'=>2),$inspection['identity_resolution_counts'],'identity status aggregation is explicit');
etg_filter_drift_same(2,$inspection['definition_available_count'],'both definitions available');
etg_filter_drift_same(true,$inspection['evidence_complete'],'definition evidence is complete');
etg_filter_drift_same(1,$inspection['drift_count'],'only Guide Language mismatch observed');
$drift=$inspection['drift'][0];
etg_filter_drift_same(15034,$drift['filter_id'],'mismatch preserves filter ID');
etg_filter_drift_same('guide-languages_jet',$drift['source_taxonomy'],'source taxonomy preserved');
etg_filter_drift_same('guides-language',$drift['target_taxonomy'],'target taxonomy preserved');
etg_filter_drift_same('query_var',$drift['target_source'],'normal query var source preserved');
etg_filter_drift_same(false,$drift['custom_query_enabled'],'custom query state preserved');
etg_filter_drift_same('14',$drift['query_builder_query'],'Query Builder binding evidence preserved');
etg_filter_drift_same('source_taxonomy_query_target_mismatch_observed',$drift['reason'],'mismatch is observation evidence');
etg_filter_drift_same('review',$drift['severity_hint'],'raw mismatch does not invent blocking authority');
etg_filter_drift_same(false,$drift['authorizing'],'inspection remains non-authorizing');
etg_filter_drift_same(true,$inspection['surfaces'][0]['custom_query_enabled'],'custom query enabled is exposed per surface');
etg_filter_drift_same('aligned',$inspection['surfaces'][0]['taxonomy_semantic_status'],'custom target aligned with source is explicit');
etg_filter_drift_same('mismatch_observed',$inspection['surfaces'][1]['taxonomy_semantic_status'],'source/target mismatch remains visible');

$identityTemplateProvider=function(){return array(array('id'=>900,'data'=>array(
    array('id'=>'empty','widgetType'=>'jet-smart-filters-checkboxes','settings'=>array('filter_id'=>array(),'query_id'=>'q')),
    array('id'=>'malformed','widgetType'=>'jet-smart-filters-checkboxes','settings'=>array('filter_id'=>'abc','query_id'=>'q')),
    array('id'=>'ambiguous','widgetType'=>'jet-smart-filters-select','settings'=>array('filter_id'=>array('16032','24515'),'query_id'=>'q')),
    array('id'=>'future','widgetType'=>'jet-smart-filters-future-filter','settings'=>array('filter_id'=>'16032','query_id'=>'q')),
)));};
$identityInspection=(new FilterDefinitionInspector($identityTemplateProvider,$filterProvider))->inspect();
etg_filter_drift_same(4,$identityInspection['candidate_surface_count'],'all four fail-closed candidates observed');
etg_filter_drift_same(0,$identityInspection['resolved_surface_count'],'invalid/unsupported identities do not resolve');
etg_filter_drift_same(4,$identityInspection['unresolved_surface_count'],'invalid/unsupported identities stay explicit');
etg_filter_drift_same(array('ambiguous'=>1,'empty'=>1,'malformed'=>1,'unsupported'=>1),$identityInspection['identity_resolution_counts'],'unresolved identities are decomposed by class');
$identityReasons=array();foreach($identityInspection['surfaces'] as $surface){$identityReasons[$surface['node_id']]=$surface['identity_resolution_reason'];}
etg_filter_drift_same('empty_filter_assignment',$identityReasons['empty'],'empty Elementor array is classified exactly');
etg_filter_drift_same('malformed_filter_identity',$identityReasons['malformed'],'malformed identity is distinct');
etg_filter_drift_same('ambiguous_filter_identity',$identityReasons['ambiguous'],'multi-ID ambiguity fails closed');
etg_filter_drift_same('unsupported_filter_widget',$identityReasons['future'],'unknown future widget fails closed explicitly');

$GLOBALS['etg_filter_posts']=array(
    16086=>(object)array('post_status'=>'trash','post_type'=>'jet-smart-filters'),
    16087=>(object)array('post_status'=>'publish','post_type'=>'post'),
    16088=>(object)array('post_status'=>'draft','post_type'=>'jet-smart-filters'),
    16089=>(object)array('post_status'=>'publish','post_type'=>'jet-smart-filters'),
);
$GLOBALS['etg_filter_meta']=array();
$lifecycleTemplateProvider=function(){return array(array('id'=>901,'data'=>array(
    array('id'=>'missing','widgetType'=>'jet-smart-filters-checkboxes','settings'=>array('filter_id'=>'16084','query_id'=>'q')),
    array('id'=>'trash','widgetType'=>'jet-smart-filters-checkboxes','settings'=>array('filter_id'=>'16086','query_id'=>'q')),
    array('id'=>'wrong-type','widgetType'=>'jet-smart-filters-checkboxes','settings'=>array('filter_id'=>'16087','query_id'=>'q')),
    array('id'=>'draft','widgetType'=>'jet-smart-filters-checkboxes','settings'=>array('filter_id'=>'16088','query_id'=>'q')),
    array('id'=>'metadata-empty','widgetType'=>'jet-smart-filters-checkboxes','settings'=>array('filter_id'=>'16089','query_id'=>'q')),
)));};
$lifecycleInspection=(new FilterDefinitionInspector($lifecycleTemplateProvider))->inspect();
etg_filter_drift_same(5,$lifecycleInspection['resolved_surface_count'],'post lifecycle debt is separate from identity resolution');
etg_filter_drift_same(0,$lifecycleInspection['unresolved_surface_count'],'all lifecycle test IDs resolve');
etg_filter_drift_same(5,$lifecycleInspection['definition_unavailable_count'],'all five lifecycle fixtures are unavailable for distinct reasons');
$definitionReasons=array();foreach($lifecycleInspection['definition_unavailable'] as $row){$definitionReasons[$row['filter_id']]=$row['definition_reason'];}
etg_filter_drift_same('filter_post_missing',$definitionReasons[16084],'missing filter post is explicit');
etg_filter_drift_same('filter_post_trash',$definitionReasons[16086],'trashed filter post is explicit');
etg_filter_drift_same('filter_post_wrong_type',$definitionReasons[16087],'wrong post type is explicit');
etg_filter_drift_same('filter_post_non_public',$definitionReasons[16088],'non-public filter post is explicit');
etg_filter_drift_same('filter_definition_metadata_empty',$definitionReasons[16089],'published filter with empty metadata is explicit');

$incompleteTemplateProvider=function(){return array(array('id'=>30843,'data'=>array(
    array('id'=>'unresolved-filter','elType'=>'widget','widgetType'=>'jet-smart-filters-checkboxes','settings'=>array('filter_id'=>array(),'query_id'=>'tours_query_archive','content_provider'=>'jet-engine')),
)));};
$incompleteInspection=(new FilterDefinitionInspector($incompleteTemplateProvider,$filterProvider))->inspect();
etg_filter_drift_same(1,$incompleteInspection['unresolved_surface_count'],'empty assignment remains fail-incomplete');
etg_filter_drift_same('empty_filter_assignment',$incompleteInspection['surfaces'][0]['identity_resolution_reason'],'empty assignment is not generic parser failure');
etg_filter_drift_same('filter_id_unresolved',$incompleteInspection['surfaces'][0]['resolution_reason'],'legacy resolution reason remains compatible');
etg_filter_drift_expect(in_array('filter_identity_unresolved',$incompleteInspection['evidence_reasons'],true),'aggregate evidence remains fail-incomplete');

$parityTopology=array('available'=>true,'truncated'=>false,'query_surfaces'=>array(
    array('widget_type'=>'jet-smart-filters-checkboxes','query_id'=>'tours_query_archive'),
    array('widget_type'=>'jet-smart-filters-select','query_id'=>'tours_query_archive'),
));
$reflection=new ReflectionMethod(RuntimeInventory::class,'reconcileFilterDefinitionEvidence');$reflection->setAccessible(true);
$reconciledIncomplete=$reflection->invoke(new RuntimeInventory(),$incompleteInspection,$parityTopology);
etg_filter_drift_same(false,$reconciledIncomplete['topology_surface_parity'],'topology parity mismatch prevents false clean evidence');
etg_filter_drift_expect(in_array('topology_filter_surface_parity_mismatch',$reconciledIncomplete['evidence_reasons'],true),'parity mismatch is explicit');

$queryRecords=array(array('id'=>'7','custom_query_id'=>'tours_qb','identity_key'=>'tours_qb','type'=>'posts','post_types'=>array('tours-and-activities'),'post_type_bounded'=>true));
$topology=array(
    'contract'=>'etg.dfsb.runtime-topology.v1','authorizing'=>false,'read_only'=>true,'profile_mutation'=>false,'available'=>true,
    'sources'=>array('templates'=>'test','query_builder'=>'test'),'templates_scanned'=>1,'query_builder_records_observed'=>1,'elements_scanned'=>3,'truncated'=>false,
    'provider_query_ids'=>array('tours_query_archive'),'bindings'=>array(array(
        'provider'=>'jet-engine','provider_query_id'=>'tours_query_archive','status'=>'verified','reason'=>'verified','query_builder_internal_id'=>'7',
        'query_builder_custom_query_id'=>'tours_qb','query_type'=>'posts','post_types'=>array('tours-and-activities'),'template_ids'=>array(30843),'evidence_count'=>1,
    )),'binding_count'=>1,'bindings_truncated'=>false,'provider_group_drift'=>array(),'provider_group_drift_count'=>0,'provider_group_drift_truncated'=>false,
);
$inventory=array(
    'post_types'=>array('tours-and-activities'=>array('label'=>'Tours & Activities','publicly_queryable'=>true,'has_archive'=>true,'taxonomies'=>array('location_jet','guide-languages_jet','guides-language'),'archive_paths'=>array('current'=>'/tours-and-activities/'))),
    'taxonomies'=>array(
        'location_jet'=>array('object_type'=>array('tours-and-activities')),
        'guide-languages_jet'=>array('object_type'=>array('tours-and-activities')),
        'guides-language'=>array('object_type'=>array('tours-and-activities')),
    ),
    'languages'=>array(array('code'=>'en','url_path'=>'/')),
    'query_builder'=>array('available'=>true,'source'=>'test','queries'=>$queryRecords,'identity_index'=>$queryRecords,'identity_index_complete'=>true,'identity_conflict_count'=>0,'identity_conflicts'=>array(),'identity_conflicts_truncated'=>false),
    'elementor_topology'=>$topology,'jet_smart_filters'=>$inspection,
    'completeness'=>array(
        'post_types'=>array('observed_count'=>1,'included_count'=>1,'limit'=>RuntimeInventory::MAX_POST_TYPES,'truncated'=>false),
        'taxonomies'=>array('observed_count'=>3,'included_count'=>3,'limit'=>RuntimeInventory::MAX_TAXONOMIES,'truncated'=>false),
        'languages'=>array('observed_count'=>1,'included_count'=>1,'limit'=>RuntimeInventory::MAX_LANGUAGES,'truncated'=>false),
        'query_builder'=>array('observed_count'=>1,'included_count'=>1,'limit'=>RuntimeInventory::MAX_QUERIES,'truncated'=>false),
        'query_identity_index'=>array('observed_count'=>1,'included_count'=>1,'limit'=>RuntimeInventory::MAX_QUERY_IDENTITIES,'truncated'=>false),
        'archive_path_translations'=>array('observed_count'=>0,'included_count'=>0,'limit'=>RuntimeInventory::MAX_ARCHIVE_PATH_TRANSLATIONS,'truncated'=>false),
    ),
);
$snapshot=array('contract'=>RuntimeInventory::CONTRACT,'authorizing'=>false,'read_only'=>true,'profile_mutation'=>false,'snapshot_fingerprint'=>hash('sha256',json_encode($inventory,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)),'inventory'=>$inventory);
$profile=array(
    'id'=>'tours','enabled'=>true,'post_types'=>array('tours-and-activities'),
    'taxonomy_rules'=>array('location_jet'=>array('role'=>'location'),'guide-languages_jet'=>array('role'=>'guide_language')),
    'archive_paths'=>array('/tours-and-activities/'),'routes'=>array(array('provider'=>'jet-engine','query_id'=>'tours_query_archive')),
);

$reconciler=new InventoryReconciler();
$enabled=$reconciler->analyze($snapshot,array('tours'=>$profile));
$blockingTaxonomy=array_values(array_filter($enabled['findings'],static function($finding){return 'profile_filter_taxonomy_target_drift'===(string)($finding['code']??'');}));
etg_filter_drift_same(0,count($blockingTaxonomy),'observed source/target mismatch is not promoted to blocker without stronger authority');
$routeReview=array_values(array_filter($enabled['findings'],static function($finding){return 'profile_filter_taxonomy_target_review'===(string)($finding['code']??'');}));
etg_filter_drift_same(1,count($routeReview),'governed route retains mismatch as explicit review evidence');
etg_filter_drift_same('warning',$routeReview[0]['severity'],'route mismatch review is non-blocking');
etg_filter_drift_same(15034,$routeReview[0]['details']['evidence'][0]['filter_id'],'route review preserves offending filter ID');
$globalFindings=array_values(array_filter($enabled['findings'],static function($finding){return 'jetsmartfilters_taxonomy_target_drift_detected'===(string)($finding['code']??'');}));
etg_filter_drift_same(1,count($globalFindings),'global mismatch remains visible');
etg_filter_drift_same('warning',$globalFindings[0]['severity'],'global mismatch remains warning/non-authorizing');
etg_filter_drift_same(0,$enabled['summary']['blocking'],'semantic observation alone does not create blocking authority');

$outside=$profile;$outside['taxonomy_rules']=array('location_jet'=>array('role'=>'location'));
$outsideResult=$reconciler->analyze($snapshot,array('tours'=>$outside));
$outsideReview=array_values(array_filter($outsideResult['findings'],static function($finding){return 'profile_filter_taxonomy_target_review'===(string)($finding['code']??'');}));
etg_filter_drift_same(0,count($outsideReview),'out-of-scope mismatch is not promoted to route review');
$outsideGlobal=array_values(array_filter($outsideResult['findings'],static function($finding){return 'jetsmartfilters_taxonomy_target_drift_detected'===(string)($finding['code']??'');}));
etg_filter_drift_same(1,count($outsideGlobal),'out-of-scope mismatch remains visible globally');

$disabled=$profile;$disabled['enabled']=false;
$disabledResult=$reconciler->analyze($snapshot,array('tours'=>$disabled));
$disabledReview=array_values(array_filter($disabledResult['findings'],static function($finding){return 'profile_filter_taxonomy_target_review'===(string)($finding['code']??'');}));
etg_filter_drift_same(1,count($disabledReview),'disabled governed profile keeps semantic review evidence');
etg_filter_drift_same('warning',$disabledReview[0]['severity'],'disabled profile semantic review stays warning');
etg_filter_drift_same(0,$disabledResult['summary']['blocking'],'disabled profile creates no blocking authority');

echo "Alpha13 JetSmartFilters diagnostic contract smoke tests passed.\n";
