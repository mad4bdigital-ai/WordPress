<?php
/**
 * Compatibility filters and actions
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Define Jet_Engine_Compatibility_JE class
 */
class Jet_Smart_Filters_Compatibility_JE {

	/**
	 * Constructor for the class
	 */
	function __construct() {

		if ( ! function_exists( 'jet_engine' ) ) {
			return;
		}

		// for CCT
		add_filter( 'jet-smart-filters/post-type/options-data-sources', array( $this, 'cct_data_sources' ) );
		add_filter( 'jet-smart-filters/post-type/meta-fields-settings', array( $this, 'cct_register_controls' ) );
		add_filter( 'jet-smart-filters/admin/settings-data', array( $this, 'cct_modification_editor_settings' ) );
		add_action( 'jet-smart-filters/listing/render/query-types/register', array( $this, 'register_listing_query_types' ) );

		// Filter query macros
		add_action( 'jet-engine/register-macros', array( $this, 'register_macros' ) );

		// Range filter compatibility
		require_once jet_smart_filters()->plugin_path( 'includes/compatibility/jet-engine/range.php' );
		new Jet_Smart_Filters_Compatibility_JE_Range();

		// Listing builder blocks compact
		require jet_smart_filters()->plugin_path( 'includes/compatibility/jet-engine/listing/blocks.php' );
		require_once jet_smart_filters()->plugin_path( 'includes/compatibility/jet-engine/listing/indexer.php' );
		new \Jet_Smart_Filters\Compatibility\Jet_Engine\Listing\Blocks();
		new \Jet_Smart_Filters\Compatibility\Jet_Engine\Listing\Indexer();
	}

	/**
	 * Register Listing Builder query types backed by JetEngine.
	 *
	 * @return void
	 */
	public function register_listing_query_types() {

		if ( ! class_exists( '\Jet_Engine\Query_Builder\Manager' ) ) {
			return;
		}

		require_once jet_smart_filters()->plugin_path( 'includes/compatibility/jet-engine/listing/query-types/jet-engine-query.php' );

		\Jet_Smart_Filters\Listing\Render\Query_Factory::register_query_type(
			\Jet_Smart_Filters\Compatibility\Jet_Engine\Listing\Query_Types\Jet_Engine_Query::get_type(),
			\Jet_Smart_Filters\Compatibility\Jet_Engine\Listing\Query_Types\Jet_Engine_Query::class
		);
	}

	/**
	 * Register filter related macros for JetEngine
	 * @return void
	 */
	public function register_macros() {

		require_once jet_smart_filters()->plugin_path( 'includes/compatibility/jet-engine/macros/filter-query.php' );
		require_once jet_smart_filters()->plugin_path( 'includes/compatibility/jet-engine/macros/seo-rules-title.php' );
		require_once jet_smart_filters()->plugin_path( 'includes/compatibility/jet-engine/macros/seo-rules-description.php' );

		new Jet_Smart_Filters_Macros_Filter_Query();
		new Jet_Smart_Filters_Macros_SEO_Rules_Title();
		new Jet_Smart_Filters_Macros_SEO_Rules_Description();
	}

	public function cct_data_sources( $data_sources ) {

		if ( function_exists( 'jet_engine' ) && jet_engine()->modules->is_module_active( 'custom-content-types' ) ) {
			$data_sources['cct'] = __( 'JetEngine Custom Content Types', 'jet-smart-filters' );
		}

		return $data_sources;
	}

	public function cct_register_controls( $fields ) {

		if ( function_exists( 'jet_engine' ) && jet_engine()->modules->is_module_active( 'custom-content-types' ) ) {
			$fields = jet_smart_filters()->utils->array_insert_after( $fields, '_data_source', array(
				'_cct_notice' => array(
					'title'      => __( 'Coming soon', 'jet-smart-filters' ),
					'type'       => 'html',
					'fullwidth'  => true,
					'html'       => __( 'Support for the Visual filter will be added with future updates', 'jet-smart-filters' ),
					'conditions' => array(
						'_filter_type' => 'color-image',
						'_data_source' => 'cct',
					),
				),
			) );

			if ( jet_smart_filters()->is_classic_admin ) {
				$fields['_cct_notice']['type']        = 'text';
				$fields['_cct_notice']['input_type']  = 'hidden';
				$fields['_cct_notice']['description'] = $fields['_cct_notice']['html'];
				unset( $fields['_cct_notice']['html'] );
			}
		}

		$fields = jet_smart_filters()->utils->add_control_condition( $fields, '_color_image_type', '_cct_notice!', 'is_visible' );
		$fields = jet_smart_filters()->utils->add_control_condition( $fields, '_color_image_behavior', '_cct_notice!', 'is_visible' );
		$fields = jet_smart_filters()->utils->add_control_condition( $fields, '_source_color_image_input', '_cct_notice!', 'is_visible' );
		$fields = jet_smart_filters()->utils->add_control_condition( $fields, '_query_var', '_cct_notice!', 'is_visible' );

		return $fields;
	}

	public function cct_modification_editor_settings( $data ) {

		if (
			function_exists( 'jet_engine' )
			&& jet_engine()->modules->is_module_active( 'custom-content-types' )
			&& ! jet_smart_filters()->is_classic_admin
		) {
			$settings = $data['settings'];
			$keys     = array_keys( $settings );
			$index    = array_search( '_data_source', $keys );

			if ( $index ) {
				$before = array_slice( $settings, 0, $index + 1, true );
				$after  = array_slice( $settings, $index + 1, null, true );

				$warning_text =apply_filters(
					'jet-smart-filters/compatibility/cct/checkboxes-warning-text',
					'<strong>Please note:</strong> The JetEngine Custom Content Types source with the Checkboxes filter works correctly only if the appropriate Custom Content Type field is also of the Checkbox type. In other cases, you need to select a different filter type or a different Data Source.'
				);

				$cct_checkboxes_info = array(
					'_cct_checkboxes_info' => array(
						'type'       => 'html',
						'fullwidth'  => true,
						'html'       => $warning_text,
						'conditions' => array(
							'_filter_type' => 'checkboxes',
							'_data_source' => 'cct'
						),
					)
				);

				$data['settings'] = $before + $cct_checkboxes_info + $after;
			}
		}

		return $data;
	}
}
