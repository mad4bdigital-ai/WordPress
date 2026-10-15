<?php
/** Source-only native regression: owner-intent receipts never act as Admin tickets. */
define( 'ABSPATH', __DIR__ );
class WP_Error {
    private $code; public function __construct($code,$message=''){ $this->code=$code; }
    public function get_error_code(){ return $this->code; }
}
function is_wp_error($value){ return $value instanceof WP_Error; }
function wp_json_encode($v,$flags=0){ return json_encode($v,$flags); }
function add_action($name,$callback,$priority=10){ $GLOBALS['hooks'][]=$name; }
function wp_has_ability($name){ return isset($GLOBALS['abilities'][$name]); }
function wp_register_ability($name,$args){ $GLOBALS['abilities'][$name]=$args; return true; }
function current_user_can($cap){ return 'manage_options'===$cap && $GLOBALS['admin']; }
function get_current_user_id(){ return $GLOBALS['user']; }
function get_option($key,$default=null){ return $GLOBALS['options'][$key]??$default; }
function admin_url($path=''){ return 'https://staging.example.test/wp-admin/'.$path; }
function update_option($key,$value,$autoload=false){ $GLOBALS['options'][$key]=$value; return true; }
class MAD4B_SCP_Policy { public static function can_read(){ return $GLOBALS['read']; } }
class MAD4B_SCP_Site_Profile {
    public static function configured(){ return true; }
    public static function current_environment(){ return $GLOBALS['env']; }
    public static function origin_enrolled(){ return true; }
    public static function site_urls_match_enrollment(){ return true; }
    public static function site_uuid(){ return '49c562d1-8f2f-456f-b454-26816c6ba4cb'; }
    public static function revision(){ return $GLOBALS['revision']; }
    public static function profile_digest(){ return hash('sha256','staging-profile-v'.$GLOBALS['revision']); }
    public static function user_is_enrolled($id){ return $id===17 && $GLOBALS['enrolled']; }
}
class MAD4B_SCP_WP_Native_Build {
    const CONFIRM='BUILD UNVERIFIED STAGING SOURCE';
    public static function plan($input){
        if(($input['expected_head']??'')!==str_repeat('a',40))
            return new WP_Error('source_not_allowed');
        return array('source_commit_sha'=>$input['expected_head'],
            'plan_sha256'=>hash('sha256','exact-native-plan'),
            'eligible'=>$GLOBALS['build_eligible'],'blockers'=>$GLOBALS['build_eligible']?array():array('staging_not_ready'));
    }
}
class MAD4B_SCP_Audit {
    public static function record($name,$data,$status){ $GLOBALS['audit'][]=array($name,$data,$status); }
}
$GLOBALS['read']=true; $GLOBALS['admin']=true; $GLOBALS['enrolled']=true;
$GLOBALS['user']=17; $GLOBALS['revision']=3; $GLOBALS['env']='staging';
$GLOBALS['build_eligible']=true; $GLOBALS['audit']=array();$GLOBALS['abilities']=array();$GLOBALS['options']=array();$GLOBALS['hooks']=array();
require __DIR__.'/../includes/class-mad4b-scp-native-build-owner-consent.php';
MAD4B_SCP_Native_Build_Owner_Consent::boot();
MAD4B_SCP_Native_Build_Owner_Consent::register_abilities();
$assert=static function($ok,$why){ if(!$ok) throw new RuntimeException($why); };
$head=str_repeat('a',40);
$plan=MAD4B_SCP_Native_Build_Owner_Consent::plan(array('expected_head'=>$head));
$assert(!is_wp_error($plan) && $plan['state']==='OWNER_REVIEW_AVAILABLE','Owner source plan was not formed');
$assert($plan['review_payload']['site_profile_revision']===3 && $plan['review_payload']['environment']==='staging','Wrong binding');
$assert(strpos($plan['owner_consent_entrypoint'],'expected_head='.$head)!==false,'Owner review URL missing pinned SHA');
$assert($plan['owner_approval_ticket_issued']===false && $plan['can_auto_approve_from_chat_text']===false,'Read plan claimed OAuth authority');
$assert(isset($GLOBALS['abilities'][MAD4B_SCP_Native_Build_Owner_Consent::PLAN_ABILITY]),'Plan ability missing');
$assert($GLOBALS['abilities'][MAD4B_SCP_Native_Build_Owner_Consent::PLAN_ABILITY]['meta']['annotations']['readonly'],'Plan wrongly classified as mutation');
$bad=MAD4B_SCP_Native_Build_Owner_Consent::attest(array('expected_head'=>$head,'review_sha256'=>$plan['review_sha256'],'confirmation'=>'BUILD UNVERIFIED STAGING SOURCE'));
$assert(is_wp_error($bad),'Unscoped text-only confirmation accepted');
$assert(empty($GLOBALS['options']),'Invalid confirmation mutated WordPress options');
$input=array('expected_head'=>$head,'review_sha256'=>$plan['review_sha256'],'confirmation'=>$plan['confirmation_format']);
$issued=MAD4B_SCP_Native_Build_Owner_Consent::attest($input);
$assert(!is_wp_error($issued) && $issued['state']==='OWNER_INTENT_ATTESTED_EXECUTION_NOT_AUTHORIZED','Authenticated owner intent not recorded');
$assert($issued['approval_ticket_issued']===false && $issued['execution_authorized']===false,'Owner intent bypassed Admin ticket');
$assert(count($GLOBALS['audit'])===1 && $GLOBALS['audit'][0][0]==='mad4b/native-build-owner-intent','Owner intent audit missing');
$status=MAD4B_SCP_Native_Build_Owner_Consent::status(array('expected_head'=>$head));
$assert($status['owner_intent_valid'] && !$status['execution_authorized'],'Remote status omitted scoped intent truth');
$assert($status['seconds_remaining'] > 0 && $status['seconds_remaining'] <= 600,'Owner intent TTL readback invalid');
$attested_plan=MAD4B_SCP_Native_Build_Owner_Consent::plan(array('expected_head'=>$head));
$assert($attested_plan['state']==='OWNER_INTENT_ATTESTED_EXECUTION_NOT_AUTHORIZED','Planning UI did not display live attested intent');
$assert($attested_plan['owner_intent_valid'] && !$attested_plan['owner_approval_ticket_issued'],'Plan falsely promoted owner intent to a mutation ticket');
$assert(count($attested_plan['next_required_steps'])===3,'Owner intent handoff omitted OAuth/ticket/build stages');
$GLOBALS['revision']=4;
$stale=MAD4B_SCP_Native_Build_Owner_Consent::status(array('expected_head'=>$head));
$assert(!$stale['owner_intent_valid'],'Old owner consent survived Site Profile revision drift');
$stale_plan=MAD4B_SCP_Native_Build_Owner_Consent::plan(array('expected_head'=>$head));
$assert($stale_plan['owner_intent_state']==='OWNER_INTENT_NOT_CURRENT','Plan reused intent from a different site revision');
$GLOBALS['revision']=3;
$GLOBALS['options'][MAD4B_SCP_Native_Build_Owner_Consent::OPTION]['expires_at']=time()-1;
$expired=MAD4B_SCP_Native_Build_Owner_Consent::status(array('expected_head'=>$head));
$assert(!$expired['owner_intent_valid'],'Expired consent was accepted');
$assert($expired['state']==='OWNER_INTENT_EXPIRED' && $expired['requires_renewal'],'Expired UI state or renewal directive missing');
$expired_plan=MAD4B_SCP_Native_Build_Owner_Consent::plan(array('expected_head'=>$head));
$assert($expired_plan['owner_intent_requires_renewal'] && !$expired_plan['owner_intent_valid'],'Expired plan was not renew-only');
$assert($expired_plan['owner_approval_ticket_issued']===false,'Expired intent claimed Admin ticket authority');
$GLOBALS['env']='production';
$assert(is_wp_error(MAD4B_SCP_Native_Build_Owner_Consent::attest($input)),'Production attestation was allowed');
$GLOBALS['env']='staging'; $GLOBALS['enrolled']=false;
$assert(is_wp_error(MAD4B_SCP_Native_Build_Owner_Consent::attest($input)),'Non-enrolled user was accepted');
$GLOBALS['enrolled']=true; $GLOBALS['read']=false;
$assert(is_wp_error(MAD4B_SCP_Native_Build_Owner_Consent::plan(array('expected_head'=>$head))),'Governed read denial was bypassed');
echo "PASS native owner consent: live UI readback, 10-minute expiry/renewal, exact SHA/site, no OAuth/ticket/execution bypass\n";
