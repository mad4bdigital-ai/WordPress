<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_Context_Admin_UI {
	const PAGE_SLUG = 'mad4b-control-plane-context';
	const ACTION_SAVE_PROFILE = 'mad4b_context_save_profile';
	const ACTION_SAVE_GOOGLE = 'mad4b_context_google_save';
	const ACTION_SAVE_GOOGLE_DEDICATED = 'mad4b_context_google_dedicated_save';
	const ACTION_SAVE_GOOGLE_AUTH_MODE = 'mad4b_context_google_auth_mode_save';
	const ACTION_SAVE_GOOGLE_GRANTS = 'mad4b_context_google_grants_save';
	const ACTION_CONNECT_GOOGLE = 'mad4b_context_google_connect';
	const ACTION_GOOGLE_CALLBACK = 'mad4b_context_google_callback';
	const ACTION_GOOGLE_DEDICATED_CALLBACK = 'mad4b_context_google_dedicated_callback';
	const ACTION_GOOGLE_MANAGED_CALLBACK = 'mad4b_context_google_managed_callback';
	const ACTION_DISCONNECT_GOOGLE = 'mad4b_context_google_disconnect';
	const ACTION_SELECT_SOURCE = 'mad4b_context_select_source';
	const ACTION_SCAN_SOURCE = 'mad4b_context_scan_source';
	const ACTION_REVIEW_ASSET = 'mad4b_context_review_asset';
	const ACTION_REMOVE_SOURCE = 'mad4b_context_remove_source';
	const ACTION_UPDATE_SOURCE_POLICY = 'mad4b_context_update_source_policy';

	private static $booted = false;

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ), 45 );
		foreach ( array(
			self::ACTION_SAVE_PROFILE => 'handle_save_profile',
			self::ACTION_SAVE_GOOGLE => 'handle_save_google',
			self::ACTION_SAVE_GOOGLE_DEDICATED => 'handle_save_google_dedicated',
			self::ACTION_SAVE_GOOGLE_AUTH_MODE => 'handle_save_google_auth_mode',
			self::ACTION_SAVE_GOOGLE_GRANTS => 'handle_save_google_grants',
			self::ACTION_CONNECT_GOOGLE => 'handle_connect_google',
			self::ACTION_GOOGLE_CALLBACK => 'handle_google_callback',
			self::ACTION_GOOGLE_DEDICATED_CALLBACK => 'handle_google_dedicated_callback',
			self::ACTION_GOOGLE_MANAGED_CALLBACK => 'handle_google_managed_callback',
			self::ACTION_DISCONNECT_GOOGLE => 'handle_disconnect_google',
			self::ACTION_SELECT_SOURCE => 'handle_select_source',
			self::ACTION_SCAN_SOURCE => 'handle_scan_source',
			self::ACTION_REVIEW_ASSET => 'handle_review_asset',
			self::ACTION_REMOVE_SOURCE => 'handle_remove_source',
			self::ACTION_UPDATE_SOURCE_POLICY => 'handle_update_source_policy',
		) as $action => $method ) {
			add_action( 'admin_post_' . $action, array( __CLASS__, $method ) );
			if ( in_array( $action, array( self::ACTION_SAVE_GOOGLE, self::ACTION_SAVE_GOOGLE_DEDICATED, self::ACTION_SAVE_GOOGLE_AUTH_MODE, self::ACTION_SAVE_GOOGLE_GRANTS ), true ) ) add_action( 'wp_ajax_' . $action, array( __CLASS__, $method ) );
		}
	}

	public static function register_page() {
		add_submenu_page(
			'mad4b-control-plane',
			__( 'MAD4B Context Authority', 'mad4b-site-control-plane' ),
			__( 'Context Authority', 'mad4b-site-control-plane' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function handle_save_profile() {
		self::require_admin( self::ACTION_SAVE_PROFILE );
		$result = MAD4B_SCP_Context_Authority::save_profile( isset( $_POST['brand_name'] ) ? wp_unslash( $_POST['brand_name'] ) : '' );
		self::redirect_result( $result, 'overview', 'brand_profile_saved' );
	}

	public static function handle_save_google_auth_mode() {
		self::require_admin_request( self::ACTION_SAVE_GOOGLE_AUTH_MODE );
		$result = MAD4B_SCP_Google_Drive_Context::set_auth_mode( isset( $_POST['auth_mode'] ) ? wp_unslash( $_POST['auth_mode'] ) : '' );
		self::finish_result( $result, 'google-drive', 'google_auth_mode_saved', __( 'Connection method saved.', 'mad4b-site-control-plane' ) );
	}

	public static function handle_save_google_grants() {
		self::require_admin_request( self::ACTION_SAVE_GOOGLE_GRANTS );
		$grants = isset( $_POST['grants'] ) && is_array( $_POST['grants'] ) ? wp_unslash( $_POST['grants'] ) : array();
		$result = MAD4B_SCP_Google_Drive_Context::save_workspace_grants( $grants );
		self::finish_result( $result, 'google-drive', 'google_grants_saved', __( 'Google Workspace grants saved.', 'mad4b-site-control-plane' ) );
	}

	public static function handle_save_google() {
		self::require_admin_request( self::ACTION_SAVE_GOOGLE );
		$result = MAD4B_SCP_Google_Drive_Context::save_credentials(
			isset( $_POST['client_id'] ) ? wp_unslash( $_POST['client_id'] ) : '',
			isset( $_POST['client_secret'] ) ? wp_unslash( $_POST['client_secret'] ) : ''
		);
		self::finish_result( $result, 'google-drive', 'google_credentials_saved', __( 'Custom Google OAuth configuration saved.', 'mad4b-site-control-plane' ) );
	}

	public static function handle_save_google_dedicated() {
		self::require_admin_request( self::ACTION_SAVE_GOOGLE_DEDICATED );
		$result = MAD4B_SCP_Google_Drive_Context::save_dedicated_credentials(
			isset( $_POST['client_id'] ) ? wp_unslash( $_POST['client_id'] ) : '',
			isset( $_POST['client_secret'] ) ? wp_unslash( $_POST['client_secret'] ) : ''
		);
		self::finish_result( $result, 'google-drive', 'google_dedicated_credentials_saved', __( 'Dedicated Google OAuth configuration saved.', 'mad4b-site-control-plane' ) );
	}

	public static function handle_connect_google() {
		self::require_admin( self::ACTION_CONNECT_GOOGLE );
		$access_mode = isset( $_POST['access_mode'] ) ? sanitize_key( wp_unslash( $_POST['access_mode'] ) ) : 'read_only';
		$managed_signin = ! empty( $_POST['managed_signin'] );

		if ( $managed_signin ) {
			$mode = MAD4B_SCP_Google_Drive_Context::set_auth_mode( MAD4B_SCP_Google_Drive_Context::AUTH_MODE_MANAGED );
			if ( is_wp_error( $mode ) ) self::redirect_result( $mode, 'google-drive', '' );
			$url = MAD4B_SCP_Google_Drive_Context::managed_authorization_url( $access_mode );
		} else {
			$url = MAD4B_SCP_Google_Drive_Context::AUTH_MODE_MANAGED === MAD4B_SCP_Google_Drive_Context::auth_mode()
				? MAD4B_SCP_Google_Drive_Context::managed_authorization_url( $access_mode )
				: MAD4B_SCP_Google_Drive_Context::authorization_url( $access_mode );
		}

		if ( is_wp_error( $url ) ) self::redirect_result( $url, 'google-drive', '' );
		wp_redirect( esc_url_raw( $url ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- validated Google OAuth endpoint.
		exit;
	}

	public static function handle_google_callback() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'Administrator capability is required.', 'mad4b-site-control-plane' ), '', array( 'response' => 403 ) );
		if ( isset( $_GET['error'] ) ) {
			$error = new WP_Error( 'mad4b_google_drive_oauth_denied', 'Google OAuth was cancelled or denied.', array( 'provider_error' => sanitize_key( wp_unslash( $_GET['error'] ) ) ) );
			self::redirect_result( $error, 'google-drive', '' );
		}
		$result = MAD4B_SCP_Google_Drive_Context::complete_oauth(
			isset( $_GET['code'] ) ? wp_unslash( $_GET['code'] ) : '',
			isset( $_GET['state'] ) ? wp_unslash( $_GET['state'] ) : ''
		);
		self::redirect_result( $result, 'google-drive', 'google_connected' );
	}

	public static function handle_google_dedicated_callback() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'Administrator capability is required.', 'mad4b-site-control-plane' ), '', array( 'response' => 403 ) );
		if ( isset( $_GET['error'] ) ) {
			$error = new WP_Error( 'mad4b_google_dedicated_oauth_denied', 'Dedicated Google OAuth was cancelled or denied.', array( 'provider_error' => sanitize_key( wp_unslash( $_GET['error'] ) ) ) );
			self::redirect_result( $error, 'google-drive', '' );
		}
		$result = MAD4B_SCP_Google_Drive_Context::complete_oauth(
			isset( $_GET['code'] ) ? wp_unslash( $_GET['code'] ) : '',
			isset( $_GET['state'] ) ? wp_unslash( $_GET['state'] ) : ''
		);
		self::redirect_result( $result, 'google-drive', 'google_connected' );
	}

	public static function handle_google_managed_callback() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'Administrator capability is required.', 'mad4b-site-control-plane' ), '', array( 'response' => 403 ) );
		if ( isset( $_GET['error'] ) ) {
			$error = new WP_Error( 'mad4b_google_managed_oauth_denied', 'Managed Google Sign-In was cancelled or denied.', array( 'provider_error' => sanitize_key( wp_unslash( $_GET['error'] ) ) ) );
			self::redirect_result( $error, 'google-drive', '' );
		}
		$result = MAD4B_SCP_Google_Drive_Context::complete_managed_oauth(
			isset( $_GET['handoff_code'] ) ? wp_unslash( $_GET['handoff_code'] ) : '',
			isset( $_GET['state'] ) ? wp_unslash( $_GET['state'] ) : ''
		);
		self::redirect_result( $result, 'google-drive', 'google_connected' );
	}

	public static function handle_disconnect_google() {
		self::require_admin( self::ACTION_DISCONNECT_GOOGLE );
		$result = MAD4B_SCP_Google_Drive_Context::disconnect();
		self::redirect_result( $result, 'google-drive', 'google_disconnected' );
	}

	public static function handle_select_source() {
		self::require_admin( self::ACTION_SELECT_SOURCE );
		$mode = isset( $_POST['source_mode'] ) ? sanitize_key( wp_unslash( $_POST['source_mode'] ) ) : 'governed';
		$write_policy = 'task_attachment' === $mode
			? 'read_only'
			: ( isset( $_POST['write_policy'] ) ? sanitize_key( wp_unslash( $_POST['write_policy'] ) ) : 'read_only' );
		$result = MAD4B_SCP_Context_Authority::upsert_source(
			array(
				'provider' => 'google_drive',
				'mode' => $mode,
				'write_policy' => $write_policy,
				'write_policy_confirmed' => ! empty( $_POST['write_policy_confirmed'] ),
				'external_root_id' => isset( $_POST['folder_id'] ) ? wp_unslash( $_POST['folder_id'] ) : '',
				'label' => isset( $_POST['folder_name'] ) ? wp_unslash( $_POST['folder_name'] ) : '',
				'task_scope' => isset( $_POST['task_scope'] ) ? wp_unslash( $_POST['task_scope'] ) : '',
				'recursive' => ! empty( $_POST['recursive'] ),
			)
		);
		self::redirect_result( $result, 'sources', 'source_selected' );
	}

	public static function handle_update_source_policy() {
		self::require_admin( self::ACTION_UPDATE_SOURCE_POLICY );
		$result = MAD4B_SCP_Context_Authority::update_source_write_policy(
			isset( $_POST['source_id'] ) ? wp_unslash( $_POST['source_id'] ) : '',
			isset( $_POST['write_policy'] ) ? wp_unslash( $_POST['write_policy'] ) : 'read_only',
			! empty( $_POST['write_policy_confirmed'] )
		);
		self::redirect_result( $result, 'sources', 'source_policy_updated' );
	}

	public static function handle_scan_source() {
		self::require_admin( self::ACTION_SCAN_SOURCE );
		$source_id = isset( $_POST['source_id'] ) ? strtolower( trim( sanitize_text_field( wp_unslash( $_POST['source_id'] ) ) ) ) : '';
		$sources = MAD4B_SCP_Context_Authority::sources();
		if ( ! isset( $sources[ $source_id ] ) ) self::redirect_result( new WP_Error( 'mad4b_context_source_not_found', 'Context source was not found.' ), 'sources', '' );
		$source = $sources[ $source_id ];
		$scan = MAD4B_SCP_Google_Drive_Context::scan_folder( $source['external_root_id'], ! empty( $source['recursive'] ) );
		if ( is_wp_error( $scan ) ) self::redirect_result( $scan, 'sources', '' );
		$result = MAD4B_SCP_Context_Authority::replace_source_assets(
			$source_id,
			isset( $scan['assets'] ) && is_array( $scan['assets'] ) ? $scan['assets'] : array(),
			$scan
		);
		self::redirect_result( $result, 'assets', ! empty( $scan['truncated'] ) ? 'source_scanned_truncated' : 'source_scanned' );
	}

	public static function handle_remove_source() {
		self::require_admin( self::ACTION_REMOVE_SOURCE );
		if ( empty( $_POST['confirm_remove'] ) ) self::redirect_result( new WP_Error( 'mad4b_context_source_remove_confirmation_required', 'Confirm source removal first.' ), 'sources', '' );
		$result = MAD4B_SCP_Context_Authority::remove_source( isset( $_POST['source_id'] ) ? wp_unslash( $_POST['source_id'] ) : '' );
		self::redirect_result( $result, 'sources', 'source_removed' );
	}

	public static function handle_review_asset() {
		self::require_admin( self::ACTION_REVIEW_ASSET );
		$result = MAD4B_SCP_Context_Authority::review_asset(
			isset( $_POST['asset_id'] ) ? wp_unslash( $_POST['asset_id'] ) : '',
			array(
				'category' => isset( $_POST['category'] ) ? wp_unslash( $_POST['category'] ) : '',
				'authority_class' => isset( $_POST['authority_class'] ) ? wp_unslash( $_POST['authority_class'] ) : '',
				'required' => ! empty( $_POST['required'] ),
				'quality_mode' => isset( $_POST['quality_mode'] ) ? wp_unslash( $_POST['quality_mode'] ) : 'automatic',
				'quality_score' => isset( $_POST['quality_score'] ) ? wp_unslash( $_POST['quality_score'] ) : '',
			)
		);
		self::redirect_result( $result, 'assets', 'asset_review_saved' );
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'Administrator capability is required.', 'mad4b-site-control-plane' ) );
		if ( class_exists( 'MAD4B_SCP_Admin_Experience' ) ) MAD4B_SCP_Admin_Experience::styles();
		self::styles();
		self::scripts();
		$tabs = array(
			'overview' => __( 'Overview', 'mad4b-site-control-plane' ),
			'google-drive' => __( 'Google Drive', 'mad4b-site-control-plane' ),
			'sources' => __( 'Source Folders', 'mad4b-site-control-plane' ),
			'assets' => __( 'Assets', 'mad4b-site-control-plane' ),
			'quality' => __( 'Quality', 'mad4b-site-control-plane' ),
			'intelligence' => __( 'Intelligence', 'mad4b-site-control-plane' ),
		);
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation.
		if ( ! isset( $tabs[ $tab ] ) ) $tab = 'overview';
		echo '<div class="wrap mad4b-scp-admin-page mad4b-context-page">';
		echo '<h1>' . esc_html__( 'Context Authority', 'mad4b-site-control-plane' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Connect brand knowledge to this Site Profile. Google Drive can be read-only or explicitly upgraded to read + write; Context Authority still decides which assets are governed, task-only, required, and eligible for governed mutation.', 'mad4b-site-control-plane' ) . '</p>';
		self::render_notice();
		if ( class_exists( 'MAD4B_SCP_Admin_Experience' ) ) MAD4B_SCP_Admin_Experience::tabs( self::PAGE_SLUG, $tabs, $tab );
		if ( 'overview' === $tab ) self::render_overview();
		if ( 'google-drive' === $tab ) self::render_google_drive();
		if ( 'sources' === $tab ) self::render_sources();
		if ( 'assets' === $tab ) self::render_assets();
		if ( 'quality' === $tab ) self::render_quality();
		if ( 'intelligence' === $tab ) self::render_intelligence();
		echo '</div>';
	}

	private static function render_overview() {
		$status = MAD4B_SCP_Context_Authority::status();
		$google = MAD4B_SCP_Google_Drive_Context::connection_status();
		$profile = MAD4B_SCP_Context_Authority::profile();
		$site = class_exists( 'MAD4B_SCP_Site_Profile' ) ? MAD4B_SCP_Site_Profile::status() : array();
		$stages = array(
			array( 'label' => 'Site Profile', 'detail' => ! empty( $site['configured'] ) && ! empty( $site['origin_match'] ) ? 'Enrolled to this origin' : 'Enroll this site first', 'state' => ! empty( $site['configured'] ) && ! empty( $site['origin_match'] ) ? 'complete' : 'blocked' ),
			array( 'label' => 'Brand Profile', 'detail' => ! empty( $profile ) ? (string) $profile['brand_name'] : 'Name the brand context', 'state' => ! empty( $profile ) ? 'complete' : 'pending' ),
			array( 'label' => 'Google Drive', 'detail' => ! empty( $google['connected'] ) ? ( ( $google['account_email'] ? $google['account_email'] . ' · ' : '' ) . ( ! empty( $google['write_available'] ) ? 'Read + Write' : 'Read-only' ) ) : 'Connect a Google account', 'state' => ! empty( $google['connected'] ) ? 'complete' : 'pending', 'url' => self::tab_url( 'google-drive' ) ),
			array( 'label' => 'Source Folder', 'detail' => $status['governed_source_count'] ? $status['governed_source_count'] . ' governed source(s)' : 'Choose a governed folder', 'state' => $status['governed_source_count'] ? 'complete' : 'pending', 'url' => self::tab_url( 'sources' ) ),
			array( 'label' => 'Context Ready', 'detail' => $status['ready'] ? 'Mandatory context is ready' : 'Scan and review assets', 'state' => $status['ready'] ? 'complete' : 'attention', 'url' => self::tab_url( 'assets' ) ),
		);
		if ( class_exists( 'MAD4B_SCP_Admin_Experience' ) ) MAD4B_SCP_Admin_Experience::stages( $stages );
		$quality = null === $status['average_quality_score'] ? 'Not scored' : $status['average_quality_score'] . '/100';
		$cards = array(
			array( 'label' => 'Context Authority', 'value' => strtoupper( $status['state'] ), 'help' => $status['ready'] ? 'Skills can rely on governed context.' : 'Fail-closed until mandatory assets are ready.', 'state' => $status['ready'] ? 'complete' : 'attention' ),
			array( 'label' => 'Governed Sources', 'value' => (string) $status['governed_source_count'], 'help' => 'Affect Brand Context fingerprint.', 'state' => $status['governed_source_count'] ? 'complete' : 'pending' ),
			array( 'label' => 'Task Sources', 'value' => (string) $status['task_source_count'], 'help' => 'Temporary context; does not change Brand Authority.', 'state' => 'pending' ),
			array( 'label' => 'Assets', 'value' => (string) $status['asset_count'], 'help' => $status['required_asset_count'] . ' mandatory · ' . ( isset( $status['unavailable_asset_count'] ) ? $status['unavailable_asset_count'] : 0 ) . ' unavailable.', 'state' => ! empty( $status['unavailable_asset_count'] ) ? 'attention' : ( $status['asset_count'] ? 'complete' : 'pending' ) ),
			array( 'label' => 'Average Quality', 'value' => $quality, 'help' => $status['quality_scored_asset_count'] . ' asset(s) scored.', 'state' => null === $status['average_quality_score'] ? 'pending' : 'complete' ),
		);
		if ( class_exists( 'MAD4B_SCP_Admin_Experience' ) ) MAD4B_SCP_Admin_Experience::cards( $cards );

		echo '<div class="mad4b-scp-panel"><h2>' . esc_html__( 'Brand Context Profile', 'mad4b-site-control-plane' ) . '</h2>';
		echo '<p>' . esc_html__( 'Use a short brand name. This profile is bound to the current Site Profile UUID, not to a global WordPress setting.', 'mad4b-site-control-plane' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::ACTION_SAVE_PROFILE );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_SAVE_PROFILE ) . '">';
		echo '<label for="mad4b-brand-name"><strong>' . esc_html__( 'Brand name', 'mad4b-site-control-plane' ) . '</strong></label><br>';
		$value = isset( $profile['brand_name'] ) ? (string) $profile['brand_name'] : get_bloginfo( 'name' );
		echo '<input id="mad4b-brand-name" name="brand_name" type="text" class="regular-text" value="' . esc_attr( $value ) . '" required> ';
		submit_button( empty( $profile ) ? __( 'Create Brand Context', 'mad4b-site-control-plane' ) : __( 'Update Brand Context', 'mad4b-site-control-plane' ), 'primary', 'submit', false );
		echo '</form></div>';

		if ( ! empty( $status['blockers'] ) ) {
			echo '<div class="mad4b-scp-panel mad4b-scp-next-step is-attention"><h2>' . esc_html__( 'What is still missing?', 'mad4b-site-control-plane' ) . '</h2><ul>';
			foreach ( $status['blockers'] as $blocker ) echo '<li><code>' . esc_html( $blocker ) . '</code></li>';
			echo '</ul></div>';
		}
		echo '<div class="mad4b-context-fingerprint"><strong>Context fingerprint:</strong> <code>' . esc_html( $status['context_fingerprint'] ) . '</code></div>';
	}

	private static function render_google_drive() {
		$credentials = MAD4B_SCP_Google_Drive_Context::credentials_status();
		$connection = MAD4B_SCP_Google_Drive_Context::connection_status();
		$auth_mode_status = MAD4B_SCP_Google_Drive_Context::auth_mode_status();
		$auth_mode = isset( $auth_mode_status['mode'] ) ? (string) $auth_mode_status['mode'] : MAD4B_SCP_Google_Drive_Context::AUTH_MODE_CUSTOM;
		$managed_ready = ! empty( $auth_mode_status['managed_google']['configured'] );
		$dedicated_ready = ! empty( $auth_mode_status['dedicated_google']['configured'] );
		$dedicated_origin_ready = ! empty( $auth_mode_status['dedicated_google']['redirect_uri'] );
		$grants = MAD4B_SCP_Google_Drive_Context::workspace_grants_status();
		$grant_catalog = isset( $grants['catalog'] ) && is_array( $grants['catalog'] ) ? $grants['catalog'] : array();
		$grant_selection = isset( $grants['selection'] ) && is_array( $grants['selection'] ) ? $grants['selection'] : array();
		$full_suite = MAD4B_SCP_Google_Drive_Context::full_suite_grant_selection();
		echo '<div id="mad4b-google-ajax-feedback" class="mad4b-google-ajax-feedback" aria-live="polite"></div>';

		$drive_grant = isset( $grant_selection['drive'] ) ? (string) $grant_selection['drive'] : 'read';
		$managed_access_mode = 'full' === $drive_grant ? 'read_write' : 'read_only';

		echo '<div class="mad4b-scp-panel mad4b-google-primary-signin" id="mad4b-google-primary-signin"><h2>' . esc_html__( 'Connect Google', 'mad4b-site-control-plane' ) . '</h2>';
		if ( ! empty( $connection['connected'] ) ) {
			echo '<div class="notice notice-success inline"><p><strong>' . esc_html__( 'Google is connected.', 'mad4b-site-control-plane' ) . '</strong>';
			if ( ! empty( $connection['account_email'] ) ) echo ' · ' . esc_html( $connection['account_email'] );
			echo '</p></div>';
		} elseif ( $managed_ready ) {
			echo '<p>' . esc_html__( 'Use your Google account. MAD4B handles the OAuth application centrally, so this WordPress site does not need a Google Client ID or Client Secret.', 'mad4b-site-control-plane' ) . '</p>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="mad4b-google-primary-signin-form">';
			wp_nonce_field( self::ACTION_CONNECT_GOOGLE );
			echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_CONNECT_GOOGLE ) . '">';
			echo '<input type="hidden" name="managed_signin" value="1">';
			echo '<input type="hidden" name="access_mode" value="' . esc_attr( $managed_access_mode ) . '">';
			echo '<button type="submit" class="button mad4b-google-signin-button">' . esc_html__( 'Sign in with Google', 'mad4b-site-control-plane' ) . '</button>';
			echo '</form>';
			echo '<p class="description">' . esc_html( sprintf( __( '%1$d Google OAuth scopes selected. Drive access: %2$s. You can change app access under Advanced Google settings.', 'mad4b-site-control-plane' ), isset( $grants['scope_count'] ) ? (int) $grants['scope_count'] : 0, 'full' === $drive_grant ? 'Full' : 'Read-only' ) ) . '</p>';
		} else {
			echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__( 'Sign in with Google needs one-time server enrollment.', 'mad4b-site-control-plane' ) . '</strong> ' . esc_html__( 'No Google Client ID or Client Secret is required on this WordPress site. The MAD4B broker site binding must be configured server-side first.', 'mad4b-site-control-plane' ) . '</p></div>';
		}
		echo '</div>';

		echo '<details class="mad4b-google-advanced"' . ( $managed_ready ? '' : ' open' ) . '><summary><strong>' . esc_html__( 'Advanced Google settings', 'mad4b-site-control-plane' ) . '</strong><span>' . esc_html__( ' Grants, Dedicated OAuth and Custom OAuth', 'mad4b-site-control-plane' ) . '</span></summary>';

		echo '<div class="mad4b-scp-panel" id="mad4b-google-connection-method"><h2>' . esc_html__( 'Authentication method', 'mad4b-site-control-plane' ) . '</h2>';
		if ( ! empty( $connection['connected'] ) || ! empty( $connection['revocation_pending'] ) || ! empty( $connection['token_unreadable'] ) ) {
			$mode_label = MAD4B_SCP_Google_Drive_Context::AUTH_MODE_MANAGED === $auth_mode
				? __( 'Sign in with Google — Managed', 'mad4b-site-control-plane' )
				: ( MAD4B_SCP_Google_Drive_Context::AUTH_MODE_DEDICATED === $auth_mode ? __( 'Dedicated Google OAuth — Site Domain', 'mad4b-site-control-plane' ) : __( 'Custom Google OAuth App', 'mad4b-site-control-plane' ) );
			echo '<div class="notice notice-info inline"><p><strong>' . esc_html( $mode_label ) . '</strong> · ' . esc_html__( 'Disconnect and revoke the current Google grant before changing authentication mode.', 'mad4b-site-control-plane' ) . '</p></div>';
		} else {
			echo '<p>' . esc_html__( 'Choose how this site authenticates with Google. Managed Sign-In uses the central MAD4B broker; Dedicated Site OAuth stays entirely on this site primary domain; Custom OAuth keeps the legacy manual setup.', 'mad4b-site-control-plane' ) . '</p>';
			echo '<form class="mad4b-context-ajax-form mad4b-context-auth-mode-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( self::ACTION_SAVE_GOOGLE_AUTH_MODE );
			echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_SAVE_GOOGLE_AUTH_MODE ) . '">';
			echo '<div class="mad4b-context-source-mode">';
			echo '<label><input type="radio" name="auth_mode" value="' . esc_attr( MAD4B_SCP_Google_Drive_Context::AUTH_MODE_MANAGED ) . '" ' . checked( MAD4B_SCP_Google_Drive_Context::AUTH_MODE_MANAGED, $auth_mode, false ) . ( $managed_ready ? '' : ' disabled' ) . '> <strong>' . esc_html__( 'Sign in with Google — Recommended', 'mad4b-site-control-plane' ) . '</strong><span>' . esc_html__( 'Uses MAD4B Managed OAuth. No Google Client ID or Client Secret is stored on this site.', 'mad4b-site-control-plane' ) . '</span></label>';
			echo '<label><input type="radio" name="auth_mode" value="' . esc_attr( MAD4B_SCP_Google_Drive_Context::AUTH_MODE_DEDICATED ) . '" ' . checked( MAD4B_SCP_Google_Drive_Context::AUTH_MODE_DEDICATED, $auth_mode, false ) . ( $dedicated_origin_ready ? '' : ' disabled' ) . '> <strong>' . esc_html__( 'Dedicated Google OAuth — Site Domain', 'mad4b-site-control-plane' ) . '</strong><span>' . esc_html__( 'Uses a Google OAuth app dedicated to this site. Callback and token lifecycle stay on the Site Profile primary domain with no auth.mad4b.com dependency.', 'mad4b-site-control-plane' ) . '</span></label>';
			echo '<label><input type="radio" name="auth_mode" value="' . esc_attr( MAD4B_SCP_Google_Drive_Context::AUTH_MODE_CUSTOM ) . '" ' . checked( MAD4B_SCP_Google_Drive_Context::AUTH_MODE_CUSTOM, $auth_mode, false ) . '> <strong>' . esc_html__( 'Custom Google OAuth App — Advanced', 'mad4b-site-control-plane' ) . '</strong><span>' . esc_html__( 'Use your own Google Cloud OAuth Web application and the existing custom credential path.', 'mad4b-site-control-plane' ) . '</span></label>';
			echo '</div>';
			if ( ! $managed_ready ) echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Managed Google Sign-In is waiting for the server-side MAD4B broker site binding. This is an operator configuration; no Google Client ID or Client Secret is required here.', 'mad4b-site-control-plane' ) . '</p></div>';
			if ( ! $dedicated_origin_ready ) echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Dedicated Site OAuth requires an enrolled Site Profile whose canonical origin matches the current WordPress Home URL and Site URL.', 'mad4b-site-control-plane' ) . '</p></div>';
			echo '<noscript>';
			submit_button( __( 'Save Connection Method', 'mad4b-site-control-plane' ), 'secondary', 'submit', false );
			echo '</noscript></form>';
		}
		echo '</div>';

		echo '<div class="mad4b-scp-panel" id="mad4b-google-grants"><h2>' . esc_html__( '2. Google Workspace grants', 'mad4b-site-control-plane' ) . '</h2>';
		echo '<p>' . esc_html__( 'Choose the OAuth grant level for each Google app. The saved grant set is bound to the OAuth request and token fingerprint. Changing grants requires disconnecting and revoking the current Google token first.', 'mad4b-site-control-plane' ) . '</p>';
		if ( ! empty( $connection['connected'] ) || ! empty( $connection['revocation_pending'] ) || ! empty( $connection['token_unreadable'] ) ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Grant selection is locked while a Google token exists. Disconnect and revoke Google access before changing the suite grants.', 'mad4b-site-control-plane' ) . '</p></div>';
		}
		echo '<form class="mad4b-context-ajax-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::ACTION_SAVE_GOOGLE_GRANTS );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_SAVE_GOOGLE_GRANTS ) . '">';
		echo '<div class="mad4b-google-grant-grid">';
		foreach ( $grant_catalog as $app => $definition ) {
			$selected_mode = isset( $grant_selection[ $app ] ) ? (string) $grant_selection[ $app ] : ( isset( $definition['default'] ) ? (string) $definition['default'] : 'off' );
			$full_mode = isset( $full_suite[ $app ] ) ? (string) $full_suite[ $app ] : $selected_mode;
			echo '<div class="mad4b-google-grant-card"><label for="mad4b-google-grant-' . esc_attr( $app ) . '"><strong>' . esc_html( isset( $definition['label'] ) ? $definition['label'] : $app ) . '</strong></label>';
			echo '<select id="mad4b-google-grant-' . esc_attr( $app ) . '" name="grants[' . esc_attr( $app ) . ']" data-full-mode="' . esc_attr( $full_mode ) . '"' . ( ! empty( $connection['connected'] ) || ! empty( $connection['revocation_pending'] ) || ! empty( $connection['token_unreadable'] ) ? ' disabled' : '' ) . '>';
			foreach ( $definition['modes'] as $mode => $mode_definition ) echo '<option value="' . esc_attr( $mode ) . '" ' . selected( $selected_mode, $mode, false ) . '>' . esc_html( isset( $mode_definition['label'] ) ? $mode_definition['label'] : $mode ) . '</option>';
			echo '</select>';
			if ( 'gemini' === $app ) echo '<p class="description">' . esc_html__( 'Gemini uses Google API OAuth scopes (cloud-platform + generative-language retriever); it is not treated as Workspace document authority.', 'mad4b-site-control-plane' ) . '</p>';
			echo '</div>';
		}
		echo '</div>';
		echo '<p><strong>' . esc_html__( 'Current scope count:', 'mad4b-site-control-plane' ) . '</strong> ' . esc_html( isset( $grants['scope_count'] ) ? (string) $grants['scope_count'] : '0' ) . ' · <code>' . esc_html( isset( $grants['grant_sha256'] ) ? substr( (string) $grants['grant_sha256'], 0, 16 ) : '' ) . '…</code></p>';
		echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Full Drive and Gmail access use restricted OAuth scopes and can require Google OAuth verification/security review for public use.', 'mad4b-site-control-plane' ) . '</p></div>';
		if ( empty( $connection['connected'] ) && empty( $connection['revocation_pending'] ) && empty( $connection['token_unreadable'] ) ) {
			echo '<button type="button" class="button mad4b-select-full-suite">' . esc_html__( 'Select Full Apps Suite', 'mad4b-site-control-plane' ) . '</button> ';
			submit_button( __( 'Save Grants', 'mad4b-site-control-plane' ), 'secondary', 'submit', false );
		}
		echo '</form></div>';

		echo '<div class="mad4b-scp-panel" id="mad4b-google-auth-setup"><h2>' . esc_html__( '3. Authentication setup', 'mad4b-site-control-plane' ) . '</h2>';
		if ( MAD4B_SCP_Google_Drive_Context::AUTH_MODE_MANAGED === $auth_mode ) {
			echo '<div class="mad4b-scp-next-step ' . ( $managed_ready ? 'is-complete' : 'is-attention' ) . '"><h3>' . esc_html__( 'Managed Google Sign-In', 'mad4b-site-control-plane' ) . '</h3>';
			echo '<p>' . esc_html__( 'The Google OAuth application is held by the MAD4B broker. This site receives a one-time, site-bound handoff and stores only the encrypted Google token after server-to-server redemption. Google Client Secret never enters WordPress.', 'mad4b-site-control-plane' ) . '</p>';
			echo '<p><label><strong>' . esc_html__( 'Managed callback URI', 'mad4b-site-control-plane' ) . '</strong></label><br><input type="text" readonly class="large-text code" value="' . esc_attr( $credentials['managed_redirect_uri'] ) . '"></p>';
			if ( $managed_ready ) echo '<p><span class="dashicons dashicons-yes-alt"></span> ' . esc_html__( 'MAD4B Managed OAuth broker and per-site request signing are configured.', 'mad4b-site-control-plane' ) . '</p>';
			else echo '<p><code>managed_google_oauth_broker_not_configured</code></p>';
			echo '</div>';
		} elseif ( MAD4B_SCP_Google_Drive_Context::AUTH_MODE_DEDICATED === $auth_mode ) {
			echo '<div class="mad4b-scp-next-step ' . ( $dedicated_ready ? 'is-complete' : 'is-attention' ) . '"><h3>' . esc_html__( 'Dedicated Google OAuth — Site Domain', 'mad4b-site-control-plane' ) . '</h3>';
			echo '<p>' . esc_html__( 'This mode is fully independent of auth.mad4b.com. The Google OAuth Web Client is dedicated to this site, and its callback URI is derived dynamically from the enrolled Site Profile canonical origin. The Client Secret is encrypted and stored on this WordPress site.', 'mad4b-site-control-plane' ) . '</p>';
			echo '<p><label><strong>' . esc_html__( 'Authorized redirect URI', 'mad4b-site-control-plane' ) . '</strong></label><br><input type="text" readonly class="large-text code" value="' . esc_attr( isset( $credentials['dedicated_redirect_uri'] ) ? $credentials['dedicated_redirect_uri'] : '' ) . '"></p>';
			if ( ! empty( $credentials['configured_by_constants'] ) ) {
				echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Dedicated Google OAuth credentials are managed by wp-config constants.', 'mad4b-site-control-plane' ) . '</p></div>';
			} else {
				echo '<form class="mad4b-context-ajax-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
				wp_nonce_field( self::ACTION_SAVE_GOOGLE_DEDICATED );
				echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_SAVE_GOOGLE_DEDICATED ) . '">';
				echo '<table class="form-table"><tr><th><label for="mad4b-google-dedicated-client-id">' . esc_html__( 'Dedicated Client ID', 'mad4b-site-control-plane' ) . '</label></th><td><input id="mad4b-google-dedicated-client-id" name="client_id" type="text" class="large-text" required autocomplete="off" value="' . esc_attr( isset( $credentials['client_id'] ) ? $credentials['client_id'] : '' ) . '"><p class="description">' . esc_html__( 'Google OAuth Web Client dedicated to this Site Profile.', 'mad4b-site-control-plane' ) . '</p></td></tr>';
				echo '<tr><th><label for="mad4b-google-dedicated-client-secret">' . esc_html__( 'Dedicated Client Secret', 'mad4b-site-control-plane' ) . '</label></th><td><input id="mad4b-google-dedicated-client-secret" name="client_secret" type="password" class="regular-text" autocomplete="new-password"><p class="description">' . esc_html__( 'Encrypted at rest on this site. Leave blank after first setup to keep the stored secret.', 'mad4b-site-control-plane' ) . '</p></td></tr></table>';
				submit_button( __( 'Save Dedicated OAuth Configuration', 'mad4b-site-control-plane' ) );
				echo '</form>';
			}
			echo '</div>';
		} else {
			echo '<p>' . esc_html__( 'Create a Google OAuth Web application, enable Google Drive API, and add this exact redirect URI. Start read-only, or explicitly grant read + write when you want governed asset update/recreate. Arbitrary new-file create remains disabled until its exact rollback contract is certified.', 'mad4b-site-control-plane' ) . '</p>';
			echo '<p><label><strong>' . esc_html__( 'Authorized redirect URI', 'mad4b-site-control-plane' ) . '</strong></label><br><input type="text" readonly class="large-text code" value="' . esc_attr( $credentials['custom_redirect_uri'] ) . '"></p>';
			if ( ! empty( $credentials['configured_by_constants'] ) ) {
				echo '<div class="notice notice-success inline"><p>' . esc_html__( 'OAuth client credentials are managed by wp-config constants. Secrets are not editable here.', 'mad4b-site-control-plane' ) . '</p></div>';
			} else {
				echo '<form class="mad4b-context-ajax-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
				wp_nonce_field( self::ACTION_SAVE_GOOGLE );
				echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_SAVE_GOOGLE ) . '">';
				echo '<table class="form-table"><tr><th><label for="mad4b-google-client-id">' . esc_html__( 'Client ID', 'mad4b-site-control-plane' ) . '</label></th><td><input id="mad4b-google-client-id" name="client_id" type="text" class="large-text" required autocomplete="off" value="' . esc_attr( isset( $credentials['client_id'] ) ? $credentials['client_id'] : '' ) . '"><p class="description">' . esc_html( ! empty( $credentials['configured'] ) ? 'Already configured. Enter the same or replacement Client ID.' : 'From Google Cloud OAuth credentials.' ) . '</p></td></tr>';
				echo '<tr><th><label for="mad4b-google-client-secret">' . esc_html__( 'Client Secret', 'mad4b-site-control-plane' ) . '</label></th><td><input id="mad4b-google-client-secret" name="client_secret" type="password" class="regular-text" autocomplete="new-password"><p class="description">' . esc_html__( 'Encrypted at rest. Leave blank after first setup to keep the stored secret.', 'mad4b-site-control-plane' ) . '</p></td></tr></table>';
				submit_button( __( 'Save OAuth Configuration', 'mad4b-site-control-plane' ) );
				echo '</form>';
			}
		}
		echo '</div>';
		echo '</details>';

		echo '<div class="mad4b-scp-panel" id="mad4b-google-connect"><h2>' . esc_html__( 'Google connection status', 'mad4b-site-control-plane' ) . '</h2>';
		if ( empty( $credentials['configured'] ) && ! $managed_ready ) {
			echo '<p>' . esc_html__( 'Managed Sign-In is not enrolled yet. Open Advanced Google settings only if you intentionally want Dedicated or Custom OAuth.', 'mad4b-site-control-plane' ) . '</p></div>';
			return;
		}
		if ( ! empty( $connection['token_unreadable'] ) ) {
			echo '<div class="notice notice-error inline"><p><strong>' . esc_html__( 'Stored Google credentials cannot be decrypted.', 'mad4b-site-control-plane' ) . '</strong> ' . esc_html__( 'Drive access is disabled. Clear the unreadable local token, revoke MAD4B access from your Google Account security settings, then reconnect.', 'mad4b-site-control-plane' ) . '</p></div>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( self::ACTION_DISCONNECT_GOOGLE );
			echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_DISCONNECT_GOOGLE ) . '">';
			submit_button( __( 'Clear unreadable local token', 'mad4b-site-control-plane' ), 'secondary', 'submit', false );
			echo '</form></div>';
			self::render_write_governance_readiness();
			return;
		}
		if ( empty( $connection['connected'] ) ) {
			if ( $managed_ready ) {
				echo '<p>' . esc_html__( 'Ready for Managed Google Sign-In. Use the “Sign in with Google” button at the top of this page.', 'mad4b-site-control-plane' ) . '</p></div>';
				return;
			}
			$drive_grant = isset( $grant_selection['drive'] ) ? (string) $grant_selection['drive'] : 'read';
			$access_mode = 'full' === $drive_grant ? 'read_write' : 'read_only';
			echo '<p>' . esc_html__( 'Connect using the selected advanced OAuth method and saved Google Workspace grant set.', 'mad4b-site-control-plane' ) . '</p>';
			echo '<div class="mad4b-context-source-mode"><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( self::ACTION_CONNECT_GOOGLE );
			echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_CONNECT_GOOGLE ) . '"><input type="hidden" name="access_mode" value="' . esc_attr( $access_mode ) . '">';
			submit_button( __( 'Connect with Advanced OAuth', 'mad4b-site-control-plane' ), 'primary', 'submit', false );
			echo '</form></div></div>';
			return;
		}
		if ( ! empty( $connection['revocation_pending'] ) ) {
			echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__( 'Google revocation is pending.', 'mad4b-site-control-plane' ) . '</strong> ' . esc_html__( 'All Drive reads and writes are disabled locally. Retry revoke to finish disconnecting before reconnecting.', 'mad4b-site-control-plane' ) . '</p>';
			if ( ! empty( $connection['revocation_error'] ) ) echo '<p><code>' . esc_html( $connection['revocation_error'] ) . '</code></p>';
			echo '</div>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( self::ACTION_DISCONNECT_GOOGLE );
			echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_DISCONNECT_GOOGLE ) . '">';
			submit_button( __( 'Retry revoke', 'mad4b-site-control-plane' ), 'primary', 'submit', false );
			echo '</form></div>';
			self::render_write_governance_readiness();
			return;
		}
		$access_label = ! empty( $connection['write_available'] ) ? __( 'Read + Write', 'mad4b-site-control-plane' ) : __( 'Read-only', 'mad4b-site-control-plane' );
		echo '<div class="notice notice-success inline"><p><strong>' . esc_html__( 'Connected', 'mad4b-site-control-plane' ) . '</strong>';
		if ( $connection['account_email'] ) echo ' · ' . esc_html( $connection['account_email'] );
		echo ' · ' . esc_html( $access_label ) . '</p></div>';
		if ( empty( $connection['write_available'] ) ) {
			echo '<div class="mad4b-scp-next-step is-attention"><p><strong>' . esc_html__( 'Need to repair or recreate Drive assets?', 'mad4b-site-control-plane' ) . '</strong> ' . esc_html__( 'Upgrade OAuth to read + write. This grants provider capability only; mutations still require governed approval.', 'mad4b-site-control-plane' ) . '</p>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( self::ACTION_CONNECT_GOOGLE );
			echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_CONNECT_GOOGLE ) . '"><input type="hidden" name="access_mode" value="read_write">';
			submit_button( __( 'Upgrade to Read + Write', 'mad4b-site-control-plane' ), 'primary', 'submit', false );
			echo '</form></div>';
		} else {
			echo '<div class="mad4b-scp-next-step is-complete"><p><strong>' . esc_html__( 'Drive write capability is available.', 'mad4b-site-control-plane' ) . '</strong> ' . esc_html__( 'Update and recreate can mount on mad4b-write and require exact NHI grant plus one-time approval. Arbitrary create remains blocked until exact create rollback is certified. This page never executes those mutations directly.', 'mad4b-site-control-plane' ) . '</p><p class="description">' . esc_html__( 'To return to least-privilege Read-only mode, disconnect and revoke this grant first, then reconnect Read-only.', 'mad4b-site-control-plane' ) . '</p></div>';
		}
		echo '<p><a class="button button-primary" href="' . esc_url( self::tab_url( 'google-drive', array( 'folder' => 'root' ) ) ) . '">' . esc_html__( 'Choose Source Folder', 'mad4b-site-control-plane' ) . '</a></p>';
		echo '<form class="mad4b-context-folder-jump" method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '"><input type="hidden" name="page" value="' . esc_attr( self::PAGE_SLUG ) . '"><input type="hidden" name="tab" value="google-drive"><label><strong>' . esc_html__( 'Open a shared folder by ID', 'mad4b-site-control-plane' ) . '</strong><span class="description"> ' . esc_html__( 'Useful for Shared Drives or folders that do not appear under My Drive.', 'mad4b-site-control-plane' ) . '</span></label><div><input type="text" name="folder" class="regular-text code" placeholder="Google Drive folder ID"> ';
		submit_button( __( 'Open Folder', 'mad4b-site-control-plane' ), 'secondary', 'submit', false );
		echo '</div></form>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::ACTION_DISCONNECT_GOOGLE );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_DISCONNECT_GOOGLE ) . '">';
		submit_button( __( 'Disconnect & Revoke Google Access', 'mad4b-site-control-plane' ), 'secondary', 'submit', false );
		echo '</form></div>';
		self::render_write_governance_readiness();
		self::render_folder_browser();
	}

	private static function render_write_governance_readiness() {
		$connection = MAD4B_SCP_Google_Drive_Context::connection_status();
		$abilities = array(
			'context/update-drive-asset',
			'context/recreate-drive-asset',
		);
		$sources = MAD4B_SCP_Context_Authority::sources();
		$policy_ops = array( 'create' => 0, 'update' => 0, 'recreate' => 0 );
		foreach ( $sources as $source ) {
			if ( ! is_array( $source ) || 'governed' !== ( isset( $source['mode'] ) ? (string) $source['mode'] : '' ) ) continue;
			foreach ( array_keys( $policy_ops ) as $operation ) {
				if ( MAD4B_SCP_Context_Authority::source_allows_write( (string) $source['source_id'], $operation ) ) ++$policy_ops[ $operation ];
			}
		}

		$truth = class_exists( 'MAD4B_SCP_Live_Truth' ) ? MAD4B_SCP_Live_Truth::current_authority_status() : array();
		$write_tools = isset( $truth['write_tools'] ) && is_array( $truth['write_tools'] ) ? array_values( array_map( 'strval', $truth['write_tools'] ) ) : array();
		$mounted = array_values( array_intersect( $abilities, $write_tools ) );
		$context_grant_blockers = array();
		foreach ( isset( $truth['grant_blockers'] ) && is_array( $truth['grant_blockers'] ) ? $truth['grant_blockers'] : array() as $blocker ) {
			$blocker = (string) $blocker;
			foreach ( $abilities as $ability ) {
				if ( false !== strpos( $blocker, $ability ) ) { $context_grant_blockers[] = $blocker; break; }
			}
		}
		$context_grant_blockers = array_values( array_unique( $context_grant_blockers ) );
		$runtime_reconciled = ! empty( $truth['runtime_reconciled'] );
		$authority_ready = ! empty( $truth['ready'] );
		$desired_write_ops = (int) $policy_ops['update'] + (int) $policy_ops['recreate'];
		$provider_write_ready = ! empty( $connection['write_available'] );
		$context_authority_ready = $provider_write_ready && $desired_write_ops > 0 && ! empty( $mounted ) && empty( $context_grant_blockers ) && $runtime_reconciled && $authority_ready;

		echo '<div class="mad4b-scp-panel"><h2>' . esc_html__( '3. Write Governance Readiness', 'mad4b-site-control-plane' ) . '</h2>';
		echo '<p>' . esc_html__( 'Google OAuth is provider capability only. MAD4B write authority is evaluated separately from the live write inventory and exact grants.', 'mad4b-site-control-plane' ) . '</p>';
		echo '<div class="mad4b-context-governance-grid">';
		self::governance_cell( 'Google provider access', $provider_write_ready ? 'Read + Write' : ( ! empty( $connection['read_available'] ) ? 'Read-only' : 'Unavailable' ), $provider_write_ready ? 'complete' : 'attention' );
		self::governance_cell( 'Source policy', sprintf( 'create %d (reserved) · update %d · recreate %d', $policy_ops['create'], $policy_ops['update'], $policy_ops['recreate'] ), $desired_write_ops > 0 ? 'complete' : 'pending' );
		self::governance_cell( 'mad4b-write mount', count( $mounted ) . '/2 certified Context abilities', count( $mounted ) > 0 ? 'complete' : 'pending' );
		self::governance_cell( 'Exact authority', $context_authority_ready ? 'Ready' : ( $desired_write_ops > 0 && $provider_write_ready ? 'Reconciliation required' : 'Not requested' ), $context_authority_ready ? 'complete' : ( $desired_write_ops > 0 && $provider_write_ready ? 'attention' : 'pending' ) );
		echo '</div>';

		if ( $desired_write_ops > 0 && $provider_write_ready && ! $context_authority_ready ) {
			echo '<div class="mad4b-scp-next-step is-attention"><p><strong>' . esc_html__( 'Provider write access is ready, but governed execution is not fully reconciled yet.', 'mad4b-site-control-plane' ) . '</strong> ' . esc_html__( 'Review the changed mad4b-write inventory, then run the explicit governed write-grant reconciliation flow. Context Authority never reconciles grants automatically.', 'mad4b-site-control-plane' ) . '</p>';
			if ( ! empty( $context_grant_blockers ) ) {
				echo '<p><strong>' . esc_html__( 'Context grant blockers', 'mad4b-site-control-plane' ) . '</strong></p><ul>';
				foreach ( $context_grant_blockers as $blocker ) echo '<li><code>' . esc_html( $blocker ) . '</code></li>';
				echo '</ul>';
			}
			if ( ! $runtime_reconciled ) echo '<p><code>runtime_authority_not_reconciled</code></p>';
			echo '</div>';
		} elseif ( $context_authority_ready ) {
			echo '<div class="mad4b-scp-next-step is-complete"><p><strong>' . esc_html__( 'Governed Drive writes are ready.', 'mad4b-site-control-plane' ) . '</strong> ' . esc_html__( 'Each mutation still requires its own exact one-time approval.', 'mad4b-site-control-plane' ) . '</p></div>';
		}
		echo '</div>';
	}

	private static function governance_cell( $label, $value, $state ) {
		$state = in_array( $state, array( 'complete', 'attention', 'pending' ), true ) ? $state : 'pending';
		echo '<div class="mad4b-context-governance-cell is-' . esc_attr( $state ) . '"><span>' . esc_html( $label ) . '</span><strong>' . esc_html( $value ) . '</strong></div>';
	}

	private static function render_folder_browser() {
		$connection = MAD4B_SCP_Google_Drive_Context::connection_status();
		if ( empty( $connection['connected'] ) ) return;
		$folder_id = isset( $_GET['folder'] ) ? sanitize_text_field( wp_unslash( $_GET['folder'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only folder navigation.
		if ( '' === $folder_id ) return;
		$folder = MAD4B_SCP_Google_Drive_Context::get_folder( $folder_id );
		if ( is_wp_error( $folder ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $folder->get_error_message() ) . '</p></div>';
			return;
		}
		$children = MAD4B_SCP_Google_Drive_Context::list_folders( $folder_id );
		if ( is_wp_error( $children ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $children->get_error_message() ) . '</p></div>';
			return;
		}
		echo '<div class="mad4b-scp-panel"><h2>' . esc_html__( 'Folder Picker', 'mad4b-site-control-plane' ) . '</h2>';
		echo '<div class="mad4b-context-folder-head"><div><span class="dashicons dashicons-category"></span> <strong>' . esc_html( isset( $folder['name'] ) ? $folder['name'] : 'My Drive' ) . '</strong><br><code>' . esc_html( isset( $folder['id'] ) ? $folder['id'] : '' ) . '</code></div>';
		echo '<a class="button" href="' . esc_url( self::tab_url( 'google-drive', array( 'folder' => 'root' ) ) ) . '">' . esc_html__( 'My Drive', 'mad4b-site-control-plane' ) . '</a></div>';
		if ( $children ) {
			echo '<div class="mad4b-context-folder-grid">';
			foreach ( $children as $child ) {
				$url = self::tab_url( 'google-drive', array( 'folder' => isset( $child['id'] ) ? $child['id'] : '' ) );
				echo '<a class="mad4b-context-folder" href="' . esc_url( $url ) . '"><span class="dashicons dashicons-category"></span><span>' . esc_html( isset( $child['name'] ) ? $child['name'] : 'Folder' ) . '</span></a>';
			}
			echo '</div>';
		} else echo '<p class="mad4b-scp-muted">' . esc_html__( 'No child folders here. You can still select this folder as a source.', 'mad4b-site-control-plane' ) . '</p>';

		$current_folder_id = isset( $folder['id'] ) ? (string) $folder['id'] : '';
		if ( 'root' === strtolower( $current_folder_id ) ) {
			echo '<hr><div class="mad4b-scp-next-step is-attention"><h3>' . esc_html__( 'Choose a specific folder', 'mad4b-site-control-plane' ) . '</h3><p>' . esc_html__( 'My Drive is available for navigation only. Open a Brand, project, or reference folder and select that folder as the governed source boundary.', 'mad4b-site-control-plane' ) . '</p></div></div>';
			return;
		}
		echo '<hr><h3>' . esc_html__( 'Use this folder', 'mad4b-site-control-plane' ) . '</h3>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::ACTION_SELECT_SOURCE );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_SELECT_SOURCE ) . '">';
		echo '<input type="hidden" name="folder_id" value="' . esc_attr( isset( $folder['id'] ) ? $folder['id'] : '' ) . '">';
		echo '<input type="hidden" name="folder_name" value="' . esc_attr( isset( $folder['name'] ) ? $folder['name'] : '' ) . '">';
		echo '<div class="mad4b-context-source-mode"><label><input type="radio" name="source_mode" value="governed" checked> <strong>' . esc_html__( 'Governed Library', 'mad4b-site-control-plane' ) . '</strong><span>' . esc_html__( 'Affects Brand Context fingerprint and mandatory context.', 'mad4b-site-control-plane' ) . '</span></label>';
		echo '<label><input type="radio" name="source_mode" value="task_attachment"> <strong>' . esc_html__( 'Task-only Source', 'mad4b-site-control-plane' ) . '</strong><span>' . esc_html__( 'Temporary context; excluded from Brand Authority and always read-only in this release.', 'mad4b-site-control-plane' ) . '</span></label></div>';
		echo '<h4>' . esc_html__( 'Write policy for Governed Library', 'mad4b-site-control-plane' ) . '</h4>';
		echo '<div class="mad4b-context-source-mode">';
		echo '<label><input type="radio" name="write_policy" value="read_only" checked> <strong>' . esc_html__( 'Read-only', 'mad4b-site-control-plane' ) . '</strong><span>' . esc_html__( 'Recommended default. Scan and use context without Drive mutations.', 'mad4b-site-control-plane' ) . '</span></label>';
		echo '<label><input type="radio" name="write_policy" value="repair_only"> <strong>' . esc_html__( 'Repair existing assets', 'mad4b-site-control-plane' ) . '</strong><span>' . esc_html__( 'Update existing assets and recreate ones confirmed unavailable. No unrelated new files.', 'mad4b-site-control-plane' ) . '</span></label>';
		echo '<label><input type="radio" name="write_policy" value="managed"> <strong>' . esc_html__( 'Managed library', 'mad4b-site-control-plane' ) . '</strong><span>' . esc_html__( 'Managed policy reserves future create authority, but only update and recreate are currently certifiable. Every mounted write still needs governed approval.', 'mad4b-site-control-plane' ) . '</span></label>';
		echo '</div>';
		echo '<p><label><input type="checkbox" name="write_policy_confirmed" value="1"> <strong>' . esc_html__( 'Confirm any increase in Drive write authority for this source', 'mad4b-site-control-plane' ) . '</strong></label><br><span class="description">' . esc_html__( 'Required only when selecting Repair or Managed. Read-only remains the default and needs no confirmation.', 'mad4b-site-control-plane' ) . '</span></p>';
		echo '<p><label for="mad4b-task-scope"><strong>' . esc_html__( 'Task scope', 'mad4b-site-control-plane' ) . '</strong> <span class="description">' . esc_html__( '(required only for Task-only Source)', 'mad4b-site-control-plane' ) . '</span></label><br><input id="mad4b-task-scope" type="text" name="task_scope" class="regular-text" placeholder="e.g. luxor-family-blog-2026"></p>';
		echo '<p><label><input type="checkbox" name="recursive" value="1" checked> ' . esc_html__( 'Include subfolders', 'mad4b-site-control-plane' ) . '</label></p>';
		submit_button( __( 'Add Source Folder', 'mad4b-site-control-plane' ), 'primary' );
		echo '</form></div>';
	}

	private static function render_sources() {
		$sources = MAD4B_SCP_Context_Authority::sources();
		$connection = MAD4B_SCP_Google_Drive_Context::connection_status();
		echo '<div class="mad4b-scp-panel"><h2>' . esc_html__( 'Source Folders', 'mad4b-site-control-plane' ) . '</h2>';
		echo '<p>' . esc_html__( 'Governed folders participate in Context Authority. Task-only folders are isolated to their task scope and do not change the Brand Context fingerprint.', 'mad4b-site-control-plane' ) . '</p>';
		if ( empty( $sources ) ) {
			echo '<p>' . esc_html__( 'No source folders selected yet.', 'mad4b-site-control-plane' ) . '</p>';
			if ( ! empty( $connection['connected'] ) ) echo '<p><a class="button button-primary" href="' . esc_url( self::tab_url( 'google-drive', array( 'folder' => 'root' ) ) ) . '">' . esc_html__( 'Choose a Folder', 'mad4b-site-control-plane' ) . '</a></p>';
			echo '</div>';
			return;
		}
		echo '<div class="mad4b-scp-table-wrap"><table class="widefat striped"><thead><tr><th>Folder</th><th>Mode</th><th>Write policy</th><th>Task scope</th><th>Status</th><th>Assets</th><th>Last scan</th><th>Action</th></tr></thead><tbody>';
		foreach ( $sources as $source ) {
			echo '<tr><td><strong>' . esc_html( $source['label'] ) . '</strong><br><code>' . esc_html( $source['external_root_id'] ) . '</code></td>';
			echo '<td><span class="mad4b-context-badge">' . esc_html( $source['mode'] ) . '</span></td>';
			echo '<td><form class="mad4b-context-policy-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( self::ACTION_UPDATE_SOURCE_POLICY );
			echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_UPDATE_SOURCE_POLICY ) . '"><input type="hidden" name="source_id" value="' . esc_attr( $source['source_id'] ) . '">';
			if ( 'task_attachment' === $source['mode'] ) {
				echo '<input type="hidden" name="write_policy" value="read_only"><code>read_only</code>';
			} else {
				echo '<select name="write_policy">';
				foreach ( MAD4B_SCP_Context_Authority::write_policies() as $policy_key => $policy ) echo '<option value="' . esc_attr( $policy_key ) . '"' . selected( $source['write_policy'], $policy_key, false ) . '>' . esc_html( $policy['label'] ) . '</option>';
				echo '</select><br><label class="description"><input type="checkbox" name="write_policy_confirmed" value="1"> ' . esc_html__( 'Confirm if this change increases Drive write authority', 'mad4b-site-control-plane' ) . '</label> ';
				submit_button( __( 'Save', 'mad4b-site-control-plane' ), 'secondary small', 'submit', false );
			}
			echo '</form></td><td>' . esc_html( $source['task_scope'] ? $source['task_scope'] : '—' ) . '</td>';
			echo '<td>' . esc_html( $source['status'] ) . '</td><td>' . esc_html( (string) $source['asset_count'] ) . '</td><td>' . esc_html( $source['last_synced_at'] ? $source['last_synced_at'] : 'Never' ) . '</td>';
			echo '<td><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( self::ACTION_SCAN_SOURCE );
			echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_SCAN_SOURCE ) . '"><input type="hidden" name="source_id" value="' . esc_attr( $source['source_id'] ) . '">';
			submit_button( __( 'Scan now', 'mad4b-site-control-plane' ), 'secondary', 'submit', false );
			echo '</form><details class="mad4b-context-remove"><summary>' . esc_html__( 'Remove', 'mad4b-site-control-plane' ) . '</summary><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( self::ACTION_REMOVE_SOURCE );
			echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_REMOVE_SOURCE ) . '"><input type="hidden" name="source_id" value="' . esc_attr( $source['source_id'] ) . '">';
			echo '<label><input type="checkbox" name="confirm_remove" value="1" required> ' . esc_html__( 'Remove this source and its indexed assets.', 'mad4b-site-control-plane' ) . '</label> ';
			submit_button( __( 'Remove Source', 'mad4b-site-control-plane' ), 'delete small', 'submit', false );
			echo '</form></details></td></tr>';
		}
		echo '</tbody></table></div></div>';
	}

	private static function render_assets() {
		$assets = MAD4B_SCP_Context_Authority::assets();
		self::render_repair_queue( $assets );
		$mode_filter = isset( $_GET['mode_filter'] ) ? sanitize_key( wp_unslash( $_GET['mode_filter'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filtering.
		$category_filter = isset( $_GET['category_filter'] ) ? sanitize_key( wp_unslash( $_GET['category_filter'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filtering.
		$review_filter = isset( $_GET['review_filter'] ) ? sanitize_key( wp_unslash( $_GET['review_filter'] ) ) : '';
		$status_filter = isset( $_GET['status_filter'] ) ? sanitize_key( wp_unslash( $_GET['status_filter'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filtering.
		$search_filter = isset( $_GET['asset_search'] ) ? trim( sanitize_text_field( wp_unslash( $_GET['asset_search'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filtering.
		$all_assets = $assets;
		$assets = array_filter(
			$assets,
			static function ( $asset ) use ( $mode_filter, $category_filter, $review_filter, $status_filter, $search_filter ) {
				if ( $mode_filter && $mode_filter !== ( isset( $asset['source_mode'] ) ? $asset['source_mode'] : '' ) ) return false;
				if ( $category_filter && $category_filter !== ( isset( $asset['category'] ) ? $asset['category'] : '' ) ) return false;
				if ( $status_filter && $status_filter !== ( isset( $asset['status'] ) ? $asset['status'] : '' ) ) return false;
				$review = isset( $asset['review_status'] ) ? $asset['review_status'] : 'unreviewed';
				if ( 'needs_review' === $review_filter && ! in_array( $review, array( 'unreviewed', 'needs_review_content_changed' ), true ) ) return false;
				if ( 'approved' === $review_filter && 'approved' !== $review ) return false;
				if ( '' !== $search_filter ) {
					$haystack = strtolower( ( isset( $asset['title'] ) ? $asset['title'] : '' ) . ' ' . ( isset( $asset['path'] ) ? $asset['path'] : '' ) );
					if ( false === strpos( $haystack, strtolower( $search_filter ) ) ) return false;
				}
				return true;
			}
		);
		echo '<div class="mad4b-scp-panel"><h2>' . esc_html__( 'Context Assets', 'mad4b-site-control-plane' ) . '</h2>';
		echo '<p>' . esc_html__( 'Classification is automatic in this first slice and remains visible with confidence. Quality scores marked provisional are metadata-based because the file type did not expose normalized text.', 'mad4b-site-control-plane' ) . '</p>';
		$categories = MAD4B_SCP_Context_Authority::categories();
		echo '<form class="mad4b-context-filterbar" method="get"><input type="hidden" name="page" value="' . esc_attr( self::PAGE_SLUG ) . '"><input type="hidden" name="tab" value="assets">';
		echo '<input type="search" name="asset_search" value="' . esc_attr( $search_filter ) . '" placeholder="' . esc_attr__( 'Search assets…', 'mad4b-site-control-plane' ) . '">';
		echo '<select name="mode_filter"><option value="">' . esc_html__( 'All modes', 'mad4b-site-control-plane' ) . '</option><option value="governed"' . selected( $mode_filter, 'governed', false ) . '>Governed</option><option value="task_attachment"' . selected( $mode_filter, 'task_attachment', false ) . '>Task-only</option></select>';
		echo '<select name="category_filter"><option value="">' . esc_html__( 'All categories', 'mad4b-site-control-plane' ) . '</option>';
		foreach ( $categories as $key => $label ) echo '<option value="' . esc_attr( $key ) . '"' . selected( $category_filter, $key, false ) . '>' . esc_html( $label ) . '</option>';
		echo '</select><select name="status_filter"><option value="">' . esc_html__( 'All availability', 'mad4b-site-control-plane' ) . '</option><option value="ready"' . selected( $status_filter, 'ready', false ) . '>' . esc_html__( 'Ready', 'mad4b-site-control-plane' ) . '</option><option value="unavailable"' . selected( $status_filter, 'unavailable', false ) . '>' . esc_html__( 'Unavailable', 'mad4b-site-control-plane' ) . '</option><option value="recreated"' . selected( $status_filter, 'recreated', false ) . '>' . esc_html__( 'Recreated', 'mad4b-site-control-plane' ) . '</option></select><select name="review_filter"><option value="">' . esc_html__( 'All review states', 'mad4b-site-control-plane' ) . '</option><option value="needs_review"' . selected( $review_filter, 'needs_review', false ) . '>' . esc_html__( 'Needs review', 'mad4b-site-control-plane' ) . '</option><option value="approved"' . selected( $review_filter, 'approved', false ) . '>' . esc_html__( 'Approved', 'mad4b-site-control-plane' ) . '</option></select>';
		submit_button( __( 'Filter', 'mad4b-site-control-plane' ), 'secondary', 'submit', false );
		echo ' <span class="mad4b-scp-muted">' . esc_html( sprintf( __( 'Showing %1$d of %2$d assets', 'mad4b-site-control-plane' ), count( $assets ), count( $all_assets ) ) ) . '</span></form>';
		if ( ! $all_assets ) { echo '<p>' . esc_html__( 'Scan a source folder to discover assets.', 'mad4b-site-control-plane' ) . '</p></div>'; return; }
		if ( ! $assets ) { echo '<p>' . esc_html__( 'No assets match the current filters.', 'mad4b-site-control-plane' ) . '</p></div>'; return; }
		$authorities = MAD4B_SCP_Context_Authority::authority_classes();
		echo '<div class="mad4b-scp-table-wrap"><table class="widefat striped"><thead><tr><th>Asset</th><th>Mode</th><th>Category</th><th>Authority</th><th>Required</th><th>Quality</th><th>Confidence</th><th>Normalization</th><th>Status</th><th>Actionability</th><th>Review</th></tr></thead><tbody>';
		foreach ( $assets as $asset ) {
			$quality = isset( $asset['quality'] ) && is_array( $asset['quality'] ) ? $asset['quality'] : array();
			echo '<tr><td><strong>' . esc_html( $asset['title'] ) . '</strong><br><span class="mad4b-scp-muted">' . esc_html( $asset['path'] ) . '</span></td>';
			echo '<td>' . esc_html( $asset['source_mode'] ) . '</td><td><code>' . esc_html( $asset['category'] ) . '</code></td><td>' . esc_html( $asset['authority_class'] ) . '</td>';
			echo '<td>' . esc_html( ! empty( $asset['required'] ) ? 'yes' : 'no' ) . '</td>';
			$quality_profile = isset( $quality['profile'] ) ? (string) $quality['profile'] : 'legacy';
			$quality_confidence = isset( $quality['confidence'] ) ? (float) $quality['confidence'] : null;
			echo '<td><strong>' . esc_html( null === $asset['quality_score'] ? '—' : (string) $asset['quality_score'] . '/100' ) . '</strong>';
			echo '<br><span class="mad4b-context-badge">' . esc_html( $quality_profile ) . '</span>';
			echo '<br><span class="mad4b-scp-muted">' . esc_html( isset( $quality['mode'] ) ? $quality['mode'] : '' );
			if ( null !== $quality_confidence ) echo ' · ' . esc_html( number_format_i18n( $quality_confidence * 100, 0 ) . '% score confidence' );
			echo '</span></td>';
			echo '<td>' . esc_html( number_format_i18n( (float) $asset['classification_confidence'] * 100, 0 ) . '%' ) . '<br><span class="mad4b-scp-muted">' . esc_html( isset( $asset['classification_source'] ) ? $asset['classification_source'] : '' ) . '</span></td>';
			$normalization_status = isset( $asset['normalization_status'] ) ? (string) $asset['normalization_status'] : ( ! empty( $asset['content_complete'] ) ? 'ready' : 'unknown' );
			$normalization_reason = isset( $asset['normalization_reason'] ) ? (string) $asset['normalization_reason'] : '';
			echo '<td><strong>' . esc_html( $normalization_status ) . '</strong>';
			if ( $normalization_reason ) echo '<br><span class="mad4b-scp-muted">' . esc_html( $normalization_reason ) . '</span>';
			echo '</td>';
			echo '<td><strong>' . esc_html( $asset['status'] ) . '</strong>';
			if ( 'unavailable' === $asset['status'] ) {
				$source_policy = MAD4B_SCP_Context_Authority::source_write_policy( $asset['source_id'] );
				$repair_allowed = MAD4B_SCP_Context_Authority::source_allows_write( $asset['source_id'], 'recreate' );
				echo '<br><span class="mad4b-scp-muted">' . esc_html( isset( $asset['availability_reason'] ) ? $asset['availability_reason'] : 'not_seen_in_latest_scan' ) . '</span>';
				if ( $repair_allowed ) echo '<div class="mad4b-context-repair-hint"><code>context/recreate-drive-asset</code><br><span>' . esc_html__( 'Repair path available through governed approval.', 'mad4b-site-control-plane' ) . '</span></div>';
				else echo '<div class="mad4b-context-repair-hint"><span>' . esc_html( sprintf( __( 'Source policy %s blocks recreation.', 'mad4b-site-control-plane' ), $source_policy ) ) . '</span></div>';
			}
			echo '</td>';
			$write_capabilities = MAD4B_SCP_Google_Drive_Context::asset_write_capabilities( $asset['asset_id'] );
			echo '<td><div class="mad4b-context-actionability">';
			if ( ! empty( $write_capabilities['update'] ) ) echo '<code>context/update-drive-asset</code><br><span class="mad4b-scp-muted">' . esc_html__( 'Reversible text update · governed approval required.', 'mad4b-site-control-plane' ) . '</span>';
			elseif ( ! empty( $write_capabilities['recreate'] ) ) echo '<code>context/recreate-drive-asset</code><br><span class="mad4b-scp-muted">' . esc_html__( 'Reversible missing-asset recreation · governed approval required.', 'mad4b-site-control-plane' ) . '</span>';
			else {
				echo '<strong>' . esc_html__( 'No direct mutation', 'mad4b-site-control-plane' ) . '</strong>';
				if ( ! empty( $write_capabilities['blockers'] ) ) echo '<br><span class="mad4b-scp-muted">' . esc_html( implode( ' · ', array_map( 'sanitize_key', $write_capabilities['blockers'] ) ) ) . '</span>';
			}
			echo '</div></td>';
			echo '<td><details><summary class="button button-small">' . esc_html__( 'Review', 'mad4b-site-control-plane' ) . '</summary><form class="mad4b-context-review-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( self::ACTION_REVIEW_ASSET );
			echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_REVIEW_ASSET ) . '"><input type="hidden" name="asset_id" value="' . esc_attr( $asset['asset_id'] ) . '">';
			echo '<label><strong>' . esc_html__( 'Category', 'mad4b-site-control-plane' ) . '</strong><select name="category">';
			foreach ( $categories as $key => $label ) echo '<option value="' . esc_attr( $key ) . '"' . selected( $asset['category'], $key, false ) . '>' . esc_html( $label ) . '</option>';
			echo '</select></label>';
			echo '<label><strong>' . esc_html__( 'Authority', 'mad4b-site-control-plane' ) . '</strong><select name="authority_class">';
			foreach ( $authorities as $key => $label ) echo '<option value="' . esc_attr( $key ) . '"' . selected( $asset['authority_class'], $key, false ) . '>' . esc_html( $label ) . '</option>';
			echo '</select></label>';
			echo '<label><input type="checkbox" name="required" value="1"' . checked( ! empty( $asset['required'] ), true, false ) . '> ' . esc_html__( 'Required context', 'mad4b-site-control-plane' ) . '</label>';
			$human_quality_override = ! empty( $quality['human_override'] );
			$automatic_quality_score = isset( $asset['quality_auto_score'] ) ? (int) $asset['quality_auto_score'] : ( isset( $quality['automatic_score'] ) ? (int) $quality['automatic_score'] : null );
			echo '<fieldset class="mad4b-context-quality-mode"><legend><strong>' . esc_html__( 'Quality score', 'mad4b-site-control-plane' ) . '</strong></legend>';
			echo '<label><input type="radio" name="quality_mode" value="automatic"' . checked( $human_quality_override, false, false ) . '> ' . esc_html__( 'Use automatic score', 'mad4b-site-control-plane' );
			if ( null !== $automatic_quality_score ) echo ' <strong>(' . esc_html( (string) $automatic_quality_score . '/100' ) . ')</strong>';
			echo '<span>' . esc_html__( 'Recommended. Uses the current scoring profile and updates naturally when the source changes.', 'mad4b-site-control-plane' ) . '</span></label>';
			echo '<label><input type="radio" name="quality_mode" value="manual"' . checked( $human_quality_override, true, false ) . '> ' . esc_html__( 'Manual override', 'mad4b-site-control-plane' ) . '<span>' . esc_html__( 'Use only when a reviewer has a documented reason to replace the automatic score.', 'mad4b-site-control-plane' ) . '</span></label>';
			echo '<input type="number" min="0" max="100" name="quality_score" value="' . esc_attr( $human_quality_override && isset( $asset['quality_score'] ) ? (string) $asset['quality_score'] : '' ) . '" placeholder="' . esc_attr( null === $automatic_quality_score ? '0–100' : (string) $automatic_quality_score ) . '">';
			echo '</fieldset>';
			submit_button( __( 'Save Review', 'mad4b-site-control-plane' ), 'primary small', 'submit', false );
			echo '</form></details></td></tr>';
		}
		echo '</tbody></table></div></div>';
	}

	private static function render_repair_queue( array $assets ) {
		$unavailable = array();
		foreach ( $assets as $asset ) {
			if ( ! is_array( $asset ) || 'unavailable' !== ( isset( $asset['status'] ) ? (string) $asset['status'] : '' ) ) continue;
			$unavailable[] = $asset;
		}
		if ( empty( $unavailable ) ) return;

		$connection = MAD4B_SCP_Google_Drive_Context::connection_status();
		$provider_write = ! empty( $connection['write_available'] );
		echo '<div class="mad4b-scp-panel mad4b-context-repair-queue"><div class="mad4b-context-repair-title"><div><h2>' . esc_html__( 'Repair Queue', 'mad4b-site-control-plane' ) . '</h2><p>' . esc_html__( 'Unavailable governed assets are listed here first. This screen never mutates Drive directly; repair remains a governed mad4b-write operation with exact approval.', 'mad4b-site-control-plane' ) . '</p></div><span class="mad4b-context-repair-count">' . esc_html( (string) count( $unavailable ) ) . '</span></div>';
		if ( ! $provider_write ) {
			echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__( 'Google Drive is not connected with write access.', 'mad4b-site-control-plane' ) . '</strong> ' . esc_html__( 'You can still review the queue. Upgrade OAuth to Read + Write before a governed repair can mount.', 'mad4b-site-control-plane' ) . ' <a href="' . esc_url( self::tab_url( 'google-drive' ) ) . '">' . esc_html__( 'Open Google Drive settings', 'mad4b-site-control-plane' ) . '</a></p></div>';
		}
		echo '<div class="mad4b-context-repair-grid">';
		foreach ( array_slice( $unavailable, 0, 24 ) as $asset ) {
			$source_id = isset( $asset['source_id'] ) ? (string) $asset['source_id'] : '';
			$policy = MAD4B_SCP_Context_Authority::source_write_policy( $source_id );
			$cap = MAD4B_SCP_Google_Drive_Context::asset_write_capabilities( isset( $asset['asset_id'] ) ? (string) $asset['asset_id'] : '' );
			$recreate_ready = $provider_write && ! empty( $cap['recreate'] );
			$state = $recreate_ready ? 'ready' : 'blocked';
			echo '<div class="mad4b-context-repair-card is-' . esc_attr( $state ) . '">';
			echo '<div class="mad4b-context-repair-card-head"><strong>' . esc_html( isset( $asset['title'] ) ? $asset['title'] : 'Unavailable asset' ) . '</strong><span class="mad4b-context-badge">' . esc_html( $recreate_ready ? 'repair ready' : 'blocked' ) . '</span></div>';
			echo '<p class="mad4b-scp-muted">' . esc_html( isset( $asset['path'] ) ? $asset['path'] : '' ) . '</p>';
			echo '<dl><dt>' . esc_html__( 'Reason', 'mad4b-site-control-plane' ) . '</dt><dd><code>' . esc_html( isset( $asset['availability_reason'] ) ? $asset['availability_reason'] : 'not_seen_in_latest_scan' ) . '</code></dd>';
			echo '<dt>' . esc_html__( 'Source policy', 'mad4b-site-control-plane' ) . '</dt><dd><code>' . esc_html( $policy ) . '</code></dd>';
			echo '<dt>' . esc_html__( 'Governed ability', 'mad4b-site-control-plane' ) . '</dt><dd><code>context/recreate-drive-asset</code></dd>';
			echo '<dt>' . esc_html__( 'Authority', 'mad4b-site-control-plane' ) . '</dt><dd>' . esc_html( ! empty( $asset['authority_class'] ) ? $asset['authority_class'] : 'reference' ) . ( ! empty( $asset['required'] ) ? ' · required' : '' ) . '</dd></dl>';
			if ( ! $recreate_ready ) {
				$blockers = isset( $cap['blockers'] ) && is_array( $cap['blockers'] ) ? array_values( array_unique( array_map( 'sanitize_key', $cap['blockers'] ) ) ) : array();
				if ( ! $provider_write ) array_unshift( $blockers, 'google_drive_write_scope_not_granted' );
				$blockers = array_values( array_unique( $blockers ) );
				if ( $blockers ) echo '<p><strong>' . esc_html__( 'Blockers:', 'mad4b-site-control-plane' ) . '</strong><br><code>' . esc_html( implode( ' · ', $blockers ) ) . '</code></p>';
			} else {
				echo '<p class="mad4b-scp-muted">' . esc_html__( 'Use the normal approval-plan → human approval → mad4b-write execution flow. No direct repair button is exposed here.', 'mad4b-site-control-plane' ) . '</p>';
			}
			echo '</div>';
		}
		echo '</div>';
		if ( count( $unavailable ) > 24 ) echo '<p class="description">' . esc_html( sprintf( __( 'Showing the first 24 of %d unavailable assets. Use the availability filter below to review all items.', 'mad4b-site-control-plane' ), count( $unavailable ) ) ) . '</p>';
		echo '</div>';
	}

	private static function render_quality() {
		$assets = MAD4B_SCP_Context_Authority::assets();
		$groups = array();
		foreach ( $assets as $asset ) {
			$category = isset( $asset['category'] ) ? $asset['category'] : 'uncategorized';
			if ( ! isset( $groups[ $category ] ) ) $groups[ $category ] = array( 'count' => 0, 'scores' => array(), 'confidences' => array(), 'profiles' => array(), 'provisional' => 0 );
			++$groups[ $category ]['count'];
			if ( null !== $asset['quality_score'] ) $groups[ $category ]['scores'][] = (int) $asset['quality_score'];
			$quality = isset( $asset['quality'] ) && is_array( $asset['quality'] ) ? $asset['quality'] : array();
			if ( isset( $quality['confidence'] ) ) $groups[ $category ]['confidences'][] = (float) $quality['confidence'];
			if ( ! empty( $quality['profile'] ) ) $groups[ $category ]['profiles'][] = (string) $quality['profile'];
			if ( ! empty( $quality['provisional'] ) ) ++$groups[ $category ]['provisional'];
		}
		ksort( $groups );
		echo '<div class="mad4b-scp-panel"><h2>' . esc_html__( 'Content Quality', 'mad4b-site-control-plane' ) . '</h2>';
		echo '<p>' . esc_html__( 'Quality and authority are separate. A high-quality reference never outranks an authoritative Brand Core asset. Scores are transparent and show whether content or metadata was analyzed.', 'mad4b-site-control-plane' ) . '</p>';
		echo '<h3>' . esc_html__( 'Normalization support', 'mad4b-site-control-plane' ) . '</h3><div class="mad4b-scp-table-wrap"><table class="widefat striped"><thead><tr><th>File type</th><th>Mode</th><th>Status</th><th>Notes</th></tr></thead><tbody>';
		foreach ( MAD4B_SCP_Google_Drive_Context::normalization_capabilities() as $capability ) {
			echo '<tr><td><strong>' . esc_html( $capability['type'] ) . '</strong><br><code>' . esc_html( $capability['mime'] ) . '</code></td><td>' . esc_html( $capability['mode'] ) . '</td><td>' . esc_html( $capability['status'] ) . '</td><td>' . esc_html( $capability['note'] ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
		if ( ! $groups ) { echo '<p>' . esc_html__( 'No quality evidence yet.', 'mad4b-site-control-plane' ) . '</p></div>'; return; }
		echo '<div class="mad4b-scp-table-wrap"><table class="widefat striped"><thead><tr><th>Category</th><th>Quality profile</th><th>Assets</th><th>Average score</th><th>Score confidence</th><th>Metadata-only</th></tr></thead><tbody>';
		foreach ( $groups as $category => $group ) {
			$average = $group['scores'] ? (int) round( array_sum( $group['scores'] ) / count( $group['scores'] ) ) : null;
			$confidence = $group['confidences'] ? array_sum( $group['confidences'] ) / count( $group['confidences'] ) : null;
			$profiles = array_values( array_unique( $group['profiles'] ) );
			$profile = $profiles ? implode( ', ', $profiles ) : 'legacy';
			echo '<tr><td><code>' . esc_html( $category ) . '</code></td><td><code>' . esc_html( $profile ) . '</code></td><td>' . esc_html( (string) $group['count'] ) . '</td><td>' . esc_html( null === $average ? '—' : $average . '/100' ) . '</td><td>' . esc_html( null === $confidence ? '—' : number_format_i18n( $confidence * 100, 0 ) . '%' ) . '</td><td>' . esc_html( (string) $group['provisional'] ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
		echo '<h3>' . esc_html__( 'Scoring dimensions', 'mad4b-site-control-plane' ) . '</h3><p><code>freshness · completeness · structure · specificity · source_quality · language_quality · retrieval_quality · extractability</code></p>';
		echo '<p class="description">' . esc_html__( 'Weights change by quality profile. Authority is never part of the quality score; Brand Authority and human review remain separate governance dimensions.', 'mad4b-site-control-plane' ) . '</p>';
		echo '</div>';
	}

	private static function render_intelligence() {
		echo '<div class="mad4b-scp-panel"><h2>' . esc_html__( 'Context Intelligence', 'mad4b-site-control-plane' ) . '</h2>';
		echo '<p>' . esc_html__( 'These analyses are read-only and run only when you request them. Required Brand Context never competes in ranking; conflicts are never auto-resolved; Writer Profiles expose structural signals without copying source text.', 'mad4b-site-control-plane' ) . '</p>';
		if ( ! class_exists( 'MAD4B_SCP_Context_Intelligence' ) ) {
			echo '<div class="notice notice-error inline"><p><code>context_intelligence_unavailable</code></p></div></div>';
			return;
		}

		$action = isset( $_GET['intel_action'] ) ? sanitize_key( wp_unslash( $_GET['intel_action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- validated below before any provider read.
		$result = null;
		if ( '' !== $action ) {
			$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( ! wp_verify_nonce( $nonce, 'mad4b_context_intelligence' ) ) {
				$result = new WP_Error( 'mad4b_context_intelligence_nonce_invalid', 'Context Intelligence request expired. Run the analysis again.' );
			} elseif ( 'conflicts' === $action ) {
				$result = MAD4B_SCP_Context_Intelligence::conflict_report(
					array(
						'category' => isset( $_GET['category'] ) ? wp_unslash( $_GET['category'] ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
						'limit' => 25,
					)
				);
			} elseif ( 'retrieve' === $action ) {
				$result = MAD4B_SCP_Context_Intelligence::retrieve(
					array(
						'query' => isset( $_GET['query'] ) ? wp_unslash( $_GET['query'] ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
						'task_scope' => isset( $_GET['task_scope'] ) ? wp_unslash( $_GET['task_scope'] ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
						'category' => isset( $_GET['category'] ) ? wp_unslash( $_GET['category'] ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
						'limit' => 10,
					)
				);
			} elseif ( 'reference' === $action ) {
				$result = MAD4B_SCP_Context_Intelligence::reference_profile(
					array(
						'asset_id' => isset( $_GET['asset_id'] ) ? wp_unslash( $_GET['asset_id'] ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					)
				);
			}
		}

		$nonce = wp_create_nonce( 'mad4b_context_intelligence' );
		echo '<div class="mad4b-context-intelligence-grid">';

		echo '<section class="mad4b-context-intelligence-card"><h3>' . esc_html__( 'Conflict Review', 'mad4b-site-control-plane' ) . '</h3>';
		echo '<p>' . esc_html__( 'Looks only for explicit keyed directives in approved Brand/Policy assets. Different values become review candidates; the system never chooses a winner.', 'mad4b-site-control-plane' ) . '</p>';
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '"><input type="hidden" name="page" value="' . esc_attr( self::PAGE_SLUG ) . '"><input type="hidden" name="tab" value="intelligence"><input type="hidden" name="intel_action" value="conflicts"><input type="hidden" name="_wpnonce" value="' . esc_attr( $nonce ) . '">';
		echo '<label><strong>' . esc_html__( 'Category (optional)', 'mad4b-site-control-plane' ) . '</strong><select name="category"><option value="">' . esc_html__( 'All approved authority categories', 'mad4b-site-control-plane' ) . '</option>';
		foreach ( MAD4B_SCP_Context_Authority::categories() as $key => $label ) echo '<option value="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</option>';
		echo '</select></label>';
		submit_button( __( 'Analyze Conflicts', 'mad4b-site-control-plane' ), 'secondary', 'submit', false );
		echo '</form></section>';

		echo '<section class="mad4b-context-intelligence-card"><h3>' . esc_html__( 'Context Retrieval Preview', 'mad4b-site-control-plane' ) . '</h3>';
		echo '<p>' . esc_html__( 'Required governed assets are always returned separately as mandatory. Only optional/task/reference assets are ranked.', 'mad4b-site-control-plane' ) . '</p>';
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '"><input type="hidden" name="page" value="' . esc_attr( self::PAGE_SLUG ) . '"><input type="hidden" name="tab" value="intelligence"><input type="hidden" name="intel_action" value="retrieve"><input type="hidden" name="_wpnonce" value="' . esc_attr( $nonce ) . '">';
		echo '<label><strong>' . esc_html__( 'Task / query', 'mad4b-site-control-plane' ) . '</strong><input type="text" name="query" class="regular-text" required maxlength="' . esc_attr( (string) MAD4B_SCP_Context_Intelligence::MAX_QUERY_BYTES ) . '" placeholder="' . esc_attr__( 'e.g. family travel in Luxor', 'mad4b-site-control-plane' ) . '"></label>';
		echo '<label><strong>' . esc_html__( 'Task scope (optional)', 'mad4b-site-control-plane' ) . '</strong><input type="text" name="task_scope" class="regular-text" placeholder="campaign-2026"></label>';
		submit_button( __( 'Preview Retrieval', 'mad4b-site-control-plane' ), 'secondary', 'submit', false );
		echo '</form></section>';

		$reference_assets = array();
		foreach ( MAD4B_SCP_Context_Authority::assets() as $asset ) {
			if ( ! is_array( $asset ) || 'ready' !== ( isset( $asset['status'] ) ? (string) $asset['status'] : '' ) ) continue;
			if ( 'governed' !== ( isset( $asset['source_mode'] ) ? (string) $asset['source_mode'] : '' ) || 'approved' !== ( isset( $asset['review_status'] ) ? (string) $asset['review_status'] : '' ) ) continue;
			$category = isset( $asset['category'] ) ? (string) $asset['category'] : '';
			if ( ! in_array( $category, array( 'writer_reference', 'content_example', 'historical_content' ), true ) && 'reference' !== ( isset( $asset['authority_class'] ) ? (string) $asset['authority_class'] : '' ) ) continue;
			$reference_assets[] = $asset;
		}
		echo '<section class="mad4b-context-intelligence-card"><h3>' . esc_html__( 'Writer / Reference Profile', 'mad4b-site-control-plane' ) . '</h3>';
		echo '<p>' . esc_html__( 'Extracts sentence rhythm, paragraph density, question ratio and lexical richness. It never returns the source text or an instruction to imitate a named writer.', 'mad4b-site-control-plane' ) . '</p>';
		if ( $reference_assets ) {
			echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '"><input type="hidden" name="page" value="' . esc_attr( self::PAGE_SLUG ) . '"><input type="hidden" name="tab" value="intelligence"><input type="hidden" name="intel_action" value="reference"><input type="hidden" name="_wpnonce" value="' . esc_attr( $nonce ) . '"><select name="asset_id">';
			foreach ( $reference_assets as $asset ) echo '<option value="' . esc_attr( $asset['asset_id'] ) . '">' . esc_html( $asset['title'] ) . '</option>';
			echo '</select> ';
			submit_button( __( 'Build Structural Profile', 'mad4b-site-control-plane' ), 'secondary', 'submit', false );
			echo '</form>';
		} else {
			echo '<p class="mad4b-scp-muted">' . esc_html__( 'No ready Writer Reference / Content Example assets yet.', 'mad4b-site-control-plane' ) . '</p>';
		}
		echo '</section>';

		echo '<section class="mad4b-context-intelligence-card"><h3>' . esc_html__( 'Brand Compliance', 'mad4b-site-control-plane' ) . '</h3>';
		echo '<p>' . esc_html__( 'Compliance is intentionally not a free-form Admin check. The governed ability requires the exact Context Receipt issued by Skill Context Preflight, so the draft is checked against the same revision, registry and asset set used for generation.', 'mad4b-site-control-plane' ) . '</p>';
		echo '<p><code>context/compliance-check</code><br><span class="mad4b-scp-muted">explicit_machine_readable_rules_only · semantic_model_used=false</span></p></section>';
		echo '</div>';

		if ( null !== $result ) self::render_intelligence_result( $action, $result );
		echo '</div>';
	}

	private static function render_intelligence_result( $action, $result ) {
		echo '<div class="mad4b-scp-panel mad4b-context-intelligence-result"><h2>' . esc_html__( 'Analysis Result', 'mad4b-site-control-plane' ) . '</h2>';
		if ( is_wp_error( $result ) ) {
			echo '<div class="notice notice-error inline"><p><code>' . esc_html( $result->get_error_code() ) . '</code> · ' . esc_html( $result->get_error_message() ) . '</p></div></div>';
			return;
		}
		if ( 'conflicts' === $action ) {
			echo '<p><strong>' . esc_html__( 'State:', 'mad4b-site-control-plane' ) . '</strong> ' . esc_html( isset( $result['state'] ) ? $result['state'] : '' ) . ' · <strong>' . esc_html__( 'Conflicts:', 'mad4b-site-control-plane' ) . '</strong> ' . esc_html( isset( $result['conflict_count'] ) ? (string) $result['conflict_count'] : '0' ) . '</p>';
			if ( ! empty( $result['blockers'] ) ) echo '<div class="notice notice-error inline"><p><strong>' . esc_html__( 'Coverage incomplete:', 'mad4b-site-control-plane' ) . '</strong> <code>' . esc_html( implode( ' · ', $result['blockers'] ) ) . '</code></p></div>';
			if ( ! empty( $result['warnings'] ) ) echo '<p class="mad4b-scp-muted"><strong>' . esc_html__( 'Warnings:', 'mad4b-site-control-plane' ) . '</strong> <code>' . esc_html( implode( ' · ', $result['warnings'] ) ) . '</code></p>';
			foreach ( isset( $result['conflicts'] ) && is_array( $result['conflicts'] ) ? $result['conflicts'] : array() as $conflict ) {
				echo '<div class="mad4b-context-conflict-card"><strong>' . esc_html( isset( $conflict['category'] ) ? $conflict['category'] : '' ) . ' / ' . esc_html( isset( $conflict['directive_key'] ) ? $conflict['directive_key'] : '' ) . '</strong> <span class="mad4b-context-badge">' . esc_html( isset( $conflict['severity'] ) ? $conflict['severity'] : '' ) . '</span>';
				echo '<p class="mad4b-scp-muted">' . esc_html__( 'Human resolution required. No winner was selected automatically.', 'mad4b-site-control-plane' ) . '</p><ul>';
				foreach ( isset( $conflict['assets'] ) && is_array( $conflict['assets'] ) ? $conflict['assets'] : array() as $row ) echo '<li><strong>' . esc_html( isset( $row['title'] ) ? $row['title'] : '' ) . '</strong> · ' . esc_html( isset( $row['authority_class'] ) ? $row['authority_class'] : '' ) . ' · <code>' . esc_html( isset( $row['value_fingerprint'] ) ? substr( $row['value_fingerprint'], 0, 12 ) : '' ) . '…</code></li>';
				echo '</ul></div>';
			}
		} elseif ( 'retrieve' === $action ) {
			echo '<p><strong>' . esc_html__( 'Ranking:', 'mad4b-site-control-plane' ) . '</strong> <code>' . esc_html( isset( $result['ranking_mode'] ) ? $result['ranking_mode'] : '' ) . '</code></p>';
			echo '<h3>' . esc_html__( 'Mandatory — not ranked', 'mad4b-site-control-plane' ) . '</h3><ul>';
			foreach ( isset( $result['mandatory_assets'] ) && is_array( $result['mandatory_assets'] ) ? $result['mandatory_assets'] : array() as $row ) echo '<li><strong>' . esc_html( $row['title'] ) . '</strong> · <code>' . esc_html( $row['category'] ) . '</code></li>';
			echo '</ul><h3>' . esc_html__( 'Ranked optional / task context', 'mad4b-site-control-plane' ) . '</h3><ol>';
			foreach ( isset( $result['ranked_optional_assets'] ) && is_array( $result['ranked_optional_assets'] ) ? $result['ranked_optional_assets'] : array() as $row ) echo '<li><strong>' . esc_html( $row['title'] ) . '</strong> · ' . esc_html( number_format_i18n( (float) $row['retrieval_score'] * 100, 1 ) . '%' ) . ' · <code>' . esc_html( $row['category'] ) . '</code></li>';
			echo '</ol>';
			if ( ! empty( $result['blockers'] ) ) echo '<p><strong>Blockers:</strong> <code>' . esc_html( implode( ' · ', $result['blockers'] ) ) . '</code></p>';
		} elseif ( 'reference' === $action ) {
			echo '<p><strong>' . esc_html( isset( $result['title'] ) ? $result['title'] : '' ) . '</strong> · <code>' . esc_html( isset( $result['usage'] ) ? $result['usage'] : '' ) . '</code></p>';
			echo '<div class="mad4b-context-governance-grid">';
			foreach ( isset( $result['style_signals'] ) && is_array( $result['style_signals'] ) ? $result['style_signals'] : array() as $key => $value ) echo '<div class="mad4b-context-governance-cell"><span>' . esc_html( str_replace( '_', ' ', $key ) ) . '</span><strong>' . esc_html( (string) $value ) . '</strong></div>';
			echo '</div><div class="mad4b-scp-table-wrap"><table class="widefat striped"><tbody>';
			foreach ( isset( $result['metrics'] ) && is_array( $result['metrics'] ) ? $result['metrics'] : array() as $key => $value ) echo '<tr><th>' . esc_html( str_replace( '_', ' ', $key ) ) . '</th><td>' . esc_html( (string) $value ) . '</td></tr>';
			echo '</tbody></table></div>';
		}
		echo '</div>';
	}

	private static function render_notice() {
		$notice = isset( $_GET['mad4b_notice'] ) ? sanitize_key( wp_unslash( $_GET['mad4b_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- post-action status only.
		$error = isset( $_GET['mad4b_error'] ) ? sanitize_key( wp_unslash( $_GET['mad4b_error'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- post-action status only.
		if ( $error ) {
			echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Action stopped safely:', 'mad4b-site-control-plane' ) . '</strong> <code>' . esc_html( $error ) . '</code></p></div>';
			return;
		}
		$messages = array(
			'brand_profile_saved' => __( 'Brand Context Profile saved.', 'mad4b-site-control-plane' ),
			'google_credentials_saved' => __( 'Google OAuth configuration saved securely.', 'mad4b-site-control-plane' ),
			'google_dedicated_credentials_saved' => __( 'Dedicated Google OAuth configuration saved securely.', 'mad4b-site-control-plane' ),
			'google_auth_mode_saved' => __( 'Google connection method saved.', 'mad4b-site-control-plane' ),
			'google_grants_saved' => __( 'Google Workspace grants saved.', 'mad4b-site-control-plane' ),
			'google_connected' => __( 'Google Drive connected.', 'mad4b-site-control-plane' ),
			'google_disconnected' => __( 'Google Drive disconnected. Existing Context assets were not deleted.', 'mad4b-site-control-plane' ),
			'source_selected' => __( 'Source folder added. Scan it when you are ready.', 'mad4b-site-control-plane' ),
			'source_policy_updated' => __( 'Source write policy updated. Runtime write eligibility will follow the selected policy and OAuth scope.', 'mad4b-site-control-plane' ),
			'source_scanned' => __( 'Source scan completed and Context assets were refreshed.', 'mad4b-site-control-plane' ),
			'source_scanned_truncated' => __( 'Source scan was partial. Seen assets were refreshed, but unseen assets were not marked unavailable. Narrow the folder or increase certified coverage before using absence as evidence.', 'mad4b-site-control-plane' ),
			'asset_review_saved' => __( 'Asset classification and quality review saved and the Context fingerprint was refreshed.', 'mad4b-site-control-plane' ),
			'source_removed' => __( 'Source and its indexed assets were removed. Google Drive content was not changed.', 'mad4b-site-control-plane' ),
		);
		if ( 'google_connected' === $notice ) {
			$connection = MAD4B_SCP_Google_Drive_Context::connection_status();
			$mode = ! empty( $connection['write_available'] ) ? __( 'Read + Write', 'mad4b-site-control-plane' ) : __( 'Read-only', 'mad4b-site-control-plane' );
			echo '<div class="notice notice-success"><p>' . esc_html( sprintf( __( 'Google Drive connected. Access mode: %s.', 'mad4b-site-control-plane' ), $mode ) ) . '</p></div>';
			return;
		}
		if ( $notice && isset( $messages[ $notice ] ) ) echo '<div class="notice notice-success"><p>' . esc_html( $messages[ $notice ] ) . '</p></div>';
	}

	private static function is_ajax_request() {
		return function_exists( 'wp_doing_ajax' ) && wp_doing_ajax();
	}

	private static function require_admin_request( $action ) {
		if ( ! self::is_ajax_request() ) {
			self::require_admin( $action );
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( array( 'code' => 'mad4b_context_admin_required', 'message' => __( 'Administrator capability is required.', 'mad4b-site-control-plane' ) ), 403 );
		if ( false === check_ajax_referer( $action, '_wpnonce', false ) ) wp_send_json_error( array( 'code' => 'mad4b_context_ajax_nonce_invalid', 'message' => __( 'The settings request expired. Refresh the page and try again.', 'mad4b-site-control-plane' ) ), 403 );
	}

	private static function finish_result( $result, $tab, $notice, $message ) {
		if ( ! self::is_ajax_request() ) {
			self::redirect_result( $result, $tab, $notice );
			return;
		}
		if ( is_wp_error( $result ) ) wp_send_json_error( array( 'code' => sanitize_key( $result->get_error_code() ), 'message' => $result->get_error_message() ), 422 );
		wp_send_json_success(
			array(
				'message' => (string) $message,
				'auth_mode' => MAD4B_SCP_Google_Drive_Context::auth_mode(),
				'workspace_grants' => MAD4B_SCP_Google_Drive_Context::workspace_grants_status(),
				'refresh' => true,
			)
		);
	}

	private static function require_admin( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'Administrator capability is required.', 'mad4b-site-control-plane' ), '', array( 'response' => 403 ) );
		check_admin_referer( $action );
	}

	private static function redirect_result( $result, $tab, $notice ) {
		$args = array( 'page' => self::PAGE_SLUG, 'tab' => sanitize_key( $tab ) );
		if ( is_wp_error( $result ) ) $args['mad4b_error'] = sanitize_key( $result->get_error_code() );
		elseif ( '' !== $notice ) $args['mad4b_notice'] = sanitize_key( $notice );
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	private static function tab_url( $tab, array $extra = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE_SLUG, 'tab' => sanitize_key( $tab ) ), $extra ), admin_url( 'admin.php' ) );
	}

	private static function scripts() {
		echo '<script>
		(function(){
			function feedback(message, ok, code){
				document.querySelectorAll(".mad4b-context-page > .notice").forEach(function(node){node.remove();});
				var box=document.getElementById("mad4b-google-ajax-feedback");
				if(!box)return;
				box.className="mad4b-google-ajax-feedback notice "+(ok?"notice-success":"notice-error")+" inline";
				box.innerHTML="<p></p>";
				box.querySelector("p").textContent=(code?code+" · ":"")+(message||"");
			}
			function clearStatusQuery(){
				try{
					var url=new URL(window.location.href);
					var changed=false;
					["mad4b_error","mad4b_notice"].forEach(function(key){if(url.searchParams.has(key)){url.searchParams.delete(key);changed=true;}});
					if(changed&&window.history&&window.history.replaceState)window.history.replaceState({},document.title,url.toString());
				}catch(error){}
			}
			async function refreshPanels(){
				var response=await fetch(window.location.href,{credentials:"same-origin",headers:{"X-MAD4B-Fragment":"google-context","Cache-Control":"no-cache"}});
				if(!response.ok)throw new Error("Updated settings were saved, but the Google setup panels could not be refreshed.");
				var html=await response.text();
				var doc=new DOMParser().parseFromString(html,"text/html");
				["mad4b-google-connection-method","mad4b-google-grants","mad4b-google-auth-setup","mad4b-google-connect"].forEach(function(id){
					var current=document.getElementById(id), next=doc.getElementById(id);
					if(current&&next)current.replaceWith(next);
				});
			}
			document.addEventListener("click",function(event){
				var button=event.target.closest(".mad4b-select-full-suite");
				if(!button)return;
				event.preventDefault();
				var form=button.closest("form");
				if(!form)return;
				form.querySelectorAll("select[data-full-mode]").forEach(function(select){select.value=select.getAttribute("data-full-mode");});
				feedback("Full Apps Suite selected. Save Grants to persist the reviewed scope set.",true,"");
			});
			document.addEventListener("change",function(event){
				var input=event.target.closest(".mad4b-context-auth-mode-form input[name=auth_mode]");
				if(!input)return;
				var form=input.closest("form");
				if(!form||form.dataset.mad4bBusy==="1")return;
				if(typeof form.requestSubmit==="function")form.requestSubmit();
				else form.dispatchEvent(new Event("submit",{bubbles:true,cancelable:true}));
			});
			document.addEventListener("submit",async function(event){
				var form=event.target.closest(".mad4b-context-ajax-form");
				if(!form)return;
				event.preventDefault();
				if(form.dataset.mad4bBusy==="1")return;
				form.dataset.mad4bBusy="1";
				form.setAttribute("aria-busy","true");
				var body=new URLSearchParams(new FormData(form));
				var controls=Array.prototype.slice.call(form.querySelectorAll("button,input,select,textarea"));
				var priorDisabled=controls.map(function(control){return control.disabled;});
				controls.forEach(function(control){control.disabled=true;});
				feedback("Saving…",true,"");
				try{
					var response=await fetch(window.ajaxurl,{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/x-www-form-urlencoded; charset=UTF-8","X-Requested-With":"XMLHttpRequest"},body:body.toString()});
					var payload=await response.json();
					if(!payload||!payload.success){
						var failure=new Error(payload&&payload.data&&payload.data.message?payload.data.message:"Settings could not be saved.");
						failure.mad4bCode=payload&&payload.data&&payload.data.code?payload.data.code:"mad4b_context_ajax_save_failed";
						throw failure;
					}
					form.querySelectorAll("input[type=password]").forEach(function(input){input.value="";});
					if(payload.data&&payload.data.refresh)await refreshPanels();
					clearStatusQuery();
					feedback(payload.data&&payload.data.message?payload.data.message:"Saved.",true,"");
				}catch(error){
					try{await refreshPanels();}catch(refreshError){}
					feedback(error&&error.message?error.message:"Settings could not be saved.",false,error&&error.mad4bCode?error.mad4bCode:"");
				}finally{
					form.removeAttribute("aria-busy");
					delete form.dataset.mad4bBusy;
					controls.forEach(function(control,index){control.disabled=priorDisabled[index];});
				}
			});
		})();
		</script>';
	}

	private static function styles() {
		echo '<style>
		.mad4b-context-page .mad4b-context-folder-head{display:flex;justify-content:space-between;gap:12px;align-items:center;margin-bottom:16px}
		.mad4b-context-folder-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:10px;margin:14px 0 22px}
		.mad4b-context-folder{display:flex;align-items:center;gap:8px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:5px;padding:12px;text-decoration:none;color:#1d2327}
		.mad4b-context-folder:hover{background:#fff;border-color:#72aee6}.mad4b-context-folder .dashicons{color:#dba617}
		.mad4b-context-source-mode{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:10px;margin:12px 0}
		.mad4b-google-ajax-feedback{margin:12px 0}.mad4b-google-grant-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:10px;margin:14px 0}.mad4b-google-grant-card{border:1px solid #dcdcde;border-radius:5px;background:#fff;padding:12px}.mad4b-google-grant-card select{width:100%;margin-top:7px}
		.mad4b-google-primary-signin{border-left:4px solid #4285f4}.mad4b-google-signin-button{display:inline-flex!important;align-items:center;justify-content:center;min-height:42px;padding:0 20px!important;background:#fff!important;border:1px solid #dadce0!important;border-radius:4px!important;color:#3c4043!important;font-weight:600;box-shadow:0 1px 2px rgba(60,64,67,.15)!important}.mad4b-google-signin-button:hover{background:#f8faff!important;border-color:#c6dafc!important;color:#202124!important}.mad4b-google-advanced{margin:16px 0}.mad4b-google-advanced>summary{cursor:pointer;padding:12px 14px;border:1px solid #dcdcde;border-radius:5px;background:#f6f7f7}.mad4b-google-advanced[open]>summary{margin-bottom:12px}.mad4b-google-advanced>summary span{color:#646970;font-weight:400;margin-left:6px}
		.mad4b-context-source-mode label{display:block;border:1px solid #dcdcde;border-radius:5px;padding:12px;background:#fff}.mad4b-context-source-mode label span{display:block;margin:5px 0 0 24px;color:#646970}
		.mad4b-context-badge{display:inline-block;padding:3px 7px;border-radius:12px;background:#f0f0f1;font-size:12px}.mad4b-context-fingerprint{margin:18px 0;color:#646970}
		.mad4b-context-repair-title{display:flex;align-items:flex-start;justify-content:space-between;gap:16px}.mad4b-context-repair-title h2{margin-top:0}.mad4b-context-repair-count{display:inline-flex;min-width:38px;height:38px;align-items:center;justify-content:center;border-radius:20px;background:#f0f0f1;font-weight:700;font-size:16px}
		.mad4b-context-repair-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:12px;margin-top:14px}.mad4b-context-repair-card{border:1px solid #dcdcde;border-left-width:4px;border-radius:5px;background:#fff;padding:13px}.mad4b-context-repair-card.is-ready{border-left-color:#00a32a}.mad4b-context-repair-card.is-blocked{border-left-color:#dba617}.mad4b-context-repair-card-head{display:flex;justify-content:space-between;gap:8px}.mad4b-context-repair-card dl{display:grid;grid-template-columns:auto 1fr;gap:5px 10px}.mad4b-context-repair-card dt{font-weight:600}.mad4b-context-repair-card dd{margin:0;min-width:0;overflow-wrap:anywhere}
		.mad4b-context-governance-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:10px;margin:14px 0}.mad4b-context-governance-cell{border:1px solid #dcdcde;border-radius:5px;padding:12px;background:#fff}.mad4b-context-governance-cell span{display:block;color:#646970;margin-bottom:5px}.mad4b-context-governance-cell strong{display:block}.mad4b-context-governance-cell.is-complete{border-left:4px solid #00a32a}.mad4b-context-governance-cell.is-attention{border-left:4px solid #dba617}.mad4b-context-governance-cell.is-pending{border-left:4px solid #8c8f94}
		.mad4b-context-review-form{min-width:260px;padding:12px;background:#fff;border:1px solid #dcdcde;margin-top:8px}.mad4b-context-review-form label{display:block;margin:0 0 10px}.mad4b-context-review-form select,.mad4b-context-review-form input[type=number]{display:block;width:100%;margin-top:4px}.mad4b-context-quality-mode{border:0;padding:0;margin:0 0 12px}.mad4b-context-quality-mode label{padding:8px;border:1px solid #dcdcde;border-radius:4px}.mad4b-context-quality-mode label span{display:block;margin:4px 0 0 22px;color:#646970;font-weight:400}
		.mad4b-context-filterbar{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:12px 0 16px}.mad4b-context-intelligence-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:12px;margin:14px 0}.mad4b-context-intelligence-card{border:1px solid #dcdcde;border-radius:5px;background:#fff;padding:14px}.mad4b-context-intelligence-card h3{margin-top:0}.mad4b-context-intelligence-card label{display:block;margin:10px 0}.mad4b-context-intelligence-card select,.mad4b-context-intelligence-card input[type=text]{width:100%;margin-top:4px}.mad4b-context-conflict-card{border-left:4px solid #dba617;background:#fff8e5;padding:12px;margin:10px 0}.mad4b-context-folder-jump{margin:14px 0 18px}.mad4b-context-folder-jump label{display:block;margin-bottom:6px}.mad4b-context-filterbar select,.mad4b-context-filterbar input{max-width:220px}.mad4b-context-remove{margin-top:8px}.mad4b-context-remove form{margin-top:8px;max-width:280px}
		@media(max-width:782px){.mad4b-context-folder-head{align-items:flex-start!important;flex-direction:column}}
		</style>';
	}
}
