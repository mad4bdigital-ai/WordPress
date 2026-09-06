<?php
namespace ETG\DynamicFilterSEOBridge\Admin;

use ETG\DynamicFilterSEOBridge\Config\Configuration;
use ETG\DynamicFilterSEOBridge\Config\ProfileRegistry;
use ETG\DynamicFilterSEOBridge\Diagnostics\InventoryProfilePlanner;
use ETG\DynamicFilterSEOBridge\Diagnostics\RuntimeInventory;

final class InventoryControlPage {
    const SLUG = 'etg-dfsb-inventory-control';
    private $config;
    private $profiles;
    private $inventory;
    private $planner;

    public function __construct( Configuration $config, ProfileRegistry $profiles, RuntimeInventory $inventory, InventoryProfilePlanner $planner ) {
        $this->config = $config;
        $this->profiles = $profiles;
        $this->inventory = $inventory;
        $this->planner = $planner;
    }

    public function register(): void {
        add_action( 'admin_menu', array( $this, 'menu' ) );
        add_action( 'admin_post_etg_dfsb_apply_inventory_profile_plan', array( $this, 'apply' ) );
    }

    public function menu(): void {
        add_options_page( 'ETG Inventory Control', 'ETG Inventory Control', 'manage_options', self::SLUG, array( $this, 'render' ) );
    }

    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        $snapshot = $this->inventory->collect();
        $plan = $this->planner->plan( $snapshot, $this->profiles->all() );
        $config = $this->config->all();
        $revision = $this->config->revision();
        $globalOn = ! empty( $config['enabled'] );
        ?>
        <div class="wrap">
            <h1>ETG Inventory Control</h1>
            <p><strong>Inventory-driven control plane.</strong> Runtime evidence can propose exact structural bindings and content taxonomies, but it never enables Global, a profile, an indexing set, a combination, or publication evidence automatically.</p>
            <p><strong>Global:</strong> <?php echo $globalOn ? '<span style="color:#b32d2e">ON — apply is locked</span>' : '<span style="color:#008a20">OFF — safe planning mode</span>'; ?> &nbsp; <strong>Inventory:</strong> <code><?php echo esc_html( (string) ( $plan['snapshot_fingerprint'] ?? '' ) ); ?></code> &nbsp; <strong>Config:</strong> <code><?php echo esc_html( $revision ); ?></code></p>
            <?php if ( isset( $_GET['applied'] ) ): ?><div class="notice notice-success is-dismissible"><p>Inventory profile patch applied in disabled/fail-closed mode. Re-run Reconciliation before any activation decision.</p></div><?php endif; ?>
            <?php if ( isset( $_GET['error'] ) ): ?><div class="notice notice-error"><p><?php echo esc_html( sanitize_text_field( (string) wp_unslash( $_GET['error'] ) ) ); ?></p></div><?php endif; ?>
            <p><a class="button" href="<?php echo esc_url( add_query_arg( array( 'page'=>'etg-filter-seo', 'tab'=>'reconciliation', 'etg_reconcile_runtime_inventory'=>'1' ), admin_url( 'options-general.php' ) ) ); ?>">Reconcile Current Inventory</a> <a class="button" href="<?php echo esc_url( add_query_arg( array( 'page'=>'etg-dfsb-dynamic-content' ), admin_url( 'options-general.php' ) ) ); ?>">Open Dynamic Content</a></p>

