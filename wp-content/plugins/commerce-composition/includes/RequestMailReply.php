<?php
declare(strict_types=1);
namespace Commerce\Composition;

/** Non-marketing reply to a verified unpriced inquiry via the site's FluentSMTP. */
final class RequestMailReply
{
    private const OPTION='commerce_request_reply_policy_v1';

    private static function table():string {
        global $wpdb;
        return $wpdb->prefix.'commerce_request_reply_events';
    }

    public static function install():true|\WP_Error {
        global $wpdb;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $t=self::table();
        dbDelta("CREATE TABLE $t (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            request_id bigint(20) unsigned NOT NULL,
            contact_id bigint(20) unsigned NOT NULL,
            actor_id bigint(20) unsigned NOT NULL,
            switched_from_actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
            command_sha256 char(64) NOT NULL,
            recipient_sha256 char(64) NOT NULL,
            content_sha256 char(64) NOT NULL,
            state varchar(35) NOT NULL,
            created_utc datetime NOT NULL,
            updated_utc datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY command_key (command_sha256),
            KEY request_id (request_id)
        ) {$wpdb->get_charset_collate()};");
        return self::ready()?true:new \WP_Error('reply_audit_schema_unavailable');
    }

    public static function ready():bool {
        global $wpdb;
        $t=self::table();
        return $wpdb->get_var($wpdb->prepare(
            "SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s",
            $t))==='InnoDB';
    }

    public static function policy():array {
        $cfg=get_option(self::OPTION,[]);
        return is_array($cfg)?$cfg:[];
    }

    public static function enabled():bool {
        $c=self::policy();
        return ($c['enabled']??null)===true
            &&($c['site_url']??null)===home_url()
            &&($c['transport']??null)==='fluent_smtp';
    }

    public static function authorized():bool {
        if(!is_user_logged_in())return false;
        if(!self::enabled()||!RequestReview::enabled())return false;
        $user=wp_get_current_user();
        // A switched administrator acts as the *target* Master Admin.
        // No generic administrator or supplier/CRM role gets sending rights.
        return in_array('master_admin',(array)$user->roles,true)
            &&current_user_can('mad4b_manage_company_operations');
    }

    /** The selected connection belongs to FluentSMTP, not Commerce Composition. */
    public static function transport():array {
        $installed=in_array('fluent-smtp/fluent-smtp.php',
            (array)get_option('active_plugins',[]),true);
        if(!$installed||!function_exists('fluentMailGetSettings'))
            return ['ready'=>false,'provider'=>null,'reason'=>'fluent_smtp_inactive'];
        $settings=fluentMailGetSettings([],false);
        $connections=is_array($settings)?($settings['connections']??[]):[];
        if(!is_array($connections)||count($connections)<1)
            return ['ready'=>false,'provider'=>null,'reason'=>'fluent_smtp_connection_missing'];
        $default=$settings['misc']['default_connection']??null;
        $selected=$default!==null&&isset($connections[$default])
            ?$connections[$default]:reset($connections);
        $ps=$selected['provider_settings']??[];
        $provider=$ps['provider']??null;
        if(!is_string($provider)||!is_email($ps['sender_email']??null))
            return ['ready'=>false,'provider'=>null,'reason'=>'fluent_smtp_sender_invalid'];
        if($provider==='gmail' &&
            (empty($ps['client_id'])||empty($ps['client_secret'])||
             empty($ps['refresh_token'])))
            return ['ready'=>false,'provider'=>'gmail','reason'=>'fluent_smtp_gmail_auth_missing'];
        return ['ready'=>true,'provider'=>$provider,'reason'=>null];
    }

