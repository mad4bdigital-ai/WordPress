<?php
namespace ETG\DynamicFilterSEOBridge\SEO;

final class IndexingPolicy {
	public function decide( array $context ): array {
		if ( empty( $context['active'] ) ) { return $this->decision( null, 'inactive' ); }
		if ( ! empty( $context['unknown_filters'] ) ) { return $this->filtered( false, 'unknown_filter', $context ); }
		if ( ! empty( $context['malformed'] ) ) { return $this->filtered( false, 'malformed_filter', $context ); }
		if ( ! empty( $context['missing_terms'] ) ) { return $this->filtered( false, 'missing_term', $context ); }
		if ( ! empty( $context['translation_fallback'] ) ) { return $this->filtered( false, 'translation_fallback', $context ); }

		$filters = isset( $context['filters'] ) ? (array) $context['filters'] : array();
		$count = count( $filters );
		if ( 0 === $count ) { return $this->decision( null, 'no_filters' ); }
		if ( $count > 3 ) { return $this->filtered( false, 'too_many_filters', $context ); }

		$terms = isset( $context['terms'] ) ? (array) $context['terms'] : array();
		if ( 1 === $count && isset( $terms['location'] ) ) {
			$level = sanitize_key( (string) ( $terms['location']['location_level'] ?? '' ) );
			$hasResults = ! empty( $terms['location']['count'] );
			if ( $hasResults && in_array( $level, array( 'city', 'landmark' ), true ) ) {
				return $this->filtered( true, 'indexable_location', $context );
			}
			return $this->filtered( false, 'location_not_explicitly_indexable', $context );
		}
		if ( 1 === $count && isset( $terms['tour_type'] ) ) {
			return $this->filtered( false, 'tour_type_requires_opt_in', $context );
		}

		$resultCount = apply_filters( 'etg_filter_seo_result_count', null, $context );
		if ( is_numeric( $resultCount ) && (int) $resultCount < 1 ) { return $this->filtered( false, 'zero_results', $context ); }
		$hasLocation = isset( $terms['location'] );
		$hasType = isset( $terms['tour_type'] );
		$hasStyle = isset( $terms['style'] );
		if ( $hasLocation && $hasType && 2 === $count ) {
			return $this->filtered( is_numeric( $resultCount ) && (int) $resultCount >= 3, 'location_type_min_results', $context );
		}
		if ( $hasLocation && $hasType && $hasStyle && 3 === $count ) {
			return $this->filtered( is_numeric( $resultCount ) && (int) $resultCount >= 3, 'triple_min_results', $context );
		}
		return $this->filtered( false, 'combination_not_allowlisted', $context );
	}

	private function filtered( bool $index, string $reason, array $context ): array {
		$index = (bool) apply_filters( 'etg_filter_seo_should_index', $index, $reason, $context );
		return $this->decision( $index, $reason );
	}

	private function decision( $index, string $reason ): array {
		return array( 'index' => $index, 'follow' => true, 'reason' => $reason );
	}
}
