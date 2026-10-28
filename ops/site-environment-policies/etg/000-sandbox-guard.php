<?php
/* Plugin Name: ETG Sandbox Guard */
if (!defined('ABSPATH')) exit;
// Early MU policy uses the native HOST environment, before a Site Profile can
// load. Explicit Production is excluded; implicit default Production is NOT a
// proof of a Production host and is quarantined until host bootstrap aligns.
if (!function_exists('wp_get_environment_type')) return;
$mad4b_raw_env = (string) wp_get_environment_type();
$mad4b_host_env = defined('WP_ENVIRONMENT_TYPE') ? (string) constant('WP_ENVIRONMENT_TYPE') : '';
if ($mad4b_host_env === '' && function_exists('getenv')) {
    $mad4b_host_env = (string) (getenv('WP_ENVIRONMENT_TYPE') ?: '');
}
$mad4b_host_explicit = in_array($mad4b_host_env, array('local','development','staging','production'), true);
// An explicit, matching Production host does not receive any lab hooks.
if ($mad4b_host_explicit && $mad4b_raw_env === 'production'
    && $mad4b_host_env === 'production') return;
// Missing or contradictory host evidence is NOT a signal to run as Production.
$mad4b_sandbox_env = (!$mad4b_host_explicit || $mad4b_host_env !== $mad4b_raw_env)
    ? 'unverified_host' : $mad4b_raw_env;
if (!in_array($mad4b_sandbox_env,
        array('local','development','staging','unverified_host'), true)) return;

// Local, Development and an unverified host keep mail/Cron isolated. Staging instead
// uses the approved Company Communication + Fluent SMTP mail gate and its
// DISABLE_WP_CRON configuration / governed scheduler.
if (in_array($mad4b_sandbox_env, array('local','development','unverified_host'), true)) {
    add_filter('pre_wp_mail', static function () { return false; }, PHP_INT_MAX);
    add_filter('cron_request', static function () { return false; }, PHP_INT_MAX);
}

/**
 * Test-environment outbound destination policy: preserve private-destination
 * deny and two strictly Local-only exceptions:
 * (1) internal delegated AI review handshake; (2) only the explicitly enabled
 * and credential-ready model provider route. All other HTTP remains blocked.
 * This is a test-environment MU override, never a Production policy.
 */
add_filter('pre_http_request',static function($pre,$args,$url) use ($mad4b_sandbox_env) {
    // Never override an upstream rejection or a provider's intercepted response.
    if ($mad4b_sandbox_env !== 'unverified_host' && false !== $pre) return $pre;
    $block=new WP_Error('etg_sandbox_network_blocked','Outbound HTTP blocked in sandbox');
    // An unverified or contradictory host cannot authorize a network request. Host
    // bootstrap must explicitly declare Local/Development/Staging/Production.
    if ($mad4b_sandbox_env === 'unverified_host') return $block;
    $method=strtoupper((string)($args['method']??'GET'));
    $headers=$args['headers']??array();
    $headers=is_array($headers)?$headers:array();
    $get_header=static function($name) use($headers) {
        foreach($headers as $k=>$v)if(strtolower((string)$k)===strtolower($name))return (string)$v;
        return '';
    };
    $approved_local_reviews=array(
      'http://etg-woo-lab-nginx:8080/wp-json/mad4b-local-ai/v1/context-review',
      'http://etg-woo-lab-nginx:8080/wp-json/mad4b-local-ai/v1/domain-review');
    if($mad4b_sandbox_env === 'local' && in_array((string)$url,$approved_local_reviews,true)) {
        if($method!=='POST')return $block;
        if($get_header('Host')==='localhost:8091' &&
           strncmp($get_header('Authorization'),'Basic ',6)===0 &&
           defined('MAD4B_LOCAL_CLIENT_PROVISION_ENABLED') && MAD4B_LOCAL_CLIENT_PROVISION_ENABLED)
            return false;
        return $block;
    }
    // Exact in-namespace open model for the authorized Local Lab only.
    // The model server binds 127.0.0.1 inside the PHP network namespace,
    // has no published Docker port, and has no external network egress.
    if($mad4b_sandbox_env === 'local' && (string)$url==='http://127.0.0.1:11434/api/chat') {
        if($method!=='POST')return $block;
        if(!class_exists('MAD4B_SCP_AI_Model_Router'))return $block;
        $cfg=MAD4B_SCP_AI_Model_Router::settings();
        if(($cfg['mode']??'')!=='local_ollama' || empty($cfg['enabled'])
           ||($cfg['model']??'')!==MAD4B_SCP_AI_Model_Router::LOCAL_OLLAMA_MODEL
           || !hash_equals('application/json',strtolower(trim($get_header('Content-Type')))))
           return $block;
        $body=$args['body']??null;
        $data=is_string($body) && strlen($body)<65000?json_decode($body,true):null;
        if(!is_array($data)||($data['model']??'')!==MAD4B_SCP_AI_Model_Router::LOCAL_OLLAMA_MODEL
           ||!empty($data['stream']) ||!is_array($data['messages']??null)
           ||count($data['messages'])!==1)return $block;
        // Syntax is insufficient: a caller could POST a valid JSON payload
        // without native Agent/owner admission or core token metering.
        // Bind this transport to the exact Router-created request body.
        if(!method_exists('MAD4B_SCP_AI_Model_Router','local_ollama_transport_permitted')
           || !MAD4B_SCP_AI_Model_Router::local_ollama_transport_permitted($method,$body))
           return new WP_Error('etg_sandbox_ollama_governance_required',
             'Ollama HTTP requires a governed MAD4B Router admission.');
        return false;
    }

    // Validated public network egress for explicit non-Production test hosts. Keep private/metadata
    // addresses unavailable to ordinary WordPress HTTP requests.
    $parts=wp_parse_url((string)$url);
    if (!is_array($parts) || !in_array(strtolower((string)($parts['scheme']??'')),array('http','https'),true)
        || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
        || isset($parts['fragment'])) return $block;
    $host=strtolower(rtrim((string)$parts['host'],'.'));
    if ($host==='localhost' || str_ends_with($host,'.localhost') || str_ends_with($host,'.local')
        || str_ends_with($host,'.internal') || str_ends_with($host,'.test')) return $block;
    if (filter_var($host,FILTER_VALIDATE_IP)) $ips=array($host);
    else $ips=function_exists('gethostbynamel')?@gethostbynamel($host):false;
    if (!is_array($ips) || !$ips) return $block;
    foreach ($ips as $ip)
        if (!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))
            return $block;
    return false; // WordPress retains native HTTP/SSL validation, redirects and timeouts.
}, PHP_INT_MAX,3);
