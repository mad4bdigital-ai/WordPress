<?php
/** Original ContentJob owner certificate verifier; never runs/creates mutations. */
define('ABSPATH',__DIR__.'/');
class WP_Error{function __construct($code,$msg='',$data=[]){}}
interface MAD4B_Central_Enrichment_Write_Adapter {}
class MAD4B_SCP_Central_Write_Acceptance {
 const DEFAULT_TTL=600;
 static function sha($v){return is_string($v)&&preg_match('/^[a-f0-9]{64}$/D',$v)===1;}
}
function owner_assert($ok,$why){if(!$ok){fwrite(STDERR,'FAIL '.$why.PHP_EOL);exit(1);}$GLOBALS['cases']=($GLOBALS['cases']??0)+1;}
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-content-relationship-writeback-owner.php';
$c='MAD4B_SCP_Relationship_Owner_Write_Adapter';
$now=1700000000;
$trusted=['site_uuid'=>'11111111-1111-4111-8111-111111111111',
 'job_id'=>'22222222-2222-4222-8222-222222222222',
 'original_ability'=>'mad4b/contentjob-writeback',
 'native_source_sha256'=>str_repeat('a',64),
 'binding_sha256'=>str_repeat('b',64),'provider_id'=>'wpml-native',
 'source_post_id'=>12,'target_post_id'=>13];
$proof=$trusted+['contract'=>'mad4b.contentjob.typed-owner-certification.v1',
 'verified_at'=>$now,'independent_verification'=>true,
 'quality_pass'=>true,'rights_pass'=>true,
 'evidence_sha256'=>str_repeat('c',64)];
owner_assert($c::certificateValid($proof,$trusted,$now)===true,
 'Exact locally proven owner certificate is valid only for bounded evidence, not execution');
foreach(['site_uuid','job_id','original_ability','native_source_sha256','binding_sha256','provider_id'] as $field){
 foreach([new WP_Error('source_unknown'),1,'true',[],null] as $bad){
  $changed=$trusted;$changed[$field]=$bad;
  owner_assert($c::certificateValid($proof,$changed,$now)===false,
   'Error-shaped trusted source evidence cannot certify original Owner');
 }
}
foreach(['independent_verification','quality_pass','rights_pass'] as $field){
 $changed=$proof;$changed[$field]=new WP_Error('proof_unverified');
 owner_assert($c::certificateValid($changed,$trusted,$now)===false,
  'Error-shaped third-party verification is not an original owner certificate');
}
$changed=$proof;$changed['verified_at']=$now-601;
owner_assert($c::certificateValid($changed,$trusted,$now)===false,
 'Expired owner certificate cannot bind a new ContentJob write');
echo 'PASS: CONTENT_RELATIONSHIP_OWNER_CERT_ORIGINAL '.($GLOBALS['cases']??0).
 ' exact original source/rights/expiry negative certificate cases'.PHP_EOL;
