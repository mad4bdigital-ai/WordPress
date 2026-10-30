<?php
/**
 * Plugin Name: WPML Meta ID Mapper UI
 * Description: Map post/term IDs inside selected meta fields to their WPML translated IDs with an admin UI.
 * Version: 1.1.0
 * Author: Abdullah
 */

if (!defined('ABSPATH')) {
    exit;
}

final class WPML_Meta_ID_Mapper_UI {
    private const OPTION_RULES = 'wpml_meta_id_mapper_rules';
    private const OPTION_TRANSLATIONS_ONLY = 'wpml_meta_id_mapper_translations_only';
    private const OPTION_RAW_SOURCE_LANG = 'wpml_meta_id_mapper_raw_source_lang';
    private const OPTION_REPEATER_RULES = 'wpml_meta_id_mapper_repeater_rules';
    private const NONCE_ACTION = 'wpml_meta_id_mapper_save_settings';
    private const NONCE_RAW_ACTION = 'wpml_meta_id_mapper_save_raw_settings';
    private const NONCE_REPEATER_ACTION = 'wpml_meta_id_mapper_save_repeater';
    private const NONCE_BULK_ACTION = 'wpml_meta_id_mapper_bulk_sync';
    private const NONCE_RAW_BULK_ACTION = 'wpml_meta_id_mapper_bulk_sync_raw';
    private const NONCE_REPEATER_BULK_ACTION = 'wpml_meta_id_mapper_bulk_sync_repeater';
    private const TRANSIENT_RAW_STATS_PREFIX = 'wpml_meta_id_mapper_raw_stats_';
    private $raw_last_updated_langs = [];

    public function __construct() {
        add_action('admin_menu', [$this, 'register_admin_page']);
        add_action('admin_post_wpml_meta_id_mapper_save', [$this, 'handle_save_settings']);
        add_action('admin_post_wpml_meta_id_mapper_save_raw', [$this, 'handle_save_raw_settings']);
        add_action('admin_post_wpml_meta_id_mapper_save_repeater', [$this, 'handle_save_repeater_settings']);
        add_action('admin_post_wpml_meta_id_mapper_bulk_sync', [$this, 'handle_bulk_sync']);
        add_action('admin_post_wpml_meta_id_mapper_bulk_sync_raw', [$this, 'handle_bulk_sync_raw']);
        add_action('admin_post_wpml_meta_id_mapper_bulk_sync_repeater', [$this, 'handle_bulk_sync_repeater']);
        add_filter('wpml_sync_custom_field_copied_value', [$this, 'filter_wpml_copied_value'], 50, 4);
        add_action('wpml_after_save_post', [$this, 'handle_wpml_after_save_post'], 50, 4);
        add_action('wpml_pro_translation_completed', [$this, 'handle_wpml_translation_completed'], 50, 3);
        add_action('pmxi_saved_post', [$this, 'handle_pmxi_saved_post'], 50, 3);
        add_action('save_post', [$this, 'handle_fallback_save_post'], 999, 3);
        add_action('created_term', [$this, 'handle_fallback_term_sync'], 999, 3);
        add_action('edited_term', [$this, 'handle_fallback_term_sync'], 999, 3);
    }

