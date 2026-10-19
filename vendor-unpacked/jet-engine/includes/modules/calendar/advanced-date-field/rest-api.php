<?php
/**
 * Register Advanced date meta field type
 */

class Jet_Engine_Advanced_Date_Field_Rest_API extends Jet_Engine_Advanced_Date_Field_Data {

	public $field_type;

	/**
	 * Constructor for the class
	 */
	public function __construct( $field_type ) {

		$this->field_type = $field_type;

		add_filter(
			'jet-engine/meta-boxes/rest-api/fields/field-type',
			array( $this, 'prepare_rest_api_field_type' ),
			10, 2
		);

		add_filter(
			'jet-engine/meta-boxes/rest-api/fields/schema',
			array( $this, 'prepare_rest_api_schema' ),
			10, 3
		);

		add_filter(
			'jet-engine/meta-boxes/rest-api/fields/custom-instance',
			array( $this, 'custom_rest_instance' ), 10, 2
		);

	}

	/**
	 * @param \Jet_Engine_Rest_Post_Meta $rest_meta
	 */
	public function custom_rest_instance( $is_custom, $rest_meta ) {
		if ( empty( $rest_meta->field['custom_type'] ) ) {
			return $is_custom;
		}

		if ( $rest_meta->field['custom_type'] !== 'advanced-date' ) {
			return $is_custom;
		}

		require_once jet_engine()->meta_boxes->component_path( "rest-api/fields/types/base.php" );
		require_once jet_engine()->plugin_path( 'includes/modules/calendar/advanced-date-field/rest-type.php' );

		$instance = new \Jet_Engine\REST_API_Fields\Advanced_Date( $rest_meta );

		$instance->set_data( 'rest_data', $this );

		return true;
	}

	/**
	 * Adjust field type for registering advanced date field in Rest API
	 *
	 * @param  [type] $type  [description]
	 * @param  [type] $field [description]
	 * @return [type]        [description]
	 */
	public function prepare_rest_api_field_type( $type, $field ) {

		if ( $this->is_advanced_date_field( $field ) ) {
			$type = 'object';
		}

		return $type;

	}

