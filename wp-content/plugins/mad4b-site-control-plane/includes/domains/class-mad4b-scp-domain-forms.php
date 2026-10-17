<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Form definitions and submission authority are independent provider-owned contracts. */
final class MAD4B_SCP_Domain_Forms {
	public static function validate( $profile, array $desired, array $facts ) {
		if ( 'form_config' === $profile ) return self::configuration( $desired, $facts );
		if ( 'form_submissions' === $profile ) return self::submissions( $desired, $facts );
		return MAD4B_SCP_Domain_Contracts::error( 'forms_profile' );
	}

	private static function configuration( array $desired, array $facts ) {
		$check = MAD4B_SCP_Domain_Contracts::keys( $desired, array( 'native_format','fields','settings' ), array( 'native_format','fields','settings' ) );
		if ( is_wp_error( $check ) ) return $check;
		if ( ! is_string( $desired['native_format'] ) || $desired['native_format'] !== ( $facts['native_format'] ?? '' ) || true !== ( $facts['definition_available'] ?? null ) || true !== ( $facts['serialization_contract'] ?? null ) ) return MAD4B_SCP_Domain_Contracts::error( 'form_serialization' );
		if ( ! is_array( $desired['fields'] ) || ! is_array( $desired['settings'] ) ) return MAD4B_SCP_Domain_Contracts::error( 'form_shape' );
		$ids = array(); $count = 0;
		$valid = self::fields( $desired['fields'], $facts, $ids, $count, 0 );
		if ( is_wp_error( $valid ) ) return $valid;
		if ( $desired['settings'] ) {
			$valid = MAD4B_SCP_Domain_Contracts::fields( $desired['settings'], $facts, array( 'local_config' ) );
			if ( is_wp_error( $valid ) ) return $valid;
		}
		return MAD4B_SCP_Domain_Contracts::result( 'form_config', $desired, array( 'provider_serialization','nested_fields','per_field_effects','no_submission_values' ), 'governed_config' );
	}

	private static function fields( array $fields, array $facts, array &$ids, &$count, $depth ) {
		if ( ! MAD4B_SCP_Domain_Contracts::is_list( $fields ) || ! $fields || $depth > 5 ) return MAD4B_SCP_Domain_Contracts::error( 'form_nesting' );
		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) || ++$count > 128 ) return MAD4B_SCP_Domain_Contracts::error( 'form_field_bound' );
			$check = MAD4B_SCP_Domain_Contracts::keys( $field, array( 'id','type','required','choices','children' ), array( 'id','type','required' ) );
			if ( is_wp_error( $check ) ) return $check;
			if ( ! MAD4B_SCP_Domain_Contracts::identifier( $field['id'] ) || isset( $ids[ $field['id'] ] ) || ! is_string( $field['type'] ) || ! is_bool( $field['required'] ) || ! in_array( $field['type'], $facts['allowed_field_types'] ?? array(), true ) ) return MAD4B_SCP_Domain_Contracts::error( 'form_field_contract' );
			$ids[ $field['id'] ] = true;
			if ( isset( $field['choices'] ) ) {
				if ( ! is_array( $field['choices'] ) || ! MAD4B_SCP_Domain_Contracts::is_list( $field['choices'] ) || count( $field['choices'] ) > 64 ) return MAD4B_SCP_Domain_Contracts::error( 'form_choices' );
				$seen = array();
				foreach ( $field['choices'] as $choice ) {
					if ( ! is_string( $choice ) || '' === $choice || strlen( $choice ) > 200 || isset( $seen[ $choice ] ) ) return MAD4B_SCP_Domain_Contracts::error( 'form_choices' );
					$seen[ $choice ] = true;
				}
			}
			if ( isset( $field['children'] ) ) {
				if ( ! in_array( $field['type'], $facts['repeater_types'] ?? array(), true ) || ! is_array( $field['children'] ) ) return MAD4B_SCP_Domain_Contracts::error( 'form_repeater' );
				$valid = self::fields( $field['children'], $facts, $ids, $count, $depth + 1 );
				if ( is_wp_error( $valid ) ) return $valid;
			}
			if ( in_array( $field['type'], $facts['upload_types'] ?? array(), true ) && true !== ( $facts['upload_policy_certified'] ?? null ) ) return MAD4B_SCP_Domain_Contracts::error( 'form_upload_policy' );
		}
		return MAD4B_SCP_Domain_Contracts::no_secrets( $fields );
	}

	private static function submissions( array $desired, array $facts ) {
		$check = MAD4B_SCP_Domain_Contracts::keys( $desired, array( 'action','field_ids','limit','cursor','masked' ), array( 'action','field_ids','limit','cursor','masked' ) );
		if ( is_wp_error( $check ) ) return $check;
		if ( true !== ( $facts['entries_available'] ?? null ) || true !== ( $facts['object_access'] ?? null ) || true !== ( $facts['consent_current'] ?? null ) ) return MAD4B_SCP_Domain_Contracts::error( 'submission_authority_or_prerequisite' );
		if ( ! in_array( $desired['action'], array( 'preview','export','delete' ), true ) || ! is_int( $desired['limit'] ) || $desired['limit'] < 1 || $desired['limit'] > 50 || ! is_string( $desired['cursor'] ) || strlen( $desired['cursor'] ) > 256 || true !== $desired['masked'] ) return MAD4B_SCP_Domain_Contracts::error( 'submission_preview_bound' );
		if ( ! is_array( $desired['field_ids'] ) || ! MAD4B_SCP_Domain_Contracts::is_list( $desired['field_ids'] ) || ! $desired['field_ids'] || count( $desired['field_ids'] ) > 32 ) return MAD4B_SCP_Domain_Contracts::error( 'submission_field_bound' );
		$seen = array();
		foreach ( $desired['field_ids'] as $field ) {
			$grant = is_string( $field ) ? ( $facts['field_access'][ $field ] ?? array() ) : array();
			if ( ! MAD4B_SCP_Domain_Contracts::identifier( $field ) || isset( $seen[ $field ] ) || true !== ( $grant['authorized'] ?? null ) || true !== ( $grant['redaction_verified'] ?? null ) || false !== ( $grant['private_upload'] ?? null ) ) return MAD4B_SCP_Domain_Contracts::error( 'submission_field_authority' );
			$seen[ $field ] = true;
		}
		if ( 'preview' !== $desired['action'] && ( true !== ( $facts['explicit_action_review'] ?? null ) || true !== ( $facts['retention_eligible'] ?? null ) ) ) return MAD4B_SCP_Domain_Contracts::error( 'submission_action_review' );
		$result = MAD4B_SCP_Domain_Contracts::result( 'form_submissions', $desired, array( 'independent_entries_readiness','object_and_field_authority','masked_only','retention_review' ), 'preview' === $desired['action'] ? 'private_read' : 'high_risk_explicit_gate' );
		$result['execution_supported'] = false;
		$result['delete_is_irreversible'] = 'delete' === $desired['action'];
		return $result;
	}
}
