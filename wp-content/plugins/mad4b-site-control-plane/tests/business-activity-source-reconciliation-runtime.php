<?php
/** Isolated N-way, field-ownership, conflict and source identity test fixture. */
define( 'ABSPATH', __DIR__ );
class WP_Error { private $code; function __construct($c,$m=''){ $this->code=$c; } function get_error_code(){return $this->code;} }
function is_wp_error($x){return $x instanceof WP_Error;}
function wp_json_encode($v,$options=0){return json_encode($v,$options);}
function current_user_can($cap,$id=0){return true;}
function get_post_type($id){return $id===101?'vendor_profiles':'post';}
class MAD4B_SCP_Site_Profile {
 static function configured(){return true;}
 static function origin_enrolled(){return true;}
 static function site_urls_match_enrollment(){return true;}
 static function site_uuid(){return '11111111-2222-4333-8444-555555555555';}
}
class MAD4B_SCP_Content_Experience_Profiles {
 static function profile($slug){
  if($slug!=='vendor'){return new WP_Error('unknown');}
  return array('slug'=>'vendor','enabled'=>true,'revision'=>2,
   'post_type'=>'vendor_profiles','authority_sha256'=>str_repeat('a',64),
   'activity_contract'=>array('enabled'=>true,'sync_identity_key'=>'post_id',
    'attribute_meta_keys'=>array('region_code','biography'),
    'field_owners'=>array('region_code'=>'wordpress','biography'=>'brand_drive'),
    'sync_targets'=>array(
      'brand_drive'=>array('provider'=>'google_drive','source_ref'=>'drive_doc_01',
       'direction'=>'bidirectional','field_keys'=>array('region_code','biography')),
      'archive_drive'=>array('provider'=>'google_drive','source_ref'=>'drive_doc_02',
       'direction'=>'export','field_keys'=>array('biography')),
      'editorial_drive'=>array('provider'=>'google_drive','source_ref'=>'editorial_doc_01',
       'direction'=>'import','field_keys'=>array(),'purpose'=>'editorial_policy',
       'resource_kind'=>'drive_document')
    )));
 }
}
require __DIR__.'/../includes/class-mad4b-scp-activity-source-reconciliation.php';
function check($value,$message){if(!$value)throw new RuntimeException($message);}
function fingerprint($value){return hash('sha256',json_encode(array('type'=>gettype($value),'value'=>$value),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));}
function record($resource,$revision,$values){
 return array('resource_id'=>$resource,'revision'=>$revision,'observed_at'=>gmdate('Y-m-d\TH:i:s\Z'),'state'=>'present','fields'=>$values);
}
function base_record($observed){$h=array();foreach($observed['fields'] as $k=>$v)$h[$k]=fingerprint($v);return array('resource_id'=>$observed['resource_id'],'revision'=>$observed['revision'],'field_hashes'=>$h);}
$input=array(
 'profile_slug'=>'vendor','entity_id'=>'101',
 'observations'=>array(
   'wordpress'=>record('101','wp_r1',array('region_code'=>'EG','biography'=>'Initial')),
   'brand_drive'=>record('drive_doc_01','rev1',array('region_code'=>'EG','biography'=>'Initial')),
   'archive_drive'=>record('drive_doc_02','rev1',array('biography'=>'Initial'))
 ),
);
$input['baseline']=array();
foreach($input['observations'] as $id=>$o)$input['baseline'][$id]=base_record($o);
$clean=MAD4B_SCP_Activity_Source_Reconciliation::plan($input);
check(!is_wp_error($clean)&&!$clean['has_conflicts']&&count($clean['field_results'])===2,'Clean multi-source reconciliation rejected');
check(!$clean['ready_for_automatic_apply']&&!$clean['provider_receipts_independently_verified']&&!$clean['client_supplied_evidence_trusted'],'Untrusted snapshots became write authority');
$owner=$input;$owner['observations']['brand_drive']['fields']['biography']='Approved updated';
$r=MAD4B_SCP_Activity_Source_Reconciliation::plan($owner);
check(!is_wp_error($r)&&!$r['has_conflicts']&&count($r['copy_proposals'])===1 &&
 $r['copy_proposals'][0]['source_id']==='brand_drive'&&
 count($r['copy_proposals'][0]['destination_ids'])===2,'Single canonical Drive edit did not yield two bounded proposals');
