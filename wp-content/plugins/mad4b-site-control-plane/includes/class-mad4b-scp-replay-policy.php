<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Preparation replay policy.
 *
 * This service never grants execution authority. It constrains reuse of already
 * validated preparation evidence and composes that evidence with the existing
 * Durable Execution idempotency ledger.
 */
final class MAD4B_SCP_Replay_Policy {
	const CONTRACT = 'mad4b.replay-policy.v1';
	const RESULT_CONTRACT = 'mad4b.replay-result.v1';
	const DURABLE_KEY = 'preparation-replay-v1';
	private static $catalog = null;

	public static function clear_cache() { self::$catalog = null; }

	public static function policy_sha256() {
		$catalog = self::catalog();
		return is_wp_error( $catalog ) ? '' : self::digest( $catalog );
	}

	public static function begin( $preparation_receipt, $ability_name, $provider, $target_input, $idempotency_key = '' ) {
		if ( ! class_exists( 'MAD4B_SCP_Preparation_Receipt' ) || ! method_exists( 'MAD4B_SCP_Preparation_Receipt', 'claims' ) ) {
			return new WP_Error( 'mad4b_replay_preparation_claims_unavailable', 'Preparation replay claims are unavailable.' );
		}
		if ( ! class_exists( 'MAD4B_SCP_Durable_Execution' ) ) {
			return new WP_Error( 'mad4b_replay_durable_runtime_unavailable', 'Durable idempotency runtime is unavailable.' );
		}
		if ( ! class_exists( 'MAD4B_SCP_Impact_Policy' ) ) {
			return new WP_Error( 'mad4b_replay_impact_policy_unavailable', 'Impact policy is unavailable for replay classification.' );
		}
		$claims = MAD4B_SCP_Preparation_Receipt::claims( $preparation_receipt, $ability_name );
		if ( is_wp_error( $claims ) ) return $claims;
		$current_policy_sha = self::policy_sha256();
		if ( '' === $current_policy_sha
			|| empty( $claims['replay_policy_sha256'] )
			|| ! hash_equals( $current_policy_sha, strtolower( (string) $claims['replay_policy_sha256'] ) ) ) {
			return new WP_Error(
				'mad4b_preparation_replay_policy_stale',
				'Preparation evidence was issued under a different replay policy. Prepare the capability again.',
				array( 'reprepare_required' => true, 'blind_retry_allowed' => false )
			);
		}

		$provider = sanitize_key( (string) $provider );
		if ( '' === $provider ) $provider = 'core';
		$classification = MAD4B_SCP_Impact_Policy::classify( $ability_name, $provider, is_array( $target_input ) ? $target_input : array() );
		if ( is_wp_error( $classification ) || ! is_array( $classification ) ) {
			return new WP_Error( 'mad4b_replay_risk_unavailable', 'Replay risk classification is unavailable.' );
		}
		$risk = sanitize_key( isset( $classification['risk_tier'] ) ? (string) $classification['risk_tier'] : '' );
		$rule = self::risk_rule( $risk );
		if ( is_wp_error( $rule ) ) return $rule;

		$idempotency_key = trim( (string) $idempotency_key );
		if ( strlen( $idempotency_key ) > 191 ) return new WP_Error( 'mad4b_replay_idempotency_key_invalid', 'Replay idempotency key is too long.' );
		$mode = ( 'reusable_same_idempotency' === $rule && '' !== $idempotency_key )
			? 'reusable_same_idempotency'
			: 'single_use';

		$input_sha = self::digest( array(
			'ability_name'=>(string)$ability_name,
			'provider'=>$provider,
			'target_input'=>$target_input,
		) );
		if ( '' === $input_sha ) return new WP_Error( 'mad4b_replay_request_digest_invalid', 'Replay request digest could not be derived.' );
		$idempotency_binding_sha = '' === $idempotency_key ? hash( 'sha256', 'none' ) : hash( 'sha256', $idempotency_key );
		$request_sha = self::digest( array(
			'contract'=>self::CONTRACT,
			'policy_sha256'=>$current_policy_sha,
			'ability_name'=>(string)$ability_name,
			'provider'=>$provider,
			'risk_tier'=>$risk,
			'mode'=>$mode,
			'preparation_nonce'=>(string)$claims['nonce'],
			'preparation_receipt_sha256'=>hash( 'sha256', (string)$preparation_receipt ),
			'idempotency_key_sha256'=>$idempotency_binding_sha,
			'target_input_sha256'=>$input_sha,
		) );
		$scope_key = hash(
			'sha256',
			self::CONTRACT . '|' . (string)$claims['authority_scope_sha256'] . '|' . (string)$ability_name . '|' . (string)$claims['nonce']
		);
		$catalog = self::catalog();
		if ( is_wp_error( $catalog ) ) return $catalog;
		$ttl = max( 3600, min( 2592000, (int)$catalog['durable_record_ttl_seconds'] ) );

		$claim = MAD4B_SCP_Durable_Execution::begin_idempotency( $scope_key, self::DURABLE_KEY, $request_sha, $ttl );
		if ( is_wp_error( $claim ) ) {
			if ( 'mad4b_idempotency_hash_conflict' === $claim->get_error_code() ) {
				return new WP_Error(
					'mad4b_preparation_replay_binding_conflict',
					'The same preparation receipt was rebound to a different idempotency key or request payload.',
					array( 'reprepare_required'=>true, 'blind_retry_allowed'=>false, 'risk_tier'=>$risk )
				);
			}
			return $claim;
		}

		$base = array(
			'contract'=>self::CONTRACT,
			'mode'=>$mode,
			'risk_tier'=>$risk,
			'policy_sha256'=>$current_policy_sha,
			'preparation_nonce'=>(string)$claims['nonce'],
			'preparation_receipt_sha256'=>hash( 'sha256', (string)$preparation_receipt ),
			'idempotency_key_sha256'=>$idempotency_binding_sha,
			'request_sha256'=>$request_sha,
			'scope_key'=>$scope_key,
			'authorizing'=>false,
			'blind_retry_allowed'=>false,
		);

		if ( ! empty( $claim['replayed'] ) ) {
			$stored = isset( $claim['result'] ) && is_array( $claim['result'] ) ? $claim['result'] : array();
			if ( 'single_use' === $mode ) {
				return new WP_Error(
					'mad4b_preparation_replay_denied',
					'This preparation receipt is single-use and has already been consumed.',
					array( 'risk_tier'=>$risk, 'reprepare_required'=>true, 'blind_retry_allowed'=>false )
				);
			}
			if ( self::RESULT_CONTRACT !== ( isset( $stored['contract'] ) ? (string)$stored['contract'] : '' )
				|| ! array_key_exists( 'dispatch_result', $stored ) ) {
				return new WP_Error(
					'mad4b_preparation_replay_result_invalid',
					'Completed replay state is missing its durable recorded result.',
					array( 'reconciliation_required'=>true, 'blind_retry_allowed'=>false )
				);
			}
			$base['replayed'] = true;
			$base['claimed'] = false;
			$base['dispatch_result'] = $stored['dispatch_result'];
			return $base;
		}

		if ( empty( $claim['claimed'] ) ) {
			return new WP_Error( 'mad4b_preparation_replay_state_invalid', 'Durable replay state is neither newly claimed nor completed.' );
		}
		$base['replayed'] = false;
		$base['claimed'] = true;
		$base['durable_claim'] = $claim;

		if ( 'single_use' === $mode ) {
			$consume = MAD4B_SCP_Durable_Execution::complete_idempotency(
				$claim,
				array(
					'contract'=>self::RESULT_CONTRACT,
					'state'=>'consumed_single_use',
					'risk_tier'=>$risk,
					'preparation_nonce'=>(string)$claims['nonce'],
					'dispatch_result'=>null,
					'authorizing'=>false,
				)
			);
			if ( is_wp_error( $consume ) ) return $consume;
			unset( $base['durable_claim'] );
			$base['consumed_before_execution'] = true;
		}
		return $base;
	}

