<?php
/**
 * The early MCP logic has ONE authoritative source in MAD4B Control Plane.
 * This source-owned bridge preserves original MU loading order and invokes
 * that original implementation, never introducing a second MCP adapter.
 */
if (!defined('ABSPATH')) exit;
$mad4b_mcp_main='mad4b-site-control-plane.php';
$mad4b_mcp_active=get_option('active_plugins',[]);
$mad4b_mcp_active=is_array($mad4b_mcp_active)?$mad4b_mcp_active:[];
$mad4b_mcp_sources=[];
foreach($mad4b_mcp_active as $mad4b_mcp_relative){
 if(!is_string($mad4b_mcp_relative)||basename($mad4b_mcp_relative)!==$mad4b_mcp_main)continue;
 if(str_contains($mad4b_mcp_relative,'..')||str_starts_with($mad4b_mcp_relative,'/'))continue;
 $mad4b_mcp_candidate=WP_PLUGIN_DIR.'/'.dirname($mad4b_mcp_relative).'/bootstrap/mad4b-mcp-adapter-mu-bootstrap.php';
 if(is_file($mad4b_mcp_candidate))$mad4b_mcp_sources[$mad4b_mcp_relative]=$mad4b_mcp_candidate;
}
// If the Plugin is disabled, the early gate must not grant runtime privileges.
// No fallback search for an unrelated file or a privileged plugin class.
if(count($mad4b_mcp_sources)===1){
 require_once reset($mad4b_mcp_sources);
}else{
 $GLOBALS['mad4b_scp_mcp_mu_bootstrap']=[
  'contract'=>'mad4b.mcp-adapter-mu-bootstrap.v6','executed'=>true,
  'state'=>count($mad4b_mcp_sources)>1?'ambiguous_canonical_control_plane':'canonical_control_plane_unavailable',
  'eligible'=>false,'request_requires_mcp_runtime'=>false,'adapter_instance_armed'=>false,
  'request_scope_bypassed'=>true,'transaction_pending'=>false,
 ];
}
unset($mad4b_mcp_main,$mad4b_mcp_active,$mad4b_mcp_sources,$mad4b_mcp_relative,$mad4b_mcp_candidate);
