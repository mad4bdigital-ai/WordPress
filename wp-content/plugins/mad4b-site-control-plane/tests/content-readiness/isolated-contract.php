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
function has_filter($key){return false;}
function get_post($id){return (object)['ID'=>$id];}
function get_post_type($id){return 'tours-and-activities';}
function get_term($id,$tax){return isset($GLOBALS['term_exists']) && !$GLOBALS['term_exists'] ? null : new WP_Term();}
function get_term_meta($id,$key,$single=true){return $key==='destination_type'?'continent':'';}
function taxonomy_exists($key){return $key==='location_jet';}
function wp_get_object_terms($id,$tax,$args){return $id===111?[]:[10];}
function apply_filters($key,$v,...$args){
 if($key==='wpml_post_language_details') return ['language_code'=>$GLOBALS['languages'][$args[0]]??''];
 return $v;
}
function wp_json_encode($v){return json_encode($v);}
require dirname(__DIR__, 2).'/includes/class-mad4b-scp-content-readiness-alerts.php';
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
$GLOBALS['admin']=false;
$before=serialize(get_option($cls::STORE));
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
echo "ALL READINESS LEDGER CONTRACTS PASSED\n";