	public static function complete( array $admission, $dispatch_result ) {
		if ( self::CONTRACT !== ( isset( $admission['contract'] ) ? (string)$admission['contract'] : '' ) ) {
			return new WP_Error( 'mad4b_replay_admission_invalid', 'Replay completion requires an exact replay admission.' );
		}
		if ( ! empty( $admission['replayed'] ) || 'single_use' === ( isset($admission['mode']) ? (string)$admission['mode'] : '' ) ) {
			return array( 'contract'=>self::CONTRACT, 'completed'=>true, 'durable_completion_required'=>false, 'authorizing'=>false );
		}
		if ( empty( $admission['durable_claim'] ) || ! is_array( $admission['durable_claim'] ) ) {
			return new WP_Error( 'mad4b_replay_durable_claim_missing', 'Reusable replay completion is missing its durable idempotency claim.' );
		}
		$completed = MAD4B_SCP_Durable_Execution::complete_idempotency(
			$admission['durable_claim'],
			array(
				'contract'=>self::RESULT_CONTRACT,
				'state'=>'completed',
				'risk_tier'=>(string)$admission['risk_tier'],
				'dispatch_result'=>$dispatch_result,
				'authorizing'=>false,
			)
		);
		if ( is_wp_error( $completed ) ) {
			return new WP_Error(
				'mad4b_replay_completion_uncertain',
				'Mutation completed but durable replay state could not be committed. Reconcile before any retry.',
				array(
					'cause'=>$completed->get_error_code(),
					'reconciliation_required'=>true,
					'blind_retry_allowed'=>false,
					'request_sha256'=>(string)$admission['request_sha256'],
				)
			);
		}
		return array( 'contract'=>self::CONTRACT, 'completed'=>true, 'durable_completion_required'=>true, 'authorizing'=>false );
	}

