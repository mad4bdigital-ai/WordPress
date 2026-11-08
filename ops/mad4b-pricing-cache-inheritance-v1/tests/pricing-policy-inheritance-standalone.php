<?php
declare(strict_types=1);
// Portable contract tests: mocks for immutable policy source/context only.
define('ABSPATH','/');
class WP_Error {
 private string $code;
 public function __construct(string $code){$this->code=$code;}
 public function get_error_code():string{return $this->code;}
}
class WP_Term {
 public int $term_id;public string $taxonomy;
 public function __construct(int $id,string $tax){$this->term_id=$id;$this->taxonomy=$tax;}
}
function is_wp_error($x):bool{return $x instanceof WP_Error;}
function add_filter(...$args):void{}
function add_action(...$args):void{}
function wp_using_ext_object_cache():bool{return true;}
function is_admin():bool{return false;}
function wp_doing_ajax():bool{return false;}
function has_filter(...$args){return false;}
function wp_get_environment_type():string{return 'staging';}
function get_current_blog_id():int{return 1;}
function determine_locale():string{return 'en_US';}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
function wp_cache_get($key,$group){return $GLOBALS['mock_cache'][$group][$key]??false;}
function wp_cache_set($key,$value,$group,$ttl){$GLOBALS['mock_cache'][$group][$key]=$value;$GLOBALS['cache_ttl'][$group][$key]=$ttl;return true;}
function wp_is_mobile():bool{return false;}
function absint($x):int{return abs((int)$x);}
function sanitize_key(string $s):string{return preg_replace('/[^a-z0-9_-]/','',strtolower($s));}
function apply_filters(string $name,$value,...$rest){return $value;}
function get_option(string $key,$default=null){
 $values=$GLOBALS['mock_options']??[];
 return array_key_exists($key,$values)?$values[$key]:$default;
}
function get_post_type(int $id):string{return match($id){7=>'tours-and-activities',8=>'tour-rates',default=>''};}
function get_post_meta(int $id,string $key,bool $single=true){
 if($id===8&&$key==='related_tour_id')return 7;
 return '';
}
function get_term(int $id,string $tax){
 return (($id===77 && $tax==='programs')||($id===88 && $tax==='season'))
  ? new WP_Term($id,$tax) : new WP_Error('term_missing');
}
function check(bool $ok,string $message):void{
 if(!$ok)throw new RuntimeException($message);
}
$GLOBALS['mock_options']=[
 'etg-dynamic-pricing-controls_shared_mode_policy_rows'=>[
  ['mode'=>'tour','enabled'=>'1','strategy'=>'rate_occupancy'],
  ['mode'=>'activity','enabled'=>'1','strategy'=>'rate_occupancy']],
 'mad4b_pricing_cache_inheritance_v1'=>[
  'company'=>['policy_ttl'=>1200],
  'modes'=>['tour'=>['policy_ttl'=>1000]],
  'terms'=>['programs'=>[77=>['policy_ttl'=>700]],'season'=>[88=>['policy_ttl'=>600]]],
  'tours'=>[7=>['policy_ttl'=>400]],'rates'=>[8=>['policy_ttl'=>200]],
 ]];
require dirname(__DIR__).'/overlays/mu-plugins/mad4b-pricing-cache-inheritance.php';
$cls='MAD4B_Pricing_Cache_Inheritance_V1';
$ctx=['tour_mode'=>'tour','program_term_id'=>77,'season_id'=>88,'tour_id'=>7,'rate_id'=>8,'language'=>'en'];
$expect=[
 ['ctx'=>$ctx,'ttl'=>200],
 ['ctx'=>array_diff_key($ctx,['rate_id'=>0]),'ttl'=>400],
 ['ctx'=>array_diff_key($ctx,['rate_id'=>0,'tour_id'=>0]),'ttl'=>600],
 ['ctx'=>array_diff_key($ctx,['rate_id'=>0,'tour_id'=>0,'season_id'=>0]),'ttl'=>700],
 ['ctx'=>['tour_mode'=>'tour','language'=>'en'],'ttl'=>1000],
 ['ctx'=>[],'ttl'=>1200],
];
foreach($expect as $case) {
 $r=$cls::decision('mode_policy_definition',$case['ctx']);
 check($r['ttl_seconds']===$case['ttl'],'inheritance precedence regression');
}
$src=$cls::decision('pricing_source_definition',['tour_mode'=>'tour']);
check($src['cacheable']===true&&$src['persistent_cache_activated']===true,'source blueprint never enabled');
$matrix=['rate_price_amount','customer_quote','checkout_total','payment_fee_amount',
 'live_availability','extras_selection','market_price_adjustment','currency_conversion_amount','tour_rate_eligibility'];
