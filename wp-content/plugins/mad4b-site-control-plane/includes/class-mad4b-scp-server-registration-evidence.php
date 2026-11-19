<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Non-authorizing registration evidence helpers extracted from MAD4B_SCP_Servers.
 *
 * This service classifies bounded adapter construction failures and materializes
 * capability-descriptor evidence. It never grants transport or mutation authority.
 */
final class MAD4B_SCP_Server_Registration_Evidence {
	public static function bounded_registration_failure( $result ) {
		$code = is_wp_error( $result ) && method_exists( $result, 'get_error_code' )
			? sanitize_key( (string) $result->get_error_code() )
			: 'adapter_rejected';
		$message = is_wp_error( $result ) && method_exists( $result, 'get_error_message' )
			? (string) $result->get_error_message()
			: '';
		$stage = 'adapter_create_server';
		$reason = '' !== $code ? $code : 'adapter_rejected';

		if ( 'server_creation_failed' === $code ) {
			$stage = 'server_construction';
			$reason = 'server_constructor_exception';
			if ( preg_match( '/(?:too (?:few|many) arguments|expects (?:exactly|at least|at most) [0-9]+ arguments?|argument #[0-9]+)/i', $message ) ) {
				$reason = 'runtime_constructor_contract_mismatch';
			} elseif ( preg_match( '/(?:class|interface|trait) .+ not found/i', $message ) ) {
				$reason = 'runtime_symbol_unavailable';
			} elseif ( false !== stripos( $message, 'call to undefined method' ) || false !== stripos( $message, 'undefined method' ) ) {
				$reason = 'runtime_method_contract_mismatch';
			} elseif ( false !== stripos( $message, 'typeerror' ) || false !== stripos( $message, 'must be of type' ) || false !== stripos( $message, 'cannot assign' ) ) {
				$reason = 'runtime_type_contract_mismatch';
			}
		}

		return array(
			'stage' => sanitize_key( $stage ),
			'reason' => sanitize_key( $reason ),
			'fingerprint' => '' !== $message ? hash( 'sha256', $code . "\n" . $message ) : '',
		);
	}

