<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Shared write-time invariant for existing Elementor documents.
 *
 * Does not author templates, introduce a parallel renderer, alter WPML
 * translations or grant mutation authority. Applies to the governed Elementor
 * adapter; native Elementor Editor saves remain editor-owned.
 */
final class MAD4B_SCP_Elementor_Native_Template_Parity {
	const CONTRACT = 'mad4b.elementor-native-template-parity.v1';

	/**
	 * Inspect source-owned display conditions and their JetEngine consumers by
	 * element identity rather than site-specific IDs, query numbers or locales.
	 */
	private static function inspect( array $elements ) {
		$protected = array();
		$error = self::walk( $elements, array(), array(), $protected, 0 );
		return is_wp_error( $error ) ? $error : $protected;
	}

	private static function walk( array $elements, array $parents, array $condition_ancestors, array &$protected, $depth ) {
		if ( $depth > 50 ) return new WP_Error( 'mad4b_elementor_parity_depth_exceeded', 'Elementor parity inspection exceeded the supported nesting depth.' );
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) continue;
			$id = isset( $element['id'] ) ? (string) $element['id'] : '';
			if ( '' === $id ) continue; // Existing document validation handles malformed IDs.
			$settings = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();
			$conditions = array();
			foreach ( $settings as $key => $value ) {
				if ( self::is_condition_key( $key ) ) $conditions[ (string) $key ] = $value;
			}
			$widget_type = isset( $element['widgetType'] ) ? (string) $element['widgetType'] : '';
			$provider_consumer = '' !== $widget_type && 0 === strpos( $widget_type, 'jet-' ) && ! empty( $condition_ancestors );
			if ( ! empty( $conditions ) || $provider_consumer ) {
				if ( isset( $protected[ $id ] ) ) return new WP_Error( 'mad4b_elementor_parity_duplicate_identity', 'Protected Elementor element identity is duplicated.' );
				$protected[ $id ] = array(
					'parent_path' => $parents,
					'condition_ancestors' => $condition_ancestors,
					'conditions' => $conditions,
					'widget_type' => $provider_consumer ? $widget_type : '',
				);
			}
			$next_parents = array_merge( $parents, array( $id ) );
			$next_condition_ancestors = ! empty( $conditions )
				? array_merge( $condition_ancestors, array( $id ) ) : $condition_ancestors;
			if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$error = self::walk( $element['elements'], $next_parents, $next_condition_ancestors, $protected, $depth + 1 );
				if ( is_wp_error( $error ) ) return $error;
			}
		}
		return true;
	}

	private static function is_condition_key( $key ) {
		$key = (string) $key;
		return 1 === preg_match( '/(?:^|_)(?:conditions?|visibility)(?:_|$)/i', $key );
	}

	/**
	 * A bounded provider write may add/edit ordinary Elementor widgets inside
	 * an existing container. It may not silently remove, duplicate, relocate or
	 * reconfigure a visibility owner / protected JetEngine consumer. Explicit
	 * editor-owned structural changes require a new source + locale baseline.
	 *
	 * @return true|WP_Error
	 */
	public static function verify( array $before, array $after ) {
		$original = self::inspect( $before );
		if ( is_wp_error( $original ) ) return $original;
		$current = self::inspect( $after );
		if ( is_wp_error( $current ) ) return $current;
		foreach ( $original as $id => $snapshot ) {
			if ( ! isset( $current[ $id ] ) ) {
				return new WP_Error( 'mad4b_elementor_native_parity_owner_removed', 'Existing conditional Elementor/JetEngine element was removed or its visibility contract was dropped.', array( 'element_id' => $id, 'contract' => self::CONTRACT ) );
			}
			if ( $snapshot !== $current[ $id ] ) {
				return new WP_Error( 'mad4b_elementor_native_parity_drift', 'Existing Elementor visibility, ancestry or JetEngine consumer changed. Reconcile source and WPML templates through the original editor.', array( 'element_id' => $id, 'contract' => self::CONTRACT ) );
			}
		}
		foreach ( $current as $id => $snapshot ) {
			if ( ! isset( $original[ $id ] ) ) {
				return new WP_Error( 'mad4b_elementor_native_parity_parallel_surface', 'A governed fallback cannot introduce another conditional rendering surface or duplicate JetEngine consumer. Use the existing native Elementor template.', array( 'element_id' => $id, 'contract' => self::CONTRACT ) );
			}
		}
		return true;
	}
}