foreach($matrix as $item){
 $r=$cls::decision($item,$ctx);
 check(!$r['cacheable']&&$r['ttl_seconds']===0,'private data was made shared: '.$item);
}
$GLOBALS['mock_options']['mad4b_pricing_cache_inheritance_v1']['rates'][8]=['mode'=>'private_only'];
$forced=$cls::decision('mode_policy_definition',$ctx);
check(!$forced['cacheable'] && $forced['ttl_seconds']===0,'private_only override bypass failed');
check(is_array($cls::validated_admin_change(['scope'=>'company','policy_ttl'=>'1800',
 'static_ttl'=>'','conditional_ttl'=>'','mode'=>'auto'])),'valid admin change rejected');
foreach([
 ['scope'=>'company','policy_ttl'=>'2401'],
 ['scope'=>'company','policy_ttl'=>['500']],
 ['scope'=>'unknown','policy_ttl'=>'30'],
 ['scope'=>'rate','scope_id'=>'9999','policy_ttl'=>'30'],
 ['scope'=>'program','scope_id'=>'88','policy_ttl'=>'30'],
 ['scope'=>'mode','scope_id'=>'admin','policy_ttl'=>'30'],
] as $input){
 check(is_wp_error($cls::validated_admin_change($input)),'invalid admin change accepted');
}
$registry=$cls::registry_provider([]);
check(isset($registry['pricing-cache-inheritance']['fields']['policy_ttl']),'provider fields missing');
$_SERVER['REQUEST_METHOD']='GET';
$GLOBALS['mock_options']['mad4b_pricing_cache_inheritance_v1']['rates'][8]=['policy_ttl'=>200];
$GLOBALS['mock_cache']=[];$GLOBALS['cache_ttl']=[];
$ctx=['tour_id'=>7,'rate_id'=>8,'tour_mode'=>'tour'];
$calls=0;
$build=static function()use(&$calls){++$calls;return ['tour'=>['strategy'=>'rate_occupancy']];};
$one=$cls::memoize_mode_policies($build,$ctx);
$two=$cls::memoize_mode_policies($build,$ctx);
check($one===$two && $calls===1,'rate scope cache hit not observed');
$ttls=array_values($GLOBALS['cache_ttl']['mad4b-pricing-blueprints-v1']??[]);
check(in_array(200,$ttls,true),'Rate TTL not applied to Redis');
$global=$cls::memoize_mode_policies($build,['tour_mode'=>'tour']);
check($calls===2,'Global and Rate scopes collided');
$GLOBALS['mock_options']['mad4b_pricing_cache_inheritance_v1']['rates'][8]=['mode'=>'private_only'];
$previous=count($GLOBALS['mock_cache']['mad4b-pricing-blueprints-v1']??[]);
$private=$cls::memoize_mode_policies($build,$ctx);
check($calls===3,'Private override reused Redis');
check(count($GLOBALS['mock_cache']['mad4b-pricing-blueprints-v1']??[])===$previous,'Private override wrote Redis');
check($private===$one,'Native policy definition changed');
echo json_encode(['contract'=>'mad4b.pricing-cache-portable-tests.v1',
  'inheritance_cases'=>count($expect),'dynamic_bypasses'=>count($matrix),
  'scope_validation'=>7,'status'=>'PASS'])."\n";
