<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/class-mad4b-scp-g6-operation-compiler.php';
require_once __DIR__ . '/class-mad4b-scp-g6-ai-workspace.php';
require_once __DIR__ . '/class-mad4b-scp-g6-knowledge-admission.php';
require_once __DIR__ . '/class-mad4b-scp-g6-provider-routing.php';
require_once __DIR__ . '/class-mad4b-scp-g6-retrieval-evaluation.php';

/** Repository-foundation review; no claims of AI, vector or live execution parity. */
final class MAD4B_SCP_G6_Acceptance {
	const CONTRACT = 'mad4b.feature007-g6-acceptance.v1';
	private static $booted = false;
	public static function can_manage() { return current_user_can( 'manage_options' ); }
	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 35 );
	}
	public static function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) ) return;
		$private = array( 'public' => false, 'show_in_rest' => false, 'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'admin' ), 'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ) );
		$defs = array(
			'mad4b/g6-content-candidate-preview' => array(
				'label' => 'Preview G6 Content Candidate',
				'callback' => array( 'MAD4B_SCP_G6_Operation_Compiler', 'candidate' ),
				'schema' => array( 'type' => 'object', 'properties' => array( 'post_type' => array( 'type' => 'string', 'maxLength' => 191 ) ), 'required' => array( 'post_type' ), 'additionalProperties' => false ),
			),
			'mad4b/g6-content-operation-preview' => array(
				'label' => 'Preview G6 Compiled Operation',
				'callback' => array( 'MAD4B_SCP_G6_Operation_Compiler', 'preview' ),
				'schema' => array( 'type' => 'object', 'properties' => array(
					'job_id' => array( 'type' => 'string' ),
					'expected_job_revision' => array( 'type' => 'integer', 'minimum' => 1 ),
					'profile_slug' => array( 'type' => 'string' ),
					'nodes' => array( 'type' => 'array', 'minItems' => 1, 'maxItems' => 32, 'items' => array( 'type' => 'object' ) ),
					'reason' => array( 'type' => 'string', 'maxLength' => 4096 ),
					'workflow' => array( 'type' => 'object' ),
				), 'required' => array( 'job_id', 'expected_job_revision', 'profile_slug', 'nodes' ), 'additionalProperties' => false ),
			),
			'mad4b/g6-ai-workspace-proposal' => array(
				'label' => 'Review G6 AI Workspace Proposal',
				'callback' => array( 'MAD4B_SCP_G6_AI_Workspace', 'propose' ),
				'schema' => array( 'type' => 'object', 'properties' => array(
					'intent' => array( 'type' => 'string' ),
					'prompt' => array( 'type' => 'string', 'maxLength' => 8192 ),
					'context_sha256' => array( 'type' => 'string' ),
					'requested_region' => array( 'type' => 'string' ),
					'max_cost_micro' => array( 'type' => 'integer', 'minimum' => 0 ),
					'data_class' => array( 'type' => 'string' ),
				), 'required' => array( 'intent', 'prompt', 'context_sha256', 'requested_region', 'max_cost_micro', 'data_class' ), 'additionalProperties' => false ),
			),
			'mad4b/g6-knowledge-source-admission' => array(
				'label' => 'Review G6 Knowledge Source',
				'callback' => array( 'MAD4B_SCP_G6_Knowledge_Admission', 'preview' ),
				'schema' => array( 'type' => 'object', 'properties' => array(
					'source_ref' => array( 'type' => 'string' ), 'source_sha256' => array( 'type' => 'string' ),
					'artifact_sha256' => array( 'type' => 'string' ), 'site_uuid' => array( 'type' => 'string' ),
					'kind' => array( 'type' => 'string' ), 'rights' => array( 'type' => 'string' ),
					'expires_at' => array( 'type' => 'integer' ), 'privacy_class' => array( 'type' => 'string' ),
					'storage_region' => array( 'type' => 'string' ), 'deleted' => array( 'type' => 'boolean' ),
					'rights_revoked' => array( 'type' => 'boolean' ),
				), 'required' => array( 'source_ref', 'source_sha256', 'artifact_sha256', 'site_uuid', 'kind', 'rights', 'expires_at', 'privacy_class', 'storage_region' ), 'additionalProperties' => false ),
			),
			'mad4b/g6-model-routing-review' => array(
				'label' => 'Review G6 Provider Routing',
				'callback' => array( 'MAD4B_SCP_G6_Provider_Routing', 'review' ),
				'schema' => array( 'type' => 'object', 'properties' => array(
					'intent' => array( 'type' => 'string' ), 'privacy_class' => array( 'type' => 'string' ),
					'region' => array( 'type' => 'string' ), 'maximum_cost_micro' => array( 'type' => 'integer' ),
					'context_sha256' => array( 'type' => 'string' ),
				), 'required' => array( 'intent', 'privacy_class', 'region', 'maximum_cost_micro', 'context_sha256' ), 'additionalProperties' => false ),
			),
			'mad4b/g6-acceptance-status' => array(
				'label' => 'Inspect G6 Acceptance',
				'callback' => array( __CLASS__, 'status' ),
				'schema' => array( 'type' => 'object', 'properties' => array(), 'additionalProperties' => false ),
			),
		);
		foreach ( $defs as $name => $def ) {
			if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $name ) ) continue;
			wp_register_ability( $name, array(
				'label' => $def['label'],
				'description' => 'Private read-only G6 preview with no authority, publication or external execution.',
				'category' => 'mad4b-admin',
				'execute_callback' => $def['callback'],
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'input_schema' => $def['schema'],
				'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
				'meta' => $private,
			) );
		}
	}
	public static function status( $input = array() ) {
		if ( ! self::can_manage() ) return MAD4B_SCP_G6_Contracts::error( 'owner_required', 'G6 acceptance requires administrator authority.' );
		if ( ! is_array( $input ) || $input ) return MAD4B_SCP_G6_Contracts::error( 'input_schema', 'Acceptance status takes no inputs.' );
		return array(
			'contract' => self::CONTRACT,
			'task_ids' => array( 'T3956', 'T3957', 'T3958', 'T3959', 'T3960', 'T3961', 'T3962', 'T3963', 'T3964', 'T3965', 'T4016', 'T4017', 'T4018', 'T4019', 'T4020' ),
			'foundation' => 'partial',
			'compiled_plan_review_redacts_payloads' => true,
			'ai_workspace_proposal_has_no_model_execution' => true,
			'knowledge_admission_is_metadata_only' => true,
			'model_router_has_no_execution' => true,
			'retrieval_evaluation_requires_external_readback' => true,
			'dispatch_dependency_receipts' => 'not_implemented_fail_closed',
			'ai_provider_execution' => false,
			'vector_index_ingestion_certified' => false,
			'live_provider_parity' => false,
			'production_authorized' => false,
			'external_paid_calls_performed' => false,
			'new_grants_or_tool_mounts' => false,
			'authorizing' => false,
		);
	}
}
