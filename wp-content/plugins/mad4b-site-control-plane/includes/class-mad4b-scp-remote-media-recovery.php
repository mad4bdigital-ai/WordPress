<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Durable correlation for Media Library assets imported before content creation.
 *
 * Successful imports are never deleted merely because a later post operation
 * fails. Created assets remain staged and discoverable for deterministic retry;
 * reused assets are tracked without ownership claims. Cleanup is manual only.
 */
final class MAD4B_SCP_Remote_Media_Recovery {
	const CONTRACT = 'mad4b.remote-media-recovery.v1';
	const STAGE_CONTRACT = 'mad4b.remote-media-recovery-stage.v1';
	const BINDING_CONTRACT = 'mad4b.remote-media-recovery-binding.v1';
	const ATTACHMENT_MANIFEST_META = '_mad4b_remote_media_manifest_sha256';
	const ATTACHMENT_STAGE_META = '_mad4b_remote_media_recovery_stage';
	const POST_MANIFEST_META = '_mad4b_content_experience_media_manifest_sha256';
	const POST_BINDING_META = '_mad4b_content_experience_media_manifest_binding';
	const MAX_STAGE_EVENTS = 32;
	const MAX_OVERVIEW_ATTACHMENTS = 200;
	const MAX_OVERVIEW_MANIFESTS = 50;

	private static function sha( $value, $code ) {
		$value = strtolower( trim( (string) $value ) );
		return preg_match( '/^[a-f0-9]{64}$/', $value ) ? $value : new WP_Error( $code, 'Recovery identity must be an exact SHA-256 digest.' );
	}

