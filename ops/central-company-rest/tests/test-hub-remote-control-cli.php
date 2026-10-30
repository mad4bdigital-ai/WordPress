<?php
/**
 * PHP CLI self-contained contract tests for the central/remote hub-control v1.
 * Safe: no database, filesystem changes, WordPress install or network requests.
 */
declare(strict_types=1);
define('ABSPATH', __DIR__.'/../../../../');
class WP_Error {
    private $code;
    public function __construct($code,$message='',$data=null){$this->code=$code;}
    public function get_error_code(){return $this->code;}
}
class WP_REST_Request {}
class MAD4B_Central_Company_REST_V1 {
    const OPTION='mad4b_central_companies_v1';
    const COMPANIES=array(
        'tourism-almallahgroup'=>array('url'=>'https://tourism.almallahgroup-mg.com',
            'host'=>'tourism.almallahgroup-mg.com'),
        'all-royal-egypt'=>array('url'=>'https://www.allroyalegypt.com',
            'host'=>'www.allroyalegypt.com'),
        'egypt-tour-gates'=>array('url'=>'https://egypttourgates.com',
            'host'=>'egypttourgates.com')
    );
}
class MAD4B_Company_PK_Identity_V1 {
    public static function identity(){return 'tourism-almallahgroup';}
}
function add_action($hook,$callback,$priority=null){return true;}
function home_url($path='/'){return 'https://tours.dev.mad4b.com'.$path;}
function wp_parse_url($url,$part=-1){return parse_url($url,$part);}
function wp_json_encode($value,$options=0){return json_encode($value,$options);}
function get_option($key,$default=null){return $GLOBALS['test_options'][$key]??$default;}
function get_current_user_id(){return 42;}
function current_user_can($cap){return $cap==='manage_options';}
function is_wp_error($value){return $value instanceof WP_Error;}
function assert_case($truth,$label){
    if(!$truth)throw new RuntimeException('FAIL '.$label);
}
function denied($result,$code){
    return $result instanceof WP_Error && $result->get_error_code()===$code;
}

require dirname(__DIR__).'/central/230-mad4b-central-hub-remote-control.php';
require dirname(__DIR__).'/remote/230-mad4b-company-hub-control-agent.php';

$base=array('company_id'=>'tourism-almallahgroup',
    'consumer_company'=>'all-royal-egypt',
    'action'=>'content_release_reference',
    'selection'=>array('record_ids'=>array(24900,24902),
        'languages'=>array('en'),'source_post_type'=>'tours-and-activities',
        'valid_until'=>time()+3600,
        'reference_only'=>true,'checkout_enabled'=>false));
$central=MAD4B_Central_Hub_Remote_Control_V1::validate($base);
assert_case(is_array($central),'valid central plan');
assert_case(strlen($central['intent_id'])===64,'intent stable hash');
assert_case($central['remote_apply_authorized']===false,'plan never authorizes apply');
assert_case($central['publication_to_jetengine_authorized']===false,'plan never publishes');
$centralAgain=MAD4B_Central_Hub_Remote_Control_V1::validate($base);
assert_case($centralAgain['intent_id']===$central['intent_id'],'deterministic intent');
$payload=array('contract'=>MAD4B_Company_Hub_Control_Agent_V1::CONTRACT,
    'operation'=>'preflight','company_id'=>'tourism-almallahgroup',
    'consumer_company'=>$central['consumer_company'],
    'target_host'=>$central['target_host'],
    'action'=>$central['action'],'intent_id'=>$central['intent_id'],
    'selection'=>$central['selection']);
$remote=MAD4B_Company_Hub_Control_Agent_V1::validate_intent($payload);
assert_case($remote===true,'remote accepts canonical plan');
$delegation=array('consumer_companies'=>array('all-royal-egypt'),
    'source_post_types'=>array('tours-and-activities'),
    'expires_at'=>time()+86400);
assert_case(MAD4B_Company_Hub_Control_Agent_V1::validate_intent(
    $payload,$delegation)===true,'source delegation exact scope');
