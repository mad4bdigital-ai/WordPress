<?php
/** Original native Connector Vault: no keys, no network, no real options. */
define('ABSPATH',__DIR__.'/');
define('MAD4B_CORE_CONNECTOR_VAULT',true);
if(!function_exists('str_contains')) { function str_contains($h,$n){return strpos($h,$n)!==false;} }
if(!function_exists('str_starts_with')) { function str_starts_with($h,$n){return substr($h,0,strlen($n))===$n;} }
function get_current_blog_id(){return $GLOBALS['blog_id'];}
class WP_Error { function __construct($code='error'){} }
function add_action($hook,$handler,$priority=10,$argc=1){$GLOBALS['actions'][$hook]=[$handler,$priority,$argc];}
function add_filter($hook,$handler,$priority=10,$argc=1){$GLOBALS['filters'][$hook]=[$handler,$priority,$argc];}
function wp_get_connectors(){
 if($GLOBALS['connector_rows']==='throw')throw new RuntimeException('connector listing failed');
 return $GLOBALS['connector_rows'];
}
function native_assert($ok,$reason){
 if(!$ok){fwrite(STDERR,'FAIL: '.$reason.PHP_EOL);exit(1);}
 $GLOBALS['cases']=($GLOBALS['cases']??0)+1;
}
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-core-connector-vault.php';
$c='MAD4B_SCP_Core_Connector_Vault';
$GLOBALS['blog_id']=3;$GLOBALS['filters']=[];$GLOBALS['actions']=[];
$GLOBALS['connector_rows']=[];
native_assert($c::enabled()===true,'Vault opt-in requires canonical exact constant true');
$c::boot();
native_assert(isset($GLOBALS['actions']['wp_connectors_init'])
 && $GLOBALS['actions']['wp_connectors_init'][2]===0,
 'Native vault uses original WordPress connector source discovery, not static provider permission');
foreach(['connectors_ai_google_api_key','connectors_ai_openai_api_key',
 'connectors_ai_anthropic_api_key','connectors_mad4b_gemini_api_key',
 'connectors_mad4b_openrouter_api_key','connectors_mad4b_custom_api_key'] as $name){
 native_assert(isset($GLOBALS['filters']['pre_update_option_'.$name])
  &&isset($GLOBALS['filters']['option_'.$name]),
  'Native option encoder and decoder installed for registered secret: '.$name);
}
native_assert(!isset($GLOBALS['filters']['pre_update_option_random_key']),
 'Unregistered arbitrary WordPress option is never instrumented');
$prefix=$c::PREFIX;
$original='SECRET-HIGH-ENTROPY-UNSTORED';
$stored=$c::encode_option('connectors_ai_openai_api_key',$original,'');
native_assert($stored!==$original,
 'Unavailable encryption key must prevent plaintext persistence');
native_assert($c::unseal('connectors_ai_openai_api_key',$prefix.'invalid')===false,
 'Forged ciphertext cannot be interpreted as an original credential');
native_assert($c::decode_option('connectors_ai_openai_api_key',$prefix.'invalid')==='',
 'Unverifiable encrypted secret never leaks fake plaintext to core plugin');
native_assert($c::seal('not_a_registered_option',$original)===false,
 'Unknown option cannot gain a ciphertext identity');
native_assert($c::encode_option('connectors_ai_openai_api_key','•••••••','previous-original-secret')
 ==='previous-original-secret',
 'Masked admin readback is not a secret rotation request');
native_assert($c::encode_option('connectors_ai_openai_api_key',$prefix.'forged','old')===$prefix.'forged',
 'Existing encrypted marker stays tagged for decoder, not treated as newly encrypted plaintext');
$GLOBALS['connector_rows']=[
 ['authentication'=>['method'=>'api_key','setting_name'=>'connectors_myvendor_extra_api_key']],
 ['authentication'=>['method'=>'oauth2','setting_name'=>'connectors_non_api_api_key']],
 ['authentication'=>['method'=>'api_key','setting_name'=>'admin_password']],
 ['authentication'=>['method'=>'api_key','setting_name'=>'connectors_myvendor_extra_api_key']]
];
$c::discover();
native_assert(isset($GLOBALS['filters']['option_connectors_myvendor_extra_api_key']),
 'Dynamically connected first-party registered API key uses original option filter');
native_assert(!isset($GLOBALS['filters']['option_admin_password'])
 &&!isset($GLOBALS['filters']['option_connectors_non_api_api_key']),
 'OAuth/untrusted option names must not enter the API key option registry');
$before=count($GLOBALS['filters']);
$c::discover();
native_assert(count($GLOBALS['filters'])===$before,'Re-discovery must not add duplicate filters');
$baseline_count=count($GLOBALS['filters']);
foreach([false,'broken',new stdClass(),'throw',
 [['authentication'=>new stdClass()],null,['authentication'=>new WP_Error('oops')]]] as $bad_source){
 $GLOBALS['connector_rows']=$bad_source;
 $c::discover();
 native_assert(count($GLOBALS['filters'])===$baseline_count,
  'Untrusted or unavailable original dynamic connector listing cannot register option writers');
}
$reflection=new ReflectionProperty($c,'key');
if(PHP_VERSION_ID<80100)$reflection->setAccessible(true);
$reflection->setValue(null,random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES));
$cipher=$c::seal('connectors_ai_openai_api_key',$original);
native_assert(is_string($cipher) && str_starts_with($cipher,$prefix) && $cipher!==$original,
 'With a hermetic 32-byte key the original XChaCha20 encryption must yield an opaque envelope');
native_assert($c::unseal('connectors_ai_openai_api_key',$cipher)===$original,
 'Only exact original option name and WordPress blog can decrypt');
native_assert($c::unseal('connectors_ai_google_api_key',$cipher)===false,
 'Authenticated option AAD rejects a different connector key');
$GLOBALS['blog_id']=8;
native_assert($c::unseal('connectors_ai_openai_api_key',$cipher)===false,
 'WordPress multisite identity cannot cross-decrypt the original option');
$GLOBALS['blog_id']=3;
$raw=base64_decode(substr($cipher,strlen($prefix)),true);
$raw[25]=chr(ord($raw[25]) ^ 1);
native_assert($c::unseal('connectors_ai_openai_api_key',$prefix.base64_encode($raw))===false,
 'Ciphertext tamper cannot bypass original AEAD integrity');
$reflection->setValue(null,null);
$view=$c::status();
native_assert($view['secret_value_exposed']===false
 &&$view['runtime_php_can_read_key']===false
 &&$view['registered_count']>=7
 &&$view['db_only_exposure_mitigated']===false,
 'Vault status states key-unavailable limitations, never leaks secret values');
echo 'PASS: CORE_CONNECTOR_VAULT '.($GLOBALS['cases']??0).
 ' no-key, dynamic connector and native persistence-denial cases'.PHP_EOL;
