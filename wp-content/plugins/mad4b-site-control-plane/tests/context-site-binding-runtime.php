<?php

define( 'ABSPATH', '/tmp/mad4b-context-site-binding/' );

$GLOBALS['mad4b_context_options'] = array();

class WP_Error {
	private $code;
	private $message;
	public function __construct( $code, $message = '' ) { $this->code = (string) $code; $this->message = (string) $message; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function absint( $value ) { return abs( (int) $value ); }
function get_option( $name, $default = false ) { return array_key_exists( $name, $GLOBALS['mad4b_context_options'] ) ? $GLOBALS['mad4b_context_options'][ $name ] : $default; }
function add_option( $name, $value ) { $GLOBALS['mad4b_context_options'][ $name ] = $value; return true; }
function update_option( $name, $value ) { $GLOBALS['mad4b_context_options'][ $name ] = $value; return true; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }

class MAD4B_SCP_Site_Profile {
	public static $site_uuid = '11111111-1111-4111-8111-111111111111';
	public static $ready = true;
	public static function status() {
		return array(
			'configured' => self::$ready,
			'origin_match' => self::$ready,
			'environment_match' => self::$ready,
			'site_uuid' => self::$site_uuid,
			'environment' => 'staging',
		);
	}
}

// Produce an explicit, revision-bound fixture identity. The production guard
// still enforces record ownership; it is not replaced or bypassed.
class MAD4B_SCP_Operational_Integrity {
 public static function capture() {
  $site = MAD4B_SCP_Site_Profile::$site_uuid;
  $brand = 0 === strpos( $site, '1111' ) ? str_repeat( 'a', 32 ) : str_repeat( 'b', 32 );
  return array( 'scope' => array(
   'tenant_ref' => 'site-binding-fixture',
   'site_uuid' => $site, 'brand_ref' => $brand,
   'blog_id' => 1, 'network_id' => 1,
   'environment' => 'staging', 'deployment_mode' => 'staging',
  ) );
 }
}
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-operational-scope-guard.php';
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-context-authority.php';

function mad4b_context_binding_assert( $condition, $message, $context = null ) {
	if ( $condition ) return;
	fwrite( STDERR, "FAIL: {$message}\n" );
	if ( null !== $context ) fwrite( STDERR, json_encode( $context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	exit( 1 );
}

$current = MAD4B_SCP_Site_Profile::$site_uuid;
$foreign = '22222222-2222-4222-8222-222222222222';
$current_source = str_repeat( 'a', 64 );
$foreign_source = str_repeat( 'b', 64 );
$current_asset = str_repeat( 'c', 64 );
$foreign_asset = str_repeat( 'd', 64 );

$GLOBALS['mad4b_context_options'][ MAD4B_SCP_Context_Authority::PROFILE_OPTION ] = array(
	'contract' => MAD4B_SCP_Context_Authority::PROFILE_CONTRACT,
	'site_uuid' => $current,
	'brand_id' => str_repeat( 'a', 32 ),
	'brand_name' => 'Current Brand',
	'revision' => 1,
);

function mad4b_site_binding_source_fixture( $id, $site_uuid ) {
	return array(
		'contract' => MAD4B_SCP_Context_Authority::SOURCE_CONTRACT,
		'source_id' => $id,
		'site_uuid' => $site_uuid,
		'brand_id' => ( 0 === strpos( $site_uuid, '1111' ) ? str_repeat( 'a', 32 ) : str_repeat( 'b', 32 ) ),
		'provider' => 'google_drive',
		'mode' => 'governed',
		'external_root_id' => 'folder-' . substr( $id, 0, 8 ),
		'label' => 'Fixture',
		'write_policy' => 'read_only',
		'status' => 'ready',
	);
}
function mad4b_site_binding_asset_fixture( $id, $source_id, $site_uuid ) {
	return array(
		'contract' => MAD4B_SCP_Context_Authority::ASSET_CONTRACT,
		'asset_id' => $id,
		'site_uuid' => $site_uuid,
		'brand_id' => ( 0 === strpos( $site_uuid, '1111' ) ? str_repeat( 'a', 32 ) : str_repeat( 'b', 32 ) ),
		'source_id' => $source_id,
		'source_mode' => 'governed',
		'provider' => 'google_drive',
		'file_id' => 'file-' . substr( $id, 0, 8 ),
		'title' => 'Fixture',
		'category' => 'brand_strategy',
		'status' => 'ready',
	);
}

$GLOBALS['mad4b_context_options'][ MAD4B_SCP_Context_Authority::SOURCES_OPTION ] = array(
	$current_source => mad4b_site_binding_source_fixture( $current_source, $current ),
	$foreign_source => mad4b_site_binding_source_fixture( $foreign_source, $foreign ),
);
$GLOBALS['mad4b_context_options'][ MAD4B_SCP_Context_Authority::ASSETS_OPTION ] = array(
	$current_asset => mad4b_site_binding_asset_fixture( $current_asset, $current_source, $current ),
	$foreign_asset => mad4b_site_binding_asset_fixture( $foreign_asset, $foreign_source, $foreign ),
);

$profile = MAD4B_SCP_Context_Authority::profile();
$sources = MAD4B_SCP_Context_Authority::sources();
$assets = MAD4B_SCP_Context_Authority::assets();
mad4b_context_binding_assert( str_repeat( 'a', 32 ) === $profile['brand_id'], 'Current-site profile must be visible.', $profile );
mad4b_context_binding_assert( isset( $sources[ $current_source ] ) && ! isset( $sources[ $foreign_source ] ), 'Only current-site source must be visible.', $sources );
mad4b_context_binding_assert( isset( $assets[ $current_asset ] ) && ! isset( $assets[ $foreign_asset ] ), 'Only current-site asset must be visible.', $assets );

MAD4B_SCP_Site_Profile::$site_uuid = $foreign;
mad4b_context_binding_assert( array() === MAD4B_SCP_Context_Authority::profile(), 'Profile from prior Site UUID must disappear after re-enroll.' );
$sources = MAD4B_SCP_Context_Authority::sources();
$assets = MAD4B_SCP_Context_Authority::assets();
mad4b_context_binding_assert( array() === $sources && array() === $assets,
 'Switching site without an enrolled Brand Profile must never adopt orphaned foreign sources.', array( $sources, $assets ) );
// Only explicit same-site enrollment of the foreign fixture's owned brand may
// make its previously stored records visible; switching a site alone is insufficient.
$GLOBALS['mad4b_context_options'][ MAD4B_SCP_Context_Authority::PROFILE_OPTION ] = array(
 'contract' => MAD4B_SCP_Context_Authority::PROFILE_CONTRACT,
 'site_uuid' => $foreign, 'brand_id' => str_repeat( 'b', 32 ),
 'brand_name' => 'Foreign Fixture Brand', 'revision' => 1,
);
$sources = MAD4B_SCP_Context_Authority::sources();
$assets = MAD4B_SCP_Context_Authority::assets();
mad4b_context_binding_assert( isset( $sources[ $foreign_source ] ) && ! isset( $sources[ $current_source ] ),
 'Only explicitly enrolled foreign Brand may read foreign-site source.', $sources );
mad4b_context_binding_assert( isset( $assets[ $foreign_asset ] ) && ! isset( $assets[ $current_asset ] ),
 'Only explicitly enrolled foreign Brand may read foreign-site asset.', $assets );

MAD4B_SCP_Site_Profile::$ready = false;
mad4b_context_binding_assert( array() === MAD4B_SCP_Context_Authority::profile(), 'Unenrolled Site Profile must expose no Context profile.' );
mad4b_context_binding_assert( array() === MAD4B_SCP_Context_Authority::sources(), 'Unenrolled Site Profile must expose no Context sources.' );
mad4b_context_binding_assert( array() === MAD4B_SCP_Context_Authority::assets(), 'Unenrolled Site Profile must expose no Context assets.' );

echo "mad4b.site-control-plane.context-site-binding.runtime.v1: PASS\n";
