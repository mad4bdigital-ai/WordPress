<?php
/** Standalone synthetic-only classification reconciliation: no WP, network or DB. */
define('ABSPATH', __DIR__.'/');
class WP_Error {
    private $code;
    public function __construct($code, $message='') {$this->code=$code;}
    public function get_error_code() {return $this->code;}
}
function is_wp_error($value) {return $value instanceof WP_Error;}
function wp_json_encode($value, $flags=0) {return json_encode($value,$flags);}
function get_current_user_id() {return 0;}
function current_user_can($capability) {return false;}
require dirname(__DIR__).'/includes/class-mad4b-scp-context-authority.php';
function expect($condition,$name) {
    if (!$condition) {fwrite(STDERR,"FAIL {$name}\n");exit(1);}
    echo "{$name}: PASS\n";
}
$id=str_repeat('a',64);
$aid=str_repeat('b',64);
$uuid='a3a7cc36-db7e-4a14-8456-b41857def05c';
$brand=str_repeat('c',32);
$source=array('source_id'=>$id,'site_uuid'=>$uuid,'brand_id'=>$brand,
 'provider'=>'google_drive','mode'=>'governed','status'=>'partial_scan',
 'last_scan_generation'=>str_repeat('d',64),'asset_count'=>1);
$asset=array('asset_id'=>$aid,'source_id'=>$id,'site_uuid'=>$uuid,'brand_id'=>$brand,
 'title'=>'Transport configuration snapshot','path'=>'Brand Core/Brand Strategy',
 'category'=>'brand_strategy','authority_class'=>'brand_authority','required'=>true,
 'priority'=>100,'classification_confidence'=>0.96,
 'classification_source'=>'automatic_heuristic','review_status'=>'unreviewed',
 'reviewed_content_hash'=>'','reviewed_at'=>'','content_complete'=>true,
 'content_hash'=>str_repeat('e',64));
$records=array($aid=>$asset);
$run=static function($s,$r)use($id,$uuid){
 return MAD4B_SCP_Context_Authority::classification_reconcile_preview(
   $s,array('site_uuid'=>$uuid),$r,$id,9,str_repeat('f',64));
};
$p=$run($source,$records);
expect(is_array($p)&&count($p['changes'])===1&&$p['blocked_reviewed_count']===0,'one_title_only_reclassification');
expect($p['changes'][0]['after']['category']==='uncategorized'
 && $p['changes'][0]['after']['authority_class']==='reference'
 && $p['changes'][0]['after']['required']===false,'no_false_brand_authority');
expect($p['changes'][0]['content_hash']===$asset['content_hash']
 && $p['authorizing']===false,'preserve_original_content_sha');
$hash=$p['plan_sha256'];$unsigned=$p;unset($unsigned['plan_sha256']);
expect(hash_equals($hash,hash('sha256',wp_json_encode($unsigned,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE))),
 'exact_plan_sha');
$reviewed=$asset;$reviewed['review_status']='approved';
$reviewed['reviewed_content_hash']=$asset['content_hash'];
$reviewed['reviewed_at']='2026-11-19T00:00:00Z';
$p2=$run($source,array($aid=>$reviewed));
expect(is_array($p2)&&count($p2['changes'])===0&&$p2['blocked_reviewed_count']===1,
 'human_review_never_queued');
$manual=$asset;$manual['classification_source']='manual';
expect($run($source,array($aid=>$manual))['blocked_reviewed_count']===1,
 'manual_classification_never_queued');
$incomplete=$asset;$incomplete['content_complete']=false;
expect($run($source,array($aid=>$incomplete))['blocked_reviewed_count']===1,
 'incomplete_content_never_queued');
$stale=$asset;$stale['content_hash']='invalid';
expect($run($source,array($aid=>$stale))['blocked_reviewed_count']===1,
 'invalid_hash_never_queued');
$foreign=$asset;$foreign['site_uuid']='bad';
expect(is_wp_error($run($source,array($aid=>$foreign))),'foreign_site_denied');
$foreign=$asset;$foreign['brand_id']=str_repeat('0',32);
expect(is_wp_error($run($source,array($aid=>$foreign))),'foreign_brand_denied');
$wrong=$source;$wrong['asset_count']=2;
expect(is_wp_error($run($wrong,$records)),'unindexed_asset_count_denied');
foreach(array('provider'=>'local','mode'=>'task_attachment','status'=>'ready',
 'last_scan_generation'=>'invalid','site_uuid'=>'other') as $key=>$value){
 $wrong=$source;$wrong[$key]=$value;
 expect(is_wp_error($run($wrong,$records)),'bad_source_'.$key.'_denied');
}
$new=$asset;$new['title']='Brand Strategy — Approved Document';
expect($run($source,array($aid=>$new))['plan_sha256']!==$p['plan_sha256'],
 'changed_title_invalidates_plan_sha');
expect(is_wp_error(MAD4B_SCP_Context_Authority::classification_reconcile_apply(
 array('confirm_metadata_only'=>true,'source_id'=>$id,'plan_sha256'=>$hash,'registry_revision'=>9))),
 'no_wpcli_mutation_denied');
expect(is_wp_error(MAD4B_SCP_Context_Authority::classification_reconcile_plan($id)),
 'unauthenticated_plan_denied');
echo "CONTEXT_CLASSIFICATION_RECONCILE_HERMETIC: PASS\n";
