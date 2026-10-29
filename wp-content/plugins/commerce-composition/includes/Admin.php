<?php
declare(strict_types=1);
namespace Commerce\Composition;

final class Admin
{
    public static function bootstrap():void
    {
        add_action('admin_menu',[self::class,'menu']);
        add_action('admin_post_commerce_composition_save',[self::class,'handle']);
        add_action('admin_post_commerce_composition_binding_save',[self::class,'handleBinding']);
    }
    public static function menu():void
    {
        add_management_page(__('Composition Policies','woocommerce'),
            __('Composition Policies','woocommerce'),
            'edit_posts','commerce-composition',[self::class,'render']);
    }
    private static function parentAndRate():array
    {
        $parent=isset($_REQUEST['parent_id'])?absint($_REQUEST['parent_id']):0;
        $rate=isset($_REQUEST['rate_id'])?absint($_REQUEST['rate_id']):0;
        return [$parent,$rate?:null];
    }
    public static function render():void
    {
        if(!current_user_can('edit_posts'))wp_die(esc_html__('Insufficient permissions','woocommerce'));
        [$parent,$rate]=self::parentAndRate();
        echo '<div class="wrap"><h1>'.esc_html__('Composition Policies','woocommerce').'</h1>';
        echo '<p>'.esc_html__('Policies determine component roles, never product classification or a certified sale price. Editing requires permission on the actual source posts.','woocommerce').'</p>';
        echo '<form method="get"><input type="hidden" name="page" value="commerce-composition" />';
        echo '<label>Parent post ID <input type="number" min="1" name="parent_id" value="'.esc_attr((string)$parent).'" required></label> ';
        echo '<label>Optional Rate post ID <input type="number" min="0" name="rate_id" value="'.esc_attr((string)($rate??0)).'"></label> ';
        submit_button(__('Load source','woocommerce'),'secondary','',false);
        echo '</form>';
        if($parent<1){echo '</div>';return;}
        if(!current_user_can('edit_post',$parent) ||
            ($rate!==null&&!current_user_can('edit_post',$rate))){
            echo '<div class="notice notice-error"><p>Source edit permission required.</p></div></div>';return;
        }
        $current=get_post_meta($rate??$parent,'_commerce_composition_policy',true);
        if($current!==''&&!is_array($current)){
            echo '<div class="notice notice-error"><p>Stored policy is malformed. No edits allowed.</p></div></div>';return;
        }
        $revision=is_array($current)?(int)($current['revision']??0):0;
        $template=$current?:[
            'schema'=>1,'revision'=>1,
            'parent'=>[
                'service'=>Integration::serviceForPostType(get_post_type($parent))??'',
                'source_type'=>get_post_type($parent),
                'source_id'=>$parent,
            ],
            'required_kind'=>'',
            'groups'=>[]
        ];
        if($rate!==null)$template['parent']['rate_id']=$rate;
        // Immutable audit is visible as read-only history, not editable JSON.
        if(isset($template['audit_trail']))unset($template['audit_trail']);
        $text=wp_json_encode($template,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        if(is_array($current)&&is_array($current['audit_trail']??null)) {
            echo '<details><summary>Revision audit trail</summary><ul>';
            foreach(array_reverse($current['audit_trail']) as $entry) {
                echo '<li>'.esc_html('Revision '.(int)($entry['revision']??0)).
                ' — '.esc_html((string)($entry['timestamp_utc']??'')).
                ' — '.esc_html('Editor ID '.(int)($entry['actor_id']??0)).
                ' — '.esc_html((string)($entry['policy_sha256']??'')).'</li>';
            }
            echo '</ul></details>';
        }
        echo '<p><strong>Stored revision:</strong> '.esc_html((string)$revision).
             ' &nbsp; <strong>Source:</strong> '.esc_html((string)get_post_type($parent)).
             ' &nbsp; <strong>Classification:</strong> ';
        $kinds=SourceClassifier::kindsFor($parent);
        echo esc_html(is_wp_error($kinds)?$kinds->get_error_code():implode(', ',$kinds)).'</p>';
        if(isset($_GET['policy_status'])) {
            echo '<div class="notice notice-info"><p>'.esc_html(sanitize_text_field(wp_unslash($_GET['policy_status']))).'</p></div>';
        }
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('commerce_composition_policy_'.$parent.'_'.$rate);
        echo '<input type="hidden" name="action" value="commerce_composition_save">';
        echo '<input type="hidden" name="parent_id" value="'.esc_attr((string)$parent).'">';
        echo '<input type="hidden" name="rate_id" value="'.esc_attr((string)($rate??0)).'">';
        echo '<input type="hidden" name="expected_revision" value="'.esc_attr((string)$revision).'">';
        echo '<p><label for="composition-policy-json">Versioned policy JSON</label></p>';
        echo '<textarea id="composition-policy-json" name="policy_json" rows="22" cols="100" spellcheck="false" required>'.esc_textarea($text).'</textarea>';
        echo '<p>Use source-owned CPT/Rate links for options; included pricing is never inferred. Set required_kind for any nonempty groups. Increase revision by exactly one.</p>';
        submit_button(__('Validate and Save Policy','woocommerce'));
        echo '</form>';
        $binding=get_post_meta($rate??$parent,'_commerce_form_field_bindings',true);
        if($binding!==''&&!is_array($binding)){
            echo '<p>Stored field mapping is invalid. Administrator review required.</p>';
        }else{
            $bindRevision=is_array($binding)?(int)($binding['revision']??0):0;
            $candidate=$binding?:['schema'=>1,'revision'=>1,
                'form_id'=>0,'groups'=>[]];
            if(isset($candidate['audit_trail']))unset($candidate['audit_trail']);
            $formJson=wp_json_encode($candidate,
                JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
            echo '<hr><h2>Fluent Forms field bindings</h2>';
            echo '<p>Bind existing published select or checkbox field names to the saved component groups. This changes render-time choices only; it does not edit Fluent Forms templates.</p>';
            if(is_array($binding)&&is_array($binding['audit_trail']??null)){
                echo '<details><summary>Binding audit trail</summary><ul>';
                foreach(array_reverse($binding['audit_trail']) as $entry){
                    echo '<li>'.esc_html('Revision '.(int)($entry['revision']??0)).
                    ' — '.esc_html((string)($entry['saved_at_utc']??'')).
                    ' — '.esc_html((string)($entry['sha256']??'')).'</li>';
                }
                echo '</ul></details>';
            }
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
            wp_nonce_field('commerce_composition_binding_'.$parent.'_'.$rate);
            echo '<input type="hidden" name="action" value="commerce_composition_binding_save">';
            echo '<input type="hidden" name="parent_id" value="'.esc_attr((string)$parent).'">';
            echo '<input type="hidden" name="rate_id" value="'.esc_attr((string)($rate??0)).'">';
            echo '<input type="hidden" name="expected_revision" value="'.esc_attr((string)$bindRevision).'">';
            echo '<p><label for="composition-bindings-json">Field binding JSON</label></p>';
            echo '<textarea id="composition-bindings-json" name="bindings_json" rows="12" cols="100" spellcheck="false" required>'.esc_textarea($formJson).'</textarea>';
            submit_button('Validate and Save Fluent Forms Bindings');
            echo '</form>';
        }
        echo '</div>';
    }
    public static function handleBinding():void
    {
        if(!current_user_can('edit_posts'))wp_die('Forbidden',403);
        [$parent,$rate]=self::parentAndRate();
        if($parent<1||!current_user_can('edit_post',$parent)||
           ($rate!==null&&!current_user_can('edit_post',$rate)))
            wp_die('Forbidden',403);
        check_admin_referer('commerce_composition_binding_'.$parent.'_'.$rate);
        $raw=isset($_POST['bindings_json'])&&is_string($_POST['bindings_json'])
            ?wp_unslash($_POST['bindings_json']):'';
        $expected=isset($_POST['expected_revision'])?absint($_POST['expected_revision']):0;
        if(strlen($raw)>65536)wp_die('Bindings JSON too large',400);
        $decoded=json_decode($raw,true,64);
        if(!is_array($decoded)||json_last_error()!==JSON_ERROR_NONE)
            $result=new \WP_Error('binding_json_invalid');
        else $result=BindingStore::save($parent,$rate,$decoded,$expected);
        $status=is_wp_error($result)?$result->get_error_code():
            'bindings_saved_revision_'.$result['revision'];
        wp_safe_redirect(add_query_arg([
            'page'=>'commerce-composition','parent_id'=>$parent,
            'rate_id'=>$rate??0,'policy_status'=>$status,
        ],admin_url('tools.php')));
        exit;
    }
    public static function handle():void
    {
        if(!current_user_can('edit_posts'))wp_die('Forbidden',403);
        [$parent,$rate]=self::parentAndRate();
        if($parent<1||!current_user_can('edit_post',$parent)||
           ($rate!==null&&!current_user_can('edit_post',$rate)))
           wp_die('Forbidden',403);
        check_admin_referer('commerce_composition_policy_'.$parent.'_'.$rate);
        $raw=isset($_POST['policy_json']) && is_string($_POST['policy_json'])
            ? wp_unslash($_POST['policy_json']):'';
        $expected=isset($_POST['expected_revision'])?absint($_POST['expected_revision']):0;
        if(strlen($raw)>65536)wp_die('Policy JSON too large',400);
        $decoded=json_decode($raw,true,64);
        if(!is_array($decoded)||json_last_error()!==JSON_ERROR_NONE)
            $result=new \WP_Error('policy_json_invalid');
        else $result=PolicyStore::save($parent,$rate,$decoded,$expected);
        $status=is_wp_error($result)?$result->get_error_code():'policy_saved_revision_'.$result['revision'];
        wp_safe_redirect(add_query_arg([
            'page'=>'commerce-composition','parent_id'=>$parent,
            'rate_id'=>$rate??0,'policy_status'=>$status,
        ],admin_url('tools.php')));
        exit;
    }
}
