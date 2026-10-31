<?php
/**
 * Isolated contract tests for Portfolio Manager's existing company_binding().
 * No WordPress boot, DB connection, REST calls, grants, or remote writes.
 */
declare(strict_types=1);
define('ABSPATH', '/isolated-test/');
$GLOBALS['options']=[];
$GLOBALS['candidate']=[];
$GLOBALS['proof']=[];
$GLOBALS['hooks']=[];
function add_action(...$args) { $GLOBALS['hooks'][]=$args[0]; }
function get_option($name,$default=[]) {return $GLOBALS['options'][$name]??$default;}
function wp_is_uuid($value) {return is_string($value)&&preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/D',$value)===1;}
function is_wp_error($value) {return $value instanceof WP_Error;}
class WP_Error {
 private string $code;
 function __construct($code,$message='',$meta=[]) {$this->code=$code;}
 function get_error_code() {return $this->code;}
}
class MAD4B_Company_Environment_Registry_V2 {
 static function target($company,$env) {return $GLOBALS['candidate'][$company.':'.$env]??null;}
}
class MAD4B_Environment_Signed_Identity_V4 {
 static function status($company,$env) {return $GLOBALS['proof'][$company.':'.$env]??['centrally_verified'=>false];}
}
require __DIR__.'/mad4b-company-portfolio-manager.php';

$company='all-royal-egypt';
$site='49c562d1-8f2f-456f-b454-26816c6ba4cb';
$origin='https://staging.allroyalegypt.com';
$env='staging';
$target=['company_id'=>$company,'environment'=>$env,'site_uuid'=>$site,
    'canonical_origin'=>$origin,'identity_evidence'=>['signed_v4_centrally_verified'=>true]];
$proof=['company_id'=>$company,'environment'=>$env,'site_uuid'=>$site,
    'canonical_origin'=>$origin,'centrally_verified'=>true];
$GLOBALS['candidate'][$company.':'.$env]=$target;
$GLOBALS['proof'][$company.':'.$env]=$proof;
function check_case($condition,$name) {
 if(!$condition) {fwrite(STDERR,'FAIL '.$name.PHP_EOL);exit(1);}
 echo 'PASS '.$name.PHP_EOL;
}
$code=MAD4B_Company_Portfolio_Manager_V1::REGISTRY;
check_case(count($GLOBALS['options'])===0,'no_initial_storage_writes');
$r=MAD4B_Company_Portfolio_Manager_V1::company_binding($company);
check_case(!is_wp_error($r),'exact_verified_staging_binding_accepted');
check_case(($r['company_site_uuid']??'')===$site && ($r['environment']??'')==='staging','exact_staging_identity_preserved');
$expected=hash('sha256',implode('|',['mad4b.company.staging-binding.v1',$company,$env,$site,$origin]));
check_case(($r['binding_sha256']??null)===$expected,'scope_hash_binds_company_environment_site_origin');

$GLOBALS['proof'][$company.':'.$env]['centrally_verified']=false;
check_case(is_wp_error(MAD4B_Company_Portfolio_Manager_V1::company_binding($company)),'expired_or_unverified_proof_denied');
$GLOBALS['proof'][$company.':'.$env]=$proof;
$GLOBALS['proof'][$company.':'.$env]['canonical_origin']='https://evil.invalid';
check_case(is_wp_error(MAD4B_Company_Portfolio_Manager_V1::company_binding($company)),'mismatched_proof_origin_denied');
$GLOBALS['proof'][$company.':'.$env]=$proof;
$GLOBALS['proof'][$company.':'.$env]['site_uuid']='11111111-1111-1111-1111-111111111111';
check_case(is_wp_error(MAD4B_Company_Portfolio_Manager_V1::company_binding($company)),'mismatched_proof_site_denied');
$GLOBALS['proof'][$company.':'.$env]=$proof;
$GLOBALS['candidate'][$company.':'.$env]['identity_evidence']['signed_v4_centrally_verified']=false;
check_case(is_wp_error(MAD4B_Company_Portfolio_Manager_V1::company_binding($company)),'registry_must_confirm_signed_proof');
$GLOBALS['candidate'][$company.':'.$env]=$target;

$way='wayfatours';$wayuuid='b0725ffa-87e1-47e3-b0c0-c4aa4eb8882b';
$nativeuuid='78622475-bd53-48ce-af1a-4ff3e3c01470';
$digest=str_repeat('a',64);
$GLOBALS['options'][$code][$way]=['company_code'=>$way,'host_binding_verified'=>true,'company_site_uuid'=>$wayuuid,
 'native_site_profile_uuid'=>$nativeuuid,'native_profile_digest'=>$digest];
$legacy=MAD4B_Company_Portfolio_Manager_V1::company_binding($way);
check_case(!is_wp_error($legacy),'original_production_legacy_binding_preserved');
check_case(($legacy['binding_sha256']??null)===hash('sha256',implode('|',[$way,$wayuuid,$nativeuuid,$digest])),'original_production_hash_unchanged');
check_case(!isset($legacy['source']),'no_implicit_production_rebinding');
$drift=MAD4B_Company_Portfolio_Manager_V1::transition_row([
 'state'=>'proposed','revision'=>1,'binding_sha256'=>str_repeat('0',64),
 'proposal_sha256'=>str_repeat('1',64)],'approve',1,$expected,1,str_repeat('1',64));
check_case(is_wp_error($drift),'existing_assignment_binding_drift_denied');
check_case(!isset($GLOBALS['options'][MAD4B_Company_Portfolio_Manager_V1::ASSIGNMENTS]),'no_delegation_created');
check_case(!isset($GLOBALS['options']['mad4b_central_ssh_command_intake_v1']),'no_original_grant_created');
echo 'PASS 14/14 isolated portfolio binding assertions'.PHP_EOL;
