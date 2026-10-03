<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_Authorization_Decision_Graph {
	const CONTRACT = 'mad4b.authorization-decision-graph.v1';

	public static function decorate( $result, $ability, $server, $provider ) {
		$code = is_wp_error( $result ) ? sanitize_key( (string) $result->get_error_code() ) : '';
		$failed = self::stage_for_error( $code );
		$after = false;
		$steps = array();
		foreach ( self::stages() as $stage => $pass_reason ) {
			$status = 'PASS';
			$reason = $pass_reason;
			if ( '' !== $code ) {
				if ( $stage === $failed ) {
					$status = 'FAIL';
					$reason = $code;
					$after = true;
				} elseif ( $after || '' === $failed ) {
					$status = 'NOT_EVALUATED';
					$reason = 'blocked_by_prior_stage';
				}
			}
			$steps[] = array(
				'stage' => $stage,
				'status' => $status,
				'reason_code' => $reason,
				'policy_version' => 'authorization.v1',
				'evidence_version' => 'v1',
				'evidence_refs' => self::refs( $stage, $result ),
			);
		}
		$graph = array(
			'contract' => self::CONTRACT,
			'ability' => (string) $ability,
			'server_id' => sanitize_key( (string) $server ),
			'provider' => sanitize_key( (string) $provider ),
			'decision' => is_wp_error( $result ) ? 'FAIL' : 'PASS',
			'reason_code' => is_wp_error( $result ) ? $code : ( isset( $result['reason_code'] ) ? (string) $result['reason_code'] : 'preflight_allowed' ),
			'steps' => $steps,
			'redacted' => true,
			'authorizing' => false,
		);
		$graph['decision_sha256'] = self::digest( $graph );
		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			if ( ! is_array( $data ) ) $data = array();
			$data['authorization_decision_graph'] = $graph;
			$result->add_data( $data );
			return $result;
		}
		if ( is_array( $result ) ) $result['authorization_decision_graph'] = $graph;
		return $result;
	}

	private static function stages() {
		return array(
			'request_scope' => 'request_scope_admitted',
			'identity' => 'identity_bound',
			'grant' => 'exact_grant_current',
			'capability_descriptor' => 'descriptor_current',
			'resource_constraints' => 'resource_constraints_satisfied',
			'impact_approval' => 'impact_and_approval_satisfied',
			'policy_resolution' => 'policy_resolution_allowed',
			'budget' => 'budget_preflight_satisfied',
		);
	}

	private static function stage_for_error( $code ) {
		if ( false !== strpos( $code, 'request' ) || false !== strpos( $code, 'transport' ) ) return 'request_scope';
		if ( false !== strpos( $code, 'identity' ) || false !== strpos( $code, 'subject' ) || false !== strpos( $code, 'agent' ) ) return 'identity';
		if ( false !== strpos( $code, 'grant' ) || false !== strpos( $code, 'authority' ) ) return 'grant';
		if ( false !== strpos( $code, 'descriptor' ) || false !== strpos( $code, 'capability' ) || false !== strpos( $code, 'provider' ) ) return 'capability_descriptor';
		if ( false !== strpos( $code, 'resource' ) || false !== strpos( $code, 'target' ) ) return 'resource_constraints';
		if ( false !== strpos( $code, 'approval' ) || false !== strpos( $code, 'impact' ) ) return 'impact_approval';
		if ( false !== strpos( $code, 'policy' ) || false !== strpos( $code, 'environment' ) ) return 'policy_resolution';
		if ( false !== strpos( $code, 'budget' ) ) return 'budget';
		return '';
	}

	private static function refs( $stage, $result ) {
		$source = array();
		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			if ( is_array( $data ) ) $source = $data;
		} elseif ( is_array( $result ) ) {
			$source = $result;
		}
		$map = array(
			'request_scope' => array( 'request_id' ),
			'identity' => array( 'subject_fingerprint' ),
			'grant' => array( 'grant_id' ),
			'capability_descriptor' => array( 'capability_descriptor_sha256' ),
			'resource_constraints' => array( 'resource_set_sha256', 'target_fingerprint' ),
			'impact_approval' => array( 'approval_ticket_id', 'approval_impact_binding_sha256', 'approval_impact_sha256' ),
			'policy_resolution' => array( 'policy_decision_sha256' ),
			'budget' => array(),
		);
		$out = array();
		foreach ( isset( $map[ $stage ] ) ? $map[ $stage ] : array() as $key ) {
			if ( ! array_key_exists( $key, $source ) || '' === (string) $source[ $key ] ) continue;
			$value = (string) $source[ $key ];
			$out[] = array(
				'type' => $key,
				'sha256' => 1 === preg_match( '/^[a-f0-9]{64}$/D', strtolower( $value ) ) ? strtolower( $value ) : hash( 'sha256', $value ),
				'redacted' => true,
			);
		}
		return $out;
	}

	private static function digest( $value ) {
		$json = function_exists( 'wp_json_encode' )
			? wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
			: json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return is_string( $json ) ? hash( 'sha256', $json ) : '';
	}
}
