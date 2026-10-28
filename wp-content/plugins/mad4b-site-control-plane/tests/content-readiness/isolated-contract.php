<?php
define('ABSPATH','/isolated/');
define('ICL_SITEPRESS_VERSION','mock');
class WP_Term {public $name='Africa';}
class WP_Error {
    public function __construct(private string $code) {}
    public function get_error_code(){return $this->code;}
}
function is_wp_error($r){return $r instanceof WP_Error;}
function add_action($a,$b,$c=10){}
function current_user_can($cap){return !empty($GLOBALS['admin']);}
function get_current_user_id(){return 5;}
function get_current_blog_id(){return 6;}
function sanitize_text_field($v){return trim(strip_tags((string)$v));}
function sanitize_key($str){return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$str));}
function absint($v){return abs((int)$v);}
function get_option($key,$default=[]){return $GLOBALS['options'][$key]??$default;}
function update_option($key,$v,$autoload=false){$GLOBALS['options'][$key]=$v;return true;}
function get_post_types($args,$format){return ['tours-and-activities'=>'tours-and-activities'];}
function post_type_exists($type){return $type==='tours-and-activities';}
function get_posts($args){return [111,112];}
function get_post_meta($id,$key,$one=true){return $GLOBALS['post_meta'][$id][$key]??'';}
function has_post_thumbnail($id){return false;}
function has_filter($key){return $key==='mad4b/content_readiness/external_issues' && array_key_exists('external_source', $GLOBALS);}
function get_post($id){return (object)['ID'=>$id];}
function get_post_type($id){return 'tours-and-activities';}
function get_term($id,$tax){return isset($GLOBALS['term_exists']) && !$GLOBALS['term_exists'] ? null : new WP_Term();}
function get_term_meta($id,$key,$single=true){return $key==='destination_type'?'continent':'';}
function taxonomy_exists($key){return $key==='location_jet';}
function wp_get_object_terms($id,$tax,$args){return $id===111?[]:[10];}
function apply_filters($key,$v,...$args){
 if($key==='mad4b/content_readiness/external_issues') return $GLOBALS['external_source']??$v;
 if($key==='wpml_post_language_details') return ['language_code'=>$GLOBALS['languages'][$args[0]]??''];
 return $v;
}
function wp_json_encode($v){return json_encode($v);}
function rest_ensure_response($data){return $data;}
class Readiness_Test_Request {
    public function __construct(private array $values=[]){}
    public function get_param($key){return $this->values[$key]??null;}
}
$module=dirname(__DIR__).'/includes/class-mad4b-scp-content-readiness-alerts.php';
if (!is_file($module)) $module=dirname(__DIR__,2).'/includes/class-mad4b-scp-content-readiness-alerts.php';
require $module;
$cls='MAD4B_SCP_Content_Readiness_Alerts';
function yes($condition,$label){if(!$condition){fwrite(STDERR,"FAIL $label\n");exit(3);}echo "PASS $label\n";}
$GLOBALS['admin']=true;
$GLOBALS['languages']=[111=>'en',112=>''];
$GLOBALS['post_meta']=[111=>['tour_origin_address'=>''],112=>['tour_origin_address'=>'12']];
$GLOBALS['options']=[
 'mad4b_content_readiness_rules_v1'=>[
  'post_types'=>['tours-and-activities'],
  'origin_fields'=>['tours-and-activities'=>'tour_origin_address'],
  'location_taxonomies'=>['tours-and-activities'=>'location_jet'],
  'check_wpml'=>true,'check_cron'=>false
 ],
];
$a=$cls::discover();yes($a['complete']&&count($a['issues'])===3,'source-backed read-only discovery');
yes(!get_option($cls::STORE,[]),'discover produces no persistent issues');
$first=$cls::reconcile($a);yes($first['open']===3,'admin scan creates three issues');
$second=$cls::reconcile($cls::discover());yes($second['open']===3,'same source does not duplicate');
$store=get_option($cls::STORE,[]);
$seen=array_unique(array_column($store,'id'));
yes(count($store)===count($seen),'issue fingerprints are unique');
$GLOBALS['post_meta'][111]['tour_origin_address']='12';
$GLOBALS['languages'][112]='en';
$third=$cls::reconcile($cls::discover());
yes($third['open']===1&&$third['resolved']===2,'resolved sources close issues');
$GLOBALS['post_meta'][111]['tour_origin_address']='';
$fourth=$cls::reconcile($cls::discover());
$source_key=hash('sha256','6|tours-and-activities|111|origin_missing');
yes($fourth['open']===2 && get_option($cls::STORE)[$source_key]['reopens']===1,'regression reopens original issue exactly once');
yes(array_key_exists('resolved_at',get_option($cls::STORE)[$source_key]) &&
 get_option($cls::STORE)[$source_key]['resolved_at']===null,
 'reopened source issue clears its obsolete resolved timestamp');
