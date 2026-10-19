<?php
namespace Jet_Smart_Filters\Listing\Render\Query_Types;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Unsupported query type class.
 */
class Unsupported extends Base {

	/**
	 * Query type.
	 *
	 * @return string
	 */
	public static function get_type() {
		return 'unsupported';
	}

	/**
	 * Query-type specific items getter.
	 *
	 * @return array
	 */
	protected function _get_items() {
		return [];
	}

	/**
	 * Get item ID.
	 *
	 * @param mixed $item
	 *
	 * @return int|null
	 */
	public function get_item_id( $item ) {
		return null;
	}
}
