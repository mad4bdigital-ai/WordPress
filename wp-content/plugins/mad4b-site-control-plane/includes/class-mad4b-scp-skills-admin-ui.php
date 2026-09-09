<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Local administrator authoring workspace for repeatable Skill workflows.
 * No MCP write tool is added here. File mutation is available only when the
 * explicit Skill editor gate is enabled for the current environment.
 */
final class MAD4B_SCP_Skills_Admin_UI {
	const PAGE_SLUG = 'mad4b-control-plane-skills';

	public static function boot() {
		add_action( 'admin_init', array( __CLASS__, 'intercept_export' ), 1 );
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 30 );
	}

	public static function register_menu() {
		add_submenu_page(
			MAD4B_SCP_Admin_UI::PAGE_SLUG,
			__( 'MAD4B Skills', 'mad4b-site-control-plane' ),
			__( 'Skills', 'mad4b-site-control-plane' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Stream exports before wp-admin emits page headers. Failures redirect back
	 * with a bounded error code so the administrator never gets a silent no-op.
	 */
	public static function intercept_export() {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) return;
		if ( empty( $_POST['mad4b_skill_action'] ) || 'export' !== sanitize_key( wp_unslash( $_POST['mad4b_skill_action'] ) ) ) return; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified below.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing only.
		if ( self::PAGE_SLUG !== $page ) return;

		check_admin_referer( 'mad4b_skill_export', 'mad4b_skill_export_nonce' );
		$export = MAD4B_SCP_Skill_Exporter::build_temp_zip();
		if ( is_wp_error( $export ) ) {
			self::redirect_export_error( $export->get_error_code() );
		}

		$path = isset( $export['path'] ) ? (string) $export['path'] : '';
		$filename = isset( $export['filename'] ) ? (string) $export['filename'] : 'mad4b-wordpress-skills.zip';
		if ( '' === $path || ! is_file( $path ) ) self::redirect_export_error( 'mad4b_skill_export_file_missing' );
		if ( headers_sent() ) { @unlink( $path ); self::redirect_export_error( 'mad4b_skill_export_headers_sent' ); }

		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . str_replace( '"', '', basename( $filename ) ) . '"' );
		header( 'Content-Length: ' . (string) filesize( $path ) );
		if ( ! empty( $export['sha256'] ) ) header( 'X-MAD4B-SHA256: ' . (string) $export['sha256'] );
		if ( ! empty( $export['identity_token'] ) ) header( 'X-MAD4B-Snapshot-Identity: ' . (string) $export['identity_token'] );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		@unlink( $path );
		exit;
	}

	private static function redirect_export_error( $code ) {
		$url = add_query_arg(
			array(
				'page' => self::PAGE_SLUG,
				'mad4b_skill_notice' => 'export_error',
				'mad4b_skill_error' => sanitize_key( (string) $code ),
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'You do not have permission to manage MAD4B Skills.', 'mad4b-site-control-plane' ) );

		$message = null;
		if ( isset( $_POST['mad4b_skill_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in handler.
			$action = sanitize_key( wp_unslash( $_POST['mad4b_skill_action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( 'save' === $action ) $message = self::handle_save();
			if ( 'save_resource' === $action ) $message = self::handle_save_resource();
		}

		$status = MAD4B_SCP_Skill_Registry::status();
		$skills = MAD4B_SCP_Skill_Registry::list_skills();
		$selected = self::selected_skill();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'MAD4B Skills', 'mad4b-site-control-plane' ) . '</h1>';
		echo '<p>' . esc_html__( 'Create repeatable WordPress workflows as real SKILL.md files. Runtime authoring is stored under wp-content/mad4b-skills and grouped by site, connection, provider, adapter, or workflow ownership.', 'mad4b-site-control-plane' ) . '</p>';
		self::render_query_notice();
		if ( is_wp_error( $message ) ) echo '<div class="notice notice-error"><p>' . esc_html( $message->get_error_message() ) . '</p></div>';
		elseif ( is_string( $message ) && '' !== $message ) echo '<div class="notice notice-success"><p>' . esc_html( $message ) . '</p></div>';

		self::render_status( $status );
		self::render_snapshot_note();
		self::render_skill_table( $skills );
		self::render_editor( $status, $selected );
		self::render_resource_editor( $status, $selected );
		self::render_export( $status );
		echo '</div>';
	}

	private static function render_query_notice() {
		if ( empty( $_GET['mad4b_skill_notice'] ) ) return; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice.
		$notice = sanitize_key( wp_unslash( $_GET['mad4b_skill_notice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'export_error' !== $notice ) return;
		$code = isset( $_GET['mad4b_skill_error'] ) ? sanitize_key( wp_unslash( $_GET['mad4b_skill_error'] ) ) : 'unknown'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( sprintf( __( 'Portable Plugin export failed. Error: %s', 'mad4b-site-control-plane' ), $code ) ) . '</p></div>';
	}

	private static function render_status( array $status ) {
		$identity = class_exists( 'MAD4B_SCP_Skill_Snapshot_Identity' ) ? MAD4B_SCP_Skill_Snapshot_Identity::build() : array();
		$cert = class_exists( 'MAD4B_SCP_Skill_Runtime_Certification' ) ? MAD4B_SCP_Skill_Runtime_Certification::status() : array();

		echo '<h2>' . esc_html__( 'Skill registry', 'mad4b-site-control-plane' ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:1000px"><tbody>';
		self::row( 'Editor enabled', ! empty( $status['editor_enabled'] ) ? 'yes' : 'no' );
		self::row( 'Scripts editor enabled', ! empty( $status['scripts_editor_enabled'] ) ? 'yes' : 'no' );
		self::row( 'Storage initialized', ! empty( $status['storage_initialized'] ) ? 'yes' : 'no' );
		self::row( 'Storage writable', ! empty( $status['storage_writable'] ) ? 'yes' : 'no' );
		self::row( 'Registered runtime skills', isset( $status['skill_count'] ) ? (string) $status['skill_count'] : '0' );
		self::row( 'OpenAI App mapping configured', ! empty( $status['portable_app_id_configured'] ) ? 'yes' : 'no' );
		if ( ! empty( $status['portable_app_id'] ) ) self::row( 'OpenAI App ID', $status['portable_app_id'] );
		self::row( 'Runtime certification', isset( $cert['state'] ) ? (string) $cert['state'] : 'unavailable' );
		if ( ! empty( $identity['identity_token'] ) ) self::row( 'Snapshot identity', $identity['identity_token'] );
		echo '</tbody></table>';

		if ( empty( $status['editor_enabled'] ) ) {
			echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__( 'Authoring is fail-closed.', 'mad4b-site-control-plane' ) . '</strong> ';
			echo esc_html__( 'On Staging the Control Plane enables the Skill editor automatically unless an explicit MAD4B_SKILLS_EDITOR_ENABLED=false kill-switch is present. Production is never auto-enabled and requires both MAD4B_SKILLS_EDITOR_ENABLED=true and MAD4B_SKILLS_PRODUCTION_EDITOR_ENABLED=true. These switches do not enable MCP mutation.', 'mad4b-site-control-plane' );
			echo '</p></div>';
		}
	}

	private static function render_snapshot_note() {
		echo '<div class="notice notice-info inline"><p>';
		echo esc_html__( 'The WordPress registry is dynamic, but installed ChatGPT/Codex Plugin skills are versioned snapshots. After changing a Skill, export a new portable package or redeploy the MCP skill source and run Scan Tools again. Compare the exported MAD4B-SNAPSHOT-ID.txt token with the Snapshot identity shown above.', 'mad4b-site-control-plane' );
		echo '</p></div>';
	}

	private static function render_skill_table( array $skills ) {
		echo '<h2>' . esc_html__( 'Skills', 'mad4b-site-control-plane' ) . '</h2>';
		if ( empty( $skills ) ) { echo '<p>' . esc_html__( 'No runtime-authored skills exist yet.', 'mad4b-site-control-plane' ) . '</p>'; return; }
		echo '<table class="widefat striped"><thead><tr><th>Name</th><th>Level</th><th>Target</th><th>Enabled</th><th>Resources</th><th>SHA-256</th><th>Action</th></tr></thead><tbody>';
		foreach ( $skills as $skill ) {
			$url = add_query_arg(
				array(
					'page' => self::PAGE_SLUG,
					'level' => $skill['level'],
					'target' => $skill['target'],
					'skill' => $skill['name'],
				),
				admin_url( 'admin.php' )
			);
			echo '<tr><td><strong><code>' . esc_html( $skill['name'] ) . '</code></strong><br>' . esc_html( $skill['description'] ) . '</td>';
			echo '<td>' . esc_html( $skill['level'] ) . '</td><td><code>' . esc_html( $skill['target'] ) . '</code></td>';
			echo '<td>' . esc_html( ! empty( $skill['enabled'] ) ? 'yes' : 'no' ) . '</td>';
			echo '<td>' . esc_html( isset( $skill['resources'] ) && is_array( $skill['resources'] ) ? (string) count( $skill['resources'] ) : '0' ) . '</td>';
			echo '<td><code>' . esc_html( substr( (string) $skill['sha256'], 0, 16 ) ) . '…</code></td>';
			echo '<td><a class="button button-small" href="' . esc_url( $url ) . '">' . esc_html__( 'Open', 'mad4b-site-control-plane' ) . '</a></td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function selected_skill() {
		if ( empty( $_GET['skill'] ) || empty( $_GET['level'] ) ) return null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only selection.
		$level = sanitize_key( wp_unslash( $_GET['level'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$target = isset( $_GET['target'] ) ? sanitize_text_field( wp_unslash( $_GET['target'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$name = sanitize_key( wp_unslash( $_GET['skill'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$skill = MAD4B_SCP_Skill_Registry::get_skill( $level, $target, $name );
		return is_wp_error( $skill ) ? null : $skill;
	}

	private static function render_editor( array $status, $selected ) {
		$editing = is_array( $selected );
		$level = $editing ? $selected['level'] : 'site';
		$target = $editing ? $selected['target'] : '';
		$name = $editing ? $selected['name'] : '';
		$description = $editing ? $selected['description'] : '';
		$body = '';
		if ( $editing && isset( $selected['content'] ) ) {
			$body = preg_replace( '/\A---\R.*?\R---\R/s', '', (string) $selected['content'], 1 );
			$body = trim( (string) $body );
		}
		$enabled = ! $editing || ! empty( $selected['enabled'] );

		echo '<h2>' . esc_html( $editing ? __( 'Edit Skill', 'mad4b-site-control-plane' ) : __( 'Create Skill', 'mad4b-site-control-plane' ) ) . '</h2>';
		echo '<form method="post" style="max-width:1000px">';
		wp_nonce_field( 'mad4b_skill_save', 'mad4b_skill_nonce' );
		echo '<input type="hidden" name="mad4b_skill_action" value="save">';
		echo '<table class="form-table"><tbody>';
		if ( $editing ) {
			echo '<input type="hidden" name="level" value="' . esc_attr( $level ) . '">';
			echo '<input type="hidden" name="target" value="' . esc_attr( $target ) . '">';
			echo '<input type="hidden" name="name" value="' . esc_attr( $name ) . '">';
			echo '<tr><th>Identity</th><td><code>' . esc_html( $level . ':' . $target . ':' . $name ) . '</code><p class="description">Skill identity is immutable while editing. Create a new Skill when ownership or name must change.</p></td></tr>';
		} else {
			echo '<tr><th><label for="mad4b_skill_level">Level</label></th><td><select id="mad4b_skill_level" name="level">';
			foreach ( MAD4B_SCP_Skill_Registry::levels() as $option ) echo '<option value="' . esc_attr( $option ) . '" ' . selected( $level, $option, false ) . '>' . esc_html( $option ) . '</option>';
			echo '</select><p class="description">site, connection, provider, adapter, or workflow.</p></td></tr>';
			echo '<tr><th><label for="mad4b_skill_target">Target</label></th><td><input class="regular-text" id="mad4b_skill_target" name="target" value=""><p class="description">For provider use a stable plugin slug such as elementor or jet-engine. Site level ignores this field.</p></td></tr>';
			echo '<tr><th><label for="mad4b_skill_name">Name</label></th><td><input class="regular-text" required pattern="[a-z0-9]+(?:-[a-z0-9]+)*" id="mad4b_skill_name" name="name" value=""><p class="description">Globally unique kebab-case name used in portable skills/&lt;name&gt;/SKILL.md.</p></td></tr>';
		}
		echo '<tr><th><label for="mad4b_skill_description">Description</label></th><td><textarea class="large-text" rows="3" required id="mad4b_skill_description" name="description">' . esc_textarea( $description ) . '</textarea><p class="description">Trigger-focused description that tells the model when to consider this workflow.</p></td></tr>';
		echo '<tr><th><label for="mad4b_skill_body">Workflow</label></th><td><textarea class="large-text code" rows="18" required id="mad4b_skill_body" name="body">' . esc_textarea( $body ) . '</textarea><p class="description">Instructions only. MAD4B writes the required YAML frontmatter automatically.</p></td></tr>';
		echo '<tr><th>Enabled</th><td><label><input type="checkbox" name="enabled" value="1" ' . checked( $enabled, true, false ) . '> Include in the next portable snapshot</label></td></tr>';
		echo '</tbody></table>';
		submit_button( $editing ? __( 'Save Skill', 'mad4b-site-control-plane' ) : __( 'Create Skill', 'mad4b-site-control-plane' ), 'primary', 'submit', true, array( 'disabled' => empty( $status['editor_enabled'] ) ? 'disabled' : null ) );
		echo '</form>';
	}

	private static function render_resource_editor( array $status, $selected ) {
		if ( ! is_array( $selected ) ) return;
		echo '<h2>' . esc_html__( 'Supporting file', 'mad4b-site-control-plane' ) . '</h2>';
		echo '<p>' . esc_html__( 'Add text resources under references/, assets/, or scripts/. scripts/ requires its own explicit gate and is never executed by WordPress.', 'mad4b-site-control-plane' ) . '</p>';
		echo '<form method="post" style="max-width:1000px">';
		wp_nonce_field( 'mad4b_skill_resource_save', 'mad4b_skill_resource_nonce' );
		echo '<input type="hidden" name="mad4b_skill_action" value="save_resource">';
		echo '<input type="hidden" name="level" value="' . esc_attr( $selected['level'] ) . '">';
		echo '<input type="hidden" name="target" value="' . esc_attr( $selected['target'] ) . '">';
		echo '<input type="hidden" name="name" value="' . esc_attr( $selected['name'] ) . '">';
		echo '<table class="form-table"><tbody>';
		echo '<tr><th><label for="mad4b_skill_resource_path">Relative path</label></th><td><input class="regular-text" id="mad4b_skill_resource_path" name="resource_path" placeholder="references/provider-contract.md" required></td></tr>';
		echo '<tr><th><label for="mad4b_skill_resource_content">Content</label></th><td><textarea class="large-text code" rows="12" id="mad4b_skill_resource_content" name="resource_content" required></textarea></td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Save Supporting File', 'mad4b-site-control-plane' ), 'secondary', 'submit', true, array( 'disabled' => empty( $status['editor_enabled'] ) ? 'disabled' : null ) );
		echo '</form>';
	}

	private static function render_export( array $status ) {
		echo '<h2>' . esc_html__( 'Portable Plugin snapshot', 'mad4b-site-control-plane' ) . '</h2>';
		echo '<p>' . esc_html__( 'Exports enabled runtime skills into a portable Agent Plugins ZIP with root plugin.json, snapshot identity files, and skills/. The governed Staging App mapping is included automatically and the exported capability remains Read.', 'mad4b-site-control-plane' ) . '</p>';
		echo '<form method="post">';
		wp_nonce_field( 'mad4b_skill_export', 'mad4b_skill_export_nonce' );
		echo '<input type="hidden" name="mad4b_skill_action" value="export">';
		submit_button( __( 'Export Portable Plugin ZIP', 'mad4b-site-control-plane' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	private static function handle_save() {
		check_admin_referer( 'mad4b_skill_save', 'mad4b_skill_nonce' );
		$result = MAD4B_SCP_Skill_Registry::save_skill(
			array(
				'level' => isset( $_POST['level'] ) ? sanitize_key( wp_unslash( $_POST['level'] ) ) : '',
				'target' => isset( $_POST['target'] ) ? sanitize_text_field( wp_unslash( $_POST['target'] ) ) : '',
				'name' => isset( $_POST['name'] ) ? sanitize_key( wp_unslash( $_POST['name'] ) ) : '',
				'description' => isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '',
				'body' => isset( $_POST['body'] ) ? wp_unslash( $_POST['body'] ) : '',
				'enabled' => isset( $_POST['enabled'] ),
			)
		);
		return is_wp_error( $result ) ? $result : __( 'Skill file saved and audit evidence committed.', 'mad4b-site-control-plane' );
	}

	private static function handle_save_resource() {
		check_admin_referer( 'mad4b_skill_resource_save', 'mad4b_skill_resource_nonce' );
		$result = MAD4B_SCP_Skill_Resource_Writer::save(
			isset( $_POST['level'] ) ? sanitize_key( wp_unslash( $_POST['level'] ) ) : '',
			isset( $_POST['target'] ) ? sanitize_text_field( wp_unslash( $_POST['target'] ) ) : '',
			isset( $_POST['name'] ) ? sanitize_key( wp_unslash( $_POST['name'] ) ) : '',
			isset( $_POST['resource_path'] ) ? sanitize_text_field( wp_unslash( $_POST['resource_path'] ) ) : '',
			isset( $_POST['resource_content'] ) ? wp_unslash( $_POST['resource_content'] ) : ''
		);
		return is_wp_error( $result ) ? $result : __( 'Supporting Skill file saved with audit evidence.', 'mad4b-site-control-plane' );
	}

	private static function row( $label, $value ) {
		echo '<tr><th style="width:260px">' . esc_html( $label ) . '</th><td><code style="word-break:break-all">' . esc_html( (string) $value ) . '</code></td></tr>';
	}
}
