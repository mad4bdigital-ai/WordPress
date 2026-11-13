<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Exact remote-media state evidence plus publish-time rights enforcement. */
final class MAD4B_SCP_Remote_Media_Rights {
	const CONTRACT = 'mad4b.remote-media-publish-provenance.v1';
	const STATE_CONTRACT = 'mad4b.remote-media-state-evidence.v1';

	private static function evaluation_date( $today = '' ) {
		$today = trim( (string) $today );
		if ( '' === $today ) $today = wp_date( 'Y-m-d' );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $today ) ) return new WP_Error( 'mad4b_remote_media_rights_date_invalid', 'Remote media rights evaluation date must use YYYY-MM-DD.' );
		$parts = array_map( 'intval', explode( '-', $today ) );
		if ( 3 !== count( $parts ) || ! checkdate( $parts[1], $parts[2], $parts[0] ) ) return new WP_Error( 'mad4b_remote_media_rights_date_invalid', 'Remote media rights evaluation date is invalid.' );
		return $today;
	}

	private static function valid_iso_date( $value ) {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) return false;
		$parts = array_map( 'intval', explode( '-', $value ) );
		return 3 === count( $parts ) && checkdate( $parts[1], $parts[2], $parts[0] );
	}

	private static function attachment_ids( $featured_media_id, array $field_specs, array $effective_meta ) {
		$ids = array();
		$featured_media_id = absint( $featured_media_id );
		if ( $featured_media_id > 0 ) $ids[] = $featured_media_id;
		foreach ( $field_specs as $key => $spec ) {
			$kind = isset( $spec['kind'] ) ? (string) $spec['kind'] : '';
			if ( ! in_array( $kind, array( 'image_id', 'attachment_id', 'image_gallery', 'attachment_gallery' ), true ) || ! array_key_exists( $key, $effective_meta ) ) continue;
			$ids = array_merge( $ids, MAD4B_SCP_Content_Experience_Media_Storage::reference_ids( $effective_meta[ $key ], $spec ) );
		}
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		sort( $ids, SORT_NUMERIC );
		return $ids;
	}

	private static function provenance_event_sha256( array $event ) {
		$stored = isset( $event['provenance_event_sha256'] ) ? strtolower( trim( (string) $event['provenance_event_sha256'] ) ) : '';
		if ( preg_match( '/^[a-f0-9]{64}$/', $stored ) ) return $stored;
		$identity = array(
			'contract' => isset( $event['contract'] ) ? (string) $event['contract'] : '',
			'source_url_sha256' => isset( $event['source_url_sha256'] ) ? strtolower( trim( (string) $event['source_url_sha256'] ) ) : '',
			'source_page_sha256' => isset( $event['source_page_sha256'] ) ? strtolower( trim( (string) $event['source_page_sha256'] ) ) : '',
			'rights_basis' => isset( $event['rights_basis'] ) ? sanitize_key( (string) $event['rights_basis'] ) : '',
			'rights_note' => isset( $event['rights_note'] ) ? (string) $event['rights_note'] : '',
			'rights_reference' => isset( $event['rights_reference'] ) ? (string) $event['rights_reference'] : '',
			'license_expires_on' => isset( $event['license_expires_on'] ) ? (string) $event['license_expires_on'] : '',
			'content_sha256' => isset( $event['content_sha256'] ) ? strtolower( trim( (string) $event['content_sha256'] ) ) : '',
		);
		return hash( 'sha256', wp_json_encode( $identity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	public static function state_evidence( $featured_media_id, array $field_specs, array $effective_meta ) {
		$ids = self::attachment_ids( $featured_media_id, $field_specs, $effective_meta );
		$source_meta = class_exists( 'MAD4B_SCP_Remote_Media_Adapter' ) ? MAD4B_SCP_Remote_Media_Adapter::REMOTE_SOURCE_HASH_META : '_mad4b_remote_media_source_sha256';
		$content_meta = class_exists( 'MAD4B_SCP_Remote_Media_Adapter' ) ? MAD4B_SCP_Remote_Media_Adapter::REMOTE_CONTENT_HASH_META : '_mad4b_remote_media_content_sha256';
		$provenance_meta = class_exists( 'MAD4B_SCP_Remote_Media_Adapter' ) ? MAD4B_SCP_Remote_Media_Adapter::REMOTE_PROVENANCE_META : '_mad4b_remote_media_provenance';
		$provenance_contract = class_exists( 'MAD4B_SCP_Remote_Media_Adapter' ) ? MAD4B_SCP_Remote_Media_Adapter::REMOTE_PROVENANCE_CONTRACT : 'mad4b.remote-media-provenance.v1';
		$rows = array();
		foreach ( $ids as $id ) {
			$source_hashes = array_values( array_filter( array_map( static function ( $value ) { return strtolower( trim( (string) $value ) ); }, (array) get_post_meta( $id, $source_meta, false ) ) ) );
			$source_hashes = array_values( array_unique( $source_hashes ) ); sort( $source_hashes, SORT_STRING );
			$content_hash = strtolower( trim( (string) get_post_meta( $id, $content_meta, true ) ) );
			$history = array_values( array_filter( (array) get_post_meta( $id, $provenance_meta, false ), static function ( $row ) use ( $provenance_contract ) {
				return is_array( $row ) && $provenance_contract === ( isset( $row['contract'] ) ? (string) $row['contract'] : '' );
			} ) );
			if ( empty( $source_hashes ) && '' === $content_hash && empty( $history ) ) continue;
			if ( ! preg_match( '/^[a-f0-9]{64}$/', $content_hash ) ) return new WP_Error( 'mad4b_remote_media_publish_content_identity_missing', 'Remote media attachment has no exact content identity.', array( 'attachment_id' => $id ) );
			if ( empty( $history ) ) return new WP_Error( 'mad4b_remote_media_publish_provenance_missing', 'Remote media attachment has no governed provenance record.', array( 'attachment_id' => $id ) );
			$latest = end( $history );
			$event_content_hash = isset( $latest['content_sha256'] ) ? strtolower( trim( (string) $latest['content_sha256'] ) ) : '';
			if ( ! preg_match( '/^[a-f0-9]{64}$/', $event_content_hash ) || ! hash_equals( $content_hash, $event_content_hash ) ) return new WP_Error( 'mad4b_remote_media_publish_provenance_drift', 'Remote media provenance no longer matches the attachment content identity.', array( 'attachment_id' => $id ) );
			$source_hash = isset( $latest['source_url_sha256'] ) ? strtolower( trim( (string) $latest['source_url_sha256'] ) ) : '';
			if ( ! preg_match( '/^[a-f0-9]{64}$/', $source_hash ) || ! in_array( $source_hash, $source_hashes, true ) ) return new WP_Error( 'mad4b_remote_media_publish_source_identity_drift', 'Remote media provenance no longer matches a recorded source identity.', array( 'attachment_id' => $id ) );
			$rows[] = array(
				'attachment_id' => $id,
				'content_sha256' => $content_hash,
				'source_url_sha256' => $source_hash,
				'source_aliases_sha256' => hash( 'sha256', wp_json_encode( $source_hashes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ),
				'provenance_event_sha256' => self::provenance_event_sha256( $latest ),
				'rights_basis' => isset( $latest['rights_basis'] ) ? sanitize_key( (string) $latest['rights_basis'] ) : '',
				'rights_evidence_present' => '' !== trim( isset( $latest['rights_note'] ) ? (string) $latest['rights_note'] : '' ) || '' !== trim( isset( $latest['rights_reference'] ) ? (string) $latest['rights_reference'] : '' ),
				'license_expires_on' => isset( $latest['license_expires_on'] ) ? trim( (string) $latest['license_expires_on'] ) : '',
			);
		}
		$encoded = wp_json_encode( $rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return array(
			'contract' => self::STATE_CONTRACT, 'checked_attachment_count' => count( $ids ),
			'remote_attachment_count' => count( $rows ), 'remote_items' => $rows,
			'remote_state_sha256' => hash( 'sha256', false === $encoded ? '' : $encoded ), 'valid' => true,
		);
	}

	public static function publish_guard( $featured_media_id, array $field_specs, array $effective_meta, $today = '' ) {
		$today = self::evaluation_date( $today );
		if ( is_wp_error( $today ) ) return $today;
		// Site-neutral editorial default: missing rights metadata is advisory,
		// while exact attachment/source/provenance identity remains mandatory.
		// Operators can explicitly select enforced mode for stricter workflows.
		// Advisory never establishes copyright permission or a verified license.
		$rights_mode = sanitize_key( (string) apply_filters( 'mad4b_remote_media_rights_mode', get_option( 'mad4b_remote_media_rights_mode', 'advisory' ) ) );
		if ( ! in_array( $rights_mode, array( 'advisory', 'enforced' ), true ) ) return new WP_Error( 'mad4b_remote_media_rights_policy_invalid', 'Media rights policy must be advisory or enforced.' );
		$advisory = 'advisory' === $rights_mode;
		$state = self::state_evidence( $featured_media_id, $field_specs, $effective_meta );
		if ( is_wp_error( $state ) ) return $state;
		$nearest_expiry = '';
		$warnings = array();
		foreach ( $state['remote_items'] as $row ) {
			$id = (int) $row['attachment_id'];
			$basis = (string) $row['rights_basis'];
			$known = in_array( $basis, array( 'owned', 'licensed', 'permission', 'public_domain', 'creative_commons' ), true );
			if ( ! $known ) {
				if ( ! $advisory ) return new WP_Error( 'mad4b_remote_media_publish_rights_unproven', 'Remote media rights basis is not verified.', array( 'attachment_id' => $id ) );
				$warnings[] = array( 'attachment_id' => $id, 'code' => 'rights_basis_unverified' );
			}
			if ( 'owned' !== $basis && empty( $row['rights_evidence_present'] ) ) {
				if ( ! $advisory ) return new WP_Error( 'mad4b_remote_media_publish_rights_evidence_missing', 'Remote media reuse evidence is missing.', array( 'attachment_id' => $id ) );
				$warnings[] = array( 'attachment_id' => $id, 'code' => 'rights_evidence_missing' );
			}
			$expiry = (string) $row['license_expires_on'];
			if ( '' !== $expiry ) {
				if ( ! self::valid_iso_date( $expiry ) || $expiry < $today ) {
					// A known invalid/expired license is a concrete restriction, not merely
					// absent evidence. Keep it blocking in either policy mode.
					return new WP_Error( 'mad4b_remote_media_publish_rights_expired', 'Remote media license is invalid or expired.', array( 'attachment_id' => $id, 'expired_on' => $expiry, 'evaluated_on' => $today ) );
				} elseif ( '' === $nearest_expiry || $expiry < $nearest_expiry ) $nearest_expiry = $expiry;
			}
		}
		return array(
			'contract' => self::CONTRACT, 'evaluated_on' => $today,
			'checked_attachment_count' => (int) $state['checked_attachment_count'],
			'remote_attachment_count' => (int) $state['remote_attachment_count'],
			'nearest_expiry' => $nearest_expiry,
			'remote_state_sha256' => (string) $state['remote_state_sha256'],
			'license_mode' => $advisory ? 'advisory' : 'enforced',
			'license_warnings' => $warnings, 'license_warning_count' => count( $warnings ),
			'legal_reuse_permission' => 'not_determined_by_system',
			'valid' => true,
		);
	}}
