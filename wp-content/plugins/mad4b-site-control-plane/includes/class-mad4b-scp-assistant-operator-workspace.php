<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Read-only bounded WP admin overview. Never changes governance or queues jobs. */
final class MAD4B_SCP_Assistant_Operator_Workspace {
    const SLUG = 'mad4b-assistant-workspace';
    private static $booted = false;

    public static function boot() {
        if ( self::$booted || ! function_exists( 'add_action' ) ) return;
        self::$booted = true;
        add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 100 );
    }

    public static function register_menu() {
        if ( ! function_exists( 'add_submenu_page' ) ) return;
        add_submenu_page( 'mad4b-control-plane',
            'MAD4B Assistant Review', 'Assistant Review', 'manage_options',
            self::SLUG, array( __CLASS__, 'render' ) );
    }

    public static function snapshot() {
        if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
            return new WP_Error( 'mad4b_assistant_workspace_forbidden',
                'Administrator permission is required.' );
        }
        $bootstrap = class_exists( 'MAD4B_SCP_Assistant_Bootstrap_Diagnostic', false )
            ? MAD4B_SCP_Assistant_Bootstrap_Diagnostic::status() : null;
        $blockers = array(); $environment = 'unknown';
        $previewReady = false;
        if ( is_wp_error( $bootstrap ) ) {
            $blockers[] = 'bootstrap_read_unavailable';
        } elseif ( is_array( $bootstrap ) ) {
            $environment = in_array( $bootstrap['environment'] ?? '',
                array( 'staging', 'development', 'local', 'production' ), true )
                ? $bootstrap['environment'] : 'unknown';
            $previewReady = ! empty( $bootstrap['preview_eligible'] );
            foreach ( $bootstrap['blockers'] ?? array() as $b ) {
                if ( is_string( $b ) && preg_match( '/^[a-z0-9_]{3,96}$/D', $b )
                    && count( $blockers ) < 12 ) $blockers[] = $b;
            }
        } else {
            $blockers[] = 'bootstrap_read_unavailable';
        }
        $queues = array();
        foreach ( MAD4B_SCP_Assistant_Read_Work_Operations::OPERATIONS as $operation ) {
            $result = class_exists( 'MAD4B_SCP_Remote_Work_Queue', false )
                ? MAD4B_SCP_Remote_Work_Queue::list_jobs( $operation ) : null;
            $count = is_array( $result ) && isset( $result['count'] )
                && is_numeric( $result['count'] ) ? min( 100, max( 0, (int) $result['count'] ) ) : null;
            $queues[ $operation ] = $count;
        }
        return array(
            'contract' => 'mad4b.assistant-operator-workspace.v1',
            'environment' => $environment,
            'preview_eligible' => $previewReady,
            'blockers' => array_values( array_unique( $blockers ) ),
            'read_work_counts' => $queues,
            'catalog_trust' => 'NOT_INDEPENDENTLY_CERTIFIED',
            'task_execution_ready' => false,
            'provider_install_allowed' => false,
            'production_mutation_allowed' => false,
            'read_only' => true, 'mutation_performed' => false,
        );
    }

    public static function render() {
        $status = self::snapshot();
        if ( is_wp_error( $status ) ) {
            if ( function_exists( 'wp_die' ) ) wp_die( esc_html( $status->get_error_code() ) );
            return;
        }
        echo '<div class="wrap"><h1>Assistant Review</h1>';
        echo '<p>This is a read-only status view. No plugin installation, grants, model spending or Production writes are authorized here.</p>';
        echo '<table class="widefat striped"><tbody>';
        $display = array(
            'Environment' => $status['environment'],
            'Read-only preview' => $status['preview_eligible'] ? 'Eligible' : 'Blocked',
            'External provider certification' => 'Not verified',
            'Execution authority' => 'Not granted',
            'Provider installation' => 'Not authorized',
        );
        foreach ( $display as $label => $value ) {
            echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
        }
        echo '</tbody></table><h2>Current blockers</h2><ul>';
        if ( ! $status['blockers'] ) echo '<li>None reported by the read diagnostic; independent certification remains required.</li>';
        foreach ( $status['blockers'] as $reason ) echo '<li><code>' . esc_html( $reason ) . '</code></li>';
        echo '</ul><h2>Read-only work queue</h2><table class="widefat striped"><thead><tr><th>Operation</th><th>Queued jobs</th></tr></thead><tbody>';
        foreach ( $status['read_work_counts'] as $name => $count ) {
            echo '<tr><th scope="row">' . esc_html( $name ) . '</th><td>'
                . esc_html( null === $count ? 'Unavailable' : (string) $count ) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
}
