<?php
/** Isolated original signed Staging company binding contract. No writes. */
declare(strict_types=1);
define('ABSPATH','/isolated-test/');
define('DB_NAME','etg_commerce_staging');
$GLOBALS['options']=[];
$GLOBALS['proof_enabled']=true;
$GLOBALS['delegated']=true;
$GLOBALS['bound']=true;
$GLOBALS['native_environment']='staging';
$GLOBALS['signed_site']='49c562d1-8f2f-456f-b454-26816c6ba4cb';
$GLOBALS['origin']='https://staging.allroyalegypt.com';
$GLOBALS['company']='all-royal-egypt';
$GLOBALS['calls']=0;
function add_action(...$x) {}
function wp_get_environment_type(){return $GLOBALS['native_environment'];}
function wp_parse_url($x,$part){return parse_url($x,$part);}
function home_url($p='/'){return 'https://tours.dev.mad4b.com/';}
function wp_is_uuid($x){return is_string($x)&&preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D',$x)===1;}
function get_option($k,$d=[]){return $GLOBALS['options'][$k]??$d;}
function is_wp_error($x){return $x instanceof WP_Error;}
class WP_Error {
 private string $error;
 function __construct($name,$message='',$meta=[]){$this->error=$name;}
 function get_error_code(){return $this->error;}
}
class MAD4B_Company_Environment_Registry_V2{
 static function target($c,$e){return [
    'company_id'=>$c,'environment'=>$e,'site_uuid'=>$GLOBALS['signed_site'],
    'canonical_origin'=>$GLOBALS['origin']];}
}
class MAD4B_Company_Environment_Portfolio_Evidence_V1{
 static function readback($c,$e){
   $GLOBALS['calls']++;
   $site=$GLOBALS['signed_site'];$origin=$GLOBALS['origin'];
   return [
    'contract'=>'mad4b.company-environment-portfolio-evidence.v1',
    'company'=>$c,'environment'=>$e,'site_uuid'=>$site,'canonical_origin'=>$origin,
    'signed_identity_verified'=>$GLOBALS['proof_enabled'],
    'operations_delegation_approved'=>$GLOBALS['delegated'],
    'portfolio_assignment_bound'=>$GLOBALS['bound'],
    'portfolio_binding_sha256'=>hash('sha256',implode('|',
       [$c,$e,$site,$origin,str_repeat('f',64)])),
    'grant_issued'=>false,'production_mutation_allowed'=>false];
 }
}
require __DIR__.'/mad4b-company-portfolio-manager.php';
function check_case($yes,$label){
 if(!$yes){fwrite(STDERR,"FAIL $label\n");exit(2);}
 echo "PASS $label\n";
}
$p='MAD4B_Company_Portfolio_Manager_V1';
$company='all-royal-egypt';
$r=$p::company_binding($company);
check_case(!is_wp_error($r),'signed_staging_portfolio_identity_accepted');
check_case($r['company_site_uuid']===$GLOBALS['signed_site'],'exact_staging_site_uuid');
$expected=hash('sha256',implode('|',[$company,'staging',$GLOBALS['signed_site'],
 $GLOBALS['origin'],str_repeat('f',64)]));
check_case($r['binding_sha256']===$expected,'original_environment_portfolio_binding_reused');
check_case($r['environment']==='staging','environment_explicit');
check_case(!isset($GLOBALS['options'][$p::ASSIGNMENTS]),'no_owner_approval_implicitly_created');
$GLOBALS['proof_enabled']=false;
check_case(is_wp_error($p::company_binding($company)),'unsigned_original_proof_denied');
$GLOBALS['proof_enabled']=true;$GLOBALS['delegated']=false;
check_case(is_wp_error($p::company_binding($company)),'unapproved_environment_manager_denied');
$GLOBALS['delegated']=true;$GLOBALS['bound']=false;
check_case(is_wp_error($p::company_binding($company)),'unbound_environment_manager_denied');
$GLOBALS['bound']=true;$GLOBALS['native_environment']='production';
check_case(is_wp_error($p::company_binding($company)),'production_does_not_inherit_staging');
$GLOBALS['native_environment']='staging';
$GLOBALS['options'][$p::REGISTRY][$company]=[
 'company_code'=>$company,'host_binding_verified'=>false];
check_case(is_wp_error($p::company_binding($company)),'conflicting_explicit_legacy_registry_denied');
unset($GLOBALS['options'][$p::REGISTRY][$company]);
$way='wayfatours';$wayuuid='b0725ffa-87e1-47e3-b0c0-c4aa4eb8882b';
$native='78622475-bd53-48ce-af1a-4ff3e3c01470';$digest=str_repeat('a',64);
$GLOBALS['options'][$p::REGISTRY][$way]=[
 'company_code'=>$way,'host_binding_verified'=>true,
 'company_site_uuid'=>$wayuuid,'native_site_profile_uuid'=>$native,
 'native_profile_digest'=>$digest];
$old=$p::company_binding($way);
check_case(!is_wp_error($old),'valid_legacy_binding_preserved');
check_case($old['binding_sha256']===hash('sha256',implode('|',
 [$way,$wayuuid,$native,$digest])),'valid_legacy_hash_preserved');
$drift=$p::transition_row(['state'=>'proposed','revision'=>1,
 'binding_sha256'=>str_repeat('0',64),'proposal_sha256'=>str_repeat('1',64)],
 'approve',1,$expected,1,str_repeat('1',64));
check_case(is_wp_error($drift),'manager_assignment_drift_fails_closed');
check_case(!isset($GLOBALS['options'][$p::ASSIGNMENTS]),'no_grants_or_assignment_mutation');
echo "SIGNED_STAGING_PORTFOLIO: PASS\n";
