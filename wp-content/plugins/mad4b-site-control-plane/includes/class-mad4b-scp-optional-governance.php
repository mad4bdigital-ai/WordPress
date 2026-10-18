<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Optional governance = operator advisory presentation ONLY.
 * Cannot turn off auth, release CI, approvals, grants, certification,
 * rollback, audit, integrity, incident recovery or kill switches.
 */
final class MAD4B_SCP_Optional_Governance {
    const CONTRACT = 'mad4b.optional-governance-options.v1';
    const CATALOG = 'config/optional-governance-catalog.json';
    const CATALOG_CONTRACT = 'mad4b.optional-governance-catalog.v1';
    const OPTION = 'mad4b_scp_optional_governance_options_v1';
    const SLUG = 'mad4b-optional-governance-options';
    const ACTION = 'mad4b_optional_governance_save';
    const LOCK = 'mad4b_scp_optional_governance_settings_lock_v1';
    private static $catalog = null;
    private static $profile_defaults = null;
    private static $protected_gates = array(
        'Owner identity and OAuth',
        'Exact operation owner approval',
        'GitHub required CI and Release Verdict',
        'Production and Breakglass authority',
        'Provider write certification',
        'Audit, rollback, recovery and kill switches',
        'Environment, runtime identity and drift checks',
    );
    private static $builtins = array(
        'developer_lane' => 'developer_lane_not_ready',
        'developer_breakglass_lane' => 'developer_breakglass_lane_not_ready',
        'machine_ingress' => 'external_machine_ingress_not_reachable',
        'machine_ingress_evidence' => 'external_machine_ingress_unbound',
    );

