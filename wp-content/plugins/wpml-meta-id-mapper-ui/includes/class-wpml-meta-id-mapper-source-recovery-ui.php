<?php
defined('ABSPATH') || exit;
final class WPML_Meta_ID_Mapper_Source_Recovery_UI {
    public static function boot(): void {
        add_action('admin_menu', [self::class, 'menu'], 40);
        add_action('admin_post_wpml_mapper_recovery', [self::class, 'submit']);
    }
    public static function menu(): void {
        add_submenu_page('wpml-meta-id-mapper', 'Source Recovery',
            'Source Recovery', 'manage_options', 'wpml-mapper-source-recovery', [self::class, 'page']);
    }
    public static function page(): void {
        if (!current_user_can('manage_options')) return;
        $policies = (array)get_option(WPML_Meta_ID_Mapper_Source_Recovery::POLICY_OPTION, []);
        $result = get_transient('wpml_mapper_recovery_result_' . get_current_user_id());
        delete_transient('wpml_mapper_recovery_result_' . get_current_user_id());
        echo '<div class="wrap"><h1>Source Reference Recovery</h1><p>Uses the configured post-ID mapping rule, WordPress source fields and WPML locale. Missing source evidence is rejected.</p>';
        if ($result) echo '<pre>' . esc_html(wp_json_encode($result, JSON_PRETTY_PRINT)) . '</pre>';
        foreach ((array)get_option('wpml_meta_id_mapper_rules',[]) as $rule) {
            if (!is_array($rule) || ($rule['type']??'')!=='post' || empty($rule['object_type'])) continue;
            $type = sanitize_key($rule['post_type']??'');
            $key = sanitize_key($rule['meta_key']??'');
            if (!$type || !$key) continue;
            $policy = (array)($policies[$type][$key]??[]);
            echo '<h2>' . esc_html($type.' / '.$key) . '</h2><form method="post" action="' .
                esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('wpml_mapper_recovery');
            echo '<input type="hidden" name="action" value="wpml_mapper_recovery">';
            echo '<input type="hidden" name="post_type" value="'.esc_attr($type).'">';
            echo '<input type="hidden" name="meta_key" value="'.esc_attr($key).'">';
            foreach (['target_id'=>'Target Post ID','source_id'=>'Source Post ID (if WPML lineage missing)'] as $k=>$label) {
                echo '<p><label>'.esc_html($label).' <input type="number" name="'.esc_attr($k).'" min="0"></label></p>';
            }
            foreach (['match_fields'=>'Evidence meta fields (comma-separated)','mirror_meta_key'=>'Mirror key',
                'expiry_meta_key'=>'End date field','stop_sale_meta_key'=>'Stop-sale key'] as $k=>$label) {
                $val=$policy[$k]??'';
                if (is_array($val))$val=implode(',',$val);
                echo '<p><label>'.esc_html($label).' <input class="regular-text" name="'.esc_attr($k).
                    '" value="'.esc_attr((string)$val).'"></label></p>';
            }
            echo '<button class="button" name="operation" value="policy">Save rule policy</button> ';
            echo '<button class="button" name="operation" value="preview">Preview</button> ';
            echo '<button class="button button-primary" name="operation" value="repair">Repair verified reference</button> ';
            echo '<button class="button" name="operation" value="expiry">Protect expired record</button></form>';
        }
        echo '</div>';
    }
    public static function submit(): void {
        if (!current_user_can('manage_options')) wp_die('Permission denied', 403);
        check_admin_referer('wpml_mapper_recovery');
        $type=sanitize_key((string)($_POST['post_type']??''));
        $key=sanitize_key((string)($_POST['meta_key']??''));
        $allowed=false;
        foreach ((array)get_option('wpml_meta_id_mapper_rules',[]) as $r) {
            if (($r['type']??'')==='post' && ($r['post_type']??'')===$type && ($r['meta_key']??'')===$key) $allowed=true;
        }
        if (!$allowed) wp_die('Mapping rule unavailable', 400);
        $op=sanitize_key((string)($_POST['operation']??'preview'));
        if ($op==='policy') {
            $all=(array)get_option(WPML_Meta_ID_Mapper_Source_Recovery::POLICY_OPTION,[]);
            $fields=array_filter(array_map('sanitize_key',
                explode(',',(string)wp_unslash($_POST['match_fields']??''))));
            $all[$type][$key]=[
                'match_fields'=>array_values(array_slice($fields,0,10)),
                'mirror_meta_key'=>sanitize_key((string)($_POST['mirror_meta_key']??'')),
                'expiry_meta_key'=>sanitize_key((string)($_POST['expiry_meta_key']??'')),
                'stop_sale_meta_key'=>sanitize_key((string)($_POST['stop_sale_meta_key']??''))
            ];
            update_option(WPML_Meta_ID_Mapper_Source_Recovery::POLICY_OPTION,$all,false);
            $result=['policy_saved'=>true,'post_type'=>$type,'meta_key'=>$key];
        } else {
            $id=absint($_POST['target_id']??0);$source=absint($_POST['source_id']??0);
            $result=$op==='repair' ? WPML_Meta_ID_Mapper_Source_Recovery::repair($id,$key,$source)
                : ($op==='expiry' ? WPML_Meta_ID_Mapper_Source_Recovery::protect_expired($id,$key)
                : WPML_Meta_ID_Mapper_Source_Recovery::assess($id,$key,$source));
            if (is_wp_error($result))$result=['error'=>$result->get_error_code()];
        }
        set_transient('wpml_mapper_recovery_result_'.get_current_user_id(),$result,120);
        wp_safe_redirect(admin_url('admin.php?page=wpml-mapper-source-recovery'));exit;
    }
}
