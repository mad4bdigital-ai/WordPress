<?php
/**
 * Original Native Origin external research orchestration. Hermetic PHP7.4/8.5.
 * Fake Firecrawl returns evidence only; no provider network, tour writes or
 * WordPress changes; no delegation to other company or ContentJob.
 */
define('ABSPATH',__DIR__.'/');
$GLOBALS['origin_site']='11111111-1111-4111-8111-111111111111';
$GLOBALS['origin_admitted']=false;
$GLOBALS['origin_call_count']=0;
$GLOBALS['origin_add_count']=0;
$GLOBALS['origin_records']=[];
$GLOBALS['origin_draft']=false;
$GLOBALS['origin_address']='';
class WP_Error {
 private $code;
 public function __construct($code){$this->code=$code;}
 public function get_error_code(){return $this->code;}
}
function is_wp_error($v){return $v instanceof WP_Error;}
function absint($v){return abs((int)$v);}
function wp_json_encode($v,$opts=0){return json_encode($v,$opts);}
function get_post_status($id){return ($id==77&&$GLOBALS['origin_draft'])?'draft':'publish';}
function get_post_meta($id,$key,$single=true) {
 if($key==='tour_origin_address')return $GLOBALS['origin_address'];
 if($key==='tour_origin_location_term')return '333';
 return '';
}
function wp_get_object_terms($id,$tax,$opts=[]) {
 if($id!==77||$tax!=='destinations'||$opts['fields']!=='ids')
  throw new RuntimeException('Source term lookup deviated from original contract');
 return [333,222];
}
class MAD4B_Native_Origin_Content_Jobs_V1 {
 public static function is_admitted(){return $GLOBALS['origin_admitted'];}
 public static function actor(){return ['id'=>'native-source-owner'];}
}
class MAD4B_SCP_Content_Jobs {
 public static function get_job($input){
  if($input['job_id']!=='job-fixture')throw new RuntimeException('Unknown original job');
  return ['job'=>$GLOBALS['origin_job']];
 }
}
class MAD4B_SCP_Firecrawl_Provider {
 public static function scrape_native_origin_tour($post_id){
  ++$GLOBALS['origin_call_count'];
  if($post_id!==77)throw new RuntimeException('Foreign origin was scraped');
  return ['source_url'=>'https://tours.example.invalid/tours/luxor/',
   'source_sha256'=>str_repeat('b',64),'markdown'=>'Bounded public tour evidence',
   'captured_at'=>gmdate('c'),'http_status'=>200,
   'writeback_authorized'=>false];
 }
}
class MAD4B_SCP_Research_Groups {
 public static function group($name,$site,$language){
  if($name!=='origin:tour:66'||$site!==$GLOBALS['origin_site']||$language!=='en')
   throw new RuntimeException('Research group no longer bound to exact source and site');
  return ['id'=>'group-fixture'];
 }
 public static function records($group){return $GLOBALS['origin_records'];}
 public static function add_record($group,$provider,$lang,$source,$data) {
  if($group!=='group-fixture'||$provider!=='firecrawl'||$lang!=='en'||
     $data['writeback_authorized']!==false||
     $data['source_independently_verified']!==false||
     $data['exact_pickup_verified']!==false||
     $data['maps_verified']!==false||
     $data['site_uuid']!==$GLOBALS['origin_site'])
   throw new RuntimeException('Unverified location evidence was promoted to a verified source');
  ++$GLOBALS['origin_add_count'];
  $GLOBALS['origin_records'][]=['provider_id'=>'firecrawl'];
  return ['record_uid'=>'original-record-1'];
 }
}
function origin_check($yes,$msg){if(!$yes)throw new RuntimeException('NATIVE ORIGIN RESEARCH: '.$msg);}
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-origin-external-research.php';
$c='MAD4B_SCP_Origin_External_Research';
$token=str_repeat('a',64);
$fresh=hash('sha256',wp_json_encode([
 'canonical_id'=>66,'origin_term'=>'333','destination_terms'=>[222,333]
],JSON_UNESCAPED_SLASHES));
$issue=['status'=>'open','code'=>'origin_missing','content_job_id'=>'job-fixture',
 'content_job_request'=>['status'=>'LINKED','site_uuid'=>$GLOBALS['origin_site'],
 'source_post_id'=>77,'canonical_post_id'=>66,
 'deduplication_key'=>$token,'source_sha256'=>$fresh]];
