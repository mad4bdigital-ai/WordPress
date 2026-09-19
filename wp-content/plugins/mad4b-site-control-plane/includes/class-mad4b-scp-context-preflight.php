<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Resolves the exact governed context required by one Skill execution.
 *
 * This service is read-only. It never repairs, reclassifies, rescans or writes
 * provider/context state while answering readiness.
 */
final class MAD4B_SCP_Context_Preflight {
	const POLICY_CONTRACT = 'mad4b.skill-context-policy.v1';
	const PREFLIGHT_CONTRACT = 'mad4b.context-preflight.v1';
	const ENVELOPE_CONTRACT = 'mad4b.context-envelope.v1';
	const RECEIPT_CONTRACT = 'mad4b.content-context-receipt.v1';

	const MAX_REQUIRED_SETS = 12;
	const MAX_OPTIONAL_SETS = 16;
	const MAX_ASSETS_PER_SET = 3;
	const MAX_CONTEXT_ASSETS = 24;
	const MAX_CONTEXT_BYTES = 786432; // 768 KiB exact-provider text across one preflight.
	const MAX_RECEIPT_AGE = 1800; // 30 minutes; approval is still one-time and separately short-lived.

	public static function presets() {
		return array(
			'none' => array(
				'label' => 'No governed context',
				'required_context_sets' => array(),
				'optional_context_sets' => array(),
				'allow_task_context' => false,
			),
			'brand_core' => array(
				'label' => 'Brand Core required',
				'required_context_sets' => array( 'brand_strategy', 'tone_of_voice', 'editorial_guidelines' ),
				'optional_context_sets' => array( 'terminology', 'claim_policy', 'seo_strategy', 'writer_reference' ),
				'allow_task_context' => true,
			),
			'custom' => array(
				'label' => 'Custom',
				'required_context_sets' => array(),
				'optional_context_sets' => array(),
				'allow_task_context' => true,
			),
		);
	}

	public static function default_policy() {
		return array(
			'contract' => self::POLICY_CONTRACT,
			'preset' => 'none',
			'brand_context_required' => false,
			'required_context_sets' => array(),
			'optional_context_sets' => array(),
			'allow_task_context' => false,
		);
	}

	public static function normalize_policy( $input ) {
		if ( null === $input || false === $input || '' === $input ) return self::default_policy();
		if ( ! is_array( $input ) ) return new WP_Error( 'mad4b_skill_context_policy_invalid', 'Skill Context policy must be an object.' );

		$preset = isset( $input['preset'] ) ? sanitize_key( (string) $input['preset'] ) : '';
		if ( '' === $preset ) {
			$preset = ! empty( $input['brand_context_required'] ) || ! empty( $input['required_context_sets'] ) || ! empty( $input['optional_context_sets'] ) ? 'custom' : 'none';
		}
		$presets = self::presets();
		if ( ! isset( $presets[ $preset ] ) ) return new WP_Error( 'mad4b_skill_context_preset_invalid', 'Skill Context preset is not supported.' );

		if ( 'none' === $preset ) return self::default_policy();

		$required = 'brand_core' === $preset
			? $presets['brand_core']['required_context_sets']
			: self::normalize_sets( isset( $input['required_context_sets'] ) ? $input['required_context_sets'] : array(), self::MAX_REQUIRED_SETS );
		if ( is_wp_error( $required ) ) return $required;

		$optional = 'brand_core' === $preset
			? $presets['brand_core']['optional_context_sets']
			: self::normalize_sets( isset( $input['optional_context_sets'] ) ? $input['optional_context_sets'] : array(), self::MAX_OPTIONAL_SETS );
		if ( is_wp_error( $optional ) ) return $optional;
		$optional = array_values( array_diff( $optional, $required ) );

		if ( empty( $required ) ) return new WP_Error( 'mad4b_skill_context_required_sets_empty', 'A Context-required Skill must declare at least one required context set.' );

		return array(
			'contract' => self::POLICY_CONTRACT,
			'preset' => $preset,
			'brand_context_required' => true,
			'required_context_sets' => $required,
			'optional_context_sets' => $optional,
			'allow_task_context' => 'brand_core' === $preset ? true : ! empty( $input['allow_task_context'] ),
		);
	}

