<?php
/**
 * Read-only live WordPress regression: unlimited universe, cursor pages,
 * independent tool quarantine, bounded mutation batches, unchanged authority.
 */
if ( ! defined( 'ABSPATH' ) ) throw new RuntimeException( 'WordPress bootstrap required.' );
$assert = static function ( $condition, $message ) {
    if ( ! $condition ) throw new RuntimeException( $message );
};

$all = function_exists( 'wp_get_abilities' ) ? wp_get_abilities() : array();
$assert( count( $all ) > 100, 'Ability universe unexpectedly small.' );

$first = MAD4B_SCP_Unified_Capability_Gateway::search( array(
    'browse' => true, 'limit' => 25, 'sort' => 'name',
), 'internal' );
$assert( is_array( $first ) && count( $first['items'] ) === 25 && ! empty( $first['next_cursor'] ), 'First cursor page unavailable.' );
$second = MAD4B_SCP_Unified_Capability_Gateway::search( array(
    'browse' => true, 'limit' => 25, 'sort' => 'name', 'cursor' => $first['next_cursor'],
), 'internal' );
$assert( is_array( $second ) && count( $second['items'] ) === 25, 'Second cursor page unavailable.' );
$first_names = array_column( $first['items'], 'ability_name' );
$second_names = array_column( $second['items'], 'ability_name' );
$assert( ! array_intersect( $first_names, $second_names ), 'Cursor pages overlap.' );
$assert( (int) $first['total_match_count'] === count( $all ), 'Search omitted registered Abilities.' );

$base = MAD4B_SCP_Servers::chatgpt_tools();
$optional = MAD4B_SCP_Servers::chatgpt_materialization_optional_tools( $base );
$base_check = MAD4B_SCP_MCP_Catalog_Diagnostics::preflight( $base, $optional );
$assert( ! empty( $base_check['ready'] ) && count( $base_check['tools'] ) === count( $base ), 'Base catalog failed preflight.' );

$extra = array();
$registered_names = array_keys( $all );
sort( $registered_names, SORT_STRING );
foreach ( $registered_names as $name ) {
    if ( count( $extra ) >= 170 ) break;
    if ( 0 !== strpos( $name, 'mad4b/' ) || in_array( $name, $base, true ) ) continue;
    $extra[] = $name;
}
$sample = array_merge( $base, $extra );
$assert( count( $sample ) > 64, 'Insufficient Abilities for historical-cap regression.' );
$large = MAD4B_SCP_MCP_Catalog_Diagnostics::preflight( $sample, array() );
$assert( ! empty( $large['ready'] ) && count( $large['tools'] ) > 64, 'Valid Abilities still blocked above 64.' );
$assert( (int) $large['serialized_tool_bytes'] > 98304, 'Legacy total catalog byte cap not exceeded in test.' );

$absent = 'mad4b/not-a-registered-ability-fixture';
$quarantine = MAD4B_SCP_MCP_Catalog_Diagnostics::preflight( array_merge( $base, array( $absent ) ), $optional );
$assert( ! empty( $quarantine['ready'] ) && ! empty( $quarantine['degraded'] ), 'One missing Ability poisoned healthy catalog.' );
$assert( count( $quarantine['tools'] ) === count( $base ) && ! in_array( $absent, $quarantine['tools'], true ), 'Unregistered Ability became executable.' );
$invalid_only = MAD4B_SCP_MCP_Catalog_Diagnostics::preflight( array( $absent ), array() );
$assert( empty( $invalid_only['ready'] ), 'Empty catalog failed open.' );

$projection = new ReflectionMethod( 'MAD4B_SCP_ChatGPT_Tool_Projection', 'desired_names' );
$batch = array();
for ( $i = 0; $i < 64; $i++ ) $batch[] = 'fixture/ability-' . $i;
$allowed = $projection->invoke( null, array( 'mode' => 'replace', 'ability_names' => $batch ) );
$assert( is_array( $allowed ) && 64 === count( $allowed ), 'Bounded batch rejected.' );
// Simulate an existing projection with 100 records through a request-local
// option read filter. No persistent option or WordPress data is modified.
$mock = array( 'contract' => MAD4B_SCP_ChatGPT_Tool_Projection::CONTRACT,
    'revision' => 4, 'abilities' => array(), 'updated_at' => '' );
for ( $i = 0; $i < 100; ++$i ) $mock['abilities']['fixture/saved-' . $i] = array( 'eligible' => true );
$override = static function ( $pre ) use ( $mock ) { return $mock; };
$option_filter = 'pre_option_' . MAD4B_SCP_ChatGPT_Tool_Projection::OPTION;
add_filter( $option_filter, $override, PHP_INT_MAX );
$raw_state_method = new ReflectionMethod( 'MAD4B_SCP_ChatGPT_Tool_Projection', 'raw_state' );
$fake_state = $raw_state_method->invoke( null );
remove_filter( $option_filter, $override, PHP_INT_MAX );
$assert( 100 === count( $fake_state['abilities'] ), 'Saved projection was truncated above 64.' );

$batch[] = 'fixture/ability-64';
$denied = $projection->invoke( null, array( 'mode' => 'replace', 'ability_names' => $batch ) );
$assert( is_wp_error( $denied ) && 'mad4b_chatgpt_projection_change_batch_too_large' === $denied->get_error_code(), 'Oversized single request accepted.' );

echo wp_json_encode( array(
    'state' => 'PASS',
    'ability_universe_count' => count( $all ),
    'first_page' => count( $first_names ),
    'second_page' => count( $second_names ),
    'validated_large_sample' => count( $large['tools'] ),
    'large_sample_wire_bytes' => $large['serialized_tool_bytes'],
    'quarantine_preserved' => count( $quarantine['tools'] ),
    'max_change_batch' => 64,
    'mutation_performed' => false,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), PHP_EOL;
