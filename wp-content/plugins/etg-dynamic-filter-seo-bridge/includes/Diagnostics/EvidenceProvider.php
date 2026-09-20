<?php
namespace ETG\DynamicFilterSEOBridge\Diagnostics;

require_once dirname( __DIR__ ) . '/Identifiers/QueryId.php';

use ETG\DynamicFilterSEOBridge\Identifiers\QueryId;

/**
 * Bounded, non-authorizing evidence adapter for central diagnostic transports.
 *
 * This class deliberately owns no transport, authentication, REST route, MCP
 * ability, approval, or mutation surface. It projects the canonical ETG runtime
 * inventory/reconciliation into small versioned responses that a central
 * transport can page, export, or materialize safely.
 */
final class EvidenceProvider {
    const CONTRACT = 'etg.dfsb.evidence-provider.v1';
    const PROVIDER_ID = 'etg-dfsb';
    const DEFAULT_PAGE_SIZE = 25;
    const MAX_PAGE_SIZE = 50;
    const MAX_FILTER_IDS = 50;

    private $snapshotProvider;
    private $reconciliationProvider;
    private $profilesProvider;
    private $snapshotCache = null;
    private $reconciliationCache = null;
    private $profilesCache = null;
    private $snapshotError = '';
    private $reconciliationError = '';
    private $profilesError = '';

    public function __construct( callable $snapshotProvider, callable $reconciliationProvider, callable $profilesProvider ) {
        $this->snapshotProvider = $snapshotProvider;
        $this->reconciliationProvider = $reconciliationProvider;
        $this->profilesProvider = $profilesProvider;
    }

    /**
     * Register only provider discovery hooks. The central MCP/Control Plane owns
     * transport, permissions, pagination orchestration, export and materialization.
     */
    public function register(): void {
        if ( ! function_exists( 'add_filter' ) ) { return; }
        add_filter( 'mad4b_mcp_evidence_providers', array( $this, 'registerCentralProvider' ), 10, 1 );
        add_filter( 'etg_dfsb_evidence_provider', array( $this, 'exposeNativeProvider' ), 10, 1 );
    }

    public function registerCentralProvider( $providers ): array {
        $providers = is_array( $providers ) ? $providers : array();
        $providers[ self::PROVIDER_ID ] = array(
            'provider_id' => self::PROVIDER_ID,
            'contract' => self::CONTRACT,
            'read_only' => true,
            'authorizing' => false,
            'profile_mutation' => false,
            'descriptor_callback' => array( $this, 'descriptor' ),
            'query_callback' => array( $this, 'query' ),
        );
        return $providers;
    }

    public function exposeNativeProvider( $provider = null ) {
        unset( $provider );
        return $this;
    }

    public function descriptor(): array {
        return array(
            'contract' => self::CONTRACT,
            'provider_id' => self::PROVIDER_ID,
            'authorizing' => false,
            'read_only' => true,
            'profile_mutation' => false,
            'transport_owned_by_provider' => false,
            'sections' => array(
                'summary',
                'unresolved_surfaces',
                'filters',
                'profile_reconciliation',
                'provider_group_drift',
            ),
            'limits' => array(
                'default_page_size' => self::DEFAULT_PAGE_SIZE,
                'max_page_size' => self::MAX_PAGE_SIZE,
                'max_filter_ids' => self::MAX_FILTER_IDS,
            ),
        );
    }

