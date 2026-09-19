<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Deterministic, read-only intelligence over governed Context Authority assets.
 *
 * This layer never mutates Google Drive or Context registries. It performs:
 * - explicit directive conflict discovery (human-resolved, never auto-winner),
 * - structural writer/reference profiling without imitation instructions,
 * - explainable lexical retrieval ranking for optional/task context,
 * - bounded deterministic compliance checks against an exact Context Receipt.
 */
final class MAD4B_SCP_Context_Intelligence {
	const CONFLICT_CONTRACT = 'mad4b.context-conflict-report.v1';
	const REFERENCE_CONTRACT = 'mad4b.writer-reference-profile.v1';
	const RETRIEVAL_CONTRACT = 'mad4b.context-retrieval-ranking.v1';
	const COMPLIANCE_CONTRACT = 'mad4b.brand-compliance-report.v1';

	const MAX_PROVIDER_READS = 24;
	const MAX_QUERY_BYTES = 1200;
	const MAX_DRAFT_BYTES = 131072;
	const MAX_RULES = 200;

	public static function conflict_report( array $input = array() ) {
		$category_filter = sanitize_key( isset( $input['category'] ) ? (string) $input['category'] : '' );
		$limit = isset( $input['limit'] ) ? max( 1, min( 50, absint( $input['limit'] ) ) ) : 25;
		$assets = self::assets();
		$directive_rows = array();
		$warnings = array();
		$reads = 0;

		foreach ( $assets as $asset ) {
			if ( ! self::governed_authority_asset( $asset ) ) continue;
			$category = isset( $asset['category'] ) ? sanitize_key( (string) $asset['category'] ) : '';
			if ( '' !== $category_filter && $category_filter !== $category ) continue;
			if ( $reads >= self::MAX_PROVIDER_READS ) {
				$warnings[] = 'conflict_provider_read_limit_reached';
				break;
			}
			$read = self::read_asset( $asset );
			++$reads;
			if ( is_wp_error( $read ) ) {
				$warnings[] = 'asset_unreadable:' . ( isset( $asset['asset_id'] ) ? (string) $asset['asset_id'] : '' );
				continue;
			}
			$directives = self::extract_keyed_directives( isset( $read['content'] ) ? (string) $read['content'] : '' );
			foreach ( $directives as $directive ) {
				$key = $category . '|' . $directive['key'];
				if ( ! isset( $directive_rows[ $key ] ) ) $directive_rows[ $key ] = array();
				$directive_rows[ $key ][] = array(
					'asset_id' => isset( $asset['asset_id'] ) ? (string) $asset['asset_id'] : '',
					'title' => isset( $asset['title'] ) ? (string) $asset['title'] : '',
					'category' => $category,
					'authority_class' => isset( $asset['authority_class'] ) ? (string) $asset['authority_class'] : '',
					'required' => ! empty( $asset['required'] ),
					'directive_key' => $directive['key'],
					'value' => $directive['value'],
					'value_fingerprint' => hash( 'sha256', self::fold( $directive['value'] ) ),
				);
			}
		}

		$conflicts = array();
		foreach ( $directive_rows as $rows ) {
			if ( count( $rows ) < 2 ) continue;
			$distinct = array();
			foreach ( $rows as $row ) $distinct[ $row['value_fingerprint'] ] = true;
			if ( count( $distinct ) < 2 ) continue;
			$required = false;
			$policy = false;
			foreach ( $rows as $row ) {
				if ( ! empty( $row['required'] ) ) $required = true;
				if ( 'policy_authority' === $row['authority_class'] ) $policy = true;
			}
			$conflicts[] = array(
				'conflict_id' => hash( 'sha256', wp_json_encode( $rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ),
				'category' => $rows[0]['category'],
				'directive_key' => $rows[0]['directive_key'],
				'severity' => ( $required || $policy ) ? 'high' : 'medium',
				'assets' => array_values( $rows ),
				'resolution' => 'human_required',
				'auto_resolution' => false,
				'precedence_guidance' => 'policy_authority > brand_authority > task_knowledge > reference',
			);
			if ( count( $conflicts ) >= $limit ) break;
		}

		return array(
			'contract' => self::CONFLICT_CONTRACT,
			'ready' => true,
			'state' => empty( $conflicts ) ? 'clear' : 'review_required',
			'conflict_count' => count( $conflicts ),
			'conflicts' => $conflicts,
			'provider_reads' => $reads,
			'warnings' => array_values( array_unique( array_filter( $warnings ) ) ),
			'auto_resolution_performed' => false,
		);
	}

	public static function reference_profile( array $input ) {
		$asset_id = isset( $input['asset_id'] ) ? strtolower( trim( sanitize_text_field( (string) $input['asset_id'] ) ) ) : '';
		$asset = self::asset( $asset_id );
		if ( empty( $asset ) ) return new WP_Error( 'mad4b_reference_asset_not_found', 'Reference asset was not found in the active Context registry.' );
		$category = isset( $asset['category'] ) ? sanitize_key( (string) $asset['category'] ) : '';
		$allowed = array( 'writer_reference', 'content_example', 'historical_content' );
		if ( ! in_array( $category, $allowed, true ) && 'reference' !== ( isset( $asset['authority_class'] ) ? (string) $asset['authority_class'] : '' ) ) {
			return new WP_Error( 'mad4b_reference_asset_category_invalid', 'Writer Reference Profile requires a reference/content-example asset.' );
		}
		if ( 'ready' !== ( isset( $asset['status'] ) ? (string) $asset['status'] : '' ) || empty( $asset['content_complete'] ) ) {
			return new WP_Error( 'mad4b_reference_asset_not_ready', 'Reference asset must be ready with complete normalized content.' );
		}
		$read = self::read_asset( $asset );
		if ( is_wp_error( $read ) ) return $read;
		$content = isset( $read['content'] ) ? trim( (string) $read['content'] ) : '';
		if ( '' === $content ) return new WP_Error( 'mad4b_reference_asset_empty', 'Reference asset has no normalized text.' );

		$plain = trim( wp_strip_all_tags( $content ) );
		$words = self::tokens( $plain, false );
		$sentences = self::sentences( $plain );
		$paragraphs = array_values( array_filter( preg_split( '/\R{2,}/u', $plain ), static function ( $value ) { return '' !== trim( (string) $value ); } ) );
		if ( empty( $paragraphs ) && '' !== $plain ) $paragraphs = array( $plain );
		$sentence_lengths = array();
		$question_count = 0;
		foreach ( $sentences as $sentence ) {
			$sentence_lengths[] = count( self::tokens( $sentence, false ) );
			if ( preg_match( '/[?؟]\s*$/u', trim( $sentence ) ) ) ++$question_count;
		}
		$paragraph_lengths = array();
		foreach ( $paragraphs as $paragraph ) $paragraph_lengths[] = count( self::tokens( $paragraph, false ) );
		$unique = array_values( array_unique( array_map( array( __CLASS__, 'fold' ), $words ) ) );
		$avg_sentence = self::average( $sentence_lengths );
		$avg_paragraph = self::average( $paragraph_lengths );
		$rhythm_stddev = self::stddev( $sentence_lengths );
		$first_sentence = isset( $sentences[0] ) ? trim( $sentences[0] ) : '';
		$first_words = count( self::tokens( $first_sentence, false ) );
		$opening = preg_match( '/[?؟]\s*$/u', $first_sentence ) ? 'question' : ( $first_words > 0 && $first_words <= 8 ? 'short_hook' : 'declarative' );
		$rhythm = $rhythm_stddev >= 9 ? 'dynamic' : ( $rhythm_stddev >= 4 ? 'mixed' : 'steady' );
		$paragraph_style = $avg_paragraph >= 120 ? 'long_form' : ( $avg_paragraph >= 55 ? 'balanced' : 'compact' );

		return array(
			'contract' => self::REFERENCE_CONTRACT,
			'asset_id' => $asset_id,
			'title' => isset( $asset['title'] ) ? (string) $asset['title'] : '',
			'category' => $category,
			'content_sha256' => isset( $read['content_sha256'] ) ? (string) $read['content_sha256'] : '',
			'usage' => 'structural_reference_only',
			'imitation_instruction_allowed' => false,
			'metrics' => array(
				'word_count' => count( $words ),
				'sentence_count' => count( $sentences ),
				'paragraph_count' => count( $paragraphs ),
				'average_sentence_words' => round( $avg_sentence, 2 ),
				'average_paragraph_words' => round( $avg_paragraph, 2 ),
				'sentence_length_stddev' => round( $rhythm_stddev, 2 ),
				'question_ratio' => count( $sentences ) ? round( $question_count / count( $sentences ), 4 ) : 0,
				'lexical_richness' => count( $words ) ? round( count( $unique ) / count( $words ), 4 ) : 0,
			),
			'style_signals' => array(
				'opening_style' => $opening,
				'sentence_rhythm' => $rhythm,
				'paragraph_density' => $paragraph_style,
				'formality' => 'not_inferred_deterministically',
			),
			'raw_content_exposed' => false,
		);
	}

	public static function retrieve( array $input ) {
		$query = isset( $input['query'] ) ? trim( sanitize_text_field( (string) $input['query'] ) ) : '';
		if ( '' === $query || strlen( $query ) > self::MAX_QUERY_BYTES ) return new WP_Error( 'mad4b_context_retrieval_query_invalid', 'Retrieval query is required and must remain within the bounded query size.' );
		$task_scope = isset( $input['task_scope'] ) ? trim( sanitize_text_field( (string) $input['task_scope'] ) ) : '';
		$category_filter = sanitize_key( isset( $input['category'] ) ? (string) $input['category'] : '' );
		$limit = isset( $input['limit'] ) ? max( 1, min( 25, absint( $input['limit'] ) ) ) : 10;
		$query_tokens = array_values( array_unique( self::tokens( $query, true ) ) );
		$mandatory = array();
		$ranked = array();
		$blockers = array();

		foreach ( self::assets() as $asset ) {
			if ( ! is_array( $asset ) ) continue;
			$status = isset( $asset['status'] ) ? (string) $asset['status'] : '';
			$category = isset( $asset['category'] ) ? sanitize_key( (string) $asset['category'] ) : '';
			$mode = isset( $asset['source_mode'] ) ? (string) $asset['source_mode'] : '';
			if ( '' !== $category_filter && $category_filter !== $category ) continue;
			if ( 'task_attachment' === $mode ) {
				if ( '' === $task_scope || ! hash_equals( $task_scope, isset( $asset['task_scope'] ) ? (string) $asset['task_scope'] : '' ) ) continue;
			}
			if ( 'governed' === $mode && ! empty( $asset['required'] ) ) {
				if ( 'ready' !== $status || 'approved' !== ( isset( $asset['review_status'] ) ? (string) $asset['review_status'] : '' ) ) {
					$blockers[] = 'mandatory_asset_not_ready:' . ( isset( $asset['asset_id'] ) ? (string) $asset['asset_id'] : '' );
					continue;
				}
				$mandatory[] = self::retrieval_row( $asset, 1.0, array(
					'selection_mode' => 'mandatory',
					'semantic_relevance' => null,
					'authority_weight' => self::authority_weight( isset( $asset['authority_class'] ) ? $asset['authority_class'] : '' ),
					'quality_weight' => self::quality_weight( $asset ),
					'freshness_weight' => self::freshness_weight( $asset ),
					'classification_confidence' => self::classification_weight( $asset ),
				) );
				continue;
			}
			if ( 'ready' !== $status ) continue;
			if ( 'governed' === $mode && 'approved' !== ( isset( $asset['review_status'] ) ? (string) $asset['review_status'] : '' ) ) continue;

			$haystack = implode( ' ', array(
				isset( $asset['title'] ) ? (string) $asset['title'] : '',
				isset( $asset['path'] ) ? (string) $asset['path'] : '',
				isset( $asset['content_excerpt'] ) ? (string) $asset['content_excerpt'] : '',
				$category,
			) );
			$document_tokens = array_values( array_unique( self::tokens( $haystack, true ) ) );
			$semantic = self::token_overlap( $query_tokens, $document_tokens );
			$authority = self::authority_weight( isset( $asset['authority_class'] ) ? $asset['authority_class'] : '' );
			$quality = self::quality_weight( $asset );
			$freshness = self::freshness_weight( $asset );
			$confidence = self::classification_weight( $asset );
			$score = ( 0.45 * $semantic ) + ( 0.20 * $authority ) + ( 0.15 * $quality ) + ( 0.10 * $freshness ) + ( 0.10 * $confidence );
			$ranked[] = self::retrieval_row( $asset, $score, array(
				'selection_mode' => 'ranked_optional',
				'semantic_relevance' => round( $semantic, 4 ),
				'authority_weight' => round( $authority, 4 ),
				'quality_weight' => round( $quality, 4 ),
				'freshness_weight' => round( $freshness, 4 ),
				'classification_confidence' => round( $confidence, 4 ),
			) );
		}

		usort( $ranked, static function ( $a, $b ) {
			if ( $a['retrieval_score'] === $b['retrieval_score'] ) return strcmp( $a['asset_id'], $b['asset_id'] );
			return $a['retrieval_score'] > $b['retrieval_score'] ? -1 : 1;
		} );
		$ranked = array_slice( $ranked, 0, $limit );
		usort( $mandatory, static function ( $a, $b ) {
			$pa = isset( $a['priority'] ) ? (int) $a['priority'] : 0;
			$pb = isset( $b['priority'] ) ? (int) $b['priority'] : 0;
			if ( $pa === $pb ) return strcmp( $a['asset_id'], $b['asset_id'] );
			return $pa > $pb ? -1 : 1;
		} );

		return array(
			'contract' => self::RETRIEVAL_CONTRACT,
			'ready' => empty( $blockers ),
			'state' => empty( $blockers ) ? 'ready' : 'blocked',
			'query_sha256' => hash( 'sha256', self::fold( $query ) ),
			'task_scope' => $task_scope,
			'ranking_mode' => 'deterministic_lexical_metadata_v1',
			'embeddings_used' => false,
			'mandatory_assets' => $mandatory,
			'ranked_optional_assets' => $ranked,
			'blockers' => array_values( array_unique( $blockers ) ),
		);
	}

	public static function compliance_check( array $input ) {
		$text = isset( $input['text'] ) ? trim( (string) $input['text'] ) : '';
		if ( '' === $text || strlen( $text ) > self::MAX_DRAFT_BYTES ) return new WP_Error( 'mad4b_context_compliance_text_invalid', 'Compliance text is required and must remain within the bounded analysis size.' );
		$receipt = isset( $input['receipt'] ) && is_array( $input['receipt'] ) ? $input['receipt'] : array();
		if ( ! class_exists( 'MAD4B_SCP_Context_Preflight' ) || ! method_exists( 'MAD4B_SCP_Context_Preflight', 'validate_receipt_binding' ) ) {
			return new WP_Error( 'mad4b_context_receipt_validator_unavailable', 'Context Receipt validator is unavailable.' );
		}
		$validation = MAD4B_SCP_Context_Preflight::validate_receipt_binding( $receipt );
		if ( is_wp_error( $validation ) ) return $validation;

		$policy_categories = array( 'claim_policy', 'terminology', 'editorial_guidelines', 'messaging', 'tone_of_voice', 'brand_strategy', 'brand_positioning' );
		$rules = array();
		$warnings = array();
		$blockers = array();
		$reads = 0;
		$seen = array();
		$receipt_assets = isset( $receipt['assets_loaded'] ) && is_array( $receipt['assets_loaded'] ) ? $receipt['assets_loaded'] : array();
		foreach ( $receipt_assets as $receipt_asset ) {
			if ( ! is_array( $receipt_asset ) || empty( $receipt_asset['asset_id'] ) ) continue;
			$asset_id = strtolower( trim( (string) $receipt_asset['asset_id'] ) );
			if ( isset( $seen[ $asset_id ] ) ) continue;
			$seen[ $asset_id ] = true;
			$asset = self::asset( $asset_id );
			if ( empty( $asset ) ) {
				$blockers[] = 'receipt_asset_missing:' . $asset_id;
				continue;
			}
			$category = isset( $asset['category'] ) ? sanitize_key( (string) $asset['category'] ) : '';
			if ( ! in_array( $category, $policy_categories, true ) ) continue;
			if ( $reads >= self::MAX_PROVIDER_READS ) {
				$blockers[] = 'compliance_provider_read_limit_reached';
				break;
			}
			$read = self::read_asset( $asset );
			++$reads;
			if ( is_wp_error( $read ) ) {
				$blockers[] = 'policy_asset_unreadable:' . $asset_id;
				continue;
			}
			foreach ( self::extract_compliance_rules( isset( $read['content'] ) ? (string) $read['content'] : '', $asset_id, $category ) as $rule ) {
				$rules[] = $rule;
				if ( count( $rules ) >= self::MAX_RULES ) break 2;
			}
		}
		if ( ! empty( $blockers ) ) {
			return array(
				'contract' => self::COMPLIANCE_CONTRACT,
				'ready' => false,
				'state' => 'blocked',
				'verdict' => 'BLOCKED',
				'coverage' => 'explicit_machine_readable_rules_only',
				'semantic_model_used' => false,
				'blockers' => array_values( array_unique( $blockers ) ),
				'warnings' => array(),
				'rules_loaded' => count( $rules ),
				'provider_reads' => $reads,
			);
		}

		$folded_text = self::fold( wp_strip_all_tags( $text ) );
		$violations = array();
		foreach ( $rules as $rule ) {
			$phrase = self::fold( $rule['phrase'] );
			if ( '' === $phrase ) continue;
			$present = false !== strpos( $folded_text, $phrase );
			if ( 'forbidden' === $rule['type'] && $present ) {
				$violations[] = array(
					'code' => 'forbidden_phrase_present',
					'asset_id' => $rule['asset_id'],
					'category' => $rule['category'],
					'phrase_fingerprint' => $rule['phrase_fingerprint'],
				);
			} elseif ( 'required' === $rule['type'] && ! $present ) {
				$violations[] = array(
					'code' => 'required_phrase_missing',
					'asset_id' => $rule['asset_id'],
					'category' => $rule['category'],
					'phrase_fingerprint' => $rule['phrase_fingerprint'],
				);
			} elseif ( 'preferred' === $rule['type'] && ! $present ) {
				$warnings[] = 'preferred_phrase_missing:' . $rule['phrase_fingerprint'];
			}
		}
		if ( empty( $rules ) ) $warnings[] = 'no_explicit_machine_readable_compliance_rules_detected';
		$score = max( 0, 100 - ( count( $violations ) * 30 ) - ( count( $warnings ) * 5 ) );

		return array(
			'contract' => self::COMPLIANCE_CONTRACT,
			'ready' => true,
			'state' => empty( $violations ) ? 'ready' : 'revision_required',
			'verdict' => empty( $violations ) ? 'PASS' : 'REVISION_REQUIRED',
			'coverage' => 'explicit_machine_readable_rules_only',
			'coverage_limited' => true,
			'semantic_model_used' => false,
			'compliance_score' => $score,
			'rules_loaded' => count( $rules ),
			'provider_reads' => $reads,
			'violations' => $violations,
			'warnings' => array_values( array_unique( $warnings ) ),
			'blockers' => array(),
			'receipt_sha256' => isset( $validation['receipt_sha256'] ) ? (string) $validation['receipt_sha256'] : '',
		);
	}

	private static function governed_authority_asset( array $asset ) {
		if ( 'governed' !== ( isset( $asset['source_mode'] ) ? (string) $asset['source_mode'] : '' ) ) return false;
		if ( 'ready' !== ( isset( $asset['status'] ) ? (string) $asset['status'] : '' ) || empty( $asset['content_complete'] ) ) return false;
		if ( 'approved' !== ( isset( $asset['review_status'] ) ? (string) $asset['review_status'] : '' ) ) return false;
		return in_array( isset( $asset['authority_class'] ) ? (string) $asset['authority_class'] : '', array( 'policy_authority', 'brand_authority' ), true );
	}

	private static function extract_keyed_directives( $content ) {
		$key_map = array(
			'positioning' => 'positioning',
			'brand positioning' => 'positioning',
			'التموضع' => 'positioning',
			'audience' => 'audience',
			'target audience' => 'audience',
			'الجمهور' => 'audience',
			'الجمهور المستهدف' => 'audience',
			'brand promise' => 'brand_promise',
			'promise' => 'brand_promise',
			'وعد العلامة' => 'brand_promise',
			'cta' => 'cta',
			'call to action' => 'cta',
			'الدعوة لاتخاذ إجراء' => 'cta',
			'tone' => 'tone_of_voice',
			'tone of voice' => 'tone_of_voice',
			'نبرة الصوت' => 'tone_of_voice',
			'النبرة' => 'tone_of_voice',
			'tagline' => 'tagline',
			'slogan' => 'tagline',
			'الشعار' => 'tagline',
			'claim' => 'claim',
			'الادعاء' => 'claim',
		);
		$out = array();
		foreach ( preg_split( '/\R/u', (string) $content ) as $line ) {
			if ( ! preg_match( '/^\s*([^:=：]{2,80})\s*[:=：]\s*(.{2,500})\s*$/u', trim( (string) $line ), $m ) ) continue;
			$raw_key = self::fold( $m[1] );
			if ( ! isset( $key_map[ $raw_key ] ) ) continue;
			$value = trim( wp_strip_all_tags( $m[2] ) );
			if ( '' === $value ) continue;
			$out[] = array( 'key' => $key_map[ $raw_key ], 'value' => $value );
		}
		return $out;
	}

	private static function extract_compliance_rules( $content, $asset_id, $category ) {
		$out = array();
		foreach ( preg_split( '/\R/u', (string) $content ) as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line ) continue;
			$type = '';
			$phrase = '';
			$patterns = array(
				'forbidden' => '/^\s*(?:forbidden(?:\s+(?:term|phrase|claim))?|avoid|do\s+not\s+use|must\s+not\s+use|never\s+say|ممنوع|تجنب|لا\s+تستخدم|لا\s+تذكر)\s*[:：\-]\s*(.+)$/iu',
				'required' => '/^\s*(?:required(?:\s+(?:term|phrase))?|must\s+use|use\s+term|يجب\s+استخدام|استخدم\s+المصطلح)\s*[:：\-]\s*(.+)$/iu',
				'preferred' => '/^\s*(?:preferred(?:\s+(?:term|phrase))?|prefer|المصطلح\s+المفضل|يفضل\s+استخدام)\s*[:：\-]\s*(.+)$/iu',
			);
			foreach ( $patterns as $candidate => $pattern ) {
				if ( preg_match( $pattern, $line, $m ) ) {
					$type = $candidate;
					$phrase = trim( wp_strip_all_tags( $m[1] ) );
					break;
				}
			}
			if ( '' === $type || '' === $phrase || strlen( $phrase ) > 500 ) continue;
			$out[] = array(
				'type' => $type,
				'asset_id' => (string) $asset_id,
				'category' => (string) $category,
				'phrase' => $phrase,
				'phrase_fingerprint' => hash( 'sha256', self::fold( $phrase ) ),
			);
			if ( count( $out ) >= self::MAX_RULES ) break;
		}
		return $out;
	}

