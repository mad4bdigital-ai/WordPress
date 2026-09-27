<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_Impact_Policy {
	const CLASSIFICATION_CONTRACT = 'mad4b.operation-classification.v1';

	public static function classify( $ability_name, $provider = 'core', $input = null ) {
		$ability_name = (string) $ability_name;
		$provider = sanitize_key( (string) $provider );
		$input = is_array( $input ) ? $input : array();
		$environment = class_exists( 'MAD4B_SCP_Site_Profile' ) && method_exists( 'MAD4B_SCP_Site_Profile', 'current_environment' )
			? sanitize_key( (string) MAD4B_SCP_Site_Profile::current_environment() )
			: ( function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown' );
		$impact = self::impact_for( $ability_name, $provider, $input );
		$ticket_class = self::ticket_class_for( $ability_name, $provider, $input );
		$operation_type = 'governed_mutation';

		if ( 'mad4b/database-raw-query' === $ability_name || 0 === strpos( $ability_name, 'mad4b/developer-breakglass-' ) ) {
			$operation_type = 'exceptional';
		} elseif ( in_array( $ability_name, array( 'mad4b/plugin-package-apply', 'mad4b/control-plane-upload-apply' ), true ) ) {
			$operation_type = 'certified_package';
		} elseif ( in_array( $ability_name, array( 'mad4b/mutation-undo', 'context/rollback-materialized-brand-draft' ), true ) || false !== strpos( $ability_name, 'rollback' ) ) {
			$operation_type = 'recovery';
		} elseif ( in_array( $ability_name, array( 'mad4b/content-update-post', 'mad4b/content-create-post' ), true ) ) {
			$status = isset( $input['post_status'] ) ? sanitize_key( (string) $input['post_status'] ) : '';
			$operation_type = in_array( $status, array( 'publish', 'private' ), true ) ? 'content_publish' : 'content_change';
		} elseif ( in_array( $ability_name, array( 'mad4b/plugin-activate', 'mad4b/plugin-deactivate', 'mad4b/filesystem-write', 'mad4b/filesystem-patch', 'mad4b/database-update', 'mad4b/provider-canary-execute' ), true ) ) {
			$operation_type = 'system_admin';
		} elseif ( 0 === strpos( $ability_name, 'jetengine/' ) || 0 === strpos( $ability_name, 'elementor/' ) || 0 === strpos( $ability_name, 'context/' ) || 0 === strpos( $ability_name, 'woocommerce/' ) || 0 === strpos( $ability_name, 'bitflows/' ) || 0 === strpos( $ability_name, 'media/' ) || 0 === strpos( $ability_name, 'seo/' ) ) {
			$operation_type = 'provider_configuration';
		}

		$ai_forbidden = 'staging' !== $environment
			|| 'breakglass' === $ticket_class
			|| 'exceptional' === $impact
			|| 'exceptional' === $operation_type
			|| 0 === strpos( $ability_name, 'mad4b/developer-' )
			|| 'mad4b/database-raw-query' === $ability_name;
		$ai_eligible = ! $ai_forbidden;
		$approval_lane = $ai_eligible ? 'ai_autonomous' : 'human_only';
		$risk_tier = 'exceptional' === $impact ? 'exceptional' : ( 'high' === $impact ? 'high' : ( in_array( $operation_type, array( 'content_change', 'provider_configuration', 'governed_mutation' ), true ) ? 'medium' : 'low' ) );
		$rollback_evidence_required = in_array( $operation_type, array( 'certified_package', 'recovery', 'system_admin', 'provider_configuration' ), true );

		$result = array(
			'contract' => self::CLASSIFICATION_CONTRACT,
			'ability' => $ability_name,
			'provider' => $provider,
			'environment' => $environment,
			'operation_type' => $operation_type,
			'impact' => $impact,
			'risk_tier' => $risk_tier,
			'ticket_class' => $ticket_class,
			'approval_lane' => $approval_lane,
			'ai_approval_eligible' => $ai_eligible,
			'human_approval_required' => ! $ai_eligible,
			'human_fallback_available' => 'breakglass' !== $ticket_class,
			'rollback_evidence_required' => $rollback_evidence_required,
			'production_auto_approval' => false,
			'breakglass_auto_approval' => false,
		);
		$encoded = wp_json_encode( $result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$result['classification_sha256'] = false === $encoded ? '' : hash( 'sha256', $encoded );
		return $result;
	}
	public static function impact_for( $ability_name, $provider = 'core', $input = null ) {
		$ability_name = (string) $ability_name;
		$provider = sanitize_key( (string) $provider );
		if ( 'mad4b/database-raw-query' === $ability_name ) return 'exceptional';
		if ( 0 === strpos( $ability_name, 'mad4b/developer-breakglass-' ) ) return 'exceptional';
		if ( 0 === strpos( $ability_name, 'mad4b/developer-' ) && 'mad4b/developer-runtime-status' !== $ability_name ) return 'high';
		$high_core = array(
			'mad4b/plugin-activate', 'mad4b/plugin-deactivate', 'mad4b/plugin-package-apply', 'mad4b/filesystem-write', 'mad4b/filesystem-patch', 'mad4b/database-update', 'mad4b/mutation-undo',
			'mad4b/provider-canary-execute',
		);
		if ( in_array( $ability_name, $high_core, true ) ) return 'high';
		if ( in_array( $ability_name, array( 'mad4b/content-update-post', 'mad4b/content-create-post' ), true )
			&& is_array( $input )
			&& isset( $input['post_status'] )
			&& in_array( $input['post_status'], array( 'publish', 'private' ), true ) ) return 'high';
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
