<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Exact-source Staging UI. Preview is a short-lived, nonauthorizing observation.
 * Installation delegates to the existing certified selected-head executor.
 * No fetch, installer, or new Cron schedule occurs during normal wp-admin GET.
 */
final class MAD4B_SCP_Staging_Selected_Head_Admin {
    const PREVIEW_ACTION = 'mad4b_staging_source_preview';
    const APPLY_ACTION = 'mad4b_staging_source_apply';
    const MAX_PREVIEW_AGE = 600;
    const REPO = 'mad4bdigital-ai/WordPress';

    public static function boot() {
        add_action( 'admin_post_' . self::PREVIEW_ACTION, array( __CLASS__, 'handle_preview' ) );
        add_action( 'admin_post_' . self::APPLY_ACTION, array( __CLASS__, 'handle_apply' ) );
    }

    public static function visible_for_site( array $site, $wp_environment ) {
        return 'staging' === $wp_environment
            && 'staging' === (string) ( $site['environment'] ?? '' )
            && 'staging' === (string) ( $site['configured_environment'] ?? '' );
    }

    private static function site() {
        if ( ! function_exists( 'wp_get_environment_type' )
            || ! class_exists( 'MAD4B_SCP_Site_Profile' ) ) return array();
        $site = MAD4B_SCP_Site_Profile::status();
        return is_array( $site ) && self::visible_for_site( $site, wp_get_environment_type() )
            ? $site : array();
    }

    private static function key() {
        return 'mad4b_staging_source_preview_' . (int) get_current_user_id();
    }

    public static function input_from_form( array $input ) {
        $type = isset( $input['source_type'] ) && is_string( $input['source_type'] )
            ? $input['source_type'] : '';
        $branch = isset( $input['branch'] ) && is_string( $input['branch'] )
            ? trim( $input['branch'] ) : '';
        $commit = isset( $input['commit'] ) && is_string( $input['commit'] )
            ? trim( $input['commit'] ) : '';
        $reference = isset( $input['reference'] ) && is_string( $input['reference'] )
            ? trim( $input['reference'] ) : '';
        if ( 'branch' === $type ) $reference = $branch;
        elseif ( 'branch_commit' === $type ) $reference = $branch . '@' . $commit;
        elseif ( 'commit' === $type ) $reference = $commit;
        elseif ( 'pull_request' !== $type ) return new WP_Error( 'mad4b_staging_selection_type_invalid', 'Unsupported selection type.' );
        if ( ! class_exists( 'MAD4B_SCP_Staging_Source_Selector', false ) )
            require_once __DIR__ . '/class-mad4b-scp-staging-source-selector.php';
        return MAD4B_SCP_Staging_Source_Selector::normalize( array(
            'repository' => self::REPO, 'type' => $type, 'reference' => $reference
        ) );
    }

    private static function redirect() {
        wp_safe_redirect( admin_url( 'admin.php?page=mad4b-runtime-components&tab=maintenance#mad4b-selected-head' ) );
        exit;
    }

