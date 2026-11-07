<?php
/** Isolated channel registry tests. WP core/database/network never loaded. */
define('ABSPATH','/tmp/test');
$GLOBALS['mad4b_test_options']=[];$GLOBALS['mad4b_test_auth']=false;
class WP_Error {public function __construct(private string $code){} public function get_error_code(){return $this->code;}}
function get_option($k,$default=null){return $GLOBALS['mad4b_test_options'][$k]??$default;}
function update_option($k,$v,$autoload=false){$GLOBALS['mad4b_test_options'][$k]=$v;return true;}
function add_action($name,$fn){return true;}
function apply_filters($name,$val){return $val;}
function current_user_can($cap){return $GLOBALS['mad4b_test_auth'];}
function sanitize_key($x){return strtolower(preg_replace('/[^a-z0-9_\\-]/','',(string)$x));}
function sanitize_text_field($x){return strip_tags((string)$x);}
function has_filter($name){return false;}
function wp_salt($scheme){return str_repeat($scheme==='auth'?'A':'B',64);}
function home_url($route='/'){return ($GLOBALS['mad4b_test_home']??'https://company-a.example').$route;}
function untrailingslashit($url){return rtrim($url,'/');}
function get_current_blog_id(){return 1;}
function is_wp_error($r){return $r instanceof WP_Error;}
function get_current_user_id(){return $GLOBALS['mad4b_test_auth']?5:0;}
require_once __DIR__.'/../wp-content/plugins/mad4b-distribution-connectors/includes/class-mad4b-distribution-local-credentials.php';
require_once __DIR__.'/../wp-content/plugins/mad4b-distribution-connectors/includes/class-mad4b-distribution-channel-registry.php';
function check($truth,$why){if(!$truth)throw new RuntimeException('FAILED_'.$why);}
$cls='MAD4B_Distribution_Channel_Registry_V1';
$initial=$cls::provision_defaults();
check(is_array($initial)&&count($initial['added'])===11,'DEFAULT_11');
check(count($cls::rows())===11,'DEFAULT_STORED');
check($cls::provision_defaults()['added']===[],'IDEMPOTENT');
foreach($cls::rows() as $row) {
    check(!$row['external_delivery_enabled']&&!$row['publishing_enabled'],'SAFE_NO_EXTERNAL');
    check($row['status']==='not_connected','SAFE_NOT_CONNECTED');
}
check($cls::configure('meta_catalog',['account_alias'=>'x']) instanceof WP_Error,'ANON_EDIT_DENIED');
check($cls::create_custom(['id'=>'custom_vendor_a','label'=>'A','transport'=>'api','category'=>'other']) instanceof WP_Error,'ANON_ADD_DENIED');
$GLOBALS['mad4b_test_auth']=true;
check($cls::create_custom(['id'=>'custom_vendor_a','label'=>'Vendor A','transport'=>'partner_api','category'=>'ota_marketplace'])['state']==='CUSTOM_CHANNEL_REGISTERED','ADD_CUSTOM');
check(count($cls::rows())===12,'ADD_COUNT');
check($cls::create_custom(['id'=>'custom_vendor_a','label'=>'Vendor A','transport'=>'api','category'=>'other']) instanceof WP_Error,'DUPLICATE_DENIED');
check($cls::create_custom(['id'=>'custom_not_valid','label'=>'Vendor','transport'=>'ssh_exec','category'=>'other']) instanceof WP_Error,'UNSAFE_TRANSPORT_REJECTED');
check($cls::configure('custom_vendor_a',['external_delivery_enabled'=>true]) instanceof WP_Error,'DIRECT_SEND_UNCONFIGURABLE');
check($cls::configure('custom_vendor_a',['credential_reference'=>'plainapikey']) instanceof WP_Error,'PLAINTEXT_DENIED');
check($cls::configure('custom_vendor_a',['endpoint_host'=>'localhost']) instanceof WP_Error,'LOCALHOST_DENIED');
check($cls::configure('custom_vendor_a',['linked_channels'=>['custom_vendor_a']]) instanceof WP_Error,'SELF_REFERENCE_DENIED');
check($cls::configure('custom_vendor_a',['linked_channels'=>['unregistered']]) instanceof WP_Error,'UNKNOWN_LINK_DENIED');
check($cls::configure('custom_vendor_a',[
    'account_alias'=>'vendor_demo',
    'credential_reference'=>'mad4b_vault:company_vendor_pointer',
    'endpoint_host'=>'example.com','linked_channels'=>['meta_catalog'],
    'scope_languages'=>['en','es'],
])['state']==='CONFIGURED_AWAITING_VERIFICATION','DRAFT_CREATED');
check($cls::configure('custom_vendor_a',['account_alias'=>'vendor_demo'])['state']==='UNCHANGED','IDEMPOTENT_NOOP');
$after=$cls::rows()['custom_vendor_a'];
check($after['external_delivery_enabled']===false && $after['publishing_enabled']===false,'RECONFIG_NO_EXTERNAL');
check($after['configuration_revision']===1,'CONFIG_VERSION');
check($after['company_override']===true,'LOCAL_OVERRIDE');
$read=$cls::public_status()['custom_vendor_a'];
check(!array_key_exists('credential_reference',$read) && $read['legacy_credential_reference_present'],'NO_REF_IN_PUBLIC_STATUS');
$health=$cls::connection_health()['custom_vendor_a'];
check(!$health['connection_verified'] && !in_array('company_vault_unavailable',$health['missing'],true),'NO_EXTERNAL_VAULT');
check(in_array('linked_channel_not_connected:meta_catalog',$health['missing'],true),'LINKED_REQUIREMENT');
check(in_array('provider_enrollment_pending',$health['missing'],true),'PROVIDER_APPROVAL_GUARD');
$audit=(array)get_option($cls::AUDIT,[]);
check(count($audit)===2,'AUDIT_TWO_CHANGES');
$trail=json_encode($audit);
check(!str_contains($trail,'company_vendor_pointer')&&!str_contains($trail,'example.com'),'REDACTED_AUDIT');
check($cls::provision_defaults()['added']===[] && count($cls::rows())===12,'LOCAL_CHANNEL_PRESERVED');
// Live-like local credential lifecycle, in memory only; no third-party vault.
$storage='MAD4B_Distribution_Local_Credentials_V1';
check($storage::available(),'LOCAL_ENCRYPTION_AVAILABLE');
check($storage::status()['external_vault_required']===false,'EXTERNAL_VAULT_NOT_REQUIRED');
$GLOBALS['mad4b_test_auth']=false;
check($storage::put('custom_vendor_a','demo_not_real_secret_123') instanceof WP_Error,'ANONYMOUS_SECRET_WRITE_DENIED');
$GLOBALS['mad4b_test_auth']=true;
$changed=$cls::change_local_credential('custom_vendor_a','demo_not_real_secret_123');
check(is_array($changed)&&$changed['status']==='SAVED_ENCRYPTED_LOCALLY','SECRET_STORED_LOCALLY');
$blob=get_option($storage::STORE,[])['custom_vendor_a']??null;
check(is_string($blob)&&str_starts_with($blob,'MAD4B$DL1$'),'AEAD_PREFIX');
check(!str_contains($blob,'demo_not_real_secret_123'),'CIPHERTEXT_NO_PLAIN');
check(!str_contains(json_encode($cls::rows()),'demo_not_real_secret_123'),'REGISTRY_NOT_LEAKING_SECRET');
check(!str_contains(json_encode($cls::public_status()),'demo_not_real_secret_123'),'REST_NOT_LEAKING_SECRET');
check($storage::has('custom_vendor_a'),'KEYED_DECRYPT_VERIFIED');
// Simulate copying the encrypted WordPress option to a different company domain.
$GLOBALS['mad4b_test_home']='https://company-b.example';
check(!$storage::has('custom_vendor_a'),'CROSS_COMPANY_SECRET_CANNOT_DECRYPT');
$GLOBALS['mad4b_test_home']='https://company-a.example';
check($storage::has('custom_vendor_a'),'ORIGINAL_COMPANY_RETAINS_SECRET');
check($storage::for_adapter('custom_vendor_a','pricing_export')===false,'DEFAULT_ADAPTER_READ_DENIED');
$health=$cls::connection_health()['custom_vendor_a'];
check(!$health['connection_verified'] && $health['credential_saved_locally'],'NO_PREMATURE_CHANNEL_VERIFICATION');
check(!in_array('company_vault_unavailable',$health['missing'],true),'NO_EXTERNAL_VAULT_REQUIREMENT');
check(in_array('provider_credential_not_tested',$health['missing'],true),'NEEDS_PROVIDER_VERIFICATION');
check($cls::rows()['custom_vendor_a']['external_delivery_enabled']===false,'STILL_NO_DELIVERY');
check($storage::delete('custom_vendor_a')['status']==='REVOKED_LOCALLY','SECRET_DELETE');
check(!$storage::has('custom_vendor_a'),'SECRET_READBACK_ABSENT');
check($cls::change_local_credential('custom_vendor_a','demo_not_real_secret_456')['status']==='SAVED_ENCRYPTED_LOCALLY','CREDENTIAL_ROTATION');
check($storage::has('custom_vendor_a'),'ROTATION_PRESENT');
$otherHome=$storage::STORE;
check(count(get_option($otherHome,[]))===1,'CREDENTIALS_NEVER_IN_META');
check(count(array_filter($cls::rows(),static fn($row)=>!empty($row['external_delivery_enabled'])))===0,'NO_EXTERNAL_SEND_AFTER_SECRET_STORE');
// No fake transports or partner verification performed by these tests.
echo json_encode(['tests_passed'=>49,'base_channels'=>11,'with_local_custom'=>count($cls::rows()),
    'custom_channel'=>'custom_vendor_a','external_deliveries'=>0,'connection_status'=>$health['status'],
    'health_missing'=>$health['missing'],'local_secret_encrypted'=>true,'audit_events'=>count($audit),
    'isolated_no_wp_db'=>true],JSON_UNESCAPED_SLASHES).PHP_EOL;
