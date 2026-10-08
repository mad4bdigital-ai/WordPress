<?php
/**
 * Read-only real WordPress acceptance: wp eval-file tests/assistant-wordpress-integration-readonly.php
 * Run ONLY on a disposable/exact-deployed WordPress host, with the plugin active.
 * This never registers fake abilities, calls installers or mutates WordPress state.
 */
if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, 'WORDPRESS_RUNTIME_REQUIRED' . PHP_EOL ); exit( 2 ); }
function mad4b_assistant_native_check( $condition, $message ) {
    if ( ! $condition ) throw new RuntimeException( 'ASSISTANT_NATIVE_ACCEPTANCE_FAIL:' . $message );
}
mad4b_assistant_native_check( class_exists( 'MAD4B_SCP_Assistant_Planning', false ), 'planning_class_not_loaded' );
mad4b_assistant_native_check( class_exists( 'MAD4B_SCP_Assistant_Bootstrap_Diagnostic', false ), 'diagnostic_class_not_loaded' );
mad4b_assistant_native_check( class_exists( 'MAD4B_SCP_Adapter_Registry', false ), 'adapter_registry_not_loaded' );
mad4b_assistant_native_check( function_exists( 'wp_has_ability' ) && function_exists( 'did_action' ), 'native_abilities_api_unavailable' );
mad4b_assistant_native_check( did_action( 'wp_abilities_api_init' ) > 0, 'native_abilities_lifecycle_not_observed' );
foreach ( array(
    'mad4b/assistant-plan' => array( 'MAD4B_SCP_Assistant_Planning', 'register_ability' ),
    'mad4b/assistant-bootstrap-diagnostic' => array( 'MAD4B_SCP_Assistant_Bootstrap_Diagnostic', 'register_ability' ),
) as $name => $callback ) {
    mad4b_assistant_native_check( false !== has_action( 'wp_abilities_api_init', $callback ), 'hook_missing:' . $name );
    mad4b_assistant_native_check( wp_has_ability( $name ), 'ability_not_registered:' . $name );
}
$registry = MAD4B_SCP_Adapter_Registry::instance();
foreach ( array(
    'assistant-planning' => 'mad4b/assistant-plan',
    'assistant-bootstrap' => 'mad4b/assistant-bootstrap-diagnostic',
) as $id => $ability ) {
    $adapter = $registry->get( $id );
    mad4b_assistant_native_check( null !== $adapter, 'adapter_not_registered:' . $id );
    $names = $adapter->ability_names();
    mad4b_assistant_native_check( array( $ability ) === $names['read'] &&
        array() === $names['content'] && array() === $names['admin'], 'adapter_authority_widened:' . $id );
}
$bad_plan = MAD4B_SCP_Assistant_Planning::read_plan( array( 'execute' => true ) );
mad4b_assistant_native_check( is_wp_error( $bad_plan ) && 'mad4b_assistant_input_invalid' === $bad_plan->get_error_code(), 'unsafe_planning_input_not_denied' );
$bad_bootstrap = MAD4B_SCP_Assistant_Bootstrap_Diagnostic::status( array( 'install' => true ) );
mad4b_assistant_native_check( is_wp_error( $bad_bootstrap ) &&
    'mad4b_assistant_bootstrap_input_invalid' === $bad_bootstrap->get_error_code(), 'unsafe_bootstrap_input_not_denied' );
$witness = MAD4B_SCP_Assistant_Bootstrap_Diagnostic::status();
mad4b_assistant_native_check( is_array( $witness ) && ! empty( $witness['assistant_read_registration']['read_catalog_local_ready'] ) &&
    empty( $witness['assistant_read_registration']['external_mcp_catalog_verified'] ) &&
    empty( $witness['ready_for_mutation'] ), 'native_local_catalog_or_scope_unverified' );
echo wp_json_encode( array(
    'contract' => 'mad4b.assistant-wordpress-readonly-acceptance.v1',
    'result' => 'LOCAL_NATIVE_REGISTRATION_PASS',
    'php' => PHP_VERSION,
    'wordpress' => get_bloginfo( 'version' ),
    'bootstrap_source_sha256' => is_readable( MAD4B_SCP_FILE ) ? hash_file( 'sha256', MAD4B_SCP_FILE ) : '',
    'mcp_external_handshake_certified' => false,
    'provider_execution_certified' => false,
    'mutation_performed' => false,
    'release_certified' => false,
), JSON_UNESCAPED_SLASHES ) . PHP_EOL;
