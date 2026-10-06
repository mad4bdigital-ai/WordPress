<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Exact bridge from staged remote-media manifests into content operations. */
final class MAD4B_SCP_Content_Experience_Media_Manifest {
	const CONTRACT = 'mad4b.content-experience-media-manifest.v1';

	public static function plan_guard( array $profile, $featured_media_id, array $media_fields, array $meta, array $input ) {
		$manifest = isset( $input['expected_media_manifest_sha256'] ) ? strtolower( trim( (string) $input['expected_media_manifest_sha256'] ) ) : '';
		$item_count = isset( $input['expected_media_manifest_item_count'] ) ? (int) $input['expected_media_manifest_item_count'] : 0;
		$expected_receipt = isset( $input['expected_media_recovery_receipt_sha256'] ) ? strtolower( trim( (string) $input['expected_media_recovery_receipt_sha256'] ) ) : '';
		$expected_binding = isset( $input['expected_media_binding_state_sha256'] ) ? strtolower( trim( (string) $input['expected_media_binding_state_sha256'] ) ) : '';
		$normalized = array(
			'expected_media_manifest_sha256' => $manifest,
			'expected_media_manifest_item_count' => $item_count,
			'expected_media_recovery_receipt_sha256' => $expected_receipt,
			'expected_media_binding_state_sha256' => $expected_binding,
		);
		if ( '' === $manifest ) {
			if ( $item_count > 0 || '' !== $expected_receipt || '' !== $expected_binding ) return new WP_Error( 'mad4b_content_experience_media_manifest_identity_missing', 'Manifest-derived evidence cannot be supplied without an exact manifest SHA-256.' );
			return array( 'contract' => self::CONTRACT, 'active' => false, 'normalized' => $normalized, 'receipt' => null, 'binding_state_sha256' => '', 'attachment_ids' => array() );
		}
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $manifest ) || $item_count < 1 || $item_count > MAD4B_SCP_Remote_Media_Adapter::MAX_REMOTE_CANDIDATES
			|| ! preg_match( '/^[a-f0-9]{64}$/', $expected_receipt ) || ! preg_match( '/^[a-f0-9]{64}$/', $expected_binding ) ) {
			return new WP_Error( 'mad4b_content_experience_media_manifest_evidence_incomplete', 'Manifest-bound content requires exact manifest, item-count, recovery-receipt and binding-state identities.' );
		}
		$receipt = MAD4B_SCP_Remote_Media_Recovery::manifest_receipt( $manifest, $item_count );
		if ( is_wp_error( $receipt ) ) return $receipt;
		if ( ! hash_equals( (string) $receipt['recovery_receipt_sha256'], $expected_receipt ) ) return new WP_Error( 'mad4b_content_experience_media_recovery_receipt_drift', 'Staged remote media recovery receipt changed after binding review.' );
		$binding_state = MAD4B_SCP_Content_Experience_Media_Binding::state_sha256( $profile, $featured_media_id, $meta, $manifest );
		if ( ! hash_equals( $binding_state, $expected_binding ) ) return new WP_Error( 'mad4b_content_experience_media_binding_state_drift', 'Content media mapping changed after the reviewed manifest binding plan.' );

		$receipt_ids = array_values( array_unique( array_map( static function ( $row ) { return absint( $row['attachment_id'] ?? 0 ); }, $receipt['items'] ) ) );
		$binding_ids = MAD4B_SCP_Content_Experience_Media_Binding::attachment_ids_from_state( $featured_media_id, $media_fields, $meta );
		sort( $receipt_ids, SORT_NUMERIC ); sort( $binding_ids, SORT_NUMERIC );
		if ( $receipt_ids !== $binding_ids ) return new WP_Error( 'mad4b_content_experience_media_manifest_binding_drift', 'Content media attachments no longer exactly match the staged remote media manifest.' );
		return array(
			'contract' => self::CONTRACT, 'active' => true, 'normalized' => $normalized,
			'receipt' => $receipt, 'binding_state_sha256' => $binding_state, 'attachment_ids' => $binding_ids,
		);
	}

	public static function bind_post( array $guard, $post_id ) {
		if ( empty( $guard['active'] ) ) return null;
		$n = isset( $guard['normalized'] ) && is_array( $guard['normalized'] ) ? $guard['normalized'] : array();
		return MAD4B_SCP_Remote_Media_Recovery::bind_post(
			$n['expected_media_manifest_sha256'], absint( $post_id ),
			$n['expected_media_binding_state_sha256'], $n['expected_media_recovery_receipt_sha256'],
			isset( $guard['attachment_ids'] ) ? (array) $guard['attachment_ids'] : array()
		);
	}

	public static function verify_post_binding( array $normalized_input, $post_id ) {
		if ( empty( $normalized_input['expected_media_manifest_sha256'] ) ) return true;
		return MAD4B_SCP_Remote_Media_Recovery::verify_post_binding(
			$normalized_input['expected_media_manifest_sha256'], absint( $post_id ),
			$normalized_input['expected_media_binding_state_sha256'], $normalized_input['expected_media_recovery_receipt_sha256']
		);
	}

	public static function post_meta_keys() {
		return array(
			MAD4B_SCP_Remote_Media_Recovery::POST_MANIFEST_META,
			MAD4B_SCP_Remote_Media_Recovery::POST_BINDING_META,
		);
	}

	public static function multi_post_meta_keys() { return self::post_meta_keys(); }
}
