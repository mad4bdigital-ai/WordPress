<?php
/** CSO01 isolated WordPress Ability contract smoke + adversarial denial tests. */
define( 'ABSPATH', '/fixture/' );
class WP_Error {
    private $code;
    public function __construct( $code, $message = '' ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function wp_json_encode( $data ) { return json_encode( $data ); }
function sanitize_key( $key ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ); }
$GLOBALS['can_read'] = true;
$GLOBALS['enrolled'] = true;
$GLOBALS['site_origin'] = 'https://staging.example.org';
$GLOBALS['abilities'] = array();
$GLOBALS['hooks'] = array();
function current_user_can( $cap ) { return 'manage_options' === $cap && $GLOBALS['can_read']; }
function get_current_blog_id() { return $GLOBALS['current_blog'] ?? 3; }
function get_current_user_id() { return isset( $GLOBALS['actor_id'] ) ? $GLOBALS['actor_id'] : 7; }
function wp_salt( $scheme ) { return str_repeat( 'fixture-auth-salt', 4 ); }
function get_user_locale() { return 'ar_EG'; }
function is_rtl() { return false; }
function wp_get_environment_type() { return $GLOBALS['wp_env'] ?? 'staging'; }
function add_action( $name, $cb, $priority = 10 ) { $GLOBALS['hooks'][] = $name; }
function wp_has_ability( $name ) { return isset( $GLOBALS['abilities'][ $name ] ); }
function wp_register_ability( $name, $args ) { $GLOBALS['abilities'][ $name ] = $args; return new Fake_Ability( $args ); }
function wp_get_ability( $name ) { return new Fake_Ability( $GLOBALS['abilities'][ $name ] ); }
class MAD4B_SCP_Policy { public static function can_read() { return true; }
    public static function can_connect_user( $id ) { return in_array( $id, array( 7, 8 ), true ) && ( ! isset( $GLOBALS['actor_enrolled'] ) || $GLOBALS['actor_enrolled'] ); } }
class MAD4B_SCP_Site_Profile {
    public static function configured() { return $GLOBALS['enrolled']; }
    public static function origin_enrolled() { return $GLOBALS['enrolled']; }
    public static function site_uuid() { return '12345678-1234-1234-1234-123456789abc'; }
    public static function current_origin() { return $GLOBALS['site_origin']; }
    public static function current_environment() { return $GLOBALS['profile_env'] ?? 'staging'; }
    public static function revision() { return 'v9'; }
}
class MAD4B_SCP_Servers {
    public static function is_chatgpt_full_catalog_candidate( $name ) {
        if ( ! empty( $GLOBALS['long_ability_fixture'] )
            && in_array( $name, $GLOBALS['long_ability_fixture'], true ) ) return true;
        return in_array( $name, array( 'woocommerce/get-product', 'jetengine/get-cpt-definition', 'rank-math/get-post-schema',
            'elementor/get-dynamic-tags', 'fluentforms/list-forms', 'wpml/status' ), true );
    }
    public static function core_tools( $name ) { return 'mad4b-read' === $name ? array_merge( array( 'mad4b/site-info' ), $GLOBALS['extra_tools'] ?? array() ) : array(); }
}
class MAD4B_SCP_Capability_Descriptor_Registry {
    public static function binding( $name, $consumer ) {
        return array( 'execution_lane' => isset( $GLOBALS['lane'] ) ? $GLOBALS['lane'] : 'read',
            'descriptor_sha256' => str_repeat( 'a', 64 ), 'consumer' => $consumer,
            'ability_name' => $name, 'generation_roots' => array( 'site_root' => 'mock' ) );
    }
}
class MAD4B_SCP_Provider_Compatibility_Certification {
    public static function supports_provider( $family ) {
        return in_array( $family, array( 'jetengine', 'woocommerce' ), true );
    }
    public static function certification_generation_sha256( $family ) {
        return str_repeat( ! empty( $GLOBALS['provider_generation_drift'] ) ? 'f' : 'a', 64 );
    }
    public static function capability_certification( $input ) {
        $family = $input['provider_id'];
        $drift = ! empty( $GLOBALS['jetengine_version_drift'] );
        $unattested = ! empty( $GLOBALS['jetengine_unattested'] );
        if ( 'woocommerce' === $family ) return array(
            'provider_id' => $family, 'compatibility_state' => 'unavailable',
            'capabilities' => array() );
        return array( 'provider_id' => $family,
            'compatibility_state' => $drift ? 'version_drift' : ( $unattested ? 'compatible_unattested' : 'compatible' ),
            'capabilities' => array( 'cpt_definition_read' => array(
                'risk' => 'read', 'read_eligible' => true,
                'structural_compatible' => true,
                'certification_level' => $drift || $unattested ? 'READ_COMPATIBLE' : 'FULLY_CERTIFIED',
                'certification_source' => $drift || $unattested ? 'structural_compatibility' : 'repository_exact_baseline',
            ) ) );
    }
}
class MAD4B_SCP_Unified_Capability_Gateway {
    public static function dispatch( $input, $transport ) {
        $GLOBALS['gateway_dispatch_calls'][] = $input['action'] ?? 'unknown';
        if ( ! empty( $GLOBALS['gateway_dispatch_denied'] ) )
            return new WP_Error( 'mad4b_gateway_policy_denied', 'Gateway denied.' );
        if ( 'search' === $input['action'] ) return self::search( $input, $transport );
        if ( 'prepare' === $input['action'] ) return self::prepare( $input, $transport );
        return new WP_Error( 'mad4b_gateway_action_invalid' );
    }
    public static function runtime_blog_matches() { return ! empty( $GLOBALS['blog_ok'] ) || ! isset( $GLOBALS['blog_ok'] ); }
    public static function search( $input, $transport ) {
        if ( 'internal' !== $transport ) throw new Exception( 'unexpected transport' );
        $GLOBALS['gateway_readonly_prefilter_requested'] = true === ( $input['declared_readonly_only'] ?? null );
        return array(
            'universe_count' => 644,
            'items' => array(
                array( 'ability_name' => 'jetengine/get-cpt-definition',
                    'label' => 'Get JetEngine CPT definition', 'declared_readonly' => true ),
                array( 'ability_name' => 'woocommerce/update-product',
                    'label' => 'Update WooCommerce Product', 'declared_readonly' => false ),
                array( 'ability_name' => 'fluentforms/list-forms',
                    'label' => 'List Fluent Forms', 'declared_readonly' => true ),
            ),
        );
    }
    public static function prepare( $input, $transport ) {
        $name = $input['ability_names'][0];
        if ( ! empty( $GLOBALS['provider_generation_flip_on_prepare'] ) )
            $GLOBALS['provider_generation_drift'] = true;
        $schema = array( 'type' => 'object', 'additionalProperties' => false,
            'properties' => array( 'post_type' => array(
                'type' => 'string', 'minLength' => 1, 'maxLength' => 20 ) ),
            'required' => array( 'post_type' ) );
        if ( 'rank-math/get-post-schema' === $name ) {
            $schema = array( 'type' => 'object', 'additionalProperties' => false,
                'default' => array(), 'properties' => array(
                    'post_id' => array( 'type' => 'integer' ),
                    'include_available_types' => array( 'type' => 'boolean', 'default' => true ) ) );
        } elseif ( 'elementor/get-dynamic-tags' === $name ) {
            $schema = array( 'type' => 'object', 'additionalProperties' => false,
                'properties' => array( 'post_id' => array( 'type' => 'integer', 'minimum' => 1 ) ) );
        } elseif ( 'fluentforms/list-forms' === $name ) {
            $schema = array( 'type' => 'object', 'additionalProperties' => false,
                'properties' => array( 'limit' => array( 'type' => 'integer',
                    'minimum' => 1, 'maximum' => 100 ) ) );
        } elseif ( 'wpml/status' === $name ) $schema = array();
        if ( ! empty( $GLOBALS['provider_nested'] ) )
            $schema = array( 'type' => 'object', 'additionalProperties' => false,
                'properties' => array( 'settings' => array( 'type' => 'object' ) ) );
        $lane = ! empty( $GLOBALS['provider_write'] ) ? 'write' : 'read';
        return array( 'abilities' => array( array(
            'ability_name' => $name,
            'execution_eligible' => true,
            'execution' => array( 'expected_execution_lane' => $lane ),
            'input_schema_sha256' => str_repeat( 'a', 64 ),
            'descriptor_sha256' => str_repeat( ! empty( $GLOBALS['descriptor_drift'] ) ? 'b' : 'c', 64 ),
            'classification_sha256' => str_repeat( 'd', 64 ),
            'authority_scope_sha256' => str_repeat( 'e', 64 ),
            'schema' => array( 'inputSchema' => $schema ),
        ) ) );
    }
}
class MAD4B_SCP_Site_Capability_Discovery {
    public static function observe( $origin ) {
        return array( 'discovery_complete' => ! isset( $GLOBALS['inventory_ready'] ) || $GLOBALS['inventory_ready'], 'origin' => $origin, 'plugins' => array(),
            'post_types' => array( 'post' ), 'taxonomies' => array( 'category' ),
            'provider_matches' => array(), 'certification_issued' => false, 'authorizing' => false );
    }
}
class Fake_Ability {
    private $args;
    public function __construct( $args ) { $this->args = $args; }
    public function get_meta() { return $this->args['meta']; }
    public function get_input_schema() { if ( ! empty( $this->args['crash_schema'] ) ) throw new Exception( 'provider failed' ); return $this->args['input_schema']; }
    public function get_label() { if ( ! empty( $this->args['crash_label'] ) ) throw new Exception( 'provider failed' ); return $this->args['label'] ?? 'Read ability'; }
}
function mad4b_test( $condition, $name ) {
    if ( ! $condition ) { fwrite( STDERR, "FAIL $name\n" ); exit( 1 ); }
}
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-cso01-read-foundation.php';
$target = 'mad4b/site-info';
$input_schema = array( 'type' => 'object', 'additionalProperties' => false,
    'properties' => array(
        'query' => array( 'type' => 'string', 'maxLength' => 20 ),
        'limit' => array( 'type' => 'integer', 'enum' => array( 1, 2, 3 ) ),
        'confirmed' => array( 'type' => 'boolean' ),
    ), 'required' => array( 'query' ) );
$GLOBALS['abilities'][ $target ] = array( 'input_schema' => $input_schema,
    'label' => 'Inspect site details',
    'meta' => array( 'mcp' => array( 'surface' => 'read' ),
        'annotations' => array( 'readonly' => true ) ) );
MAD4B_SCP_CSO01_Read_Foundation::boot();
mad4b_test( in_array( 'wp_abilities_api_init', $GLOBALS['hooks'], true ), 'register hook missing' );
MAD4B_SCP_CSO01_Read_Foundation::register_abilities();
mad4b_test( 7 === count( MAD4B_SCP_CSO01_Read_Foundation::read_ability_names() ),
    'owned read ability registration not recognized' );
foreach ( array( 'cso/discover', 'cso/form-catalog', 'cso/integration-search', 'cso/integration-inspect', 'cso/form-schema', 'cso/form-validate', 'cso/form-explain' ) as $name ) {
    mad4b_test( wp_has_ability( $name ), 'registered ability missing: ' . $name );
    $args = $GLOBALS['abilities'][ $name ];
    mad4b_test( true === $args['meta']['annotations']['readonly']
        && false === $args['meta']['annotations']['destructive']
        && 'read' === $args['meta']['mcp']['surface'], 'write surface introduced' );
}
$discovered = MAD4B_SCP_CSO01_Read_Foundation::discover();
mad4b_test( ! is_wp_error( $discovered ) && false === $discovered['writes_enabled']
    && false === $discovered['provider_certification_issued']
    && ! isset( $discovered['site']['subject_binding_sha256'] ), 'discovery incorrectly authorized or leaked actor fingerprint' );
$GLOBALS['wp_env'] = 'production';
$divergent = MAD4B_SCP_CSO01_Read_Foundation::discover();
mad4b_test( ! is_wp_error( $divergent )
    && true === $divergent['site']['environment_identity_review_required']
    && 'production' === $divergent['site']['observed_wp_environment']
    && false === $divergent['writes_enabled'],
    'environment divergence hidden or treated as write authority' );
unset( $GLOBALS['wp_env'] );
$GLOBALS['inventory_ready'] = false;
$incomplete = MAD4B_SCP_CSO01_Read_Foundation::discover();
mad4b_test( ! is_wp_error( $incomplete ) && false === $incomplete['discovery_complete']
    && false === $incomplete['writes_enabled'] && false === $incomplete['authorizing']
    && 'reconcile_inventory_evidence_before_any_certification' === $incomplete['next_safe_action'],
    'partial inventory falsely authorized or hidden' );
unset( $GLOBALS['inventory_ready'] );
$GLOBALS['gateway_dispatch_denied'] = true;
$blocked_gateway = MAD4B_SCP_CSO01_Read_Foundation::integration_search( array(
    'task' => 'trip fields' ) );
mad4b_test( is_wp_error( $blocked_gateway ),
    'CSO01 bypassed governed gateway admission or permissions' );
unset( $GLOBALS['gateway_dispatch_denied'] );
$integration = MAD4B_SCP_CSO01_Read_Foundation::integration_search( array( 'task' => 'fields for trip', 'limit' => 5 ) );
mad4b_test( true === ( $GLOBALS['gateway_readonly_prefilter_requested'] ?? false ),
    'CSO01 did not ask shared gateway to filter reads before top ranking' );
mad4b_test( ! is_wp_error( $integration )
    && 644 === $integration['universe_count']
    && 2 === $integration['visible_count']
    && 1 === $integration['nonread_rows_omitted']
    && false === $integration['results_exhaustive']
    && false === $integration['authorizing'],
    'mixed large live integration catalog elevated writes or concealed limits' );
foreach ( array(
    'jetengine/get-cpt-definition' => 1,
    'elementor/get-dynamic-tags' => 1,
    'fluentforms/list-forms' => 1,
    'rank-math/get-post-schema' => 2,
    'wpml/status' => 0,
) as $name => $expected_fields ) {
    $inspected = MAD4B_SCP_CSO01_Read_Foundation::integration_inspect( array( 'ability_name' => $name ) );
    $expected_state = 'jetengine/get-cpt-definition' === $name
        ? 'read_form_prepared' : 'read_schema_preview_uncertified';
    mad4b_test( ! is_wp_error( $inspected ) && $expected_state === $inspected['state']
        && $expected_fields === count( $inspected['fields'] )
        && false === $inspected['execution_performed']
        && false === $inspected['provider_write_certified']
        && ! isset( $inspected['site']['subject_binding_sha256'] ),
        'live provider input Schema integration not safely compatible: ' . $name );
}
$site_provider = MAD4B_SCP_CSO01_Read_Foundation::integration_inspect(
    array( 'ability_name' => 'jetengine/get-cpt-definition' ) );
$provider_valid = MAD4B_SCP_CSO01_Read_Foundation::form_validate( array(
    'ability_name' => 'jetengine/get-cpt-definition',
    'expected_descriptor_sha256' => $site_provider['descriptor_sha256'],
    'values' => array( 'post_type' => 'tour' ) ) );
$GLOBALS['provider_generation_drift'] = true;
$provider_generation_replay = MAD4B_SCP_CSO01_Read_Foundation::form_validate( array(
    'ability_name' => 'jetengine/get-cpt-definition',
    'expected_descriptor_sha256' => $site_provider['descriptor_sha256'],
    'values' => array( 'post_type' => 'tour' ) ) );
mad4b_test( is_wp_error( $provider_generation_replay )
    && 'mad4b_cso01_stale_descriptor' === $provider_generation_replay->get_error_code(),
    'provider recertification generation did not fence stale form' );
unset( $GLOBALS['provider_generation_drift'] );
$GLOBALS['provider_generation_flip_on_prepare'] = true;
$midflight = MAD4B_SCP_CSO01_Read_Foundation::integration_inspect(
    array( 'ability_name' => 'jetengine/get-cpt-definition' ) );
mad4b_test( is_wp_error( $midflight )
    && 'mad4b_cso01_provider_changed_during_preparation' === $midflight->get_error_code(),
    'provider changed after preflight but returned ready form' );
unset( $GLOBALS['provider_generation_flip_on_prepare'], $GLOBALS['provider_generation_drift'] );
$GLOBALS['descriptor_drift'] = true;
$provider_replay = MAD4B_SCP_CSO01_Read_Foundation::form_validate( array(
    'ability_name' => 'jetengine/get-cpt-definition',
    'expected_descriptor_sha256' => $site_provider['descriptor_sha256'],
    'values' => array( 'post_type' => 'tour' ) ) );
mad4b_test( is_wp_error( $provider_replay )
    && 'mad4b_cso01_stale_descriptor' === $provider_replay->get_error_code(),
    'provider generation change did not invalidate client descriptor' );
unset( $GLOBALS['descriptor_drift'] );
$provider_missing = MAD4B_SCP_CSO01_Read_Foundation::form_validate( array(
    'ability_name' => 'jetengine/get-cpt-definition',
    'expected_descriptor_sha256' => $site_provider['descriptor_sha256'],
    'values' => array( 'post_type' => '' ) ) );
mad4b_test( ! is_wp_error( $provider_valid ) && true === $provider_valid['valid']
    && false === $provider_valid['saved'] && false === $provider_valid['authorizing']
    && false === $provider_valid['execution_approved']
    && false === $provider_missing['valid'],
    'provider form was not validated with its prepared exact descriptor' );
$provider_help = MAD4B_SCP_CSO01_Read_Foundation::form_explain( array(
    'ability_name' => 'jetengine/get-cpt-definition', 'field' => 'post_type' ) );
mad4b_test( ! is_wp_error( $provider_help ) && false === $provider_help['write_supported'],
    'cross-plugin form explanation escalated to editing' );
$wpml_form = MAD4B_SCP_CSO01_Read_Foundation::integration_inspect(
    array( 'ability_name' => 'wpml/status' ) );
$wpml_check = MAD4B_SCP_CSO01_Read_Foundation::form_validate( array(
    'ability_name' => 'wpml/status',
    'expected_descriptor_sha256' => $wpml_form['descriptor_sha256'],
    'values' => array() ) );
mad4b_test( ! is_wp_error( $wpml_check ) && true === $wpml_check['valid']
    && false === $wpml_check['saved']
    && 'family_certification_unavailable' === $wpml_check['provider_readiness']['state'],
    'no-parameter read-only WPML form unusable or falsely runtime certified' );
$woo = MAD4B_SCP_CSO01_Read_Foundation::integration_inspect( array(
    'ability_name' => 'woocommerce/get-product' ) );
mad4b_test( ! is_wp_error( $woo )
    && 'provider_unavailable' === $woo['state']
    && false === $woo['form_ready'],
    'unavailable WooCommerce adapter falsely presented as live ready form' );
$GLOBALS['jetengine_unattested'] = true;
$unattested = MAD4B_SCP_CSO01_Read_Foundation::integration_inspect(
    array( 'ability_name' => 'jetengine/get-cpt-definition' ) );
mad4b_test( ! is_wp_error( $unattested )
    && 'read_schema_preview_uncertified' === $unattested['state']
    && true === $unattested['form_ready']
    && false === $unattested['read_execution_certified']
    && 'structural_read_preview_unattested' === $unattested['provider_readiness']['state'],
    'read-compatible but unattested provider incorrectly promoted to execution certified' );
unset( $GLOBALS['jetengine_unattested'] );
$GLOBALS['jetengine_version_drift'] = true;
$jet_drift = MAD4B_SCP_CSO01_Read_Foundation::integration_inspect( array(
    'ability_name' => 'jetengine/get-cpt-definition' ) );
mad4b_test( ! is_wp_error( $jet_drift )
    && 'provider_version_drift' === $jet_drift['state']
    && false === $jet_drift['form_ready']
    && 'recertify_exact_provider_runtime' === $jet_drift['next_safe_action'],
    'JetEngine version drift did not block implied form readiness' );
unset( $GLOBALS['jetengine_version_drift'] );
$wpml_preview = MAD4B_SCP_CSO01_Read_Foundation::integration_inspect( array(
    'ability_name' => 'wpml/status' ) );
mad4b_test( ! is_wp_error( $wpml_preview )
    && false === $wpml_preview['read_execution_certified']
    && 'family_certification_unavailable' === $wpml_preview['provider_readiness']['state'],
    'uncertified read-only family falsely claimed provider certification' );
mad4b_test( in_array( 'search', $GLOBALS['gateway_dispatch_calls'], true )
    && in_array( 'prepare', $GLOBALS['gateway_dispatch_calls'], true ),
    'search/prepare bypassed governed gateway dispatch' );
$GLOBALS['gateway_dispatch_denied'] = true;
$blocked_prepare = MAD4B_SCP_CSO01_Read_Foundation::integration_inspect(
    array( 'ability_name' => 'jetengine/get-cpt-definition' ) );
mad4b_test( is_wp_error( $blocked_prepare ),
    'governed gateway refused preparation but form still prepared' );
unset( $GLOBALS['gateway_dispatch_denied'] );
$GLOBALS['long_ability_fixture'] = array(
    str_repeat( 'a', 95 ) . '1/' . str_repeat( 'b', 95 ) . '2',
    'my_plugin.api/read_status.v2',
);
foreach ( $GLOBALS['long_ability_fixture'] as $canonical_name ) {
    $preview = MAD4B_SCP_CSO01_Read_Foundation::integration_inspect(
        array( 'ability_name' => $canonical_name ) );
    mad4b_test( ! is_wp_error( $preview ) && true === $preview['form_ready']
        && 'read_schema_preview_uncertified' === $preview['state'],
        'canonical long or punctuation Ability name was rejected: ' . strlen( $canonical_name ) );
    $validation = MAD4B_SCP_CSO01_Read_Foundation::form_validate( array(
        'ability_name' => $canonical_name,
        'expected_descriptor_sha256' => $preview['descriptor_sha256'],
        'values' => array( 'post_type' => 'tour' ) ) );
    mad4b_test( ! is_wp_error( $validation ) && true === $validation['valid']
        && false === $validation['execution_approved'],
        'canonical provider Ability name cannot validate same prepared form' );
}
mad4b_test( 193 === $GLOBALS['abilities']['cso/form-validate']['input_schema']['properties']['ability_name']['maxLength']
    && 193 === $GLOBALS['abilities']['cso/integration-inspect']['input_schema']['properties']['ability_name']['maxLength'],
    'WordPress input Schema still truncates canonical Ability names' );
unset( $GLOBALS['long_ability_fixture'] );
$GLOBALS['provider_write'] = true;
mad4b_test( is_wp_error( MAD4B_SCP_CSO01_Read_Foundation::integration_inspect(
    array( 'ability_name' => 'jetengine/get-cpt-definition' ) ) ),
    'write-classified provider was exposed as a read form' );
unset( $GLOBALS['provider_write'] );
$GLOBALS['provider_nested'] = true;
$complex = MAD4B_SCP_CSO01_Read_Foundation::integration_inspect( array(
    'ability_name' => 'jetengine/get-cpt-definition' ) );
mad4b_test( ! is_wp_error( $complex ) && 'unsupported_schema' === $complex['state']
    && false === $complex['form_ready'], 'unknown nested plugin form falsely enabled' );
unset( $GLOBALS['provider_nested'] );
mad4b_test( is_wp_error( MAD4B_SCP_CSO01_Read_Foundation::integration_inspect( array(
    'ability_name' => 'untrusted/random-ability' ) ) ),
    'unknown provider name auto-certified from WordPress registration' );

$catalog = MAD4B_SCP_CSO01_Read_Foundation::form_catalog( array( 'query' => 'site', 'page' => 0 ) );
mad4b_test( ! is_wp_error( $catalog ) && 1 === $catalog['total_matches']
    && true === $catalog['items'][0]['form_ready']
    && 'mad4b/site-info' === $catalog['items'][0]['ability_name']
    && 'rtl' === $catalog['ui']['direction'] && false === $catalog['ui']['can_save']
    && ! isset( $catalog['site']['subject_binding_sha256'] ),
    'catalog search or RTL guidance failed' );
$GLOBALS['abilities'][ $target ]['label'] = 'إعدادات الرِّحلة';
$ar_catalog = MAD4B_SCP_CSO01_Read_Foundation::form_catalog( array( 'query' => 'اعدادات الرحلة' ) );
mad4b_test( ! is_wp_error( $ar_catalog ) && 1 === $ar_catalog['total_matches']
    && true === $ar_catalog['items'][0]['form_ready'],
    'Arabic diacritics and alef variants blocked human-form search' );
$GLOBALS['abilities'][ $target ]['label'] = 'Inspect site details';
mad4b_test( is_wp_error( MAD4B_SCP_CSO01_Read_Foundation::form_catalog( array( 'page' => -1 ) ) ),
    'negative catalog page accepted' );
mad4b_test( is_wp_error( MAD4B_SCP_CSO01_Read_Foundation::form_catalog( array( 'query' => array() ) ) ),
    'untyped catalog query accepted' );
$form = MAD4B_SCP_CSO01_Read_Foundation::form_schema( array( 'ability_name' => $target ) );
mad4b_test( ! is_wp_error( $form ) && false === $form['editable']
    && 64 === strlen( $form['descriptor_sha256'] ) && 3 === count( $form['fields'] )
    && 'rtl' === $form['ui']['direction'] && 'text' === $form['fields'][0]['control']
    && ! isset( $form['site']['subject_binding_sha256'] ),
    'bounded form failed' );
$arg = array( 'ability_name' => $target,
    'expected_descriptor_sha256' => $form['descriptor_sha256'],
    'values' => array( 'query' => 'hello', 'limit' => 2, 'confirmed' => false ) );
$ok = MAD4B_SCP_CSO01_Read_Foundation::form_validate( $arg );
$arg['values']['query'] = 'رحلة';
$arabic = MAD4B_SCP_CSO01_Read_Foundation::form_validate( $arg );
mad4b_test( ! is_wp_error( $arabic ) && true === $arabic['valid'], 'Arabic Unicode text rejected' );
$arg['values']['query'] = str_repeat( 'ر', 21 );
$long = MAD4B_SCP_CSO01_Read_Foundation::form_validate( $arg );
mad4b_test( ! is_wp_error( $long ) && false === $long['valid'], 'Unicode overflow accepted' );
$arg['values']['query'] = 'hello';
mad4b_test( ! is_wp_error( $ok ) && true === $ok['valid'] && false === $ok['saved'], 'stateless validation failed' );
$GLOBALS['site_origin'] = 'https://another.example.org';
$other_origin = MAD4B_SCP_CSO01_Read_Foundation::form_validate( $arg );
mad4b_test( is_wp_error( $other_origin )
    && 'mad4b_cso01_stale_descriptor' === $other_origin->get_error_code(),
    'cross-origin descriptor replay allowed' );
$GLOBALS['site_origin'] = 'https://staging.example.org';
$GLOBALS['current_blog'] = 4;
$other_blog = MAD4B_SCP_CSO01_Read_Foundation::form_validate( $arg );
mad4b_test( is_wp_error( $other_blog )
    && 'mad4b_cso01_stale_descriptor' === $other_blog->get_error_code(),
    'cross-blog descriptor replay allowed' );
unset( $GLOBALS['current_blog'] );
$GLOBALS['actor_id'] = 8;
$replay = MAD4B_SCP_CSO01_Read_Foundation::form_validate( $arg );
mad4b_test( is_wp_error( $replay ) && 'mad4b_cso01_stale_descriptor' === $replay->get_error_code(),
    'cross-actor form descriptor replay was accepted' );
$GLOBALS['actor_id'] = 7;
$arg['values']['unexpected'] = 'DO_NOT_ECHO';
$invalid = MAD4B_SCP_CSO01_Read_Foundation::form_validate( $arg );
mad4b_test( ! is_wp_error( $invalid ) && false === $invalid['valid']
    && false === strpos( wp_json_encode( $invalid ), 'DO_NOT_ECHO' )
    && 'remove_unknown_field' === $invalid['field_errors']['__form'], 'hidden input leaked or accepted' );
$arg['values'] = array( 'limit' => 3 );
$missing = MAD4B_SCP_CSO01_Read_Foundation::form_validate( $arg );
mad4b_test( false === $missing['valid'] && in_array( 'required_field_absent', $missing['issues'], true ),
    'missing required field accepted' );
mad4b_test( 'fill_required_field' === $missing['field_errors']['query'], 'missing field recovery hint' );
$arg['values'] = array( 'query' => 'ok', 'limit' => 50 );
$enum = MAD4B_SCP_CSO01_Read_Foundation::form_validate( $arg );
mad4b_test( false === $enum['valid'], 'enum bypassed' );
$arg['expected_descriptor_sha256'] = str_repeat( 'b', 64 );
$stale = MAD4B_SCP_CSO01_Read_Foundation::form_validate( $arg );
mad4b_test( is_wp_error( $stale ) && 'mad4b_cso01_stale_descriptor' === $stale->get_error_code(), 'stale descriptor accepted' );
$explain = MAD4B_SCP_CSO01_Read_Foundation::form_explain( array( 'ability_name' => $target, 'field' => 'query' ) );
mad4b_test( ! is_wp_error( $explain ) && false === $explain['write_supported'], 'explain claimed write' );
mad4b_test( is_wp_error( MAD4B_SCP_CSO01_Read_Foundation::form_explain( array( 'ability_name' => $target, 'field' => 'private_field' ) ) ), 'unknown help disclosed' );
foreach ( array( 'api_key', 'Password', 'bearer_token', 'secretField' ) as $secret_key ) {
    $schema = $input_schema;
    $schema['properties'][ $secret_key ] = array( 'type' => 'string' );
    mad4b_test( is_wp_error( MAD4B_SCP_CSO01_Read_Foundation::compile_schema( $schema ) ), 'secret key exposed' );
}
$schema = $input_schema; $schema['additionalProperties'] = true;
mad4b_test( is_wp_error( MAD4B_SCP_CSO01_Read_Foundation::compile_schema( $schema ) ), 'unbounded object allowed' );
$schema = $input_schema; $schema['properties']['nested'] = array( 'type' => 'object' );
mad4b_test( is_wp_error( MAD4B_SCP_CSO01_Read_Foundation::compile_schema( $schema ) ), 'nested arbitrary write form allowed' );
$schema = $input_schema;
$schema['properties']['query']['default'] = 'DO_NOT_ECHO';
$has_default = MAD4B_SCP_CSO01_Read_Foundation::compile_schema( $schema );
mad4b_test( ! is_wp_error( $has_default )
    && true === $has_default[0]['has_default']
    && false === $has_default[0]['default_value_exposed']
    && false === strpos( wp_json_encode( $has_default ), 'DO_NOT_ECHO' ),
    'provider default was echoed into the conversational form' );
$schema = $input_schema;
$schema['properties']['query']['pattern'] = '^[A-Z]+$';
mad4b_test( is_wp_error( MAD4B_SCP_CSO01_Read_Foundation::compile_schema( $schema ) ), 'ignored pattern accepted' );
$schema = $input_schema;
$schema['properties']['limit']['minimum'] = 2;
mad4b_test( is_wp_error( MAD4B_SCP_CSO01_Read_Foundation::compile_schema( $schema ) ),
    'enum contradicted minimum without rejection' );
$schema = array( 'type' => 'object', 'additionalProperties' => false,
    'properties' => array(
        'post_id' => array( 'type' => 'integer', 'minimum' => 1 ),
        'post_type' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 20 ),
        'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ),
        'include_available_types' => array( 'type' => 'boolean', 'default' => true ),
    ), 'default' => array() );
