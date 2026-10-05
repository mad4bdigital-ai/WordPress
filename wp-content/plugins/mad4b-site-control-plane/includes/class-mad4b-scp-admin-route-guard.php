<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Compatibility guard for historical direct /wp-admin/<slug> links.
 *
 * WordPress plugin pages are canonical admin.php?page=<slug> routes. Old
 * bookmarks or generated links that used the slug as a physical wp-admin path
 * otherwise fall through to the front-end 404 template. This guard performs a
 * narrow authenticated redirect and is filter-extensible for future pages.
 */
final class MAD4B_SCP_Admin_Route_Guard {
	private static $booted = false;

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_action( 'template_redirect', array( __CLASS__, 'maybe_redirect_legacy_admin_route' ), -1000 );
		add_action( 'admin_init', array( __CLASS__, 'maybe_redirect_legacy_admin_route' ), -1000 );
	}

	public static function aliases() {
		$aliases = array(
			'mad4b-search-intelligence' => 'mad4b-search-intelligence',
			'mad4b-control-plane-content-pipeline' => 'mad4b-control-plane-content-pipeline',
		);
		$aliases = apply_filters( 'mad4b_scp_legacy_admin_route_aliases', $aliases );
		if ( ! is_array( $aliases ) ) return array();

		$clean = array();
		foreach ( $aliases as $legacy => $page ) {
			$legacy = sanitize_key( (string) $legacy );
			$page = sanitize_key( (string) $page );
			if ( '' !== $legacy && '' !== $page ) $clean[ $legacy ] = $page;
		}
		return $clean;
	}

	public static function canonical_url_for_path( $request_uri ) {
		$path = function_exists( 'wp_parse_url' ) ? (string) wp_parse_url( (string) $request_uri, PHP_URL_PATH ) : '';
		if ( '' === $path ) return '';
		$admin_path = function_exists( 'wp_parse_url' ) ? (string) wp_parse_url( admin_url(), PHP_URL_PATH ) : '/wp-admin/';
		$admin_path = trailingslashit( '/' . trim( $admin_path, '/' ) );
		if ( 0 !== strpos( $path, $admin_path ) ) return '';

		$legacy = trim( substr( $path, strlen( $admin_path ) ), '/' );
		if ( '' === $legacy || false !== strpos( $legacy, '/' ) || false !== strpos( $legacy, '.' ) ) return '';
		$legacy = sanitize_key( $legacy );
		$aliases = self::aliases();
		if ( ! isset( $aliases[ $legacy ] ) ) return '';

		$args = array( 'page' => $aliases[ $legacy ] );
		foreach ( array( 'section', 'profile_id', 'tab' ) as $key ) {
			if ( isset( $_GET[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- redirect preserves bounded navigation only.
				$value = sanitize_text_field( wp_unslash( (string) $_GET[ $key ] ) );
				if ( '' !== $value ) $args[ $key ] = $value;
			}
		}
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	public static function maybe_redirect_legacy_admin_route() {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) return;
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) return;
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( (string) $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- parsed as path only.
		$url = self::canonical_url_for_path( $uri );
		if ( '' === $url ) return;
		wp_safe_redirect( $url, 302, 'MAD4B Admin Route Guard' );
		exit;
	}
}
