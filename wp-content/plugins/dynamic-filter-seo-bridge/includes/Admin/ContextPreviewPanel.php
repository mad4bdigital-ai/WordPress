<?php
namespace DynamicFilterSEOBridge\Admin;

/** Admin console for both Elementor and shortcode value parity. */
final class ContextPreviewPanel {
    public static function render(): void {
        if(!current_user_can('manage_options'))return;
        $source=plugins_url('assets/js/context-admin-preview.js',DFSB_MAIN_FILE);
        wp_enqueue_script('dfsb-context-admin-preview',$source,array(),filemtime(dirname(DFSB_MAIN_FILE).'/assets/js/context-admin-preview.js'),true);
        wp_localize_script('dfsb-context-admin-preview','dfsbContextAdmin',array(
            'base'=>esc_url_raw(rest_url('dfsb-admin/v1/')),
            'nonce'=>wp_create_nonce('wp_rest')));
        echo '<section id="dfsb-context-preview" style="max-width:1120px;margin-top:24px">';
        echo '<h2>Dynamic Tags &amp; Shortcodes — Source Preview</h2>';
        echo '<p>Read-only preview, even during Safe Boot. Each field uses the same resolver as Elementor Dynamic Tags and Shortcodes. No price is calculated, no post is saved, and no notification is dispatched here.</p>';
        echo '<p><label for="dfsb-preview-scope">Context </label>';
        echo '<select id="dfsb-preview-scope"><option value="post">Published Post / CPT</option><option value="taxonomy">Taxonomy Term</option><option value="archive">CPT Archive</option><option value="posts_home">Blog Home</option><option value="shop">Woo Shop</option></select> ';
        echo '<label for="dfsb-preview-id">Object ID </label><input id="dfsb-preview-id" type="number" min="0" step="1" value="60627" style="width:90px"/> ';
        echo '<label for="dfsb-preview-type">CPT / Taxonomy </label><input id="dfsb-preview-type" type="text" placeholder="e.g. destinations" maxlength="64" style="width:200px"/> ';
        echo '<button type="button" class="button" id="dfsb-preview-browse">Find Sources</button> ';
        echo '<button type="button" class="button button-primary" id="dfsb-preview-run">Preview</button> ';
        echo '<button type="button" class="button" id="dfsb-preview-integrations">Integrations</button> ';
        echo '<button type="button" class="button" id="dfsb-preview-issues">Current Alerts</button></p>';
        echo '<p><code>GET /wp-json/dfsb-admin/v1/catalog</code> &nbsp; <code>/preview</code> &nbsp; <code>/integrations</code> &nbsp; <code>/issues</code> &nbsp; <code>/objects</code></p>';
        echo '<p>Elementor tags: <code>Context Source Value</code> and <code>Context Source Image</code> (<code>dfsb-context-value</code>). Shortcode: <code>[dfsb_context_value field="title"]</code>. Image shortcode: <code>[dfsb_context_image]</code>. Existing <code>[dfsb_context_heading]</code> and <code>[dfsb_context_editorial]</code> remain supported.</p>';
        echo '<p id="dfsb-preview-status" role="status" aria-live="polite">Choose a source and run the preview.</p>';
        echo '<div id="dfsb-preview-output" style="max-height:520px;overflow:auto"></div>';
        echo '</section>';
    }
}
