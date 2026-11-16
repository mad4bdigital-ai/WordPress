<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Time-sensitive publish guard for contextual media rights.
 *
 * Canonical media state remains time-independent. This guard is evaluated only
 * while planning publish operations so verification hashes do not drift daily.
 */
final class MAD4B_SCP_Content_Experience_Media_Rights {
	const CONTRACT = 'mad4b.content-experience-media-publish-rights.v1';
	private static function evaluation_date( $today = '' ) {
		if ( ! is_string( $today ) ) return new WP_Error( 'mad4b_content_experience_media_rights_date_invalid', 'A trusted media evaluation date must be a string.' );
		$today = trim( $today );
		if ( '' === $today ) $today = wp_date( 'Y-m-d' );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $today ) ) {
			return new WP_Error( 'mad4b_content_experience_media_rights_date_invalid', 'Publish rights evaluation date must use YYYY-MM-DD.' );
		}
		$parts = array_map( 'intval', explode( '-', $today ) );
		if ( 3 !== count( $parts ) || ! checkdate( $parts[1], $parts[2], $parts[0] ) ) {
			return new WP_Error( 'mad4b_content_experience_media_rights_date_invalid', 'Publish rights evaluation date is invalid.' );
		}
		return $today;
	}
	/** Concrete restrictions are blocking, independent of the advisory/enforced
	 * setting. Missing data is not a restriction, and an invalid date is not
	 * evidence that a license continues indefinitely. */
	private static function explicit_restriction( $license, $expiry, $today, $key, $id ) {
		$license = sanitize_key( (string) $license );
		$expiry = trim( (string) $expiry );
		if ( in_array( $license, array( 'restricted', 'denied', 'no_reuse', 'all_rights_reserved' ), true ) )
			return new WP_Error( 'mad4b_content_experience_media_rights_explicit_restriction',
				'The recorded media reuse restriction explicitly prohibits publication.',
				array( 'key' => $key, 'attachment_id' => $id ) );
		if ( '' !== $expiry && ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $expiry )
			|| ! checkdate( (int) substr( $expiry, 5, 2 ), (int) substr( $expiry, 8, 2 ), (int) substr( $expiry, 0, 4 ) )
			|| $expiry < $today ) )
			return new WP_Error( 'mad4b_content_experience_media_rights_expired',
				'The recorded media license is invalid or expired.',
				array( 'key' => $key, 'attachment_id' => $id, 'expired_on' => $expiry ) );
		return null;
	}
	public static function publish_guard( array $field_specs, array $effective_meta, $today = '' ) {
		$today = self::evaluation_date( $today );
		if ( is_wp_error( $today ) ) return $today;
		// Site-neutral publication policy, shared with remote media rights.
		// Absence of licence metadata is advisory by default, not a claim of
		// legal reuse permission. Identity and provenance checks remain active.
		$mode_source = apply_filters( 'mad4b_remote_media_rights_mode', get_option( 'mad4b_remote_media_rights_mode', 'advisory' ) );
		$mode = is_string( $mode_source ) ? sanitize_key( $mode_source ) : '';
		if ( ! in_array( $mode, array( 'advisory', 'enforced' ), true ) ) return new WP_Error( 'mad4b_media_rights_policy_invalid', 'Media rights policy must be advisory or enforced.' );
		if ( 'advisory' === $mode ) {
			$fields = 0;
			$items = 0;
			$warnings = array();
			foreach ( $field_specs as $key => $spec ) {
				if ( ! is_array( $spec ) || false === strpos( (string) ( $spec['kind'] ?? '' ), '_usage' ) ) continue;
				if ( 'require_valid' !== (string) ( $spec['publish_rights_policy'] ?? 'none' ) ) continue;
				++$fields;
				$entries = isset( $effective_meta[$key] ) && is_array( $effective_meta[$key] ) ? $effective_meta[$key] : array();
				if ( ! $entries ) $warnings[] = array( 'field' => $key, 'code' => 'license_metadata_missing_or_empty' );
				foreach ( $entries as $entry ) {
					++$items;
					if ( ! is_array( $entry ) || empty( $entry['license'] ) )
						$warnings[] = array( 'field' => $key, 'code' => 'license_missing' );
					$expiry = is_array( $entry ) ? trim( (string) ( $entry['license_expires_on'] ?? '' ) ) : '';
					$license = is_array( $entry ) ? (string) ( $entry['license'] ?? '' ) : '';
					$id = is_array( $entry ) ? absint( $entry['attachment_id'] ?? 0 ) : 0;
					$restriction = self::explicit_restriction( $license, $expiry, $today, $key, $id );
					if ( is_wp_error( $restriction ) ) return $restriction;
					$expiry_required = (array) ( $spec['expiry_required_licenses'] ?? array() );
					if ( is_array( $entry ) && in_array( (string) ( $entry['license'] ?? '' ), $expiry_required, true ) && $expiry === '' )
						$warnings[] = array( 'field' => $key, 'code' => 'required_expiry_missing' );
				}
			}
			return array(
				'contract' => self::CONTRACT, 'evaluated_on' => $today,
				'checked_field_count' => $fields, 'checked_item_count' => $items,
				'nearest_expiry' => '', 'license_mode' => 'advisory',
				'license_warnings' => $warnings, 'license_warning_count' => count($warnings),
				'legal_reuse_permission' => 'not_determined_by_system', 'valid' => true,
			);
		}
		$checked_fields = 0;
		$checked_items = 0;
		$nearest_expiry = '';
		$warnings = array();
		// An already-enrolled WordPress image is publishable when optional
		// attribution fields are missing. This is NOT rights verification.
		// Unknown URLs/invalid attachment references and concrete restrictions
		// remain governed by their existing strict paths.
		$is_library_image = static function ( $attachment_id ) {
			$id = absint( $attachment_id );
			if ( $id <= 0 || get_post_type( $id ) !== 'attachment'
				|| 0 !== strpos( (string) get_post_mime_type( $id ), 'image/' ) ) return false;
			$file = get_attached_file( $id );
			return is_string( $file ) && is_file( $file );
		};
		foreach ( $field_specs as $key => $spec ) {
			if ( false === strpos( isset( $spec['kind'] ) ? (string) $spec['kind'] : '', '_usage' ) ) continue;
			if ( 'require_valid' !== ( isset( $spec['publish_rights_policy'] ) ? (string) $spec['publish_rights_policy'] : 'none' ) ) continue;
			++$checked_fields;
			$reference = isset( $spec['references_field'] ) ? (string) $spec['references_field'] : '';
			$reference_ids = isset( $effective_meta[ $reference ], $field_specs[ $reference ] ) ? MAD4B_SCP_Content_Experience_Media_Storage::reference_ids( $effective_meta[ $reference ], $field_specs[ $reference ] ) : array();
			$items = isset( $effective_meta[ $key ] ) && is_array( $effective_meta[ $key ] ) ? $effective_meta[ $key ] : array();
			if ( ! empty( $reference_ids ) && empty( $items ) ) {
				$local_only = true;
				foreach ( $reference_ids as $reference_id ) {
					if ( ! $is_library_image( $reference_id ) ) { $local_only = false; break; }
				}
				if ( ! $local_only ) return new WP_Error(
					'mad4b_content_experience_media_rights_usage_required',
					'Unresolved media references have no governed rights metadata.',
					array( 'key' => $key, 'references_field' => $reference )
				);
				$warnings[] = array( 'field' => $key, 'code' => 'local_library_license_metadata_missing' );
				continue;
			}

			$expiry_required = isset( $spec['expiry_required_licenses'] ) ? (array) $spec['expiry_required_licenses'] : array();
			foreach ( $items as $item ) {
				++$checked_items;
				$id = isset( $item['attachment_id'] ) ? absint( $item['attachment_id'] ) : 0;
				// Preserve the canonical bounded profile token (e.g. cc-by-4.0).
				// sanitize_key() removes dots and changes the configured license.
				$license = isset( $item['license'] ) && is_string( $item['license'] )
					? strtolower( trim( $item['license'] ) ) : '';
				$expiry = isset( $item['license_expires_on'] ) ? trim( (string) $item['license_expires_on'] ) : '';
				$restriction = self::explicit_restriction( $license, $expiry, $today, $key, $id );
				if ( is_wp_error( $restriction ) ) return $restriction;
				if ( '' === $license ) {
					// Missing metadata on a real image in the WordPress Library is a
					// warning, even when Enforced is selected for external imports.
					// Do not create or infer a copyright or reuse license.
					$local = $is_library_image( $id );
					if ( ! $local && $id === 0 && count( $reference_ids ) === 1 )
						$local = $is_library_image( reset( $reference_ids ) );
					if ( $local ) {
						$warnings[] = array( 'field' => $key, 'attachment_id' => $id, 'code' => 'local_library_license_metadata_missing' );
						continue;
					}
					return new WP_Error(
						'mad4b_content_experience_media_rights_license_required',
						'Unresolved image has no governed license.',
						array( 'key' => $key, 'attachment_id' => $id )
					);
				}
				if ( in_array( $license, $expiry_required, true ) && '' === $expiry ) {
					if ( $is_library_image( $id ) ) {
						$warnings[] = array( 'field' => $key, 'attachment_id' => $id, 'code' => 'local_library_license_expiry_missing' );
						continue;
					}
					return new WP_Error(
						'mad4b_content_experience_media_rights_expiry_required',
						'License expiry is missing for unresolved external media.',
						array( 'key' => $key, 'attachment_id' => $id, 'license' => $license )
					);
				}
				if ( '' !== $expiry && ( '' === $nearest_expiry || $expiry < $nearest_expiry ) )
					$nearest_expiry = $expiry;
			}
		}

		return array(
			'contract' => self::CONTRACT,
			'evaluated_on' => $today,
			'checked_field_count' => $checked_fields,
			'checked_item_count' => $checked_items,
			'nearest_expiry' => $nearest_expiry,
			'license_mode' => 'enforced',
			'license_warnings' => $warnings,
			'license_warning_count' => count( $warnings ),
			'legal_reuse_permission' => 'not_determined_by_system',
			'valid' => true,
		);
	}

}
