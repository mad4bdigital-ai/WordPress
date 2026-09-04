<?php
namespace ETG\DynamicFilterSEOBridge\Runtime;

final class PostTypeObserver {
	public function observe( array $parsed, array $profile ): array {
		$required = ! empty( $profile['require_post_type_binding'] );
		$authority = sanitize_key( (string) ( $profile['post_type_authority'] ?? 'query_builder' ) );
		if ( ! in_array( $authority, array( 'query_builder', 'main_query', 'either', 'both' ), true ) ) { $authority = 'query_builder'; }
		$allowed = array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) ( $profile['post_types'] ?? array() ) ) ) ) );
		$queryBuilder = $this->queryBuilderObservation( $parsed );
		$mainQuery = $this->mainQueryObservation();

		if ( ! $required ) {
			return $this->result( false, $authority, true, true, 'not_required', array(), 'not_required', $queryBuilder, $mainQuery, $allowed );
		}
		if ( ! $allowed ) {
			return $this->result( true, $authority, false, false, 'profile_post_types_empty', array(), 'configuration', $queryBuilder, $mainQuery, $allowed );
		}

		if ( 'query_builder' === $authority ) {
			return $this->fromOne( true, $authority, $queryBuilder, $allowed );
		}
		if ( 'main_query' === $authority ) {
			return $this->fromOne( true, $authority, $mainQuery, $allowed );
		}
		if ( 'either' === $authority ) {
			if ( ! empty( $queryBuilder['observed'] ) ) { return $this->fromOne( true, $authority, $queryBuilder, $allowed ); }
			return $this->fromOne( true, $authority, $mainQuery, $allowed );
		}

		if ( empty( $queryBuilder['observed'] ) || empty( $mainQuery['observed'] ) ) {
			return $this->result( true, $authority, false, false, 'required_authority_unobserved', array(), 'both', $queryBuilder, $mainQuery, $allowed );
		}
		$queryTypes = (array) $queryBuilder['post_types'];
		$mainTypes = (array) $mainQuery['post_types'];
		$matches = $this->typesAllowed( $queryTypes, $allowed ) && $this->typesAllowed( $mainTypes, $allowed );
		$consistent = $queryTypes === $mainTypes;
		return $this->result( true, $authority, true, $matches && $consistent, $matches ? ( $consistent ? 'matched' : 'authority_disagreement' ) : 'post_type_mismatch', array_values( array_unique( array_merge( $queryTypes, $mainTypes ) ) ), 'both', $queryBuilder, $mainQuery, $allowed );
	}

	private function fromOne( bool $required, string $authority, array $observation, array $allowed ): array {
		if ( empty( $observation['observed'] ) ) {
			return $this->result( $required, $authority, false, false, (string) ( $observation['detail'] ?? 'unobserved' ), array(), (string) ( $observation['source'] ?? $authority ), $observation['source'] === 'query_builder' ? $observation : array(), $observation['source'] === 'main_query' ? $observation : array(), $allowed );
		}
		$types = (array) ( $observation['post_types'] ?? array() );
		$matches = $this->typesAllowed( $types, $allowed );
		return $this->result( $required, $authority, true, $matches, $matches ? 'matched' : 'post_type_mismatch', $types, (string) ( $observation['source'] ?? $authority ), $observation['source'] === 'query_builder' ? $observation : array(), $observation['source'] === 'main_query' ? $observation : array(), $allowed );
	}

	private function queryBuilderObservation( array $parsed ): array {
		$out = array( 'observed'=>false, 'post_types'=>array(), 'source'=>'query_builder', 'detail'=>'unavailable', 'query_type'=>'', 'query_id'=>'' );
		if ( 'jet-engine' !== sanitize_key( (string) ( $parsed['provider'] ?? '' ) ) ) { $out['detail']='unsupported_provider'; return $out; }
		$queryId = sanitize_key( (string) ( $parsed['query_id'] ?? '' ) );
		$out['query_id'] = $queryId;
		if ( '' === $queryId ) { $out['detail']='missing_query_id'; return $out; }
		$class = '\\Jet_Engine\\Query_Builder\\Manager';
		if ( ! class_exists( $class ) || ! method_exists( $class, 'instance' ) ) { $out['detail']='manager_unavailable'; return $out; }
		try {
			$manager = $class::instance();
			if ( ! is_object( $manager ) || ! method_exists( $manager, 'get_query_by_id' ) ) { $out['detail']='query_lookup_unavailable'; return $out; }
			$query = $manager->get_query_by_id( $queryId );
			if ( ! is_object( $query ) ) { $out['detail']='query_not_found'; return $out; }
			$type = method_exists( $query, 'get_query_type' ) ? sanitize_key( (string) $query->get_query_type() ) : ( isset( $query->query_type ) ? sanitize_key( (string) $query->query_type ) : '' );
			$out['query_type'] = $type;
			if ( 'posts' !== $type ) { $out['detail'] = '' === $type ? 'query_type_unobserved' : 'query_type_not_posts'; return $out; }
			if ( ! method_exists( $query, 'get_query_args' ) ) { $out['detail']='query_args_unavailable'; return $out; }
			$query = clone $query;
			$args = $query->get_query_args();
			if ( ! is_array( $args ) ) { $out['detail']='query_args_invalid'; return $out; }
			$types = $this->normalizePostTypes( $args['post_type'] ?? null );
			if ( ! $types ) { $out['detail']='post_type_unbounded'; return $out; }
			$out['observed']=true; $out['post_types']=$types; $out['detail']='observed'; return $out;
		} catch ( \Throwable $e ) {
			$out['detail']='observer_exception'; return $out;
		}
	}

	private function mainQueryObservation(): array {
		$out = array( 'observed'=>false, 'post_types'=>array(), 'source'=>'main_query', 'detail'=>'unavailable' );
		if ( ! function_exists( 'get_query_var' ) ) { return $out; }
		$value = get_query_var( 'post_type' );
		$types = $this->normalizePostTypes( $value );
		if ( ! $types ) { $out['detail']='post_type_unobserved'; return $out; }
		$out['observed']=true; $out['post_types']=$types; $out['detail']='observed'; return $out;
	}

	private function normalizePostTypes( $value ): array {
		$items = is_array( $value ) ? $value : ( is_scalar( $value ) ? array( $value ) : array() );
		$out = array();
		foreach ( $items as $item ) {
			$item = sanitize_key( (string) $item );
			if ( '' === $item || 'any' === $item ) { continue; }
			$out[] = $item;
		}
		$out = array_values( array_unique( array_filter( $out ) ) );
		sort( $out, SORT_STRING );
		return $out;
	}

	private function typesAllowed( array $observed, array $allowed ): bool {
		if ( ! $observed || ! $allowed ) { return false; }
		foreach ( $observed as $postType ) { if ( ! in_array( $postType, $allowed, true ) ) { return false; } }
		return true;
	}

	private function result( bool $required, string $authority, bool $observed, bool $matches, string $reason, array $postTypes, string $source, array $queryBuilder, array $mainQuery, array $allowed ): array {
		return array(
			'contract'=>'etg.dfsb.post-type-binding.v2', 'required'=>$required, 'authority'=>$authority, 'observed'=>$observed, 'matches_profile'=>$matches,
			'reason'=>$reason, 'post_types'=>array_values($postTypes), 'allowed_post_types'=>array_values($allowed), 'source'=>$source,
			'sources'=>array( 'query_builder'=>$queryBuilder, 'main_query'=>$mainQuery ),
		);
	}
}
