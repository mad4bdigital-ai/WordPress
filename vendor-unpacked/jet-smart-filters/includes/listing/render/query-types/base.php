<?php
namespace Jet_Smart_Filters\Listing\Render\Query_Types;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Base query type class
 */
abstract class Base {

	/**
	 * Query args
	 *
	 * @var array
	 */
	protected $query_args = [];

	/**
	 * Query type
	 *
	 * @var string
	 */
	abstract public static function get_type();

	/**
	 * Query-type specific items getter
	 *
	 * @var array
	 */
	abstract protected function _get_items();

	/**
	 * Get item ID
	 *
	 * @param mixed $item
	 *
	 * @return int|null
	 */
	abstract public function get_item_id( $item );

	/**
	 * Get items
	 *
	 * @return array
	 */
	public function get_items() {

		$items = $this->_get_items();

		if ( ! is_array( $items ) ) {
			return [];
		}

		return $items;
	}

	/**
	 * Prepare query args to use in query.
	 * This method can be overridden in child classes to modify
	 *
	 * @param array $query_args
	 *
	 * @return array
	 */
	protected function prepare_query_args( $query_args = [] ) {

		if ( ! is_array( $query_args ) ) {
			$query_args = [];
		}

		return $query_args;
	}

	/**
	 * Add a new query arguments to the already existing ones.
	 * Rewrite this method in child classes to define exact merging behavior.
	 *
	 * @param array $query_args
	 * @return void
	 */
	public function add_query_args( $query_args ) {
		$this->query_args = array_merge_recursive( $this->query_args, $query_args );
	}

	/**
	 * Get query args
	 *
	 * @return array
	 */
	public function get_query_args() {
		return $this->query_args;
	}

	/**
	 * Get query stats.
	 *
	 * @return array
	 */
	public function get_stats() {
		return [
			'found_posts'   => 0,
			'max_num_pages' => 0,
			'page'          => 1,
		];
	}

	/**
	 * Explode string by delimiter and trim values
	 *
	 * @param string $string
	 * @param string $delimiter
	 *
	 * @return array
	 */
	public function explode_string( $string, $delimiter = ',' ) {

		if ( ! $string ) {
			return [];
		}

		if ( is_array( $string ) ) {
			return $string;
		}

		$items = explode( $delimiter, $string );
		$items = array_map( 'trim', $items );
		$items = array_filter( $items );

		return $items;
	}

	/**
	 * Base constructor
	 *
	 * @param array $query_args
	 */
	public function __construct( $query_args = [] ) {
		$this->query_args = $this->prepare_query_args( $query_args );
	}
}
