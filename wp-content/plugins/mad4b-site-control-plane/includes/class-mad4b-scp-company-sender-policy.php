<?php
/**
 * MAD4B sender mode resolver, source-owned by the existing Company Communication
 * JetEngine Options Page. Transport and verification are independent authorities.
 */
defined('ABSPATH') || exit;
final class MAD4B_SCP_Company_Sender_Policy {
    const CONTRACT = 'mad4b.company.sender-policy.v1';
    const VERIFIED_STAFF_META = 'mad4b_company_verified_sender_v1';
    const EVENTS = [
        'marketing_campaign','sales_followup','new_request','booking_confirmed',
        'order_confirmation','operations_internal','operations_external','support_reply'
    ];
    const MODES = ['company','department','employee'];
    const DEPARTMENTS = [
        'marketing_campaign'=>'sales_inbox',
        'sales_followup'=>'sales_inbox',
        'new_request'=>'bookings_inbox',
        'booking_confirmed'=>'bookings_inbox',
        'order_confirmation'=>'bookings_inbox',
        'operations_internal'=>'operations_internal_inbox',
        'operations_external'=>'operations_external_inbox',
        'support_reply'=>'support_inbox'
    ];
    private static function source(): array {
        if (!class_exists('MAD4B_SCP_Company_Communication',false))
            require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-company-communication.php';
        $raw = get_option(MAD4B_SCP_Company_Communication::SLUG, []);
        return is_array($raw) ? $raw : [];
    }
    private static function valid_mail($input): string {
        if (!is_string($input)) return '';
        $value = strtolower(trim($input));
        return is_email($value) && $value === strtolower(sanitize_email($value)) ? $value : '';
    }
    private static function same_domain(string $candidate,string $primary): bool {
        if ($candidate === '' || $primary === '') return false;
        return substr(strrchr($candidate,'@'),1) === substr(strrchr($primary,'@'),1);
    }
    public static function policy(): array {
        $source = self::source();
        $defaults = [
            'marketing_campaign'=>'company','sales_followup'=>'employee',
            'new_request'=>'department','booking_confirmed'=>'department',
            'order_confirmation'=>'company','operations_internal'=>'employee',
            'operations_external'=>'department','support_reply'=>'department'
        ];
        $overrides = $source['sender_event_modes'] ?? [];
        if (!is_array($overrides)) $overrides = [];
        foreach ($defaults as $event=>$mode) {
            if (in_array($overrides[$event] ?? '', self::MODES, true))
                $defaults[$event] = $overrides[$event];
        }
        $default = in_array($source['sender_mode_default'] ?? '', self::MODES, true)
            ? $source['sender_mode_default'] : 'company';
        return ['contract'=>self::CONTRACT,'source'=>MAD4B_SCP_Company_Communication::SLUG,
            'default_mode'=>$default,'event_modes'=>$defaults,'available_modes'=>self::MODES,
            'wp_user_reference'=>'WordPress Users + company-managed staff verification',
            'no_implicit_employee_mailbox'=>true];
    }
    public static function preview(string $event,int $staffId=0,?string $requestedMode=null): array {
        $rules=self::policy();
        $source=MAD4B_SCP_Company_Communication::config();
        $email=self::valid_mail((string)$source['transactional_from_email']);
        $identity=class_exists('MAD4B_SCP_Company_Email_Routing',false)
            ? MAD4B_SCP_Company_Email_Routing::identity() : ['verified'=>false,'site_uuid'=>'','identity_sha256'=>''];
        $mode=$rules['event_modes'][$event]??$rules['default_mode'];
        $reasons=[];
        if (!in_array($event,self::EVENTS,true)) $reasons[]='unknown_event';
        // Unconfigured client overrides cannot choose an unauthorized From.
        if ($requestedMode!==null && $requestedMode!==$mode) $reasons[]='mode_override_requires_saved_policy';
        $companyName=(string)$source['company_display_name'];
        if ($companyName==='') $companyName=(string)get_option('blogname','Company');
        $fromName=$companyName;
        $fromEmail=$email;
        $reply=self::valid_mail((string)$source['traveler_reply_to']);
        if (!$email) $reasons[]='official_company_mail_missing';
        if (empty($identity['verified'])) $reasons[]='company_mail_not_verified';
        $selected='company';
        $staffReason=null;
        if ($mode==='department') {
            $key=self::DEPARTMENTS[$event]??'';
            $dept=$key ? self::valid_mail((string)($source[$key]??'')) : '';
            if (self::same_domain($dept,$email)) {
                $selected='department';$fromEmail=$dept;
                $fromName=ucwords(str_replace('_',' ',preg_replace('/_inbox$/','',$key))).' | '.$companyName;
                $reply=$dept;
            } else {
                $staffReason='department_sender_unverified_or_cross_domain';
            }
        } elseif ($mode==='employee') {
            $user=$staffId>0?get_userdata($staffId):false;
            $companyStaff=$user && class_exists('MAD4B_SCP_Company_Master_Admin',false)
                && MAD4B_SCP_Company_Master_Admin::managedStaff($staffId);
            $verified=$companyStaff?get_user_meta($staffId,self::VERIFIED_STAFF_META,true):null;
            $sender=$user?self::valid_mail((string)$user->user_email):'';
            $validated=is_array($verified)
                && ($verified['site_uuid']??'')===($identity['site_uuid']??'')
                && ($verified['email']??'')===$sender
                && !empty($verified['approved_at'])
                && !empty($verified['approved_by'])
                && ($verified['identity_sha256']??'')===($identity['identity_sha256']??'')
                && self::same_domain($sender,$email);
            if ($validated) {
                $selected='employee';$fromEmail=$sender;$reply=$sender;
                $fromName=sanitize_text_field($user->display_name).' | '.$companyName;
            } else {
                $staffReason='employee_mailbox_not_verified';
                if ($user && $companyStaff) {
                    $fromName=sanitize_text_field($user->display_name).' | '.$companyName;
                }
            }
        }
        if (!MAD4B_SCP_Company_Communication::smtpConfigured()) $reasons[]='smtp_not_verified';
        if (!MAD4B_SCP_Company_Communication::canSend()) $reasons[]='company_send_gate_closed';
        $allowed=!$reasons;
        return ['contract'=>self::CONTRACT,'event'=>$event,
            'requested_mode'=>$mode,'effective_mode'=>$selected,
            'fallback_reason'=>$staffReason,'from_name'=>$fromName,
            'from_email'=>$fromEmail,'reply_to'=>$reply ?: $fromEmail,
            'site_uuid'=>(string)($identity['site_uuid']??''),
            'ready'=>$allowed,'blockers'=>array_values(array_unique($reasons)),
            'dry_run'=>true,'messages_sent'=>0];
    }
    /**
     * Operator configuration: optimistic revision and independent write policy.
     * Stored under the existing JetEngine Company Communication option only.
     */
    public static function revise($request) {
        if (!current_user_can('mad4b_manage_company_operations')
            || !class_exists('MAD4B_SCP_Policy',false)
            || !MAD4B_SCP_Policy::can_mutate())
            return new WP_Error('sender_policy_write_closed','Governed write authority is required.',['status'=>403]);
        $data=$request->get_json_params();
        $modes=$data['event_modes']??null;
        $default=$data['default_mode']??null;
        if (!is_array($data) || !is_array($modes) || !is_string($default)
            || !in_array($default,self::MODES,true)
            || array_diff(array_keys($data),['event_modes','default_mode','expected_sha256'])
            || array_diff(array_keys($modes),self::EVENTS))
            return new WP_Error('sender_policy_invalid','Unsupported sender policy configuration.',['status'=>422]);
        foreach ($modes as $event=>$mode)
            if (!in_array($mode,self::MODES,true))
                return new WP_Error('sender_policy_invalid','Unsupported sender mode.',['status'=>422]);
        global $wpdb;
        $lock='mad4b:company-sender-mode:'.get_current_blog_id();
        if ((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,3)',$lock))!==1)
            return new WP_Error('sender_policy_busy','Concurrent update in progress.',['status'=>423]);
        try {
            $raw=self::source();
            $digest=hash('sha256',wp_json_encode($raw));
            if (empty($data['expected_sha256']) || !hash_equals($digest,(string)$data['expected_sha256']))
                return new WP_Error('sender_policy_revision_conflict','Company Communication source has changed.',['status'=>409]);
            $before=$raw;
            $raw['sender_mode_default']=$default;
            $raw['sender_event_modes']=array_merge(self::policy()['event_modes'],$modes);
            if (!update_option(MAD4B_SCP_Company_Communication::SLUG,$raw,false)
                && get_option(MAD4B_SCP_Company_Communication::SLUG)!==$raw)
                return new WP_Error('sender_policy_write_failed','Source update failed.',['status'=>502]);
            $checked=self::source();
            if (($checked['sender_mode_default']??null)!==$default
                || ($checked['sender_event_modes']??null)!==$raw['sender_event_modes'])
                return new WP_Error('sender_policy_readback_failed','Source readback mismatch.',['status'=>502]);
            $audit=(array)get_option('mad4b_company_sender_policy_audit_v1',[]);
            $audit[]=['actor_id'=>get_current_user_id(),'site_uuid'=>
                (class_exists('MAD4B_SCP_Company_Email_Routing',false)
                  ? MAD4B_SCP_Company_Email_Routing::identity()['site_uuid'] : ''),
                'at'=>current_time('mysql',true),
                'before_digest'=>hash('sha256',wp_json_encode($before)),
                'after_digest'=>hash('sha256',wp_json_encode($checked))];
            update_option('mad4b_company_sender_policy_audit_v1',array_slice($audit,-100),false);
            return rest_ensure_response(['status'=>'UPDATED','policy'=>self::policy(),
                'source_sha256'=>hash('sha256',wp_json_encode($checked))]);
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));
        }
    }
    /** Never change campaign recipient or unsubscribe headers. */
    public static function crm_data($data,$subscriber=null,$emailModel=null) {
        if (!is_array($data)) return $data;
        $kind = is_object($emailModel) ? (string)($emailModel->type ?? '') : '';
        $event = in_array($kind,['automation','sequence','funnel','sequence_mail'],true)
            ? 'sales_followup' : 'marketing_campaign';
        $staff = is_object($emailModel) ? absint($emailModel->created_by ?? 0) : 0;
        $plan=self::preview($event,$staff);
        if (!$plan['ready']) return $data;
        // FluentCRM 3.2.6 Mailer::buildHeaders reads headers.From and
        // headers.Reply-To, not the loose 'from_email' fields.
        if (isset($data['headers']) && !is_array($data['headers'])) return $data;
        if (!isset($data['headers'])) $data['headers']=[];
        $safeName=str_replace(["\r","\n","<",">"],'',
            sanitize_text_field((string)$plan['from_name']));
        $data['headers']['From']=$safeName.' <'.$plan['from_email'].'>';
        $data['headers']['Reply-To']='<'.$plan['reply_to'].'>';
        return $data;
    }
    /**
     * WooCommerce-native per-event sender, after the event is known.
     * Existing headers cannot override a verified company-bound identity.
     */
    public static function woo_headers($headers,$emailId='') {
        $event=['new_order'=>'new_request',
            'customer_processing_order'=>'order_confirmation',
            'customer_completed_order'=>'order_confirmation',
            'customer_on_hold_order'=>'order_confirmation',
            'customer_invoice'=>'order_confirmation',
            'customer_note'=>'support_reply'][$emailId] ?? null;
        if (!$event) return $headers;
        $p=self::preview($event);
        if (!$p['ready']) return $headers;
        $items=is_array($headers)?array_values($headers):
            preg_split('/\r?\n/',(string)$headers,-1,PREG_SPLIT_NO_EMPTY);
        $items=array_values(array_filter($items,static fn($h)=>
            is_string($h) && !preg_match('/^\s*(From|Reply-To)\s*:/i',$h)));
        $safeName=str_replace(["\r","\n","<",">"],'',$p['from_name']);
        $items[]='From: '.$safeName.' <'.$p['from_email'].'>';
        $items[]='Reply-To: <'.$p['reply_to'].'>';
        return is_array($headers)?$items:implode("\r\n",$items);
    }
    public static function crm_processing_disabled($disabled) {
        return (bool)$disabled || !self::preview('marketing_campaign')['ready'];
    }
    public static function crm_simulate($simulated) {
        // Do not report a rejected outbound message as successfully sent.
        // Bulk processing is blocked separately; direct send flows reach
        // pre_wp_mail and return false instead of a simulated success.
        return (bool)$simulated;
    }
    public static function prevent_unauthorized_wp_mail($pre) {
        // The foreign REST/AJAX zero-touch kernel intentionally does not load
        // Company Communication. Missing authority must reject, not fatal.
        if (wp_get_environment_type()==='staging'
            && (!class_exists('MAD4B_SCP_Company_Communication', false)
                || !MAD4B_SCP_Company_Communication::canSend())) return false;
        return $pre;
    }
    public static function rest():void {
        add_action('rest_api_init',static function() {
            $permission=static fn()=>current_user_can('mad4b_manage_company_operations');
            register_rest_route('mad4b/v1','/admin/company-sender-policy',[
                'methods'=>'GET','permission_callback'=>$permission,
                'callback'=>static function() {return rest_ensure_response(array_merge(self::policy(),['source_sha256'=>hash('sha256',wp_json_encode(self::source()))]));}]);
            register_rest_route('mad4b/v1','/admin/company-sender-policy',[
                'methods'=>'PATCH','permission_callback'=>$permission,
                'callback'=>[__CLASS__,'revise']]);
            register_rest_route('mad4b/v1','/admin/company-sender-policy/preview',[
                'methods'=>'POST','permission_callback'=>$permission,
                'callback'=>static function($request) {
                    $event=sanitize_key((string)$request->get_param('event'));
                    $staff=absint($request->get_param('staff_user_id'));
                    $mode=$request->has_param('mode')?sanitize_key((string)$request->get_param('mode')):null;
                    $p=self::preview($event,$staff,$mode);
                    // No employee personal address exposed by the diagnostics API.
                    unset($p['from_email'],$p['reply_to'],$p['from_name']);
                    return rest_ensure_response($p);
                }]);
        },32);
    }
    public static function boot():void {
        add_filter('fluent_crm/email_data_before_headers',[__CLASS__,'crm_data'],99,3);
        add_filter('woocommerce_email_headers',[__CLASS__,'woo_headers'],99,2);
        add_filter('fluent_crm/disable_email_processing',[__CLASS__,'crm_processing_disabled'],99,1);
        add_filter('fluent_crm/is_simulated_mail',[__CLASS__,'crm_simulate'],99,1);
        add_filter('pre_wp_mail',[__CLASS__,'prevent_unauthorized_wp_mail'],PHP_INT_MAX-1,1);
        self::rest();
    }
}
MAD4B_SCP_Company_Sender_Policy::boot();