    /**
     * Scope-specific read for Master Admin reply; does not extend the ability
     * to approve/reject requests in the independent RequestReview subsystem.
     */
    private static function requestSource(int $requestId):array|\WP_Error {
        if($requestId<1 || !RequestReview::ready())
            return new \WP_Error('reply_request_source_missing');
        global $wpdb;
        $b=$wpdb->prefix.'commerce_request_batches';
        $s=$wpdb->prefix.'commerce_request_review_states';
        $row=$wpdb->get_row($wpdb->prepare(
            "SELECT r.id,r.state,r.pricing_state,r.sales_order_id,r.snapshot_json,
             COALESCE(s.review_state,'pending_review') review_state
             FROM $b r LEFT JOIN $s s ON s.request_id=r.id WHERE r.id=%d",
            $requestId),ARRAY_A);
        if(!is_array($row)||$row['state']!=='pending_review'
            ||$row['pricing_state']!=='not_quoted'||$row['sales_order_id']!==null
            ||!in_array($row['review_state'],
                ['pending_review','under_review','needs_information'],true))
            return new \WP_Error('reply_request_not_active');
        $items=json_decode((string)$row['snapshot_json'],true);
        if(!is_array($items)||count($items)<1||count($items)>30)
            return new \WP_Error('reply_request_items_invalid');
        $sources=[];
        foreach($items as $item) {
            if(!is_array($item)
                ||(int)($item['form_id']??0)<1
                ||(int)($item['source_submission_id']??0)<1
                ||(int)($item['source_id']??0)<1)
                return new \WP_Error('reply_original_item_invalid');
            $sources[]=[
                'form_id'=>(int)$item['form_id'],
                'submission_id'=>(int)$item['source_submission_id']
            ];
        }
        return ['review_state'=>$row['review_state'],'sources'=>$sources];
    }

