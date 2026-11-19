<?php
/** Original ContentJob Group plan/apply admission, without touching WPML or CWA writes. */
define('ABSPATH',__DIR__.'/');
class WP_Error {private $code;function __construct($code,$msg='',$data=[]){$this->code=$code;}function get_error_code(){return $this->code;}}
function is_wp_error($x){return $x instanceof WP_Error;}
function absint($v){return is_numeric($v)?abs((int)$v):0;}
function get_post_type($id){$GLOBALS['post_type_lookups']++;return 'elementor_library';}
function get_option($key,$default='no'){$GLOBALS['option_reads']++;return 'no';}
function cg_check($yes,$why){if(!$yes){fwrite(STDERR,'FAIL '.$why.PHP_EOL);exit(1);} $GLOBALS['n']=($GLOBALS['n']??0)+1;}
class MAD4B_SCP_Central_Write_Acceptance {
 static $permitted=false,$scope=[];
 static function permitted(){return self::$permitted;}
 static function site_scope(){return self::$scope;}
}
class MAD4B_WPML_Design_Reconciliation_V1 {
 static $calls=0;
 static function reconcile($source,$apply){self::$calls++;return new WP_Error('not_authorized_to_preview');}
}
class MAD4B_WPML_ContentJob_Adapter {const MAP='mad4b_wpml_group_map';}
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-content-job-groups.php';
$c='MAD4B_SCP_Content_Job_Group_Orchestrator';
foreach([new WP_Error('write_unverified'),1,'true',null,false] as $bad){
 MAD4B_SCP_Central_Write_Acceptance::$permitted=$bad;
 $GLOBALS['option_reads']=0;
 $r=$c::apply(['source_post_id'=>12]);
 cg_check(($r['state']??null)==='HOLD'
  &&($r['reason']??null)==='group_write_requires_original_site_authority_and_opt_in'
  &&$GLOBALS['option_reads']===0,
  'Unverified original CWA permission must block before plan or opt-in inspection');
}
MAD4B_SCP_Central_Write_Acceptance::$permitted=true;
foreach([new WP_Error('site_scope_unknown'),null,'wrong_scope',[]] as $bad){
 MAD4B_SCP_Central_Write_Acceptance::$scope=$bad;
 $GLOBALS['post_type_lookups']=0;
 MAD4B_WPML_Design_Reconciliation_V1::$calls=0;
 $r=$c::plan(['source_post_id'=>12]);
 cg_check(($r['state']??null)==='HOLD'
  &&($r['reason']??null)==='group_site_or_source_invalid'
  &&MAD4B_WPML_Design_Reconciliation_V1::$calls===0,
  'Unverified source Site Profile cannot inspect WPML group family');
}
MAD4B_SCP_Central_Write_Acceptance::$scope=['site_uuid'=>'11111111-1111-4111-8111-111111111111'];
$r=$c::plan(['source_post_id'=>12]);
cg_check(($r['state']??'')==='HOLD'
 &&($r['reason']??'')==='original_elementor_group_preview_denied',
 'Valid source reaches only governed first-party WPML preview, never a write');
echo 'PASS: CONTENT_JOB_GROUPS_ADMISSION_ORIGINAL '.($GLOBALS['n']??0).' original plan/write authority denials'.PHP_EOL;
