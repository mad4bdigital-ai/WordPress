<?php
define('ABSPATH','/tmp/');define('MAD4B_CORE_CONNECTOR_VAULT',true);
function get_current_blog_id(){return 1;}
function add_filter($name,$cb,$priority=10,$args=1){return true;}
function add_action($name,$cb,$priority=10,$args=1){return true;}
require __DIR__.'/../wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-core-connector-vault.php';
function check($v,$why){if(!$v)throw new Exception($why);}
$c='MAD4B_SCP_Core_Connector_Vault';
$c::boot();
$old='stored-previous-value';
check($c::enabled()===true,'explicit vault opt-in not honored');
check($c::seal('invalid_name','secret')===false,'invalid option allowed encryption');
check($c::encode_option('connectors_ai_openai_api_key','rotated-secret',$old)===$old,'key-unavailable secret persisted in plaintext');
check($c::encode_option('connectors_ai_openai_api_key','••••••',$old)===$old,'masked secret rotated');
check($c::decode_option('connectors_ai_openai_api_key','MAD4B$X1$badcipher')==='','malformed ciphertext exposed');
$status=$c::status();
check($status['secret_value_exposed']===false&&$status['storage']==='native_wp_options_ciphertext','secret disclosure declared');
echo "CORE_CONNECTOR_VAULT_DENIAL_RUNTIME PASS no plaintext on absent key and masked credential preservation\n";
