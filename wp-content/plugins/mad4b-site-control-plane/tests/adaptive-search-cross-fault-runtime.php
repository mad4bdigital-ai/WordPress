<?php
define( 'ABSPATH', __DIR__ . '/' );
class WP_Error { private $code; public function __construct( $code, $message = '', $data = array() ){ $this->code=$code; } public function get_error_code(){ return $this->code; } }
function is_wp_error($v){ return $v instanceof WP_Error; }
function sanitize_key($v){ return strtolower(preg_replace('/[^a-z0-9_\-]/','',str_replace('.','_',trim((string)$v)))); }
function sanitize_text_field($v){ return trim((string)$v); }
function wp_json_encode($v,$f=0){ return json_encode($v,$f); }
function wp_parse_url($url,$component=-1){ return parse_url($url,$component); }
function apply_filters($tag,$value){ return $value; }
function get_option($key,$default=false){ return $default; }
function update_option($key,$value,$autoload=null){ return true; }
final class MAD4B_SCP_Distributed_Lock { public static function catalog_name($s){return 'x';} public static function acquire($n){return true;} public static function release($n){} }

$root=dirname(__DIR__);
require $root.'/includes/class-mad4b-scp-search-measurement.php';
require $root.'/includes/class-mad4b-scp-search-eligibility.php';
require $root.'/includes/class-mad4b-scp-provider-account-budget-authority.php';
require $root.'/includes/class-mad4b-scp-adaptive-search-acceptance.php';
require $root.'/includes/class-mad4b-scp-adaptive-search-fault-guard.php';

$fail=static function($m){fwrite(STDERR,"FAIL adaptive-search-cross-fault-runtime: $m\n");exit(1);};
$check=static function($c,$m)use($fail){if(!$c)$fail($m);};

$base=array('query'=>'egypt tours','market'=>'US','language'=>'en','engine'=>'google','device'=>'desktop','requested_depth'=>20,'provider_location_id'=>'us','location_precision'=>'country','provider_id'=>'a');
$ctx=MAD4B_SCP_Search_Measurement::observation_context($base);

// Provider drift + partial capture must not become a ranking-loss fact.
$provider=$base;$provider['provider_id']='b';
$ctx_provider=MAD4B_SCP_Search_Measurement::observation_context($provider);
$partial=MAD4B_SCP_Search_Measurement::capture_completeness(array('requested_depth'=>20,'returned_depth'=>8,'validated'=>true,'target_found'=>false,'partial_reason'=>'timeout_after_partial'));
$check(!MAD4B_SCP_Search_Measurement::compare_contexts($ctx,$ctx_provider)['comparable'],'provider drift comparable during partial capture');
$check(!$partial['loss_inference_eligible'],'partial provider failure became ranking loss');

// Language/profile drift plus noindex must suspend owned tracking without inventing comparability.
$lang=$base;$lang['language']='es';$lang['hl']='es';
$ctx_lang=MAD4B_SCP_Search_Measurement::observation_context($lang);
$elig=MAD4B_SCP_Search_Eligibility::resolve(array('http_status'=>200,'robots_txt_allowed'=>true,'meta_robots'=>'noindex','canonical_state'=>'self','redirect_state'=>'none','language_live'=>false,'object_public'=>true));
$check(!MAD4B_SCP_Search_Measurement::compare_contexts($ctx,$ctx_lang)['comparable'],'language drift comparable');
$check(!$elig['owned_tracking_eligible'],'language/noindex drift left owned target eligible');

// Surface explosion is denied even when the URL otherwise looks valid.
$policy=array('allowed_surface_types'=>array('virtual_landing_surface'),'allowed_path_prefixes'=>array('/tours/'),'allowed_query_params'=>array('page'),'max_cardinality'=>50,'max_page_number'=>5,'require_indexable_virtual'=>true);
$explosion=MAD4B_SCP_Search_Eligibility::admit_surface(array('surface_type'=>'virtual_landing_surface','url'=>'https://example.com/tours/?page=2','estimated_cardinality'=>5000,'page_number'=>2,'indexable'=>true),$policy);
$check(is_wp_error($explosion)&&'mad4b_search_surface_cardinality_exceeded'===$explosion->get_error_code(),'surface explosion admitted');

// A hard-global budget claim without a shared coordinator is denied, not silently downgraded.
$hard=MAD4B_SCP_Provider_Account_Budget_Authority::reserve(array('provider_id'=>'serp','credential_ref'=>'ref','enforcement_mode'=>'hard_global','units'=>1,'hard_allowance'=>10,'protected_reserve'=>1,'billing_cycle_id'=>'2026-10','idempotency_key'=>'cross-fault'));
$check(is_wp_error($hard)&&'mad4b_provider_budget_shared_authority_required'===$hard->get_error_code(),'hard-global budget silently downgraded');

