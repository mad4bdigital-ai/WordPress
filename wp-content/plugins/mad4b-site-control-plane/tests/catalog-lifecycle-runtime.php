<?php
define( 'ABSPATH', __DIR__ );
$GLOBALS['blog'] = 1; $GLOBALS['stack'] = array(); $GLOBALS['cron'] = array(); $GLOBALS['data'] = array( 'authority' => 'retain', 'projection' => 'retain', 'catalog' => 'retain' );
function is_multisite() { return $GLOBALS['multisite'] ?? false; }
function get_sites( $args ) { return array_slice( range( 1, 205 ), $args['offset'], $args['number'] ); }
function switch_to_blog( $id ) { $GLOBALS['stack'][] = $GLOBALS['blog']; $GLOBALS['blog'] = $id; }
function restore_current_blog() { $GLOBALS['blog'] = array_pop( $GLOBALS['stack'] ); }
function wp_clear_scheduled_hook( $hook ) {
 if ( ! empty( $GLOBALS['fail_cleanup'] ) ) throw new RuntimeException( 'simulated cron failure' );
 unset( $GLOBALS['cron'][$GLOBALS['blog']][$hook] );
}
require __DIR__ . '/../includes/class-mad4b-scp-catalog-lifecycle.php';
$baseline = $GLOBALS['data'];
$GLOBALS['cron'][1] = array( 'mad4b_catalog_gc' => 1, 'foreign_cron' => 2 );
MAD4B_SCP_Catalog_Lifecycle::deactivate();
if ( isset( $GLOBALS['cron'][1]['mad4b_catalog_gc'] ) || 2 !== $GLOBALS['cron'][1]['foreign_cron'] || $baseline !== $GLOBALS['data'] ) throw new RuntimeException( 'Single-site deactivation violated continuity' );
$GLOBALS['multisite'] = true;
foreach ( range( 1, 205 ) as $id ) $GLOBALS['cron'][$id] = array( 'mad4b_catalog_gc' => 1, 'foreign_cron' => 2 );
MAD4B_SCP_Catalog_Lifecycle::deactivate( true );
foreach ( $GLOBALS['cron'] as $cron ) if ( isset( $cron['mad4b_catalog_gc'] ) || 2 !== $cron['foreign_cron'] ) throw new RuntimeException( 'Network pagination missed cron or touched another schedule' );
if ( 1 !== $GLOBALS['blog'] || $GLOBALS['stack'] || $baseline !== $GLOBALS['data'] ) throw new RuntimeException( 'Network cleanup leaked blog or deleted data' );
$GLOBALS['fail_cleanup'] = true;
try { MAD4B_SCP_Catalog_Lifecycle::deactivate( true ); throw new LogicException( 'Failure was swallowed' ); }
catch ( RuntimeException $expected ) {}
if ( 1 !== $GLOBALS['blog'] || $GLOBALS['stack'] ) throw new RuntimeException( 'Failed cleanup did not restore caller blog' );
echo "PASS catalog lifecycle: single/network cron cleanup, 205-site pagination, data retention and exception restoration\n";
