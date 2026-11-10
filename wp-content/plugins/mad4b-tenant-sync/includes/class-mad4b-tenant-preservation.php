<?php
/** Field-level, tenant-scoped preservation selection for legacy companies. */
defined('ABSPATH') || exit;
final class MAD4B_Tenant_Preservation {
    const CONTRACT='mad4b.tenant-selective-preservation.v1';
    public static function preview($post_id){
        $ctx=MAD4B_Tenant_Override_Policy::context($post_id);
        if(!$ctx)return new WP_Error('mad4b_preserve_invalid_content');
        $thumb=(int)get_post_thumbnail_id($post_id);
        $validThumb=$thumb>0&&get_post_type($thumb)==='attachment'
            &&str_starts_with((string)get_post_mime_type($thumb),'image/');
        $layout=get_post_meta($post_id,'_mad4b_presentation_variant',true);
        if(!in_array($layout,['card','accordion'],true))$layout='';
        $mapId='local-'.substr(hash('sha256',$ctx['site_uuid'].'|'.$ctx['post']->post_type.'|'.$post_id),0,32);
        $map=MAD4B_Tenant_Sparse_Store::mapping($mapId,$ctx['language']);
        if(is_wp_error($map))return $map;
        $existing=$map?MAD4B_Tenant_Sparse_Store::override_rows($map):[];
        if(is_wp_error($existing))return $existing;
        return [
            'contract'=>self::CONTRACT,'post_id'=>(int)$post_id,
            'site_uuid'=>$ctx['site_uuid'],'language'=>$ctx['language'],
            'current_featured_id'=>$validThumb?$thumb:0,
            'current_layout'=>$layout,
            'available_layouts'=>['card','accordion'],
            'selected_overrides'=>array_intersect_key($existing,
                array_flip(['featured_media_global_id','presentation_variant'])),
            'will_copy_image_files'=>false,'will_modify_templates'=>false,
            'native_wordpress_post_modified'=>false,
            'requires_independent_review'=>true
        ];
    }
    public static function selected_value($post_id,$selection){
        $p=self::preview($post_id);
        if(is_wp_error($p))return $p;
        if($selection==='featured_media_global_id'){
            if(!$p['current_featured_id'])
                return new WP_Error('mad4b_preserve_missing_featured_image');
            return ['scope'=>'tenant','site_uuid'=>$p['site_uuid'],
                'attachment_id'=>$p['current_featured_id']];
        }
        if($selection==='presentation_variant'){
            $picked=sanitize_key(wp_unslash($_POST['presentation_variant']??''));
            if($picked==='keep_existing')$picked=$p['current_layout'];
            if(!in_array($picked,$p['available_layouts'],true))
                return new WP_Error('mad4b_preserve_layout_choice_not_supported');
            return $picked;
        }
        return new WP_Error('mad4b_preserve_not_allowlisted');
    }
    public static function submit(){
        $post_id=absint($_POST['post_id']??0);
        $field=sanitize_key(wp_unslash($_POST['field']??''));
        if(!in_array($field,['featured_media_global_id','presentation_variant'],true)
            ||!current_user_can('edit_post',$post_id)
            ||!MAD4B_Tenant_Operations_Workspace::content())
            wp_die('Company content access denied',403);
        check_admin_referer('mad4b_preservation_'.$post_id.'_'.$field);
        if(($field==='featured_media_global_id'
                && !isset($_POST['preserve_featured']))
            ||get_current_user_id()<1)
            wp_die('Explicit selection required',400);
        $preview=self::preview($post_id);
        if(is_wp_error($preview))wp_die('Company content cannot be read',409);
        $value=self::selected_value($post_id,$field);
        if(is_wp_error($value))wp_die(esc_html($value->get_error_code()),400);
        $revision=(int)($preview['selected_overrides'][$field]['revision']??0);
        $result=MAD4B_Tenant_Workflow::submit($post_id,$field,$value,$revision);
        if(is_wp_error($result))wp_die(esc_html($result->get_error_code()),409);
        wp_safe_redirect(add_query_arg(['page'=>MAD4B_Tenant_Operations_Workspace::SLUG,
            'post_id'=>$post_id,'submitted'=>(int)$result['request_id']],
            admin_url('admin.php')));
        exit;
    }
    public static function render($post_id){
        $p=self::preview($post_id);
        if(is_wp_error($p))return;
        echo '<h2>Preserve selected existing customizations</h2>';
        echo '<p>Select only the local values you want to keep when a future Hub update is reviewed. '
            .'Selections create separate company approval requests; current WordPress content is not overwritten.</p>';
        echo '<div style="display:flex;gap:16px;flex-wrap:wrap">';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"
             style="border:1px solid #ccd0d4;padding:14px;min-width:250px">';
        wp_nonce_field('mad4b_preservation_'.$post_id.'_featured_media_global_id');
        echo '<input type="hidden" name="action" value="mad4b_preserve_existing">';
        echo '<input type="hidden" name="post_id" value="'.(int)$post_id.'">';
        echo '<input type="hidden" name="field" value="featured_media_global_id">';
        echo '<strong>Existing featured image</strong><p>';
        if($p['current_featured_id']){
            echo wp_get_attachment_image($p['current_featured_id'],'thumbnail');
            echo '<label><input type="checkbox" name="preserve_featured" value="1" required> '
                .'Keep this image for this company</label>';
        } else echo 'No existing featured image. No attachment will be copied.';
        echo '</p><p>'.(isset($p['selected_overrides']['featured_media_global_id'])
             ?'Already preserved · revision '.(int)$p['selected_overrides']['featured_media_global_id']['revision']
             :'Currently inherited from WordPress').'</p>';
        echo '<button type="submit" class="button" '
             .($p['current_featured_id']?'':'disabled').'>Request image preservation</button></form>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"
             style="border:1px solid #ccd0d4;padding:14px;min-width:250px">';
        wp_nonce_field('mad4b_preservation_'.$post_id.'_presentation_variant');
        echo '<input type="hidden" name="action" value="mad4b_preserve_existing">';
        echo '<input type="hidden" name="post_id" value="'.(int)$post_id.'">';
        echo '<input type="hidden" name="field" value="presentation_variant">';
        echo '<strong>Template presentation</strong><p><label>Choose layout <select name="presentation_variant">';
        if($p['current_layout'])
            echo '<option value="keep_existing">Keep current '.esc_html(ucfirst($p['current_layout'])).'</option>';
        echo '<option value="card">Card</option><option value="accordion">Accordion</option>';
        echo '</select></label></p>';
        echo '<p>'.(isset($p['selected_overrides']['presentation_variant'])
             ?'Approved company preference · revision '.(int)$p['selected_overrides']['presentation_variant']['revision']
             :'No approved company layout preference yet').'</p>';
        echo '<button type="submit" class="button">Request layout preservation</button></form>';
        echo '</div><p><small>Templates should read '
            .'<code>[mad4b_tenant_field field="presentation_variant"]</code> '
            .'or the MAD4B Elementor/JetEngine dynamic field adapter. '
            .'No raw Elementor template JSON is copied.</small></p>';
    }
    public static function boot(){
        add_action('admin_post_mad4b_preserve_existing',[__CLASS__,'submit']);
    }
}
MAD4B_Tenant_Preservation::boot();