// Composed fault guard exercises material dependencies and derives operator state.
$healthy=array(
 'profile_generation_match'=>true,'provider_generation_match'=>true,'surface_fingerprint_match'=>true,
 'target_type'=>'owned_rank_tracking','language_live'=>true,'budget_cycle_match'=>true,
 'budget_reservation_valid'=>true,'lease_valid'=>true,'provider_effect_state'=>'none',
 'capture_complete'=>true,'target_found'=>true,'cache_context_comparable'=>true,'cache_fresh'=>true,
);
$guard=MAD4B_SCP_Adaptive_Search_Fault_Guard::evaluate($healthy);
$check($guard['execution_allowed']&&'ACTIVE'===$guard['experience_state'],'healthy composed state did not execute');

$profile_drift=$healthy;$profile_drift['profile_generation_match']=false;
$g=MAD4B_SCP_Adaptive_Search_Fault_Guard::evaluate($profile_drift);
$check(!$g['execution_allowed']&&in_array('profile_drift',$g['blockers'],true)&&'PROFILE_DRIFT'===$g['experience_state'],'profile drift not fenced');

$provider_drift=$healthy;$provider_drift['provider_generation_match']=false;
$check(!MAD4B_SCP_Adaptive_Search_Fault_Guard::evaluate($provider_drift)['execution_allowed'],'provider generation drift not fenced');

$language_drift=$healthy;$language_drift['language_live']=false;
$check(!MAD4B_SCP_Adaptive_Search_Fault_Guard::evaluate($language_drift)['execution_allowed'],'owned target language drift not fenced');

$surface_drift=$healthy;$surface_drift['surface_fingerprint_match']=false;
$check(!MAD4B_SCP_Adaptive_Search_Fault_Guard::evaluate($surface_drift)['execution_allowed'],'surface drift not fenced');

$budget_drift=$healthy;$budget_drift['budget_cycle_match']=false;
$g=MAD4B_SCP_Adaptive_Search_Fault_Guard::evaluate($budget_drift);
$check(!$g['execution_allowed']&&'DEGRADED_BUDGET'===$g['experience_state'],'budget cycle drift did not degrade safely');

$lease_loss=$healthy;$lease_loss['lease_valid']=false;
$check(!MAD4B_SCP_Adaptive_Search_Fault_Guard::evaluate($lease_loss)['execution_allowed'],'lease loss not fenced');

$uncertain=$healthy;$uncertain['provider_effect_state']='unknown';
$g=MAD4B_SCP_Adaptive_Search_Fault_Guard::evaluate($uncertain);
$check(!$g['execution_allowed']&&!$g['provider_retry_allowed']&&'RECONCILIATION_REQUIRED'===$g['experience_state'],'uncertain provider effect did not require reconciliation');

$partial_state=$healthy;$partial_state['capture_complete']=false;$partial_state['target_found']=false;$partial_state['cache_fresh']=false;
$g=MAD4B_SCP_Adaptive_Search_Fault_Guard::evaluate($partial_state);
$check(!$g['loss_signal_allowed']&&'EVIDENCE_STALE'===$g['experience_state'],'partial capture created loss or wrong experience state');

$cache_bad=$healthy;$cache_bad['cache_context_comparable']=false;
$g=MAD4B_SCP_Adaptive_Search_Fault_Guard::evaluate($cache_bad);
$check(!$g['cache_reusable']&&in_array('cache_context_incomparable',$g['warnings'],true),'incomparable cache was reused');

// Acceptance reducer proves composition fails when one cross-fault fixture is missing.
$ev=array();
foreach(MAD4B_SCP_Adaptive_Search_Acceptance::gates() as $gate=>$def){
 $fixtures=array(); foreach($def['fixtures'] as $name)$fixtures[$name]=true;
 $ev[$gate]=array('fixtures'=>$fixtures,'assertion_count'=>$def['assertion_count_min'],'exact_head_bound'=>true);
}
$check(MAD4B_SCP_Adaptive_Search_Acceptance::evaluate($ev)['pass'],'full cross-fault acceptance did not pass');
$ev['ADAPTIVE_SEARCH_CROSS_FAULT_ACCEPTANCE_PASS']['fixtures']['budget_race']=false;
$check(!MAD4B_SCP_Adaptive_Search_Acceptance::evaluate($ev)['pass'],'missing budget-race fixture did not fail composed acceptance');
$check(15===count(MAD4B_SCP_Adaptive_Search_Acceptance::phase_gates()),'not every Phase 38 gate has measurable acceptance criteria');

echo "mad4b.adaptive-search-cross-fault-runtime.v1: PASS\n";
