<?php
declare(strict_types=1);

function sanitize_key($value){return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$value));}
function sanitize_text_field($value){return trim(strip_tags((string)$value));}
function absint($value){return abs((int)$value);}
function wp_json_encode($value,$flags=0){return json_encode($value,$flags);}
function etg_drift_expect($condition,string $message):void{if(!$condition){fwrite(STDERR,"FAIL: {$message}\n");exit(1);}}
function etg_drift_same($expected,$actual,string $message):void{if($expected!==$actual){fwrite(STDERR,"FAIL: {$message}\nEXPECTED ".var_export($expected,true)."\nACTUAL ".var_export($actual,true)."\n");exit(1);}}

$root=dirname(__DIR__);
require_once $root.'/includes/Runtime/RuntimeTopologyDiscoverer.php';
require_once $root.'/includes/Diagnostics/RuntimeInventory.php';
require_once $root.'/includes/Diagnostics/InventoryReconciler.php';

use ETG\DynamicFilterSEOBridge\Runtime\RuntimeTopologyDiscoverer;
use ETG\DynamicFilterSEOBridge\Diagnostics\RuntimeInventory;
use ETG\DynamicFilterSEOBridge\Diagnostics\InventoryReconciler;

class Alpha13DriftQuery {
    public $id;
    public $query_id;
    private $type;
    private $args;
    public function __construct($id,string $queryId,string $postType){$this->id=$id;$this->query_id=$queryId;$this->type='posts';$this->args=array('post_type'=>$postType);}
    public function get_query_type(){return $this->type;}
    public function get_query_args(){return $this->args;}
}

$queries=array(
    new Alpha13DriftQuery(239,'property_qb','properties'),
    new Alpha13DriftQuery(246,'transport_qb','transportations'),
);
$queryProvider=function()use($queries){return $queries;};
$templateProvider=function(){return array(
    array('id'=>37924,'data'=>array(
        array('id'=>'property-listing','elType'=>'widget','widgetType'=>'jet-listing-grid','settings'=>array('_element_id'=>'property_query_archive','custom_query'=>'yes','custom_query_id'=>'239')),
        array('id'=>'property-active','elType'=>'widget','widgetType'=>'jet-smart-filters-active','settings'=>array('query_id'=>'property_query_archive','content_provider'=>'jet-engine')),
        array('id'=>'property-sort','elType'=>'widget','widgetType'=>'jet-smart-filters-sorting','settings'=>array('query_id'=>'trans_query_archive','content_provider'=>'jet-engine')),
    )),
    array('id'=>40308,'data'=>array(
        array('id'=>'transport-listing','elType'=>'widget','widgetType'=>'jet-listing-grid','settings'=>array('_element_id'=>'trans_query_archive','custom_query'=>'yes','custom_query_id'=>'246')),
        array('id'=>'transport-active','elType'=>'widget','widgetType'=>'jet-smart-filters-active','settings'=>array('query_id'=>'trans_query_archive','content_provider'=>'jet-engine')),
    )),
);};

$topology=(new RuntimeTopologyDiscoverer($templateProvider,$queryProvider))->discover(true);
etg_drift_same(2,$topology['binding_count'],'both archive listing bindings are verified');
etg_drift_same(1,$topology['provider_group_drift_count'],'only the mismatched Properties sorting surface is drift');
$drift=$topology['provider_group_drift'][0];
etg_drift_same(37924,$drift['template_id'],'drift is bound to the Properties archive template');
etg_drift_same('property-sort',$drift['node_id'],'drift preserves the exact Elementor node ID');
etg_drift_same('jet-smart-filters-sorting',$drift['widget_type'],'drift preserves widget type');
etg_drift_same('trans_query_archive',$drift['observed_query_id'],'foreign provider query ID is preserved');
etg_drift_same(array('property_query_archive'),$drift['expected_provider_query_ids'],'verified listing group defines expected query authority');
etg_drift_same(array('properties'),$drift['expected_post_types'],'expected post type comes from verified Query Builder binding');
etg_drift_same(array('transportations'),$drift['observed_post_types'],'foreign query group resolves to its actual post type');
etg_drift_same('provider_group_post_type_mismatch',$drift['reason'],'cross-CPT drift receives the strongest diagnostic reason');
etg_drift_same('blocking',$drift['severity_hint'],'cross-CPT drift carries blocking severity hint without itself authorizing mutation');
etg_drift_same(false,$drift['authorizing'],'topology drift evidence remains non-authorizing');

