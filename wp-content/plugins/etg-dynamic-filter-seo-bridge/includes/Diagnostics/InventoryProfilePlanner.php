<?php
namespace ETG\DynamicFilterSEOBridge\Diagnostics;

final class InventoryProfilePlanner {
    const CONTRACT = 'etg.dfsb.inventory-profile-plan.v1';
    const MAX_PROFILES = 50;
    const MAX_ROUTES = 20;
    const MAX_TAXONOMIES = 100;

    public function plan( array $snapshot, array $profiles ): array {
        $inventory = (array) ( $snapshot['inventory'] ?? array() );
        $postTypes = (array) ( $inventory['post_types'] ?? array() );
        $taxonomies = (array) ( $inventory['taxonomies'] ?? array() );
        $queryBuilder = (array) ( $inventory['query_builder'] ?? array() );
        $topology = (array) ( $inventory['elementor_topology'] ?? array() );
        $identityComplete = ! empty( $queryBuilder['identity_index_complete'] );
        $identityIndex = $this->identityIndex( $queryBuilder );
        $conflicts = $this->conflicts( $queryBuilder );
        $proposals = array();

        foreach ( array_slice( $profiles, 0, self::MAX_PROFILES, true ) as $profileKey => $profile ) {
            if ( ! is_array( $profile ) ) { continue; }
            $id = $this->key( (string) ( $profile['id'] ?? $profileKey ) );
            if ( '' === $id ) { continue; }
            $current = $profile;
            $proposed = $profile;
            $proposed['enabled'] = false;
            $routeEvidence = array();
            $resolvedSets = array();
            $blocked = array();
            if ( ! $identityComplete ) { $blocked[] = 'query_identity_index_incomplete'; }
            $routes = array();

            foreach ( array_slice( (array) ( $profile['routes'] ?? array() ), 0, self::MAX_ROUTES ) as $route ) {
                if ( ! is_array( $route ) ) { continue; }
                $provider = $this->key( (string) ( $route['provider'] ?? '' ) );
                $providerQueryId = $this->key( (string) ( ( $route['provider_query_id'] ?? '' ) ?: ( $route['query_id'] ?? '' ) ) );
                if ( '' === $provider || '' === $providerQueryId ) {
                    $blocked[] = 'route_identity_missing';
                    continue;
                }
                $resolved = $this->resolveRoute( $provider, $providerQueryId, $route, $identityIndex, $conflicts, $topology );
                $routeEvidence[] = $resolved;
                if ( empty( $resolved['resolved'] ) ) {
                    $blocked[] = (string) ( $resolved['reason'] ?? 'route_unresolved' );
                    continue;
                }
                $postTypeSet = $this->keys( (array) ( $resolved['post_types'] ?? array() ) );
                if ( ! $postTypeSet ) {
                    $blocked[] = 'query_builder_post_type_unbounded';
                    continue;
                }
                $resolvedSets[ implode( '|', $postTypeSet ) ] = $postTypeSet;
                $routes[] = array(
                    'provider' => $provider,
                    'query_id' => $providerQueryId,
                    'provider_query_id' => $providerQueryId,
                    'query_builder_query_id' => (string) $resolved['query_builder_query_id'],
                );
            }

            if ( ! $routes ) { $blocked[] = 'no_verified_routes'; }
            if ( count( $resolvedSets ) > 1 ) { $blocked[] = 'routes_resolve_to_different_post_type_sets'; }
            $observedPostTypes = 1 === count( $resolvedSets ) ? (array) reset( $resolvedSets ) : array();
            $currentPostTypes = $this->keys( (array) ( $profile['post_types'] ?? array() ) );
            if ( $currentPostTypes && $observedPostTypes && $currentPostTypes !== $observedPostTypes ) {
                $blocked[] = 'configured_post_types_conflict_with_runtime';
            }

            if ( ! $blocked && $observedPostTypes ) {
                if ( ! $currentPostTypes ) { $proposed['post_types'] = $observedPostTypes; }
                $proposed['require_post_type_binding'] = true;
                $proposed['post_type_authority'] = 'query_builder';
                $proposed['routes'] = $routes;
                if ( empty( $proposed['archive_paths'] ) && 1 === count( $observedPostTypes ) ) {
                    $record = (array) ( $postTypes[ $observedPostTypes[0] ] ?? array() );
                    $paths = array_values( array_unique( array_filter( array_map( array( $this, 'path' ), array_values( (array) ( $record['archive_paths'] ?? array() ) ) ) ) ) );
                    if ( $paths ) { $proposed['archive_paths'] = array( $paths[0] ); }
                }
            }

            $taxonomyCandidates = $this->taxonomyCandidates( $observedPostTypes ?: $currentPostTypes, $taxonomies, (array) ( $profile['taxonomy_rules'] ?? array() ) );
            $changes = $this->changes( $current, $proposed, array( 'enabled', 'post_types', 'require_post_type_binding', 'post_type_authority', 'archive_paths', 'routes' ) );
            $blocked = array_values( array_unique( array_filter( array_map( 'strval', $blocked ) ) ) );
            $proposals[ $id ] = array(
                'contract' => self::CONTRACT,
                'profile_id' => $id,
                'authorizing' => false,
                'requires_operator_review' => true,
                'safe_to_apply' => empty( $blocked ) && ! empty( $routeEvidence ),
                'status' => $blocked ? 'blocked' : ( $changes ? 'ready' : 'aligned' ),
                'blocking_reasons' => $blocked,
                'route_evidence' => $routeEvidence,
                'observed_post_types' => $observedPostTypes,
                'changes' => $changes,
                'taxonomy_candidates' => $taxonomyCandidates,
                'current_profile' => $current,
                'proposed_profile' => $proposed,
            );
        }

        return array(
            'contract' => self::CONTRACT,
            'authorizing' => false,
            'read_only' => true,
            'profile_mutation' => false,
            'requires_operator_review' => true,
            'snapshot_fingerprint' => (string) ( $snapshot['snapshot_fingerprint'] ?? '' ),
            'proposal_count' => count( $proposals ),
            'proposals' => $proposals,
        );
    }

