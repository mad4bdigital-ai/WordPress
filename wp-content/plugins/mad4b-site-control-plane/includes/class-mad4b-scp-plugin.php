<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_Plugin {
	private static $booted = false;
	private static $catalog_registration_wired = false;
	private static $schema_error = null;

	public static function activate() {
		MAD4B_SCP_Staging_OAuth_Autoconfig::bootstrap();
		MAD4B_SCP_Skill_Autoconfig::bootstrap();
		MAD4B_SCP_Staging_Write_Authority::bootstrap();
		$schema = MAD4B_SCP_Schema::install_or_upgrade();
		if ( is_wp_error( $schema ) ) self::$schema_error = $schema;
		update_option( 'mad4b_scp_version', MAD4B_SCP_VERSION, false );
		if ( false === get_option( MAD4B_SCP_Audit::LEGACY_OPTION, false ) ) add_option( MAD4B_SCP_Audit::LEGACY_OPTION, array(), '', false );
		if ( ! is_wp_error( self::$schema_error ) ) {
			$audit = MAD4B_SCP_Audit::ensure_head_initialized();
			if ( is_wp_error( $audit ) ) self::$schema_error = $audit;
		}
		if ( ! is_wp_error( self::$schema_error ) && class_exists( 'MAD4B_SCP_Schema_Lifecycle' ) ) {
			MAD4B_SCP_Schema_Lifecycle::mark_current_package_applied( 'activation' );
		}
		if ( ! is_wp_error( self::$schema_error ) ) MAD4B_SCP_Skill_Seeder::bootstrap();
		// Activation is the start of the safe autopilot lifecycle. It schedules
		// bounded post-activation convergence after schema/audit bootstrap instead
		// of waiting for a later request to rediscover version drift. Production
		// remains observe-only inside Runtime_Convergence.
		if ( ! is_wp_error( self::$schema_error ) && class_exists( 'MAD4B_SCP_Runtime_Convergence' ) ) {
			MAD4B_SCP_Runtime_Convergence::mark_activation_pending();
		}
	}

	/**
	 * Bind the canonical Ability/catalog registration graph without starting any
	 * reconciliation, provider discovery, persistence, telemetry or mutation.
	 *
	 * A signed endpoint diagnostic is deliberately classified as passive until
	 * its worker verifies capability + nonce + build + MU proof. The MU-bound
	 * server id is nevertheless trusted as routing evidence, so the exact catalog
	 * definitions must be wired before WordPress lazily fires
	 * wp_abilities_api_init. Keeping this graph separate from the heavy lifecycle
	 * prevents diagnostic catalog parity from becoming lifecycle authority.
	 */
	public static function bind_catalog_registration_wiring() {
		if ( self::$catalog_registration_wired ) return;
		self::$catalog_registration_wired = true;

		$boots = array(
			array( 'MAD4B_SCP_Staging_Certification', 'boot' ),
			array( 'MAD4B_SCP_Acceptance_Core', 'boot_early' ),
			array( 'MAD4B_SCP_Connection_Ability', 'boot' ),
			array( 'MAD4B_SCP_Read_Consistency', 'boot' ),
			array( 'MAD4B_SCP_Multi_Authority_Registry', 'boot' ),
			array( 'MAD4B_SCP_Context_Authority', 'boot' ),
			array( 'MAD4B_SCP_AI_Approval', 'boot' ),
			array( 'MAD4B_SCP_Provider_Transport_Registry', 'boot' ),
			array( 'MAD4B_SCP_Dependency_Impact_Graph', 'boot' ),
			array( 'MAD4B_SCP_Operation_Registry', 'boot' ),
			array( 'MAD4B_SCP_Operation_Pipeline', 'boot' ),
			array( 'MAD4B_SCP_Provider_Autopilot', 'boot' ),
			array( 'MAD4B_SCP_Plugin_Transaction', 'boot' ),
			array( 'MAD4B_SCP_Operation_Resume', 'boot' ),
			array( 'MAD4B_SCP_Plugin_Lifecycle', 'boot' ),
			array( 'MAD4B_SCP_Plugin_Package', 'boot' ),
			array( 'MAD4B_SCP_Remote_Plugin_Update', 'boot' ),
			array( 'MAD4B_SCP_Self_Update', 'boot' ),
			array( 'MAD4B_SCP_Functional_Gap_Runtime_Diagnostic', 'boot' ),
			array( 'MAD4B_SCP_Code_Snippets_Runtime_Diagnostic', 'boot' ),
			array( 'MAD4B_SCP_Workflow_Providers', 'boot' ),
			array( 'MAD4B_SCP_Addon_Registry', 'boot' ),
			array( 'MAD4B_SCP_Operating_Model', 'boot' ),
			array( 'MAD4B_SCP_Governed_Ability_Overrides', 'boot' ),
			array( 'MAD4B_SCP_Staging_Write_Grant_Reconciliation_Plan', 'boot' ),
			array( 'MAD4B_SCP_Staging_Write_Grant_Reconciliation', 'boot' ),
			array( 'MAD4B_SCP_Staging_Write_Candidate_Binding', 'boot' ),
			array( 'MAD4B_SCP_Staging_Write_Planning_Guard', 'boot' ),
			array( 'MAD4B_SCP_Governance_Abilities', 'boot' ),
			array( 'MAD4B_SCP_Skill_Abilities', 'boot' ),
			array( 'MAD4B_SCP_Skills_Adapter', 'boot' ),
			array( 'MAD4B_SCP_MCP_Adapter_Metadata_Bridge', 'bootstrap' ),
		);
		foreach ( $boots as $boot ) {
			if ( class_exists( $boot[0], false ) && is_callable( $boot ) ) call_user_func( $boot );
		}


		$registrars = array(
			array( 35, 'MAD4B_SCP_Staging_Write_Authority', 'register_status_ability' ),
			array( 36, 'MAD4B_SCP_REST_Compatibility', 'register_ability' ),
			array( 37, 'MAD4B_SCP_Write_Runtime_Certification', 'register_ability' ),
		);
		foreach ( $registrars as $registrar ) {
			$callback = array( $registrar[1], $registrar[2] );
			if ( class_exists( $registrar[1], false ) && is_callable( $callback )
				&& false === has_action( 'wp_abilities_api_init', $callback ) ) {
				add_action( 'wp_abilities_api_init', $callback, $registrar[0] );
			}
		}
	}

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;

		// Admin navigation is presentation-only and must stay visible on every
		// wp-admin screen, including zero-touch third-party/plugin pages. Register
		// menu hooks before the heavy-runtime zero-touch gate; render callbacks stay
		// lazy and no authority/provider reconciliation is performed here.
		if ( is_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) ) self::boot_admin_navigation();

		// Tier 0 connection identity is always projected before any zero-touch
		// decision. This is request-local/read-only: no provider discovery, schema
		// repair, key generation, persistence or outbound I/O is allowed here.
		if ( class_exists( 'MAD4B_SCP_Connection_Identity_Resolver', false ) ) {
			MAD4B_SCP_Connection_Identity_Resolver::project_runtime_identity();
		}

		// Unrelated Core/provider REST and generic wp-cron.php are infrastructure
		// hotpaths, not Control Plane operator lifecycles. Their owning scheduled
		// hooks are registered before init; skip admin, authority, OAuth and
		// Abilities bootstrap here so Site Health loopbacks remain bounded.
		if ( class_exists( 'MAD4B_SCP_Provider_Diagnostic_Policy', false )
			&& MAD4B_SCP_Provider_Diagnostic_Policy::current_request_is_zero_touch_surface() ) return;

		// REST recovery scope is request-local and must be classified before the
		// protocol/passive fast return below. On MAD4B MCP routes this records the
		// scoped request without removing callbacks; on unrelated admin reads it
		// disarms only MAD4B recovery callbacks for the current request.
		MAD4B_SCP_REST_Compatibility::boot();

		MAD4B_SCP_Staging_OAuth_Autoconfig::bootstrap();
		MAD4B_SCP_Skill_Autoconfig::bootstrap();
		MAD4B_SCP_Staging_Write_Authority::bootstrap();
		MAD4B_SCP_MCP_Provider_Isolation::boot();
		self::bind_local_oauth_subject_compatibility();
		MAD4B_SCP_Local_OAuth_Key_Path_Policy::boot();
		MAD4B_SCP_Local_OAuth_Init_Lock::boot();
		MAD4B_SCP_Local_OAuth_Loopback_Guard::boot();
		MAD4B_SCP_Local_OAuth_Server::boot();

		add_action( 'init', array( __CLASS__, 'boot_oauth_transport_if_effective' ), 3 );
		MAD4B_SCP_MCP_Client_Compatibility::boot();

		$plugin_lifecycle = self::request_is_wordpress_plugin_lifecycle();
		$protocol_hotpath = class_exists( 'MAD4B_SCP_MCP_Request_Scope', false )
			&& MAD4B_SCP_MCP_Request_Scope::current_request_is_protocol_hotpath();
		$passive_admin_hotpath = class_exists( 'MAD4B_SCP_MCP_Request_Scope', false )
			&& MAD4B_SCP_MCP_Request_Scope::current_request_is_passive_admin_hotpath();
		$schema_reconciliation = self::request_requires_schema_reconciliation();

		// The protocol kernel and passive ChatGPT/Connection admin views already
		// have their canonical hooks bound from the entrypoint. Do not continue
		// through resource writers, browser canaries or lifecycle reconciliation
		// merely to serve a protocol request or render a cached/read-only admin view.
		// Keep only the lightweight ability annotations needed by catalog/status
		// projections. Endpoint jobs stay passive until their worker authorizes REST.
		if ( $protocol_hotpath || $passive_admin_hotpath ) {
			MAD4B_SCP_Staging_Write_Authority::boot();
			MAD4B_SCP_Write_Runtime_Certification::boot();
			MAD4B_SCP_Skill_Runtime_Certification::boot();
			MAD4B_SCP_MCP_Registration_Bridge::boot_early();
			return;
		}

		// Governance schema repair is lifecycle work, never request-serving work.
		// Activation performs the normal install. After an update, only explicit
		// Control Plane/CLI lifecycle may run physical readiness probes or dbDelta.
		// Mutation authorization independently re-proves Schema::is_ready() before
		// every side effect, so ordinary frontend/REST/Admin reads stay lightweight
		// without weakening fail-closed mutation safety.
		if ( ! $plugin_lifecycle && ! $protocol_hotpath && $schema_reconciliation
			&& ( (int) get_option( MAD4B_SCP_Schema::OPTION, 0 ) < MAD4B_SCP_Schema::VERSION
				|| ! MAD4B_SCP_Schema::is_ready()
				|| ! MAD4B_SCP_Schema::critical_ready() ) ) {
			$schema = MAD4B_SCP_Schema::install_or_upgrade();
			if ( is_wp_error( $schema ) ) self::$schema_error = $schema;
		}
		if ( ! $plugin_lifecycle && ! $protocol_hotpath && $schema_reconciliation
			&& false === get_option( MAD4B_SCP_Audit::LEGACY_OPTION, false ) ) {
			add_option( MAD4B_SCP_Audit::LEGACY_OPTION, array(), '', false );
		}
		if ( ! $plugin_lifecycle && ! $protocol_hotpath && $schema_reconciliation && ! is_wp_error( self::$schema_error ) ) {
			// Audit::record() performs the same fail-closed head initialization before
			// every mutation audit. MCP/OAuth discovery therefore does not need table/
			// engine/legacy-chain/head inspection merely to establish the protocol.
			$audit = MAD4B_SCP_Audit::ensure_head_initialized();
			if ( is_wp_error( $audit ) ) self::$schema_error = $audit;
		}
		if ( ! $plugin_lifecycle && ! is_wp_error( self::$schema_error ) && self::request_requires_skill_reconciliation() ) {
			// Provider discovery derives adapter readiness from the in-memory adapter
			// registry. Register the deterministic defaults before the first provider
			// reconciliation so the result cannot depend on a later MCP/server call.
			$adapter_registry = MAD4B_SCP_Adapter_Registry::instance();
			$adapter_registry->register_defaults();
			MAD4B_SCP_Skill_Seeder::bootstrap();
			MAD4B_SCP_Skill_Provider_Discovery::bootstrap();
		}
		if ( is_wp_error( self::$schema_error ) ) add_action( 'admin_notices', array( __CLASS__, 'schema_notice' ) );

		self::boot_admin_navigation();
		MAD4B_SCP_Skill_Resource_Writer::boot();
		MAD4B_SCP_Local_OAuth_Browser_Canary::boot();

		if ( ! function_exists( 'wp_register_ability' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'abilities_notice' ) );
			return;
		}

		MAD4B_SCP_Connection_Ability::boot();
		MAD4B_SCP_Functional_Gap_Runtime_Diagnostic::boot();
		MAD4B_SCP_Code_Snippets_Runtime_Diagnostic::boot();
		MAD4B_SCP_Governed_Ability_Overrides::boot();
		MAD4B_SCP_Staging_Write_Authority::boot();
		MAD4B_SCP_Staging_Write_Candidate_Binding::boot();

		// Inspection and mutation are separate contracts. Never reconcile grants
		// or subjects from Abilities bootstrap or ordinary wp-admin reads.
		remove_action( 'admin_init', array( 'MAD4B_SCP_Staging_Write_Authority', 'reconcile' ), 20 );
		remove_action( 'wp_abilities_api_init', array( 'MAD4B_SCP_Staging_Write_Authority', 'reconcile' ), 95 );

		MAD4B_SCP_Staging_Write_Planning_Guard::boot();

		// Explicit persistence remains an admin/runtime lifecycle concern. The
		// public read ability is rebound earlier by MAD4B_SCP_Live_Truth to a
		// non-mutating current-truth inspector.
		add_filter( 'wp_register_ability_args', array( __CLASS__, 'make_write_certification_explicit' ), 90, 2 );
		MAD4B_SCP_Write_Runtime_Certification::boot();
		remove_action( 'mcp_adapter_init', array( 'MAD4B_SCP_Write_Runtime_Certification', 'observe' ), 110 );
		remove_action( 'admin_init', array( 'MAD4B_SCP_Write_Runtime_Certification', 'observe' ), 110 );
		add_action( 'admin_init', array( __CLASS__, 'observe_write_certification_on_certification_page' ), 110 );

		MAD4B_SCP_Governance_Abilities::boot();
		MAD4B_SCP_Skill_Abilities::boot();
		MAD4B_SCP_Skills_Adapter::boot();
		MAD4B_SCP_Skill_Runtime_Certification::boot();
		MAD4B_SCP_MCP_Registration_Bridge::boot_early();

		if ( ! class_exists( 'WP\\MCP\\Core\\McpAdapter' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'mcp_notice' ) );
		}
	}

	private static function boot_admin_navigation() {
		if ( ! is_admin() && ( ! defined( 'WP_CLI' ) || ! WP_CLI ) ) return;
		MAD4B_SCP_Admin_UI::boot();
		MAD4B_SCP_Context_Admin_UI::boot();
		MAD4B_SCP_Connection_Admin_UI::boot();
		MAD4B_SCP_ChatGPT_Connection_Admin_UI::boot();
		// Menu/enqueue registration is presentation-only. Keep OAuth Canary
		// navigable even when the current Control Plane GET is zero-touch; its
		// status projection is shallow and the actual canary runs explicitly in JS.
		MAD4B_SCP_Local_OAuth_Browser_Canary::boot();
		MAD4B_SCP_Adapter_Coverage_Admin_UI::boot();
		MAD4B_SCP_Runtime_Components_Admin_UI::boot();
		MAD4B_SCP_Skills_Admin_UI::boot();
	}

	public static function boot_oauth_transport_if_effective() {
		if ( ! self::oauth_transport_enabled() || ! class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) ) return;
		if ( defined( 'MAD4B_MCP_LOCAL_OAUTH_ENABLED' ) && true === constant( 'MAD4B_MCP_LOCAL_OAUTH_ENABLED' ) ) {
			if ( ! class_exists( 'MAD4B_SCP_Local_OAuth_Key_Path_Policy' ) || ! MAD4B_SCP_Local_OAuth_Key_Path_Policy::transport_ready() ) return;
		}
		$status = method_exists( 'MAD4B_SCP_OAuth_Resource_Bridge', 'runtime_identity_status' )
			? MAD4B_SCP_OAuth_Resource_Bridge::runtime_identity_status()
			: MAD4B_SCP_OAuth_Resource_Bridge::status();
		if ( ! is_array( $status ) || empty( $status['effective'] ) ) return;
		MAD4B_SCP_OAuth_Request_Context_Guard::boot();
		MAD4B_SCP_OAuth_JWT_Header_Guard::boot();
		MAD4B_SCP_OAuth_Resource_Bridge::boot();
		MAD4B_SCP_OAuth_Outbound_Budget_Guard::boot();
		MAD4B_SCP_OAuth_Subject_Gate::boot();
		MAD4B_SCP_OAuth_Challenge_Alignment::boot();
	}

	private static function oauth_transport_enabled() {
		return defined( 'MAD4B_MCP_OAUTH_ENABLED' ) && true === constant( 'MAD4B_MCP_OAUTH_ENABLED' );
	}

	private static function request_is_wordpress_plugin_lifecycle() {
		if ( ! is_admin() ) return false;
		$pagenow = isset( $GLOBALS['pagenow'] ) ? sanitize_key( (string) $GLOBALS['pagenow'] ) : '';
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( (string) $_REQUEST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- lifecycle observation only.
		if ( in_array( $pagenow, array( 'update.php', 'update-core.php', 'plugin-install.php', 'plugins.php' ), true ) ) return true;
		return in_array( $action, array( 'upload-plugin', 'install-plugin', 'update-plugin', 'activate', 'deactivate', 'delete-selected' ), true );
	}

	private static function request_requires_schema_reconciliation() {
		if ( defined( 'WP_CLI' ) && constant( 'WP_CLI' ) ) return true;
		// Keep the historical MAD4B admin classifier explicit, but make it a
		// negative gate. Viewing a Control Plane screen is request-serving work,
		// not permission to run physical schema probes or dbDelta.
		if ( is_admin() ) {
			$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- lifecycle classification only.
			if ( 0 === strpos( $page, 'mad4b-control-plane' ) ) return false;
		}
		// Activation, governed self-update and Runtime Convergence own schema
		// lifecycle repair. All other web/admin reads remain zero-repair.
		return false;
	}

	private static function request_requires_skill_reconciliation() {
		if ( defined( 'WP_CLI' ) && constant( 'WP_CLI' ) ) return true;

		// Preserve the explicit protocol-hotpath invariant for the refresh/MCP
		// contract, then apply the stronger rule below: no ordinary web/admin read
		// request owns Skill/provider reconciliation.
		if ( class_exists( 'MAD4B_SCP_MCP_Request_Scope', false )
			&& MAD4B_SCP_MCP_Request_Scope::current_request_requires_mcp_runtime() ) return false;

		// Keep the historical MAD4B admin classification explicit for compatibility,
		// but invert its authority: rendering a MAD4B page is also read-serving work
		// and therefore cannot trigger reconciliation.
		if ( is_admin() ) {
			$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing observation only.
			if ( 0 === strpos( $page, 'mad4b-control-plane' ) ) return false;
		}

		// Seed/provider reconciliation performs filesystem and provider discovery.
		// It is owned by activation, explicit Skills reconciliation, or Runtime
		// Convergence after a deployment. Read-only admin rendering never owns it.
		return false;
	}

	private static function bind_local_oauth_subject_compatibility() {
		if ( defined( 'MAD4B_MCP_OAUTH_ALLOWED_SUBJECTS' ) ) return;
		if ( ! defined( 'MAD4B_MCP_LOCAL_OAUTH_ENABLED' ) || true !== constant( 'MAD4B_MCP_LOCAL_OAUTH_ENABLED' ) ) return;
		if ( ! defined( 'MAD4B_MCP_OAUTH_ALLOWED_SUBJECT_BINDINGS' ) || ! class_exists( 'MAD4B_SCP_Local_OAuth_Server' ) ) return;
		$bindings = constant( 'MAD4B_MCP_OAUTH_ALLOWED_SUBJECT_BINDINGS' );
		if ( ! is_array( $bindings ) ) return;
		$local_issuer = rtrim( (string) MAD4B_SCP_Local_OAuth_Server::issuer(), '/' );
		if ( '' === $local_issuer ) return;
		foreach ( $bindings as $issuer => $subjects ) {
			if ( ! is_string( $issuer ) || ! hash_equals( $local_issuer, rtrim( trim( $issuer ), '/' ) ) ) continue;
			$items = is_array( $subjects ) ? $subjects : preg_split( '/[\s,]+/', (string) $subjects );
			$bounded = array();
			foreach ( is_array( $items ) ? array_slice( $items, 0, 500 ) : array() as $subject ) {
				if ( ! is_string( $subject ) ) continue;
				$subject = trim( $subject );
				if ( '' === $subject || strlen( $subject ) > 512 || preg_match( '/[\s,]/', $subject ) ) continue;
				if ( 0 !== strpos( $subject, 'user:' ) && 0 !== strpos( $subject, 'tenant:' ) ) continue;
				$bounded[] = $subject;
			}
			$bounded = array_values( array_unique( $bounded ) );
			if ( ! empty( $bounded ) ) define( 'MAD4B_MCP_OAUTH_ALLOWED_SUBJECTS', $bounded );
			return;
		}
	}

	public static function make_write_certification_explicit( $args, $name ) {
		if ( ! is_array( $args ) || 'mad4b/write-runtime-certification' !== (string) $name ) return $args;
		// The MCP status ability is readonly: fresh inspection only.
		$args['execute_callback'] = array( 'MAD4B_SCP_Live_Truth', 'current_write_certification' );
		return $args;
	}

	public static function reconcile_authority_if_needed() {
		// Compatibility entry point retained for older callers. Inspection only.
		if ( class_exists( 'MAD4B_SCP_Live_Truth' ) && method_exists( 'MAD4B_SCP_Live_Truth', 'current_authority_status' ) ) {
			return MAD4B_SCP_Live_Truth::current_authority_status();
		}
		return class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) ? MAD4B_SCP_Staging_Write_Authority::status() : array();
	}

	public static function is_authority_admin_surface() {
		if ( ! is_admin() ) return false;
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin routing decision.
		return 0 === strpos( $page, 'mad4b-control-plane' ) || 'mad4b-approval-decisions' === $page;
	}

	public static function reconcile_authority_on_mad4b_admin() {
		// Compatibility entry point only. Admin reads must not mutate grants.
		if ( ! current_user_can( 'manage_options' ) || ! self::is_authority_admin_surface() ) return array();
		return self::reconcile_authority_if_needed();
	}

	public static function observe_write_certification_on_certification_page() {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) return;
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing decision.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing decision.
		if ( 'mad4b-control-plane-connection' !== $page || 'certification' !== $tab ) return;
		MAD4B_SCP_Write_Runtime_Certification::observe();
	}

	public static function prime_admin_mcp_runtime() {
		// Compatibility entry point. HTML rendering never primes REST; signed
		// MAD4B_SCP_Endpoint_Diagnostic AJAX jobs own single-server materialization.
		return;
	}

	public static function governance_bootstrap_error_code() {
		return is_wp_error( self::$schema_error ) ? sanitize_key( (string) self::$schema_error->get_error_code() ) : '';
	}

	public static function governance_bootstrap_error_data() {
		$data = is_wp_error( self::$schema_error ) ? self::$schema_error->get_error_data() : array();
		return is_array( $data ) ? $data : array();
	}

	public static function schema_notice() {
		if ( ! current_user_can( 'manage_options' ) || ! is_wp_error( self::$schema_error ) ) return;
		$code = self::governance_bootstrap_error_code();
		$data = self::governance_bootstrap_error_data();
		$details = array();
		if ( isset( $data['from_version'], $data['target_version'] ) ) {
			$details[] = 'schema ' . (int) $data['from_version'] . '→' . (int) $data['target_version'];
		}
		$physical = isset( $data['physical_integrity'] ) && is_array( $data['physical_integrity'] ) ? $data['physical_integrity'] : array();
		foreach ( array(
			'missing_tables' => 'missing tables',
			'missing_approval_columns' => 'missing approval columns',
			'missing_durable_columns' => 'missing durable columns',
			'missing_durable_indexes' => 'missing durable indexes',
		) as $key => $label ) {
			$items = isset( $physical[ $key ] ) && is_array( $physical[ $key ] ) ? array_values( array_filter( array_map( 'strval', $physical[ $key ] ) ) ) : array();
			if ( $items ) $details[] = $label . ': ' . implode( ', ', array_slice( $items, 0, 12 ) );
		}
		$dbdelta_errors = array();
		foreach ( isset( $data['dbdelta_diagnostics'] ) && is_array( $data['dbdelta_diagnostics'] ) ? $data['dbdelta_diagnostics'] : array() as $row ) {
			if ( ! is_array( $row ) || empty( $row['last_error'] ) ) continue;
			$table = ! empty( $row['table'] ) ? (string) $row['table'] : 'unknown-table';
			$dbdelta_errors[] = $table . ': ' . substr( trim( (string) $row['last_error'] ), 0, 220 );
			if ( count( $dbdelta_errors ) >= 3 ) break;
		}
		if ( $dbdelta_errors ) $details[] = 'dbDelta: ' . implode( ' | ', $dbdelta_errors );
		$message = 'MAD4B governance schema is unavailable';
		if ( '' !== $code ) $message .= ' [' . $code . ']';
		$message .= '. Mutation remains fail-closed.';
		if ( $details ) $message .= ' ' . implode( ' | ', $details );
		echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
	}
	public static function abilities_notice() {
		if ( current_user_can( 'activate_plugins' ) ) echo '<div class="notice notice-error"><p>' . esc_html__( 'MAD4B Site Control Plane requires the WordPress Abilities API (WordPress 6.9+).', 'mad4b-site-control-plane' ) . '</p></div>';
	}
	public static function mcp_notice() {
		if ( current_user_can( 'activate_plugins' ) ) echo '<div class="notice notice-warning"><p>' . esc_html__( 'MAD4B Site Control Plane abilities are registered, but the official WordPress MCP Adapter is not active. Install and activate mcp-adapter to expose MCP servers.', 'mad4b-site-control-plane' ) . '</p></div>';
	}
}
