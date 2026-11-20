<?php
/** Hermetic signed-bearer identity test, no token issuance, no WP writes. */
if (!defined('ABSPATH')) define('ABSPATH',__DIR__.'/');
if (!defined('MAD4B_MCP_OAUTH_MODE')) define('MAD4B_MCP_OAUTH_MODE','external');
if (!defined('MAD4B_MCP_OAUTH_ISSUER')) define('MAD4B_MCP_OAUTH_ISSUER','https://issuer.example.test');
function wp_parse_url($value,$component=-1){return parse_url($value,$component);}
function sanitize_key($value){return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$value));}
function wp_set_current_user($uid){$GLOBALS['cimd_user_clear']=(int)$uid;}
function cimd_assert($ok,$label){if (!$ok){fwrite(STDERR,"FAIL CIMD IDENTITY: ".$label."\n");exit(1);}}
require dirname(__DIR__).'/includes/class-mad4b-scp-local-oauth-server.php';
require dirname(__DIR__).'/includes/class-mad4b-scp-oauth-resource-bridge.php';
$c='MAD4B_SCP_OAuth_Resource_Bridge';
$legacy=MAD4B_SCP_Local_OAuth_Server::CHATGPT_CIMD_CLIENT_ID;
$scoped='https://chatgpt.com/oauth/connector_example_123456/client.json';
$issuer=MAD4B_MCP_OAUTH_ISSUER;
$context=new ReflectionProperty($c,'verified_context');
$client=new ReflectionProperty($c,'verified_client_id');
if(PHP_VERSION_ID<80100){$context->setAccessible(true);$client->setAccessible(true);}
$identity=function($actual,$fingerprint_client,$authenticated=true,$auth_method='oauth2_bearer',$fissuer=null)
        use($context,$client,$issuer) {
    $fissuer=$fissuer===null?$issuer:$fissuer;
    $context->setValue(null,array('authenticated'=>$authenticated,'auth_method'=>$auth_method,
        'client_fingerprint'=>hash('sha256','oauth-client'."\0".$fissuer."\0".$fingerprint_client),
        'token_scopes'=>array('mad4b:read')));
    $client->setValue(null,$actual);
};
cimd_assert($c::trusted_issuers()===array($issuer),'Only exact external issuer trusted');
$identity($scoped,$scoped);
cimd_assert($c::verified_bearer_trusted_chatgpt_client_id()===$scoped,
    'Signed and fingerprint-bound scoped client admitted');
cimd_assert(!$c::verified_bearer_client_is($legacy),'Scoped client must not impersonate legacy client');
$identity($legacy,$legacy);
cimd_assert($c::verified_bearer_trusted_chatgpt_client_id()===$legacy,'Legacy signed identity remains valid');
$identity($scoped,$legacy);
cimd_assert($c::verified_bearer_trusted_chatgpt_client_id()==='','Mismatched scoped client claim denied');
$identity('https://evil.example/client.json','https://evil.example/client.json');
cimd_assert($c::verified_bearer_trusted_chatgpt_client_id()==='','Foreign client denied even if fingerprint agrees');
$identity($scoped,$scoped,false);
cimd_assert($c::verified_bearer_trusted_chatgpt_client_id()==='','Unauthenticated request denied');
$identity($scoped,$scoped,true,'cookie');
cimd_assert($c::verified_bearer_trusted_chatgpt_client_id()==='','Cookie identity is not signed bearer');
$identity($scoped,$scoped,true,'oauth2_bearer','https://wrong-issuer.test');
cimd_assert($c::verified_bearer_trusted_chatgpt_client_id()==='','Issuer substitution denied');
$identity($scoped,$scoped);
$c::reset_verified_bearer_context(true);
cimd_assert($c::verified_bearer_trusted_chatgpt_client_id()===''
    && $client->getValue() === ''
    && ($GLOBALS['cimd_user_clear']??-1)===0,
    'Verified client and WordPress user identity reset together');
echo "CIMD_SCOPED_SIGNED_BEARER_IDENTITY: PASS (legacy, scoped, mismatch, foreign, issuer, reset)\n";
// Preserve the detailed evidence above and publish a strict machine-readable
// success marker only after all positive and negative identity checks pass.
echo "PASS: signed scoped CIMD bearer, legacy and foreign-client denials\n";