$scope=['site_uuid'=>$GLOBALS['origin_site']];
$GLOBALS['origin_job']=[
 'state'=>'WAITING_REVIEW','stage'=>'FACT_QA',
 'site_uuid'=>$GLOBALS['origin_site'],'created_by_nhi'=>'native-source-owner',
 'subject'=>'origin-gap-ref:'.$token.' source audit'
];
$deny=$c::process_one('issue-fixture',$issue,$scope);
origin_check(is_wp_error($deny)
 && $deny->get_error_code()==='mad4b_origin_external_unauthorized'
 && $GLOBALS['origin_call_count']===0,
 'An unadmitted Native Origin cannot invoke provider or store tour evidence');
$GLOBALS['origin_admitted']=true;
$bad=$issue;$bad['content_job_request']['site_uuid']='another-company';
$deny=$c::process_one('issue-fixture',$bad,$scope);
origin_check(is_wp_error($deny)
 && $deny->get_error_code()==='mad4b_origin_external_source_ineligible',
 'Cross-company source cannot enter bound ContentJob research');
$bad=$issue;$bad['content_job_request']['source_sha256']=str_repeat('0',64);
$deny=$c::process_one('issue-fixture',$bad,$scope);
origin_check(is_wp_error($deny)
 && $deny->get_error_code()==='mad4b_origin_external_stale_source',
 'Content/taxonomy drift requires a new source receipt');
$GLOBALS['origin_job']['stage']='DRAFT';
$deny=$c::process_one('issue-fixture',$issue,$scope);
origin_check(is_wp_error($deny)
 && $deny->get_error_code()==='mad4b_origin_external_job_ineligible',
 'Only canonical FACT_QA review, not ContentJob draft stage, may ingest research');
$GLOBALS['origin_job']['stage']='FACT_QA';
$done=$c::process_one('issue-fixture',$issue,$scope);
origin_check(is_array($done) && $done['state']==='CAPTURED'
 && $done['exact_pickup_verified']===false
 && $done['record_uid']==='original-record-1'
 && $GLOBALS['origin_call_count']===1 && $GLOBALS['origin_add_count']===1,
 'One exact owner research record is evidence only, not pickup approval');
$repeat=$c::process_one('issue-fixture',$issue,$scope);
origin_check(is_array($repeat)&&$repeat['state']==='ALREADY_CAPTURED'
 && $GLOBALS['origin_call_count']===1 && $GLOBALS['origin_add_count']===1,
 'A prior Firecrawl research record blocks silent re-capture or duplicate writes');
// Exact canonical DB owner must retain bounded reads and limited evidence
// payloads. These are source contracts, not fabricated Production DB metrics.
$nativeOwner=file_get_contents(dirname(__DIR__).
 '/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-research-groups.php');
origin_check(is_string($nativeOwner)
 && strpos($nativeOwner,'ORDER BY id ASC LIMIT 100')!==false
 && strpos($nativeOwner,'strlen(wp_json_encode($data))>65536')!==false
 && strpos($nativeOwner,'UNIQUE KEY group_evidence (group_id,source_sha256,locale,provider_id)')!==false,
 'Canonical Research Groups SQL owner must retain bounded evidence and dedup indexes');
echo "NATIVE_ORIGIN_EXTERNAL_RESEARCH: PASS site, source hash, job lifecycle, evidence, no duplicate\n";// Success-only audited completion marker after all denial assertions.
echo "NATIVE_ORIGIN_EXTERNAL_RESEARCH: PASS\n";