	private static function retrieval_row( array $asset, $score, array $components ) {
		return array(
			'asset_id' => isset( $asset['asset_id'] ) ? (string) $asset['asset_id'] : '',
			'title' => isset( $asset['title'] ) ? (string) $asset['title'] : '',
			'category' => isset( $asset['category'] ) ? (string) $asset['category'] : '',
			'authority_class' => isset( $asset['authority_class'] ) ? (string) $asset['authority_class'] : '',
			'source_mode' => isset( $asset['source_mode'] ) ? (string) $asset['source_mode'] : '',
			'task_scope' => isset( $asset['task_scope'] ) ? (string) $asset['task_scope'] : '',
			'priority' => isset( $asset['priority'] ) ? (int) $asset['priority'] : 0,
			'retrieval_score' => round( max( 0, min( 1, (float) $score ) ), 4 ),
			'score_components' => $components,
		);
	}

	private static function authority_weight( $authority ) {
		$map = array( 'policy_authority' => 1.0, 'brand_authority' => 0.95, 'task_knowledge' => 0.78, 'reference' => 0.62 );
		$key = sanitize_key( (string) $authority );
		return isset( $map[ $key ] ) ? $map[ $key ] : 0.5;
	}

	private static function quality_weight( array $asset ) {
		if ( ! isset( $asset['quality_score'] ) || null === $asset['quality_score'] ) return 0.5;
		return max( 0, min( 1, (int) $asset['quality_score'] / 100 ) );
	}