	public static function policy_digest( $policy ) {
		$policy = self::normalize_policy( $policy );
		if ( is_wp_error( $policy ) || empty( $policy['brand_context_required'] ) ) return '';
		$json = wp_json_encode( self::canonical_policy( $policy ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return is_string( $json ) && '' !== $json ? hash( 'sha256', $json ) : '';
	}

	public static function preflight_skill( $level, $target, $name, $task_scope = '' ) {
		if ( ! class_exists( 'MAD4B_SCP_Skill_Registry' ) ) return new WP_Error( 'mad4b_skill_registry_unavailable', 'Skill registry is unavailable.' );
		$skill = MAD4B_SCP_Skill_Registry::get_skill( $level, $target, $name );
		if ( is_wp_error( $skill ) ) return $skill;
		return self::preflight_entry( $skill, $task_scope );
	}

	public static function preflight_entry( array $skill, $task_scope = '' ) {
		$policy = self::normalize_policy( isset( $skill['context_policy'] ) ? $skill['context_policy'] : array() );
		if ( is_wp_error( $policy ) ) return $policy;
		$policy_digest = self::policy_digest( $policy );
		$logical_id = isset( $skill['logical_id'] ) ? (string) $skill['logical_id'] : '';
		$skill_sha = isset( $skill['sha256'] ) ? strtolower( trim( (string) $skill['sha256'] ) ) : '';
		$task_scope = substr( sanitize_text_field( (string) $task_scope ), 0, 160 );
		$observed_at = gmdate( 'c' );

		if ( empty( $policy['brand_context_required'] ) ) {
			$receipt = self::receipt(
				$logical_id,
				$skill_sha,
				$policy,
				$policy_digest,
				array(),
				array(),
				array(),
				'',
				0,
				'',
				$observed_at,
				$task_scope
			);
			return array(
				'contract' => self::PREFLIGHT_CONTRACT,
				'ready' => true,
				'state' => 'not_required',
				'skill_logical_id' => $logical_id,
				'skill_sha256' => $skill_sha,
				'policy' => $policy,
				'policy_sha256' => '',
				'blockers' => array(),
				'warnings' => array(),
				'envelope' => array(
					'contract' => self::ENVELOPE_CONTRACT,
					'required' => false,
					'context_fingerprint' => '',
					'authority_manifest_fingerprint' => '',
					'registry_revision' => 0,
					'assets' => array(),
					'total_bytes' => 0,
				),
				'receipt' => $receipt,
			);
		}

		if ( ! class_exists( 'MAD4B_SCP_Context_Authority' ) || ! class_exists( 'MAD4B_SCP_Google_Drive_Context' ) ) {
			return self::blocked( $skill, $policy, $policy_digest, array( 'context_authority_unavailable' ), $task_scope, $observed_at );
		}

		$authority = MAD4B_SCP_Context_Authority::status();
		$profile = MAD4B_SCP_Context_Authority::profile();
		$assets = MAD4B_SCP_Context_Authority::assets();
		$registry_revision = isset( $authority['registry_revision'] ) ? (int) $authority['registry_revision'] : MAD4B_SCP_Context_Authority::registry_revision();
		$authority_manifest_fingerprint = isset( $authority['authority_manifest_fingerprint'] ) ? (string) $authority['authority_manifest_fingerprint'] : MAD4B_SCP_Context_Authority::authority_manifest_fingerprint( $assets );
		$blockers = array();
		$warnings = array();

		if ( empty( $profile ) ) $blockers[] = 'brand_context_profile_unconfigured';
		if ( empty( $authority['site_uuid'] ) ) $blockers[] = 'context_site_binding_unavailable';
		if ( ! empty( $authority['partial_source_count'] ) ) $blockers[] = 'governed_context_source_scan_incomplete';
		if ( ! empty( $authority['required_stale_asset_count'] ) ) $blockers[] = 'mandatory_context_contains_stale_assets';
		if ( ! empty( $authority['required_unavailable_asset_count'] ) ) $blockers[] = 'mandatory_context_contains_unavailable_assets';
		if ( ! empty( $authority['required_incomplete_asset_count'] ) ) $blockers[] = 'mandatory_context_contains_incomplete_assets';
		if ( ! empty( $authority['required_conflicting_asset_count'] ) ) $blockers[] = 'mandatory_context_conflict';
		foreach ( isset( $authority['warnings'] ) && is_array( $authority['warnings'] ) ? $authority['warnings'] : array() as $authority_warning ) {
			$authority_warning = sanitize_key( (string) $authority_warning );
			if ( '' !== $authority_warning ) $warnings[] = $authority_warning;
		}

		$site_required_sets = array();
		foreach ( $assets as $asset ) {
			if ( ! is_array( $asset ) || 'governed' !== ( isset( $asset['source_mode'] ) ? (string) $asset['source_mode'] : '' ) || empty( $asset['required'] ) ) continue;
			$category = isset( $asset['category'] ) ? sanitize_key( (string) $asset['category'] ) : '';
			if ( '' !== $category && 'uncategorized' !== $category ) $site_required_sets[] = $category;
		}
		$site_required_sets = array_values( array_unique( $site_required_sets ) );
		sort( $site_required_sets, SORT_STRING );
		$effective_required_sets = array_values( array_unique( array_merge( $policy['required_context_sets'], $site_required_sets ) ) );
		sort( $effective_required_sets, SORT_STRING );
		if ( empty( $effective_required_sets ) ) $blockers[] = 'required_context_sets_empty';

		$effective_optional_sets = array_values( array_diff( $policy['optional_context_sets'], $effective_required_sets ) );
		sort( $effective_optional_sets, SORT_STRING );

		$selected = array();
		$missing_sets = array();
		foreach ( $effective_required_sets as $category ) {
			$candidates = self::category_candidates( $assets, $category, true, '' );
			if ( empty( $candidates ) ) {
				$missing_sets[] = $category;
				continue;
			}
			foreach ( array_slice( $candidates, 0, self::MAX_ASSETS_PER_SET ) as $asset ) $selected[ (string) $asset['asset_id'] ] = $asset;
		}
		if ( $missing_sets ) $blockers[] = 'required_context_sets_missing';

		foreach ( $effective_optional_sets as $category ) {
			$candidates = self::category_candidates( $assets, $category, true, '' );
			foreach ( array_slice( $candidates, 0, self::MAX_ASSETS_PER_SET ) as $asset ) {
				if ( count( $selected ) >= self::MAX_CONTEXT_ASSETS ) break 2;
				$selected[ (string) $asset['asset_id'] ] = $asset;
			}
			if ( ! empty( $policy['allow_task_context'] ) && '' !== $task_scope ) {
				$task_candidates = self::category_candidates( $assets, $category, false, $task_scope );
				foreach ( array_slice( $task_candidates, 0, self::MAX_ASSETS_PER_SET ) as $asset ) {
					if ( count( $selected ) >= self::MAX_CONTEXT_ASSETS ) break 2;
					$selected[ (string) $asset['asset_id'] ] = $asset;
				}
			}
		}

		$envelope_assets = array();
		$receipt_assets = array();
		$total_bytes = 0;
		$required_ids = array();
		foreach ( $effective_required_sets as $category ) {
			foreach ( self::category_candidates( $assets, $category, true, '' ) as $candidate ) $required_ids[ (string) $candidate['asset_id'] ] = true;
		}

		foreach ( $selected as $asset_id => $asset ) {
			$required_asset = isset( $required_ids[ $asset_id ] );
			$content = MAD4B_SCP_Google_Drive_Context::read_context_asset( $asset_id );
			if ( is_wp_error( $content ) ) {
				if ( $required_asset ) $blockers[] = 'required_context_asset_unreadable';
				else $warnings[] = 'optional_context_asset_unreadable:' . $asset_id;
				continue;
			}
			if ( empty( $content['content_complete'] ) ) {
				if ( $required_asset ) $blockers[] = 'required_context_asset_incomplete';
				else $warnings[] = 'optional_context_asset_incomplete:' . $asset_id;
				continue;
			}
			$bytes = isset( $content['bytes'] ) ? (int) $content['bytes'] : strlen( isset( $content['content'] ) ? (string) $content['content'] : '' );
			if ( $total_bytes + $bytes > self::MAX_CONTEXT_BYTES ) {
				if ( $required_asset ) $blockers[] = 'required_context_envelope_budget_exceeded';
				else $warnings[] = 'optional_context_asset_budget_omitted:' . $asset_id;
				continue;
			}
			$total_bytes += $bytes;
			$summary = array(
				'asset_id' => $asset_id,
				'source_id' => isset( $asset['source_id'] ) ? (string) $asset['source_id'] : '',
				'source_mode' => isset( $asset['source_mode'] ) ? (string) $asset['source_mode'] : '',
				'category' => isset( $asset['category'] ) ? (string) $asset['category'] : '',
				'authority_class' => isset( $asset['authority_class'] ) ? (string) $asset['authority_class'] : '',
				'title' => isset( $asset['title'] ) ? (string) $asset['title'] : '',
				'quality_score' => isset( $asset['quality_score'] ) ? (int) $asset['quality_score'] : null,
				'review_status' => isset( $asset['review_status'] ) ? (string) $asset['review_status'] : '',
				'content_sha256' => isset( $content['content_sha256'] ) ? (string) $content['content_sha256'] : '',
				'bytes' => $bytes,
				'content_complete' => true,
				'required_for_skill' => $required_asset,
			);
			$receipt_assets[] = $summary;
			$envelope_assets[] = $summary + array( 'content' => isset( $content['content'] ) ? (string) $content['content'] : '' );
		}

		foreach ( $effective_required_sets as $category ) {
			$loaded = false;
			foreach ( $receipt_assets as $asset ) {
				if ( ! empty( $asset['required_for_skill'] ) && $category === $asset['category'] ) { $loaded = true; break; }
			}
			if ( ! $loaded ) $blockers[] = 'required_context_set_not_loaded:' . $category;
		}

		$registry_revision_after = MAD4B_SCP_Context_Authority::registry_revision();
		if ( $registry_revision_after !== $registry_revision ) $blockers[] = 'context_registry_changed_during_preflight';
		$current_authority_fingerprint = MAD4B_SCP_Context_Authority::authority_manifest_fingerprint();
		if ( '' === $authority_manifest_fingerprint || ! hash_equals( $authority_manifest_fingerprint, $current_authority_fingerprint ) ) $blockers[] = 'context_authority_changed_during_preflight';

		$blockers = array_values( array_unique( $blockers ) );
		$warnings = array_values( array_unique( $warnings ) );
		$fingerprint = isset( $authority['context_fingerprint'] ) ? (string) $authority['context_fingerprint'] : '';
		$site_uuid = isset( $authority['site_uuid'] ) ? (string) $authority['site_uuid'] : '';
		$revision = isset( $authority['profile_revision'] ) ? (int) $authority['profile_revision'] : 0;
		$brand_id = isset( $authority['brand_id'] ) ? (string) $authority['brand_id'] : '';
		$ready = empty( $blockers );

		$effective_policy = $policy;
		$effective_policy['site_required_context_sets'] = $site_required_sets;
		$effective_policy['effective_required_context_sets'] = $effective_required_sets;
		$effective_policy['effective_optional_context_sets'] = $effective_optional_sets;

		$receipt = self::receipt(
			$logical_id,
			$skill_sha,
			$effective_policy,
			$policy_digest,
			$receipt_assets,
			$missing_sets,
			$blockers,
			$site_uuid,
			$revision,
			$fingerprint,
			$observed_at,
			$task_scope,
			$brand_id,
			$authority_manifest_fingerprint,
			$registry_revision
		);
		return array(
			'contract' => self::PREFLIGHT_CONTRACT,
			'ready' => $ready,
			'state' => $ready ? 'ready' : 'blocked',
			'skill_logical_id' => $logical_id,
			'skill_sha256' => $skill_sha,
			'policy' => $policy,
			'effective_policy' => $effective_policy,
			'policy_sha256' => $policy_digest,
			'blockers' => $blockers,
			'warnings' => $warnings,
			'missing_context_sets' => $missing_sets,
			'envelope' => array(
				'contract' => self::ENVELOPE_CONTRACT,
				'required' => true,
				'site_uuid' => $site_uuid,
				'brand_id' => $brand_id,
				'brand_context_revision' => $revision,
				'registry_revision' => $registry_revision,
				'context_fingerprint' => $fingerprint,
				'authority_manifest_fingerprint' => $authority_manifest_fingerprint,
				'task_scope' => $task_scope,
				'precedence' => array( 'site_policy', 'brand_core', 'editorial_strategy', 'campaign_context', 'task_knowledge', 'writer_reference', 'general_model_knowledge' ),
				'effective_required_context_sets' => $effective_required_sets,
				'assets' => $envelope_assets,
				'total_bytes' => $total_bytes,
			),
			'receipt' => $receipt,
		);
	}

	private static function category_candidates( array $assets, $category, $governed, $task_scope ) {
		$out = array();
		foreach ( $assets as $asset ) {
			if ( ! is_array( $asset ) || $category !== ( isset( $asset['category'] ) ? (string) $asset['category'] : '' ) ) continue;
			if ( 'ready' !== ( isset( $asset['status'] ) ? (string) $asset['status'] : '' ) ) continue;
			if ( array_key_exists( 'content_complete', $asset ) && empty( $asset['content_complete'] ) ) continue;
			$mode = isset( $asset['source_mode'] ) ? (string) $asset['source_mode'] : '';
			if ( $governed ) {
				if ( 'governed' !== $mode || 'approved' !== ( isset( $asset['review_status'] ) ? (string) $asset['review_status'] : '' ) ) continue;
			} else {
				if ( 'task_attachment' !== $mode || '' === $task_scope || ! hash_equals( $task_scope, isset( $asset['task_scope'] ) ? (string) $asset['task_scope'] : '' ) ) continue;
			}
			$out[] = $asset;
		}
		usort(
			$out,
			static function ( $a, $b ) {
				$priority = (int) ( isset( $b['priority'] ) ? $b['priority'] : 0 ) <=> (int) ( isset( $a['priority'] ) ? $a['priority'] : 0 );
				if ( 0 !== $priority ) return $priority;
				$quality = (int) ( isset( $b['quality_score'] ) ? $b['quality_score'] : 0 ) <=> (int) ( isset( $a['quality_score'] ) ? $a['quality_score'] : 0 );
				if ( 0 !== $quality ) return $quality;
				return strcmp( isset( $a['asset_id'] ) ? (string) $a['asset_id'] : '', isset( $b['asset_id'] ) ? (string) $b['asset_id'] : '' );
			}
		);
		return $out;
	}

	private static function normalize_sets( $sets, $limit ) {
		if ( ! is_array( $sets ) ) return new WP_Error( 'mad4b_skill_context_sets_invalid', 'Context sets must be an array.' );
		if ( count( $sets ) > $limit ) return new WP_Error( 'mad4b_skill_context_sets_limit', 'Skill Context set count exceeds the bounded limit.' );
		$allowed = class_exists( 'MAD4B_SCP_Context_Authority' ) ? MAD4B_SCP_Context_Authority::categories() : array();
		$out = array();
		foreach ( $sets as $set ) {
			$key = sanitize_key( (string) $set );
			if ( '' === $key || ! isset( $allowed[ $key ] ) ) return new WP_Error( 'mad4b_skill_context_set_invalid', 'Skill Context policy contains an unsupported Context category.', array( 'category' => $key ) );
			$out[] = $key;
		}
		$out = array_values( array_unique( $out ) );
		sort( $out, SORT_STRING );
		return $out;
	}

	private static function canonical_policy( array $policy ) {
		$canonical = array(
			'contract' => self::POLICY_CONTRACT,
			'preset' => isset( $policy['preset'] ) ? (string) $policy['preset'] : 'none',
			'brand_context_required' => ! empty( $policy['brand_context_required'] ),
			'required_context_sets' => isset( $policy['required_context_sets'] ) ? array_values( $policy['required_context_sets'] ) : array(),
			'optional_context_sets' => isset( $policy['optional_context_sets'] ) ? array_values( $policy['optional_context_sets'] ) : array(),
			'allow_task_context' => ! empty( $policy['allow_task_context'] ),
		);
		sort( $canonical['required_context_sets'], SORT_STRING );
		sort( $canonical['optional_context_sets'], SORT_STRING );
		return $canonical;
	}

	private static function receipt( $logical_id, $skill_sha, array $policy, $policy_digest, array $assets, array $missing_sets, array $blockers, $site_uuid, $revision, $fingerprint, $observed_at, $task_scope, $brand_id = '', $authority_manifest_fingerprint = '', $registry_revision = 0 ) {
		$effective_required = isset( $policy['effective_required_context_sets'] ) && is_array( $policy['effective_required_context_sets'] )
			? array_values( $policy['effective_required_context_sets'] )
			: ( isset( $policy['required_context_sets'] ) ? array_values( $policy['required_context_sets'] ) : array() );
		$receipt = array(
			'contract' => self::RECEIPT_CONTRACT,
			'ephemeral' => true,
			'persistence_state' => 'bindable_digest_not_yet_committed',
			'site_uuid' => (string) $site_uuid,
			'brand_id' => (string) $brand_id,
			'skill_logical_id' => (string) $logical_id,
			'skill_sha256' => (string) $skill_sha,
			'context_policy_sha256' => (string) $policy_digest,
			'brand_context_revision' => (int) $revision,
			'registry_revision' => (int) $registry_revision,
			'context_fingerprint' => (string) $fingerprint,
			'authority_manifest_fingerprint' => (string) $authority_manifest_fingerprint,
			'task_scope' => (string) $task_scope,
			'declared_required_context_sets' => isset( $policy['required_context_sets'] ) ? array_values( $policy['required_context_sets'] ) : array(),
			'site_required_context_sets' => isset( $policy['site_required_context_sets'] ) ? array_values( $policy['site_required_context_sets'] ) : array(),
			'required_context_sets' => $effective_required,
			'optional_context_sets' => isset( $policy['effective_optional_context_sets'] ) ? array_values( $policy['effective_optional_context_sets'] ) : ( isset( $policy['optional_context_sets'] ) ? array_values( $policy['optional_context_sets'] ) : array() ),
			'assets_loaded' => array_values( $assets ),
			'required_assets_missing' => array_values( $missing_sets ),
			'blockers' => array_values( $blockers ),
			'ready' => empty( $blockers ),
			'observed_at' => (string) $observed_at,
		);
		$receipt['receipt_sha256'] = self::canonical_receipt_digest( $receipt );
		return $receipt;
	}

	public static function mutation_context_guard( $ability_name, $input ) {
		$ability_name = (string) $ability_name;
		$input = is_array( $input ) ? $input : array();
		$requirement = self::content_mutation_requirement( $ability_name, $input );
		$requires_receipt = ! empty( $requirement['required'] );

		$receipt = class_exists( 'MAD4B_SCP_Staging_Write_Authority' )
			? MAD4B_SCP_Staging_Write_Authority::context_receipt_from_input( $input )
			: ( isset( $input['_mad4b_context_receipt'] ) && is_array( $input['_mad4b_context_receipt'] ) ? $input['_mad4b_context_receipt'] : array() );

		if ( ! $requires_receipt && empty( $receipt ) ) return true;
		if ( $requires_receipt && empty( $receipt ) ) {
			return new WP_Error(
				'mad4b_content_context_receipt_required',
				'This mutation changes brand-bearing content and requires the exact governed Context Receipt returned by mad4b/skill-get.',
				array(
					'ability' => $ability_name,
					'reason' => isset( $requirement['reason'] ) ? (string) $requirement['reason'] : 'content_bearing_mutation',
					'matched_fields' => isset( $requirement['matched_fields'] ) ? array_values( $requirement['matched_fields'] ) : array(),
				)
			);
		}
		return self::validate_receipt_binding( $receipt );
	}

	public static function content_mutation_requirement( $ability_name, $input ) {
		$ability_name = (string) $ability_name;
		$input = is_array( $input ) ? $input : array();
		$matched = array();

		if ( 'mad4b/content-update-post' === $ability_name ) {
			foreach ( array( 'post_title', 'post_content', 'post_excerpt' ) as $field ) if ( array_key_exists( $field, $input ) ) $matched[] = $field;
			return self::content_requirement_result( $matched, 'post_text_fields' );
		}

		if ( 'mad4b/content-import-bundle' === $ability_name ) {
			$posts = isset( $input['bundle']['posts'] ) && is_array( $input['bundle']['posts'] ) ? $input['bundle']['posts'] : array();
			foreach ( $posts as $index => $post ) {
				if ( ! is_array( $post ) ) continue;
				foreach ( array( 'post_title', 'post_content', 'post_excerpt' ) as $field ) {
					if ( array_key_exists( $field, $post ) && '' !== trim( (string) $post[ $field ] ) ) $matched[] = 'bundle.posts.' . (int) $index . '.' . $field;
				}
			}
			return self::content_requirement_result( $matched, 'content_bundle_text' );
		}

		if ( 'mad4b/taxonomy-update-term' === $ability_name ) {
			foreach ( array( 'name', 'description' ) as $field ) if ( array_key_exists( $field, $input ) ) $matched[] = $field;
			return self::content_requirement_result( $matched, 'taxonomy_text_fields' );
		}

		if ( 'seo/update-meta' === $ability_name ) {
			$fields = isset( $input['fields'] ) && is_array( $input['fields'] ) ? $input['fields'] : array();
			foreach ( array( 'title', 'description', 'focus_keyword' ) as $field ) if ( array_key_exists( $field, $fields ) ) $matched[] = 'fields.' . $field;
			return self::content_requirement_result( $matched, 'seo_text_fields' );
		}

		if ( 'woocommerce/update-product' === $ability_name ) {
			$fields = isset( $input['fields'] ) && is_array( $input['fields'] ) ? $input['fields'] : array();
			foreach ( array( 'name', 'description', 'short_description' ) as $field ) if ( array_key_exists( $field, $fields ) ) $matched[] = 'fields.' . $field;
			return self::content_requirement_result( $matched, 'product_text_fields' );
		}

		if ( 'mad4b/content-set-meta' === $ability_name ) {
			$key = isset( $input['key'] ) ? (string) $input['key'] : '';
			if ( self::content_field_name( $key ) || self::value_looks_like_content( isset( $input['value'] ) ? $input['value'] : null ) ) $matched[] = 'key:' . $key;
			return self::content_requirement_result( $matched, 'post_meta_text' );
		}

		if ( 'jetengine/update-post-meta' === $ability_name ) {
			$field = isset( $input['field'] ) ? (string) $input['field'] : '';
			if ( self::content_field_name( $field ) || self::value_looks_like_content( isset( $input['value'] ) ? $input['value'] : null ) ) $matched[] = 'field:' . $field;
			return self::content_requirement_result( $matched, 'jetengine_text_meta' );
		}

		if ( 'elementor/update-widget-settings' === $ability_name ) {
			$settings = isset( $input['settings'] ) && is_array( $input['settings'] ) ? $input['settings'] : array();
			$matched = self::content_paths_in_value( $settings, 'settings', 0 );
			return self::content_requirement_result( $matched, 'elementor_text_settings' );
		}

		return array(
			'contract' => 'mad4b.content-context-requirement.v1',
			'required' => false,
			'reason' => 'not_content_bearing',
			'matched_fields' => array(),
		);
	}

	private static function content_requirement_result( array $matched, $reason ) {
		$matched = array_values( array_unique( array_filter( array_map( 'strval', $matched ) ) ) );
		return array(
			'contract' => 'mad4b.content-context-requirement.v1',
			'required' => ! empty( $matched ),
			'reason' => empty( $matched ) ? 'not_content_bearing' : sanitize_key( (string) $reason ),
			'matched_fields' => $matched,
		);
	}

	private static function content_field_name( $key ) {
		$key = strtolower( trim( (string) $key ) );
		if ( '' === $key ) return false;
		return 1 === preg_match(
			'/(^|[_\-])(title|headline|heading|subtitle|content|body|description|excerpt|summary|text|copy|caption|label|tagline|slogan|bio|about|intro|overview|details|message|note|notes|question|answer|faq|cta|button_text|placeholder|keyword|keywords)([_\-]|$)/',
			$key
		);
	}

	private static function value_looks_like_content( $value ) {
		if ( is_string( $value ) ) {
			$text = trim( wp_strip_all_tags( $value ) );
			if ( strlen( $text ) < 80 ) return false;
			return preg_match( '/\s/u', $text ) && preg_match( '/[\p{L}]/u', $text );
		}
		if ( ! is_array( $value ) ) return false;
		foreach ( $value as $item ) if ( self::value_looks_like_content( $item ) ) return true;
		return false;
	}

	private static function content_paths_in_value( $value, $path, $depth ) {
		if ( $depth > 8 || ! is_array( $value ) ) return array();
		$matched = array();
		foreach ( $value as $key => $item ) {
			$key_text = is_string( $key ) ? $key : (string) $key;
			$child_path = '' === $path ? $key_text : $path . '.' . $key_text;
			if ( self::content_field_name( $key_text ) && ( is_scalar( $item ) || null === $item || self::value_looks_like_content( $item ) ) ) $matched[] = $child_path;
			if ( is_array( $item ) ) $matched = array_merge( $matched, self::content_paths_in_value( $item, $child_path, $depth + 1 ) );
		}
		return array_values( array_unique( $matched ) );
	}

	public static function validate_receipt_binding( $receipt ) {
		if ( ! is_array( $receipt ) || self::RECEIPT_CONTRACT !== ( isset( $receipt['contract'] ) ? (string) $receipt['contract'] : '' ) ) return new WP_Error( 'mad4b_context_receipt_invalid', 'Context Receipt contract is missing or invalid.' );
		if ( empty( $receipt['ready'] ) || ! empty( $receipt['blockers'] ) ) return new WP_Error( 'mad4b_context_receipt_not_ready', 'Context Receipt was not issued from a ready governed preflight.' );
		$expected_digest = isset( $receipt['receipt_sha256'] ) ? strtolower( trim( (string) $receipt['receipt_sha256'] ) ) : '';
		$observed_at = isset( $receipt['observed_at'] ) ? strtotime( (string) $receipt['observed_at'] ) : false;
		if ( false === $observed_at || $observed_at > time() + 60 || ( time() - $observed_at ) > self::MAX_RECEIPT_AGE ) return new WP_Error( 'mad4b_context_receipt_expired', 'Context Receipt is outside the certified freshness window; rerun the Skill Context Preflight.' );
		$observed_digest = self::canonical_receipt_digest( $receipt );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $expected_digest ) || ! hash_equals( $expected_digest, $observed_digest ) ) return new WP_Error( 'mad4b_context_receipt_integrity_failed', 'Context Receipt digest does not match its canonical evidence.' );

		if ( ! class_exists( 'MAD4B_SCP_Context_Authority' ) ) return new WP_Error( 'mad4b_context_authority_unavailable', 'Context Authority is unavailable while validating the receipt.' );
		$authority = MAD4B_SCP_Context_Authority::status();
		$current_site_uuid = isset( $authority['site_uuid'] ) ? (string) $authority['site_uuid'] : '';
		$current_revision = isset( $authority['profile_revision'] ) ? (int) $authority['profile_revision'] : 0;
		$current_registry_revision = isset( $authority['registry_revision'] ) ? (int) $authority['registry_revision'] : MAD4B_SCP_Context_Authority::registry_revision();
		$current_fingerprint = isset( $authority['context_fingerprint'] ) ? (string) $authority['context_fingerprint'] : '';
		$current_authority_fingerprint = isset( $authority['authority_manifest_fingerprint'] ) ? (string) $authority['authority_manifest_fingerprint'] : MAD4B_SCP_Context_Authority::authority_manifest_fingerprint();

		if ( '' === $current_site_uuid || empty( $receipt['site_uuid'] ) || ! hash_equals( $current_site_uuid, (string) $receipt['site_uuid'] ) ) return new WP_Error( 'mad4b_context_receipt_site_drift', 'Context Receipt belongs to a different Site Profile.' );
		if ( $current_revision < 1 || $current_revision !== (int) ( isset( $receipt['brand_context_revision'] ) ? $receipt['brand_context_revision'] : 0 ) ) return new WP_Error( 'mad4b_context_receipt_profile_revision_drift', 'Brand Context Profile revision changed after the receipt was issued.' );
		if ( $current_registry_revision !== (int) ( isset( $receipt['registry_revision'] ) ? $receipt['registry_revision'] : -1 ) ) return new WP_Error( 'mad4b_context_receipt_registry_revision_drift', 'Context registry changed after the receipt was issued.' );
		if ( '' === $current_fingerprint || empty( $receipt['context_fingerprint'] ) || ! hash_equals( $current_fingerprint, (string) $receipt['context_fingerprint'] ) ) return new WP_Error( 'mad4b_context_receipt_fingerprint_drift', 'Context content fingerprint changed after the receipt was issued.' );
		if ( '' === $current_authority_fingerprint || empty( $receipt['authority_manifest_fingerprint'] ) || ! hash_equals( $current_authority_fingerprint, (string) $receipt['authority_manifest_fingerprint'] ) ) return new WP_Error( 'mad4b_context_receipt_authority_drift', 'Context authority manifest changed after the receipt was issued.' );

		$logical_id = isset( $receipt['skill_logical_id'] ) ? (string) $receipt['skill_logical_id'] : '';
		$parts = explode( ':', $logical_id, 3 );
		if ( 3 !== count( $parts ) || ! class_exists( 'MAD4B_SCP_Skill_Registry' ) ) return new WP_Error( 'mad4b_context_receipt_skill_unresolvable', 'Context Receipt Skill identity cannot be resolved against the live registry.' );
		$current_skill = MAD4B_SCP_Skill_Registry::get_skill( $parts[0], $parts[1], $parts[2] );
		if ( is_wp_error( $current_skill ) ) return new WP_Error( 'mad4b_context_receipt_skill_unresolvable', 'Context Receipt Skill no longer resolves in the live registry.', array( 'skill_error' => $current_skill->get_error_code() ) );
		if ( empty( $receipt['skill_sha256'] ) || empty( $current_skill['sha256'] ) || ! hash_equals( (string) $current_skill['sha256'], (string) $receipt['skill_sha256'] ) ) return new WP_Error( 'mad4b_context_receipt_skill_drift', 'Skill content changed after the Context Receipt was issued.' );
		if ( isset( $current_skill['context_policy_sha256'] ) && '' !== (string) $current_skill['context_policy_sha256'] ) {
			if ( empty( $receipt['context_policy_sha256'] ) || ! hash_equals( (string) $current_skill['context_policy_sha256'], (string) $receipt['context_policy_sha256'] ) ) return new WP_Error( 'mad4b_context_receipt_policy_drift', 'Skill Context Policy changed after the Context Receipt was issued.' );
		}

		return array(
			'contract' => 'mad4b.context-receipt-validation.v1',
			'ready' => true,
			'receipt_sha256' => $expected_digest,
			'site_uuid' => $current_site_uuid,
			'registry_revision' => $current_registry_revision,
			'context_fingerprint' => $current_fingerprint,
			'authority_manifest_fingerprint' => $current_authority_fingerprint,
			'skill_logical_id' => $logical_id,
		);
	}