	public static function risk_rule( $risk_tier ) {
		$catalog = self::catalog();
		if ( is_wp_error( $catalog ) ) return $catalog;
		$risk_tier = sanitize_key( (string)$risk_tier );
		if ( empty( $catalog['risk_modes'][ $risk_tier ] ) ) {
			return new WP_Error( 'mad4b_replay_risk_unknown', 'Replay policy does not recognize this operation risk tier.' );
		}
		$mode = sanitize_key( (string)$catalog['risk_modes'][ $risk_tier ] );
		return in_array( $mode, array( 'single_use', 'reusable_same_idempotency' ), true )
			? $mode
			: new WP_Error( 'mad4b_replay_policy_invalid', 'Replay policy contains an unsupported risk mode.' );
	}

	private static function catalog() {
		if ( is_array( self::$catalog ) ) return self::$catalog;
		$path = defined( 'MAD4B_SCP_DIR' ) ? MAD4B_SCP_DIR . 'config/replay-policy.json' : dirname( __DIR__ ) . '/config/replay-policy.json';
		if ( ! is_readable( $path ) ) return new WP_Error( 'mad4b_replay_policy_missing', 'Replay policy catalog is unavailable.' );
		$data = json_decode( (string)file_get_contents( $path ), true );
		if ( ! is_array( $data )
			|| self::CONTRACT !== ( isset($data['contract']) ? (string)$data['contract'] : '' )
			|| empty( $data['risk_modes'] )
			|| ! is_array( $data['risk_modes'] )
			|| empty( $data['durable_record_ttl_seconds'] ) ) {
			return new WP_Error( 'mad4b_replay_policy_invalid', 'Replay policy catalog is invalid.' );
		}
		return self::$catalog = $data;
	}

	private static function digest( $value ) {
		$value = self::canonicalize( $value );
		$json = function_exists( 'wp_json_encode' )
			? wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
			: json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return is_string( $json ) ? hash( 'sha256', $json ) : '';
	}
	private static function canonicalize( $value ) {
		if ( ! is_array( $value ) ) return $value;
		$list = empty( $value ) || array_keys( $value ) === range( 0, count( $value ) - 1 );
		if ( $list ) return array_map( array( __CLASS__, 'canonicalize' ), $value );
		ksort( $value, SORT_STRING );
		foreach ( $value as $key => $item ) $value[ $key ] = self::canonicalize( $item );
		return $value;
	}
}
