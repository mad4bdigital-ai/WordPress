<?php
/** Local-only AI review diagnostics using authoritative native MAD4B policy. */
if (!defined('ABSPATH') || wp_get_environment_type() !== 'local') return;
function local_ai_review_diagnostics() {
 $policy=class_exists('MAD4B_SCP_Context_Authority')?MAD4B_SCP_Context_Authority::review_policy():[];
 $status=class_exists('MAD4B_SCP_Context_Authority')?MAD4B_SCP_Context_Authority::ai_review_policy_status():[];
 $site=class_exists('MAD4B_SCP_Site_Profile')?MAD4B_SCP_Site_Profile::status():[];
 $errors=is_array($status)&&isset($status['blockers'])&&is_array($status['blockers'])?array_values(array_filter($status['blockers'],'is_string')):[];
 return ['environment'=>wp_get_environment_type(),'site_identity_verified'=>!empty($site['origin_match'])&&!empty($site['environment_match'])&&!empty($site['deployment_binding_bound'])&&!empty($site['deployment_binding_match']),
  'review_mode'=>sanitize_key((string)($policy['mode']??'unknown')),'agent_configured'=>!empty($policy['ai_agent_public_id']),
  'agent_authorized'=>empty($errors)&&!empty($status['ready']),'blockers'=>$errors,
  'qa_browser_is_ai_agent'=>false,'native_ai_approval_available'=>empty($errors)&&!empty($status['ready'])];
}
add_action('admin_notices',static function() {
 if(!is_admin() || ($_GET['page']??'')!=='shared-mad4b-media' || !current_user_can('manage_options'))return;
 $d=local_ai_review_diagnostics();
 echo '<div class="notice notice-warning"><p><strong>Native MAD4B AI Approval: '.($d['native_ai_approval_available']?'Ready':'Blocked').'</strong></p><p>Environment: '.esc_html($d['environment']).' | Policy: '.esc_html($d['review_mode']).' | Verified Site: '.($d['site_identity_verified']?'Yes':'No').'</p>';
 if($d['blockers'])echo '<p>Required remediation: <code>'.esc_html(implode(' ; ',$d['blockers'])).'</code></p>';
 echo '<p>QA browser login grants WordPress access only. It does not create an OAuth Agent identity or native Context AI Review grant.</p></div>';
});
