<?php
namespace DynamicFilterSEOBridge\Admin;

use DynamicFilterSEOBridge\Presentation\SharedMediaSources;

/** Tenant-local settings UI. Never changes SEO publication or media files. */
final class MediaSourcesSettingsPage {
    public const SLUG='dfsb-media-sources';
    public function register(): void {
        add_action('admin_menu',array($this,'menu'),12);
        add_action('admin_post_dfsb_save_shared_media_sources',array($this,'save'));
    }
    public function menu(): void {
        if(!current_user_can('manage_options'))return;
        add_submenu_page('dfsb-workflow','Shared & Private Media','Media Sources',
            'manage_options',self::SLUG,array($this,'render'));
    }
    public function save(): void {
        if(!current_user_can('manage_options'))wp_die('Forbidden',403);
        check_admin_referer('dfsb_save_shared_media_sources','dfsb_media_nonce');
        $raw=isset($_POST['dfsb_shared_media_base']) && is_string($_POST['dfsb_shared_media_base'])
            ?trim(wp_unslash($_POST['dfsb_shared_media_base'])):'';
        $base=SharedMediaSources::validateBase($raw);
        $enabled=!empty($_POST['dfsb_shared_media_enabled']);
        $error=($raw!=='' && $base==='') || ($enabled && $base==='');
        if(!$error){
            update_option(SharedMediaSources::OPTION,array('enabled'=>$enabled,'base_url'=>$base),false);
        }
        wp_safe_redirect(add_query_arg('dfsb_media_status',$error?'invalid':'saved',
            admin_url('admin.php?page='.self::SLUG)));
        exit;
    }
    public function render(): void {
        if(!current_user_can('manage_options'))wp_die('Forbidden',403);
        $s=SharedMediaSources::settings();
        echo '<div class="wrap"><h1>Media Sources — Shared and Company Private</h1>';
        echo '<p>Keep private WordPress attachments local to this company, and explicitly reference public images from a central shared library. Both can appear in the same archive gallery.</p>';
        $status=isset($_GET['dfsb_media_status'])?sanitize_key((string)wp_unslash($_GET['dfsb_media_status'])):'';
        if($status==='invalid')echo '<div class="notice notice-error"><p>Enter a valid public HTTPS shared library URL (no credentials, query or localhost). Previous settings were preserved.</p></div>';
        if($status==='saved')echo '<div class="notice notice-success"><p>Media source settings saved for this company only.</p></div>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        echo '<input type="hidden" name="action" value="dfsb_save_shared_media_sources">';
        wp_nonce_field('dfsb_save_shared_media_sources','dfsb_media_nonce');
        echo '<table class="form-table"><tbody><tr><th scope="row">Shared library base URL</th><td><input type="url" class="regular-text" name="dfsb_shared_media_base" value="'.esc_attr($s['base_url']).'" placeholder="https://media.example.org/wp-content/uploads" style="min-width:450px"><p class="description">Public HTTPS image directory. Files keep their original relative paths and extensions. No files are copied. This setting belongs to this WordPress site.</p></td></tr>';
        echo '<tr><th scope="row">Use shared library</th><td><label><input type="checkbox" name="dfsb_shared_media_enabled" value="1" '.checked($s['enabled'],true,false).'> Enable explicitly shared references</label><p class="description">Company-private attachments are always separate. Shared media will never be selected from a private attachment automatically.</p></td></tr></tbody></table>';
        submit_button('Save media sources');echo '</form>';
        echo '<h2>Meta-field examples</h2>';
        echo '<p>Use the existing gallery field (for example <code>dfsb_background_gallery</code>) and select <strong>Mixed: Shared + Company Private</strong> in the Elementor background control.</p>';
        echo '<pre>64528, shared:egypt/cairo/pyramids.webp, 64351</pre>';
        echo '<p>Structured JSON gallery:</p><pre>[{"source":"local","id":64528},{"source":"shared","path":"egypt/cairo/pyramids.webp"}]</pre>';
        echo '<p><strong>Privacy:</strong> a local attachment ID always belongs to this company. Shared paths need an explicit <code>shared:</code> prefix. A filename collision cannot promote a company-private photo to the shared library.</p>';
        echo '<p><strong>Missing shared file:</strong> shared references do not trigger a server-side download or copy. Use a valid central image path; leave at least one company-local image in mixed galleries as a visual fallback.</p>';
        echo '<p><a href="'.esc_url(admin_url('admin.php?page=dfsb-workflow')).'">Back to workflow</a></p></div>';
    }
}