	public static function stage_attachment( $attachment_id, array $context ) {
		$attachment_id = absint( $attachment_id );
		if ( $attachment_id < 1 || 'attachment' !== get_post_type( $attachment_id ) ) return new WP_Error( 'mad4b_remote_media_recovery_attachment_invalid', 'Recovery stage target is not an attachment.' );
		$manifest = self::sha( isset( $context['manifest_sha256'] ) ? $context['manifest_sha256'] : '', 'mad4b_remote_media_recovery_manifest_invalid' );
		if ( is_wp_error( $manifest ) ) return $manifest;
		$plan = self::sha( isset( $context['import_plan_sha256'] ) ? $context['import_plan_sha256'] : '', 'mad4b_remote_media_recovery_plan_invalid' );
		if ( is_wp_error( $plan ) ) return $plan;
		$index = isset( $context['manifest_index'] ) ? (int) $context['manifest_index'] : -1;
		if ( $index < 0 || $index >= MAD4B_SCP_Remote_Media_Adapter::MAX_REMOTE_CANDIDATES ) return new WP_Error( 'mad4b_remote_media_recovery_index_invalid', 'Recovery manifest index is outside the bounded media manifest.' );
		$provenance = isset( $context['provenance_event_sha256'] ) ? strtolower( trim( (string) $context['provenance_event_sha256'] ) ) : '';
		if ( '' !== $provenance && ! preg_match( '/^[a-f0-9]{64}$/', $provenance ) ) return new WP_Error( 'mad4b_remote_media_recovery_provenance_invalid', 'Recovery provenance event identity is invalid.' );

		$event = array(
			'contract' => self::STAGE_CONTRACT,
			'manifest_sha256' => $manifest,
			'manifest_index' => $index,
			'import_plan_sha256' => $plan,
			'provenance_event_sha256' => $provenance,
			'attachment_id' => $attachment_id,
			'created_for_manifest' => ! empty( $context['created_for_manifest'] ),
			'staged_at_gmt' => gmdate( 'Y-m-d H:i:s' ),
			'staged_by_user_id' => get_current_user_id(),
		);
		$identity = $event;
		unset( $identity['staged_at_gmt'], $identity['staged_by_user_id'] );
		$event['stage_event_sha256'] = hash( 'sha256', wp_json_encode( $identity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );

		$manifests = array_values( array_filter( array_map( 'strval', (array) get_post_meta( $attachment_id, self::ATTACHMENT_MANIFEST_META, false ) ) ) );
		$manifest_added = false;
		if ( ! in_array( $manifest, $manifests, true ) ) {
			$manifest_added = false !== add_post_meta( $attachment_id, self::ATTACHMENT_MANIFEST_META, $manifest, false );
			if ( ! $manifest_added ) return new WP_Error( 'mad4b_remote_media_recovery_manifest_stage_failed', 'Recovery manifest identity could not be persisted on the attachment.' );
		}
		$history = array_values( array_filter( (array) get_post_meta( $attachment_id, self::ATTACHMENT_STAGE_META, false ), static function ( $row ) {
			return is_array( $row ) && self::STAGE_CONTRACT === ( isset( $row['contract'] ) ? (string) $row['contract'] : '' );
		} ) );
		foreach ( $history as $existing ) {
			if ( isset( $existing['stage_event_sha256'] ) && hash_equals( (string) $existing['stage_event_sha256'], $event['stage_event_sha256'] ) ) return $existing;
		}
		if ( count( $history ) >= self::MAX_STAGE_EVENTS ) {
			if ( $manifest_added ) delete_post_meta( $attachment_id, self::ATTACHMENT_MANIFEST_META, $manifest );
			return new WP_Error( 'mad4b_remote_media_recovery_history_full', 'Attachment recovery stage history reached its bounded limit.' );
		}
		if ( false === add_post_meta( $attachment_id, self::ATTACHMENT_STAGE_META, $event, false ) ) {
			if ( $manifest_added ) delete_post_meta( $attachment_id, self::ATTACHMENT_MANIFEST_META, $manifest );
			return new WP_Error( 'mad4b_remote_media_recovery_stage_failed', 'Recovery stage event could not be persisted.' );
		}
		return $event;
	}

	public static function stage_import_result( array $result, $manifest_sha256, $manifest_index, $created_for_manifest ) {
		$manifest_sha256 = strtolower( trim( (string) $manifest_sha256 ) );
		if ( '' === $manifest_sha256 ) { $result['recovery_stage'] = null; return $result; }
		$event = isset( $result['provenance_event'] ) && is_array( $result['provenance_event'] ) ? $result['provenance_event'] : array();
		$stage = self::stage_attachment( isset( $result['attachment_id'] ) ? absint( $result['attachment_id'] ) : 0, array(
			'manifest_sha256' => $manifest_sha256, 'manifest_index' => (int) $manifest_index,
			'import_plan_sha256' => isset( $result['plan_sha256'] ) ? $result['plan_sha256'] : '',
			'provenance_event_sha256' => isset( $event['provenance_event_sha256'] ) ? $event['provenance_event_sha256'] : '',
			'created_for_manifest' => (bool) $created_for_manifest,
		) );
		if ( is_wp_error( $stage ) ) {
			$stage->add_data( array( 'attachment_id' => isset( $result['attachment_id'] ) ? absint( $result['attachment_id'] ) : 0, 'media_library_asset_preserved' => true, 'blind_retry_allowed' => false ) );
			return $stage;
		}
		$result['recovery_stage'] = $stage;
		return $result;
	}

	private static function rows_for_manifest( $manifest ) {
		$ids = get_posts( array(
			'post_type' => 'attachment', 'post_status' => 'any', 'posts_per_page' => self::MAX_OVERVIEW_ATTACHMENTS,
			'fields' => 'ids', 'meta_key' => self::ATTACHMENT_MANIFEST_META, 'meta_value' => $manifest,
			'no_found_rows' => true, 'suppress_filters' => true,
		) );
		$rows = array();
		foreach ( array_values( array_map( 'absint', (array) $ids ) ) as $id ) {
			if ( ! current_user_can( 'read_post', $id ) ) continue;
			foreach ( (array) get_post_meta( $id, self::ATTACHMENT_STAGE_META, false ) as $event ) {
				if ( ! is_array( $event ) || self::STAGE_CONTRACT !== ( isset( $event['contract'] ) ? (string) $event['contract'] : '' ) ) continue;
				if ( ! isset( $event['manifest_sha256'] ) || ! hash_equals( $manifest, (string) $event['manifest_sha256'] ) ) continue;
				$rows[] = $event;
			}
		}
		usort( $rows, static function ( $a, $b ) {
			$ai = isset( $a['manifest_index'] ) ? (int) $a['manifest_index'] : PHP_INT_MAX;
			$bi = isset( $b['manifest_index'] ) ? (int) $b['manifest_index'] : PHP_INT_MAX;
			if ( $ai === $bi ) return (int) ( $a['attachment_id'] ?? 0 ) <=> (int) ( $b['attachment_id'] ?? 0 );
			return $ai <=> $bi;
		} );
		return $rows;
	}

	public static function manifest_receipt( $manifest_sha256, $item_count ) {
		$manifest = self::sha( $manifest_sha256, 'mad4b_remote_media_recovery_manifest_invalid' );
		if ( is_wp_error( $manifest ) ) return $manifest;
		$item_count = (int) $item_count;
		if ( $item_count < 1 || $item_count > MAD4B_SCP_Remote_Media_Adapter::MAX_REMOTE_CANDIDATES ) return new WP_Error( 'mad4b_remote_media_recovery_item_count_invalid', 'Recovery manifest item count is invalid.' );
		$rows = self::rows_for_manifest( $manifest );
		$canonical = array();
		foreach ( $rows as $row ) {
			$index = isset( $row['manifest_index'] ) ? (int) $row['manifest_index'] : -1;
			if ( $index < 0 || $index >= $item_count ) continue;
			$projection = array(
				'manifest_index' => $index,
				'attachment_id' => absint( $row['attachment_id'] ?? 0 ),
				'import_plan_sha256' => strtolower( (string) ( $row['import_plan_sha256'] ?? '' ) ),
				'provenance_event_sha256' => strtolower( (string) ( $row['provenance_event_sha256'] ?? '' ) ),
				'created_for_manifest' => ! empty( $row['created_for_manifest'] ),
			);
			if ( isset( $canonical[ $index ] ) && $canonical[ $index ] !== $projection ) return new WP_Error( 'mad4b_remote_media_recovery_index_conflict', 'Manifest index resolves to conflicting staged attachment identities.', array( 'manifest_index' => $index ) );
			$canonical[ $index ] = $projection;
		}
		ksort( $canonical, SORT_NUMERIC );
		for ( $i = 0; $i < $item_count; ++$i ) if ( ! isset( $canonical[ $i ] ) ) return new WP_Error( 'mad4b_remote_media_recovery_manifest_incomplete', 'Remote media manifest has not completed every staged import.', array( 'missing_manifest_index' => $i ) );
		$canonical = array_values( $canonical );
		$identity = array( 'contract' => self::CONTRACT, 'manifest_sha256' => $manifest, 'item_count' => $item_count, 'items' => $canonical );
		return array_merge( $identity, array(
			'recovery_receipt_sha256' => hash( 'sha256', wp_json_encode( $identity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ),
			'complete' => true, 'mutation_performed' => false,
		) );
	}

	public static function bind_post( $manifest_sha256, $post_id, $binding_state_sha256, $recovery_receipt_sha256, array $attachment_ids ) {
		$manifest = self::sha( $manifest_sha256, 'mad4b_remote_media_recovery_manifest_invalid' );
		if ( is_wp_error( $manifest ) ) return $manifest;
		$binding = self::sha( $binding_state_sha256, 'mad4b_remote_media_recovery_binding_invalid' );
		if ( is_wp_error( $binding ) ) return $binding;
		$receipt = self::sha( $recovery_receipt_sha256, 'mad4b_remote_media_recovery_receipt_invalid' );
		if ( is_wp_error( $receipt ) ) return $receipt;
		$post_id = absint( $post_id );
		if ( $post_id < 1 || ! get_post( $post_id ) ) return new WP_Error( 'mad4b_remote_media_recovery_post_invalid', 'Recovery binding target post is missing.' );

		$bound_ids = get_posts( array(
			'post_type' => 'any', 'post_status' => 'any', 'posts_per_page' => 2, 'fields' => 'ids',
			'meta_key' => self::POST_MANIFEST_META, 'meta_value' => $manifest, 'no_found_rows' => true, 'suppress_filters' => true,
		) );
		$bound_ids = array_values( array_unique( array_map( 'absint', (array) $bound_ids ) ) );
		if ( $bound_ids && ! in_array( $post_id, $bound_ids, true ) ) return new WP_Error( 'mad4b_remote_media_recovery_manifest_already_bound', 'Remote media manifest is already bound to another content object.', array( 'post_ids' => $bound_ids ) );

		$attachment_ids = array_values( array_unique( array_filter( array_map( 'absint', $attachment_ids ) ) ) );
		sort( $attachment_ids, SORT_NUMERIC );
		$event = array(
			'contract' => self::BINDING_CONTRACT, 'manifest_sha256' => $manifest,
			'binding_state_sha256' => $binding, 'recovery_receipt_sha256' => $receipt,
			'attachment_ids' => $attachment_ids, 'bound_at_gmt' => gmdate( 'Y-m-d H:i:s' ),
			'bound_by_user_id' => get_current_user_id(),
		);
		$identity = $event; unset( $identity['bound_at_gmt'], $identity['bound_by_user_id'] );
		$event['binding_event_sha256'] = hash( 'sha256', wp_json_encode( $identity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );

		$current_manifest = (string) get_post_meta( $post_id, self::POST_MANIFEST_META, true );
		$current_binding = get_post_meta( $post_id, self::POST_BINDING_META, true );
		if ( $manifest === $current_manifest && is_array( $current_binding )
			&& isset( $current_binding['binding_event_sha256'] )
			&& hash_equals( (string) $current_binding['binding_event_sha256'], $event['binding_event_sha256'] ) ) return $current_binding;
		if ( '' !== $current_manifest && ! hash_equals( $manifest, $current_manifest ) ) return new WP_Error( 'mad4b_remote_media_recovery_post_manifest_conflict', 'Post is already bound to a different remote media manifest.' );

		if ( false === update_post_meta( $post_id, self::POST_MANIFEST_META, $manifest ) && $manifest !== (string) get_post_meta( $post_id, self::POST_MANIFEST_META, true ) ) {
			return new WP_Error( 'mad4b_remote_media_recovery_post_manifest_failed', 'Post recovery manifest identity could not be persisted.' );
		}
		if ( false === update_post_meta( $post_id, self::POST_BINDING_META, $event ) && get_post_meta( $post_id, self::POST_BINDING_META, true ) !== $event ) {
			if ( '' === $current_manifest ) delete_post_meta( $post_id, self::POST_MANIFEST_META );
			return new WP_Error( 'mad4b_remote_media_recovery_post_binding_failed', 'Post recovery binding evidence could not be persisted.' );
		}
		return $event;
	}

	public static function verify_post_binding( $manifest_sha256, $post_id, $binding_state_sha256, $recovery_receipt_sha256 ) {
		$manifest = self::sha( $manifest_sha256, 'mad4b_remote_media_recovery_manifest_invalid' );
		$binding = self::sha( $binding_state_sha256, 'mad4b_remote_media_recovery_binding_invalid' );
		$receipt = self::sha( $recovery_receipt_sha256, 'mad4b_remote_media_recovery_receipt_invalid' );
		if ( is_wp_error( $manifest ) || is_wp_error( $binding ) || is_wp_error( $receipt ) ) return is_wp_error( $manifest ) ? $manifest : ( is_wp_error( $binding ) ? $binding : $receipt );
		if ( ! hash_equals( $manifest, (string) get_post_meta( absint( $post_id ), self::POST_MANIFEST_META, true ) ) ) return new WP_Error( 'mad4b_remote_media_recovery_post_manifest_drift', 'Post no longer carries the exact reviewed media manifest identity.' );
		$event = get_post_meta( absint( $post_id ), self::POST_BINDING_META, true );
		if ( is_array( $event ) && self::BINDING_CONTRACT === ( isset( $event['contract'] ) ? (string) $event['contract'] : '' )
			&& hash_equals( $manifest, (string) ( $event['manifest_sha256'] ?? '' ) )
			&& hash_equals( $binding, (string) ( $event['binding_state_sha256'] ?? '' ) )
			&& hash_equals( $receipt, (string) ( $event['recovery_receipt_sha256'] ?? '' ) ) ) return true;
		return new WP_Error( 'mad4b_remote_media_recovery_post_binding_drift', 'Post no longer carries the exact reviewed media manifest binding evidence.' );
	}

	public static function status( $manifest_sha256 = '' ) {
		$manifest_sha256 = strtolower( trim( (string) $manifest_sha256 ) );
		if ( '' === $manifest_sha256 ) return self::overview();
		$manifest = self::sha( $manifest_sha256, 'mad4b_remote_media_recovery_manifest_invalid' );
		if ( is_wp_error( $manifest ) ) return $manifest;
		$rows = self::rows_for_manifest( $manifest );
		$attachments = array_values( array_unique( array_map( static function ( $row ) { return absint( $row['attachment_id'] ?? 0 ); }, $rows ) ) );
		$created = array_values( array_unique( array_map( static function ( $row ) { return ! empty( $row['created_for_manifest'] ) ? absint( $row['attachment_id'] ?? 0 ) : 0; }, $rows ) ) );
		$created = array_values( array_filter( $created ) ); sort( $created, SORT_NUMERIC ); sort( $attachments, SORT_NUMERIC );
		$posts = get_posts( array(
			'post_type' => 'any', 'post_status' => 'any', 'posts_per_page' => 2, 'fields' => 'ids',
			'meta_key' => self::POST_MANIFEST_META, 'meta_value' => $manifest, 'no_found_rows' => true, 'suppress_filters' => true,
		) );
		$posts = array_values( array_unique( array_map( 'absint', (array) $posts ) ) );
		$state = $posts ? 'bound' : ( $rows ? 'staged_unbound' : 'unknown' );
		return array(
			'contract' => self::CONTRACT, 'manifest_sha256' => $manifest, 'state' => $state,
			'attachment_ids' => $attachments, 'created_for_manifest_attachment_ids' => $created,
			'bound_post_ids' => $posts, 'recoverable' => 'staged_unbound' === $state,
			'auto_delete' => false, 'cleanup_policy' => 'manual_only_after_reference_review',
			'next_action' => 'staged_unbound' === $state ? 'rerun_media_binding_then_content_plan' : ( 'bound' === $state ? 'none' : 'inspect_manifest_identity' ),
			'mutation_performed' => false,
		);
	}

	private static function overview() {
		$ids = get_posts( array(
			'post_type' => 'attachment', 'post_status' => 'any', 'posts_per_page' => self::MAX_OVERVIEW_ATTACHMENTS,
			'fields' => 'ids', 'meta_query' => array( array( 'key' => self::ATTACHMENT_MANIFEST_META, 'compare' => 'EXISTS' ) ),
			'no_found_rows' => true, 'suppress_filters' => true,
		) );
		$manifests = array();
		foreach ( (array) $ids as $id ) {
			$id = absint( $id );
			if ( ! current_user_can( 'read_post', $id ) ) continue;
			foreach ( (array) get_post_meta( $id, self::ATTACHMENT_MANIFEST_META, false ) as $manifest ) {
			$manifest = strtolower( trim( (string) $manifest ) );
			if ( preg_match( '/^[a-f0-9]{64}$/', $manifest ) ) $manifests[ $manifest ] = true;
				if ( count( $manifests ) >= self::MAX_OVERVIEW_MANIFESTS ) break 2;
			}
		}
		$unbound = array(); $created_unbound = 0;
		foreach ( array_keys( $manifests ) as $manifest ) {
			$status = self::status( $manifest );
			if ( is_wp_error( $status ) || 'staged_unbound' !== $status['state'] ) continue;
			$unbound[] = array(
				'manifest_sha256' => $manifest,
				'attachment_count' => count( $status['attachment_ids'] ),
				'created_for_manifest_count' => count( $status['created_for_manifest_attachment_ids'] ),
				'next_action' => $status['next_action'],
			);
			$created_unbound += count( $status['created_for_manifest_attachment_ids'] );
		}
		return array(
			'contract' => self::CONTRACT, 'state' => 'overview', 'unbound_manifests' => $unbound,
			'unbound_manifest_count' => count( $unbound ), 'created_unbound_attachment_count' => $created_unbound,
			'auto_delete' => false, 'cleanup_policy' => 'manual_only_after_reference_review',
			'scan_attachment_limit' => self::MAX_OVERVIEW_ATTACHMENTS, 'manifest_limit' => self::MAX_OVERVIEW_MANIFESTS,
			'truncated' => count( (array) $ids ) >= self::MAX_OVERVIEW_ATTACHMENTS || count( $manifests ) >= self::MAX_OVERVIEW_MANIFESTS,
			'mutation_performed' => false,
		);
	}
}
