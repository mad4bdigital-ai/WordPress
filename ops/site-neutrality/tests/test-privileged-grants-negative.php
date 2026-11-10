<?php
/**
 * Read-only proof of developer operation admission. Execute via WP-CLI on an
 * enrolled site. No grants, files, WordPress Options, or posts are mutated.
 */
if (!defined('WP_CLI') || !WP_CLI) throw new RuntimeException('WPCLI_REQUIRED');
$required=['MAD4B_SCP_Developer_Runtime','MAD4B_SCP_Developer_Workspace','MAD4B_SCP_Agent_Grant_Lifecycle','MAD4B_SCP_Developer_Authority','MAD4B_SCP_Site_Profile'];
foreach($required as $class) if(!class_exists($class,false)) throw new RuntimeException('MISSING_REQUIRED_RUNTIME_'.$class);
$profile=MAD4B_SCP_Site_Profile::status();
$managed=MAD4B_SCP_Site_Profile::managed_runtime_enabled();
$writable=MAD4B_SCP_Site_Profile::write_enabled();
$runtime=(new ReflectionMethod('MAD4B_SCP_Developer_Runtime','runtime_gate'))->invoke(null,false,[],false);
$workspace=(new ReflectionMethod('MAD4B_SCP_Developer_Workspace','mutation_gate'))->invoke(null,[],false);
if (!$managed || !$writable || empty($profile['authority_ready'])) {
 if(!is_wp_error($runtime)||$runtime->get_error_code()!=='mad4b_developer_site_authority_required')throw new RuntimeException('UNMANAGED_DEVELOPER_EXECUTION_NOT_REJECTED');
 if(!is_wp_error($workspace)||$workspace->get_error_code()!=='mad4b_developer_workspace_site_authority_required')throw new RuntimeException('UNMANAGED_WORKSPACE_MUTATION_NOT_REJECTED');
} else {
 if(is_wp_error($runtime)&&$runtime->get_error_code()==='mad4b_developer_site_authority_required')throw new RuntimeException('MANAGED_SITE_FALSE_DENIAL');
 if(is_wp_error($workspace)&&$workspace->get_error_code()==='mad4b_developer_workspace_site_authority_required')throw new RuntimeException('MANAGED_WORKSPACE_FALSE_DENIAL');
}
if(MAD4B_SCP_Agent_Grant_Lifecycle::owner())throw new RuntimeException('CLI_GRANTED_BROWSER_ONLY_CAPABILITY');
$operator=class_exists('MAD4B_SCP_Operator_Grant_Convergence',false)?MAD4B_SCP_Operator_Grant_Convergence::can_access([]):new WP_Error('operator_not_loaded');
if(!is_wp_error($operator))throw new RuntimeException('CLI_OPERATOR_GRANT_CAPABILITY');
WP_CLI::line(wp_json_encode(['state'=>'PASS','site'=>home_url(),'environment_label'=>wp_get_environment_type(),'authority_ready'=>!empty($profile['authority_ready']),'managed_runtime'=>$managed,'write_enabled'=>$writable,'developer_gate'=>is_wp_error($runtime)?$runtime->get_error_code():'pass','workspace_gate'=>is_wp_error($workspace)?$workspace->get_error_code():'pass','anonymous_cli_agent_grant_denied'=>true,'operator_grant_denial'=>$operator->get_error_code()]));
