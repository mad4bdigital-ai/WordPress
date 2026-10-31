<?php
/** Company-scoped communication policy; JetEngine Options is the editor/source. */
defined('ABSPATH') || exit;
final class MAD4B_SCP_Company_Communication {
    const SLUG='company-communication-operations';
    const REST='mad4b/v1/admin/company-communication';
    private static $cached=null;
    /** Reuse the EXISTING governed Brand Context; never invent a second profile. */
    public static function brandIdentity():array {
        if(!class_exists('MAD4B_SCP_Context_Authority') ||
           !class_exists('MAD4B_SCP_Site_Profile'))
            return ['valid'=>false,'reason'=>'brand_context_authority_unavailable',
                    'brand_name'=>'','revision'=>0];
        // The original Authority API performs canonical contract, site UUID,
        // and environment/origin binding checks. Raw get_option() is unsafe.
        $profile=MAD4B_SCP_Context_Authority::profile();
        if(!$profile || empty($profile['brand_name']))
            return ['valid'=>false,'reason'=>'brand_context_not_bound_to_current_site',
                    'brand_name'=>'','revision'=>0];
        return ['valid'=>true,'reason'=>'site_bound_brand_context',
                'brand_name'=>sanitize_text_field((string)$profile['brand_name']),
                'revision'=>absint($profile['revision']??0)];
    }
    public static function config(): array {
        $raw=get_option(self::SLUG,array());
        if(!is_array($raw))$raw=array();
        $keys=array('company_display_name','transactional_from_email','traveler_reply_to',
            'bookings_inbox','sales_inbox','support_inbox','operations_internal_inbox',
            'operations_external_inbox','operations_escalation_email','smtp_connection_label',
            'operations_escalation_note');
        $result=array();
        foreach($keys as$k)$result[$k]=sanitize_text_field((string)($raw[$k]??''));
        foreach(array('outbound_email_policy','woo_routing_policy','fluent_forms_routing_policy',
                       'notify_internal_operations','notify_external_operations','notify_traveler')as$k){
            $result[$k]=($raw[$k]??'disabled')==='enabled'?'enabled':'disabled';
        }
        $result['email_transport']=($raw['email_transport']??'unconfigured')==='fluent_smtp'?'fluent_smtp':'unconfigured';
        // The brand name is inherited from the authority, not independently
        // editable email fields, WooCommerce defaults, or another tenant.
        $brand=self::brandIdentity();
        $result['company_display_name']=$brand['valid']?$brand['brand_name']:'';
        foreach(array('operations_internal_team','operations_external_team')as$k){
            $result[$k]=is_array($raw[$k]??null)?array_values(array_filter($raw[$k],'is_array')):array();
        }
        return $result;
    }
    private static function emails(string $field):array {
        $v=trim((string)(self::config()[$field]??''));
        if($v==='')return array();
        $emails=preg_split('/[,;\s]+/',$v,-1,PREG_SPLIT_NO_EMPTY);
        $validated=array();
        foreach($emails as$e){$email=sanitize_email($e);if(!is_email($email))return array();$validated[]=strtolower($email);}
        return array_values(array_unique($validated));
    }
    public static function problems(): array {
        $c=self::config();$out=array();
        if(!self::brandIdentity()['valid'])$out[]='brand_context_not_bound_to_current_site';
        $trial=class_exists('MAD4B_SCP_Context_Authority')?MAD4B_SCP_Context_Authority::profile():[];
        if(($trial['status']??'')==='test_only')$out[]='brand_context_test_only_no_outbound';
        $from=sanitize_email($c['transactional_from_email']);
        if(!$from||!is_email($from))$out[]='transactional_from_missing_or_invalid';
        foreach(array('traveler_reply_to','bookings_inbox','sales_inbox',
             'operations_internal_inbox','operations_external_inbox','support_inbox')as$key){
            if($c[$key]!==''&&!self::emails($key))$out[]=$key.'_invalid';
        }
        if(!self::emails('bookings_inbox'))$out[]='bookings_inbox_missing';
        if($c['email_transport']!=='fluent_smtp')$out[]='email_transport_not_selected';
        if(!self::smtpInstalled())$out[]='fluent_smtp_not_installed';
        if(!self::smtpConfigured())$out[]='smtp_connection_unverified';
        if(class_exists('MAD4B_SCP_Policy') && !MAD4B_SCP_Policy::can_mutate())$out[]='mad4b_write_policy_closed';
        if(wp_get_environment_type()==='local')$out[]='local_sandbox_outbound_blocked';
        if (class_exists('MAD4B_SCP_Company_Email_Routing',false))
            $out=array_merge($out,MAD4B_SCP_Company_Email_Routing::readiness_issues());
        if($c['outbound_email_policy']!=='enabled')$out[]='outbound_disabled_by_company';
        return array_values(array_unique($out));
    }
    public static function smtpInstalled():bool {
        if(!function_exists('is_plugin_active'))require_once ABSPATH.'wp-admin/includes/plugin.php';
        return is_plugin_active('fluent-smtp/fluent-smtp.php');
    }
    public static function smtpConfigured():bool {
        if(!self::smtpInstalled())return false;
        // Fluent SMTP owns credentials; never read or expose them here.
        // Only an external verified transport certification may mark ready.
        return (bool)apply_filters('mad4b/company_communication/smtp_verified',false);
    }
    public static function canSend():bool {
        $c=self::config();
        return $c['outbound_email_policy']==='enabled' && !self::problems();
    }
    public static function recipients(string $event):array {
        $c=self::config();
        $fields=array(
            'new_request'=>array('bookings_inbox'),
            'booking_confirmed'=>array('bookings_inbox'),
            'sales_quote'=>array('sales_inbox'),
            'traveler_support'=>array('support_inbox'),
            'operations_internal'=>array('operations_internal_inbox'),
            'operations_external'=>array('operations_external_inbox'));
        if(!isset($fields[$event]))return array();
        if($event==='operations_internal' && $c['notify_internal_operations']!=='enabled')return array();
        // External supplier notification must be scoped to an independently
        // approved ORDER/SERVICE assignment, not a broadcast to the address book.
        if($event==='operations_external')return array();
        $out=array();foreach($fields[$event] as$key)$out=array_merge($out,self::emails($key));
        if(class_exists('MAD4B_SCP_Company_Communication_Team') && $c['notify_internal_operations']==='enabled'){
            if($event==='operations_internal'){
                $out=array_merge($out,MAD4B_SCP_Company_Communication_Team::teamEmails('internal'));
            }elseif($event==='new_request' || $event==='booking_confirmed'){
                $out=array_merge($out,MAD4B_SCP_Company_Communication_Team::teamEmails('internal','booking'));
            }elseif($event==='sales_quote'){
                $out=array_merge($out,MAD4B_SCP_Company_Communication_Team::teamEmails('internal','sales'));
            }
        }
        return array_values(array_unique($out));
    }
    public static function woocommerceName($value){
        $c=self::config();return self::canSend()&&$c['woo_routing_policy']==='enabled'&&$c['company_display_name']!==''?$c['company_display_name']:$value;
    }
    public static function woocommerceFrom($value){
        $c=self::config();return self::canSend()&&$c['woo_routing_policy']==='enabled'?$c['transactional_from_email']:$value;
    }
    public static function woocommerceNewOrder($recipient){
        $c=self::config();if(!self::canSend()||$c['woo_routing_policy']!=='enabled')return $recipient;
        $r=self::recipients('new_request');return $r?implode(', ',$r):$recipient;
    }
    public static function formRecipient($address,$notification,$submittedData,$form){
        $c=self::config();
        if((int)($form->id??0)!==6 || !self::canSend() ||$c['fluent_forms_routing_policy']!=='enabled')return $address;
        $to=$notification['sendTo']??array();
        // Never redirect form-field traveler mail to internal recipients.
        if(!is_array($to)||($to['type']??'')!=='email')return $address;
        $r=self::recipients('new_request');return $r?implode(', ',$r):$address;
    }
    public static function validateSave($new,$old){
        if(!is_array($new))return $old;
        // Never persist transport secrets in the public-facing JetEngine field store.
        foreach(array_keys($new)as$key){
            if(preg_match('/password|secret|private_key|api[_-]?key|smtp[_-]?token|access_token/i',(string)$key)){
                return $old;
            }
        }
        foreach(array('transactional_from_email','traveler_reply_to','bookings_inbox',
           'sales_inbox','support_inbox','operations_internal_inbox',
           'operations_external_inbox','operations_escalation_email')as$key){
            if(empty($new[$key]))continue;
            $values=preg_split('/[,;\s]+/',(string)$new[$key],-1,PREG_SPLIT_NO_EMPTY);
            if(!$values)return $old;
            foreach($values as$email)if(!is_email($email))return $old;
        }
        if (array_key_exists('sender_mode_default',$new)
            && !in_array($new['sender_mode_default'],['company','department','employee'],true))
            return $old;
        if (isset($new['sender_event_modes'])) {
            if (!is_array($new['sender_event_modes']) || count($new['sender_event_modes'])>20)
                return $old;
            foreach ($new['sender_event_modes'] as $event=>$mode) {
                if (!in_array((string)$event,MAD4B_SCP_Company_Sender_Policy::EVENTS,true)
                    || !in_array($mode,MAD4B_SCP_Company_Sender_Policy::MODES,true))return $old;
            }
        }
        foreach(array('operations_internal_team','operations_external_team')as$team){
            if(!isset($new[$team]))continue;
            if(!is_array($new[$team])||count($new[$team])>60)return $old;
            foreach($new[$team]as$member){
                if(!is_array($member))return $old;
                if(!empty($member['email'])&&!is_email($member['email']))return $old;
                if(!empty($member['wp_user_id']) &&
                    (!ctype_digit((string)$member['wp_user_id'])||!get_user_by('id',(int)$member['wp_user_id'])))return $old;
                if($team==='operations_internal_team' && in_array(strtolower((string)($member['enabled']??'no')),['yes','enabled','1','true'],true)
                   && empty($member['wp_user_id']))return $old;
                if($team==='operations_external_team' && empty($member['wp_user_id']) &&
                    in_array(strtolower((string)($member['enabled']??'no')),['yes','enabled','1','true'],true) &&
                    (empty($member['email']) || empty($member['contact_name'])))return $old;
            }
        }
        // A source-profile edit cannot bypass SMTP verification, deployment or
        // site-level send gates; readiness remains separately calculated.
        return $new;
    }
    public static function register():void {
        add_filter('pre_update_option_'.self::SLUG,array(__CLASS__,'validateSave'),10,2);
        add_filter('woocommerce_email_from_name',array(__CLASS__,'woocommerceName'));
        add_filter('woocommerce_email_from_address',array(__CLASS__,'woocommerceFrom'));
        add_filter('woocommerce_email_recipient_new_order',array(__CLASS__,'woocommerceNewOrder'),30);
        add_filter('fluentform/email_to',array(__CLASS__,'formRecipient'),30,4);
        add_action('rest_api_init',array(__CLASS__,'rest'),30);
    }
    public static function rest():void {
        register_rest_route('mad4b/v1','/admin/company-communication',array(
            'methods'=>'GET','callback'=>array(__CLASS__,'overview'),
            'permission_callback'=>static fn()=>current_user_can('mad4b_manage_company_operations')));
        register_rest_route('mad4b/v1','/admin/company-communication/preview',array(
            'methods'=>'POST','callback'=>array(__CLASS__,'preview'),
            'permission_callback'=>static fn()=>current_user_can('mad4b_manage_company_operations')));
    }
    private static function response(array $data):WP_REST_Response {
        $r=rest_ensure_response($data);
        $r->header('Cache-Control','private, no-store');return $r;
    }
    public static function overview($request){
        $c=self::config();
        // Avoid exposing personal team address books or credentials via REST.
        return self::response(array('contract'=>'mad4b.communication.diagnostics.v1','tenant_home'=>home_url('/'),
            'page_url'=>admin_url('admin.php?page='.self::SLUG),
            'policies'=>array_intersect_key($c,array_flip(array('outbound_email_policy','woo_routing_policy','fluent_forms_routing_policy',
                'notify_internal_operations','notify_external_operations','notify_traveler','email_transport'))),
            'internal_team_count'=>count($c['operations_internal_team']),
            'external_team_count'=>count($c['operations_external_team']),
            'identity_source'=>'WordPress Users for linked team members',
            'brand_identity_source'=>'MAD4B Context Authority (site-bound)',
            'brand_identity_bound'=>self::brandIdentity()['valid'],
            'brand_identity_revision'=>self::brandIdentity()['revision'],
            'brand_identity_reason'=>self::brandIdentity()['reason'],
            'internal_eligible_count'=>class_exists('MAD4B_SCP_Company_Communication_Team')?
                count(MAD4B_SCP_Company_Communication_Team::members('internal',true)):0,
            'external_eligible_count'=>class_exists('MAD4B_SCP_Company_Communication_Team')?
                count(MAD4B_SCP_Company_Communication_Team::members('external',true)):0,
            'external_assignment_required'=>true,
            'smtp_installed'=>self::smtpInstalled(),'smtp_verified'=>self::smtpConfigured(),
            'can_send'=>self::canSend(),'issues'=>self::problems(),
            'diagnostic_only'=>true,'messages_sent'=>0));
    }
    public static function preview($request){
        $event=sanitize_key((string)$request->get_param('event'));
        if(!in_array($event,array('new_request','booking_confirmed','sales_quote',
           'traveler_support','operations_internal','operations_external'),true))
            return new WP_Error('mad4b_communication_event_invalid','Unknown event.',array('status'=>422));
        $r=self::recipients($event);
        return self::response(array('contract'=>'mad4b.communication.preview.v1',
            'event'=>$event,'recipient_count'=>count($r),
            'requires_order_scoped_external_approval'=>$event==='operations_external',
            'recipient_domains'=>array_values(array_unique(array_map(static fn($x)=>substr(strrchr($x,'@'),1),$r))),
            'would_send'=>self::canSend(),'reasons'=>self::problems(),
            'dry_run'=>true,'messages_sent'=>0));
    }
}
MAD4B_SCP_Company_Communication::register();