$provider_form = MAD4B_SCP_CSO01_Read_Foundation::compile_schema( $schema );
mad4b_test( ! is_wp_error( $provider_form ) && 4 === count( $provider_form )
    && 1 === $provider_form[0]['minimum']
    && 1 === $provider_form[1]['min_length']
    && 100 === $provider_form[2]['maximum']
    && true === $provider_form[3]['has_default']
    && false === $provider_form[3]['default_value_exposed'],
    'live Elementor JetEngine Fluent Forms Rank Math constraint patterns unsupported' );
$range_bad = MAD4B_SCP_CSO01_Read_Foundation::validate_values( $provider_form,
    array( 'post_id' => 0, 'post_type' => '', 'limit' => 101 ) );
$range_ok = MAD4B_SCP_CSO01_Read_Foundation::validate_values( $provider_form,
    array( 'post_id' => 4, 'post_type' => 'tour', 'limit' => 20 ) );
mad4b_test( false === $range_bad['valid'] && true === $range_ok['valid'],
    'provider bounds silently ignored in actual values validation' );
$empty_ability = MAD4B_SCP_CSO01_Read_Foundation::compile_schema( array() );
mad4b_test( ! is_wp_error( $empty_ability ) && array() === $empty_ability,
    'WPML no-input read ability was rejected' );
