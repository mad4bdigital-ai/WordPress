<?php
declare(strict_types=1);
namespace {
define('ABSPATH','/tmp/');
$GLOBALS['options']=[];
$GLOBALS['checks']=0;
$GLOBALS['caps']=['manage_options'=>true,'manage_woocommerce'=>true];
$GLOBALS['lifecycle']=['commerce_manage_operations'=>true,'commerce_manage_pricing'=>true];
$GLOBALS['profile']=[
 'contract'=>'mad4b.site-profile.v2',
 'site_uuid'=>'11111111-2222-3333-4444-555555555555',
 'revision'=>3,'environment'=>'production'
];
$GLOBALS['status']=[
 'profile_digest'=>str_repeat('a',64),
 'authority_ready'=>true,'write_enabled'=>true,
 'origin_match'=>true,'environment_match'=>true,
 'deployment_binding_match'=>true,'profile_authority_quarantined'=>false
];
class WP_Error {
 private string $code;
 public function __construct(string $c,string $message='',array $data=[]) {$this->code=$c;}
 public function get_error_code():string {return $this->code;}
}
function is_wp_error($v):bool {return $v instanceof WP_Error;}
function get_current_user_id():int{return 42;}
function current_user_can(string $cap):bool{return $GLOBALS['caps'][$cap]??false;}
class MAD4B_Lifecycle_Access {
 public static function can(string $key):bool {return $GLOBALS['lifecycle'][$key]??false;}
}
class MAD4B_SCP_Site_Profile {
 public static function profile(){return $GLOBALS['profile'];}
 public static function status(){return $GLOBALS['status'];}
}
function post_type_exists(string $s):bool{return in_array($s,['tours-and-activities','properties','tour-rates','units'],true);}
function get_post_type_object(string $s){return post_type_exists($s)?(object)['show_ui'=>true]:null;}
function add_action(string $event,$cb,int $priority=10):void {}
function get_option(string $key,$fallback=null){return $GLOBALS['options'][$key]??$fallback;}
function add_option(string $key,$v,string $deprecated='',bool $autoload=false):bool{
 if(array_key_exists($key,$GLOBALS['options']))return false;
 $GLOBALS['options'][$key]=$v;return true;
}
function wp_json_encode($v,$opts=0){return json_encode($v,$opts);}
function assert_true($value,$reason):void{
 if(!$value){fwrite(STDERR,"FAIL: $reason\n");exit(1);}
 $GLOBALS['checks']++;
}
function assert_error($value,string $code):void{
 assert_true(is_wp_error($value)&&$value->get_error_code()===$code,$code.' got '.(is_wp_error($value)?$value->get_error_code():gettype($value)));
}
require dirname(__DIR__).'/wp-content/mu-plugins/mad4b-commerce-company-first-enrollment-v1.php';
require dirname(__DIR__).'/wp-content/plugins/commerce-composition/includes/CompanyCommercePolicy.php';
$cls='MAD4B_Commerce_Company_First_Enrollment_V1';
$site=$cls::site();
assert_true(!is_wp_error($site)&&$site['site_profile_revision']===3,'signed site state');
$request=[
 'site_uuid'=>$site['site_uuid'],
 'site_profile_revision'=>3,'site_profile_digest'=>$site['site_profile_digest'],
 'environment'=>'production','expected_revision'=>0,
 'intent'=>'owner-entry-approved-version-0001',
 'domains'=>['services'=>true,'rates'=>true,'forms'=>true,'quotes'=>true],
 'source_types'=>['rates'=>['tour-rates'],'services'=>['tours-and-activities','properties']],
 'rate_context_term_fields'=>['tour-rates'=>'related_program_term_id'],
 'form_roles'=>[],'role_personas'=>[]
];
$doc=$cls::definition_document($request,$site);
assert_true(!is_wp_error($doc),'exact-scoped definition accepted');
assert_true($doc['revision']===1&&$doc['environment']==='production','revision one, prod');
assert_true($doc['enrollment_state']==='draft_source_visibility_only','not commercial');
$bad=$request;$bad['site_uuid']='99999999-2222-3333-4444-555555555555';
assert_error($cls::definition_document($bad,$site),'first_enrollment_identity_or_revision_invalid');
$bad=$request;$bad['site_profile_digest']=str_repeat('b',64);
assert_error($cls::definition_document($bad,$site),'first_enrollment_identity_or_revision_invalid');
$bad=$request;$bad['expected_revision']=1;
assert_error($cls::definition_document($bad,$site),'first_enrollment_identity_or_revision_invalid');
$bad=$request;$bad['environment']='staging';
assert_error($cls::definition_document($bad,$site),'first_enrollment_identity_or_revision_invalid');
$bad=$request;$bad['domains']['payments']=true;
assert_error($cls::definition_document($bad,$site),'first_enrollment_financial_domain_forbidden');
$bad=$request;$bad['domains']['fake']=true;
assert_error($cls::definition_document($bad,$site),'first_enrollment_domain_unknown');
$bad=$request;$bad['source_types']['rates']=['fake-rates'];
assert_error($cls::definition_document($bad,$site),'first_enrollment_source_type_invalid');
$bad=$request;$bad['rate_context_term_fields']['not-registered']='program';
assert_error($cls::definition_document($bad,$site),'first_enrollment_rate_mapping_invalid');
$bad=$request;$bad['form_roles']=['booking'=>6];
assert_error($cls::definition_document($bad,$site),'first_enrollment_unreviewed_field_mapping');
$GLOBALS['caps']['manage_woocommerce']=false;
assert_error($cls::initialize_definition($request),'first_enrollment_forbidden');
$GLOBALS['caps']['manage_woocommerce']=true;
$r=$cls::initialize_definition($request);
assert_true(!is_wp_error($r)&&$r['state']==='definition_draft_created','definition atomic first write');
assert_true(count($GLOBALS['options'])===1,'one source only');
assert_error($cls::initialize_definition($request),'first_enrollment_definition_already_exists');
$defaults=[
 'pricing_source'=>'manual_quote','supplier_cost_source'=>'manual_review',
 'margin_mode'=>'manual_review','tax_mode'=>'woocommerce',
 'availability_mode'=>'manual_confirmation','manager_approval'=>'required',
 'traveler_acceptance'=>'required','order_timing'=>'manual',
 'supplier_booking_timing'=>'manual','deposit_mode'=>'manual_review',
 'currency_source'=>'manual_review','rate_sale_mode'=>'disabled'
];
$policy=[
 'site_uuid'=>$site['site_uuid'],
 'site_profile_revision'=>3,'site_profile_digest'=>$site['site_profile_digest'],
 'environment'=>'production','expected_revision'=>0,
 'intent'=>'owner-policy-draft-reviewed-0001','status'=>'draft',
 'rules'=>[['id'=>'company_draft_defaults','scope'=>'company',
 'selectors'=>[],'settings'=>$defaults]]
];
$r=$cls::initialize_policy($policy);
assert_true(!is_wp_error($r)&&$r['state']==='policy_draft_created'&&$r['policy_active']===false,'first draft policy');
$fromNative=\Commerce\Composition\CompanyCommercePolicy::registry();
assert_true(!is_wp_error($fromNative)&&$fromNative['revision']===1&&$fromNative['status']==='draft','native policy validator reads draft');
assert_true(count($GLOBALS['options'])===2,'two site-pinned options');
assert_error($cls::initialize_policy($policy),'first_enrollment_policy_already_exists');
$bad=$policy;$bad['status']='active';
assert_error($cls::initialize_policy($bad),'first_enrollment_policy_payload_invalid');
unset($GLOBALS['options'][$cls::POLICY]);
$bad=$policy;$bad['rules'][0]['settings']['rate_sale_mode']='sell_all';
assert_error($cls::initialize_policy($bad),'policy_rule_invalid');
assert_true(!array_key_exists($cls::POLICY,$GLOBALS['options']),'rejected policy never written');
$GLOBALS['status']['authority_ready']=false;
assert_error($cls::site(),'first_enrollment_site_authority_unready');
assert_error($cls::initialize_policy($policy),'first_enrollment_site_authority_unready');
assert_true(!array_key_exists($cls::POLICY,$GLOBALS['options']),'denied host authority no write');
echo "PASS: {$GLOBALS['checks']} protected first enrollment assertions (native CompanyCommercePolicy validator)\n";
}
