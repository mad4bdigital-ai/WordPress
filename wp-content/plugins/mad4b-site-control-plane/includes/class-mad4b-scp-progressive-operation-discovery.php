<?php
/**
 * Discover dynamic registered operations, planner variables and dependencies.
 * Never guesses an executor, invokes a mutation, adopts a plugin callback or
 * authorizes a future step. Pure matching is independently testable.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_Progressive_Operation_Discovery {
	const CONTRACT = 'mad4b.progressive-operation-discovery.v1';
	const MAX_OPERATIONS = 512;
	const MAX_VARIABLES = 80;

	private static function normalize( $value ) {
		$value = is_string( $value ) ? trim( $value ) : '';
		$value = function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
		$safe = preg_replace( '/[^\\p{L}\\p{N}_.-]+/u', ' ', $value );
		return is_string( $safe ) ? substr( $safe, 0, 160 ) : '';
	}

	/** The catalog is the authority; no caller-controlled definitions allowed. */
	public static function catalog( $internal = array() ) {
		if ( ! class_exists( 'MAD4B_SCP_Operation_Registry', false ) )
			return new WP_Error( 'mad4b_progressive_operation_registry_unavailable',
				'Canonical operation registry is not enrolled.' );
		$registry = MAD4B_SCP_Operation_Registry::status();
		if ( ! is_array( $registry ) || ! isset( $registry['operations'] ) ||
			! is_array( $registry['operations'] ) )
			return new WP_Error( 'mad4b_progressive_operation_registry_invalid',
				'Registry metadata unavailable or incomplete.' );
		if ( count( $registry['operations'] ) + count( $internal ) > self::MAX_OPERATIONS )
			return new WP_Error( 'mad4b_progressive_operation_registry_overflow',
				'Dynamic operation inventory exceeds the bounded secure discovery limit.' );
		$items = array();
		foreach ( $registry['operations'] as $item ) {
			if ( ! is_array( $item ) ) continue;
			$id = (string) ( $item['id'] ?? '' );
			if ( ! preg_match( '/^[a-z][a-z0-9._-]{1,119}$/D', $id ) )
				continue;
			$planner = (string) ( $item['planner'] ?? '' );
			$executor = (string) ( $item['executor'] ?? '' );
			$ready = ! empty( $item['descriptor_binding_ready'] )
				&& true === ( $item['planner_registered'] ?? false )
				&& true === ( $item['executor_registered'] ?? false );
			$items[] = array(
				'id' => $id,
				'selector' => 'registry.operation',
				'target_operation_id' => $id,
				'planner' => $planner,
				'executor' => $executor,
				'kind' => (string) ( $item['target_kind'] ?? '' ),
				'available' => $ready,
				'capability_verified' => $ready,
				'capability_family' => 'canonical_operation_registry',
				'supports' => array_values( array_slice(
					is_array( $item['supports'] ?? null ) ? $item['supports'] : array(), 0, 20 ) ),
			);
		}
		foreach ( $internal as $id => $callback ) {
			if ( ! is_string( $id ) || ! preg_match( '/^[a-z][a-z0-9._-]{1,79}$/D', $id ) )
				continue;
			$items[] = array(
				'id' => $id,
				'selector' => $id,
				'target_operation_id' => '',
				'planner' => '',
				'executor' => '',
				'kind' => 'source_registered_observer',
				'available' => is_callable( $callback ),
				'capability_verified' => is_callable( $callback ),
				'capability_family' => 'trusted_builtin_provider',
				'supports' => array(),
			);
		}
		usort( $items, static function ( $a, $b ) {
			return strcmp( $a['id'], $b['id'] );
		} );
		return array(
			'contract' => self::CONTRACT,
			'catalog_sha256' => (string) ( $registry['catalog_sha256'] ?? '' ),
			'operations' => $items,
			'count' => count( $items ),
			'provider_metadata_only' => true,
			'read_only' => true,
			'autorizing' => false,
			'mutation_performed' => false,
		);
	}

	/** Deterministic matching. An ambiguous match is never silently chosen. */
	public static function select( array $catalog, $query, $limit = 30, $offset = 0 ) {
		$q = self::normalize( $query );
		$limit = max( 1, min( 50, (int) $limit ) );
		$offset = max( 0, min( 1000, (int) $offset ) );
		$matches = array();
		foreach ( $catalog['operations'] ?? array() as $row ) {
			if ( ! is_array( $row ) ) continue;
			$id = self::normalize( $row['id'] ?? '' );
			$planner = self::normalize( $row['planner'] ?? '' );
			$kind = self::normalize( $row['kind'] ?? '' );
			$supports = implode( ' ', array_map( array( __CLASS__, 'normalize' ),
				is_array( $row['supports'] ?? null ) ? $row['supports'] : array() ) );
			if ( '' !== $q &&
				$q !== $id && strpos( $id, $q ) === false &&
				strpos( $planner, $q ) === false && strpos( $kind, $q ) === false &&
				strpos( $supports, $q ) === false ) continue;
			$matches[] = $row;
		}
		return array(
			'contract' => self::CONTRACT,
			'query' => $q,
			'match_count' => count( $matches ),
			'count' => count( array_slice( $matches, $offset, $limit ) ),
			'results' => array_slice( $matches, $offset, $limit ),
			'next_offset' => $offset + $limit < count( $matches ) ? $offset + $limit : null,
			'unambiguous' => 1 === count( $matches ),
			'auto_selection' => 1 === count( $matches ) ? $matches[0] : null,
			'ambiguous_selection_rejected' => count( $matches ) > 1,
			'executor_invoked' => false,
			'read_only' => true,
			'mutation_performed' => false,
		);
	}

	/** Read declared planner inputs, but never interpret or execute any values. */
	public static function planner_variables( $planner ) {
		$empty = array( 'schema_available' => false, 'variables' => array(),
			'exact_planner_input_required' => true, 'values_exposed' => false );
		if ( ! is_string( $planner ) ||
			! preg_match( '#^[a-z][a-z0-9._-]*/[a-z][a-z0-9._-]+$#D', $planner ) ||
			! function_exists( 'wp_get_ability' ) ) return $empty;
		$ability = wp_get_ability( $planner );
		if ( ! is_object( $ability ) || ! method_exists( $ability, 'get_input_schema' ) )
			return $empty;
		$schema = $ability->get_input_schema();
		if ( ! is_array( $schema ) || ! is_array( $schema['properties'] ?? null ) ||
			count( $schema['properties'] ) > self::MAX_VARIABLES )
			return $empty;
		$required = is_array( $schema['required'] ?? null ) ? $schema['required'] : array();
		$variables = array();
		foreach ( $schema['properties'] as $name => $property ) {
			if ( ! is_string( $name ) || ! preg_match( '/^[a-zA-Z_][a-zA-Z0-9_-]{0,79}$/D', $name ) )
				continue;
			$variables[] = array(
				'name' => $name,
				'type' => is_array( $property ) && is_string( $property['type'] ?? null )
					? (string) $property['type'] : 'unknown',
				'required' => in_array( $name, $required, true ),
				'input_schema_source' => 'registered_planner',
				'value' => null,
			);
		}
		return array( 'schema_available' => true, 'variables' => $variables,
			'exact_planner_input_required' => true, 'values_exposed' => false );
	}
}