    /** Derive recipient solely from the original persisted forms + CRM linkage. */
    public static function recipient(int $requestId):array|\WP_Error {
        if(!self::authorized()||!self::ready())
            return new \WP_Error('reply_not_authorized');
        $r=self::requestSource($requestId);
        if(is_wp_error($r))return $r;
        $keys=ContactBridge::sources();
        $contactId=0;
        $email=null;
        global $wpdb;
        $linkTable=$wpdb->prefix.'commerce_contact_links';
        $subTable=$wpdb->prefix.'fluentform_submissions';
        foreach($r['sources'] as $source){
            $fid=(int)$source['form_id'];$sid=(int)$source['submission_id'];
            $proof=SubmissionProvenance::verify($sid,$fid);
            if(is_wp_error($proof))return $proof;
            $ids=[];
            foreach($keys as $key=>$def) {
                if(($def['domain']??null)==='fluent_form' &&
                    in_array($fid,array_map('intval',(array)($def['form_ids']??[])),true))
                    $ids[]=$key;
            }
            if(count($ids)!==1)return new \WP_Error('reply_form_origin_ambiguous');
            $row=$wpdb->get_row($wpdb->prepare(
                "SELECT response,status FROM $subTable WHERE id=%d AND form_id=%d",
                $sid,$fid),ARRAY_A);
            if(!is_array($row)||in_array($row['status'],['spam','trashed','deleted'],true)
                ||strlen((string)$row['response'])>120000)
                return new \WP_Error('reply_submission_invalid');
            $response=json_decode($row['response'],true);
            $original=is_array($response)?strtolower(trim((string)($response['email']??''))):'';
            if(!is_email($original))return new \WP_Error('reply_original_email_invalid');
            $contacts=$wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT contact_id FROM $linkTable
                WHERE domain=%s AND source_key=%s AND source_id=%d AND role=%s LIMIT 3",
                'fluent_form',$ids[0],$sid,'requester'));
            if(count($contacts)!==1)return new \WP_Error('reply_original_crm_link_missing');
            if($contactId!==0 && $contactId!==(int)$contacts[0])
                return new \WP_Error('reply_cross_source_contact_conflict');
            if($email!==null&&!hash_equals($email,$original))
                return new \WP_Error('reply_cross_source_email_conflict');
            $contactId=(int)$contacts[0];
            $email=$original;
        }
        if(!$contactId||$email===null)return new \WP_Error('reply_no_identity');
        $c=$wpdb->get_row($wpdb->prepare(
            "SELECT email,status FROM {$wpdb->prefix}fc_subscribers WHERE id=%d",$contactId),
            ARRAY_A);
        if(!is_array($c)||!hash_equals(strtolower($c['email']),$email))
            return new \WP_Error('reply_crm_identity_mismatch');
        if(in_array($c['status'],['bounced','complained','spammed','suppressed'],true))
            return new \WP_Error('reply_contact_delivery_suppressed');
        return ['request_id'=>$requestId,'contact_id'=>$contactId,
            'recipient'=>$email,'review_state'=>$r['review_state']];
    }

    public static function send(int $requestId,string $subject,string $message,string $intent):array|\WP_Error {
        if(!self::authorized())return new \WP_Error('reply_access_denied');
        $transport=self::transport();
        if(!$transport['ready'])
            return new \WP_Error($transport['reason']);
        if(!preg_match('/^[a-zA-Z0-9._:-]{16,128}$/D',$intent))
            return new \WP_Error('reply_idempotency_required');
        $subject=trim($subject);$message=trim($message);
        if(mb_strlen($subject)<5||mb_strlen($subject)>140||
            mb_strlen($message)<10||mb_strlen($message)>4000||
            preg_match('/[\r\n]/',$subject)||
            str_contains($subject,'<')||str_contains($message,'<'))
            return new \WP_Error('reply_content_invalid');
        $proof=self::recipient($requestId);
        if(is_wp_error($proof))return $proof;
        $current=get_current_user_id();
        $old=class_exists('user_switching')?\user_switching::get_old_user():false;
        $switched=$old instanceof \WP_User && $old->ID!==$current
            ?(int)$old->ID:0;
        global $wpdb;
        $table=self::table();
        $key=hash('sha256',home_url().'|'.$requestId.'|'.$intent);
        $contentHash=hash_hmac('sha256',$subject."\n".$message,
            wp_salt('secure_auth'));
        $recipientHash=hash_hmac('sha256',strtolower($proof['recipient']),
            wp_salt('secure_auth'));
        $now=gmdate('Y-m-d H:i:s');
        $insert=$wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO $table
             (request_id,contact_id,actor_id,switched_from_actor_id,
              command_sha256,recipient_sha256,content_sha256,state,created_utc,updated_utc)
             VALUES (%d,%d,%d,%d,%s,%s,%s,%s,%s,%s)",
            $requestId,$proof['contact_id'],$current,$switched,$key,
            $recipientHash,$contentHash,'claimed',$now,$now));
        if($insert!==1)return new \WP_Error($insert===0?'reply_duplicate_command':'reply_audit_write_failed');
        // The actual delivery backend and connection selection are owned by
        // FluentSMTP's wp_mail hooks, not this module.
        $sent=false;
        try {
            $sent=wp_mail($proof['recipient'],$subject,
                "Regarding inquiry #".$requestId."\n\n".$message,
                ['Content-Type: text/plain; charset=UTF-8']);
        } catch(\Throwable $e) {
            $sent=false;
        }
        $wpdb->update($table,
            ['state'=>$sent===true?'transport_accepted':'transport_failed',
                'updated_utc'=>gmdate('Y-m-d H:i:s')],
            ['command_sha256'=>$key]);
        return $sent===true
            ?['status'=>'transport_accepted','request_id'=>$requestId,
               'actor_id'=>$current,'switched_from_actor_id'=>$switched,
               'provider'=>$transport['provider'],'delivery_confirmed'=>false]
            :new \WP_Error('reply_fluentsmtp_send_failed');
    }

    public static function bootstrap():void {
        add_action('rest_api_init',static function(){
            $permission=static fn()=>self::authorized();
            register_rest_route('commerce-composition/v1',
                '/request-replies/(?P<request_id>[1-9][0-9]*)',[
                [
                    'methods'=>\WP_REST_Server::READABLE,
                    'permission_callback'=>$permission,
                    'callback'=>static function(\WP_REST_Request $request){
                        $proof=self::recipient((int)$request['request_id']);
                        if(is_wp_error($proof))return $proof;
                        $response=new \WP_REST_Response([
                            'request_id'=>$proof['request_id'],
                            'review_state'=>$proof['review_state'],
                            'transport'=>self::transport(),
                            'recipient_verified'=>true,
                            'recipient_address'=>$proof['recipient']
                        ],200);
                        $response->header('Cache-Control','private,no-store');
                        return $response;
                    }
                ],
                [
                    'methods'=>\WP_REST_Server::CREATABLE,
                    'permission_callback'=>$permission,
                    'callback'=>static function(\WP_REST_Request $request){
                        $body=$request->get_json_params();
                        if(!is_array($body))return new \WP_Error('reply_body_required');
                        $keys=array_keys($body);sort($keys);
                        if($keys!==['message','subject'])
                            return new \WP_Error('reply_payload_invalid');
                        $intent=(string)$request->get_header('idempotency-key');
                        return self::send((int)$request['request_id'],
                            (string)$body['subject'],(string)$body['message'],$intent);
                    }
                ]
            ]);
        });
    }
}
