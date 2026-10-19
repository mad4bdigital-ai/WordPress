<?php
/**
 * Filters manager class
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Define Jet_Smart_Filters_Referrer_Manager class
 */
class Jet_Smart_Filters_Referrer_Manager {

	/**
	 * Available types:
	 * front - send request to the initial page with ?jsf_ajax=1
	 * ajax - send request to admin-ajax.php with referrer data in the request
	 */
	public $referrer_type = 'default';

	/**
	 * Provider value hidden from WordPress request parsing.
	 *
	 * @var mixed
	 */
	private $front_request_provider = null;

	/**
	 * Query varaibale key to define front referrer request
	 */
	public static $front_query_key = 'jsf_ajax';

	/**
	 * Query varaibale key to define referrer method while request and not on the settings
	 *
	 * @var string
	 */
	public static $force_referrer_key = 'jsf_force_referrer';

	/**
	 * Query varaibale key to define referrer firing sequence - early or late
	 *
	 * @var string
	 */
	public static $sequence_referrer_key = 'jsf_referrer_sequence';

	/**
	 * Constructor for the class
	 */
	public function __construct() {

		if ( ! empty( $_GET[ self::$force_referrer_key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$this->referrer_type = sanitize_key( $_GET[ self::$force_referrer_key ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		} else {
			$this->referrer_type = jet_smart_filters()->settings->get( 'ajax_request_types' );
		}

		if ( 'default' === $this->referrer_type || ! $this->referrer_type ) {

			if ( jet_smart_filters()->query->is_ajax_filter() ) {
				$this->define_filters_request_constant();
			}

			return;
		}

		add_filter( 'jet-smart-filters/filters/localized-data', array( $this, 'set_referrer_settings' ) );

		if ( 'referrer' === $this->referrer_type ) {
			add_action( 'jet-smart-filters/render/ajax/before', array( $this, 'setup_ajax_referrer' ) );
		}

		if ( 'self' === $this->referrer_type && ! empty( $_GET[ self::$front_query_key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended

			if ( $this->is_front_referrer_ajax_request() ) {
				$this->hide_front_request_provider();
			}

			$sequence = ! empty( $_GET[ self::$sequence_referrer_key ] ) ? sanitize_key( $_GET[ self::$sequence_referrer_key ] ) : 'early'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

			if ( ! in_array( $sequence, array( 'early', 'late' ) ) ) {
				$sequence = 'early';
			}

			$hook = 'parse_request';
			$priority = 10;

			if ( 'late' === $sequence ) {
				$hook = 'wp';
				$priority = 99999;
			}

			/**
			 * Only 'parse_request' and 'wp' hooks are available for the front referrer
			 * because both og them accepts the WP object as a parameter
			 */
			add_action( $hook, array( $this, 'setup_front_referrer' ), $priority );
		}

	}

	/**
	 * Prevent the internal provider field from being parsed as a public WP query var.
	 *
	 * A public post type may use "provider" as its query var. In that case WP::parse_request()
	 * treats the JSF provider value as a post slug and replaces the matched page query.
	 */
	private function hide_front_request_provider() {

		if ( ! isset( $_POST['provider'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}

		// Preserve the exact payload value; request consumers sanitize it when reading.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$this->front_request_provider = $_POST['provider'];
		unset( $_POST['provider'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		add_action( 'parse_request', array( $this, 'restore_front_request_provider' ), -PHP_INT_MAX );
	}

	/**
	 * Restore the provider field after WordPress has parsed frontend query vars.
	 */
	public function restore_front_request_provider() {

		if ( null === $this->front_request_provider ) {
			return;
		}

		$_POST['provider'] = $this->front_request_provider; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$this->front_request_provider = null;
	}

	public function define_filters_request_constant() {
		define( 'JET_SMART_FILTERS_DOING_REQUEST', true );
	}

	public function set_referrer_settings( $data ) {

		if ( 'referrer' === $this->referrer_type ) {
			$data['referrer_data'] = $this->get_referrer_data();
		}

		if ( 'self' === $this->referrer_type ) {
			$data['referrer_url'] = self::get_referrer_url();
		}

		return $data;
	}

	/**
	 * Setup front referrer
	 */
	public function setup_front_referrer( $wp ) {

		if ( ! $this->is_front_referrer_ajax_request() ) {
			$this->redirect_front_referrer_to_clean_url();

			return;
		}

		$this->define_filters_request_constant();

		do_action( 'jet-smart-filters/referrer/self/before' );

		$wp->query_posts();
		$wp->register_globals();

		if ( apply_filters( 'jet-smart-filters/referrer/front/define-ajax', false ) ) {
			define( 'DOING_AJAX', true );
		}

		jet_smart_filters()->query->set_is_ajax_filter();

		do_action( 'jet-smart-filters/referrer/request' );

		do_action( 'wp_ajax_jet_smart_filters' );
		do_action( 'wp_ajax_nopriv_jet_smart_filters' );

		die();
	}

	/**
	 * Check if the current front referrer request is a real JSF AJAX request.
	 */
	private function is_front_referrer_ajax_request() {

		$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';

		if ( 'post' !== $request_method ) {
			return false;
		}

		$action = jet_smart_filters()->data->get_request_var( 'action' );

		return 'jet_smart_filters' === $action;
	}

	/**
	 * Redirect direct front referrer hits to the clean frontend URL.
	 */
	private function redirect_front_referrer_to_clean_url() {

		if ( headers_sent() ) {
			return;
		}

		$redirect_url = $this->remove_query_args_from_url(
			array(
				self::$front_query_key,
				self::$force_referrer_key,
				self::$sequence_referrer_key,
				'nocache',
			)
		);

		wp_safe_redirect( esc_url_raw( $redirect_url ) );
		exit;
	}

	/**
	 * Remove passed query arguments from a URL.
	 */
	private function remove_query_args_from_url( $query_args, $url = null ) {

		$query_args = array_values( array_filter( (array) $query_args ) );

		if ( null === $url ) {
			return remove_query_arg( $query_args );
		}

		return remove_query_arg( $query_args, $url );
	}

	/**
	 * Setup data by referrer URL string
	 */
	public function setup_ajax_referrer() {

		$referrer = jet_smart_filters()->data->get_request_var( 'referrer' );

		if ( empty( $referrer ) || ! is_array( $referrer ) ) {
			return;
		}

		$this->define_filters_request_constant();

		global $wp;

		$map = array(
			'uri'  => 'REQUEST_URI',
			'info' => 'PATH_INFO',
			'self' => 'PHP_SELF',
		);

		$temp = array();

		$uri      = ! empty( $referrer['uri'] ) ? $referrer['uri'] : false;
		$uri_data = explode( '?', $uri );

		if ( ! empty( $uri_data[1] ) ) {
			wp_parse_str( $uri_data[1], $request );
			$request = jet_smart_filters()->utils->sanitize_text_field_recursive( wp_unslash( $request ) );

			foreach ( $request as $key => $value ) {
				$_GET[ $key ]     = $value;
				$_REQUEST[ $key ] = $value;
			}
		}

		foreach ( $map as $request_key => $server_key ) {
			if ( isset( $referrer[ $request_key ] ) ) {
				$temp[ $server_key ] = isset( $_SERVER[ $server_key ] ) ? $_SERVER[ $server_key ] : ''; // phpcs:ignore
				$_SERVER[ $server_key ] = $referrer[ $request_key ];
			}
		}

		global $current_screen;

		$current_screen = WP_Screen::get( 'front' );

		do_action( 'jet-smart-filters/referrer/ajax/before' );

		$wp->parse_request();
		$wp->query_posts();
		$wp->register_globals();

		foreach ( $temp as $key => $value) {
			$_SERVER[ $key ] = $value;
		}

		do_action( 'jet-smart-filters/referrer/request' );
	}

	/**
	 * Returns referrer URL
	 */
	public function get_referrer_data() {

		return array(
			'uri'  => ! empty( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : null, // phpcs:ignore
			'info' => ! empty( $_SERVER['PATH_INFO'] ) ? $_SERVER['PATH_INFO'] : null, // phpcs:ignore
			'self' => ! empty( $_SERVER['PHP_SELF'] ) ? $_SERVER['PHP_SELF'] : null, // phpcs:ignore
		);
	}

	/**
	 * Returns referrer URL
	 */
	public static function get_referrer_url() {
		$request_uri = ! empty( $_SERVER['REQUEST_URI'] )
			? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) )
			: '';

		if ( ! $request_uri ) {
			return add_query_arg( array( self::$front_query_key => 1 ) );
		}

		// Keep "?" after paths with "=" so add_query_arg() does not treat permalink data as query data.
		if ( false === strpos( $request_uri, '?' ) && false !== strpos( $request_uri, '=' ) ) {
			$request_uri .= '?';
		}

		return add_query_arg( array( self::$front_query_key => 1 ), $request_uri );
	}
}