	/** Validate the first-party descriptor, not just absence of WP_Error. */
	private static function descriptor_binding_verified( $binding, $ability_name, $server_id ) {
		if ( ! is_array( $binding )
			|| MAD4B_SCP_Capability_Descriptor_Registry::CONSUMER_BINDING_CONTRACT !== ( $binding['contract'] ?? null )
			|| MAD4B_SCP_Capability_Descriptor_Registry::CONTRACT !== ( $binding['descriptor_contract'] ?? null )
			|| MAD4B_SCP_Capability_Descriptor_Registry::GENERATION_CONTRACT !== ( $binding['generation_contract'] ?? null )
			|| 'servers' !== ( $binding['consumer'] ?? null )
			|| $ability_name !== ( $binding['ability_name'] ?? null )
			|| false !== ( $binding['authorizing'] ?? null )
			|| false !== ( $binding['authority_snapshot_is_grant'] ?? null )
			|| true !== ( $binding['authority_revalidation_required'] ?? null )
			// Never treat an unclassified/blocked execution as a descriptor:
			// execution_lane='none' means its first-party final boundary failed.
			// Exceptional lanes are structurally mountable ONLY on their
			// original exceptional server, or as an already schema-pinned,
			// independently scoped ChatGPT projection. This does not authorize
			// a call, create a grant, or bypass bearer/approval checks.
			|| ! self::descriptor_lane_matches_server( $binding, $ability_name, $server_id ) )
			return false;
		foreach ( array( 'input_schema_sha256', 'classification_sha256', 'descriptor_sha256' ) as $field ) {
			if ( ! is_string( $binding[ $field ] ?? null )
				|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $binding[ $field ] ) ) return false;
		}
		$roots = $binding['generation_roots'] ?? null;
		if ( ! is_array( $roots ) ) return false;
		foreach ( array( 'contract_root', 'site_root' ) as $field ) {
			if ( ! is_string( $roots[ $field ] ?? null )
				|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $roots[ $field ] ) ) return false;
		}
		return true;
	}

	/** Strict routing of exceptional source lanes; never promote 'none'. */
	private static function descriptor_lane_matches_server( array $binding, $ability_name, $server_id ) {
		$lane = (string) ( $binding['execution_lane'] ?? '' );
		if ( in_array( $lane, array( 'read', 'write', 'content', 'admin', 'enrollment', 'developer' ), true ) ) {
			return 'mad4b-read' !== $server_id || 'read' === $lane;
		}
		if ( 'breakglass' === $lane && 'mad4b/database-raw-query' === $ability_name ) {
			if ( 'mad4b-breakglass' === $server_id ) return true;
			return 'mad4b-chatgpt' === $server_id
				&& class_exists( 'MAD4B_SCP_ChatGPT_Tool_Projection' )
				&& MAD4B_SCP_ChatGPT_Tool_Projection::is_projected( $ability_name );
		}
		if ( 'developer-breakglass' === $lane && 'mad4b-developer-breakglass' === $server_id
			&& class_exists( 'MAD4B_SCP_Servers' ) ) {
			return in_array( $ability_name,
				MAD4B_SCP_Servers::core_tools( 'mad4b-developer-breakglass' ), true );
		}
		return false;
	}

	/** Verify only already-bound tools; preserve explicit quarantine diagnostics. */
	public static function verified_subset( $server_id, array $requested, array $evidence ) {
		$verified = array_fill_keys( array_keys( $evidence['bindings'] ?? array() ), true );
		$tools = array_values( array_filter( $requested, static function ( $name ) use ( $verified ) { return isset( $verified[ $name ] ); } ) );
		$quarantined = array_values( array_diff( $requested, $tools ) );
		$evidence['quarantined_abilities'] = $quarantined;
		$evidence['partial_ready'] = ! empty( $tools );
		$evidence['degraded'] = ! empty( $quarantined );
		$surface = in_array( $server_id, array( 'mad4b-chatgpt', 'mad4b-enrollment' ), true );
		return array( 'admitted' => $surface && ( ! empty( $tools ) || 'mad4b-chatgpt' === $server_id ), 'tools' => $tools, 'evidence' => $evidence, 'authorizing' => false );
	}

	public static function capability_descriptor_evidence( $server_id, array $tools ) {
		$bindings = array();
		$blockers = array();
		if ( ! class_exists( 'MAD4B_SCP_Capability_Descriptor_Registry' ) ) {
			$blockers[] = 'capability_descriptor_registry_unavailable';
		} else {
			foreach ( array_values( array_unique( array_map( 'strval', $tools ) ) ) as $ability_name ) {
				$binding = MAD4B_SCP_Capability_Descriptor_Registry::binding( $ability_name, 'servers' );
				if ( is_wp_error( $binding ) ) {
					$blockers[] = $ability_name . ':' . $binding->get_error_code();
					continue;
				}
				// A null or malformed non-WP_Error is never a successful descriptor
				// binding. Only the exact canonical consumer/name/roots contract
				// may reach the MCP Server registration tool projection.
				if ( ! self::descriptor_binding_verified( $binding, $ability_name, $server_id ) ) {
					$blockers[] = $ability_name . ':descriptor_binding_unverified';
					continue;
				}
				$bindings[ $ability_name ] = $binding;
			}
		}
		ksort( $bindings, SORT_STRING );
		$blockers = array_values( array_unique( $blockers ) );
		sort( $blockers, SORT_STRING );
		return array(
			'contract' => 'mad4b.server-capability-descriptor-bindings.v1',
			'server_id' => (string) $server_id,
			'ready' => empty( $blockers ) && count( $bindings ) === count( array_values( array_unique( array_map( 'strval', $tools ) ) ) ),
			'bindings' => $bindings,
			'blockers' => $blockers,
			'authorizing' => false,
			'authority_effect' => 'none',
		);
	}
}
