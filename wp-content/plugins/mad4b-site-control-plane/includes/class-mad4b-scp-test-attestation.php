<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Public-key verification of exact artifact test results; never grants execution. */
final class MAD4B_SCP_Test_Attestation {
 const CONTRACT = 'mad4b.artifact-test-attestation.v1';
 const OPTION = 'mad4b_scp_test_attestation_trust_v1';
 const ACTION = 'mad4b_test_attestation_trust_save';
 const ABILITY = 'mad4b/test-attestation-verify';
 const ENVIRONMENTS = array( 'local', 'development', 'staging', 'production' );
 const REQUIRED_TESTS = array( 'install', 'smoke', 'rollback' );
 public static function boot() {
  add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_save' ) );
  add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_ability' ), 34 );
 }
 public static function register_ability() {
  if ( ! function_exists( 'wp_register_ability' ) || ( function_exists( 'wp_has_ability' ) && wp_has_ability( self::ABILITY ) ) ) return;
  wp_register_ability( self::ABILITY, array( 'label' => 'Verify signed artifact test certificate', 'description' => 'Verify an enrolled signer, exact package, target site/environment, test results and expiry. Read-only and non-authorizing.', 'category' => 'mad4b-read', 'execute_callback' => array( __CLASS__, 'execute' ), 'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ), 'input_schema' => array( 'type' => 'object', 'properties' => array( 'certificate' => array( 'type' => 'object', 'additionalProperties' => true ), 'artifact' => array( 'type' => 'object', 'additionalProperties' => true ) ), 'required' => array( 'certificate', 'artifact' ), 'additionalProperties' => false ), 'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ), 'meta' => array( 'public' => false, 'show_in_rest' => false, 'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ), 'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ) ) ) );
 }
 public static function execute( $input ) { return self::verify( $input['certificate'] ?? null, $input['artifact'] ?? null ); }
 public static function owner() {
  if ( ! is_admin() || ! current_user_can( 'manage_options' ) || ! MAD4B_SCP_Site_Profile::configured()
   || ! in_array( MAD4B_SCP_Site_Profile::current_environment(), self::ENVIRONMENTS, true )
   || ! MAD4B_SCP_Site_Profile::origin_enrolled() || ! MAD4B_SCP_Site_Profile::site_urls_match_enrollment() ) return false;
  $ids = MAD4B_SCP_Site_Profile::oauth_user_ids();
  return is_array( $ids ) && count( array_unique( array_map( 'intval', $ids ) ) ) === 1 && in_array( get_current_user_id(), array_map( 'intval', $ids ), true );
 }
 public static function trust() { $v = get_option( self::OPTION, array() ); return is_array( $v ) ? $v : array(); }
 private static function error( $reason ) { return new WP_Error( 'mad4b_test_attestation_' . $reason, 'Artifact test certificate rejected: ' . $reason . '.' ); }
 public static function verify( $certificate, $artifact ) {
  MAD4B_SCP_Production_Automation::refresh_authority();
  if ( ! is_array( $certificate ) || ! is_array( $artifact ) || strlen( wp_json_encode( $certificate ) ) > 65536 ) return self::error( 'input_invalid' );
  $p = $certificate['payload'] ?? null; $signature = $certificate['signature'] ?? null;
  $trust = self::trust(); $keys = $trust['keys'] ?? array();
  if ( ! is_array( $p ) || ! is_array( $signature ) || ( $p['contract'] ?? '' ) !== self::CONTRACT || ! is_string( $signature['kid'] ?? null ) ) return self::error( 'contract_invalid' );
  foreach ( array( 'contract', 'profile_id', 'profile_sha256', 'algorithm', 'kid', 'signed_sha256', 'signature_b64url' ) as $field ) if ( ! is_string( $signature[ $field ] ?? null ) || '' === $signature[ $field ] ) return self::error( 'signature_invalid' );
  $key = $keys[ $signature['kid'] ] ?? null;
  if ( ! is_array( $key ) || empty( $key['enabled'] ) || ! isset( $trust['binding'] ) || $trust['binding'] !== self::binding() ) return self::error( 'signer_untrusted' );
  $env = MAD4B_SCP_Site_Profile::current_environment();
  if ( ! in_array( $env, self::ENVIRONMENTS, true ) || ! MAD4B_SCP_Site_Profile::origin_enrolled() || ! MAD4B_SCP_Site_Profile::site_urls_match_enrollment() ) return self::error( 'environment_unbound' );
  if ( ( $p['target_site_uuid'] ?? '' ) !== MAD4B_SCP_Site_Profile::site_uuid() || ( $p['target_origin'] ?? '' ) !== MAD4B_SCP_Site_Profile::current_origin() || ( $p['target_environment'] ?? '' ) !== $env || ! in_array( $env, $key['target_environments'], true ) ) return self::error( 'target_mismatch' );
  if ( ( $p['issuer_site_uuid'] ?? '' ) !== $key['issuer_site_uuid'] || ( $p['issuer_origin'] ?? '' ) !== $key['issuer_origin'] || ( $p['issuer_environment'] ?? '' ) !== $key['issuer_environment'] ) return self::error( 'issuer_mismatch' );
  if ( 'production' === $env && ! in_array( $key['issuer_environment'], array( 'staging', 'ci' ), true ) ) return self::error( 'production_test_source_invalid' );
  $now = time(); $issued = $p['issued_at'] ?? null; $expires = $p['expires_at'] ?? null;
  if ( ! is_int( $issued ) || ! is_int( $expires ) || $issued > $now || $issued < $key['not_before'] || $expires <= $now || $expires <= $issued || $expires - $issued > DAY_IN_SECONDS || $expires > $key['not_after'] || $now >= $key['not_after'] ) return self::error( 'time_invalid' );
  foreach ( array( 'plugin_file', 'package_sha256', 'archive_manifest_sha256', 'target_version' ) as $field ) {
   if ( ! is_string( $artifact[ $field ] ?? null ) || '' === $artifact[ $field ] || ! is_string( $p[ $field ] ?? null ) || ! hash_equals( $artifact[ $field ], $p[ $field ] ) ) return self::error( 'artifact_mismatch' );
  }
  foreach ( array( 'package_sha256', 'archive_manifest_sha256' ) as $field ) if ( ! preg_match( '/^[a-f0-9]{64}$/D', $p[ $field ] ) ) return self::error( 'digest_invalid' );
  if ( ! is_string( $p['source_commit_sha'] ?? null ) || ! preg_match( '/^[a-f0-9]{40}$/D', $p['source_commit_sha'] ) ) return self::error( 'source_identity_missing' );
  $tests = $p['tests'] ?? null;
  if ( ! is_array( $tests ) || count( $tests ) > 50 ) return self::error( 'tests_invalid' );
  foreach ( array_unique( array_merge( self::REQUIRED_TESTS, $key['required_tests'] ) ) as $test ) if ( ( $tests[ $test ] ?? '' ) !== 'PASS' ) return self::error( 'tests_incomplete' );
  foreach ( $tests as $result ) if ( 'PASS' !== $result ) return self::error( 'test_failed' );
  $digest = MAD4B_SCP_Production_Automation::digest( $p );
  $verified = MAD4B_SCP_Crypto_Profile::verify_with_public_key( $signature, $digest, $key['public_key'] );
  if ( is_wp_error( $verified ) ) return $verified;
  return array( 'contract' => self::CONTRACT, 'valid' => true, 'certificate_sha256' => MAD4B_SCP_Production_Automation::digest( $certificate ), 'source_commit_sha' => $p['source_commit_sha'], 'environment' => $env, 'signer_kid' => $signature['kid'], 'expires_at' => $expires, 'read_only' => true, 'authorizing' => false, 'mutation_performed' => false );
 }
 private static function binding() { return array( 'site_uuid' => MAD4B_SCP_Site_Profile::site_uuid(), 'revision' => MAD4B_SCP_Site_Profile::revision(), 'digest' => MAD4B_SCP_Site_Profile::profile_digest() ); }
 public static function save_trust( $keys, $expected_digest ) {
  if ( ! self::owner() ) return self::error( 'owner_required' );
  if ( ! is_array( $keys ) || count( $keys ) > 10 || ! is_string( $expected_digest ) || ! hash_equals( MAD4B_SCP_Production_Automation::digest( self::trust() ), $expected_digest ) ) return self::error( 'trust_stale_or_invalid' );
  foreach ( $keys as $kid => $key ) {
   if ( ! is_string( $kid ) || ! preg_match( '/^[a-zA-Z0-9_-]{1,100}$/D', $kid ) || ! is_array( $key ) || ! is_bool( $key['enabled'] ?? null ) || ! is_string( $key['issuer_site_uuid'] ?? null ) || ! preg_match( '/^[a-f0-9-]{36}$/D', $key['issuer_site_uuid'] ) || ! is_string( $key['issuer_origin'] ?? null ) || ! filter_var( $key['issuer_origin'], FILTER_VALIDATE_URL ) || ! in_array( $key['issuer_environment'] ?? '', array( 'local', 'development', 'staging', 'production', 'ci' ), true ) || ! is_array( $key['target_environments'] ?? null ) || ! MAD4B_SCP_Production_Automation::is_list( $key['target_environments'] ) || ! $key['target_environments'] || array_diff( $key['target_environments'], self::ENVIRONMENTS ) || ! is_array( $key['required_tests'] ?? null ) || count( $key['required_tests'] ) > 20 || ! is_int( $key['not_before'] ?? null ) || ! is_int( $key['not_after'] ?? null ) || $key['not_after'] <= $key['not_before'] ) return self::error( 'trust_key_invalid' );
   foreach ( $key['required_tests'] as $test ) if ( ! is_string( $test ) || ! preg_match( '/^[a-zA-Z0-9_.-]{1,100}$/D', $test ) ) return self::error( 'test_name_invalid' );
   $public = $key['public_key'] ?? null;
   if ( ! is_array( $public ) || ( $public['kid'] ?? '' ) !== $kid || ( $public['algorithm'] ?? '' ) !== 'RS256' || ! is_string( $public['public_key_pem'] ?? null ) || strlen( $public['public_key_pem'] ) > 16384 || strpos( $public['public_key_pem'], 'PRIVATE KEY' ) !== false ) return self::error( 'public_key_invalid' );
   foreach ( array( 'profile_id', 'profile_sha256' ) as $field ) if ( ! is_string( $public[ $field ] ?? null ) || '' === $public[ $field ] ) return self::error( 'key_metadata_invalid' );
   $resource = openssl_pkey_get_public( $public['public_key_pem'] ); $details = $resource ? openssl_pkey_get_details( $resource ) : false;
   if ( ! $details || OPENSSL_KEYTYPE_RSA !== $details['type'] || $details['bits'] < 2048 ) return self::error( 'rsa_key_invalid' );
  }
  $next = array( 'binding' => self::binding(), 'keys' => $keys );
  if ( empty( MAD4B_SCP_Audit::storage_status()['ready'] ) || is_wp_error( MAD4B_SCP_Audit::record( 'mad4b/test-attestation-trust-owner-consent', array( 'owner' => get_current_user_id(), 'before_digest' => $expected_digest, 'after_digest' => MAD4B_SCP_Production_Automation::digest( $next ), 'binding' => $next['binding'], 'key_ids' => array_keys( $keys ) ), 'intent' ) ) ) return self::error( 'trust_audit_failed' );
  global $wpdb; $before = get_option( self::OPTION, false );
  if ( ! hash_equals( MAD4B_SCP_Production_Automation::digest( is_array( $before ) ? $before : array() ), $expected_digest ) ) return self::error( 'trust_stale_or_invalid' );
  $saved = false === $before ? add_option( self::OPTION, $next, '', false ) : 1 === (int) $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", maybe_serialize( $next ), self::OPTION, maybe_serialize( $before ) ) );
  wp_cache_delete( self::OPTION, 'options' ); wp_cache_delete( 'alloptions', 'options' );
  return $saved && self::trust() === $next ? $next : self::error( 'trust_persistence_failed' );
 }
 public static function handle_save() {
  if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) wp_die( 'POST required.', '', array( 'response' => 405 ) );
  check_admin_referer( self::ACTION );
  if ( ! is_string( $_POST['keys'] ?? null ) || strlen( $_POST['keys'] ) > 131072 || ( $_POST['confirmation'] ?? '' ) !== 'TRUST THESE TEST SIGNERS' ) wp_die( 'Exact test signer consent required.', '', array( 'response' => 400 ) );
  $keys = json_decode( wp_unslash( $_POST['keys'] ), true );
  $result = self::save_trust( $keys, is_string( $_POST['expected_digest'] ?? null ) ? $_POST['expected_digest'] : '' );
  if ( is_wp_error( $result ) ) wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => 409 ) );
  wp_safe_redirect( admin_url( 'admin.php?page=mad4b-control-plane-site-profile' ) ); exit;
 }
 public static function render_admin() {
  if ( ! self::owner() ) return;
  echo '<div class="card" style="max-width:950px"><h2>' . esc_html__( 'Signed test certificates', 'mad4b-site-control-plane' ) . '</h2><p>' . esc_html__( 'Verify exact artifact tests in Local, Development, Staging and Production. Enroll public keys from trusted test runners. Certificates expire within 24 hours and bind the package, file manifest, source commit and exact target site/environment. Production accepts Staging or CI test issuers. No private key is accepted here.', 'mad4b-site-control-plane' ) . '</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'; wp_nonce_field( self::ACTION );
  echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '"><input type="hidden" name="expected_digest" value="' . esc_attr( MAD4B_SCP_Production_Automation::digest( self::trust() ) ) . '"><label for="mad4b-test-keys">Trusted public test signers (JSON)</label><textarea id="mad4b-test-keys" name="keys" rows="8" class="large-text code">' . esc_textarea( wp_json_encode( self::trust()['keys'] ?? new stdClass(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ) . '</textarea><p><label><input type="checkbox" name="confirmation" value="TRUST THESE TEST SIGNERS" required> Trust exactly these test signers; removed or disabled keys revoke verification immediately.</label></p>';
  submit_button( 'Save trusted test signers' ); echo '</form></div>';
 }
}
