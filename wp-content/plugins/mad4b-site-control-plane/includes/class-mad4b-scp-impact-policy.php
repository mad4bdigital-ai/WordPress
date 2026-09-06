<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_Impact_Policy {
	public static function impact_for( $ability_name, $provider = 'core', $input = null ) {
		$ability_name = (string) $ability_name;
		$provider = sanitize_key( (string) $provider );
		if ( 'mad4b/database-raw-query' === $ability_name ) return 'exceptional';
		$high_core = array(
			'mad4b/plugin-activate', 'mad4b/plugin-deactivate', 'mad4b/filesystem-write', 'mad4b/filesystem-patch', 'mad4b/database-update', 'mad4b/mutation-undo',
		);
		if ( in_array( $ability_name, $high_core, true ) ) return 'high';
		if ( 'mad4b/content-update-post' === $ability_name && is_array( $input ) && isset( $input['post_status'] ) && in_array( $input['post_status'], array( 'publish', 'private' ), true ) ) return 'high';
		if ( 'core' !== $provider && 'media' !== $provider ) return 'high';
		$impact = 'low';
		$filtered = apply_filters( 'mad4b_scp_mutation_impact', $impact, $ability_name, $provider, $input );
		if ( ! in_array( $filtered, array( 'low', 'high', 'exceptional' ), true ) ) return 'high';
		// Filters may raise impact freely. They may not lower hard minimums defined above because those returned already.
		return $filtered;
	}

	public static function requires_approval( $ability_name, $provider = 'core', $input = null ) {
		$impact = self::impact_for( $ability_name, $provider, $input );
		if ( in_array( $impact, array( 'high', 'exceptional' ), true ) ) return true;
		return (bool) apply_filters( 'mad4b_scp_low_impact_requires_approval', false, $ability_name, $provider, $input );
	}

	public static function ticket_class_for( $ability_name, $provider = 'core', $input = null ) {
		return 'exceptional' === self::impact_for( $ability_name, $provider, $input ) ? 'breakglass' : 'mutation';
	}
}
