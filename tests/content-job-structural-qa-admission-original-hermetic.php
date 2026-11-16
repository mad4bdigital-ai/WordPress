<?php
/** Original Structural document QA attestation stops before WPML/DB when owner not proven. */
define('ABSPATH',__DIR__.'/');
class WP_Error{function __construct($code,$msg='',$data=[]){}}
function is_wp_error($v){return $v instanceof WP_Error;}
function qa_assert($yes,$reason){if(!$yes){fwrite(STDERR,'FAIL '.$reason.PHP_EOL);exit(1);} $GLOBALS['n']=($GLOBALS['n']??0)+1;}
class MAD4B_SCP_Site_Profile {
 static $status=['authority_ready'=>true];
 static function status(){return self::$status;}
}
class MAD4B_SCP_Central_Write_Acceptance {
 static $permitted=true;
 static function permitted(){return self::$permitted;}
}
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-content-job-structural-qa.php';
$c='MAD4B_SCP_Content_Job_Structural_QA';
foreach([new WP_Error('site_unknown'),1,'true',null,false] as $bad){
 MAD4B_SCP_Site_Profile::$status=['authority_ready'=>$bad];
 MAD4B_SCP_Central_Write_Acceptance::$permitted=true;
 $r=$c::attest('job-unverified');
 qa_assert(($r['state']??null)==='HOLD'&&($r['reason']??null)==='enrolled_original_actor_required'
 &&($r['mutation_performed']??null)===false,
 'Unverified Site Profile cannot enter Structural attestation');
}
MAD4B_SCP_Site_Profile::$status=['authority_ready'=>true];
foreach([new WP_Error('owner_unknown'),1,'true',null,false] as $bad){
 MAD4B_SCP_Central_Write_Acceptance::$permitted=$bad;
 $r=$c::attest('job-unverified');
 qa_assert(($r['state']??null)==='HOLD'&&($r['reason']??null)==='enrolled_original_actor_required'
 &&($r['mutation_performed']??null)===false,
 'Unverified original write acceptance cannot inspect or attest WPML changes');
}
echo 'PASS: CONTENT_JOB_STRUCTURAL_QA_ADMISSION_ORIGINAL '.($GLOBALS['n']??0).
 ' exact original owner/source denial checks'.PHP_EOL;
