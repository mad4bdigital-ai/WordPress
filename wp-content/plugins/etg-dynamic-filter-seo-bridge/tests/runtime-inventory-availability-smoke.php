<?php
function sanitize_key($value){return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$value));}
function sanitize_text_field($value){return trim(strip_tags((string)$value));}
function wp_json_encode($value,$flags=0){return json_encode($value,$flags);}
require_once __DIR__ . '/../includes/Diagnostics/RuntimeInventory.php';
require_once __DIR__ . '/../includes/Diagnostics/InventoryReconciler.php';
use ETG\DynamicFilterSEOBridge\Diagnostics\RuntimeInventory;
use ETG\DynamicFilterSEOBridge\Diagnostics\InventoryReconciler;
function check($condition,$message){if(!$condition){fwrite(STDERR,"FAIL: $message\n");exit(1);}}
$inventory=array(
    'post_types'=>array(),
    'taxonomies'=>array(),
    'languages'=>array(),
    'query_builder'=>array('available'=>false,'source'=>'unavailable','identity_conflict_count'=>0,'identity_conflicts_truncated'=>false,'identity_conflicts'=>array(),'queries'=>array()),
    'availability'=>array(
        'post_types'=>array('available'=>true,'source'=>'wordpress_get_post_types'),
        'taxonomies'=>array('available'=>true,'source'=>'wordpress_get_taxonomies'),
        'languages'=>array('available'=>false,'source'=>'wpml_active_languages_unavailable'),
        'query_builder'=>array('available'=>false,'source'=>'unavailable'),
        'archive_path_translations'=>array('available'=>false,'source'=>'languages_unavailable'),
    ),
    'completeness'=>array(
        'post_types'=>array('observed_count'=>0,'included_count'=>0,'limit'=>RuntimeInventory::MAX_POST_TYPES,'truncated'=>false),
        'taxonomies'=>array('observed_count'=>0,'included_count'=>0,'limit'=>RuntimeInventory::MAX_TAXONOMIES,'truncated'=>false),
        'languages'=>array('observed_count'=>0,'included_count'=>0,'limit'=>RuntimeInventory::MAX_LANGUAGES,'truncated'=>false),
        'query_builder'=>array('observed_count'=>0,'included_count'=>0,'limit'=>RuntimeInventory::MAX_QUERIES,'truncated'=>false),
        'archive_path_translations'=>array('observed_count'=>0,'included_count'=>0,'limit'=>RuntimeInventory::MAX_ARCHIVE_PATH_TRANSLATIONS,'truncated'=>false),
    ),
);
$snapshot=array(
    'contract'=>RuntimeInventory::UNAVAILABLE_CONTRACT,
    'expected_contract'=>RuntimeInventory::CONTRACT,
    'authorizing'=>false,
    'read_only'=>true,
    'profile_mutation'=>false,
    'evidence_complete'=>false,
    'availability_errors'=>array('languages_unavailable','query_builder_unavailable','archive_path_translations_unavailable'),
    'snapshot_fingerprint'=>hash('sha256',json_encode($inventory,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)),
    'inventory'=>$inventory,
);
$result=(new InventoryReconciler())->analyze($snapshot,array());
check($result['state']==='invalid_inventory','unavailable runtime evidence must be rejected by reconciliation');
check(in_array('invalid_inventory_contract',$result['errors'],true),'unavailable contract is an explicit reconciliation error');
check($result['disabled_candidates']===array(),'unavailable runtime evidence cannot generate disabled candidates');
check($result['authorizing']===false && $result['profile_mutation']===false,'rejected evidence remains non-authorizing and non-mutating');
echo "Runtime inventory availability reconciliation smoke tests passed.\n";
