<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/class-mad4b-scp-g6-operation-compiler.php';
require_once __DIR__ . '/class-mad4b-scp-g6-ai-workspace.php';
require_once __DIR__ . '/class-mad4b-scp-g6-knowledge-admission.php';
require_once __DIR__ . '/class-mad4b-scp-g6-conversation-vault.php';
require_once __DIR__ . '/class-mad4b-scp-g6-provider-routing.php';
require_once __DIR__ . '/class-mad4b-scp-g6-retrieval-evaluation.php';

/** Repository-foundation review; no claims of AI, vector or live execution parity. */
final class MAD4B_SCP_G6_Acceptance {
	const CONTRACT = 'mad4b.feature007-g6-acceptance.v1';
	const PAGE_SLUG = 'mad4b-ai-knowledge-workspace';
	private static $booted = false;
	public static function can_manage() { return current_user_can( 'manage_options' ); }
	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 35 );
		if ( class_exists( 'MAD4B_SCP_Admin_Route_Registry', false ) ) MAD4B_SCP_Admin_Route_Registry::schedule_submenu( array( __CLASS__, 'menu' ), 35 );
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
			'mad4b/g6-private-conversation-status' => array(
				'label' => 'Inspect private conversation metadata',
				'callback' => array( 'MAD4B_SCP_G6_Conversation_Vault', 'status' ),
				'schema' => array( 'type' => 'object', 'properties' => array(), 'additionalProperties' => false ),
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

	public static function menu() {
		add_submenu_page( 'mad4b-control-plane', 'AI and Knowledge Workspace', 'AI and Knowledge', 'manage_options', self::PAGE_SLUG, array( __CLASS__, 'render' ) );
	}

	/** Human-readable owner forms use fixed fields and fixed reviewed methods. */
	private static function local_reviews() {
		$intent = array( 'content', 'translation', 'image', 'video', 'audio', 'answer', 'form_proposal' );
		$context = array( 'label' => 'Approved context digest', 'type' => 'text', 'max' => 64 );
		$region = array( 'label' => 'Data region', 'type' => 'text', 'max' => 15 );
		$class = array( 'label' => 'Data classification', 'type' => 'select', 'choices' => array( 'public', 'internal', 'restricted' ) );
		$cost = array( 'label' => 'Maximum cost in micros', 'type' => 'integer', 'max' => 1000000000 );
		return array(
			'proposal' => array( 'label' => 'AI proposal', 'fields' => array(
				'intent' => array( 'label' => 'Purpose', 'type' => 'select', 'choices' => $intent ),
				'prompt' => array( 'label' => 'Proposal instructions', 'type' => 'textarea', 'max' => 8192 ),
				'context_sha256' => $context, 'requested_region' => $region, 'data_class' => $class, 'max_cost_micro' => $cost,
			) ),
			'routing' => array( 'label' => 'Model routing review', 'fields' => array(
				'intent' => array( 'label' => 'Purpose', 'type' => 'select', 'choices' => $intent ),
				'context_sha256' => $context, 'region' => $region, 'privacy_class' => $class, 'maximum_cost_micro' => $cost,
			) ),
			'candidate' => array( 'label' => 'Content candidate', 'fields' => array(
				'post_type' => array( 'label' => 'Registered content type', 'type' => 'text', 'max' => 191 ),
			) ),
			'conversation_status' => array( 'label' => 'Private conversation status', 'fields' => array() ),
			'acceptance' => array( 'label' => 'Acceptance status', 'fields' => array() ),
		);
	}

	/** No ability names, arbitrary callbacks or executable fields enter this path. */
	public static function review_local_request( $request ) {
		$owner = MAD4B_SCP_G6_Contracts::owner(); if ( is_wp_error( $owner ) ) return $owner;
		if ( ! is_array( $request ) || array_diff( array_keys( $request ), array( 'g6_review', 'g6_review_fields', '_wpnonce', '_wp_http_referer', 'submit' ) )
			|| ! isset( $request['g6_review'], $request['_wpnonce'] )
			|| ! is_string( $request['g6_review'] ) || ! is_string( $request['_wpnonce'] ) )
			return MAD4B_SCP_G6_Contracts::error( 'local_review_schema', 'A typed owner review operation is required.' );
		foreach ( array( '_wp_http_referer', 'submit' ) as $optional ) if ( isset( $request[ $optional ] ) && ! is_string( $request[ $optional ] ) )
			return MAD4B_SCP_G6_Contracts::error( 'local_review_schema', 'Review session fields must be scalar strings.' );
		if ( strlen( $request['_wpnonce'] ) > 128 || ! wp_verify_nonce( $request['_wpnonce'], 'mad4b_g6_private_review_' . $owner ) )
			return MAD4B_SCP_G6_Contracts::error( 'local_review_nonce', 'The owner review session expired; reload this page.' );
		$reviews = self::local_reviews(); $operation = $request['g6_review'];
		if ( ! isset( $reviews[ $operation ] ) ) return MAD4B_SCP_G6_Contracts::error( 'local_review_operation', 'Only the fixed read-only reviews are available.' );
		$fields = isset( $request['g6_review_fields'] ) ? $request['g6_review_fields'] : array();
		$schema = $reviews[ $operation ]['fields'];
		if ( ! is_array( $fields ) || array_diff( array_keys( $fields ), array_keys( $schema ) ) || array_diff( array_keys( $schema ), array_keys( $fields ) ) )
			return MAD4B_SCP_G6_Contracts::error( 'local_review_schema', 'Review fields are missing or not permitted.' );
		$input = array();
		foreach ( $schema as $name => $field ) {
			if ( ! is_string( $fields[ $name ] ) ) return MAD4B_SCP_G6_Contracts::error( 'local_review_schema', 'Every form field must be a scalar string.' );
			$value = wp_unslash( $fields[ $name ] );
			if ( 'integer' === $field['type'] ) {
				if ( 1 !== preg_match( '/^(0|[1-9][0-9]{0,9})$/D', $value ) || (int) $value > $field['max'] )
					return MAD4B_SCP_G6_Contracts::error( 'local_review_schema', 'Cost must be an exact bounded non-negative integer.' );
				$input[ $name ] = (int) $value;
			} elseif ( 'select' === $field['type'] ) {
				if ( ! in_array( $value, $field['choices'], true ) ) return MAD4B_SCP_G6_Contracts::error( 'local_review_schema', 'The selected review value is not available.' );
				$input[ $name ] = $value;
			} else {
				if ( strlen( $value ) > $field['max'] ) return MAD4B_SCP_G6_Contracts::error( 'local_review_schema', 'Review text exceeds its permitted length.' );
				$input[ $name ] = $value;
			}
		}
		if ( 'proposal' === $operation ) return MAD4B_SCP_G6_AI_Workspace::propose( $input );
		if ( 'routing' === $operation ) return MAD4B_SCP_G6_Provider_Routing::review( $input );
		if ( 'candidate' === $operation ) return MAD4B_SCP_G6_Operation_Compiler::candidate( $input );
		if ( 'conversation_status' === $operation ) return MAD4B_SCP_G6_Conversation_Vault::status( $input );
		return self::status( $input );
	}

	/** Owner read-only forms: submitted instructions are never echoed or retained. */
	public static function render() {
		if ( ! self::can_manage() ) return;
		echo '<div class="wrap"><h1>' . esc_html__( 'AI and Knowledge Workspace', 'mad4b-site-control-plane' ) . '</h1>';
		echo '<p>' . esc_html__( 'Review plans and knowledge provenance here. Provider access, budgets, consent and execution are independent approvals.', 'mad4b-site-control-plane' ) . '</p>';
		echo '<h2>' . esc_html__( 'Available read-only reviews', 'mad4b-site-control-plane' ) . '</h2><ul>';
		foreach ( array( 'Content candidate and redacted DAG review', 'AI proposal and model routing policy', 'Knowledge source metadata and citation evaluation' ) as $item )
			echo '<li>' . esc_html( $item ) . '</li>';
		echo '</ul><h2>' . esc_html__( 'Review an owned request', 'mad4b-site-control-plane' ) . '</h2>';
		echo '<p>' . esc_html__( 'These reviews inspect metadata and create proposals. Provider consent, approved context, region and budget still require their own verification before execution.', 'mad4b-site-control-plane' ) . '</p>';
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['g6_review'] ) ) {
			$result = self::review_local_request( $_POST );
			if ( is_wp_error( $result ) ) {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'Review could not complete. Check the input or reload your session.', 'mad4b-site-control-plane' ) . ' <code>' . esc_html( $result->get_error_code() ) . '</code></p></div>';
			} else {
				echo '<div class="notice notice-info"><p>' . esc_html__( 'Review completed. Provider execution and publication still require separate verification and approval.', 'mad4b-site-control-plane' ) . '</p></div>';
				echo '<details><summary>' . esc_html__( 'Review metadata', 'mad4b-site-control-plane' ) . '</summary><pre class="mad4b-g6-review-result">' . esc_html( wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ) . '</pre></details>';
			}
		}
		foreach ( self::local_reviews() as $operation => $review ) {
			echo '<details><summary>' . esc_html( $review['label'] ) . '</summary><form method="post">';
			echo '<input type="hidden" name="g6_review" value="' . esc_attr( $operation ) . '">';
			foreach ( $review['fields'] as $name => $field ) {
				$id = 'mad4b-g6-' . $operation . '-' . $name; $input_name = 'g6_review_fields[' . $name . ']';
				echo '<p><label for="' . esc_attr( $id ) . '">' . esc_html( $field['label'] ) . '</label><br>';
				if ( 'select' === $field['type'] ) {
					echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $input_name ) . '">';
					foreach ( $field['choices'] as $choice ) echo '<option value="' . esc_attr( $choice ) . '">' . esc_html( $choice ) . '</option>';
					echo '</select>';
				} elseif ( 'textarea' === $field['type'] ) {
					echo '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $input_name ) . '" rows="5" maxlength="' . (int) $field['max'] . '" class="large-text" autocomplete="off" required></textarea>';
				} else {
					$numeric = 'integer' === $field['type'];
					echo '<input id="' . esc_attr( $id ) . '" name="' . esc_attr( $input_name ) . '" type="' . ( $numeric ? 'number' : 'text' ) . '" ' . ( $numeric ? 'min="0" step="1" max="' . (int) $field['max'] . '"' : 'maxlength="' . (int) $field['max'] . '"' ) . ' autocomplete="off" required>';
				}
				echo '</p>';
			}
			wp_nonce_field( 'mad4b_g6_private_review_' . (int) get_current_user_id() );
			submit_button( __( 'Review request', 'mad4b-site-control-plane' ) );
			echo '</form></details>';
		}
		echo '<h2>' . esc_html__( 'Execution blockers', 'mad4b-site-control-plane' ) . '</h2><ul>';
		foreach ( array( 'No certified model account or paid generation is implied', 'Knowledge ingestion and vector storage remain disabled until a reviewed provider is certified', 'Dependent DAG steps cannot run without server-owned journal receipts and fresh approval', 'No automatic publication, external fetch, or production grant' ) as $item )
			echo '<li>' . esc_html( $item ) . '</li>';
		echo '</ul></div>';
	}

	public static function status( $input = array() ) {
		if ( ! self::can_manage() ) return MAD4B_SCP_G6_Contracts::error( 'owner_required', 'G6 acceptance requires administrator authority.' );
		if ( ! is_array( $input ) || $input ) return MAD4B_SCP_G6_Contracts::error( 'input_schema', 'Acceptance status takes no inputs.' );
		return array(
			'contract' => self::CONTRACT,
			'task_ids' => array( 'T3956', 'T3957', 'T3958', 'T3959', 'T3960', 'T3961', 'T3962', 'T3963', 'T3964', 'T3965', 'T4016', 'T4017', 'T4018', 'T4019', 'T4020' ),
			'foundation' => 'partial',
			'compiled_plan_review_redacts_payloads' => true,
			'owner_local_review_form_nonce_bound' => true,
			'owner_local_review_form_persists_payload' => false,
			'ai_workspace_proposal_has_no_model_execution' => true,
			'knowledge_admission_is_metadata_only' => true,
			'conversation_vault_encryption_requires_dedicated_key' => true,
			'conversation_vault_production_certified' => false,
			'conversation_vault_whole_registry_rollback_certified' => false,
			'conversation_vault_tombstone_authenticity_certified' => false,
			'conversation_vault_monotonic_external_anchor_required' => true,
			'model_router_has_no_execution' => true,
			'retrieval_evaluation_requires_external_readback' => true,
			'dispatch_dependency_receipts' => 'durable_records_inspected_postconditions_pending_fail_closed',
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

// Declare only this reviewed read-only admin route; preserve parent menu ordering.
if ( class_exists( 'MAD4B_SCP_Admin_Route_Registry', false ) ) MAD4B_SCP_Admin_Route_Registry::register( MAD4B_SCP_G6_Acceptance::PAGE_SLUG, 'manage_options' );
