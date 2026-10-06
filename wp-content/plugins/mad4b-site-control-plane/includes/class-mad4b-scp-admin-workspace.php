<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Local navigation only: no provider discovery, settings writes or new authority. */
final class MAD4B_SCP_Admin_Workspace {
	const HANDLE = 'mad4b-admin-workspace';
	private static $booted = false;
	private static $rendered = false;

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_action( 'init', array( __CLASS__, 'load_translations' ), 0 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'in_admin_header', array( __CLASS__, 'render' ) );
		add_filter( 'admin_body_class', array( __CLASS__, 'body_class' ) );
	}

	public static function load_translations() {
		load_plugin_textdomain( 'mad4b-site-control-plane', false, dirname( plugin_basename( MAD4B_SCP_FILE ) ) . '/languages' );
	}

	/** Exact registered route and its capability, never a prefix-only match. */
	public static function current_page() {
		if ( ! is_admin() || wp_doing_ajax() ) return '';
		$page = MAD4B_SCP_Admin_Experience::query_string( 'page', '', 128 );
		$routes = MAD4B_SCP_Admin_Route_Registry::routes();
		return isset( $routes[ $page ] ) && current_user_can( $routes[ $page ]['required_capability'] ) ? $page : '';
	}

	public static function body_class( $classes ) {
		return '' !== self::current_page() ? $classes . ' mad4b-workspace-page' : $classes;
	}

	public static function enqueue() {
		if ( '' === self::current_page() ) return;
		// File mtimes invalidate presentation caches without hashing the connection hot path.
		foreach ( array( 'css', 'js' ) as $extension ) {
			$relative = 'assets/admin-workspace.' . $extension;
			$path = MAD4B_SCP_DIR . $relative;
			$version = MAD4B_SCP_VERSION . '-' . ( is_readable( $path ) ? (string) filemtime( $path ) : '0' );
			$url = plugins_url( $relative, MAD4B_SCP_FILE );
			if ( 'css' === $extension ) wp_enqueue_style( self::HANDLE, $url, array(), $version );
			else wp_enqueue_script( self::HANDLE, $url, array(), $version, true );
		}
	}

	/** Presentation contracts for the existing pages; unknown registered pages remain discoverable. */
	private static function definitions() {
		return array(
			'mad4b-operator-control-center' => array( __( 'Action Center', 'mad4b-site-control-plane' ), __( 'Review blockers and choose the next safe step.', 'mad4b-site-control-plane' ), 'operate', '', array() ),
			'mad4b-control-plane-site-profile' => array( __( 'Site Profile', 'mad4b-site-control-plane' ), __( 'Enroll this site and review its environment and access.', 'mad4b-site-control-plane' ), 'connect', '', array( 'Environment', 'Site identity', 'Access settings' ) ),
			'mad4b-control-plane-connection' => array( __( 'Connection', 'mad4b-site-control-plane' ), __( 'Inspect OAuth, endpoints, isolation and certification.', 'mad4b-site-control-plane' ), 'connect', 'tab', array( 'readiness', 'oauth', 'endpoints', 'isolation', 'certification' ) ),
			'mad4b-control-plane-chatgpt' => array( __( 'ChatGPT Connection', 'mad4b-site-control-plane' ), __( 'Follow client connection and consent instructions.', 'mad4b-site-control-plane' ), 'connect', '', array() ),
			'mad4b-control-plane-oauth-canary' => array( __( 'OAuth Check', 'mad4b-site-control-plane' ), __( 'Review the local browser connection check.', 'mad4b-site-control-plane' ), 'connect', '', array() ),
			'mad4b-control-plane-context' => array( __( 'Brand & Context', 'mad4b-site-control-plane' ), __( 'Connect Google and manage brand sources and assets.', 'mad4b-site-control-plane' ), 'content', 'tab', array( 'overview', 'google-drive', 'sources', 'assets', 'quality', 'intelligence' ) ),
			'mad4b-search-intelligence' => array( __( 'Search Intelligence', 'mad4b-site-control-plane' ), __( 'Add API credentials, select a profile and review search budgets.', 'mad4b-site-control-plane' ), 'content', 'section', array( 'overview', 'providers', 'budgets', 'markets', 'languages', 'surfaces', 'targets', 'serp', 'competitors', 'archives', 'experiments', 'diagnostics' ) ),
			'mad4b-control-plane-content-pipeline' => array( __( 'Content Pipeline', 'mad4b-site-control-plane' ), __( 'Select validation stages and review their prerequisites.', 'mad4b-site-control-plane' ), 'content', '', array( 'Stage selection', 'Advanced policy' ) ),
			'mad4b-control-plane' => array( __( 'Governance & History', 'mad4b-site-control-plane' ), __( 'Inspect agents, effective access, changes and audit evidence.', 'mad4b-site-control-plane' ), 'operate', 'tab', array( 'overview', 'agents', 'approvals', 'mutations', 'audit' ) ),
			'mad4b-approval-decisions' => array( __( 'Approval Decisions', 'mad4b-site-control-plane' ), __( 'Review exact proposed changes and decision history.', 'mad4b-site-control-plane' ), 'operate', 'view', array( 'actionable', 'history' ) ),
			'mad4b-adapter-coverage' => array( __( 'Provider Coverage', 'mad4b-site-control-plane' ), __( 'Review installed providers and capability-specific gaps.', 'mad4b-site-control-plane' ), 'runtime', 'tab', array( 'overview', 'installed', 'priority', 'functional', 'requests' ) ),
			'mad4b-runtime-components' => array( __( 'Runtime Components', 'mad4b-site-control-plane' ), __( 'Review component identity, updates and maintenance.', 'mad4b-site-control-plane' ), 'runtime', 'tab', array( 'overview', 'core', 'plugins', 'mu-plugins', 'drop-ins', 'themes', 'astra', 'maintenance' ) ),
			'mad4b-control-plane-skills' => array( __( 'Managed Skills', 'mad4b-site-control-plane' ), __( 'Review available skills and their reconciliation status.', 'mad4b-site-control-plane' ), 'runtime', '', array() ),
			'mad4b-control-plane-performance' => array( __( 'Performance', 'mad4b-site-control-plane' ), __( 'Inspect database performance and maintenance evidence.', 'mad4b-site-control-plane' ), 'runtime', '', array() ),
		);
	}

	public static function inventory() {
		$definitions = self::definitions();
		$out = array();
		foreach ( MAD4B_SCP_Admin_Route_Registry::routes() as $slug => $route ) {
			if ( ! current_user_can( $route['required_capability'] ) ) continue;
			$d = isset( $definitions[ $slug ] ) ? $definitions[ $slug ] : array( ucwords( str_replace( '-', ' ', $slug ) ), __( 'Open this registered workspace.', 'mad4b-site-control-plane' ), 'runtime', '', array() );
			$out[ $slug ] = array( 'slug' => $slug, 'title' => $d[0], 'description' => $d[1], 'group' => $d[2], 'query_key' => $d[3], 'sections' => $d[4], 'url' => $route['canonical_admin_url'], 'required_capability' => $route['required_capability'], 'authorizing' => false );
		}
		return $out;
	}

	/** Links resolve through declared routes; query parameters are fixed presentation values. */
	public static function link( $slug, array $query = array(), $fragment = '' ) {
		$inventory = self::inventory();
		if ( ! isset( $inventory[ $slug ] ) ) return '';
		$url = $inventory[ $slug ]['url'];
		if ( $query ) $url = add_query_arg( $query, $url );
		return $url . ( '' !== $fragment ? '#' . rawurlencode( $fragment ) : '' );
	}

	public static function render() {
		$page = self::current_page();
		if ( '' === $page || self::$rendered ) return;
		self::$rendered = true;
		$inventory = self::inventory();
		$environment = MAD4B_SCP_Admin_Experience::environment_context();
		echo '<section class="mad4b-workspace" aria-label="' . esc_attr__( 'MAD4B workspace navigation', 'mad4b-site-control-plane' ) . '">';
		echo '<a class="mad4b-workspace-skip" href="#mad4b-page-content">' . esc_html__( 'Skip to page content', 'mad4b-site-control-plane' ) . '</a>';
		echo '<div class="mad4b-workspace-heading"><div><span class="mad4b-workspace-brand">MAD4B</span><span class="mad4b-workspace-current">' . esc_html( $inventory[ $page ]['title'] ) . '</span></div>';
		echo '<span class="mad4b-workspace-environment">' . esc_html__( 'Environment', 'mad4b-site-control-plane' ) . ': <bdi>' . esc_html( $environment['effective_environment'] ) . '</bdi></span></div>';
		echo '<nav class="mad4b-workspace-shortcuts" aria-label="' . esc_attr__( 'Common setup actions', 'mad4b-site-control-plane' ) . '">';
		$shortcuts = array(
			array( 'mad4b-operator-control-center', array(), '', __( 'Action Center', 'mad4b-site-control-plane' ) ),
			array( 'mad4b-search-intelligence', array( 'section' => 'providers' ), 'search-providers', __( 'Search API credentials', 'mad4b-site-control-plane' ) ),
			array( 'mad4b-control-plane-context', array( 'tab' => 'google-drive' ), 'mad4b-google-primary-signin', __( 'Connect Google', 'mad4b-site-control-plane' ) ),
			array( 'mad4b-control-plane-content-pipeline', array(), '', __( 'Content Pipeline', 'mad4b-site-control-plane' ) ),
		);
		foreach ( $shortcuts as $shortcut ) {
			if ( ! isset( $inventory[ $shortcut[0] ] ) ) continue;
			$url = add_query_arg( $shortcut[1], $inventory[ $shortcut[0] ]['url'] ) . ( '' !== $shortcut[2] ? '#' . rawurlencode( $shortcut[2] ) : '' );
			if ( '' !== $url ) echo '<a href="' . esc_url( $url ) . '">' . esc_html( $shortcut[3] ) . '</a>';
		}
		echo '</nav><details class="mad4b-workspace-directory"><summary>' . esc_html__( 'All workspaces & setup', 'mad4b-site-control-plane' ) . ' <span>(' . esc_html( (string) count( $inventory ) ) . ')</span></summary>';
		echo '<div data-mad4b-directory-controls hidden><label for="mad4b-workspace-filter">' . esc_html__( 'Find a page or setting', 'mad4b-site-control-plane' ) . '</label><input type="search" id="mad4b-workspace-filter" aria-controls="mad4b-workspace-pages" autocomplete="off"><p data-mad4b-directory-empty hidden>' . esc_html__( 'No matching workspace. Clear the search to see all pages.', 'mad4b-site-control-plane' ) . '</p><span class="screen-reader-text" role="status" aria-live="polite" aria-atomic="true" data-mad4b-directory-status data-template="' . esc_attr__( '%d workspaces found', 'mad4b-site-control-plane' ) . '"></span></div>';
		echo '<div id="mad4b-workspace-pages" class="mad4b-workspace-pages">';
		foreach ( $inventory as $slug => $row ) {
			echo '<article data-mad4b-workspace-item><a href="' . esc_url( $row['url'] ) . '"' . ( $slug === $page ? ' aria-current="page"' : '' ) . '><strong>' . esc_html( $row['title'] ) . '</strong><span>' . esc_html( $row['description'] ) . '</span></a>';
			if ( '' !== $row['query_key'] && $row['sections'] ) {
				echo '<ul class="mad4b-workspace-section-links">';
				foreach ( $row['sections'] as $section ) echo '<li><a href="' . esc_url( add_query_arg( $row['query_key'], $section, $row['url'] ) ) . '">' . esc_html( __( ucwords( str_replace( array( '-', '_' ), ' ', $section ) ), 'mad4b-site-control-plane' ) ) . '</a></li>';
				echo '</ul>';
			}
			echo '</article>';
		}
		echo '</div></details></section><span id="mad4b-page-content" tabindex="-1"></span>';
	}
}
