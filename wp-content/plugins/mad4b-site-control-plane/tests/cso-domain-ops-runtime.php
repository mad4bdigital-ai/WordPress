<?php
/** Real Scope/domain services; WordPress, provider and prepared-read dependencies are explicit doubles. */
function cso_domain_focused_fixture() {
global $argv;
define( 'ABSPATH', __DIR__ . '/' ); define( 'MAD4B_SCP_DIR', dirname( __DIR__ ) . '/' );
class WP_Error { private $code; public function __construct( $code, $message = '', $data = null ) { $this->code = $code; } public function get_error_code() { return $this->code; } }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function wp_json_encode( $v, $flags = 0 ) { return json_encode( $v, $flags ); }
function sanitize_key( $v ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( $v ) ); }
function absint( $v ) { return abs( (int) $v ); }
function wp_date( $f ) { return gmdate( $f ); }
function current_user_can( $cap ) { return $GLOBALS['read_allowed']; }
function get_current_blog_id() { return 1; }
function get_current_user_id() { return 1; }
function determine_locale() { return $GLOBALS['identity']['locale']; }
function wp_get_current_user() { return (object) array( 'allcaps' => array( 'read' => true, 'edit_posts' => true ), 'roles' => array( 'editor' ) ); }
function is_user_logged_in() { return true; }
function wp_get_session_token() { return 'fixture-session-token-0123456789'; }
// WordPress native schema validation is a seam; full WP/DB acceptance is not claimed.
function rest_validate_value_from_schema( $value, $schema, $path = '' ) {
    foreach ( $schema['required'] ?? array() as $key ) if ( ! array_key_exists( $key, $value ) ) return new WP_Error( 'required' );
    foreach ( $value as $key => $item ) {
        if ( ! isset( $schema['properties'][ $key ] ) ) { if ( false === ( $schema['additionalProperties'] ?? true ) ) return new WP_Error( 'unknown' ); continue; }
        $type = $schema['properties'][ $key ]['type'] ?? '';
        if ( 'string' === $type && ! is_string( $item ) || 'integer' === $type && ! is_int( $item ) || 'object' === $type && ! is_array( $item ) ) return new WP_Error( 'type' );
    }
    return true;
}
$GLOBALS['identity'] = array( 'site_uuid' => '11111111-1111-4111-8111-111111111111', 'origin' => 'https://site.example', 'environment' => 'staging', 'source_sha' => str_repeat( 'a', 40 ), 'package_sha256' => str_repeat( 'c', 64 ), 'runtime_generation' => str_repeat( 'd', 64 ), 'restore_epoch' => 1, 'profile_sha256' => str_repeat( 'e', 64 ), 'deployment_sha256' => str_repeat( '8', 64 ), 'external_record_sha256' => str_repeat( '9', 64 ), 'locale' => 'ar' );
$GLOBALS['read_allowed'] = true; $GLOBALS['certified'] = true; $GLOBALS['publish_allowed'] = true; $GLOBALS['media_usage'] = array(); $GLOBALS['handshake'] = array(); $GLOBALS['change_scope'] = false; $GLOBALS['reads'] = 0; $GLOBALS['count'] = 0; $GLOBALS['object_revision'] = str_repeat( '3', 64 ); $GLOBALS['metrics_ready'] = true;
$disabled = in_array( '--disabled', $argv ?? array(), true );
if ( ! $disabled ) foreach ( array( 'MAD4B_CSO_FORMS_ENABLED', 'MAD4B_CSO_OPERATIONS_ENABLED', 'MAD4B_CSO_MULTISITE_ENABLED', 'MAD4B_CSO_PRODUCTION_PROPOSAL_ENABLED' ) as $flag ) define( $flag, true );
class MAD4B_SCP_Policy { public static function can_read() { return $GLOBALS['read_allowed']; } }
class MAD4B_SCP_Identity_Context { public static function current() { return array( 'authenticated' => false ); } }
class MAD4B_SCP_Site_Profile {
    public static function configured() { return true; } public static function origin_enrolled() { return true; } public static function site_urls_match_enrollment() { return true; }
    public static function status() { return array( 'authority_ready' => true, 'origin_match' => true, 'environment_match' => true, 'deployment_binding_match' => true, 'profile_authority_quarantined' => false ); }
    public static function site_uuid() { return $GLOBALS['identity']['site_uuid']; } public static function current_origin() { return $GLOBALS['identity']['origin']; }
    public static function current_environment() { return $GLOBALS['identity']['environment']; } public static function profile_digest() { return $GLOBALS['identity']['profile_sha256']; }
    public static function deployment_binding_digest() { return $GLOBALS['identity']['deployment_sha256']; }
    public static function deployment_binding_proof( $purpose, $sha ) { return hash_hmac( 'sha256', $purpose . '|' . $sha, 'fixture-deployment-key' ); }
    public static function verify_deployment_binding_proof( $purpose, $sha, $proof ) { return hash_equals( self::deployment_binding_proof( $purpose, $sha ), $proof ); }
}
class MAD4B_SCP_Runtime_Generation_Fence { public static function capture() { return array( 'generation_sha256' => $GLOBALS['identity']['runtime_generation'] ); } }
class MAD4B_SCP_Restore_Epoch { public static function status( $a, $b ) { return array( 'ready' => true, 'epoch' => $GLOBALS['identity']['restore_epoch'], 'external_record_sha256' => $GLOBALS['identity']['external_record_sha256'] ); } }
class MAD4B_SCP_Live_Acceptance_Observer {
    public static function external_handshake_attestation_status() { return $GLOBALS['handshake']; }
    public static function build_provenance_status() { return array( 'runtime_manifest_match' => true, 'package_manifest_digest' => $GLOBALS['identity']['package_sha256'] ); }
    public static function build_provenance_identity_status() { return array( 'identity_ready' => true, 'manifest_valid' => true, 'source_commit_sha' => $GLOBALS['identity']['source_sha'], 'package_manifest_digest' => $GLOBALS['identity']['package_sha256'] ); }
}
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-adaptive-operations-context.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-cso-scope.php';
class MAD4B_SCP_Content_Experience_Profiles {
    public static function profile_for_ability( $name ) { return 0 === strpos( $name, 'mad4b/tour-' ) ? array( 'slug' => 'tour', 'post_type' => 'tour', 'revision' => 3 ) : new WP_Error( 'unknown_profile' ); }
    public static function profile_routes( $slug, $rev ) { return array( 'create_plan' => 'mad4b/tour-create-plan', 'create_apply' => 'mad4b/tour-create-apply', 'update_plan' => 'mad4b/tour-update-plan', 'update_apply' => 'mad4b/tour-update-apply', 'publish_plan' => 'mad4b/tour-publish-plan', 'publish_apply' => 'mad4b/tour-publish-apply', 'verify' => 'mad4b/tour-verify' ); }
}
class MAD4B_SCP_Content_Experience_Media_Storage { public static function reference_ids( $v, $spec ) { return is_array( $v ) ? $v : array( (int) $v ); } }
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-content-experience-media-rights.php';
class MAD4B_SCP_Provider_Compatibility_Certification { public static function ability_status( $provider, $name ) { return 'woocommerce/update-product' === $name ? array( 'capability_id' => 'product.bounded-write', 'structural_compatible' => true, 'artifact_authority_bound' => true, 'write_eligible' => $GLOBALS['certified'] ) : array(); } }
class MAD4B_SCP_Capability_Traits { public static function profile( $provider, $capability ) { return array( 'descriptor_binding_ready' => true, 'ability_names' => array( 'woocommerce/update-product' ), 'traits' => array( 'traits_scope' => 'mad4b_adapter_semantics' ) ); } }
class MAD4B_SCP_Observability { public static function slo_status( $hours ) { return array( 'contract' => 'mad4b.observability.v1', 'stages' => array( 'planner' => array( 'ready' => $GLOBALS['metrics_ready'], 'samples' => 20, 'errors' => 2, 'p95_ms' => 120, 'burn_state' => 'warning', 'secret' => 'DO_NOT_LEAK', 'post_title' => 'Private content' ) ) ); } }

