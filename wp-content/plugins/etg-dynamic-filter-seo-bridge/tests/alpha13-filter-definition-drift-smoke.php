<?php
declare(strict_types=1);

function sanitize_key($value){return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$value));}
function sanitize_text_field($value){return trim(strip_tags((string)$value));}
function absint($value){return abs((int)$value);}
function wp_json_encode($value,$flags=0){return json_encode($value,$flags);}
function etg_filter_drift_expect($condition,string $message):void{if(!$condition){fwrite(STDERR,"FAIL: {$message}\n");exit(1);}}
function etg_filter_drift_same($expected,$actual,string $message):void{if($expected!==$actual){fwrite(STDERR,"FAIL: {$message}\nEXPECTED ".var_export($expected,true)."\nACTUAL ".var_export($actual,true)."\n");exit(1);}}

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
        '_data_source'=>'taxonomies',
        '_source_taxonomy'=>'location_jet',
        '_query_var'=>'related_children*46',
        '_is_custom_query_var'=>'1',
        '_custom_query_var'=>'_tax_query::location_jet',
    );}
    if(15034===$filterId){return array(
        '_data_source'=>'taxonomies',
        '_source_taxonomy'=>'guide-languages_jet',
        '_query_var'=>'_tax_query::guides-language',
        '_is_custom_query_var'=>'',
        '_custom_query_var'=>'',
    );}
    return array();
};

$inspection=(new FilterDefinitionInspector($templateProvider,$filterProvider))->inspect();
etg_filter_drift_same(true,$inspection['available'],'injected Elementor/filter definition sources are available');
etg_filter_drift_same(2,$inspection['surface_count'],'both JetSmartFilters surfaces are observed');
etg_filter_drift_same(2,$inspection['definition_count'],'both distinct filter definitions are read once');
etg_filter_drift_same(2,$inspection['candidate_surface_count'],'both JetSmartFilters widgets are candidate surfaces');
etg_filter_drift_same(2,$inspection['resolved_surface_count'],'both candidate surfaces resolve filter identity');
etg_filter_drift_same(0,$inspection['unresolved_surface_count'],'no filter identity is silently dropped');
etg_filter_drift_same(2,$inspection['definition_available_count'],'both filter definitions have observable metadata');
etg_filter_drift_same(0,$inspection['definition_unavailable_count'],'no resolved filter definition is unavailable');
etg_filter_drift_same(true,$inspection['evidence_complete'],'complete injected evidence is explicit');
etg_filter_drift_same('complete',$inspection['evidence_state'],'complete injected evidence has explicit state');
etg_filter_drift_same(1,$inspection['drift_count'],'only Guide Language source/target mismatch is drift');
$drift=$inspection['drift'][0];
etg_filter_drift_same(15034,$drift['filter_id'],'Guide Language filter identity preserved');
etg_filter_drift_same(30843,$drift['template_id'],'Elementor template identity preserved');
etg_filter_drift_same('tours_query_archive',$drift['query_id'],'provider query group preserved');
etg_filter_drift_same('guide-languages_jet',$drift['source_taxonomy'],'filter source taxonomy preserved');
etg_filter_drift_same('guides-language',$drift['target_taxonomy'],'query target taxonomy parsed exactly');
etg_filter_drift_same('query_var',$drift['target_source'],'normal query_var is identified as target authority source');
etg_filter_drift_same('source_taxonomy_query_target_mismatch',$drift['reason'],'mismatch has explicit diagnostic reason');
etg_filter_drift_same('blocking',$drift['severity_hint'],'mismatch carries a blocking hint without authorizing mutation');
etg_filter_drift_same(false,$drift['authorizing'],'filter definition inspection remains non-authorizing');

$incompleteTemplateProvider=function(){return array(
    array('id'=>30843,'data'=>array(
        array('id'=>'unresolved-filter','elType'=>'widget','widgetType'=>'jet-smart-filters-checkboxes','settings'=>array('query_id'=>'tours_query_archive','content_provider'=>'jet-engine')),
    )),
);};
$incompleteInspection=(new FilterDefinitionInspector($incompleteTemplateProvider,$filterProvider))->inspect();
etg_filter_drift_same(1,$incompleteInspection['surface_count'],'JetSmartFilters widget is counted even when filter identity is unresolved');
etg_filter_drift_same(1,$incompleteInspection['candidate_surface_count'],'candidate surface count preserves unresolved widget');
etg_filter_drift_same(0,$incompleteInspection['resolved_surface_count'],'unresolved filter identity is not fabricated');
etg_filter_drift_same(1,$incompleteInspection['unresolved_surface_count'],'unresolved filter identity is explicit');
etg_filter_drift_same(0,$incompleteInspection['definition_count'],'no synthetic definition is created');
etg_filter_drift_same(false,$incompleteInspection['evidence_complete'],'unresolved filter identity makes definition evidence incomplete');
etg_filter_drift_same('incomplete',$incompleteInspection['evidence_state'],'unresolved filter identity is not reported as clean');
etg_filter_drift_expect(in_array('filter_identity_unresolved',$incompleteInspection['evidence_reasons'],true),'incomplete evidence names unresolved filter identity');
etg_filter_drift_same('filter_id_unresolved',$incompleteInspection['surfaces'][0]['resolution_reason'],'surface records why definition lookup could not run');

