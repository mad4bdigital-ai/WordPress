<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/** Original IMP01 read-only admin fallback, isolated from ingestion and approvals. */
trait MAD4B_SCP_Activity_Import_Fallback_View {
    private static function render_fallback_admin_page() {
        if ( class_exists( 'MAD4B_SCP_Activity_Import_Experience' ) )
            return MAD4B_SCP_Activity_Import_Experience::render();
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Permission denied.' );
        echo '<div class="wrap"><h1>MAD4B Activity Import Review</h1>';
        echo '<p>Review-only transports: signed Apps Script/Make/n8n/Zapier/BitFlows push, or Staging administrator CSV upload. No import job is executed here.</p>';
        echo '<h2>Alternative import modes</h2>';
        $catalog = class_exists( 'MAD4B_SCP_Activity_Import_Modes' )
            ? MAD4B_SCP_Activity_Import_Modes::catalog() : new WP_Error( 'modes_unavailable', 'Mode registry not loaded.' );
        if ( ! is_wp_error( $catalog ) ) {
            echo '<table class="widefat striped"><thead><tr><th>Mode</th><th>Family</th><th>State</th><th>Transport</th><th>Destination</th></tr></thead><tbody>';
            foreach ( $catalog['modes'] as $m ) {
                echo '<tr><td>' . esc_html( $m['id'] ) . '</td><td>' . esc_html( $m['family'] ) .
                    '</td><td>' . esc_html( $m['state'] ) . '</td><td>' . esc_html( $m['transport'] ) .
                    '</td><td>' . esc_html( $m['destination'] ) . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        $slug = isset( $_GET['profile_slug'] ) ? sanitize_key( wp_unslash( $_GET['profile_slug'] ) ) : '';
        echo '<form method="get"><input type="hidden" name="page" value="mad4b-import-review" />';
        echo '<label>Content Experience Profile <input name="profile_slug" value="' . esc_attr( $slug ) . '" /></label>';
        submit_button( 'Inspect staged conflicts', 'secondary', '', false );
        echo '</form>';
        if ( $slug && is_array( $catalog ) ) {
            $chosen = isset( $_GET['mode_id'] ) ? sanitize_key( wp_unslash( $_GET['mode_id'] ) ) : '';
            echo '<form method="get">';
            echo '<input type="hidden" name="page" value="mad4b-import-review" />';
            echo '<input type="hidden" name="profile_slug" value="' . esc_attr( $slug ) . '" />';
            echo '<label>Import Mode <select name="mode_id"><option value="">Choose a transport</option>';
            foreach ( $catalog['modes'] as $m )
                echo '<option value="' . esc_attr( $m['id'] ) . '"' .
                    selected( $chosen, $m['id'], false ) . '>' .
                    esc_html( $m['id'] . ' — ' . $m['state'] ) . '</option>';
            echo '</select></label>';
            submit_button( 'Inspect mode requirements', 'secondary', '', false );
            echo '</form>';
            if ( $chosen ) {
                $mode_plan = MAD4B_SCP_Activity_Import_Modes::plan( array(
                    'profile_slug' => $slug, 'mode_id' => $chosen ) );
                if ( is_wp_error( $mode_plan ) ) echo '<p>' .
                    esc_html( $mode_plan->get_error_message() ) . '</p>';
                else {
                    echo '<h3>Selected mode prerequisites</h3><ul>';
                    foreach ( $mode_plan['required_setup'] as $step )
                        echo '<li>' . esc_html( $step ) . '</li>';
                    echo '</ul><p>Ready for staging review: ' .
                        esc_html( $mode_plan['eligible_for_staging_review'] ? 'yes' : 'no' ) .
                        '. Automatic post writing: no.</p>';
                }
            }
        }
        if ( method_exists( 'MAD4B_SCP_Site_Profile', 'environment_allowed' ) &&
            MAD4B_SCP_Site_Profile::environment_allowed( array( 'staging' ) ) ) {
            echo '<h2>Staging CSV source review</h2>';
            echo '<form enctype="multipart/form-data" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
            echo '<input type="hidden" name="action" value="mad4b_activity_import_csv" />';
            wp_nonce_field( 'mad4b_activity_csv_intake', 'mad4b_import_nonce' );
            echo '<p><label>Profile Slug <input required name="profile_slug" value="' . esc_attr( $slug ) . '" /></label></p>';
            echo '<p>Identifier, allowed currencies and field mapping are governed by this site\'s Import Contract, not by the uploaded file.</p>';
            echo '<p><label>CSV file (max 1 MiB / 500 rows) <input required type="file" name="import_csv" accept=".csv,text/csv" /></label></p>';
            submit_button( 'Stage CSV for conflict review', 'secondary' );
            echo '</form>';
        }
        if ( $slug ) {
            $review = self::review( array( 'profile_slug' => $slug ) );
            if ( is_wp_error( $review ) ) echo '<p>' . esc_html( $review->get_error_message() ) . '</p>';
            elseif ( isset( $review['plan']['issues'] ) ) {
                $issue_offset = isset( $_GET['issue_offset'] ) ?
                    absint( $_GET['issue_offset'] ) : 0;
                $issues_view = isset( $review['snapshot_sha256'] ) ?
                    self::issues_page( array( 'profile_slug' => $slug,
                        'snapshot_sha256' => $review['snapshot_sha256'],
                        'issue_offset' => $issue_offset ) ) : $review['plan'];
                echo '<h3>Source validation conflicts</h3>';
                echo '<p>Plan: <code>' . esc_html( $review['plan']['plan_sha256'] ) .
                    '</code>; total: ' . esc_html( $review['plan']['issue_count_observed'] ) . '</p>';
                if ( is_wp_error( $issues_view ) ) {
                    echo '<p>' . esc_html( $issues_view->get_error_message() ) . '</p>';
                } else {
                    echo '<table class="widefat striped"><thead><tr><th>Row</th><th>Reason</th><th>Severity</th></tr></thead><tbody>';
                    foreach ( $issues_view['issues'] as $issue )
                        echo '<tr><td>' . esc_html( $issue['row'] ) . '</td><td>' .
                            esc_html( $issue['reason'] ) . '</td><td>' .
                            esc_html( $issue['severity'] ) . '</td></tr>';
                    echo '</tbody></table>';
                    if ( isset( $issues_view['next_offset'] ) &&
                        null !== $issues_view['next_offset'] )
                        echo '<p><a href="' . esc_url( add_query_arg( array(
                            'page' => 'mad4b-import-review',
                            'profile_slug' => $slug,
                            'issue_offset' => $issues_view['next_offset']
                        ), admin_url( 'tools.php' ) ) ) .
                        '">Review next 200 source issues</a></p>';
                }
                if ( isset( $review['snapshot_sha256'] ) &&
                    class_exists( 'MAD4B_SCP_Activity_Import_Reconciliation' ) ) {
                    $page_index = isset( $_GET['reconcile_start'] ) ?
                        absint( $_GET['reconcile_start'] ) : 0;
                    $diff = MAD4B_SCP_Activity_Import_Reconciliation::plan( array(
                        'profile_slug' => $slug,
                        'snapshot_sha256' => $review['snapshot_sha256'],
                        'start_index' => $page_index, 'page_size' => 25
                    ) );
                    if ( is_wp_error( $diff ) ) {
                        echo '<p>Independent destination comparison unavailable: ' .
                            esc_html( $diff->get_error_message() ) . '</p>';
                    } else {
                        echo '<h3>WordPress destination comparison (no changes)</h3>';
                        echo '<p>Matched: ' . esc_html( $diff['counts']['matched'] ) .
                            ', Different: ' . esc_html( $diff['counts']['different'] ) .
                            ', Missing: ' . esc_html( $diff['counts']['missing'] ) .
                            ', Ambiguous: ' . esc_html( $diff['counts']['ambiguous'] ) . '</p>';
                        echo '<table class="widefat striped"><thead><tr><th>Row</th><th>Match status</th><th>Field conflicts</th></tr></thead><tbody>';
                        foreach ( $diff['items'] as $item ) {
                            echo '<tr><td>' . esc_html( $item['row_index'] + 1 ) .
                                '</td><td>' . esc_html( $item['status'] ) . '</td><td>';
                            foreach ( $item['field_issues'] as $issue )
                                echo '<p>' . esc_html( $issue['field'] . ': ' . $issue['reason'] ) . '</p>';
                            echo '</td></tr>';
                        }
                        echo '</tbody></table>';
                        if ( null !== $diff['next_index'] )
                            echo '<p><a href="' . esc_url( add_query_arg( array(
                                'page' => 'mad4b-import-review', 'profile_slug' => $slug,
                                'reconcile_start' => $diff['next_index']
                            ), admin_url( 'tools.php' ) ) ) .
                            '">Review next 25 destination rows</a></p>';
                    }
                }
                if ( isset( $review['snapshot_sha256'] ) ) {
                    $receipt = MAD4B_SCP_Activity_Import_Snapshot::approval(
                        $slug, $review['snapshot_sha256'] );
                    if ( ! is_wp_error( $receipt ) ) {
                        echo '<p>Approved snapshot <code>' .
                            esc_html( $review['snapshot_sha256'] ) .
                            '</code>. Ready for manual CSV handoff, not automated post write.</p>';
                        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
                        echo '<input type="hidden" name="action" value="mad4b_activity_import_export" />';
                        echo '<input type="hidden" name="profile_slug" value="' . esc_attr( $slug ) . '" />';
                        echo '<input type="hidden" name="snapshot_sha256" value="' .
                            esc_attr( $review['snapshot_sha256'] ) . '" />';
                        wp_nonce_field( 'mad4b_activity_export_approved', 'mad4b_export_nonce' );
                        submit_button( 'Download approved immutable CSV', 'secondary' );
                        echo '</form>';
                    } elseif ( isset( $review['plan']['block_issue_count'] ) &&
                        0 === (int) $review['plan']['block_issue_count'] &&
                        empty( $review['plan']['issues_truncated'] ) ) {
                        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
                        echo '<input type="hidden" name="action" value="mad4b_activity_import_approve" />';
                        echo '<input type="hidden" name="profile_slug" value="' . esc_attr( $slug ) . '" />';
                        echo '<input type="hidden" name="snapshot_sha256" value="' .
                            esc_attr( $review['snapshot_sha256'] ) . '" />';
                        wp_nonce_field( 'mad4b_activity_approve_review', 'mad4b_approve_nonce' );
                        submit_button( 'Approve exact staged snapshot for manual export', 'primary' );
                        echo '</form>';
                    } else {
                        echo '<p>Approval blocked: fix all blocking issues and truncated reviews at source, then stage a new snapshot.</p>';
                    }
                }
                if ( isset( $review['payload_sha256'] ) &&
                    method_exists( 'MAD4B_SCP_Site_Profile', 'environment_allowed' ) &&
                    MAD4B_SCP_Site_Profile::environment_allowed( array( 'staging' ) ) ) {
                    echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
                    echo '<input type="hidden" name="action" value="mad4b_activity_import_archive" />';
                    echo '<input type="hidden" name="profile_slug" value="' . esc_attr( $slug ) . '" />';
                    echo '<input type="hidden" name="expected_payload_sha256" value="' .
                        esc_attr( $review['payload_sha256'] ) . '" />';
                    echo '<input type="hidden" name="expected_snapshot_sha256" value="' .
                        esc_attr( $review['snapshot_sha256'] ) . '" />';
                    wp_nonce_field( 'mad4b_activity_archive_review', 'mad4b_archive_nonce' );
                    submit_button( 'Archive reviewed snapshot (no WordPress post changes)', 'secondary' );
                    echo '</form>';
                }
            } else echo '<p>No staged feed for this profile.</p>';
        }
        echo '</div>';
    }
}