            <?php foreach ( (array) ( $plan['proposals'] ?? array() ) as $profileId => $proposal ): ?>
                <?php $safe = ! empty( $proposal['safe_to_apply'] ); $candidates = (array) ( $proposal['taxonomy_candidates'] ?? array() ); ?>
                <div class="card" style="max-width:none;padding:18px;margin-top:18px">
                    <h2>Profile: <code><?php echo esc_html( (string) $profileId ); ?></code> — <?php echo esc_html( strtoupper( (string) ( $proposal['status'] ?? 'unknown' ) ) ); ?></h2>
                    <?php if ( ! empty( $proposal['blocking_reasons'] ) ): ?><p><strong>Blocked:</strong> <?php echo esc_html( implode( ', ', (array) $proposal['blocking_reasons'] ) ); ?></p><?php endif; ?>
                    <h3>Verified route evidence</h3>
                    <pre style="max-height:260px;overflow:auto;background:#f6f7f7;padding:12px"><?php echo esc_html( wp_json_encode( (array) ( $proposal['route_evidence'] ?? array() ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); ?></pre>
                    <h3>Safe structural changes</h3>
                    <?php if ( empty( $proposal['changes'] ) ): ?><p>No structural changes are currently required.</p><?php else: ?>
                        <table class="widefat striped"><thead><tr><th>Field</th><th>Current</th><th>Proposed</th></tr></thead><tbody>
                        <?php foreach ( (array) $proposal['changes'] as $change ): ?><tr><td><code><?php echo esc_html( (string) $change['field'] ); ?></code></td><td><code><?php echo esc_html( wp_json_encode( $change['from'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); ?></code></td><td><code><?php echo esc_html( wp_json_encode( $change['to'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); ?></code></td></tr><?php endforeach; ?>
                        </tbody></table>
                    <?php endif; ?>

                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <input type="hidden" name="action" value="etg_dfsb_apply_inventory_profile_plan" />
                        <input type="hidden" name="profile_id" value="<?php echo esc_attr( (string) $profileId ); ?>" />
                        <input type="hidden" name="snapshot_fingerprint" value="<?php echo esc_attr( (string) ( $plan['snapshot_fingerprint'] ?? '' ) ); ?>" />
                        <input type="hidden" name="config_revision" value="<?php echo esc_attr( $revision ); ?>" />
                        <?php wp_nonce_field( 'etg_dfsb_apply_inventory_profile_plan' ); ?>

                        <h3>Optional content taxonomies from Inventory</h3>
                        <p>Selecting a taxonomy adds a disabled-profile taxonomy rule for presentation/filter context only. It is <strong>not</strong> added to <code>allowed_taxonomy_sets</code> or <code>indexable_combinations</code>, so no indexing shape is granted.</p>
                        <table class="widefat striped"><thead><tr><th>Use</th><th>Taxonomy</th><th>Role</th><th>Configured</th><th>Attached Post Types</th></tr></thead><tbody>
                        <?php foreach ( $candidates as $taxonomy => $candidate ): ?>
                            <tr>
                                <td><?php if ( empty( $candidate['configured'] ) ): ?><input type="checkbox" name="taxonomy_select[]" value="<?php echo esc_attr( (string) $taxonomy ); ?>" /><?php else: ?>—<?php endif; ?></td>
                                <td><code><?php echo esc_html( (string) $taxonomy ); ?></code><br><?php echo esc_html( (string) ( $candidate['label'] ?? '' ) ); ?></td>
                                <td><?php if ( empty( $candidate['configured'] ) ): ?><input type="text" name="taxonomy_role[<?php echo esc_attr( (string) $taxonomy ); ?>]" value="<?php echo esc_attr( (string) ( $candidate['suggested_role'] ?? $taxonomy ) ); ?>" /><?php else: ?><code><?php echo esc_html( (string) ( $candidate['suggested_role'] ?? $taxonomy ) ); ?></code><?php endif; ?></td>
                                <td><?php echo ! empty( $candidate['configured'] ) ? 'Yes' : 'No'; ?></td>
                                <td><code><?php echo esc_html( implode( ', ', (array) ( $candidate['attached_post_types'] ?? array() ) ) ); ?></code></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody></table>
                        <p><label><input type="checkbox" name="confirm_fail_closed" value="1" required /> I understand this action forces the profile disabled, keeps Global OFF, and invalidates prior publication-verification evidence.</label></p>
                        <p><button type="submit" class="button button-primary" <?php disabled( $globalOn || ! $safe ); ?>>Apply Safe Disabled Profile Patch</button></p>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
    }

    public function apply(): void {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Forbidden', 403 ); }
        check_admin_referer( 'etg_dfsb_apply_inventory_profile_plan' );
        if ( empty( $_POST['confirm_fail_closed'] ) ) { $this->redirectError( 'Explicit fail-closed confirmation is required.' ); }
        $config = $this->config->all();
        if ( ! empty( $config['enabled'] ) ) { $this->redirectError( 'Global bridge must be OFF before applying an Inventory profile patch.' ); }
        $expectedRevision = isset( $_POST['config_revision'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['config_revision'] ) ) : '';
        if ( '' === $expectedRevision || ! hash_equals( $expectedRevision, $this->config->revision() ) ) { $this->redirectError( 'Configuration changed after the proposal was generated. Refresh and review again.' ); }

        $snapshot = $this->inventory->collect();
        $expectedFingerprint = isset( $_POST['snapshot_fingerprint'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['snapshot_fingerprint'] ) ) : '';
        $actualFingerprint = (string) ( $snapshot['snapshot_fingerprint'] ?? '' );
        if ( '' === $expectedFingerprint || ! hash_equals( $expectedFingerprint, $actualFingerprint ) ) { $this->redirectError( 'Runtime Inventory changed after the proposal was generated. Refresh and review again.' ); }

        $profileId = isset( $_POST['profile_id'] ) ? sanitize_key( wp_unslash( (string) $_POST['profile_id'] ) ) : '';
        $plan = $this->planner->plan( $snapshot, $this->profiles->all() );
        $proposal = (array) ( $plan['proposals'][ $profileId ] ?? array() );
        if ( ! $proposal || empty( $proposal['safe_to_apply'] ) ) { $this->redirectError( 'The selected profile does not have a safe verified Inventory proposal.' ); }

        $rawProfiles = json_decode( (string) ( $config['profiles_json'] ?? '' ), true );
        if ( ! is_array( $rawProfiles ) ) { $this->redirectError( 'Surface Profiles JSON is unavailable.' ); }
        $index = null;
        foreach ( $rawProfiles as $i => $profile ) {
            if ( is_array( $profile ) && $profileId === sanitize_key( (string) ( $profile['id'] ?? '' ) ) ) { $index = $i; break; }
        }
        if ( null === $index ) { $this->redirectError( 'Profile no longer exists.' ); }

        $raw = (array) $rawProfiles[ $index ];
        $proposed = (array) ( $proposal['proposed_profile'] ?? array() );
        foreach ( array( 'post_types', 'require_post_type_binding', 'post_type_authority', 'routes' ) as $field ) {
            if ( array_key_exists( $field, $proposed ) ) { $raw[ $field ] = $proposed[ $field ]; }
        }
        if ( empty( $raw['archive_paths'] ) && ! empty( $proposed['archive_paths'] ) ) { $raw['archive_paths'] = $proposed['archive_paths']; }
        $raw['enabled'] = false;

        $rules = is_array( $raw['taxonomy_rules'] ?? null ) ? $raw['taxonomy_rules'] : array();
        $priority = 0;
        foreach ( $rules as $rule ) { if ( is_array( $rule ) ) { $priority = max( $priority, (int) ( $rule['priority'] ?? 0 ) ); } }
        $selected = isset( $_POST['taxonomy_select'] ) ? (array) wp_unslash( $_POST['taxonomy_select'] ) : array();
        $roles = isset( $_POST['taxonomy_role'] ) && is_array( $_POST['taxonomy_role'] ) ? wp_unslash( $_POST['taxonomy_role'] ) : array();
        $candidates = (array) ( $proposal['taxonomy_candidates'] ?? array() );
        foreach ( array_slice( $selected, 0, 20 ) as $taxonomy ) {
            $taxonomy = sanitize_key( (string) $taxonomy );
            if ( '' === $taxonomy || ! isset( $candidates[ $taxonomy ] ) || ! empty( $candidates[ $taxonomy ]['configured'] ) ) { continue; }
            $role = sanitize_key( (string) ( $roles[ $taxonomy ] ?? $candidates[ $taxonomy ]['suggested_role'] ?? $taxonomy ) );
            if ( '' === $role ) { $role = $taxonomy; }
            $priority += 10;
            $rules[ $taxonomy ] = array(
                'role'=>$role, 'priority'=>$priority, 'gallery_priority'=>$priority,
                'index_single'=>false, 'min_results'=>3,
                'required_meta_key'=>'', 'required_meta_values'=>array(), 'meta_constraint_scope'=>'single', 'field_map'=>array(),
            );
        }
        $raw['taxonomy_rules'] = $rules;

        $publication = is_array( $raw['publication'] ?? null ) ? $raw['publication'] : array();
        foreach ( array( 'elementor_content_verified', 'provider_observation_verified', 'result_count_parity_verified' ) as $flag ) { $publication[ $flag ] = false; }
        foreach ( array( 'elementor_verification_evidence_id', 'provider_observation_evidence_id', 'result_count_parity_evidence_id' ) as $field ) { $publication[ $field ] = ''; }
        $raw['publication'] = $publication;
        $rawProfiles[ $index ] = $raw;

        $config['enabled'] = false;
        $config['profiles_json'] = wp_json_encode( $rawProfiles, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        $saved = update_option( Configuration::OPTION_NAME, $this->config->sanitize( $config ), false );
        if ( false === $saved && $this->config->revision() === $expectedRevision ) { $this->redirectError( 'WordPress did not persist the Inventory profile patch.' ); }
        wp_safe_redirect( add_query_arg( array( 'page'=>self::SLUG, 'applied'=>'1', 'profile'=>$profileId ), admin_url( 'options-general.php' ) ) );
        exit;
    }

    private function redirectError( string $message ): void {
        wp_safe_redirect( add_query_arg( array( 'page'=>self::SLUG, 'error'=>$message ), admin_url( 'options-general.php' ) ) );
        exit;
    }
}
