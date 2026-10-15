<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Exact-scope read-only projection of the existing Context asset registry. */
trait MAD4B_SCP_Context_Asset_Read_Model {
	public function list_assets( $input ) {
		$input = is_array( $input ) ? $input : array();
		$mode = sanitize_key( isset( $input['mode'] ) ? (string) $input['mode'] : '' );
		$task_scope = isset( $input['task_scope'] ) ? substr( sanitize_text_field( (string) $input['task_scope'] ), 0, 160 ) : '';
		$status = sanitize_key( isset( $input['status'] ) ? (string) $input['status'] : '' );
		$category = sanitize_key( isset( $input['category'] ) ? (string) $input['category'] : '' );
		$limit = isset( $input['limit'] ) ? max( 1, min( 200, absint( $input['limit'] ) ) ) : 100;
		if ( 'task_attachment' === $mode && '' === $task_scope ) {
			return new WP_Error(
				'mad4b_context_task_scope_required',
				'Task-scoped Context asset metadata requires the exact task_scope.'
			);
		}
		$items = array();
		foreach ( MAD4B_SCP_Context_Authority::assets() as $asset ) {
			if ( ! is_array( $asset ) ) continue;
			$asset_mode = isset( $asset['source_mode'] ) ? (string) $asset['source_mode'] : '';
			if ( 'task_attachment' === $asset_mode ) {
				$asset_scope = isset( $asset['task_scope'] ) ? (string) $asset['task_scope'] : '';
				if ( '' === $task_scope || '' === $asset_scope || ! hash_equals( $asset_scope, $task_scope ) ) continue;
			}
			if ( '' !== $mode && $mode !== $asset_mode ) continue;
			if ( '' !== $status && $status !== ( isset( $asset['status'] ) ? (string) $asset['status'] : '' ) ) continue;
			if ( '' !== $category && $category !== ( isset( $asset['category'] ) ? (string) $asset['category'] : '' ) ) continue;
			$current_content_hash = isset( $asset['content_hash'] ) ? strtolower( trim( (string) $asset['content_hash'] ) ) : '';
			$reviewed_content_hash = isset( $asset['reviewed_content_hash'] ) ? strtolower( trim( (string) $asset['reviewed_content_hash'] ) ) : '';
			$review_binding_exact = 'approved' === ( isset( $asset['review_status'] ) ? (string) $asset['review_status'] : '' ) && preg_match( '/^[a-f0-9]{64}$/', $current_content_hash ) && preg_match( '/^[a-f0-9]{64}$/', $reviewed_content_hash ) && hash_equals( $current_content_hash, $reviewed_content_hash );
			$items[] = array(
				'asset_id' => isset( $asset['asset_id'] ) ? (string) $asset['asset_id'] : '',
				'source_id' => isset( $asset['source_id'] ) ? (string) $asset['source_id'] : '',
				'source_mode' => $asset_mode,
				'source_write_policy' => class_exists( 'MAD4B_SCP_Context_Authority' ) && ! empty( $asset['source_id'] ) ? MAD4B_SCP_Context_Authority::source_write_policy( (string) $asset['source_id'] ) : 'read_only',
				'title' => isset( $asset['title'] ) ? (string) $asset['title'] : '',
				'category' => isset( $asset['category'] ) ? (string) $asset['category'] : '',
				'authority_class' => isset( $asset['authority_class'] ) ? (string) $asset['authority_class'] : '',
				'required' => ! empty( $asset['required'] ),
				'quality_score' => isset( $asset['quality_score'] ) ? (int) $asset['quality_score'] : null,
				'quality_mode' => isset( $asset['quality']['mode'] ) ? (string) $asset['quality']['mode'] : '',
				'quality_confidence' => isset( $asset['quality']['confidence'] ) ? (float) $asset['quality']['confidence'] : null,
				'quality_provisional' => ! empty( $asset['quality']['provisional'] ),
				'status' => isset( $asset['status'] ) ? (string) $asset['status'] : '',
				'review_status' => isset( $asset['review_status'] ) ? (string) $asset['review_status'] : 'unreviewed',
				'review_decision' => isset( $asset['review_decision'] ) ? (string) $asset['review_decision'] : '',
				'review_note' => isset( $asset['review_note'] ) ? (string) $asset['review_note'] : '',
				'review_actor_type' => isset( $asset['review_actor_type'] ) ? (string) $asset['review_actor_type'] : '',
				'review_agent_public_id' => isset( $asset['review_agent_public_id'] ) ? (string) $asset['review_agent_public_id'] : '',
				'reviewed_at' => isset( $asset['reviewed_at'] ) ? (string) $asset['reviewed_at'] : '',
				'reviewed_content_hash' => isset( $asset['reviewed_content_hash'] ) ? (string) $asset['reviewed_content_hash'] : '',
				'review_binding_exact' => $review_binding_exact,
				'content_complete' => ! array_key_exists( 'content_complete', $asset ) || ! empty( $asset['content_complete'] ),
				'content_available' => ! empty( $asset['content_available'] ),
				'content_excerpt' => isset( $asset['content_excerpt'] ) ? (string) $asset['content_excerpt'] : '',
				'normalization_status' => isset( $asset['normalization_status'] ) ? (string) $asset['normalization_status'] : '',
				'classification_source' => isset( $asset['classification_source'] ) ? (string) $asset['classification_source'] : '',
				'classification_confidence' => isset( $asset['classification_confidence'] ) ? (float) $asset['classification_confidence'] : 0.0,
				'automatic_classification' => isset( $asset['automatic_classification'] ) && is_array( $asset['automatic_classification'] ) ? $asset['automatic_classification'] : array(),
				'availability_reason' => isset( $asset['availability_reason'] ) ? (string) $asset['availability_reason'] : '',
				'content_hash' => isset( $asset['content_hash'] ) ? (string) $asset['content_hash'] : '',
				'file_id' => isset( $asset['file_id'] ) ? (string) $asset['file_id'] : '',
				'parent_folder_id' => isset( $asset['parent_folder_id'] ) ? (string) $asset['parent_folder_id'] : '',
				'mime_type' => isset( $asset['mime_type'] ) ? (string) $asset['mime_type'] : '',
				'last_synced_at' => isset( $asset['last_synced_at'] ) ? (string) $asset['last_synced_at'] : '',
				'write_capabilities' => MAD4B_SCP_Google_Drive_Context::asset_write_capabilities( isset( $asset['asset_id'] ) ? (string) $asset['asset_id'] : '' ),
			);
			if ( count( $items ) >= $limit ) break;
		}
		$status = method_exists( 'MAD4B_SCP_Context_Authority', 'status' ) ? MAD4B_SCP_Context_Authority::status() : array();
		return array(
			'contract' => 'mad4b.context-asset-list.v4',
			'items' => $items,
			'count' => count( $items ),
			'task_scope_bound' => '' !== $task_scope,
			'registry_revision' => isset( $status['registry_revision'] ) ? (int) $status['registry_revision'] : MAD4B_SCP_Context_Authority::registry_revision(),
			'context_fingerprint' => isset( $status['context_fingerprint'] ) ? (string) $status['context_fingerprint'] : MAD4B_SCP_Context_Authority::context_fingerprint(),
			'authority_manifest_fingerprint' => isset( $status['authority_manifest_fingerprint'] ) ? (string) $status['authority_manifest_fingerprint'] : MAD4B_SCP_Context_Authority::authority_manifest_fingerprint(),
		);
	}

}
