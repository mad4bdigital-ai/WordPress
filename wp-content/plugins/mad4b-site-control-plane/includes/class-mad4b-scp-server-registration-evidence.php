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
