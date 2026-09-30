<?php
define( 'ABSPATH', __DIR__ . '/' );
define( 'MAD4B_SCP_FILE', __DIR__ . '/mad4b-site-control-plane/mad4b-site-control-plane.php' );

$GLOBALS['mad4b_auto_global'] = true;
$GLOBALS['mad4b_auto_selected'] = array();
$GLOBALS['mad4b_auto_transient'] = (object) array();
$GLOBALS['mad4b_auto_forced'] = null;
$GLOBALS['mad4b_effective_environment'] = 'staging';
$GLOBALS['mad4b_profile_write_allowed'] = true;

function sanitize_key( $key ) {
	$key = strtolower( (string) $key );
	return preg_replace( '/[^a-z0-9_\-]/', '', $key );
}
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
final class MAD4B_SCP_Site_Profile {
	public static function environment_resolution() {
		return array(
			'contract' => 'mad4b.site-profile-environment-resolution.v1',
			'wordpress_environment' => 'production',
			'profile_environment' => $GLOBALS['mad4b_effective_environment'],
			'exact_profile_bound' => true,
			'effective_environment' => $GLOBALS['mad4b_effective_environment'],
			'effective_source' => 'exact_site_profile',
			'suggested_environment' => $GLOBALS['mad4b_effective_environment'],
			'wordpress_profile_mismatch' => 'production' !== $GLOBALS['mad4b_effective_environment'],
			'hostname_hint_used_for_authority' => false,
		);
	}
	public static function environment_allowed( $environments, $feature = '' ) {
		return in_array( $GLOBALS['mad4b_effective_environment'], (array) $environments, true )
			&& ( 'write' !== $feature || ! empty( $GLOBALS['mad4b_profile_write_allowed'] ) );
	}
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

$environment_allowed = new ReflectionMethod( 'MAD4B_SCP_Self_Update', 'environment_allowed' );
$environment_allowed->setAccessible( true );
$GLOBALS['mad4b_effective_environment'] = 'staging';
$GLOBALS['mad4b_profile_write_allowed'] = true;
if ( true !== $environment_allowed->invoke( null, false ) ) {
	fwrite( STDERR, "exact Site Profile staging environment did not enable native self-update\n" );
	exit( 5 );
}
if ( true !== $environment_allowed->invoke( null, true ) ) {
	fwrite( STDERR, "exact Staging write profile did not enable governed remote self-update\n" );
	exit( 6 );
}
$GLOBALS['mad4b_effective_environment'] = 'production';
$GLOBALS['mad4b_profile_write_allowed'] = false;
if ( false !== $environment_allowed->invoke( null, false ) ) {
	fwrite( STDERR, "Production native self-update bypassed explicit production gate\n" );
	exit( 7 );
}

echo "mad4b.wordpress-plugin-auto-update-observation.v1 runtime: PASS\n";