	public static function commit_receipt_evidence( $receipt, array $binding = array() ) {
		$validated = self::validate_receipt_binding( $receipt );
		if ( is_wp_error( $validated ) ) return $validated;
		if ( ! class_exists( 'MAD4B_SCP_Audit' ) ) return new WP_Error( 'mad4b_context_receipt_audit_unavailable', 'Append-only audit is unavailable for Context Receipt binding.' );
		$audit_status = MAD4B_SCP_Audit::storage_status();
		if ( ! is_array( $audit_status ) || empty( $audit_status['ready'] ) ) return new WP_Error( 'mad4b_context_receipt_audit_not_ready', 'Append-only audit must be ready before Context-bound content execution.' );
		$event = MAD4B_SCP_Audit::record(
			'mad4b/context-receipt-bound',
			array(
				'receipt_sha256' => (string) $validated['receipt_sha256'],
				'site_uuid' => (string) $validated['site_uuid'],
				'registry_revision' => (int) $validated['registry_revision'],
				'context_fingerprint' => (string) $validated['context_fingerprint'],
				'authority_manifest_fingerprint' => (string) $validated['authority_manifest_fingerprint'],
				'skill_logical_id' => (string) $validated['skill_logical_id'],
				'ability' => isset( $binding['ability'] ) ? (string) $binding['ability'] : '',
				'provider' => isset( $binding['provider'] ) ? sanitize_key( (string) $binding['provider'] ) : '',
				'target_fingerprint' => isset( $binding['target_fingerprint'] ) ? (string) $binding['target_fingerprint'] : '',
				'approval_ticket_id' => isset( $binding['approval_ticket_id'] ) ? (string) $binding['approval_ticket_id'] : '',
				'request_id' => isset( $binding['request_id'] ) ? (string) $binding['request_id'] : '',
				'context_receipt' => $receipt,
			),
			'ok'
		);
		return is_wp_error( $event ) ? $event : $validated;
	}