$parityTopology=array(
    'available'=>true,'truncated'=>false,
    'query_surfaces'=>array(
        array('widget_type'=>'jet-smart-filters-checkboxes','query_id'=>'tours_query_archive'),
        array('widget_type'=>'jet-smart-filters-select','query_id'=>'tours_query_archive'),
    ),
);
$reflection=new ReflectionMethod(RuntimeInventory::class,'reconcileFilterDefinitionEvidence');
$reflection->setAccessible(true);
$reconciledIncomplete=$reflection->invoke(new RuntimeInventory(),$incompleteInspection,$parityTopology);
etg_filter_drift_same(2,$reconciledIncomplete['topology_filter_surface_count'],'independent topology counts JetSmartFilters query surfaces');
etg_filter_drift_same(true,$reconciledIncomplete['topology_parity_checked'],'topology parity is checked only with complete topology evidence');
etg_filter_drift_same(false,$reconciledIncomplete['topology_surface_parity'],'topology seeing more filter surfaces prevents false-clean evidence');
etg_filter_drift_same(false,$reconciledIncomplete['evidence_complete'],'topology parity mismatch remains incomplete');
etg_filter_drift_expect(in_array('topology_filter_surface_parity_mismatch',$reconciledIncomplete['evidence_reasons'],true),'parity mismatch is explicit evidence');

$queryRecords=array(
    array('id'=>'7','custom_query_id'=>'tours_qb','identity_key'=>'tours_qb','type'=>'posts','post_types'=>array('tours-and-activities'),'post_type_bounded'=>true),
);
$topology=array(
    'contract'=>'etg.dfsb.runtime-topology.v1',
    'authorizing'=>false,
    'read_only'=>true,
    'profile_mutation'=>false,
    'available'=>true,
    'sources'=>array('templates'=>'test','query_builder'=>'test'),
    'templates_scanned'=>1,
    'query_builder_records_observed'=>1,
    'elements_scanned'=>3,
    'truncated'=>false,
    'provider_query_ids'=>array('tours_query_archive'),
    'bindings'=>array(array(
        'provider'=>'jet-engine',
        'provider_query_id'=>'tours_query_archive',
        'status'=>'verified',
        'reason'=>'verified',
        'query_builder_internal_id'=>'7',
        'query_builder_custom_query_id'=>'tours_qb',
        'query_type'=>'posts',
        'post_types'=>array('tours-and-activities'),
        'template_ids'=>array(30843),
        'evidence_count'=>1,
    )),
    'binding_count'=>1,
    'bindings_truncated'=>false,
    'provider_group_drift'=>array(),
    'provider_group_drift_count'=>0,
    'provider_group_drift_truncated'=>false,
);
$inventory=array(
    'post_types'=>array(
        'tours-and-activities'=>array('label'=>'Tours & Activities','publicly_queryable'=>true,'has_archive'=>true,'taxonomies'=>array('location_jet','guide-languages_jet','guides-language'),'archive_paths'=>array('current'=>'/tours-and-activities/')),
    ),
    'taxonomies'=>array(
        'location_jet'=>array('object_type'=>array('tours-and-activities')),
        'guide-languages_jet'=>array('object_type'=>array('tours-and-activities')),
        'guides-language'=>array('object_type'=>array('tours-and-activities')),
    ),
    'languages'=>array(array('code'=>'en','url_path'=>'/')),
    'query_builder'=>array(
        'available'=>true,
        'source'=>'test',
        'queries'=>$queryRecords,
        'identity_index'=>$queryRecords,
        'identity_index_complete'=>true,
        'identity_conflict_count'=>0,
        'identity_conflicts'=>array(),
        'identity_conflicts_truncated'=>false,
    ),
    'elementor_topology'=>$topology,
    'jet_smart_filters'=>$inspection,
    'completeness'=>array(
        'post_types'=>array('observed_count'=>1,'included_count'=>1,'limit'=>RuntimeInventory::MAX_POST_TYPES,'truncated'=>false),
        'taxonomies'=>array('observed_count'=>3,'included_count'=>3,'limit'=>RuntimeInventory::MAX_TAXONOMIES,'truncated'=>false),
        'languages'=>array('observed_count'=>1,'included_count'=>1,'limit'=>RuntimeInventory::MAX_LANGUAGES,'truncated'=>false),
        'query_builder'=>array('observed_count'=>1,'included_count'=>1,'limit'=>RuntimeInventory::MAX_QUERIES,'truncated'=>false),
        'query_identity_index'=>array('observed_count'=>1,'included_count'=>1,'limit'=>RuntimeInventory::MAX_QUERY_IDENTITIES,'truncated'=>false),
        'archive_path_translations'=>array('observed_count'=>0,'included_count'=>0,'limit'=>RuntimeInventory::MAX_ARCHIVE_PATH_TRANSLATIONS,'truncated'=>false),
    ),
);
$snapshot=array(
    'contract'=>RuntimeInventory::CONTRACT,
    'authorizing'=>false,
    'read_only'=>true,
    'profile_mutation'=>false,
    'snapshot_fingerprint'=>hash('sha256',json_encode($inventory,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)),
    'inventory'=>$inventory,
);
$profile=array(
    'id'=>'tours',
    'enabled'=>true,
    'post_types'=>array('tours-and-activities'),
    'taxonomy_rules'=>array(
        'location_jet'=>array('role'=>'location'),
        'guide-languages_jet'=>array('role'=>'guide_language'),
    ),
    'archive_paths'=>array('/tours-and-activities/'),
    'routes'=>array(array('provider'=>'jet-engine','query_id'=>'tours_query_archive')),
);