    /**
     * Query one bounded evidence section.
     *
     * Supported request shapes:
     * - ['section'=>'summary']
     * - ['section'=>'unresolved_surfaces','offset'=>0,'limit'=>25]
     * - ['section'=>'filters','filter_ids'=>[15032,16084],'offset'=>0,'limit'=>50]
     * - ['section'=>'profile_reconciliation','profile_id'=>'tours','offset'=>0,'limit'=>25]
     * - ['section'=>'provider_group_drift','template_id'=>44320,'node_id'=>'b417678','offset'=>0,'limit'=>25]
     */
    public function query( array $request ): array {
        $section = $this->cleanKey( $request['section'] ?? 'summary' );
        $allowed = array( 'summary', 'unresolved_surfaces', 'filters', 'profile_reconciliation', 'provider_group_drift' );
        if ( ! in_array( $section, $allowed, true ) ) {
            return $this->envelope( $section, 'invalid_request', array(), array( 'unsupported_section' ) );
        }

        $snapshot = $this->snapshot();
        if ( ! $snapshot ) {
            $reason = '' !== $this->snapshotError ? $this->snapshotError : 'runtime_inventory_unavailable';
            return $this->envelope( $section, 'provider_unavailable', array(), array( $reason ) );
        }

        switch ( $section ) {
            case 'summary':
                $payload = $this->summaryPayload( $snapshot );
                break;
            case 'unresolved_surfaces':
                $payload = $this->unresolvedPayload( $snapshot, $request );
                break;
            case 'filters':
                $payload = $this->filtersPayload( $snapshot, $request );
                if ( isset( $payload['error'] ) ) {
                    return $this->envelope( $section, 'invalid_request', $payload, array( (string) $payload['error'] ), $snapshot );
                }
                break;
            case 'profile_reconciliation':
                $payload = $this->profileReconciliationPayload( $snapshot, $request );
                if ( isset( $payload['error'] ) ) {
                    $state = 'provider_unavailable' === (string) ( $payload['error_state'] ?? '' ) ? 'provider_unavailable' : 'invalid_request';
                    return $this->envelope( $section, $state, $payload, array( (string) $payload['error'] ), $snapshot );
                }
                break;
            case 'provider_group_drift':
                $payload = $this->providerGroupDriftPayload( $snapshot, $request );
                break;
            default:
                $payload = array();
        }

        return $this->envelope( $section, 'ok', $payload, array(), $snapshot );
    }

    private function summaryPayload( array $snapshot ): array {
        $inventory = (array) ( $snapshot['inventory'] ?? array() );
        $filters = (array) ( $inventory['jet_smart_filters'] ?? array() );
        $topology = (array) ( $inventory['elementor_topology'] ?? array() );
        return array(
            'inventory_contract' => (string) ( $snapshot['contract'] ?? '' ),
            'inventory_evidence_complete' => ! empty( $snapshot['evidence_complete'] ),
            'availability_errors' => array_values( (array) ( $snapshot['availability_errors'] ?? array() ) ),
            'availability' => (array) ( $inventory['availability'] ?? array() ),
            'completeness' => (array) ( $inventory['completeness'] ?? array() ),
            'jet_smart_filters' => $this->pick( $filters, array(
                'contract','diagnostic_contract','authorizing','read_only','profile_mutation','available','cache_scope',
                'templates_scanned','elements_scanned','truncated','surface_count','candidate_surface_count','control_surface_count',
                'resolved_surface_count','unresolved_surface_count','identity_resolution_counts','identity_resolution_counts_truncated',
                'surfaces_truncated','definition_count','definition_available_count','definition_unavailable_count','definition_reason_counts',
                'definition_unavailable_truncated','evidence_complete','evidence_state','evidence_reasons','drift_count','drift_truncated',
                'topology_filter_surface_count','topology_parity_checked','topology_surface_parity'
            ) ),
            'elementor_topology' => $this->pick( $topology, array(
                'contract','authorizing','read_only','profile_mutation','available','templates_scanned','query_builder_records_observed',
                'elements_scanned','truncated','binding_count','bindings_truncated','query_surface_count','query_surfaces_truncated',
                'provider_group_drift_count','provider_group_drift_truncated','template_reference_count','template_references_truncated'
            ) ),
        );
    }

