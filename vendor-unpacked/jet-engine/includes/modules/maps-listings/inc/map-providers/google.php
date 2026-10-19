<?php
namespace Jet_Engine\Modules\Maps_Listings\Providers;

use Jet_Engine\Modules\Maps_Listings\Module;

class Google extends Base {

	/**
	 * Returns provider system slug
	 *
	 * @return [type] [description]
	 */
	public function get_id() {
		return 'google';
	}

	/**
	 * Returns provider human-readable name
	 *
	 * @return [type] [description]
	 */
	public function get_label() {
		return __( 'Google Maps', 'jet-engine' );
	}

	public function map_api_deps() {
		return array();
	}

	public function register_public_assets() {
		$api_disabled = Module::instance()->settings->get( 'disable_api_file' );

		add_filter(
			'jet-engine/listing/frontend/js-settings',
			function( $settings ) use ( $api_disabled ) {
				$settings['maps_api_disabled'] = $api_disabled;
				return $settings;
			}
		);

		if ( ! $api_disabled ) {

			$query_args = apply_filters( 'jet-engine/maps-listing/map-providers/google/api-url/query-args', array(
				'key'      => Module::instance()->settings->get( 'api_key' ),
				'language' => substr( get_bloginfo( 'language' ), 0, 2 ),
				'callback' => 'window.JetEngineMapsGoogleApiInit', // fixed js error: Loading the Google Maps JavaScript API without a callback is not supported
			) );

			if ( Module::instance()->settings->get( 'async_load' ) ) {
				$query_args['loading']  = 'async';
				$query_args['callback'] = 'window.JetEngineMapsGoogleApiAsyncInit';
			}

			wp_register_script(
				'jet-engine-google-maps-api',
				add_query_arg(
					$query_args,
					'https://maps.googleapis.com/maps/api/js'
				),
				array( 'jet-google-map-provider' ),
				false,
				true
			);

		}

		wp_register_script(
			'jet-markerclustererplus',
			jet_engine()->plugin_url( 'includes/modules/maps-listings/assets/lib/markerclustererplus/markerclustererplus.min.js' ),
			$this->map_api_deps(),
			jet_engine()->get_version(),
			true
		);

	}

	public function public_assets( $query, $settings, $render ) {

		wp_enqueue_script( 'jet-engine-google-maps-api' );

		$marker_clustering = isset( $settings['marker_clustering'] ) ? filter_var( $settings['marker_clustering'], FILTER_VALIDATE_BOOLEAN ) : true;

		if ( $marker_clustering ) {
			wp_enqueue_script( 'jet-markerclustererplus' );
		}

		wp_enqueue_script(
			'jet-google-map-provider',
			jet_engine()->plugin_url( 'includes/modules/maps-listings/assets/js/public/google-maps.js' ),
			$this->map_api_deps(),
			jet_engine()->get_version(),
			true 
		);

	}

	public function get_script_handles() {
		return array(
			'jet-engine-google-maps-api',
			'jet-markerclustererplus',
			'jet-google-map-provider',
		);
	}

	/**
	 * Provider-specific settings fields template
	 *
	 * @return [type] [description]
	 */
	public function settings_fields() {
		?>
		<template
			v-if="'google' === settings.map_provider"
		>
			<cx-vui-input
				label="<?php _e( 'API Key', 'jet-engine' ); ?>"
				description="<?php _e( 'Google maps API key. Video tutorial about creating Google Maps API key <a href=\'https://www.youtube.com/watch?v=t2O2a2YiLJA\' target=\'_blank\'>here</a>. <br>Please make sure <b>Geocoding API</b> is enabled for your API key (or use sparate key for Geocoding API).', 'jet-engine' ); ?>"
				:wrapper-css="[ 'equalwidth' ]"
				size="fullwidth"
				@on-input-change="updateSetting( $event.target.value, 'api_key' )"
				:value="settings.api_key"
			></cx-vui-input>
			<cx-vui-switcher
				label="<?php _e( 'Disable Google Maps API JS file', 'jet-engine' ); ?>"
				description="<?php _e( 'Disable Google Maps API JS file, if it already included by another plugin or theme', 'jet-engine' ); ?>"
				:wrapper-css="[ 'equalwidth' ]"
				@input="updateSetting( $event, 'disable_api_file' )"
				:value="settings.disable_api_file"
			></cx-vui-switcher>
			<cx-vui-switcher
				v-if="!settings.disable_api_file"
				label="<?php _e( 'Load Google Maps API asynchronously', 'jet-engine' ); ?>"
				description="<?php _e( 'May slightly improve Map Listing\'s load performance. Disable if you experience any issues with other plugins using Google Maps API', 'jet-engine' ); ?>"
				:wrapper-css="[ 'equalwidth' ]"
				@input="updateSetting( $event, 'async_load' )"
				:value="settings.async_load"
			></cx-vui-switcher>
		</template>
		<?php
	}