$reconciler=new InventoryReconciler();
$incompleteInventory=$inventory;
$incompleteInventory['jet_smart_filters']=$reconciledIncomplete;
$incompleteSnapshot=$snapshot;
$incompleteSnapshot['inventory']=$incompleteInventory;
$incompleteSnapshot['snapshot_fingerprint']=hash('sha256',json_encode($incompleteInventory,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
$incompleteResult=$reconciler->analyze($incompleteSnapshot,array('tours'=>$profile));
$incompleteFindings=array_values(array_filter($incompleteResult['findings'],static function($finding){return 'jetsmartfilters_definition_inspection_incomplete'===(string)($finding['code']??'');}));
etg_filter_drift_same(1,count($incompleteFindings),'incomplete filter-definition evidence is visible in reconciliation');
etg_filter_drift_same('warning',$incompleteFindings[0]['severity'],'incomplete global filter evidence remains non-authorizing review evidence');
etg_filter_drift_same(2,$incompleteFindings[0]['details']['topology_filter_surface_count'],'reconciliation preserves independent topology count');

$enabled=$reconciler->analyze($snapshot,array('tours'=>$profile));
$routeFindings=array_values(array_filter($enabled['findings'],static function($finding){return 'profile_filter_taxonomy_target_drift'===(string)($finding['code']??'');}));
etg_filter_drift_same(1,count($routeFindings),'enabled governed Guide Language mismatch produces one route finding');
etg_filter_drift_same('blocking',$routeFindings[0]['severity'],'enabled governed taxonomy mismatch fails closed');
etg_filter_drift_same(1,$routeFindings[0]['details']['drift_count'],'only relevant route/taxonomy drift is promoted');
etg_filter_drift_same(15034,$routeFindings[0]['details']['drift'][0]['filter_id'],'route blocker preserves offending filter ID');
$globalFindings=array_values(array_filter($enabled['findings'],static function($finding){return 'jetsmartfilters_taxonomy_target_drift_detected'===(string)($finding['code']??'');}));
etg_filter_drift_same(1,count($globalFindings),'inventory always exposes global filter-definition drift evidence');
etg_filter_drift_same('warning',$globalFindings[0]['severity'],'global drift summary stays warning/non-authorizing');
etg_filter_drift_expect($enabled['summary']['blocking']>=1,'enabled governed mismatch contributes to blocking summary');

$outside=$profile;
$outside['taxonomy_rules']=array('location_jet'=>array('role'=>'location'));
$outsideResult=$reconciler->analyze($snapshot,array('tours'=>$outside));
$outsideRouteFindings=array_values(array_filter($outsideResult['findings'],static function($finding){return 'profile_filter_taxonomy_target_drift'===(string)($finding['code']??'');}));
etg_filter_drift_same(0,count($outsideRouteFindings),'facet mismatch outside governed taxonomy rules is not a route blocker');
$outsideGlobal=array_values(array_filter($outsideResult['findings'],static function($finding){return 'jetsmartfilters_taxonomy_target_drift_detected'===(string)($finding['code']??'');}));
etg_filter_drift_same(1,count($outsideGlobal),'out-of-scope facet mismatch remains visible globally');

$disabled=$profile;
$disabled['enabled']=false;
$disabledResult=$reconciler->analyze($snapshot,array('tours'=>$disabled));
$disabledRouteFindings=array_values(array_filter($disabledResult['findings'],static function($finding){return 'profile_filter_taxonomy_target_drift'===(string)($finding['code']??'');}));
etg_filter_drift_same(1,count($disabledRouteFindings),'disabled governed profile keeps mismatch visible');
etg_filter_drift_same('warning',$disabledRouteFindings[0]['severity'],'disabled governed mismatch is review evidence rather than activation blocker');
etg_filter_drift_same(0,$disabledResult['summary']['blocking'],'disabled profile does not create blocking authority from filter drift');

echo "Alpha13 JetSmartFilters filter-definition drift smoke tests passed.\n";