class MAD4B_SCP_CSO_Registry {
    public static function describe( $name, array $params = array() ) { return $GLOBALS['descriptors'][ $name ] ?? new WP_Error( 'unavailable' ); }
    public static function read( $name, array $params, array $prepared ) {
        if ( ( $prepared['capability'] ?? null ) !== $name || ( $prepared['input_sha256'] ?? null ) !== MAD4B_SCP_CSO_Scope::digest( $params ) ) return new WP_Error( 'preparation_mismatch' );
        ++$GLOBALS['reads']; if ( $GLOBALS['change_scope'] ) ++$GLOBALS['identity']['restore_epoch'];
        $result = self::native_result( $name, $params );
        return is_wp_error( $result ) ? $result : array( 'contract' => 'mad4b.cso01.native-read.v1', 'result' => $result, 'read_only' => true, 'authorizing' => false, 'mutation_performed' => false );
    }
    private static function native_result( $name, array $params ) {
        if ( 0 === strpos( $name, 'mad4b/tour-' ) ) {
            $rights = array( 'valid' => true );
            if ( 'mad4b/tour-publish-plan' === $name ) {
                $spec = array( 'gallery' => array( 'kind' => 'image_gallery' ), 'usage' => array( 'kind' => 'image_usage', 'publish_rights_policy' => 'require_valid', 'references_field' => 'gallery' ) );
                $rights = MAD4B_SCP_Content_Experience_Media_Rights::publish_guard( $spec, array( 'gallery' => array( 50 ), 'usage' => $GLOBALS['media_usage'] ) );
                if ( is_wp_error( $rights ) ) return $rights; if ( ! $GLOBALS['publish_allowed'] ) return new WP_Error( 'publish_denied' );
            }
            return array( 'contract' => 'mad4b.chatgpt-read-execute.v1', 'result' => array( 'contract' => 'mad4b.content-experience-operation-plan.v1', 'profile_slug' => 'tour', 'post_type' => 'tour', 'profile_revision' => 3, 'plan_sha256' => str_repeat( 'a', 64 ), 'operation' => str_replace( array( 'mad4b/tour-', '-plan' ), '', $name ), 'media_publish_rights' => $rights, 'mutation_performed' => false ) );
        }
        if ( 'woocommerce/get-product' === $name ) return array( 'product' => array( 'id' => $GLOBALS['observed_product_id'] ?? $params['product_id'], 'name' => 'Current name', 'regular_price' => '15', 'password' => 'DO_NOT_LEAK' ), 'sha256' => $GLOBALS['object_revision'] );
        if ( 'mad4b/operational-remediation-status' === $name ) return array( 'contract' => 'mad4b.operational-remediation-control.v1', 'plan_integrity_blockers' => array(), 'work_items' => array( array( 'gate_id' => 'host', 'owner' => 'host-owner', 'paths' => array( array( 'action_id' => 'host_probe', 'apply_ability' => 'mad4b/developer-probe', 'apply_registered' => false, 'executor' => 'host' ), array( 'action_id' => 'native_plan', 'apply_ability' => 'mad4b/tour-create-apply' ), array( 'action_id' => 'disabled_host', 'apply_ability' => 'mad4b/disabled-host', 'apply_registered' => true, 'executor' => 'host' ) ) ) ), 'mutation_performed' => false );
        if ( 'mad4b/operator-doctor' === $name ) return array( 'contract' => 'mad4b.operator-doctor.v1', 'findings' => array( array( 'finding_id' => 'expired-lease', 'severity' => 'high', 'reason_code' => 'expired_active_lease', 'evidence' => array( 'api_key' => 'DO_NOT_LEAK' ) ) ), 'mutation_performed' => false );
        if ( 'mad4b/production-readiness-evaluate' === $name ) return $GLOBALS['verdict'];
        return array( 'contract' => 'fixture.passive-diagnostic.v1', 'findings' => array(), 'mutation_performed' => false );
    }
}
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-cso-domain-plans.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-cso-operations.php';
function schema( $props = array() ) { return array( 'type' => 'object', 'properties' => $props, 'additionalProperties' => false ); }
function descriptor( $name, $read, $schema ) { return array( 'ability_name' => $name, 'schema_sha256' => MAD4B_SCP_CSO_Scope::digest( $schema ), 'adapter_sha256' => str_repeat( '1', 64 ), 'capability_binding' => array( 'ability_name' => $name ), 'lane' => $read ? 'read' : 'content', 'readonly' => $read, 'input_schema' => $schema, 'field_metadata' => array( 'title' => array( 'label' => 'Title' ) ), 'provider' => array( 'id' => 0 === strpos( $name, 'woocommerce/' ) ? 'woocommerce' : 'core', 'certification_sha256' => str_repeat( '2', 64 ) ), 'storage' => array( 'kind' => 'native_ability', 'native_route_available' => true, 'write_supported' => ! $read ), 'storage_status' => $read ? 'READ_ONLY' : 'WRITE_CANDIDATE' ); }
$cs = schema( array( 'post_title' => array( 'type' => 'string' ), 'post_content' => array( 'type' => 'string' ), 'post_id' => array( 'type' => 'integer' ), 'expected_modified_gmt' => array( 'type' => 'string' ), 'helpers' => array( 'type' => 'object' ), 'taxonomies' => array( 'type' => 'object' ) ) );
foreach ( array( 'mad4b/tour-create-plan', 'mad4b/tour-update-plan', 'mad4b/tour-publish-plan', 'mad4b/tour-create-apply' ) as $name ) $GLOBALS['descriptors'][ $name ] = descriptor( $name, false === strpos( $name, '-apply' ), $cs );
$GLOBALS['descriptors']['woocommerce/update-product'] = descriptor( 'woocommerce/update-product', false, schema( array( 'product_id' => array( 'type' => 'integer' ), 'fields' => array( 'type' => 'object' ), 'expected_sha256' => array( 'type' => 'string' ) ) ) );
foreach ( array( 'woocommerce/get-product', 'mad4b/operator-doctor', 'mad4b/connection-doctor', 'mad4b/operational-remediation-status', 'mad4b/runtime-functional-gap-diagnostic', 'mad4b/production-readiness-evaluate', 'fixture/get-object', 'mad4b/content-create-post' ) as $name ) $GLOBALS['descriptors'][ $name ] = descriptor( $name, true, array( 'type' => 'object' ) );
$GLOBALS['descriptors']['woocommerce/update-product']['readback'] = array( 'ability_name' => 'woocommerce/get-product', 'input_map' => array( 'product_id' => 'product_id' ), 'field_map' => array( 'fields.name' => 'product.name', 'fields.regular_price' => 'product.regular_price' ), 'target_map' => array( 'product.id' => 'product_id' ), 'revision_path' => 'sha256', 'revision_input' => 'expected_sha256', 'capability_binding' => $GLOBALS['descriptors']['woocommerce/get-product']['capability_binding'], 'independently_read_required' => true );
function prep( $name, array $params ) { return array( 'capability' => $name, 'input_sha256' => MAD4B_SCP_CSO_Scope::digest( $params ) ); }
function check( $ok, $message ) { ++$GLOBALS['count']; if ( ! $ok ) { fwrite( STDERR, 'FAIL: ' . $message . "\n" ); exit( 1 ); } }
function denied( $result, $message ) { check( is_wp_error( $result ), $message ); }
function inert( $result, $family ) {
    check( ! is_wp_error( $result ), $family . ' yields plan' );
    foreach ( array( 'authorizing', 'execution_supported', 'mutation_performed', 'production_authorized', 'capability_grants_created' ) as $key ) check( false === $result[ $key ], $family . ':' . $key );
    $unsealed = MAD4B_SCP_CSO_Scope::unseal( $result['sealed_plan'], 'mad4b.cso01.domain-plan.' . $family ); $expected = $result; unset( $expected['sealed_plan'] );
    check( $unsealed === $expected, 'real array-first Scope helper preserves exact supplied material' );
    check( $result['expires_at'] - $result['issued_at'] === MAD4B_SCP_CSO_Domain_Plans::TTL, 'service explicitly owns proposal TTL' );
}
$scope = MAD4B_SCP_CSO_Scope::current(); check( is_array( $scope ), 'real Scope captures supplied WP/site/runtime seams' ); $base = array( 'scope' => $scope );
if ( $disabled ) { denied( MAD4B_SCP_CSO_Operations::monitor_plan( $base + array( 'opt_in' => true, 'conditions' => array() ) ), 'unset trusted rollout remains off' ); echo json_encode( array( 'status' => 'PASS', 'checks' => $GLOBALS['count'], 'mode' => 'default_off', 'production_scope_under_test' => true ) ) . "\n"; exit( 0 ); }
$values = array( 'post_title' => 'رحلة جديدة', 'post_content' => 'Draft content', 'taxonomies' => array( 'destination' => array( 4 ) ), 'helpers' => array( 'seo' => array( 'title' => 'Proposed SEO' ) ) );
$content = $base + array( 'capability' => 'mad4b/tour-create-plan', 'target' => array( 'kind' => 'post', 'post_type' => 'tour', 'id' => 0, 'expected_revision' => '' ), 'values' => $values, 'preparation' => prep( 'mad4b/tour-create-plan', $values ) );
$r = MAD4B_SCP_CSO_Domain_Plans::content_plan( $content ); inert( $r, 'content_plan' ); check( 'mad4b/tour-create-apply' === $r['facts']['apply_capability'] && 'mad4b/tour-verify' === $r['facts']['independent_verifier'], 'actual dynamic profile routes retained' ); check( array( 'seo' ) === $r['facts']['helper_ids'] && 1 === $r['facts']['taxonomy_count'], 'typed helpers and taxonomies retained' ); check( false === strpos( json_encode( $r ), 'Draft content' ), 'no value disclosure' );
foreach ( array( 'scope', 'preparation' ) as $key ) { $bad = $content; unset( $bad[ $key ] ); denied( MAD4B_SCP_CSO_Domain_Plans::content_plan( $bad ), 'missing exact context denied' ); }
$bad = $content; $bad['preparation']['input_sha256'] = str_repeat( '0', 64 ); denied( MAD4B_SCP_CSO_Domain_Plans::content_plan( $bad ), 'exact native preparation enforced' );
$bad = $content; $bad['values']['api_key'] = 'DO_NOT_LEAK'; denied( MAD4B_SCP_CSO_Domain_Plans::content_plan( $bad ), 'real boolean secret ingress guard' );
$bad = $content; $bad['values']['post_content'] = 'ghp_12345678901234567890'; denied( MAD4B_SCP_CSO_Domain_Plans::content_plan( $bad ), 'real Scope detects secret-shaped values' );
$bad = $content; $bad['values']['table'] = 'private_options'; denied( MAD4B_SCP_CSO_Domain_Plans::content_plan( $bad ), 'arbitrary storage denied' );
$bad = $content; $bad['values']['post_title'] = array(); denied( MAD4B_SCP_CSO_Domain_Plans::content_plan( $bad ), 'native schema type enforced' );
$bad = $content; $bad['values']['post_content'] = str_repeat( 'x', 131073 ); denied( MAD4B_SCP_CSO_Domain_Plans::content_plan( $bad ), 'input budget enforced' );
$bad = $content; $bad['target']['post_type'] = 'other'; denied( MAD4B_SCP_CSO_Domain_Plans::content_plan( $bad ), 'CPT mismatch denied' );
$bad = $content; $bad['capability'] = 'mad4b/content-create-post'; denied( MAD4B_SCP_CSO_Domain_Plans::content_plan( $bad ), 'generic CPT fallback denied' );
$pv = array( 'post_id' => 42, 'expected_modified_gmt' => '2026-10-10 01:00:00' ); $publish = $base + array( 'capability' => 'mad4b/tour-publish-plan', 'target' => array( 'kind' => 'post', 'post_type' => 'tour', 'id' => 42, 'expected_revision' => $pv['expected_modified_gmt'] ), 'values' => $pv, 'preparation' => prep( 'mad4b/tour-publish-plan', $pv ) );
denied( MAD4B_SCP_CSO_Domain_Plans::content_plan( $publish ), 'production media rights rejects absent usage' ); $GLOBALS['media_usage'] = array( array( 'attachment_id' => 50, 'license' => 'owned' ) ); inert( MAD4B_SCP_CSO_Domain_Plans::content_plan( $publish ), 'content_plan' ); $GLOBALS['publish_allowed'] = false; denied( MAD4B_SCP_CSO_Domain_Plans::content_plan( $publish ), 'publisher permission denied' ); $GLOBALS['publish_allowed'] = true;
$GLOBALS['media_usage'][0]['license_expires_on'] = '2000-01-01'; denied( MAD4B_SCP_CSO_Domain_Plans::content_plan( $publish ), 'actual rights expiry enforced' );
$object = $base + array( 'capability' => 'woocommerce/update-product', 'target' => array( 'type' => 'product', 'id' => 42, 'expected_revision' => $GLOBALS['object_revision'] ), 'values' => array( 'product_id' => 42, 'fields' => array( 'name' => 'New name' ), 'expected_sha256' => $GLOBALS['object_revision'] ), 'read_preparation' => prep( 'woocommerce/get-product', array( 'product_id' => 42 ) ) );
$r = MAD4B_SCP_CSO_Domain_Plans::object_contract( $object ); inert( $r, 'object_contract' ); check( $r['facts']['business_contract_observed'] && $r['facts']['target_revision_verified'], 'native traits and exact revision observed' );
$bad = $object; $bad['values']['product_id'] = 43; denied( MAD4B_SCP_CSO_Domain_Plans::object_contract( $bad ), 'commerce target mismatch denied' );
$GLOBALS['observed_product_id'] = 43; denied( MAD4B_SCP_CSO_Domain_Plans::object_contract( $object ), 'readback object mismatch denied' ); unset( $GLOBALS['observed_product_id'] );
$GLOBALS['object_revision'] = str_repeat( '0', 64 ); denied( MAD4B_SCP_CSO_Domain_Plans::object_contract( $object ), 'native revision drift denied' ); $GLOBALS['object_revision'] = str_repeat( '3', 64 );
$GLOBALS['certified'] = false; $r = MAD4B_SCP_CSO_Domain_Plans::object_contract( $object ); check( ! $r['facts']['business_contract_observed'], 'revoked provider certification blocked' ); $GLOBALS['certified'] = true;
$child = array( 'scope' => $scope, 'target' => array( 'type' => 'product', 'id' => 42, 'expected_revision' => str_repeat( '3', 64 ) ), 'capability' => 'woocommerce/update-product', 'schema_sha256' => $GLOBALS['descriptors']['woocommerce/update-product']['schema_sha256'] ); $other = $child; $other['scope']['site_uuid'] = '22222222-2222-4222-8222-222222222222'; $other['scope']['origin'] = 'https://other.example';
$multi = $base + array( 'sites' => array( $child, $other ) ); $r = MAD4B_SCP_CSO_Domain_Plans::multisite_plan( $multi ); inert( $r, 'multisite_plan' ); check( $r['facts']['children'][0]['local_descriptor_verified'] && ! $r['facts']['children'][1]['local_descriptor_verified'], 'foreign site never inherits local descriptor' ); check( ! $r['facts']['children'][1]['grant_reuse_allowed'] && ! $r['facts']['children'][1]['cross_blog_execution_allowed'], 'per site authority retained' );
$bad = $multi; $bad['sites'][1] = $child; denied( MAD4B_SCP_CSO_Domain_Plans::multisite_plan( $bad ), 'duplicate sites denied' ); $bad['sites'][1]['scope']['origin'] = 'https://SITE.EXAMPLE:443/'; denied( MAD4B_SCP_CSO_Domain_Plans::multisite_plan( $bad ), 'equivalent origin duplicate denied' );
$bad = $multi; $bad['sites'][1]['scope']['origin'] = 'https://user@other.example'; denied( MAD4B_SCP_CSO_Domain_Plans::multisite_plan( $bad ), 'userinfo origin denied' ); $bad = $multi; $bad['sites'][1]['scope']['invented_grant'] = true; denied( MAD4B_SCP_CSO_Domain_Plans::multisite_plan( $bad ), 'unknown scope fields denied' );
$monitor = $base + array( 'opt_in' => true, 'conditions' => array( array( 'metric' => 'external_connectivity', 'operator' => 'eq', 'threshold' => 0 ) ), 'interval_seconds' => 300, 'ttl_seconds' => 3600 ); $r = MAD4B_SCP_CSO_Operations::monitor_plan( $monitor ); inert( $r, 'monitor_plan' ); check( ! $r['facts']['job_scheduled'] && ! $r['facts']['subscription_persisted'], 'no enrollment or scheduling' );
$GLOBALS['handshake'] = array( 'verified' => true, 'real_external_session' => true, 'package_identity_match' => true, 'build_fingerprint_match' => true, 'current_source_commit_sha' => $scope['source_sha'], 'current_package_manifest_digest' => $scope['package_sha256'], 'observed_at' => gmdate( 'Y-m-d H:i:s' ) ); $r = MAD4B_SCP_CSO_Operations::monitor_plan( $monitor ); check( $r['facts']['connectivity']['fresh_external_receipt'] && ! $r['facts']['connectivity']['online_claim'], 'fresh receipt is never continuous uptime' );
foreach ( array( time() - 301, time() + 600 ) as $ts ) { $GLOBALS['handshake']['observed_at'] = gmdate( 'Y-m-d H:i:s', $ts ); $r = MAD4B_SCP_CSO_Operations::monitor_plan( $monitor ); check( ! $r['facts']['connectivity']['fresh_external_receipt'], 'stale or future proof denied' ); }
$bad = $monitor; $bad['opt_in'] = false; denied( MAD4B_SCP_CSO_Operations::monitor_plan( $bad ), 'explicit optin required' ); $bad = $monitor; $bad['conditions'][] = $bad['conditions'][0]; denied( MAD4B_SCP_CSO_Operations::monitor_plan( $bad ), 'dedup enforced' ); $bad = $monitor; $bad['ttl_seconds'] = 86401; denied( MAD4B_SCP_CSO_Operations::monitor_plan( $bad ), 'TTL bounded' );
$drift = $base + array( 'capability' => 'woocommerce/update-product', 'reference' => array( 'schema_sha256' => $GLOBALS['descriptors']['woocommerce/update-product']['schema_sha256'], 'adapter_sha256' => str_repeat( '1', 64 ), 'binding_sha256' => $scope['binding_sha256'] ) ); $r = MAD4B_SCP_CSO_Operations::drift_plan( $drift ); inert( $r, 'drift_plan' ); check( ! $r['facts']['stale_descriptor_invalidated'], 'current descriptor retained' ); $bad = $drift; $bad['reference']['schema_sha256'] = str_repeat( '0', 64 ); $r = MAD4B_SCP_CSO_Operations::drift_plan( $bad ); check( $r['facts']['stale_descriptor_invalidated'] && ! $r['facts']['pending_form_reuse_allowed'], 'schema invalidates forms' );
$drift += array( 'target' => $object['values'], 'desired_values' => array( 'fields.name' => 'New name', 'fields.regular_price' => '15' ), 'observation_capability' => 'woocommerce/get-product', 'observation_input' => array( 'product_id' => 42 ), 'preparation' => prep( 'woocommerce/get-product', array( 'product_id' => 42 ) ) ); $r = MAD4B_SCP_CSO_Operations::drift_plan( $drift ); check( array( 'fields.name' ) === $r['facts']['changed_fields'], 'native current desired fields compared' ); check( $r['facts']['target_binding_verified'] && $r['facts']['target_revision_verified'], 'actual target/revision mapping checked' ); check( false === strpos( json_encode( $r ), 'Current name' ) && false === strpos( json_encode( $r ), 'DO_NOT_LEAK' ), 'values and low entropy hashes omitted' );
$bad = $drift; $bad['observation_capability'] = 'fixture/get-object'; denied( MAD4B_SCP_CSO_Operations::drift_plan( $bad ), 'unrelated capability rejected' ); $bad = $drift; $bad['observation_input']['product_id'] = 43; denied( MAD4B_SCP_CSO_Operations::drift_plan( $bad ), 'unrelated read input rejected' );
$bad = $drift; $bad['observation_path'] = array( 'product' ); denied( MAD4B_SCP_CSO_Operations::drift_plan( $bad ), 'client selected witness path denied' ); $bad = $drift; $bad['desired_values']['unmapped_field'] = 'value'; denied( MAD4B_SCP_CSO_Operations::drift_plan( $bad ), 'unmapped field denied' );
$GLOBALS['observed_product_id'] = 43; denied( MAD4B_SCP_CSO_Operations::drift_plan( $drift ), 'native target mismatch denied' ); unset( $GLOBALS['observed_product_id'] ); $GLOBALS['object_revision'] = str_repeat( '0', 64 ); denied( MAD4B_SCP_CSO_Operations::drift_plan( $drift ), 'native revision changed denied' ); $GLOBALS['object_revision'] = str_repeat( '3', 64 );
$witness = $GLOBALS['descriptors']['woocommerce/update-product']['readback']; unset( $GLOBALS['descriptors']['woocommerce/update-product']['readback'] ); denied( MAD4B_SCP_CSO_Operations::drift_plan( $drift ), 'no trusted readback fails closed' ); $GLOBALS['descriptors']['woocommerce/update-product']['readback'] = $witness;
$GLOBALS['descriptors']['woocommerce/update-product']['readback']['capability_binding']['ability_name'] = 'fixture/get-object'; denied( MAD4B_SCP_CSO_Operations::drift_plan( $drift ), 'binding mismatch denied' ); $GLOBALS['descriptors']['woocommerce/update-product']['readback'] = $witness;
$doctor = $base + array( 'domain' => 'acceptance', 'preparation' => prep( 'mad4b/operational-remediation-status', array() ) ); $r = MAD4B_SCP_CSO_Operations::doctor_plan( $doctor ); inert( $r, 'doctor_plan' ); check( 'MISSING' === $r['facts']['followups'][0]['executor_status'] && ! $r['facts']['followups'][0]['execution_allowed'], 'missing host executor never executes' ); check( 'SEPARATE_NATIVE_AUTHORIZATION_REQUIRED' === $r['facts']['followups'][1]['executor_status'], 'registered action still needs authority' ); check( 'REGISTERED_NATIVE_DESCRIPTOR_BLOCKED' === $r['facts']['followups'][2]['executor_status'], 'disabled registered host remains blocked' );
$doctor['domain'] = 'operations'; $doctor['preparation'] = prep( 'mad4b/operator-doctor', array() ); $r = MAD4B_SCP_CSO_Operations::doctor_plan( $doctor ); check( 1 === $r['facts']['finding_count'] && false === strpos( json_encode( $r ), 'DO_NOT_LEAK' ), 'actual finding identifiers omit evidence secrets' ); $doctor['diagnostic_input'] = array( 'include_live_acceptance' => true ); denied( MAD4B_SCP_CSO_Operations::doctor_plan( $doctor ), 'active probing not silently enabled' );
$r = MAD4B_SCP_CSO_Operations::metrics_plan( $base + array( 'opt_in' => true ) ); inert( $r, 'ux_metrics_plan' ); check( 20 === $r['facts']['stages']['planner']['samples'] && false === strpos( json_encode( $r ), 'DO_NOT_LEAK' ), 'aggregate counters only' ); check( ! $r['facts']['new_event_collection_enabled'], 'no analytics enrollment' ); denied( MAD4B_SCP_CSO_Operations::metrics_plan( $base + array( 'opt_in' => false ) ), 'analytics explicit optin' );
$GLOBALS['metrics_ready'] = false; $r = MAD4B_SCP_CSO_Operations::metrics_plan( $base + array( 'opt_in' => true ) ); check( in_array( 'no_native_metric_stage_available', $r['blockers'], true ) && ! $r['facts']['stages']['planner']['sampled'], 'unavailable metrics never claim observation' ); $GLOBALS['metrics_ready'] = true;
$r = MAD4B_SCP_CSO_Operations::accessibility_report( $base + array( 'capability' => 'woocommerce/update-product' ) ); inert( $r, 'form_accessibility_report' ); check( 'rtl' === $r['facts']['direction'] && ! $r['facts']['accessibility_certified'], 'Arabic RTL is not browser certification' );
$artifact = array( 'source_sha' => $scope['source_sha'], 'package_sha256' => $scope['package_sha256'], 'artifact_sha256' => str_repeat( '4', 64 ) ); $artifact['proof'] = MAD4B_SCP_Site_Profile::deployment_binding_proof( 'mad4b.cso01.promotion-artifact.v1', MAD4B_SCP_CSO_Scope::digest( $artifact ) );
$GLOBALS['verdict'] = array( 'contract' => 'mad4b.production-live-evidence-verdict.v1', 'production_ready' => true, 'production_authorized' => false, 'promotion_required' => true, 'authorizing' => false, 'mutation_performed' => false, 'evidence_trust_contract' => 'mad4b.production-evidence-trust.v1', 'evidence_gate_count' => 11, 'trusted_evidence_gate_count' => 11, 'candidate_identity' => array( 'source_commit_sha' => $scope['source_sha'], 'package_manifest_digest' => $scope['package_sha256'] ) );
$promotion = $base + array( 'artifact' => $artifact, 'destination' => array( 'site_uuid' => $scope['site_uuid'], 'origin' => 'https://production.example', 'environment' => 'production', 'profile_sha256' => str_repeat( '5', 64 ) ), 'staging_bundle' => array( 'contract' => 'fixture_native_verifier_bundle' ), 'preparation' => prep( 'mad4b/production-readiness-evaluate', array( 'bundle' => array( 'contract' => 'fixture_native_verifier_bundle' ) ) ) ); $r = MAD4B_SCP_CSO_Domain_Plans::promotion_plan( $promotion ); inert( $r, 'promotion_plan' ); check( $r['facts']['artifact_binding_proof_verified'] && $r['facts']['staging_evidence_ready'], 'exact MAC and native trusted verdict checked' ); check( ! $r['facts']['artifact_signature_verified'] && ! $r['facts']['artifact_linkage_to_staging_receipts_verified'], 'deployment MAC never claims independent release certification' ); check( in_array( 'separate_production_authority_and_owner_approval_required', $r['blockers'], true ), 'no inherited production authority' );
$bad = $promotion; $bad['artifact']['artifact_sha256'] = str_repeat( '6', 64 ); $r = MAD4B_SCP_CSO_Domain_Plans::promotion_plan( $bad ); check( ! $r['facts']['artifact_binding_proof_verified'], 'changed artifact invalidates MAC' ); $bad = $promotion; $bad['artifact']['source_sha'] = str_repeat( '0', 40 ); denied( MAD4B_SCP_CSO_Domain_Plans::promotion_plan( $bad ), 'wrong source artifact denied' );
$bad = $promotion; $bad['destination']['origin'] = 'https://SITE.EXAMPLE:443/'; denied( MAD4B_SCP_CSO_Domain_Plans::promotion_plan( $bad ), 'equivalent staging destination denied' ); $GLOBALS['verdict']['production_authorized'] = true; $r = MAD4B_SCP_CSO_Domain_Plans::promotion_plan( $promotion ); check( ! $r['facts']['staging_evidence_ready'], 'self authorizing staging rejected' );
$GLOBALS['change_scope'] = true; denied( MAD4B_SCP_CSO_Domain_Plans::content_plan( $content ), 'restore during native read invalidates final proposal' ); $GLOBALS['change_scope'] = false; $GLOBALS['identity']['restore_epoch'] = 1; $GLOBALS['read_allowed'] = false; denied( MAD4B_SCP_CSO_Operations::monitor_plan( $monitor ), 'revoked actor denied' ); $GLOBALS['read_allowed'] = true;
$bad = $monitor; $bad['scope']['actor_sha256'] = str_repeat( '0', 64 ); denied( MAD4B_SCP_CSO_Operations::monitor_plan( $bad ), 'foreign actor scope rejected' ); check( false === MAD4B_SCP_CSO_Scope::enabled( 'unrecognized' ), 'unrecognized rollout remains off' );
echo json_encode( array( 'status' => 'PASS', 'checks' => $GLOBALS['count'], 'native_read_seam_invocations' => $GLOBALS['reads'], 'production_classes_under_test' => array( 'CSO_Scope', 'CSO_Domain_Plans', 'CSO_Operations', 'Content_Experience_Media_Rights', 'Canonicalization', 'Adaptive_Operations_Context' ), 'wordpress_provider_dispatch_dependencies_mocked' => true, 'native_ci_or_provider_certification_claimed' => false ), JSON_UNESCAPED_SLASHES ) . "\n";
}

