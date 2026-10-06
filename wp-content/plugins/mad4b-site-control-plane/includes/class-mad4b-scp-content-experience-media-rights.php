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
		$today = trim( (string) $today );
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

	public static function publish_guard( array $field_specs, array $effective_meta, $today = '' ) {
		$today = self::evaluation_date( $today );
		if ( is_wp_error( $today ) ) return $today;

		$checked_fields = 0;
		$checked_items = 0;
		$nearest_expiry = '';

		foreach ( $field_specs as $key => $spec ) {
			if ( false === strpos( isset( $spec['kind'] ) ? (string) $spec['kind'] : '', '_usage' ) ) continue;
			if ( 'require_valid' !== ( isset( $spec['publish_rights_policy'] ) ? (string) $spec['publish_rights_policy'] : 'none' ) ) continue;
			++$checked_fields;

			$reference = isset( $spec['references_field'] ) ? (string) $spec['references_field'] : '';
			$reference_ids = isset( $effective_meta[ $reference ], $field_specs[ $reference ] ) ? MAD4B_SCP_Content_Experience_Media_Storage::reference_ids( $effective_meta[ $reference ], $field_specs[ $reference ] ) : array();
			$items = isset( $effective_meta[ $key ] ) && is_array( $effective_meta[ $key ] ) ? $effective_meta[ $key ] : array();

			if ( ! empty( $reference_ids ) && empty( $items ) ) {
				return new WP_Error(
					'mad4b_content_experience_media_rights_usage_required',
					'Publish is denied because referenced media has no governed contextual rights metadata.',
					array( 'key' => $key, 'references_field' => $reference )
				);
			}

			$expiry_required = isset( $spec['expiry_required_licenses'] ) ? (array) $spec['expiry_required_licenses'] : array();
			foreach ( $items as $item ) {
				++$checked_items;
				$id = isset( $item['attachment_id'] ) ? absint( $item['attachment_id'] ) : 0;
				$license = isset( $item['license'] ) ? sanitize_key( (string) $item['license'] ) : '';
				if ( '' === $license ) {
					return new WP_Error(
						'mad4b_content_experience_media_rights_license_required',
						'Publish is denied because one media usage item has no governed license.',
						array( 'key' => $key, 'attachment_id' => $id )
					);
				}
				$expiry = isset( $item['license_expires_on'] ) ? trim( (string) $item['license_expires_on'] ) : '';
				if ( in_array( $license, $expiry_required, true ) && '' === $expiry ) {
					return new WP_Error(
						'mad4b_content_experience_media_rights_expiry_required',
						'Publish is denied because this license requires an expiry date.',
						array( 'key' => $key, 'attachment_id' => $id, 'license' => $license )
					);
				}
				if ( '' !== $expiry ) {
					if ( $expiry < $today ) {
						return new WP_Error(
							'mad4b_content_experience_media_rights_expired',
							'Publish is denied because one media usage license has expired.',
							array( 'key' => $key, 'attachment_id' => $id, 'license' => $license, 'expired_on' => $expiry, 'evaluated_on' => $today )
						);
					}
					if ( '' === $nearest_expiry || $expiry < $nearest_expiry ) $nearest_expiry = $expiry;
				}
			}
		}

		return array(
			'contract' => self::CONTRACT,
			'evaluated_on' => $today,
			'checked_field_count' => $checked_fields,
			'checked_item_count' => $checked_items,
			'nearest_expiry' => $nearest_expiry,
			'valid' => true,
		);
	}

}
