<?php
/** Original structural CWA source scope; no WordPress reconciliation/mutation. */
define('ABSPATH',__DIR__.'/');
class WP_Error{function __construct($code,$msg='',$data=[]){}}
interface MAD4B_Central_Enrichment_Write_Adapter{}
class MAD4B_SCP_Site_Profile {
 static $enrolled=false;
 static function origin_enrolled(){return self::$enrolled;}
 static function current_environment(){return 'staging';}
 static function current_origin(){return 'https://tours.dev.mad4b.com';}
 static function site_origin(){return 'https://tours.dev.mad4b.com';}
}
class MAD4B_Company_Environment_Registry_V2{}
class MAD4B_SCP_Content_Job_Structural_QA {
 static $reads=0;
 static function receipt($id){self::$reads++;return ['state'=>'HOLD'];}
}
function get_option($key,$default=''){return $key==='mad4b_source_profile_company_code_v1'?'tours-central':$default;}
function structural_check($ok,$why){if(!$ok){fwrite(STDERR,'FAIL '.$why.PHP_EOL);exit(1);}$GLOBALS['n']=($GLOBALS['n']??0)+1;}
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-contentjob-structural-cwa.php';
$a=new MAD4B_SCP_Structural_Only_CWA_Adapter('22222222-2222-4222-8222-222222222222');
foreach([new WP_Error('enrollment_unknown'),1,'true',null,false] as $bad){
 MAD4B_SCP_Site_Profile::$enrolled=$bad;
 MAD4B_SCP_Content_Job_Structural_QA::$reads=0;
 $r=$a->run();
 structural_check(($r['state']??null)==='HOLD'
  &&($r['reason']??null)==='structural_cwa_source_enrollment_required'
  &&($r['mutation_performed']??null)===false
  &&MAD4B_SCP_Content_Job_Structural_QA::$reads===0,
  'Unverified Site Profile enrollment cannot read CWA structural owner receipts');
}
MAD4B_SCP_Site_Profile::$enrolled=true;
MAD4B_SCP_Content_Job_Structural_QA::$reads=0;
$r=$a->run();
structural_check(($r['state']??null)==='HOLD'
 &&($r['reason']??null)==='structural_original_qa_stale_or_invalid'
 &&MAD4B_SCP_Content_Job_Structural_QA::$reads===1,
 'Even enrolled original Staging site requires independent structural QA receipt');
echo 'PASS: CONTENTJOB_STRUCTURAL_CWA_ORIGINAL '.($GLOBALS['n']??0).
 ' native site enrollment and no-mutation structural source cases'.PHP_EOL;
