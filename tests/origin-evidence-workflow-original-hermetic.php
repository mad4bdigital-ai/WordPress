<?php
/** Native origin location evidence intake guards, before ContentJobs or Google writes. */
define('ABSPATH',__DIR__.'/');
class WP_Error{private $code;function __construct($code,$msg='',$data=[]){$this->code=$code;}function get_error_code(){return $this->code;}}
function is_wp_error($x){return $x instanceof WP_Error;}
function absint($v){return is_numeric($v)?abs((int)$v):0;}
$GLOBALS['meta_reads']=0;
$GLOBALS['term_reads']=0;
function get_post_status($id){$GLOBALS['post_reads']++;return 'publish';}
function get_post_meta($id,$key,$single=true){$GLOBALS['meta_reads']++;return '';}
function wp_get_object_terms($id,$taxonomy,$opts=[]){$GLOBALS['term_reads']++;return new WP_Error('terms_unavailable');}
function origin_test($ok,$why){if(!$ok){fwrite(STDERR,'FAIL '.$why.PHP_EOL);exit(1);}$GLOBALS['checks']=($GLOBALS['checks']??0)+1;}
class MAD4B_Native_Origin_Content_Jobs_V1 {
 static $admitted=false;
 static function is_admitted(){return self::$admitted;}
}
class MAD4B_SCP_Research_Groups {}
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-origin-evidence-workflow.php';
$c='MAD4B_SCP_Origin_Evidence_Workflow';
$site='11111111-1111-4111-8111-111111111111';
$req=['site_uuid'=>$site,'deduplication_key'=>str_repeat('a',64),
 'source_post_id'=>12,'canonical_post_id'=>13,'source_sha256'=>str_repeat('b',64)];
$issue=['content_job_id'=>'22222222-2222-4222-8222-222222222222',
 'content_job_request'=>$req];
foreach([new WP_Error('actor_unknown'),1,'true',null,false] as $bad){
 MAD4B_Native_Origin_Content_Jobs_V1::$admitted=$bad;
 $GLOBALS['post_reads']=0;
 $r=$c::process_one($issue,['site_uuid'=>$site]);
 origin_test(is_wp_error($r)&&$r->get_error_code()==='mad4b_origin_evidence_source_not_eligible'
  &&$GLOBALS['post_reads']===0,
  'Unverified native origin author cannot read WordPress source for a new evidence Job');
}
MAD4B_Native_Origin_Content_Jobs_V1::$admitted=true;
foreach([new WP_Error('site_unknown'),1,'true',null,false] as $bad){
 $GLOBALS['post_reads']=0;
 $r=$c::process_one($issue,['site_uuid'=>$bad]);
 origin_test(is_wp_error($r)&&$GLOBALS['post_reads']===0,
  'Unverified Site identity cannot read tour source or enqueue research');
}
foreach(['deduplication_key','source_sha256'] as $field){
 foreach([new WP_Error('digest_unknown'),1,'true',null,false] as $bad){
  $changed=$issue;$changed['content_job_request'][$field]=$bad;
  $GLOBALS['post_reads']=0;
  $r=$c::process_one($changed,['site_uuid'=>$site]);
  origin_test(is_wp_error($r)&&$GLOBALS['post_reads']===0,
   'Original native intake fingerprint must be a precise source string before reading');
 }
}
$GLOBALS['term_reads']=0;
$r=$c::process_one($issue,['site_uuid'=>$site]);
origin_test(is_wp_error($r)&&$r->get_error_code()==='terms_unavailable'
 &&$GLOBALS['term_reads']===1,
 'Verified intake fails closed on independent taxonomy provider WP_Error');
echo 'PASS: ORIGIN_EVIDENCE_WORKFLOW_ORIGINAL '.($GLOBALS['checks']??0).
 ' original intake author, site and source identity denials'.PHP_EOL;