    public function register_admin_page() {
        add_menu_page(
            'WPML Meta ID Mapper',
            'WPML ID Mapper',
            'manage_options',
            'wpml-meta-id-mapper',
            [$this, 'render_admin_page'],
            'dashicons-translation'
        );
        add_submenu_page(
            'wpml-meta-id-mapper',
            'WPML Meta ID Mapper',
            'Mapping Rules',
            'manage_options',
            'wpml-meta-id-mapper',
            [$this, 'render_admin_page']
        );
        add_submenu_page(
            'wpml-meta-id-mapper',
            'WPML Raw Copy Sync',
            'Raw Copy Sync',
            'manage_options',
            'wpml-raw-copy-sync',
            [$this, 'render_raw_copy_page']
        );
        add_submenu_page(
            'wpml-meta-id-mapper',
            'WPML Repeater Mapper',
            'Repeater Mapper',
            'manage_options',
            'wpml-repeater-mapper',
            [$this, 'render_repeater_page']
        );
    }

    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized.');
        }

        $rules = $this->get_mapper_rules();
        $translations_only = (int) get_option(self::OPTION_TRANSLATIONS_ONLY, 1) === 1;
        ?>
        <div class="wrap">
            <h1>WPML Meta ID Mapper</h1>
            <p>Define conversion rules for meta fields that store post/term relationships.</p>

            <?php if (!defined('ICL_SITEPRESS_VERSION')): ?>
                <div class="notice notice-warning"><p>WPML not detected. Rules are saved, but mapping will not run until WPML is active.</p></div>
            <?php elseif (!$this->wpml_tables_ready()): ?>
                <div class="notice notice-error"><p>WPML table check failed: <code><?php echo esc_html($GLOBALS['wpdb']->prefix . 'icl_translations'); ?></code> not accessible.</p></div>
            <?php endif; ?>

            <?php if (!empty($_GET['updated'])): ?>
                <div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>
            <?php endif; ?>
            <?php if (!empty($_GET['bulk_done'])): ?>
                <div class="notice notice-success is-dismissible">
                    <p>
                        Bulk sync finished.
                        Scanned: <?php echo isset($_GET['scanned']) ? (int) $_GET['scanned'] : 0; ?>,
                        Updated: <?php echo isset($_GET['changed']) ? (int) $_GET['changed'] : 0; ?>
                    </p>
                </div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="wpml_meta_id_mapper_save" />
                <?php wp_nonce_field(self::NONCE_ACTION); ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Apply on translated posts only</th>
                        <td>
                            <label>
                                <input type="checkbox" name="translations_only" value="1" <?php checked($translations_only); ?> />
                                Run mapping only when the saved post is a WPML translation.
                            </label>
                        </td>
                    </tr>
                </table>

                <h2>Mapping Rules</h2>
                <p>Each rule maps values to translated IDs using <code>post</code> or <code>term</code>.</p>

                <table class="widefat striped" id="wpml-mapper-rules-table">
                    <thead>
                    <tr>
                        <th>Post Type</th>
                        <th>Meta Key</th>
                        <th>Type</th>
                        <th>Object Type</th>
                        <th>Storage Mode</th>
                        <th>Remove</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($rules)): ?>
                        <tr class="no-items"><td colspan="6">No rules yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($rules as $idx => $rule): ?>
                            <tr>
                                <td><input type="text" class="regular-text" name="rules[<?php echo (int) $idx; ?>][post_type]" value="<?php echo esc_attr($rule['post_type']); ?>" /></td>
                                <td><input type="text" class="regular-text" name="rules[<?php echo (int) $idx; ?>][meta_key]" value="<?php echo esc_attr($rule['meta_key']); ?>" /></td>
                                <td>
                                    <select name="rules[<?php echo (int) $idx; ?>][type]">
                                        <option value="post" <?php selected($rule['type'], 'post'); ?>>post</option>
                                        <option value="term" <?php selected($rule['type'], 'term'); ?>>term</option>
                                    </select>
                                </td>
                                <td><input type="text" class="regular-text" name="rules[<?php echo (int) $idx; ?>][object_type]" value="<?php echo esc_attr($rule['object_type']); ?>" /></td>
                                <td>
                                    <select name="rules[<?php echo (int) $idx; ?>][storage_mode]">
                                        <option value="auto" <?php selected(isset($rule['storage_mode']) ? $rule['storage_mode'] : 'auto', 'auto'); ?>>auto</option>
                                        <option value="id" <?php selected(isset($rule['storage_mode']) ? $rule['storage_mode'] : 'auto', 'id'); ?>>id</option>
                                        <option value="name_key_map" <?php selected(isset($rule['storage_mode']) ? $rule['storage_mode'] : 'auto', 'name_key_map'); ?>>name_key_map</option>
                                    </select>
                                </td>
                                <td><button type="button" class="button-link-delete wpml-mapper-remove-row">Remove</button></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>

                <p>
                    <button type="button" class="button" id="wpml-mapper-add-row">Add Rule</button>
                </p>

                <?php submit_button('Save Settings'); ?>
            </form>

            <p>
                Raw value copy: <a href="<?php echo esc_url(admin_url('admin.php?page=wpml-raw-copy-sync')); ?>">WPML Raw Copy Sync</a>.
                Repeater fields (Object IDs): <a href="<?php echo esc_url(admin_url('admin.php?page=wpml-repeater-mapper')); ?>">WPML Repeater Mapper</a>.
            </p>

            <hr />
            <h2>Bulk Auto Update</h2>
            <p>Run mapping on existing posts that match current rules, without opening each post manually.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="wpml_meta_id_mapper_bulk_sync" />
                <?php wp_nonce_field(self::NONCE_BULK_ACTION); ?>
                <?php submit_button('Run Bulk Sync Now', 'secondary', 'submit', false); ?>
            </form>
        </div>

        <script>
            (function () {
                const tableBody = document.querySelector('#wpml-mapper-rules-table tbody');
                const addBtn = document.getElementById('wpml-mapper-add-row');

                function nextIndex() {
                    const inputs = tableBody.querySelectorAll('input[name^="rules["]');
                    let max = -1;
                    inputs.forEach((el) => {
                        const match = el.name.match(/rules\[(\d+)]/);
                        if (match) {
                            const n = parseInt(match[1], 10);
                            if (n > max) {
                                max = n;
                            }
                        }
                    });
                    return max + 1;
                }

                function removeNoItemsRow() {
                    const noItems = tableBody.querySelector('.no-items');
                    if (noItems) {
                        noItems.remove();
                    }
                }

                addBtn.addEventListener('click', function () {
                    removeNoItemsRow();
                    const idx = nextIndex();
                    const tr = document.createElement('tr');
                    tr.innerHTML = `
                        <td><input type="text" class="regular-text" name="rules[${idx}][post_type]" value="" /></td>
                        <td><input type="text" class="regular-text" name="rules[${idx}][meta_key]" value="" /></td>
                        <td>
                            <select name="rules[${idx}][type]">
                                <option value="post">post</option>
                                <option value="term">term</option>
                            </select>
                        </td>
                        <td><input type="text" class="regular-text" name="rules[${idx}][object_type]" value="" /></td>
                        <td>
                            <select name="rules[${idx}][storage_mode]">
                                <option value="auto">auto</option>
                                <option value="id">id</option>
                                <option value="name_key_map">name_key_map</option>
                            </select>
                        </td>
                        <td><button type="button" class="button-link-delete wpml-mapper-remove-row">Remove</button></td>
                    `;
                    tableBody.appendChild(tr);
                });

                tableBody.addEventListener('click', function (e) {
                    if (!e.target.classList.contains('wpml-mapper-remove-row')) {
                        return;
                    }
                    const row = e.target.closest('tr');
                    if (row) {
                        row.remove();
                    }
                    if (!tableBody.querySelector('tr')) {
                        const tr = document.createElement('tr');
                        tr.className = 'no-items';
                        tr.innerHTML = '<td colspan="6">No rules yet.</td>';
                        tableBody.appendChild(tr);
                    }
                });
            })();
        </script>
        <?php
    }

    public function handle_save_settings() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized.');
        }

        check_admin_referer(self::NONCE_ACTION);

        $raw_rules = isset($_POST['rules']) && is_array($_POST['rules']) ? $_POST['rules'] : [];
        $clean = [];

        foreach ($raw_rules as $rule) {
            $post_type = isset($rule['post_type']) ? sanitize_key($rule['post_type']) : '';
            $meta_key = isset($rule['meta_key']) ? sanitize_text_field($rule['meta_key']) : '';
            $type = isset($rule['type']) ? sanitize_key($rule['type']) : '';
            $object_type = isset($rule['object_type']) ? sanitize_key($rule['object_type']) : '';
            $storage_mode = isset($rule['storage_mode']) ? sanitize_key($rule['storage_mode']) : 'auto';

            if ($post_type === '' || $meta_key === '') {
                continue;
            }
            if (!in_array($type, ['post', 'term'], true)) {
                continue;
            }
            if ($object_type === '') {
                continue;
            }
            if (!in_array($storage_mode, ['auto', 'id', 'name_key_map'], true)) {
                $storage_mode = 'auto';
            }

            $clean[] = [
                'post_type' => $post_type,
                'meta_key' => $meta_key,
                'type' => $type,
                'object_type' => $object_type,
                'storage_mode' => $storage_mode,
            ];
        }

        $all_rules = array_merge($clean, $this->get_raw_rules());
        update_option(self::OPTION_RULES, $all_rules, false);
        update_option(self::OPTION_TRANSLATIONS_ONLY, isset($_POST['translations_only']) ? 1 : 0, false);

        $url = add_query_arg(
            [
                'page' => 'wpml-meta-id-mapper',
                'updated' => '1',
            ],
            admin_url('admin.php')
        );

        wp_safe_redirect($url);
        exit;
    }

    public function render_raw_copy_page() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized.');
        }

        $rules = $this->get_raw_rules();
        $raw_source_lang = (string) get_option(self::OPTION_RAW_SOURCE_LANG, 'en');
        ?>
        <div class="wrap">
            <h1>WPML Raw Copy Sync</h1>
            <p>Copy raw meta values from the source language item to all translated items (force overwrite). Supports posts and taxonomies.</p>

            <?php if (!empty($_GET['raw_updated'])): ?>
                <div class="notice notice-success is-dismissible"><p>Raw copy settings saved.</p></div>
            <?php endif; ?>
            <?php if (!defined('ICL_SITEPRESS_VERSION')): ?>
                <div class="notice notice-warning"><p>WPML not detected.</p></div>
            <?php elseif (!$this->wpml_tables_ready()): ?>
                <div class="notice notice-error"><p>WPML table check failed: <code><?php echo esc_html($GLOBALS['wpdb']->prefix . 'icl_translations'); ?></code> not accessible.</p></div>
            <?php endif; ?>
            <?php if (!empty($_GET['raw_bulk_done'])): ?>
                <div class="notice notice-success is-dismissible">
                    <p>
                        Raw sync finished.
                        Scanned: <?php echo isset($_GET['scanned']) ? (int) $_GET['scanned'] : 0; ?>,
                        Updated: <?php echo isset($_GET['updated_items']) ? (int) $_GET['updated_items'] : (isset($_GET['changed']) ? (int) $_GET['changed'] : 0); ?>
                    </p>
                </div>
                <?php
                $stats_key = self::TRANSIENT_RAW_STATS_PREFIX . get_current_user_id();
                $stats = get_transient($stats_key);
                if (is_array($stats) && (!empty($stats['scanned_by_lang']) || !empty($stats['updated_by_lang']))) :
                ?>
                    <h2>Sync Breakdown By Language</h2>
                    <table class="widefat striped">
                        <thead>
                        <tr>
                            <th>Language</th>
                            <th>Scanned</th>
                            <th>Updated</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php
                        $langs = [];
                        if (!empty($stats['scanned_by_lang']) && is_array($stats['scanned_by_lang'])) {
                            $langs = array_merge($langs, array_keys($stats['scanned_by_lang']));
                        }
                        if (!empty($stats['updated_by_lang']) && is_array($stats['updated_by_lang'])) {
                            $langs = array_merge($langs, array_keys($stats['updated_by_lang']));
                        }
                        $langs = array_values(array_unique($langs));
                        sort($langs);
                        foreach ($langs as $lang_code):
                            $sc = isset($stats['scanned_by_lang'][$lang_code]) ? (int) $stats['scanned_by_lang'][$lang_code] : 0;
                            $up = isset($stats['updated_by_lang'][$lang_code]) ? (int) $stats['updated_by_lang'][$lang_code] : 0;
                        ?>
                            <tr>
                                <td><?php echo esc_html($lang_code); ?></td>
                                <td><?php echo (int) $sc; ?></td>
                                <td><?php echo (int) $up; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="wpml_meta_id_mapper_save_raw" />
                <?php wp_nonce_field(self::NONCE_RAW_ACTION); ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Source language code</th>
                        <td>
                            <input type="text" name="raw_source_lang" value="<?php echo esc_attr($raw_source_lang); ?>" class="regular-text" />
            <p class="description">Example: <code>en</code>. Raw sync force-copies from this language to all others.</p>
                        </td>
                    </tr>
                </table>

                <table class="widefat striped" id="wpml-raw-rules-table">
                    <thead>
                    <tr>
                        <th>Scope</th>
                        <th>Object</th>
                        <th>Meta Key</th>
                        <th>Remove</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($rules)): ?>
                        <tr class="no-items"><td colspan="4">No raw copy rules yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($rules as $idx => $rule): ?>
                            <tr>
                                <?php $scope = $this->get_raw_rule_scope($rule); ?>
                                <?php $object_name = $this->get_raw_rule_object_name($rule); ?>
                                <td>
                                    <select name="raw_rules[<?php echo (int) $idx; ?>][scope]">
                                        <option value="post" <?php selected($scope, 'post'); ?>>post</option>
                                        <option value="taxonomy" <?php selected($scope, 'taxonomy'); ?>>taxonomy</option>
                                    </select>
                                </td>
                                <td><input type="text" class="regular-text" name="raw_rules[<?php echo (int) $idx; ?>][object_name]" value="<?php echo esc_attr($object_name); ?>" /></td>
                                <td><input type="text" class="regular-text" name="raw_rules[<?php echo (int) $idx; ?>][meta_key]" value="<?php echo esc_attr($rule['meta_key']); ?>" /></td>
                                <td><button type="button" class="button-link-delete wpml-raw-remove-row">Remove</button></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>

                <p>
                    <button type="button" class="button" id="wpml-raw-add-row">Add Raw Rule</button>
                </p>

                <?php submit_button('Save Raw Copy Settings'); ?>
            </form>
        </div>

        <script>
            (function () {
                const tableBody = document.querySelector('#wpml-raw-rules-table tbody');
                const addBtn = document.getElementById('wpml-raw-add-row');

                function nextIndex() {
                    const inputs = tableBody.querySelectorAll('input[name^="raw_rules["]');
                    let max = -1;
                    inputs.forEach((el) => {
                        const match = el.name.match(/raw_rules\[(\d+)]/);
                        if (match) {
                            const n = parseInt(match[1], 10);
                            if (n > max) {
                                max = n;
                            }
                        }
                    });
                    return max + 1;
                }

                function removeNoItemsRow() {
                    const noItems = tableBody.querySelector('.no-items');
                    if (noItems) {
                        noItems.remove();
                    }
                }

                addBtn.addEventListener('click', function () {
                    removeNoItemsRow();
                    const idx = nextIndex();
                    const tr = document.createElement('tr');
                    tr.innerHTML = `
                        <td>
                            <select name="raw_rules[${idx}][scope]">
                                <option value="post">post</option>
                                <option value="taxonomy">taxonomy</option>
                            </select>
                        </td>
                        <td><input type="text" class="regular-text" name="raw_rules[${idx}][object_name]" value="" /></td>
                        <td><input type="text" class="regular-text" name="raw_rules[${idx}][meta_key]" value="" /></td>
                        <td><button type="button" class="button-link-delete wpml-raw-remove-row">Remove</button></td>
                    `;
                    tableBody.appendChild(tr);
                });

                tableBody.addEventListener('click', function (e) {
                    if (!e.target.classList.contains('wpml-raw-remove-row')) {
                        return;
                    }
                    const row = e.target.closest('tr');
                    if (row) {
                        row.remove();
                    }
                    if (!tableBody.querySelector('tr')) {
                        const tr = document.createElement('tr');
                        tr.className = 'no-items';
                        tr.innerHTML = '<td colspan="4">No raw copy rules yet.</td>';
                        tableBody.appendChild(tr);
                    }
                });
            })();
        </script>
        <?php

        echo '<hr />';
        echo '<h2>Sync</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="wpml_meta_id_mapper_bulk_sync_raw" />';
        wp_nonce_field(self::NONCE_RAW_BULK_ACTION);
        submit_button('Run Raw Sync Now', 'secondary', 'submit', false);
        echo '</form>';
    }

    public function render_repeater_page() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized.');
        }

        $rules = $this->get_repeater_rules();
        ?>
        <div class="wrap">
            <h1>WPML Repeater Mapper</h1>
            <p>Map taxonomy term IDs or post IDs inside repeater sub-fields (e.g. <code>cruise_runs_day</code>, <code>cruise_runs_origin</code>) to WPML translated IDs.</p>

            <?php if (!defined('ICL_SITEPRESS_VERSION')): ?>
                <div class="notice notice-warning"><p>WPML not detected. Rules are saved, but mapping will not run until WPML is active.</p></div>
            <?php endif; ?>
            <?php if (!empty($_GET['repeater_updated'])): ?>
                <div class="notice notice-success is-dismissible"><p>Repeater settings saved.</p></div>
            <?php endif; ?>
            <?php if (!empty($_GET['repeater_bulk_done'])): ?>
                <div class="notice notice-success is-dismissible">
                    <p>
                        Repeater bulk sync finished.
                        Scanned: <?php echo isset($_GET['scanned']) ? (int) $_GET['scanned'] : 0; ?>,
                        Updated: <?php echo isset($_GET['changed']) ? (int) $_GET['changed'] : 0; ?>
                    </p>
                    <?php
                    $translations_only = (int) get_option(self::OPTION_TRANSLATIONS_ONLY, 1) === 1;
                    if ($translations_only && isset($_GET['scanned']) && (int) $_GET['scanned'] > 0 && isset($_GET['changed']) && (int) $_GET['changed'] === 0):
                    ?>
                        <p><strong>Note:</strong> Only translated posts were processed (source posts skipped). If no updates occurred, the values may already be correct, or the translated posts may not have values in these meta keys yet. Check the <a href="<?php echo esc_url(admin_url('admin.php?page=wpml-meta-id-mapper')); ?>">main mapper settings</a> to disable "translations only" if needed.</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="wpml_meta_id_mapper_save_repeater" />
                <?php wp_nonce_field(self::NONCE_REPEATER_ACTION); ?>

                <table class="widefat striped" id="wpml-repeater-rules-table">
                    <thead>
                    <tr>
                        <th>Post Type</th>
                        <th>Repeater base name</th>
                        <th>Sub-field Meta Key</th>
                        <th>Type</th>
                        <th>Object type (Taxonomy/Post Type)</th>
                        <th>Remove</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($rules)): ?>
                        <tr class="no-items"><td colspan="6">No repeater rules yet. Add rows: one row per sub-field (e.g. cruise_runs_day + type term + taxonomy day).</td></tr>
                    <?php else: ?>
                        <?php foreach ($rules as $idx => $rule): ?>
                            <?php
                            $sub_fields = isset($rule['sub_fields']) && is_array($rule['sub_fields']) ? $rule['sub_fields'] : [];
                            $first = true;
                            foreach ($sub_fields as $sf_idx => $sub): ?>
                            <tr>
                                <?php if ($first): ?>
                                    <td rowspan="<?php echo count($sub_fields); ?>"><input type="text" class="regular-text" name="repeater_rules[<?php echo (int) $idx; ?>][post_type]" value="<?php echo esc_attr($rule['post_type']); ?>" /></td>
                                    <td rowspan="<?php echo count($sub_fields); ?>"><input type="text" class="regular-text" name="repeater_rules[<?php echo (int) $idx; ?>][repeater_base]" value="<?php echo esc_attr(isset($rule['repeater_base']) ? $rule['repeater_base'] : ''); ?>" placeholder="e.g. cruise_runs" /></td>
                                <?php endif; ?>
                                <?php
                                $sub_type = isset($sub['type']) && in_array($sub['type'], ['post', 'term']) ? $sub['type'] : 'term';
                                $sub_obj = isset($sub['object_type']) ? $sub['object_type'] : (isset($sub['taxonomy']) ? $sub['taxonomy'] : '');
                                ?>
                                <td><input type="text" class="regular-text" name="repeater_rules[<?php echo (int) $idx; ?>][sub_fields][<?php echo (int) $sf_idx; ?>][meta_key]" value="<?php echo esc_attr($sub['meta_key']); ?>" /></td>
                                <td>
                                    <select name="repeater_rules[<?php echo (int) $idx; ?>][sub_fields][<?php echo (int) $sf_idx; ?>][type]">
                                        <option value="term" <?php selected($sub_type, 'term'); ?>>term</option>
                                        <option value="post" <?php selected($sub_type, 'post'); ?>>post</option>
                                    </select>
                                </td>
                                <td><input type="text" class="regular-text" name="repeater_rules[<?php echo (int) $idx; ?>][sub_fields][<?php echo (int) $sf_idx; ?>][object_type]" value="<?php echo esc_attr($sub_obj); ?>" /></td>
                                <td><button type="button" class="button-link-delete wpml-repeater-remove-row">Remove</button></td>
                            </tr>
                            <?php $first = false; endforeach; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>

                <p>
                    <button type="button" class="button" id="wpml-repeater-add-row">Add sub-field row</button>
                </p>
                <p class="description">Example: Post type <code>cruise</code>, base <code>cruise_runs</code>, sub-fields: <code>cruise_runs_day</code> → taxonomy <code>day</code>, <code>cruise_runs_origin</code> → <code>origin</code>, <code>cruise_runs_program</code> → <code>program</code>, <code>cruise_runs_time</code> → <code>time</code>.</p>

                <?php submit_button('Save Repeater Rules'); ?>
            </form>

            <hr />
            <h2>Bulk Sync</h2>
            <p>Run repeater mapping on all posts that match the rules above.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="wpml_meta_id_mapper_bulk_sync_repeater" />
                <?php wp_nonce_field(self::NONCE_REPEATER_BULK_ACTION); ?>
                <?php submit_button('Run Repeater Bulk Sync Now', 'secondary', 'submit', false); ?>
            </form>
        </div>

        <script>
            (function () {
                const tableBody = document.querySelector('#wpml-repeater-rules-table tbody');
                const addBtn = document.getElementById('wpml-repeater-add-row');

                function nextGroupIndex() {
                    const inputs = tableBody.querySelectorAll('input[name^="repeater_rules["]');
                    let max = -1;
                    inputs.forEach((el) => {
                        const match = el.name.match(/repeater_rules\[(\d+)]/);
                        if (match) {
                            const n = parseInt(match[1], 10);
                            if (n > max) max = n;
                        }
                    });
                    return max + 1;
                }

                function nextSubIndex() {
                    const inputs = tableBody.querySelectorAll('input[name*="[sub_fields]["]');
                    let max = -1;
                    inputs.forEach((el) => {
                        const m = el.name.match(/sub_fields\]\[(\d+)\]/);
                        if (m) {
                            const n = parseInt(m[1], 10);
                            if (n > max) max = n;
                        }
                    });
                    return max + 1;
                }

                function removeNoItemsRow() {
                    const noItems = tableBody.querySelector('.no-items');
                    if (noItems) noItems.remove();
                }

                addBtn.addEventListener('click', function () {
                    removeNoItemsRow();
                    const gIdx = nextGroupIndex();
                    const sIdx = nextSubIndex();
                    const tr = document.createElement('tr');
                    tr.innerHTML = `
                        <td><input type="text" class="regular-text" name="repeater_rules[${gIdx}][post_type]" value="" /></td>
                        <td><input type="text" class="regular-text" name="repeater_rules[${gIdx}][repeater_base]" value="" placeholder="e.g. cruise_runs" /></td>
                        <td><input type="text" class="regular-text" name="repeater_rules[${gIdx}][sub_fields][${sIdx}][meta_key]" value="" /></td>
                        <td>
                            <select name="repeater_rules[${gIdx}][sub_fields][${sIdx}][type]">
                                <option value="term">term</option>
                                <option value="post">post</option>
                            </select>
                        </td>
                        <td><input type="text" class="regular-text" name="repeater_rules[${gIdx}][sub_fields][${sIdx}][object_type]" value="" /></td>
                        <td><button type="button" class="button-link-delete wpml-repeater-remove-row">Remove</button></td>
                    `;
                    tableBody.appendChild(tr);
                });

                tableBody.addEventListener('click', function (e) {
                    if (!e.target.classList.contains('wpml-repeater-remove-row')) return;
                    const row = e.target.closest('tr');
                    if (row) row.remove();
                    if (!tableBody.querySelector('tr')) {
                        const tr = document.createElement('tr');
                        tr.className = 'no-items';
                        tr.innerHTML = '<td colspan="5">No repeater rules yet.</td>';
                        tableBody.appendChild(tr);
                    }
                });
            })();
        </script>
        <?php
    }

    public function handle_save_repeater_settings() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized.');
        }
        check_admin_referer(self::NONCE_REPEATER_ACTION);

        $raw = isset($_POST['repeater_rules']) && is_array($_POST['repeater_rules']) ? $_POST['repeater_rules'] : [];
        $groups = [];

        foreach ($raw as $rule) {
            $post_type = isset($rule['post_type']) ? sanitize_key((string) $rule['post_type']) : '';
            $repeater_base = isset($rule['repeater_base']) ? sanitize_text_field((string) $rule['repeater_base']) : '';
            $sub_fields_raw = isset($rule['sub_fields']) && is_array($rule['sub_fields']) ? $rule['sub_fields'] : [];

            if ($post_type === '') {
                continue;
            }

            $sub_fields = [];
            foreach ($sub_fields_raw as $sub) {
                $meta_key = isset($sub['meta_key']) ? sanitize_text_field((string) $sub['meta_key']) : '';
                $type = isset($sub['type']) && in_array($sub['type'], ['post', 'term']) ? $sub['type'] : 'term';
                
                // Allow fallback to taxonomy for old rules
                $object_type = '';
                if (!empty($sub['object_type'])) {
                    $object_type = sanitize_key((string) $sub['object_type']);
                } elseif (!empty($sub['taxonomy'])) {
                    $object_type = sanitize_key((string) $sub['taxonomy']);
                }

                if ($meta_key !== '' && $object_type !== '') {
                    $sub_fields[] = [
                        'meta_key' => $meta_key, 
                        'type' => $type,
                        'object_type' => $object_type,
                        'taxonomy' => $object_type // Keep for backwards compatibility within the script
                    ];
                }
            }
            if (empty($sub_fields)) {
                continue;
            }

            $groups[] = [
                'post_type' => $post_type,
                'repeater_base' => $repeater_base,
                'sub_fields' => $sub_fields,
            ];
        }

        update_option(self::OPTION_REPEATER_RULES, $groups, false);

        wp_safe_redirect(add_query_arg(['page' => 'wpml-repeater-mapper', 'repeater_updated' => '1'], admin_url('admin.php')));
        exit;
    }

    public function handle_bulk_sync_repeater() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized.');
        }
        check_admin_referer(self::NONCE_REPEATER_BULK_ACTION);

        $scanned = 0;
        $changed = 0;

        if (defined('ICL_SITEPRESS_VERSION')) {
            $rules = $this->get_repeater_rules();
            $post_types = [];
            foreach ($rules as $rule) {
                if (!empty($rule['post_type'])) {
                    $post_types[$rule['post_type']] = true;
                }
            }
            foreach (array_keys($post_types) as $post_type) {
                $paged = 1;
                do {
                    $q = new WP_Query([
                        'post_type' => $post_type,
                        'post_status' => 'any',
                        'posts_per_page' => 200,
                        'paged' => $paged,
                        'fields' => 'ids',
                        'no_found_rows' => false,
                        'orderby' => 'ID',
                        'order' => 'ASC',
                        'suppress_filters' => true,
                    ]);
                    if (!empty($q->posts)) {
                        foreach ($q->posts as $post_id) {
                            $post_id = (int) $post_id;
                            if ($post_id <= 0) continue;
                            
                            $log_file = __DIR__ . '/repeater_debug_log.txt';
                            
                            $lang_info = $this->get_lang_info($post_id);
                            if (empty($lang_info['language_code'])) {
                                file_put_contents($log_file, "Post $post_id: SKIPPED (No language_code in WPML)\n", FILE_APPEND);
                                continue;
                            }
                            
                            $is_translation = false;
                            $actual_source_lang = null;
                            if (!empty($lang_info['source_language_code'])) {
                                $is_translation = true;
                                $actual_source_lang = $lang_info['source_language_code'];
                            } else {
                                $original_id = $this->get_original_post_id($post_id, $post_type, null);
                                if ($original_id > 0 && $original_id !== $post_id) {
                                    $is_translation = true;
                                    $orig_lang_info = $this->get_lang_info($original_id);
                                    if (!empty($orig_lang_info['language_code'])) {
                                        $actual_source_lang = $orig_lang_info['language_code'];
                                    }
                                }
                            }
                            
                            $lang_code = $lang_info['language_code'];
                            $translations_only = (int) get_option(self::OPTION_TRANSLATIONS_ONLY, 1) === 1;
                            
                            // If it's an Arabic post, force it to be treated as a translation to see if that helps
                            if ($lang_code !== 'en') {
                                $is_translation = true;
                            }

                            if ($translations_only && !$is_translation) {
                                file_put_contents($log_file, "Post $post_id ($lang_code): SKIPPED (translations_only=true, is_translation=false)\n", FILE_APPEND);
                                continue;
                            }
                            
                            if ($is_translation && empty($actual_source_lang)) {
                                $actual_source_lang = 'en';
                            }
                            
                            file_put_contents($log_file, "Post $post_id ($lang_code): STARTING SYNC (Source Lang: $actual_source_lang)\n", FILE_APPEND);
                            
                            $scanned++;
                            if ($this->apply_repeater_rules_for_post($post_id, $lang_code, $actual_source_lang)) {
                                $changed++;
                            }
                        }
                    }
                    $paged++;
                } while ($paged <= (int) $q->max_num_pages);
                wp_reset_postdata();
            }
        }

        wp_safe_redirect(add_query_arg([
            'page' => 'wpml-repeater-mapper',
            'repeater_bulk_done' => '1',
            'scanned' => (string) $scanned,
            'changed' => (string) $changed,
        ], admin_url('admin.php')));
        exit;
    }

    public function handle_save_raw_settings() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized.');
        }

        check_admin_referer(self::NONCE_RAW_ACTION);

        $submitted = isset($_POST['raw_rules']) && is_array($_POST['raw_rules']) ? $_POST['raw_rules'] : [];
        $raw_source_lang = isset($_POST['raw_source_lang']) ? sanitize_key((string) $_POST['raw_source_lang']) : 'en';
        if ($raw_source_lang === '') {
            $raw_source_lang = 'en';
        }
        $raw_clean = [];

        foreach ($submitted as $rule) {
            $scope = isset($rule['scope']) ? sanitize_key($rule['scope']) : 'post';
            $object_name = isset($rule['object_name']) ? sanitize_key($rule['object_name']) : '';
            $meta_key = isset($rule['meta_key']) ? sanitize_text_field($rule['meta_key']) : '';
            if (!in_array($scope, ['post', 'taxonomy'], true)) {
                $scope = 'post';
            }
            if ($object_name === '' || $meta_key === '') {
                continue;
            }

            $raw_clean[] = [
                'post_type' => $scope === 'post' ? $object_name : '',
                'taxonomy' => $scope === 'taxonomy' ? $object_name : '',
                'scope' => $scope,
                'object_name' => $object_name,
                'meta_key' => $meta_key,
                'type' => 'raw',
                'object_type' => '',
                'storage_mode' => 'auto',
            ];
        }

        $all_rules = array_merge($this->get_mapper_rules(), $raw_clean);
        update_option(self::OPTION_RULES, $all_rules, false);
        update_option(self::OPTION_RAW_SOURCE_LANG, $raw_source_lang, false);

        $url = add_query_arg(
            [
                'page' => 'wpml-raw-copy-sync',
                'raw_updated' => '1',
            ],
            admin_url('admin.php')
        );

        wp_safe_redirect($url);
        exit;
    }

    public function handle_bulk_sync_raw() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized.');
        }
        check_admin_referer(self::NONCE_RAW_BULK_ACTION);

        $scanned = 0;
        $changed = 0;
        $updated_items_total = 0;
        $scanned_by_lang = [];
        $updated_by_lang = [];

        if (defined('ICL_SITEPRESS_VERSION')) {
            $raw_rules = $this->get_raw_rules();
            $post_types = [];
            $taxonomies = [];

            foreach ($raw_rules as $rule) {
                $scope = $this->get_raw_rule_scope($rule);
                $object_name = $this->get_raw_rule_object_name($rule);
                if ($scope === 'taxonomy' && $object_name !== '') {
                    $taxonomies[$object_name] = true;
                } elseif ($object_name !== '') {
                    $post_types[$object_name] = true;
                }
            }

            foreach (array_keys($post_types) as $post_type) {
                $element_type = 'post_' . $post_type;
                $rows = $this->wpml_get_all_elements_by_type($element_type);
                $processed_trids = [];
                foreach ($rows as $row) {
                    if (!isset($row->element_id, $row->language_code)) {
                        continue;
                    }
                    $post_id = (int) $row->element_id;
                    $lang_code = (string) $row->language_code;
                    if ($post_id <= 0) {
                        continue;
                    }

                    $scanned++;
                    if (!isset($scanned_by_lang[$lang_code])) {
                        $scanned_by_lang[$lang_code] = 0;
                    }
                    $scanned_by_lang[$lang_code]++;

                    $trid_key = isset($row->trid) ? (int) $row->trid : 0;
                    if ($trid_key > 0 && isset($processed_trids[$trid_key])) {
                        continue;
                    }
                    if ($trid_key > 0) {
                        $processed_trids[$trid_key] = true;
                    }

                    if ($this->sync_raw_rules_for_post_group($post_id, $post_type)) {
                        $changed++;
                        $updated_langs = $this->consume_raw_last_updated_langs();
                        if (empty($updated_langs)) {
                            $updated_langs = [$lang_code => 1];
                        }
                        foreach ($updated_langs as $updated_lang_code => $count) {
                            if (!isset($updated_by_lang[$updated_lang_code])) {
                                $updated_by_lang[$updated_lang_code] = 0;
                            }
                            $count = (int) $count;
                            $updated_by_lang[$updated_lang_code] += $count;
                            $updated_items_total += $count;
                        }
                    }
                }
            }

            foreach (array_keys($taxonomies) as $taxonomy) {
                if (!taxonomy_exists($taxonomy)) {
                    continue;
                }
                $element_type = 'tax_' . $taxonomy;
                $rows = $this->wpml_get_all_elements_by_type($element_type);
                $processed_trids = [];
                foreach ($rows as $row) {
                    if (!isset($row->element_id, $row->language_code)) {
                        continue;
                    }
                    $lang_code = (string) $row->language_code;
                    $term_id = $this->wpml_tax_element_to_term_id((int) $row->element_id, $taxonomy);
                    if ($term_id <= 0) {
                        continue;
                    }

                    $scanned++;
                    if (!isset($scanned_by_lang[$lang_code])) {
                        $scanned_by_lang[$lang_code] = 0;
                    }
                    $scanned_by_lang[$lang_code]++;

                    $trid_key = isset($row->trid) ? (int) $row->trid : 0;
                    if ($trid_key > 0 && isset($processed_trids[$trid_key])) {
                        continue;
                    }
                    if ($trid_key > 0) {
                        $processed_trids[$trid_key] = true;
                    }

                    if ($this->sync_raw_rules_for_term_group($term_id, $taxonomy)) {
                        $changed++;
                        $updated_langs = $this->consume_raw_last_updated_langs();
                        if (empty($updated_langs)) {
                            $updated_langs = [$lang_code => 1];
                        }
                        foreach ($updated_langs as $updated_lang_code => $count) {
                            if (!isset($updated_by_lang[$updated_lang_code])) {
                                $updated_by_lang[$updated_lang_code] = 0;
                            }
                            $count = (int) $count;
                            $updated_by_lang[$updated_lang_code] += $count;
                            $updated_items_total += $count;
                        }
                    }
                }
            }
        }

        $stats_key = self::TRANSIENT_RAW_STATS_PREFIX . get_current_user_id();
        set_transient($stats_key, [
            'scanned_by_lang' => $scanned_by_lang,
            'updated_by_lang' => $updated_by_lang,
            'scanned' => $scanned,
            'changed' => $changed,
            'updated_items_total' => $updated_items_total,
        ], 10 * MINUTE_IN_SECONDS);

        $url = add_query_arg(
            [
                'page' => 'wpml-raw-copy-sync',
                'raw_bulk_done' => '1',
                'scanned' => (string) $scanned,
                'changed' => (string) $changed,
                'updated_items' => (string) $updated_items_total,
            ],
            admin_url('admin.php')
        );
        wp_safe_redirect($url);
        exit;
    }

    public function handle_bulk_sync() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized.');
        }
        check_admin_referer(self::NONCE_BULK_ACTION);

        if (!defined('ICL_SITEPRESS_VERSION')) {
            $url = add_query_arg(
                [
                    'page' => 'wpml-meta-id-mapper',
                    'bulk_done' => '1',
                    'scanned' => '0',
                    'changed' => '0',
                ],
                admin_url('admin.php')
            );
            wp_safe_redirect($url);
            exit;
        }

        $rules = $this->get_rules();
        $post_types = [];
        foreach ($rules as $rule) {
            if (!empty($rule['post_type'])) {
                $post_types[$rule['post_type']] = true;
            }
        }

        $scanned = 0;
        $changed = 0;

        if (!empty($post_types)) {
            foreach (array_keys($post_types) as $post_type) {
                $paged = 1;
                do {
                    $q = new WP_Query([
                        'post_type' => $post_type,
                        'post_status' => 'any',
                        'posts_per_page' => 200,
                        'paged' => $paged,
                        'fields' => 'ids',
                        'no_found_rows' => false,
                        'orderby' => 'ID',
                        'order' => 'ASC',
                        'suppress_filters' => true,
                    ]);

                    if (!empty($q->posts)) {
                        foreach ($q->posts as $post_id) {
                            $post_id = (int) $post_id;
                            if ($post_id <= 0) {
                                continue;
                            }
                            $lang_info = $this->get_lang_info($post_id);
                            // Determine translation status and source language
                            $is_translation = false;
                            $actual_source_lang = null;
                            if (!empty($lang_info['source_language_code'])) {
                                $is_translation = true;
                                $actual_source_lang = $lang_info['source_language_code'];
                            } else {
                                $original_id = $this->get_original_post_id($post_id, $post_type, null);
                                if ($original_id > 0 && $original_id !== $post_id) {
                                    $is_translation = true;
                                    $orig_lang_info = $this->get_lang_info($original_id);
                                    if (!empty($orig_lang_info['language_code'])) {
                                        $actual_source_lang = $orig_lang_info['language_code'];
                                    }
                                }
                            }

                            if ($this->should_skip_for_source_post($lang_info, $post_id, $post_type) && !$is_translation) {
                                continue;
                            }
                            
                            if ($is_translation && empty($actual_source_lang)) {
                                $actual_source_lang = apply_filters('wpml_default_language', null);
                            }

                            $scanned++;
                            $did_change = $this->map_rules_for_post(
                                $post_id,
                                $lang_info['language_code'],
                                $actual_source_lang
                            );
                            if ($did_change) {
                                $changed++;
                            }
                        }
                    }

                    $paged++;
                } while ($paged <= (int) $q->max_num_pages);
                wp_reset_postdata();
            }
        }

        $url = add_query_arg(
            [
                'page' => 'wpml-meta-id-mapper',
                'bulk_done' => '1',
                'scanned' => (string) $scanned,
                'changed' => (string) $changed,
            ],
            admin_url('admin.php')
        );
        wp_safe_redirect($url);
        exit;
    }

    public function filter_wpml_copied_value($copied_value, $target_post_id, $source_post_id, $meta_key) {
        if (!defined('ICL_SITEPRESS_VERSION')) {
            return $copied_value;
        }

        $target_post_id = (int) $target_post_id;
        if ($target_post_id <= 0 || !is_string($meta_key) || $meta_key === '') {
            return $copied_value;
        }

        $target_post = get_post($target_post_id);
        if (!($target_post instanceof WP_Post)) {
            return $copied_value;
        }

        $rule = $this->find_rule_for_meta($target_post->post_type, $meta_key);
        if (empty($rule)) {
            return $copied_value;
        }

        $lang_info = $this->get_lang_info($target_post_id);
        if (empty($lang_info['language_code'])) {
            return $copied_value;
        }
        if ($this->should_skip_for_source_post($lang_info, $target_post_id, $target_post->post_type)) {
            return $copied_value;
        }

        $source_lang_info = $this->get_lang_info((int) $source_post_id);
        $source_lang = !empty($source_lang_info['language_code']) ? $source_lang_info['language_code'] : null;

        return $this->map_meta_value_by_rule($copied_value, $rule, $lang_info['language_code'], $source_lang);
    }

    public function handle_wpml_after_save_post($post_id, $trid = null, $language_code = null, $source_language = null) {
        if (!defined('ICL_SITEPRESS_VERSION')) {
            return;
        }

        $post_id = (int) $post_id;
        if ($post_id <= 0) {
            return;
        }

        $lang_info = $this->get_lang_info($post_id);
        if (empty($lang_info['language_code'])) {
            return;
        }
        if ($this->should_skip_for_source_post($lang_info, $post_id, get_post_type($post_id))) {
            return;
        }

        $this->map_rules_for_post($post_id, $lang_info['language_code'], !empty($lang_info['source_language_code']) ? $lang_info['source_language_code'] : null);
    }

    public function handle_wpml_translation_completed($post_id, $fields = null, $job = null) {
        if (!defined('ICL_SITEPRESS_VERSION')) {
            return;
        }

        $post_id = (int) $post_id;
        if ($post_id <= 0) {
            return;
        }

        $lang_info = $this->get_lang_info($post_id);
        if (empty($lang_info['language_code'])) {
            return;
        }
        if ($this->should_skip_for_source_post($lang_info, $post_id, get_post_type($post_id))) {
            return;
        }

        $this->map_rules_for_post($post_id, $lang_info['language_code'], !empty($lang_info['source_language_code']) ? $lang_info['source_language_code'] : null);
    }

    public function handle_pmxi_saved_post($post_id, $xml_node = null, $is_update = null) {
        if (!defined('ICL_SITEPRESS_VERSION')) {
            return;
        }

        $post_id = (int) $post_id;
        if ($post_id <= 0) {
            return;
        }

        $lang_info = $this->get_lang_info($post_id);
        if (empty($lang_info['language_code'])) {
            return;
        }
        if ($this->should_skip_for_source_post($lang_info, $post_id, get_post_type($post_id))) {
            return;
        }

        $this->map_rules_for_post($post_id, $lang_info['language_code'], !empty($lang_info['source_language_code']) ? $lang_info['source_language_code'] : null);
    }

    public function handle_fallback_save_post($post_id, $post = null, $update = null) {
        if (!defined('ICL_SITEPRESS_VERSION')) {
            return;
        }

        $post_id = (int) $post_id;
        if ($post_id <= 0) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
            return;
        }

        $post_obj = $post instanceof WP_Post ? $post : get_post($post_id);
        if (!($post_obj instanceof WP_Post)) {
            return;
        }

        $lang_info = $this->get_lang_info($post_id);
        if (empty($lang_info['language_code'])) {
            return;
        }
        if ($this->should_skip_for_source_post($lang_info, $post_id, $post_obj->post_type)) {
            return;
        }

        $this->map_rules_for_post($post_id, $lang_info['language_code'], !empty($lang_info['source_language_code']) ? $lang_info['source_language_code'] : null);
    }

    public function handle_fallback_term_sync($term_id, $tt_id = null, $taxonomy = null) {
        if (!defined('ICL_SITEPRESS_VERSION')) {
            return;
        }

        $term_id = (int) $term_id;
        $taxonomy = (string) $taxonomy;
        if ($term_id <= 0 || $taxonomy === '') {
            return;
        }

        $this->sync_raw_rules_for_term_group($term_id, $taxonomy);
    }

    private function should_skip_for_source_post($lang_info, $post_id = 0, $post_type = '') {
        $translations_only = (int) get_option(self::OPTION_TRANSLATIONS_ONLY, 1) === 1;
        if (!$translations_only) {
            return false;
        }

        if (!empty($lang_info['source_language_code'])) {
            return false;
        }

        $post_id = (int) $post_id;
        $post_type = (string) $post_type;
        if ($post_id <= 0 || $post_type === '') {
            return true;
        }

        // Import fallback: sometimes source_language_code is empty at this point.
        $element_type = 'post_' . $post_type;
        $original_id = $this->wpml_get_original_element_id($post_id, $element_type);
        if (!empty($original_id) && (int) $original_id !== $post_id) {
            return false;
        }

        return true;
    }

    private function get_lang_info($post_id) {
        $post_id = (int) $post_id;
        $lang_info = apply_filters('wpml_post_language_details', null, $post_id);
        return is_array($lang_info) ? $lang_info : [];
    }

    private function find_rule_for_meta($post_type, $meta_key) {
        foreach ($this->get_rules() as $rule) {
            if (
                isset($rule['post_type'], $rule['meta_key'])
                && $rule['post_type'] === $post_type
                && $rule['meta_key'] === $meta_key
            ) {
                return $rule;
            }
        }
        return [];
    }

    private function map_rules_for_post($post_id, $target_lang, $source_lang = null) {
        $post_id = (int) $post_id;
        $target_lang = (string) $target_lang;
        $post = get_post($post_id);
        if (!($post instanceof WP_Post)) {
            return false;
        }

        $rules = $this->get_rules();
        if (empty($rules)) {
            return false;
        }

        static $running = [];
        if (!empty($running[$post_id])) {
            return false;
        }
        $running[$post_id] = true;
        $updated_any = false;

        try {
            $source_post_id = $this->get_original_post_id($post_id, $post->post_type, $source_lang);
            if ($source_post_id <= 0 || $source_post_id === $post_id) {
                $source_post_id = 0;
            }

            foreach ($rules as $rule) {
            if (!isset($rule['post_type'], $rule['meta_key'], $rule['type'])) {
                    continue;
                }
                if ($rule['post_type'] !== $post->post_type) {
                    continue;
                }
                if (in_array($rule['type'], ['post', 'term'], true) && empty($rule['object_type'])) {
                    continue;
                }

                // Deterministic behavior: for translated posts, always map from original language value.
                if ($source_post_id > 0) {
                    $raw = get_post_meta($source_post_id, $rule['meta_key'], true);
                } else {
                    $raw = get_post_meta($post_id, $rule['meta_key'], true);
                }
                if ($raw === '' || $raw === null) {
                    continue;
                }

                $converted = $this->map_meta_value_by_rule($raw, $rule, $target_lang, $source_lang);
                $current_target_value = get_post_meta($post_id, $rule['meta_key'], true);
                $should_update = ($converted !== $current_target_value);

                if ($should_update) {
                    update_post_meta($post_id, $rule['meta_key'], $converted);
                    $updated_any = true;
                }
            }
        } finally {
            unset($running[$post_id]);
        }

        $repeater_updated = $this->apply_repeater_rules_for_post($post_id, $target_lang, $source_lang);
        if ($repeater_updated) {
            $updated_any = true;
        }

        return $updated_any;
    }

    private function get_repeater_rules(): array {
        $rules = get_option(self::OPTION_REPEATER_RULES, []);
        if (!is_array($rules)) {
            return [];
        }
        $clean = [];
        foreach ($rules as $rule) {
            if (!is_array($rule) || empty($rule['post_type']) || empty($rule['sub_fields']) || !is_array($rule['sub_fields'])) {
                continue;
            }
            $sub_fields = [];
            foreach ($rule['sub_fields'] as $sub) {
                if (!is_array($sub) || empty($sub['meta_key'])) {
                    continue;
                }
                $type = isset($sub['type']) && in_array($sub['type'], ['post', 'term'], true) ? $sub['type'] : 'term';
                $object_type = !empty($sub['object_type']) ? $sub['object_type'] : (!empty($sub['taxonomy']) ? $sub['taxonomy'] : '');
                
                if (empty($object_type)) {
                    continue;
                }
                
                $sub_fields[] = [
                    'meta_key' => sanitize_text_field((string) $sub['meta_key']),
                    'type' => sanitize_key($type),
                    'object_type' => sanitize_key((string) $object_type),
                    'taxonomy' => sanitize_key((string) $object_type), // fallback
                ];
            }
            if (empty($sub_fields)) {
                continue;
            }
            $clean[] = [
                'post_type' => sanitize_key((string) $rule['post_type']),
                'repeater_base' => isset($rule['repeater_base']) ? sanitize_text_field((string) $rule['repeater_base']) : '',
                'sub_fields' => $sub_fields,
            ];
        }
        return $clean;
    }

    private function apply_repeater_rules_for_post($post_id, $target_lang, $source_lang = null): bool {
        $post_id = (int) $post_id;
        $target_lang = (string) $target_lang;
        
        if (empty($source_lang)) {
            $source_lang = 'en';
        }

        $post = get_post($post_id);
        if (!($post instanceof WP_Post)) {
            return false;
        }

        $raw_rules = $this->get_repeater_rules();
        if (empty($raw_rules)) {
            return false;
        }
        
        // Consolidate rules by repeater_base so we process all sub_fields for a base array together
        $rules_map = [];
        foreach ($raw_rules as $rule) {
            $rule_post_type = isset($rule['post_type']) ? $rule['post_type'] : '';
            if ($rule_post_type !== $post->post_type) {
                continue;
            }
            $base = isset($rule['repeater_base']) ? trim($rule['repeater_base']) : '';
            $key = $rule_post_type . '|||' . $base;

            if (!isset($rules_map[$key])) {
                $rules_map[$key] = [
                    'post_type' => $rule_post_type,
                    'repeater_base' => $base,
                    'sub_fields' => []
                ];
            }
            if (isset($rule['sub_fields']) && is_array($rule['sub_fields'])) {
                foreach ($rule['sub_fields'] as $sub) {
                    $rules_map[$key]['sub_fields'][] = $sub;
                }
            }
        }
        $rules = array_values($rules_map);

        if (empty($rules)) {
            return false;
        }

        static $running = [];
        if (!empty($running[$post_id])) {
            return false;
        }
        $running[$post_id] = true;
        $updated_any = false;

        try {
            $source_post_id = $this->get_original_post_id($post_id, $post->post_type, $source_lang);
            if ($source_post_id <= 0 || $source_post_id === $post_id) {
                $source_post_id = 0;
            }

            foreach ($rules as $rule) {
                $repeater_base = isset($rule['repeater_base']) ? $rule['repeater_base'] : '';
                
                // 1. Handle the main serialized repeater array (JetEngine style)
                if ($repeater_base !== '') {
                    $has_base_updates = false;
                    
                    // Get base value from source or current
                    $base_raw = null;
                    $actual_source_lang_base = null;
                    
                    if ($source_post_id > 0) {
                        $base_raw = get_post_meta($source_post_id, $repeater_base, true);
                        $source_lang_info = $this->get_lang_info($source_post_id);
                        $actual_source_lang_base = !empty($source_lang_info['language_code']) ? $source_lang_info['language_code'] : $source_lang;
                    } else {
                        $base_raw = get_post_meta($post_id, $repeater_base, true);
                        $actual_source_lang_base = $source_lang;
                    }

                    $base_unserialized = is_string($base_raw) ? maybe_unserialize($base_raw) : $base_raw;

                    if (is_array($base_unserialized) && !empty($base_unserialized)) {
                        $converted_base = $base_unserialized;
                        
                        // Loop through repeater items (e.g. 'item-0', 'item-1')
                        foreach ($converted_base as $item_key => &$item_data) {
                            if (!is_array($item_data)) {
                                continue;
                            }
                            
                            // Check sub-fields
                            foreach ($rule['sub_fields'] as $sub) {
                                $meta_key_full = $sub['meta_key'];
                                
                                // In JetEngine, the array keys are usually the actual field names without the repeater prefix
                                // e.g. base = "cruise_runs", sub-field in UI might be "cruise_runs_day", but array key is "day"
                                $sub_key = $meta_key_full;
                                
                                // First try: exact match (e.g. if the user entered "day" directly)
                                $found_key = null;
                                if (isset($item_data[$sub_key])) {
                                    $found_key = $sub_key;
                                } else {
                                    // Second try: remove repeater base from prefix
                                    $prefix = $repeater_base . '_';
                                    if (strpos($meta_key_full, $prefix) === 0) {
                                        $stripped_key = substr($meta_key_full, strlen($prefix));
                                        if (isset($item_data[$stripped_key])) {
                                            $found_key = $stripped_key;
                                        }
                                    }
                                }
                                
                                // Third try: manual scanning of keys to see if one ends with our sub-field naming
                                if ($found_key === null) {
                                    foreach ($item_data as $k => $v) {
                                        // e.g. checking if "cruise_runs_day" ends with "day"
                                        if (strpos($meta_key_full, $k) !== false || strpos($k, $meta_key_full) !== false) {
                                            $found_key = $k;
                                            break;
                                        }
                                    }
                                }

                                if ($found_key !== null && $item_data[$found_key] !== '') {
                                    $original_value = $item_data[$found_key];
                                    
                                    $synthetic_rule = [
                                        'type' => isset($sub['type']) ? $sub['type'] : 'term',
                                        'object_type' => isset($sub['object_type']) ? $sub['object_type'] : $sub['taxonomy'],
                                        'storage_mode' => 'id',
                                    ];
                                    
                                    // The map_meta_value_by_rule function parses both arrays and pipe strings
                                    $item_data[$found_key] = $this->map_meta_value_by_rule(
                                        $original_value,
                                        $synthetic_rule,
                                        $target_lang,
                                        $actual_source_lang_base
                                    );
                                    
                                    // Deep logging for Sub-fields
                                    if (isset($_REQUEST['action']) && $_REQUEST['action'] === 'wpml_meta_id_mapper_bulk_sync_repeater') {
                                        $log_file = __DIR__ . '/repeater_debug_log.txt';
                                        $log_entry = "   -> Sub-field '{$found_key}' | Type: {$sub['taxonomy']} | Original: " . print_r($original_value, true) . " | Translated: " . print_r($item_data[$found_key], true) . "\n";
                                        file_put_contents($log_file, $log_entry, FILE_APPEND);
                                    }
                                }
                            }
                        }
                        unset($item_data); // break reference

                        $current_base = get_post_meta($post_id, $repeater_base, true);
                        $current_base_unserialized = is_string($current_base) ? maybe_unserialize($current_base) : $current_base;
                        
                        $needs_base_update = false;
                        if (empty($current_base_unserialized) && !empty($converted_base)) {
                            $needs_base_update = true;
                        } elseif (is_array($current_base_unserialized) && $current_base_unserialized !== $converted_base) {
                            $needs_base_update = true;
                        }

                        // Background Debug Logging
                        if (isset($_REQUEST['action']) && $_REQUEST['action'] === 'wpml_meta_id_mapper_bulk_sync_repeater') {
                            $log_file = __DIR__ . '/repeater_debug_log.txt';
                            $log_entry = "--- UPDATE CHECK for Post $post_id ---\n";
                            $log_entry .= "Target Lang: $target_lang | Source Lang: " . ($source_lang ?: 'NULL') . "\n";
                            $log_entry .= "Repeater Base: $repeater_base\n";
                            $log_entry .= "Needs Update? " . ($needs_base_update ? "YES" : "NO") . "\n";
                            if (!$needs_base_update) {
                                $log_entry .= "Current Base vs Converted Base:\n" . print_r($current_base_unserialized, true) . "\n" . print_r($converted_base, true) . "\n";
                            }
                            file_put_contents($log_file, $log_entry, FILE_APPEND);
                        }

                        if ($needs_base_update) {
                            $value_to_save = $converted_base;
                            if (is_string($base_raw) && maybe_unserialize($base_raw) !== $base_raw) {
                                $value_to_save = maybe_serialize($converted_base);
                            }
                            delete_post_meta($post_id, $repeater_base);
                            update_post_meta($post_id, $repeater_base, $value_to_save);
                            $updated_any = true;
                            
                            if (isset($_REQUEST['action']) && $_REQUEST['action'] === 'wpml_meta_id_mapper_bulk_sync_repeater') {
                                $log_file = __DIR__ . '/repeater_debug_log.txt';
                                $log_entry = "-> FINAL SAVED ARRAY for {$repeater_base}:\n" . print_r($converted_base, true) . "\n";
                                file_put_contents($log_file, $log_entry, FILE_APPEND);
                            }
                            // Log the final saved value
                            if (isset($_REQUEST['action']) && $_REQUEST['action'] === 'wpml_meta_id_mapper_bulk_sync_repeater') {
                                $log_file = __DIR__ . '/repeater_debug_log.txt';
                                file_put_contents($log_file, "  -> BASE ARRAY SAVED! New Value:\n" . print_r($value_to_save, true) . "\n", FILE_APPEND);
                            }
                        }
                    }
                }

                // 2. Handle the flattened multiple-row meta keys (JetEngine)
                foreach ($rule['sub_fields'] as $sub) {
                    $meta_key = $sub['meta_key'];
                    $synthetic_rule = [
                        'type' => isset($sub['type']) ? $sub['type'] : 'term',
                        'object_type' => isset($sub['object_type']) ? $sub['object_type'] : $sub['taxonomy'],
                        'storage_mode' => 'id',
                    ];

                    // Get current value in translated post (fetch as array to get ALL rows)
                    $current_rows = get_post_meta($post_id, $meta_key, false);
                    if (!is_array($current_rows)) {
                        $current_rows = [];
                    }
                    
                    // Get source value: always from source post if available, otherwise from current post
                    $raw_rows = [];
                    $actual_source_lang = null;
                    
                    if ($source_post_id > 0) {
                        // We have a source post - get values from it
                        $raw_rows = get_post_meta($source_post_id, $meta_key, false);
                        $source_lang_info = $this->get_lang_info($source_post_id);
                        $actual_source_lang = !empty($source_lang_info['language_code']) ? $source_lang_info['language_code'] : $source_lang;
                    } else {
                        // No source post - use current post values
                        $raw_rows = $current_rows;
                        $actual_source_lang = $source_lang;
                    }
                    
                    if (!is_array($raw_rows) || empty($raw_rows)) {
                        continue;
                    }

                    $converted_rows = [];
                    foreach ($raw_rows as $raw_val) {
                        // Convert the IDs from source language to target language
                        $converted = $this->map_meta_value_by_rule($raw_val, $synthetic_rule, $target_lang, $actual_source_lang);
                        $converted_rows[] = $converted;
                    }
                    
                    // Normalize both values for comparison
                    $current_normalized = array_map(function($v) { return is_string($v) ? maybe_unserialize($v) : $v; }, $current_rows);
                    $converted_normalized = array_map(function($v) { return is_string($v) ? maybe_unserialize($v) : $v; }, $converted_rows);
                    
                    $needs_update = ($current_normalized !== $converted_normalized);

                    if (isset($_REQUEST['action']) && $_REQUEST['action'] === 'wpml_meta_id_mapper_bulk_sync_repeater') {
                        $log_file = __DIR__ . '/repeater_debug_log.txt';
                        $log_entry = "  -> Flat Field: $meta_key | Needs Update? " . ($needs_update ? "YES" : "NO") . "\n";
                        if ($needs_update) {
                            $log_entry .= "     Current Rows: " . print_r($current_normalized, true) . "     Converted Rows: " . print_r($converted_normalized, true) . "\n";
                        }
                        file_put_contents($log_file, $log_entry, FILE_APPEND);
                    }
                    
                    if ($needs_update) {
                        // Force update: delete old meta first to ensure clean save
                        delete_post_meta($post_id, $meta_key);
                        foreach ($converted_rows as $i => $converted_val) {
                            // Preserve serialization format if original was serialized
                            $value_to_save = $converted_val;
                            $original_raw = isset($raw_rows[$i]) ? $raw_rows[$i] : null;
                            if (is_string($original_raw) && maybe_unserialize($original_raw) !== $original_raw) {
                                // Original was serialized, serialize the converted value
                                $value_to_save = maybe_serialize($converted_val);
                            }
                            add_post_meta($post_id, $meta_key, $value_to_save);
                            if (isset($_REQUEST['action']) && $_REQUEST['action'] === 'wpml_meta_id_mapper_bulk_sync_repeater') {
                                $log_file = __DIR__ . '/repeater_debug_log.txt';
                                file_put_contents($log_file, "  -> ADDED ROW! New Value: " . print_r($value_to_save, true) . "\n", FILE_APPEND);
                            }
                        }
                        $updated_any = true;
                    }
                }
            }
        } finally {
            unset($running[$post_id]);
        }

        return $updated_any;
    }

    private function get_original_post_id($post_id, $post_type, $source_lang = null) {
        $post_id = (int) $post_id;
        $post_type = (string) $post_type;
        if ($post_id <= 0 || $post_type === '') {
            return 0;
        }

        $element_type = 'post_' . $post_type;
        $original_id = $this->wpml_get_original_element_id($post_id, $element_type, $source_lang);
        if ($original_id) {
            return (int) $original_id;
        }

        $trid = $this->wpml_get_trid($post_id, $element_type);
        if (!$trid) {
            return 0;
        }

        $translations = $this->wpml_get_element_translations($trid, $element_type);
        if (!is_array($translations) || empty($translations)) {
            return 0;
        }

        $expected_source_lang = $source_lang ? (string) $source_lang : (string) apply_filters('wpml_default_language', null);
        if (empty($expected_source_lang)) {
            $expected_source_lang = 'en'; // Hardcode fallback as requested by the user
        }

        if ($expected_source_lang !== '') {
            foreach ($translations as $translation) {
                if (
                    isset($translation->language_code, $translation->element_id)
                    && $translation->language_code === $expected_source_lang
                ) {
                    return (int) $translation->element_id;
                }
            }
        }

        foreach ($translations as $translation) {
            if (
                isset($translation->source_language_code, $translation->element_id)
                && (string) $translation->source_language_code === ''
            ) {
                return (int) $translation->element_id;
            }
        }

        return 0;
    }

    private function get_original_term_id($term_id, $taxonomy, $source_lang = null) {
        $term_id = (int) $term_id;
        $taxonomy = (string) $taxonomy;
        if ($term_id <= 0 || $taxonomy === '') {
            return 0;
        }

        $tt_id = $this->get_term_taxonomy_id($term_id, $taxonomy);
        $element_id = $tt_id > 0 ? $tt_id : $term_id;
        $element_type = 'tax_' . $taxonomy;
        $original_id = $this->wpml_get_original_element_id($element_id, $element_type, $source_lang);
        if ($original_id) {
            return $this->wpml_tax_element_to_term_id((int) $original_id, $taxonomy);
        }

        $trid = $this->wpml_get_trid($element_id, $element_type);
        if (!$trid) {
            return 0;
        }

        $translations = $this->wpml_get_element_translations($trid, $element_type);
        if (!is_array($translations) || empty($translations)) {
            return 0;
        }

        $expected_source_lang = $source_lang ? (string) $source_lang : (string) apply_filters('wpml_default_language', null);
        if ($expected_source_lang !== '') {
            foreach ($translations as $translation) {
                if (
                    isset($translation->language_code, $translation->element_id)
                    && $translation->language_code === $expected_source_lang
                ) {
                    return $this->wpml_tax_element_to_term_id((int) $translation->element_id, $taxonomy);
                }
            }
        }

        foreach ($translations as $translation) {
            if (
                isset($translation->source_language_code, $translation->element_id)
                && (string) $translation->source_language_code === ''
            ) {
                return $this->wpml_tax_element_to_term_id((int) $translation->element_id, $taxonomy);
            }
        }

        return 0;
    }

    private function sync_raw_rules_for_post($post_id, $post_type, $source_lang = null) {
        $post_id = (int) $post_id;
        $post_type = (string) $post_type;
        if ($post_id <= 0 || $post_type === '') {
            return false;
        }

        $source_post_id = $this->get_original_post_id($post_id, $post_type, $source_lang);
        if ($source_post_id <= 0 || $source_post_id === $post_id) {
            return false;
        }

        $updated = false;
        foreach ($this->get_raw_rules() as $rule) {
            if ($this->get_raw_rule_scope($rule) !== 'post') {
                continue;
            }
            if ($this->get_raw_rule_object_name($rule) !== $post_type) {
                continue;
            }
            $meta_key = isset($rule['meta_key']) ? (string) $rule['meta_key'] : '';
            if ($meta_key === '') {
                continue;
            }
            $source_value = get_post_meta($source_post_id, $meta_key, true);
            update_post_meta($post_id, $meta_key, $source_value);
            $updated = true;
        }

        return $updated;
    }

    private function sync_raw_rules_for_post_group($post_id, $post_type) {
        $post_id = (int) $post_id;
        $post_type = (string) $post_type;
        if ($post_id <= 0 || $post_type === '') {
            return false;
        }

        $element_type = 'post_' . $post_type;
        $trid = $this->wpml_get_trid($post_id, $element_type);
        if (!$trid) {
            return false;
        }

        $translations = $this->wpml_get_element_translations($trid, $element_type);
        if (!is_array($translations) || empty($translations)) {
            return false;
        }

        $updated = false;
        $updated_langs = [];
        $source_lang = $this->get_raw_source_language_code();
        foreach ($this->get_raw_rules() as $rule) {
            if ($this->get_raw_rule_scope($rule) !== 'post') {
                continue;
            }
            if ($this->get_raw_rule_object_name($rule) !== $post_type) {
                continue;
            }
            $meta_key = isset($rule['meta_key']) ? (string) $rule['meta_key'] : '';
            if ($meta_key === '') {
                continue;
            }

            // Source must be the original language element only.
            $source_post_id = 0;
            foreach ($translations as $translation) {
                if (
                    isset($translation->language_code, $translation->element_id)
                    && (string) $translation->language_code === $source_lang
                ) {
                    $candidate_id = (int) $translation->element_id;
                    if ($candidate_id > 0) {
                        $source_post_id = $candidate_id;
                        break;
                    }
                }
            }
            // Fallback to original marker if source language is not found in this group.
            if ($source_post_id <= 0) {
                foreach ($translations as $translation) {
                    if (
                        isset($translation->source_language_code, $translation->element_id)
                        && (string) $translation->source_language_code === ''
                    ) {
                        $candidate_id = (int) $translation->element_id;
                        if ($candidate_id > 0) {
                            $source_post_id = $candidate_id;
                            break;
                        }
                    }
                }
            }
            if ($source_post_id <= 0) {
                continue;
            }
            $source_value = get_post_meta($source_post_id, $meta_key, true);
            // Force copy to all translations except the original source.
            foreach ($translations as $translation) {
                if (!isset($translation->element_id)) {
                    continue;
                }
                $target_post_id = (int) $translation->element_id;
                if ($target_post_id <= 0 || $target_post_id === $source_post_id) {
                    continue;
                }

                // Force copy: always write source value to every translation.
                update_post_meta($target_post_id, $meta_key, $source_value);
                $updated = true;
                $target_lang = isset($translation->language_code) ? (string) $translation->language_code : 'unknown';
                if (!isset($updated_langs[$target_lang])) {
                    $updated_langs[$target_lang] = 0;
                }
                $updated_langs[$target_lang]++;
            }
        }

        $this->raw_last_updated_langs = $updated_langs;
        return $updated;
    }

    private function sync_raw_rules_for_term($term_id, $taxonomy, $source_lang = null) {
        $term_id = (int) $term_id;
        $taxonomy = (string) $taxonomy;
        if ($term_id <= 0 || $taxonomy === '') {
            return false;
        }

        $source_term_id = $this->get_original_term_id($term_id, $taxonomy, $source_lang);
        if ($source_term_id <= 0 || $source_term_id === $term_id) {
            return false;
        }

        $updated = false;
        foreach ($this->get_raw_rules() as $rule) {
            if ($this->get_raw_rule_scope($rule) !== 'taxonomy') {
                continue;
            }
            if ($this->get_raw_rule_object_name($rule) !== $taxonomy) {
                continue;
            }
            $meta_key = isset($rule['meta_key']) ? (string) $rule['meta_key'] : '';
            if ($meta_key === '') {
                continue;
            }
            $source_value = $this->get_raw_term_meta_value($source_term_id, $meta_key);
            update_term_meta($term_id, $meta_key, $source_value);
            $updated = true;
        }

        return $updated;
    }

    private function sync_raw_rules_for_term_group($term_id, $taxonomy) {
        $term_id = (int) $term_id;
        $taxonomy = (string) $taxonomy;
        if ($term_id <= 0 || $taxonomy === '') {
            return false;
        }

        $element_type = 'tax_' . $taxonomy;
        $tt_id = $this->get_term_taxonomy_id($term_id, $taxonomy);
        $element_id = $tt_id > 0 ? $tt_id : $term_id;
        $trid = $this->wpml_get_trid($element_id, $element_type);
        if (!$trid) {
            return false;
        }

        $translations = $this->wpml_get_element_translations($trid, $element_type);
        if (!is_array($translations) || empty($translations)) {
            return false;
        }

        $updated = false;
        $updated_langs = [];
        $source_lang = $this->get_raw_source_language_code();
        foreach ($this->get_raw_rules() as $rule) {
            if ($this->get_raw_rule_scope($rule) !== 'taxonomy') {
                continue;
            }
            if ($this->get_raw_rule_object_name($rule) !== $taxonomy) {
                continue;
            }
            $meta_key = isset($rule['meta_key']) ? (string) $rule['meta_key'] : '';
            if ($meta_key === '') {
                continue;
            }

            // Source must be the original language term only.
            $source_term_id = 0;
            foreach ($translations as $translation) {
                if (
                    isset($translation->language_code, $translation->element_id)
                    && (string) $translation->language_code === $source_lang
                ) {
                    $candidate_term_id = $this->wpml_tax_element_to_term_id((int) $translation->element_id, $taxonomy);
                    if ($candidate_term_id > 0) {
                        $source_term_id = $candidate_term_id;
                        break;
                    }
                }
            }
            // Fallback to original marker if source language is not found in this group.
            if ($source_term_id <= 0) {
                foreach ($translations as $translation) {
                    if (
                        isset($translation->source_language_code, $translation->element_id)
                        && (string) $translation->source_language_code === ''
                    ) {
                        $candidate_term_id = $this->wpml_tax_element_to_term_id((int) $translation->element_id, $taxonomy);
                        if ($candidate_term_id > 0) {
                            $source_term_id = $candidate_term_id;
                            break;
                        }
                    }
                }
            }
            if ($source_term_id <= 0) {
                continue;
            }
            $source_value = $this->get_raw_term_meta_value($source_term_id, $meta_key);
            // Force copy to all translations except the original source.
            foreach ($translations as $translation) {
                if (!isset($translation->element_id)) {
                    continue;
                }
                $target_term_id = $this->wpml_tax_element_to_term_id((int) $translation->element_id, $taxonomy);
                if ($target_term_id <= 0 || $target_term_id === $source_term_id) {
                    continue;
                }

                // Force copy: always write source value to every translation.
                update_term_meta($target_term_id, $meta_key, $source_value);
                $updated = true;
                $target_lang = isset($translation->language_code) ? (string) $translation->language_code : 'unknown';
                if (!isset($updated_langs[$target_lang])) {
                    $updated_langs[$target_lang] = 0;
                }
                $updated_langs[$target_lang]++;
            }
        }

        $this->raw_last_updated_langs = $updated_langs;
        return $updated;
    }

    private function consume_raw_last_updated_langs() {
        $langs = is_array($this->raw_last_updated_langs) ? $this->raw_last_updated_langs : [];
        $this->raw_last_updated_langs = [];
        return $langs;
    }

    private function wpml_tables_ready() {
        global $wpdb;
        static $ready = null;

        if ($ready !== null) {
            return $ready;
        }
        if (empty($wpdb)) {
            $ready = false;
            return $ready;
        }

        $table = $wpdb->prefix . 'icl_translations';
        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        $ready = ($exists === $table);
        return $ready;
    }

    private function wpml_get_trid($element_id, $element_type) {
        $element_id = (int) $element_id;
        $element_type = (string) $element_type;
        if ($element_id <= 0 || $element_type === '') {
            return 0;
        }

        if ($this->wpml_tables_ready()) {
            global $wpdb;
            $trid = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT trid FROM {$wpdb->prefix}icl_translations WHERE element_id = %d AND element_type = %s LIMIT 1",
                    $element_id,
                    $element_type
                )
            );
            if ($trid) {
                return (int) $trid;
            }
        }

        $fallback = apply_filters('wpml_element_trid', null, $element_id, $element_type);
        return $fallback ? (int) $fallback : 0;
    }

    private function wpml_get_element_translations($trid, $element_type) {
        $trid = (int) $trid;
        $element_type = (string) $element_type;
        if ($trid <= 0 || $element_type === '') {
            return [];
        }

        if ($this->wpml_tables_ready()) {
            global $wpdb;
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT element_id, language_code, source_language_code
                     FROM {$wpdb->prefix}icl_translations
                     WHERE trid = %d AND element_type = %s",
                    $trid,
                    $element_type
                )
            );
            if (is_array($rows) && !empty($rows)) {
                return $rows;
            }
        }

        $fallback = apply_filters('wpml_get_element_translations', null, $trid, $element_type);
        return is_array($fallback) ? $fallback : [];
    }

    private function wpml_get_all_elements_by_type($element_type) {
        $element_type = (string) $element_type;
        if ($element_type === '') {
            return [];
        }

        if ($this->wpml_tables_ready()) {
            global $wpdb;
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT element_id, trid, language_code, source_language_code
                     FROM {$wpdb->prefix}icl_translations
                     WHERE element_type = %s",
                    $element_type
                )
            );
            if (is_array($rows)) {
                return $rows;
            }
        }

        // Fallback path if table access is unavailable.
        return [];
    }

    private function wpml_get_original_element_id($element_id, $element_type, $source_lang = null) {
        $element_id = (int) $element_id;
        $element_type = (string) $element_type;
        if ($element_id <= 0 || $element_type === '') {
            return 0;
        }

        if ($this->wpml_tables_ready()) {
            $trid = $this->wpml_get_trid($element_id, $element_type);
            if ($trid > 0) {
                $translations = $this->wpml_get_element_translations($trid, $element_type);
                $expected_source_lang = $source_lang ? (string) $source_lang : (string) apply_filters('wpml_default_language', null);
                if (empty($expected_source_lang)) {
                    $expected_source_lang = 'en'; // Hardcode fallback as requested by the user
                }

                if ($expected_source_lang !== '') {
                    foreach ($translations as $translation) {
                        if (
                            isset($translation->language_code, $translation->element_id)
                            && (string) $translation->language_code === $expected_source_lang
                        ) {
                            return (int) $translation->element_id;
                        }
                    }
                }

                foreach ($translations as $translation) {
                    if (
                        isset($translation->source_language_code, $translation->element_id)
                        && (string) $translation->source_language_code === ''
                    ) {
                        return (int) $translation->element_id;
                    }
                }
            }
        }

        $fallback = apply_filters('wpml_original_element_id', null, $element_id, $element_type);
        return $fallback ? (int) $fallback : 0;
    }

    private function get_term_taxonomy_id($term_id, $taxonomy) {
        global $wpdb;
        $term_id = (int) $term_id;
        $taxonomy = (string) $taxonomy;
        if ($term_id <= 0 || $taxonomy === '' || empty($wpdb)) {
            return 0;
        }

        $tt_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_id = %d AND taxonomy = %s LIMIT 1",
                $term_id,
                $taxonomy
            )
        );

        return $tt_id ? (int) $tt_id : 0;
    }

    private function get_term_language_code($term_id, $taxonomy) {
        $term_id = (int) $term_id;
        $taxonomy = (string) $taxonomy;
        if ($term_id <= 0 || $taxonomy === '') {
            return 'unknown';
        }

        $tt_id = $this->get_term_taxonomy_id($term_id, $taxonomy);
        $element_id = $tt_id > 0 ? $tt_id : $term_id;
        $details = apply_filters('wpml_element_language_details', null, [
            'element_id' => $element_id,
            'element_type' => 'tax_' . $taxonomy,
        ]);

        if (is_object($details) && !empty($details->language_code)) {
            return (string) $details->language_code;
        }
        if (is_array($details) && !empty($details['language_code'])) {
            return (string) $details['language_code'];
        }

        return 'unknown';
    }

    private function wpml_tax_element_to_term_id($element_id, $taxonomy) {
        global $wpdb;
        $element_id = (int) $element_id;
        $taxonomy = (string) $taxonomy;
        if ($element_id <= 0 || $taxonomy === '' || empty($wpdb)) {
            return 0;
        }

        // In many WPML taxonomy operations, element_id is term_taxonomy_id.
        $term_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT term_id FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id = %d AND taxonomy = %s LIMIT 1",
                $element_id,
                $taxonomy
            )
        );
        if ($term_id) {
            return (int) $term_id;
        }

        // Fallback: if element_id already is term_id.
        return (int) $element_id;
    }

    private function get_raw_term_meta_value($term_id, $meta_key) {
        global $wpdb;
        $term_id = (int) $term_id;
        $meta_key = (string) $meta_key;
        if ($term_id <= 0 || $meta_key === '' || empty($wpdb)) {
            return null;
        }

        $raw = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->termmeta} WHERE term_id = %d AND meta_key = %s ORDER BY meta_id DESC LIMIT 1",
                $term_id,
                $meta_key
            )
        );

        if ($raw === null) {
            return null;
        }

        return maybe_unserialize($raw);
    }

    private function get_raw_source_language_code() {
        $lang = (string) get_option(self::OPTION_RAW_SOURCE_LANG, 'en');
        return $lang !== '' ? $lang : 'en';
    }

    private function get_mapper_rules() {
        return array_values(array_filter($this->get_rules(), static function ($rule) {
            return isset($rule['type']) && $rule['type'] !== 'raw';
        }));
    }

    private function get_raw_rules() {
        return array_values(array_filter($this->get_rules(), static function ($rule) {
            return isset($rule['type']) && $rule['type'] === 'raw';
        }));
    }

    private function get_raw_rule_scope($rule) {
        $scope = isset($rule['scope']) ? (string) $rule['scope'] : '';
        if (!in_array($scope, ['post', 'taxonomy'], true)) {
            $scope = !empty($rule['taxonomy']) ? 'taxonomy' : 'post';
        }
        return $scope;
    }

    private function get_raw_rule_object_name($rule) {
        if (!empty($rule['object_name'])) {
            return (string) $rule['object_name'];
        }
        $scope = $this->get_raw_rule_scope($rule);
        if ($scope === 'taxonomy') {
            return isset($rule['taxonomy']) ? (string) $rule['taxonomy'] : '';
        }
        return isset($rule['post_type']) ? (string) $rule['post_type'] : '';
    }

    private function get_rules(): array {
        $rules = get_option(self::OPTION_RULES, []);
        if (!is_array($rules)) {
            return [];
        }

        $clean = [];
        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $post_type = isset($rule['post_type']) ? sanitize_key((string) $rule['post_type']) : '';
            $taxonomy = isset($rule['taxonomy']) ? sanitize_key((string) $rule['taxonomy']) : '';
            $scope = isset($rule['scope']) ? sanitize_key((string) $rule['scope']) : '';
            $object_name = isset($rule['object_name']) ? sanitize_key((string) $rule['object_name']) : '';
            $meta_key = isset($rule['meta_key']) ? sanitize_text_field((string) $rule['meta_key']) : '';
            $type = isset($rule['type']) ? sanitize_key((string) $rule['type']) : '';
            $object_type = isset($rule['object_type']) ? sanitize_key((string) $rule['object_type']) : '';
            $storage_mode = isset($rule['storage_mode']) ? sanitize_key((string) $rule['storage_mode']) : 'auto';

            if ($meta_key === '') {
                continue;
            }
            if (!in_array($type, ['post', 'term', 'raw'], true)) {
                continue;
            }
            if (in_array($type, ['post', 'term'], true)) {
                if ($post_type === '' || $object_type === '') {
                    continue;
                }
            } else {
                if (!in_array($scope, ['post', 'taxonomy'], true)) {
                    $scope = $taxonomy !== '' ? 'taxonomy' : 'post';
                }
                if ($object_name === '') {
                    $object_name = $scope === 'taxonomy' ? $taxonomy : $post_type;
                }
                if ($object_name === '') {
                    continue;
                }
                if ($scope === 'taxonomy') {
                    $taxonomy = $object_name;
                    $post_type = '';
                } else {
                    $post_type = $object_name;
                    $taxonomy = '';
                }
                $object_type = '';
            }
            if (!in_array($storage_mode, ['auto', 'id', 'name_key_map'], true)) {
                $storage_mode = 'auto';
            }

            $clean[] = [
                'post_type' => $post_type,
                'meta_key' => $meta_key,
                'type' => $type,
                'object_type' => $object_type,
                'storage_mode' => $storage_mode,
                'scope' => $scope,
                'object_name' => $object_name,
                'taxonomy' => $taxonomy,
            ];
        }

        return $clean;
    }

    private function map_meta_value_by_rule($value, $rule, $target_lang, $source_lang = null) {
        $object_type = isset($rule['object_type']) ? (string) $rule['object_type'] : '';
        $type = isset($rule['type']) ? (string) $rule['type'] : '';
        $storage_mode = $this->get_rule_storage_mode($rule);

        if ($type === 'raw') {
            return $value;
        }

        if (is_string($value)) {
            $maybe = maybe_unserialize($value);
            if ($maybe !== $value) {
                $value = $maybe;
            }
        }

        if (is_array($value)) {
            $out = [];
            $type = isset($rule['type']) ? (string) $rule['type'] : '';
            $object_type = isset($rule['object_type']) ? (string) $rule['object_type'] : '';
            foreach ($value as $k => $v) {
                $new_key = $k;

                // Some meta fields store selected terms/posts as associative keys (e.g. serialized maps).
                if ($storage_mode !== 'id' && is_string($k) && $k !== '' && !ctype_digit($k)) {
                    $mapped_key = $this->map_single_key_by_rule($k, $type, $object_type, $target_lang, $source_lang);
                    if (is_scalar($mapped_key) && $mapped_key !== '') {
                        $new_key = (string) $mapped_key;
                    }
                }

                $mapped_value = $this->map_meta_value_by_rule($v, $rule, $target_lang, $source_lang);

                // Preserve data if mapped keys collide.
                if (array_key_exists($new_key, $out) && $new_key !== $k) {
                    $out[$k] = $mapped_value;
                } else {
                    $out[$new_key] = $mapped_value;
                }
            }
            return $out;
        }

        if (is_string($value) && strpos($value, ',') !== false) {
            $parts = array_filter(array_map('trim', explode(',', $value)), static function ($p) {
                return $p !== '';
            });

            $parts = array_map(function ($p) use ($type, $object_type, $target_lang, $source_lang, $storage_mode) {
                return (string) $this->map_single_value_by_rule($p, $type, $object_type, $target_lang, $source_lang, $storage_mode);
            }, $parts);

            return implode(',', $parts);
        }

        // Support pipe-separated format (e.g. 228|228 -> 232|232): convert every part
        if (is_string($value) && strpos($value, '|') !== false) {
            $parts = array_map('trim', explode('|', $value));
            $converted_parts = [];
            foreach ($parts as $part) {
                if ($part === '') {
                    $converted_parts[] = '';
                    continue;
                }
                $converted = $this->map_single_value_by_rule($part, $type, $object_type, $target_lang, $source_lang, $storage_mode);
                $converted_parts[] = (string) $converted;
            }
            return implode('|', $converted_parts);
        }

        return $this->map_single_value_by_rule($value, $type, $object_type, $target_lang, $source_lang, $storage_mode);
    }

    private function map_single_value_by_rule($value, $type, $object_type, $target_lang, $source_lang = null, $storage_mode = 'auto') {
        if ($value === '' || $value === null) {
            return $value;
        }

        $raw_string = is_string($value) ? trim($value) : null;
        $is_numeric = is_numeric($value) || (is_string($value) && ctype_digit($raw_string));

        if ($is_numeric) {
            $id_int = (int) $value;
            if ($id_int <= 0) {
                return $value;
            }
            
            // JetEngine items might have source_lang as null, so WPML might bounce the ID back if standard filters fail.
            // Explicitly finding the language of the element first helps WPML understand the translation path.
            $element_type_wpml = ($type === 'term') ? 'tax_' . $object_type : 'post_' . $object_type;
            $term_lang_info = apply_filters('wpml_element_language_details', null, ['element_id' => $id_int, 'element_type' => $element_type_wpml]);
            $term_source_lang = !empty($term_lang_info->language_code) ? $term_lang_info->language_code : $source_lang;
            
            // Only try translating if the source language of the term is DIFFERENT from the target language.
            // Or just ask WPML, but give it the original language if we found it.
            if ($term_source_lang && $term_source_lang === $target_lang) {
                return $value; // Already in target language
            }
            // By default WPML filter uses current language if missing, which fails in background syncs.
            // Passing true for $return_original_if_missing ensures it still falls back safely.
            $translated = apply_filters('wpml_object_id', $id_int, $object_type, true, $target_lang);
            if ($translated && $translated !== $id_int) {
                return is_string($value) ? (string) $translated : (int) $translated;
            }

            // Fallback 1: Direct DB query for exact element type (e.g. tax_schudle-days-of-weak)
            // WPML often tags the same ID with multiple trids (like package_elementor) which breaks standard filters
            // when context is lost. This forces it to look only at the exact term/post translation group.
            global $wpdb;
            $table_icl = $wpdb->prefix . 'icl_translations';
            $sql_trid = $wpdb->prepare("SELECT trid FROM {$table_icl} WHERE element_id = %d AND element_type = %s", $id_int, $element_type_wpml);
            $trid = $wpdb->get_var($sql_trid);
            
            if ($trid) {
                $sql_trans = $wpdb->prepare("SELECT element_id FROM {$table_icl} WHERE trid = %d AND language_code = %s", $trid, $target_lang);
                $translated_direct = $wpdb->get_var($sql_trans);
                if ($translated_direct) {
                    return is_string($value) ? (string) $translated_direct : (int) $translated_direct;
                }
            }

            // Fallback 2: some fields store term_taxonomy_id instead of term_id.
            if ($type === 'term') {
                $fallback_term_id = $this->term_id_from_term_taxonomy_id($id_int, $object_type);
                if ($fallback_term_id > 0) {
                    // Try DB fallback for the term_taxonomy_id as well
                    $sql_trid_fallback = $wpdb->prepare("SELECT trid FROM {$table_icl} WHERE element_id = %d AND element_type = %s", $fallback_term_id, $element_type_wpml);
                    $trid_fallback = $wpdb->get_var($sql_trid_fallback);
                    if ($trid_fallback) {
                        $sql_trans_fallback = $wpdb->prepare("SELECT element_id FROM {$table_icl} WHERE trid = %d AND language_code = %s", $trid_fallback, $target_lang);
                        $translated_tt_direct = $wpdb->get_var($sql_trans_fallback);
                        if ($translated_tt_direct) {
                            return is_string($value) ? (string) $translated_tt_direct : (int) $translated_tt_direct;
                        }
                    }
                    
                    // Final WPML filter attempt
                    $translated_fallback = apply_filters('wpml_object_id', $fallback_term_id, $object_type, true, $target_lang);
                    if ($translated_fallback && $translated_fallback !== $fallback_term_id) {
                        return is_string($value) ? (string) $translated_fallback : (int) $translated_fallback;
                    }
                    return is_string($value) ? (string) $fallback_term_id : $fallback_term_id;
                }
            }

            return $value;
        }

        // Support name/slug values as well (mainly for term fields, and post fields if unique).
        if (!is_string($value) || $raw_string === '') {
            return $value;
        }

        if ($storage_mode === 'id') {
            return $value;
        }

        if ($type === 'term') {
            $source_id = $this->resolve_term_id_from_value($raw_string, $object_type, $source_lang);
            if (!$source_id) {
                return $value;
            }

            $translated_id = apply_filters('wpml_object_id', $source_id, $object_type, true, $target_lang);
            if (!$translated_id) {
                return $value;
            }
            return is_string($value) ? (string) $translated_id : (int) $translated_id;
        }

        if ($type === 'post') {
            $source_id = $this->resolve_post_id_from_value($raw_string, $object_type, $source_lang);
            if (!$source_id) {
                return $value;
            }

            $translated_id = apply_filters('wpml_object_id', $source_id, $object_type, true, $target_lang);
            if (!$translated_id) {
                return $value;
            }
            return (int) $translated_id;
        }

        return $value;
    }

    private function map_single_key_by_rule($value, $type, $object_type, $target_lang, $source_lang = null) {
        if (!is_string($value) || trim($value) === '') {
            return $value;
        }

        $raw_string = trim($value);
        $is_slug_like = $this->looks_like_slug($raw_string);

        if ($type === 'term') {
            $source_id = $this->resolve_term_id_from_value($raw_string, $object_type, $source_lang);
            if (!$source_id) {
                return $value;
            }
            $translated_id = apply_filters('wpml_object_id', $source_id, $object_type, true, $target_lang);
            if (!$translated_id) {
                return $value;
            }
            $translated_term = get_term((int) $translated_id, $object_type);
            if (!$translated_term || is_wp_error($translated_term)) {
                return $value;
            }
            return $is_slug_like ? $translated_term->slug : $translated_term->name;
        }

        if ($type === 'post') {
            $source_id = $this->resolve_post_id_from_value($raw_string, $object_type, $source_lang);
            if (!$source_id) {
                return $value;
            }
            $translated_id = apply_filters('wpml_object_id', $source_id, $object_type, true, $target_lang);
            if (!$translated_id) {
                return $value;
            }
            $translated_post = get_post((int) $translated_id);
            if (!($translated_post instanceof WP_Post)) {
                return $value;
            }
            return $is_slug_like ? $translated_post->post_name : $translated_post->post_title;
        }

        return $value;
    }

    private function resolve_term_id_from_value($raw_value, $taxonomy, $source_lang = null) {
        $raw_value = trim((string) $raw_value);
        if ($raw_value === '' || !taxonomy_exists($taxonomy)) {
            return 0;
        }

        $query = [
            'taxonomy' => $taxonomy,
            'hide_empty' => false,
            'number' => 1,
            'name' => $raw_value,
            'fields' => 'ids',
        ];
        if (!empty($source_lang)) {
            $query['lang'] = $source_lang;
        }

        $terms = get_terms($query);
        if (!is_wp_error($terms) && !empty($terms[0])) {
            return (int) $terms[0];
        }

        $by_slug = get_term_by('slug', sanitize_title($raw_value), $taxonomy);
        if ($by_slug && !is_wp_error($by_slug)) {
            return (int) $by_slug->term_id;
        }

        $by_name = get_term_by('name', $raw_value, $taxonomy);
        if ($by_name && !is_wp_error($by_name)) {
            return (int) $by_name->term_id;
        }

        return 0;
    }

    private function resolve_post_id_from_value($raw_value, $post_type, $source_lang = null) {
        $raw_value = trim((string) $raw_value);
        if ($raw_value === '' || !post_type_exists($post_type)) {
            return 0;
        }

        $args = [
            'post_type' => $post_type,
            'post_status' => 'any',
            'posts_per_page' => 20,
            'fields' => 'ids',
            'suppress_filters' => false,
            's' => $raw_value,
        ];
        if (!empty($source_lang)) {
            $args['lang'] = $source_lang;
        }

        $posts = get_posts($args);
        if (!empty($posts)) {
            foreach ($posts as $candidate_id) {
                $candidate = get_post((int) $candidate_id);
                if ($candidate instanceof WP_Post && $candidate->post_title === $raw_value) {
                    return (int) $candidate->ID;
                }
            }
        }

        $slug_args = [
            'post_type' => $post_type,
            'name' => sanitize_title($raw_value),
            'post_status' => 'any',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'suppress_filters' => false,
        ];
        if (!empty($source_lang)) {
            $slug_args['lang'] = $source_lang;
        }

        $slug_posts = get_posts($slug_args);
        if (!empty($slug_posts[0])) {
            return (int) $slug_posts[0];
        }

        return 0;
    }

    private function looks_like_slug($value) {
        $value = trim((string) $value);
        if ($value === '') {
            return false;
        }
        return (bool) preg_match('/^[a-z0-9\\-_]+$/', $value);
    }

    private function get_rule_storage_mode($rule) {
        $mode = isset($rule['storage_mode']) ? (string) $rule['storage_mode'] : 'auto';
        if (!in_array($mode, ['auto', 'id', 'name_key_map'], true)) {
            $mode = 'auto';
        }
        return $mode;
    }

    private function term_id_from_term_taxonomy_id($term_taxonomy_id, $taxonomy) {
        global $wpdb;
        $term_taxonomy_id = (int) $term_taxonomy_id;
        $taxonomy = (string) $taxonomy;
        if ($term_taxonomy_id <= 0 || $taxonomy === '' || empty($wpdb)) {
            return 0;
        }

        $term_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT term_id FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id = %d AND taxonomy = %s",
                $term_taxonomy_id,
                $taxonomy
            )
        );

        return $term_id ? (int) $term_id : 0;
    }
}

add_action('plugins_loaded', function () {
    if (!defined('ICL_SITEPRESS_VERSION')) {
        return;
    }
    new WPML_Meta_ID_Mapper_UI();
}, 20);

require_once __DIR__ . "/includes/class-wpml-meta-id-mapper-source-recovery.php";
require_once __DIR__ . "/includes/class-wpml-meta-id-mapper-source-recovery-ui.php";
add_action("plugins_loaded", ["WPML_Meta_ID_Mapper_Source_Recovery_UI", "boot"], 25);