$other=$input;$other['observations']['wordpress']['fields']['biography']='Unexpected WordPress edit';
$r=MAD4B_SCP_Activity_Source_Reconciliation::plan($other);
check(!is_wp_error($r)&&$r['has_conflicts']&&$r['conflicts'][0]['code']==='non_owner_changed','Nonowner edit silently won');
$both=$owner;$both['observations']['wordpress']['fields']['biography']='Concurrent WordPress edit';
$r=MAD4B_SCP_Activity_Source_Reconciliation::plan($both);
check(!is_wp_error($r)&&$r['has_conflicts']&&$r['conflicts'][0]['code']==='concurrent_source_edit','Concurrent edits merged silently');
$stale=$input;$stale['observations']['brand_drive']['observed_at']='2020-01-01T00:00:00Z';
$r=MAD4B_SCP_Activity_Source_Reconciliation::plan($stale);
check(!is_wp_error($r)&&$r['has_conflicts']&&$r['conflicts'][0]['code']==='snapshot_missing_or_stale','Stale source data accepted');
$deleted=$input;$deleted['observations']['brand_drive']['state']='deleted';
$r=MAD4B_SCP_Activity_Source_Reconciliation::plan($deleted);
check(!is_wp_error($r)&&$r['has_conflicts']&&$r['conflicts'][0]['code']==='missing_or_deleted_requires_reconciliation','Deletion propagated without review');
$swapped=$input;$swapped['observations']['brand_drive']['resource_id']='drive_doc_replaced';
$r=MAD4B_SCP_Activity_Source_Reconciliation::plan($swapped);
check(!is_wp_error($r)&&$r['has_conflicts']&&$r['conflicts'][0]['code']==='configured_source_resource_mismatch','Drive file identity swap accepted');
$dup=$input;$dup['observations']['archive_drive']['resource_id']='drive_doc_01';
$dup['baseline']['archive_drive']['resource_id']='drive_doc_01';
$r=MAD4B_SCP_Activity_Source_Reconciliation::plan($dup);
check(!is_wp_error($r)&&$r['has_conflicts']&&$r['conflicts'][0]['code']==='configured_source_resource_mismatch','Duplicate or swapped resource accepted');
$missing=$input;unset($missing['observations']['archive_drive']);
$r=MAD4B_SCP_Activity_Source_Reconciliation::plan($missing);
check(!is_wp_error($r)&&$r['has_conflicts']&&$r['conflicts'][0]['code']==='snapshot_missing_or_stale','Missing source treated as blank');
$prior_conflict=$input;$prior_conflict['baseline']['wordpress']['field_hashes']['biography']=fingerprint('Historic different');
$r=MAD4B_SCP_Activity_Source_Reconciliation::plan($prior_conflict);
check(!is_wp_error($r)&&$r['has_conflicts']&&$r['conflicts'][0]['code']==='baseline_already_divergent','Already-divergent baseline incorrectly trusted');
$fake=$input;$fake['observations']['brand_drive']['verified']=true;
$r=MAD4B_SCP_Activity_Source_Reconciliation::plan($fake);
check(!is_wp_error($r)&&$r['has_conflicts'],'Client-provided verification flag was accepted as external receipt');
$notlinked=$input;$notlinked['entity_id']='102';
$r=MAD4B_SCP_Activity_Source_Reconciliation::plan($notlinked);
check(is_wp_error($r)&&$r->get_error_code()==='mad4b_activity_reconcile_cpt_identity_mismatch','Cross-CPT ID accepted');
$context=MAD4B_SCP_Activity_Source_Reconciliation::context_impact_plan(array(
 'profile_slug'=>'vendor',
 'current_revisions'=>array('editorial_drive'=>array('resource_id'=>'editorial_doc_01',
    'revision'=>'new_rev','observed_at'=>gmdate('Y-m-d\TH:i:s\Z'))),
 'used_revisions'=>array('editorial_drive'=>array('resource_id'=>'editorial_doc_01','revision'=>'old_rev'))));
check(!is_wp_error($context)&&$context['review_required']&&
 $context['impact'][0]['status']==='source_revision_changed'&&
 !$context['update_authorized']&&!$context['publication_authorized'],
 'Changed editorial guidelines caused auto-publication');
$contextMismatch=MAD4B_SCP_Activity_Source_Reconciliation::context_impact_plan(array(
 'profile_slug'=>'vendor',
 'current_revisions'=>array('editorial_drive'=>array('resource_id'=>'different_file',
    'revision'=>'new_rev','observed_at'=>gmdate('Y-m-d\TH:i:s\Z'))),
 'used_revisions'=>array('editorial_drive'=>array('resource_id'=>'editorial_doc_01','revision'=>'old_rev'))));
check(!is_wp_error($contextMismatch)&&$contextMismatch['review_required']&&
 $contextMismatch['impact'][0]['status']==='source_unverified_or_stale',
 'Editorial policy silently followed swapped file');
$ob=MAD4B_SCP_Activity_Source_Reconciliation::plan($input);
check(!is_wp_error($ob)&&isset($ob['separate_context_sources']['editorial_drive'])&&
 !in_array('editorial_drive',$ob['sources_expected'],true),
 'Editorial policy was treated as a business field value source');
echo "PASS N-way business field and editorial context revisions, source rights, staleness and anti-autorun cases\n";