$schema = $input_schema;
$schema['if'] = array( 'properties' => array() );
mad4b_test( is_wp_error( MAD4B_SCP_CSO01_Read_Foundation::compile_schema( $schema ) ), 'root conditional ignored' );
$schema = $input_schema;
$schema['properties']['query']['enum'] = array( 'short', 'this option is far longer than twenty characters' );
mad4b_test( is_wp_error( MAD4B_SCP_CSO01_Read_Foundation::compile_schema( $schema ) ),
    'impossible overlength dropdown option accepted' );
$schema = $input_schema;
$schema['properties']['limit']['enum'] = array( 1, 1 );
mad4b_test( is_wp_error( MAD4B_SCP_CSO01_Read_Foundation::compile_schema( $schema ) ),
    'duplicate dropdown choice accepted' );
$schema = $input_schema;
$schema['properties']['limit']['enum'] = array();
mad4b_test( is_wp_error( MAD4B_SCP_CSO01_Read_Foundation::compile_schema( $schema ) ),
    'empty dropdown was advertised as valid' );
$GLOBALS['lane'] = 'write';
mad4b_test( is_wp_error( MAD4B_SCP_CSO01_Read_Foundation::form_schema( array( 'ability_name' => $target ) ) ), 'write lane accepted' );
unset( $GLOBALS['lane'] );
$GLOBALS['can_read'] = false;
mad4b_test( is_wp_error( MAD4B_SCP_CSO01_Read_Foundation::discover() ), 'unauthorized discovery accepted' );
$GLOBALS['can_read'] = true;
$GLOBALS['actor_enrolled'] = false;
mad4b_test( is_wp_error( MAD4B_SCP_CSO01_Read_Foundation::discover() ), 'unenrolled actor accepted' );
$GLOBALS['actor_enrolled'] = true;
$GLOBALS['enrolled'] = false;
mad4b_test( is_wp_error( MAD4B_SCP_CSO01_Read_Foundation::discover() ), 'unenrolled site accepted' );
$GLOBALS['enrolled'] = true;
$GLOBALS['blog_ok'] = false;
mad4b_test( is_wp_error( MAD4B_SCP_CSO01_Read_Foundation::discover() ), 'blog switch accepted' );
$GLOBALS['blog_ok'] = true;

