<?php
/** Exact-site snapshot import tests, no WordPress DB or original Job. */
define('ABSPATH','/');
define('WP_CLI',true);
class WP_Error {
 private string $code;
 public function __construct($code,$message='',$data=[]){$this->code=$code;}
 public function get_error_code(){return $this->code;}
}
function is_wp_error($x):bool {return $x instanceof WP_Error;}
function wp_json_encode($x,$flags=0):string|false {return json_encode($x,$flags);}
function current_user_can($cap):bool {return ($GLOBALS['ADMIN']??true)&&$cap==='manage_options';}
function get_option($key,$default=false){return $GLOBALS['OPTIONS'][$key]??$default;}
class TestSnapshotDB {
 public function prepare($sql,...$args){return $sql;}
 public function get_var($sql){return ($GLOBALS['DENY_LOCK']??false)&&
     str_contains($sql,'GET_LOCK')?0:1;}
}
$GLOBALS['wpdb']=new TestSnapshotDB();
function add_option($key,$value,$deprecated='',$autoload=false):bool {
 if(isset($GLOBALS['OPTIONS'][$key]))return false;
 $GLOBALS['OPTIONS'][$key]=$value;return true;
}
function update_option($key,$value,$autoload=false):bool {
 $GLOBALS['OPTIONS'][$key]=$value;return true;
}
function wp_is_uuid($str){return is_string($str)&&preg_match('/^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/D',$str);}
class MAD4B_Company_Reconcile_Repair_Jobs_V1 {
 public static function central():bool {return true;}
}
require dirname(__DIR__).'/includes/class-mad4b-company-repair-resource-resolver.php';
require dirname(__DIR__).'/includes/class-mad4b-company-resource-snapshot-store.php';
$c='MAD4B_Company_Resource_Snapshot_Store_V1';
$assertions=[];$assert=static function($name,$pass)use(&$assertions){$assertions[$name]=(bool)$pass;};
$uuid='a3a7cc36-db7e-4a14-8456-b41857def05c';
$plan=[
 'company_code'=>'wayfatours','environment'=>'production',
 'site_uuid'=>$uuid,'canonical_origin'=>'https://wayfatours.com',
 'incident_code'=>'draft_tour_rate_advisory',
 'source_plan_sha256'=>str_repeat('a',64)
];
$scope=$plan;$id='61152';
$evidence=[
 'contract'=>'mad4b.company-repair.resource-observation.v1',
 'state'=>'EXACT_RESOURCE_READ_ONLY_OBSERVED',
 'observation_level'=>'TARGET_WORDPRESS_AUTHENTICATED_READ_ONLY',
 'site_uuid'=>$uuid,'canonical_origin'=>'https://wayfatours.com',
 'environment'=>'production','resource_type'=>'wordpress.post',
 'resource_id'=>$id,'resource_key'=>'wordpress.post:'.$id,
 'ownership_scope'=>'tenant_owned',
 'before_sha256'=>str_repeat('b',64),
 'resource_manifest_sha256'=>str_repeat('c',64),
 'company_preservation_sha256'=>str_repeat('d',64),
 'observed_epoch'=>time(),
 'source_of_evidence'=>'LOCAL_ADMINISTRATOR_WORDPRESS_READ_ONLY',
 'raw_sensitive_metadata_emitted'=>false,
 'resource_sample_counts'=>['metadata_key_count'=>39,'taxonomy_count'=>1],
 'original_313_signed'=>false,'original_312_signed'=>false,
 'mutation_performed'=>false,'native_job_created'=>false,
];
$GLOBALS['DENY_LOCK']=true;
$blocked=$c::ingest($plan,$evidence);
$assert('concurrent_snapshot_lease_blocks_updates',is_wp_error($blocked));
$GLOBALS['DENY_LOCK']=false;
$test=$c::ingest($plan,$evidence);
$assert('exact_original_site_snapshot_admitted',
 !is_wp_error($test)&&$test['state']==='UNSIGNED_TARGET_SNAPSHOT_STORED');
