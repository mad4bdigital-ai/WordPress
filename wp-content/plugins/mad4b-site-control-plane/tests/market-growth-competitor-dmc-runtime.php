<?php
/** Native isolated adversarial fixture; no real WordPress state or HTTP calls. */
define('ABSPATH',__DIR__);
$GLOBALS['opts']=array();
function get_option($key,$fallback=false){ return array_key_exists($key,$GLOBALS['opts'])?$GLOBALS['opts'][$key]:$fallback; }
function add_option($key,$v,$unused='',$autoload=false){ if(array_key_exists($key,$GLOBALS['opts']))return false; $GLOBALS['opts'][$key]=$v;return true; }
function update_option($key,$v,$autoload=false){ if(array_key_exists($key,$GLOBALS['opts']) && $GLOBALS['opts'][$key]===$v)return false;$GLOBALS['opts'][$key]=$v;return true; }
function delete_option($key){if(!array_key_exists($key,$GLOBALS['opts']))return false;unset($GLOBALS['opts'][$key]);return true;}
function current_user_can($cap,$id=0){return empty($GLOBALS['deny_cap']);}
function get_current_user_id(){return 2;}
function home_url($path='/'){return 'https://staging.example.test'.$path;}
function wp_get_environment_type(){return 'staging';}
function wp_json_encode($v,$opts=0){return json_encode($v,$opts);}
function is_wp_error($v){return $v instanceof WP_Error;}
function sanitize_key($v){return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$v));}
function get_post_type_object($v){return $v==='tours-and-activities'?(object)array('cap'=>(object)array('create_posts'=>'edit_posts','edit_posts'=>'edit_posts')):null;}
function get_object_taxonomies($v){return array('location','tour_type');}
function get_posts($args){return array((object)array('ID'=>7,'post_title'=>'Original tour','post_content'=>'Original description','post_excerpt'=>'Summary'));}
class WP_Error {
 private $code; function __construct($code,$m=''){ $this->code=$code; }
 function get_error_code(){return $this->code;}
}
require __DIR__.'/../includes/class-mad4b-scp-market-growth-policies.php';
require __DIR__.'/../includes/class-mad4b-scp-market-content-exchange.php';
require __DIR__.'/../includes/class-mad4b-scp-recovery-attempt-budget.php';
function ok($v,$msg){if(!$v)throw new RuntimeException($msg);}
$initial=MAD4B_SCP_Market_Growth_Policies::status();
ok(!is_wp_error($initial)&&$initial['revision']===0,'Initial market registry invalid');
$conf=array(
 'competitors'=>array('competitor_one'=>array('display_name'=>'Tour market peer','source_url'=>'https://competitor.example/tours')),
 'suppliers'=>array('dmc_one'=>array('display_name'=>'Licensed DMC','source_url'=>'https://dmc.example/catalog','commercial_status'=>'contract_reviewed','agreement_ref'=>'internal-contract-42','valid_until'=>'2099-12-31')),
 'dmc_connections'=>array('conn_one'=>array('supplier_id'=>'dmc_one','direction'=>'bidirectional')),
 'feed_mappings'=>array('mapping_one'=>array('post_type'=>'tours-and-activities','direction'=>'bidirectional','fields'=>array('post_title','post_excerpt'))),
 'pricing_rules'=>array('competitive'=>array('mode'=>'discount_percent','basis_points'=>1000,'currency'=>'EGP','cost_minor'=>7000)),
 'media_rules'=>array('image_one'=>array('source_url'=>'https://competitor.example/image.jpg','rights_status'=>'unknown')),
 'assistant_roles'=>array('writer_one'=>array('role'=>'writer','skill_name'=>'brand-content-authoring')),
);
$saved=MAD4B_SCP_Market_Growth_Policies::replace(array('settings'=>$conf,'confirmed'=>true,'expected_revision'=>0,'expected_sha256'=>$initial['registry_sha256']));
ok(!is_wp_error($saved)&&$saved['revision']===1,'Could not save policy with exact CAS');
$stale=MAD4B_SCP_Market_Growth_Policies::replace(array('settings'=>$conf,'confirmed'=>true,'expected_revision'=>0,'expected_sha256'=>$initial['registry_sha256']));
ok(is_wp_error($stale)&&$stale->get_error_code()==='mad4b_growth_policy_stale','Stale config update passed');
$competitor=MAD4B_SCP_Market_Content_Exchange::competitor_plan(array('competitor_id'=>'competitor_one','facts'=>array(
 array('field'=>'duration','value'=>'4 nights','source_url'=>'https://competitor.example/tours')
),'media_candidates'=>array(array('url'=>'https://competitor.example/image.jpg')),'pricing'=>array('pricing_rule_id'=>'competitive','market_price_minor'=>10000,'market_currency'=>'EGP')));
ok(!is_wp_error($competitor)&&$competitor['draft_without_supplier_contract_allowed']&&
 !$competitor['resale_rights_granted']&&!$competitor['publication_authorized']&&!$competitor['media_copied']&&
 $competitor['pricing']['amount_minor']===9000,'Noncontracted competitor research must remain a draft');
