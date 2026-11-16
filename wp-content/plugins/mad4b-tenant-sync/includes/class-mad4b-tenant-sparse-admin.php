<?php
/** Local company override editor. Never writes content or checkout fields. */
defined('ABSPATH') || exit;
final class MAD4B_Tenant_Sparse_Admin {
    const SLUG='mad4b-sparse-overrides';
    public static function boot(){
        add_action('admin_menu',[__CLASS__,'menu']);
        add_action('admin_post_mad4b_sparse_local_map',[__CLASS__,'map']);
        add_action('admin_post_mad4b_sparse_value',[__CLASS__,'save']);
    }
    public static function menu(){
        add_submenu_page('edit.php','Tenant Sparse Overrides','Sparse Overrides',
          'edit_posts',self::SLUG,[__CLASS__,'page']);
    }
    private static function context($post_id){
        $ctx=MAD4B_Tenant_Override_Policy::context($post_id);
        if(!$ctx || !current_user_can('edit_post',$post_id))return null;
        if(!MAD4B_Tenant_Sparse_Store::exists())return null;
        return $ctx;
    }
    private static function global($ctx){
        return 'local-'.substr(hash('sha256',$ctx['site_uuid'].'|'.$ctx['post']->post_type.'|'.$ctx['post']->ID),0,32);
    }
    public static function map(){
        $id=absint($_POST['post_id']??0);
        $ctx=self::context($id);
        if(!$ctx)wp_die('Untrusted site or content',403);
        check_admin_referer('mad4b_sparse_map_'.$id);
        $r=MAD4B_Tenant_Sparse_Store::ensure_local_seed($id);
        if(is_wp_error($r))wp_die(esc_html($r->get_error_code()),409);
        wp_safe_redirect(admin_url('edit.php?page='.self::SLUG.'&post_id='.$id.'&mapped=1'));exit;
    }
    public static function save(){
        wp_die('Direct sparse modifications are disabled. Submit changes through Company Workspace for approval.',403);
        $id=absint($_POST['post_id']??0);
        $ctx=self::context($id);
        if(!$ctx)wp_die('Untrusted site or content',403);
        check_admin_referer('mad4b_sparse_value_'.$id);
        $map=MAD4B_Tenant_Sparse_Store::mapping(self::global($ctx),$ctx['language']);
        if(is_wp_error($map)||!$map||$map['state']!=='local_seed')wp_die('Local content binding missing',409);
        $field=sanitize_key(wp_unslash($_POST['field']??''));
        $defs=$ctx['definitions'];
        if(!isset($defs[$field]))wp_die('Unregistered field',400);
        $rev=filter_var(wp_unslash($_POST['revision']??null),FILTER_VALIDATE_INT);
        if($rev===false || $rev<0)wp_die('Invalid revision',400);
        $action=sanitize_key(wp_unslash($_POST['operation']??''));
        if($action==='clear'){
            $r=MAD4B_Tenant_Sparse_Store::clear_override($map,$field,$rev);
        }elseif($action==='set'){
            $type=$defs[$field]['type'];
            if($type==='text') {
                $value=wp_unslash($_POST['value']??'');
                if(!is_string($value))wp_die('Invalid text',400);
                $value=sanitize_textarea_field($value);
            }elseif(in_array($type,['media_ref','media_collection'],true)) {
                $raw=trim((string)wp_unslash($_POST['value']??''));
                if(!preg_match('/^[0-9, ]{1,600}$/D',$raw))wp_die('Enter WordPress attachment IDs only',400);
                $ids=array_values(array_filter(array_map('absint',explode(',',$raw))));
                if(!$ids||count($ids)>60 || count($ids)!==count(array_unique($ids)))
                    wp_die('Invalid attachment list',400);
                $values=array_map(static fn($aid)=>[
                    'scope'=>'tenant','site_uuid'=>$ctx['site_uuid'],'attachment_id'=>$aid
                ],$ids);
                $value=$type==='media_ref'&&count($values)===1?$values[0]:$values;
                if($type==='media_ref'&&count($ids)!==1)wp_die('Choose one image',400);
            }else wp_die('This field type requires a dedicated provider editor',400);
            $r=MAD4B_Tenant_Sparse_Store::set_override($map,$field,$value,$rev);
        }else wp_die('Unknown action',400);
        if(is_wp_error($r))wp_die(esc_html($r->get_error_code()),409);
        wp_safe_redirect(admin_url('edit.php?page='.self::SLUG.'&post_id='.$id.'&saved=1'));exit;
    }
    public static function page(){
        if(!current_user_can('edit_posts'))wp_die('Access denied',403);
        echo '<div class="wrap"><h1>Company Sparse Overrides</h1>';
        echo '<p>Direct updates have been replaced by company review and approval.</p>';
        echo '<p><a class="button button-primary" href="'.esc_url(admin_url('admin.php?page=mad4b-company-workspace&post_id='.absint($_GET['post_id']??0))).'">Open Company Workspace</a></p></div>';
        return;
        $id=absint($_GET['post_id']??0);
        echo '<div class="wrap"><h1>Tenant Sparse Content Overrides</h1>';
        echo '<p>Store only company-specific fields; inherited Hub values are resolved on demand. No automatic WordPress, SEO, media or checkout mutation.</p>';
        if(!$id){
            echo '<p>Open a tour or property and choose Sparse Overrides from the edit screen.</p></div>';return;
        }
        $ctx=self::context($id);
        if(!$ctx){echo '<p>Site, schema, or content permissions not ready.</p></div>';return;}
        $map=MAD4B_Tenant_Sparse_Store::mapping(self::global($ctx),$ctx['language']);
        if(is_wp_error($map)){echo '<p>Storage unavailable.</p></div>';return;}
        echo '<h2>'.esc_html($ctx['post']->post_title).' ('.esc_html($ctx['language']).')</h2>';
        if(!$map){
            echo '<p>No mapping exists. Create a local reference row without copying this post or changing its content.</p>';
            echo '<form action="'.esc_url(admin_url('admin-post.php')).'" method="post">';
            wp_nonce_field('mad4b_sparse_map_'.$id);
            echo '<input type="hidden" name="action" value="mad4b_sparse_local_map"><input type="hidden" name="post_id" value="'.(int)$id.'">';
            submit_button('Create lightweight local mapping');echo '</form></div>';return;
        }
        $entries=MAD4B_Tenant_Sparse_Store::override_rows($map);
        if(is_wp_error($entries)){echo '<p>Override readback failed.</p></div>';return;}
        $effective=MAD4B_Tenant_Sparse_Store::effective($map['global_content_id'],$map['language']);
        if(isset($_GET['saved']))echo '<div class="notice notice-success"><p>Sparse override change saved and verified.</p></div>';
        echo '<p>Global ID: <code>'.esc_html($map['global_content_id']).'</code> · Mapping: '.esc_html($map['state']).' · Override rows: '.count($entries).'</p>';
        echo '<p>Current read source: '.esc_html(is_wp_error($effective)?$effective->get_error_code():$effective['source']).'</p>';
        echo '<table class="widefat striped" style="max-width:1080px"><thead><tr><th>Field</th><th>Value source</th><th>Current value</th><th>Action</th></tr></thead><tbody>';
        foreach($ctx['definitions'] as $key=>$d){
            $type=$d['type'];
            if(!in_array($type,['text','media_ref','media_collection'],true))continue;
            $entry=$entries[$key]??null;
            $resolved=is_array($effective)?($effective['fields'][$key]??null):null;
            $display=is_scalar($resolved)?(string)$resolved:wp_json_encode($resolved,JSON_UNESCAPED_SLASHES);
            echo '<tr><td>'.esc_html($d['label']).'<br><code>'.esc_html($key).'</code></td>';
            echo '<td>'.($entry?'<strong>Company override</strong>':'Inherited/local WordPress').'</td>';
            echo '<td><code>'.esc_html(mb_substr((string)$display,0,160)).'</code></td><td>';
            echo '<form action="'.esc_url(admin_url('admin-post.php')).'" method="post">';
            wp_nonce_field('mad4b_sparse_value_'.$id);
            echo '<input type="hidden" name="action" value="mad4b_sparse_value"><input type="hidden" name="post_id" value="'.(int)$id.'">';
            echo '<input type="hidden" name="field" value="'.esc_attr($key).'"><input type="hidden" name="revision" value="'.(int)($entry['revision']??0).'">';
            if($type==='text') echo '<textarea name="value" rows="2" cols="35">'.esc_textarea($entry?$entry['value']:'').'</textarea>';
            else {
                $ids=[];
                if($entry){
                    $items=$type==='media_ref'?[$entry['value']]:$entry['value'];
                    foreach((array)$items as $asset)if(is_array($asset))$ids[]=(int)($asset['attachment_id']??0);
                }
                echo '<input type="text" name="value" value="'.esc_attr(implode(',',$ids)).'" placeholder="Local attachment ID'.($type==='media_collection'?'s, comma-separated':'').'" size="28">';
            }
            echo '<button class="button" name="operation" value="set">Save</button> ';
            if($entry)echo '<button class="button" name="operation" value="clear" onclick="return confirm(\'Remove company override and inherit again?\')">Revert</button>';
            echo '</form></td></tr>';
        }
        echo '</tbody></table><p>Media values must reference valid image attachments in this company site. The cache is bounded and does not contain full media files.</p></div>';
    }
}
MAD4B_Tenant_Sparse_Admin::boot();