$GLOBALS['profile_env'] = 'local';
$GLOBALS['site_origin'] = 'http://localhost:8080';
$local = MAD4B_SCP_CSO01_Read_Foundation::discover();
mad4b_test( ! is_wp_error( $local ) && 'local' === $local['site']['environment'],
    'enrolled local WordPress loopback was incorrectly blocked' );
$GLOBALS['profile_env'] = 'staging';
$remote_http = MAD4B_SCP_CSO01_Read_Foundation::discover();
mad4b_test( is_wp_error( $remote_http ), 'Staging silently accepted unsafe HTTP origin' );
$GLOBALS['site_origin'] = 'https://staging.example.org';
$GLOBALS['abilities']['evil/arbitrary-write'] = $GLOBALS['abilities'][ $target ];
mad4b_test( is_wp_error( MAD4B_SCP_CSO01_Read_Foundation::form_schema( array( 'ability_name' => 'evil/arbitrary-write' ) ) ),
    'untrusted registered plugin ability auto-certified' );
$GLOBALS['extra_tools'] = array();
for ( $idx = 0; $idx < 14; $idx++ ) {
    $name = sprintf( 'mad4b/read-%02d', $idx );
    $GLOBALS['extra_tools'][] = $name;
    $GLOBALS['abilities'][ $name ] = $GLOBALS['abilities'][ $target ];
}
$p0 = MAD4B_SCP_CSO01_Read_Foundation::form_catalog( array( 'page' => 0 ) );
$p1 = MAD4B_SCP_CSO01_Read_Foundation::form_catalog( array( 'page' => 1, 'expected_catalog_sha256' => $p0['catalog_sha256'] ) );
$missing_page_hash = MAD4B_SCP_CSO01_Read_Foundation::form_catalog( array( 'page' => 1 ) );
mad4b_test( is_wp_error( $missing_page_hash ), 'unfenced pagination accepted' );
$stale_page = MAD4B_SCP_CSO01_Read_Foundation::form_catalog( array( 'page' => 1,
    'expected_catalog_sha256' => str_repeat( 'b', 64 ) ) );
