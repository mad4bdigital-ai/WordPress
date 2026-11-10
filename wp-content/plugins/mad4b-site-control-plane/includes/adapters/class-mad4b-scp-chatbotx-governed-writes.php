<?php
defined('ABSPATH') || exit;
/** Explicit, bounded ChatbotX write targets. Never called without SCP authorization. */
final class MAD4B_SCP_ChatbotX_Governed_Writes {
    public static function guard() {
        if (!class_exists('MAD4B_SCP_Policy') || !MAD4B_SCP_Policy::can_mutate()
            || !current_user_can('manage_options')) {
            return new WP_Error('cmb_mutation_gate_closed', 'Site mutation gate or actor permission is not ready.');
        }
        return true;
    }
    private static function reserve($kind, $input) {
        $guard = self::guard();
        if (is_wp_error($guard)) return $guard;
        $key = (string)($input['idempotency_key'] ?? '');
        if (!preg_match('/^[A-Za-z0-9._:-]{16,100}$/D', $key)) {
            return new WP_Error('cmb_idempotency_required', 'A stable idempotency key is required.');
        }
        $id='cmb_action_' . hash_hmac('sha256',$kind.'|'.get_current_user_id().'|'.$key,wp_salt('auth'));
        if (!add_option($id,array('state'=>'reserved','at'=>time()),'',false)) {
            return new WP_Error('cmb_duplicate_action','The request is already reserved or completed.');
        }
        return $id;
    }
    private static function mark($option,$id,$ability) {
        update_option($option,array('state'=>'completed','object_id'=>(int)$id,'at'=>time()),false);
        if (class_exists('MAD4B_SCP_Audit')) {
            MAD4B_SCP_Audit::record($ability,array('object_id'=>(int)$id,'result'=>'created'),'success');
        }
    }
    public static function support_create_ticket($in) {
        $guard=self::guard();if(is_wp_error($guard))return $guard;
        $customer_id=absint($in['customer_id']??0);$mailbox_id=absint($in['mailbox_id']??0);
        $title=trim((string)($in['title']??''));$content=trim((string)($in['content']??''));
        if (!$customer_id||!$mailbox_id||$title===''||$content===''||strlen($title)>300||strlen($content)>4000) {
            return new WP_Error('cmb_invalid_ticket_request','Existing customer, mailbox, title and bounded content required.');
        }
        $helper='FluentSupport\App\Services\Helper';
        $customer_class='FluentSupport\App\Models\Customer';
        $mail_class='FluentSupport\App\Models\MailBox';
        if(!class_exists($helper)||!$helper::getAgentByUserId()||
           !$customer_class::find($customer_id)||!$mail_class::find($mailbox_id)) {
            return new WP_Error('cmb_support_not_ready','Requires a native support agent, existing customer and mailbox.');
        }
        $token=self::reserve('support_create_ticket',$in);
        if(is_wp_error($token))return $token;
        // No provider email is sent during an automated internal handoff.
        $quiet=static function($send,$channel){return $channel==='email'?false:$send;};
        add_filter('fluent_support/should_send_notification',$quiet,100,5);
        try {
            $customer=$customer_class::find($customer_id);
            $service='FluentSupport\App\Services\Tickets\TicketService';
            $ticket=(new $service())->storeTicket(array(
                'title'=>sanitize_text_field($title),'content'=>wp_kses_post($content),
                'customer_id'=>$customer_id,'mailbox_id'=>$mailbox_id,
                'source'=>'mcp','priority'=>'normal','status'=>'new'
            ),$customer);
            if(!$ticket||!$ticket->id)throw new RuntimeException('Ticket not saved');
            self::mark($token,$ticket->id,'mad4b/chatbotx-support-create-ticket');
            return array('created'=>true,'ticket_id'=>(int)$ticket->id,'email_notification_requested'=>false);
        } catch(Throwable $e) {
            // Keep reservation fail-closed if the native create partially committed.
            return new WP_Error('cmb_native_ticket_failed','Fluent Support did not confirm ticket creation.');
        } finally {remove_filter('fluent_support/should_send_notification',$quiet,100);}
    }
    public static function support_add_note($in) {
        $guard=self::guard();if(is_wp_error($guard))return $guard;
        $id=absint($in['ticket_id']??0);$content=trim((string)($in['content']??''));
        if(!$id||$content===''||strlen($content)>4000)
            return new WP_Error('cmb_invalid_note','An existing ticket and bounded note are required.');
        $helper='FluentSupport\App\Services\Helper';
        $ticket_class='FluentSupport\App\Models\Ticket';
        $agent=$helper::getAgentByUserId();$ticket=$ticket_class::find($id);
        $access='FluentSupport\App\Services\Tickets\AgentTicketAccess';
        if(!$agent||!$ticket||!(new $access())->canAccess($agent,$ticket))
            return new WP_Error('cmb_note_forbidden','Native ticket access denied.');
        $token=self::reserve('support_add_note',$in);
        if(is_wp_error($token))return $token;
        try {
            $service='FluentSupport\App\Services\Tickets\ResponseService';
            $result=(new $service())->createResponse(array(
                'content'=>$content,'conversation_type'=>'note','source'=>'mcp'
            ),$agent,$ticket,true);
            $note=is_array($result)?($result['response']??null):null;
            if(!$note||!$note->id)throw new RuntimeException('Note not saved');
            self::mark($token,$note->id,'mad4b/chatbotx-support-add-note');
            return array('created'=>true,'ticket_id'=>$id,'note_id'=>(int)$note->id,'customer_notified'=>false);
        }catch(Throwable $e){
            return new WP_Error('cmb_note_failed','The native support note failed.');
        }
    }
    public static function cpt_create_draft($in) {
        $guard=self::guard();if(is_wp_error($guard))return $guard;
        $type=sanitize_key((string)($in['post_type']??''));
        $title=trim((string)($in['title']??''));
        if(!in_array($type,array('tours-and-activities','properties','transportations'),true)
            ||$title===''||strlen($title)>240)
            return new WP_Error('cmb_invalid_draft','Supported CPT and bounded title required.');
        $obj=get_post_type_object($type);
        if(!$obj||!current_user_can($obj->cap->create_posts))
            return new WP_Error('cmb_cpt_forbidden','Cannot create this content type.');
        $token=self::reserve('cpt_create_draft',$in);
        if(is_wp_error($token))return $token;
        $id=wp_insert_post(array('post_type'=>$type,'post_status'=>'draft',
            'post_title'=>sanitize_text_field($title),'post_author'=>get_current_user_id(),
            'post_content'=>'','comment_status'=>'closed'),true);
        if(is_wp_error($id)||!$id)return new WP_Error('cmb_draft_failed','WordPress could not create the draft.');
        self::mark($token,$id,'mad4b/chatbotx-cpt-create-draft');
        return array('post_id'=>(int)$id,'status'=>'draft','published'=>false,
            'price_set'=>false,'booking_created'=>false);
    }
}
