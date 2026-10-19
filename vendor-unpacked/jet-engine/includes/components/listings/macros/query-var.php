<?php
namespace Jet_Engine\Macros;

/**
 * Returns queried variable.
 */
class Query_Var extends \Jet_Engine_Base_Macros {

	/**
	 * @inheritDoc
	 */
	public function macros_tag() {
		return 'query_var';
	}

	/**
	 * @inheritDoc
	 */
	public function macros_name() {
		return esc_html__( 'Query Variable', 'jet-engine' );
	}

	/**
	 * @inheritDoc
	 */
	public function macros_args() {
		return array(
			'var_name' => array(
				'label'   => __( 'Variable Name', 'jet-engine' ),
				'type'    => 'text',
				'default' => '',
			),
		);
	}

	/**
	 * @inheritDoc
	 */
	public function macros_callback( $args = array() ) {

		$variable = ! empty( $args['var_name'] ) ? $args['var_name'] : null;

		if ( ! $variable ) {
			return null;
		}

		global $wp_query;

		// phpcs:disable
		if ( isset( $wp_query->query_vars[ $variable ] ) ) {
			$value = $this->normalize_request_value( $wp_query->query_vars[ $variable ] );
		} elseif ( isset( $_REQUEST[ $variable ] ) ) {
			$value = $this->normalize_request_value( wp_unslash( $_REQUEST[ $variable ] ) );
		} else {
			return null;
		}
		// phpcs:enable

		if ( jet_engine()->listings->macros->is_esc_output() ) {
			return $this->escape_output( $value );
		}

		return $value;
	}

	/**
	 * Normalize request-backed macro values to scalar data only.
	 *
	 * @param mixed $value Raw query var value.
	 * @return mixed Scalar string, flat scalar array, or null.
	 */
	protected function normalize_request_value( $value ) {

		if ( is_scalar( $value ) || null === $value ) {
			return (string) $value;
		}

		if ( ! is_array( $value ) ) {
			return null;
		}

		$result = array();

		foreach ( $value as $item ) {
			if ( is_scalar( $item ) || null === $item ) {
				$result[] = (string) $item;
			}
		}

		return $result;
	}

	/**
	 * Escape normalized request values for direct HTML output.
	 *
	 * @param string|array|null $value Normalized request value.
	 * @return string|array
	 */
	protected function escape_output( $value ) {

		if ( is_array( $value ) ) {
			return array_map( 'esc_html', $value );
		}

		return esc_html( $value );
	}
}
