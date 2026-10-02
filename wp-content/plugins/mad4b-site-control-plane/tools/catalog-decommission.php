<?php
/** Explicit offline cache retirement. Run with wp eval-file, never load in plugin boot. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) { throw new RuntimeException( 'CLI only' ); }
require_once __DIR__ . '/../includes/class-mad4b-scp-catalog-object-store.php';
final class MAD4B_Catalog_Decommission {
 public static function assert_offline() {
  if ( ! current_user_can( 'manage_options' ) || is_multisite() && ! is_super_admin() ) throw new RuntimeException( 'Administrator authority required' );
  if ( function_exists( 'ms_is_switched' ) && ms_is_switched() ) throw new RuntimeException( 'Use a fresh wp --url process for this blog' );
  $maintenance = ABSPATH . '.maintenance'; clearstatcache( true, $maintenance );
  if ( ! is_file( $maintenance ) || filemtime( $maintenance ) < time() - 540 ) throw new RuntimeException( 'Activate maintenance mode and keep it renewed throughout retirement' );
  $contents = file_get_contents( $maintenance );
  if ( ! is_string( $contents ) || ! preg_match( '/\$upgrading\s*=\s*([0-9]+)\s*;/', $contents, $match ) || (int) $match[1] < time() - 540 || (int) $match[1] > time() + 5 ) throw new RuntimeException( 'Maintenance timestamp is absent, stale or future-dated' );
  $plugin = 'mad4b-site-control-plane/mad4b-site-control-plane.php';
  global $wpdb;
  $active = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'active_plugins' ) );
  if ( in_array( $plugin, (array) maybe_unserialize( $active ), true ) || isset( get_site_option( 'active_sitewide_plugins', array() )[$plugin] ) ) throw new RuntimeException( 'Deactivate the control plane on this site and network first' );
 }
 public static function inventory() {
  global $wpdb;
  $rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_name, SHA2(option_value,256) AS sha256, OCTET_LENGTH(option_value) AS bytes FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name IN (%s,%s) ORDER BY option_name LIMIT 10001", $wpdb->esc_like( 'mad4b_ct2_' ) . '%', MAD4B_SCP_Catalog_Object_Store::DIRECTORY, MAD4B_SCP_Catalog_Object_Store::GC_CURSOR ), ARRAY_A );
  if ( ! is_array( $rows ) || count( $rows ) > 10000 || $wpdb->last_error ) throw new RuntimeException( 'Inventory unavailable or exceeds bounded retirement budget' );
  $out = array();
  foreach ( $rows as $row ) {
   $name = $row['option_name'];
   if ( ! preg_match( '/^mad4b_ct2_[0-9]+_[a-f0-9]{32}$/D', $name ) && ! in_array( $name, array( MAD4B_SCP_Catalog_Object_Store::DIRECTORY, MAD4B_SCP_Catalog_Object_Store::GC_CURSOR ), true ) ) throw new RuntimeException( 'Unknown cache option format; do not guess' );
   $out[$name] = array( 'sha256' => $row['sha256'], 'bytes' => (int) $row['bytes'] );
  }
  return $out;
 }
 public static function plan( $created = null ) {
  self::assert_offline(); global $wpdb;
  return array( 'contract' => 'mad4b.catalog-offline-purge.v1', 'blog_id' => get_current_blog_id(), 'table' => $wpdb->options, 'origin' => home_url(), 'created_at' => null === $created ? time() : $created, 'objects' => self::inventory(), 'preserves' => array( 'projection', 'grants', 'approvals', 'audit', 'site_profile' ) );
 }
 public static function digest( array $plan ) { return hash( 'sha256', wp_json_encode( $plan, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); }
 public static function apply( array $plan, $expected ) {
  self::assert_offline(); global $wpdb;
  $lock = 'mad4b-purge-' . substr( hash( 'sha256', $wpdb->options ), 0, 48 );
  if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $lock ) ) ) throw new RuntimeException( 'Retirement lock busy or unavailable' );
  try { return self::apply_locked( $plan, $expected ); }
  finally { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); }
 }
 private static function apply_locked( array $plan, $expected ) {
  if ( ! isset( $plan['created_at'] ) || ! is_int( $plan['created_at'] ) || $plan['created_at'] > time() - MAD4B_SCP_Catalog_Object_Store::READER_GRACE_SECONDS || ! is_string( $expected ) || ! hash_equals( self::digest( $plan ), $expected ) ) throw new RuntimeException( 'Exact reviewed plan and full reader drain required' );
  $current = self::plan( $plan['created_at'] );
  if ( $current !== $plan ) throw new RuntimeException( 'Inventory/site drift; prepare a new plan and drain again' );
  global $wpdb; $deleted = 0;
  foreach ( $plan['objects'] as $name => $row ) {
   self::assert_offline();
   $count = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND SHA2(option_value,256) = %s", $name, $row['sha256'] ) );
   if ( 1 !== $count ) throw new RuntimeException( 'Purge stopped on concurrent drift after ' . $deleted . ' deletions; inspect and prepare again' );
   wp_cache_delete( $name, 'options' ); ++$deleted;
  }
  wp_cache_delete( 'alloptions', 'options' ); wp_cache_delete( 'notoptions', 'options' );
  if ( self::inventory() ) throw new RuntimeException( 'Readback is not empty; investigate concurrent writers' );
  return array( 'deleted' => $deleted, 'plan_sha256' => $expected, 'readback_verified' => true, 'authority_data_deleted' => false );
 }
}
if ( defined( 'MAD4B_CATALOG_DECOMMISSION_LIBRARY' ) && MAD4B_CATALOG_DECOMMISSION_LIBRARY ) return;
// wp eval-file tools/catalog-decommission.php plan|apply [plan.json] [sha256]
$mode = $args[0] ?? 'plan';
if ( 'plan' === $mode ) { $plan = MAD4B_Catalog_Decommission::plan(); WP_CLI::line( wp_json_encode( array( 'plan' => $plan, 'plan_sha256' => MAD4B_Catalog_Decommission::digest( $plan ) ) ) ); }
elseif ( 'apply' === $mode ) {
 $file = $args[1] ?? ''; if ( ! is_file( $file ) || filesize( $file ) > 4194304 ) throw new RuntimeException( 'Bounded reviewed plan file required' );
 $document = json_decode( file_get_contents( $file ), true );
 if ( ! is_array( $document['plan'] ?? null ) ) throw new RuntimeException( 'Invalid plan file' );
 WP_CLI::line( wp_json_encode( MAD4B_Catalog_Decommission::apply( $document['plan'], $args[2] ?? '' ) ) );
} else { throw new RuntimeException( 'Use plan or apply' ); }
