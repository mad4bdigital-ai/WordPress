<?php
/**
 * Plugin Name: MAD4B Optional Company Authorization (Company Site)
 * Description: Single-use owner consent by enrolled WordPress master_admin; does not confer WordPress write authority.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;
if(!class_exists('MAD4B_Optional_Company_Authorization_Site_V1',false)){
final class MAD4B_Optional_Company_Authorization_Site_V1 {
    const STORE='mad4b_optional_company_authorization_site_v1';
    const CONTRACT='mad4b.company.optional-authorization.site.v1';
    const PAGE='mad4b-company-authorization';
    private static function scoped($uuid,$origin):bool {
        if(wp_get_environment_type()!=='staging'
           ||!class_exists('MAD4B_SCP_Site_Profile',false))return false;
        $profile=MAD4B_SCP_Site_Profile::profile();
        return is_array($profile)
          &&($profile['site_uuid']??'')===$uuid
          &&untrailingslashit(home_url('/'))===$origin
          &&($profile['environment']??'')==='staging'
          &&MAD4B_SCP_Site_Profile::site_urls_match_enrollment();
    }
    private static function user($id,$emailSha=null){
        $user=get_userdata((int)$id);
        if(!$user||!in_array('master_admin',(array)$user->roles,true)
            ||!user_can($user,'read')||!is_email($user->user_email))return null;
        if($emailSha!==null&&!hash_equals((string)$emailSha,
            hash('sha256',strtolower(trim($user->user_email)))))return null;
        return $user;
    }

    /**
     * Owner consent is authority to START the original OAuth + grant flow,
     * never permission to fabricate an OAuth token or issue DB grants via CLI.
     * The ChatGPT public client gets tokens exclusively through the site's
     * original S256-PKCE token endpoint, bound to an enrolled OAuth admin.
     */
    private static function exact_oauth_user():int {
        if(!class_exists('MAD4B_SCP_Site_Profile',false)
          ||!class_exists('MAD4B_SCP_Policy',false))return 0;
        $ids=array_values(array_unique(array_map('absint',
          (array)MAD4B_SCP_Site_Profile::oauth_user_ids())));
        if(count($ids)!==1 ||$ids[0]<1
          ||!MAD4B_SCP_Site_Profile::user_is_enrolled($ids[0])
          ||!MAD4B_SCP_Policy::can_connect_user($ids[0]))return 0;
        return $ids[0];
    }
    private static function oauth_issuance_proof(array $row):array {
        $userId=(int)($row['oauth_user_id']??0);
        $approvedAt=(string)($row['decision_at']??'');
        if($userId<1||$userId!==self::exact_oauth_user()
          ||!in_array((string)($row['state']??''),array('APPROVED'),true)
          ||!class_exists('MAD4B_SCP_Local_OAuth_Store',false)
          ||!MAD4B_SCP_Local_OAuth_Store::is_ready()
          ||!class_exists('MAD4B_SCP_Local_OAuth_Server',false)
          ||!class_exists('MAD4B_SCP_OAuth_Resource_Bridge',false)
          ||!class_exists('MAD4B_SCP_ChatGPT_Auto_Binding',false))
            return array('token_issued'=>false,'reason'=>'oauth_identity_not_enrolled_or_unavailable');
        $stamp=strtotime($approvedAt);
        if(!$stamp ||$stamp>time() ||time()-$stamp>86400)
            return array('token_issued'=>false,'reason'=>'owner_approval_expired_for_new_oauth_issuance');
        global $wpdb;
        $t=MAD4B_SCP_Local_OAuth_Store::tables()['refresh_tokens'];
        $client=MAD4B_SCP_ChatGPT_Auto_Binding::CHATGPT_CLIENT_ID;
        $resource=MAD4B_SCP_OAuth_Resource_Bridge::resource_identifier();
        // Only site-bound, current, nonrevoked refresh families issued
        // AFTER this owner approval; never read/expose token hashes or values.
        $records=$wpdb->get_results($wpdb->prepare(
          "SELECT scope,created_at FROM {$t} WHERE site_uuid=%s AND wp_user_id=%d
          AND client_id=%s AND resource=%s AND revoked_at IS NULL
          AND used_at IS NULL AND expires_at>UTC_TIMESTAMP()
          AND created_at>=%s ORDER BY id DESC LIMIT 20",
          $row['site_uuid'],$userId,$client,$resource,
          gmdate('Y-m-d H:i:s',$stamp)),ARRAY_A);
        $requiresStepUp=in_array($row['purpose'],array('write_grants','adaptive_intake','company_configuration','provider_connection'),true);
        foreach((array)$records as $record){
            $scopes=preg_split('/\\s+/',trim((string)($record['scope']??'')));
            if(in_array('mad4b:read',$scopes,true)
              &&(!$requiresStepUp
               ||in_array(MAD4B_SCP_OAuth_Resource_Bridge::AUTHORITY_STEP_UP_SCOPE,$scopes,true))){
                return array('token_issued'=>true,'reason'=>'exact_chatgpt_pkce_token_issued',
                  'client'=>'chatgpt_cimd','subject_user_matches'=>true,'issued_at'=>$record['created_at']);
            }
        }
        return array('token_issued'=>false,
          'reason'=>$requiresStepUp?'waiting_client_pkce_with_step_up':'waiting_client_pkce_authorization');
    }
    private static function activation(array $row):array {
        if(($row['state']??'')!=='APPROVED')return array('state'=>'NOT_APPROVED',
          'oauth_token_issued'=>false,'exact_grants_ready'=>false);
        $oauth=self::oauth_issuance_proof($row);
        $token=!empty($oauth['token_issued']);
        $grants=false;$grantBlocker='WAITING_ORIGINAL_WRITE_AUTHORITY';
        if($token
          &&class_exists('MAD4B_SCP_Staging_Write_Authority',false)
          &&class_exists('MAD4B_SCP_Staging_Write_Grant_Reconciliation',false)){
            $plan=MAD4B_SCP_Staging_Write_Authority::reconciliation_plan();
            $binding=MAD4B_SCP_Staging_Write_Authority::candidate_binding_status();
            $grants=is_array($plan)
               &&(int)($plan['exact_grants_missing_count']??-1)===0
               &&(int)($plan['unreviewed_stale_allow_grants_count']??-1)===0
               &&!empty($binding['match'])
               &&MAD4B_SCP_Staging_Write_Authority::effective();
            if($grants)$grantBlocker='';
        }
        return array(
          'state'=>!$token?'WAITING_CHATGPT_PKCE_OAUTH':($grants?'READY':'WAITING_ORIGINAL_GRANT_CONVERGENCE'),
          'oauth_token_issued'=>$token,'oauth_status'=>$oauth['reason'],
          'exact_grants_ready'=>$grants,'grant_blocker'=>$grantBlocker,
          'requires_original_oauth_authorization_code_and_pkce'=>true,
          'requires_original_staging_write_reconciliation'=>true,
          'original_wordpress_grants_not_issued_by_email'=>true,
          'bearer_token_returned_in_readback'=>false,
          'optional'=>true);
    }
    public static function entries():array {
        $v=get_option(self::STORE,array());return is_array($v)?$v:array();
    }
    public static function snapshot($id) {
        $v=self::entries();return is_array($v[$id]??null)?$v[$id]:null;
    }
    private static function err($reason) {
        return array('ok'=>false,'reason'=>$reason,'optional'=>true,
            'write_authorization_granted'=>false);
    }
    /** Trusted host, through enrolled pinned SSH + site identity check only. */
    public static function prepare(array $p) {
        if(!defined('WP_CLI')||!WP_CLI)return self::err('original_host_required');
        $id=(string)($p['id']??'');$siteUuid=(string)($p['site_uuid']??'');
        $origin=(string)($p['origin']??'');$hash=(string)($p['token_hash']??'');
        $company=(string)($p['company']??'');$userId=$p['owner_user_id']??null;
        $purpose=(string)($p['purpose']??'');$expires=$p['expires_epoch']??null;
        if(!preg_match('/^[a-f0-9-]{36}$/D',$id)||!wp_is_uuid($siteUuid)
          ||!preg_match('/^[a-f0-9]{64}$/D',$hash)
          ||!preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$company)
          ||!in_array($purpose,array('oauth_reconnect','write_grants','adaptive_intake','company_configuration','provider_connection'),true)
          ||!is_int($userId)||!is_int($expires)
          ||$expires<=time()+300||$expires>time()+86400
          ||!self::scoped($siteUuid,$origin))return self::err('target_identity_or_payload_invalid');
        $user=self::user($userId);
        if(!$user)return self::err('selected_user_not_master_admin');
        $rows=self::entries();
        if(count($rows)>=250)return self::err('capacity');
        if(isset($rows[$id])){
            $current=$rows[$id];
            return ($current['site_uuid']??null)===$siteUuid
              &&($current['token_hash']??null)===$hash
              &&(int)($current['owner_user_id']??0)===$userId
              ?array('ok'=>true,'state'=>'ALREADY_REGISTERED','owner_user_id'=>$userId,
                 'owner_email'=>$user->user_email,'owner_role_verified'=>true,'site_uuid'=>$siteUuid)
              :self::err('request_id_reuse_conflict');
        }
        $oauthUserId=self::exact_oauth_user();
        $rows[$id]=array('contract'=>self::CONTRACT,'id'=>$id,'company'=>$company,
           'environment'=>'staging','site_uuid'=>$siteUuid,'origin'=>$origin,
           'purpose'=>$purpose,'owner_user_id'=>$userId,
           'oauth_user_id'=>$oauthUserId,
           'owner_email_hash'=>hash('sha256',strtolower(trim($user->user_email))),
           'token_hash'=>$hash,'state'=>'AWAITING_OWNER',
           'created_at'=>gmdate('c'),'expires_epoch'=>$expires,
           'decision_at'=>null,'decision_user_id'=>null,'receipt_digest'=>null,
           'activation_state'=>'NOT_APPROVED',
           'write_authorization_granted'=>false,'optional'=>true);
        if(!update_option(self::STORE,$rows,false)&&self::entries()!==$rows)
            return self::err('site_save_failed');
        return array('ok'=>true,'state'=>'REGISTERED','owner_user_id'=>$userId,
            'owner_email'=>$user->user_email,'owner_role_verified'=>true,'site_uuid'=>$siteUuid);
    }
    /** Exact one-use verified owner consent; never grant/revoke any NHI, OAuth or WP capabilities. */
    public static function decide($id,$token,$decision) {
        if(!is_user_logged_in()||!in_array($decision,array('approve','decline'),true))
          return self::err('invalid_decision_or_session');
        if(!preg_match('/^[a-f0-9-]{36}$/D',(string)$id)
          ||!is_string($token)||strlen($token)<32||strlen($token)>128)
          return self::err('token_format_invalid');
        $rows=self::entries();$row=$rows[$id]??null;
        if(!is_array($row)||($row['state']??'')!=='AWAITING_OWNER')
          return self::err('not_pending');
        if(time()>=(int)$row['expires_epoch']||!self::scoped($row['site_uuid'],$row['origin']))
          return self::err('expired_or_site_changed');
        $owner=self::user((int)$row['owner_user_id'],$row['owner_email_hash']);
        if(!$owner||get_current_user_id()!==(int)$owner->ID)
          return self::err('exact_master_admin_required');
        if(!hash_equals($row['token_hash'],hash('sha256',$token)))
          return self::err('token_mismatch');
        global $wpdb;
        $lock='mad4b:company:authorization:'.get_current_blog_id();
        if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,3)',$lock))!==1)
          return self::err('busy');
        try{
            $latest=self::entries();$current=$latest[$id]??null;
            if(!is_array($current)||($current['state']??'')!=='AWAITING_OWNER'
                ||($current['token_hash']??'')!==$row['token_hash'])
                return self::err('state_changed');
            $new=$decision==='approve'?'APPROVED':'DECLINED';
            $digest=hash('sha256',wp_json_encode(array('id'=>$id,'site_uuid'=>$row['site_uuid'],
                'owner_user_id'=>$owner->ID,'decision'=>$new,'created_at'=>$row['created_at'],
                'token_hash'=>$row['token_hash'])));
            $latest[$id]=array_merge($current,array('state'=>$new,
                'decision_at'=>gmdate('c'),'decision_user_id'=>$owner->ID,
                'receipt_digest'=>$digest,
                'activation_state'=>$decision==='approve'?'WAITING_CHATGPT_PKCE_OAUTH':'DECLINED'));
            if(!update_option(self::STORE,$latest,false)&&self::entries()!==$latest)
                return self::err('decision_not_persisted');
            return array('ok'=>true,'state'=>$new,'request_id'=>$id,
                'receipt_digest'=>$digest,'optional'=>true,
                'original_write_grants_created'=>false,
                'oauth_connection_reauthorized'=>false,
                'activation_state'=>$decision==='approve'?'WAITING_CHATGPT_PKCE_OAUTH':'DECLINED',
                'original_313_receipt_issued'=>false);
        }finally{
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));
        }
    }
    public static function owner_page() {
        $id=sanitize_text_field((string)($_GET['request']??''));
        $token=(string)($_GET['code']??'');
        $row=self::snapshot($id);
        $owner=is_array($row)?self::user((int)($row['owner_user_id']??0),$row['owner_email_hash']??null):null;
        if(!$row||!$owner||$owner->ID!==get_current_user_id()
          ||!self::scoped($row['site_uuid'],$row['origin'])
          ||time()>=(int)$row['expires_epoch']
          ||!is_string($token)||!hash_equals((string)$row['token_hash'],hash('sha256',$token)))
          wp_die('This optional company authorization link is invalid, expired or belongs to another administrator.','Authorization unavailable',array('response'=>403));
        header('Referrer-Policy: no-referrer');
        nocache_headers();
        echo '<div class="wrap"><h1>Company authorization request</h1>';
        echo '<p><strong>'.esc_html($row['company']).'</strong> / '.esc_html($row['environment']).'</p>';
        echo '<p>Purpose: '.esc_html(str_replace('_',' ',$row['purpose'])).'</p>';
        echo '<p>Optional request from MAD4B Central. Declining or ignoring this request does not block existing VPS Client or Company Manager operations. Approval starts the original ChatGPT OAuth connection and exact write-grant reconciliation when authorized. OAuth tokens are issued only through the official client PKCE exchange, never through email.</p>';
        echo '<p>Expires: '.esc_html(gmdate('Y-m-d H:i \U\T\C',$row['expires_epoch'])).'</p>';
        if($row['state']==='AWAITING_OWNER'){
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
            echo '<input type="hidden" name="action" value="mad4b_optional_company_decide">';
            echo '<input type="hidden" name="request" value="'.esc_attr($id).'">';
            echo '<input type="hidden" name="code" value="'.esc_attr($token).'">';
            wp_nonce_field('mad4b_company_optional_decide_'.$id);
            echo '<button name="decision" value="approve" class="button button-primary">Approve request</button> ';
            echo '<button name="decision" value="decline" class="button">Decline</button></form>';
        }else echo '<p>Status: '.esc_html($row['state']).'</p>';
        echo '</div><script>if(history.replaceState){history.replaceState(null,"",location.pathname+"?page=mad4b-company-authorization&request="+encodeURIComponent('.wp_json_encode($id).'));}</script>';
    }
    public static function owner_post() {
        if(!is_user_logged_in())wp_die('Authenticated company administrator required.', 'Authorization required', array('response'=>403));
        $id=sanitize_text_field((string)($_POST['request']??''));
        check_admin_referer('mad4b_company_optional_decide_'.$id);
        $result=self::decide($id,(string)($_POST['code']??''),(string)($_POST['decision']??''));
        if(empty($result['ok']))wp_die('Company request was not accepted: '.esc_html($result['reason']??'invalid'), 'Request rejected',array('response'=>403));
        nocache_headers();
        echo '<h2>Decision recorded: '.esc_html($result['state']).'</h2>';
        echo '<p>The authorization request is approved. Next, complete the registered ChatGPT OAuth connection for this company. The Agent can then reconcile exact scoped write grants through the original authenticated MAD4B Write Authority path; the email cannot mint tokens by itself.</p>';
    }
    public static function readback($id) {
        if(!defined('WP_CLI')||!WP_CLI)return self::err('original_host_required');
        $r=self::snapshot($id);
        if(!$r)return self::err('not_found');
        if(!self::scoped($r['site_uuid'],$r['origin']))return self::err('site_scope_changed');
        $user=self::user($r['owner_user_id'],$r['owner_email_hash']);
        if(!$user)return self::err('owner_role_changed');
        return array('ok'=>true,'id'=>$id,'state'=>$r['state'],
           'activation'=>self::activation($r),
           'company'=>$r['company'],'site_uuid'=>$r['site_uuid'],
           'environment'=>$r['environment'],'origin'=>$r['origin'],
           'purpose'=>$r['purpose'],'owner_user_id'=>$r['owner_user_id'],
           'receipt_digest'=>$r['receipt_digest'],'decision_user_id'=>$r['decision_user_id'],
           'expires_epoch'=>$r['expires_epoch'],'optional'=>true,
           'original_write_grants_created'=>false,'original_313_receipt_issued'=>false);
    }
    public static function boot() {
        if(wp_get_environment_type()!=='staging')return;
        add_action('admin_menu',static function(){
            add_submenu_page(null,'Company Authorization','Company Authorization','read',
                self::PAGE,array(__CLASS__,'owner_page'));
        },35);
        add_action('admin_post_mad4b_optional_company_decide',array(__CLASS__,'owner_post'));
    }
}
MAD4B_Optional_Company_Authorization_Site_V1::boot();
}
