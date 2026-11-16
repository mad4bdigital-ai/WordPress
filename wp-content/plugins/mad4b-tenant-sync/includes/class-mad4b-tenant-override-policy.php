<?php
/**
 * Per-post/per-language company-owned content field override policy.
 * Stores only policy metadata; never updates the post body/media/SEO or price.
 */
defined('ABSPATH') || exit;
final class MAD4B_Tenant_Override_Policy {
    const CONTRACT='mad4b.tenant-field-overrides.v1';
    const META='_mad4b_tenant_field_modes_v1';
    const SLUG='mad4b-content-overrides';
    public static function boot() {
        add_action('admin_menu',[__CLASS__,'admin_menu']);
        add_action('add_meta_boxes',[__CLASS__,'meta_box'],20,2);
        add_action('admin_post_mad4b_content_override_save',[__CLASS__,'save']);
    }
    public static function is_content_type($type) {
        $supported=['post','page','tours-and-activities','properties','transportations'];
        $extra=apply_filters('mad4b/tenant_sync/override_content_types',[],$type);
        if(is_array($extra))$supported=array_merge($supported,$extra);
        // Reject types that carry commercial/financial or system records.
        if(preg_match('/(?:order|booking|rate|payment|user|customer|fluent|jet-engine|revision)/i',(string)$type))
            return false;
        $obj=get_post_type_object($type);
        return $obj && !empty($obj->public) && !empty($obj->show_ui)
            && in_array($type,$supported,true);
    }
    public static function context($post_id) {
        $post=get_post(absint($post_id));
        if(!$post||!self::is_content_type($post->post_type))return null;
        if(!class_exists('MAD4B_Tenant_Sync'))return null;
        $profile=MAD4B_Tenant_Sync::profile();
        if(empty($profile['ready'])||empty($profile['site_uuid']))return null;
        $lang='';
        if(has_filter('wpml_post_language_details')){
            $v=apply_filters('wpml_post_language_details',null,$post->ID);
            if(is_array($v))$lang=(string)($v['language_code']??'');
        }
        if($lang==='' && defined('ICL_SITEPRESS_VERSION'))return null;
        // Single-language WordPress sites have no WPML language-detail hook.
        // Use the site's declared locale, never an unverified empty language.
        if($lang==='' && !defined('ICL_SITEPRESS_VERSION')){
            $locale=str_replace('_','-',(string)get_locale());
            $primary=strtolower((string)explode('-',$locale)[0]);
            if(preg_match('/^[a-z]{2,3}$/D',$primary))$lang=$primary;
        }
        if($lang==='')return null;
        return ['post'=>$post,'site_uuid'=>$profile['site_uuid'],
            'language'=>$lang,'definitions'=>MAD4B_Tenant_Field_Registry::definitions($post->post_type)];
    }
    public static function state($post_id,$context) {
        $raw=get_post_meta(absint($post_id),self::META,true);
        if(!is_array($raw)||($raw['site_uuid']??'')!==$context['site_uuid']
            ||($raw['language']??'')!==$context['language'])
            return ['revision'=>0,'modes'=>[]];
        return ['revision'=>(int)($raw['revision']??0),
            'modes'=>self::normalize_modes($raw['modes']??[],$context['definitions'])];
    }
    public static function normalize_modes($modes,$definitions) {
        if(!is_array($modes)||!is_array($definitions))return [];
        $out=[];
        foreach($modes as $key=>$mode) {
            if(!isset($definitions[$key])||!is_string($mode)
                ||!in_array($mode,MAD4B_Tenant_Field_Registry::POLICIES,true))continue;
            // Don't store redundant defaults.
            if($mode!==$definitions[$key]['default'])$out[$key]=$mode;
        }
        ksort($out,SORT_STRING);
        return $out;
    }
    public static function effective_modes($post_id) {
        $ctx=self::context($post_id);
        if(!$ctx)return [];
        $saved=self::state($post_id,$ctx);
        $modes=[];
        foreach($ctx['definitions'] as $name=>$definition)
            $modes[$name]=$saved['modes'][$name]??$definition['default'];
        return $modes;
    }
    public static function plan_for_post($source,$mapping,$post_id) {
        $ctx=self::context($post_id);
        if(!$ctx)return ['status'=>'blocked','reason'=>'invalid_tenant_content_identity','mutation_performed'=>false];
        if(!is_array($mapping))$mapping=[];
        $mapping['site_uuid']=$ctx['site_uuid'];
        $mapping['language']=$ctx['language'];
        $mapping['field_modes']=self::effective_modes($post_id);
        return MAD4B_Tenant_Sync_Planner::plan($source,$mapping,$ctx['site_uuid']);
    }
    public static function admin_menu(){
        add_submenu_page('edit.php','Content Overrides','Content Overrides',
            'edit_posts',self::SLUG,[__CLASS__,'page']);
    }
    public static function meta_box($post_type,$post) {
        if(!self::is_content_type($post_type)||!$post||!current_user_can('edit_post',$post->ID))return;
        add_meta_box('mad4b-tenant-field-overrides','MAD4B — Content Sync Overrides',
            [__CLASS__,'meta_box_content'],$post_type,'side','default');
    }
    public static function meta_box_content($post) {
        $url=admin_url('edit.php?page='.self::SLUG.'&post_id='.$post->ID);
        echo '<p>Keep local images, campaign banners and SEO fields while inheriting selected Hub updates.</p>';
        echo '<p><a class="button" href="'.esc_url($url).'">Manage field overrides</a></p>';
        echo '<p><a class="button" href="'.esc_url(admin_url('edit.php?page=mad4b-sparse-overrides&post_id='.$post->ID)).'">Sparse Overrides (values)</a></p>';
        echo '<p><small>Sync application remains disabled in this release.</small></p>';
    }
    public static function page() {
        if(!current_user_can('edit_posts'))wp_die('Access denied');
        $id=absint($_GET['post_id']??0);
        echo '<div class="wrap"><h1>MAD4B — Company Content Overrides</h1>';
        echo '<p>Set local ownership per field for an individual content item and language. No content, images, SEO or selling prices are modified by this form.</p>';
        if(!$id) {
            echo '<p>Open a tour, property or page in the WordPress editor and choose Manage field overrides.</p></div>';return;
        }
        $ctx=self::context($id);
        if(!$ctx||!current_user_can('edit_post',$id)){echo '<p>Not authorized for this content item or site.</p></div>';return;}
        $record=self::state($id,$ctx);
        if(isset($_GET['saved']))echo '<div class="notice notice-success"><p>Local field policy saved and audited.</p></div>';
        echo '<h2>'.esc_html($ctx['post']->post_title).' — '.esc_html($ctx['language']).'</h2>';
        echo '<p>Company Site: <code>'.esc_html($ctx['site_uuid']).'</code>. Current revision: '.(int)$record['revision'].'</p>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('mad4b_content_override_'.$id);
        echo '<input type="hidden" name="action" value="mad4b_content_override_save">';
        echo '<input type="hidden" name="post_id" value="'.(int)$id.'">';
        echo '<input type="hidden" name="expected_revision" value="'.(int)$record['revision'].'">';
        echo '<table class="widefat striped" style="max-width:980px"><thead><tr><th>Field</th><th>Provider</th><th>Default</th><th>Company choice</th></tr></thead><tbody>';
        foreach($ctx['definitions'] as $key=>$def){
            $current=$record['modes'][$key]??$def['default'];
            echo '<tr><td>'.esc_html($def['label']).' <code>'.esc_html($key).'</code></td><td>'.esc_html($def['provider']).'</td><td>'.esc_html($def['default']).'</td><td>';
            echo '<select name="modes['.esc_attr($key).']">';
            foreach(MAD4B_Tenant_Field_Registry::POLICIES as $policy)
                echo '<option value="'.esc_attr($policy).'" '.selected($current,$policy,false).'>'.esc_html($policy).'</option>';
            echo '</select></td></tr>';
        }
        echo '</tbody></table>';
        submit_button('Save local sync preferences');
        echo '</form><p>Reference media must be resolved through Shared Media Catalog before remote sync. Local attachment IDs never travel to the Hub.</p></div>';
    }
    public static function save(){
        $id=absint($_POST['post_id']??0);
        if(!$id||!current_user_can('edit_post',$id))wp_die('Cannot edit this record',403);
        check_admin_referer('mad4b_content_override_'.$id);
        $ctx=self::context($id);
        if(!$ctx)wp_die('Site or translation binding not ready',409);
        $incoming=isset($_POST['modes'])&&is_array($_POST['modes'])
            ?array_map('sanitize_key',wp_unslash($_POST['modes'])):[];
        if(array_diff(array_keys($incoming),array_keys($ctx['definitions']))
            ||count($incoming)!==count($ctx['definitions']))
            wp_die('Unexpected field policy set',400);
        foreach($incoming as $policy)
            if(!in_array($policy,MAD4B_Tenant_Field_Registry::POLICIES,true))
                wp_die('Invalid field override mode',400);
        $previous=self::state($id,$ctx);
        $expected=filter_var($_POST['expected_revision']??null,FILTER_VALIDATE_INT);
        if($expected===false||$expected!==$previous['revision'])wp_die('Stale override preferences',409);
        $modes=self::normalize_modes($incoming,$ctx['definitions']);
        if($modes===$previous['modes']){
            wp_safe_redirect(admin_url('edit.php?page='.self::SLUG.'&post_id='.$id.'&saved=1'));exit;
        }
        if(!class_exists('MAD4B_SCP_Audit')||empty(MAD4B_SCP_Audit::storage_status()['ready']))
            wp_die('Append-only Audit unavailable',503);
        $digest=hash('sha256',wp_json_encode($modes,JSON_UNESCAPED_SLASHES));
        $intent=MAD4B_SCP_Audit::record('mad4b/tenant-field-override-intent',[
            'site_uuid'=>$ctx['site_uuid'],'post_id'=>$id,'language'=>$ctx['language'],
            'expected_revision'=>$expected,'policy_sha256'=>$digest,
            'content_changed'=>false,'remote_sync_requested'=>false
        ],'intent');
        if(is_wp_error($intent))wp_die('Audit intent failed',503);
        $next=['contract'=>self::CONTRACT,'revision'=>$expected+1,
            'site_uuid'=>$ctx['site_uuid'],'language'=>$ctx['language'],
            'modes'=>$modes,'sha256'=>$digest];
        update_post_meta($id,self::META,$next);
        $back=get_post_meta($id,self::META,true);
        if(!is_array($back)||($back['revision']??0)!==$next['revision']
            ||($back['sha256']??'')!==$digest)wp_die('Override policy readback requires reconciliation',503);
        $logged=MAD4B_SCP_Audit::record('mad4b/tenant-field-override-result',[
            'site_uuid'=>$ctx['site_uuid'],'post_id'=>$id,'language'=>$ctx['language'],
            'revision'=>$next['revision'],'policy_sha256'=>$digest,
            'content_changed'=>false,'source_mutation'=>false
        ],'ok');
        if(is_wp_error($logged))wp_die('Saved but audit result requires reconciliation',503);
        wp_safe_redirect(admin_url('edit.php?page='.self::SLUG.'&post_id='.$id.'&saved=1'));exit;
    }
}
MAD4B_Tenant_Override_Policy::boot();
