<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Tenant-neutral site identity and authority profile.
 *
 * The profile is deliberately local to one WordPress site. Installing the
 * Control Plane does not enroll the site and does not grant mutation authority.
 * A privileged operator must explicitly bind the current canonical origin and
 * environment before generic-site authority may be enabled.
 */
final class MAD4B_SCP_Site_Profile {
	const CONTRACT = 'mad4b.site-profile.v1';
	const OPTION = 'mad4b_scp_site_profile_v1';
	const PAGE_SLUG = 'mad4b-site-profile';
	const SAVE_ACTION = 'mad4b_site_profile_save';
	const VERSION = 1;
	const PRODUCTION_WRITE_CONFIRMATION = 'ENABLE GOVERNED PRODUCTION WRITE';

	private static $booted = false;

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ), 85 );
		add_action( 'admin_post_' . self::SAVE_ACTION, array( __CLASS__, 'handle_save' ) );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_status_ability' ), 28 );
	}

	public static function register_page() {
		add_submenu_page(
			'mad4b-control-plane',
			__( 'MAD4B Site Profile', 'mad4b-site-control-plane' ),
			__( 'Site Profile', 'mad4b-site-control-plane' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function register_status_ability() {
		if ( ! function_exists( 'wp_register_ability' ) ) return;
		wp_register_ability( 'mad4b/site-profile-status', array(
			'label' => 'MAD4B Site Profile Status',
			'description' => 'Read the tenant-neutral site enrollment, origin, environment and authority policy state.',
			'category' => 'mad4b-admin',
			'execute_callback' => array( __CLASS__, 'status' ),
			'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
			'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
			'meta' => array(
				'public' => false,
				'show_in_rest' => false,
				'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ),
				'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
			),
		) );
	}

	public static function load() {
		$profile = get_option( self::OPTION, array() );
		return self::normalize_profile( is_array( $profile ) ? $profile : array() );
	}

	public static function enrolled() {
		$profile = self::load();
		return ! empty( $profile['site_uuid'] ) && ! empty( $profile['canonical_origin'] ) && ! empty( $profile['environment'] );
	}

	public static function site_uuid() {
		$profile = self::load();
		return isset( $profile['site_uuid'] ) ? (string) $profile['site_uuid'] : '';
	}

	public static function revision() {
		$profile = self::load();
		return isset( $profile['revision'] ) ? absint( $profile['revision'] ) : 0;
	}

	public static function openai_app_id() {
		$profile = self::load();
		return isset( $profile['openai_app_id'] ) ? (string) $profile['openai_app_id'] : '';
	}

	public static function subject_user_id() {
		$profile = self::load();
		return isset( $profile['subject_user_id'] ) ? absint( $profile['subject_user_id'] ) : 0;
	}

	public static function profile_digest( $profile = null ) {
		if ( null === $profile ) $profile = self::load();
		$profile = self::normalize_profile( is_array( $profile ) ? $profile : array() );
		$identity = array(
			'contract' => self::CONTRACT,
			'site_uuid' => (string) $profile['site_uuid'],
			'revision' => (int) $profile['revision'],
			'canonical_origin' => (string) $profile['canonical_origin'],
			'environment' => (string) $profile['environment'],
			'subject_user_id' => (int) $profile['subject_user_id'],
			'openai_app_id' => (string) $profile['openai_app_id'],
			'write_enabled' => (bool) $profile['write_enabled'],
			'production_write_confirmed' => (bool) $profile['production_write_confirmed'],
			'breakglass_enabled' => (bool) $profile['breakglass_enabled'],
		);
		return hash( 'sha256', self::canonical_json( $identity ) );
	}

	public static function current_environment() {
		return function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown';
	}

	public static function current_origin() {
		return self::canonicalize_origin( function_exists( 'home_url' ) ? home_url( '/' ) : '', self::current_environment() );
	}

	public static function status() {
		$profile = self::load();
		$enrolled = self::enrolled();
		$observed_environment = self::current_environment();
		$observed_origin = self::current_origin();
		$origin_match = $enrolled && is_string( $observed_origin ) && '' !== $observed_origin && hash_equals( (string) $profile['canonical_origin'], $observed_origin );
		$environment_match = $enrolled && hash_equals( (string) $profile['environment'], (string) $observed_environment );
		$subject_valid = $enrolled ? self::subject_user_is_valid( (int) $profile['subject_user_id'] ) : false;
		$blockers = array();
		if ( ! $enrolled ) $blockers[] = 'site_not_enrolled';
		if ( $enrolled && ! $origin_match ) $blockers[] = 'origin_drift';
		if ( $enrolled && ! $environment_match ) $blockers[] = 'environment_drift';
		if ( $enrolled && ! $subject_valid ) $blockers[] = 'subject_user_invalid';
		if ( $enrolled && ! empty( $profile['write_enabled'] ) && 'production' === $profile['environment'] && empty( $profile['production_write_confirmed'] ) ) $blockers[] = 'production_write_confirmation_missing';

		$ready = $enrolled && $origin_match && $environment_match && $subject_valid;
		$write_allowed = $ready && ! empty( $profile['write_enabled'] ) && ( 'production' !== $profile['environment'] || ! empty( $profile['production_write_confirmed'] ) );
		$breakglass_allowed = $write_allowed && ! empty( $profile['breakglass_enabled'] );

		return array(
			'contract' => self::CONTRACT,
			'version' => self::VERSION,
			'enrolled' => $enrolled,
			'ready' => $ready,
			'site_uuid' => (string) $profile['site_uuid'],
			'profile_revision' => (int) $profile['revision'],
			'profile_digest' => self::profile_digest( $profile ),
			'canonical_origin' => (string) $profile['canonical_origin'],
			'observed_origin' => is_string( $observed_origin ) ? $observed_origin : '',
			'origin_match' => $origin_match,
			'environment' => (string) $profile['environment'],
			'observed_environment' => $observed_environment,
			'environment_match' => $environment_match,
			'subject_user_id' => (int) $profile['subject_user_id'],
			'subject_user_valid' => $subject_valid,
			'openai_app_id_configured' => '' !== (string) $profile['openai_app_id'],
			'write_enabled' => (bool) $profile['write_enabled'],
			'production_write_confirmed' => (bool) $profile['production_write_confirmed'],
			'write_allowed' => $write_allowed,
			'breakglass_enabled' => (bool) $profile['breakglass_enabled'],
			'breakglass_allowed' => $breakglass_allowed,
			'blockers' => array_values( array_unique( $blockers ) ),
		);
	}

	public static function mutation_allowed() {
		$status = self::status();
		return ! empty( $status['write_allowed'] );
	}

	public static function breakglass_allowed() {
		$status = self::status();
		return ! empty( $status['breakglass_allowed'] );
	}

	public static function save_profile( array $input ) {
		if ( ! current_user_can( 'manage_options' ) ) return new WP_Error( 'mad4b_site_profile_admin_required', 'Administrator capability is required to enroll or change a site profile.' );
		$existing = self::load();
		$expected_revision = isset( $input['expected_revision'] ) ? absint( $input['expected_revision'] ) : 0;
		if ( (int) $existing['revision'] !== $expected_revision ) return new WP_Error( 'mad4b_site_profile_revision_conflict', 'Site profile changed since this form was loaded. Refresh before retrying.' );

		$environment = isset( $input['environment'] ) ? sanitize_key( (string) $input['environment'] ) : self::current_environment();
		$observed_environment = self::current_environment();
		if ( '' === $environment || ! hash_equals( $observed_environment, $environment ) ) return new WP_Error( 'mad4b_site_profile_environment_mismatch', 'Enrollment environment must match the current WordPress environment exactly.' );

		$origin_input = isset( $input['canonical_origin'] ) ? (string) $input['canonical_origin'] : '';
		$canonical_origin = self::canonicalize_origin( $origin_input, $environment );
		if ( is_wp_error( $canonical_origin ) ) return $canonical_origin;
		$observed_origin = self::current_origin();
		if ( is_wp_error( $observed_origin ) || ! hash_equals( (string) $observed_origin, (string) $canonical_origin ) ) return new WP_Error( 'mad4b_site_profile_origin_mismatch', 'Enrollment origin must match the current canonical WordPress home origin exactly.' );

		$subject_user_id = isset( $input['subject_user_id'] ) ? absint( $input['subject_user_id'] ) : get_current_user_id();
		if ( ! self::subject_user_is_valid( $subject_user_id ) ) return new WP_Error( 'mad4b_site_profile_subject_invalid', 'Selected WordPress subject does not satisfy the configured MAD4B connection capability.' );

		$app_id = isset( $input['openai_app_id'] ) ? trim( (string) $input['openai_app_id'] ) : '';
		if ( '' !== $app_id && 1 !== preg_match( '/^plugin_asdk_app_[A-Za-z0-9]+$/', $app_id ) ) return new WP_Error( 'mad4b_site_profile_app_id_invalid', 'OpenAI Plugin App ID is invalid.' );

		$write_enabled = ! empty( $input['write_enabled'] );
		$production_write_confirmed = false;
		if ( $write_enabled && 'production' === $environment ) {
			$confirmation = isset( $input['production_write_confirmation'] ) ? trim( (string) $input['production_write_confirmation'] ) : '';
			if ( ! hash_equals( self::PRODUCTION_WRITE_CONFIRMATION, $confirmation ) ) return new WP_Error( 'mad4b_site_profile_production_confirmation_required', 'Production governed write requires the exact confirmation phrase.' );
			$production_write_confirmed = true;
		}

		$site_uuid = ! empty( $existing['site_uuid'] ) ? (string) $existing['site_uuid'] : wp_generate_uuid4();
		$profile = array(
			'contract' => self::CONTRACT,
			'version' => self::VERSION,
			'site_uuid' => $site_uuid,
			'revision' => (int) $existing['revision'] + 1,
			'canonical_origin' => $canonical_origin,
			'environment' => $environment,
			'subject_user_id' => $subject_user_id,
			'openai_app_id' => $app_id,
			'write_enabled' => $write_enabled,
			'production_write_confirmed' => $production_write_confirmed,
			'breakglass_enabled' => false,
			'updated_at' => gmdate( 'c' ),
			'updated_by' => get_current_user_id(),
		);
		$profile['profile_digest'] = self::profile_digest( $profile );
		$ok = update_option( self::OPTION, $profile, false );
		if ( false === $ok && self::normalize_profile( get_option( self::OPTION, array() ) ) !== self::normalize_profile( $profile ) ) return new WP_Error( 'mad4b_site_profile_persist_failed', 'Site profile could not be persisted.' );
		do_action( 'mad4b_scp_site_profile_changed', self::normalize_profile( $existing ), self::normalize_profile( $profile ) );
		return self::normalize_profile( $profile );
	}

	public static function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'Administrator capability is required to change the MAD4B Site Profile.', 'mad4b-site-control-plane' ), '', array( 'response' => 403 ) );
		check_admin_referer( self::SAVE_ACTION );
		$input = array(
			'expected_revision' => isset( $_POST['expected_revision'] ) ? absint( $_POST['expected_revision'] ) : 0,
			'canonical_origin' => isset( $_POST['canonical_origin'] ) ? esc_url_raw( wp_unslash( $_POST['canonical_origin'] ) ) : '',
			'environment' => isset( $_POST['environment'] ) ? sanitize_key( wp_unslash( $_POST['environment'] ) ) : '',
			'subject_user_id' => isset( $_POST['subject_user_id'] ) ? absint( $_POST['subject_user_id'] ) : 0,
			'openai_app_id' => isset( $_POST['openai_app_id'] ) ? sanitize_text_field( wp_unslash( $_POST['openai_app_id'] ) ) : '',
			'write_enabled' => ! empty( $_POST['write_enabled'] ),
			'production_write_confirmation' => isset( $_POST['production_write_confirmation'] ) ? sanitize_text_field( wp_unslash( $_POST['production_write_confirmation'] ) ) : '',
		);
		$result = self::save_profile( $input );
		$state = is_wp_error( $result ) ? $result->get_error_code() : 'saved';
		$url = add_query_arg( array( 'page' => self::PAGE_SLUG, 'mad4b_site_profile' => sanitize_key( $state ) ), admin_url( 'admin.php' ) );
		wp_safe_redirect( $url );
		exit;
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) return;
		$profile = self::load();
		$status = self::status();
		$current_origin = self::current_origin();
		if ( is_wp_error( $current_origin ) ) $current_origin = '';
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'MAD4B Site Profile', 'mad4b-site-control-plane' ); ?></h1>
			<p><?php echo esc_html__( 'Enrollment binds this WordPress site to its exact canonical origin and environment. Installation alone grants no generic-site mutation authority.', 'mad4b-site-control-plane' ); ?></p>
			<p><strong><?php echo esc_html__( 'Status:', 'mad4b-site-control-plane' ); ?></strong> <?php echo esc_html( ! empty( $status['ready'] ) ? 'ready' : 'not ready' ); ?></p>
			<?php if ( ! empty( $status['blockers'] ) ) : ?><p><strong><?php echo esc_html__( 'Blockers:', 'mad4b-site-control-plane' ); ?></strong> <?php echo esc_html( implode( ', ', $status['blockers'] ) ); ?></p><?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( self::SAVE_ACTION ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE_ACTION ); ?>" />
				<input type="hidden" name="expected_revision" value="<?php echo esc_attr( (string) $profile['revision'] ); ?>" />
				<table class="form-table" role="presentation">
					<tr><th scope="row"><label for="mad4b-canonical-origin"><?php echo esc_html__( 'Canonical origin', 'mad4b-site-control-plane' ); ?></label></th><td><input class="regular-text code" id="mad4b-canonical-origin" name="canonical_origin" value="<?php echo esc_attr( $profile['canonical_origin'] ? $profile['canonical_origin'] : $current_origin ); ?>" /></td></tr>
					<tr><th scope="row"><label for="mad4b-environment"><?php echo esc_html__( 'Environment', 'mad4b-site-control-plane' ); ?></label></th><td><input class="regular-text" id="mad4b-environment" name="environment" value="<?php echo esc_attr( $profile['environment'] ? $profile['environment'] : self::current_environment() ); ?>" /></td></tr>
					<tr><th scope="row"><label for="mad4b-subject-user"><?php echo esc_html__( 'WordPress subject user ID', 'mad4b-site-control-plane' ); ?></label></th><td><input type="number" min="1" id="mad4b-subject-user" name="subject_user_id" value="<?php echo esc_attr( (string) ( $profile['subject_user_id'] ? $profile['subject_user_id'] : get_current_user_id() ) ); ?>" /></td></tr>
					<tr><th scope="row"><label for="mad4b-openai-app-id"><?php echo esc_html__( 'OpenAI Plugin App ID', 'mad4b-site-control-plane' ); ?></label></th><td><input class="regular-text code" id="mad4b-openai-app-id" name="openai_app_id" value="<?php echo esc_attr( $profile['openai_app_id'] ); ?>" /></td></tr>
					<tr><th scope="row"><?php echo esc_html__( 'Governed write', 'mad4b-site-control-plane' ); ?></th><td><label><input type="checkbox" name="write_enabled" value="1" <?php checked( ! empty( $profile['write_enabled'] ) ); ?> /> <?php echo esc_html__( 'Enable site-level governed write policy after all existing NHI, grant, provider, budget and approval gates.', 'mad4b-site-control-plane' ); ?></label></td></tr>
					<?php if ( 'production' === ( $profile['environment'] ? $profile['environment'] : self::current_environment() ) ) : ?>
					<tr><th scope="row"><label for="mad4b-production-write-confirmation"><?php echo esc_html__( 'Production confirmation', 'mad4b-site-control-plane' ); ?></label></th><td><input class="regular-text code" id="mad4b-production-write-confirmation" name="production_write_confirmation" value="" autocomplete="off" /><p class="description"><?php echo esc_html( self::PRODUCTION_WRITE_CONFIRMATION ); ?></p></td></tr>
					<?php endif; ?>
				</table>
				<?php submit_button( __( 'Save Site Profile', 'mad4b-site-control-plane' ) ); ?>
			</form>
		</div>
		<?php
	}

	public static function canonicalize_origin( $url, $environment = '' ) {
		$url = trim( (string) $url );
		if ( '' === $url ) return new WP_Error( 'mad4b_site_profile_origin_missing', 'Canonical origin is required.' );
		$parts = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url ) : parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) return new WP_Error( 'mad4b_site_profile_origin_invalid', 'Canonical origin must be an absolute URL.' );
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) return new WP_Error( 'mad4b_site_profile_origin_ambiguous', 'Canonical origin cannot contain credentials, query parameters or fragments.' );
		$scheme = strtolower( (string) $parts['scheme'] );
		$environment = sanitize_key( (string) $environment );
		if ( 'https' !== $scheme && ! in_array( $environment, array( 'local', 'development' ), true ) ) return new WP_Error( 'mad4b_site_profile_https_required', 'HTTPS is required outside local/development environments.' );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) return new WP_Error( 'mad4b_site_profile_origin_scheme_invalid', 'Only HTTP(S) origins are supported.' );
		$host = strtolower( rtrim( (string) $parts['host'], '.' ) );
		if ( '' === $host || 1 !== preg_match( '/^[a-z0-9.-]+$/', $host ) ) return new WP_Error( 'mad4b_site_profile_origin_host_invalid', 'Canonical origin host is invalid.' );
		$port = isset( $parts['port'] ) ? ':' . absint( $parts['port'] ) : '';
		$path = isset( $parts['path'] ) ? '/' . ltrim( (string) $parts['path'], '/' ) : '';
		$path = rtrim( $path, '/' );
		return $scheme . '://' . $host . $port . $path;
	}

	public static function transition_for_test( array $existing, array $input, $observed_origin, $observed_environment, $is_admin ) {
		$existing = self::normalize_profile( $existing );
		if ( ! $is_admin ) return array( 'ok' => false, 'error' => 'mad4b_site_profile_admin_required' );
		$expected_revision = isset( $input['expected_revision'] ) ? (int) $input['expected_revision'] : 0;
		if ( (int) $existing['revision'] !== $expected_revision ) return array( 'ok' => false, 'error' => 'mad4b_site_profile_revision_conflict' );
		$environment = isset( $input['environment'] ) ? sanitize_key( (string) $input['environment'] ) : '';
		if ( ! hash_equals( sanitize_key( (string) $observed_environment ), $environment ) ) return array( 'ok' => false, 'error' => 'mad4b_site_profile_environment_mismatch' );
		$canonical = self::canonicalize_origin( isset( $input['canonical_origin'] ) ? $input['canonical_origin'] : '', $environment );
		if ( is_wp_error( $canonical ) ) return array( 'ok' => false, 'error' => $canonical->get_error_code() );
		$observed = self::canonicalize_origin( $observed_origin, $environment );
		if ( is_wp_error( $observed ) || ! hash_equals( (string) $observed, (string) $canonical ) ) return array( 'ok' => false, 'error' => 'mad4b_site_profile_origin_mismatch' );
		$write_enabled = ! empty( $input['write_enabled'] );
		if ( $write_enabled && 'production' === $environment ) {
			$confirmation = isset( $input['production_write_confirmation'] ) ? (string) $input['production_write_confirmation'] : '';
			if ( ! hash_equals( self::PRODUCTION_WRITE_CONFIRMATION, $confirmation ) ) return array( 'ok' => false, 'error' => 'mad4b_site_profile_production_confirmation_required' );
		}
		return array( 'ok' => true, 'canonical_origin' => $canonical, 'environment' => $environment, 'write_enabled' => $write_enabled );
	}

	private static function subject_user_is_valid( $user_id ) {
		$user_id = absint( $user_id );
		if ( $user_id < 1 ) return false;
		$user = get_userdata( $user_id );
		if ( ! $user ) return false;
		$capability = apply_filters( 'mad4b_scp_connection_subject_capability', 'manage_options', $user_id );
		return is_string( $capability ) && '' !== $capability && user_can( $user, $capability );
	}

	private static function normalize_profile( array $profile ) {
		return array(
			'contract' => isset( $profile['contract'] ) ? (string) $profile['contract'] : self::CONTRACT,
			'version' => isset( $profile['version'] ) ? absint( $profile['version'] ) : self::VERSION,
			'site_uuid' => isset( $profile['site_uuid'] ) ? strtolower( trim( (string) $profile['site_uuid'] ) ) : '',
			'revision' => isset( $profile['revision'] ) ? absint( $profile['revision'] ) : 0,
			'canonical_origin' => isset( $profile['canonical_origin'] ) ? rtrim( trim( (string) $profile['canonical_origin'] ), '/' ) : '',
			'environment' => isset( $profile['environment'] ) ? sanitize_key( (string) $profile['environment'] ) : '',
			'subject_user_id' => isset( $profile['subject_user_id'] ) ? absint( $profile['subject_user_id'] ) : 0,
			'openai_app_id' => isset( $profile['openai_app_id'] ) ? trim( (string) $profile['openai_app_id'] ) : '',
			'write_enabled' => ! empty( $profile['write_enabled'] ),
			'production_write_confirmed' => ! empty( $profile['production_write_confirmed'] ),
			'breakglass_enabled' => ! empty( $profile['breakglass_enabled'] ),
			'profile_digest' => isset( $profile['profile_digest'] ) ? strtolower( trim( (string) $profile['profile_digest'] ) ) : '',
			'updated_at' => isset( $profile['updated_at'] ) ? (string) $profile['updated_at'] : '',
			'updated_by' => isset( $profile['updated_by'] ) ? absint( $profile['updated_by'] ) : 0,
		);
	}

	private static function canonical_json( array $value ) {
		ksort( $value );
		return function_exists( 'wp_json_encode' ) ? wp_json_encode( $value, JSON_UNESCAPED_SLASHES ) : json_encode( $value, JSON_UNESCAPED_SLASHES );
	}
}
