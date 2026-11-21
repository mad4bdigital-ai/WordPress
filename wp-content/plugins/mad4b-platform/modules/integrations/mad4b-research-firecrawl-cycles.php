<?php
/**
 * Plugin Name: MAD4B Research Cycles - Firecrawl Observations
 * Description: Governed competitor research planning and non-persistent content, SEO, and price observations.
 * Version: 0.1.0
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_Firecrawl_Research_Cycles {
    const CONTRACT = 'mad4b.firecrawl-research-cycle.v1';
    const PLAN = 'mad4b/research-firecrawl-cycle-plan';
    const COLLECT = 'mad4b/research-firecrawl-cycle-observe';

    public static function boot() {
        add_action( 'wp_abilities_api_init', array( __CLASS__, 'register' ), 46 );
    }

    public static function permitted() {
        return current_user_can( 'manage_options' )
            && class_exists( 'MAD4B_SCP_Policy' )
            && MAD4B_SCP_Policy::can_read();
    }

    public static function register() {
        if ( ! function_exists( 'wp_register_ability' ) || ! class_exists( 'MAD4B_SCP_Policy' ) ) return;
        $meta = array(
            'public' => false, 'show_in_rest' => false,
            'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'admin' ),
            'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => false ),
        );
        $common = array(
            'category' => 'mad4b-admin',
            'permission_callback' => array( __CLASS__, 'permitted' ),
            'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
            'meta' => $meta,
        );
        if ( ! function_exists( 'wp_has_ability' ) || ! wp_has_ability( self::PLAN ) ) {
            wp_register_ability( self::PLAN, array_merge( $common, array(
                'label' => 'Plan Competitive Research Collection',
                'description' => 'Inspect enrolled competitor sources and existing MAD4B research/SEO/content/price review lanes without network execution.',
                'execute_callback' => array( __CLASS__, 'plan' ),
                'input_schema' => array( 'type' => 'object', 'properties' => array(), 'additionalProperties' => false ),
            ) ) );
        }
        if ( ! function_exists( 'wp_has_ability' ) || ! wp_has_ability( self::COLLECT ) ) {
            wp_register_ability( self::COLLECT, array_merge( $common, array(
                'label' => 'Observe Approved Competitor Page',
                'description' => 'Collect one explicit HTTPS page via the shared private Firecrawl provider and return provisional content, SEO and price candidates. Does not publish, store or update prices.',
                'execute_callback' => array( __CLASS__, 'observe' ),
                'input_schema' => array(
                    'type' => 'object',
                    'required' => array( 'url' ),
                    'properties' => array(
                        'url' => array( 'type' => 'string', 'maxLength' => 2048 ),
                        'competitor_id' => array( 'type' => 'string', 'maxLength' => 80, 'default' => '' ),
                        'lane' => array( 'type' => 'string', 'enum' => array( 'all', 'content', 'seo', 'pricing' ), 'default' => 'all' ),
                        'demo' => array( 'type' => 'boolean', 'default' => false ),
                    ),
                    'additionalProperties' => false,
                ),
            ) ) );
        }
    }

    private static function fail( $code, $status = 403 ) {
        return new WP_Error( 'mad4b_research_cycle_' . $code, 'Research observation is unavailable or requires an approved competitor source.', array( 'status' => $status, 'mutation_performed' => false ) );
    }

    private static function competitors() {
        if ( ! class_exists( 'MAD4B_SCP_Market_Growth_Policies' ) ) return self::fail( 'market_registry_unavailable', 503 );
        $registry = MAD4B_SCP_Market_Growth_Policies::current();
        if ( is_wp_error( $registry ) ) return $registry;
        return isset( $registry['competitors'] ) && is_array( $registry['competitors'] ) ? $registry['competitors'] : array();
    }

    private static function https_host( $url ) {
        if ( ! is_string( $url ) || strlen( $url ) > 2048 ) return '';
        $p = parse_url( $url );
        if ( ! is_array( $p ) || ! isset( $p['scheme'], $p['host'] )
            || 'https' !== strtolower( $p['scheme'] )
            || isset( $p['user'] ) || isset( $p['pass'] ) || isset( $p['port'] ) || isset( $p['fragment'] ) ) return '';
        $host = strtolower( $p['host'] );
        if ( ! filter_var( $host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME ) || filter_var( $host, FILTER_VALIDATE_IP ) ) return '';
        return $host;
    }

    public static function plan( $input = array() ) {
        if ( ! self::permitted() ) return self::fail( 'denied' );
        if ( ! is_array( $input ) || $input ) return self::fail( 'invalid_input' );
        $profiles = self::competitors();
        if ( is_wp_error( $profiles ) ) return $profiles;
        $items = array();
        $admitted = 0;
        $allowlist = defined( 'MAD4B_FIRECRAWL_ALLOWED_HOSTS' ) ? constant( 'MAD4B_FIRECRAWL_ALLOWED_HOSTS' ) : array( 'example.com' );
        $allowlist = is_array( $allowlist ) ? $allowlist : array();
        foreach ( $profiles as $id => $row ) {
            if ( ! is_array( $row ) || ! empty( $row['disabled'] ) ) continue;
            $url = isset( $row['source_url'] ) && is_string( $row['source_url'] ) ? $row['source_url'] : '';
            $host = self::https_host( $url );
            $allowed = '' !== $host && in_array( $host, $allowlist, true );
            if ( $allowed ) ++$admitted;
            $items[] = array(
                'competitor_id' => (string) $id,
                'host' => $host,
                'site_source_enrolled' => '' !== $host,
                'provider_allowlist_verified' => $allowed,
                'robots_rights_review_required' => true,
            );
            if ( count( $items ) >= 100 ) break;
        }
        return array(
            'contract' => self::CONTRACT,
            'provider' => 'firecrawl',
            'scope' => 'site_control_plane',
            'enrolled_competitors' => $items,
            'ready_for_competitor_collection' => $admitted > 0,
            'pre_collection_legal_review_required' => true,
            'stages' => array(
                array( 'stage' => 'discover', 'ability' => 'mad4b/research-serp-record', 'provider' => 'separately certified SERP provider' ),
                array( 'stage' => 'select', 'ability' => 'mad4b/research-competitor-select', 'provider' => 'existing research artifacts' ),
                array( 'stage' => 'collect', 'ability' => self::COLLECT, 'provider' => 'private Firecrawl' ),
                array( 'stage' => 'retain_reviewed', 'ability' => 'mad4b/research-scrape-record', 'requires' => 'job + provider evidence + rights/robots clearance + write approval' ),
                array( 'stage' => 'content', 'ability' => 'mad4b/research-coverage-build', 'requires' => 'reviewed observations' ),
                array( 'stage' => 'seo', 'ability' => 'mad4b/research-information-gain-build', 'requires' => 'SERP and content evidence; no ranking claims from scrape alone' ),
                array( 'stage' => 'pricing', 'ability' => 'mad4b/competitor-research-plan', 'requires' => 'fresh price validation; no sales price mutation' ),
                array( 'stage' => 'editorial', 'ability' => 'mad4b/blueprint-build', 'requires' => 'independent content and editorial approvals' ),
            ),
            'scheduled' => false, 'mutation_performed' => false, 'published' => false,
        );
    }

    public static function observe( $input ) {
        if ( ! self::permitted() ) return self::fail( 'denied' );
        if ( ! is_array( $input ) || array_diff( array_keys( $input ), array( 'url', 'competitor_id', 'lane', 'demo' ) ) ) return self::fail( 'invalid_input' );
        $url = isset( $input['url'] ) ? $input['url'] : '';
        $host = self::https_host( $url );
        if ( '' === $host ) return self::fail( 'invalid_target' );
        $lane = isset( $input['lane'] ) ? $input['lane'] : 'all';
        if ( ! in_array( $lane, array( 'all', 'content', 'seo', 'pricing' ), true ) ) return self::fail( 'invalid_lane' );
        $demo = ! empty( $input['demo'] );
        $id = isset( $input['competitor_id'] ) ? (string) $input['competitor_id'] : '';
        if ( $demo ) {
            if ( '' !== $id || 'https://example.com' !== $url ) return self::fail( 'demo_target_invalid' );
        } else {
            if ( '' === $id || ! preg_match( '/^[a-z0-9][a-z0-9._-]{0,79}$/D', $id ) ) return self::fail( 'competitor_not_enrolled' );
            $profiles = self::competitors();
            if ( is_wp_error( $profiles ) ) return $profiles;
            $profile = isset( $profiles[ $id ] ) ? $profiles[ $id ] : null;
            if ( ! is_array( $profile ) || ! empty( $profile['disabled'] ) || self::https_host( $profile['source_url'] ?? '' ) !== $host ) return self::fail( 'competitor_domain_not_enrolled' );
        }
        if ( ! class_exists( 'MAD4B_Research_Firecrawl_Bridge' ) ) return self::fail( 'provider_missing', 503 );
        // The existing bridge re-checks exact domain allowlist, private credentials,
        // caller permissions and the scoped Docker/Sandbox transport exception.
        $result = MAD4B_Research_Firecrawl_Bridge::preview( array( 'url' => $url ) );
        if ( is_wp_error( $result ) ) return $result;
        if ( ! is_array( $result ) || ! isset( $result['preview'] ) || ! is_string( $result['preview'] ) ) return self::fail( 'provider_result_invalid', 503 );
        return self::normalize( $url, $id, $lane, $result );
    }

    public static function normalize( $url, $id, $lane, array $result ) {
        $markdown = substr( (string) ( $result['preview'] ?? '' ), 0, 6000 );
        $lines = preg_split( '/\r\n|\r|\n/', $markdown );
        $headings = array(); $questions = array();
        foreach ( $lines as $line ) {
            if ( preg_match( '/^(#{1,4})\s+(.+)$/u', $line, $m ) ) {
                $h = array( 'level' => strlen( $m[1] ), 'text' => mb_substr( trim( $m[2] ), 0, 160, 'UTF-8' ) );
                if ( count( $headings ) < 25 ) $headings[] = $h;
                if ( substr( $h['text'], -1 ) === '?' && count( $questions ) < 12 ) $questions[] = $h['text'];
            }
        }
        $prices = array();
        if ( preg_match_all( '/(?<![A-Za-z0-9])(?:USD|EUR|GBP|EGP|AED|SAR)\s*[0-9][0-9,.]{0,15}|[$€£]\s*[0-9][0-9,.]{0,15}/iu', $markdown, $matches, PREG_OFFSET_CAPTURE ) ) {
            foreach ( $matches[0] as $hit ) {
                if ( count( $prices ) >= 15 ) break;
                $prices[] = array(
                    'literal' => substr( $hit[0], 0, 36 ),
                    'context' => trim( substr( $markdown, max( 0, $hit[1] - 45 ), min( 110, strlen( $markdown ) - max( 0, $hit[1] - 45 ) ) ) ),
                    'state' => 'unverified_price_mention',
                    'amount_normalized' => false,
                    'comparison_ready' => false,
                );
            }
        }
        $word_count = count( preg_split( '/\s+/u', trim( $markdown ), -1, PREG_SPLIT_NO_EMPTY ) );
        $meta = isset( $result['metadata'] ) && is_array( $result['metadata'] ) ? $result['metadata'] : array();
        $seo = array( 'headings' => $headings, 'question_headings' => $questions,
            'title_candidate' => isset( $meta['title'] ) && is_string( $meta['title'] ) ? mb_substr( $meta['title'], 0, 200, 'UTF-8' ) : '',
            'meta_description_candidate' => isset( $meta['description'] ) && is_string( $meta['description'] ) ? mb_substr( $meta['description'], 0, 400, 'UTF-8' ) : '',
            'language_candidate' => isset( $meta['language'] ) && is_string( $meta['language'] ) ? mb_substr( $meta['language'], 0, 32, 'UTF-8' ) : '',
            'metadata_source' => 'firecrawl_unverified_page_metadata',
            'html_title_verified' => false, 'meta_description_verified' => false,
            'canonical_verified' => false, 'search_ranking_verified' => false );
        $content = array( 'word_count_estimate' => $word_count,
            'outline' => array_slice( $headings, 0, 20 ),
            'content_is_source_not_publication' => true );
        $pricing = array( 'price_mentions' => $prices,
            'currency_verified' => false, 'tax_inclusion_verified' => false,
            'availability_verified' => false, 'pricing_rule_applied' => false );
        return array(
            'contract' => self::CONTRACT,
            'provider' => 'firecrawl',
            'competitor_id' => $id, 'source_url' => $url, 'lane' => $lane,
            'collected_at' => gmdate( 'c' ),
            'extracted_sha256' => hash( 'sha256', $markdown ),
            'sample_truncated' => ! empty( $result['truncated'] ),
            'seo' => in_array( $lane, array( 'all', 'seo' ), true ) ? $seo : null,
            'content' => in_array( $lane, array( 'all', 'content' ), true ) ? $content : null,
            'pricing' => in_array( $lane, array( 'all', 'pricing' ), true ) ? $pricing : null,
            'evidence_state' => 'preliminary_unreviewed',
            'robots_policy_verdict' => 'not_verified',
            'external_source_rights' => 'not_verified',
            'requires_data_governance_artifact' => true,
            'proposed_next_ability' => 'mad4b/research-scrape-record',
            'retained_as_research_artifact' => false,
            'used_to_change_website_price' => false,
            'published' => false, 'mutation_performed' => false,
        );
    }
}
MAD4B_Firecrawl_Research_Cycles::boot();
