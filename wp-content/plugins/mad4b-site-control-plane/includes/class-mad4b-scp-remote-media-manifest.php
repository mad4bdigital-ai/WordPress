<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Exact manifest-item intent and execution correlation for remote media imports. */
final class MAD4B_SCP_Remote_Media_Manifest {
	const CONTRACT = 'mad4b.remote-media-import-manifest.v1';

	public static function allowed_roles() {
		return array( 'featured', 'gallery', 'content', 'field', 'shared' );
	}

	public static function normalize_role( $value, $index = -1 ) {
		$role = sanitize_key( (string) $value );
		if ( '' === $role ) $role = 'gallery';
		if ( ! in_array( $role, self::allowed_roles(), true ) ) {
			return new WP_Error(
				'mad4b_remote_media_manifest_binding_role_invalid',
				'Remote media manifest binding_role is unsupported.',
				array( 'index' => (int) $index, 'binding_role' => $role )
			);
		}
		return $role;
	}

	public static function item_sha256( $index, $binding_role, $plan_sha256 ) {
		$identity = array(
			'index' => (int) $index,
			'binding_role' => (string) $binding_role,
			'plan_sha256' => strtolower( trim( (string) $plan_sha256 ) ),
		);
		return hash( 'sha256', wp_json_encode( $identity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	public static function import_plan_input( array $input ) {
		unset(
			$input['plan_sha256'], $input['manifest_sha256'], $input['manifest_index'], $input['manifest_item_count'],
			$input['manifest_item_sha256'], $input['manifest_binding_role'],
			$input['_mad4b_approval_ticket_id'], $input['_mad4b_context_receipt']
		);
		return $input;
	}

	public static function execution_context( array $input, $plan_sha256 ) {
		$manifest = isset( $input['manifest_sha256'] ) ? strtolower( trim( (string) $input['manifest_sha256'] ) ) : '';
		$index = isset( $input['manifest_index'] ) ? (int) $input['manifest_index'] : -1;
		$count = isset( $input['manifest_item_count'] ) ? (int) $input['manifest_item_count'] : 0;
		$item = isset( $input['manifest_item_sha256'] ) ? strtolower( trim( (string) $input['manifest_item_sha256'] ) ) : '';
		$role_raw = isset( $input['manifest_binding_role'] ) ? (string) $input['manifest_binding_role'] : '';
		$has = '' !== $manifest || $index >= 0 || $count > 0 || '' !== $item || '' !== trim( $role_raw );
		if ( ! $has ) return array( 'active' => false, 'manifest_sha256' => '', 'manifest_index' => -1, 'manifest_item_count' => 0, 'manifest_item_sha256' => '', 'manifest_binding_role' => '' );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $manifest ) ) return new WP_Error( 'mad4b_remote_media_manifest_identity_invalid', 'Manifest-correlated import requires an exact manifest SHA-256.' );
		if ( $count < 1 || $count > MAD4B_SCP_Remote_Media_Adapter::MAX_REMOTE_CANDIDATES ) return new WP_Error( 'mad4b_remote_media_manifest_item_count_invalid', 'Manifest-correlated import requires an exact bounded manifest item count.' );
		if ( $index < 0 || $index >= $count ) return new WP_Error( 'mad4b_remote_media_manifest_index_invalid', 'Manifest-correlated import requires an index inside the reviewed manifest item count.' );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $item ) ) return new WP_Error( 'mad4b_remote_media_manifest_item_identity_invalid', 'Manifest-correlated import requires an exact manifest item SHA-256.' );
		$role = self::normalize_role( $role_raw, $index );
		if ( is_wp_error( $role ) ) return $role;
		$current_item = self::item_sha256( $index, $role, $plan_sha256 );
		if ( ! hash_equals( $current_item, $item ) ) return new WP_Error( 'mad4b_remote_media_manifest_item_drift', 'Manifest item intent no longer matches the reviewed import plan, index and binding role.' );
		return array(
			'active' => true, 'manifest_sha256' => $manifest, 'manifest_index' => $index, 'manifest_item_count' => $count,
			'manifest_item_sha256' => $item, 'manifest_binding_role' => $role,
		);
	}
}
