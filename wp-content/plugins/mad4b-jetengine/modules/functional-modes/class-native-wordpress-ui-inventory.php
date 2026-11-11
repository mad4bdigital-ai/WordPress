<?php
defined('ABSPATH') || exit;

/**
 * WordPress core UI inventory / read-only admin catalogue.
 * Not a skin engine; no global CSS, no replacement vendor controls.
 */
final class MAD4B_Native_WP_UI_Inventory_V1 {
    private const GROUPS = [
        'foundations'=>['WordPress Foundation','common','Layout, notice, tabs and settings shells','Scoped admin panels'],
        'native_forms'=>['Forms & Controls','forms','Inputs, selects, checkboxes and descriptions','Scoped forms only'],
        'tables'=>['List Tables','list-tables','Native tabular data, pagination and row actions','Separate table adapter'],
        'admin_navigation'=>['Admin Navigation','admin-menu','WordPress sidebar and submenu','Protect core navigation'],
        'media_modal'=>['Media Library','media-views','Library modal, uploads and attachment states','Provider-owned, opt-in adapter'],
        'color_picker'=>['Color Picker','wp-color-picker','Native picker trigger, swatches and popup','Provider-owned, guarded adapter'],
        'post_editor_legacy'=>['Legacy Editor','edit','Postboxes, panels and classic editor','Legacy postbox adapter'],
        'navigation_menus'=>['Menus','nav-menus','Menu items and drag-and-drop editing','Provider-owned, guarded adapter'],
        'widgets_legacy'=>['Legacy Widgets','widgets','Sidebar widget editor and sorting','Provider-owned, guarded adapter'],
        'gutenberg_components'=>['Gutenberg Components','wp-components','Buttons, dialogs, panels and notices','Components tokens, no blanket selectors'],
        'gutenberg_block_editor'=>['Block Editor','wp-block-editor','Block lists and inspector interactions','Dedicated editor adapter'],
        'gutenberg_editor'=>['Gutenberg Editor','wp-editor','Editor shells, focus and canvas boundaries','Dedicated editor adapter'],
    ];
    public static function boot():void {
        if (!is_admin()) return;
        add_action('admin_menu', [__CLASS__,'menu'], 32);
        add_action('admin_enqueue_scripts', [__CLASS__,'assets']);
    }
    public static function assets():void {
        if(($_GET['page']??'')!=='mad4b-wp-native-ui-inventory')return;
        $dir=__DIR__.'/assets/';
        wp_enqueue_style('mad4b-wp-native-ui-inventory',
            plugins_url('mad4b-jetengine/modules/functional-modes/assets/native-wp-ui-inventory.css'),
            [],(string)filemtime($dir.'native-wp-ui-inventory.css'));
        wp_enqueue_script('mad4b-wp-native-ui-inventory',
            plugins_url('mad4b-jetengine/modules/functional-modes/assets/native-wp-ui-inventory.js'),
            [],(string)filemtime($dir.'native-wp-ui-inventory.js'),true);
    }
    public static function menu():void {
        add_submenu_page('mad4b-jetengine','WordPress Native UI Inventory',
            'Native WP UI Inventory','manage_options',
            'mad4b-wp-native-ui-inventory',[__CLASS__,'page']);
    }
    public static function page():void {
        if(!current_user_can('manage_options'))wp_die('Not authorized');
        $styles=wp_styles();
        echo '<div class="wrap" id="mad4b-wp-native-inventory"><h1>WordPress Native UI Inventory</h1>';
        echo '<p>Read-only catalogue from installed WordPress core. This is discovery, <strong>not a global admin skin</strong>. ';
        echo 'All original WordPress controls, save handlers, editor interactions and accessibility remain vendor-owned.</p>';
        echo '<p><strong>Core:</strong> '.esc_html(get_bloginfo('version')).
            ' · <strong>Families:</strong> '.esc_html((string)count(self::GROUPS)).
            ' · <strong>Mode:</strong> Read-only</p>';
        echo '<div class="mad4b-native-scroll" role="region" tabindex="0" aria-label="WordPress native component style inventory"><table class="widefat striped"><thead><tr><th>Native family</th><th>Style handle</th><th>Core surface</th><th>Recommended treatment</th><th>Registered</th></tr></thead><tbody>';
        foreach(self::GROUPS as $key=>$parts){
            [$name,$handle,$details,$treatment]=$parts;
            $registered=isset($styles->registered[$handle]);
            echo '<tr><td><strong>'.esc_html($name).'</strong><br><code>'.
               esc_html($key).'</code></td><td><code>'.esc_html($handle).
               '</code></td><td>'.esc_html($details).
               '</td><td>'.esc_html($treatment).
               '</td><td>'.esc_html($registered?'Registered':'Not in this context').
               '</td></tr>';
        }
        echo '</tbody></table></div>';
        echo '<section class="mad4b-native-pilot" aria-labelledby="mad4b-native-pilot-title">';
        echo '<div class="mad4b-native-pilot-heading"><h2 id="mad4b-native-pilot-title">Native components · scoped Modern Adapter</h2>';
        echo '<p>A safe, interactive visual pilot using original WordPress input, select, button and notice elements. No data is saved or submitted.</p></div>';
        echo '<div class="mad4b-native-pilot-grid">';
        echo '<div class="mad4b-native-pilot-field"><label for="mad4b-native-pilot-text">Example setting</label>';
        echo '<input type="text" class="regular-text" id="mad4b-native-pilot-text" value="Preview only" autocomplete="off">';
        echo '<p class="description">Native text input · local preview state only</p></div>';
        echo '<div class="mad4b-native-pilot-field"><label for="mad4b-native-pilot-select">Appearance preference</label>';
        echo '<select id="mad4b-native-pilot-select"><option value="cards">Cards</option><option value="compact">Compact</option></select>';
        echo '<p class="description">Original WordPress select · no settings changed</p></div>';
        echo '<label class="mad4b-native-pilot-choice"><input type="checkbox" id="mad4b-native-pilot-check">';
        echo '<span>Show preview confirmation</span></label>';
        echo '<div class="mad4b-native-pilot-actions"><button type="button" class="button button-primary" id="mad4b-native-pilot-action">Preview interaction</button>';
        echo '<button type="button" class="button" id="mad4b-native-pilot-reset">Reset</button></div>';
        echo '</div><div class="notice notice-success inline mad4b-native-pilot-feedback" id="mad4b-native-pilot-feedback" role="status" aria-live="polite" hidden><p>Preview interaction was activated. Nothing was saved.</p></div></section>';
        echo '<p>Registration here is a core handle check, not proof that a stylesheet is enqueued on every WordPress screen. ';
        echo 'For precise CSS selector coverage, run the project-local read-only QA command and inspect its JSON report.</p>';
        echo '<p><code>wp eval-file wp-content/plugins/mad4b-jetengine/modules/functional-modes/qa/wp-native-ui-inventory.php --skip-themes --user=1</code></p>';
        MAD4B_Native_WP_Screen_Coverage_V1::render();
        echo '<p><a href="'.esc_url(admin_url('admin.php?page=mad4b-jetengine-recipe-registry')).'">Recipe Workspace</a></p></div>';
    }
}
MAD4B_Native_WP_UI_Inventory_V1::boot();