    public static function boot() {
        add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'save' ) );
        if ( class_exists( 'MAD4B_SCP_Admin_Route_Registry', false ) ) {
            MAD4B_SCP_Admin_Route_Registry::register( self::SLUG, 'manage_options' );
            MAD4B_SCP_Admin_Route_Registry::schedule_submenu( array( __CLASS__, 'menu' ), 33 );
        }
        add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_read_ability' ), 39 );
    }

    private static function valid_definition( $row, $external = false ) {
        if ( ! is_array( $row ) || ( $row['scope'] ?? 'operator_advisory_only' ) !== 'operator_advisory_only' ) return false;
        foreach ( array( 'id', 'reason', 'label', 'description' ) as $key )
            if ( ! isset( $row[$key] ) || ! is_string( $row[$key] ) || strlen( $row[$key] ) > 200 ) return false;
        if ( ! preg_match( '/^[a-z][a-z0-9_]{2,79}$/D', $row['id'] )
            || ! preg_match( '/^[a-z][a-z0-9_]{2,95}$/D', $row['reason'] ) ) return false;
        // Built-in reasons are fixed in source; external plugins may only add
        // optional_* diagnostics, never redefine a protected/core gate.
        if ( $external ) return 0 === strpos( $row['id'], 'optional_' )
            && 0 === strpos( $row['reason'], 'optional_' )
            && ! isset( self::$builtins[$row['id']] );
        return isset( self::$builtins[$row['id']] )
            && self::$builtins[$row['id']] === $row['reason'];
    }

    public static function catalog() {
        if ( null !== self::$catalog ) return self::$catalog;
        self::$catalog = new WP_Error( 'mad4b_optional_catalog_invalid', 'Optional governance catalog is missing or invalid.' );
        if ( ! defined( 'MAD4B_SCP_DIR' ) ) return self::$catalog;
        $file = MAD4B_SCP_DIR . self::CATALOG;
        if ( ! is_readable( $file ) || filesize( $file ) > 32768 ) return self::$catalog;
        $data = json_decode( (string) file_get_contents( $file ), true );
        if ( ! is_array( $data ) || ( $data['contract'] ?? '' ) !== self::CATALOG_CONTRACT
            || ( $data['scope'] ?? '' ) !== 'operator_advisory_only'
            || ! isset( $data['gates'] ) || ! is_array( $data['gates'] )
            || count( $data['gates'] ) > 64 ) return self::$catalog;
        $defaults = $data['operating_mode_defaults'] ?? null;
        if ( ! is_array( $defaults ) || count( $defaults ) !== 3 ) return self::$catalog;
        foreach ( array( 'ENTERPRISE_MULTI_OPERATOR', 'SINGLE_OWNER_HARDENED', 'EMERGENCY_RECOVERY' ) as $profile )
            if ( ! isset( $defaults[$profile] )
                || ! in_array( $defaults[$profile], array( 'enforced', 'advisory' ), true ) )
                return self::$catalog;
        self::$profile_defaults = $defaults;
        $result = array();
        $reasons = array();
        foreach ( $data['gates'] as $row ) {
            if ( ! self::valid_definition( $row ) || isset( $result[$row['id']] )
                || isset( $reasons[$row['reason']] ) ) return self::$catalog;
            $result[$row['id']] = $row;
            $reasons[$row['reason']] = true;
        }
        // Additional modules may register diagnostics without editing MAD4B.
        // They cannot repurpose core reasons, alter authority or disable checks.
        $extensions = function_exists( 'apply_filters' )
            ? apply_filters( 'mad4b_scp_optional_governance_definitions', array() ) : array();
        if ( is_array( $extensions ) && count( $extensions ) <= 64 ) {
            foreach ( $extensions as $row )
                if ( self::valid_definition( $row, true ) && ! isset( $result[$row['id']] )
                    && ! isset( $reasons[$row['reason']] ) ) {
                    $result[$row['id']] = $row;
                    $reasons[$row['reason']] = true;
                }
        }
        ksort( $result, SORT_STRING );
        self::$catalog = $result;
        return self::$catalog;
    }

    private static function signature( $definitions ) {
        return hash( 'sha256', (string) wp_json_encode( $definitions, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
    }

    private static function operating_mode() {
        if ( ! class_exists( 'MAD4B_SCP_Policy_Resolution', false ) )
            return new WP_Error( 'mad4b_optional_profile_unavailable', 'The authoritative operating mode is unavailable.' );
        $mode = MAD4B_SCP_Policy_Resolution::current_operating_mode();
        if ( is_wp_error( $mode ) || ! is_string( $mode ) || ! isset( self::$profile_defaults[$mode] ) )
            return new WP_Error( 'mad4b_optional_profile_invalid', 'The authoritative operating mode is invalid.' );
        return $mode;
    }

    private static function state() {
        $definitions = self::catalog();
        if ( is_wp_error( $definitions ) ) return $definitions;
        $operating_mode = self::operating_mode();
        if ( is_wp_error( $operating_mode ) ) return $operating_mode;
        $raw = function_exists( 'get_option' ) ? get_option( self::OPTION, array() ) : array();
        if ( ! is_array( $raw ) || ( isset( $raw['contract'] ) && $raw['contract'] !== self::CONTRACT ) )
            return new WP_Error( 'mad4b_optional_options_invalid', 'Optional governance configuration is malformed.' );
        $revision = isset( $raw['revision'] ) ? $raw['revision'] : 0;
        if ( ! is_int( $revision ) || $revision < 0 ) return new WP_Error( 'mad4b_optional_revision_invalid', 'Policy revision is invalid.' );
        $saved = isset( $raw['modes'] ) ? $raw['modes'] : array();
        if ( ! is_array( $saved ) ) return new WP_Error( 'mad4b_optional_modes_invalid', 'Saved modes are invalid.' );
        $modes = array();
        $effective_modes = array();
        foreach ( $definitions as $id => $row ) {
            $mode = isset( $saved[$id] ) ? $saved[$id] : 'inherit';
            if ( ! in_array( $mode, array( 'inherit', 'enforced', 'advisory', 'off' ), true ) )
                return new WP_Error( 'mad4b_optional_mode_invalid', 'An optional setting is invalid.' );
            $modes[$id] = $mode;
            $effective_modes[$id] = 'inherit' === $mode ? self::$profile_defaults[$operating_mode] : $mode;
        }
        return array( 'revision' => $revision, 'modes' => $modes, 'effective_modes' => $effective_modes,
            'operating_mode' => $operating_mode,
            'definitions_sha256' => self::signature( array( 'definitions' => $definitions,
                'profile_defaults' => self::$profile_defaults ) ) );
    }

    public static function status() {
        $definitions = self::catalog();
        $state = self::state();
        if ( is_wp_error( $definitions ) || is_wp_error( $state ) ) return array(
            'contract' => self::CONTRACT, 'valid' => false,
            'state' => 'STRICT_DEFAULT_ON_CONFIGURATION_ERROR',
            'permission_created' => false, 'mutation_performed' => false );
        $rows = array();
        foreach ( $definitions as $id => $row )
            $rows[] = array( 'id' => $id, 'reason' => $row['reason'],
                'label' => $row['label'], 'configured_mode' => $state['modes'][$id],
                'mode' => $state['effective_modes'][$id],
                'scope' => 'operator_advisory_only' );
        return array( 'contract' => self::CONTRACT, 'valid' => true,
            'revision' => $state['revision'], 'definitions_sha256' => $state['definitions_sha256'],
            'operating_mode' => $state['operating_mode'], 'profile_defaults' => self::$profile_defaults,
            'gates' => $rows, 'locked_security_gates' => self::$protected_gates, 'settings_only' => true, 'governed_authorization_modified' => false,
            'production_authorized' => false, 'permission_created' => false,
            'mutation_performed' => false );
    }

    /** Pure UI health severity reducer. Never receives or modifies authority facts. */
    public static function project( array $degraded, array $signals ) {
        $original = array_values( array_unique( $degraded ) );
        $definitions = self::catalog();
        $state = self::state();
        $result = array( 'degraded' => $original, 'raw_degraded' => $original,
            'advisory' => array(), 'muted' => array(), 'active_policy' => false );
        if ( is_wp_error( $definitions ) || is_wp_error( $state ) ) return $result;
        // Extension observations cannot overwrite any core signal and apply
        // only to nonauthorizing namespaced diagnostics.
        $observed = function_exists( 'apply_filters' )
            ? apply_filters( 'mad4b_scp_optional_governance_observations', array() ) : array();
        foreach ( $definitions as $id => $row ) {
            $reason = $row['reason'];
            if ( 0 === strpos( $reason, 'optional_' ) && is_array( $observed )
                && array_key_exists( $reason, $observed ) && false === $observed[$reason]
                && ! in_array( $reason, $result['degraded'], true ) ) {
                $result['degraded'][] = $reason;
                $result['raw_degraded'][] = $reason;
            }
            if ( ! in_array( $reason, $result['degraded'], true ) ) continue;
            $mode = $state['effective_modes'][$id];
            if ( 'enforced' === $mode ) continue;
            $result['degraded'] = array_values( array_diff( $result['degraded'], array( $reason ) ) );
            if ( 'advisory' === $mode ) $result['advisory'][] = $reason;
            else $result['muted'][] = $reason;
            $result['active_policy'] = true;
        }
        return $result;
    }

    public static function register_read_ability() {
        if ( ! function_exists( 'wp_register_ability' ) ) return;
        if ( function_exists( 'wp_has_ability' ) && wp_has_ability( 'mad4b/optional-governance-status' ) ) return;
        wp_register_ability( 'mad4b/optional-governance-status', array(
            'label' => 'Optional Governance Options Status',
            'description' => 'Read-only advisory policy modes; no authority or release gate changes.',
            'category' => 'mad4b-read', 'execute_callback' => array( __CLASS__, 'status' ),
            'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
            'input_schema' => array( 'type' => 'object', 'additionalProperties' => false ),
            'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
            'meta' => array( 'public' => false, 'show_in_rest' => false,
                'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ),
                'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ) ),
        ) );
    }

    public static function menu() {
        if ( ! class_exists( 'MAD4B_SCP_Admin_UI', false ) ) return;
        add_submenu_page( MAD4B_SCP_Admin_UI::PAGE_SLUG, 'Optional Governance',
            'Optional Governance', 'manage_options', self::SLUG, array( __CLASS__, 'page' ) );
    }

    public static function page() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Administrator required.' );
        $definitions = self::catalog();
        $state = self::state();
        echo '<div class="wrap"><h1>Options Control — Optional Governance</h1>';
        echo '<p>These settings affect operator advisory status only. They never change GitHub CI, authorization, grants, certification, audit, rollback or Production protection.</p>';
        if ( is_wp_error( $definitions ) || is_wp_error( $state ) ) {
            echo '<div class="notice notice-error"><p>Invalid configuration. Strict defaults remain active.</p></div></div>';
            return;
        }
        if ( isset( $_GET['saved'] ) && '1' === $_GET['saved'] ) echo '<div class="notice notice-success"><p>Advisory options saved and audited.</p></div>';
        echo '<p><strong>Operating profile:</strong> ' . esc_html( $state['operating_mode'] ) .
            '. Inherited optional warning mode: ' . esc_html( self::$profile_defaults[$state['operating_mode']] ) . '.</p>';
        echo '<p>Owner intent does not replace exact-target approval. The controls below change only advisory severity.</p>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( self::ACTION );
        echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
        echo '<input type="hidden" name="revision" value="' . esc_attr( (string) $state['revision'] ) . '">';
        echo '<input type="hidden" name="definitions_sha256" value="' . esc_attr( $state['definitions_sha256'] ) . '">';
        echo '<table class="widefat striped"><thead><tr><th>Policy</th><th>Mode</th><th>Scope</th></tr></thead><tbody>';
        foreach ( $definitions as $id => $row ) {
            echo '<tr><td><strong>' . esc_html( $row['label'] ) . '</strong><p>' . esc_html( $row['description'] ) . '</p></td><td>';
            echo '<select name="modes[' . esc_attr( $id ) . ']">';
            foreach ( array( 'inherit'=>'Inherit operating profile', 'enforced'=>'Enforced warning', 'advisory'=>'Optional notice', 'off'=>'Disabled notice' ) as $key => $label )
                echo '<option value="' . esc_attr( $key ) . '"' . selected( $state['modes'][$id], $key, false ) . '>' . esc_html( $label ) . '</option>';
            echo '</select> <small>Effective: ' . esc_html( $state['effective_modes'][$id] ) . '</small></td><td>Operator status only</td></tr>';
        }
        echo '</tbody></table><h2>Mandatory protection gates (read-only)</h2><ul>';
        foreach ( self::$protected_gates as $locked )
            echo '<li>' . esc_html( $locked ) . ' — mandatory</li>';
        echo '</ul>';
        submit_button( 'Save advisory preferences' );
        echo '</form></div>';
    }


    /**
     * Shared ordinary-admin and remotely approved preference persistence.
     * A caller must have the current operator capability; the remote Ability
     * separately requires exact-ticket, OAuth step-up and Staging authority.
     * Preferences never change mutation authorization or provider certification.
     */
    public static function persist_modes( array $modes, $revision, $fingerprint ) {
        if ( ! current_user_can( 'manage_options' ) )
            return new WP_Error( 'mad4b_optional_actor_denied', 'An administrator must save advisory options.' );
        $definitions = self::catalog();
        $before = self::state();
        if ( is_wp_error( $definitions ) || is_wp_error( $before ) )
            return new WP_Error( 'mad4b_optional_config_invalid', 'Optional policy configuration cannot be verified.' );
        if ( ! is_int( $revision ) || $revision !== $before['revision'] ||
            ! is_string( $fingerprint ) || ! hash_equals( $before['definitions_sha256'], $fingerprint ) )
            return new WP_Error( 'mad4b_optional_plan_stale', 'Options changed. Refresh the plan.' );
        if ( count( $modes ) !== count( $definitions ) ||
            array_diff( array_keys( $modes ), array_keys( $definitions ) ) )
            return new WP_Error( 'mad4b_optional_policy_ids_invalid', 'Unexpected advisory policy identifiers.' );
        foreach ( $modes as $mode )
            if ( ! is_string( $mode ) || ! in_array( $mode, array( 'inherit', 'enforced', 'advisory', 'off' ), true ) )
                return new WP_Error( 'mad4b_optional_mode_invalid', 'Unsupported advisory policy mode.' );
        if ( ! class_exists( 'MAD4B_SCP_Audit', false ) )
            return new WP_Error( 'mad4b_optional_audit_missing', 'Audit storage is unavailable.' );
        if ( ! class_exists( 'MAD4B_SCP_Managed_Skills_Lease', false ) )
            require_once __DIR__ . '/class-mad4b-scp-managed-skills-lease.php';
        $token = MAD4B_SCP_Managed_Skills_Lease::acquire( self::LOCK, 120 );
        if ( is_wp_error( $token ) ) return $token;
        $failure = '';
        $prior_loaded = false;
        $next = null;
        try {
            $current = self::state();
            if ( is_wp_error( $current ) || $current['revision'] !== $revision
                || ! hash_equals( $current['definitions_sha256'], $fingerprint ) )
                throw new RuntimeException( 'Optional policy changed under lock. Review a fresh plan.' );
            $old_raw = get_option( self::OPTION, null );
            $prior_loaded = true;
            $event = array( 'previous_revision' => $revision, 'next_revision' => $revision + 1,
                'definitions_sha256' => $fingerprint, 'modes_sha256' => self::signature( $modes ),
                'scope' => 'operator_advisory_only' );
            $requested = MAD4B_SCP_Audit::record( 'mad4b/optional-governance-requested', $event, 'ok' );
            if ( is_wp_error( $requested ) )
                throw new RuntimeException( 'Requested-option audit failed. Settings were not applied.' );
            $next = array( 'contract' => self::CONTRACT,
                'revision' => $revision + 1, 'modes' => $modes );
            update_option( self::OPTION, $next, false );
            if ( get_option( self::OPTION, null ) !== $next )
                throw new RuntimeException( 'Optional settings failed persisted readback.' );
            $applied = MAD4B_SCP_Audit::record( 'mad4b/optional-governance-applied', $event, 'ok' );
            if ( is_wp_error( $applied ) )
                throw new RuntimeException( 'Applied policy audit failed; attempting preference rollback.' );
        } catch ( Throwable $e ) {
            $failure = $e->getMessage();
            if ( $prior_loaded ) {
                if ( null === $old_raw ) delete_option( self::OPTION );
                else update_option( self::OPTION, $old_raw, false );
                if ( get_option( self::OPTION, null ) !== $old_raw )
                    $failure = 'Policy rollback could not be verified; investigate operator settings and the audit log.';
            }
        } finally {
            MAD4B_SCP_Managed_Skills_Lease::release( self::LOCK, $token );
        }
        if ( '' !== $failure )
            return new WP_Error( 'mad4b_optional_persist_failed', $failure );
        return array( 'contract' => self::CONTRACT . '.receipt.v1',
            'revision' => $revision + 1, 'policy_snapshot_sha256' => self::signature( $modes ),
            'readback_verified' => true, 'audit_recorded' => true,
            'advisory_preferences_saved' => true, 'authority_created' => false,
            'production_authorized' => false );
    }

    public static function save() {
        if ( ! current_user_can( 'manage_options' ) )
            wp_die( 'Administrator required.', '', array( 'response' => 403 ) );
        check_admin_referer( self::ACTION );
        $definitions = self::catalog();
        $before = self::state();
        if ( is_wp_error( $definitions ) || is_wp_error( $before ) )
            wp_die( 'Invalid optional governance policy configuration.' );
        $revision = isset( $_POST['revision'] ) ? (int) $_POST['revision'] : -1;
        $fingerprint = isset( $_POST['definitions_sha256'] )
            ? (string) wp_unslash( $_POST['definitions_sha256'] ) : '';
        if ( $revision !== $before['revision']
            || ! hash_equals( $before['definitions_sha256'], $fingerprint ) )
            wp_die( 'Options changed. Refresh the page before saving.' );
        $posted = isset( $_POST['modes'] ) && is_array( $_POST['modes'] )
            ? wp_unslash( $_POST['modes'] ) : array();
        if ( array_diff( array_keys( $posted ), array_keys( $definitions ) )
            || count( $posted ) !== count( $definitions ) )
            wp_die( 'Unexpected optional policy settings.' );
        $modes = array();
        foreach ( $definitions as $id => $row ) {
            $mode = $posted[$id] ?? null;
            if ( ! in_array( $mode, array( 'inherit', 'enforced', 'advisory', 'off' ), true ) )
                wp_die( 'Invalid optional policy mode.' );
            $modes[$id] = $mode;
        }
        $outcome = self::persist_modes( $modes, $revision, $fingerprint );
        if ( is_wp_error( $outcome ) ) wp_die( $outcome->get_error_message() );
        wp_safe_redirect( add_query_arg( 'saved', '1', admin_url( 'admin.php?page=' . self::SLUG ) ) );
        exit;
    }
}