mad4b_test( is_wp_error( $stale_page ) && 'mad4b_cso01_catalog_changed' === $stale_page->get_error_code(),
    'catalog drift accepted' );
mad4b_test( ! is_wp_error( $p0 ) && ! is_wp_error( $p1 )
    && 64 === strlen( $p0['catalog_sha256'] )
    && 12 === count( $p0['items'] ) && 3 === count( $p1['items'] )
    && true === $p0['has_more'] && false === $p1['has_more'],
    'catalog pagination and bounded ordering failed' );
unset( $GLOBALS['extra_tools'] );
$GLOBALS['extra_tools'] = array( 'mad4b/broken-label', 'mad4b/broken-schema' );
$GLOBALS['abilities']['mad4b/broken-label'] = $GLOBALS['abilities'][ $target ];
$GLOBALS['abilities']['mad4b/broken-label']['crash_label'] = true;
$GLOBALS['abilities']['mad4b/broken-schema'] = $GLOBALS['abilities'][ $target ];
$GLOBALS['abilities']['mad4b/broken-schema']['crash_schema'] = true;
$isolated = MAD4B_SCP_CSO01_Read_Foundation::form_catalog();
mad4b_test( ! is_wp_error( $isolated )
    && 2 === $isolated['total_matches']
    && false === $isolated['items'][0]['form_ready']
    && true === $isolated['items'][1]['form_ready'],
    'broken provider corrupted catalog or hid all safe forms' );
