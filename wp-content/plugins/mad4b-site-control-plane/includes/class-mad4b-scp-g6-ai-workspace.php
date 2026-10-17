<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/class-mad4b-scp-g6-contracts.php';

/**
 * Private per-admin proposal compiler. Prompt text is never returned, persisted
 * or delivered to a model here. A generated instruction is always untrusted.
 */
final class MAD4B_SCP_G6_AI_Workspace {
	const CONTRACT = 'mad4b.g6-ai-workspace-proposal.v1';
	public static function propose( $input = array() ) {
		$owner = MAD4B_SCP_G6_Contracts::owner();
		if ( is_wp_error( $owner ) ) return $owner;
		$budget = MAD4B_SCP_G6_Contracts::untrusted_arguments( $input );
		if ( is_wp_error( $budget ) ) return $budget;
		if ( ! is_array( $input ) || array_diff( array_keys( $input ), array( 'intent', 'prompt', 'context_sha256', 'requested_region', 'max_cost_micro', 'data_class' ) ) ) return MAD4B_SCP_G6_Contracts::error( 'workspace_schema', 'Workspace accepts only the reviewed proposal schema.' );
		$intent = isset( $input['intent'] ) ? $input['intent'] : '';
		if ( ! in_array( $intent, array( 'content', 'translation', 'image', 'video', 'audio', 'answer', 'form_proposal' ), true ) ) return MAD4B_SCP_G6_Contracts::error( 'workspace_intent', 'Only typed proposal intents are supported.' );
		$prompt = isset( $input['prompt'] ) ? $input['prompt'] : null;
		if ( ! is_string( $prompt ) || '' === trim( $prompt ) || strlen( $prompt ) > 8192 ) return MAD4B_SCP_G6_Contracts::error( 'workspace_prompt', 'Bounded proposal text is required.' );
		$context = isset( $input['context_sha256'] ) ? $input['context_sha256'] : '';
		if ( ! MAD4B_SCP_G6_Contracts::sha( $context ) ) return MAD4B_SCP_G6_Contracts::error( 'workspace_context', 'Exact, pre-authorized context digest is required.' );
		$region = isset( $input['requested_region'] ) ? $input['requested_region'] : '';
		if ( ! is_string( $region ) || 1 !== preg_match( '/^[a-z]{2}(-[a-z0-9]{2,12})?$/D', $region ) ) return MAD4B_SCP_G6_Contracts::error( 'workspace_region', 'Explicit bounded data region is required.' );
		$classification = isset( $input['data_class'] ) ? $input['data_class'] : '';
		if ( ! in_array( $classification, array( 'public', 'internal', 'restricted' ), true ) ) return MAD4B_SCP_G6_Contracts::error( 'workspace_class', 'Explicit data classification is required.' );
		$cost = isset( $input['max_cost_micro'] ) ? $input['max_cost_micro'] : null;
		if ( ! is_int( $cost ) || $cost < 0 || $cost > 1000000000 ) return MAD4B_SCP_G6_Contracts::error( 'workspace_budget', 'Cost cap must be an explicit bounded integer.' );
		$binding = MAD4B_SCP_G6_Contracts::binding( $context );
		if ( is_wp_error( $binding ) ) return $binding;
		$review = array(
			'contract' => self::CONTRACT,
			'owner_user_ref_sha256' => MAD4B_SCP_G6_Contracts::digest( $owner ),
			'site_binding_sha256' => MAD4B_SCP_G6_Contracts::digest( $binding ),
			'intent' => $intent,
			'prompt_sha256' => MAD4B_SCP_G6_Contracts::digest( $prompt ),
			'context_sha256' => $context,
			'data_class' => $classification,
			'requested_region' => $region,
			'max_cost_micro_requested' => $cost,
			'provider_admission' => 'missing',
			'consent_admission' => 'required',
			'cost_admission' => 'required',
			'execution_state' => 'EXTERNAL_ACTION_REQUIRED',
			'bounded_fallback' => false,
			'provider_inferred' => false,
			'paid_generation_performed' => false,
			'tool_execution_performed' => false,
			'payload_persisted' => false,
			'content_publication_performed' => false,
			'authorizing' => false,
		);
		$review['review_sha256'] = MAD4B_SCP_G6_Contracts::digest( $review );
		return $review;
	}
}