	private static function freshness_weight( array $asset ) {
		if ( isset( $asset['quality']['dimensions']['freshness'] ) ) return max( 0, min( 1, (float) $asset['quality']['dimensions']['freshness'] / 100 ) );
		return 0.5;
	}

	private static function classification_weight( array $asset ) {
		return isset( $asset['classification_confidence'] ) ? max( 0, min( 1, (float) $asset['classification_confidence'] ) ) : 0.5;
	}

	private static function token_overlap( array $query, array $document ) {
		if ( empty( $query ) || empty( $document ) ) return 0.0;
		$set = array_fill_keys( $document, true );
		$hits = 0;
		foreach ( $query as $token ) if ( isset( $set[ $token ] ) ) ++$hits;
		return min( 1, $hits / max( 1, count( $query ) ) );
	}

	private static function tokens( $text, $fold = true ) {
		$text = wp_strip_all_tags( (string) $text );
		$parts = preg_split( '/[^\p{L}\p{N}_-]+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
		$out = array();
		foreach ( is_array( $parts ) ? $parts : array() as $part ) {
			$value = $fold ? self::fold( $part ) : trim( (string) $part );
			if ( strlen( $value ) < 2 ) continue;
			$out[] = $value;
		}
		return $out;
	}

	private static function sentences( $text ) {
		$parts = preg_split( '/(?<=[.!?؟])\s+/u', trim( (string) $text ), -1, PREG_SPLIT_NO_EMPTY );
		return array_values( array_filter( is_array( $parts ) ? $parts : array(), static function ( $value ) { return '' !== trim( (string) $value ); } ) );
	}

	private static function average( array $values ) {
		return empty( $values ) ? 0.0 : array_sum( $values ) / count( $values );
	}

	private static function stddev( array $values ) {
		if ( count( $values ) < 2 ) return 0.0;
		$mean = self::average( $values );
		$sum = 0.0;
		foreach ( $values as $value ) $sum += pow( (float) $value - $mean, 2 );
		return sqrt( $sum / count( $values ) );
	}

	public static function fold( $text ) {
		$text = trim( wp_strip_all_tags( (string) $text ) );
		if ( function_exists( 'mb_strtolower' ) ) return mb_strtolower( $text, 'UTF-8' );
		return strtolower( $text );
	}

	private static function assets() {
		return class_exists( 'MAD4B_SCP_Context_Authority' ) ? MAD4B_SCP_Context_Authority::assets() : array();
	}

	private static function asset( $asset_id ) {
		return class_exists( 'MAD4B_SCP_Context_Authority' ) ? MAD4B_SCP_Context_Authority::asset( $asset_id ) : array();
	}

	private static function read_asset( array $asset ) {
		if ( ! class_exists( 'MAD4B_SCP_Google_Drive_Context' ) || ! method_exists( 'MAD4B_SCP_Google_Drive_Context', 'read_context_asset' ) ) {
			return new WP_Error( 'mad4b_context_read_provider_unavailable', 'Governed Context read provider is unavailable.' );
		}
		$asset_id = isset( $asset['asset_id'] ) ? (string) $asset['asset_id'] : '';
		return MAD4B_SCP_Google_Drive_Context::read_context_asset( $asset_id );
	}
}
