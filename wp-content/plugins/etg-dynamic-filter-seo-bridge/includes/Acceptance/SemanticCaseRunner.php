<?php
namespace ETG\DynamicFilterSEOBridge\Acceptance;

final class SemanticCaseRunner {
    const CONTRACT = 'etg.dfsb.semantic-acceptance-case.v2';

    private $directEvaluator;
    private $ajaxEvaluator;
    private $queryEvaluator;
    private $normalizer;

    public function __construct( callable $directEvaluator, callable $ajaxEvaluator, SemanticQueryEvaluator $queryEvaluator, CanonicalStateNormalizer $normalizer ) {
        $this->directEvaluator = $directEvaluator;
        $this->ajaxEvaluator = $ajaxEvaluator;
        $this->queryEvaluator = $queryEvaluator;
        $this->normalizer = $normalizer;
    }

    public function run( array $profile, array $case ): array {
        $route = array( 'provider'=>$case['provider'], 'query_id'=>$case['query_id'], 'provider_query_id'=>$case['query_id'] );
        $candidate = array( 'taxonomy'=>$case['taxonomy'], 'term_id'=>$case['term_id'], 'term_slug'=>$case['term_slug'] );
        $directUri = $this->normalizer->directUri( $profile, $route, $candidate );
        $ajaxPayload = $this->normalizer->ajaxPayload( $profile, $route, $candidate );
        if ( '' === $directUri ) return $this->blocked( $case, 'state_encoder_unavailable' );
        try {
            $directContext = call_user_func( $this->directEvaluator, $directUri );
            $ajaxContext = call_user_func( $this->ajaxEvaluator, $ajaxPayload );
        } catch ( \Throwable $e ) { return $this->blocked( $case, 'context_evaluator_exception' ); }
        if ( ! is_array( $directContext ) || ! is_array( $ajaxContext ) ) return $this->blocked( $case, 'context_evaluator_unavailable' );

        $directState = $this->normalizer->context( $directContext );
        $ajaxState = $this->normalizer->context( $ajaxContext );
        $directQuery = $this->normalizer->directFilteredQuery( $directState );
        $ajaxQuery = ! empty( $ajaxState['filtered_query'] ) && is_array( $ajaxState['filtered_query'] ) ? $ajaxState['filtered_query'] : $this->normalizer->directFilteredQuery( $ajaxState );
        $directDataset = $this->queryEvaluator->evaluate( $directState, $profile, $directQuery );
        $ajaxDataset = $this->queryEvaluator->evaluate( $ajaxState, $profile, $ajaxQuery );

        $stateParity = $this->normalizer->semanticStateEqual( $directState, $ajaxState );
        $routeBinding = $this->routeMatches( $case, $directState ) && $this->routeMatches( $case, $ajaxState );
        $scopeReady = $this->stateReady( $directState ) && $this->stateReady( $ajaxState );
        $seoNonAuthority = empty( $directState['authorizing'] ) && empty( $ajaxState['authorizing'] ) && empty( $ajaxState['url_authority'] );
        $datasetsReady = 'ok' === (string) ( $directDataset['state'] ?? '' ) && 'ok' === (string) ( $ajaxDataset['state'] ?? '' );
        $countParity = $datasetsReady ? ( (int) $directDataset['total'] === (int) $ajaxDataset['total'] ) : null;
        $proofComplete = $datasetsReady && ! empty( $directDataset['proof_complete'] ) && ! empty( $ajaxDataset['proof_complete'] );
        $idsParity = null; $orderParity = null;
        if ( $proofComplete ) {
            $idsParity = hash_equals(
                (string) ( $directDataset['identity_digest'] ?? '' ),
                (string) ( $ajaxDataset['identity_digest'] ?? '' )
            );
            $orderParity = hash_equals(
                (string) ( $directDataset['order_digest'] ?? '' ),
                (string) ( $ajaxDataset['order_digest'] ?? '' )
            );
        }

        $blocking = array();
        if ( ! $routeBinding ) $blocking[] = 'route_binding_mismatch';
        if ( ! $scopeReady ) $blocking[] = 'semantic_scope_not_ready';
        foreach ( array( $directDataset, $ajaxDataset ) as $dataset ) foreach ( (array) ( $dataset['blocking_reasons'] ?? array() ) as $reason ) $blocking[] = (string) $reason;

        $infrastructureFailures = array();
        foreach ( array( 'direct'=>$directDataset, 'ajax'=>$ajaxDataset ) as $side => $dataset ) {
            if ( empty( $dataset['infrastructure_failure'] ) ) continue;
            $reason = (string) ( $dataset['pagination_failure'] ?? 'dataset_pagination_infrastructure_failure' );
            $infrastructureFailures[] = $side . ':' . ( $reason ?: 'dataset_pagination_infrastructure_failure' );
        }

        $incomplete = $datasetsReady && ! $proofComplete && ! $infrastructureFailures ? array( 'dataset_proof_incomplete' ) : array();
        $defects = array();
        if ( ! $stateParity ) $defects[] = 'semantic_state_divergence';
        if ( false === $countParity ) $defects[] = 'result_count_divergence';
        if ( false === $idsParity ) $defects[] = 'dataset_identity_divergence';
        if ( false === $orderParity ) $defects[] = 'dataset_order_divergence';
        if ( ! $seoNonAuthority ) $defects[] = 'seo_non_authority_violation';
        if ( ! $routeBinding ) $defects[] = 'route_binding_mismatch';
        if ( $defects ) { $verdict='FAIL'; $classification='PRODUCT_DEFECT'; }
        elseif ( $infrastructureFailures ) { $verdict='BLOCKED'; $classification='TEST_INFRASTRUCTURE_FAILURE'; }
        elseif ( $blocking ) { $verdict='BLOCKED'; $classification='ENVIRONMENT_OR_PROVIDER_BLOCK'; }
        elseif ( $incomplete ) { $verdict='INCOMPLETE_EVIDENCE'; $classification='OBSERVATION_GAP'; }
        else { $verdict='PASS'; $classification='NO_CONFIRMED_DEFECT'; }

        return array(
            'contract'=>self::CONTRACT,
            'case_id'=>(string)($case['case_id']??''), 'taxonomy'=>(string)$case['taxonomy'], 'term_id'=>(int)$case['term_id'], 'term_slug'=>(string)$case['term_slug'],
            'provider'=>(string)$case['provider'], 'query_id'=>(string)$case['query_id'], 'selection_reason'=>(string)($case['selection_reason']??''),
            'direct_total'=>$datasetsReady?(int)$directDataset['total']:null, 'ajax_total'=>$datasetsReady?(int)$ajaxDataset['total']:null, 'provider_total'=>$datasetsReady?(int)$directDataset['total']:null,
            'ids_parity'=>$idsParity, 'order_parity'=>$orderParity, 'state_parity'=>$stateParity, 'result_count_parity'=>$countParity,
            'provider_binding_parity'=>$routeBinding, 'seo_non_authority'=>$seoNonAuthority,
            'semantic_direct_state_digest'=>(string)($directState['state_digest']??''), 'semantic_ajax_state_digest'=>(string)($ajaxState['state_digest']??''),
            'direct_dataset'=>$this->datasetEvidence($directDataset), 'ajax_dataset'=>$this->datasetEvidence($ajaxDataset),
            'blocking_reasons'=>array_values(array_unique($blocking)), 'incomplete_evidence'=>array_values(array_unique($incomplete)), 'defect_reasons'=>array_values(array_unique($defects)),
            'infrastructure_failures'=>array_values(array_unique($infrastructureFailures)),
            'classification'=>$classification, 'verdict'=>$verdict,
        );
    }

