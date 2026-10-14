<?php
/** Content experience Ability schemas and callbacks; authority stays in the registry. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
trait MAD4B_SCP_Content_Experience_Ability_Definitions {
	private static function common_payload_schema( $require_post_id ) {
		$properties = array(
			'post_id' => array( 'type' => 'integer', 'minimum' => 1 ),
			'expected_modified_gmt' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 32 ),
			'post_title' => array( 'type' => 'string' ),
			'post_content' => array( 'type' => 'string' ),
			'post_excerpt' => array( 'type' => 'string' ),
			'post_name' => array( 'type' => 'string', 'maxLength' => 200 ),
			'post_parent' => array( 'type' => 'integer', 'minimum' => 0 ),
			'menu_order' => array( 'type' => 'integer' ),
			'featured_media_id' => array( 'type' => 'integer', 'minimum' => 0 ),
			'expected_remote_media_state_sha256' => self::sha_schema(),
			'expected_media_manifest_sha256' => self::sha_schema(),
			'expected_media_manifest_item_count' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 50 ),
			'expected_media_recovery_receipt_sha256' => self::sha_schema(),
			'expected_media_binding_state_sha256' => self::sha_schema(),
			'meta' => array( 'type' => 'object', 'additionalProperties' => self::json_schema() ),
			'taxonomies' => array( 'type' => 'object', 'additionalProperties' => true ),
			'helpers' => array( 'type' => 'object', 'additionalProperties' => true ),
			'plan_sha256' => self::sha_schema(),
		);
		return self::schema( $properties, $require_post_id ? array( 'post_id', 'expected_modified_gmt' ) : array( 'post_title' ) );
	}

	private static function publish_schema() {
		return self::schema( array(
			'post_id' => array( 'type' => 'integer', 'minimum' => 1 ),
			'expected_modified_gmt' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 32 ),
			'post_status' => array( 'type' => 'string', 'enum' => array( 'publish', 'private' ), 'default' => 'publish' ),
			'helpers' => array( 'type' => 'object', 'additionalProperties' => true ),
			'plan_sha256' => self::sha_schema(),
		), array( 'post_id', 'expected_modified_gmt' ) );
	}

	private static function business_activity_link_schema( $apply ) {
		$properties = array(
			'profile_slug' => array( 'type' => 'string', 'minLength' => 2, 'maxLength' => 48 ),
			'post_id' => array( 'type' => 'integer', 'minimum' => 0 ),
			'user_id' => array( 'type' => 'integer', 'minimum' => 0 ),
			'login' => array( 'type' => 'string', 'maxLength' => 60 ),
			'email' => array( 'type' => 'string', 'maxLength' => 254 ),
			'post_title' => array( 'type' => 'string', 'maxLength' => 200 ),
			'attributes' => array( 'type' => 'object', 'maxProperties' => 40, 'additionalProperties' => true ),
			'classifications' => array( 'type' => 'object', 'maxProperties' => 20, 'additionalProperties' => true ),
		);
		$required = array( 'profile_slug' );
		if ( $apply ) {
			$properties['confirmed'] = array( 'type' => 'boolean' );
			$properties['plan_sha256'] = self::sha_schema();
			$properties['operation_key'] = array( 'type' => 'string', 'minLength' => 12, 'maxLength' => 128 );
			$required = array( 'profile_slug', 'plan_sha256', 'confirmed', 'operation_key' );
		}
		return self::schema( $properties, $required );
	}

	public static function ability_definitions() {
		$read = array( 'MAD4B_SCP_Policy', 'can_read' );
		$definitions = array(
            array(
                'name' => 'mad4b/business-activity-import-modes',
                'label' => 'Discover Dynamic Alternative Spreadsheet Import Modes',
                'callback' => array( 'MAD4B_SCP_Activity_Import_Modes', 'catalog' ),
                'permission' => array( __CLASS__, 'can_manage_profiles' ),
                'schema' => self::schema( array() ),
                'surface' => 'read', 'readonly' => true, 'destructive' => false, 'idempotent' => true,
            ),
            array(
                'name' => 'mad4b/business-activity-import-mode-plan',
                'label' => 'Plan Exact Authorized Source and Destination Import Mode',
                'callback' => array( 'MAD4B_SCP_Activity_Import_Modes', 'plan' ),
                'permission' => array( __CLASS__, 'can_manage_profiles' ),
                'schema' => self::schema( array(
                    'profile_slug' => array( 'type' => 'string', 'minLength' => 2, 'maxLength' => 48 ),
                    'mode_id' => array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 64 ),
                ), array( 'profile_slug', 'mode_id' ) ),
                'surface' => 'read', 'readonly' => true, 'destructive' => false, 'idempotent' => true,
            ),
            array(
                'name' => 'mad4b/business-activity-import-approve',
                'label' => 'Confirm Exact Immutable Source for Manual-Only CSV Export',
                'callback' => array( 'MAD4B_SCP_Activity_Import_Snapshot', 'approve_ability' ),
                'permission' => array( __CLASS__, 'can_manage_profiles' ),
                'schema' => self::schema( array(
                    'profile_slug' => array( 'type' => 'string', 'minLength' => 2, 'maxLength' => 48 ),
                    'snapshot_sha256' => self::sha_schema(),
                    'plan_sha256' => self::sha_schema(),
                    'acknowledged_warning_count' => array( 'type' => 'integer', 'minimum' => 0 ),
                    'confirmed' => array( 'type' => 'boolean' ),
                ), array( 'profile_slug', 'snapshot_sha256', 'plan_sha256',
                    'acknowledged_warning_count', 'confirmed' ) ),
                'surface' => 'content', 'readonly' => false,
                'destructive' => true, 'idempotent' => false,
            ),
            array(
                'name' => 'mad4b/business-activity-import-approval-plan',
                'label' => 'Inspect Exact Staged Source Approval Plan',
                'callback' => array( 'MAD4B_SCP_Activity_Import_Snapshot', 'approval_plan' ),
                'permission' => array( __CLASS__, 'can_manage_profiles' ),
                'schema' => self::schema( array(
                    'profile_slug' => array( 'type' => 'string', 'minLength' => 2, 'maxLength' => 48 ),
                    'snapshot_sha256' => self::sha_schema(),
                ), array( 'profile_slug', 'snapshot_sha256' ) ),
                'surface' => 'read', 'readonly' => true, 'destructive' => false, 'idempotent' => true,
            ),
            array(
                'name' => 'mad4b/business-activity-import-approval-receipt',
                'label' => 'Read Immutable Import Approval Receipt',
                'callback' => array( 'MAD4B_SCP_Activity_Import_Snapshot', 'approval_receipt' ),
                'permission' => array( __CLASS__, 'can_manage_profiles' ),
                'schema' => self::schema( array(
                    'profile_slug' => array( 'type' => 'string', 'minLength' => 2, 'maxLength' => 48 ),
                    'snapshot_sha256' => self::sha_schema(),
                ), array( 'profile_slug', 'snapshot_sha256' ) ),
                'surface' => 'read', 'readonly' => true, 'destructive' => false, 'idempotent' => true,
            ),
            array(
                'name' => 'mad4b/business-activity-wpai-observation-arm',
                'label' => 'Arm Exact Approved Staging Import Observation Only',
                'callback' => array( 'MAD4B_SCP_Activity_WPAI_Observer', 'arm' ),
                'permission' => array( __CLASS__, 'can_manage_profiles' ),
                'schema' => self::schema( array(
                    'profile_slug' => array( 'type' => 'string', 'minLength' => 2, 'maxLength' => 48 ),
                    'snapshot_sha256' => self::sha_schema(),
                    'import_id' => array( 'type' => 'integer', 'minimum' => 1 ),
                    'plan_sha256' => self::sha_schema(),
                    'confirmed' => array( 'type' => 'boolean' ),
                ), array( 'profile_slug', 'snapshot_sha256', 'import_id',
                    'plan_sha256', 'confirmed' ) ),
                'surface' => 'content', 'readonly' => false,
                'destructive' => true, 'idempotent' => false,
            ),
            array(
                'name' => 'mad4b/business-activity-wpai-observation-plan',
                'label' => 'Plan Staging Observation of an Approved WP All Import Job',
                'callback' => array( 'MAD4B_SCP_Activity_WPAI_Observer', 'arm_plan' ),
                'permission' => array( __CLASS__, 'can_manage_profiles' ),
                'schema' => self::schema( array(
                    'profile_slug' => array( 'type' => 'string', 'minLength' => 2, 'maxLength' => 48 ),
                    'snapshot_sha256' => self::sha_schema(),
                    'import_id' => array( 'type' => 'integer', 'minimum' => 1 ),
                ), array( 'profile_slug', 'snapshot_sha256', 'import_id' ) ),
                'surface' => 'read', 'readonly' => true, 'destructive' => false, 'idempotent' => true,
            ),
            array(
                'name' => 'mad4b/business-activity-wpai-observation-status',
                'label' => 'Read WP All Import Hook Observation Without Inferring Success',
                'callback' => array( 'MAD4B_SCP_Activity_WPAI_Observer', 'status' ),
                'permission' => array( __CLASS__, 'can_manage_profiles' ),
                'schema' => self::schema( array(
                    'import_id' => array( 'type' => 'integer', 'minimum' => 1 ),
                ), array( 'import_id' ) ),
                'surface' => 'read', 'readonly' => true, 'destructive' => false, 'idempotent' => true,
            ),
            array(
                'name' => 'mad4b/business-activity-import-batch-begin',
                'label' => 'Start Bounded Encrypted Staging Import Review Batch',
                'callback' => array( 'MAD4B_SCP_Activity_Import_Batches', 'begin' ),
                'permission' => array( __CLASS__, 'can_manage_profiles' ),
                'schema' => self::schema( array(
                    'profile_slug' => array( 'type' => 'string', 'minLength' => 2, 'maxLength' => 48 ),
                    'expected_chunks' => array( 'type' => 'integer', 'minimum' => 2, 'maximum' => 10 ),
                    'confirmed' => array( 'type' => 'boolean' ),
                ), array( 'profile_slug', 'expected_chunks', 'confirmed' ) ),
                'surface' => 'content', 'readonly' => false, 'destructive' => true,
                'idempotent' => false,
            ),
            array(
                'name' => 'mad4b/business-activity-import-batch-append',
                'label' => 'Append One Immutable Encrypted Source Chunk',
                'callback' => array( 'MAD4B_SCP_Activity_Import_Batches', 'append' ),
                'permission' => array( __CLASS__, 'can_manage_profiles' ),
                'schema' => self::schema( array(
                    'profile_slug' => array( 'type' => 'string', 'minLength' => 2, 'maxLength' => 48 ),
                    'batch_id' => array( 'type' => 'string', 'minLength' => 32, 'maxLength' => 32 ),
                    'chunk_index' => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 9 ),
                    'source' => array( 'type' => 'object',
                        'additionalProperties' => true ),
                ), array( 'profile_slug', 'batch_id', 'chunk_index', 'source' ) ),
                'surface' => 'content', 'readonly' => false, 'destructive' => true,
                'idempotent' => false,
            ),
            array(
                'name' => 'mad4b/business-activity-import-batch-approve',
                'label' => 'Approve Complete Encrypted Batch for Manual CSV Chunk Export Only',
                'callback' => array( 'MAD4B_SCP_Activity_Import_Batches', 'approve' ),
                'permission' => array( __CLASS__, 'can_manage_profiles' ),
                'schema' => self::schema( array(
                    'profile_slug' => array( 'type' => 'string', 'minLength' => 2, 'maxLength' => 48 ),
                    'batch_id' => array( 'type' => 'string', 'minLength' => 32, 'maxLength' => 32 ),
                    'plan_sha256' => self::sha_schema(),
                    'acknowledged_warning_count' => array( 'type' => 'integer', 'minimum' => 0 ),
                    'confirmed' => array( 'type' => 'boolean' ),
                ), array( 'profile_slug', 'batch_id', 'plan_sha256',
                    'acknowledged_warning_count', 'confirmed' ) ),
                'surface' => 'content', 'readonly' => false,
                'destructive' => true, 'idempotent' => false,
            ),
            array(
                'name' => 'mad4b/business-activity-import-batch-archive',
                'label' => 'Archive Exact Source Batch After Durable Audit',
                'callback' => array( 'MAD4B_SCP_Activity_Import_Batches', 'archive' ),
                'permission' => array( __CLASS__, 'can_manage_profiles' ),
                'schema' => self::schema( array(
                    'profile_slug' => array( 'type' => 'string', 'minLength' => 2, 'maxLength' => 48 ),
                    'batch_id' => array( 'type' => 'string', 'minLength' => 32, 'maxLength' => 32 ),
                    'confirmed' => array( 'type' => 'boolean' ),
                ), array( 'profile_slug', 'batch_id', 'confirmed' ) ),
                'surface' => 'content', 'readonly' => false,
                'destructive' => true, 'idempotent' => false,
            ),
            array(
                'name' => 'mad4b/business-activity-import-schema-onboarding-plan',
                'label' => 'Discover Safe WordPress CPT and Meta Candidates Without a Profile',
                'callback' => array( 'MAD4B_SCP_Import_Schema_Onboarding', 'plan' ),
                'permission' => array( __CLASS__, 'can_manage_profiles' ),
                'schema' => self::schema( array(
                    'post_type' => array( 'type' => 'string',
                        'minLength' => 1, 'maxLength' => 40 ),
                    'observed_headers' => array( 'type' => 'array',
                        'maxItems' => 80, 'items' => array(
                            'type' => 'string', 'minLength' => 1,
                            'maxLength' => 121 ) )
                ), array() ),
                'surface' => 'read', 'readonly' => true,
                'destructive' => false, 'idempotent' => true,
            ),
            array(
                'name' => 'mad4b/business-activity-import-mapping-evolution-plan',
                'label' => 'Observe Schema Drift and Proposed Import Mapping Changes',
                'callback' => array( 'MAD4B_SCP_Import_Mapping_Evolution', 'plan' ),
                'permission' => array( __CLASS__, 'can_manage_profiles' ),
                'schema' => self::schema( array(
                    'profile_slug' => array( 'type' => 'string', 'minLength' => 2, 'maxLength' => 48 ),
                    'snapshot_sha256' => self::sha_schema(),
                    'observed_headers' => array( 'type' => 'array', 'minItems' => 1,
                        'maxItems' => 80, 'items' => array( 'type' => 'string',
                            'minLength' => 1, 'maxLength' => 121 ) ),
                    'expected_profile_authority_sha256' => self::sha_schema(),
                ), array( 'profile_slug' ) ),
                'surface' => 'read', 'readonly' => true, 'destructive' => false,
                'idempotent' => true,
            ),
            array(
                'name' => 'mad4b/business-activity-import-mapping-mutation-simulate',
                'label' => 'Simulate Site-Owned Import Mapping Mutation Without Applying',
                'callback' => array( 'MAD4B_SCP_Import_Mapping_Evolution', 'simulate' ),
                'permission' => array( __CLASS__, 'can_manage_profiles' ),
                'schema' => self::schema( array(
                    'profile_slug' => array( 'type' => 'string', 'minLength' => 2, 'maxLength' => 48 ),
                    'snapshot_sha256' => self::sha_schema(),
                    'observed_headers' => array( 'type' => 'array', 'minItems' => 1,
                        'maxItems' => 80, 'items' => array( 'type' => 'string',
                            'minLength' => 1, 'maxLength' => 121 ) ),
                    'expected_profile_authority_sha256' => self::sha_schema(),
                    'proposal_plan_sha256' => self::sha_schema(),
                    'candidate_validation' => array( 'type' => 'object',
                        'additionalProperties' => true ),
                ), array( 'profile_slug', 'proposal_plan_sha256',
                    'candidate_validation' ) ),
                'surface' => 'read', 'readonly' => true, 'destructive' => false,
                'idempotent' => true,
            ),
            array(
                'name' => 'mad4b/business-activity-import-wpml-readback',
                'label' => 'Audit Exact Source WPML Translation Group Readback',
                'callback' => array( 'MAD4B_SCP_Import_WPML_Readback', 'plan' ),
                'permission' => array( __CLASS__, 'can_manage_profiles' ),
                'schema' => self::schema( array(
                    'profile_slug' => array( 'type' => 'string', 'minLength' => 2, 'maxLength' => 48 ),
                    'snapshot_sha256' => self::sha_schema(),
                    'group_index' => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 499 ),
                ), array( 'profile_slug', 'snapshot_sha256', 'group_index' ) ),
                'surface' => 'read', 'readonly' => true,
                'destructive' => false, 'idempotent' => true,
            ),
            array(
                'name' => 'mad4b/business-activity-import-acceptance-gates',
                'label' => 'Inspect Live Staging, Provider, Encryption and Acceptance Gaps',
                'callback' => array( 'MAD4B_SCP_Import_Acceptance_Gates', 'status' ),
                'permission' => array( __CLASS__, 'can_manage_profiles' ),
                'schema' => self::schema( array(
                    'profile_slug' => array( 'type' => 'string', 'minLength' => 2, 'maxLength' => 48 ),
                ), array( 'profile_slug' ) ),
                'surface' => 'read', 'readonly' => true,
                'destructive' => false, 'idempotent' => true,
            ),
            array(
                'name' => 'mad4b/business-activity-import-batch-mutation-status',
                'label' => 'Inspect Exact Staging Batch Mutation Lock and Recovery State',
                'callback' => array( 'MAD4B_SCP_Activity_Import_Batches', 'mutation_status' ),
                'permission' => array( __CLASS__, 'can_manage_profiles' ),
                'schema' => self::schema( array(
                    'profile_slug' => array( 'type' => 'string',
                        'minLength' => 2, 'maxLength' => 48 ),
                ), array( 'profile_slug' ) ),
                'surface' => 'read', 'readonly' => true,
                'destructive' => false, 'idempotent' => true,
            ),
            array(
                'name' => 'mad4b/business-activity-import-batch-verify',
                'label' => 'Verify Source Chunk Integrity and Cross-Chunk Identity',
                'callback' => array( 'MAD4B_SCP_Activity_Import_Batches', 'verify' ),
                'permission' => array( __CLASS__, 'can_manage_profiles' ),
                'schema' => self::schema( array(
                    'profile_slug' => array( 'type' => 'string', 'minLength' => 2, 'maxLength' => 48 ),
                    'batch_id' => array( 'type' => 'string', 'minLength' => 32, 'maxLength' => 32 ),
                ), array( 'profile_slug', 'batch_id' ) ),
                'surface' => 'read', 'readonly' => true, 'destructive' => false,
                'idempotent' => true,
            ),
            array(
                'name' => 'mad4b/business-activity-import-experience-plan',
                'label' => 'Plan Safe Import Operator Steps and Available Site Modes',
                'callback' => array( 'MAD4B_SCP_Activity_Import_Experience', 'plan' ),
                'permission' => array( __CLASS__, 'can_manage_profiles' ),
                'schema' => self::schema( array(
                    'profile_slug' => array( 'type' => 'string', 'minLength' => 2, 'maxLength' => 48 ),
                    'mode_id' => array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 64 ),
                    'wizard_step' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 4 ),
                ), array() ),
                'surface' => 'read', 'readonly' => true, 'destructive' => false, 'idempotent' => true,
            ),
            array(
                'name' => 'mad4b/business-activity-import-issues-page',
                'label' => 'Read Paginated Immutable Import Issues and Category Totals',
                'callback' => array( 'MAD4B_SCP_Activity_Import_Review', 'issues_page' ),
                'permission' => array( __CLASS__, 'can_manage_profiles' ),
                'schema' => self::schema( array(
                    'profile_slug' => array( 'type' => 'string', 'minLength' => 2, 'maxLength' => 48 ),
                    'snapshot_sha256' => self::sha_schema(),
                    'issue_offset' => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 10000 ),
                ), array( 'profile_slug', 'snapshot_sha256' ) ),
                'surface' => 'read', 'readonly' => true,
                'destructive' => false, 'idempotent' => true,
            ),
            array(
                'name' => 'mad4b/business-activity-import-reconciliation-plan',
                'label' => 'Compare Immutable Source IDs to WordPress CPT Meta in Safe Pages',
                'callback' => array( 'MAD4B_SCP_Activity_Import_Reconciliation', 'plan' ),
                'permission' => array( __CLASS__, 'can_manage_profiles' ),
                'schema' => self::schema( array(
                    'profile_slug' => array( 'type' => 'string', 'minLength' => 2, 'maxLength' => 48 ),
                    'snapshot_sha256' => self::sha_schema(),
                    'start_index' => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 500 ),
                    'page_size' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 25 ),
                ), array( 'profile_slug', 'snapshot_sha256' ) ),
                'surface' => 'read', 'readonly' => true, 'destructive' => false, 'idempotent' => true,
            ),
            array(
                'name' => 'mad4b/business-activity-import-capabilities',
                'label' => 'Discover Governed Spreadsheet Import Engines and Options',
                'callback' => array( 'MAD4B_SCP_Activity_Import_Review', 'capabilities' ),
                'permission' => array( __CLASS__, 'can_manage_profiles' ),
                'schema' => self::schema( array() ),
                'surface' => 'read', 'readonly' => true, 'destructive' => false, 'idempotent' => true,
            ),
            array(
                'name' => 'mad4b/business-activity-import-plan',
                'label' => 'Preview Profile-Bound Excel CSV or Google Sheets Import and Conflicts',
                'callback' => array( 'MAD4B_SCP_Activity_Import_Review', 'plan' ),
                'permission' => array( __CLASS__, 'can_manage_profiles' ),
                'schema' => self::schema( array(
                    'profile_slug' => array( 'type' => 'string', 'minLength' => 2, 'maxLength' => 48 ),
                    'identity_field' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 120 ),
                    'headers' => array( 'type' => 'array', 'minItems' => 1, 'maxItems' => 80, 'items' => array( 'type' => 'string' ) ),
                    'rows' => array( 'type' => 'array', 'maxItems' => 500, 'items' => array( 'type' => 'object', 'additionalProperties' => true ) ),
                    'field_mapping' => array( 'type' => 'object', 'maxProperties' => 80, 'additionalProperties' => array( 'type' => 'string' ) ),
                    'allowed_currencies' => array( 'type' => 'array', 'maxItems' => 20, 'items' => array( 'type' => 'string' ) ),
                    'price_tier_policy' => array( 'type' => 'string', 'enum' => array( 'none', 'review_monotonic' ) ),
                    'review_past_intervals' => array( 'type' => 'boolean' ),
                ), array( 'profile_slug', 'identity_field', 'headers', 'rows' ) ),
                'surface' => 'read', 'readonly' => true, 'destructive' => false, 'idempotent' => true,
            ),
            array(
                'name' => 'mad4b/business-activity-import-review',
                'label' => 'Review Signed Apps Script Import Inbox Conflicts',
                'callback' => array( 'MAD4B_SCP_Activity_Import_Review', 'review' ),
                'permission' => array( __CLASS__, 'can_manage_profiles' ),
                'schema' => self::schema( array(
                    'profile_slug' => array( 'type' => 'string', 'minLength' => 2, 'maxLength' => 48 ),
                ), array( 'profile_slug' ) ),
                'surface' => 'read', 'readonly' => true, 'destructive' => false, 'idempotent' => true,
            ),
            array(
                'name' => 'mad4b/business-activity-wp-all-import-plan',
                'label' => 'Plan Exact WP All Import Job Settings and Commercial Safeguards',
                'callback' => array( 'MAD4B_SCP_Activity_Import_Review', 'wp_all_import_plan' ),
                'permission' => array( __CLASS__, 'can_manage_profiles' ),
                'schema' => self::schema( array(
                    'profile_slug' => array( 'type' => 'string', 'minLength' => 2, 'maxLength' => 48 ),
                    'import_id' => array( 'type' => 'integer', 'minimum' => 1 ),
                    'unique_identifier' => array( 'type' => 'string', 'maxLength' => 120 ),
                    'mode' => array( 'type' => 'string', 'enum' => array( 'create_new', 'match_existing', 'update_existing' ) ),
                    'update_fields' => array( 'type' => 'array', 'maxItems' => 80, 'items' => array( 'type' => 'string' ) ),
                    'provider_options' => array( 'type' => 'object', 'maxProperties' => 18, 'additionalProperties' => true ),
                    'delete_missing' => array( 'type' => 'boolean' ),
                    'publish_immediately' => array( 'type' => 'boolean' ),
                ), array( 'profile_slug', 'import_id', 'unique_identifier', 'mode' ) ),
                'surface' => 'read', 'readonly' => true, 'destructive' => false, 'idempotent' => true,
            ),
            array(
                'name' => 'mad4b/brand-core-acceptance-plan',
                'label' => 'Inspect Brand Core Coverage Conflicts and Governing Review Queue',
                'callback' => array( 'MAD4B_SCP_Activity_Import_Review', 'brand_core_plan' ),
                'permission' => array( __CLASS__, 'can_manage_profiles' ),
                'schema' => self::schema( array() ),
                'surface' => 'read', 'readonly' => true, 'destructive' => false, 'idempotent' => true,
            ),
			array( 'name' => 'mad4b/content-experience-discover', 'label' => 'Discover Content Experience Profiles', 'callback' => array( __CLASS__, 'discover' ), 'permission' => $read, 'schema' => self::schema( array() ), 'surface' => 'read', 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
			array(
				'name' => self::BOOTSTRAP_PLAN_ABILITY, 'label' => 'Plan Content Experience Bootstrap', 'callback' => array( __CLASS__, 'bootstrap_plan' ), 'permission' => $read,
				'schema' => self::schema( array(
					'post_type' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 64, 'pattern' => '^[a-zA-Z0-9_-]+$' ),
					'profile_slug' => array( 'type' => 'string', 'maxLength' => 48 ),
					'label' => array( 'type' => 'string', 'maxLength' => 200 ),
					'taxonomy_strategy' => array( 'type' => 'string', 'enum' => array( 'none', 'public_assignable', 'all_assignable' ), 'default' => 'public_assignable' ),
					'featured_media' => array( 'type' => 'boolean' ),
					'creation_status' => array( 'type' => 'string', 'enum' => array( 'draft', 'pending', 'private' ), 'default' => 'draft' ),
					'live_update_mode' => array( 'type' => 'string', 'enum' => array( 'draft_first', 'direct' ), 'default' => 'draft_first' ),
					'media_meta_fields' => array( 'type' => 'object', 'additionalProperties' => true ),
					'media_resolution_strategy' => array( 'type' => 'string', 'enum' => array( 'manual', 'provider_declared_compatible' ), 'default' => 'manual' ),
					'allow_protected_media_meta' => array( 'type' => 'boolean', 'default' => false ),
					'expected_revision' => array( 'type' => 'integer', 'minimum' => 0 ),
				), array( 'post_type' ) ),
				'surface' => 'read', 'readonly' => true, 'destructive' => false, 'idempotent' => true,
			),
			array(
				'name' => self::MEDIA_BINDING_PLAN_ABILITY, 'label' => 'Plan Content Experience Media Binding', 'callback' => array( __CLASS__, 'media_binding_plan' ), 'permission' => $read,
				'schema' => self::schema( array(
					'profile_slug' => array( 'type' => 'string', 'minLength' => 2, 'maxLength' => 48 ),
					'manifest_sha256' => self::sha_schema(),
					'manifest_item_count' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 50 ),
					'items' => array(
						'type' => 'array', 'minItems' => 1, 'maxItems' => self::MAX_MEDIA_GALLERY_ITEMS,
						'items' => array( 'type' => 'object', 'additionalProperties' => true ),
					),
				), array( 'profile_slug', 'items' ) ),
				'surface' => 'read', 'readonly' => true, 'destructive' => false, 'idempotent' => true,
			),
			array( 'name' => 'mad4b/business-activity-status', 'label' => 'Inspect Optional Business Activity Contract', 'callback' => array( 'MAD4B_SCP_Business_Activity_Contracts', 'status' ), 'permission' => $read,
				'schema' => self::schema( array( 'profile_slug' => array( 'type' => 'string', 'minLength' => 2, 'maxLength' => 48 ) ), array( 'profile_slug' ) ),
				'surface' => 'read', 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
			array( 'name' => 'mad4b/business-activity-link-plan', 'label' => 'Plan User and Business Profile Link', 'callback' => array( 'MAD4B_SCP_Business_Activity_Contracts', 'plan' ), 'permission' => $read,
				'schema' => self::business_activity_link_schema( false ),
				'surface' => 'read', 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
			array( 'name' => 'mad4b/business-activity-context-impact-plan', 'label' => 'Review Changed Drive Policies and Context Rules Before Regenerating Draft', 'callback' => array( 'MAD4B_SCP_Activity_Source_Reconciliation', 'context_impact_plan' ), 'permission' => $read,
				'schema' => self::schema( array(
					'profile_slug' => array( 'type' => 'string', 'minLength' => 2, 'maxLength' => 48 ),
					'current_revisions' => array( 'type' => 'object', 'maxProperties' => 12, 'additionalProperties' => true ),
					'used_revisions' => array( 'type' => 'object', 'maxProperties' => 12, 'additionalProperties' => true ),
				), array( 'profile_slug', 'current_revisions', 'used_revisions' ) ),
				'surface' => 'read', 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
			array( 'name' => 'mad4b/business-activity-sync-status', 'label' => 'Inspect Persisted Activity Sync Journal', 'callback' => array( 'MAD4B_SCP_Activity_Sync_Runtime', 'status' ), 'permission' => array( __CLASS__, 'can_manage_profiles' ),
				'schema' => self::schema( array(
                'profile_slug' => array( 'type' => 'string', 'minLength' => 2, 'maxLength' => 48 ),
                'entity_id' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 180 ),
                'field_sources' => array( 'type' => 'object', 'maxProperties' => 40, 'additionalProperties' => array( 'type' => 'string' ) ),
            ), array( 'profile_slug', 'entity_id' ) ),
				'surface' => 'read', 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
			array( 'name' => 'mad4b/business-activity-sync-plan', 'label' => 'Plan Authoritative Multi-Source Sync', 'callback' => array( 'MAD4B_SCP_Activity_Sync_Runtime', 'plan' ), 'permission' => array( __CLASS__, 'can_manage_profiles' ),
				'schema' => self::schema( array(
                'profile_slug' => array( 'type' => 'string', 'minLength' => 2, 'maxLength' => 48 ),
                'entity_id' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 180 ),
            ), array( 'profile_slug', 'entity_id' ) ),
				'surface' => 'read', 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
			array( 'name' => 'mad4b/business-activity-sync-begin', 'label' => 'Begin Exact Governed Activity Sync', 'callback' => array( 'MAD4B_SCP_Activity_Sync_Runtime', 'begin' ), 'permission' => array( __CLASS__, 'can_manage_profiles' ),
				'schema' => self::schema( array(
                'profile_slug' => array( 'type' => 'string', 'minLength' => 2, 'maxLength' => 48 ),
                'entity_id' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 180 ),
                'confirmed' => array( 'type' => 'boolean' ),
                'plan_sha256' => self::sha_schema(),
                'operation_key' => array( 'type' => 'string', 'minLength' => 12, 'maxLength' => 128 ),
                'field_sources' => array( 'type' => 'object', 'maxProperties' => 40, 'additionalProperties' => array( 'type' => 'string' ) ),
            ), array( 'profile_slug', 'entity_id', 'confirmed', 'plan_sha256', 'operation_key' ) ),
				'surface' => 'content', 'readonly' => false, 'destructive' => true, 'idempotent' => false ),
			array( 'name' => 'mad4b/business-activity-sync-advance', 'label' => 'Advance One Guarded Provider Sync Step', 'callback' => array( 'MAD4B_SCP_Activity_Sync_Runtime', 'advance' ), 'permission' => array( __CLASS__, 'can_manage_profiles' ),
				'schema' => self::schema( array(
                'profile_slug' => array( 'type' => 'string', 'minLength' => 2, 'maxLength' => 48 ),
                'entity_id' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 180 ),
                'confirmed' => array( 'type' => 'boolean' ),
                'operation_key' => array( 'type' => 'string', 'minLength' => 12, 'maxLength' => 128 ),
            ), array( 'profile_slug', 'entity_id', 'confirmed', 'operation_key' ) ),
				'surface' => 'content', 'readonly' => false, 'destructive' => true, 'idempotent' => false ),
			array( 'name' => 'mad4b/business-activity-sync-recover', 'label' => 'Recover Exact Ambiguous Provider Write', 'callback' => array( 'MAD4B_SCP_Activity_Sync_Runtime', 'recover' ), 'permission' => array( __CLASS__, 'can_manage_profiles' ),
				'schema' => self::schema( array(
                'profile_slug' => array( 'type' => 'string', 'minLength' => 2, 'maxLength' => 48 ),
                'entity_id' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 180 ),
                'confirmed' => array( 'type' => 'boolean' ),
                'operation_key' => array( 'type' => 'string', 'minLength' => 12, 'maxLength' => 128 ),
                'expected_operation_sha256' => self::sha_schema(),
            ), array( 'profile_slug', 'entity_id', 'confirmed', 'operation_key', 'expected_operation_sha256' ) ),
				'surface' => 'content', 'readonly' => false, 'destructive' => true, 'idempotent' => false ),
			array( 'name' => 'mad4b/business-activity-sync-finalize-reconciled', 'label' => 'Finalize Partial Multi-Source Saga Only After All Provider Readbacks', 'callback' => array( 'MAD4B_SCP_Activity_Sync_Runtime', 'finalize_reconciled' ), 'permission' => array( __CLASS__, 'can_manage_profiles' ),
				'schema' => self::schema( array(
                'profile_slug' => array( 'type' => 'string', 'minLength' => 2, 'maxLength' => 48 ),
                'entity_id' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 180 ),
                'confirmed' => array( 'type' => 'boolean' ),
                'expected_operation_sha256' => self::sha_schema(),
            ), array( 'profile_slug', 'entity_id', 'confirmed', 'expected_operation_sha256' ) ),
				'surface' => 'content', 'readonly' => false, 'destructive' => true, 'idempotent' => false ),
			array( 'name' => 'mad4b/business-activity-sync-cancel', 'label' => 'Cancel Verified Prewrite Activity Sync', 'callback' => array( 'MAD4B_SCP_Activity_Sync_Runtime', 'cancel' ), 'permission' => array( __CLASS__, 'can_manage_profiles' ),
				'schema' => self::schema( array(
                'profile_slug' => array( 'type' => 'string', 'minLength' => 2, 'maxLength' => 48 ),
                'entity_id' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 180 ),
                'confirmed' => array( 'type' => 'boolean' ),
                'expected_operation_sha256' => self::sha_schema(),
            ), array( 'profile_slug', 'entity_id', 'confirmed', 'expected_operation_sha256' ) ),
				'surface' => 'content', 'readonly' => false, 'destructive' => true, 'idempotent' => false ),
			array( 'name' => 'mad4b/business-activity-sync-archive', 'label' => 'Archive Verified Activity Sync Receipt', 'callback' => array( 'MAD4B_SCP_Activity_Sync_Runtime', 'archive' ), 'permission' => array( __CLASS__, 'can_manage_profiles' ),
				'schema' => self::schema( array(
                'profile_slug' => array( 'type' => 'string', 'minLength' => 2, 'maxLength' => 48 ),
                'entity_id' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 180 ),
                'confirmed' => array( 'type' => 'boolean' ),
                'expected_operation_sha256' => self::sha_schema(),
            ), array( 'profile_slug', 'entity_id', 'confirmed', 'expected_operation_sha256' ) ),
				'surface' => 'content', 'readonly' => false, 'destructive' => true, 'idempotent' => false ),
			array( 'name' => 'mad4b/business-activity-reconcile-plan', 'label' => 'Compare Multi-Source Profile Field Revisions and Conflicts', 'callback' => array( 'MAD4B_SCP_Activity_Source_Reconciliation', 'plan' ), 'permission' => $read,
				'schema' => self::schema( array(
					'profile_slug' => array( 'type' => 'string', 'minLength' => 2, 'maxLength' => 48 ),
					'entity_id' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 180 ),
					'observations' => array( 'type' => 'object', 'maxProperties' => 13, 'additionalProperties' => true ),
					'baseline' => array( 'type' => 'object', 'maxProperties' => 13, 'additionalProperties' => true ),
					'max_snapshot_age_seconds' => array( 'type' => 'integer', 'minimum' => 60, 'maximum' => 86400 ),
				), array( 'profile_slug', 'entity_id', 'observations', 'baseline' ) ),
				'surface' => 'read', 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
			array( 'name' => 'mad4b/business-activity-sync-plan', 'label' => 'Plan Configured Business Activity Source Synchronization', 'callback' => array( 'MAD4B_SCP_Business_Activity_Contracts', 'sync_plan' ), 'permission' => $read,
				'schema' => self::schema( array(
					'profile_slug' => array( 'type' => 'string', 'minLength' => 2, 'maxLength' => 48 ),
					'target_id' => array( 'type' => 'string', 'minLength' => 2, 'maxLength' => 48 ),
					'operation' => array( 'type' => 'string', 'enum' => array( 'import', 'export', 'update', 'improve', 'reconcile' ) ),
				), array( 'profile_slug', 'target_id', 'operation' ) ),
				'surface' => 'read', 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
			array( 'name' => 'mad4b/business-activity-link-apply', 'label' => 'Apply Exact User and Business Profile Link', 'callback' => array( 'MAD4B_SCP_Business_Activity_Contracts', 'apply' ), 'permission' => array( __CLASS__, 'can_manage_profiles' ),
				'schema' => self::business_activity_link_schema( true ),
				'surface' => 'content', 'readonly' => false, 'destructive' => true, 'idempotent' => false ),
			array( 'name' => 'mad4b/content-experience-profile-status', 'label' => 'Content Experience Profile Status', 'callback' => array( __CLASS__, 'profile_status' ), 'permission' => $read, 'schema' => self::schema( array() ), 'surface' => 'read', 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
			array(
				'name' => 'mad4b/content-experience-profile-plan', 'label' => 'Plan Content Experience Profile', 'callback' => array( __CLASS__, 'profile_plan' ), 'permission' => $read,
				'schema' => self::schema( array( 'profile' => array( 'type' => 'object', 'additionalProperties' => true ), 'expected_revision' => array( 'type' => 'integer', 'minimum' => 0 ) ), array( 'profile' ) ),
				'surface' => 'read', 'readonly' => true, 'destructive' => false, 'idempotent' => true,
			),
			array(
				'name' => 'mad4b/content-experience-profile-clone-plan', 'label' => 'Plan Content Experience Profile Clone', 'callback' => array( __CLASS__, 'profile_clone_plan' ), 'permission' => $read,
				'schema' => self::schema( array( 'source_slug' => array( 'type' => 'string' ), 'new_slug' => array( 'type' => 'string' ), 'label' => array( 'type' => 'string' ), 'post_type' => array( 'type' => 'string' ) ), array( 'source_slug', 'new_slug' ) ),
				'surface' => 'read', 'readonly' => true, 'destructive' => false, 'idempotent' => true,
			),
			array(
				'name' => self::PROFILE_CLONE_APPLY_ABILITY, 'label' => 'Apply Content Experience Profile Clone', 'callback' => array( __CLASS__, 'profile_clone_apply' ), 'permission' => array( __CLASS__, 'can_manage_profiles' ),
				'schema' => self::schema( array( 'source_slug' => array( 'type' => 'string' ), 'new_slug' => array( 'type' => 'string' ), 'label' => array( 'type' => 'string' ), 'post_type' => array( 'type' => 'string' ), 'plan_sha256' => self::sha_schema() ), array( 'source_slug', 'new_slug', 'plan_sha256' ) ),
				'surface' => 'content', 'readonly' => false, 'destructive' => true, 'idempotent' => false,
			),
			array(
				'name' => 'mad4b/content-experience-profile-delete-plan', 'label' => 'Plan Content Experience Profile Decommission', 'callback' => array( __CLASS__, 'profile_delete_plan' ), 'permission' => $read,
				'schema' => self::schema( array( 'slug' => array( 'type' => 'string' ), 'expected_revision' => array( 'type' => 'integer', 'minimum' => 1 ) ), array( 'slug' ) ),
				'surface' => 'read', 'readonly' => true, 'destructive' => false, 'idempotent' => true,
			),
			array(
				'name' => self::PROFILE_DELETE_APPLY_ABILITY, 'label' => 'Apply Content Experience Profile Decommission', 'callback' => array( __CLASS__, 'profile_delete_apply' ), 'permission' => array( __CLASS__, 'can_manage_profiles' ),
				'schema' => self::schema( array( 'slug' => array( 'type' => 'string' ), 'expected_revision' => array( 'type' => 'integer', 'minimum' => 1 ), 'plan_sha256' => self::sha_schema() ), array( 'slug', 'plan_sha256' ) ),
				'surface' => 'content', 'readonly' => false, 'destructive' => true, 'idempotent' => false,
			),
			array(
				'name' => self::PROFILE_APPLY_ABILITY, 'label' => 'Apply Content Experience Profile', 'callback' => array( __CLASS__, 'profile_apply' ), 'permission' => array( __CLASS__, 'can_manage_profiles' ),
				'schema' => self::schema( array( 'profile' => array( 'type' => 'object', 'additionalProperties' => true ), 'expected_revision' => array( 'type' => 'integer', 'minimum' => 0 ), 'plan_sha256' => self::sha_schema() ), array( 'profile', 'plan_sha256' ) ),
				'surface' => 'content', 'readonly' => false, 'destructive' => true, 'idempotent' => false,
			),
		);
		foreach ( self::stored_profiles() as $profile ) {
			if ( empty( $profile['enabled'] ) ) continue;
			$slug = (string) $profile['slug'];
			$routes = self::routes_for_profile( $profile );
			$label = isset( $profile['label'] ) ? (string) $profile['label'] : $slug;
			$definitions[] = self::route_definition( $routes['helpers'], $label . ' Helpers', $slug, 'helpers', 'read', self::schema( array() ) );
			$definitions[] = self::route_definition( $routes['create_plan'], 'Plan ' . $label . ' Create', $slug, 'create', 'plan', self::common_payload_schema( false ) );
			$definitions[] = self::route_definition( $routes['create_apply'], 'Apply ' . $label . ' Create', $slug, 'create', 'apply', self::common_payload_schema( false ) );
			$definitions[] = self::route_definition( $routes['update_plan'], 'Plan ' . $label . ' Update', $slug, 'update', 'plan', self::common_payload_schema( true ) );
			$definitions[] = self::route_definition( $routes['update_apply'], 'Apply ' . $label . ' Update', $slug, 'update', 'apply', self::common_payload_schema( true ) );
			$definitions[] = self::route_definition( $routes['publish_plan'], 'Plan ' . $label . ' Publish', $slug, 'publish', 'plan', self::publish_schema() );
			$definitions[] = self::route_definition( $routes['publish_apply'], 'Apply ' . $label . ' Publish', $slug, 'publish', 'apply', self::publish_schema() );
			$definitions[] = self::route_definition( $routes['verify'], 'Verify ' . $label, $slug, 'verify', 'read', self::schema( array( 'post_id' => array( 'type' => 'integer', 'minimum' => 1 ) ), array( 'post_id' ) ) );
		}
		return $definitions;
	}

}
