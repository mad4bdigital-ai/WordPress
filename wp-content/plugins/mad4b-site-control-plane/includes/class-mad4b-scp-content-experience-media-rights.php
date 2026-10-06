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

	private static function reference_ids( $value ) {
		if ( is_array( $value ) ) return array_values( array_map( 'absint', $value ) );
		if ( is_string( $value ) && '' !== $value ) return array_values( array_filter( array_map( 'absint', explode( ',', $value ) ) ) );
		if ( is_numeric( $value ) && (int) $value > 0 ) return array( (int) $value );
		return array();
	}

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
			$reference_ids = isset( $effective_meta[ $reference ] ) ? self::reference_ids( $effective_meta[ $reference ] ) : array();
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
	public static function remote_provenance_guard( $featured_media_id, array $field_specs, array $effective_meta, $today = '' ) {
		$today = self::evaluation_date( $today );
		if ( is_wp_error( $today ) ) return $today;
		$ids = array();
		$featured_media_id = absint( $featured_media_id );
		if ( $featured_media_id > 0 ) $ids[] = $featured_media_id;
		foreach ( $field_specs as $key => $spec ) {
			$kind = isset( $spec['kind'] ) ? (string) $spec['kind'] : '';
			if ( ! in_array( $kind, array( 'image_id', 'attachment_id', 'image_gallery', 'attachment_gallery' ), true ) ) continue;
			if ( ! array_key_exists( $key, $effective_meta ) ) continue;
			$ids = array_merge( $ids, self::reference_ids( $effective_meta[ $key ] ) );
		}
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		sort( $ids, SORT_NUMERIC );

		$checked = 0; $remote = 0; $nearest_expiry = '';
		$source_meta = class_exists( 'MAD4B_SCP_Media_Adapter' ) ? MAD4B_SCP_Media_Adapter::REMOTE_SOURCE_HASH_META : '_mad4b_remote_media_source_sha256';
		$content_meta = class_exists( 'MAD4B_SCP_Media_Adapter' ) ? MAD4B_SCP_Media_Adapter::REMOTE_CONTENT_HASH_META : '_mad4b_remote_media_content_sha256';
		$provenance_meta = class_exists( 'MAD4B_SCP_Media_Adapter' ) ? MAD4B_SCP_Media_Adapter::REMOTE_PROVENANCE_META : '_mad4b_remote_media_provenance';
		$provenance_contract = class_exists( 'MAD4B_SCP_Media_Adapter' ) ? MAD4B_SCP_Media_Adapter::REMOTE_PROVENANCE_CONTRACT : 'mad4b.remote-media-provenance.v1';

		foreach ( $ids as $id ) {
			++$checked;
			$source_hashes = array_values( array_filter( array_map( 'strval', (array) get_post_meta( $id, $source_meta, false ) ) ) );
			$content_hash = strtolower( trim( (string) get_post_meta( $id, $content_meta, true ) ) );
			$history = array_values( array_filter( (array) get_post_meta( $id, $provenance_meta, false ), static function ( $row ) use ( $provenance_contract ) {
				return is_array( $row ) && $provenance_contract === ( isset( $row['contract'] ) ? (string) $row['contract'] : '' );
			} ) );
			$is_remote = ! empty( $source_hashes ) || '' !== $content_hash || ! empty( $history );
			if ( ! $is_remote ) continue;
			++$remote;
			if ( ! preg_match( '/^[a-f0-9]{64}$/', $content_hash ) ) return new WP_Error(
				'mad4b_remote_media_publish_content_identity_missing',
				'Publish is denied because a remote media attachment has no exact content identity.',
				array( 'attachment_id' => $id )
			);
			if ( empty( $history ) ) return new WP_Error(
				'mad4b_remote_media_publish_provenance_missing',
				'Publish is denied because a remote media attachment has no governed provenance record.',
				array( 'attachment_id' => $id )
			);
			$latest = end( $history );
			$event_content_hash = isset( $latest['content_sha256'] ) ? strtolower( trim( (string) $latest['content_sha256'] ) ) : '';
			if ( ! preg_match( '/^[a-f0-9]{64}$/', $event_content_hash ) || ! hash_equals( $content_hash, $event_content_hash ) ) return new WP_Error(
				'mad4b_remote_media_publish_provenance_drift',
				'Publish is denied because remote media provenance no longer matches the attachment content identity.',
				array( 'attachment_id' => $id )
			);
			$source_hash = isset( $latest['source_url_sha256'] ) ? strtolower( trim( (string) $latest['source_url_sha256'] ) ) : '';
			if ( ! preg_match( '/^[a-f0-9]{64}$/', $source_hash ) || ! in_array( $source_hash, $source_hashes, true ) ) return new WP_Error(
				'mad4b_remote_media_publish_source_identity_drift',
				'Publish is denied because remote media provenance no longer matches a recorded source identity.',
				array( 'attachment_id' => $id )
			);
			$rights_basis = isset( $latest['rights_basis'] ) ? sanitize_key( (string) $latest['rights_basis'] ) : '';
			if ( ! in_array( $rights_basis, array( 'owned', 'licensed', 'permission', 'public_domain', 'creative_commons' ), true ) ) return new WP_Error(
				'mad4b_remote_media_publish_rights_unproven',
				'Publish is denied because a remote media attachment has no accepted rights basis.',
				array( 'attachment_id' => $id )
			);
			if ( 'owned' !== $rights_basis ) {
				$note = isset( $latest['rights_note'] ) ? trim( (string) $latest['rights_note'] ) : '';
				$reference = isset( $latest['rights_reference'] ) ? trim( (string) $latest['rights_reference'] ) : '';
				if ( '' === $note && '' === $reference ) return new WP_Error(
					'mad4b_remote_media_publish_rights_evidence_missing',
					'Publish is denied because non-owned remote media has no traceable rights evidence.',
					array( 'attachment_id' => $id, 'rights_basis' => $rights_basis )
				);
			}
			$expiry = isset( $latest['license_expires_on'] ) ? trim( (string) $latest['license_expires_on'] ) : '';
			if ( '' !== $expiry ) {
				if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $expiry ) || $expiry < $today ) return new WP_Error(
					'mad4b_remote_media_publish_rights_expired',
					'Publish is denied because remote media rights are expired or invalid.',
					array( 'attachment_id' => $id, 'expired_on' => $expiry, 'evaluated_on' => $today )
				);
				if ( '' === $nearest_expiry || $expiry < $nearest_expiry ) $nearest_expiry = $expiry;
			}
		}
		return array(
			'contract' => 'mad4b.remote-media-publish-provenance.v1',
			'evaluated_on' => $today,
			'checked_attachment_count' => $checked,
			'remote_attachment_count' => $remote,
			'nearest_expiry' => $nearest_expiry,
			'valid' => true,
		);
	}

}
