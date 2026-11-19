<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * First-party local Brand Draft writer. This is deliberately NOT the Google
 * Drive provider: it writes only WordPress ContentJobs and unreviewed Artifacts.
 * Google Drive materialization, source scans and external assets retain their
 * independent provider certification and approval lanes.
 *
 * Staging only, evidence-bound, exact approved ticket required by central
 * Authorization; no brand authority, content publication or supplier claims.
 */
final class MAD4B_SCP_Local_Brand_Draft_Adapter extends MAD4B_SCP_Adapter_Base {
    public function id() { return 'local-brand-draft'; }
    public function label() { return 'First Party Local Brand Draft'; }
    public function is_available() {
        return class_exists( 'MAD4B_SCP_Brand_Context_Builder' )
            && class_exists( 'MAD4B_SCP_Artifacts' )
            && class_exists( 'MAD4B_SCP_Content_Jobs' )
            && class_exists( 'MAD4B_SCP_Recovery_Attempt_Budget' );
    }
    protected function certified_provider_key() { return 'core'; }
    protected function mutation_requires_certification() { return false; }
    protected function provider_certification( $available ) { return null; }
    public function ability_names() {
        return array(
            'read' => array(), 'content' => array(), 'admin' => array(),
            'write' => array( 'context/brand-draft-append', 'context/brand-draft-create' )
        );
    }
    public function register_abilities() {
        // No direct OAuth or source-provider privileges. This callback does not
        // grant a ticket. Permission is wrapped by Adapter_Base's central
        // authorization before any durable local mutation.
        $append_permission = array( __CLASS__, 'can_propose_local_append' );
        $create_permission = array( __CLASS__, 'can_propose_local_create' );
		$this->add_ability(
			'context/brand-draft-append',
			'Append Evidence-Bound Brand Context Draft',
			'brand_draft_append',
			$append_permission,
			$this->schema(
				array(
					'category' => array( 'type' => 'string', 'enum' => array( 'tone_of_voice', 'editorial_guidelines' ) ),
					'content' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => MAD4B_SCP_Brand_Context_Builder::MAX_DRAFT_BYTES ),
					'expected_plan_sha256' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ),
					'evidence_digest' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ),
					'draft_preflight_sha256' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ),
					'include_rendered_frontend' => array( 'type' => 'boolean', 'default' => false ),
				),
				array( 'category', 'content', 'expected_plan_sha256', 'evidence_digest', 'draft_preflight_sha256' )
			),
			'write',
			false,
			true,
			false
		);
		$this->add_ability(
			'context/brand-draft-create',
			'Create Evidence-Bound Brand Context Draft',
			'brand_draft_create',
			$create_permission,
			$this->schema(
				array(
					'category' => array( 'type' => 'string', 'enum' => array( 'tone_of_voice', 'editorial_guidelines' ) ),
					'content' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => MAD4B_SCP_Brand_Context_Builder::MAX_DRAFT_BYTES ),
					'expected_plan_sha256' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ),
					'evidence_digest' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ),
					'include_rendered_frontend' => array( 'type' => 'boolean', 'default' => false ),
				),
				array( 'category', 'content', 'expected_plan_sha256', 'evidence_digest' )
			),
			'write',
			false,
			true,
			false
		);

    }
    public static function can_propose_local_append( $input = null ) {
        return self::can_propose_local_draft( $input, 'context/brand-draft-append' );
    }
    public static function can_propose_local_create( $input = null ) {
        return self::can_propose_local_draft( $input, 'context/brand-draft-create' );
    }
    private static function can_propose_local_draft( $input, $ability ) {
        if ( ! function_exists( 'wp_get_environment_type' ) || 'staging' !== wp_get_environment_type() )
            return new WP_Error( 'mad4b_local_brand_draft_staging_only', 'Local draft creation is governed Staging only.' );
        if ( ! class_exists( 'MAD4B_SCP_Policy' ) || ! MAD4B_SCP_Policy::can_mutate() )
            return new WP_Error( 'mad4b_local_brand_draft_mutation_disabled', 'Governed write authority is required.' );
        // First-party core classification does not waive the exact candidate,
        // current grant, owner-review or runtime convergence gates.
        if ( ! class_exists( 'MAD4B_SCP_Staging_Write_Authority' )
            || ! method_exists( 'MAD4B_SCP_Staging_Write_Authority', 'current_execution_readiness' ) )
            return new WP_Error( 'mad4b_local_brand_draft_authority_unavailable', 'Current staging write authority evidence is required.' );
        $readiness = MAD4B_SCP_Staging_Write_Authority::current_execution_readiness( $ability, $input );
        if ( ! is_array( $readiness ) || empty( $readiness['ready'] )
            || ! empty( $readiness['candidate_bootstrap_exception'] ) )
            return new WP_Error( 'mad4b_local_brand_draft_authority_not_ready', 'The exact current staging build and grant binding must be ready before draft creation.', array( 'blind_retry_allowed' => false ) );
        if ( ! is_array( $input ) )
            return new WP_Error( 'mad4b_local_brand_draft_input_invalid', 'Exact evidence-bound draft input required.' );
        if ( ! self::valid_input( $input ) )
            return new WP_Error( 'mad4b_local_brand_draft_not_evidence_bound', 'Draft requires a category, content, current plan and evidence digest.' );
        return true;
    }
    private static function valid_input( $input ) {
        return is_array( $input )
            && in_array( (string) ( $input['category'] ?? '' ), array( 'tone_of_voice', 'editorial_guidelines' ), true )
            && ! empty( $input['content'] )
            && strlen( (string) $input['content'] ) <= MAD4B_SCP_Brand_Context_Builder::MAX_DRAFT_BYTES
            && 1 === preg_match( '/^[a-f0-9]{64}$/D', (string) ( $input['expected_plan_sha256'] ?? '' ) )
            && 1 === preg_match( '/^[a-f0-9]{64}$/D', (string) ( $input['evidence_digest'] ?? '' ) );
    }
    private function run_local_draft( $input, $method, $lane ) {
        $input = is_array( $input ) ? $input : array();
        $guard = self::can_propose_local_draft( $input,
            'brand_draft_append' === $lane ? 'context/brand-draft-append' : 'context/brand-draft-create' );
        if ( is_wp_error( $guard ) ) return $guard;
        // This is deliberately append-only and NON-REVERSIBLE: the Builder
        // can also change jobs, job events, lineage, draft index and durable
        // idempotency. Never mislabel it as a behavioral rollback-certified
        // provider capability. Independent readback + attempt journaling is
        // required before a new draft is considered persisted.
        $preflight = MAD4B_SCP_Brand_Context_Builder::draft_preflight( $input );
        if ( is_wp_error( $preflight ) ) return $preflight;
        if ( empty( $preflight['quality_gate_pass'] ) )
            return new WP_Error( 'mad4b_local_brand_draft_quality_failed', 'Evidence-bound structural preflight did not pass.' );
        if ( 'brand_draft_append' === $lane && (
            empty( $input['draft_preflight_sha256'] )
            || ! hash_equals( (string) $preflight['draft_preflight_sha256'], (string) $input['draft_preflight_sha256'] )
        ) ) return new WP_Error( 'mad4b_local_brand_draft_preflight_stale', 'The exact draft preflight receipt is required.' );
        $reservation = MAD4B_SCP_Recovery_Attempt_Budget::reserve( $lane, $input );
        if ( is_wp_error( $reservation ) ) return $reservation;
        $result = call_user_func( array( 'MAD4B_SCP_Brand_Context_Builder', $method ), $input );
        $verified = false;
        if ( is_array( $result ) && isset( $result['artifact'] ) && is_array( $result['artifact'] ) ) {
            $artifact = $result['artifact'];
            $artifact_id = (string) ( $artifact['artifact_id'] ?? '' );
            $job_id = (string) ( $artifact['job_id'] ?? '' );
            if ( '' !== $artifact_id && '' !== $job_id
                && 'brand_context_draft' === (string) ( $artifact['artifact_type'] ?? '' )
                && 'active' === (string) ( $artifact['status'] ?? '' )
                && empty( $result['brand_core_ready'] )
                && empty( $result['review_performed'] )
                && empty( $result['materialization_performed'] ) ) {
                $artifact_read = MAD4B_SCP_Artifacts::get_artifact( array( 'artifact_id' => $artifact_id ) );
                $job_read = MAD4B_SCP_Content_Jobs::get_job( array( 'job_id' => $job_id ) );
                $row = is_array( $artifact_read ) ? ( $artifact_read['artifact'] ?? array() ) : array();
                $job = is_array( $job_read ) ? ( $job_read['job'] ?? array() ) : array();
                $verified = ! is_wp_error( $artifact_read ) && ! is_wp_error( $job_read )
                    && (string) ( $row['artifact_id'] ?? '' ) === $artifact_id
                    && (string) ( $row['job_id'] ?? '' ) === $job_id
                    && (string) ( $row['artifact_type'] ?? '' ) === 'brand_context_draft'
                    && (string) ( $row['status'] ?? '' ) === 'active'
                    && (string) ( $job['job_id'] ?? '' ) === $job_id
                    && (string) ( $job['content_type'] ?? '' ) === 'brand_context_generation';
            }
        }
        $outcome = $verified ? 'succeeded' : 'uncertain';
        $finished = MAD4B_SCP_Recovery_Attempt_Budget::finish( $reservation, $outcome );
        if ( is_wp_error( $finished ) )
            return new WP_Error( 'mad4b_local_brand_draft_outcome_unverified',
                'Draft outcome could not be journaled; readback and reconciliation are required.', array( 'blind_retry_allowed' => false ) );
        if ( ! $verified ) return new WP_Error( 'mad4b_local_brand_draft_readback_inconsistent',
                'Local provisional draft, job and attempt result have not been independently confirmed. Reconciliation is required.', array( 'blind_retry_allowed' => false ) );
        $result['reversible'] = false;
        $result['review_performed'] = false;
        $result['materialization_performed'] = false;
        $result['brand_core_ready'] = false;
        return $result;
    }
    public function brand_draft_create( $input ) {
        return $this->run_local_draft( $input, 'create_draft', 'brand_draft_create' );
    }
    public function brand_draft_append( $input ) {
        return $this->run_local_draft( $input, 'append_draft', 'brand_draft_append' );
    }
}
