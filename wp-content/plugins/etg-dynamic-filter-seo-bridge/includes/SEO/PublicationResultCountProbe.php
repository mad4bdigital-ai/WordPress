<?php
namespace ETG\DynamicFilterSEOBridge\SEO;

final class PublicationResultCountProbe {
	public function resolve( array $context ): array {
		if ( 'jet-engine' !== sanitize_key( (string) ( $context['provider'] ?? '' ) ) ) {
			return $this->unavailable( 'unsupported_provider' );
		}
		$queryId = sanitize_key( (string) ( $context['query_id'] ?? '' ) );
		if ( '' === $queryId ) { return $this->unavailable( 'missing_query_id' ); }
		$filters = (array) ( $context['filters'] ?? array() );
		if ( ! $filters ) { return $this->unavailable( 'missing_filters' ); }
		$class = '\\Jet_Engine\\Query_Builder\\Manager';
		if ( ! class_exists( $class ) || ! method_exists( $class, 'instance' ) ) { return $this->unavailable( 'manager_unavailable' ); }
		if ( ! class_exists( '\\WP_Query' ) ) { return $this->unavailable( 'wp_query_unavailable' ); }

		try {
			$manager = $class::instance();
			if ( ! is_object( $manager ) || ! method_exists( $manager, 'get_query_by_id' ) ) { return $this->unavailable( 'query_lookup_unavailable' ); }
			$query = $manager->get_query_by_id( $queryId );
			if ( ! is_object( $query ) ) { return $this->unavailable( 'query_not_found' ); }
			$type = method_exists( $query, 'get_query_type' ) ? sanitize_key( (string) $query->get_query_type() ) : '';
			if ( 'posts' !== $type ) { return $this->unavailable( '' === $type ? 'query_type_unobserved' : 'query_type_not_posts' ); }
			if ( ! method_exists( $query, 'get_query_args' ) ) { return $this->unavailable( 'query_args_unavailable' ); }
			$query = clone $query;
			$args = $query->get_query_args();
			if ( ! is_array( $args ) ) { return $this->unavailable( 'query_args_invalid' ); }
			$postTypes = $this->postTypes( $args['post_type'] ?? null );
			if ( ! $postTypes ) { return $this->unavailable( 'post_type_unbounded' ); }

			$taxClauses = array();
			foreach ( $filters as $taxonomy => $slug ) {
				$taxonomy = sanitize_key( (string) $taxonomy );
				$slug = sanitize_title( (string) $slug );
				if ( '' === $taxonomy || '' === $slug || ! taxonomy_exists( $taxonomy ) ) { return $this->unavailable( 'invalid_taxonomy_filter' ); }
				$term = get_term_by( 'slug', $slug, $taxonomy );
				if ( ! $term || is_wp_error( $term ) ) { return $this->unavailable( 'term_not_found' ); }
				$taxClauses[] = array( 'taxonomy'=>$taxonomy, 'field'=>'slug', 'terms'=>array($slug), 'operator'=>'IN' );
			}
			if ( ! $taxClauses ) { return $this->unavailable( 'tax_query_empty' ); }

			$existing = isset( $args['tax_query'] ) && is_array( $args['tax_query'] ) ? $args['tax_query'] : array();
			if ( $existing ) {
				$args['tax_query'] = array_merge( array( 'relation'=>'AND', $existing ), $taxClauses );
			} else {
				$args['tax_query'] = count( $taxClauses ) > 1 ? array_merge( array( 'relation'=>'AND' ), $taxClauses ) : $taxClauses;
			}
			$args['posts_per_page'] = 1;
			$args['paged'] = 1;
			$args['fields'] = 'ids';
			$args['no_found_rows'] = false;
			$args['ignore_sticky_posts'] = true;
			unset( $args['offset'] );

			$wpQuery = new \WP_Query( $args );
			$count = isset( $wpQuery->found_posts ) ? $wpQuery->found_posts : null;
			if ( ! is_numeric( $count ) ) { return $this->unavailable( 'non_numeric_count' ); }
			$result = array(
				'count'=>max(0,(int)$count),
				'source'=>'jet_engine_query_builder_background_tax_query',
				'authoritative'=>true,
				'detail'=>'query_builder_base_args_plus_exact_taxonomy_filters',
				'post_types'=>$postTypes,
			);
			return function_exists('apply_filters') ? (array) apply_filters('etg_filter_seo_publication_result_count',$result,$context,$args) : $result;
		} catch ( \Throwable $error ) {
			return $this->unavailable( 'publication_count_exception' );
		}
	}

	private function postTypes( $value ): array {
		$items = is_array( $value ) ? $value : ( is_scalar( $value ) ? array( $value ) : array() );
		$out = array();
		foreach ( $items as $item ) {
			$item = sanitize_key( (string) $item );
			if ( '' === $item || 'any' === $item ) { return array(); }
			$out[] = $item;
		}
		$out = array_values( array_unique( array_filter( $out ) ) );
		sort( $out, SORT_STRING );
		return $out;
	}

	private function unavailable( string $detail ): array {
		return array( 'count'=>null, 'source'=>'unavailable', 'authoritative'=>false, 'detail'=>$detail, 'post_types'=>array() );
	}
}