$assert('signed_313_not_claimed',$test['independent_original_313']===false);
$assert('native_job_not_created',$test['native_job_submitted']===false);
$record=$c::get($scope,'wordpress.post',$id);
$assert('readback_from_center_exact',!is_wp_error($record)
 &&$record['before_sha256']===str_repeat('b',64));
$another=$c::ingest($plan,$evidence);
$assert('same_snapshot_import_is_idempotent',!is_wp_error($another));
$copies=[
 'wrong_site_uuid'=>['site_uuid','a3a7cc36-db7e-4a14-8456-b41857def05f'],
 'wrong_origin'=>['canonical_origin','https://other.invalid'],
 'wrong_environment'=>['environment','staging'],
 'wrong_resource_key'=>['resource_key','wordpress.post:12'],
 'wrong_ownership'=>['ownership_scope','never_clone'],
 'claim_original_313'=>['original_313_signed',true],
 'claim_original_312'=>['original_312_signed',true],
 'claim_target_mutation'=>['mutation_performed',true],
 'claim_job_created'=>['native_job_created',true],
 'raw_secrets_claim'=>['raw_sensitive_metadata_emitted',true],
 'fake_source_kind'=>['source_of_evidence','HTTP_ANYONE'],
 'invalid_scope_claim'=>['observation_level','PUBLIC_HTML'],
 'changed_raw_before_hash'=>['before_sha256',str_repeat('e',64)],
 'changed_metadata_manifest'=>['resource_manifest_sha256',str_repeat('e',64)],
 'changed_company_preservation'=>['company_preservation_sha256',str_repeat('e',64)]
];
foreach($copies as $name=>[$key,$value]){
 $copy=$evidence;$copy[$key]=$value;
 $err=$c::ingest($plan,$copy);
 $assert($name.'_rejected',is_wp_error($err));
}
$changed=$evidence;$changed['observed_epoch']=time()-301;
$assert('stale_target_readback_rejected',is_wp_error($c::ingest($plan,$changed)));
$changed=$evidence;$changed['observed_epoch']=time()+31;
$assert('future_target_time_rejected',is_wp_error($c::ingest($plan,$changed)));
$changed=$evidence;$changed['resource_sample_counts']['metadata_key_count']='many';
$assert('unstructured_counts_rejected',is_wp_error($c::ingest($plan,$changed)));
$changed=$evidence;$changed['customer_email']='sensitive';
$assert('unknown_fields_rejected',is_wp_error($c::ingest($plan,$changed)));
$key=$c::key($scope,'wordpress.post',$id);
$original=$GLOBALS['OPTIONS'][$key];
$GLOBALS['OPTIONS'][$key]['target_source']['before_sha256']=str_repeat('f',64);
$assert('stored_fingerprint_mismatch_rejected',
 is_wp_error($c::get($scope,'wordpress.post',$id)));
$GLOBALS['OPTIONS'][$key]=$original;
$changedScope=$scope;$changedScope['site_uuid']='a3a7cc36-db7e-4a14-8456-b41857def05f';
$assert('different_company_site_key_isolated',
 is_wp_error($c::get($changedScope,'wordpress.post',$id)));
$changedScope=$scope;$changedScope['source_plan_sha256']=str_repeat('f',64);
$assert('source_plan_version_isolated',
 is_wp_error($c::get($changedScope,'wordpress.post',$id)));
$GLOBALS['ADMIN']=false;
$assert('non_admin_cli_ingest_rejected',
 is_wp_error($c::ingest($plan,$evidence)));
echo json_encode(['pass'=>!in_array(false,$assertions,true),
 'assertions'=>count($assertions),
 'failed'=>array_keys(array_filter($assertions,fn($v)=>!$v)),
 'signed_original_313'=>false],JSON_UNESCAPED_SLASHES).PHP_EOL;
if(in_array(false,$assertions,true))exit(1);
