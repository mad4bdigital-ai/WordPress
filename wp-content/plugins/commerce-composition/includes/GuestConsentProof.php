<?php
declare(strict_types=1);
namespace Commerce\Composition;

/** One-time email ownership proof for an opted-in guest Form.
 * Does not subscribe/update contacts or send email without approved transport.
 */
final class GuestConsentProof
{
    private static function table():string
    {
        global $wpdb;
        return $wpdb->prefix.'commerce_guest_consent_proofs';
    }
    public static function install():true|\WP_Error
    {
        global $wpdb;
        $t=self::table();
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        dbDelta("CREATE TABLE $t (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            form_id bigint(20) unsigned NOT NULL,
            submission_id bigint(20) unsigned NOT NULL,
            purpose varchar(80) NOT NULL,
            version varchar(40) NOT NULL,
            display_language varchar(16) NOT NULL,
            statement_snapshot text NOT NULL,
            form_sha256 char(64) NOT NULL,
            email_hmac char(64) NOT NULL,
            token_hmac char(64) NOT NULL,
            state varchar(24) NOT NULL,
            expires_utc datetime NOT NULL,
            created_utc datetime NOT NULL,
            verified_utc datetime NULL DEFAULT NULL,
            attempts int(10) unsigned NOT NULL DEFAULT 1,
            next_retry_utc datetime NULL DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY submission_purpose (form_id,submission_id,purpose),
            UNIQUE KEY token_key (token_hmac),
            KEY proof_state (state,expires_utc)
        ) {$wpdb->get_charset_collate()};");
        return self::ready()?true:new \WP_Error('guest_consent_table_failed');
    }
    public static function ready():bool
    {
        global $wpdb;
        $t=self::table();
        return $wpdb->get_var($wpdb->prepare(
            'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',
            $t))==='InnoDB';
    }
    private static function key(string $value,string $purpose):string
    {
        return hash_hmac('sha256',$value.':'.$purpose,wp_salt('secure_auth'));
    }
    public static function fromOriginalSubmission($entryId,$data,$form):void
    {
        if(!is_numeric($entryId)||!is_object($form)||
            !is_numeric($form->id??null))return;
        self::issue((int)$form->id,(int)$entryId);
    }
    public static function issue(int $formId,int $submissionId):array|\WP_Error
    {
        global $wpdb;
        if(!self::ready()||$formId<1||$submissionId<1)
            return new \WP_Error('guest_consent_unavailable');
        $policies=ConsentPolicySource::all();
        $rule=is_array($policies)?($policies[$formId]??null):null;
        if(!is_array($rule)||($rule['field']??null)!=='marketing_optin'||
            ($rule['purpose']??null)!=='marketing_updates'||
            ($rule['accepted_value']??null)!=='yes'||
            !is_string($rule['form_sha256']??null)||
            !is_string($rule['version']??null)||
            !is_string($rule['valid_from']??null))
            return new \WP_Error('guest_consent_policy_missing');
        $entries=$wpdb->prefix.'fluentform_submissions';
        $entry=$wpdb->get_row($wpdb->prepare(
            "SELECT id,form_id,user_id,status,response,created_at
             FROM $entries WHERE id=%d AND form_id=%d",
            $submissionId,$formId),ARRAY_A);
        if(!is_array($entry)||(int)$entry['user_id']!==0||
            in_array($entry['status'],['spam','trashed','deleted'],true))
            return new \WP_Error('guest_consent_source_not_valid');
        $forms=$wpdb->prefix.'fluentform_forms';
        $raw=$wpdb->get_var($wpdb->prepare(
            "SELECT form_fields FROM $forms WHERE id=%d",$formId));
        if(!is_string($raw)||!hash_equals((string)$rule['form_sha256'],hash('sha256',$raw)))
            return new \WP_Error('guest_consent_source_hash_mismatch');
        try{
            $submitted=(new \DateTimeImmutable((string)$entry['created_at'],
                wp_timezone()))->getTimestamp();
        }catch(\Throwable $e){
            return new \WP_Error('guest_consent_timestamp_invalid');
        }
        if($submitted<strtotime($rule['valid_from'])||$submitted>time()+60)
            return new \WP_Error('guest_consent_policy_not_in_effect');
        $answers=json_decode((string)$entry['response'],true);
        if(!is_array($answers)||!(($answers['marketing_optin']??null)==='yes'||
            ($answers['marketing_optin']??null)===['yes']))
            return new \WP_Error('guest_consent_not_checked');
        $config=get_option('commerce_guest_consent_config',[]);
        $field=is_array($config)?($config['email_fields'][$formId]??null):null;
        if(!is_string($field)||!preg_match('/^[a-z][a-z0-9_]{1,79}$/D',$field))
            return new \WP_Error('guest_consent_email_field_unconfigured');
        $email=$answers[$field]??null;
        if(!is_string($email)||!is_email($email))
            return new \WP_Error('guest_consent_email_invalid');
        $email=strtolower(trim($email));
        $t=self::table();
        $existing=$wpdb->get_row($wpdb->prepare(
            "SELECT id,state FROM $t WHERE form_id=%d AND submission_id=%d
             AND purpose=%s",$formId,$submissionId,'marketing_updates'),ARRAY_A);
        if(is_array($existing))
            return ['state'=>$existing['state'],
                'proof_id'=>(int)$existing['id'],'reused'=>true,'delivered'=>false];
        if(apply_filters('commerce_composition_guest_consent_transport_ready',
            false,$formId)!==true)
            return new \WP_Error('guest_consent_delivery_not_configured');
        try{$token=bin2hex(random_bytes(32));}
        catch(\Throwable $e){return new \WP_Error('guest_consent_entropy_unavailable');}
        $locale=MarketingConsentTranslations::statementForCurrentLanguage($rule);
        if(trim($locale['text'])==='')return new \WP_Error('guest_consent_statement_missing');
        $tokenHash=self::key($token,'challenge');
        $expires=gmdate('Y-m-d H:i:s',time()+86400);
        $insert=$wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO $t
            (form_id,submission_id,purpose,version,display_language,
             statement_snapshot,form_sha256,email_hmac,token_hmac,state,
             expires_utc,created_utc)
            VALUES (%d,%d,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)",
            $formId,$submissionId,'marketing_updates',$rule['version'],
            $locale['language'],$locale['text'],
            $rule['form_sha256'],self::key($email,'email'),$tokenHash,
            'delivery_pending',$expires,gmdate('Y-m-d H:i:s')
        ));
        if($insert!==1)
            return new \WP_Error('guest_consent_pending_conflict');
        $id=(int)$wpdb->insert_id;
        // No default email. An owner-approved sender receives the token
        // transiently, sends it only to the original saved email address.
        try{
            $delivered=apply_filters('commerce_composition_guest_consent_deliver',
                false,['email'=>$email,'token'=>$token,'proof_id'=>$id,
                    'form_id'=>$formId,'expires_utc'=>$expires,
                    'purpose'=>'marketing_updates']
            );
        }catch(\Throwable $e){$delivered=false;}
        if($delivered!==true){
            $next=gmdate('Y-m-d H:i:s',time()+900);
            $wpdb->query($wpdb->prepare(
                "UPDATE $t SET state='delivery_failed',next_retry_utc=%s
                 WHERE id=%d AND state='delivery_pending'",$next,$id));
            return new \WP_Error('guest_consent_delivery_failed');
        }
        $wpdb->query($wpdb->prepare(
            "UPDATE $t SET state='pending' WHERE id=%d
             AND state='delivery_pending'",$id));
        return ['state'=>'pending','proof_id'=>$id,
            'reused'=>false,'delivered'=>true];
    }
    /**
     * Dedicated plugin-only worker retries failed sends with a fresh token.
     * It cannot create a customer, resubscribe someone or bypass a changed
     * source/Form/consent policy. No SMTP credentials stored here.
     */
    public static function retryDue(int $limit=10):array
    {
        global $wpdb;
        $result=['processed'=>0,'delivered'=>0,'failed'=>0,'invalidated'=>0];
        if(!self::ready())return $result;
        $t=self::table();
        $ids=$wpdb->get_col($wpdb->prepare(
            "SELECT id FROM $t WHERE state='delivery_failed' AND attempts<3
             AND next_retry_utc<=%s ORDER BY id LIMIT %d",
            gmdate('Y-m-d H:i:s'),max(1,min($limit,20))
        ));
        foreach($ids as $id){
            $row=$wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $t WHERE id=%d",(int)$id
            ),ARRAY_A);
            if(!is_array($row)||!self::mailEnabled((int)$row['form_id']))continue;
            $claimed=$wpdb->query($wpdb->prepare(
                "UPDATE $t SET state='delivery_retrying',attempts=attempts+1
                 WHERE id=%d AND state='delivery_failed' AND attempts<3
                   AND next_retry_utc<=%s",
                (int)$id,gmdate('Y-m-d H:i:s')
            ));
            if($claimed!==1)continue;
            $result['processed']++;
            $email=self::checkRetrySource($row);
            if(is_wp_error($email)){
                $wpdb->update($t,[
                    'state'=>'invalidated','next_retry_utc'=>null
                ],['id'=>(int)$id,'state'=>'delivery_retrying']);
                $result['invalidated']++;
                continue;
            }
            try{$token=bin2hex(random_bytes(32));}
            catch(\Throwable $e){$token='';}
            if($token===''){
                $wpdb->update($t,['state'=>'delivery_failed',
                    'next_retry_utc'=>gmdate('Y-m-d H:i:s',time()+3600)],
                    ['id'=>(int)$id,'state'=>'delivery_retrying']);
                $result['failed']++;
                continue;
            }
            $newHash=self::key($token,'challenge');
            $wpdb->update($t,[
                'token_hmac'=>$newHash,
                'expires_utc'=>gmdate('Y-m-d H:i:s',time()+86400)
            ],['id'=>(int)$id,'state'=>'delivery_retrying']);
            $sent=false;
            try{
                $sent=apply_filters('commerce_composition_guest_consent_deliver',
                    false,['email'=>$email,'token'=>$token,'proof_id'=>(int)$id,
                        'form_id'=>(int)$row['form_id'],
                        'expires_utc'=>gmdate('Y-m-d H:i:s',time()+86400),
                        'purpose'=>'marketing_updates'])===true;
            }catch(\Throwable $e){$sent=false;}
            $wpdb->update($t,[
                'state'=>$sent?'pending':'delivery_failed',
                'next_retry_utc'=>$sent?null:gmdate('Y-m-d H:i:s',time()+3600),
            ],['id'=>(int)$id,'state'=>'delivery_retrying']);
            $sent?$result['delivered']++:$result['failed']++;
        }
        return $result;
    }
    private static function mailEnabled(int $formId):bool
    {
        return apply_filters('commerce_composition_guest_consent_transport_ready',
            false,$formId)===true;
    }
    private static function checkRetrySource(array $proof):string|\WP_Error
    {
        global $wpdb;
        $formId=(int)$proof['form_id'];
        $rule=ConsentPolicySource::all()[$formId]??null;
        if(!is_array($rule)||
            ($rule['purpose']??null)!==$proof['purpose']||
            ($rule['version']??null)!==$proof['version']||
            ($rule['form_sha256']??null)!==$proof['form_sha256'])
            return new \WP_Error('consent_retry_policy_changed');
        $forms=$wpdb->prefix.'fluentform_forms';
        $form=$wpdb->get_var($wpdb->prepare(
            "SELECT form_fields FROM $forms WHERE id=%d",$formId
        ));
        if(!is_string($form)||!hash_equals($proof['form_sha256'],hash('sha256',$form)))
            return new \WP_Error('consent_retry_form_changed');
        $entries=$wpdb->prefix.'fluentform_submissions';
        $row=$wpdb->get_row($wpdb->prepare(
            "SELECT user_id,status,response FROM $entries
             WHERE id=%d AND form_id=%d",
            (int)$proof['submission_id'],$formId
        ),ARRAY_A);
        $values=is_array($row)?json_decode((string)$row['response'],true):null;
        $settings=get_option('commerce_guest_consent_config',[]);
        $field=is_array($settings)?($settings['email_fields'][$formId]??null):null;
        $email=is_array($values)&&is_string($field)?($values[$field]??null):null;
        if(!is_array($row)||(int)$row['user_id']!==0||
            in_array($row['status'],['spam','trashed','deleted'],true)||
            !is_array($values)||
            !(($values['marketing_optin']??null)==='yes'||
                ($values['marketing_optin']??null)===['yes'])||
            !is_string($email)||!is_email($email)||
            !hash_equals($proof['email_hmac'],
                self::key(strtolower(trim($email)),'email')))
            return new \WP_Error('consent_retry_source_changed');
        return strtolower(trim($email));
    }

    /** POST-only one-time confirmation; does NOT update the CRM status. */
    public static function confirm(string $token):array|\WP_Error
    {
        global $wpdb;
        if(!self::ready()||!preg_match('/^[a-f0-9]{64}$/D',$token))
            return new \WP_Error('guest_consent_token_invalid');
        $t=self::table();
        $hash=self::key($token,'challenge');
        $now=gmdate('Y-m-d H:i:s');
        $pending=$wpdb->get_row($wpdb->prepare(
            "SELECT id,form_id,submission_id,purpose,version,form_sha256,
                email_hmac,expires_utc FROM $t
             WHERE token_hmac=%s AND state='pending'", $hash
        ),ARRAY_A);
        if(!is_array($pending)||$pending['expires_utc']<=$now)
            return new \WP_Error('guest_consent_token_expired_or_unknown');
        $formId=(int)$pending['form_id'];
        $policies=ConsentPolicySource::all();
        $rule=$policies[$formId]??null;
        if(!is_array($rule)||
            ($rule['purpose']??null)!==$pending['purpose']||
            ($rule['version']??null)!==$pending['version']||
            ($rule['form_sha256']??null)!==$pending['form_sha256'])
            return new \WP_Error('guest_consent_policy_changed');
        $forms=$wpdb->prefix.'fluentform_forms';
        $sourceForm=$wpdb->get_var($wpdb->prepare(
            "SELECT form_fields FROM $forms WHERE id=%d",$formId
        ));
        if(!is_string($sourceForm)||
            !hash_equals($pending['form_sha256'],hash('sha256',$sourceForm)))
            return new \WP_Error('guest_consent_form_changed');
        $submissions=$wpdb->prefix.'fluentform_submissions';
        $entry=$wpdb->get_row($wpdb->prepare(
            "SELECT user_id,status,response FROM $submissions
             WHERE id=%d AND form_id=%d",
            (int)$pending['submission_id'],$formId
        ),ARRAY_A);
        $answers=is_array($entry)?json_decode((string)$entry['response'],true):null;
        $config=get_option('commerce_guest_consent_config',[]);
        $emailField=is_array($config)?($config['email_fields'][$formId]??null):null;
        $email=is_array($answers)&&is_string($emailField)?($answers[$emailField]??null):null;
        if(!is_array($entry)||(int)$entry['user_id']!==0||
            in_array($entry['status'],['spam','trashed','deleted'],true)||
            !is_array($answers)||
            !(($answers['marketing_optin']??null)==='yes'||
                ($answers['marketing_optin']??null)===['yes'])||
            !is_string($email)||!is_email($email)||
            !hash_equals($pending['email_hmac'],
                self::key(strtolower(trim($email)),'email')))
            return new \WP_Error('guest_consent_original_submission_changed');
        $changed=$wpdb->query($wpdb->prepare(
            "UPDATE $t SET state='verified_email',verified_utc=%s,
              token_hmac=%s WHERE token_hmac=%s AND state='pending'
              AND expires_utc>%s",
            $now,self::key('consumed:'.$token,'challenge'),$hash,$now
        ));
        if($changed!==1)return new \WP_Error('guest_consent_token_unavailable');
        return ['verified_email'=>true,
            'marketing_crm_subscription'=>false,
            'message'=>'Email verified; subscription still requires approved CRM action'];
    }
    public static function bootstrap():void
    {
        add_action('fluentform/submission_inserted',
            [self::class,'fromOriginalSubmission'],170,3);
        add_action('rest_api_init',[self::class,'routes']);
    }
    public static function routes():void
    {
        register_rest_route('commerce-composition/v1','/guest-consent/confirm',[
            'methods'=>\WP_REST_Server::CREATABLE,
            'callback'=>[self::class,'restConfirm'],
            'permission_callback'=>'__return_true',
            'args'=>['token'=>['type'=>'string','required'=>true]]
        ]);
    }
    public static function restConfirm(\WP_REST_Request $request):\WP_REST_Response|\WP_Error
    {
        $result=self::confirm((string)$request['token']);
        if(is_wp_error($result))return new \WP_Error('guest_consent_unavailable',
            'The confirmation code is invalid or expired',['status'=>400]);
        $response=new \WP_REST_Response($result,200);
        $response->header('Cache-Control','private, no-store');
        return $response;
    }
}
