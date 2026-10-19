<?php
/**
 * Register post meta field for Rest API
 */

namespace Jet_Engine\REST_API_Fields;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

class Advanced_Date extends Base {
	protected function init() {
		switch ( $this->object_type ) {
			case 'post':
				$this->add_post_hooks();
				break;
		}
	}

	protected function add_post_hooks() {
			add_filter( 'update_post_metadata', array( $this, 'update_field' ), 10, 4 );
	}

	public function update_field( $check, $post_id, $meta_key, $meta_value ) {
		if ( $meta_key !== $this->field['name'] ) {
			return $check;
		}

		if ( ! $this->is_rest() ) {
			return $check;
		}
		
		/**
		 * @var \Jet_Engine_Advanced_Date_Field_Rest_API
		 */
		$rest_data = $this->get_data( 'rest_data' );
	
		if ( ! $rest_data ) {
			return $check;
		}

		$meta_value = $this->prepare_meta_value( $meta_value );

		$rest_data->update_field_with_value( $meta_value, $post_id, $meta_key );

		return true;
	}

	protected function prepare_meta_value( $value ) {
		$format     = ! empty( $this->field['extra_attr']['data-format'] ) ? $this->field['extra_attr']['data-format'] : 'rrule';
		$allow_time = ! empty( $this->field['extra_attr']['data-allow-time'] ) ? $this->field['extra_attr']['data-allow-time'] : false;
		
		switch ( $format ) {
			case 'manual':
				if ( empty( $value['dates'] ) ) {
					return array(
						'dates' => array(),
					);
				}

				foreach ( $value['dates'] as $i => $date ) {
					if ( isset( $date['date'] ) ) {
						$start = $date['date'];

						if ( preg_match( '/(?<time>\d\d:\d\d)/', $start, $matches ) ) {
							$date['time'] = $matches['time'];
							$start = str_replace( $matches['time'], '', $start );
							$start = trim( $start );

							if ( $allow_time ) {
								$value['dates'][ $i ]['time'] = $matches['time'];
							}
						}

						$value['dates'][ $i ]['date'] = $start;
					}

					if ( isset( $date['end_date'] ) ) {
						$end = $date['end_date'];
						$value['dates'][ $i ]['is_end_date'] = '1';

						if ( preg_match( '/(?<time>\d\d:\d\d)/', $end, $matches ) ) {
							$date['end_time'] = $matches['time'];
							$end = str_replace( $matches['time'], '', $end );
							$end = trim( $end );

							if ( $allow_time ) {
								$value['dates'][ $i ]['end_time'] = $matches['time'];
							}
						}

						$value['dates'][ $i ]['end_date'] = $end;
					}
				}
				break;
			case 'rrule':
				if ( empty( $value['date'] ) ) {
					return array(
						'date' => ''
					);
				}

				if ( preg_match( '/(?<time>\d\d:\d\d)/', $value['date'], $matches ) ) {
					$value['date'] = str_replace( $matches['time'], '', $value['date'] );
					$value['date'] = trim( $value['date'] );

					if ( $allow_time ) {
						$value['time'] = $matches['time'];
					}
				}

				if ( ! empty( $value['end_date'] ) ) {
					$value['is_end_date'] = '1';
				}

				if ( ! empty( $value['end_date'] ) && preg_match( '/(?<time>\d\d:\d\d)/', $value['end_date'], $matches ) ) {
					$value['end_date'] = str_replace( $matches['time'], '', $value['end_date'] );
					$value['end_date'] = trim( $value['end_date'] );

					if ( $allow_time ) {
						$value['end_time'] = $matches['time'];
					}
				}

				break;
		}

		return $value;
	}
}