if ( in_array( '--registry-integration', $argv ?? array(), true ) ) {
    define( 'CSO_REGISTRY_FIXTURE_ONLY', true );
    require __DIR__ . '/cso-registry-runtime.php';
    foreach ( array( 'MAD4B_CSO_READ_INVENTORY_ENABLED', 'MAD4B_CSO_FORMS_ENABLED', 'MAD4B_CSO_OPERATIONS_ENABLED', 'MAD4B_CSO_MULTISITE_ENABLED' ) as $flag ) if ( ! defined( $flag ) ) define( $flag, true );
    require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-cso-domain-plans.php';
    require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-cso-operations.php';
    $checks = 0;
    $assert = static function( $truth, $label ) use ( &$checks ) { ++$checks; if ( true !== $truth ) { fwrite( STDERR, 'FAIL: ' . $label . "\n" ); exit( 1 ); } };
    $scope = MAD4B_SCP_CSO_Scope::current(); $assert( is_array( $scope ), 'actual Scope captured' );
    $descriptor = MAD4B_SCP_CSO_Registry::describe( 'fixture/read', array( 'id' => 7 ) );
    $assert( is_array( $descriptor ), 'actual native descriptor' );
    $read = MAD4B_SCP_CSO_Domain_Plans::read( 'fixture/read', array( 'id' => 7 ), $descriptor['preparation'] );
    $assert( is_array( $read ) && 7 === $read['id'] && 'رحلة مصر' === $read['title'], 'actual native-read envelope unwrapped' );
    $assert( 1 === $GLOBALS['cso_fixture']['dispatches'] && 0 === $GLOBALS['cso_fixture']['direct_targets'] && 0 === $GLOBALS['cso_fixture']['missing_children'], 'original read dispatcher and governed child fence used' );
    $write = MAD4B_SCP_CSO_Registry::describe( 'fixture/write' );
    $assert( is_array( $write ) && is_array( $write['readback'] ), 'trusted native readback compiled by real Registry' );
    $input = array( 'scope' => $scope, 'capability' => 'fixture/write', 'target' => array( 'id' => 7, 'title' => 'Proposed', 'expected_revision' => 3 ),
        'reference' => array( 'schema_sha256' => $write['schema_sha256'], 'adapter_sha256' => $write['adapter_sha256'], 'binding_sha256' => $scope['binding_sha256'] ),
        'desired_values' => array( 'title' => 'Proposed' ), 'preparation' => $descriptor['preparation'] );
    $plan = MAD4B_SCP_CSO_Operations::drift_plan( $input );
    $assert( is_array( $plan ) && array( 'title' ) === $plan['facts']['changed_fields'] && $plan['facts']['target_binding_verified'] && $plan['facts']['target_revision_verified'], 'actual native target readback compared' );
    $exact = $plan; unset( $exact['sealed_plan'] );
    $assert( $exact === MAD4B_SCP_CSO_Scope::unseal( $plan['sealed_plan'], 'mad4b.cso01.domain-plan.drift_plan' ), 'real array-first pure integrity helper preserves exact service fields' );
    $assert( is_wp_error( MAD4B_SCP_CSO_Scope::unseal( $plan['sealed_plan'], 'mad4b.cso01.domain-plan.doctor_plan' ) ), 'purpose substitution denied' );
    $assert( 0 === $GLOBALS['cso_fixture']['write_permission_calls'] && false === $plan['execution_supported'], 'no mutation permission probed or write enrolled' );
    $bad = $input; $bad['target']['expected_revision'] = 4;
    $assert( is_wp_error( MAD4B_SCP_CSO_Operations::drift_plan( $bad ) ), 'actual typed CAS mismatch denied' );
    wp_get_ability( 'fixture/read' )->result['id'] = 8;
    $assert( is_wp_error( MAD4B_SCP_CSO_Operations::drift_plan( $input ) ), 'actual native result identity mismatch denied' );
    wp_get_ability( 'fixture/read' )->result['id'] = 7;
    $before = $GLOBALS['cso_fixture']['target_effects'];
    $assert( is_wp_error( MAD4B_SCP_CSO_Domain_Plans::read( 'fixture/read', array( 'id' => 7 ), array() ) ) && $before === $GLOBALS['cso_fixture']['target_effects'], 'actual dispatcher rejects missing preparation before target effect' );
    wp_get_ability( 'fixture/read' )->after = static function() { ++$GLOBALS['cso_fixture']['restore']; };
    $assert( is_wp_error( MAD4B_SCP_CSO_Operations::drift_plan( $input ) ), 'actual Scope rejects restoration during native read' );
    cso_fixture_reset(); $scope = MAD4B_SCP_CSO_Scope::current();
    $monitor = MAD4B_SCP_CSO_Operations::monitor_plan( array( 'scope' => $scope, 'opt_in' => true, 'conditions' => array( array( 'metric' => 'external_connectivity', 'operator' => 'eq', 'threshold' => 0 ) ) ) );
    $assert( is_array( $monitor ) && ! $monitor['facts']['job_scheduled'] && 'UNKNOWN' === $monitor['facts']['connectivity']['state'], 'actual Scope monitor proposal remains inert without external witness' );
    $private = array( 'scope' => $scope, 'opt_in' => true, 'conditions' => array(), 'api_key' => 'DO_NOT_LEAK' );
    $assert( is_wp_error( MAD4B_SCP_CSO_Operations::monitor_plan( $private ) ), 'actual boolean privacy guard enforced' );
    $GLOBALS['cso_fixture']['read_enabled'] = false;
    $assert( is_wp_error( MAD4B_SCP_CSO_Operations::monitor_plan( array( 'scope' => $scope, 'opt_in' => true, 'conditions' => array() ) ) ), 'actual Policy revocation closes context' );
    echo json_encode( array( 'status' => 'PASS', 'checks' => $checks, 'mode' => 'actual_registry_integration', 'production_scope_registry_dispatch_fence_under_test' => true, 'wordpress_provider_dependencies_mocked' => true, 'native_ci_or_provider_certification_claimed' => false ) ) . "\n";
} else {
    cso_domain_focused_fixture();
}
