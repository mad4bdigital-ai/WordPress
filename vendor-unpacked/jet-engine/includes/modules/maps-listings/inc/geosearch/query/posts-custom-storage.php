<?php
namespace Jet_Engine\Modules\Maps_Listings\Geosearch\Query;

use Jet_Engine\CPT\Custom_Tables\Manager as Custom_Tables;

class Posts_Custom_Storage extends Posts {

	protected $resolved_geo_queries = array();

	/**
	 * Public function get geoquery from give query
	 *
	 * @param  [type] $query [description]
	 * @return [type]        [description]
	 */
	public function get_geo_query( $query ) {

		$cache_key = spl_object_hash( $query );

		if ( array_key_exists( $cache_key, $this->resolved_geo_queries ) ) {
			return $this->resolved_geo_queries[ $cache_key ];
		}

		$geo_query = apply_filters(
			'jet-engine/maps-listings/geosearch/posts-custom-storage/get-geo-query',
			false,
			$query
		);

		if ( empty( $geo_query ) || ! is_array( $geo_query ) ) {
			$this->resolved_geo_queries[ $cache_key ] = false;
			return false;
		}

		$this->resolved_geo_queries[ $cache_key ] = $this->resolve_geo_query( $geo_query, $query );

		return $this->resolved_geo_queries[ $cache_key ];
	}

	public function add_distance_field( $fields ) {
		return $fields;
	}

	public function lat_field( $geo_query, $format = 'field' ) {

		$lat_field = 'latitude';
		if ( ! empty( $geo_query['lat_field'] ) ) {
			$lat_field =  $geo_query['lat_field'];
		}

		return $lat_field;
	}

	public function lng_field( $geo_query, $format = 'field' ) {

		$lng_field = 'longitude';
		if ( ! empty( $geo_query['lng_field'] ) ) {
			$lng_field =  $geo_query['lng_field'];
		}

		return $lng_field;
	}

	public function posts_join( $sql, $query ) {

		global $wpdb;

		$geo_query = $this->get_geo_query( $query );

		if ( $geo_query && ! empty( $geo_query['geo_query_table'] ) ) {

			if ( $sql ) {
				$sql .= ' ';
			}

			$sql .= $wpdb->prepare(
				"INNER JOIN %i AS geo_query ON ( $wpdb->posts.ID = geo_query.object_ID ) ",
				$geo_query['geo_query_table']
			);
		}

		return $sql;
	}

	public function haversine_term( $geo_query ) {

		global $wpdb;
		$units = "miles";

		if ( ! empty( $geo_query['units'] ) ) {
			$units = strtolower( $geo_query['units'] );
		}

		$radius = 3959;

		if ( in_array( $units, array( 'km', 'kilometers' ), true ) ) {
			$radius = 6371;
		}

		$lat_field = $this->lat_field( $geo_query, 'field' );
		$lng_field = $this->lng_field( $geo_query, 'field' );
		$lat       = isset( $geo_query['latitude'] ) ? (float) $geo_query['latitude'] : 0;
		$lng       = isset( $geo_query['longitude'] ) ? (float) $geo_query['longitude'] : 0;

		$haversine  = "( " . $radius . " * ";
		$haversine .=     "acos( cos( radians(%f) ) * cos( radians( geo_query.%i ) ) * ";
		$haversine .=     "cos( radians( geo_query.%i ) - radians(%f) ) + ";
		$haversine .=     "sin( radians(%f) ) * sin( radians( geo_query.%i ) ) ) ";
		$haversine .= ")";

		return $wpdb->prepare( $haversine, array( $lat, $lat_field, $lng_field, $lng, $lat, $lat_field ) );
	}

	// match on the right metafields, and filter by distance
	public function posts_where( $sql, $query ) {

		global $wpdb;

		$geo_query = $this->get_geo_query( $query );

		if ( $geo_query ) {

			$distance = isset( $geo_query['distance'] ) ? (float) $geo_query['distance'] : 20;

			if ( $sql ) {
				$sql .= " AND ";
			}

			$new_sql = '';

			if ( $this->must_apply_bounds( $geo_query ) ) {
				$bounds = $this->get_bounds( $geo_query );

				$lat_field = $this->lat_field( $geo_query, 'field' );
				$lng_field = $this->lng_field( $geo_query, 'field' );

				$new_sql = $wpdb->prepare(
					' ( geo_query.%i BETWEEN %f AND %f',
					$lat_field,
					$bounds['south'],
					$bounds['north']
				);

				//if map includes 180deg meridian and western bound is greater than eastern
				if ( $bounds['west'] >= $bounds['east'] ) {
					$new_sql .= $wpdb->prepare(
						' AND ( geo_query.%i >= %f OR geo_query.%i <= %f ) )',
						$lng_field,
						$bounds['west'],
						$lng_field,
						$bounds['east']
					);
				} else {
					$new_sql .= $wpdb->prepare(
						' AND geo_query.%i BETWEEN %f AND %f )',
						$lng_field,
						$bounds['west'],
						$bounds['east']
					);
				}

				$sql .= $new_sql;
			} else {
				$haversine = $this->haversine_term( $geo_query );
				$new_sql = "( $haversine <= %f )";
				$sql    .= $wpdb->prepare( $new_sql, $distance );
			}
		}

		return $sql;

	}

