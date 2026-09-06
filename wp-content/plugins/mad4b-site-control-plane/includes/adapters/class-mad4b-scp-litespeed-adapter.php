<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class MAD4B_SCP_LiteSpeed_Adapter extends MAD4B_SCP_Adapter_Base {
	public function id() { return 'litespeed'; }
	public function label() { return 'LiteSpeed Cache'; }
	public function is_available() { return defined( 'LSCWP_V' ) || class_exists( 'LiteSpeed_Core' ); }
	public function ability_names() { return array( 'read' => array( 'litespeed/status' ), 'content' => array(), 'admin' => array( 'litespeed/purge-url', 'litespeed/purge-all' ) ); }
	protected function detect_plugin_version() { return defined( 'LSCWP_V' ) ? LSCWP_V : ''; }
	public function register_abilities() { $this->add_ability( 'litespeed/status', 'Get LiteSpeed Cache Status', 'status', array( 'MAD4B_SCP_Policy', 'can_read' ) ); $this->add_ability( 'litespeed/purge-url', 'Purge LiteSpeed URL', 'purge_url', array( 'MAD4B_SCP_Policy', 'can_admin' ), $this->schema( array( 'url' => array( 'type' => 'string', 'format' => 'uri' ) ), array( 'url' ) ), 'admin', false, false, true ); $this->add_ability( 'litespeed/purge-all', 'Purge All LiteSpeed Cache', 'purge_all', array( 'MAD4B_SCP_Policy', 'can_admin' ), null, 'admin', false, true, true ); }
	public function purge_url( $input ) { if ( ! $this->is_available() ) return $this->unavailable_error(); $url = esc_url_raw( $input['url'] ); $home_host = wp_parse_url( home_url(), PHP_URL_HOST ); $target_host = wp_parse_url( $url, PHP_URL_HOST ); if ( ! $target_host || strtolower( $target_host ) !== strtolower( $home_host ) ) return new WP_Error( 'mad4b_litespeed_external_url', 'Only this WordPress site can be purged.' ); do_action( 'litespeed_purge_url', $url ); MAD4B_SCP_Audit::record( 'litespeed/purge-url', array( 'url' => $url ) ); return array( 'purged' => true, 'url' => $url ); }
	public function purge_all() { if ( ! $this->is_available() ) return $this->unavailable_error(); do_action( 'litespeed_purge_all' ); MAD4B_SCP_Audit::record( 'litespeed/purge-all', array() ); return array( 'purged' => true, 'scope' => 'all' ); }
}