	public function provider_settings() {
		return array(
			'section_general' => array(
				'custom_style' => array(
					'label'       => __( 'Custom Map Style', 'jet-engine' ),
					'type'        => 'textarea',
					'default'     => '',
					'description' => __( 'Find free map styles at <a href="https://snazzymaps.com/explore" target="_blank" rel="nofollow">Snazzy Maps</a>. Use pasted JSON style code or a trusted allowlisted URL to a JSON config file.', 'jet-engine' ),
					'has_html'    => true,
					'label_block' => true,
					'dynamic'     => array(
						'active' => true,
					),
				),
				'zoom_control' => array(
					'separator'   => 'before',
					'label'       => __( 'Zoom & Pan Control', 'jet-engine' ),
					'type'        => 'select',
					'description' => __( 'Controls how the API handles gestures on the map. More details <a href="https://developers.google.com/maps/documentation/javascript/interaction#gestureHandling" target="_blank">here</a>', 'jet-engine' ),
					'default'     => 'auto',
					'has_html'    => true,
					'options'     => array(
						'auto'        => __( 'Auto', 'jet-engine' ),
						'greedy'      => __( 'Greedy', 'jet-engine' ),
						'cooperative' => __( 'Cooperative', 'jet-engine' ),
						'none'        => __( 'None', 'jet-engine' ),
					),
				),
				'zoom_controls' => array(
					'label'        => __( 'Zoom Controls', 'jet-engine' ),
					'type'         => 'switcher',
					'label_on'     => __( 'Show', 'jet-engine' ),
					'label_off'    => __( 'Hide', 'jet-engine' ),
					'return_value' => 'true',
					'default'      => 'true',
				),
				'fullscreen_control' => array(
					'label'        => __( 'Fullscreen Control', 'jet-engine' ),
					'type'         => 'switcher',
					'label_on'     => __( 'Show', 'jet-engine' ),
					'label_off'    => __( 'Hide', 'jet-engine' ),
					'return_value' => 'true',
					'default'      => 'true',
				),
				'street_view_controls' => array(
					'label'        => __( 'Street View Controls', 'jet-engine' ),
					'type'         => 'switcher',
					'label_on'     => __( 'Show', 'jet-engine' ),
					'label_off'    => __( 'Hide', 'jet-engine' ),
					'return_value' => 'true',
					'default'      => 'true',
				),
				'map_type_controls' => array(
					'label'        => __( 'Map Type Controls (Map/Satellite)', 'jet-engine' ),
					'type'         => 'switcher',
					'label_on'     => __( 'Show', 'jet-engine' ),
					'label_off'    => __( 'Hide', 'jet-engine' ),
					'return_value' => 'true',
					'default'      => 'true',
				)
			),
			'section_popup_settings' => array(
				'popup_pin' => array(
					'label'        => esc_html__( 'Add popup pin', 'jet-engine' ),
					'type'         => 'switcher',
					'label_on'     => esc_html__( 'Yes', 'jet-engine' ),
					'label_off'    => esc_html__( 'No', 'jet-engine' ),
					'return_value' => 'yes',
					'default'      => '',
				),
			),
		);
	}

	/**
	 * Normalize Google-specific front-end settings before the map config is printed.
	 *
	 * Inline JSON styles stay untouched. Remote style URLs are resolved only through
	 * the hardened fetch path below so front-end rendering cannot trigger arbitrary
	 * outbound requests.
	 *
	 * @param array $settings Raw render settings.
	 * @return array
	 */
	public function prepare_render_settings( $settings = array() ) {
		if ( empty( $settings['custom_style'] ) || ! is_string( $settings['custom_style'] ) ) {
			return $settings;
		}

		$custom_style = trim( $settings['custom_style'] );

		if ( '' === $custom_style || $this->is_valid_custom_style_json( $custom_style ) ) {
			return $settings;
		}

		if ( ! $this->is_custom_style_remote_url( $custom_style ) ) {
			return $settings;
		}

		$styles = $this->get_remote_custom_style( $custom_style );

		// Never pass rejected or invalid remote URLs through to the frontend map config.
		if ( null === $styles ) {
			$settings['custom_style'] = '';
			return $settings;
		}

		$settings['custom_style'] = $styles;

		return $settings;
	}