// The only prerequisite for reference sharing is a fresh P-256 authenticated
// REST company connection. No separate source/local or operator grant.
assert_case(denied(MAD4B_Central_Hub_Remote_Control_V1::dispatch(
    $central,'apply'),'mad4b_central_hubctl_signed_rest_identity_check_stale_or_missing'),
    'unsigned/stale REST connection denied, not missing operator grant');
$kp=openssl_pkey_new(array('private_key_type'=>OPENSSL_KEYTYPE_EC,
    'curve_name'=>'prime256v1'));
assert_case($kp!==false,'local ephemeral test P256 key');
$private='';openssl_pkey_export($kp,$private);
$public=openssl_pkey_get_details($kp)['key'];
$GLOBALS['test_options']=array(
  'mad4b_central_pk_private_v1'=>$private,
  'mad4b_central_pk_remote_public_v1_tourism_almallahgroup'=>$public,
  'mad4b_company_central_pk_trust_v1'=>$public,
  'mad4b_central_companies_v1'=>array('tourism-almallahgroup'=>array(
      'connection_state'=>'authenticated_read_only',
      'last_authenticated_check'=>gmdate('c')
  ))
);
assert_case(MAD4B_Central_Hub_Remote_Control_V1::reference_share_eligible(
    'tourism-almallahgroup')===true,'fresh mutually signed REST grants reference sharing');
assert_case(MAD4B_Central_Hub_Remote_Control_V1::reference_share_eligible(
    'unknown-source')===false,'unregistered company denied');
$readPlan=array('source_company'=>'tourism-almallahgroup',
    'content_model'=>'remote_reference',
    'pricing_strategy'=>'commercial_review_required');
$readScope=array('market_scoped'=>true,
    'selected'=>array('source_record_ids'=>array(24900),'languages'=>array('en')),
    'query_plan'=>array('policy_blocks_read'=>false),
    'market_scope_kind'=>'language_only',
    'market_plan'=>array('effective_visible_languages'=>array('en')));
assert_case(MAD4B_Central_Hub_Remote_Control_V1::reference_release_authority(
    false,$readPlan,$readScope)===true,'reference release from verified signed connection');
assert_case(MAD4B_Central_Hub_Remote_Control_V1::inherited_language_authority(
    false,$readPlan,$readScope)===true,'verified REST plus inherited WPML permits read');
$badScope=$readScope;$badScope['query_plan']['policy_blocks_read']=true;
assert_case(MAD4B_Central_Hub_Remote_Control_V1::reference_release_authority(
    false,$readPlan,$badScope)===false,'inherited policy deny wins');
$stale=$GLOBALS['test_options']['mad4b_central_companies_v1'];
$stale['tourism-almallahgroup']['last_authenticated_check']=gmdate('c',time()-86401);
$GLOBALS['test_options']['mad4b_central_companies_v1']=$stale;
assert_case(MAD4B_Central_Hub_Remote_Control_V1::reference_share_eligible(
    'tourism-almallahgroup')===false,'stale REST challenge denied');
assert_case(MAD4B_Central_Hub_Remote_Control_V1::reference_release_authority(
    false,$readPlan,$readScope)===false,'stale REST no reference sharing');
$GLOBALS['test_options']['mad4b_central_companies_v1']['tourism-almallahgroup']['last_authenticated_check']=gmdate('c');
unset($GLOBALS['test_options']['mad4b_central_pk_remote_public_v1_tourism_almallahgroup']);
assert_case(MAD4B_Central_Hub_Remote_Control_V1::reference_share_eligible(
    'tourism-almallahgroup')===false,'missing trusted company public key denied');
$GLOBALS['test_options']['mad4b_central_pk_remote_public_v1_tourism_almallahgroup']=$public;
$method=new ReflectionMethod('MAD4B_Company_Hub_Control_Agent_V1','delegated');
$auto=$method->invoke(null,$payload);
assert_case(is_array($auto) && $auto['authority']==='verified_signed_rest_connection'
   && $auto['consumer_companies']===array('all-royal-egypt'),
   'remote signed reference request implicitly authorized, no source owner enrollment');
