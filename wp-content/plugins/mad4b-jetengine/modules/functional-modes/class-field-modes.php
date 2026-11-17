<?php
defined('ABSPATH') || exit;
/**
 * Dynamic field modes. Defs and UI presentation are independent of JetEngine storage.
 * Never changes JetEngine meta keys, field types, pricing calculations or option values.
 */
final class MAD4B_JetEngine_Field_Modes_V1 {
    const VERSION = '1.0.0';
    const OPTION = 'mad4b_jetengine_field_modes_v1';
    public const FIELD_WIDTHS = ['100%', '75%', '66.66666%', '50%', '33.33333%', '25%'];
    public static function normalized_width($value): string {
        return is_string($value) && in_array($value, self::FIELD_WIDTHS, true) ? $value : '100%';
    }
    private static string $dir = '';
    private static array $modes = [];
    private static ?array $pageCache = null;

    public static function boot(string $dir): void {
        self::$dir = $dir;
        if (!is_admin()) return;
        // Brand-neutral display after JetEngine/WPML translation, without editing labels or values.
        add_filter('jet-engine/compatibility/translate-string',
            ['MAD4B_JetEngine_Guidance_V1', 'strip_brand_from_admin_translation'], 100);
        add_action('plugins_loaded', static function (): void { self::$modes = self::discover_modes(); }, 15);
        add_action('admin_menu', [__CLASS__, 'menu'], 30);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue'], 55);
        add_action('jet-engine/meta-boxes/templates/fields/controls', [__CLASS__, 'native_editor_select'], 50);
    }
    private static function discover_modes(): array {
        $out = [];
        $files = glob(self::$dir . '/modes/*.php') ?: [];
        sort($files, SORT_STRING);
        foreach ($files as $file) {
            $id = basename($file, '.php');
            if (!preg_match('/^[a-z][a-z0-9-]{1,48}$/D', $id)) continue;
            $spec = require $file;
            if (!is_array($spec) || ($spec['id'] ?? '') !== $id ||
                !is_string($spec['label'] ?? null)) continue;
            $out[$id] = [
                'id'=>$id, 'label'=>sanitize_text_field($spec['label']),
                'types'=>(string)($spec['types'] ?? '*'),
                'description'=>sanitize_text_field((string)($spec['description'] ?? '')),
            ];
        }
        // Additional trusted modules may register descriptions, never executable PHP from admin requests.
        $extensions = apply_filters('mad4b/jetengine/field_modes', []);
        if (is_array($extensions)) foreach ($extensions as $id => $spec) {
            if (!is_string($id) || !preg_match('/^[a-z][a-z0-9-]{1,48}$/D', $id) ||
                isset($out[$id]) || !is_array($spec) ||
                !is_string($spec['label'] ?? null) || !is_string($spec['types'] ?? null)) continue;
            $out[$id] = [
                'id'=>$id,'label'=>sanitize_text_field($spec['label']),
                'types'=>sanitize_text_field($spec['types']),
                'description'=>sanitize_text_field((string)($spec['description']??'')),
            ];
        }
        return $out;
    }
    public static function modes(): array { if (!self::$modes) self::$modes = self::discover_modes(); return self::$modes; }
    public static function pages(): array {
        if (self::$pageCache !== null) return self::$pageCache;
        global $wpdb;
        $table = $wpdb->prefix . 'jet_post_types';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) return self::$pageCache = [];
        $rows = $wpdb->get_results("SELECT slug, meta_fields, labels FROM {$table} WHERE status='page' ORDER BY slug ASC LIMIT 100", ARRAY_A);
        $pages = [];
        foreach ((array)$rows as $row) {
            $slug = (string)$row['slug'];
            if (!preg_match('/^[a-z0-9_-]{2,90}$/D', $slug)) continue;
            $defs = maybe_unserialize($row['meta_fields']);
            // Service Activation extends its native JetEngine field definition
            // through raw-fields filters. Include those *existing native*
            // dynamic fields in Modern Mode discovery; do not invent parallel
            // input nodes or keep them in an unrelated 'Other fields' bucket.
            if(in_array($slug,['service-activation','etg-dynamic-pricing-controls'],true) && is_array($defs)){
                $defs=apply_filters('jet-engine/options-pages/raw-fields',
                    $defs,(object)['slug'=>$slug]);
            }
            $labels = maybe_unserialize($row['labels']);
            $page = ['slug'=>$slug,'label'=>sanitize_text_field((string)($labels['name']??$slug)), 'fields'=>[]];
            foreach ((array)$defs as $field) {
                if (!is_array($field)) continue;
                $name = (string)($field['name'] ?? '');
                $type = (string)($field['type'] ?? '');
                if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,88}$/D', $name)) continue;
                if (preg_match('/(?:password|secret|token|api_?key|private|customer|email|phone|mobile|billing|payment_card|oauth|webhook)/i', $name)) continue;
                $subFields = [];
                if ($type === 'repeater') {
                    foreach ((array)($field['repeater-fields'] ?? $field['repeater_fields'] ?? []) as $sub) {
                        if (!is_array($sub)) continue;
                        $subName = (string)($sub['name'] ?? '');
                        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,80}$/D', $subName)) continue;
                        if (preg_match('/(?:password|secret|token|api_?key|private|customer|email|phone|mobile|billing|payment_card|oauth|webhook)/i', $subName)) continue;
                        $subFields[] = [
                            'name'=>$subName,
                            'label'=>sanitize_text_field((string)($sub['title']??$subName)),
                            'type'=>sanitize_key((string)($sub['type']??'text')),
                        ];
                    }
                }
                $page['fields'][$name] = ['name'=>$name,'type'=>sanitize_key($type),
                    'label'=>sanitize_text_field((string)($field['title']??$name)),
                    'repeater_fields'=>$subFields,
                    'description'=>MAD4B_JetEngine_Guidance_V1::neutral_source_label($field['description'] ?? ''),
                    'width'=>self::normalized_width($field['width'] ?? '100%'),
                    'field_layout'=>in_array($field['field_layout'] ?? '', ['inline', 'stacked'], true) ? $field['field_layout'] : 'inline',
                    'native_mode'=>sanitize_key((string)($field['mad4b_ui_mode']??''))];
            }
            $pages[$slug] = $page;
        }
        return self::$pageCache = $pages;
    }
    private static function stored(): array {
        $raw = get_option(self::OPTION, []);
        return is_array($raw) ? $raw : [];
    }
    public static function suggest(string $name, string $type): string {
        if ($type === 'number') return 'slider';
        if (in_array($type, ['switcher','checkbox'], true)) return 'toggle';
        if (in_array($type, ['select','radio'], true)) return 'select-cards';
        if (in_array($type, ['date','datetime-local'], true)) return 'date';
        if ($type === 'repeater') return 'rules';
        if ($type === 'textarea' && preg_match('/formula|json|rules|polic/i', $name)) return 'code';
        return 'field-card';
    }
    public static function compatible(string $mode, string $type): bool {
        $modes = self::modes();
        if (!isset($modes[$mode])) return false;
        $allowed = $modes[$mode]['types'];
        return $allowed === '*' || in_array($type, explode(',', $allowed), true);
    }
    public static function resolved(string $slug, array $field): array {
        $name = $field['name'];
        $all = self::stored();
        $local = $all['pages'][$slug][$name] ?? [];
        $local = is_array($local) ? $local : [];
        $raw = (string)($local['mode']??$field['native_mode']??'');
        $mode = self::compatible($raw, $field['type']) ? $raw : self::suggest($name, $field['type']);
        $mode = self::compatible($mode, $field['type']) ? $mode : 'standard';
        $out = ['name'=>$name, 'label'=>$field['label'], 'type'=>$field['type'],
            'mode'=>$mode, 'source'=>isset($local['mode'])?'company':(!empty($field['native_mode'])?'jetengine':'automatic'),
            'guidance'=>MAD4B_JetEngine_Guidance_V1::resolve($slug, $field, $local),
            'layout'=>[
                // Native JetEngine owns field geometry; preserve historical
                // local overrides in storage, but never apply them.
                'width'=>self::normalized_width($field['width'] ?? '100%'),
                'native_width'=>self::normalized_width($field['width'] ?? '100%'),
                'field_layout'=>in_array($field['field_layout'] ?? '', ['inline','stacked'], true)
                    ? $field['field_layout'] : 'inline',
                'source'=>'jetengine',
            ]];
        if ($field['type'] === 'repeater') {
            $out['repeater_fields'] = (array)($field['repeater_fields'] ?? []);
        }
        foreach (['min','max','step'] as $k) if (isset($local[$k]) && is_numeric($local[$k])) $out[$k]=(float)$local[$k];
        return $out;
    }
    public static function menu(): void {
        add_submenu_page('mad4b-jetengine', 'Functional Modes', 'Functional Modes', 'manage_options',
            'mad4b-jetengine-field-modes', [__CLASS__,'admin_page']);
    }
    public static function native_editor_select(): void {
        $choices = [['value'=>'','label'=>'Auto (MAD4B registry)']];
        foreach (self::modes() as $mode) $choices[] = ['value'=>$mode['id'],'label'=>$mode['label']];
        echo '<cx-vui-select label="MAD4B Functional UI Mode" description="UI only; does not change stored values or original JetEngine field type." :wrapper-css="[\'equalwidth\']" size="fullwidth" :options-list="' .
            esc_attr(wp_json_encode($choices)) .
            '" :value="field.mad4b_ui_mode || \'\'" @input="setFieldProp( \'mad4b_ui_mode\', $event )"></cx-vui-select>';
    }
    public static function admin_page(): void {
        if (!current_user_can('manage_options')) return;
        $pages = self::pages();
        $slug = sanitize_key((string)($_GET['target'] ?? 'etg-dynamic-pricing-controls'));
        if (!isset($pages[$slug])) $slug = array_key_first($pages) ?? '';
        if (isset($_POST['mad4b_field_modes_save']) && ($_SERVER['REQUEST_METHOD']??'')==='POST') {
            check_admin_referer('mad4b_field_modes_save_' . $slug);
            $settings = self::stored();
            $submitted = isset($_POST['mode']) && is_array($_POST['mode']) ? wp_unslash($_POST['mode']) : [];
            if (count($submitted) <= 500 && isset($pages[$slug])) {
                foreach ($pages[$slug]['fields'] as $name => $field) {
                    $choice = isset($submitted[$name]) && is_string($submitted[$name])
                        ? sanitize_key($submitted[$name]) : '';
                    $local = $settings['pages'][$slug][$name] ?? [];
                    $local = is_array($local) ? $local : [];
                    if ($choice !== '' && self::compatible($choice, $field['type'])) {
                        $local['mode'] = $choice;
                    } else {
                        unset($local['mode']);
                    }
                    // Native field width and field layout are never written by
                    // the MAD4B functional presentation layer. Do not delete
                    // existing historical layout_width metadata on save.
                    foreach (['override', 'context'] as $kind) {
                        $postKey = 'guidance_' . $kind;
                        if (!isset($_POST[$postKey]) || !is_array($_POST[$postKey])) continue;
                        $value = $_POST[$postKey][$name] ?? '';
                        if (!is_string($value)) continue;
                        $value = MAD4B_JetEngine_Guidance_V1::normalize(wp_unslash($value));
                        if ($value !== '') $local[$postKey] = $value;
                        else unset($local[$postKey]);
                    }
                    if ($local) {
                        $settings['pages'][$slug][$name] = $local;
                        MAD4B_JetEngine_Guidance_V1::register_configured_strings($slug, $name, $local);
                    } else {
                        unset($settings['pages'][$slug][$name]);
                    }
                }
                $settings['version'] = self::VERSION;
                $settings['updated_at'] = gmdate('c');
                update_option(self::OPTION, $settings, false);
                echo '<div class="notice notice-success"><p>Functional UI Mode configuration saved.</p></div>';
            }
        }
        echo '<div class="wrap"><h1>JetEngine · Functional Modes</h1>';
        echo '<p>Each mode changes the editing interface, not the original field name, type, storage or commerce logic.</p>';
        echo '<p><label>Options Page <select onchange="location.href=this.value">';
        foreach ($pages as $id => $page) {
            $href = admin_url('admin.php?page=mad4b-jetengine-field-modes&target='.rawurlencode($id));
            echo '<option value="' . esc_url($href) . '" ' . selected($id,$slug,false) . '>' . esc_html($page['label']) . '</option>';
        }
        echo '</select></label></p>';
        if (!isset($pages[$slug])) { echo '<p>No JetEngine Options Pages detected.</p></div>'; return; }
        $native = admin_url('admin.php?page='.($slug==='etg-dynamic-pricing-controls'?'dynamic-pricing-controls':$slug));
        echo '<p><a class="button" href="' . esc_url($native) . '">Open native Options Page</a></p>';
        echo '<p><strong>Field width and inline/stacked layout follow JetEngine only.</strong> Historical MAD4B layout overrides are preserved for audit but no longer affect rendering. Set these properties in JetEngine.</p>';
        echo '<form method="post">';
        wp_nonce_field('mad4b_field_modes_save_' . $slug);
        echo '<table class="widefat striped"><thead><tr><th>JetEngine field</th><th>Native type</th><th>Mode override</th><th>Native layout (read-only)</th><th>Resolved mode</th></tr></thead><tbody>';
        $stored = self::stored();
        foreach ($pages[$slug]['fields'] as $field) {
            $name = $field['name']; $type = $field['type'];
            $override = (string)($stored['pages'][$slug][$name]['mode'] ?? '');
            $resolved = self::resolved($slug,$field);
            echo '<tr><td><strong>'.esc_html($field['label']).'</strong><br><code>'.esc_html($name).'</code></td><td>'.esc_html($type).'</td><td>';
            echo '<select name="mode['.esc_attr($name).']"><option value="">Auto / Inherit</option>';
            foreach (self::modes() as $mode) {
                if (!self::compatible($mode['id'],$type)) continue;
                echo '<option value="'.esc_attr($mode['id']).'" '.selected($override,$mode['id'],false).'>'.esc_html($mode['label']).'</option>';
            }
            echo '</select></td><td>';
            echo '<strong>JetEngine</strong><br><small>Width: '.esc_html($field['width']).' · '.esc_html($field['field_layout']).'</small>';
            if(!empty($stored['pages'][$slug][$name]['layout_width']))
                echo '<br><small>Previous MAD4B width ignored: '.esc_html(
                    $stored['pages'][$slug][$name]['layout_width']).'</small>';
            echo '</td><td>'.esc_html($resolved['mode']).' <small>('.esc_html($resolved['source']).')</small>';
            $local = $stored['pages'][$slug][$name] ?? [];
            $local = is_array($local) ? $local : [];
            echo '<details style="margin-top:8px"><summary>'.esc_html__('Help text (optional)', 'mad4b-jetengine').'</summary>';
            if (!empty($field['description'])) echo '<p><small><strong>JetEngine Description:</strong> '.esc_html($field['description']).'</small></p>';
            else echo '<p><small>JetEngine Description is empty.</small></p>';
            foreach (['override'=>'Company override','context'=>'Extra context'] as $kind=>$label) {
                $key = 'guidance_'.$kind;
                echo '<label style="display:block;margin-bottom:8px"><strong>'.esc_html($label).'</strong><br><textarea rows="2" style="width:100%;max-width:440px" name="'.esc_attr($key.'['.$name.']').'">'.esc_textarea((string)($local[$key]??'')).'</textarea></label>';
            }
            echo '<small>Leave blank to inherit JetEngine Description. WPML translations can be managed through String Translation.</small></details>';
            echo '</td></tr>';
        }
        echo '</tbody></table><p><button class="button button-primary" name="mad4b_field_modes_save" value="1">Save UI Modes</button></p></form></div>';
    }
    public static function enqueue(): void {
        $slug = sanitize_key((string)($_GET['page'] ?? ''));
        if ($slug === '') return;
        if ($slug === 'dynamic-pricing-controls') $slug='etg-dynamic-pricing-controls';
        if ($slug === 'etg-dynamic-pricing-controls') {
            if (!MAD4B_JetEngine_Pricing_Controls_Access_V1::authorized())return;
        } elseif (!current_user_can('manage_options'))return;
        $pages = self::pages();
        if (!isset($pages[$slug])) return;
        $fields = [];
        foreach ($pages[$slug]['fields'] as $f) $fields[] = self::resolved($slug,$f);
        $asset = self::$dir.'/assets/functional-modes.js';
        $url = plugins_url('mad4b-jetengine/modules/functional-modes/assets/');
        wp_enqueue_style('mad4b-jetengine-functional-modes', $url.'functional-modes.css',[],(string)filemtime(self::$dir.'/assets/functional-modes.css'));
        do_action('mad4b/jetengine/field-mode-assets', $slug, $fields);
        // Optional enhancers are not registered on every JetEngine Options
        // Page. WordPress silently drops a script when any dependency is
        // missing: Provider Price Sources only exists on Pricing pages.
        // Gate dependencies by their actual registration instead of making
        // Service Activation (and all other Options Pages) depend on a
        // Pricing-only module.
        $optionalDeps = [];
        foreach (['mad4b-pricing-context-help', 'mad4b-provider-price-sources'] as $handle) {
            if (wp_script_is($handle, 'registered')) $optionalDeps[] = $handle;
        }
        wp_enqueue_script('mad4b-jetengine-functional-modes', $url.'functional-modes.js',
            $optionalDeps, (string)filemtime($asset),true);
        if (class_exists('MAD4B_JetEngine_Page_Recipes_V1') && $slug === MAD4B_JetEngine_Page_Recipes_V1::CANARY) {
            wp_enqueue_style('mad4b-jetengine-page-recipes', $url.'page-recipes.css',
                ['mad4b-jetengine-functional-modes'],
                (string)filemtime(self::$dir.'/assets/page-recipes.css'));
            wp_enqueue_script('mad4b-jetengine-page-recipes', $url.'page-recipes.js',
                ['mad4b-jetengine-functional-modes'],
                (string)filemtime(self::$dir.'/assets/page-recipes.js'), true);
        }
        // Shared modern JetEngine field cards, types and native Save: loaded
        // for all supported Options Pages, not solely the Pricing Recipe.
        wp_enqueue_style('mad4b-jetengine-field-recipe',
            $url.'jetengine-field-recipe.css',
            ['mad4b-jetengine-functional-modes'],
            (string)filemtime(self::$dir.'/assets/jetengine-field-recipe.css'));

        MAD4B_JetEngine_Recipe_Registry_V1::enqueue('options', $slug);

        wp_enqueue_script('mad4b-jetengine-repeater-overview', $url.'repeater-overview.js',
            ['mad4b-jetengine-functional-modes'], (string)filemtime(self::$dir.'/assets/repeater-overview.js'), true);
        wp_enqueue_script('mad4b-jetengine-published-tours', $url.'published-tours.js',
            ['mad4b-jetengine-functional-modes'], (string)filemtime(self::$dir.'/assets/published-tours.js'), true);
        wp_enqueue_script('mad4b-jetengine-price-groups', $url.'price-group-controller.js',
            ['mad4b-jetengine-published-tours'], (string)filemtime(self::$dir.'/assets/price-group-controller.js'), true);
        wp_add_inline_script('mad4b-jetengine-functional-modes','window.MAD4BJetModePage='.wp_json_encode([
            'slug'=>$slug,'pageLabel'=>$pages[$slug]['label'],'fields'=>$fields,
            'pageRecipe'=>class_exists('MAD4B_JetEngine_Page_Recipes_V1')
                ?MAD4B_JetEngine_Page_Recipes_V1::config($slug):null,
            'dimensions'=>$slug==='etg-dynamic-pricing-controls'
                ?[
                    'ajax_url'=>admin_url('admin-ajax.php'),
                    'action'=>MAD4B_JetEngine_Pricing_Dimensions_V1::ACTION,
                    'nonce'=>wp_create_nonce(MAD4B_JetEngine_Pricing_Dimensions_V1::ACTION),
                    'save_nonce'=>wp_create_nonce(MAD4B_JetEngine_Pricing_Dimensions_V1::SAVE_ACTION),
                    'pricing_lab'=>admin_url('admin.php?page=mad4b-pricing-lab'),
                    'saved_engine_on'=>in_array(
                        strtolower((string)get_option(
                            'etg-dynamic-pricing-controls_pricing_engine_enabled','false')),
                        ['true','yes','1','on'],true),
                ]:null,
            'editor'=>current_user_can('manage_options')
                ?admin_url('admin.php?page=mad4b-jetengine-field-modes&target='.rawurlencode($slug)):null,
            'publishedTours'=>[
                'ajax_url'=>admin_url('admin-ajax.php'),
                'action'=>MAD4B_JetEngine_Published_Tours_Preview_V1::AJAX_ACTION,
                'nonce'=>wp_create_nonce(MAD4B_JetEngine_Published_Tours_Preview_V1::AJAX_ACTION),
                'market_setup_url'=>admin_url('admin.php?page=mad4b-market-profiles'),
            ],
            'priceGroup'=>[
                'ajax_url'=>admin_url('admin-ajax.php'),
                'action'=>MAD4B_JetEngine_Price_Group_Controller_V1::ACTION,
                'nonce'=>wp_create_nonce(MAD4B_JetEngine_Price_Group_Controller_V1::ACTION),
            ],
            'affectedStrategy'=>[
                'ajax_url'=>admin_url('admin-ajax.php'),
                'action'=>MAD4B_JetEngine_Affected_Tour_Strategy_V1::ACTION,
                'nonce'=>wp_create_nonce(MAD4B_JetEngine_Affected_Tour_Strategy_V1::ACTION),
            ],
            'overview'=>[
                'ajax_url'=>admin_url('admin-ajax.php'),
                'action'=>MAD4B_JetEngine_Repeater_Overview_V1::AJAX_ACTION,
                'nonce'=>wp_create_nonce(MAD4B_JetEngine_Repeater_Overview_V1::AJAX_ACTION),
            ],
            'i18n'=>[
                'help'=>__('Field description', 'mad4b-jetengine'),
                'helpMode'=>__('Help mode', 'mad4b-jetengine'),
                'jetengine'=>__('JetEngine Description', 'mad4b-jetengine'),
                'company'=>__('Company guidance', 'mad4b-jetengine'),
                'context'=>__('Additional context', 'mad4b-jetengine'),
            ],
        ]).';','before');
    }
}
