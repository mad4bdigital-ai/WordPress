<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_Execution_Receipt {
	const CONTRACT = 'mad4b.execution-receipt.v1';
	const SIGNATURE_STATE = 'signed';

	public static function build( array $claim, $result, array $terminal ) {
		if ( ! class_exists( 'MAD4B_SCP_Crypto_Profile' ) ) return new WP_Error( 'mad4b_execution_receipt_crypto_unavailable', 'Execution receipt cryptographic profile runtime is unavailable.' );
		$profile_id = MAD4B_SCP_Crypto_Profile::default_profile( 'execution_receipt' );
		if ( is_wp_error( $profile_id ) ) return $profile_id;
		if ( empty( $terminal['receipt_sha256'] ) || empty( $terminal['receipt_id'] ) ) {
			return new WP_Error( 'mad4b_execution_receipt_terminal_missing', 'Unified execution receipt requires a durable terminal receipt.' );
		}
		$stages = array(
			'preparation' => self::stage(
				! empty( $claim['context_receipt_sha256'] ) || ! empty( $claim['commit_guard_snapshot']['material_sha256'] ),
				! empty( $claim['context_receipt_sha256'] ) ? (string) $claim['context_receipt_sha256'] : ( isset( $claim['commit_guard_snapshot']['material_sha256'] ) ? (string) $claim['commit_guard_snapshot']['material_sha256'] : '' ),
				'preparation_or_commit_guard'
			),
			'capability_descriptor' => self::stage( ! empty( $claim['capability_descriptor_sha256'] ), isset( $claim['capability_descriptor_sha256'] ) ? (string) $claim['capability_descriptor_sha256'] : '', 'capability_descriptor' ),
			'policy_decision' => self::stage( ! empty( $claim['policy_decision_sha256'] ), isset( $claim['policy_decision_sha256'] ) ? (string) $claim['policy_decision_sha256'] : '', 'policy_decision' ),
			'approval' => ! empty( $claim['approval_required'] )
				? self::stage( ! empty( $claim['approval_ticket_id'] ), isset( $claim['approval_ticket_id'] ) ? hash( 'sha256', (string) $claim['approval_ticket_id'] ) : '', 'approval_ticket' )
				: self::na( 'approval_not_required' ),
			'idempotency_claim' => self::optional( $claim, array( 'idempotency_key', 'idempotency_claim_sha256' ), 'idempotency_not_required' ),
			'operation_journal' => self::optional( $claim, array( 'operation_id', 'operation_key' ), 'operation_journal_not_required' ),
			'provider_evidence' => self::stage( true, isset( $terminal['terminal_material_sha256'] ) ? (string) $terminal['terminal_material_sha256'] : (string) $terminal['receipt_sha256'], 'terminal_material' ),
			'readback_reconciliation' => self::readback( $claim, $result ),
			'terminal_outcome' => self::stage( true, (string) $terminal['receipt_sha256'], 'terminal_receipt' ),
		);
		foreach ( array( 'preparation', 'capability_descriptor', 'policy_decision', 'provider_evidence', 'terminal_outcome' ) as $required ) {
			if ( 'PASS' !== $stages[ $required ]['status'] ) {
				return new WP_Error( 'mad4b_execution_receipt_required_stage_missing', 'Terminal success cannot be represented with a missing required execution stage.', array( 'stage'=>$required, 'terminal_success'=>false ) );
			}
		}
		if ( ! empty( $claim['approval_required'] ) && 'PASS' !== $stages['approval']['status'] ) {
			return new WP_Error( 'mad4b_execution_receipt_approval_missing', 'Terminal success requires the exact approval stage.' );
		}
		$receipt = array(
			'contract' => self::CONTRACT,
			'receipt_version' => 1,
			'ability' => isset( $claim['ability'] ) ? (string) $claim['ability'] : '',
			'provider_id' => isset( $claim['provider'] ) ? sanitize_key( (string) $claim['provider'] ) : 'core',
			'request_id' => isset( $claim['request_id'] ) ? substr( (string) $claim['request_id'], 0, 100 ) : '',
			'target_fingerprint' => isset( $claim['target_fingerprint'] ) ? (string) $claim['target_fingerprint'] : '',
			'resource_set_sha256' => isset( $claim['resource_set_sha256'] ) ? (string) $claim['resource_set_sha256'] : '',
			'approval_required' => ! empty( $claim['approval_required'] ),
			'approval_impact_binding_sha256' => isset( $claim['approval_impact_binding_sha256'] ) ? (string) $claim['approval_impact_binding_sha256'] : '',
			'stages' => $stages,
			'terminal_receipt_id' => (string) $terminal['receipt_id'],
			'terminal_receipt_sha256' => (string) $terminal['receipt_sha256'],
			'signature_state' => self::SIGNATURE_STATE,
			'signature_profile' => (string) $profile_id,
			'authorizing' => false,
		);
		$receipt['receipt_sha256'] = self::digest( $receipt );
		if ( '' === $receipt['receipt_sha256'] ) return new WP_Error( 'mad4b_execution_receipt_encode_failed', 'Unified execution receipt could not be canonically encoded.' );
		$receipt['receipt_id'] = 'execution-receipt:v1:' . $receipt['receipt_sha256'];
		$signature = MAD4B_SCP_Crypto_Profile::sign_digest( $profile_id, $receipt['receipt_sha256'] );
		if ( is_wp_error( $signature ) ) return $signature;
		$receipt['signature'] = $signature;
		return $receipt;
	}

	public static function verify( array $receipt ) {
		if ( self::CONTRACT !== ( isset( $receipt['contract'] ) ? (string) $receipt['contract'] : '' ) ) return new WP_Error( 'mad4b_execution_receipt_contract_invalid', 'Execution receipt contract is invalid.' );
		$expected = isset( $receipt['receipt_sha256'] ) ? strtolower( (string) $receipt['receipt_sha256'] ) : '';
		$receipt_id = isset( $receipt['receipt_id'] ) ? strtolower( (string) $receipt['receipt_id'] ) : '';
		$material = $receipt;
		unset( $material['receipt_sha256'], $material['receipt_id'], $material['signature'] );
		$actual = self::digest( $material );
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $expected ) || ! hash_equals( $expected, $actual ) ) return new WP_Error( 'mad4b_execution_receipt_integrity_invalid', 'Execution receipt integrity verification failed.' );
		if ( ! hash_equals( 'execution-receipt:v1:' . $expected, $receipt_id ) ) return new WP_Error( 'mad4b_execution_receipt_identity_invalid', 'Execution receipt identifier is not bound to its canonical digest.' );
		foreach ( array( 'preparation', 'capability_descriptor', 'policy_decision', 'provider_evidence', 'terminal_outcome' ) as $stage ) {
			if ( empty( $receipt['stages'][ $stage ] ) || 'PASS' !== (string) $receipt['stages'][ $stage ]['status'] ) return new WP_Error( 'mad4b_execution_receipt_required_stage_missing', 'Execution receipt is missing a required successful stage.', array( 'stage'=>$stage ) );
		}
		if ( ! empty( $receipt['approval_required'] ) && ( empty( $receipt['stages']['approval'] ) || 'PASS' !== (string) $receipt['stages']['approval']['status'] ) ) {
			return new WP_Error( 'mad4b_execution_receipt_approval_missing', 'Execution receipt is missing the required approval stage.' );
		}
		if ( self::SIGNATURE_STATE !== ( isset( $receipt['signature_state'] ) ? (string) $receipt['signature_state'] : '' ) || empty( $receipt['signature'] ) || ! is_array( $receipt['signature'] ) ) {
			return new WP_Error( 'mad4b_execution_receipt_signature_missing', 'Execution receipt is missing its required cryptographic signature.' );
		}
		if ( ! class_exists( 'MAD4B_SCP_Crypto_Profile' ) ) return new WP_Error( 'mad4b_execution_receipt_crypto_unavailable', 'Execution receipt cryptographic profile runtime is unavailable.' );
		$sig = MAD4B_SCP_Crypto_Profile::verify_digest_for_purpose( $receipt['signature'], $expected, 'execution_receipt' );
		if ( is_wp_error( $sig ) ) return $sig;
		if ( ! hash_equals( (string)$receipt['signature_profile'], (string)$receipt['signature']['profile_id'] ) ) return new WP_Error( 'mad4b_execution_receipt_signature_profile_mismatch', 'Execution receipt signature profile is not bound to the receipt material.' );
		return array(
			'contract' => 'mad4b.execution-receipt-verification.v1',
			'valid' => true,
			'receipt_id' => $receipt_id,
			'receipt_sha256' => $expected,
			'signature_state' => (string) $receipt['signature_state'],
			'signature_profile' => (string) $receipt['signature_profile'],
			'signature_kid' => (string) $receipt['signature']['kid'],
			'cryptographic_signature_verified' => true,
			'authorizing' => false,
		);
	}

	public static function export( array $receipt ) {
		$verification = self::verify( $receipt );
		if ( is_wp_error( $verification ) ) return $verification;
		$key = MAD4B_SCP_Crypto_Profile::public_key( (string)$receipt['signature']['profile_id'], (string)$receipt['signature']['kid'] );
		if ( is_wp_error( $key ) ) return $key;
		return array(
			'contract' => 'mad4b.execution-receipt-export.v1',
			'receipt' => $receipt,
			'verification' => $verification,
			'verification_key' => $key,
			'authorizing' => false,
		);
	}

	private static function stage( $ok, $sha, $type ) {
		return array( 'status'=>$ok?'PASS':'MISSING', 'evidence_type'=>$type, 'evidence_sha256'=>(string)$sha, 'reason_code'=>$ok?'evidence_present':'required_evidence_missing' );
	}
	private static function na( $reason ) { return array( 'status'=>'NOT_REQUIRED', 'evidence_type'=>'none', 'evidence_sha256'=>'', 'reason_code'=>$reason ); }
	private static function optional( array $claim, array $keys, $reason ) {
		foreach ( $keys as $key ) if ( ! empty( $claim[ $key ] ) ) return self::stage( true, 1===preg_match('/^[a-f0-9]{64}$/D',strtolower((string)$claim[$key]))?strtolower((string)$claim[$key]):hash('sha256',(string)$claim[$key]), $key );
		return self::na( $reason );
	}
	private static function readback( array $claim, $result ) {
		$evidence=array();
		foreach(array('postcondition_recovery','postcondition_observation','reconciliation_ref') as $key) if(!empty($claim[$key])) $evidence[$key]=$claim[$key];
		if(is_array($result)) foreach(array('postcondition','readback','reconciliation_ref') as $key) if(!empty($result[$key])) $evidence[$key]=$result[$key];
		return empty($evidence)?self::na('no_reconciliation_required_for_terminal_success_path'):self::stage(true,self::digest($evidence),'readback_or_reconciliation');
	}
	private static function digest( $value ) {
		$value=self::canon($value);
		$json=function_exists('wp_json_encode')?wp_json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE):json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
		return is_string($json)?hash('sha256',$json):'';
	}
	private static function canon( $value ) {
		if(!is_array($value))return$value;
		$keys=array_keys($value);$list=empty($value)||$keys===range(0,count($value)-1);
		if($list)return array_map(array(__CLASS__,'canon'),$value);
		ksort($value,SORT_STRING);foreach($value as $key=>$item)$value[$key]=self::canon($item);return$value;
	}
}
