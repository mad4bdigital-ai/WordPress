<?php
/**
 * Plugin Name: TripKlik MU Meta Admin Toolkit - Safe
 * Description: Admin-only toolkit to inspect and bulk-manage post/term meta with dry-run, confirmation, batching, and array-safe WPML sync.
 * Version: 1.1.0
 * Author: OpenAI for Nagy
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( defined( 'TK_META_ADMIN_TOOLKIT_ENABLED' ) && ! TK_META_ADMIN_TOOLKIT_ENABLED ) {
    return;
}

if ( ! class_exists( 'TripKlik_MU_Meta_Admin_Toolkit_Safe' ) ) {
    final class TripKlik_MU_Meta_Admin_Toolkit_Safe {
        private const CAPABILITY       = 'manage_options';
        private const NONCE_ACTION     = 'tk_mu_meta_admin_action';
        private const ACTION_HOOK      = 'tk_mu_meta_admin_run';
        private const MENU_SLUG_PREFIX = 'tk-meta-admin-';
        private const NOTICE_TRANSIENT = 'tk_mu_meta_notice_';
        private const DEFAULT_LIMIT    = 1000;
        private const MAX_LIMIT        = 10000;

        public function __construct() {
            if ( ! is_admin() ) {
                return;
            }

            add_action( 'admin_menu', [ $this, 'register_admin_menus' ], 99 );
            add_action( 'admin_post_' . self::ACTION_HOOK, [ $this, 'handle_action' ] );
        }

        public function register_admin_menus(): void {
            if ( ! current_user_can( self::CAPABILITY ) ) {
                return;
            }

            foreach ( $this->get_supported_post_types() as $post_type => $obj ) {
                add_submenu_page(
                    'edit.php?post_type=' . $post_type,
                    __( 'Meta Toolkit', 'tk-mu-meta' ),
                    __( 'Meta Toolkit', 'tk-mu-meta' ),
                    self::CAPABILITY,
                    self::MENU_SLUG_PREFIX . 'post-' . $post_type,
                    function () use ( $post_type ) {
                        $this->render_page( 'post', $post_type );
                    }
                );
            }

            foreach ( $this->get_supported_taxonomies() as $taxonomy => $obj ) {
                $parent_slug = $this->get_taxonomy_parent_slug( $taxonomy, $obj );
                add_submenu_page(
                    $parent_slug,
                    __( 'Meta Toolkit', 'tk-mu-meta' ),
                    __( 'Meta Toolkit', 'tk-mu-meta' ),
                    self::CAPABILITY,
                    self::MENU_SLUG_PREFIX . 'term-' . $taxonomy,
                    function () use ( $taxonomy ) {
                        $this->render_page( 'term', $taxonomy );
                    }
                );
            }
        }

        private function get_supported_post_types(): array {
            $objects = get_post_types( [ 'public' => true ], 'objects' );
            unset( $objects['attachment'] );
            return is_array( $objects ) ? $objects : [];
        }

        private function get_supported_taxonomies(): array {
            $objects = get_taxonomies( [ 'public' => true ], 'objects' );
            return is_array( $objects ) ? $objects : [];
        }

        private function get_taxonomy_parent_slug( string $taxonomy, $taxonomy_object ): string {
            if ( $taxonomy_object && ! empty( $taxonomy_object->object_type ) && is_array( $taxonomy_object->object_type ) ) {
                foreach ( $taxonomy_object->object_type as $post_type ) {
                    if ( post_type_exists( $post_type ) && 'attachment' !== $post_type ) {
                        return 'edit-tags.php?taxonomy=' . rawurlencode( $taxonomy ) . '&post_type=' . rawurlencode( $post_type );
                    }
                }
            }

            return 'edit-tags.php?taxonomy=' . rawurlencode( $taxonomy );
        }

        private function render_page( string $mode, string $object_name ): void {
            if ( ! current_user_can( self::CAPABILITY ) ) {
                wp_die( esc_html__( 'You do not have permission to access this page.', 'tk-mu-meta' ) );
            }

            $notice              = $this->consume_notice();
            $translations_enabled = $this->is_wpml_available();
            $load_summary        = isset( $_GET['tk_load_summary'] ) && '1' === (string) $_GET['tk_load_summary'];
            $meta_summary        = $load_summary ? $this->get_meta_summary( $mode, $object_name ) : [];
            $sample_rows         = $this->get_sample_rows( $mode, $object_name );

            echo '<div class="wrap">';
            echo '<h1>' . esc_html( $this->page_title( $mode, $object_name ) ) . '</h1>';
            echo '<p>' . esc_html__( 'Admin-only metadata toolkit. Dry-run is enabled by default. Full-object updates require explicit confirmation.', 'tk-mu-meta' ) . '</p>';

            if ( $notice ) {
                printf(
                    '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
                    esc_attr( $notice['type'] ),
                    esc_html( $notice['message'] )
                );
            }

            echo '<div class="notice notice-info"><p><strong>Safety:</strong> leave Dry run enabled first. Empty target IDs means all items in this post type/taxonomy and requires the confirmation checkbox.</p></div>';
            echo '<hr>';
            $this->render_bulk_update_form( $mode, $object_name, $translations_enabled );
            echo '<hr>';
            $this->render_translation_sync_form( $mode, $object_name, $translations_enabled );
            echo '<hr>';

            if ( $load_summary ) {
                $this->render_meta_summary_table( $meta_summary );
            } else {
                $url = esc_url( add_query_arg( 'tk_load_summary', '1', $this->get_page_url( $mode, $object_name ) ) );
                echo '<h2>' . esc_html__( 'Detected meta keys', 'tk-mu-meta' ) . '</h2>';
                echo '<p>' . esc_html__( 'Meta summary can be heavy on large sites, so it is loaded only when requested.', 'tk-mu-meta' ) . '</p>';
                echo '<p><a class="button" href="' . $url . '">' . esc_html__( 'Load meta summary', 'tk-mu-meta' ) . '</a></p>';
            }

            echo '<hr>';
            $this->render_sample_rows_table( $sample_rows );
            echo '</div>';
        }

        private function page_title( string $mode, string $object_name ): string {
            return 'post' === $mode
                ? sprintf( 'Meta Toolkit — Post Type: %s', $object_name )
                : sprintf( 'Meta Toolkit — Taxonomy: %s', $object_name );
        }

        private function render_bulk_update_form( string $mode, string $object_name, bool $translations_enabled ): void {
            echo '<h2>' . esc_html__( 'Bulk update / insert meta', 'tk-mu-meta' ) . '</h2>';
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
            wp_nonce_field( self::NONCE_ACTION );
            echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_HOOK ) . '">';
            echo '<input type="hidden" name="tool_action" value="bulk_update">';
            echo '<input type="hidden" name="mode" value="' . esc_attr( $mode ) . '">';
            echo '<input type="hidden" name="object_name" value="' . esc_attr( $object_name ) . '">';

            echo '<table class="form-table" role="presentation">';
            $this->text_row( 'meta_key', 'Meta key', '', 'Examples: is_fetured, destination_type. Private keys starting with _ are blocked by default.' );
            $this->text_row( 'meta_value', 'Meta value', '', 'Stored as plain text for bulk update. Use translation sync for copying complex existing values safely.' );
            $this->text_row( 'target_ids', 'post' === $mode ? 'Optional post IDs' : 'Optional term IDs', '', 'Comma separated. Leave empty only if you intend to target all items in this screen object.' );
            $this->text_row( 'limit', 'Batch limit', (string) self::DEFAULT_LIMIT, 'Maximum items to process in this run. Max: ' . self::MAX_LIMIT );
            $this->select_row( 'missing_only', 'Write mode', [
                '0' => 'Update existing + insert missing',
                '1' => 'Insert missing only',
            ] );
            if ( $translations_enabled ) {
                $this->select_row( 'wpml_behavior', 'WPML behavior', [
                    'current_only'       => 'Current object items only',
                    'translation_group' => 'Apply to all translations of targeted IDs',
                ] );
            }
            echo '<tr><th scope="row">Dry run</th><td><label><input type="checkbox" name="dry_run" value="1" checked> Preview only, do not write changes</label></td></tr>';
            echo '<tr><th scope="row">Confirm all-items run</th><td><label><input type="checkbox" name="confirm_all" value="1"> I understand that empty target IDs can affect all items in this post type/taxonomy</label></td></tr>';
            echo '</table>';
            submit_button( __( 'Run bulk update', 'tk-mu-meta' ) );
            echo '</form>';
        }

        private function render_translation_sync_form( string $mode, string $object_name, bool $translations_enabled ): void {
            echo '<h2>' . esc_html__( 'WPML translation sync for one source item', 'tk-mu-meta' ) . '</h2>';
            if ( ! $translations_enabled ) {
                echo '<p>' . esc_html__( 'WPML was not detected. Translation sync tools are disabled.', 'tk-mu-meta' ) . '</p>';
                return;
            }

            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
            wp_nonce_field( self::NONCE_ACTION );
            echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_HOOK ) . '">';
            echo '<input type="hidden" name="tool_action" value="sync_translations">';
            echo '<input type="hidden" name="mode" value="' . esc_attr( $mode ) . '">';
            echo '<input type="hidden" name="object_name" value="' . esc_attr( $object_name ) . '">';

            echo '<table class="form-table" role="presentation">';
            $this->text_row( 'source_id', 'post' === $mode ? 'Source post ID' : 'Source term ID', '', 'Required. Copies the chosen meta key from this item to linked translations in the same WPML group.' );
            $this->text_row( 'sync_meta_key', 'Meta key', '', 'One key at a time. Complex values/arrays are preserved.' );
            echo '<tr><th scope="row">Dry run</th><td><label><input type="checkbox" name="dry_run" value="1" checked> Preview only, do not write changes</label></td></tr>';
            echo '</table>';
            submit_button( __( 'Sync this key to linked translations', 'tk-mu-meta' ), 'secondary' );
            echo '</form>';
        }

        private function render_meta_summary_table( array $rows ): void {
            echo '<h2>' . esc_html__( 'Detected meta keys', 'tk-mu-meta' ) . '</h2>';
            if ( empty( $rows ) ) {
                echo '<p>' . esc_html__( 'No meta keys were found for this object.', 'tk-mu-meta' ) . '</p>';
                return;
            }

            echo '<table class="widefat striped"><thead><tr><th>Meta key</th><th>Usage count</th><th>Sample values</th></tr></thead><tbody>';
            foreach ( $rows as $row ) {
                $samples = array_filter( [ $row['sample_1'] ?? '', $row['sample_2'] ?? '' ] );
                echo '<tr><td><code>' . esc_html( (string) $row['meta_key'] ) . '</code></td><td>' . esc_html( (string) $row['usage_count'] ) . '</td><td>' . esc_html( implode( ' | ', array_unique( $samples ) ) ) . '</td></tr>';
            }
            echo '</tbody></table>';
        }

        private function render_sample_rows_table( array $rows ): void {
            echo '<h2>' . esc_html__( 'Sample rows', 'tk-mu-meta' ) . '</h2>';
            if ( empty( $rows ) ) {
                echo '<p>' . esc_html__( 'No rows available.', 'tk-mu-meta' ) . '</p>';
                return;
            }

            echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Name</th><th>Status / Slug</th><th>Meta keys snapshot</th></tr></thead><tbody>';
            foreach ( $rows as $row ) {
                echo '<tr><td>' . esc_html( (string) $row['id'] ) . '</td><td>' . esc_html( (string) $row['label'] ) . '</td><td>' . esc_html( (string) $row['aux'] ) . '</td><td><code>' . esc_html( (string) $row['meta_keys'] ) . '</code></td></tr>';
            }
            echo '</tbody></table>';
        }

        private function text_row( string $name, string $label, string $value = '', string $description = '' ): void {
            echo '<tr><th scope="row"><label for="' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td>';
            echo '<input type="text" class="regular-text" name="' . esc_attr( $name ) . '" id="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">';
            if ( $description ) {
                echo '<p class="description">' . esc_html( $description ) . '</p>';
            }
            echo '</td></tr>';
        }

        private function select_row( string $name, string $label, array $options ): void {
            echo '<tr><th scope="row"><label for="' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td><select name="' . esc_attr( $name ) . '" id="' . esc_attr( $name ) . '">';
            foreach ( $options as $value => $caption ) {
                echo '<option value="' . esc_attr( (string) $value ) . '">' . esc_html( (string) $caption ) . '</option>';
            }
            echo '</select></td></tr>';
        }

        public function handle_action(): void {
            if ( ! current_user_can( self::CAPABILITY ) ) {
                wp_die( esc_html__( 'Unauthorized request.', 'tk-mu-meta' ) );
            }

            check_admin_referer( self::NONCE_ACTION );

            $mode        = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( (string) $_POST['mode'] ) ) : '';
            $object_name = isset( $_POST['object_name'] ) ? sanitize_key( wp_unslash( (string) $_POST['object_name'] ) ) : '';
            $tool_action = isset( $_POST['tool_action'] ) ? sanitize_key( wp_unslash( (string) $_POST['tool_action'] ) ) : '';

            if ( ! in_array( $mode, [ 'post', 'term' ], true ) || ! $this->object_is_supported( $mode, $object_name ) ) {
                $this->redirect_with_notice( $mode, $object_name, 'error', 'Invalid mode or object name.' );
            }

            if ( 'bulk_update' === $tool_action ) {
                $this->handle_bulk_update( $mode, $object_name );
            } elseif ( 'sync_translations' === $tool_action ) {
                $this->handle_translation_sync( $mode, $object_name );
            }

            $this->redirect_with_notice( $mode, $object_name, 'error', 'Unknown action.' );
        }

        private function object_is_supported( string $mode, string $object_name ): bool {
            return 'post' === $mode ? post_type_exists( $object_name ) : taxonomy_exists( $object_name );
        }

        private function normalize_meta_key( $raw ): string {
            $key = is_string( $raw ) ? sanitize_text_field( wp_unslash( $raw ) ) : '';
            $key = trim( $key );

            if ( '' === $key || strlen( $key ) > 191 || preg_match( '/[\x00-\x1F\x7F]/', $key ) ) {
                return '';
            }

            if ( '_' === substr( $key, 0, 1 ) && ! ( defined( 'TK_META_ADMIN_ALLOW_PRIVATE_KEYS' ) && TK_META_ADMIN_ALLOW_PRIVATE_KEYS ) ) {
                return '';
            }

            return $key;
        }

        private function handle_bulk_update( string $mode, string $object_name ): void {
            $meta_key      = $this->normalize_meta_key( $_POST['meta_key'] ?? '' );
            $meta_value    = isset( $_POST['meta_value'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['meta_value'] ) ) : '';
            $target_ids    = $this->parse_ids( $_POST['target_ids'] ?? '' );
            $missing_only  = ! empty( $_POST['missing_only'] );
            $wpml_behavior = isset( $_POST['wpml_behavior'] ) ? sanitize_key( wp_unslash( (string) $_POST['wpml_behavior'] ) ) : 'current_only';
            $dry_run       = ! empty( $_POST['dry_run'] );
            $confirm_all   = ! empty( $_POST['confirm_all'] );
            $limit         = $this->safe_limit( $_POST['limit'] ?? self::DEFAULT_LIMIT );

            if ( '' === $meta_key ) {
                $this->redirect_with_notice( $mode, $object_name, 'error', 'Meta key is required or blocked. Private keys starting with _ are disabled unless explicitly allowed.' );
            }

            if ( empty( $target_ids ) && ! $confirm_all ) {
                $this->redirect_with_notice( $mode, $object_name, 'error', 'Empty target IDs would affect all items. Check the confirmation box first.' );
            }

            $ids = ! empty( $target_ids ) ? $target_ids : $this->get_all_object_ids( $mode, $object_name, $limit );

            if ( 'translation_group' === $wpml_behavior && $this->is_wpml_available() ) {
                $ids = $this->expand_ids_to_translation_groups( $mode, $object_name, $ids );
            }

            $affected = 0;
            $skipped  = 0;
            foreach ( array_slice( array_unique( array_map( 'intval', $ids ) ), 0, $limit ) as $id ) {
                if ( 'post' === $mode ) {
                    if ( ! $this->validate_post_target( $id, $object_name ) ) {
                        $skipped++;
                        continue;
                    }
                    if ( $missing_only && metadata_exists( 'post', $id, $meta_key ) ) {
                        $skipped++;
                        continue;
                    }
                    if ( ! $dry_run ) {
                        $missing_only ? add_post_meta( $id, $meta_key, wp_slash( $meta_value ), true ) : update_post_meta( $id, $meta_key, wp_slash( $meta_value ) );
                    }
                    $affected++;
                } else {
                    if ( ! $this->validate_term_target( $id, $object_name ) ) {
                        $skipped++;
                        continue;
                    }
                    if ( $missing_only && metadata_exists( 'term', $id, $meta_key ) ) {
                        $skipped++;
                        continue;
                    }
                    if ( ! $dry_run ) {
                        $missing_only ? add_term_meta( $id, $meta_key, wp_slash( $meta_value ), true ) : update_term_meta( $id, $meta_key, wp_slash( $meta_value ) );
                    }
                    $affected++;
                }
            }

            $message = sprintf( '%s completed. Matched items: %d. Skipped: %d. Limit: %d.', $dry_run ? 'Dry run' : 'Bulk operation', $affected, $skipped, $limit );
            $this->redirect_with_notice( $mode, $object_name, $dry_run ? 'warning' : 'success', $message );
        }

        private function handle_translation_sync( string $mode, string $object_name ): void {
            if ( ! $this->is_wpml_available() ) {
                $this->redirect_with_notice( $mode, $object_name, 'error', 'WPML is not available on this site.' );
            }

            $source_id = isset( $_POST['source_id'] ) ? absint( $_POST['source_id'] ) : 0;
            $meta_key  = $this->normalize_meta_key( $_POST['sync_meta_key'] ?? '' );
            $dry_run   = ! empty( $_POST['dry_run'] );

            if ( ! $source_id || ! $meta_key ) {
                $this->redirect_with_notice( $mode, $object_name, 'error', 'Source ID and meta key are required.' );
            }

            if ( 'post' === $mode && ! $this->validate_post_target( $source_id, $object_name ) ) {
                $this->redirect_with_notice( $mode, $object_name, 'error', 'Source post does not belong to this post type.' );
            }

            if ( 'term' === $mode && ! $this->validate_term_target( $source_id, $object_name ) ) {
                $this->redirect_with_notice( $mode, $object_name, 'error', 'Source term does not belong to this taxonomy.' );
            }

            $source_value    = 'post' === $mode ? get_post_meta( $source_id, $meta_key, true ) : get_term_meta( $source_id, $meta_key, true );
            $translation_ids = $this->get_wpml_translation_ids( $mode, $object_name, $source_id );
            $synced          = 0;

            foreach ( $translation_ids as $translation_id ) {
                if ( (int) $translation_id === (int) $source_id ) {
                    continue;
                }
                if ( ! $dry_run ) {
                    'post' === $mode
                        ? update_post_meta( (int) $translation_id, $meta_key, wp_slash( $source_value ) )
                        : update_term_meta( (int) $translation_id, $meta_key, wp_slash( $source_value ) );
                }
                $synced++;
            }

            $message = sprintf( '%s completed. Translation targets: %d.', $dry_run ? 'Dry run' : 'WPML sync', $synced );
            $this->redirect_with_notice( $mode, $object_name, $dry_run ? 'warning' : 'success', $message );
        }

        private function safe_limit( $raw ): int {
            $limit = absint( $raw );
            if ( $limit <= 0 ) {
                $limit = self::DEFAULT_LIMIT;
            }
            return min( $limit, self::MAX_LIMIT );
        }

        private function get_all_object_ids( string $mode, string $object_name, int $limit ): array {
            if ( 'post' === $mode ) {
                return get_posts( [
                    'post_type'        => $object_name,
                    'post_status'      => [ 'publish', 'draft', 'pending', 'private', 'future' ],
                    'posts_per_page'   => $limit,
                    'fields'           => 'ids',
                    'orderby'          => 'ID',
                    'order'            => 'ASC',
                    'suppress_filters' => false,
                ] );
            }

            $terms = get_terms( [
                'taxonomy'   => $object_name,
                'hide_empty' => false,
                'fields'     => 'ids',
                'number'     => $limit,
            ] );

            return is_array( $terms ) ? array_map( 'intval', $terms ) : [];
        }

        private function validate_post_target( int $post_id, string $post_type ): bool {
            $post = get_post( $post_id );
            return $post instanceof WP_Post && $post->post_type === $post_type;
        }

        private function validate_term_target( int $term_id, string $taxonomy ): bool {
            $term = get_term( $term_id, $taxonomy );
            return $term instanceof WP_Term && ! is_wp_error( $term );
        }

        private function parse_ids( $raw ): array {
            if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
                return [];
            }
            return array_values( array_filter( array_map( 'absint', preg_split( '/\s*,\s*/', trim( $raw ) ) ) ) );
        }

        private function get_meta_summary( string $mode, string $object_name ): array {
            global $wpdb;
            if ( 'post' === $mode ) {
                $sql = $wpdb->prepare(
                    "SELECT pm.meta_key AS meta_key, COUNT(*) AS usage_count, MIN(CAST(pm.meta_value AS CHAR(255))) AS sample_1, MAX(CAST(pm.meta_value AS CHAR(255))) AS sample_2
                     FROM {$wpdb->postmeta} pm
                     INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                     WHERE p.post_type = %s AND pm.meta_key NOT LIKE '\\_%%'
                     GROUP BY pm.meta_key
                     ORDER BY usage_count DESC, pm.meta_key ASC
                     LIMIT 200",
                    $object_name
                );
            } else {
                $sql = $wpdb->prepare(
                    "SELECT tm.meta_key AS meta_key, COUNT(*) AS usage_count, MIN(CAST(tm.meta_value AS CHAR(255))) AS sample_1, MAX(CAST(tm.meta_value AS CHAR(255))) AS sample_2
                     FROM {$wpdb->termmeta} tm
                     INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = tm.term_id
                     WHERE tt.taxonomy = %s AND tm.meta_key NOT LIKE '\\_%%'
                     GROUP BY tm.meta_key
                     ORDER BY usage_count DESC, tm.meta_key ASC
                     LIMIT 200",
                    $object_name
                );
            }
            $rows = $wpdb->get_results( $sql, ARRAY_A );
            return is_array( $rows ) ? $rows : [];
        }

        private function get_sample_rows( string $mode, string $object_name ): array {
            if ( 'post' === $mode ) {
                $ids = get_posts( [
                    'post_type'        => $object_name,
                    'post_status'      => [ 'publish', 'draft', 'pending', 'private', 'future' ],
                    'posts_per_page'   => 10,
                    'fields'           => 'ids',
                    'orderby'          => 'ID',
                    'order'            => 'DESC',
                    'suppress_filters' => false,
                ] );
                $rows = [];
                foreach ( $ids as $id ) {
                    $post = get_post( $id );
                    if ( ! $post instanceof WP_Post ) {
                        continue;
                    }
                    $meta   = get_post_meta( $id );
                    $rows[] = [
                        'id'        => $id,
                        'label'     => $post->post_title,
                        'aux'       => $post->post_status,
                        'meta_keys' => implode( ', ', array_slice( array_keys( $meta ), 0, 15 ) ),
                    ];
                }
                return $rows;
            }

            $terms = get_terms( [ 'taxonomy' => $object_name, 'hide_empty' => false, 'number' => 10, 'orderby' => 'term_id', 'order' => 'DESC' ] );
            $rows  = [];
            if ( is_array( $terms ) ) {
                foreach ( $terms as $term ) {
                    if ( ! $term instanceof WP_Term ) {
                        continue;
                    }
                    $meta   = get_term_meta( $term->term_id );
                    $rows[] = [
                        'id'        => $term->term_id,
                        'label'     => $term->name,
                        'aux'       => $term->slug,
                        'meta_keys' => implode( ', ', array_slice( array_keys( $meta ), 0, 15 ) ),
                    ];
                }
            }
            return $rows;
        }

        private function is_wpml_available(): bool {
            if ( defined( 'ICL_SITEPRESS_VERSION' ) || function_exists( 'icl_object_id' ) ) {
                return true;
            }
            global $wpdb;
            $table = $wpdb->prefix . 'icl_translations';
            return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table;
        }

        private function get_wpml_element_type( string $mode, string $object_name ): string {
            return 'post' === $mode ? 'post_' . $object_name : 'tax_' . $object_name;
        }

        private function get_wpml_translation_ids( string $mode, string $object_name, int $source_id ): array {
            global $wpdb;
            $table        = $wpdb->prefix . 'icl_translations';
            $element_type = $this->get_wpml_element_type( $mode, $object_name );
            $trid         = $wpdb->get_var( $wpdb->prepare( "SELECT trid FROM {$table} WHERE element_id = %d AND element_type = %s LIMIT 1", $source_id, $element_type ) );
            if ( ! $trid ) {
                return [ $source_id ];
            }
            $ids = $wpdb->get_col( $wpdb->prepare( "SELECT element_id FROM {$table} WHERE trid = %d AND element_type = %s", (int) $trid, $element_type ) );
            return array_map( 'intval', is_array( $ids ) ? $ids : [ $source_id ] );
        }

        private function expand_ids_to_translation_groups( string $mode, string $object_name, array $ids ): array {
            $expanded = [];
            foreach ( $ids as $id ) {
                $expanded = array_merge( $expanded, $this->get_wpml_translation_ids( $mode, $object_name, (int) $id ) );
            }
            return array_values( array_unique( array_map( 'intval', $expanded ) ) );
        }

        private function redirect_with_notice( string $mode, string $object_name, string $type, string $message ): void {
            $key = self::NOTICE_TRANSIENT . get_current_user_id();
            set_transient( $key, [ 'type' => sanitize_html_class( $type ), 'message' => sanitize_text_field( $message ) ], 60 );
            wp_safe_redirect( $this->get_page_url( $mode, $object_name ) );
            exit;
        }

        private function get_page_url( string $mode, string $object_name ): string {
            if ( 'post' === $mode ) {
                return admin_url( 'edit.php?post_type=' . rawurlencode( $object_name ) . '&page=' . self::MENU_SLUG_PREFIX . 'post-' . rawurlencode( $object_name ) );
            }
            $taxonomy    = get_taxonomy( $object_name );
            $parent_slug = $this->get_taxonomy_parent_slug( $object_name, $taxonomy );
            return admin_url( $parent_slug . '&page=' . self::MENU_SLUG_PREFIX . 'term-' . rawurlencode( $object_name ) );
        }

        private function consume_notice(): ?array {
            $key    = self::NOTICE_TRANSIENT . get_current_user_id();
            $notice = get_transient( $key );
            if ( is_array( $notice ) ) {
                delete_transient( $key );
                return $notice;
            }
            return null;
        }
    }

    new TripKlik_MU_Meta_Admin_Toolkit_Safe();
}