    private function resolveRoute( string $provider, string $providerQueryId, array $route, array $identityIndex, array $conflicts, array $topology ): array {
        $explicit = $this->key( (string) ( $route['query_builder_query_id'] ?? '' ) );
        $queryId = '';
        $source = '';
        if ( '' !== $explicit ) {
            $queryId = $explicit;
            $source = 'profile_explicit_query_builder_query_id';
        } elseif ( ! isset( $conflicts[ $providerQueryId ] ) && isset( $identityIndex[ $providerQueryId ] ) ) {
            $queryId = $providerQueryId;
            $source = 'provider_query_id_equals_query_builder_custom_id';
        } else {
            $matches = array();
            foreach ( (array) ( $topology['bindings'] ?? array() ) as $binding ) {
                if ( ! is_array( $binding ) || 'verified' !== (string) ( $binding['status'] ?? '' ) ) { continue; }
                if ( $provider !== $this->key( (string) ( $binding['provider'] ?? '' ) ) ) { continue; }
                if ( $providerQueryId !== $this->key( (string) ( $binding['provider_query_id'] ?? '' ) ) ) { continue; }
                $candidate = $this->key( (string) ( $binding['query_builder_custom_query_id'] ?? '' ) );
                if ( '' !== $candidate ) { $matches[ $candidate ][] = $binding; }
            }
            if ( 1 !== count( $matches ) ) {
                return array( 'resolved'=>false, 'provider'=>$provider, 'provider_query_id'=>$providerQueryId, 'reason'=>count($matches)>1?'topology_ambiguous':'query_builder_identity_unresolved' );
            }
            $queryId = (string) array_key_first( $matches );
            $source = 'elementor_runtime_topology';
        }
        if ( isset( $conflicts[ $queryId ] ) ) {
            return array( 'resolved'=>false, 'provider'=>$provider, 'provider_query_id'=>$providerQueryId, 'query_builder_query_id'=>$queryId, 'reason'=>'query_builder_identity_collision' );
        }
        $query = (array) ( $identityIndex[ $queryId ] ?? array() );
        if ( ! $query ) { return array( 'resolved'=>false, 'provider'=>$provider, 'provider_query_id'=>$providerQueryId, 'query_builder_query_id'=>$queryId, 'reason'=>'query_builder_identity_missing' ); }
        if ( 'posts' !== (string) ( $query['type'] ?? '' ) ) { return array( 'resolved'=>false, 'provider'=>$provider, 'provider_query_id'=>$providerQueryId, 'query_builder_query_id'=>$queryId, 'reason'=>'query_builder_query_not_posts' ); }
        if ( empty( $query['post_type_bounded'] ) ) { return array( 'resolved'=>false, 'provider'=>$provider, 'provider_query_id'=>$providerQueryId, 'query_builder_query_id'=>$queryId, 'reason'=>'query_builder_post_type_unbounded' ); }
        $postTypes = $this->keys( (array) ( $query['post_types'] ?? array() ) );
        return array(
            'resolved'=>! empty( $postTypes ),
            'provider'=>$provider,
            'provider_query_id'=>$providerQueryId,
            'query_builder_query_id'=>$queryId,
            'query_builder_internal_id'=>(string) ( $query['id'] ?? '' ),
            'query_type'=>(string) ( $query['type'] ?? '' ),
            'post_types'=>$postTypes,
            'source'=>$source,
            'reason'=>$postTypes?'verified':'query_builder_post_type_unbounded',
        );
    }

