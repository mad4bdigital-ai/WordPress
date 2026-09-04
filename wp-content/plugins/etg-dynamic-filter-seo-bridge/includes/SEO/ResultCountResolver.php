<?php
namespace ETG\DynamicFilterSEOBridge\SEO;

use ETG\DynamicFilterSEOBridge\Config\Configuration;

final class ResultCountResolver {
	private $config;
	private $adapter;
	public function __construct( Configuration $config, ?JetEngineResultCountAdapter $adapter = null ) { $this->config = $config; $this->adapter = $adapter ?: new JetEngineResultCountAdapter(); }

	public function resolve( array $context ): array {
		$authority = apply_filters( 'etg_filter_seo_result_count_authority', null, $context );
		$normalized = $this->normalizeStructured( $authority, 'authority_filter' );
		if ( null !== $normalized['count'] && $normalized['authoritative'] ) { return $normalized; }
		if ( $this->config->get( 'enable_jet_engine_result_count_adapter', true ) ) {
			$adapted = $this->adapter->resolve( $context );
			if ( null !== $adapted['count'] && ! empty( $adapted['authoritative'] ) ) { return $adapted; }
		}
		$legacy = apply_filters( 'etg_filter_seo_result_count', null, $context );
		if ( is_numeric( $legacy ) ) {
			return array( 'count' => max( 0, (int) $legacy ), 'source' => 'legacy_filter', 'authoritative' => (bool) $this->config->get( 'trust_legacy_result_count', false ), 'detail' => 'legacy_numeric' );
		}
		return array( 'count' => null, 'source' => 'unavailable', 'authoritative' => false, 'detail' => (string) ( $normalized['detail'] ?? 'unavailable' ) );
	}

	private function normalizeStructured( $value, string $defaultSource ): array {
		if ( is_array( $value ) && isset( $value['count'] ) && is_numeric( $value['count'] ) ) {
			$source = isset( $value['source'] ) ? sanitize_key( (string) $value['source'] ) : $defaultSource;
			return array(
				'count' => max( 0, (int) $value['count'] ),
				'source' => $source ?: $defaultSource,
				'authoritative' => ! empty( $value['authoritative'] ),
				'detail' => isset( $value['detail'] ) ? sanitize_key( (string) $value['detail'] ) : 'structured',
			);
		}
		return array( 'count' => null, 'source' => 'unavailable', 'authoritative' => false, 'detail' => 'structured_authority_unavailable' );
	}
}
