<?php
define( 'ABSPATH', __DIR__ . '/' );
define( 'MAD4B_SCP_FILE', __DIR__ . '/mad4b-site-control-plane/mad4b-site-control-plane.php' );

$GLOBALS['mad4b_auto_global'] = true;
$GLOBALS['mad4b_auto_selected'] = array();
$GLOBALS['mad4b_auto_transient'] = (object) array();
$GLOBALS['mad4b_auto_forced'] = null;

function plugin_basename( $file ) {
	unset( $file );
	return 'mad4b-site-control-plane/mad4b-site-control-plane.php';
}
function wp_is_auto_update_enabled_for_type( $type ) {
	return 'plugin' === $type && (bool) $GLOBALS['mad4b_auto_global'];
}
function get_site_option( $name, $default = false ) {
	if ( 'auto_update_plugins' === $name ) return $GLOBALS['mad4b_auto_selected'];
	return $default;
}
function get_site_transient( $name ) {
	return 'update_plugins' === $name ? $GLOBALS['mad4b_auto_transient'] : false;
}
function wp_is_auto_update_forced_for_item( $type, $update, $item ) {
	unset( $update, $item );
	return 'plugin' === $type ? $GLOBALS['mad4b_auto_forced'] : null;
}
function apply_filters( $hook, $value ) {
	unset( $hook );
	return $value;
}

require dirname( __DIR__ ) . '/includes/class-mad4b-scp-self-update.php';

$observe = new ReflectionMethod( 'MAD4B_SCP_Self_Update', 'wordpress_auto_update_state' );
$observe->setAccessible( true );
$plugin = 'mad4b-site-control-plane/mad4b-site-control-plane.php';

// Selected + WordPress-recognized metadata = enabled even when there is no
// current update offer (no_update bucket).
$GLOBALS['mad4b_auto_selected'] = array( $plugin );
$GLOBALS['mad4b_auto_transient'] = (object) array(
	'no_update' => array( $plugin => (object) array( 'plugin' => $plugin, 'new_version' => '0.4.0-rc.83' ) ),
);
$state = $observe->invoke( null );
if ( empty( $state['effective_enabled'] ) || empty( $state['update_metadata_supported'] ) || ! empty( $state['current_update_offer_present'] ) ) {
	fwrite( STDERR, "selected WordPress auto-update state was not observed correctly\n" );
	exit( 1 );
}

// A stale/manual option entry is not enough when WordPress has no update
// metadata for the plugin.
$GLOBALS['mad4b_auto_transient'] = (object) array();
$state = $observe->invoke( null );
if ( ! empty( $state['effective_enabled'] ) || ! in_array( 'wordpress_update_metadata_not_supported', $state['blockers'], true ) ) {
	fwrite( STDERR, "missing WordPress update metadata did not fail closed\n" );
	exit( 2 );
}

// A site/plugin policy filter may force enablement independently of the stored
// selection. This is observed only; MAD4B does not register such a filter.
$GLOBALS['mad4b_auto_selected'] = array();
$GLOBALS['mad4b_auto_forced'] = true;
$state = $observe->invoke( null );
if ( empty( $state['effective_enabled'] ) || 'forced_enabled' !== $state['forced_state'] || ! empty( $state['current_offer_auto_update_eligible'] ) ) {
	fwrite( STDERR, "forced auto-update policy was not observed correctly\n" );
	exit( 3 );
}

// Global Automatic Updater disablement remains authoritative even when an item
// filter attempts to force the plugin on.
$GLOBALS['mad4b_auto_global'] = false;
$state = $observe->invoke( null );
if ( ! empty( $state['effective_enabled'] ) || ! in_array( 'wordpress_plugin_auto_updates_globally_disabled', $state['blockers'], true ) ) {
	fwrite( STDERR, "global WordPress automatic-updater disablement was not authoritative\n" );
	exit( 4 );
}

echo "mad4b.wordpress-plugin-auto-update-observation.v1 runtime: PASS\n";