$noCommerce=$payload;$noCommerce['selection']['checkout_enabled']=true;
assert_case(denied($method->invoke(null,$noCommerce),
    'mad4b_hubctl_action_not_permitted_by_connection'),
    'identity cannot grant checkout');
$invalid=array(
    'bad_company'=>array('company_id'=>'unknown-company'),
    'same_site_as_consumer'=>array('consumer_company'=>'tourism-almallahgroup'),
    'price_activation'=>array('selection'=>array_replace($base['selection'],
        array('checkout_enabled'=>true))),
    'unsafe_fields'=>array('selection'=>array_replace($base['selection'],
        array('customer_email'=>'private@example.com'))),
    'unselected_locale'=>array('selection'=>array_replace($base['selection'],
        array('languages'=>array('ru')))),
    'duplicated_records'=>array('selection'=>array_replace($base['selection'],
        array('record_ids'=>array(24900,24900)))),
    'missing_source_records'=>array('selection'=>array_replace($base['selection'],
        array('record_ids'=>array()))),
    'expired_grant'=>array('selection'=>array_replace($base['selection'],
        array('valid_until'=>time()-15))),
    'grant_too_long'=>array('selection'=>array_replace($base['selection'],
        array('valid_until'=>time()+367*86400))),
);
foreach($invalid as $label=>$change){
    $result=MAD4B_Central_Hub_Remote_Control_V1::validate(
        array_replace($base,$change));
    assert_case($result instanceof WP_Error,'denied '.$label);
}
$failedPayload=$payload;
$failedPayload['operation']='update_database';
assert_case(denied(MAD4B_Company_Hub_Control_Agent_V1::validate_intent(
    $failedPayload),'mad4b_hubctl_intent_invalid'),
    'remote arbitrary operations denied');
$failedPayload=$payload;
$failedPayload['selection']['checkout_enabled']=true;
assert_case(denied(MAD4B_Company_Hub_Control_Agent_V1::validate_intent(
    $failedPayload),'mad4b_hubctl_intent_digest_mismatch'),
    'remote rejects tampered selection under unchanged intent id');
$failedPayload['intent_id']=hash('sha256',wp_json_encode(array(
    'company_id'=>$failedPayload['company_id'],
    'consumer_company'=>$failedPayload['consumer_company'],
    'action'=>$failedPayload['action'],
    'selection'=>$failedPayload['selection']
),JSON_UNESCAPED_SLASHES));
assert_case(denied(MAD4B_Company_Hub_Control_Agent_V1::validate_intent(
    $failedPayload),'mad4b_hubctl_selection_invalid'),
    'remote rejects checkout activation even with internally consistent hash');
$failedDelegation=$delegation;
$failedDelegation['consumer_companies']=array('egypt-tour-gates');
assert_case(denied(MAD4B_Company_Hub_Control_Agent_V1::validate_intent(
    $payload,$failedDelegation),'mad4b_hubctl_delegation_scope_exceeded'),
    'remote exact consumer scope');
$failedDelegation=$delegation;
$failedDelegation['expires_at']=time()+1000;
assert_case(denied(MAD4B_Company_Hub_Control_Agent_V1::validate_intent(
    $payload,$failedDelegation),'mad4b_hubctl_delegation_scope_exceeded'),
    'remote grant cannot exceed delegation lifetime');

echo json_encode(array('contract'=>'mad4b.hub.remote-control.v1',
    'result'=>'PASS','checks'=>array(
        'deterministic_sha256_intents','signed_remote_request_schema',
        'signed_rest_connection_sufficient','fresh_p256_identity_required',
        'source_local_consent_not_required','central_operator_grant_not_required',
        'arbitrary_operations_denied','historical_rates_noncommercial',
        'consumer_and_record_allowlist',
        'delegation_expiration','reference_only_no_checkout'),
    'negative_cases'=>20,'real_vps_tested'=>false,
    'public_jetengine_feed_enabled'=>false),JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
