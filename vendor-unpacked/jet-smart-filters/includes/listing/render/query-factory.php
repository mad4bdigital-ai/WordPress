<?php
namespace Jet_Smart_Filters\Listing\Render;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Query Factory class
 */
class Query_Factory {

	/**
	 * Query types registry
	 *
	 * @var array
	 */
	public static $query_types = [];

	/**
	 * Register all query types
	 */
	public static function register_query_types() {

		require_once jet_smart_filters()->plugin_path( 'includes/listing/render/query-types/base.php' );
		require_once jet_smart_filters()->plugin_path( 'includes/listing/render/query-types/posts.php' );
		require_once jet_smart_filters()->plugin_path( 'includes/listing/render/query-types/unsupported.php' );

		self::register_query_type( Query_Types\Posts::get_type(), Query_Types\Posts::class );

		do_action( 'jet-smart-filters/listing/render/query-types/register' );
	}

	/**
	 * Register query type
	 *
	 * @param string $type
	 * @param string $class
	 */
	public static function register_query_type( $type, $class ) {
		if ( ! is_string( $type ) || ! is_string( $class ) || ! class_exists( $class ) ) {
			return;
		}

		self::$query_types[ $type ] = $class;
	}

	/**
	 * Get query type class by type
	 *
	 * @param string $query_type
	 *
	 * @return string|null
	 */
	public static function get_query_type_class( $query_type = '' ) {
		if ( ! $query_type || ! isset( self::$query_types[ $query_type ] ) ) {
			return null;
		}

		return self::$query_types[ $query_type ];
	}

	/**
	 * Create query type instance
	 *
	 * @param array $query_args
	 *
	 * @return Query_Types\Base
	 */
	public static function create( $query_args = [] ) {

		$query_args = is_array( $query_args ) ? $query_args : [];
		$query_type = ! empty( $query_args['type'] ) ? $query_args['type'] : 'posts';

		$query_type_class = self::get_query_type_class( $query_type );

		if ( ! $query_type_class || ! class_exists( $query_type_class ) ) {

			// Last chance to handle the query type by calling register_query_types()
			self::register_query_types();
			$query_type_class = self::get_query_type_class( $query_type );

			if ( ! $query_type_class || ! class_exists( $query_type_class ) ) {
				return new Query_Types\Unsupported( $query_args );
			}
		}

		return new $query_type_class( $query_args );
	}
}