    private function unresolvedPayload( array $snapshot, array $request ): array {
        $filters = (array) ( (array) ( $snapshot['inventory'] ?? array() )['jet_smart_filters'] ?? array() );
        $items = array();
        foreach ( (array) ( $filters['surfaces'] ?? array() ) as $surface ) {
            if ( ! is_array( $surface ) || empty( $surface['filter_identity_expected'] ) ) { continue; }
            $status = $this->cleanKey( $surface['identity_resolution_status'] ?? '' );
            if ( 'resolved' === $status ) { continue; }
            $items[] = $this->surfaceRecord( $surface );
        }
        return $this->page( $items, $request, array(
            'source_unresolved_surface_count' => (int) ( $filters['unresolved_surface_count'] ?? 0 ),
            'source_surfaces_truncated' => ! empty( $filters['surfaces_truncated'] ),
            'identity_resolution_counts' => (array) ( $filters['identity_resolution_counts'] ?? array() ),
        ) );
    }

    private function filtersPayload( array $snapshot, array $request ): array {
        $selection = $this->filterIds( $request['filter_ids'] ?? ( $request['ids'] ?? array() ) );
        if ( '' !== (string) ( $selection['error'] ?? '' ) ) {
            return array(
                'error' => (string) $selection['error'],
                'max_filter_ids' => self::MAX_FILTER_IDS,
                'requested_unique_filter_ids' => (int) ( $selection['unique_count'] ?? 0 ),
            );
        }
        $ids = (array) ( $selection['ids'] ?? array() );
        if ( ! $ids ) { return array( 'error' => 'filter_ids_required', 'max_filter_ids' => self::MAX_FILTER_IDS, 'requested_unique_filter_ids' => 0 ); }

        $lookup = array_fill_keys( $ids, true );
        $filters = (array) ( (array) ( $snapshot['inventory'] ?? array() )['jet_smart_filters'] ?? array() );

        $surfaces = array();
        foreach ( (array) ( $filters['surfaces'] ?? array() ) as $surface ) {
            if ( ! is_array( $surface ) ) { continue; }
            $id = (int) ( $surface['filter_id'] ?? 0 );
            if ( $id > 0 && isset( $lookup[ $id ] ) ) { $surfaces[] = $this->surfaceRecord( $surface ); }
        }
        $paged = $this->page( $surfaces, $request );

        $definitionIssues = array();
        foreach ( (array) ( $filters['definition_unavailable'] ?? array() ) as $row ) {
            if ( ! is_array( $row ) ) { continue; }
            $id = (int) ( $row['filter_id'] ?? 0 );
            if ( $id > 0 && isset( $lookup[ $id ] ) ) { $definitionIssues[] = $row; }
        }

        $semanticDrift = array();
        foreach ( (array) ( $filters['drift'] ?? array() ) as $row ) {
            if ( ! is_array( $row ) ) { continue; }
            $id = (int) ( $row['filter_id'] ?? 0 );
            if ( $id > 0 && isset( $lookup[ $id ] ) ) { $semanticDrift[] = $row; }
        }

        $paged['requested_filter_ids'] = $ids;
        $paged['definition_issues'] = array_slice( $definitionIssues, 0, self::MAX_PAGE_SIZE );
        $paged['definition_issues_truncated'] = count( $definitionIssues ) > self::MAX_PAGE_SIZE;
        $paged['semantic_drift'] = array_slice( $semanticDrift, 0, self::MAX_PAGE_SIZE );
        $paged['semantic_drift_truncated'] = count( $semanticDrift ) > self::MAX_PAGE_SIZE;
        $paged['source_surfaces_truncated'] = ! empty( $filters['surfaces_truncated'] );
        return $paged;
    }

