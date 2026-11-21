<?php
/**
 * MAD4B PERMANENT MCP AUTOLOADER-SCOPE REGRESSION
 *
 * Run in two separate WP-CLI processes, never in one (PHP classes cannot be
 * unloaded). Expected input comes from the original documented MU CLI opt-in:
 *
 *   wp eval-file wp-content/plugins/mad4b-site-control-plane/tests/mcp-request-scope-provenance-regression.php --path=/var/www/html --skip-themes --user=1
 *   MAD4B_SCP_MCP_CLI_REQUEST=1 wp eval-file wp-content/plugins/mad4b-site-control-plane/tests/mcp-request-scope-provenance-regression.php --path=/var/www/html --skip-themes --user=1
 *
 * Scope = 0: Rank Math's embedded MCP namespace may load on ordinary
 * WordPress requests. This is NOT a MAD4B MCP endpoint failure.
 * Scope = 1: original MU bootstrap MUST pin the standalone official Adapter
 * before third-party packages and MUST negotiate 2026-07-28 (if installed).
 *
 * Read-only: no site settings, plugin load order, grants or adapters changed.
 */
defined('ABSPATH') || exit;
$check = static function(bool $ok,string $reason):void {
    if (!$ok) WP_CLI::error('MAD4B_MCP_SCOPE_REGRESSION_FAIL_'.$reason);
};
$expected = getenv('MAD4B_SCP_MCP_CLI_REQUEST')==='1';
$scope = MAD4B_SCP_MCP_Protocol_Profile::request_scope_evidence();
$profile = MAD4B_SCP_MCP_Protocol_Profile::status();
$mu = $GLOBALS['mad4b_scp_mcp_mu_bootstrap']??[];
$check($scope['canonical_mcp_request']===$expected,'DETECTION_MISMATCH');
$check($scope['authorizing']===false,'SCOPE_MUST_NOT_AUTHORIZE');
$check($profile['authorizing']===false,'PROFILE_MUST_NOT_AUTHORIZE');
$check($profile['canonical_mcp_runtime_evaluated']===$expected,
    'PROTOCOL_SCOPE_REPORTED_WRONG');
// A changed read-only guard state must not give a false negative on the real
// scoped runtime, nor certify an ordinary Rank Math WordPress request.
$node=MAD4B_SCP_MCP_Runtime_Recovery::node_evidence();
$check((bool)$node['runtime_execution_verified']===$expected,
    'NODE_RUNTIME_READBACK_SCOPE_MISMATCH');
$check(($node['runtime_execution_verification_source']??'')===($expected
    ?'canonical_mu_pin_plus_complete_class_provenance_and_exact_hash'
    :'not_verified_for_this_request'),'NODE_READBACK_PROOF_MISMATCH');
$check($node['runtime_node_scope']==='current_node','NODE_SCOPE_NOT_LOCAL');

$check($profile['request_scope']['scope_bypassed']===!$expected,
    'SCOPE_BYPASS_MISMATCH');
$check(!empty($profile['ready']),'COMMON_PROTOCOL_NOT_READY');
$check(in_array('2025-11-25',$profile['effective_protocol_versions'],true),
    'BASELINE_PROTOCOL_MISSING');
$adapter='WP\\MCP\\Core\\McpAdapter';
$negotiator='WP\\MCP\\Core\\McpVersionNegotiator';
$server='WP\\MCP\\Core\\McpServer';
$root=trailingslashit(wp_normalize_path(WP_PLUGIN_DIR)).'mcp-adapter/';
$origin=[];
foreach([$adapter,$negotiator,$server] as $class){
    $check(class_exists($class),'CLASS_MISSING');
    $ref=new ReflectionClass($class);
    $origin[$class]=wp_normalize_path((string)$ref->getFileName());
}
if($expected){
    $check($scope['canonical_bootstrap_verified'],'CANONICAL_MU_NOT_PINNED');
    $check($profile['canonical_mcp_ready']===true,'CANONICAL_PROTOCOL_NOT_READY');
    $check(!empty($mu['critical_class_set_pinned']),'CRITICAL_CLASSES_UNPINNED');
    $check(!empty($mu['adapter_init_hook_bound']),'ADAPTER_HOOK_MISSING');
    foreach($origin as $file)$check(str_starts_with($file,$root),'CLASS_FROM_THIRD_PARTY');
    $provenance=MAD4B_SCP_MCP_Class_Provenance::status(true,false);
    $check(!empty($provenance['ready']) && empty($provenance['mixed_runtime']),
        'MIXED_CLASSES_IN_MCP_SCOPE');
    $check(in_array('2026-07-28',$profile['effective_protocol_versions'],true),
        'MCP_2026_REVISION_UNAVAILABLE');
    $modern=MAD4B_SCP_MCP_Protocol_Profile::negotiate('2026-07-28');
    $legacy=MAD4B_SCP_MCP_Protocol_Profile::negotiate('2025-11-25');
    $unknown=MAD4B_SCP_MCP_Protocol_Profile::negotiate('2099-01-01');
    $check(!is_wp_error($modern) && $modern['protocol_version']==='2026-07-28',
        'ACTUAL_2026_NEGOTIATION_UNAVAILABLE');
    $check(!is_wp_error($legacy) && $legacy['protocol_version']==='2025-11-25',
        'LEGACY_NEGOTIATION_REGRESSED');
    $check(is_wp_error($unknown),'UNKNOWN_PROTOCOL_MUST_NOT_AUTO_ADOPT');
    $check($modern['authorizing']===false,'NEGOTIATION_CANNOT_GRANT_WRITES');
}else{
    $check($profile['canonical_mcp_ready']===null,
        'UNSCOPED_PROVENANCE_MUST_BE_UNEVALUATED');
    $check($scope['source']==='non_mcp_request',
        'NON_MCP_REQUEST_SCOPE_MARKER_MISSING');
}
WP_CLI::line(wp_json_encode([
    'contract'=>'mad4b.mcp-request-scope-provenance-regression.v1',
    'result'=>'PASS',
    'canonical_mcp_scope'=>$expected,
    'scope_state'=>$scope['bootstrap_state'],
    'canonical_mcp_ready'=>$profile['canonical_mcp_ready'],
    'effective_protocol_versions'=>$profile['effective_protocol_versions'],
    'class_sources'=>$origin,
    'mutation_performed'=>false,
    'write_authority_effect'=>'none'
]));
