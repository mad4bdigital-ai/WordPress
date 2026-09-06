<?php
namespace ETG\DynamicFilterSEOBridge\Presentation;

use ETG\DynamicFilterSEOBridge\Context\FilterContextBuilder;

final class AjaxPresentationEndpoint {
    const CONTRACT = 'etg.dfsb.ajax-presentation.v1';
    const MAX_TOKENS = 100;
    const MAX_SLOTS = 50;
    const MAX_PAYLOAD_BYTES = 65536;

    private $builder;
    private $resolver;
    private $slots;
    private $catalogProvider;
    private $catalogTokens = null;

    public function __construct( FilterContextBuilder $builder, PresentationResolver $resolver, ContentSlotRegistry $slots, callable $catalogProvider = null ) {
        $this->builder = $builder;
        $this->resolver = $resolver;
        $this->slots = $slots;
        $this->catalogProvider = $catalogProvider;
    }

    public function register(): void {
        add_action( 'rest_api_init', array( $this, 'routes' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'assets' ), 30 );
    }

    public function routes(): void {
        register_rest_route( 'etg-dfsb/v1', '/ajax-presentation', array(
            'methods' => 'POST',
            'callback' => array( $this, 'handle' ),
            'permission_callback' => '__return_true',
        ) );
    }

    public function assets(): void {
        if ( is_admin() || ! function_exists( 'jet_smart_filters' ) ) { return; }
        $src = plugins_url( 'assets/js/ajax-filter-state.js', ETG_DFSB_DIR . 'etg-dynamic-filter-seo-bridge.php' );
        wp_enqueue_script( 'etg-dfsb-ajax-filter-state', $src, array(), ETG_DFSB_VERSION, true );
        wp_localize_script( 'etg-dfsb-ajax-filter-state', 'ETGDFSB_AJAX', array(
            'endpoint' => esc_url_raw( rest_url( 'etg-dfsb/v1/ajax-presentation' ) ),
            'contract' => self::CONTRACT,
            'maxTokens' => self::MAX_TOKENS,
            'maxSlots' => self::MAX_SLOTS,
        ) );
    }

    public function handle( $request ) {
        $params = is_object( $request ) && method_exists( $request, 'get_json_params' ) ? $request->get_json_params() : array();
        $params = is_array( $params ) ? $params : array();
        $encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( $params ) : json_encode( $params );
        if ( ! is_string( $encoded ) || strlen( $encoded ) > self::MAX_PAYLOAD_BYTES ) {
            return $this->response( 'blocked', array(), array(), array( 'payload_too_large' ) );
        }

        $context = $this->builder->buildAjaxEvidence( $params );
        $blocking = $this->contextBlockingReasons( $context );
        if ( $blocking ) {
            return $this->response( 'blocked', array(), $context, $blocking );
        }

        $profile = (array) ( $context['profile'] ?? array() );
        $publication = (array) ( $profile['publication'] ?? array() );
        if ( empty( $context['global_enabled'] ) && empty( $publication['elementor_render_when_global_off'] ) ) {
            return $this->response( 'blocked_dark_rendering', array(), $context, array( 'elementor_render_when_global_off_disabled' ) );
        }

        $tokenResult = $this->tokenList( $params['tokens'] ?? array(), $context );
        if ( ! empty( $tokenResult['rejected'] ) ) {
            return $this->response( 'blocked', array(), $context, array( 'token_not_allowlisted' ) );
        }
        $slotResult = $this->slotList( $params['slots'] ?? array() );
        if ( ! empty( $slotResult['rejected'] ) ) {
            return $this->response( 'blocked', array(), $context, array( 'slot_not_allowlisted' ) );
        }

        $values = array( 'tokens' => array(), 'slots' => array() );
        foreach ( $tokenResult['allowed'] as $token ) {
            $value = $this->resolver->value( $token, $context );
            if ( is_array( $value ) || is_object( $value ) ) { continue; }
            $values['tokens'][ $token ] = array( 'value' => (string) $value, 'type' => $this->tokenType( $token ) );
        }
        foreach ( $slotResult['allowed'] as $slotId ) {
            $values['slots'][ $slotId ] = array(
                'value' => $this->resolver->slot( $slotId, $context ),
                'type' => $this->resolver->slotType( $slotId ),
            );
        }
        return $this->response( 'ready', $values, $context, array() );
    }

