<?php
function sanitize_key($value){return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$value));}
function sanitize_text_field($value){return trim(strip_tags((string)$value));}
function wp_json_encode($value,$flags=0){return json_encode($value,$flags);}
require_once __DIR__ . '/../includes/Diagnostics/RuntimeInventory.php';
require_once __DIR__ . '/../includes/Diagnostics/InventoryReconciler.php';
use ETG\DynamicFilterSEOBridge\Diagnostics\InventoryReconciler;
use ETG\DynamicFilterSEOBridge\Diagnostics\RuntimeInventory;

function check($condition,$message){if(!$condition){fwrite(STDERR,"FAIL: $message\n");exit(1);}}
function refreshFingerprint(array &$snapshot){
    $map=array('post_types'=>count((array)($snapshot['inventory']['post_types']??array())),'taxonomies'=>count((array)($snapshot['inventory']['taxonomies']??array())),'languages'=>count((array)($snapshot['inventory']['languages']??array())),'query_builder'=>count((array)($snapshot['inventory']['query_builder']['queries']??array())));
    foreach($map as $section=>$count){if(empty($snapshot['inventory']['completeness'][$section]['truncated'])){$snapshot['inventory']['completeness'][$section]['observed_count']=$count;$snapshot['inventory']['completeness'][$section]['included_count']=$count;}}
    $snapshot['snapshot_fingerprint']=hash('sha256',json_encode($snapshot['inventory'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
}
function inventory(array $overrides=array()){
    $base=array(
        'contract'=>RuntimeInventory::CONTRACT,'authorizing'=>false,'read_only'=>true,'profile_mutation'=>false,
        'snapshot_fingerprint'=>'snap-a',
        'inventory'=>array(
            'post_types'=>array(
                'property'=>array('publicly_queryable'=>true,'has_archive'=>true,'taxonomies'=>array('property_city','property_type'),'archive_paths'=>array('en'=>'/properties/','ar'=>'/ar/عقارات/')),
                'product'=>array('publicly_queryable'=>true,'has_archive'=>true,'taxonomies'=>array('brand'),'archive_paths'=>array('en'=>'/shop/')),
            ),
            'taxonomies'=>array(
                'property_city'=>array('object_type'=>array('property')),
                'property_type'=>array('object_type'=>array('property')),
                'brand'=>array('object_type'=>array('product')),
            ),
            'languages'=>array(array('code'=>'en','url_path'=>'/'),array('code'=>'ar','url_path'=>'/ar/')),
            'query_builder'=>array(
                'available'=>true,'source'=>'test','identity_conflict_count'=>0,'identity_conflicts_truncated'=>false,'identity_conflicts'=>array(),
                'queries'=>array(
                    array('id'=>'10','custom_query_id'=>'property_archive','identity_key'=>'property_archive','type'=>'posts','post_types'=>array('property'),'post_type_bounded'=>true,'taxonomies'=>array('property_city')),
                    array('id'=>'20','custom_query_id'=>'product_archive','identity_key'=>'product_archive','type'=>'posts','post_types'=>array('product'),'post_type_bounded'=>true,'taxonomies'=>array('brand')),
                ),
            ),
            'completeness'=>array(
                'post_types'=>array('observed_count'=>2,'included_count'=>2,'limit'=>RuntimeInventory::MAX_POST_TYPES,'truncated'=>false),
                'taxonomies'=>array('observed_count'=>3,'included_count'=>3,'limit'=>RuntimeInventory::MAX_TAXONOMIES,'truncated'=>false),
                'languages'=>array('observed_count'=>2,'included_count'=>2,'limit'=>RuntimeInventory::MAX_LANGUAGES,'truncated'=>false),
                'query_builder'=>array('observed_count'=>2,'included_count'=>2,'limit'=>RuntimeInventory::MAX_QUERIES,'truncated'=>false),
                'archive_path_translations'=>array('observed_count'=>3,'included_count'=>3,'limit'=>RuntimeInventory::MAX_ARCHIVE_PATH_TRANSLATIONS,'truncated'=>false),
            ),
        ),
    );
    $out=array_replace_recursive($base,$overrides);
    $out['snapshot_fingerprint']=hash('sha256',json_encode($out['inventory'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    return $out;
}
$profile=array(
    'id'=>'properties','enabled'=>true,'post_types'=>array('property'),'taxonomy_rules'=>array('property_city'=>array(),'property_type'=>array()),
    'archive_paths'=>array('/properties/','/ar/عقارات/'),'routes'=>array(array('provider'=>'jet-engine','query_id'=>'property_archive')),
);
$r=new InventoryReconciler();
$out=$r->analyze(inventory(),array('properties'=>$profile));
check($out['contract']===InventoryReconciler::CONTRACT,'contract');
check($out['authorizing']===false && $out['profile_mutation']===false,'non-authorizing');
check($out['summary']['blocking']===0,'healthy profile should not block');
check(count($out['disabled_candidates'])===1,'unprofiled product candidate expected');
$c=$out['disabled_candidates'][0];
check($c['profile']['id']==='product' && $c['profile']['enabled']===false,'candidate disabled');
check($c['profile']['routes']===array() && $c['profile']['allowed_taxonomy_sets']===array() && $c['profile']['indexable_combinations']===array(),'candidate has no authority');
check($c['evidence']['suggested_routes'][0]['query_id']==='product_archive','route may be evidence only');

$unbounded=inventory(array('snapshot_fingerprint'=>'snap-b','inventory'=>array('query_builder'=>array('available'=>true,'source'=>'test','queries'=>array(
    array('id'=>'10','custom_query_id'=>'property_archive','identity_key'=>'property_archive','type'=>'posts','post_types'=>array('any'),'post_type_bounded'=>false,'taxonomies'=>array()),
))))); refreshFingerprint($unbounded);
$out2=$r->analyze($unbounded,array('properties'=>$profile),inventory());
$codes=array_column($out2['findings'],'code');
check(in_array('profile_query_unbounded_post_type',$codes,true),'current unbounded query blocks');
check(in_array('snapshot_query_became_unbounded',$codes,true),'boundedness downgrade blocks');
check($out2['state']==='blocked_drift' && $out2['summary']['blocking']>=2,'blocked drift state');

$missingTax=inventory(); unset($missingTax['inventory']['taxonomies']['property_type']); refreshFingerprint($missingTax);
$out3=$r->analyze($missingTax,array('properties'=>$profile));
check(in_array('profile_taxonomy_missing',array_column($out3['findings'],'code'),true),'missing taxonomy detected');

$mixed=inventory(array('inventory'=>array('query_builder'=>array('available'=>true,'source'=>'test','queries'=>array(
    array('id'=>'10','custom_query_id'=>'property_archive','identity_key'=>'property_archive','type'=>'posts','post_types'=>array('product','property'),'post_type_bounded'=>true,'taxonomies'=>array()),
))))); refreshFingerprint($mixed);
$out4=$r->analyze($mixed,array('properties'=>$profile));
check(in_array('profile_query_post_type_mismatch',array_column($out4['findings'],'code'),true),'mixed foreign post type detected');

$previous=inventory();
$changed=inventory(array('snapshot_fingerprint'=>'snap-c'));
$changed['inventory']['post_types']['property']['archive_paths']['ar']='/ar/عقارات-جديدة/';
$changed['inventory']['languages'][1]['url_path']='/ar-eg/';
unset($changed['inventory']['query_builder']['queries'][0]);
$changed['inventory']['query_builder']['queries']=array_values($changed['inventory']['query_builder']['queries']); refreshFingerprint($changed);
$outDrift=$r->analyze($changed,array('properties'=>$profile),$previous);
$driftCodes=array_column($outDrift['findings'],'code');
check(in_array('snapshot_post_types_changed',$driftCodes,true),'archive path drift detected');
check(in_array('snapshot_language_changed',$driftCodes,true),'language path drift detected');
check(in_array('snapshot_query_removed',$driftCodes,true),'query removal drift detected');


$archiveDrift=inventory();
$profileArchive=$profile;$profileArchive['archive_paths']=array('/listing-page/');
$outArchive=$r->analyze($archiveDrift,array('properties'=>$profileArchive));
check($outArchive['summary']['blocking']===0,'listing page archive mismatch is not blocking');
check(in_array('profile_archive_path_not_observed_as_post_type_archive',array_column($outArchive['findings'],'code'),true),'listing page archive warning recorded');

$tampered=inventory();$tampered['inventory']['post_types']['property']['label']='tampered';
$outTampered=$r->analyze($tampered,array('properties'=>$profile));
check($outTampered['state']==='invalid_inventory' && in_array('inventory_fingerprint_mismatch',$outTampered['errors'],true),'tampered fingerprint rejected');

$invalidPrevious=inventory();$invalidPrevious['snapshot_fingerprint']='bad';
$outPrevious=$r->analyze(inventory(),array('properties'=>$profile),$invalidPrevious);
check(in_array('previous_inventory_invalid',array_column($outPrevious['findings'],'code'),true),'invalid previous snapshot is visible');

$invalid=inventory();$invalid['authorizing']=true;
$out5=$r->analyze($invalid,array('properties'=>$profile));
check($out5['state']==='invalid_inventory' && in_array('inventory_must_be_non_authorizing',$out5['errors'],true),'authorizing inventory rejected');



$truncatedTax=inventory();
unset($truncatedTax['inventory']['taxonomies']['property_type']);
$truncatedTax['inventory']['completeness']['taxonomies']=array('observed_count'=>200,'included_count'=>2,'limit'=>RuntimeInventory::MAX_TAXONOMIES,'truncated'=>true);
refreshFingerprint($truncatedTax);
$outTruncated=$r->analyze($truncatedTax,array('properties'=>$profile));
$truncatedCodes=array_column($outTruncated['findings'],'code');
check(in_array('inventory_taxonomies_truncated',$truncatedCodes,true),'taxonomy truncation is blocking evidence');
check(in_array('profile_taxonomy_unresolved_inventory_truncated',$truncatedCodes,true),'missing taxonomy is unresolved when inventory truncated');
check(!in_array('profile_taxonomy_missing',$truncatedCodes,true),'truncated inventory must not assert false taxonomy missing');
check($outTruncated['disabled_candidates']===array(),'truncated inventory cannot generate candidates');

$collision=inventory();
$collision['inventory']['query_builder']['queries'][]=array('id'=>'11','custom_query_id'=>'property_archive','identity_key'=>'property_archive','type'=>'posts','post_types'=>array('product'),'post_type_bounded'=>true,'taxonomies'=>array());
$collision['inventory']['query_builder']['identity_conflict_count']=1;
$collision['inventory']['query_builder']['identity_conflicts']=array(array('identity_key'=>'property_archive','count'=>2,'records'=>array(array('id'=>'10','custom_query_id'=>'property_archive','type'=>'posts'),array('id'=>'11','custom_query_id'=>'property_archive','type'=>'posts'))));
$collision['inventory']['completeness']['query_builder']=array('observed_count'=>3,'included_count'=>3,'limit'=>RuntimeInventory::MAX_QUERIES,'truncated'=>false);
refreshFingerprint($collision);
$outCollision=$r->analyze($collision,array('properties'=>$profile));
$collisionCodes=array_column($outCollision['findings'],'code');
check(in_array('query_builder_identity_collision',$collisionCodes,true),'global query identity collision blocks inventory');
check(in_array('profile_query_identity_collision',$collisionCodes,true),'profile route collision blocks exact identity');
check(!in_array('profile_query_post_type_mismatch',$collisionCodes,true),'colliding query is never treated as a unique query');
check($outCollision['disabled_candidates']===array(),'ambiguous query inventory cannot generate candidates');

$unavailable=inventory();
$unavailable['inventory']['query_builder']['available']=false;
$unavailable['inventory']['query_builder']['source']='unavailable';
$unavailable['inventory']['query_builder']['queries']=array();
$unavailable['inventory']['completeness']['query_builder']=array('observed_count'=>0,'included_count'=>0,'limit'=>RuntimeInventory::MAX_QUERIES,'truncated'=>false);
refreshFingerprint($unavailable);
$outUnavailable=$r->analyze($unavailable,array('properties'=>$profile));
check(in_array('profile_query_inventory_unavailable',array_column($outUnavailable['findings'],'code'),true),'query inventory unavailable is not mislabeled missing');
check($outUnavailable['disabled_candidates']===array(),'unavailable query inventory cannot generate candidates');

$previousComplete=inventory();
$currentIncomplete=inventory();
unset($currentIncomplete['inventory']['taxonomies']['property_type']);
$currentIncomplete['inventory']['completeness']['taxonomies']=array('observed_count'=>200,'included_count'=>2,'limit'=>RuntimeInventory::MAX_TAXONOMIES,'truncated'=>true);
refreshFingerprint($currentIncomplete);
$outCompare=$r->analyze($currentIncomplete,array('properties'=>$profile),$previousComplete);
$compareCodes=array_column($outCompare['findings'],'code');
check(in_array('snapshot_taxonomies_comparison_skipped_incomplete_inventory',$compareCodes,true),'snapshot taxonomy comparison skipped when incomplete');
check(!in_array('snapshot_taxonomies_removed',$compareCodes,true),'incomplete snapshot must not report false removal');

$badCompleteness=inventory();
$badCompleteness['inventory']['completeness']['query_builder']['included_count']=99;
$badCompleteness['snapshot_fingerprint']=hash('sha256',json_encode($badCompleteness['inventory'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
$outBadCompleteness=$r->analyze($badCompleteness,array('properties'=>$profile));
check($outBadCompleteness['state']==='invalid_inventory' && in_array('inventory_query_builder_completeness_included_count_mismatch',$outBadCompleteness['errors'],true),'tampered completeness metadata rejected');

echo "Inventory reconciliation smoke tests passed.\n";
