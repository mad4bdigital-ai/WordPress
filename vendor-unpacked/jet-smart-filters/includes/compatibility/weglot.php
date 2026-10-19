<?php
/**
 * Weglot compatibility class
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Define Jet_Smart_Filters_Compatibility_Weglot class
 */
class Jet_Smart_Filters_Compatibility_Weglot {

	public $current_lang = null;
	public $default_lang = null;

	/**
	 * Constructor for the class
	 */
	function __construct() {

		if ( ! function_exists( 'weglot_get_current_language' ) || ! function_exists( 'weglot_get_original_language' ) ) {
			return;
		}

		$this->current_lang = weglot_get_current_language();
		$this->default_lang = weglot_get_original_language();

		if ( $this->current_lang === $this->default_lang ) {
			return;
		}

		add_filter( 'jet-smart-filters/data/baseurl', array( $this, 'modify_baseurl' ) );
		add_filter( 'jet-smart-filters/filters/localized-data', array( $this, 'modify_referrer_settings' ), 20 );
	}

	public function modify_baseurl( $baseurl ) {

		if ( ! $this->current_lang ) {
			return $baseurl;
		}

		$sitepath = jet_smart_filters()->data->get_sitepath();
		$sitepath = rtrim( $sitepath, '/' );

		if ( $sitepath && strpos( $baseurl, $sitepath . '/' ) === 0 ) {
			$baseurl = substr( $baseurl, strlen( $sitepath ) );
		}

		return $sitepath . '/' . $this->current_lang . $baseurl;
	}

	public function modify_referrer_settings( $data ) {

		if ( ! $this->current_lang || empty( $data['referrer_url'] ) ) {
			return $data;
		}

		$url      = $data['referrer_url'];
		$sitepath = jet_smart_filters()->data->get_sitepath();

		$sitepath = rtrim( $sitepath, '/' );

		if ( $sitepath && strpos( $url, $sitepath . '/' ) === 0 ) {
			$url = substr( $url, strlen( $sitepath ) );
		}

		$data['referrer_url'] = $sitepath . '/' . $this->current_lang . $url;

		return $data;
	}
}
