<?php
namespace ETG\DynamicFilterSEOBridge\SEO;

require_once dirname( __DIR__ ) . '/Runtime/RuntimeQueryBindingResolver.php';
require_once dirname( __DIR__ ) . '/Identifiers/QueryId.php';

use ETG\DynamicFilterSEOBridge\Identifiers\QueryId;
use ETG\DynamicFilterSEOBridge\JetEngine\QueryIdentityResolver;
use ETG\DynamicFilterSEOBridge\Runtime\RuntimeQueryBindingResolver;

final class PublicationResultCountProbe {
    private $bindingResolver;

    public function __construct( $resolver = null ) {
        if ( $resolver instanceof RuntimeQueryBindingResolver ) { $this->bindingResolver = $resolver; }
        elseif ( $resolver instanceof QueryIdentityResolver ) { $this->bindingResolver = new RuntimeQueryBindingResolver( null, $resolver ); }
        else { $this->bindingResolver = new RuntimeQueryBindingResolver(); }
    }

    public function resolve( array $context ): array {
        $provider = sanitize_key( (string) ( $context['provider'] ?? '' ) );
        if ( 'jet-engine' !== $provider ) { return $this->unavailable( 'unsupported_provider' ); }
        $providerQueryId = QueryId::normalize( $context['query_id'] ?? '' );
        if ( '' === $providerQueryId ) { return $this->unavailable( 'missing_query_id' ); }
        $filters = (array) ( $context['filters'] ?? array() );
        if ( ! $filters ) { return $this->unavailable( 'missing_filters' ); }
        $language = sanitize_key( (string) ( $context['language'] ?? '' ) );
        if ( ! class_exists( '\\WP_Query' ) ) { return $this->unavailable( 'wp_query_unavailable', $language, $providerQueryId ); }

        $binding = $this->bindingResolver->resolve( $provider, $providerQueryId, (array) ( $context['profile'] ?? array() ) );
        $customId = (string) ( $binding['query_builder_custom_query_id'] ?? '' );
        $internalId = (string) ( $binding['query_builder_internal_id'] ?? '' );
        if ( empty( $binding['resolved'] ) || ! is_object( $binding['query'] ?? null ) ) {
            return $this->unavailable( (string) ( $binding['reason'] ?? 'query_identity_inventory_unavailable' ), $language, $providerQueryId, $customId, $internalId );
        }
        $query = $binding['query'];

        $switched = false;
        $previousLanguage = '';
        $sitepressObject = null;

        try {
            $wpmlCurrentAvailable = function_exists( 'has_filter' ) && false !== has_filter( 'wpml_current_language' ) && function_exists( 'apply_filters' );
            if ( $language && ! $wpmlCurrentAvailable ) { return $this->unavailable( 'wpml_language_context_unavailable', $language, $providerQueryId, $customId, $internalId ); }
            if ( $wpmlCurrentAvailable ) {
                $previousLanguage = sanitize_key( (string) apply_filters( 'wpml_current_language', null ) );
                if ( $language && $previousLanguage !== $language ) {
                    global $sitepress;
                    if ( ! is_object( $sitepress ) || ! method_exists( $sitepress, 'switch_lang' ) ) { return $this->unavailable( 'wpml_language_switch_unavailable', $language, $providerQueryId, $customId, $internalId ); }
                    $sitepressObject = $sitepress;
                    $sitepressObject->switch_lang( $language, true );
                    $switched = true;
                }
            }

            $type = method_exists( $query, 'get_query_type' ) ? sanitize_key( (string) $query->get_query_type() ) : '';
            if ( 'posts' !== $type ) { return $this->unavailable( '' === $type ? 'query_type_unobserved' : 'query_type_not_posts', $language, $providerQueryId, $customId, $internalId ); }
            if ( ! method_exists( $query, 'get_query_args' ) ) { return $this->unavailable( 'query_args_unavailable', $language, $providerQueryId, $customId, $internalId ); }
            $query = clone $query;
            $args = $query->get_query_args();
            if ( ! is_array( $args ) ) { return $this->unavailable( 'query_args_invalid', $language, $providerQueryId, $customId, $internalId ); }
            $postTypes = $this->postTypes( $args['post_type'] ?? null );
            if ( ! $postTypes ) { return $this->unavailable( 'post_type_unbounded', $language, $providerQueryId, $customId, $internalId ); }

            $taxClauses = array();
            foreach ( $filters as $taxonomy => $slug ) {
                $taxonomy = sanitize_key( (string) $taxonomy );
                $slug = sanitize_title( (string) $slug );
                if ( '' === $taxonomy || '' === $slug || ! taxonomy_exists( $taxonomy ) ) { return $this->unavailable( 'invalid_taxonomy_filter', $language, $providerQueryId, $customId, $internalId ); }
                $term = get_term_by( 'slug', $slug, $taxonomy );
                if ( ! $term || is_wp_error( $term ) ) { return $this->unavailable( 'term_not_found', $language, $providerQueryId, $customId, $internalId ); }
                $taxClauses[] = array( 'taxonomy'=>$taxonomy, 'field'=>'slug', 'terms'=>array($slug), 'operator'=>'IN' );
            }
            if ( ! $taxClauses ) { return $this->unavailable( 'tax_query_empty', $language, $providerQueryId, $customId, $internalId ); }
            $existing = isset( $args['tax_query'] ) && is_array( $args['tax_query'] ) ? $args['tax_query'] : array();
            if ( $existing ) { $merged = array( 'relation'=>'AND', $existing ); foreach ( $taxClauses as $clause ) { $merged[] = $clause; } $args['tax_query'] = $merged; }
            else { $args['tax_query'] = count($taxClauses)>1 ? array_merge(array('relation'=>'AND'),$taxClauses) : $taxClauses; }
            $args['posts_per_page']=1; $args['paged']=1; $args['fields']='ids'; $args['no_found_rows']=false; $args['ignore_sticky_posts']=true; $args['suppress_filters']=false; unset($args['offset']);
            $wpQuery = new \WP_Query( $args );
            $count = isset($wpQuery->found_posts) ? $wpQuery->found_posts : null;
            if ( ! is_numeric( $count ) ) { return $this->unavailable( 'non_numeric_count', $language, $providerQueryId, $customId, $internalId ); }
            $result = array(
                'count'=>max(0,(int)$count), 'source'=>'jet_engine_query_builder_background_tax_query', 'authoritative'=>true,
                'detail'=>'query_builder_base_args_plus_exact_taxonomy_filters_with_language_context', 'post_types'=>$postTypes,
                'language'=>$language, 'wpml_language_context'=>$switched?'switched':'already_current',
                'provider_query_id'=>$providerQueryId, 'custom_query_id'=>$customId, 'query_builder_custom_query_id'=>$customId,
                'internal_query_id'=>$internalId, 'binding_source'=>(string)($binding['source']??'unavailable'), 'query_identity_source'=>(string)($binding['identity_source']??'unavailable')
            );
            return function_exists('apply_filters') ? (array) apply_filters('etg_filter_seo_publication_result_count',$result,$context,$args) : $result;
        } catch ( \Throwable $error ) {
            return $this->unavailable( 'publication_count_exception', $language, $providerQueryId, $customId, $internalId );
        } finally {
            if ( $switched && is_object($sitepressObject) && method_exists($sitepressObject,'switch_lang') && $previousLanguage ) {
                try { $sitepressObject->switch_lang($previousLanguage,true); } catch ( \Throwable $ignored ) {}
            }
        }
    }

    private function postTypes( $value ): array {
        $items = is_array($value) ? $value : (is_scalar($value)?array($value):array());
        $out = array();
        foreach ( $items as $item ) { $item=sanitize_key((string)$item); if(''===$item||'any'===$item){return array();} $out[]=$item; }
        $out=array_values(array_unique(array_filter($out))); sort($out,SORT_STRING); return $out;
    }

    private function unavailable( string $detail, string $language='', string $providerQueryId='', string $customQueryId='', string $internalQueryId='' ): array {
        return array(
            'count'=>null,'source'=>'unavailable','authoritative'=>false,'detail'=>$detail,'post_types'=>array(),'language'=>$language,'wpml_language_context'=>'unavailable',
            'provider_query_id'=>$providerQueryId,'custom_query_id'=>$customQueryId,'query_builder_custom_query_id'=>$customQueryId,'internal_query_id'=>$internalQueryId
        );
    }
}
