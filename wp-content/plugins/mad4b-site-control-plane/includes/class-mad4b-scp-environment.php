<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Canonical MAD4B environment resolver.
 *
 * WordPress may report the implicit Production default when WP_ENVIRONMENT_TYPE
 * is absent. Authority-bearing MAD4B decisions must instead consume the exact
 * Site Profile effective environment. Raw WordPress environment remains
 * available explicitly for diagnostics and conflict detection only.
 */
final class MAD4B_SCP_Environment {
	const CONTRACT = 'mad4b.effective-environment.v1';

	public static function wordpress() {
		if ( class_exists( 'MAD4B_SCP_Site_Profile' ) && method_exists( 'MAD4B_SCP_Site_Profile', 'wordpress_environment' ) ) {
			return sanitize_key( (string) MAD4B_SCP_Site_Profile::wordpress_environment() );
		}
		return function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown';
	}

	public static function wordpress_explicit() {
		return class_exists( 'MAD4B_SCP_Site_Profile' ) && method_exists( 'MAD4B_SCP_Site_Profile', 'wordpress_environment_explicit' )
			? (bool) MAD4B_SCP_Site_Profile::wordpress_environment_explicit()
			: defined( 'WP_ENVIRONMENT_TYPE' );
	}

	public static function effective() {
		if ( class_exists( 'MAD4B_SCP_Site_Profile' ) && method_exists( 'MAD4B_SCP_Site_Profile', 'current_environment' ) ) {
			return sanitize_key( (string) MAD4B_SCP_Site_Profile::current_environment() );
		}
		return self::wordpress();
	}

	public static function resolution() {
		if ( class_exists( 'MAD4B_SCP_Site_Profile' ) && method_exists( 'MAD4B_SCP_Site_Profile', 'environment_resolution' ) ) {
			$resolution = MAD4B_SCP_Site_Profile::environment_resolution();
			if ( is_array( $resolution ) ) return $resolution;
		}
		$environment = self::wordpress();
		return array(
			'contract' => self::CONTRACT,
			'wordpress_environment' => $environment,
			'wordpress_environment_explicit' => self::wordpress_explicit(),
			'profile_environment' => '',
			'profile_environment_authoritative' => false,
			'effective_environment' => $environment,
			'effective_source' => self::wordpress_explicit() ? 'wordpress_explicit' : 'wordpress_default',
		);
	}

	public static function snapshot() {
		$r = self::resolution();
		return array(
			'contract' => self::CONTRACT,
			'wordpress_environment' => isset( $r['wordpress_environment'] ) ? sanitize_key( (string) $r['wordpress_environment'] ) : self::wordpress(),
			'wordpress_environment_explicit' => ! empty( $r['wordpress_environment_explicit'] ),
			'profile_environment' => isset( $r['profile_environment'] ) ? sanitize_key( (string) $r['profile_environment'] ) : '',
			'profile_environment_authoritative' => ! empty( $r['profile_environment_authoritative'] ),
			'effective_environment' => isset( $r['effective_environment'] ) ? sanitize_key( (string) $r['effective_environment'] ) : self::effective(),
			'effective_source' => isset( $r['effective_source'] ) ? sanitize_key( (string) $r['effective_source'] ) : '',
		);
	}
}
