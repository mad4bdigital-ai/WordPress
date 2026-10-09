<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Browser setup is an operator preference, NOT browser execution or credential storage. */
final class MAD4B_SCP_Browser_Acceptance_Admin_UI {
	const PAGE_SLUG = 'mad4b-browser-acceptance';
	const OPTION = 'mad4b_scp_browser_operator_preferences_v1';
	private static $booted = false;

	public static function managed_executors() {
		return array(
			'cloudflare' => array( 'Cloudflare Browser Run', array( 'CLOUDFLARE_ACCOUNT_ID', 'CLOUDFLARE_BROWSER_RUN_API_TOKEN' ) ),
			'browserbase' => array( 'Browserbase', array( 'BROWSERBASE_API_KEY' ) ),
			'browserless' => array( 'Browserless', array( 'BROWSERLESS_TOKEN' ) ),
			'steel' => array( 'Steel', array( 'STEEL_API_KEY' ) ),
		);
	}

	public static function normalize( $input ) {
		if ( ! is_array( $input ) || array_diff( array_keys( $input ), array( 'executor', 'profile_id', 'site_provider_id', 'configuration_revision' ) ) )
			return new WP_Error( 'mad4b_browser_setup_unexpected_fields', 'Secret, script, URL and unsupported fields are not accepted.' );
		$executor = isset( $input['executor'] ) ? $input['executor'] : 'auto';
		$profile = isset( $input['profile_id'] ) ? $input['profile_id'] : '';
		$site_provider = isset( $input['site_provider_id'] ) ? $input['site_provider_id'] : '';
		if ( ! is_string( $executor ) || ! in_array( $executor, array_merge( array( 'auto' ), array_keys( self::managed_executors() ) ), true ) )
			return new WP_Error( 'mad4b_browser_setup_invalid_executor', 'Only allowlisted external browser providers are supported.' );
		if ( ! is_string( $profile ) || strlen( $profile ) > 64 || ( '' !== $profile && ! preg_match( '/^[a-z0-9][a-z0-9._-]{0,63}$/D', $profile ) ) )
			return new WP_Error( 'mad4b_browser_setup_invalid_profile', 'Invalid site-specific acceptance profile ID.' );
		if ( ! is_string( $site_provider ) || strlen( $site_provider ) > 64 || ( '' !== $site_provider && ! preg_match( '/^[a-z0-9][a-z0-9._-]{0,63}$/D', $site_provider ) ) )
			return new WP_Error( 'mad4b_browser_setup_invalid_site_provider', 'Invalid acceptance provider ID.' );
		$revision = isset( $input['configuration_revision'] ) ? $input['configuration_revision'] : '';
		if ( ! is_string( $revision ) || ( '' !== $revision && ! preg_match( '/^[a-f0-9]{32}$/D', $revision ) ) )
			return new WP_Error( 'mad4b_browser_setup_revision_invalid', 'Invalid saved operator configuration revision.' );
		return array( 'executor' => $executor, 'profile_id' => $profile, 'site_provider_id' => $site_provider, 'configuration_revision' => $revision );
	}

	public static function selection() {
		$stored = get_option( self::OPTION, array() );
		$known = is_array( $stored ) ? array_intersect_key( $stored, array_flip( array( 'executor', 'profile_id', 'site_provider_id', 'configuration_revision' ) ) ) : array();
		$valid = self::normalize( $known );
		return is_wp_error( $valid ) ? array( 'executor' => 'auto', 'profile_id' => '', 'site_provider_id' => '', 'configuration_revision' => '' ) : $valid;
	}

	/** Check the raw persisted operator selection, not the UI's display fallback. */
	public static function runtime_target_guard( $provider_id, $profile_id ) {
		$raw = get_option( self::OPTION, array() );
		$setting = self::normalize( $raw );
		if ( is_wp_error( $setting ) ) return new WP_Error( 'mad4b_browser_operator_preference_corrupt', 'Browser operator selection is invalid; re-save the approved profile before running tests.' );
		if ( '' !== $setting['site_provider_id'] && $setting['site_provider_id'] !== (string) $provider_id )
			return new WP_Error( 'mad4b_browser_operator_site_provider_mismatch', 'Requested Browser Acceptance site provider differs from the configured operator preference.' );
		if ( '' !== $setting['profile_id'] && $setting['profile_id'] !== (string) $profile_id )
			return new WP_Error( 'mad4b_browser_operator_profile_mismatch', 'Requested Browser Acceptance profile differs from the configured operator preference.' );
		return true;
	}

