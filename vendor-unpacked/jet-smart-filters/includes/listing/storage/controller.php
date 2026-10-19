<?php
namespace Jet_Smart_Filters\Listing\Storage;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Main listing/storage class
 */
class Controller {

	public $listings;
	public $items;

	public function __construct() {

		require jet_smart_filters()->plugin_path( 'includes/listing/storage/db-storage.php' );

		$this->listings = new DB_Storage( 'listings', [
			'name'     => 'TEXT',
			'query'    => 'TEXT',
			'settings' => 'TEXT',
			'item_id'  => 'BIGINT(20)',
			'created'  => 'DATETIME DEFAULT CURRENT_TIMESTAMP',
		] );

		$this->items = new DB_Storage( 'listing_items', [
			'name'     => 'TEXT',
			'content'  => 'TEXT',
			'settings' => 'TEXT',
			'styles'   => 'TEXT',
			'created'  => 'DATETIME DEFAULT CURRENT_TIMESTAMP',
		] );
	}

	/**
	 * Proxy method to get all listings.
	 *
	 * @return array
	 */
	public function get_listings( $args = [] ) {

		$this->listings->create_table();

		return $this->listings->query_items( $args );
	}

	/**
	 * Proxy method to get all listings list.
	 *
	 * @return array
	 */
	public function get_listings_list( $args = [], $as_pairs = true ) {

		$this->listings->create_table();

		return $this->listings->query_items_list( $args, $as_pairs );
	}

	/**
	 * Proxy method to get listing item by ID.
	 *
	 * @param  int $listing_id ID of the item to get.
	 * @return WP_Error|array
	 */
	public function get_listing( $listing_id = 0 ) {

		$result = $this->listings->get_item( $listing_id );
		$error  = $this->listings->get_last_error();

		if ( $error && is_wp_error( $error ) ) {
			var_dump('error!!!');
			return $error;
		}

		return $result;
	}

	/**
	 * Proxy method to update listing.
	 * If $data contains 'ID' prop, listing with given ID will be updated.
	 * If not contans - new listing will be created by given data.
	 *
	 * @param  array $data Listing data to create/update.
	 * @return int
	 */
	public function update_listing( $data = [] ) {
		/**
		 * @todo deep sanitize data
		 */
		return $this->update_or_create_item( $this->listings, $data );
	}

	/**
	 * Proxy method to remove listing.
	 *
	 * @param  int $id Item ID.
	 * @return int
	 */
	public function remove_listing( $id, $args = [] ) {

		$result = $this->listings->remove_item( $id, $args );
		$error  = $this->listings->get_last_error();

		if ( $error && is_wp_error( $error ) ) {
			return $error;
		}

		return $result;
	}

	/**
	 * Proxy method to get all listing items.
	 *
	 * @return array
	 */
	public function get_items( $args = [] ) {

		$this->items->create_table();

		return $this->items->query_items( $args );
	}

	/**
	 * Proxy method to get all listing items list.
	 *
	 * @return array
	 */
	public function get_items_list( $args = [], $as_pairs = true ) {

		$this->items->create_table();

		return $this->items->query_items_list( $args, $as_pairs );
	}

	/**
	 * Proxy method to get listing item by ID.
	 *
	 * @param  int $item_id ID of the item to get.
	 * @return WP_Error|array
	 */
	public function get_listing_item( $item_id = 0 ) {

		$result = $this->items->get_item( $item_id );
		$error  = $this->items->get_last_error();

		if ( $error && is_wp_error( $error ) ) {
			return $error;
		}

		return $result;
	}

	/**
	 * Proxy method to update listing item.
	 * If $data contains 'ID' prop, listings item with given ID will be updated.
	 * If not contans - new listing item will be created by given data.
	 *
	 * @param  array $data Listing item data to create/update.
	 * @return int
	 */
	public function update_listing_item( $data = [] ) {
		/**
		 * @todo deep sanitize data
		 */
		return $this->update_or_create_item( $this->items, $data );
	}

	/**
	 * Proxy method to remove listing item.
	 *
	 * @param  int $id Item ID.
	 * @return int
	 */
	public function remove_listing_item( $id, $args = [] ) {

		$result = $this->items->remove_item( $id, $args );
		$error  = $this->items->get_last_error();

		if ( $error && is_wp_error( $error ) ) {
			return $error;
		}

		return $result;
	}

	/**
	 * Internal method to perform item update/creation
	 *
	 * @param  DB_Storage $storage Storage object.
	 * @param  array      $data    Data to create/update.
	 * @return int|WP_Error
	 */
	protected function update_or_create_item( $storage, $data ) {

		$storage->create_table();

		if ( ! empty( $data['ID'] ) ) {

			$id = abs( $data['ID'] );
			unset( $data['ID'] );

			$result = $storage->update_item( $id, $data );
		} else {

			if ( isset( $data['ID'] ) ) {
				unset( $data['ID'] );
			}

			$result = $storage->insert_item( $data );
		}

		if ( ! $result ) {

			$error = $storage->get_last_error();

			if ( $error && is_wp_error( $error ) ) {
				return $error;
			} else {
				return new \WP_Error( 'cant_create_update', 'Error while item create/update. Please try again.' );
			}
		} else {
			return $result;
		}
	}
}