$dmc=MAD4B_SCP_Market_Content_Exchange::dmc_plan(array('connection_id'=>'conn_one','mapping_id'=>'mapping_one','direction'=>'import'));
ok(!is_wp_error($dmc)&&$dmc['exchange_plan_ready']&&$dmc['post_type']==='tours-and-activities'&&!$dmc['source_authorization_independently_verified'],'Native DMC CPT plan invalid');
$import=MAD4B_SCP_Market_Content_Exchange::import_prepare(array('connection_id'=>'conn_one','mapping_id'=>'mapping_one',
 'items'=>array(array('external_id'=>'tour-1','post_title'=>'DMC tour','post_excerpt'=>'Available itinerary'))));
ok(!is_wp_error($import)&&$import['draft_candidates'][0]['post']['post_status']==='draft'&&
 !empty($import['draft_candidates'][0]['operation_key'])&&!$import['import_written'],'DMC must prepare drafts only');
$export=MAD4B_SCP_Market_Content_Exchange::export_preview(array('connection_id'=>'conn_one','mapping_id'=>'mapping_one'));
ok(!is_wp_error($export)&&$export['count']===1&&!isset($export['items'][0]['post_content'])&&!$export['remote_transfer_executed'],'DMC exported a forbidden field or performed an external transfer');
$redacted=MAD4B_SCP_Market_Growth_Policies::status();
ok(!isset($redacted['suppliers']['dmc_one']['agreement_ref'])&&
   !isset($redacted['pricing_rules']['competitive']['cost_minor']),'Read-only market status leaked contract/cost metadata');
$assistant=MAD4B_SCP_Market_Content_Exchange::assistant_route(array('role'=>'writer'));
ok(!is_wp_error($assistant)&&!empty($assistant['configured_candidates'])&&
   empty($assistant['selected_for_research_or_draft'])&&
   !$assistant['exact_write_grant_verified'],'Configured writer became authorized without certified live Skill');
$dup=MAD4B_SCP_Market_Content_Exchange::import_prepare(array(
 'connection_id'=>'conn_one','mapping_id'=>'mapping_one',
 'items'=>array(
   array('external_id'=>'duplicate','post_title'=>'First'),
   array('external_id'=>'duplicate','post_title'=>'Second')
 )));
ok(is_wp_error($dup)&&$dup->get_error_code()==='mad4b_dmc_import_duplicate','DMC duplicate ID accepted');
$unlicensed=MAD4B_SCP_Market_Growth_Policies::inspect(array('media_id'=>'image_one'));
ok(!$unlicensed['checks']['media_ingest_preflight_eligible']&&!$unlicensed['publication_ready'],'Competitor image gained implicit license');
$attempt=array('category'=>'tone_of_voice','expected_plan_sha256'=>str_repeat('a',64));
$a=MAD4B_SCP_Recovery_Attempt_Budget::reserve('brand_draft_create',$attempt);
ok(!is_wp_error($a)&&$a['attempt']===1,'Cannot reserve first retry');
$again=MAD4B_SCP_Recovery_Attempt_Budget::reserve('brand_draft_create',$attempt);
ok(is_wp_error($again)&&$again->get_error_code()==='mad4b_retry_prior_not_reconciled','Concurrent/unknown retry bypass');
ok(true===MAD4B_SCP_Recovery_Attempt_Budget::finish($a,'failed'),'Failed to close attempt');
$b=MAD4B_SCP_Recovery_Attempt_Budget::reserve('brand_draft_create',$attempt);
ok(!is_wp_error($b)&&$b['attempt']===2,'Second authorized attempt failed');
ok(true===MAD4B_SCP_Recovery_Attempt_Budget::finish($b,'failed'),'Attempt two close');
$c=MAD4B_SCP_Recovery_Attempt_Budget::reserve('brand_draft_create',$attempt);
ok(!is_wp_error($c)&&$c['attempt']===3,'Third attempt failed');
ok(true===MAD4B_SCP_Recovery_Attempt_Budget::finish($c,'uncertain'),'Uncertain outcome not persisted');
$four=MAD4B_SCP_Recovery_Attempt_Budget::reserve('brand_draft_create',$attempt);
ok(is_wp_error($four)&&$four->get_error_code()==='mad4b_retry_circuit_open','Fourth write passed persistent limit');
$stat=MAD4B_SCP_Recovery_Attempt_Budget::status('brand_draft_create',$attempt);
ok(!is_wp_error($stat)&&$stat['circuit_open']&&$stat['last_outcome']==='uncertain','Readback did not expose circuit state');
$reset=MAD4B_SCP_Recovery_Attempt_Budget::reset('brand_draft_create',$attempt,$stat['journal_sha256'],true);
ok(!is_wp_error($reset)&&$reset['reset'],'Operator reset with exact hash failed');
$new=MAD4B_SCP_Recovery_Attempt_Budget::reserve('brand_draft_create',$attempt);
ok(!is_wp_error($new)&&$new['attempt']===1,'Reset did not start clean epoch');
$bad=MAD4B_SCP_Market_Growth_Policies::replace(array('settings'=>array('competitors'=>array('evil'=>array(
 'display_name'=>'Bad','source_url'=>'file:///etc/passwd'))),'confirmed'=>true,'expected_revision'=>1,'expected_sha256'=>$saved['registry_sha256']));
ok(is_wp_error($bad)&&$bad->get_error_code()==='mad4b_growth_competitor_invalid','Unsafe competitor source accepted');
echo "PASS market competitor/DMC separation, CPT mapping, price and media guards, durable retry circuit\n";