    private function profileReconciliationPayload( array $snapshot, array $request ): array {
        $profileId = $this->cleanKey( $request['profile_id'] ?? '' );
        if ( '' === $profileId ) { return array( 'error' => 'profile_id_required' ); }

        $profiles = $this->profiles();
        if ( '' !== $this->profilesError ) {
            return array( 'error' => $this->profilesError, 'error_state' => 'provider_unavailable', 'profile_id' => $profileId );
        }
        $profile = isset( $profiles[ $profileId ] ) && is_array( $profiles[ $profileId ] ) ? $profiles[ $profileId ] : array();
        if ( ! $profile ) { return array( 'error' => 'profile_not_found', 'profile_id' => $profileId ); }

        $reconciliation = $this->reconciliation( $snapshot, $profiles );
        if ( '' !== $this->reconciliationError || ! $reconciliation ) {
            $reason = '' !== $this->reconciliationError ? $this->reconciliationError : 'reconciliation_unavailable';
            return array( 'error' => $reason, 'error_state' => 'provider_unavailable', 'profile_id' => $profileId );
        }

        $scope = 'profile:' . $profileId;
        $findings = array();
        foreach ( (array) ( $reconciliation['findings'] ?? array() ) as $finding ) {
            if ( ! is_array( $finding ) ) { continue; }
            if ( $scope === (string) ( $finding['scope'] ?? '' ) ) { $findings[] = $finding; }
        }
        $paged = $this->page( $findings, $request );

        $routeIds = array();
        foreach ( (array) ( $profile['routes'] ?? array() ) as $route ) {
            if ( ! is_array( $route ) ) { continue; }
            $id = QueryId::normalize( $route['provider_query_id'] ?? ( $route['query_id'] ?? '' ) );
            if ( '' !== $id ) { $routeIds[ $id ] = true; }
        }
        $topology = (array) ( (array) ( $snapshot['inventory'] ?? array() )['elementor_topology'] ?? array() );
        $bindings = array();
        foreach ( (array) ( $topology['bindings'] ?? array() ) as $binding ) {
            if ( ! is_array( $binding ) ) { continue; }
            $id = QueryId::normalize( $binding['provider_query_id'] ?? '' );
            if ( '' !== $id && isset( $routeIds[ $id ] ) ) { $bindings[] = $binding; }
        }

        $routeDrift = $this->routeProviderGroupDrift( $topology, $routeIds );

        $paged['profile_id'] = $profileId;
        $paged['profile'] = $this->pick( $profile, array(
            'id','enabled','post_types','require_post_type_binding','post_type_authority','archive_slugs','archive_paths',
            'providers','query_ids','routes','taxonomy_rules','allowed_taxonomy_sets','publication'
        ) );
        $paged['reconciliation_contract'] = (string) ( $reconciliation['contract'] ?? '' );
        $paged['reconciliation_state'] = (string) ( $reconciliation['state'] ?? '' );
        $paged['reconciliation_summary'] = (array) ( $reconciliation['summary'] ?? array() );
        $paged['requires_operator_review'] = ! empty( $reconciliation['requires_operator_review'] );
        $paged['route_bindings'] = array_slice( $bindings, 0, self::MAX_PAGE_SIZE );
        $paged['route_bindings_truncated'] = count( $bindings ) > self::MAX_PAGE_SIZE;
        $paged['route_provider_group_drift'] = array_slice( $routeDrift, 0, self::MAX_PAGE_SIZE );
        $paged['route_provider_group_drift_truncated'] = count( $routeDrift ) > self::MAX_PAGE_SIZE;
        return $paged;
    }

    /**
     * Mirror InventoryReconcilerBindingTrait::routeProviderGroupDrift semantics:
     * a blocking provider-group drift belongs to a route when that route is one
     * of the drift record's expected provider query IDs. The observed/misbound
     * query ID alone must never assign the drift to an unrelated profile.
     */
    private function routeProviderGroupDrift( array $topology, array $routeIds ): array {
        if ( ! $routeIds ) { return array(); }
        $out = array();
        foreach ( (array) ( $topology['provider_group_drift'] ?? array() ) as $drift ) {
            if ( ! is_array( $drift ) || 'blocking' !== (string) ( $drift['severity_hint'] ?? 'warning' ) ) { continue; }
            $belongs = false;
            foreach ( (array) ( $drift['expected_provider_query_ids'] ?? array() ) as $candidate ) {
                $candidate = QueryId::normalize( $candidate );
                if ( '' !== $candidate && isset( $routeIds[ $candidate ] ) ) { $belongs = true; break; }
            }
            if ( ! $belongs ) { continue; }
            $out[] = $drift;
        }
        return $out;
    }

