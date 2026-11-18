<?php
/**
 * Executes original ChatbotX adapter registration and mutation-denial owners.
 * Stub only generic Adapter_Base transport, not the actual source under review.
 * No WordPress DB, network, WooCommerce, native ticket or CPT mutation.
 */
define('ABSPATH', __DIR__.'/');
define('WP_PLUGIN_DIR', '/nonexistent-php-only-provider-tests');
$GLOBALS['cmb_active'] = false;
$GLOBALS['cmb_registrations'] = array();
$GLOBALS['cmb_actor_admin'] = false;
$GLOBALS['cmb_mutation_enabled'] = false;
function get_option($name, $default = array()) {
    return $name==='active_plugins' && $GLOBALS['cmb_active']
        ? array('customer-messaging-bridge/customer-messaging-bridge.php') : $default;
}
function current_user_can($cap) {
    return $cap==='manage_options' && $GLOBALS['cmb_actor_admin'];
}
function is_wp_error($value) {return $value instanceof WP_Error;}
function sanitize_key($value) {return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$value));}
function absint($value) {return abs((int)$value);}
class WP_Error {
    private $code;
    public function __construct($code,$message=''){$this->code=$code;}
    public function get_error_code(){return $this->code;}
}
class MAD4B_SCP_Policy {
    public static function can_mutate(){return $GLOBALS['cmb_mutation_enabled'];}
    public static function mutation_gate_status(){return array('effective'=>false);}
}
class Customer_Messaging_Bridge {
    const API_ORIGIN='https://provider.example.invalid';
}
class CMB_Channels { const CHANNELS=array('email'=>true); }
class CMB_Commerce {}
class CMB_Webhooks {}
abstract class MAD4B_SCP_Adapter_Base {
    abstract public function id();
    abstract public function label();
    abstract public function is_available();
    abstract public function ability_names();
    abstract public function register_abilities();
    abstract protected function mutation_requires_certification();
    protected function schema(array $properties,array $required=array()){
        $result=array('type'=>'object','properties'=>$properties,'additionalProperties'=>false);
        if($required)$result['required']=$required;
        return $result;
    }
    protected function add_ability($name,$label,$method,$permission,$schema=null,
        $surface='read',$readonly=true,$destructive=false,$idempotent=true){
        if(isset($GLOBALS['cmb_registrations'][$name]))
            throw new RuntimeException('Duplicate original ChatbotX ability registration');
        $GLOBALS['cmb_registrations'][$name]=array(
            'method'=>$method,'callback'=>$permission,'schema'=>$schema,
            'surface'=>$surface,'readonly'=>$readonly,
            'destructive'=>$destructive,'idempotent'=>$idempotent);
    }
    protected function unavailable_error(){
        return new WP_Error('mad4b_adapter_unavailable');
    }
}

$GLOBALS['cmb_configured_cpts']=array();
$GLOBALS['cmb_origin_enrolled']=false;
class MAD4B_SCP_Site_Profile {
    public static function status(){return array('environment'=>'staging');}
    public static function write_enabled(){return false;}
    public static function origin_enrolled(){return $GLOBALS['cmb_origin_enrolled'];}
    public static function site_urls_match_enrollment(){return $GLOBALS['cmb_origin_enrolled'];}
}
function apply_filters($name,$default){
    return $name==='mad4b_scp_chatbotx_draft_cpt_allowlist' ? $GLOBALS['cmb_configured_cpts'] : $default;
}
function get_post_type_object($name){
    if ($name==='custom_itinerary')return (object)array('_builtin'=>false,'cap'=>(object)array('create_posts'=>'edit_posts'));
    if ($name==='post')return (object)array('_builtin'=>true,'cap'=>(object)array('create_posts'=>'edit_posts'));
    return null;
}
require dirname(__DIR__).
    '/wp-content/plugins/mad4b-site-control-plane/includes/adapters/class-mad4b-scp-chatbotx-provider-adapter.php';
$instance=new MAD4B_SCP_ChatbotX_Provider_Adapter();
function cmb_assert($ok,$description) {
    if(!$ok){fwrite(STDERR,"FAIL CHATBOTX ORIGINAL: ".$description."\n");exit(1);}
}
$empty=$instance->ability_names();
cmb_assert($instance->is_available()===false
    && count($empty['read'])===0 && count($empty['write'])===0,
    'Provider missing must expose no abilities');