	/**
	 * Fetch and cache a remote Google Maps style definition.
	 *
	 * The response is accepted only for explicitly allowed hosts and only when the
	 * body is valid JSON that can be passed to the map renderer.
	 *
	 * @param string $url Remote style URL.
	 * @return string|null
	 */
	protected function get_remote_custom_style( $url ) {
		if ( ! $this->is_allowed_custom_style_url( $url ) ) {
			return null;
		}

		$transient = 'jet_map_styles_' . md5( $url );
		$styles    = get_transient( $transient );

		if ( false !== $styles ) {
			return $this->is_valid_custom_style_json( $styles ) ? $styles : null;
		}

		// Keep the request strictly read-only and prevent redirects to internal targets.
		$response = wp_safe_remote_get(
			$url,
			array(
				'reject_unsafe_urls'  => true,
				'redirection'         => 0,
				'timeout'             => 5,
				'limit_response_size' => 262144,
			)
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$response_code = wp_remote_retrieve_response_code( $response );

		if ( 200 > $response_code || 300 <= $response_code ) {
			return null;
		}

		$styles = wp_remote_retrieve_body( $response );

		if ( ! $this->is_valid_custom_style_json( $styles ) ) {
			return null;
		}

		// Cache only validated JSON so subsequent renders never need to refetch it.
		set_transient( $transient, $styles, 3 * DAY_IN_SECONDS );

		return $styles;
	}

	/**
	 * Check whether the custom style value looks like a remote URL candidate.
	 *
	 * @param string $value Style setting value.
	 * @return bool
	 */
	protected function is_custom_style_remote_url( $value ) {
		$url = wp_parse_url( $value );

		if ( empty( $url['scheme'] ) || empty( $url['host'] ) ) {
			return false;
		}

		return in_array( strtolower( $url['scheme'] ), array( 'http', 'https' ), true );
	}

	/**
	 * Validate the remote style URL against the provider allowlist policy.
	 *
	 * @param string $url Remote style URL.
	 * @return bool
	 */
	protected function is_allowed_custom_style_url( $url ) {
		$parsed = wp_parse_url( $url );

		if ( empty( $parsed['scheme'] ) || empty( $parsed['host'] ) ) {
			return false;
		}

		if ( ! empty( $parsed['user'] ) || ! empty( $parsed['pass'] ) ) {
			return false;
		}

		$scheme = strtolower( $parsed['scheme'] );
		$host   = strtolower( $parsed['host'] );
		$port   = isset( $parsed['port'] ) ? absint( $parsed['port'] ) : null;

		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return false;
		}

		if ( null !== $port && ! in_array( $port, $this->get_allowed_custom_style_ports(), true ) ) {
			return false;
		}

		return $this->is_allowed_custom_style_host( $host );
	}

	/**
	 * Match the host against the configured allowlist, including subdomains.
	 *
	 * @param string $host Parsed URL host.
	 * @return bool
	 */
	protected function is_allowed_custom_style_host( $host ) {
		foreach ( $this->get_allowed_custom_style_hosts() as $allowed_host ) {
			$allowed_host = strtolower( trim( (string) $allowed_host ) );

			if ( '' === $allowed_host ) {
				continue;
			}

			if ( $host === $allowed_host || str_ends_with( $host, '.' . $allowed_host ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Return the list of trusted hosts allowed for remote style fetching.
	 *
	 * @return array
	 */
	protected function get_allowed_custom_style_hosts() {
		return apply_filters(
			'jet-engine/maps-listing/map-providers/google/custom-style-allowed-hosts',
			array( 'snazzymaps.com' )
		);
	}

	/**
	 * Return the list of allowed ports for remote style fetching.
	 *
	 * @return array
	 */
	protected function get_allowed_custom_style_ports() {
		return array_map(
			'absint',
			(array) apply_filters(
				'jet-engine/maps-listing/map-providers/google/custom-style-allowed-ports',
				array( 80, 443 )
			)
		);
	}

	/**
	 * Check whether the provided style string is valid JSON for Google Maps styles.
	 *
	 * @param string $styles Style JSON string.
	 * @return bool
	 */
	protected function is_valid_custom_style_json( $styles ) {
		if ( ! is_string( $styles ) || '' === trim( $styles ) ) {
			return false;
		}

		$decoded = json_decode( $styles );

		return JSON_ERROR_NONE === json_last_error() && ( is_array( $decoded ) || is_object( $decoded ) );
	}

}
