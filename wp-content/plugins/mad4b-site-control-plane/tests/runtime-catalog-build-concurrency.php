<?php
if ( ! defined( 'ABSPATH' ) ) throw new RuntimeException( 'WordPress must be loaded.' );
$fail = static function ( $message ) { throw new RuntimeException( $message ); };
global $wpdb;
$scope_method = new ReflectionMethod( 'MAD4B_SCP_Ability_Catalog_Transport', 'scope' );
$scope_method->setAccessible( true );
$scope = $scope_method->invoke( null );
$lock = 'mad4b-catalog-' . substr( hash( 'sha256', $wpdb->options . ':' . $scope ), 0, 48 );
$other = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
if ( 1 !== (int) $other->get_var( $other->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) $fail( 'Independent connection did not acquire catalog mutex.' );
try {
 $started = microtime( true );
 $blocked = MAD4B_SCP_Ability_Catalog_Transport::handle( array( 'force_refresh' => true ) );
 if ( ! is_wp_error( $blocked ) || 'mad4b_catalog_build_in_progress' !== $blocked->get_error_code() ) $fail( 'Two database connections admitted the same scope build.' );
 if ( microtime( true ) - $started > 2 ) $fail( 'Concurrent build waited instead of failing fast.' );
} finally { $other->get_var( $other->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); }
$budget_filter = static function () { return 1; };
add_filter( 'mad4b_scp_catalog_build_max_abilities', $budget_filter );
try {
 $blocked = MAD4B_SCP_Ability_Catalog_Transport::handle( array( 'force_refresh' => true ) );
 if ( ! is_wp_error( $blocked ) || 'mad4b_catalog_build_ability_budget' !== $blocked->get_error_code() ) $fail( 'Live universe exceeded budget without denial.' );
 if ( 1 !== (int) $other->get_var( $other->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) $fail( 'Budget rejection leaked connection mutex.' );
 $other->get_var( $other->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
} finally { remove_filter( 'mad4b_scp_catalog_build_max_abilities', $budget_filter ); $other->close(); }
// WordPress cron continuity uses its real cron option, with data byte-for-byte retained.
MAD4B_SCP_Ability_Catalog_Transport::schedule_gc();
$options = array( MAD4B_SCP_Catalog_Object_Store::DIRECTORY, MAD4B_SCP_Catalog_Object_Store::GC_CURSOR, MAD4B_SCP_ChatGPT_Tool_Projection::OPTION );
$before = array(); foreach ( $options as $name ) $before[$name] = get_option( $name, false );
MAD4B_SCP_Catalog_Lifecycle::deactivate();
if ( wp_next_scheduled( 'mad4b_catalog_gc' ) ) $fail( 'Deactivation retained catalog cron.' );
foreach ( $before as $name => $value ) if ( $value !== get_option( $name, false ) ) $fail( 'Deactivation changed retained option: ' . $name );
MAD4B_SCP_Ability_Catalog_Transport::schedule_gc();
if ( ! wp_next_scheduled( 'mad4b_catalog_gc' ) ) $fail( 'Reactivation could not restore GC scheduling.' );
echo "PASS real database concurrency: independent connections, fail-fast contention, rejection release, cron continuity\n";