$instance->register_abilities();
cmb_assert(!$GLOBALS['cmb_registrations'], 'Uninstalled provider must never register');
$GLOBALS['cmb_active']=true;
cmb_assert($instance->is_available()===true,'Native installed activation required');
$declared=$instance->ability_names();
$reads=$declared['read'];
$writes=$declared['write'];
cmb_assert(count($reads)===11 && count($writes)===2
    && !$declared['admin'] && !$declared['content'],
    'One first-party provider cannot widen the original 11/3 surface');
$instance->register_abilities();
$registered=array_keys($GLOBALS['cmb_registrations']);
cmb_assert(count($registered)===13
    && !array_diff($registered,array_merge($reads,$writes))
    && !array_diff(array_merge($reads,$writes),$registered),
    'Original registration is not exactly equal to advertised abilities');
$expected_writes=array(
    'mad4b/chatbotx-support-create-ticket',
    'mad4b/chatbotx-support-add-note',
);
cmb_assert($writes===$expected_writes,
    'Do not add provider writes without distinct full review');
foreach($registered as $name) {
    $a=$GLOBALS['cmb_registrations'][$name];
    $mutation=in_array($name,$expected_writes,true);
    cmb_assert($a['schema']['type']==='object'
        && $a['schema']['additionalProperties']===false
        && $a['surface']===($mutation?'write':'read')
        && $a['readonly']===!$mutation
        && $a['callback']===array('MAD4B_SCP_Policy',$mutation?'can_admin':'can_read')
        && $a['destructive']===$mutation,
        'Exact closed registration schema, actor or mutation annotation drift: '.$name);
    if($mutation) {
        cmb_assert(($a['schema']['properties']['idempotency_key']['minLength']??null)===16
            && ($a['schema']['properties']['idempotency_key']['maxLength']??null)===100
            && in_array('idempotency_key',$a['schema']['required'],true),
            'Every provider write must require bounded idempotency key');
    }
}
cmb_assert($instance->reversible_contracts()===array(),
    'Do not advertise unimplemented rollback when writing via a third party');
cmb_assert($instance->write_preflight(array('ability'=>'malicious/write')) instanceof WP_Error,
    'Unknown write must be rejected before external side effects');
foreach($expected_writes as $name) {
    $result=$instance->write_preflight(array('ability'=>$name));
    cmb_assert(is_array($result)
        && $result['read_only']===true
        && $result['approved_for_execution']===false
        && $result['no_mutation_performed']===true
        && !empty($result['blockers']),
        'Preflight without owner and enrolled profile must deny mutation');
}
cmb_assert($instance->support_create_ticket(array()) instanceof WP_Error
    && $instance->support_add_note(array()) instanceof WP_Error
    && $instance->cpt_create_draft(array()) instanceof WP_Error,
    'Disabled native central mutation must not write a ticket, note or CPT');

$GLOBALS['cmb_origin_enrolled']=true;
$GLOBALS['cmb_configured_cpts']=array('custom_itinerary');
$optin=$instance->ability_names();
cmb_assert(count($optin['read'])===11 && count($optin['write'])===3
    && in_array('mad4b/chatbotx-cpt-create-draft',$optin['write'],true),
    'Enrolled, configured CPT opt-in adds exactly one write');
$GLOBALS['cmb_registrations']=array();
$instance->register_abilities();
cmb_assert(count($GLOBALS['cmb_registrations'])===14,'Opt-in adds exactly one ability');
$draft=$GLOBALS['cmb_registrations']['mad4b/chatbotx-cpt-create-draft'];
cmb_assert($draft['schema']['properties']['post_type']['enum']===array('custom_itinerary')
    && $draft['readonly']===false && $draft['destructive']===true,
    'CPT write schema bound to configured type');
$pending=$instance->write_preflight(array('ability'=>'mad4b/chatbotx-cpt-create-draft','post_type'=>'custom_itinerary'));
cmb_assert(is_array($pending) && !$pending['approved_for_execution']
    && $pending['no_mutation_performed'] && !empty($pending['blockers']),
    'Configured CPT is never itself a grant');
$GLOBALS['cmb_configured_cpts']=array('post');
cmb_assert(MAD4B_SCP_ChatbotX_Write_Preflight::allowed_post_types()===array(),
    'Built-in post remains denied');
echo "PASS: CHATBOTX_NATIVE_ADAPTER_GATES 11 read / 2 default + 1 opt-in writes\n";