    private static function require_admin( $action ) {
        if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' )
            || ! current_user_can( 'manage_options' ) || ! self::site() )
            wp_die( 'Staging administrator required.', '', array( 'response' => 403 ) );
        check_admin_referer( $action );
    }

    private static function save( array $row ) {
        $row['observed_at'] = time();
        set_transient( self::key(), $row, self::MAX_PREVIEW_AGE );
    }

    private static function current_record() {
        $record = get_transient( self::key() );
        $site = self::site();
        return is_array( $record ) && $site
            && ( time() - (int) ( $record['observed_at'] ?? 0 ) ) <= self::MAX_PREVIEW_AGE
            && hash_equals( (string) ( $site['profile_digest'] ?? '' ), (string) ( $record['profile_digest'] ?? '' ) )
            ? $record : array();
    }

    public static function handle_preview() {
        self::require_admin( self::PREVIEW_ACTION );
        $raw = array();
        foreach ( array( 'source_type', 'branch', 'commit', 'reference' ) as $key ) {
            $v = isset( $_POST[$key] ) && is_string( $_POST[$key] )
                ? wp_unslash( $_POST[$key] ) : '';
            $raw[$key] = strlen( $v ) < 250 ? $v : '';
        }
        $source = self::input_from_form( $raw );
        $reason = isset( $_POST['reason'] ) && is_string( $_POST['reason'] )
            ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '';
        if ( strlen( $reason ) < 3 || strlen( $reason ) > 500 )
            $source = new WP_Error( 'mad4b_staging_selection_reason_invalid', 'A bounded reason is required.' );
        $site = self::site();
        $record = array( 'profile_digest' => (string) ( $site['profile_digest'] ?? '' ),
            'eligible' => false, 'blockers' => array(), 'source' => array(),
            'plan_sha256' => '', 'resolved_sha' => '', 'target_version' => '',
            'reason' => $reason, 'status' => 'NEEDS_REVIEW', 'mutation_performed' => false );
        if ( is_wp_error( $source ) ) {
            $record['blockers'][] = $source->get_error_code();
        } else {
            $plan = MAD4B_SCP_Selected_Head_Update::plan( array(
                'candidate_source' => $source, 'reason' => $reason ) );
            if ( is_wp_error( $plan ) ) {
                $record['blockers'][] = $plan->get_error_code();
            } else {
                $record['source'] = $source;
                $record['resolved_sha'] = (string) ( $plan['source']['resolved_sha'] ?? '' );
                $record['target_version'] = (string) ( $plan['package_identity']['version'] ?? '' );
                $record['plan_sha256'] = (string) ( $plan['plan_sha256'] ?? '' );
                $record['eligible'] = ! empty( $plan['eligible'] );
                $record['blockers'] = is_array( $plan['blockers'] ?? null ) ? $plan['blockers'] : array();
                $record['status'] = $record['eligible'] ? 'PREVIEW_READY_NOT_AUTHORIZED' : 'BLOCKED';
            }
        }
        self::save( $record );
        self::redirect();
    }

    /**
     * The WordPress Ability is the only supported mutation entrypoint. Calling
     * the provider's PHP apply() directly would skip the ticket claim.
     */
    public static function execute_approved_candidate( array $input ) {
        if ( ! function_exists( 'wp_get_ability' ) )
            return new WP_Error( 'mad4b_staging_selected_head_abilities_unavailable',
                'Governed WordPress Abilities runtime is not available.' );
        $ability = wp_get_ability( MAD4B_SCP_Selected_Head_Update::APPLY_ABILITY );
        if ( ! is_object( $ability ) || ! is_callable( array( $ability, 'execute' ) ) )
            return new WP_Error( 'mad4b_staging_selected_head_ability_unavailable',
                'The governed selected-head Apply ability is not registered.' );
        return $ability->execute( $input );
    }

    public static function handle_apply() {
        self::require_admin( self::APPLY_ACTION );
        if ( ! current_user_can( 'update_plugins' ) )
            wp_die( 'Plugin update permission required.', '', array( 'response' => 403 ) );
        $site = self::site();
        if ( ! class_exists( 'MAD4B_SCP_Site_Profile' )
            || ! MAD4B_SCP_Site_Profile::user_is_enrolled( get_current_user_id() ) )
            wp_die( 'Exact enrolled Staging owner required.', '', array( 'response' => 403 ) );
        $record = self::current_record();
        $plan_sha = isset( $_POST['expected_plan_sha256'] ) && is_string( $_POST['expected_plan_sha256'] )
            ? (string) wp_unslash( $_POST['expected_plan_sha256'] ) : '';
        $confirm = isset( $_POST['confirmation'] ) && is_string( $_POST['confirmation'] )
            ? (string) wp_unslash( $_POST['confirmation'] ) : '';
        $ticket = isset( $_POST['_mad4b_approval_ticket_id'] ) && is_string( $_POST['_mad4b_approval_ticket_id'] )
            ? (string) wp_unslash( $_POST['_mad4b_approval_ticket_id'] ) : '';
        if ( ! $record || empty( $record['eligible'] )
            || ! preg_match( '/^[a-f0-9]{64}$/D', $plan_sha )
            || ! hash_equals( (string) $record['plan_sha256'], $plan_sha )
            || ! hash_equals( MAD4B_SCP_Selected_Head_Update::CONFIRMATION, $confirm )
            || ! class_exists( 'MAD4B_SCP_Identifiers', false )
            || 1 !== preg_match( '/' . MAD4B_SCP_Identifiers::APPROVAL_TICKET_SCHEMA_PATTERN . '/D', $ticket ) )
            wp_die( 'Exact plan and one-time owner approval ticket are required; preview again.', '', array( 'response' => 409 ) );

        // Consume the preview before the mutation boundary, preventing replay.
        delete_transient( self::key() );
        $result = self::execute_approved_candidate( array(
            '_mad4b_approval_ticket_id' => $ticket,
            'candidate_source' => $record['source'],
            'reason' => $record['reason'],
            'expected_plan_sha256' => $plan_sha,
            'confirmation' => $confirm,
        ) );
        self::save( array(
            'profile_digest' => (string) ( $site['profile_digest'] ?? '' ),
            'status' => is_wp_error( $result ) ? 'INSTALL_BLOCKED'
                : ( is_array( $result ) && ! empty( $result['applied'] )
                    ? 'APPLY_RETURNED_VERIFY_READBACK' : 'INSTALL_NOT_CONFIRMED' ),
            'blockers' => is_wp_error( $result ) ? array( $result->get_error_code() ) : array(),
            'eligible' => false, 'source' => array(),
            'apply_returned' => ! is_wp_error( $result ),
            'readback_required' => true, 'authorizing' => false,
        ) );
        self::redirect();
    }

    public static function render() {
        $site = self::site();
        if ( ! $site || ! current_user_can( 'manage_options' ) ) return;
        echo '<section id="mad4b-selected-head" class="mad4b-scp-panel"><h2>'
            . esc_html__( 'Staging only: select a branch / commit', 'mad4b-site-control-plane' ) . '</h2>';
        echo '<p>' . esc_html__( 'Preview a GitHub branch HEAD, an exact commit, an open pull-request HEAD, or a historical commit proven to belong to a branch. No package is downloaded or installed by preview.', 'mad4b-site-control-plane' ) . '</p>';
        if ( class_exists( 'MAD4B_SCP_WordPress_Native_Opt_In' ) ) {
            $opt = MAD4B_SCP_WordPress_Native_Opt_In::status();
            echo '<p>WordPress-native Staging candidate mode: <strong>'
                . esc_html( ! empty( $opt['enabled'] ) ? 'Enabled' : 'Disabled / needs owner opt-in' )
                . '</strong>. <a href="' . esc_url( admin_url( 'admin.php?page=mad4b-control-plane-site-profile' ) )
                . '">Site Profile</a></p>';
        }
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr( self::PREVIEW_ACTION ) . '">';
        wp_nonce_field( self::PREVIEW_ACTION );
        echo '<p><label>Source <select name="source_type">'
            . '<option value="branch">Branch HEAD</option>'
            . '<option value="branch_commit">Specific commit within branch</option>'
            . '<option value="commit">Exact commit SHA</option>'
            . '<option value="pull_request">Pull request HEAD</option>'
            . '</select></label></p>';
        echo '<p><label>Branch <input class="regular-text" name="branch" maxlength="120" placeholder="feature/staging-work" autocomplete="off"></label></p>';
        echo '<p><label>Commit SHA <input class="regular-text" name="commit" maxlength="40" pattern="[a-f0-9]{40}" autocomplete="off" placeholder="40-character exact commit SHA"></label></p>';
        echo '<p><label>PR number <input type="text" name="reference" maxlength="9" inputmode="numeric" placeholder="415"></label></p>';
        echo '<p><label>Reason <input class="regular-text" name="reason" maxlength="500" minlength="3" required value="Staging exact source verification"></label></p>';
        echo '<p><button type="submit" class="button button-secondary">Preview certified source and package</button></p></form>';
        $record = self::current_record();
        if ( $record ) {
            echo '<h3>Last exact-source preview</h3><p>Status: <strong>'
                . esc_html( (string) ( $record['status'] ?? 'UNKNOWN' ) ) . '</strong></p>';
            foreach ( array( 'resolved_sha' => 'Resolved commit', 'target_version' => 'Certified package version', 'plan_sha256' => 'Exact plan digest' ) as $key => $label ) {
                if ( ! empty( $record[$key] ) )
                    echo '<p>' . esc_html( $label ) . ': <code>' . esc_html( $record[$key] ) . '</code></p>';
            }
            if ( ! empty( $record['blockers'] ) ) {
                echo '<p>Blockers:</p><ul>';
                foreach ( array_slice( $record['blockers'], 0, 16 ) as $code )
                    echo '<li><code>' . esc_html( sanitize_key( (string) $code ) ) . '</code></li>';
                echo '</ul>';
            }
            if ( ! empty( $record['eligible'] ) && current_user_can( 'update_plugins' ) ) {
                echo '<p>This preview does not authorize installation. Actual execution requires current enrolled owner, OAuth step-up, exact grants, signed archive and post-install verification.</p>';
                $bearer = class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' )
                    && MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active()
                    && MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_has_scope(
                        MAD4B_SCP_OAuth_Resource_Bridge::AUTHORITY_STEP_UP_SCOPE );
                if ( ! $bearer ) {
                    echo '<div class="notice notice-warning inline"><p>'
                        . esc_html__( 'This browser does not carry the required verified owner OAuth Step-Up. Use the governed Remote MCP selected-head apply for this exact plan; wp-admin cannot schedule a privileged install through WP-Cron.', 'mad4b-site-control-plane' )
                        . '</p></div>';
                } else {
                echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
                    . '<input type="hidden" name="action" value="' . esc_attr( self::APPLY_ACTION ) . '">'
                    . '<input type="hidden" name="expected_plan_sha256" value="' . esc_attr( $record['plan_sha256'] ) . '">';
                wp_nonce_field( self::APPLY_ACTION );
                echo '<label>Exact confirmation <input class="regular-text" autocomplete="off" name="confirmation" required placeholder="'
                    . esc_attr( MAD4B_SCP_Selected_Head_Update::CONFIRMATION ) . '"></label>';
                echo '<p><label>Exact one-time owner approval ticket (UUIDv4) '
                    . '<input class="regular-text" name="_mad4b_approval_ticket_id" '
                    . 'autocomplete="off" spellcheck="false" maxlength="36" minlength="36" required></label></p>';
                echo '<p class="description">The approval must match this exact plan. WordPress Abilities will claim the ticket and enforce the existing update, backup and rollback gates.</p>';
                echo '<p><button type="submit" class="button button-primary">Request governed Staging install</button></p></form>';
                }
            }
        }
        echo '<p class="description">WP-Cron recovery handles the signed stable master channel only, never the selected commit. If the local browser does not carry the required OAuth step-up, execute the same selected-head plan via the already-authorized Remote MCP lane. No Cron mutation is scheduled from this page.</p></section>';
    }
}