    private function providerGroupDriftPayload( array $snapshot, array $request ): array {
        $topology = (array) ( (array) ( $snapshot['inventory'] ?? array() )['elementor_topology'] ?? array() );
        $templateId = $this->positiveInt( $request['template_id'] ?? 0 );
        $nodeId = $this->cleanText( $request['node_id'] ?? '' );
        $items = array();
        foreach ( (array) ( $topology['provider_group_drift'] ?? array() ) as $row ) {
            if ( ! is_array( $row ) ) { continue; }
            if ( $templateId > 0 && $templateId !== (int) ( $row['template_id'] ?? 0 ) ) { continue; }
            if ( '' !== $nodeId && $nodeId !== (string) ( $row['node_id'] ?? '' ) ) { continue; }
            $items[] = $row;
        }
        return $this->page( $items, $request, array(
            'source_provider_group_drift_count' => (int) ( $topology['provider_group_drift_count'] ?? count( (array) ( $topology['provider_group_drift'] ?? array() ) ) ),
            'source_provider_group_drift_truncated' => ! empty( $topology['provider_group_drift_truncated'] ),
            'filter_template_id' => $templateId,
            'filter_node_id' => $nodeId,
        ) );
    }

    private function envelope( string $section, string $state, array $payload, array $errors = array(), array $snapshot = array() ): array {
        return array(
            'contract' => self::CONTRACT,
            'provider_id' => self::PROVIDER_ID,
            'section' => $section,
            'state' => $state,
            'authorizing' => false,
            'read_only' => true,
            'profile_mutation' => false,
            'snapshot_fingerprint' => (string) ( $snapshot['snapshot_fingerprint'] ?? '' ),
            'collected_at_gmt' => (string) ( $snapshot['collected_at_gmt'] ?? '' ),
            'errors' => array_values( array_unique( array_filter( array_map( 'strval', $errors ) ) ) ),
            'payload' => $payload,
        );
    }

    private function snapshot(): array {
        if ( null !== $this->snapshotCache ) { return $this->snapshotCache; }
        $this->snapshotError = '';
        try {
            $value = call_user_func( $this->snapshotProvider );
            if ( ! is_array( $value ) || ! $value ) {
                $this->snapshotError = 'runtime_inventory_unavailable';
                $this->snapshotCache = array();
            } else {
                $this->snapshotCache = $value;
            }
        } catch ( \Throwable $error ) {
            unset( $error );
            $this->snapshotError = 'runtime_inventory_unavailable';
            $this->snapshotCache = array();
        }
        return $this->snapshotCache;
    }

    private function profiles(): array {
        if ( null !== $this->profilesCache ) { return $this->profilesCache; }
        $this->profilesError = '';
        try {
            $value = call_user_func( $this->profilesProvider );
            if ( ! is_array( $value ) ) {
                $this->profilesError = 'profile_registry_unavailable';
                $value = array();
            }
        } catch ( \Throwable $error ) {
            unset( $error );
            $this->profilesError = 'profile_registry_unavailable';
            $value = array();
        }
        $out = array();
        foreach ( $value as $key => $profile ) {
            if ( ! is_array( $profile ) ) { continue; }
            $id = $this->cleanKey( $profile['id'] ?? ( is_string( $key ) ? $key : '' ) );
            if ( '' !== $id ) { $out[ $id ] = $profile; }
        }
        $this->profilesCache = $out;
        return $out;
    }

    private function reconciliation( array $snapshot, array $profiles ): array {
        if ( null !== $this->reconciliationCache ) { return $this->reconciliationCache; }
        $this->reconciliationError = '';
        try {
            $value = call_user_func( $this->reconciliationProvider, $snapshot, $profiles );
            if ( ! is_array( $value ) || ! $value ) {
                $this->reconciliationError = 'reconciliation_unavailable';
                $this->reconciliationCache = array();
            } else {
                $this->reconciliationCache = $value;
            }
        } catch ( \Throwable $error ) {
            unset( $error );
            $this->reconciliationError = 'reconciliation_unavailable';
            $this->reconciliationCache = array();
        }
        return $this->reconciliationCache;
    }