$broken = MAD4B_SCP_CSO01_Read_Foundation::form_schema( array( 'ability_name' => 'mad4b/broken-schema' ) );
mad4b_test( is_wp_error( $broken ), 'provider schema exception propagated instead of blocked' );
unset( $GLOBALS['extra_tools'] );
$GLOBALS['extra_tools'] = array();
for ( $i = 0; $i < 270; $i++ ) $GLOBALS['extra_tools'][] = sprintf( 'mad4b/large-%03d', $i );
$GLOBALS['extra_tools'][] = 'mad4b/searchable';
$GLOBALS['abilities']['mad4b/searchable'] = $GLOBALS['abilities'][ $target ];
mad4b_test( is_wp_error( MAD4B_SCP_CSO01_Read_Foundation::form_catalog() ),
    'unbounded catalog scan allowed' );
$narrowed = MAD4B_SCP_CSO01_Read_Foundation::form_catalog( array( 'query' => 'searchable' ) );
mad4b_test( ! is_wp_error( $narrowed ) && 1 === $narrowed['total_matches']
    && true === $narrowed['name_only_filter_due_to_large_catalog']
    && true === $narrowed['items'][0]['form_ready'],
    'large WordPress site was not safely searchable' );
unset( $GLOBALS['extra_tools'] );
$GLOBALS['extra_tools'] = array( 'mad4b/bad-utf8' );
$GLOBALS['abilities']['mad4b/bad-utf8'] = $GLOBALS['abilities'][ $target ];
$GLOBALS['abilities']['mad4b/bad-utf8']['label'] = "\xFF";
$utf8_catalog = MAD4B_SCP_CSO01_Read_Foundation::form_catalog();
mad4b_test( ! is_wp_error( $utf8_catalog ) && 2 === $utf8_catalog['total_matches'],
    'malformed plugin label made all site forms unavailable' );
