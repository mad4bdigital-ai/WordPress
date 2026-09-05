<?php
namespace ETG\DynamicFilterSEOBridge\SEO;

final class JetEngineResultCountAdapter {
	public function capable(): bool {
		if ( ! function_exists( 'jet_smart_filters' ) ) { return false; }
		$class = '\\Jet_Engine\\Query_Builder\\Manager';
		if ( ! class_exists( $class ) || ! method_exists( $class, 'instance' ) || ! method_exists( $class, 'get_query_by_id' ) ) { return false; }
		$jsf = jet_smart_filters();
		return is_object( $jsf ) && isset( $jsf->query ) && is_object( $jsf->query ) && method_exists( $jsf->query, 'get_query_from_request' );
	}

	public function resolve( array $context ): array {
		if ( ! $this->capable() || 'jet-engine' !== (string) ( $context['provider'] ?? '' ) ) { return $this->unavailable(); }
		if ( empty( $context['provider_observation_matches_url'] ) ) { return $this->unavailable( 'provider_mismatch' ); }
		$queryId = sanitize_key( (string) ( $context['query_id'] ?? '' ) );
		if ( '' === $queryId ) { return $this->unavailable( 'missing_query_id' ); }
		$jsf = jet_smart_filters();
		$filtered = $jsf->query->get_query_from_request();
		if ( ! is_array( $filtered ) || empty( $filtered ) ) { return $this->unavailable( 'filtered_request_unavailable' ); }
		$class = '\\Jet_Engine\\Query_Builder\\Manager';
		$manager = $class::instance();
		$query = $manager->get_query_by_id( $queryId );
		if ( ! is_object( $query ) || ! method_exists( $query, 'setup_query' ) || ! method_exists( $query, 'set_filtered_prop' ) || ! method_exists( $query, 'get_items_total_count' ) ) { return $this->unavailable( 'query_builder_query_unavailable' ); }
		try {
			$query = clone $query;
			$query->setup_query();
			foreach ( $filtered as $prop => $value ) { $query->set_filtered_prop( $prop, $value ); }
			$count = $query->get_items_total_count();
			if ( ! is_numeric( $count ) ) { return $this->unavailable( 'non_numeric_count' ); }
			return array( 'count' => max( 0, (int) $count ), 'source' => 'jet_engine_query_builder', 'authoritative' => true, 'detail' => 'filtered_query_builder_count' );
		} catch ( \Throwable $e ) {
			return $this->unavailable( 'adapter_exception' );
		}
	}

	private function unavailable( string $detail = 'unavailable' ): array { return array( 'count' => null, 'source' => 'unavailable', 'authoritative' => false, 'detail' => $detail ); }
}
