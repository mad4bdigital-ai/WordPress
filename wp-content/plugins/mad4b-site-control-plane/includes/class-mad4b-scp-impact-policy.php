<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_Impact_Policy {
	const CLASSIFICATION_CONTRACT = 'mad4b.operation-classification.v1';

	public static function classify( $ability_name, $provider = 'core', $input = null ) {
		$ability_name = (string) $ability_name;
		$provider = sanitize_key( (string) $provider );
		$input = is_array( $input ) ? $input : array();
		$environment = class_exists( 'MAD4B_SCP_Environment' ) ? MAD4B_SCP_Environment::effective() : ( class_exists( 'MAD4B_SCP_Site_Profile' ) ? sanitize_key( (string) MAD4B_SCP_Site_Profile::current_environment() ) : ( function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown' ) );

		$readonly = false;
		$readonly_known = false;
		if ( function_exists( 'wp_has_ability' ) && function_exists( 'wp_get_ability' ) && wp_has_ability( $ability_name ) ) {
			$ability = wp_get_ability( $ability_name );
			$meta = is_object( $ability ) && method_exists( $ability, 'get_meta' ) ? $ability->get_meta() : array();
			$annotations = isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : array();
			if ( array_key_exists( 'readonly', $annotations ) ) {
				$readonly_known = true;
				$readonly = true === $annotations['readonly'];
			}
		}

		$impact = $readonly ? 'none' : self::impact_for( $ability_name, $provider, $input );
		$ticket_class = $readonly ? 'none' : self::ticket_class_for( $ability_name, $provider, $input );
		$operation_type = $readonly ? 'observe' : 'governed_mutation';
		$mutation_kind = $readonly ? 'read' : 'mutate';

		if ( ! $readonly ) {
			if ( 'mad4b/approval-ai-decide' === $ability_name ) {
				$operation_type = 'governance_decision';
				$mutation_kind = 'decision';
			} elseif ( 'mad4b/developer-workspace-apply' === $ability_name ) {
				$operation_type = 'development_source';
				$mutation_kind = 'source_batch';
			} elseif ( 'mad4b/developer-workspace-promote' === $ability_name ) {
				$operation_type = 'certified_package';
				$mutation_kind = 'development_promotion';
			} elseif ( 'mad4b/database-raw-query' === $ability_name || 0 === strpos( $ability_name, 'mad4b/developer-breakglass-' ) ) {
				$operation_type = 'exceptional';
				$mutation_kind = 'privileged_execute';
			} elseif ( in_array( $ability_name, array( 'mad4b/plugin-package-apply', 'mad4b/plugin-remote-update-apply', 'mad4b/control-plane-upload-apply', 'mad4b/control-plane-native-apply' ), true ) ) {
				$operation_type = 'certified_package';
				$mutation_kind = 'package_replace';
			} elseif ( in_array( $ability_name, array( 'mad4b/mutation-undo', 'context/rollback-materialized-brand-draft' ), true ) || false !== strpos( $ability_name, 'rollback' ) ) {
				$operation_type = 'recovery';
				$mutation_kind = 'rollback';
			} elseif ( in_array( $ability_name, array( 'mad4b/content-update-post', 'mad4b/content-create-post', 'mad4b/content-apply-bundle' ), true ) ) {
				$status = 'mad4b/content-apply-bundle' === $ability_name && isset( $input['post'] ) && is_array( $input['post'] ) && isset( $input['post']['post_status'] )
					? sanitize_key( (string) $input['post']['post_status'] )
					: ( isset( $input['post_status'] ) ? sanitize_key( (string) $input['post_status'] ) : '' );
				$operation_type = in_array( $status, array( 'publish', 'private' ), true ) ? 'content_publish' : 'content_change';
				$mutation_kind = 'publish' === $status ? 'publish' : ( 'private' === $status ? 'visibility_change' : ( 'mad4b/content-apply-bundle' === $ability_name && isset( $input['mode'] ) && 'create' === sanitize_key( (string) $input['mode'] ) ? 'create' : ( false !== strpos( $ability_name, 'create' ) ? 'create' : 'update' ) ) );
			} elseif ( in_array( $ability_name, array( 'mad4b/plugin-activate', 'mad4b/plugin-deactivate', 'mad4b/filesystem-write', 'mad4b/filesystem-patch', 'mad4b/database-update', 'mad4b/provider-canary-execute' ), true ) ) {
				$operation_type = 'system_admin';
				if ( 'mad4b/plugin-activate' === $ability_name ) $mutation_kind = 'activate';
				elseif ( 'mad4b/plugin-deactivate' === $ability_name ) $mutation_kind = 'deactivate';
				elseif ( false !== strpos( $ability_name, 'patch' ) ) $mutation_kind = 'patch';
				elseif ( false !== strpos( $ability_name, 'update' ) || false !== strpos( $ability_name, 'write' ) ) $mutation_kind = 'update';
				else $mutation_kind = 'execute';
			} elseif ( 0 === strpos( $ability_name, 'jetengine/' ) || 0 === strpos( $ability_name, 'elementor/' ) || 0 === strpos( $ability_name, 'context/' ) || 0 === strpos( $ability_name, 'woocommerce/' ) || 0 === strpos( $ability_name, 'bitflows/' ) || 0 === strpos( $ability_name, 'media/' ) || 0 === strpos( $ability_name, 'seo/' ) ) {
				$operation_type = 'provider_configuration';
			}

			if ( 'mutate' === $mutation_kind ) {
				$tail = strtolower( preg_replace( '/^.*\//', '', $ability_name ) );
				if ( false !== strpos( $tail, 'create' ) ) $mutation_kind = 'create';
				elseif ( false !== strpos( $tail, 'delete' ) || false !== strpos( $tail, 'remove' ) ) $mutation_kind = 'delete';
				elseif ( false !== strpos( $tail, 'append' ) ) $mutation_kind = 'append';
				elseif ( false !== strpos( $tail, 'clone' ) ) $mutation_kind = 'clone';
				elseif ( false !== strpos( $tail, 'move' ) ) $mutation_kind = 'move';
				elseif ( false !== strpos( $tail, 'reconcile' ) ) $mutation_kind = 'reconcile';
				elseif ( false !== strpos( $tail, 'apply' ) || false !== strpos( $tail, 'update' ) || false !== strpos( $tail, 'set-' ) || false !== strpos( $tail, 'manage' ) ) $mutation_kind = 'update';
			}
		}

		$bounded_developer_ai = in_array( $ability_name, array( 'mad4b/developer-workspace-apply', 'mad4b/developer-workspace-promote' ), true );
		$ai_forbidden = $readonly
			|| 'staging' !== $environment
			|| 'breakglass' === $ticket_class
			|| 'exceptional' === $impact
			|| 'exceptional' === $operation_type
			|| ( 0 === strpos( $ability_name, 'mad4b/developer-' ) && ! $bounded_developer_ai )
			|| 'mad4b/database-raw-query' === $ability_name;
		$ai_eligible = ! $ai_forbidden;
		$approval_required = ! $readonly && self::requires_approval( $ability_name, $provider, $input );
		$approval_lane = $readonly ? 'none' : ( $ai_eligible ? 'ai_autonomous' : 'human_only' );
		$risk_tier = $readonly ? 'none' : ( 'exceptional' === $impact ? 'exceptional' : ( 'high' === $impact ? 'high' : ( in_array( $operation_type, array( 'content_change', 'provider_configuration', 'governed_mutation' ), true ) ? 'medium' : 'low' ) ) );
		$rollback_evidence_required = ! $readonly && ( 'mad4b/content-apply-bundle' === $ability_name || in_array( $operation_type, array( 'certified_package', 'recovery', 'system_admin', 'provider_configuration', 'development_source' ), true ) );

		$side_effect_scope = 'none';
		if ( ! $readonly ) {
			if ( in_array( $operation_type, array( 'content_change', 'content_publish' ), true ) ) $side_effect_scope = 'content';
			elseif ( 'provider_configuration' === $operation_type ) $side_effect_scope = 'provider';
			elseif ( 'certified_package' === $operation_type ) $side_effect_scope = 'package';
			elseif ( 'development_source' === $operation_type ) $side_effect_scope = 'protected_workspace';
			elseif ( 'system_admin' === $operation_type ) $side_effect_scope = 'system';
			elseif ( 'recovery' === $operation_type ) $side_effect_scope = 'recovery';
			elseif ( 'governance_decision' === $operation_type ) $side_effect_scope = 'governance';
			elseif ( 'exceptional' === $operation_type ) $side_effect_scope = 'privileged';
			else $side_effect_scope = 'governed';
		}

		$result = array(
			'contract' => self::CLASSIFICATION_CONTRACT,
			'ability' => $ability_name,
			'provider' => $provider,
			'environment' => $environment,
			'readonly_known' => $readonly_known,
			'readonly' => $readonly,
			'operation_type' => $operation_type,
			'mutation_kind' => $mutation_kind,
			'side_effect_scope' => $side_effect_scope,
			'impact' => $impact,
			'risk_tier' => $risk_tier,
			'ticket_class' => $ticket_class,
			'approval_required' => $approval_required,
			'approval_lane' => $approval_lane,
			'ai_approval_eligible' => $ai_eligible,
			'human_approval_required' => ! $readonly && ! $ai_eligible,
			'human_fallback_available' => ! $readonly && 'breakglass' !== $ticket_class,
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
		// AI approval is a bounded governance decision, not the target mutation.
		// Its standing delegation is evaluated separately against the exact ticket,
		// classification, build/profile binding and AI NHI grant.
		if ( 'mad4b/approval-ai-decide' === $ability_name ) return 'low';
		if ( 'mad4b/developer-workspace-apply' === $ability_name ) return 'low';
		if ( 'mad4b/developer-workspace-promote' === $ability_name ) return 'high';
		if ( 0 === strpos( $ability_name, 'mad4b/developer-' ) && 'mad4b/developer-runtime-status' !== $ability_name ) return 'high';
		$high_core = array(
			'mad4b/plugin-activate', 'mad4b/plugin-deactivate', 'mad4b/plugin-package-apply', 'mad4b/plugin-remote-update-apply', 'mad4b/control-plane-upload-apply', 'mad4b/control-plane-native-apply', 'mad4b/filesystem-write', 'mad4b/filesystem-patch', 'mad4b/database-update', 'mad4b/mutation-undo',
			'mad4b/provider-canary-execute', 'mad4b/content-pipeline-settings-update',
		);
		if ( in_array( $ability_name, $high_core, true ) ) return 'high';
		if ( in_array( $ability_name, array( 'mad4b/content-update-post', 'mad4b/content-create-post', 'mad4b/content-apply-bundle' ), true ) && is_array( $input ) ) {
			$status = 'mad4b/content-apply-bundle' === $ability_name && isset( $input['post'] ) && is_array( $input['post'] ) && isset( $input['post']['post_status'] )
				? sanitize_key( (string) $input['post']['post_status'] )
				: ( isset( $input['post_status'] ) ? sanitize_key( (string) $input['post_status'] ) : '' );
			if ( in_array( $status, array( 'publish', 'private' ), true ) ) return 'high';
		}
		if ( 'core' !== $provider && 'media' !== $provider ) return 'high';
		$impact = 'low';
		$filtered = apply_filters( 'mad4b_scp_mutation_impact', $impact, $ability_name, $provider, $input );
		if ( ! in_array( $filtered, array( 'low', 'high', 'exceptional' ), true ) ) return 'high';
		// Filters may raise impact freely. They may not lower hard minimums defined above because those returned already.
		return $filtered;
	}

	public static function requires_approval( $ability_name, $provider = 'core', $input = null ) {
		if ( 'mad4b/developer-workspace-apply' === (string) $ability_name ) return true;
		$impact = self::impact_for( $ability_name, $provider, $input );
		if ( in_array( $impact, array( 'high', 'exceptional' ), true ) ) return true;
		return (bool) apply_filters( 'mad4b_scp_low_impact_requires_approval', false, $ability_name, $provider, $input );
	}

	public static function ticket_class_for( $ability_name, $provider = 'core', $input = null ) {
		return 'exceptional' === self::impact_for( $ability_name, $provider, $input ) ? 'breakglass' : 'mutation';
	}
}
