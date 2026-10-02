<?php
// Disposable WordPress only: exercise real database purge and exact-plan denial.
define( 'MAD4B_CATALOG_DECOMMISSION_LIBRARY', true );
require __DIR__ . '/../tools/catalog-decommission.php';
$assert = static function( $condition, $message ) { if ( ! $condition ) throw new RuntimeException( $message ); };
$denied = static function( $callback ) { try { $callback(); } catch ( RuntimeException $error ) { return true; } return false; };
$plugin = 'mad4b-site-control-plane/mad4b-site-control-plane.php';
$maintenance = ABSPATH . '.maintenance';
$sentinel = 'mad4b_ci_retained_authority'; add_option( $sentinel, array( 'grant' => 'must survive' ), '', false );
try {
 $assert( $denied( static function() { MAD4B_Catalog_Decommission::plan(); } ), 'Online purge planning accepted' );
 file_put_contents( $maintenance, '<?php $upgrading = ' . time() . ';' );
 $assert( $denied( static function() { MAD4B_Catalog_Decommission::plan(); } ), 'Active control plane retirement accepted' );
 deactivate_plugins( $plugin );
 $payload = 'mad4b_ct2_' . time() . '_' . str_repeat( 'a', 32 ); add_option( $payload, array( 'private_schema' => true ), '', false );
 $plan = MAD4B_Catalog_Decommission::plan(); $sha = MAD4B_Catalog_Decommission::digest( $plan );
 $assert( $denied( static function() use( $plan, $sha ) { MAD4B_Catalog_Decommission::apply( $plan, $sha ); } ), 'Reader drain omitted' );
 $plan['created_at'] -= 3601; $sha = MAD4B_Catalog_Decommission::digest( $plan );
 $assert( $denied( static function() use( $plan ) { MAD4B_Catalog_Decommission::apply( $plan, str_repeat( 'f', 64 ) ); } ), 'Incorrect approval digest accepted' );
 update_option( $payload, 'changed', false );
 $assert( $denied( static function() use( $plan, $sha ) { MAD4B_Catalog_Decommission::apply( $plan, $sha ); } ), 'Concurrent inventory drift accepted' );
 global $wpdb;
 $authority_before = $wpdb->get_results( $wpdb->prepare( "SELECT option_name,option_value FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name NOT LIKE %s AND option_name NOT IN (%s,%s) ORDER BY option_name", $wpdb->esc_like( 'mad4b_' ) . '%', $wpdb->esc_like( 'mad4b_ct2_' ) . '%', MAD4B_SCP_Catalog_Object_Store::DIRECTORY, MAD4B_SCP_Catalog_Object_Store::GC_CURSOR ), ARRAY_A );
 $plan = MAD4B_Catalog_Decommission::plan( time() - 3601 );
 $result = MAD4B_Catalog_Decommission::apply( $plan, MAD4B_Catalog_Decommission::digest( $plan ) );
 $assert( $result['readback_verified'] && $result['deleted'] > 0 && array( 'grant' => 'must survive' ) === get_option( $sentinel ), 'Purge failed readback or touched unrelated authority' );
 $authority_after = $wpdb->get_results( $wpdb->prepare( "SELECT option_name,option_value FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name NOT LIKE %s AND option_name NOT IN (%s,%s) ORDER BY option_name", $wpdb->esc_like( 'mad4b_' ) . '%', $wpdb->esc_like( 'mad4b_ct2_' ) . '%', MAD4B_SCP_Catalog_Object_Store::DIRECTORY, MAD4B_SCP_Catalog_Object_Store::GC_CURSOR ), ARRAY_A );
 $assert( $authority_before === $authority_after, 'Retirement modified retained control-plane state' );
 echo "PASS offline retirement: maintenance, inactive plugin, reader drain, exact digest, drift denial and authority retention\n";
} finally {
 delete_option( $sentinel ); if ( is_file( $maintenance ) ) unlink( $maintenance );
 activate_plugin( $plugin );
}