	public static function public_selection() {
		$value = self::selection();
		$stored = get_option( self::OPTION, array() );
		$preference_valid = ! is_wp_error( self::normalize( $stored ) );
		// A pristine site may auto-resolve a *read-only preference* without a
		// manual first save. The sentinel is scoped by independent origin, profile,
		// provider and discovery-source checks in the external acceptance runner.
		// Any actual saved choice continues to require a random persisted revision.
		$auto_default = is_array( $stored ) && array() === $stored;
		if ( $auto_default && $preference_valid ) {
			$value['configuration_revision'] = substr( hash( 'sha256', 'mad4b.browser.auto-preference.v1' ), 0, 32 );
		}
		return array(
			'contract' => 'mad4b.browser-operator-preference.v1',
			'preference_valid' => $preference_valid,
			'executor' => $value['executor'],
			'profile_id' => $value['profile_id'],
			'site_provider_id' => $value['site_provider_id'],
			'configuration_revision' => $value['configuration_revision'],
			'preference_source' => $auto_default && $preference_valid ? 'default_observed' : 'operator_saved_or_legacy',
			'credential_verified' => false,
			'external_runner_connected' => false,
			'site_provider_registered_by_preference' => false,
			'authorizing' => false,
			'read_only' => true,
		);
	}

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		MAD4B_SCP_Admin_Route_Registry::schedule_submenu( array( __CLASS__, 'register_menu' ), 36 );
		add_action( 'admin_post_mad4b_browser_setup_save', array( __CLASS__, 'save' ) );
	}

	public static function register_menu() {
		if ( ! MAD4B_SCP_Admin_Route_Registry::register( self::PAGE_SLUG, 'manage_options' ) ) return;
		add_submenu_page( MAD4B_SCP_Admin_UI::PAGE_SLUG,
			__( 'Browser Acceptance Setup', 'mad4b-site-control-plane' ),
			__( 'Browser Acceptance', 'mad4b-site-control-plane' ),
			'manage_options', self::PAGE_SLUG, array( __CLASS__, 'render_page' ) );
	}

	/**
	 * Form is bound to the revision the operator saw, not just to its nonce.
	 * An invalid legacy preference may be replaced from the blank revision.
	 */
	public static function revision_guard( $expected, $stored ) {
		if ( ! is_string( $expected ) || ( '' !== $expected && 1 !== preg_match( '/^[a-f0-9]{32}$/D', $expected ) ) )
			return new WP_Error( 'mad4b_browser_setup_expected_revision_invalid', 'Reload Browser Acceptance Setup before saving.' );
		$current = self::normalize( is_array( $stored ) ? $stored : array() );
		$actual = is_wp_error( $current ) ? '' : $current['configuration_revision'];
		if ( ! hash_equals( $actual, $expected ) )
			return new WP_Error( 'mad4b_browser_setup_stale_revision', 'Browser settings changed in another tab. Reload before saving.' );
		return true;
	}

	/** Exact option-value compare-and-swap; no silent last-writer-wins. */
	private static function persist_if_unchanged( $stored, array $updated ) {
		if ( false === $stored ) {
			if ( ! add_option( self::OPTION, $updated, '', false ) )
				return new WP_Error( 'mad4b_browser_setup_concurrent_change', 'Browser settings changed during save. Reload first.' );
		} else {
			global $wpdb;
			if ( ! isset( $wpdb->options ) )
				return new WP_Error( 'mad4b_browser_setup_storage_unavailable', 'WordPress options storage unavailable.' );
			// Use BINARY comparison: a case-insensitive SQL collation must
			// never accept a different serialized state as the same revision.
			$changed = $wpdb->query( $wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND BINARY option_value = BINARY %s",
				maybe_serialize( $updated ), self::OPTION, maybe_serialize( $stored )
			) );
			if ( 1 !== (int) $changed )
				return new WP_Error( 'mad4b_browser_setup_concurrent_change', 'Browser settings changed during save. Reload first.' );
			wp_cache_delete( self::OPTION, 'options' );
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'notoptions', 'options' );
		}
		$readback = get_option( self::OPTION, array() );
		if ( ! is_array( $readback ) || $readback !== $updated )
			return new WP_Error( 'mad4b_browser_setup_readback_failed', 'Saved state could not be independently read back; refresh before another action.' );
		return true;
	}

	public static function save() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Administrator capability required.', '', array( 'response' => 403 ) );
		check_admin_referer( 'mad4b_browser_setup_save', 'mad4b_browser_nonce' );
		if ( ! is_array( $_POST ) || array_diff( array_keys( $_POST ), array( 'action', 'mad4b_browser_nonce', '_wp_http_referer', 'executor', 'profile_id', 'site_provider_id', 'expected_configuration_revision', 'submit' ) ) )
			wp_die( 'Unsupported or secret-bearing request fields are forbidden.', '', array( 'response' => 400 ) );
		$stored = get_option( self::OPTION, false );
		$expected = isset( $_POST['expected_configuration_revision'] ) && is_string( $_POST['expected_configuration_revision'] )
			? wp_unslash( $_POST['expected_configuration_revision'] ) : null;
		$guard = self::revision_guard( $expected, $stored );
		if ( is_wp_error( $guard ) ) wp_die( esc_html( $guard->get_error_message() ), '', array( 'response' => 409 ) );
		$value = self::normalize( array(
			'executor' => isset( $_POST['executor'] ) ? wp_unslash( $_POST['executor'] ) : 'auto',
			'profile_id' => isset( $_POST['profile_id'] ) ? wp_unslash( $_POST['profile_id'] ) : '',
			'site_provider_id' => isset( $_POST['site_provider_id'] ) ? wp_unslash( $_POST['site_provider_id'] ) : '',
		) );
		if ( is_wp_error( $value ) ) wp_die( esc_html( $value->get_error_message() ), '', array( 'response' => 400 ) );
		// A fresh unique revision on every save prevents silent A→B→A reuse.
		try { $value['configuration_revision'] = bin2hex( random_bytes( 16 ) ); }
		catch ( Throwable $error ) { wp_die( 'Secure configuration revision generation failed.', '', array( 'response' => 503 ) ); }
		$persisted = self::persist_if_unchanged( $stored, $value );
		if ( is_wp_error( $persisted ) ) wp_die(
			esc_html( $persisted->get_error_message() ), '', array(
				'response' => 'mad4b_browser_setup_concurrent_change' === $persisted->get_error_code() ? 409 : 503,
			)
		);
		$receipt = MAD4B_SCP_Admin_Experience::notice_receipt( self::PAGE_SLUG, 'preference_saved', $value['configuration_revision'] );
		wp_safe_redirect( add_query_arg( 'mad4b_notice_receipt', $receipt, admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ), 303 );
		exit;
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Administrator capability required.', '', array( 'response' => 403 ) );
		$choice = self::selection();
		$operator_status = self::public_selection();
		$observed = class_exists( 'MAD4B_SCP_Browser_Acceptance_Core' ) ? MAD4B_SCP_Browser_Acceptance_Core::capabilities() : array();
		$valid_count = isset( $observed['provider_count'] ) ? (int) $observed['provider_count'] : 0;
		$registered = isset( $observed['registry']['providers'] ) && is_array( $observed['registry']['providers'] ) ? $observed['registry']['providers'] : array();
		$discovery = isset( $observed['site_discovery'] ) && is_array( $observed['site_discovery'] ) ? $observed['site_discovery'] : array();
		if ( class_exists( 'MAD4B_SCP_Admin_Experience' ) ) MAD4B_SCP_Admin_Experience::styles();
		echo '<div class="wrap mad4b-scp-admin-page"><h1>' . esc_html__( 'Browser Acceptance Setup', 'mad4b-site-control-plane' ) . '</h1>';
		echo '<p>' . esc_html__( 'There are TWO independent provider types: a site-specific WordPress acceptance provider that signs plans/reduces evidence; and an external browser execution service. Saving a preference neither registers a WordPress provider nor configures credentials.', 'mad4b-site-control-plane' ) . '</p>';
		if ( empty( $operator_status['configuration_revision'] ) ) echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'A legacy operator preference has no revision. Re-save it before browser execution.', 'mad4b-site-control-plane' ) . '</p></div>';
		if ( 'default_observed' === ( $operator_status['preference_source'] ?? '' ) ) echo '<p>Default provider/profile preference is discovered automatically. Execution still requires a registered semantic provider and external browser credentials.</p>';
		if ( empty( $operator_status['preference_valid'] ) ) echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Stored browser selection is invalid. Browser acceptance and queued browser execution are blocked until this administrator re-saves an approved site provider, profile and executor preference.', 'mad4b-site-control-plane' ) . '</p></div>';
		if ( class_exists( 'MAD4B_SCP_Admin_Experience' ) && MAD4B_SCP_Admin_Experience::notice_verified( self::PAGE_SLUG, 'preference_saved', $choice['configuration_revision'] ) )
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Preference saved; execution and browser acceptance remain separately unverified.', 'mad4b-site-control-plane' ) . '</p></div>';
		echo '<h2>' . esc_html__( 'Site capability discovery (read-only)', 'mad4b-site-control-plane' ) . '</h2>';
		$complete = ! empty( $discovery['discovery_complete'] );
		$plugins = isset( $discovery['plugins'] ) && is_array( $discovery['plugins'] ) ? $discovery['plugins'] : array();
		$unmapped = isset( $discovery['unmapped_plugins'] ) && is_array( $discovery['unmapped_plugins'] ) ? $discovery['unmapped_plugins'] : array();
		$matches = isset( $discovery['provider_matches'] ) && is_array( $discovery['provider_matches'] ) ? $discovery['provider_matches'] : array();
		echo '<p><strong>' . esc_html( $complete ? 'Observed' : 'Incomplete / blocked' ) . '</strong> | ' . esc_html( count( $plugins ) ) . ' active plugin identities | ' . esc_html( count( $unmapped ) ) . ' unmapped | ' . esc_html( count( $matches ) ) . ' declarative provider matches. Discovery does not certify any test.</p>';
		if ( ! $complete ) {
			$reasons = isset( $discovery['blocking_reasons'] ) && is_array( $discovery['blocking_reasons'] ) ? implode( ', ', array_slice( $discovery['blocking_reasons'], 0, 12 ) ) : 'discovery_unavailable';
			echo '<div class="notice notice-warning inline"><p>' . esc_html( $reasons ) . '</p></div>';
		}
		if ( $unmapped ) {
			echo '<p><strong>Unmapped plugins — no inferred browser execution authority:</strong> ';
			foreach ( array_slice( $unmapped, 0, 128 ) as $slug ) echo '<code>' . esc_html( (string) $slug ) . '</code> ';
			echo '</p>';
		}
		if ( ! empty( $discovery['snapshot_sha256'] ) ) echo '<p>Snapshot: <code>' . esc_html( (string) $discovery['snapshot_sha256'] ) . '</code></p>';
		// Shared capability layer across all plugin families and registered browser
		// Providers. This is an inventory view, never a provider approval.
		if ( class_exists( 'MAD4B_SCP_Capability_Atlas' ) && class_exists( 'MAD4B_SCP_Plugin_Discovery' ) ) {
			$family_coverage = MAD4B_SCP_Plugin_Discovery::functional_coverage_report();
			$atlas = MAD4B_SCP_Capability_Atlas::compose( $observed, $family_coverage );
			$items = isset( $atlas['capabilities'] ) && is_array( $atlas['capabilities'] ) ? $atlas['capabilities'] : array();
			$shared = 0;
			foreach ( $items as $unit ) if ( ! empty( $unit['candidate_ambiguous'] ) ) ++$shared;
			echo '<h2>Cross-provider capability atlas (unverified)</h2>';
			echo '<p><strong>' . esc_html( (string) count( $items ) ) . '</strong> capability/family nodes; '
				. esc_html( (string) $shared ) . ' shared by multiple candidates. Inventory grants no execution or certification.</p>';
			if ( empty( $atlas['complete'] ) ) echo '<div class="notice notice-warning inline"><p>'
				. esc_html( implode( ', ', array_slice( (array) ( $atlas['blocking_reasons'] ?? array( 'inventory_incomplete' ) ), 0, 10 ) ) )
				. '</p></div>';
		}
		echo '<h2>' . esc_html__( '1. WordPress test provider registry', 'mad4b-site-control-plane' ) . '</h2>';
		echo '<p><strong>' . esc_html( (string) $valid_count ) . '</strong> ' . esc_html__( 'valid site-specific providers. Installing a reviewed provider adapter is required for signed browser acceptance plans.', 'mad4b-site-control-plane' ) . '</p>';
		if ( 0 === $valid_count ) echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'No WordPress Browser Acceptance provider is registered. Tests cannot run yet; entering external API keys cannot resolve this missing adapter.', 'mad4b-site-control-plane' ) . '</p></div>';
		if ( $registered ) {
			echo '<table class="widefat striped"><thead><tr><th>Provider</th><th>Contract</th><th>State</th></tr></thead><tbody>';
			foreach ( array_slice( $registered, 0, 32 ) as $item ) {
				$reasons = isset( $item['blocking_reasons'] ) && is_array( $item['blocking_reasons'] ) ? implode( ', ', $item['blocking_reasons'] ) : '';
				echo '<tr><td><code>' . esc_html( isset( $item['provider_id'] ) ? (string) $item['provider_id'] : '' ) . '</code></td><td>' . esc_html( isset( $item['contract'] ) ? (string) $item['contract'] : '' ) . '</td><td>' . esc_html( ! empty( $item['valid'] ) ? 'Registered; browser evidence pending' : 'Blocked: ' . $reasons ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '<h2>' . esc_html__( '2. Select site test provider and external browser runner', 'mad4b-site-control-plane' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="mad4b_browser_setup_save">';
		wp_nonce_field( 'mad4b_browser_setup_save', 'mad4b_browser_nonce' );
		echo '<input type="hidden" name="expected_configuration_revision" value="' . esc_attr( $choice['configuration_revision'] ) . '">';
		echo '<table class="form-table"><tbody><tr><th><label for="mad4b-site-provider">Site acceptance provider</label></th><td><select id="mad4b-site-provider" name="site_provider_id">';
		echo '<option value="">Auto-select only one discovered, supported site adapter</option>';
		foreach ( array_slice( $registered, 0, 32 ) as $site_provider ) {
			if ( empty( $site_provider['valid'] ) || empty( $site_provider['provider_id'] ) ) continue;
			$id = (string) $site_provider['provider_id'];
			echo '<option value="' . esc_attr( $id ) . '" ' . selected( $choice['site_provider_id'], $id, false ) . '>' . esc_html( $id ) . '</option>';
		}
		echo '</select><p class="description">Only installed and valid providers can produce signed test plans; choosing a name never registers one.</p></td></tr><tr><th><label for="mad4b-browser-executor">' . esc_html__( 'Managed browser service', 'mad4b-site-control-plane' ) . '</label></th><td><select id="mad4b-browser-executor" name="executor">';
		$options = array( 'auto' => 'Auto (external scheduler)' );
		foreach ( self::managed_executors() as $id => $details ) $options[ $id ] = $details[0];
		foreach ( $options as $id => $label ) echo '<option value="' . esc_attr( $id ) . '" ' . selected( $choice['executor'], $id, false ) . '>' . esc_html( $label ) . '</option>';
		echo '</select><p class="description">' . esc_html__( 'Operator preference only; the external browser scheduler must still be set up independently.', 'mad4b-site-control-plane' ) . '</p></td></tr>';
		echo '<tr><th><label for="mad4b-browser-profile">' . esc_html__( 'Acceptance profile ID', 'mad4b-site-control-plane' ) . '</label></th><td>';
		echo '<input class="regular-text" id="mad4b-browser-profile" name="profile_id" maxlength="64" pattern="[a-z0-9][a-z0-9._-]{0,63}" value="' . esc_attr( $choice['profile_id'] ) . '" autocomplete="off">';
		echo '<p class="description">' . esc_html__( 'Optional bounded ID supplied by the site-specific acceptance provider. Not a URL, password or script.', 'mad4b-site-control-plane' ) . '</p></td></tr></tbody></table>';
		submit_button( __( 'Save operator preference', 'mad4b-site-control-plane' ) );
		echo '</form>';
		echo '<h2>' . esc_html__( '3. Provision credentials OUTSIDE WordPress', 'mad4b-site-control-plane' ) . '</h2>';
		echo '<p>' . esc_html__( 'The external browser runner or GitHub Actions secrets must provide these variable names. Credentials are not accepted/stored here, and provider connectivity cannot be certified from this page.', 'mad4b-site-control-plane' ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th>External runner</th><th>Required secret/environment names</th><th>Status</th></tr></thead><tbody>';
		foreach ( self::managed_executors() as $details ) {
			echo '<tr><td>' . esc_html( $details[0] ) . '</td><td>';
			foreach ( $details[1] as $env ) echo '<code>' . esc_html( $env ) . '</code> ';
			echo '</td><td>Not verified by WordPress</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p><a href="https://github.com/mad4bdigital-ai/WordPress/blob/c1e4d4665770a453bf9fa4b1b7f22d99e0c1104d/docs/MAD4B-MANAGED-BROWSER-PROVIDERS.md" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Provider setup guide and external execution contract', 'mad4b-site-control-plane' ) . '</a></p>';
		echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Release gate stays BLOCKED until a valid WordPress adapter provides a signed plan, the external runner performs real browser work, and independent evidence is verified. This setup form does not authorize tests or production writes.', 'mad4b-site-control-plane' ) . '</p></div></div>';
	}
}
