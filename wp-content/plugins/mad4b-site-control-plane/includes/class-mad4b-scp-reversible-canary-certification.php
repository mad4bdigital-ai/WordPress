<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Reversible certification mode for the existing governed provider canary.
 *
 * This class does not create a second mutation authority. It runs only after
 * MAD4B_SCP_Provider_Canary_Execution::validate_context() has admitted the
 * exact provider/capability/build/artifact under existing Staging authority.
 */
final class MAD4B_SCP_Reversible_Canary_Certification {
	const RECIPE_CONTRACT = 'mad4b.reversible-canary-recipe.v1';
	const RECEIPT_CONTRACT = 'mad4b.reversible-canary-receipt.v1';
	const MAX_STATE_BYTES = 262144;

	public static function validate_recipe( array $context, array $recipe ) {
		$site = class_exists( 'MAD4B_SCP_Site_Profile' ) ? (string) MAD4B_SCP_Site_Profile::site_uuid() : '';
		$environment = class_exists( 'MAD4B_SCP_Site_Profile' ) ? (string) MAD4B_SCP_Site_Profile::current_environment() : '';
		$identity = class_exists( 'MAD4B_SCP_Identity_Context' ) ? MAD4B_SCP_Identity_Context::current() : array();
		if ( is_wp_error( $identity ) ) return $identity;
		$subject = is_array( $identity ) ? (string) ( $identity['subject_fingerprint'] ?? '' ) : '';
		if ( 'staging' !== $environment || '' === $site || $site !== ( $recipe['site_uuid'] ?? '' ) || $environment !== ( $recipe['environment'] ?? '' ) || ! preg_match( '/^[a-f0-9]{64}$/', $subject ) || $subject !== ( $recipe['subject_fingerprint'] ?? '' ) ) return new WP_Error( 'mad4b_reversible_canary_identity_binding_invalid', 'Canary requires the exact current Staging site and authenticated subject.' );
		$input_json = wp_json_encode( $context['target_input'] ?? array(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$input_digest = is_string( $input_json ) ? hash( 'sha256', $input_json ) : '';
		if ( '' === $input_digest || $input_digest !== ( $context['target_input_digest'] ?? '' ) || $input_digest !== ( $recipe['target_input_digest'] ?? '' ) ) return new WP_Error( 'mad4b_reversible_canary_input_binding_invalid', 'Canary input differs from the exact reviewed recipe.' );
		if ( self::RECIPE_CONTRACT !== (string) ( isset( $recipe['contract'] ) ? $recipe['contract'] : '' ) ) return new WP_Error( 'mad4b_reversible_canary_recipe_contract_invalid', 'Reversible canary recipe contract is invalid.' );
		if ( 'L3' !== (string) ( isset( $recipe['autonomy_level'] ) ? $recipe['autonomy_level'] : '' ) ) return new WP_Error( 'mad4b_reversible_canary_autonomy_invalid', 'Reversible canary requires explicit L3 autonomy admission.' );
		if ( 'disposable_fixture' !== (string) ( isset( $recipe['target_scope'] ) ? $recipe['target_scope'] : '' ) || empty( $recipe['disposable_target_required'] ) ) return new WP_Error( 'mad4b_reversible_canary_disposable_target_required', 'Reversible canary is restricted to an exact disposable fixture.' );
		if ( ! empty( $recipe['financial_effects_allowed'] ) || ! empty( $recipe['blind_retry_allowed'] ) ) return new WP_Error( 'mad4b_reversible_canary_unsafe_retry_or_financial_effect', 'Financial effects and blind retry are prohibited for reversible canary certification.' );

		foreach ( array( 'provider_id','capability_id','target_ability' ) as $field ) {
			$expected = isset( $context[ $field ] ) ? (string) $context[ $field ] : '';
			$actual = isset( $recipe[ $field ] ) ? (string) $recipe[ $field ] : '';
			if ( '' === $expected || ! hash_equals( $expected, $actual ) ) return new WP_Error( 'mad4b_reversible_canary_recipe_binding_mismatch', 'Canary recipe does not match the admitted provider capability.', array( 'field'=>$field ) );
		}
		foreach ( array(
			'artifact_fingerprint'=>'artifact_fingerprint',
			'capability_contract_digest'=>'capability_contract_digest',
			'canary_basis_digest'=>'canary_basis_digest',
		) as $recipe_field=>$context_field ) {
			$expected = strtolower( trim( (string) ( isset( $context[ $context_field ] ) ? $context[ $context_field ] : '' ) ) );
			$actual = strtolower( trim( (string) ( isset( $recipe[ $recipe_field ] ) ? $recipe[ $recipe_field ] : '' ) ) );
			if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $actual ) || ! hash_equals( $expected, $actual ) ) return new WP_Error( 'mad4b_reversible_canary_recipe_binding_mismatch', 'Canary recipe evidence binding is stale.', array( 'field'=>$recipe_field ) );
		}

		$generation = isset( $recipe['runtime_generation'] ) && is_array( $recipe['runtime_generation'] ) ? $recipe['runtime_generation'] : array();
		if ( ! class_exists( 'MAD4B_SCP_Runtime_Generation_Fence' ) ) return new WP_Error( 'mad4b_reversible_canary_generation_unavailable', 'Runtime generation fence is unavailable.' );
		$current = MAD4B_SCP_Runtime_Generation_Fence::assert_current( $generation );
		if ( is_wp_error( $current ) ) return $current;

		$restore = class_exists( 'MAD4B_SCP_Restore_Epoch' ) ? MAD4B_SCP_Restore_Epoch::material() : new WP_Error( 'mad4b_reversible_canary_restore_epoch_unavailable', 'Restore epoch is unavailable.' );
		if ( is_wp_error( $restore ) ) return $restore;
		$expected_epoch = (int) ( isset( $recipe['restore_epoch'] ) ? $recipe['restore_epoch'] : 0 );
		if ( $expected_epoch < 1 || $expected_epoch !== (int) ( isset( $restore['epoch'] ) ? $restore['epoch'] : 0 ) ) return new WP_Error( 'mad4b_reversible_canary_restore_epoch_stale', 'Canary recipe was admitted under a stale restore/authority epoch.' );

		$adapter = isset( $context['adapter'] ) ? $context['adapter'] : null;
		foreach ( array( 'capture_reversible_state','read_reversible_state','restore_reversible_state','execute_canary','reversible_contract_for','canary_target_is_disposable','capture_canary_effect_state' ) as $method ) {
			if ( ! is_object( $adapter ) || ! method_exists( $adapter, $method ) ) return new WP_Error( 'mad4b_reversible_canary_adapter_contract_incomplete', 'Provider adapter does not expose the full reversible canary contract.', array( 'method'=>$method ) );
		}
		$rollback_contract = (string) $adapter->reversible_contract_for( $context['target_ability'] );
		if ( '' === $rollback_contract || ! hash_equals( $rollback_contract, (string) ( isset( $recipe['rollback_contract'] ) ? $recipe['rollback_contract'] : '' ) ) ) return new WP_Error( 'mad4b_reversible_canary_rollback_contract_mismatch', 'Canary recipe rollback contract does not match the adapter.' );

		$cost = isset( $recipe['cost_authority'] ) && is_array( $recipe['cost_authority'] ) ? $recipe['cost_authority'] : array();
		if ( ! is_int( $cost['provider_units'] ?? null ) || ! is_int( $cost['currency_minor_units'] ?? null ) ) return new WP_Error( 'mad4b_reversible_canary_cost_authority_required', 'Canary cost requires explicit integer units.' );
		if ( 'signed_zero_cost' !== (string) ( isset( $cost['mode'] ) ? $cost['mode'] : '' )
			|| 0 !== (int) ( isset( $cost['provider_units'] ) ? $cost['provider_units'] : -1 )
			|| 0 !== (int) ( isset( $cost['currency_minor_units'] ) ? $cost['currency_minor_units'] : -1 ) ) return new WP_Error( 'mad4b_reversible_canary_cost_authority_required', 'Canary recipe lacks bounded zero-cost authority.' );

		$expires_at = (int) ( isset( $recipe['expires_at'] ) ? $recipe['expires_at'] : 0 );
		if ( $expires_at <= self::now_epoch() ) return new WP_Error( 'mad4b_reversible_canary_recipe_expired', 'Canary recipe is expired.' );
		$digest = self::payload_sha256( $recipe );
		$declared = strtolower( trim( (string) ( isset( $recipe['recipe_sha256'] ) ? $recipe['recipe_sha256'] : '' ) ) );
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $declared ) || ! hash_equals( $digest, $declared ) ) return new WP_Error( 'mad4b_reversible_canary_recipe_digest_mismatch', 'Canary recipe digest mismatch.' );
		if ( ! class_exists( 'MAD4B_SCP_Crypto_Profile' ) ) return new WP_Error( 'mad4b_reversible_canary_crypto_unavailable', 'Behavioral evidence signature verifier is unavailable.' );
		$signature = isset( $recipe['signature'] ) && is_array( $recipe['signature'] ) ? $recipe['signature'] : array();
		$verified = MAD4B_SCP_Crypto_Profile::verify_digest_for_purpose( $signature, $digest, 'behavioral_evidence' );
		if ( is_wp_error( $verified ) ) return $verified;

		return array(
			'site_uuid'=>$site, 'environment'=>$environment, 'subject_fingerprint'=>$subject,
			'recipe_id'=>sanitize_key( isset( $recipe['recipe_id'] ) ? (string) $recipe['recipe_id'] : '' ),
			'recipe_sha256'=>$digest,
			'rollback_contract'=>$rollback_contract,
			'disposable_target_digest'=>strtolower( trim( (string) ( isset( $recipe['disposable_target_digest'] ) ? $recipe['disposable_target_digest'] : '' ) ) ),
			'runtime_generation'=>$generation,
			'restore_epoch'=>$expected_epoch,
			'expires_at'=>$expires_at,
		);
	}

	public static function execute( array $context, array $recipe ) {
		$valid = self::validate_recipe( $context, $recipe );
		if ( is_wp_error( $valid ) ) return $valid;
		$adapter = $context['adapter'];
		$ability = $context['target_ability'];
		$input = $context['target_input'];

		$before = $adapter->capture_reversible_state( $ability, $input );
		if ( is_wp_error( $before ) || ! is_array( $before ) || ! isset( $before['target'], $before['state'] ) ) return new WP_Error( 'mad4b_reversible_canary_capture_failed', 'Canary fixture pre-state could not be captured.' );
		$target_digest = self::stable_digest( $before['target'] );
		$disposable = $adapter->canary_target_is_disposable( $ability, $before['target'] );
		if ( true !== $disposable ) return new WP_Error( 'mad4b_reversible_canary_target_not_disposable', 'Adapter could not prove the exact target is an isolated disposable fixture.' );
		$effects_before = $adapter->capture_canary_effect_state( $ability, $before['target'] );
		if ( is_wp_error( $effects_before ) || ! is_array( $effects_before ) ) return new WP_Error( 'mad4b_reversible_canary_effect_state_unavailable', 'Canary external/hook effect pre-state could not be captured.' );
		$effects_before_sha = self::stable_digest( $effects_before );
		if ( '' === $effects_before_sha ) return new WP_Error( 'mad4b_reversible_canary_effect_state_unavailable', 'Canary effect pre-state digest is unavailable.' );
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $valid['disposable_target_digest'] ) || ! hash_equals( $target_digest, $valid['disposable_target_digest'] ) ) return new WP_Error( 'mad4b_reversible_canary_disposable_target_mismatch', 'Captured target does not match the exact approved disposable fixture.' );
		$before_json = self::bounded_json( $before['state'] );
		if ( is_wp_error( $before_json ) ) return $before_json;
		$before_sha = hash( 'sha256', $before_json );
		$generation_check = MAD4B_SCP_Runtime_Generation_Fence::assert_current( $valid['runtime_generation'] );
		if ( is_wp_error( $generation_check ) ) return $generation_check;

		$result = null;
		$provider_error = null;
		$after = null; $effects_after = null; $observation_error = null; $restored = null;
		try {
			try { $result = $adapter->execute_canary( $ability, $input ); }
			catch ( Throwable $e ) { $provider_error = new WP_Error( 'mad4b_reversible_canary_provider_exception', 'Provider canary threw after admission; rollback verification is mandatory.' ); }
			if ( is_wp_error( $result ) ) $provider_error = $result;
			try {
				$after = $adapter->read_reversible_state( $ability, $before['target'] );
				$effects_after = $adapter->capture_canary_effect_state( $ability, $before['target'] );
			} catch ( Throwable $e ) {
				$observation_error = new WP_Error( 'mad4b_reversible_canary_observation_uncertain', 'Canary observation failed; cleanup remains mandatory and promotion is prohibited.' );
			}
		} finally {
			try {
				$restored = $adapter->restore_reversible_state( $ability, $before['target'], $before['state'], array(
					'reversible_canary_certification'=>true, 'recipe_sha256'=>$valid['recipe_sha256'], 'blind_retry_allowed'=>false,
				) );
			} catch ( Throwable $e ) {
				$restored = new WP_Error( 'mad4b_reversible_canary_cleanup_exception', 'Canary cleanup threw; restoration is uncertain.' );
			}
		}
		$effects_after_sha = ! is_wp_error( $effects_after ) && is_array( $effects_after ) ? self::stable_digest( $effects_after ) : '';
		$after_known = ! is_wp_error( $after ) && is_array( $after );
		$after_sha = $after_known ? self::stable_digest( $after ) : '';
		$changed = $after_known && '' !== $after_sha && ! hash_equals( $before_sha, $after_sha );

		if ( is_wp_error( $restored ) || true !== $restored ) return new WP_Error( 'mad4b_reversible_canary_restoration_failed', 'Canary cleanup failed or was uncertain. Promotion and blind retry are prohibited.', array( 'blind_retry_allowed'=>false, 'promotion_eligible'=>false ) );

		try {
			$final = $adapter->read_reversible_state( $ability, $before['target'] );
			$effects_final = $adapter->capture_canary_effect_state( $ability, $before['target'] );
		} catch ( Throwable $e ) {
			return new WP_Error( 'mad4b_reversible_canary_restoration_readback_failed', 'Canary cleanup readback threw; promotion and blind retry are prohibited.' );
		}
		if ( is_wp_error( $final ) || ! is_array( $final ) ) return new WP_Error( 'mad4b_reversible_canary_restoration_readback_failed', 'Canary cleanup could not be verified exactly. Promotion and blind retry are prohibited.', array( 'blind_retry_allowed'=>false, 'promotion_eligible'=>false ) );
		$final_sha = self::stable_digest( $final );
		$effects_final_sha = ! is_wp_error( $effects_final ) && is_array( $effects_final ) ? self::stable_digest( $effects_final ) : '';
		if ( '' === $final_sha || ! hash_equals( $before_sha, $final_sha ) ) return new WP_Error( 'mad4b_reversible_canary_restoration_mismatch', 'Restored state does not equal the exact native pre-state.', array( 'blind_retry_allowed'=>false, 'promotion_eligible'=>false ) );
		if ( '' === $effects_final_sha || ! hash_equals( $effects_before_sha, $effects_final_sha ) ) return new WP_Error( 'mad4b_reversible_canary_effect_restoration_mismatch', 'Hook/external-effect state did not return to the exact captured pre-state.', array( 'blind_retry_allowed'=>false, 'promotion_eligible'=>false ) );
		if ( is_wp_error( $observation_error ) || '' === $effects_after_sha ) return new WP_Error( 'mad4b_reversible_canary_observation_uncertain', 'Canary observation is incomplete; exact cleanup succeeded but no receipt can be issued.', array( 'restoration_verified'=>true, 'blind_retry_allowed'=>false ) );
		if ( is_wp_error( $provider_error ) ) return new WP_Error( 'mad4b_reversible_canary_provider_failed_restored', 'Provider canary failed; exact restoration succeeded but no promotion evidence may be issued.', array( 'provider_error_code'=>$provider_error->get_error_code(), 'blind_retry_allowed'=>false, 'restoration_verified'=>true ) );
		if ( ! $changed ) return new WP_Error( 'mad4b_reversible_canary_no_observable_effect', 'Canary produced no exact observable fixture state change; no behavioral receipt was issued.' );

		$identity = class_exists( 'MAD4B_SCP_Identity_Context' ) ? MAD4B_SCP_Identity_Context::current() : array();
		if ( is_wp_error( $identity ) ) return $identity;
		$subject = isset( $identity['subject_fingerprint'] ) ? strtolower( trim( (string) $identity['subject_fingerprint'] ) ) : '';
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $subject ) ) return new WP_Error( 'mad4b_reversible_canary_subject_identity_required', 'Signed canary receipt requires an exact authenticated subject fingerprint.' );
		$generation_check = MAD4B_SCP_Runtime_Generation_Fence::assert_current( $valid['runtime_generation'] );
		if ( is_wp_error( $generation_check ) ) return $generation_check;
		if ( $subject !== $valid['subject_fingerprint'] ) return new WP_Error( 'mad4b_reversible_canary_subject_changed', 'Subject changed during the canary; cleanup succeeded but no receipt may be issued.' );
		$site_uuid = class_exists( 'MAD4B_SCP_Site_Profile' ) ? (string) MAD4B_SCP_Site_Profile::site_uuid() : '';
		$environment = class_exists( 'MAD4B_SCP_Site_Profile' ) ? sanitize_key( (string) MAD4B_SCP_Site_Profile::current_environment() ) : '';
		if ( $valid['site_uuid'] !== $site_uuid || $valid['environment'] !== $environment ) return new WP_Error( 'mad4b_reversible_canary_site_binding_required', 'Site/environment changed during canary execution; no receipt may be issued.' );

		$receipt = array(
			'contract'=>self::RECEIPT_CONTRACT,
			'recipe_id'=>$valid['recipe_id'],
			'recipe_sha256'=>$valid['recipe_sha256'],
			'provider_id'=>$context['provider_id'],
			'capability_id'=>$context['capability_id'],
			'target_ability'=>$ability,
			'artifact_fingerprint'=>$context['artifact_fingerprint'],
			'capability_contract_digest'=>$context['capability_contract_digest'],
			'canary_basis_digest'=>$context['canary_basis_digest'],
			'candidate_sha'=>$context['candidate']['source_commit_sha'],
			'build_fingerprint'=>$context['candidate']['build_fingerprint'],
			'target_input_digest'=>$context['target_input_digest'],
			'disposable_target_digest'=>$target_digest,
			'rollback_contract'=>$valid['rollback_contract'],
			'before_state_sha256'=>$before_sha,
			'after_state_sha256'=>$after_sha,
			'restored_state_sha256'=>$final_sha,
			'effects_before_sha256'=>$effects_before_sha,
			'effects_after_sha256'=>$effects_after_sha,
			'effects_restored_sha256'=>$effects_final_sha,
			'state_change_observed'=>true,
			'restoration_verified'=>true,
			'site_uuid'=>$site_uuid,
			'environment'=>$environment,
			'subject_fingerprint'=>$subject,
			'runtime_generation_sha256'=>$valid['runtime_generation']['generation_sha256'],
			'restore_epoch'=>$valid['restore_epoch'],
			'issued_at'=>self::now_epoch(),
			'expires_at'=>min( $valid['expires_at'], self::now_epoch()+21600 ),
			'revocation_mode'=>'restore_epoch_or_signing_key_or_pack_revocation',
			'blind_retry_allowed'=>false,
			'financial_effects_allowed'=>false,
			'authorizing'=>false,
			'promotion_granted'=>false,
		);
		$receipt['receipt_sha256']=self::stable_digest( $receipt );
		$profile=MAD4B_SCP_Crypto_Profile::default_profile( 'behavioral_evidence' );
		if ( is_wp_error( $profile ) || '' === (string) $profile ) return is_wp_error( $profile ) ? $profile : new WP_Error( 'mad4b_reversible_canary_crypto_profile_unavailable', 'Behavioral evidence signing profile is unavailable.' );
		$signature=MAD4B_SCP_Crypto_Profile::sign_digest( $profile, $receipt['receipt_sha256'] );
		if ( is_wp_error( $signature ) ) return $signature;
		$receipt['signature']=$signature;
		return array( 'provider_result'=>$result, 'receipt'=>$receipt );
	}

	public static function payload_sha256( array $recipe ) {
		unset( $recipe['recipe_sha256'], $recipe['signature'] );
		return self::stable_digest( $recipe );
	}

	private static function bounded_json( $value ) {
		$json=self::stable_json( $value );
		if ( '' === $json ) return new WP_Error( 'mad4b_reversible_canary_state_invalid', 'Canary state cannot be canonically encoded.' );
		if ( strlen( $json ) > self::MAX_STATE_BYTES ) return new WP_Error( 'mad4b_reversible_canary_state_too_large', 'Canary state exceeds bounded certification size.' );
		return $json;
	}
	private static function stable_digest( $value ) { $j=self::bounded_json($value); return is_wp_error($j)?'':hash('sha256',$j); }
	private static function stable_json( $value ) {
		$value=self::canon($value);
		$j=function_exists('wp_json_encode')?wp_json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE):json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
		return is_string($j)?$j:'';
	}
	private static function canon( $value ) {
		if(is_array($value)){ $keys=array_keys($value);$list=array()===$value||$keys===range(0,count($value)-1);if($list)return array_map(array(__CLASS__,'canon'),$value);sort($keys,SORT_STRING);$out=array();foreach($keys as$key)$out[(string)$key]=self::canon($value[$key]);return$out; }
		if(is_object($value))return self::canon(get_object_vars($value));
		return$value;
	}
	private static function now_epoch(){return class_exists('MAD4B_SCP_Time_Policy')&&method_exists('MAD4B_SCP_Time_Policy','now_epoch')?(int)MAD4B_SCP_Time_Policy::now_epoch():time();}
}