    private function contextBlockingReasons( array $context ): array {
        $reasons = array();
        if ( empty( $context['active'] ) ) { $reasons[] = 'ajax_state_inactive'; }
        if ( empty( $context['in_scope'] ) ) { $reasons[] = (string) ( $context['scope']['reason'] ?? 'ajax_state_out_of_scope' ); }
        if ( isset( $context['scope_valid'] ) && empty( $context['scope_valid'] ) ) { $reasons[] = 'scope_invalid'; }
        if ( empty( $context['runtime_ready'] ) ) { $reasons[] = 'runtime_not_ready'; }
        if ( empty( $context['provider_observed'] ) || empty( $context['provider_observation_matches_state'] ) ) { $reasons[] = 'provider_state_mismatch'; }
        if ( ! empty( $context['unknown_filters'] ) ) { $reasons[] = 'unknown_filter'; }
        if ( ! empty( $context['malformed'] ) ) { $reasons[] = 'malformed_filter'; }
        if ( ! empty( $context['unsupported_filter_props'] ) || ( array_key_exists( 'filtered_query_complete', $context ) && empty( $context['filtered_query_complete'] ) ) ) { $reasons[] = 'unsupported_filter_state'; }
        if ( ! empty( $context['missing_terms'] ) ) { $reasons[] = 'missing_term'; }
        if ( ! empty( $context['translation_fallback'] ) ) { $reasons[] = 'translation_fallback'; }
        if ( ! empty( $context['authorizing'] ) || ! empty( $context['url_authority'] ) || empty( $context['ajax_only'] ) ) { $reasons[] = 'ajax_authority_boundary_violation'; }

        $profile = (array) ( $context['profile'] ?? array() );
        if ( ! empty( $profile['require_post_type_binding'] ) ) {
            $binding = (array) ( $context['post_type_binding'] ?? array() );
            if ( empty( $binding['observed'] ) ) { $reasons[] = 'post_type_unobserved'; }
            elseif ( empty( $binding['matches_profile'] ) ) { $reasons[] = 'post_type_mismatch'; }
        }
        return array_values( array_unique( array_filter( $reasons ) ) );
    }

    private function response( string $status, array $values, array $context, array $reasons ) {
        $body = array(
            'contract' => self::CONTRACT,
            'status' => $status,
            'authorizing' => false,
            'url_authority' => false,
            'seo_mutation' => false,
            'state_transport' => 'ajax',
            'provider' => (string) ( $context['provider'] ?? '' ),
            'query_id' => (string) ( $context['query_id'] ?? '' ),
            'profile_id' => (string) ( $context['profile_id'] ?? '' ),
            'filters' => (array) ( $context['filter_values'] ?? $context['filters'] ?? array() ),
            'filtered_query_complete' => ! empty( $context['filtered_query_complete'] ),
            'result_count' => $context['result_count'] ?? null,
            'result_count_source' => (string) ( $context['result_count_source'] ?? 'unavailable' ),
            'result_count_authoritative' => ! empty( $context['result_count_authoritative'] ),
            'values' => $values,
            'blocking_reasons' => array_values( array_unique( array_filter( array_map( 'sanitize_key', $reasons ) ) ) ),
        );
        if ( function_exists( 'rest_ensure_response' ) ) {
            $response = rest_ensure_response( $body );
            if ( is_object( $response ) && method_exists( $response, 'header' ) ) {
                $response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
                $response->header( 'X-Robots-Tag', 'noindex, nofollow, noarchive' );
            }
            return $response;
        }
        return $body;
    }