    private function page( array $items, array $request, array $extra = array() ): array {
        $offset = max( 0, (int) ( $request['offset'] ?? 0 ) );
        $limit = (int) ( $request['limit'] ?? self::DEFAULT_PAGE_SIZE );
        if ( $limit < 1 ) { $limit = self::DEFAULT_PAGE_SIZE; }
        $limit = min( self::MAX_PAGE_SIZE, $limit );
        $total = count( $items );
        $slice = array_slice( array_values( $items ), $offset, $limit );
        $nextOffset = $offset + count( $slice );
        return array_merge( $extra, array(
            'total' => $total,
            'offset' => $offset,
            'limit' => $limit,
            'returned' => count( $slice ),
            'has_more' => $nextOffset < $total,
            'next_offset' => $nextOffset < $total ? $nextOffset : null,
            'items' => $slice,
        ) );
    }

    /**
     * Return deduplicated positive filter IDs. The 50-ID ceiling applies after
     * deduplication; exceeding it fails closed instead of silently truncating.
     */
    private function filterIds( $value ): array {
        if ( is_string( $value ) ) { $value = preg_split( '/[\s,]+/', $value, -1, PREG_SPLIT_NO_EMPTY ); }
        if ( ! is_array( $value ) ) { return array( 'ids' => array(), 'unique_count' => 0, 'error' => 'filter_ids_required' ); }

        $ids = array();
        foreach ( $value as $raw ) {
            $id = $this->strictPositiveInt( $raw );
            if ( $id < 1 || isset( $ids[ $id ] ) ) { continue; }
            $ids[ $id ] = true;
            if ( count( $ids ) > self::MAX_FILTER_IDS ) {
                return array( 'ids' => array(), 'unique_count' => count( $ids ), 'error' => 'filter_ids_limit_exceeded' );
            }
        }

        return array(
            'ids' => array_map( 'intval', array_keys( $ids ) ),
            'unique_count' => count( $ids ),
            'error' => $ids ? '' : 'filter_ids_required',
        );
    }

    private function strictPositiveInt( $value ): int {
        if ( is_int( $value ) ) { return $value > 0 ? $value : 0; }
        if ( is_string( $value ) ) {
            $value = trim( $value );
            if ( '' === $value || ! preg_match( '/\A[1-9][0-9]*\z/', $value ) ) { return 0; }
            $int = (int) $value;
            return $int > 0 ? $int : 0;
        }
        return 0;
    }

    private function surfaceRecord( array $surface ): array {
        return $this->pick( $surface, array(
            'template_id','node_id','widget_type','surface_role','filter_identity_expected','filter_id',
            'identity_resolution_status','identity_resolution_reason','identity_source','resolution_reason',
            'definition_available','definition_lifecycle_status','definition_reason','definition_post_exists','definition_post_status','definition_post_type',
            'post_exists','post_status','post_type','data_source','source_taxonomy','query_var','custom_query_enabled','custom_query_var',
            'query_builder_query','target_taxonomy','target_source','taxonomy_semantic_status','query_id','content_provider'
        ) );
    }

    private function pick( array $record, array $keys ): array {
        $out = array();
        foreach ( $keys as $key ) {
            if ( array_key_exists( $key, $record ) ) { $out[ $key ] = $record[ $key ]; }
        }
        return $out;
    }

    private function cleanKey( $value ): string {
        $value = strtolower( trim( (string) $value ) );
        if ( function_exists( 'sanitize_key' ) ) { return (string) sanitize_key( $value ); }
        return (string) preg_replace( '/[^a-z0-9_\-]/', '', $value );
    }

    private function cleanText( $value ): string {
        if ( function_exists( 'sanitize_text_field' ) ) { return (string) sanitize_text_field( (string) $value ); }
        return trim( strip_tags( (string) $value ) );
    }

    private function positiveInt( $value ): int {
        if ( function_exists( 'absint' ) ) { return (int) absint( $value ); }
        return abs( (int) $value );
    }
}
