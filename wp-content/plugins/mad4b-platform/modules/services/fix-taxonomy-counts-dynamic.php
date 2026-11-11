<?php
/**
 * Plugin Name: Fix Taxonomy Counts Dynamic - Safe Tools
 * Description: Admin-only taxonomy count repair tools with dry-run cleanup, scoped SQL rebuild, limited debug output, and optional cache purge.
 * Author: OpenAI
 * Version: 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( defined( 'NK_TAX_COUNT_TOOLS_ENABLED' ) && ! NK_TAX_COUNT_TOOLS_ENABLED ) {
    return;
}

if ( ! class_exists( 'NK_Fix_Taxonomy_Counts_Dynamic_Safe' ) ) {
    final class NK_Fix_Taxonomy_Counts_Dynamic_Safe {
        private const CAPABILITY   = 'manage_options';
        private const MENU_SLUG    = 'nk-taxonomy-count-tools';
        private const ACTION_HOOK  = 'nk_tax_count_action';
        private const NONCE_ACTION = 'nk_tax_count_tools';

        public function __construct() {
            add_action( 'init', [ $this, 'force_count_callback' ], 100 );

            if ( is_admin() ) {
                add_action( 'admin_menu', [ $this, 'register_tools_page' ] );
                add_action( 'admin_post_' . self::ACTION_HOOK, [ $this, 'handle_action' ] );
            }
        }

        private function tools_enabled(): bool {
            return ! defined( 'NK_TAX_COUNT_TOOLS_ENABLED' ) || (bool) NK_TAX_COUNT_TOOLS_ENABLED;
        }

        public function register_tools_page(): void {
            if ( ! $this->tools_enabled() || ! current_user_can( self::CAPABILITY ) ) {
                return;
            }

            add_management_page(
                'Taxonomy Count Tools',
                'Taxonomy Count Tools',
                self::CAPABILITY,
                self::MENU_SLUG,
                [ $this, 'render_tools_page' ]
            );
        }

        private function get_context_from_request(): array {
            $taxonomy  = isset( $_REQUEST['nktc_taxonomy'] ) ? sanitize_key( wp_unslash( (string) $_REQUEST['nktc_taxonomy'] ) ) : '';
            $post_type = isset( $_REQUEST['nktc_post_type'] ) ? sanitize_key( wp_unslash( (string) $_REQUEST['nktc_post_type'] ) ) : '';

            if ( $taxonomy && ! taxonomy_exists( $taxonomy ) ) {
                $taxonomy = '';
            }

            if ( $post_type && ! post_type_exists( $post_type ) ) {
                $post_type = '';
            }

            if ( ! $post_type && $taxonomy ) {
                $tax = get_taxonomy( $taxonomy );
                if ( $tax && ! empty( $tax->object_type ) && is_array( $tax->object_type ) ) {
                    foreach ( $tax->object_type as $object_type ) {
                        if ( post_type_exists( $object_type ) ) {
                            $post_type = $object_type;
                            break;
                        }
                    }
                }
            }

            return [ 'taxonomy' => $taxonomy, 'post_type' => $post_type ];
        }

        private function get_context_for_screen(): array {
            $taxonomy  = '';
            $post_type = '';

            if ( isset( $_GET['taxonomy'] ) ) {
                $taxonomy = sanitize_key( wp_unslash( (string) $_GET['taxonomy'] ) );
            }
            if ( isset( $_GET['post_type'] ) ) {
                $post_type = sanitize_key( wp_unslash( (string) $_GET['post_type'] ) );
            }

            if ( $taxonomy && ! taxonomy_exists( $taxonomy ) ) {
                $taxonomy = '';
            }
            if ( $post_type && ! post_type_exists( $post_type ) ) {
                $post_type = '';
            }

            return [ 'taxonomy' => $taxonomy, 'post_type' => $post_type ];
        }

        public function force_count_callback(): void {
            global $wp_taxonomies;

            $context  = $this->get_context_for_screen();
            $taxonomy = $context['taxonomy'];

            if ( $taxonomy && isset( $wp_taxonomies[ $taxonomy ] ) ) {
                $wp_taxonomies[ $taxonomy ]->update_count_callback = '_update_post_term_count';
            }
        }

        public function render_tools_page(): void {
            if ( ! current_user_can( self::CAPABILITY ) ) {
                wp_die( 'Unauthorized request.' );
            }

            $context   = $this->get_context_from_request();
            $taxonomy  = $context['taxonomy'];
            $post_type = $context['post_type'];
            $taxes     = get_taxonomies( [ 'public' => true ], 'objects' );
            $post_types = get_post_types( [ 'public' => true ], 'objects' );
            unset( $post_types['attachment'] );

            echo '<div class="wrap"><h1>Taxonomy Count Tools</h1>';
            echo '<p>Use dry-run first. SQL rebuild counts published posts only. Orphan cleanup is scoped to the selected taxonomy.</p>';

            echo '<form method="get" action="' . esc_url( admin_url( 'tools.php' ) ) . '">';
            echo '<input type="hidden" name="page" value="' . esc_attr( self::MENU_SLUG ) . '">';
            echo '<table class="form-table" role="presentation">';
            echo '<tr><th><label for="nktc_taxonomy">Taxonomy</label></th><td><select name="nktc_taxonomy" id="nktc_taxonomy">';
            echo '<option value="">Select taxonomy</option>';
            foreach ( $taxes as $slug => $obj ) {
                echo '<option value="' . esc_attr( $slug ) . '" ' . selected( $taxonomy, $slug, false ) . '>' . esc_html( $slug ) . '</option>';
            }
            echo '</select></td></tr>';
            echo '<tr><th><label for="nktc_post_type">Post type</label></th><td><select name="nktc_post_type" id="nktc_post_type">';
            echo '<option value="">Auto / select post type</option>';
            foreach ( $post_types as $slug => $obj ) {
                echo '<option value="' . esc_attr( $slug ) . '" ' . selected( $post_type, $slug, false ) . '>' . esc_html( $slug ) . '</option>';
            }
            echo '</select></td></tr>';
            echo '</table>';
            submit_button( 'Load tools', 'secondary', '', false );
            echo '</form>';

            if ( ! $taxonomy ) {
                echo '</div>';
                return;
            }

            echo '<hr><h2>Selected context</h2>';
            echo '<p>Taxonomy: <code>' . esc_html( $taxonomy ) . '</code> | Post type: <code>' . esc_html( $post_type ?: '(auto)' ) . '</code></p>';
            echo '<p><strong>Note:</strong> SQL rebuild counts published posts only.</p>';

            $this->render_action_button( 'recount', 'Recount via WordPress', $taxonomy, $post_type, false );
            if ( $post_type ) {
                $this->render_action_button( 'rebuild_sql_counts', 'Rebuild via SQL', $taxonomy, $post_type, false );
            }
            $this->render_action_button( 'debug', 'Debug Relationships', $taxonomy, $post_type, false );
            $this->render_action_button( 'cleanup_orphans', 'Dry-run Cleanup Orphans', $taxonomy, $post_type, true );
            $this->render_action_button( 'cleanup_orphans', 'Run Cleanup Orphans', $taxonomy, $post_type, false, true );
            echo '</div>';
        }

        private function render_action_button( string $tool_action, string $label, string $taxonomy, string $post_type, bool $dry_run = false, bool $confirm = false ): void {
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin:0 8px 8px 0;">';
            wp_nonce_field( self::NONCE_ACTION );
            echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_HOOK ) . '">';
            echo '<input type="hidden" name="nktc_action" value="' . esc_attr( $tool_action ) . '">';
            echo '<input type="hidden" name="nktc_taxonomy" value="' . esc_attr( $taxonomy ) . '">';
            echo '<input type="hidden" name="nktc_post_type" value="' . esc_attr( $post_type ) . '">';
            if ( $dry_run ) {
                echo '<input type="hidden" name="dry_run" value="1">';
            }
            if ( $confirm ) {
                echo '<input type="hidden" name="confirm_cleanup" value="1">';
            }
            submit_button( $label, $confirm ? 'delete' : 'secondary', '', false, $confirm ? [ 'onclick' => "return confirm('Delete orphaned relationships for this taxonomy? Run dry-run first.');" ] : [] );
            echo '</form>';
        }

        public function handle_action(): void {
            if ( ! is_admin() || ! current_user_can( self::CAPABILITY ) ) {
                wp_die( 'Unauthorized request.' );
            }

            check_admin_referer( self::NONCE_ACTION );

            $context   = $this->get_context_from_request();
            $taxonomy  = $context['taxonomy'];
            $post_type = $context['post_type'];
            $action    = isset( $_POST['nktc_action'] ) ? sanitize_key( wp_unslash( (string) $_POST['nktc_action'] ) ) : '';

            if ( ! $taxonomy ) {
                wp_die( 'No valid taxonomy selected.' );
            }

            switch ( $action ) {
                case 'recount':
                    $this->recount_via_wordpress( $taxonomy, $post_type );
                    break;
                case 'rebuild_sql_counts':
                    $this->rebuild_counts_via_sql( $taxonomy, $post_type );
                    break;
                case 'cleanup_orphans':
                    $this->cleanup_orphan_relationships( $taxonomy, $post_type, ! empty( $_POST['dry_run'] ), ! empty( $_POST['confirm_cleanup'] ) );
                    break;
                case 'debug':
                    $this->debug_output( $taxonomy, $post_type );
                    break;
                default:
                    wp_die( 'Unknown taxonomy count action.' );
            }
        }

        private function recount_via_wordpress( string $taxonomy, string $post_type ): void {
            $term_ids = get_terms( [ 'taxonomy' => $taxonomy, 'hide_empty' => false, 'fields' => 'ids' ] );
            if ( is_wp_error( $term_ids ) ) {
                wp_die( 'Error loading terms: ' . esc_html( $term_ids->get_error_message() ) );
            }
            if ( ! empty( $term_ids ) ) {
                wp_update_term_count_now( $term_ids, $taxonomy );
                clean_term_cache( $term_ids, $taxonomy, false );
            }
            $this->flush_caches( $taxonomy, $term_ids );
            $this->done_page( 'Recount Complete', 'WordPress recount finished for taxonomy: ' . $taxonomy, $taxonomy, $post_type );
        }

        private function rebuild_counts_via_sql( string $taxonomy, string $post_type ): void {
            global $wpdb;
            if ( ! $post_type ) {
                wp_die( 'No valid post type selected.' );
            }

            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT term_id, term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s",
                $taxonomy
            ), ARRAY_A );

            if ( empty( $rows ) ) {
                wp_die( 'No term_taxonomy rows found.' );
            }

            $updated  = [];
            $term_ids = [];
            foreach ( $rows as $row ) {
                $term_id          = (int) $row['term_id'];
                $term_taxonomy_id = (int) $row['term_taxonomy_id'];
                $count = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(DISTINCT tr.object_id)
                     FROM {$wpdb->term_relationships} tr
                     INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id
                     WHERE tr.term_taxonomy_id = %d
                       AND p.post_type = %s
                       AND p.post_status = 'publish'",
                    $term_taxonomy_id,
                    $post_type
                ) );
                $wpdb->update( $wpdb->term_taxonomy, [ 'count' => $count ], [ 'term_taxonomy_id' => $term_taxonomy_id ], [ '%d' ], [ '%d' ] );
                $updated[ $term_taxonomy_id ] = $count;
                $term_ids[] = $term_id;
            }

            clean_term_cache( array_unique( $term_ids ), $taxonomy, false );
            $this->flush_caches( $taxonomy, $term_ids );

            echo '<div class="wrap"><h1>SQL Rebuild Complete</h1>';
            echo '<p>Counts were rebuilt for taxonomy <code>' . esc_html( $taxonomy ) . '</code> and post type <code>' . esc_html( $post_type ) . '</code>.</p>';
            echo '<p>Published posts only.</p><ul>';
            foreach ( $updated as $tt_id => $count ) {
                echo '<li><code>term_taxonomy_id ' . esc_html( (string) $tt_id ) . '</code> → <strong>' . esc_html( (string) $count ) . '</strong></li>';
            }
            echo '</ul><p><a class="button button-primary" href="' . esc_url( $this->tools_url( $taxonomy, $post_type ) ) . '">Back to tools</a></p></div>';
            exit;
        }

        private function cleanup_orphan_relationships( string $taxonomy, string $post_type, bool $dry_run, bool $confirm ): void {
            global $wpdb;

            $count_sql = $wpdb->prepare(
                "SELECT COUNT(*)
                 FROM {$wpdb->term_relationships} tr
                 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                 LEFT JOIN {$wpdb->posts} p ON p.ID = tr.object_id
                 WHERE p.ID IS NULL AND tt.taxonomy = %s",
                $taxonomy
            );
            $count = (int) $wpdb->get_var( $count_sql );

            if ( $dry_run || ! $confirm ) {
                $this->done_page( 'Orphan Cleanup Dry Run', 'Orphaned relationships found for taxonomy ' . $taxonomy . ': ' . $count . '. No rows were deleted.', $taxonomy, $post_type );
            }

            $deleted = (int) $wpdb->query( $wpdb->prepare(
                "DELETE tr
                 FROM {$wpdb->term_relationships} tr
                 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                 LEFT JOIN {$wpdb->posts} p ON p.ID = tr.object_id
                 WHERE p.ID IS NULL AND tt.taxonomy = %s",
                $taxonomy
            ) );

            $this->flush_caches( $taxonomy, [] );
            $this->done_page( 'Orphan Cleanup Complete', 'Deleted orphaned relationships for taxonomy ' . $taxonomy . ': ' . $deleted, $taxonomy, $post_type );
        }

        private function debug_output( string $taxonomy, string $post_type ): void {
            global $wpdb, $wp_taxonomies;
            $limit = defined( 'NK_TAX_COUNT_DEBUG_LIMIT' ) ? absint( NK_TAX_COUNT_DEBUG_LIMIT ) : 500;
            $limit = $limit > 0 ? min( $limit, 2000 ) : 500;
            $tax   = get_taxonomy( $taxonomy );

            echo '<div class="wrap"><h1>Taxonomy Count Debug</h1>';
            echo '<h2>Registration</h2><pre>';
            print_r( [
                'taxonomy'              => $taxonomy,
                'post_type'             => $post_type,
                'taxonomy_exists'       => taxonomy_exists( $taxonomy ),
                'post_type_exists'      => $post_type ? post_type_exists( $post_type ) : false,
                'object_type'           => $tax ? $tax->object_type : null,
                'update_count_callback' => isset( $wp_taxonomies[ $taxonomy ]->update_count_callback ) ? $wp_taxonomies[ $taxonomy ]->update_count_callback : null,
                'debug_limit'           => $limit,
            ] );
            echo '</pre>';

            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT t.term_id, t.name, t.slug, tt.term_taxonomy_id, tt.taxonomy, tt.count AS stored_count
                 FROM {$wpdb->terms} t
                 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
                 WHERE tt.taxonomy = %s
                 ORDER BY t.name ASC
                 LIMIT %d",
                $taxonomy,
                $limit
            ), ARRAY_A );
            echo '<h2>Stored Term Counts</h2><pre>'; print_r( $rows ); echo '</pre>';

            $relationships = $wpdb->get_results( $wpdb->prepare(
                "SELECT t.name AS term_name, t.slug, tt.term_taxonomy_id, p.ID, p.post_title, p.post_type, p.post_status
                 FROM {$wpdb->term_relationships} tr
                 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                 INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
                 INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id
                 WHERE tt.taxonomy = %s
                 ORDER BY t.name ASC, p.post_type ASC, p.post_status ASC, p.ID DESC
                 LIMIT %d",
                $taxonomy,
                $limit
            ), ARRAY_A );
            echo '<h2>Actual Relationships, limited</h2><pre>'; print_r( $relationships ); echo '</pre>';

            if ( $post_type ) {
                $sql_counts = $wpdb->get_results( $wpdb->prepare(
                    "SELECT t.name AS term_name, t.slug, tt.term_taxonomy_id,
                            COUNT(DISTINCT CASE WHEN p.post_type = %s AND p.post_status = 'publish' THEN p.ID END) AS published_cpt_count,
                            COUNT(DISTINCT p.ID) AS all_related_posts_count
                     FROM {$wpdb->term_taxonomy} tt
                     INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
                     LEFT JOIN {$wpdb->term_relationships} tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
                     LEFT JOIN {$wpdb->posts} p ON p.ID = tr.object_id
                     WHERE tt.taxonomy = %s
                     GROUP BY t.name, t.slug, tt.term_taxonomy_id
                     ORDER BY t.name ASC
                     LIMIT %d",
                    $post_type,
                    $taxonomy,
                    $limit
                ), ARRAY_A );
                echo '<h2>Computed Counts, limited</h2><pre>'; print_r( $sql_counts ); echo '</pre>';
            }

            echo '<p><a class="button button-primary" href="' . esc_url( $this->tools_url( $taxonomy, $post_type ) ) . '">Back to tools</a></p></div>';
            exit;
        }

        private function flush_caches( string $taxonomy = '', array $term_ids = [] ): void {
            if ( $taxonomy && function_exists( 'clean_taxonomy_cache' ) ) {
                clean_taxonomy_cache( $taxonomy );
            }
            if ( $taxonomy && ! empty( $term_ids ) ) {
                clean_term_cache( array_map( 'intval', $term_ids ), $taxonomy, false );
            }
            if ( defined( 'NK_TAX_COUNT_ALLOW_FULL_CACHE_PURGE' ) && NK_TAX_COUNT_ALLOW_FULL_CACHE_PURGE ) {
                // The native term and taxonomy caches were cleaned above.
                // Refresh public HTML without flushing the shared Redis DB.
                do_action( 'mad4b/public_html/safe_purge_all', 'taxonomy_counts_repaired' );
            }
        }

        private function done_page( string $title, string $message, string $taxonomy, string $post_type ): void {
            echo '<div class="wrap"><h1>' . esc_html( $title ) . '</h1><p>' . esc_html( $message ) . '</p>';
            echo '<p><a class="button button-primary" href="' . esc_url( $this->tools_url( $taxonomy, $post_type ) ) . '">Back to tools</a></p></div>';
            exit;
        }

        private function tools_url( string $taxonomy, string $post_type ): string {
            return add_query_arg( [
                'page'           => self::MENU_SLUG,
                'nktc_taxonomy'  => $taxonomy,
                'nktc_post_type' => $post_type,
            ], admin_url( 'tools.php' ) );
        }
    }

    new NK_Fix_Taxonomy_Counts_Dynamic_Safe();
}