$queryRecords=array(
    array('id'=>'239','custom_query_id'=>'property_qb','identity_key'=>'property_qb','type'=>'posts','post_types'=>array('properties'),'post_type_bounded'=>true),
    array('id'=>'246','custom_query_id'=>'transport_qb','identity_key'=>'transport_qb','type'=>'posts','post_types'=>array('transportations'),'post_type_bounded'=>true),
);
$inventory=array(
    'post_types'=>array(
        'properties'=>array('label'=>'Properties','publicly_queryable'=>true,'has_archive'=>true,'taxonomies'=>array('location_jet','property-types','stars'),'archive_paths'=>array('current'=>'/properties/')),
        'transportations'=>array('label'=>'Transportations','publicly_queryable'=>true,'has_archive'=>true,'taxonomies'=>array('location_jet','transportation-types','transport-service-type'),'archive_paths'=>array('current'=>'/transportations/')),
    ),
    'taxonomies'=>array(
        'location_jet'=>array('object_type'=>array('properties','transportations')),
        'property-types'=>array('object_type'=>array('properties')),
        'stars'=>array('object_type'=>array('properties')),
        'transportation-types'=>array('object_type'=>array('transportations')),
        'transport-service-type'=>array('object_type'=>array('transportations')),
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
    'completeness'=>array(
        'post_types'=>array('observed_count'=>2,'included_count'=>2,'limit'=>RuntimeInventory::MAX_POST_TYPES,'truncated'=>false),
        'taxonomies'=>array('observed_count'=>5,'included_count'=>5,'limit'=>RuntimeInventory::MAX_TAXONOMIES,'truncated'=>false),
        'languages'=>array('observed_count'=>1,'included_count'=>1,'limit'=>RuntimeInventory::MAX_LANGUAGES,'truncated'=>false),
        'query_builder'=>array('observed_count'=>2,'included_count'=>2,'limit'=>RuntimeInventory::MAX_QUERIES,'truncated'=>false),
        'query_identity_index'=>array('observed_count'=>2,'included_count'=>2,'limit'=>RuntimeInventory::MAX_QUERY_IDENTITIES,'truncated'=>false),
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
    'id'=>'properties',
    'enabled'=>true,
    'post_types'=>array('properties'),
    'taxonomy_rules'=>array('location_jet'=>array('role'=>'location'),'property-types'=>array('role'=>'property_type'),'stars'=>array('role'=>'stars')),
    'archive_paths'=>array('/properties/'),
    'routes'=>array(array('provider'=>'jet-engine','query_id'=>'property_query_archive')),
);

$reconciler=new InventoryReconciler();
$enabled=$reconciler->analyze($snapshot,array('properties'=>$profile));
$enabledFindings=array_values(array_filter($enabled['findings'],static function($finding){return 'profile_elementor_provider_group_drift'===(string)($finding['code']??'');}));
etg_drift_same(1,count($enabledFindings),'enabled Properties profile receives one route-scoped drift finding');
etg_drift_same('blocking',$enabledFindings[0]['severity'],'enabled profile fails closed on the live-style cross-provider mismatch');
etg_drift_expect($enabled['summary']['blocking']>=1,'enabled route drift contributes to blocking summary');

$profile['enabled']=false;
$disabled=$reconciler->analyze($snapshot,array('properties'=>$profile));
$disabledFindings=array_values(array_filter($disabled['findings'],static function($finding){return 'profile_elementor_provider_group_drift'===(string)($finding['code']??'');}));
etg_drift_same(1,count($disabledFindings),'disabled profile keeps the same drift visible');
etg_drift_same('warning',$disabledFindings[0]['severity'],'disabled profile drift is review evidence, not an activation blocker');
etg_drift_same(0,$disabled['summary']['blocking'],'disabled profile does not convert drift into global blocking authority');

echo "Alpha13 Elementor provider-group drift smoke tests passed.\n";
