<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Exact, expiring owner batches. No wildcard grants and no Staging authority carry. */
final class MAD4B_SCP_Production_Automation {
 const OPTION = 'mad4b_scp_production_automation_v1';
 const ACTION = 'mad4b_production_automation_save';
 const CRON = 'mad4b_production_automation_run';
 const LOCK = 'mad4b_scp_production_automation_lock';
 const MAX_ITEMS = 20;
 private static $deployment = null;
 private static $deployment_batch_id = null;

 public static function boot() {
  add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_save' ) );
  add_action( self::CRON, array( __CLASS__, 'run_deployments' ) );
 }
 public static function is_list( array $value ) { return ! $value || array_keys( $value ) === range( 0, count( $value ) - 1 ); }
 public static function digest( $value ) {
  if ( is_array( $value ) ) { if ( ! self::is_list( $value ) ) ksort( $value, SORT_STRING ); foreach ( $value as &$v ) $v = self::canonical( $v ); unset( $v ); }
  return hash( 'sha256', wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
 }
 private static function canonical( $v ) {
  if ( ! is_array( $v ) ) return $v;
  if ( ! self::is_list( $v ) ) ksort( $v, SORT_STRING );
  foreach ( $v as &$item ) $item = self::canonical( $item ); unset( $item ); return $v;
 }
 /** Refresh revocable DB authority at commit boundaries, not only at request start. */
 public static function refresh_authority() {
  foreach ( array( self::OPTION, MAD4B_SCP_Governed_Runtime_Gates::OPTION, 'mad4b_scp_test_attestation_trust_v1' ) as $key ) wp_cache_delete( $key, 'options' );
  if ( defined( 'MAD4B_SCP_Site_Profile::OPTION' ) ) wp_cache_delete( MAD4B_SCP_Site_Profile::OPTION, 'options' );
  wp_cache_delete( 'alloptions', 'options' );
  if ( method_exists( 'MAD4B_SCP_Site_Profile', 'reset_cache' ) ) MAD4B_SCP_Site_Profile::reset_cache();
 }
 public static function policy() { $p = get_option( self::OPTION, array() ); return is_array( $p ) ? $p : array(); }
 private static function binding() {
  return array( 'site_uuid' => MAD4B_SCP_Site_Profile::site_uuid(), 'revision' => MAD4B_SCP_Site_Profile::revision(), 'digest' => MAD4B_SCP_Site_Profile::profile_digest() );
 }
 public static function active() {
  $p = self::policy();
  if ( ! function_exists( 'user_can' ) || ! function_exists( 'get_userdata' ) ) return false;
  if ( empty( $p['enabled'] ) || ! isset( $p['expires_at'], $p['binding'], $p['owner'], $p['id'] )
   || ! is_int( $p['expires_at'] ) || $p['expires_at'] <= time() || $p['expires_at'] > time() + 7 * DAY_IN_SECONDS
   || 'production' !== MAD4B_SCP_Site_Profile::current_environment()
   || ! MAD4B_SCP_Site_Profile::origin_enrolled() || ! MAD4B_SCP_Site_Profile::site_urls_match_enrollment()
   || self::binding() !== $p['binding'] || ! MAD4B_SCP_Governed_Runtime_Gates::production_mutation_enabled()
   || ! MAD4B_SCP_Site_Profile::user_is_enrolled( (int) $p['owner'] )
   || ! user_can( (int) $p['owner'], 'manage_options' )
   || ( defined( 'MAD4B_MCP_MUTATION_ENABLED' ) && true !== MAD4B_MCP_MUTATION_ENABLED )
   || empty( MAD4B_SCP_Audit::storage_status()['ready'] )
   || MAD4B_SCP_Governed_Runtime_Gates::raw_sql_breakglass_enabled() ) return false;
  return true;
 }
 public static function agent_id() { $p = self::policy(); return self::active() ? (string) ( $p['agent_public_id'] ?? '' ) : ''; }
 private static function claim_key( $id, $kind, $index ) { return 'mad4b_prod_claim_' . hash( 'sha256', $id . ':' . $kind . ':' . $index ); }
 public static function approval_index( $ability, $provider, $input ) {
  if ( ! self::active() || ! is_array( $input ) ) return false;
  foreach ( (array) ( self::policy()['approvals'] ?? array() ) as $i => $rule ) {
   if ( $rule['ability'] === $ability && $rule['provider'] === $provider && hash_equals( $rule['input_sha256'], self::digest( $input ) )
    && false === get_option( self::claim_key( self::policy()['id'], 'approval', $i ), false ) ) return $i;
  }
  return false;
 }
 public static function approval_allowed( $ability, $provider, $input ) { return false !== self::approval_index( $ability, $provider, $input ); }
 public static function claim_approval( $ability, $provider, $input, $ticket ) {
  self::refresh_authority();
  $p = self::policy(); $i = self::approval_index( $ability, $provider, $input );
  if ( false === $i || ( self::policy()['id'] ?? '' ) !== ( $p['id'] ?? '' ) ) return false;
  if ( ! add_option( self::claim_key( $p['id'], 'approval', $i ), array( 'ticket' => $ticket, 'at' => time() ), '', false ) ) return false;
  $binding = array( 'batch_id' => $p['id'], 'index' => $i, 'ability' => $ability, 'provider' => $provider, 'input_sha256' => self::digest( $input ) );
  self::refresh_authority();
  return self::active() && ( self::policy()['id'] ?? '' ) === $p['id']
   && add_option( 'mad4b_prod_ticket_' . hash( 'sha256', $ticket ), $binding, '', false );
 }
 public static function ticket_delegation_valid( array $ticket ) {
  if ( 'production' !== ( $ticket['binding_environment'] ?? '' ) || (int) ( $ticket['approved_by'] ?? -1 ) !== 0 ) return true;
  self::refresh_authority();
  if ( ! self::active() ) return false;
  $b = get_option( 'mad4b_prod_ticket_' . hash( 'sha256', $ticket['ticket_id'] ), false );
  return is_array( $b ) && $b['batch_id'] === self::policy()['id']
   && $b['ability'] === $ticket['ability_name'] && $b['provider'] === $ticket['provider'];
 }
 public static function deployment_planning_allowed( $plugin ) {
  if ( 'production' !== MAD4B_SCP_Site_Profile::current_environment() || ! MAD4B_SCP_Governed_Runtime_Gates::production_mutation_enabled() ) return false;
  if ( MAD4B_SCP_Governed_Runtime_Gates::admin_owner_access() || true === MAD4B_SCP_Governed_Runtime_Gates::can_access() ) return true;
  if ( ! self::active() ) return false;
  foreach ( (array) ( self::policy()['deployments'] ?? array() ) as $job ) if ( $job['input']['plugin_file'] === $plugin ) return true;
  return false;
 }
 public static function deployment_authorized( $input ) {
  return self::active() && ( self::policy()['id'] ?? '' ) === self::$deployment_batch_id && is_array( self::$deployment ) && is_array( $input )
   && hash_equals( self::digest( self::$deployment ), self::digest( $input ) );
 }
 /** Save is local owner consent, attributed as such; it never mints NHI grants. */
 public static function save_batch( $input, $expected_digest ) {
  if ( ! MAD4B_SCP_Governed_Runtime_Gates::admin_owner_access() ) return new WP_Error( 'mad4b_prod_owner_required', 'Exact Production owner required.' );
  if ( ! is_array( $input ) || ! is_string( $expected_digest ) || ! hash_equals( self::digest( self::policy() ), $expected_digest ) ) return new WP_Error( 'mad4b_prod_batch_stale', 'Production batch changed; reload before saving.' );
  if ( ( $input['confirmation'] ?? '' ) !== 'AUTHORIZE EXACT PRODUCTION BATCH' ) return new WP_Error( 'mad4b_prod_confirmation_required', 'Exact Production batch consent required.' );
  $enabled = ( $input['enabled'] ?? false ) === true;
  $hours = $input['hours'] ?? 24;
  $agent = $input['agent_public_id'] ?? '';
  $approvals = $input['approvals'] ?? array(); $deployments = $input['deployments'] ?? array();
  if ( ! is_int( $hours ) || $hours < 1 || $hours > 168 || ! is_array( $approvals ) || ! self::is_list( $approvals ) || ! is_array( $deployments ) || ! self::is_list( $deployments ) || count( $approvals ) + count( $deployments ) > self::MAX_ITEMS ) return new WP_Error( 'mad4b_prod_batch_invalid', 'Batch limit is 20 exact operations and 1–168 hours.' );
  if ( $enabled && ! MAD4B_SCP_Governed_Runtime_Gates::production_auto_enable() ) return new WP_Error( 'mad4b_prod_runtime_required', 'Enable automatic Production runtime first.' );
  if ( $approvals && ( ! is_string( $agent ) || ! preg_match( '/^[a-f0-9-]{36}$/', $agent ) ) ) return new WP_Error( 'mad4b_prod_agent_required', 'An exact AI Agent public ID is required.' );
  $rules = array(); $jobs = array(); $seen = array();
  foreach ( $approvals as $rule ) {
   if ( ! is_array( $rule ) || ! in_array( $rule['ability'] ?? '', array( 'mad4b/content-create-post', 'mad4b/content-update-post', 'mad4b/content-apply-bundle' ), true ) || ( $rule['provider'] ?? '' ) !== 'core' || ! is_array( $rule['input'] ?? null ) ) return new WP_Error( 'mad4b_prod_approval_rule_invalid', 'Only exact governed core content operations are supported in this batch.' );
   $r = array( 'ability' => $rule['ability'], 'provider' => 'core', 'input_sha256' => self::digest( $rule['input'] ) );
   $key = self::digest( $r ); if ( isset( $seen[ $key ] ) ) return new WP_Error( 'mad4b_prod_duplicate', 'Duplicate batch operation.' ); $seen[ $key ] = true; $rules[] = $r;
  }
  foreach ( $deployments as $job ) {
   if ( ! is_array( $job ) || ! is_array( $job['input'] ?? null ) || ! is_array( $job['input']['test_attestation'] ?? null ) ) return new WP_Error( 'mad4b_prod_deployment_invalid', 'Each deployment needs exact input and a trusted signed test certificate.' );
   $operation = $job['input'];
   if ( array_diff( array_keys( $operation ), array( 'plugin_file', 'reason', 'expected_plan_sha256', 'test_attestation' ) ) || ! is_string( $operation['expected_plan_sha256'] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/', $operation['expected_plan_sha256'] ) ) return new WP_Error( 'mad4b_prod_deployment_invalid', 'Deployment must identify an exact current server-resolved plan.' );
   $plan = MAD4B_SCP_Remote_Plugin_Update::plan( $operation );
   if ( is_wp_error( $plan ) || empty( $plan['eligible'] ) || ! hash_equals( $plan['plan_sha256'], $operation['expected_plan_sha256'] ) ) return new WP_Error( 'mad4b_prod_deployment_stale', 'Deployment plan must be eligible and unchanged at owner approval.' );
   $key = 'plugin:' . $plan['plugin_file']; if ( isset( $seen[ $key ] ) ) return new WP_Error( 'mad4b_prod_duplicate', 'Only one deployment per plugin is permitted.' ); $seen[ $key ] = true;
   $jobs[] = array( 'input' => $operation );
  }
  $next = array( 'enabled' => $enabled, 'id' => wp_generate_uuid4(), 'owner' => get_current_user_id(), 'binding' => self::binding(), 'expires_at' => time() + $hours * HOUR_IN_SECONDS, 'agent_public_id' => $agent, 'approvals' => $rules, 'deployments' => $jobs );
  if ( empty( MAD4B_SCP_Audit::storage_status()['ready'] ) || is_wp_error( MAD4B_SCP_Audit::record( 'mad4b/production-batch-owner-consent', array( 'policy_digest' => self::digest( $next ), 'owner' => $next['owner'], 'binding' => $next['binding'], 'approval_rules' => $rules, 'deployments' => $jobs, 'expires_at' => $next['expires_at'], 'nhi_grants_created' => false ), 'intent' ) ) ) return new WP_Error( 'mad4b_prod_audit_required', 'Production consent audit could not be recorded.' );
  // A single atomic DB compare-and-swap serializes concurrent owner forms.
  global $wpdb;
  $before = get_option( self::OPTION, false );
  if ( ! hash_equals( self::digest( is_array( $before ) ? $before : array() ), $expected_digest ) ) return new WP_Error( 'mad4b_prod_batch_stale', 'Concurrent Production policy change.' );
  if ( false === $before ) $saved = add_option( self::OPTION, $next, '', false );
  else $saved = 1 === (int) $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", maybe_serialize( $next ), self::OPTION, maybe_serialize( $before ) ) );
  wp_cache_delete( self::OPTION, 'options' ); wp_cache_delete( 'alloptions', 'options' );
  if ( ! $saved || self::policy() !== $next ) return new WP_Error( 'mad4b_prod_batch_persist_failed', 'Production batch persistence failed.' );
  if ( $enabled && $jobs && ! wp_next_scheduled( self::CRON ) ) {
   $scheduled = wp_schedule_single_event( time() + 5, self::CRON );
   if ( ! $scheduled || is_wp_error( $scheduled ) ) return new WP_Error( 'mad4b_prod_cron_schedule_failed', 'Batch persisted, but scheduling failed. Inspect the current batch before rescheduling; no deployment was started.' );
  }
  return $next;
 }
 public static function run_deployments() {
  if ( ! self::active() ) return;
  $p = self::policy(); $token = wp_generate_uuid4();
  if ( ! add_option( self::LOCK, $token, '', false ) ) {
   // Defer boundedly without stealing a live or abandoned worker lock.
   for ( $attempt = 0; $attempt < 3; $attempt++ ) {
    if ( ! add_option( self::claim_key( $p['id'], 'defer', $attempt ), time(), '', false ) ) continue;
    if ( ! wp_next_scheduled( self::CRON ) ) wp_schedule_single_event( time() + 60, self::CRON );
    break;
   }
   return;
  }
  $previous_user = get_current_user_id();
  try {
   // Native owner delegation, explicitly NOT an OAuth/AI/human-ticket impersonation.
   wp_set_current_user( (int) $p['owner'] );
   // Durable, batch-bound fence survives worker re-entry and process death.
   // Only a verified successful job may clear it; failure needs a fresh batch.
   $boundary_key = self::claim_key( $p['id'], 'deployment_boundary', 0 );
   if ( false !== get_option( $boundary_key, false ) ) return;
   foreach ( $p['deployments'] as $i => $job ) {
    self::refresh_authority();
    if ( ! self::active() || self::policy()['id'] !== $p['id'] || ! current_user_can( 'update_plugins' ) || ! MAD4B_SCP_Policy::can_mutate() ) break;
    $key = self::claim_key( $p['id'], 'deployment', $i );
    $previous = get_option( $key, false );
    if ( false !== $previous ) {
     if ( is_array( $previous ) && 'completed' === ( $previous['state'] ?? '' ) ) continue;
     break; // Failed, interrupted, malformed and audit-blocked jobs halt re-entry.
    }
    if ( ! add_option( $boundary_key, array( 'index' => $i, 'at' => time() ), '', false )
     || ! add_option( $key, array( 'state' => 'claimed', 'at' => time() ), '', false ) ) break;
    $audit = MAD4B_SCP_Audit::record( 'mad4b/production-native-deployment-start', array( 'batch_id' => $p['id'], 'index' => $i, 'input_sha256' => self::digest( $job['input'] ), 'authority' => 'owner_preapproved_exact_batch' ), 'intent' );
    if ( is_wp_error( $audit ) ) { update_option( $key, array( 'state' => 'audit_blocked' ), false ); break; }
    self::$deployment = $job['input']; self::$deployment_batch_id = $p['id'];
    try { $result = MAD4B_SCP_Remote_Plugin_Update::apply( $job['input'] ); }
    catch ( Throwable $error ) { $result = new WP_Error( 'mad4b_prod_deployment_exception', 'Deployment interrupted; inspect backup and installed state before any retry.' ); }
    finally { self::$deployment = null; self::$deployment_batch_id = null; }
    $ok = ! is_wp_error( $result );
    $state = array( 'state' => $ok ? 'completed' : 'failed', 'at' => time(), 'result' => $ok ? $result : $result->get_error_code() );
    update_option( $key, $state, false );
    $logged = MAD4B_SCP_Audit::record( 'mad4b/production-native-deployment-result', array( 'batch_id' => $p['id'], 'index' => $i, 'outcome' => $state ), $ok ? 'ok' : 'error' );
    if ( get_option( $key ) !== $state || ! $ok || is_wp_error( $logged ) ) break; // Never blindly retry a write or continue after uncertainty.
    if ( ! delete_option( $boundary_key ) || false !== get_option( $boundary_key, false ) ) break;
   }
  } finally {
   self::$deployment = null; self::$deployment_batch_id = null; wp_set_current_user( $previous_user );
   if ( get_option( self::LOCK ) === $token ) delete_option( self::LOCK );
  }
 }
 public static function handle_save() {
  if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) wp_die( 'POST required.', '', array( 'response' => 405 ) );
  check_admin_referer( self::ACTION );
  if ( ! is_string( $_POST['batch'] ?? null ) || strlen( $_POST['batch'] ) > 131072 ) wp_die( 'Invalid batch.', '', array( 'response' => 400 ) );
  $input = json_decode( wp_unslash( $_POST['batch'] ), true );
  if ( is_array( $input ) ) $input['confirmation'] = is_string( $_POST['confirmation'] ?? null ) ? wp_unslash( $_POST['confirmation'] ) : '';
  $result = self::save_batch( $input, is_string( $_POST['expected_digest'] ?? null ) ? $_POST['expected_digest'] : '' );
  if ( is_wp_error( $result ) ) wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => 409 ) );
  wp_safe_redirect( admin_url( 'admin.php?page=mad4b-control-plane-site-profile' ) ); exit;
 }
 public static function render_admin() {
  if ( ! MAD4B_SCP_Governed_Runtime_Gates::admin_owner_access() ) return;
  $p = self::policy();
  echo '<div class="card" style="max-width:950px"><h2>' . esc_html__( 'Production approvals and deployment batches', 'mad4b-site-control-plane' ) . '</h2><p>' . esc_html__( 'Approve up to 20 exact operations once for 1–168 hours. AI content decisions still require the exact Agent grant, OAuth identity, current candidate and governed content reviews. Native plugin deployments run once through WP-Cron with package revalidation, protected plugin-file backup and readback rollback. Control Plane and other protected plugins remain excluded. Deployment requires a trusted signed test certificate, verified again against the package at apply time.', 'mad4b-site-control-plane' ) . '</p>';
  echo '<p>' . esc_html( self::active() ? 'Batch active' : 'Batch disabled, expired or blocked' ) . '</p>';
  if ( isset( $p['id'] ) && false !== get_option( self::claim_key( $p['id'], 'deployment_boundary', 0 ), false ) ) echo '<p>' . esc_html__( 'Deployment continuation is blocked by an unfinished execution boundary. Inspect the installed state and audit before approving a fresh batch.', 'mad4b-site-control-plane' ) . '</p>';
  foreach ( (array) ( $p['deployments'] ?? array() ) as $i => $job ) { $s = get_option( self::claim_key( $p['id'], 'deployment', $i ), array( 'state' => 'pending' ) ); echo '<p>' . esc_html( $job['input']['plugin_file'] . ': ' . ( $s['state'] ?? 'unknown' ) ) . '</p>'; }
  echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'; wp_nonce_field( self::ACTION );
  echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '"><input type="hidden" name="expected_digest" value="' . esc_attr( self::digest( $p ) ) . '">';
  echo '<label for="mad4b-prod-batch">' . esc_html__( 'Exact operation batch (JSON)', 'mad4b-site-control-plane' ) . '</label><textarea id="mad4b-prod-batch" name="batch" rows="8" class="large-text code">' . esc_textarea( wp_json_encode( array( 'enabled' => false, 'hours' => 24, 'agent_public_id' => '', 'approvals' => array(), 'deployments' => array() ), JSON_PRETTY_PRINT ) ) . '</textarea>';
  echo '<p><label><input type="checkbox" name="confirmation" value="AUTHORIZE EXACT PRODUCTION BATCH" required> ' . esc_html__( 'Authorize this exact Production batch, including its native deployments', 'mad4b-site-control-plane' ) . '</label></p>';
  submit_button( 'Save Production batch' ); echo '</form><p>' . esc_html__( 'To revoke: save an empty disabled batch. Failed or claimed deployments require inspection and a fresh approved plan. WP-Cron needs site traffic or a host cron runner. Plugin-file rollback does not restore database migrations.', 'mad4b-site-control-plane' ) . '</p></div>';
 }
}
