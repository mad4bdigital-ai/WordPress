<?php
declare(strict_types=1);
namespace Commerce\Composition;

final class RequestMailReplyAdmin
{
    public static function bootstrap():void {
        add_action('admin_menu',[self::class,'menu']);
        add_action('admin_post_commerce_send_request_reply',[self::class,'send']);
    }
    public static function menu():void {
        add_menu_page('Inquiry Email Replies','Inquiry Replies',
            'mad4b_manage_company_operations','commerce-request-reply',
            [self::class,'page'],'dashicons-email-alt2',57);
    }
    public static function page():void {
        if(!RequestMailReply::authorized())wp_die('Forbidden',403);
        $requestId=absint($_GET['request_id']??0);
        echo '<div class="wrap"><h1>Reply to Customer Inquiry</h1>';
        echo '<p>Transactional response only. Sent via the existing FluentSMTP connection on this WordPress installation. Not a marketing subscription, payment or booking.</p>';
        if(isset($_GET['reply_notice']))
            echo '<div class="notice notice-info"><p>'.
                esc_html(sanitize_text_field(wp_unslash($_GET['reply_notice']))).
                '</p></div>';
        echo '<form method="get" action="'.esc_url(admin_url('admin.php')).'">';
        echo '<input type="hidden" name="page" value="commerce-request-reply">'.
            '<label for="crm-request-id">Request # </label>'.
            '<input type="number" min="1" id="crm-request-id" name="request_id" value="'.
            esc_attr((string)$requestId).'"><button type="submit" class="button">Load</button></form>';
        if($requestId<1){echo '</div>';return;}
        $proof=RequestMailReply::recipient($requestId);
        $smtp=RequestMailReply::transport();
        if(is_wp_error($proof)){
            echo '<p>Request identity unavailable: '.esc_html($proof->get_error_code()).'</p></div>';
            return;
        }
        echo '<p><strong>Verified CRM recipient:</strong> '.esc_html($proof['recipient']).
            ' — Review state: '.esc_html($proof['review_state']).'</p>';
        if(!$smtp['ready']){
            echo '<p><strong>Sending disabled:</strong> '.esc_html((string)$smtp['reason']).
                '. Configure the connection in <a href="'.
                esc_url(admin_url('options-general.php?page=fluent-mail#/connections')).
                '">FluentSMTP</a> on THIS site.</p></div>';
            return;
        }
        echo '<p><strong>Transport:</strong> FluentSMTP ('.esc_html($smtp['provider']).
            '). The sender is the configured FluentSMTP account, not the switched user email.</p>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('commerce_reply_request_'.$requestId);
        echo '<input type="hidden" name="action" value="commerce_send_request_reply">'.
            '<input type="hidden" name="request_id" value="'.esc_attr((string)$requestId).'">'.
            '<input type="hidden" name="intent" value="'.
            esc_attr(wp_generate_uuid4()).'">';
        echo '<p><label for="commerce-reply-subject">Subject</label></p>';
        echo '<input required type="text" id="commerce-reply-subject" name="subject" maxlength="140" class="regular-text" value="'.
            esc_attr('Re: Inquiry #'.$requestId).'">';
        echo '<p><label for="commerce-reply-body">Message</label></p>';
        echo '<textarea required name="message" id="commerce-reply-body" maxlength="4000" rows="9" class="large-text" placeholder="Write a personal response to this inquiry."></textarea>';
        echo '<p><button type="submit" class="button button-primary">Send reply using FluentSMTP</button></p>';
        echo '</form></div>';
    }
    public static function send():void {
        if(!RequestMailReply::authorized())wp_die('Forbidden',403);
        $requestId=absint($_POST['request_id']??0);
        check_admin_referer('commerce_reply_request_'.$requestId);
        $subject=isset($_POST['subject'])&&is_string($_POST['subject'])
            ?wp_unslash($_POST['subject']):'';
        $message=isset($_POST['message'])&&is_string($_POST['message'])
            ?wp_unslash($_POST['message']):'';
        $intent=isset($_POST['intent'])&&is_string($_POST['intent'])
            ?sanitize_text_field(wp_unslash($_POST['intent'])):'';
        $result=RequestMailReply::send($requestId,$subject,$message,$intent);
        $notice=is_wp_error($result)?$result->get_error_code():
            'transport_accepted_not_delivery_confirmed';
        $url=add_query_arg([
            'page'=>'commerce-request-reply','request_id'=>$requestId,
            'reply_notice'=>$notice
        ],admin_url('admin.php'));
        wp_safe_redirect($url);
        exit;
    }
}