$GLOBALS['admin']=false;
$before=serialize(get_option($cls::STORE));
$internalDenied=$cls::reconcile($cls::discover(),true);
yes(is_wp_error($internalDenied) && serialize(get_option($cls::STORE))===$before,
 'a non-scheduler caller cannot bypass admin write policy');
$scheduledDisabled=$cls::run_scheduled();
yes(is_wp_error($scheduledDisabled),'untrusted scheduler invocation fails closed');
$denied=$cls::reconcile($cls::discover());
yes(is_wp_error($denied)&&serialize(get_option($cls::STORE))===$before,'unprivileged caller cannot save');
$board=$cls::sync_board($source_key);
yes(is_wp_error($board),'unprivileged Fluent Boards write denied');
$GLOBALS['admin']=true;
$GLOBALS['post_meta'][111]['gallery']=[['id'=>77],['id'=>78]];
$GLOBALS['post_meta'][112]['gallery']=[['id'=>77],['id'=>78]];
$GLOBALS['post_meta'][111]['tour_origin_location_term']='66';
$cfg=$GLOBALS['options']['mad4b_content_readiness_rules_v1'];
$cfg['media_fields']=['tours-and-activities'=>'gallery'];
$cfg['media_reuse_threshold']=2;
$cfg['origin_source_fields']=['tours-and-activities'=>'tour_origin_location_term'];
$cfg['origin_source_taxonomies']=['tours-and-activities'=>'location_jet'];
$cfg['invalid_origin_levels']=['continent'];
$GLOBALS['options']['mad4b_content_readiness_rules_v1']=$cfg;
$additional=$cls::discover();
$extra=array_count_values(array_column($additional['issues'],'code'));
yes(($extra['media_source_shared_across_records']??0)===2,
 'reused source galleries flagged without changing media');
yes(($extra['origin_source_too_broad']??0)===1,
 'continent-level origin detected by configured taxonomy/meta source');
yes(($extra['origin_missing']??0)===0,
 'broad origin and empty address are one root-cause alert, not duplicate tasks');
$evidence=array_values(array_filter($additional['issues'],
 function($x){return ($x['code']??'')==='origin_source_too_broad';}));
yes(($evidence[0]['source_evidence']['term_id']??null)===66 &&
 ($evidence[0]['source_evidence']['level']??null)==='continent',
 'origin issue carries source term and hierarchy level for editor');
$GLOBALS['post_meta'][111]['tour_origin_location_term']='777';
$GLOBALS['term_exists']=false;
$invalid=$cls::discover();
$codes=array_count_values(array_column($invalid['issues'],'code'));
yes(($codes['origin_source_invalid_reference']??0)===1,
 'missing source term produces an explicit referential-integrity issue');
$GLOBALS['post_meta'][111]['tour_origin_location_term']='66';

$cfg['end_date_fields']=['tours-and-activities'=>'rate_end_date'];
$GLOBALS['options']['mad4b_content_readiness_rules_v1']=$cfg;
$missingDateScan=$cls::discover();
$missingDateStats=array_count_values(array_column($missingDateScan['issues'],'code'));
yes(($missingDateStats['rate_end_date_missing']??0)===2,
 'configured missing rate dates remain visible for commercial review');
$GLOBALS['admin']=false;
yes(!$cls::rest_permission(), 'REST rejects unprivileged visitors');
$GLOBALS['admin']=true;
yes($cls::rest_permission(), 'REST allows editorial review');
$snapshot=serialize(get_option($cls::STORE));
$summary=$cls::rest_summary(new Readiness_Test_Request());
yes(($summary['read_only']??false) && ($summary['counts']['open']??0)===2,
 'REST summary exposes current counts without write authority');
