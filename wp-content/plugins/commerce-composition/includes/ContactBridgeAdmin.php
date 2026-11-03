<?php
declare(strict_types=1);
namespace Commerce\Composition;

final class ContactBridgeAdmin
{
    public static function bootstrap():void
    {
        add_action('admin_menu',[self::class,'menu']);
        add_action('admin_post_commerce_contact_link',[self::class,'handle']);
    }
    public static function menu():void
    {
        add_management_page(
            __('Contact record relationships','woocommerce'),
            __('Contact relationships','woocommerce'),
            'manage_options','commerce-contact-relations',[self::class,'render']
        );
    }
    public static function render():void
    {
        if(!current_user_can('manage_options'))wp_die('Forbidden',403);
        $cid=isset($_GET['contact_id'])?absint($_GET['contact_id']):0;
        $sources=ContactBridge::sources();
        echo '<div class="wrap"><h1>'.esc_html__('Contact record relationships','woocommerce').'</h1>';
        echo '<p>'.esc_html__('This links an existing FluentCRM contact to original records. It does not copy source data, create a booking, or certify a sale.','woocommerce').'</p>';
        if(isset($_GET['action_status'])) {
            echo '<p role="status">'.esc_html(sanitize_text_field(wp_unslash(
                (string)$_GET['action_status']))).'</p>';
        }
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('commerce_contact_link_'.$cid);
        echo '<input type="hidden" name="action" value="commerce_contact_link">';
        echo '<p><label>Contact ID <input type="number" min="1" name="contact_id" required value="'.
            esc_attr($cid?(string)$cid:'').'"></label></p>';
        echo '<p><label>Source <select name="source_key" required>';
        foreach($sources as $key=>$spec){
            if(!is_string($key)||!is_array($spec))continue;
            $label=(string)($spec['label']??$key);
            echo '<option value="'.esc_attr($key).'">'.esc_html($label).'</option>';
        }
        echo '</select></label></p>';
        echo '<p><label>Existing source ID <input type="number" min="1" name="source_id" required></label></p>';
        echo '<p><label>Relationship <select name="role">';
        foreach(['requester','traveler','representative','related_contact'] as $role)
            echo '<option value="'.esc_attr($role).'">'.esc_html(ucwords(str_replace('_',' ',$role))).'</option>';
        echo '</select></label></p>';
        submit_button(__('Link existing records','woocommerce'));
        echo '</form>';
        if($cid>0){
            $items=ContactBridge::forContact($cid);
            if(!is_wp_error($items)){
                echo '<h2>'.esc_html__('Related records','woocommerce').'</h2><ul>';
                foreach($items as $item){
                    echo '<li>'.esc_html($item['label'].' #'.$item['source_id'].' - '.$item['status']).'</li>';
                }
                echo '</ul>';
            }
        }
        echo '<h2>'.esc_html__('Available lifecycle actions','woocommerce').'</h2><ul>';
        foreach(WorkflowActions::definitions() as $key=>$definition){
            echo '<li>'.esc_html($key.' — '.$definition['owner'].
                ($definition['available']?' [enabled]':' [approval required]')).'</li>';
        }
        echo '</ul></div>';
    }
    public static function handle():void
    {
        if(!current_user_can('manage_options'))wp_die('Forbidden',403);
        $contactId=isset($_POST['contact_id'])?absint($_POST['contact_id']):0;
        check_admin_referer('commerce_contact_link_'.$contactId);
        $key=isset($_POST['source_key'])&&is_string($_POST['source_key'])
            ?sanitize_key(wp_unslash($_POST['source_key'])):'';
        $role=isset($_POST['role'])&&is_string($_POST['role'])
            ?sanitize_key(wp_unslash($_POST['role'])):'';
        $sourceId=isset($_POST['source_id'])?absint($_POST['source_id']):0;
        $result=WorkflowActions::execute('link_contact_record',[
            'contact_id'=>$contactId,'source_key'=>$key,
            'source_id'=>$sourceId,'role'=>$role
        ]);
        $status=is_wp_error($result)?$result->get_error_code():$result['status'];
        $url=add_query_arg([
            'page'=>'commerce-contact-relations','contact_id'=>$contactId,
            'action_status'=>$status
        ],admin_url('tools.php'));
        wp_safe_redirect($url,303);
        exit;
    }
}