unset( $GLOBALS['extra_tools'] );

$secret_input = MAD4B_SCP_CSO01_Read_Foundation::integration_search( array(
    'task' => 'api_key=' . str_repeat( 'A', 32 ) ) );
mad4b_test( is_wp_error( $secret_input )
    && 'mad4b_cso01_integration_query_secret_denied' === $secret_input->get_error_code(),
    'secret-like discovery payload reached provider search' );
$bad_enum = array( 'type' => 'object', 'additionalProperties' => false,
    'properties' => array( 'choice' => array( 'type' => 'string',
        'enum' => array( 'normal', 'person@example.com' ) ) ) );
mad4b_test( is_wp_error( MAD4B_SCP_CSO01_Read_Foundation::compile_schema( $bad_enum ) ),
    'personal email address was exposed in unclassified vendor enum' );
$bad_enum['properties']['choice']['enum'] = array( '<script>alert(1)</script>' );
mad4b_test( is_wp_error( MAD4B_SCP_CSO01_Read_Foundation::compile_schema( $bad_enum ) ),
    'untrusted HTML value was exposed as conversational select choice' );
$bad_enum['properties']['choice']['enum'] = array( 'city-cairo', 'city-aswan' );
mad4b_test( ! is_wp_error( MAD4B_SCP_CSO01_Read_Foundation::compile_schema( $bad_enum ) ),
    'safe deterministic vendor enum was unnecessarily rejected' );
echo "mad4b.cso01.read-foundation.runtime.v1: PASS\n";