$page=$cls::rest_issues(new Readiness_Test_Request(['status'=>'all','per_page'=>1,'page'=>1]));
yes(($page['per_page']??0)===1 && count($page['items'])===1 &&
 ($page['total']??0)>=3, 'REST filters and paginates bounded issue results');
$filtered=$cls::rest_issues(new Readiness_Test_Request(['code'=>'origin_missing','status'=>'open']));
yes(($filtered['total']??0)===1 && ($filtered['items'][0]['code']??'')==='origin_missing',
 'REST filters source issues by code and state');
yes(serialize(get_option($cls::STORE))===$snapshot, 'REST reads do not mutate issues');
// External providers must supply complete, unforgeable, read-only issue
// evidence; malformed or unavailable sources never close saved issues.
$externalCode='dfsb_archive_meta_missing';
$externalId=hash('sha256','6|site|0|'.$externalCode);
$validExternal=[
  'id'=>$externalId,'post_type'=>'site','object_id'=>0,
  'code'=>$externalCode,'severity'=>'medium',
  'label'=>'Archive description source is missing',
  'source'=>'dynamic-filter-seo-bridge'
];
$GLOBALS['external_source']=[$validExternal];
$providerScan=$cls::discover();
$providerRows=array_values(array_filter($providerScan['issues'],
 fn($row)=>($row['source']??'')==='dynamic-filter-seo-bridge'));
yes($providerScan['complete'] && count($providerRows)===1,
 'registered DFSB source contributes a single valid gap');
$persist=$cls::reconcile($providerScan);
yes(!is_wp_error($persist) && (get_option($cls::STORE)[$externalId]['status']??'')==='open',
 'reconcile persists validated external source issue');
$ledgerBefore=serialize(get_option($cls::STORE));
$GLOBALS['external_source']=[array_merge($validExternal,['status'=>'resolved','board_task_id'=>123])];
$invalid=$cls::discover();
yes(!$invalid['complete'] && is_wp_error($cls::reconcile($invalid)) &&
 serialize(get_option($cls::STORE))===$ledgerBefore,
 'provider cannot spoof resolved status or external task receipt');
$GLOBALS['external_source']=new WP_Error('dfsb_source_scan_failed');
$failed=$cls::discover();
yes(!$failed['complete'] && is_wp_error($cls::reconcile($failed)) &&
 (get_option($cls::STORE)[$externalId]['status']??'')==='open',
 'failed external source cannot close a saved issue');
unset($GLOBALS['external_source']);
$detached=$cls::reconcile($cls::discover());
yes(!is_wp_error($detached) &&
 (get_option($cls::STORE)[$externalId]['status']??'')==='open',
 'disconnected external source preserves unresolved issue');
$GLOBALS['external_source']=[];
$resolved=$cls::reconcile($cls::discover());
yes(!is_wp_error($resolved) &&
 (get_option($cls::STORE)[$externalId]['status']??'')==='resolved',
 'complete source scan can resolve an absent external issue');
$GLOBALS['external_source']=[$validExternal];
$reopened=$cls::reconcile($cls::discover());
yes(!is_wp_error($reopened) &&
 (get_option($cls::STORE)[$externalId]['reopens']??0)===1,
 'new external regression reopens original issue once');
unset($GLOBALS['external_source']);
// The authorized CLI executor may reconcile only under an enabled tenant policy.
$GLOBALS['admin']=false;
$GLOBALS['options'][$cls::POLICY]['scheduled_scan_enabled']=true;
$GLOBALS['options'][$cls::POLICY]['scheduled_scan_interval_minutes']=60;
$GLOBALS['options'][$cls::AUDIT]=[];
define('MAD4B_READINESS_SCHEDULED_RUN',true);
$scheduleResult=$cls::run_scheduled();
yes(is_array($scheduleResult) && array_key_exists('open',$scheduleResult),
 'authorized CLI scheduler reconciles source issues with tenant opt-in');
$notDue=$cls::run_scheduled();
yes(($notDue['status']??'')==='not_due',
 'scheduled scans are throttled by the configured interval');
echo "ALL READINESS LEDGER CONTRACTS PASSED\n";
