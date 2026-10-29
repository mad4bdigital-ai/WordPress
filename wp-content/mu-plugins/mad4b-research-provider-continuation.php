<?php
/**
 * Plugin Name: MAD4B Research Provider Continuation
 * Description: Fail-soft governed provider research plans and explicit evidence-gap notes.
 * Version: 0.1.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

final class MAD4B_Research_Provider_Continuation {
    const CONTRACT = 'mad4b.research-provider-continuation.v1';
    const ABILITY = 'mad4b/research-provider-continuation';
    const TASKS = array( 'content_brief', 'seo_audit', 'competitor_brief', 'price_intelligence', 'publication_readiness' );

    public static function boot() {
        add_action( 'wp_abilities_api_init', array( __CLASS__, 'register' ), 48 );
    }
    public static function permitted() {
        return class_exists( 'MAD4B_Research_Firecrawl_Bridge' )
            && MAD4B_Research_Firecrawl_Bridge::permitted();
    }
    public static function register() {
        if ( ! function_exists( 'wp_register_ability' ) || ! class_exists( 'MAD4B_SCP_Policy' )
            || ( function_exists( 'wp_has_ability' ) && wp_has_ability( self::ABILITY ) ) ) return;
        wp_register_ability( self::ABILITY, array(
            'label' => 'Research Provider Continuation',
            'description' => 'Return a bounded partial research workflow and evidence notes when one or more providers are unavailable, without treating substitute data as authoritative.',
            'category' => 'mad4b-admin',
            'execute_callback' => array( __CLASS__, 'evaluate' ),
            'permission_callback' => array( __CLASS__, 'permitted' ),
            'input_schema' => array(
                'type' => 'object', 'required' => array( 'task' ),
                'properties' => array(
                    'task' => array( 'type' => 'string', 'enum' => self::TASKS ),
                    'collect_preview' => array( 'type' => 'boolean', 'default' => false ),
                    'url' => array( 'type' => 'string', 'maxLength' => 2048 ),
                    'competitor_id' => array( 'type' => 'string', 'maxLength' => 80 ),
                    'demo' => array( 'type' => 'boolean', 'default' => false ),
                ),
                'additionalProperties' => false,
            ),
            'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
            'meta' => array(
                'public' => false, 'show_in_rest' => false,
                'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'admin' ),
                'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => false ),
            ),
        ) );
    }
    private static function fail( $code ) {
        return new WP_Error( 'mad4b_research_continuation_' . $code,
            'Research continuation input or authorization is not permitted.',
            array( 'status' => 403, 'mutation_performed' => false ) );
    }
    public static function route( $intent ) {
        if ( ! class_exists( 'MAD4B_Firecrawl_Provider_Connection' ) ) {
            return array( 'execution_ready' => false, 'provider_state' => 'adapter_missing' );
        }
        try {
            $out = MAD4B_Firecrawl_Provider_Connection::route( array( 'intent' => $intent ) );
        } catch ( Throwable $ignored ) {
            return array( 'execution_ready' => false, 'provider_state' => 'adapter_exception' );
        }
        if ( is_wp_error( $out ) || ! is_array( $out ) ) {
            return array( 'execution_ready' => false, 'provider_state' => 'adapter_unavailable' );
        }
        return array(
            'execution_ready' => true === ( $out['execution_ready'] ?? false ),
            'provider_state' => sanitize_key( (string)( $out['provider_state'] ?? 'unknown' ) ),
        );
    }
    public static function evaluate( $input ) {
        if ( ! self::permitted() ) return self::fail( 'denied' );
        if ( ! is_array( $input ) || ! isset( $input['task'] ) || ! is_string( $input['task'] )
            || ! in_array( $input['task'], self::TASKS, true )
            || array_diff( array_keys( $input ), array( 'task', 'collect_preview', 'url', 'competitor_id', 'demo' ) ) ) return self::fail( 'invalid' );
        $collect = ! empty( $input['collect_preview'] );
        if ( $collect && ( ! isset( $input['url'] ) || ! is_string( $input['url'] ) ) ) return self::fail( 'source_required' );

        $fire = self::route( 'page_content' );
        $serp = self::route( 'google_serp' );
        $observation = null;
        $collection_error = '';
        $attempts = 0;
        if ( $collect && ! empty( $fire['execution_ready'] ) ) {
            if ( ! class_exists( 'MAD4B_Firecrawl_Research_Cycles' ) ) $collection_error = 'research_cycle_missing';
            else {
                $args = array( 'url' => $input['url'], 'lane' => 'all' );
                if ( isset( $input['competitor_id'] ) ) $args['competitor_id'] = $input['competitor_id'];
                if ( ! empty( $input['demo'] ) ) $args['demo'] = true;
                try {
                    if ( class_exists( 'MAD4B_SCP_Connector_Resilience' ) ) {
                        // Read-only: at most two attempts. The wrapped Ability still enforces
                        // source enrollment, rights and exact hostname allowlist.
                        $read = MAD4B_SCP_Connector_Resilience::safe_read(
                            'research_provider_continuation_scrape',
                            static function () use ( $args ) { return MAD4B_Firecrawl_Research_Cycles::observe( $args ); },
                            array( 'max_attempts' => 2, 'retry_transient' => true )
                        );
                        $attempts = min( 2, (int) ( $read['attempts'] ?? 0 ) );
                        if ( ! empty( $read['ok'] ) && isset( $read['data'] ) && is_array( $read['data'] ) ) $observation = $read['data'];
                        else $collection_error = sanitize_key( (string) ( $read['error_code'] ?? $read['category'] ?? 'collection_unavailable' ) );
                    } else {
                        $attempts = 1;
                        $value = MAD4B_Firecrawl_Research_Cycles::observe( $args );
                        if ( is_array( $value ) ) $observation = $value;
                        else $collection_error = is_wp_error( $value ) ? sanitize_key( $value->get_error_code() ) : 'collection_unavailable';
                    }
                } catch ( Throwable $ignored ) { $collection_error = 'provider_exception'; }
            }
        } elseif ( $collect ) $collection_error = 'firecrawl_unavailable';
        $result = self::compose( $input['task'], $fire, $serp, $collect, $observation, $collection_error );
        $result['observation_attempts'] = $attempts;
        $result['evaluated_at'] = gmdate( 'c' );
        return $result;
    }

    /** Pure reducer for integration tests and deterministic provider outage drills. */
    public static function compose( $task, array $fire, array $serp, $collect = false, $observation = null, $failure = '' ) {
        $fire_ready = true === ( $fire['execution_ready'] ?? false );
        $serp_ready = true === ( $serp['execution_ready'] ?? false );
        $observed = is_array( $observation ) && ! empty( $observation['extracted_sha256'] )
            && ! empty( $observation['source_url'] ) && '' === $failure;
        $notes = array();
        $completed = array( 'scope_and_provider_diagnostics', 'research_continuation_plan' );
        $pending = array();
        if ( ! $fire_ready ) {
            $notes[] = array( 'code' => 'firecrawl_unavailable', 'provider' => 'firecrawl',
                'observed_state' => sanitize_key( (string) ( $fire['provider_state'] ?? 'unknown' ) ),
                'evidence_not_obtained' => 'competitor_page_content_seo_and_price_mentions',
                'resumption' => 'restore_private_provider_auth_or_network_then_recheck_exact_source_authority' );
            $pending[] = 'approved_page_evidence';
        }
        if ( ! $serp_ready ) {
            $notes[] = array( 'code' => 'serpapi_unavailable', 'provider' => 'serpapi',
                'observed_state' => sanitize_key( (string) ( $serp['provider_state'] ?? 'unknown' ) ),
                'evidence_not_obtained' => 'google_serp_rankings_and_search_features',
                'resumption' => 'configure_verified_certified_serp_provider_and_budget' );
            $pending[] = 'certified_serp_evidence';
        }
        if ( $observed ) {
            $completed[] = 'one_governed_page_observation';
            $notes[] = array( 'code' => 'unverified_page_evidence', 'provider' => 'firecrawl',
                'source_url' => $observation['source_url'], 'source_digest' => $observation['extracted_sha256'],
                'evidence_not_obtained' => 'verified_robots_rights_prices_and_serp_rankings',
                'resumption' => 'independent_source_rights_currency_and_commercial_validation' );
        } elseif ( $collect ) {
            $notes[] = array( 'code' => 'page_collection_incomplete', 'provider' => 'firecrawl',
                'observed_state' => sanitize_key( $failure ),
                'evidence_not_obtained' => 'actual_page_observation',
                'resumption' => 'retry_only_after_provider_and_source_authority_rechecked' );
            if ( ! in_array( 'approved_page_evidence', $pending, true ) ) $pending[] = 'approved_page_evidence';
        } else {
            $notes[] = array( 'code' => 'collection_not_requested', 'provider' => 'firecrawl',
                'evidence_not_obtained' => 'actual_page_observation',
                'resumption' => 'request_one_authorized_page_when_needed' );
        }
        $requires_serp = in_array( $task, array( 'seo_audit', 'competitor_brief', 'publication_readiness' ), true );
        $required_missing = array();
        if ( ! $observed ) $required_missing[] = 'approved_page_evidence';
        if ( $requires_serp && ! $serp_ready ) $required_missing[] = 'certified_serp_evidence';
        if ( 'price_intelligence' === $task ) $required_missing[] = 'fresh_commercial_price_validation';
        if ( 'publication_readiness' === $task ) $required_missing[] = 'independent_editorial_and_write_approval';

        // The task can still produce a useful, explicitly incomplete research plan
        // even when its publication/commercial evidence gates are not satisfied.
        return array(
            'contract' => self::CONTRACT,
            'task' => $task,
            'state' => empty( $required_missing ) ? 'evidence_collected_pending_governance' : 'partial',
            'continuation_allowed' => true,
            'allowed_next_steps' => array( 'prepare_research_outline', 'summarize_available_evidence', 'document_missing_evidence', 'request_source_or_provider_recovery' ),
            'completed_stages' => $completed,
            'pending_evidence' => array_values( array_unique( array_merge( $pending, $required_missing ) ) ),
            'blocking_evidence_for_finalization' => array_values( array_unique( $required_missing ) ),
            'notes' => $notes,
            'source_observation_collected' => $observed,
            'serp_rank_verified' => false,
            'external_source_rights_verified' => false,
            'price_verified' => false,
            'business_publication_authorized' => false,
            'wordpress_mutation_performed' => false,
            'retained_as_artifact' => false,
            'resumption_requires_revalidation' => true,
            'failure_does_not_imply_success' => true,
        );
    }
}
MAD4B_Research_Provider_Continuation::boot();