    private function tokenList( $value, array $context ): array {
        $allowed = array();
        $rejected = array();
        foreach ( array_slice( is_array( $value ) ? $value : array(), 0, self::MAX_TOKENS ) as $token ) {
            $token = strtolower( trim( (string) $token ) );
            if ( ! preg_match( '/^[a-z0-9_:\-]+$/', $token ) || ! $this->tokenAllowed( $token, $context ) ) {
                if ( '' !== $token ) { $rejected[] = $token; }
                continue;
            }
            $allowed[] = $token;
        }
        return array('allowed'=>array_values(array_unique($allowed)),'rejected'=>array_values(array_unique($rejected)));
    }

    private function tokenAllowed( string $token, array $context ): bool {
        if ( in_array( $token, array( 'title','intro','keyword','result_count','result_summary','breadcrumb','gallery_ids','image_id','image_url' ), true ) ) { return true; }
        if ( 0 === strpos( $token, 'context:' ) ) { return in_array( substr( $token, 8 ), array( 'language','profile_id','provider','query_id','query_builder_query_id','state_transport','ajax_only' ), true ); }
        if ( 0 === strpos( $token, 'url:' ) ) { return in_array( substr( $token, 4 ), array( 'current','archive' ), true ); }

        $profile=(array)($context['profile']??array());$roles=array();
        foreach((array)($profile['taxonomy_rules']??array()) as $taxonomy=>$rule){$role=sanitize_key((string)(is_array($rule)?($rule['role']??$taxonomy):$taxonomy));if(''!==$role){$roles[$role]=true;}}
        if(0===strpos($token,'term:')){$parts=explode(':',$token,3);return 3===count($parts)&&isset($roles[sanitize_key($parts[1])])&&in_array(sanitize_key($parts[2]),array('name','slug','description','short_description','seo_title','meta_description','focus_keyword','image_id','image_url','count','location_level'),true);}
        if(0===strpos($token,'terms:')){$parts=explode(':',$token,3);return 3===count($parts)&&isset($roles[sanitize_key($parts[1])])&&in_array(sanitize_key($parts[2]),array('names','slugs','count','descriptions','short_descriptions','seo_titles','meta_descriptions','focus_keywords'),true);}
        if(0===strpos($token,'termmeta:')){$parts=explode(':',$token,3);return 3===count($parts)&&isset($roles[sanitize_key($parts[1])])&&$this->catalogContains($token);}
        if(0===strpos($token,'topology:')){$parts=explode(':',$token,3);return 3===count($parts)&&sanitize_key($parts[1])===sanitize_key((string)($context['query_id']??''))&&'query_builder_query_id'===sanitize_key($parts[2])&&$this->catalogContains($token);}
        return false;
    }

    private function catalogContains( string $token ): bool {
        if(null===$this->catalogTokens){$this->catalogTokens=array();if($this->catalogProvider){try{$catalog=call_user_func($this->catalogProvider);foreach(array_keys((array)($catalog['tokens']??array())) as $candidate){$candidate=strtolower(trim((string)$candidate));if(''!==$candidate){$this->catalogTokens[$candidate]=true;}}}catch(\Throwable $error){$this->catalogTokens=array();}}}
        return isset($this->catalogTokens[$token]);
    }

    private function slotList( $value ): array {
        $allowed=array();$rejected=array();
        foreach(array_slice(is_array($value)?$value:array(),0,self::MAX_SLOTS) as $id){$id=sanitize_key((string)$id);if(''===$id){continue;}$slot=$this->slots->get($id);if(!$slot||empty($slot['enabled'])){$rejected[]=$id;continue;}$allowed[]=$id;}
        return array('allowed'=>array_values(array_unique($allowed)),'rejected'=>array_values(array_unique($rejected)));
    }

    private function tokenType( string $token ): string {
        if('intro'===$token||false!==strpos($token,':description')){return'html';}
        if('image_url'===$token||0===strpos($token,'url:')||false!==strpos($token,':image_url')){return'url';}
        if('image_id'===$token||false!==strpos($token,':image_id')){return'image';}
        return'text';
    }
}