    private function identityIndex( array $queryBuilder ): array {
        $records = ! empty( $queryBuilder['identity_index_complete'] ) ? (array) ( $queryBuilder['identity_index'] ?? array() ) : (array) ( $queryBuilder['queries'] ?? array() );
        $recordsById = array();
        $counts = array();
        foreach ( $records as $record ) {
            if ( ! is_array( $record ) ) { continue; }
            $custom = $this->key( (string) ( $record['custom_query_id'] ?? '' ) );
            if ( '' === $custom ) { continue; }
            $counts[ $custom ] = (int) ( $counts[ $custom ] ?? 0 ) + 1;
            if ( ! isset( $recordsById[ $custom ] ) ) { $recordsById[ $custom ] = $record; }
        }
        $out = array();
        foreach ( $counts as $custom => $count ) {
            if ( 1 !== $count ) { continue; }
            $copy = (array) $recordsById[ $custom ];
            $copy['custom_query_id'] = $custom;
            $out[ $custom ] = $copy;
        }
        return $out;
    }

    private function conflicts( array $queryBuilder ): array {
        $out = array();
        foreach ( (array) ( $queryBuilder['identity_conflicts'] ?? array() ) as $conflict ) {
            if ( ! is_array( $conflict ) ) { continue; }
            $key = $this->key( (string) ( $conflict['identity_key'] ?? '' ) );
            if ( '' !== $key ) { $out[ $key ] = $conflict; }
        }
        return $out;
    }

    private function taxonomyCandidates( array $postTypes, array $taxonomies, array $configuredRules ): array {
        $out = array();
        foreach ( array_slice( $taxonomies, 0, self::MAX_TAXONOMIES, true ) as $taxonomy => $record ) {
            $taxonomy = $this->key( (string) $taxonomy );
            if ( '' === $taxonomy || ! is_array( $record ) ) { continue; }
            $attached = $this->keys( (array) ( $record['object_type'] ?? array() ) );
            if ( ! array_intersect( $postTypes, $attached ) ) { continue; }
            $configured = isset( $configuredRules[ $taxonomy ] );
            $out[ $taxonomy ] = array(
                'taxonomy' => $taxonomy,
                'label' => (string) ( $record['label'] ?? $taxonomy ),
                'configured' => $configured,
                'suggested_role' => $this->suggestedRole( $taxonomy ),
                'publicly_queryable' => ! empty( $record['publicly_queryable'] ),
                'hierarchical' => ! empty( $record['hierarchical'] ),
                'attached_post_types' => $attached,
                'content_only_default' => ! $configured,
                'indexing_set_added' => false,
                'authorizing' => false,
            );
        }
        ksort( $out, SORT_STRING );
        return $out;
    }

    private function suggestedRole( string $taxonomy ): string {
        $map = array( 'location_jet'=>'location', 'tour-types_jet'=>'tour_type', 'tour-styles_jet'=>'style' );
        return $map[ $taxonomy ] ?? str_replace( '-', '_', $taxonomy );
    }

    private function changes( array $current, array $proposed, array $keys ): array {
        $out = array();
        foreach ( $keys as $key ) {
            $from = $current[ $key ] ?? null;
            $to = $proposed[ $key ] ?? null;
            if ( $from === $to ) { continue; }
            $out[] = array( 'field'=>$key, 'from'=>$from, 'to'=>$to, 'authorizing'=>false );
        }
        return $out;
    }

    private function keys( array $values ): array {
        $out = array();
        foreach ( $values as $value ) { $value = $this->key( (string) $value ); if ( '' !== $value ) { $out[] = $value; } }
        $out = array_values( array_unique( $out ) );
        sort( $out, SORT_STRING );
        return $out;
    }

    private function key( string $value ): string {
        if ( function_exists( 'sanitize_key' ) ) { return sanitize_key( $value ); }
        return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', $value ) );
    }

    private function path( string $value ): string {
        $path = parse_url( $value, PHP_URL_PATH );
        $path = is_string( $path ) ? rawurldecode( $path ) : '';
        $path = preg_replace( '#/+#', '/', $path );
        return '' === $path ? '' : '/' . trim( strtolower( $path ), '/' ) . '/';
    }
}
