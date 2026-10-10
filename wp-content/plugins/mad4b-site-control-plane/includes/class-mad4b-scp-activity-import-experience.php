<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * IMP06 guided WordPress operator UX. View-only except for the existing,
 * independently nonced upload/approval/export/archive POST handlers.
 * Never changes the security authority, source policy or the chosen import Mode.
 */
final class MAD4B_SCP_Activity_Import_Experience {
    const CONTRACT = 'mad4b.import-experience.v1';
    private static function e( $str ) { return esc_html( (string) $str ); }
    public static function title( $s ) {
        return __( $s, 'mad4b-site-control-plane' );
    }
    public static function issue_label( $reason ) {
        $labels = array(
            'currency_not_in_approved_allowlist' => 'Currency is not allowed by this site policy. Ask the rates owner to verify it.',
            'wpml_group_missing_required_language' => 'A translation group is missing a required language.',
            'wpml_language_not_allowed' => 'A row uses a language that is not approved for this site.',
            'price_tier_order_requires_commercial_review' => 'Price levels require commercial review. They will not be changed automatically.',
            'historical_rate_period_requires_review' => 'The price period is historical. Confirm whether archival import is intended.',
            'price_not_approved_decimal' => 'Price is not a valid nonnegative decimal with the approved precision.',
            'required_relationship_unresolved' => 'A required linked entity is missing from the source row.',
            'duplicate_identity' => 'The source includes repeated record identifiers.',
            'status_not_approved' => 'Publication status is not approved. Fix the source rather than publishing silently.',
        );
        return isset( $labels[ $reason ] ) ? $labels[ $reason ] :
            str_replace( '_', ' ', (string) $reason );
    }
    public static function mode_label( $id ) {
        $labels = array(
            'admin_csv_upload' => 'Upload a CSV file here',
            'admin_xlsx_convert' => 'Upload an Excel XLSX file here',
            'admin_xlsx_convert' => 'Convert Excel to CSV',
            'google_apps_script' => 'Google Sheets via Apps Script',
            'signed_generic_webhook' => 'Make / n8n / Zapier / signed webhook',
            'google_sheets_oauth' => 'Managed Google Sheets connection',
            'google_drive_file' => 'Google Drive file',
            'wp_all_import_wizard' => 'Use WP All Import wizard',
            'wp_all_import_manual_rerun' => 'Repeat an existing WP All Import job',
            'wp_all_import_cron' => 'WP All Import scheduled job',
            'wp_all_import_wpcli' => 'WP All Import WP-CLI job',
            'sftp_ftp_pull' => 'SFTP / FTP source',
            'https_csv_pull' => 'Remote HTTPS CSV file',
            'object_storage' => 'Cloud object storage',
            'wp_media_csv' => 'WordPress Media Library CSV',
        );
        return isset( $labels[ $id ] ) ? $labels[ $id ] :
            ucwords( str_replace( '_', ' ', (string) $id ) );
    }
    public static function requirement_label( $requirement ) {
        $labels = array(
            'admin' => 'Sign in as a WordPress site administrator',
            'staging' => 'Use an enrolled Staging site',
            'approved_profile' => 'Configure and approve the Import Contract for this Profile',
            'encrypted_snapshot' => 'Configure the host-managed encrypted snapshot key',
            'site_secret' => 'Configure a separate host-managed key for this source',
            'nonce' => 'Use a fresh signed one-time request ID',
            'timestamp' => 'Keep the request timestamp within the permitted window',
            'exact_json' => 'Sign the exact JSON request bytes',
            'plugin_installed' => 'Install and activate the compatible provider plugin',
            'exact_import_id' => 'Identify the existing configured import job',
            'staging_approval' => 'Review and approve the exact source snapshot on Staging',
            'controlled_parser' => 'Host administrator must approve an installed PhpSpreadsheet converter for this site',
            'zip_limits' => 'Use a simple ZIP-bounded XLSX file under 1 MiB and 8 MiB expanded',
            'no_formulas' => 'Replace spreadsheet formulas with literal values before upload',
            'single_sheet' => 'Use only one worksheet per review',
        );
        return isset( $labels[ $requirement ] ) ? $labels[ $requirement ] :
            ucwords( str_replace( '_', ' ', (string) $requirement ) );
    }
    public static function error_guidance( $code ) {
        $map = array(
            'mad4b_import_review_pending' => 'There is already a review waiting. Open it, resolve or archive it before submitting another source.',
            'mad4b_import_site_validation_not_configured' => 'Configure required fields, currencies and mappings in this Profile before uploading.',
            'mad4b_import_data_key_unavailable' => 'Ask the site administrator to configure the encrypted snapshot key on Staging.',
            'mad4b_import_snapshot_staging_only' => 'Select an enrolled Staging site. Live imports are not enabled here.',
            'mad4b_import_identity_registry_not_configured' => 'Choose the approved WordPress Meta key that stores external record identities.',
            'mad4b_import_snapshot_authority_drift' => 'Site settings changed. Re-review the source under the new Profile revision.',
            'mad4b_import_snapshot_stale' => 'This source review is no longer current. Refresh and inspect the latest review.',
            'mad4b_import_approval_blocked' => 'Correct every blocking issue in the original source and upload a new review.',
            'mad4b_import_legacy_preview_untrusted' => 'An older unencrypted review cannot be approved. Archive it and re-upload safely.',
            'mad4b_ui_csv_upload_invalid' => 'Choose a genuine .csv file under 1 MiB. Large sheets need a separate bounded conversion or batch adapter.',
            'mad4b_ui_csv_open_failed' => 'The uploaded file cannot be read. Export a fresh CSV and retry.',
            'mad4b_ui_csv_header_invalid' => 'The CSV headers are empty, malformed or exceed 80 columns.',
            'mad4b_ui_csv_rows_invalid' => 'A row has the wrong number of columns, or the file exceeds 500 records. Fix the CSV structure before retrying.',
            'mad4b_ui_csv_unsafe_value' => 'An unsafe spreadsheet formula, control character or oversized cell was found. Export literal values rather than formulas.',
            'mad4b_import_required_columns_missing' => 'The CSV is missing site-required columns or approved mappings. Compare its headers with the Profile validation contract.',
            'mad4b_import_formula_or_control_denied' => 'The source contains a formula or forbidden control data. Export literal values.',
            'mad4b_import_review_pending' => 'There is an earlier review. Open its issues, complete or explicitly archive it before uploading another source.',
            'mad4b_import_approval_blocked' => 'Blocking issues remain. Correct source values and stage a new snapshot before approving.',
            'mad4b_import_snapshot_readback_failed' => 'The source was not independently confirmed in encrypted storage. Contact the site administrator; no import was started.',
            'mad4b_import_snapshot_encrypt' => 'The server could not encrypt the source. Ask the host administrator to check encrypted storage.',
            'mad4b_import_snapshot_size' => 'The source exceeds the secure review size limit. Use a separately certified batched mode.',
            'mad4b_import_warning_ack_confirmation_missing' => 'Check the acknowledgement box before approving the full business warning count.',
            'mad4b_import_warning_acknowledgement_mismatch' => 'The business warning totals changed. Refresh the source review and confirm the new exact count.',
            'mad4b_xlsx_parser_unavailable' => 'This site has no approved XLSX parser or encryption key. Ask the administrator to configure PhpSpreadsheet, or upload CSV instead.',
            'mad4b_xlsx_upload_invalid' => 'Choose a genuine .xlsx file no larger than 1 MiB. Old .xls or macro-enabled workbooks are not supported.',
            'mad4b_xlsx_zip_invalid' => 'The uploaded Excel file cannot be opened safely. Export it again as XLSX or CSV.',
            'mad4b_xlsx_zip_unsafe' => 'Excel contains unsafe/external components or exceeds decompressed size limits. Use a simple one-sheet literal source.',
            'mad4b_xlsx_single_sheet_required' => 'Export the relevant sheet separately: only one sheet is reviewed at a time.',
            'mad4b_xlsx_dimensions_invalid' => 'Excel must contain 1–500 data rows and no more than 80 columns.',
            'mad4b_xlsx_formula_denied' => 'Excel formulas cannot be evaluated or imported automatically. Replace formulas with approved literal values.',
            'mad4b_xlsx_parse_failed' => 'The installed XLSX reader could not read this workbook safely. Export a literal CSV as fallback.',
            'mad4b_xlsx_cell_type_invalid' => 'Excel contains unsupported cell structures. Convert to literal values.',
            'mad4b_xlsx_cell_oversized' => 'An Excel cell exceeds the allowed 4096 characters.',
            'mad4b_xlsx_no_data' => 'Excel workbook has no reviewable data rows.',
        );
        return isset( $map[ $code ] ) ? $map[ $code ] :
            'Read the diagnostic, check the site configuration and retry only after the underlying issue is resolved.';
    }
    /**
     * Pure (array-in → array-out) operator journey: independently testable
     * without WordPress, real credentials, a plugin installation or a browser.
     */
    public static function journey( $profiles, $slug, $catalog, $review, $requested_mode = '', $step = 1 ) {
        $profiles = is_array( $profiles ) ? $profiles : array();
        $available = array();
        foreach ( $profiles as $p ) {
            if ( ! is_array( $p ) || empty( $p['enabled'] ) || empty( $p['slug'] ) ) continue;
            $available[ (string) $p['slug'] ] = $p;
        }
        $selected = isset( $available[ $slug ] ) ? $available[ $slug ] : null;
        $has_policy = $selected && ! empty( $selected['has_import_contract'] );
        $allowed = $has_policy && ! empty( $selected['enabled_modes'] ) ?
            array_values( $selected['enabled_modes'] ) : array();
        $catalog_modes = ! is_wp_error( $catalog ) && is_array( $catalog ) &&
            isset( $catalog['modes'] ) && is_array( $catalog['modes'] ) ?
            $catalog['modes'] : array();
        $modes = array();
        foreach ( $catalog_modes as $mode ) {
            if ( ! is_array( $mode ) || empty( $mode['id'] ) ||
                ! in_array( $mode['id'], $allowed, true ) ) continue;
            $modes[ $mode['id'] ] = array(
                'id' => $mode['id'], 'label' => self::mode_label( $mode['id'] ),
                'ready_for_review' => ! empty( $mode['detected'] ) &&
                    ! empty( $mode['review_intake_implemented'] ),
                'state' => (string) $mode['state'],
                'setup' => isset( $mode['requirements'] ) &&
                    is_array( $mode['requirements'] ) ? $mode['requirements'] : array()
            );
        }
        $requested_mode_invalid = '' !== $requested_mode &&
            ! isset( $modes[ $requested_mode ] );
        $mode_id = isset( $modes[ $requested_mode ] ) ? $requested_mode : '';
        if ( '' === $mode_id && !$requested_mode_invalid && $has_policy &&
            isset( $modes[ $selected['preferred_mode'] ] ) )
            $mode_id = $selected['preferred_mode'];
        if ( '' === $mode_id && !$requested_mode_invalid &&
            isset( $modes['admin_csv_upload'] ) )
            $mode_id = 'admin_csv_upload';
        $review_unavailable = is_wp_error( $review ) ||
            ( is_array( $review ) && isset( $review['state'] ) &&
                ! in_array( $review['state'], array( 'no_staged_feed', 'requires_review' ), true ) );
        $active_review = is_array( $review ) && isset( $review['snapshot_sha256'] ) &&
            isset( $review['plan'] ) && is_array( $review['plan'] );
        $blocks = $active_review && isset( $review['plan']['block_issue_count'] ) ?
            (int) $review['plan']['block_issue_count'] : 0;
        $warnings = $active_review && isset( $review['plan']['review_issue_count'] ) ?
            (int) $review['plan']['review_issue_count'] : 0;
        $total = $active_review && isset( $review['plan']['issue_count_observed'] ) ?
            (int) $review['plan']['issue_count_observed'] : 0;
        $truncated = $active_review && ! empty( $review['plan']['issues_truncated'] );
        // Pagination is a presentation detail. Every nonblocking warning is
        // counted and explicitly acknowledged before manual-only approval.
        $approval_candidate = $active_review && 0 === $blocks;
        $step = is_int( $step ) ? max( 1, min( 4, $step ) ) : 1;
        if ( !$selected || !$has_policy || !$allowed ) $step = 1;
        if ( !$active_review && $step > 2 ) $step = 2;
        return array(
            'contract' => self::CONTRACT, 'profiles' => array_values( $available ),
            'selected_profile' => $selected, 'has_import_policy' => (bool) $has_policy,
            'available_modes' => array_values( $modes ),
            'selected_mode_id' => $mode_id,
            'requested_mode_invalid' => $requested_mode_invalid,
            'step' => $step,
            'review_pending' => $active_review,
            'review_unavailable' => $review_unavailable,
            'can_upload_new' => $has_policy && ! $active_review &&
                ! $review_unavailable &&
                isset( $modes['admin_csv_upload'] ) &&
                $modes['admin_csv_upload']['ready_for_review'],
            'blocking_issue_count' => $blocks, 'review_issue_count' => $warnings,
            'issue_count_total' => $total, 'issue_list_truncated' => $truncated,
            'approval_candidate' => $approval_candidate,
            'can_start_import' => false,
            'never_auto_execute_or_publish' => true, 'read_only' => true
        );
    }
    /** Nonsecret read-only plan for conversation clients and admin UI parity. */
    public static function plan( $input = array() ) {
        if ( ! current_user_can( 'manage_options' ) || ! is_array( $input ) )
            return new WP_Error( 'mad4b_import_experience_denied',
                'Only enrolled site administrators may inspect import UX.' );
        $slug = isset( $input['profile_slug'] ) ?
            sanitize_key( (string) $input['profile_slug'] ) : '';
        $mode_id = isset( $input['mode_id'] ) ?
            sanitize_key( (string) $input['mode_id'] ) : '';
        $step = isset( $input['wizard_step'] ) ?
            (int) $input['wizard_step'] : 1;
        $catalog = MAD4B_SCP_Activity_Import_Modes::catalog();
        if ( is_wp_error( $catalog ) ) return $catalog;
        // Match the native UI's profile-scoped source readiness.
        foreach ( $catalog['modes'] as &$mode ) {
            if ( !$slug || empty( $mode['review_intake_implemented'] ) ) continue;
            $ready = MAD4B_SCP_Activity_Import_Modes::plan( array(
                'profile_slug' => $slug, 'mode_id' => $mode['id'] ) );
            $mode['detected'] = ! is_wp_error( $ready ) &&
                ! empty( $ready['eligible_for_staging_review'] );
        }
        unset( $mode );
        $review = $slug ? MAD4B_SCP_Activity_Import_Review::review(
            array( 'profile_slug' => $slug ) ) : array();
        $view = self::journey( self::profiles(), $slug, $catalog, $review,
            $mode_id, $step );
        $action = 'select_profile';
        if ( $view['selected_profile'] && ! $view['has_import_policy'] )
            $action = 'configure_site_owned_import_contract';
        elseif ( $view['selected_profile'] && !$view['available_modes'] )
            $action = 'enable_approved_import_mode';
        elseif ( $view['review_unavailable'] )
            $action = 'repair_existing_review_before_new_upload';
        elseif ( $view['review_pending'] && $view['blocking_issue_count'] )
            $action = 'resolve_source_blockers_and_stage_new_review';
        elseif ( $view['review_pending'] && $view['approval_candidate'] )
            $action = 'review_exact_approval_and_manual_handoff';
        elseif ( $view['selected_profile'] )
            $action = 'choose_ready_source_and_stage';
        $profile = isset( $view['selected_profile']['slug'] ) ?
            $view['selected_profile']['slug'] : '';
        $allowed_status = array();
        foreach ( $view['available_modes'] as $mode )
            $allowed_status[] = array( 'mode_id' => $mode['id'],
                'label' => $mode['label'],
                'ready_for_review' => $mode['ready_for_review'],
                'requirements' => $mode['setup'] );
        return array( 'contract' => self::CONTRACT,
            'selected_profile_slug' => $profile,
            'selected_mode_id' => $view['selected_mode_id'],
            'active_step' => $view['step'],
            'profiles' => $view['profiles'],
            'allowed_modes' => $allowed_status,
            'review_pending' => $view['review_pending'],
            'review_unavailable' => $view['review_unavailable'],
            'blocking_issue_count' => $view['blocking_issue_count'],
            'warning_issue_count' => $view['review_issue_count'],
            'issue_count_total' => $view['issue_count_total'],
            'next_recommended_action' => $action,
            'can_upload_new_source' => $view['can_upload_new'],
            'approval_candidate' => $view['approval_candidate'],
            'admin_screen' => 'tools.php?page=mad4b-import-review',
            'no_source_values' => true, 'import_execution_certified' => false,
            'read_only' => true, 'mutation_performed' => false );
    }
    private static function url( $slug, $step, $extra = array() ) {
        $query = array_merge( array( 'page' => 'mad4b-import-review',
            'profile_slug' => $slug, 'wizard_step' => $step ), $extra );
        return add_query_arg( $query, admin_url( 'tools.php' ) );
    }
    private static function explanation( $title, $detail, $type = 'info' ) {
        $role = in_array( $type, array( 'error', 'warning' ), true ) ?
            'alert' : 'status';
        echo '<div role="' . esc_attr( $role ) .
            '" class="notice notice-' . esc_attr( $type ) .
            ' inline"><p><strong>' . self::e( $title ) . '</strong> ' .
            self::e( $detail ) . '</p></div>';
    }
    private static function profiles() {
        $status = MAD4B_SCP_Content_Experience_Profiles::profile_status();
        $profiles = isset( $status['profiles'] ) && is_array( $status['profiles'] ) ?
            $status['profiles'] : array();
        foreach ( $profiles as &$p ) {
            $profile = MAD4B_SCP_Content_Experience_Profiles::profile( $p['slug'] );
            $policy = ! is_wp_error( $profile ) ?
                MAD4B_SCP_Activity_Import_Authority::profile_contract( $profile ) : array();
            $p['has_import_contract'] = ! empty( $policy['validation'] );
            $p['enabled_modes'] = isset( $policy['enabled_modes'] ) ?
                $policy['enabled_modes'] : array();
            $p['preferred_mode'] = isset( $policy['preferred_mode'] ) ?
                (string) $policy['preferred_mode'] : '';
        }
        unset( $p );
        return $profiles;
    }
    public static function render() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Administrator access is required.' );
        $profiles = self::profiles();
        $slug = isset( $_GET['profile_slug'] ) ?
            sanitize_key( wp_unslash( $_GET['profile_slug'] ) ) : '';
        $mode_id = isset( $_GET['mode_id'] ) ?
            sanitize_key( wp_unslash( $_GET['mode_id'] ) ) : '';
        $step = isset( $_GET['wizard_step'] ) ?
            absint( $_GET['wizard_step'] ) : 1;
        $catalog = MAD4B_SCP_Activity_Import_Modes::catalog();
        // A detected provider is not enough. UI readiness must also pass the
        // chosen Profile's exact key, site and encrypted-source prerequisites.
        if ( $slug && is_array( $catalog ) && ! empty( $catalog['modes'] ) ) {
            foreach ( $catalog['modes'] as &$mode ) {
                if ( empty( $mode['review_intake_implemented'] ) ) continue;
                $preflight = MAD4B_SCP_Activity_Import_Modes::plan( array(
                    'profile_slug' => $slug, 'mode_id' => $mode['id'] ) );
                $mode['detected'] = ! is_wp_error( $preflight ) &&
                    ! empty( $preflight['eligible_for_staging_review'] );
            }
            unset( $mode );
        }
        $review = $slug ? MAD4B_SCP_Activity_Import_Review::review(
            array( 'profile_slug' => $slug ) ) : array();
        $view = self::journey( $profiles, $slug, $catalog, $review, $mode_id, $step );
        // A multi-part batch has its OWN active encrypted manifest. It is
        // intentionally independent of the single-source review inbox.
        // Allow its handoff screen to be reached even with no single snapshot;
        // the authoritative batch status call below still fails closed.
        $bulk_id = isset( $_GET['batch_id'] ) ?
            sanitize_text_field( wp_unslash( $_GET['batch_id'] ) ) : '';
        if ( 4 === $step && preg_match( '/^[a-f0-9]{32}$/D', $bulk_id ) &&
            ! empty( $view['selected_profile'] ) && $view['has_import_policy'] )
            $view['step'] = 4;
        $selected = $view['selected_profile'];
        echo '<div class="wrap mad4b-import-guide">';
        echo '<style>
            .mad4b-import-guide { max-width: 1080px; }
            .mad4b-import-guide nav ol { padding: 10px 0; border-bottom: 1px solid #c3c4c7; }
            .mad4b-import-guide nav a { display: inline-block; padding: 8px 12px; }
            .mad4b-import-guide nav a[aria-current="step"] { font-weight: 700; border-bottom: 3px solid #2271b1; }
            .mad4b-import-guide table { margin-top: 12px; }
            .mad4b-import-guide th, .mad4b-import-guide td { vertical-align: top; }
            .mad4b-import-guide .button { margin-inline-end: 6px; }
            .mad4b-import-guide label { margin-inline-end: 8px; }
            @media (max-width: 782px) {
                .mad4b-import-guide nav ol { display: grid !important; grid-template-columns: 1fr 1fr; }
                .mad4b-import-guide select { max-width: 100%; }
                .mad4b-import-guide table { display: block; overflow-x: auto; }
            }
        </style>';
        echo '<h1>' . self::e( self::title( 'Import data — guided review' ) ) . '</h1>';
        self::explanation( self::title( 'Safety first.' ),
            self::title( 'This workspace reviews data on an enrolled Staging site. It does not run WP All Import, change prices, or publish records.' ) );
        if ( is_wp_error( $catalog ) )
            self::explanation( self::title( 'Import modes unavailable.' ),
                $catalog->get_error_message(), 'error' );
        echo '<nav aria-label="Import review steps"><ol style="display:flex;flex-wrap:wrap;gap:16px;margin:18px 0;">';
        $titles = array( 1 => '1. Choose destination', 2 => '2. Add source',
            3 => '3. Resolve issues', 4 => '4. Approve and hand off' );
        foreach ( $titles as $i => $title ) {
            echo '<li style="margin:0;list-style:none;">';
            if ( $selected )
                echo '<a href="' . esc_url( self::url( $slug, $i,
                    array( 'mode_id' => $view['selected_mode_id'] ) ) ) . '"' .
                    ( $view['step'] === $i ? ' aria-current="step"' : '' ) . '>' .
                    self::e( self::title( $title ) ) . '</a>';
            else echo self::e( self::title( $title ) );
            echo '</li>';
        }
        echo '</ol></nav>';
        echo '<form method="get" style="padding:12px 0;"><input type="hidden" name="page" value="mad4b-import-review" />';
        echo '<input type="hidden" name="wizard_step" value="1" />';
        echo '<label for="mad4b-import-profile"><strong>' .
            self::e( self::title( 'Content Experience Profile' ) ) . '</strong></label> ';
        echo '<select id="mad4b-import-profile" name="profile_slug" required>';
        echo '<option value="">' . self::e( self::title( 'Select an enabled Profile' ) ) . '</option>';
        foreach ( $view['profiles'] as $p )
            echo '<option value="' . esc_attr( $p['slug'] ) . '"' .
                selected( $p['slug'], $slug, false ) . '>' .
                self::e( $p['label'] . ' — ' . $p['post_type'] ) . '</option>';
        echo '</select> ';
        submit_button( self::title( 'Choose Profile' ), 'secondary', 'submit', false );
        echo '</form>';
        $ui_error = isset( $_GET['ui_error'] ) ?
            sanitize_key( wp_unslash( $_GET['ui_error'] ) ) : '';
        if ( $ui_error && preg_match( '/^[a-z0-9_]{5,100}$/D', $ui_error ) )
            self::explanation( self::title( 'The action was not completed.' ),
                self::error_guidance( $ui_error ), 'error' );
        if ( !$selected ) {
            self::explanation( self::title( 'Choose where the data belongs.' ),
                self::title( 'Select an existing configured Profile above. There is no need to enter a technical slug.' ) );
            echo '</div>'; return;
        }
        if ( !$view['has_import_policy'] ) {
            self::explanation( self::title( 'Import not configured for this Profile.' ),
                self::title( 'An administrator must approve the required columns, identity field, mappings and allowed import modes before a source can be uploaded.' ),
                'warning' );
            echo '</div>'; return;
        }
        if ( !$view['available_modes'] ) {
            self::explanation( self::title( 'No import methods are enabled.' ),
                self::title( 'Enable at least one approved mode in this Profile. Modes from another site will not be used automatically.' ),
                'warning' );
            echo '</div>'; return;
        }
        if ( $view['step'] === 1 ) self::step_destination( $view, $catalog, $slug );
        elseif ( $view['step'] === 2 ) self::step_source( $view, $slug );
        elseif ( $view['step'] === 3 ) self::step_conflicts( $view, $slug, $review );
        else self::step_handoff( $view, $slug, $review );
        echo '</div>';
    }
    private static function step_destination( $view, $catalog, $slug ) {
        echo '<h2>' . self::e( self::title( 'Choose how to receive the source' ) ) . '</h2>';
        echo '<p>' . self::e( self::title( 'Only methods permitted by this Profile appear in the main list. A method being installed does not mean it can safely import.' ) ) . '</p>';
        $current = MAD4B_SCP_Content_Experience_Profiles::profile( $slug );
        if ( ! is_wp_error( $current ) ) {
            $contract = MAD4B_SCP_Activity_Import_Authority::profile_contract( $current );
            $validation = isset( $contract['validation'] ) ? $contract['validation'] : array();
            if ( $validation ) {
                echo '<details><summary>' .
                    self::e( self::title( 'View expected source columns and mapping' ) ) .
                    '</summary>';
                echo '<p><strong>' . self::e( self::title( 'Unique source ID column:' ) ) .
                    '</strong> <code>' .
                    self::e( $validation['identity_field'] ) . '</code></p>';
                echo '<p><strong>' . self::e( self::title( 'Required source columns:' ) ) .
                    '</strong> <code>' .
                    self::e( implode( ', ', $validation['required_columns'] ) ) .
                    '</code></p>';
                if ( ! empty( $validation['currency_field'] ) )
                    echo '<p><strong>' . self::e( self::title( 'Currency column and allowed codes:' ) ) .
                        '</strong> <code>' . self::e( $validation['currency_field'] .
                            ' — ' . implode( ', ', $validation['allowed_currencies'] ) ) .
                        '</code></p>';
                if ( ! empty( $validation['price_fields'] ) )
                    echo '<p><strong>' . self::e( self::title( 'Ordered pricing columns:' ) ) .
                        '</strong> <code>' .
                        self::e( implode( ', ', $validation['price_fields'] ) ) .
                        '</code></p>';
                echo '<p>' . self::e( self::title(
                    'Field mappings belong to the site administrator. Uploading a file never changes them.' ) ) .
                    '</p></details>';
            }
        }
        echo '<form method="get"><input type="hidden" name="page" value="mad4b-import-review" />';
        echo '<input type="hidden" name="wizard_step" value="2" />';
        echo '<input type="hidden" name="profile_slug" value="' . esc_attr( $slug ) . '" />';
        echo '<label for="mad4b-import-mode"><strong>' .
            self::e( self::title( 'Source method' ) ) . '</strong></label> ';
        echo '<select name="mode_id" id="mad4b-import-mode">';
        foreach ( $view['available_modes'] as $m )
            echo '<option value="' . esc_attr( $m['id'] ) . '"' .
                selected( $m['id'], $view['selected_mode_id'], false ) . '>' .
                self::e( $m['label'] . ( $m['ready_for_review'] ?
                    ' — Ready for source review' : ' — Setup/adapter required' ) ) .
                '</option>';
        echo '</select> ';
        submit_button( self::title( 'Continue with this method' ), 'primary', 'submit', false );
        echo '</form>';
        if ( isset( $_GET['show_all_modes'] ) && '1' === (string) $_GET['show_all_modes'] ) {
            echo '<h3>' . self::e( self::title( 'All recognized alternatives (reference only)' ) ) . '</h3>';
            echo '<ul>';
            if ( is_array( $catalog ) && ! empty( $catalog['modes'] ) )
                foreach ( $catalog['modes'] as $m )
                    echo '<li>' . self::e( self::mode_label( $m['id'] ) .
                        ' — ' . $m['state'] ) . '</li>';
            echo '</ul>';
        } else echo '<p><a href="' . esc_url( self::url( $slug, 1,
            array( 'show_all_modes' => 1 ) ) ) . '">' .
            self::e( self::title( 'Show all detected and planned alternatives' ) ) . '</a></p>';
    }
    private static function step_source( $view, $slug ) {
        echo '<h2>' . self::e( self::title( 'Provide the source for review' ) ) . '</h2>';
        $mode = $view['selected_mode_id'];
        if ( $view['review_unavailable'] ) {
            self::explanation( self::title( 'The existing review cannot be verified.' ),
                self::title( 'Ask the site administrator to resolve the legacy or corrupted review state. A new upload is blocked to protect the current source.' ),
                'error' );
            return;
        }
        if ( $view['review_pending'] ) {
            self::explanation( self::title( 'An earlier review is still active.' ),
                self::title( 'You cannot overwrite it. Open the issues, complete approval, or explicitly archive that review first.' ),
                'warning' );
            echo '<p><a class="button button-primary" href="' .
                esc_url( self::url( $slug, 3 ) ) . '">' .
                self::e( self::title( 'Open current review' ) ) . '</a></p>';
            return;
        }
        if ( ! empty( $view['requested_mode_invalid'] ) ) {
            self::explanation( self::title( 'That source method is not permitted for this Profile.' ),
                self::title( 'Nothing was substituted automatically. Choose a method explicitly to avoid accidentally sending a source through a different connector.' ),
                'warning' );
            echo '<p><a class="button" href="' . esc_url( self::url( $slug, 1 ) ) . '">' .
                self::e( self::title( 'Choose a permitted method' ) ) . '</a></p>';
            return;
        }
        if ( !$mode ) {
            self::explanation( self::title( 'Choose a method first.' ),
                self::title( 'No permitted source method was selected.' ), 'warning' );
            return;
        }
        $mode_plan = MAD4B_SCP_Activity_Import_Modes::plan( array(
            'profile_slug' => $slug, 'mode_id' => $mode ) );
        if ( is_wp_error( $mode_plan ) ) {
            self::explanation( self::title( 'This method cannot be used for this Profile.' ),
                $mode_plan->get_error_message(), 'warning' ); return;
        }
        if ( !$mode_plan['eligible_for_staging_review'] ) {
            self::explanation( self::title( 'Setup is still required.' ),
                self::title( 'This method is registered but cannot yet accept a reviewed source on this site.' ),
                'warning' );
            echo '<h3>' . self::e( self::title( 'What is needed?' ) ) . '</h3><ul>';
            foreach ( $mode_plan['required_setup'] as $item )
                echo '<li>' . self::e( self::requirement_label( $item ) ) . '</li>';
            echo '</ul><p><a href="' . esc_url( self::url( $slug, 1 ) ) . '">' .
                self::e( self::title( 'Choose a different method' ) ) . '</a></p>';
            return;
        }
        if ( 'admin_csv_upload' === $mode ) {
            echo '<p>' . self::e( self::title( 'Choose the CSV exported from your spreadsheet. Maximum 1 MiB and 500 rows. The source values are reviewed under this Profile policy; no live posts are written.' ) ) . '</p>';
            echo '<form method="post" enctype="multipart/form-data" action="' .
                esc_url( admin_url( 'admin-post.php' ) ) . '">';
            echo '<input type="hidden" name="action" value="mad4b_activity_import_csv" />';
            echo '<input type="hidden" name="profile_slug" value="' . esc_attr( $slug ) . '" />';
            wp_nonce_field( 'mad4b_activity_csv_intake', 'mad4b_import_nonce' );
            echo '<p><label for="mad4b-source-file"><strong>' .
                self::e( self::title( 'CSV file' ) ) .
                '</strong></label> <input id="mad4b-source-file" type="file" name="import_csv" accept=".csv,text/csv" required /></p>';
            submit_button( self::title( 'Upload and inspect (no import)' ), 'primary' );
            echo '</form>';
        } elseif ( 'admin_xlsx_convert' === $mode ) {
            echo '<p>' . self::e( self::title(
                'Upload one XLSX worksheet as a bounded preview (up to 1 MiB, 500 data rows and 80 columns). All Excel formulas, macros and external links are rejected. No posts are written.' ) ) . '</p>';
            echo '<form method="post" enctype="multipart/form-data" action="' .
                esc_url( admin_url( 'admin-post.php' ) ) . '">';
            echo '<input type="hidden" name="action" value="mad4b_activity_import_xlsx" />';
            echo '<input type="hidden" name="profile_slug" value="' . esc_attr( $slug ) . '" />';
            wp_nonce_field( 'mad4b_activity_xlsx_intake', 'mad4b_import_xlsx_nonce' );
            echo '<p><label for="mad4b-xlsx-source"><strong>' .
                self::e( self::title( 'Excel XLSX workbook' ) ) .
                '</strong></label> <input id="mad4b-xlsx-source" type="file" name="import_xlsx" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required /></p>';
            submit_button( self::title( 'Stage Excel for conflict review (no import)' ), 'primary' );
            echo '</form>';
        } else {
            self::explanation( self::title( 'Send the source using the selected connector.' ),
                self::title( 'An authorized external app must send a signed request. No passwords or API keys should be pasted into this screen.' ) );
            echo '<p>' . self::e( self::title( 'After the connector delivers a review snapshot, return here and open the review. This screen does not claim that the external job has run.' ) ) . '</p>';
        }
    }
    private static function step_conflicts( $view, $slug, $review ) {
        echo '<h2>' . self::e( self::title( 'Understand and resolve source issues' ) ) . '</h2>';
        if ( !$view['review_pending'] ) {
            self::explanation( self::title( 'No source has been staged.' ),
                self::title( 'Upload a CSV or send a signed connector payload first.' ), 'warning' );
            return;
        }
        echo '<p><strong>' . self::e( (string) $view['issue_count_total'] ) . '</strong> ' .
            self::e( self::title( 'observations found' ) ) . ' &middot; <strong>' .
            self::e( (string) $view['blocking_issue_count'] ) . '</strong> ' .
            self::e( self::title( 'blocking' ) ) . ' &middot; <strong>' .
            self::e( (string) $view['review_issue_count'] ) . '</strong> ' .
            self::e( self::title( 'require business review' ) ) . '</p>';
        if ( $view['blocking_issue_count'] )
            self::explanation( self::title( 'Approval is blocked.' ),
                self::title( 'Fix blocking rows in the source and stage a new version. This screen never silently edits commercial values.' ),
                'warning' );
        elseif ( $view['issue_list_truncated'] )
            self::explanation( self::title( 'Additional review pages exist.' ),
                self::title( 'Browse the remaining issues. Manual approval will require acknowledging the exact total of all nonblocking business warnings, not only the first page.' ),
                'info' );
        $offset = isset( $_GET['issue_offset'] ) ? absint( $_GET['issue_offset'] ) : 0;
        $issues = MAD4B_SCP_Activity_Import_Review::issues_page( array(
            'profile_slug' => $slug, 'snapshot_sha256' => $review['snapshot_sha256'],
            'issue_offset' => $offset ) );
        if ( is_wp_error( $issues ) ) {
            self::explanation( self::title( 'The issue page cannot be verified.' ),
                self::error_guidance( $issues->get_error_code() ), 'error' ); return;
        }
        $rows = isset( $issues['issues'] ) ? $issues['issues'] : array();
        echo '<table class="widefat striped"><caption>' .
            self::e( self::title( 'Source issue details, without exposing private source values' ) ) .
            '</caption><thead><tr><th scope="col">Row</th><th scope="col">What needs attention</th><th scope="col">Type</th></tr></thead><tbody>';
        foreach ( $rows as $issue )
            echo '<tr><td>' . self::e( (string) $issue['row'] ) . '</td><td>' .
                self::e( self::issue_label( $issue['reason'] ) ) .
                '</td><td>' . self::e( $issue['severity'] ) . '</td></tr>';
        echo '</tbody></table>';
        echo '<p>' . self::e( sprintf( self::title( 'Showing issues %d–%d of %d.' ),
            $rows ? $offset + 1 : 0, $offset + count( $rows ),
            $issues['issue_count_total'] ) ) . '</p>';
        if ( $offset > 0 )
            echo '<a class="button" href="' . esc_url( self::url( $slug, 3,
                array( 'issue_offset' => max( 0, $offset - 200 ) ) ) ) . '">' .
                self::e( self::title( 'Previous issues' ) ) . '</a> ';
        if ( null !== $issues['next_offset'] )
            echo '<a class="button" href="' . esc_url( self::url( $slug, 3,
                array( 'issue_offset' => $issues['next_offset'] ) ) ) . '">' .
                self::e( self::title( 'Next issues' ) ) . '</a>';
        echo '<h3>' . self::e( self::title( 'Compare with existing WordPress records' ) ) . '</h3>';
        echo '<p>' . self::e( self::title( 'Optional independent check. It does not perform an import and does not certify WPML links or external import completion.' ) ) . '</p>';
        $compare = isset( $_GET['compare_destination'] ) &&
            '1' === (string) $_GET['compare_destination'];
        if ( !$compare ) {
            echo '<p><a class="button" href="' . esc_url( self::url( $slug, 3,
                array( 'compare_destination' => 1 ) ) ) . '">' .
                self::e( self::title( 'Compare existing destination records' ) ) . '</a></p>';
        } else {
            $cursor = isset( $_GET['reconcile_start'] ) ?
                absint( $_GET['reconcile_start'] ) : 0;
            $compare_result = MAD4B_SCP_Activity_Import_Reconciliation::plan(
                array( 'profile_slug' => $slug,
                    'snapshot_sha256' => $review['snapshot_sha256'],
                    'start_index' => $cursor, 'page_size' => 25 ) );
            if ( is_wp_error( $compare_result ) ) {
                self::explanation( self::title( 'Destination comparison unavailable.' ),
                    self::error_guidance( $compare_result->get_error_code() ),
                    'warning' );
            } else {
                $counts = $compare_result['counts'];
                echo '<p>' . self::e( sprintf( self::title(
                    'This page: %d matching, %d different, %d missing, %d ambiguous, %d unverified.' ),
                    $counts['matched'], $counts['different'],
                    $counts['missing'], $counts['ambiguous'], $counts['unverified'] ) ) . '</p>';
                echo '<table class="widefat striped"><caption>' .
                    self::e( self::title( 'Compared destination rows; no private values shown' ) ) .
                    '</caption><thead><tr><th scope="col">Row</th><th scope="col">Result</th><th scope="col">Field issues</th></tr></thead><tbody>';
                foreach ( $compare_result['items'] as $item ) {
                    echo '<tr><td>' . self::e( $item['row_index'] + 1 ) .
                        '</td><td>' . self::e( $item['status'] ) . '</td><td>';
                    foreach ( $item['field_issues'] as $issue )
                        echo '<p>' . self::e( $issue['field'] . ': ' .
                            str_replace( '_', ' ', $issue['reason'] ) ) . '</p>';
                    echo '</td></tr>';
                }
                echo '</tbody></table>';
                if ( $cursor > 0 )
                    echo '<a class="button" href="' . esc_url( self::url( $slug, 3,
                        array( 'compare_destination' => 1,
                            'reconcile_start' => max( 0, $cursor - 25 ) ) ) ) .
                        '">' . self::e( self::title( 'Previous 25 destination records' ) ) . '</a> ';
                if ( null !== $compare_result['next_index'] )
                    echo '<a class="button" href="' . esc_url( self::url( $slug, 3,
                        array( 'compare_destination' => 1,
                            'reconcile_start' => $compare_result['next_index'] ) ) ) .
                        '">' . self::e( self::title( 'Next 25 destination records' ) ) . '</a>';
            }
        }
        echo '<p><a class="button button-primary" href="' .
            esc_url( self::url( $slug, 4 ) ) . '">' .
            self::e( self::title( 'Review approval and next actions' ) ) . '</a></p>';
    }
    private static function step_handoff( $view, $slug, $review ) {
        echo '<h2>' . self::e( self::title( 'Approve a fixed snapshot and hand it off' ) ) . '</h2>';
        $batch_id = isset( $_GET['batch_id'] ) ?
            sanitize_text_field( wp_unslash( $_GET['batch_id'] ) ) : '';
        if ( preg_match( '/^[a-f0-9]{32}$/D', $batch_id ) &&
            class_exists( 'MAD4B_SCP_Activity_Import_Batches' ) ) {
            $batch = MAD4B_SCP_Activity_Import_Batches::status( array(
                'profile_slug' => $slug, 'batch_id' => $batch_id ) );
            if ( is_wp_error( $batch ) ) {
                self::explanation( self::title( 'Batch is not available for this Profile.' ),
                    $batch->get_error_message(), 'warning' );
            } else {
                echo '<h3>' . self::e( self::title( 'Encrypted bulk source review' ) ) . '</h3>';
                echo '<p>' . self::e( sprintf( self::title(
                    '%d of %d source rows currently staged; %d blockers; %d business warnings; %d duplicate IDs; %d split translation groups.' ),
                    $batch['total_rows'], $batch['expected_chunks'] * 500,
                    $batch['blocks'], $batch['warnings'],
                    $batch['cross_chunk_duplicate_id_count'],
                    $batch['cross_chunk_wpml_group_split_count'] ) ) . '</p>';
                if ( ! $batch['ready_for_manual_batch_review'] )
                    self::explanation( self::title( 'Not ready for approval.' ),
                        self::title( 'All parts must be present, without blocking source issues, duplicate external IDs or split translation groups.' ),
                        'warning' );
                elseif ( empty( $batch['approved_for_manual_chunk_export'] ) )
                    self::explanation( self::title( 'Awaiting explicit complete-batch approval.' ),
                        self::title( 'Use the governed conversational approval action with the exact full-batch plan hash and warning count. This does not run WP All Import.' ),
                        'info' );
                else {
                    self::explanation( self::title( 'Approved for manual CSV part downloads.' ),
                        self::title( 'Each part is independently revalidated before download. Selecting and running a provider job remains a separate manual action.' ),
                        'success' );
                    foreach ( $batch['chunks'] as $chunk ) {
                        if ( 'staged' !== $chunk['state'] ) continue;
                        echo '<form method="post" action="' .
                            esc_url( admin_url( 'admin-post.php' ) ) . '">';
                        echo '<input type="hidden" name="action" value="mad4b_activity_batch_export" />';
                        echo '<input type="hidden" name="profile_slug" value="' . esc_attr( $slug ) . '" />';
                        echo '<input type="hidden" name="batch_id" value="' . esc_attr( $batch_id ) . '" />';
                        echo '<input type="hidden" name="chunk_index" value="' .
                            esc_attr( $chunk['index'] ) . '" />';
                        wp_nonce_field( 'mad4b_batch_export_' . $slug . '_' . $batch_id,
                            'mad4b_batch_export_nonce' );
                        submit_button( sprintf( self::title(
                            'Download approved part %d (%d rows)' ),
                            $chunk['index'] + 1, $chunk['row_count'] ),
                            'secondary', 'submit', false );
                        echo '</form>';
                    }
                }
                echo '<p>' . self::e( self::title(
                    'A reviewed batch is not a running queue. External writes, provider locking, WPML link verification and rollback require separate certified adapters.' ) ) .
                    '</p>';
            }
            return;
        }
        if ( !$view['review_pending'] ) {
            self::explanation( self::title( 'Nothing to approve.' ),
                self::title( 'A source review must be completed first.' ), 'warning' );
            return;
        }
        $sha = $review['snapshot_sha256'];
        $approved = MAD4B_SCP_Activity_Import_Snapshot::approval( $slug, $sha );
        if ( ! is_wp_error( $approved ) ) {
            self::explanation( self::title( 'Approved for manual CSV handoff.' ),
                self::title( 'This permits downloading only this fixed source snapshot. It does not run a WordPress import or publish anything.' ),
                'success' );
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
            echo '<input type="hidden" name="action" value="mad4b_activity_import_export" />';
            echo '<input type="hidden" name="profile_slug" value="' . esc_attr( $slug ) . '" />';
            echo '<input type="hidden" name="snapshot_sha256" value="' . esc_attr( $sha ) . '" />';
            wp_nonce_field( 'mad4b_activity_export_approved', 'mad4b_export_nonce' );
            submit_button( self::title( 'Download exact approved CSV' ), 'primary' );
            echo '</form>';
        } elseif ( $view['approval_candidate'] ) {
            $approval_plan = MAD4B_SCP_Activity_Import_Snapshot::approval_plan( array(
                'profile_slug' => $slug, 'snapshot_sha256' => $sha ) );
            if ( is_wp_error( $approval_plan ) ||
                empty( $approval_plan['ready_for_manual_approval'] ) ) {
                self::explanation( self::title( 'Approval cannot proceed.' ),
                    is_wp_error( $approval_plan ) ?
                    self::error_guidance( $approval_plan->get_error_code() ) :
                    self::title( 'Source or policy has changed since the review.' ),
                    'warning' );
            } else {
                self::explanation( self::title( 'Review this decision carefully.' ),
                    self::title( 'You approve the exact source and accept any nonblocking business warnings. No automatic importing, publishing or rewriting will occur.' ) );
                echo '<p><strong>' . self::e( sprintf(
                    self::title( '%d nonblocking business warnings will be acknowledged by this approval.' ),
                    $view['review_issue_count'] ) ) . '</strong></p>';
                echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
                echo '<input type="hidden" name="action" value="mad4b_activity_import_approve" />';
                echo '<input type="hidden" name="profile_slug" value="' . esc_attr( $slug ) . '" />';
                echo '<input type="hidden" name="snapshot_sha256" value="' . esc_attr( $sha ) . '" />';
                echo '<input type="hidden" name="acknowledged_warning_count" value="' .
                    esc_attr( $view['review_issue_count'] ) . '" />';
                echo '<p><label><input name="acknowledge_warnings" type="checkbox" value="1" required /> ' .
                    self::e( self::title( 'I have reviewed the full issue totals and explicitly acknowledge all nonblocking warnings.' ) ) .
                    '</label></p>';
                wp_nonce_field( 'mad4b_activity_approve_review', 'mad4b_approve_nonce' );
                submit_button( self::title( 'Approve fixed source for manual CSV download' ), 'primary' );
                echo '</form>';
            }
        } else self::explanation( self::title( 'This review cannot be approved yet.' ),
            self::title( 'Return to issues, correct blocking rows, then stage a new source. Manual approval is never a shortcut around failed checks.' ), 'warning' );
        echo '<h3>' . self::e( self::title( 'After a manual provider import' ) ) . '</h3>';
        echo '<p>' . self::e( self::title( 'Use the existing provider interface to select the approved downloaded CSV. Independently compare destination fields and translation links on Staging before considering release.' ) ) . '</p>';
        echo '<details style="margin-top:20px;"><summary>' .
            self::e( self::title( 'Check an existing WP All Import job (advanced)' ) ) .
            '</summary>';
        echo '<p>' . self::e( self::title( 'Enter the ID of a previously configured job. The observer can show that hooks fired, but it cannot certify imported prices, WPML links or source-file identity.' ) ) . '</p>';
        echo '<form method="get"><input type="hidden" name="page" value="mad4b-import-review" />';
        echo '<input type="hidden" name="profile_slug" value="' . esc_attr( $slug ) . '" />';
        echo '<input type="hidden" name="wizard_step" value="4" />';
        echo '<label for="mad4b-wpai-job">' .
            self::e( self::title( 'Existing WP All Import job ID' ) ) . '</label> ';
        echo '<input type="number" id="mad4b-wpai-job" name="observed_import_id" min="1" step="1" required /> ';
        submit_button( self::title( 'Inspect observed job status' ), 'secondary', 'submit', false );
        echo '</form>';
        $observed_id = isset( $_GET['observed_import_id'] ) ?
            absint( $_GET['observed_import_id'] ) : 0;
        if ( $observed_id && class_exists( 'MAD4B_SCP_Activity_WPAI_Observer' ) ) {
            $job = MAD4B_SCP_Activity_WPAI_Observer::status( array(
                'import_id' => $observed_id ) );
            if ( is_wp_error( $job ) )
                self::explanation( self::title( 'Job observation unavailable.' ),
                    self::error_guidance( $job->get_error_code() ), 'warning' );
            else {
                echo '<p><strong>' . self::e( self::title( 'Observed state:' ) ) .
                    '</strong> ' . self::e( str_replace( '_', ' ', $job['state'] ) ) .
                    '</p>';
                if ( isset( $job['post_save_events_observed'] ) )
                    echo '<p>' . self::e( sprintf( self::title(
                        'Best-effort post-save events seen: %d (not a verified import count).' ),
                        (int) $job['post_save_events_observed'] ) ) . '</p>';
                self::explanation( self::title( 'Still needs independent verification.' ),
                    self::title( 'Use the destination comparison and validate language links in the installed provider before accepting the run.' ),
                    'warning' );
                echo '<a class="button" href="' . esc_url( self::url( $slug, 3,
                    array( 'compare_destination' => 1 ) ) ) . '">' .
                    self::e( self::title( 'Verify WordPress destination fields' ) ) .
                    '</a>';
            }
        }
        echo '</details>';
        echo '<p><a class="button" href="' . esc_url( self::url( $slug, 3 ) ) . '">' .
            self::e( self::title( 'Back to issues' ) ) . '</a></p>';
        // Archival is always an explicit, independently nonced action.
        echo '<details style="margin-top:24px;"><summary>' .
            self::e( self::title( 'Archive this review (does not delete imported content)' ) ) . '</summary>';
        echo '<p>' . self::e( self::title( 'Archiving clears the active review and invalidates its approval. You can submit the same data again, but it will require a new review and approval.' ) ) . '</p>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="mad4b_activity_import_archive" />';
        echo '<input type="hidden" name="profile_slug" value="' . esc_attr( $slug ) . '" />';
        echo '<input type="hidden" name="expected_payload_sha256" value="' .
            esc_attr( $review['payload_sha256'] ) . '" />';
        echo '<input type="hidden" name="expected_snapshot_sha256" value="' .
            esc_attr( $sha ) . '" />';
        wp_nonce_field( 'mad4b_activity_archive_review', 'mad4b_archive_nonce' );
        submit_button( self::title( 'Archive this exact review' ), 'secondary' );
        echo '</form></details>';
    }
}
