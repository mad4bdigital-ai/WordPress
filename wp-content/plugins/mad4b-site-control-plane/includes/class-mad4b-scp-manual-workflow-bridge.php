<?php
/**
 * Manual-to-MCP workflow bridge. Only canonical registered operations may be
 * discovered. No arbitrary route, callback, command, option or plugin may
 * become an executor. One narrowly reviewed admin-consent recipe is supported.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class MAD4B_SCP_Manual_Workflow_Bridge {
 const CONTRACT = 'mad4b.manual-workflow-bridge.v1';
 const DISCOVER = 'mad4b/manual-workflow-discover';
 const PLAN = 'mad4b/manual-workflow-plan';
 const APPLY = 'mad4b/manual-workflow-apply';
 const ENABLE = 'wordpress.native-candidate.enable';
 const DISABLE = 'wordpress.native-candidate.disable';
 const CONFIRM_ENABLE = 'ENABLE WORDPRESS NATIVE STAGING CANDIDATES VIA MCP';
 const CONFIRM_DISABLE = 'DISABLE WORDPRESS NATIVE STAGING CANDIDATES VIA MCP';
 const LOCK = 'mad4b_scp_manual_workflow_native_lock_v1';
 public static function boot() {
  // Keep the generic plugin recovery lane; admin workflow profiles extend
  // it with source-registered typed operation variables, not another installer.
  if ( ! class_exists( 'MAD4B_SCP_Admin_Operation_Profiles', false ) )
   require_once __DIR__ . '/class-mad4b-scp-admin-operation-profiles.php';
  MAD4B_SCP_Admin_Operation_Profiles::boot();
  // Standalone builds run only on a trusted external runner. MCP is read-only.
  if ( ! class_exists( 'MAD4B_SCP_Standalone_Build_Control', false ) )
   require_once __DIR__ . '/class-mad4b-scp-standalone-build-control.php';
  MAD4B_SCP_Standalone_Build_Control::boot();
  add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 39 );
 }
 public static function register_abilities() {
  if ( ! function_exists( 'wp_register_ability' ) ) return;
  $items = array(
   array( self::DISCOVER, 'Discover Governed Manual Workflows', 'discover', true, self::discover_schema() ),
   array( self::PLAN, 'Plan Exact Manual Workflow Transition', 'plan', true, self::plan_schema() ),
   array( self::APPLY, 'Apply Owner-Approved Manual Workflow', 'apply', false, self::apply_schema() ),
  );
  foreach ( $items as $row ) {
   if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $row[0] ) ) continue;
   wp_register_ability( $row[0], array(
    'label' => $row[1], 'description' => 'Bounded Staging workflow discovery and approved execution. No arbitrary calls.',
    'category' => $row[3] ? 'mad4b-read' : 'mad4b-admin',
    'execute_callback' => array( __CLASS__, $row[2] ),
    'permission_callback' => $row[3] ? array( 'MAD4B_SCP_Policy', 'can_read' ) : array( __CLASS__, 'can_apply' ),
    'input_schema' => $row[4], 'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
    'meta' => array( 'public' => false, 'show_in_rest' => false,
     'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => $row[3] ? 'read' : 'admin' ),
     'annotations' => array( 'readonly' => $row[3], 'destructive' => ! $row[3], 'idempotent' => $row[3] ) ),
   ) );
  }
 }
 private static function discover_schema() {
  return array( 'type' => 'object', 'additionalProperties' => false, 'properties' => array(
   'include_remediation' => array( 'type' => 'boolean' ),
   'include_plugin_updates' => array( 'type' => 'boolean' ),
   'include_admin_operation_profiles' => array( 'type' => 'boolean' ),
   'include_admin_surface_coverage' => array( 'type' => 'boolean' ),
   'operation_filter' => array( 'type' => 'string', 'maxLength' => 100 ),
  ) );
 }
 private static function plan_schema() {
  return array( 'type' => 'object', 'additionalProperties' => false,
   'required' => array( 'operation_id', 'reason' ), 'properties' => array(
    'operation_id' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 120 ),
    'reason' => array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 500 ),
   ) );
 }
 private static function apply_schema() {
  $s = self::plan_schema();
  $s['required'][] = 'expected_plan_sha256';
  $s['required'][] = 'confirmation';
  $s['properties']['expected_plan_sha256'] = array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' );
  $s['properties']['confirmation'] = array( 'type' => 'string', 'maxLength' => 100 );
  return $s;
 }
 private static function site() {
  return class_exists( 'MAD4B_SCP_Site_Profile' ) ? MAD4B_SCP_Site_Profile::status() : array();
 }
 /** Pure policy, never elevates a detected UI operation into permission. */
 public static function decision( array $site, $enabled, $id ) {
  $policy = class_exists( 'MAD4B_SCP_WordPress_Native_Opt_In' )
   ? MAD4B_SCP_WordPress_Native_Opt_In::site_policy( $site )
   : array( 'eligible' => false, 'blockers' => array( 'native_adapter_unavailable' ) );
  $desired = self::ENABLE === $id ? true : ( self::DISABLE === $id ? false : null );
  $blockers = is_array( $policy['blockers'] ?? null ) ? $policy['blockers'] : array( 'site_policy_missing' );
  if ( null === $desired ) $blockers[] = 'own_governed_executor_required';
  if ( empty( $policy['eligible'] ) ) $blockers[] = 'exact_staging_site_required';
  $same = null !== $desired && (bool) $enabled === $desired;
  return array( 'eligible' => empty( $blockers ) && ! $same,
   'already_current' => empty( $blockers ) && $same,
   'blockers' => array_values( array_unique( $blockers ) ), 'desired_enabled' => $desired,
   'delegated' => null === $desired, 'automatic_mutation_allowed' => false,
   'host_runner_required' => false, 'production_allowed' => false );
 }
 public static function discover( $input = array() ) {
  if ( ! is_array( $input ) || array_diff( array_keys( $input ), array( 'include_remediation', 'operation_filter', 'include_plugin_updates', 'include_admin_operation_profiles', 'include_admin_surface_coverage' ) ) )
   return new WP_Error( 'mad4b_manual_discovery_input_invalid', 'Only bounded discovery filters supported.' );
  $filter = (string) ( $input['operation_filter'] ?? '' );
  if ( strlen( $filter ) > 100 || ( '' !== $filter && ! preg_match( '/^[A-Za-z0-9._-]+$/D', $filter ) ) )
   return new WP_Error( 'mad4b_manual_discovery_filter_invalid', 'Invalid operation filter.' );
  $site = self::site();
  $native = class_exists( 'MAD4B_SCP_WordPress_Native_Opt_In' )
   ? MAD4B_SCP_WordPress_Native_Opt_In::status() : array( 'enabled' => false );
  $registry = class_exists( 'MAD4B_SCP_Operation_Registry' )
   ? MAD4B_SCP_Operation_Registry::status() : array( 'operations' => array() );
  if ( ! is_array( $registry ) ) $registry = array( 'operations' => array(), 'state' => 'catalog_unavailable' );
  $entries = array();
  foreach ( array( self::ENABLE, self::DISABLE ) as $id ) {
   if ( '' !== $filter && false === stripos( $id, $filter ) ) continue;
   $d = self::decision( is_array( $site ) ? $site : array(), ! empty( $native['enabled'] ), $id );
   $entries[] = array( 'id' => $id, 'risk' => 'high', 'type' => 'wp_native_admin_consent',
    'source' => 'site_profile_authoritative', 'plan_ability' => self::PLAN,
    'apply_ability' => self::APPLY, 'readback_ability' => 'mad4b/wordpress-native-update-status',
    'state' => $d['already_current'] ? 'already_current' : ( $d['eligible'] ? 'owner_approval_required' : 'blocked' ),
    'blockers' => $d['blockers'], 'auto_execute' => false );
  }
  $ops = is_array( $registry['operations'] ?? null ) ? $registry['operations'] : array();
  foreach ( array_slice( $ops, 0, 64 ) as $row ) {
   if ( ! is_array( $row ) || ! is_string( $row['id'] ?? null ) ) continue;
   $id = $row['id'];
   if ( '' !== $filter && false === stripos( $id, $filter ) ) continue;
   $entries[] = array( 'id' => $id, 'type' => 'existing_governed_operation',
    'source' => 'canonical_operation_registry', 'risk' => (string) ( $row['risk'] ?? 'unknown' ),
    'plan_ability' => (string) ( $row['planner'] ?? '' ),
    'apply_ability' => (string) ( $row['executor'] ?? '' ),
    'registered' => ! empty( $row['planner_registered'] ) && ! empty( $row['executor_registered'] ),
    'state' => 'delegated_governed_executor', 'auto_execute' => false );
  }
  // Inspect definition-only WordPress admin routes, including paths without
  // a certified executor. Admin routes are observations, never callbacks.
  $manual_routes = array();
  if ( class_exists( 'MAD4B_SCP_Admin_Route_Registry' ) ) {
   $routes = MAD4B_SCP_Admin_Route_Registry::routes();
   foreach ( array_slice( is_array( $routes ) ? $routes : array(), 0, 96, true ) as $slug => $route ) {
    if ( ! is_string( $slug ) || ! preg_match( '/^mad4b-[a-z0-9-]{1,100}$/D', $slug ) ||
     ! is_array( $route ) ) continue;
    $cap = (string) ( $route['required_capability'] ?? '' );
    if ( '' === $cap || ! current_user_can( $cap ) ) continue;
    if ( '' !== $filter && false === stripos( $slug, $filter ) ) continue;
    $manual_routes[] = array( 'route_id' => $slug, 'required_capability' => $cap,
     'state' => 'manual_route_detected', 'next_action' => 'find_certified_semantic_adapter',
     'mcp_generic_execution_allowed' => false );
   }
  }
  $plugin_update_routes = array(
   'state' => 'not_requested', 'items' => array(),
   'discovery_ability' => 'mad4b/plugin-update-recovery-discover'
  );
  if ( ! empty( $input['include_plugin_updates'] ) &&
   class_exists( 'MAD4B_SCP_Plugin_Update_Recovery' ) ) {
   $inv = MAD4B_SCP_Plugin_Update_Recovery::discover( array(
    'include_uncertified' => true, 'limit' => 80
   ) );
   if ( is_array( $inv ) ) {
    $plugin_update_routes = array(
     'state' => $inv['state'] ?? 'read_only_discovery',
     'installed_plugin_count' => $inv['installed_plugin_count'] ?? 0,
     'items' => $inv['items'] ?? array(),
     'discovery_ability' => 'mad4b/plugin-update-recovery-discover',
     'plan_ability' => 'mad4b/plugin-update-recovery-plan',
     'generic_auto_apply' => false
    );
   }
  }
  $admin_workflow_profiles = array( 'state' => 'not_requested',
   'discovery_ability' => 'mad4b/admin-operation-profiles-discover' );
  if ( ! empty( $input['include_admin_operation_profiles'] ) &&
   class_exists( 'MAD4B_SCP_Admin_Operation_Profiles' ) ) {
   $snapshot = MAD4B_SCP_Admin_Operation_Profiles::discover(
    array( 'operation_filter' => $filter ) );
   $admin_workflow_profiles = is_array( $snapshot ) ? $snapshot :
    array( 'state' => 'unavailable', 'operations' => array() );
  }
  $admin_surface_coverage = array(
   'state' => 'not_requested',
   'inventory_ability' => 'mad4b/admin-surface-coverage',
   'blueprint_ability' => 'mad4b/admin-adapter-blueprint'
  );
  if ( ! empty( $input['include_admin_surface_coverage'] ) &&
   class_exists( 'MAD4B_SCP_Admin_Surface_Coverage' ) ) {
   $coverage = MAD4B_SCP_Admin_Surface_Coverage::inventory();
   $admin_surface_coverage = is_array( $coverage ) ? $coverage :
    array( 'state' => 'unavailable', 'inventory_ability' => 'mad4b/admin-surface-coverage' );
  }
  $remediation = array( 'state' => 'not_requested', 'work_items' => array() );
  if ( ! empty( $input['include_remediation'] ) && class_exists( 'MAD4B_SCP_Operational_Remediation' ) ) {
   $r = MAD4B_SCP_Operational_Remediation::status( array( 'include_live_acceptance' => false ) );
   $remediation = is_array( $r ) ? array( 'state' => (string) ( $r['state'] ?? 'unknown' ),
    'plan_sha256' => (string) ( $r['plan_sha256'] ?? '' ),
    'work_items' => array_slice( is_array( $r['work_items'] ?? null ) ? $r['work_items'] : array(), 0, 40 ),
    'integrity_blockers' => array_slice( is_array( $r['plan_integrity_blockers'] ?? null ) ? $r['plan_integrity_blockers'] : array(), 0, 20 ) )
    : array( 'state' => 'unavailable', 'work_items' => array() );
  }
  return array( 'contract' => self::CONTRACT, 'native_update_enabled' => ! empty( $native['enabled'] ),
   'site_profile_staging' => 'staging' === (string) ( $site['configured_environment'] ?? '' ),
   'discovery_sources' => array( 'canonical_operation_registry', 'staging_remediation', 'site_profile' ),
   'operations' => $entries, 'operation_count' => count( $entries ),
   'admin_routes_detected' => $manual_routes, 'admin_route_count' => count( $manual_routes ),
   'remediation' => $remediation, 'plugin_update_routes' => $plugin_update_routes,
   'admin_workflow_profiles' => $admin_workflow_profiles,
   'admin_surface_coverage' => $admin_surface_coverage,
   'unregistered_manual_actions_auto_executable' => false, 'unknown_executor_policy' => 'deny_and_propose_adapter',
   'read_only' => true, 'authorizing' => false, 'mutation_performed' => false );
 }
 public static function plan( $input = array() ) {
  if ( ! is_array( $input ) || array_diff( array_keys( $input ), array( 'operation_id', 'reason' ) ) )
   return new WP_Error( 'mad4b_manual_plan_input_invalid', 'Use operation_id and reason only.' );
  $id = (string) ( $input['operation_id'] ?? '' );
  $reason = trim( (string) ( $input['reason'] ?? '' ) );
  if ( ! preg_match( '/^[a-z0-9._-]{1,120}$/D', $id ) || strlen( $reason ) < 3 || strlen( $reason ) > 500 )
   return new WP_Error( 'mad4b_manual_plan_invalid', 'Invalid operation or reason.' );
  $site = self::site();
  $enabled = class_exists( 'MAD4B_SCP_WordPress_Native_Opt_In' )
   && MAD4B_SCP_WordPress_Native_Opt_In::enabled();
  $decision = self::decision( is_array( $site ) ? $site : array(), $enabled, $id );
  $identity = class_exists( 'MAD4B_SCP_Self_Update' )
   ? MAD4B_SCP_Self_Update::installed_candidate_identity() : array();
  $plan = array(
   'contract' => self::CONTRACT . '.plan.v1', 'operation_id' => $id, 'reason' => $reason,
   'site_uuid' => (string) ( $site['site_uuid'] ?? '' ),
   'site_profile_digest' => (string) ( $site['profile_digest'] ?? '' ),
   'origin' => (string) ( $site['canonical_origin'] ?? '' ),
   'revision' => (int) ( $site['revision'] ?? 0 ),
   'caller_user_id' => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
   'current_enabled' => (bool) $enabled, 'decision' => $decision,
   'source_commit_sha' => (string) ( $identity['source_commit_sha'] ?? '' ),
   'required_confirmation' => self::ENABLE === $id ? self::CONFIRM_ENABLE
    : ( self::DISABLE === $id ? self::CONFIRM_DISABLE : '' ),
   'eligible' => $decision['eligible'], 'delegated_planner' => '',
   'production_allowed' => false, 'authorizing' => false, 'read_only' => true,
   'mutation_performed' => false, 'no_automatic_approval' => true );
  if ( $decision['delegated'] && class_exists( 'MAD4B_SCP_Operation_Registry' ) ) {
   $op = MAD4B_SCP_Operation_Registry::operation( $id );
   if ( ! is_wp_error( $op ) ) {
    $plan['delegated_planner'] = (string) ( $op['planner'] ?? '' );
    $plan['delegated_executor'] = (string) ( $op['executor'] ?? '' );
   }
   $plan['next_step'] = 'Use the operation-specific governed planner/executor; unknown actions require a certified adapter.';
  }
  $plan['plan_sha256'] = hash( 'sha256', wp_json_encode( $plan, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
  return $plan;
 }
 public static function can_apply( $input = null ) {
  if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'update_plugins' ) )
   return new WP_Error( 'mad4b_manual_admin_required', 'Enrolled WordPress plugin admin required.' );
  if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) ||
   ! MAD4B_SCP_Site_Profile::user_is_enrolled( get_current_user_id() ) ||
   ! class_exists( 'MAD4B_SCP_Policy' ) || ! MAD4B_SCP_Policy::can_mutate() )
   return new WP_Error( 'mad4b_manual_write_authority_required', 'Current Staging Write authority required.' );
  if ( ! class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) ||
   ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active() ||
   ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_has_scope( MAD4B_SCP_OAuth_Resource_Bridge::AUTHORITY_STEP_UP_SCOPE ) ||
   ! class_exists( 'MAD4B_SCP_Local_OAuth_Server' ) ||
   ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_client_is( MAD4B_SCP_Local_OAuth_Server::CHATGPT_CIMD_CLIENT_ID ) )
   return new WP_Error( 'mad4b_manual_step_up_required', 'Verified owner OAuth step-up from exact ChatGPT client required.' );
  if ( ! class_exists( 'MAD4B_SCP_Authorization' ) )
   return new WP_Error( 'mad4b_manual_authorization_missing', 'Canonical central authorization unavailable.' );
  return MAD4B_SCP_Authorization::authorize_mutation( self::APPLY, 'mad4b-admin', 'core', is_array( $input ) ? $input : array() );
 }
 public static function apply( $input = array() ) {
  if ( ! is_array( $input ) || array_diff( array_keys( $input ), array( 'operation_id', 'reason', 'expected_plan_sha256', 'confirmation' ) ) )
   return new WP_Error( 'mad4b_manual_apply_input_invalid', 'Unknown mutation input.' );
  $id = (string) ( $input['operation_id'] ?? '' );
  if ( ! in_array( $id, array( self::ENABLE, self::DISABLE ), true ) )
   return new WP_Error( 'mad4b_manual_executor_not_registered', 'Delegated and unknown actions have no generic mutation executor.' );
  $phrase = self::ENABLE === $id ? self::CONFIRM_ENABLE : self::CONFIRM_DISABLE;
  if ( ! is_string( $input['confirmation'] ?? null ) || ! hash_equals( $phrase, $input['confirmation'] ) )
   return new WP_Error( 'mad4b_manual_confirmation_required', 'Operation-specific exact owner confirmation required.' );
  $expected = (string) ( $input['expected_plan_sha256'] ?? '' );
  if ( ! preg_match( '/^[a-f0-9]{64}$/D', $expected ) )
   return new WP_Error( 'mad4b_manual_plan_sha_required', 'Exact plan SHA required.' );
  $gate = self::can_apply( $input );
  if ( is_wp_error( $gate ) || true !== $gate ) return $gate;
  $plan = self::plan( array( 'operation_id' => $id, 'reason' => (string) ( $input['reason'] ?? '' ) ) );
  if ( is_wp_error( $plan ) ) return $plan;
  if ( ! hash_equals( (string) $plan['plan_sha256'], $expected ) )
   return new WP_Error( 'mad4b_manual_replan_required', 'Source, option, caller or Site Profile changed.' );
  if ( ! $plan['eligible'] )
   return new WP_Error( 'mad4b_manual_action_blocked', 'Site identity or workflow state denies mutation.', $plan['decision'] );
  if ( ! class_exists( 'MAD4B_SCP_WordPress_Native_Opt_In' ) || ! class_exists( 'MAD4B_SCP_Audit' ) )
   return new WP_Error( 'mad4b_manual_adapter_unavailable', 'Audited consent adapter unavailable.' );
  $token = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : $expected;
  if ( ! add_option( self::LOCK, array( 'token' => $token, 'created' => time() ), '', false ) )
   return new WP_Error( 'mad4b_manual_in_progress', 'Another change in progress; inspect lock instead of overriding it.' );
  try {
   $fresh = self::plan( array( 'operation_id' => $id, 'reason' => (string) $input['reason'] ) );
   if ( is_wp_error( $fresh ) || ! hash_equals( $expected, (string) ( $fresh['plan_sha256'] ?? '' ) ) )
    return new WP_Error( 'mad4b_manual_plan_changed', 'Plan changed after lock acquisition.' );
   $key = MAD4B_SCP_WordPress_Native_Opt_In::OPTION;
   $before = get_option( $key, array() );
   $record = array( 'enabled' => self::ENABLE === $id,
    'site_uuid' => $plan['site_uuid'], 'profile_digest' => $plan['site_profile_digest'],
    'canonical_origin' => $plan['origin'],
    'approved_by_user_id' => get_current_user_id(), 'saved_at' => time() );
   update_option( $key, $record, false );
   if ( get_option( $key, array() ) !== $record ) {
    update_option( $key, $before, false );
    return new WP_Error( 'mad4b_manual_write_readback_failed', 'Setting could not be read back.' );
   }
   $site = self::site();
   if ( ! is_array( $site ) ||
    ! hash_equals( $plan['site_uuid'], (string) ( $site['site_uuid'] ?? '' ) ) ||
    ! hash_equals( $plan['site_profile_digest'], (string) ( $site['profile_digest'] ?? '' ) ) ||
    ( self::ENABLE === $id ) !== MAD4B_SCP_WordPress_Native_Opt_In::enabled() ) {
    update_option( $key, $before, false );
    return new WP_Error( 'mad4b_manual_identity_changed', 'Site identity or option readback drifted; rolled back.' );
   }
   $audit = MAD4B_SCP_Audit::record( 'mad4b/manual-workflow-native-candidate-consent',
    array( 'contract' => self::CONTRACT, 'operation_id' => $id,
     'plan_sha256' => $expected, 'site_uuid' => $plan['site_uuid'],
     'profile_digest' => $plan['site_profile_digest'],
     'enabled' => $record['enabled'], 'actor_id' => get_current_user_id(),
     'production_authorized' => false ), 'success' );
   if ( is_wp_error( $audit ) ) {
    update_option( $key, $before, false );
    return new WP_Error( 'mad4b_manual_audit_failed', 'Audit failure: prior value restored.' );
   }
   return array( 'contract' => self::CONTRACT . '.receipt.v1', 'state' => 'verified',
    'operation_id' => $id, 'site_uuid' => $plan['site_uuid'],
    'source_commit_sha' => $plan['source_commit_sha'],
    'enabled' => $record['enabled'], 'plan_sha256' => $expected,
    'readback_verified' => true, 'audit_recorded' => true,
    'mutation_performed' => true, 'authority_created' => false,
    'production_mutation_performed' => false );
  } finally {
   $lock = get_option( self::LOCK, array() );
   if ( is_array( $lock ) && hash_equals( $token, (string) ( $lock['token'] ?? '' ) ) )
    delete_option( self::LOCK );
  }
 }
}