	// handle ordering
	public function posts_orderby( $sql, $query ) {

		$geo_query = $this->get_geo_query( $query );

		if ( $geo_query ) {

			$orderby = $query->get('orderby');
			$order   = $query->get('order');

			if ( $orderby == 'distance' || ( is_array( $orderby ) && isset( $orderby['distance'] ) ) ) {

				if ( ! $order ) {
					$order = 'ASC';
				}

				$order = ( is_array( $orderby ) && ! empty( $orderby['distance'] ) ) ? $orderby['distance'] : $order;
				$order = $this->sanitize_order( $order );

				$distance_orderby = $this->distance_term . ' ' . $order;

				if ( is_array( $orderby ) && 1 < count( $orderby ) ) {
					$sql_array      = ! empty( $sql ) ? explode( ', ', $sql ) : array();
					$distance_index = array_search( 'distance', array_keys( $orderby ) );

					if ( 0 == $distance_index ) {
						array_unshift( $sql_array, $distance_orderby );
					} else {
						$sql_array = array_merge(
							array_slice( $sql_array, 0, $distance_index ),
							array( $distance_orderby ),
							array_slice( $sql_array, $distance_index, null )
						);
					}

					$sql = implode( ', ', $sql_array );

				} else {
					$sql = $distance_orderby;
				}
			}
		}

		return $sql;

	}

	protected function resolve_geo_query( $geo_query, $query ) {

		global $wpdb;

		$lat_field = isset( $geo_query['lat_field'] ) && is_scalar( $geo_query['lat_field'] ) ? (string) $geo_query['lat_field'] : '';
		$lng_field = isset( $geo_query['lng_field'] ) && is_scalar( $geo_query['lng_field'] ) ? (string) $geo_query['lng_field'] : '';

		if ( '' === $lat_field || '' === $lng_field ) {
			return false;
		}

		$storage = $this->match_storage( $geo_query, $query );

		if ( ! $storage ) {
			return false;
		}

		if ( empty( $storage['fields'] ) || ! is_array( $storage['fields'] ) ) {
			return false;
		}

		if ( ! in_array( $lat_field, $storage['fields'], true ) || ! in_array( $lng_field, $storage['fields'], true ) ) {
			return false;
		}

		$geo_query['lat_field']            = $lat_field;
		$geo_query['lng_field']            = $lng_field;
		$geo_query['geo_query_object_slug'] = $storage['object_slug'];
		$geo_query['geo_query_table']      = $wpdb->prefix . Custom_Tables::instance()->get_table_name( $storage['object_slug'] );

		return $geo_query;
	}

	protected function match_storage( $geo_query, $query ) {

		global $wpdb;

		$storages           = Custom_Tables::instance()->storages;
		$allowed_post_types = $this->get_query_post_types( $query );
		$object_slug = isset( $geo_query['geo_query_object_slug'] ) && is_scalar( $geo_query['geo_query_object_slug'] )
			? (string) $geo_query['geo_query_object_slug']
			: '';
		$table_name  = isset( $geo_query['geo_query_table'] ) && is_scalar( $geo_query['geo_query_table'] )
			? (string) $geo_query['geo_query_table']
			: '';

		foreach ( $storages as $storage ) {
			if ( empty( $storage['object_type'] ) || 'post' !== $storage['object_type'] || empty( $storage['object_slug'] ) ) {
				continue;
			}

			if ( ! empty( $allowed_post_types ) && ! in_array( $storage['object_slug'], $allowed_post_types, true ) ) {
				continue;
			}

			$storage_table = $wpdb->prefix . Custom_Tables::instance()->get_table_name( $storage['object_slug'] );

			if ( $object_slug && $object_slug === $storage['object_slug'] ) {
				return $storage;
			}

			if ( $table_name && $table_name === $storage_table ) {
				return $storage;
			}
		}

		return false;
	}

	protected function get_query_post_types( $query ) {

		$post_type = $query->get( 'post_type' );

		if ( empty( $post_type ) ) {
			return array();
		}

		if ( ! is_array( $post_type ) ) {
			$post_type = array( $post_type );
		}

		return array_values(
			array_filter(
				array_map(
					function( $item ) {
						return is_scalar( $item ) ? (string) $item : '';
					},
					$post_type
				)
			)
		);
	}

}
