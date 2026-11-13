<?php
/** First-party registry-backed policy admission, no WordPress installation or grants. */
define('ABSPATH', dirname(__DIR__, 2) . '/');
define('MAD4B_SCP_DIR', ABSPATH . 'wp-content/plugins/mad4b-site-control-plane/');
class WP_Error {
    private $code;
    public function __construct($code, $message = '') {$this->code=$code;}
    public function get_error_code(){return $this->code;}
}
function is_wp_error($x){return $x instanceof WP_Error;}
function sanitize_key($k){return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string)$k));}
function wp_json_encode($a,$flags=0){return json_encode($a,$flags);}
class MAD4B_SCP_Canonicalization {
    public static function semantic_operation_id($v){
        return preg_match('/^[a-z][a-z0-9.\-]{1,100}$/D',$v) ? $v : new WP_Error('operation_id_invalid');
    }
    public static function ability_name($v){
        return preg_match('#^[a-z0-9][a-z0-9_-]*/[a-z0-9-]+$#D',$v) ? $v : new WP_Error('ability_invalid');
    }
}
class MAD4B_SCP_Site_Profile {
    public static function status(){return array('authority_ready'=>empty($GLOBALS['no_authority']),
        'site_uuid'=>'a3a7cc36-db7e-4a14-8456-b41857def05c');}
    public static function site_uuid(){return 'a3a7cc36-db7e-4a14-8456-b41857def05c';}
    public static function revision(){return 5;}
    public static function profile_digest(){return str_repeat('b',64);}
    public static function origin_enrolled(){return empty($GLOBALS['origin_revoked']);}
    public static function site_urls_match_enrollment(){return empty($GLOBALS['origin_mismatch']);}
    public static function managed_runtime_enabled(){return empty($GLOBALS['runtime_disabled']);}
    public static function write_enabled(){return empty($GLOBALS['write_disabled']);}
    public static function current_environment(){return $GLOBALS['env']??'staging';}
}
class MAD4B_SCP_Governed_Runtime_Gates {
    public static function production_mutation_enabled(){return !empty($GLOBALS['production_policy_active']);}
}
class MAD4B_SCP_Policy_Resolution {
    public static function environment_allowed($env){return !empty($GLOBALS['allowed_environments'][$env]);}
    public static function current_operating_mode(){return 'SINGLE_OWNER_HARDENED';}
    public static function config_digest(){return str_repeat('c',64);}
    public static function resolve($facts){
        if (!empty($GLOBALS['deny_policy'])) return array('decision'=>'DENY','reason_code'=>'configured_deny');
        if (empty($facts['authority_allowed']) || empty($facts['feature_enabled']) || empty($facts['environment_allowed']))
            return array('decision'=>'DENY','reason_code'=>'required_fact_missing');
        return array('decision'=>'ALLOW','decision_sha256'=>hash('sha256',json_encode($facts)));
    }
}
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-operation-registry.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-registered-write-admission.php';
$ability='mad4b/operator-grants-converge';
function check($name,$expected_error=''){
    global $ability;
    $r=MAD4B_SCP_Registered_Write_Admission::preflight($ability);
    $error=is_wp_error($r)?$r->get_error_code():'';
    if ($error!==$expected_error || (!$expected_error && (
        !is_array($r) || ($r['state']??'')!=='eligible_for_exact_canonical_plan_only'
        || ($r['authorization_issued']??null)!==false || ($r['mutation_performed']??null)!==false))) {
        fwrite(STDERR,'FAIL '.$name.' expected '.$expected_error.' got '.$error.PHP_EOL);exit(2);
    }
    echo 'PASS '.$name.PHP_EOL;
}
$GLOBALS['allowed_environments']=array('staging'=>true,'production'=>true,'development'=>true);
$reg=MAD4B_SCP_Operation_Registry::registered_write_policy($ability);
if (is_wp_error($reg)) {fwrite(STDERR,'REGISTRY ERROR '.$reg->get_error_code().PHP_EOL);exit(3);}
check('staging eligible but never authorizes mutation');
$GLOBALS['env']='production';check('production no active runtime policy','mad4b_registered_write_runtime_policy_required');
$GLOBALS['production_policy_active']=true;check('production eligible only with effective governance');
$GLOBALS['allowed_environments']['production']=false;
check('production denied by registered Governance Policy','mad4b_registered_write_environment_policy_denied');
$GLOBALS['allowed_environments']['production']=true;
$GLOBALS['origin_mismatch']=true;check('wrong site origin','mad4b_registered_write_site_unverified');unset($GLOBALS['origin_mismatch']);
$GLOBALS['origin_revoked']=true;check('revoked enrollment','mad4b_registered_write_site_unverified');unset($GLOBALS['origin_revoked']);
$GLOBALS['write_disabled']=true;check('Write policy off','mad4b_registered_write_site_unverified');unset($GLOBALS['write_disabled']);
$GLOBALS['runtime_disabled']=true;check('managed runtime off','mad4b_registered_write_site_unverified');unset($GLOBALS['runtime_disabled']);
$GLOBALS['no_authority']=true;check('site authority revoked','mad4b_registered_write_site_unverified');unset($GLOBALS['no_authority']);
$GLOBALS['deny_policy']=true;check('governance denies explicitly','mad4b_registered_write_governance_denied');unset($GLOBALS['deny_policy']);
$unknown=MAD4B_SCP_Operation_Registry::registered_write_policy('mad4b/unknown-owner-grant');
if (!is_wp_error($unknown)||$unknown->get_error_code()!=='mad4b_registered_operation_policy_missing') exit(5);
echo 'PASS missing operation policy fails closed'.PHP_EOL;
// A compromised or incorrectly merged catalog must never make an
// ambiguous/unknown descriptor look eligible. Change request-local cache only.
$reflection=new ReflectionProperty('MAD4B_SCP_Operation_Registry','catalog');
$original=$reflection->getValue();
$duplicate=$original;
foreach($duplicate['operations'] as $row){
    if(($row['planner']??'')===$ability){$duplicate['operations'][]=$row;break;}
}
$reflection->setValue(null,$duplicate);
$collision=MAD4B_SCP_Operation_Registry::registered_write_policy($ability);
if(!is_wp_error($collision)||$collision->get_error_code()!=='mad4b_registered_operation_policy_ambiguous')exit(7);
echo 'PASS ambiguous registry operation denied'.PHP_EOL;
$invalid=$original;
foreach($invalid['operations'] as &$row){
    if(($row['planner']??'')===$ability){$row['write_admission']['policy_effect']='authorize_mutation';break;}
}
unset($row);
$reflection->setValue(null,$invalid);
$denied=MAD4B_SCP_Operation_Registry::registered_write_policy($ability);
if(!is_wp_error($denied)||$denied->get_error_code()!=='mad4b_registered_operation_policy_invalid')exit(8);
echo 'PASS unreviewed mutation-effect catalog denied'.PHP_EOL;
$reflection->setValue(null,$original);