    private function blocked( array $case, string $reason ): array {
        return array(
            'contract'=>self::CONTRACT, 'case_id'=>(string)($case['case_id']??''), 'taxonomy'=>(string)($case['taxonomy']??''), 'term_id'=>(int)($case['term_id']??0), 'term_slug'=>(string)($case['term_slug']??''),
            'provider'=>(string)($case['provider']??''), 'query_id'=>(string)($case['query_id']??''), 'selection_reason'=>(string)($case['selection_reason']??''),
            'direct_total'=>null, 'ajax_total'=>null, 'provider_total'=>null, 'ids_parity'=>null, 'order_parity'=>null, 'state_parity'=>null, 'result_count_parity'=>null,
            'provider_binding_parity'=>false, 'seo_non_authority'=>true, 'direct_dataset'=>array(), 'ajax_dataset'=>array(),
            'blocking_reasons'=>array($reason), 'incomplete_evidence'=>array(), 'defect_reasons'=>array(), 'infrastructure_failures'=>array(), 'classification'=>'ENVIRONMENT_OR_PROVIDER_BLOCK', 'verdict'=>'BLOCKED',
        );
    }

    private function datasetEvidence( array $dataset ): array {
        return array(
            'contract'=>(string)($dataset['contract']??''), 'state'=>(string)($dataset['state']??''), 'total'=>$dataset['total']??null, 'ids'=>array_values((array)($dataset['ids']??array())),
            'ids_complete'=>!empty($dataset['ids_complete']), 'ids_scope'=>(string)($dataset['ids_scope']??''), 'ids_reason'=>(string)($dataset['ids_reason']??''),
            'proof_mode'=>(string)($dataset['proof_mode']??''), 'proof_complete'=>!empty($dataset['proof_complete']), 'proof_item_count'=>(int)($dataset['proof_item_count']??0),
            'identity_digest'=>(string)($dataset['identity_digest']??''), 'order_digest'=>(string)($dataset['order_digest']??''),
            'collection_mode'=>(string)($dataset['collection_mode']??''), 'items_per_page'=>$dataset['items_per_page']??null, 'page_fetches'=>(int)($dataset['page_fetches']??0),
            'page_signatures'=>array_values((array)($dataset['page_signatures']??array())), 'raw_id_count'=>(int)($dataset['raw_id_count']??0), 'unique_id_count'=>(int)($dataset['unique_id_count']??0),
            'pagination_failure'=>(string)($dataset['pagination_failure']??''), 'infrastructure_failure'=>!empty($dataset['infrastructure_failure']),
            'max_ids'=>(int)($dataset['max_ids']??0), 'max_digest_ids'=>(int)($dataset['max_digest_ids']??0), 'max_page_fetches'=>(int)($dataset['max_page_fetches']??0),
            'binding'=>(array)($dataset['binding']??array()), 'blocking_reasons'=>array_values((array)($dataset['blocking_reasons']??array())),
        );
    }

    private function stateReady( array $state ): bool {
        return !empty($state['in_scope']) && !empty($state['scope_valid']) && !empty($state['runtime_ready'])
            && !empty($state['presentation_state_complete']) && !empty($state['filtered_query_complete'])
            && empty($state['missing_terms']) && empty($state['translation_fallback']) && !empty($state['post_type_binding_matches_profile']);
    }

    private function routeMatches( array $case, array $state ): bool {
        return (string)($case['provider']??'') === (string)($state['provider']??'')
            && (string)($case['query_id']??'') === (string)($state['query_id']??'')
            && (string)($case['profile_id']??'') === (string)($state['profile_id']??'');
    }
}