	private static function canonical_receipt_digest( array $receipt ) {
		unset( $receipt['receipt_sha256'] );
		$canonical = self::canonicalize_receipt_value( $receipt );
		$json = wp_json_encode( $canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return is_string( $json ) ? hash( 'sha256', $json ) : '';
	}

	private static function canonicalize_receipt_value( $value ) {
		if ( ! is_array( $value ) ) return $value;
		$is_list = array_keys( $value ) === range( 0, count( $value ) - 1 );
		if ( $is_list ) {
			$out = array();
			foreach ( $value as $item ) $out[] = self::canonicalize_receipt_value( $item );
			return $out;
		}
		$keys = array_keys( $value );
		sort( $keys, SORT_STRING );
		$out = array();
		foreach ( $keys as $key ) $out[ $key ] = self::canonicalize_receipt_value( $value[ $key ] );
		return $out;
	}

	private static function blocked( array $skill, array $policy, $policy_digest, array $blockers, $task_scope, $observed_at ) {
		$receipt = self::receipt(
			isset( $skill['logical_id'] ) ? $skill['logical_id'] : '',
			isset( $skill['sha256'] ) ? $skill['sha256'] : '',
			$policy,
			$policy_digest,
			array(),
			isset( $policy['required_context_sets'] ) ? $policy['required_context_sets'] : array(),
			$blockers,
			'',
			0,
			'',
			$observed_at,
			$task_scope
		);
		return array(
			'contract' => self::PREFLIGHT_CONTRACT,
			'ready' => false,
			'state' => 'blocked',
			'skill_logical_id' => isset( $skill['logical_id'] ) ? (string) $skill['logical_id'] : '',
			'policy' => $policy,
			'policy_sha256' => $policy_digest,
			'blockers' => array_values( array_unique( $blockers ) ),
			'warnings' => array(),
			'envelope' => array( 'contract' => self::ENVELOPE_CONTRACT, 'required' => true, 'assets' => array(), 'total_bytes' => 0 ),
			'receipt' => $receipt,
		);
	}
}
