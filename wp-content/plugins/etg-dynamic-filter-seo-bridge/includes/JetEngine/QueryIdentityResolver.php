<?php
namespace ETG\DynamicFilterSEOBridge\JetEngine;

require_once dirname( __DIR__ ) . '/Identifiers/QueryId.php';

use ETG\DynamicFilterSEOBridge\Identifiers\QueryId;

final class QueryIdentityResolver {
	private $queryProvider;

	public function __construct( callable $queryProvider = null ) {
		$this->queryProvider = $queryProvider;
	}

	public function capable(): bool {
		if ( $this->queryProvider ) { return true; }
		$class = '\\Jet_Engine\\Query_Builder\\Manager';
		if ( ! class_exists( $class ) || ! method_exists( $class, 'instance' ) ) { return false; }
		try {
			$manager = $class::instance();
			return is_object( $manager ) && method_exists( $manager, 'get_queries' );
		} catch ( \Throwable $error ) {
			return false;
		}
	}

	public function resolve( string $customQueryId ): array {
		$requested = QueryId::normalize( $customQueryId );
		if ( '' === $requested ) { return $this->failure( 'missing_or_invalid_query_id', $requested, 0 ); }

		try {
			if ( $this->queryProvider ) {
				$queries = call_user_func( $this->queryProvider );
				$source = 'injected_test_provider';
			} else {
				$class = '\\Jet_Engine\\Query_Builder\\Manager';
				if ( ! class_exists( $class ) || ! method_exists( $class, 'instance' ) ) {
					return $this->failure( 'query_identity_inventory_unavailable', $requested, 0 );
				}
				$manager = $class::instance();
				if ( ! is_object( $manager ) || ! method_exists( $manager, 'get_queries' ) ) {
					return $this->failure( 'query_identity_inventory_unavailable', $requested, 0 );
				}
				$queries = $manager->get_queries();
				$source = 'jet_engine_query_builder_manager_get_queries';
			}
		} catch ( \Throwable $error ) {
			return $this->failure( 'query_identity_inventory_unavailable', $requested, 0 );
		}

		if ( $queries instanceof \Traversable ) { $queries = iterator_to_array( $queries, false ); }
		if ( ! is_array( $queries ) ) { return $this->failure( 'query_identity_inventory_unavailable', $requested, 0 ); }

		$matches = array();
		foreach ( $queries as $query ) {
			if ( ! is_object( $query ) || ! isset( $query->query_id ) || ! is_scalar( $query->query_id ) ) { continue; }
			$candidate = QueryId::normalize( (string) $query->query_id );
			if ( '' === $candidate || $candidate !== $requested ) { continue; }
			$matches[] = array(
				'query' => $query,
				'internal_query_id' => isset( $query->id ) && is_scalar( $query->id ) ? (string) $query->id : '',
			);
		}

		$count = count( $matches );
		if ( 0 === $count ) { return $this->failure( 'query_identity_not_found', $requested, 0, $source ); }
		if ( 1 !== $count ) { return $this->failure( 'query_identity_ambiguous', $requested, $count, $source ); }

		return array(
			'resolved' => true,
			'reason' => 'resolved',
			'custom_query_id' => $requested,
			'internal_query_id' => $matches[0]['internal_query_id'],
			'query' => $matches[0]['query'],
			'source' => $source,
			'match_count' => 1,
		);
	}

	private function failure( string $reason, string $customQueryId, int $matchCount, string $source = 'unavailable' ): array {
		return array(
			'resolved' => false,
			'reason' => $reason,
			'custom_query_id' => $customQueryId,
			'internal_query_id' => '',
			'query' => null,
			'source' => $source,
			'match_count' => $matchCount,
		);
	}
}