	/**
	 * Setup advanced date field schema for rest API
	 *
	 * @param  [type] $schema     [description]
	 * @param  [type] $field_type [description]
	 * @param  [type] $field      [description]
	 * @return [type]             [description]
	 */
	public function prepare_rest_api_schema( $schema, $field_type, $field ) {

		if ( ! $this->is_advanced_date_field( $field ) ) {
			return $schema;
		}

		$data_format = $field['extra_attr']['data-format'] ?? 'undefined';

		if ( ! in_array( $data_format, array( 'rrule', 'manual' ) ) ) {
			$data_format = 'undefined';
		}

		$props['rrule'] = array(
			'date' => array(
				'type'        => 'string',
				'description' => 'Start date with time (truncated to date if time not enabled in field config)',
			),
			'end_date' => array(
				'type'        => 'string',
				'description' => 'End date with time (truncated to date if time not enabled in field config)',
			),
			'is_recurring' => array(
				'type'        => 'string',
				'description' => 'Whether this date is recurring,  1 / 0',
			),
			'recurring_period' => array(
				'type'        => 'string',
				'description' => 'Repeat period - daily / weekly / monthly / yearly',
			),
			'recurring' => array(
				'type'        => 'string',
				'description' => 'Repeat every N repeat periods',
			),
			'week_days' => array(
				'type'        => 'array',
				'items'       => array(
					'type' => 'string'
				),
				'description' => 'recurring_period weekly - array of week day numbers, Monday is 1',
			),
			'monthly_type' => array(
				'type'        => 'string',
				'description' => 'recurring_period monthly / yearly - on_day / on_day_type (to repeat on each Nth day of the month, e.g. the 4th of each month, or to repeat by week days, e.g. the second friday of each month)',
			),
			'month_day_type' => array(
				'type'        => 'string',
				'description' => 'recurring_period monthly / yearly, month_by_type on_day_type - first / second / third / fourth / last',
			),
			'month_day_type_value' => array(
				'type'        => 'string',
				'description' => 'recurring_period monthly / yearly, month_by_type on_day_type - week day number, Monday is 1',
			),
			'month' => array(
				'type'        => 'string',
				'description' => 'recurring_period yearly - month, 1 - 12',
			),
			'month_day' => array(
				'type'        => 'string',
				'description' => 'recurring_period monthly / yearly - month day, up to 31 depending on the month',
			),
			'end' => array(
				'type'        => 'string',
				'description' => 'after / on_date',
			),
			'end_after' => array(
				'type'        => 'string',
				'description' => "when 'end' set to 'after' - number of repetitions",
			),
			'end_after_date' => array(
				'type'        => 'string',
				'description' => "when 'end' set to 'on_date' - boundary date",
			),
		);

		$props['manual'] = array(
			'dates' => array(
				'type'  => 'array',
				'items' => array(
					'type'       => 'object',
					'required'   => array( 'date' ),
					'properties' => array(
						'date'     => array( 'type' => 'string' ),
						'end_date' => array( 'type' => 'string' ),
					),
				),
			),
		);

		$result_props = array(
			// 'rrule' => array(
			// 	'type' => 'string'
			// ),
		);

		if ( 'undefined' === $data_format ) {
			$result_props = array_merge(
				$result_props,
				$props['rrule'],
				$props['manual']
			);
		} else {
			$result_props = array_merge(
				$result_props,
				$props[ $data_format ]
			);
		}

		$schema = array(
			'schema' => array(
				'type'             => 'object',
				'properties'       => $result_props,
			),
			'prepare_callback' => function( $value, $request, $args ) {

				global $post;

				$result = array( 'rrule' => '', 'dates' => [] );

				if ( ! $post ) {
					return $result;
				}

				$post_id = $post->ID;
				$field   = $args['name'];
				$config  = $this->get_field_config( $post_id, $field, true );

				$result['rrule'] = $this->generate_rrule_from_config( $config );
				$result['dates'] = $this->get_next_dates( $post_id, $field );

				return $result;
			}
		);

		return $schema;
	}

	public function get_next_dates( $post_id, $field ) {

		$dates     = $this->get_dates( $post_id, $field );
		$end_dates = $this->get_end_dates( $post_id, $field );
		$result    = [];

		if ( empty( $dates ) ) {
			return $result;
		}

		$format    = apply_filters( 'jet-engine/calendar/advanced-date/rest-api-date-format', false, $field, $post_id );
		$with_past = apply_filters( 'jet-engine/calendar/advanced-date/rest-api-with-past', false, $field, $post_id );
		$count     = apply_filters( 'jet-engine/calendar/advanced-date/rest-api-max-count', 10, $field, $post_id );
		$now       = time();

		foreach ( $dates as $index => $date ) {

			if ( $date < $now && ! $with_past ) {
				continue;
			}

			$item = [];

			$item['start'] = ( false !== $format ) ? date( $format, $date ) : $date;

			if ( ! empty( $end_dates ) && ! empty( $end_dates[ $index ] ) ) {
				$item['end'] = ( false !== $format ) ? date( $format, $end_dates[ $index ] ) : $end_dates[ $index ];
			}

			$result[] = $item;

			if ( $count === count( $result ) ) {
				break;
			}

		}

		return $result;

	}

	public function generate_rrule_from_config( $config ) {

		if ( ! $config ) {
			return null;
		}

		if ( ! class_exists( 'Jet_Engine_Advanced_Date_Recurring_Dates' ) ) {
			require_once jet_engine()->plugin_path( 'includes/modules/calendar/advanced-date-field/recurring-dates.php' );
		}

		$recurring_dates = new Jet_Engine_Advanced_Date_Recurring_Dates( (array) $config );

		return $recurring_dates->generate_rrule();
	}
}
