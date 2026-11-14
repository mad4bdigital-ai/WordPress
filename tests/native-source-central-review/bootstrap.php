<?php
define( 'ABSPATH', '/var/www/html/' );
define( 'WP_CONTENT_DIR', '/var/www/html/wp-content' );
$GLOBALS['wp_review_abilities'] = array();
$GLOBALS['wp_review_hooks'] = array();
$GLOBALS['review_test_origin'] = 'https://tours.dev.mad4b.com';
$GLOBALS['review_test_uuid'] = 'e5e64bca-ed62-48d2-b2b3-dff693cd702a';
$GLOBALS['review_filter_mutates'] = false;
$GLOBALS['review_providers'] = array();
function home_url() { return $GLOBALS['review_test_origin']; }
function current_user_can( $cap ) { return $cap === 'manage_options'; }
function add_action( $name, $callback, $priority ) { $GLOBALS['wp_review_hooks'][] = array( $name, $callback, $priority ); }
function wp_has_ability( $name ) { return isset( $GLOBALS['wp_review_abilities'][ $name ] ); }
function wp_register_ability( $name, $data ) { $GLOBALS['wp_review_abilities'][ $name ] = $data; }
function is_wp_error( $item ) { return $item instanceof WP_Error; }
class WP_Error {
	public $code;
	public function __construct( $code, $message ) { $this->code = $code; }
}
class MAD4B_SCP_Site_Profile {
	public static function site_uuid() { return $GLOBALS['review_test_uuid']; }
}
class MAD4B_SCP_Servers {
	public static function provider_for_ability( $server, $ability ) {
		if ( $server !== 'mad4b-write' ) return null;
		return $GLOBALS['review_providers'][ $ability ] ?? null;
	}
}
class MAD4B_SCP_Staging_Write_Authority {
	public static function authorization_input( $input, $ability ) {
		if ( $GLOBALS['review_filter_mutates'] ) $input['unexpected_new_authority'] = 'no';
		return $input;
	}
}
class MAD4B_SCP_Authorization_Target_Fingerprint {
	public static function fingerprint( $ability, $provider, $input ) {
		return hash( 'sha256', json_encode( array( $ability, $provider, $input ) ) );
	}
}
?>
