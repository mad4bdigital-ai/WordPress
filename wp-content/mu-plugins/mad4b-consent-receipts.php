<?php
/**
 * Plugin Name: MAD4B Consent Receipt Evidence
 * Description: Minimal, pseudonymous, per-company append-only record of explicit consent actions and applicable policy version.
 * Version: 0.2.4
 */
defined('ABSPATH') || exit;
if (!class_exists('MAD4B_Consent_Receipts',false)) {
final class MAD4B_Consent_Receipts {
    private const VERSION='1';
    private const CRON='mad4b_consent_receipts_purge';
    public static function table():string {
        global $wpdb;return $wpdb->prefix.'mad4b_consent_receipts';
    }
    public static function installed():bool {
        global $wpdb;
        return (string)$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',self::table()))===self::table();
    }
    public static function install():bool {
        global $wpdb;
        if(!function_exists('dbDelta'))require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $charset=$wpdb->get_charset_collate();
        $sql='CREATE TABLE '.self::table()." (
          id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
          receipt_id char(36) NOT NULL,
          event_id char(36) NOT NULL,
          analytics_allowed tinyint(1) NOT NULL DEFAULT 0,
          marketing_allowed tinyint(1) NOT NULL DEFAULT 0,
          policy_revision varchar(100) NOT NULL,
          event_proof char(64) NOT NULL,
          locale varchar(8) NOT NULL,
          event_time datetime NOT NULL,
          expire_time datetime NOT NULL,
          PRIMARY KEY  (id),
          UNIQUE KEY event_id (event_id),
          KEY receipt_id (receipt_id),
          KEY expire_time (expire_time)
        ) $charset;";
        dbDelta($sql);
        if(!self::installed())return false;
        update_option('mad4b_consent_receipts_schema_version',self::VERSION,false);
        if(!wp_next_scheduled(self::CRON))wp_schedule_event(time()+HOUR_IN_SECONDS,'daily',self::CRON);
        return true;
    }
    private static function uuid($value):bool {
        return is_string($value) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/Di',$value)===1;
    }
    public static function registerRoutes():void {
        register_rest_route('mad4b-consent/v1','/receipt',[
          'methods'=>'POST','callback'=>[self::class,'post'],
          'permission_callback'=>'__return_true',
          'args'=>[
            'receipt_id'=>['type'=>'string','required'=>true],
            'event_id'=>['type'=>'string','required'=>true],
            'policy_revision'=>['type'=>'string','required'=>true],
            'policy_proof'=>['type'=>'string','required'=>true],
            'language'=>['type'=>'string','required'=>true],
            'analytics'=>['type'=>'boolean','required'=>true],
            'marketing'=>['type'=>'boolean','required'=>true],
          ]
        ]);
    }

    private static function snapshotPolicy(string $version,string $language):void {
        $key='mad4b_consent_policy_snapshot_'.hash('sha256',$version.'|'.$language);
        if(get_option($key,null)!==null)return;
        $id=(int)get_option('wp_page_for_privacy_policy');
        if($id>0)$id=(int)apply_filters('wpml_object_id',$id,'page',false,$language);
        if($id<1||get_post_status($id)!=='publish')return;
        $post=get_post($id);
        if(!$post)return;
        $current=(string)apply_filters('wpml_current_language','en');
        if($current!==$language)do_action('wpml_switch_language',$language);
        // Store exactly the public policy wording in effect on first recorded
        // acceptance, not just a mutable page URL or shortcode reference.
        $rendered=do_shortcode($post->post_content);
        if($current!==$language)do_action('wpml_switch_language',$current);
        $content=trim(wp_strip_all_tags($rendered));
        if(mb_strlen($content)>60000)$content=mb_substr($content,0,60000);
        $settings=(array)get_option('look-and-feel',[]);
        $consentWords=[];
        foreach($settings as $k=>$v)
            if(is_string($k)&&str_starts_with($k,'consent_')
               &&str_ends_with($k,'_'.$language)&&is_scalar($v))
                 $consentWords[$k]=mb_substr((string)$v,0,800);
        add_option($key,[
            'version'=>$version,'language'=>$language,
            'privacy_page_id'=>$id,'privacy_post_modified_utc'=>$post->post_modified_gmt,
            'privacy_text'=>$content,'privacy_sha256'=>hash('sha256',$content),
            'consent_overrides'=>$consentWords,
            'captured_at_utc'=>current_time('mysql',true),
        ],'',false);
    }
    private static function originValid(\WP_REST_Request $req):bool {
        $origin=(string)$req->get_header('origin');
        $referer=(string)$req->get_header('referer');
        $site=strtolower((string)wp_parse_url(home_url('/'),PHP_URL_HOST));
        if($site==='')return false;
        $seen=$origin!==''?$origin:$referer;
        if($seen==='' || strtolower((string)wp_parse_url($seen,PHP_URL_HOST))!==$site)return false;
        $fetchSite=strtolower((string)$req->get_header('sec-fetch-site'));
        return $fetchSite==='' || in_array($fetchSite,['same-site','same-origin','none'],true);
    }
    public static function post(\WP_REST_Request $req):\WP_REST_Response|\WP_Error {
        if(!self::installed())return new \WP_Error('mad4b_consent_store_unavailable','Receipt storage unavailable',['status'=>503]);
        if(!self::originValid($req))return new \WP_Error('mad4b_consent_origin','Invalid request origin',['status'=>403]);
        if(strlen($req->get_body())>1300)return new \WP_Error('mad4b_consent_payload','Oversized consent record',['status'=>413]);
        $receipt=$req->get_param('receipt_id');
        $event=$req->get_param('event_id');
        $policy=(string)$req->get_param('policy_revision');
        $language=(string)$req->get_param('language');
        $policyProof=(string)$req->get_param('policy_proof');
        $ana=$req->get_param('analytics');
        $ads=$req->get_param('marketing');
        if(!self::uuid($receipt)||!self::uuid($event) ||
           !preg_match('/^[a-zA-Z0-9_.:|-]{3,100}$/D',$policy) ||
           !in_array($language,['en','es','ar','fr','de','it'],true) ||
           !is_bool($ana)||!is_bool($ads) ||
           !preg_match('/^[a-f0-9]{64}$/D',$policyProof) ||
           !hash_equals(hash_hmac('sha256',$policy.'|'.$language,wp_salt('auth')),$policyProof))
           return new \WP_Error('mad4b_consent_invalid','Invalid consent evidence',['status'=>400]);
        // Privacy-preserving abuse throttle. Never persist IP or browser fingerprint.
        $ip=(string)($_SERVER['REMOTE_ADDR']??'');
        $key='mad4b_crlim_'.hash_hmac('sha256',$ip.'|'.gmdate('YmdH'),wp_salt('nonce'));
        $hits=(int)get_transient($key);
        if($hits>=30)return new \WP_Error('mad4b_consent_throttled','Too many requests',['status'=>429]);
        set_transient($key,$hits+1,HOUR_IN_SECONDS);
        global $wpdb;
        $retention=class_exists('MAD4B_Consent_Settings')
            ?MAD4B_Consent_Settings::settings()['receipt_days']:365;
        $utc=current_time('mysql',true);
        $expire=gmdate('Y-m-d H:i:s',time()+$retention*DAY_IN_SECONDS);
        // Server-signed event receipt provides a tamper-evident audit trail.
        $data=[
           'receipt_id'=>strtolower($receipt),'event_id'=>strtolower($event),
           'analytics_allowed'=>$ana?1:0,'marketing_allowed'=>$ads?1:0,
           'policy_revision'=>$policy,'locale'=>$language,
           'event_time'=>$utc,'expire_time'=>$expire,
        ];
        $signed=implode('|',array_values($data));
        $data['event_proof']=hash_hmac('sha256',$signed,wp_salt('auth'));
        $ok=$wpdb->insert(self::table(),$data,['%s','%s','%d','%d','%s','%s','%s','%s','%s']);
        if($ok)self::snapshotPolicy($policy,$language);
        if(!$ok) {
           // The same event may retry after an interrupted acknowledgement.
           $known=(int)$wpdb->get_var($wpdb->prepare(
             'SELECT COUNT(*) FROM '.self::table().' WHERE event_id=%s',strtolower($event)));
           if(!$known)return new \WP_Error('mad4b_consent_store_failed','Could not record consent action',['status'=>503]);
        }
        return new \WP_REST_Response(['recorded'=>true,'event_id'=>$event],201);
    }
    public static function purge():int {
        global $wpdb;
        if(!self::installed())return 0;
        return (int)$wpdb->query($wpdb->prepare(
           'DELETE FROM '.self::table().' WHERE expire_time < %s LIMIT 5000',current_time('mysql',true)));
    }

    public static function verifyRecent():array {
        global $wpdb;
        if(!self::installed())return ['verified'=>0,'invalid'=>0];
        $rows=$wpdb->get_results('SELECT receipt_id,event_id,analytics_allowed,marketing_allowed,policy_revision,locale,event_time,expire_time,event_proof FROM '.self::table().' ORDER BY id DESC LIMIT 50',ARRAY_A);
        $ok=0;$bad=0;$legacy=0;
        foreach((array)$rows as $row){
            $stored=$row['event_proof'];unset($row['event_proof']);
            if(!is_string($stored)||$stored===''){$legacy++;continue;}
            $proof=hash_hmac('sha256',implode('|',array_values($row)),wp_salt('auth'));
            if(hash_equals($proof,$stored))$ok++;
            else $bad++;
        }
        return ['verified'=>$ok,'unsigned_legacy'=>$legacy,'invalid'=>$bad];
    }
    public static function adminMenu():void {
        add_management_page('MAD4B Consent QA','MAD4B Consent QA','manage_options',
            'mad4b-consent-qa',[self::class,'adminPage']);
    }
    public static function adminPage():void {
        if(!current_user_can('manage_options'))wp_die('Not authorized');
        $stats=self::summary();
        $proof=self::verifyRecent();
        $cfg=class_exists('MAD4B_Consent_Settings')?MAD4B_Consent_Settings::diagnostics():[];
        echo '<div class="wrap"><h1>MAD4B Consent QA</h1>';
        echo '<p>Tenant-local privacy settings, tag loading controls and anonymized consent audit. A public Google Tag Manager container cannot certify the category of every tag; review the GTM workspace/export before enabling shared-GTM partial consent.</p>';
        echo '<h2>Consent configuration</h2><table class="widefat striped"><tbody>';
        foreach($cfg as $k=>$v){
          if(is_bool($v))$v=$v?'yes':'no';
          echo '<tr><th>'.esc_html((string)$k).'</th><td>'.esc_html(is_scalar($v)?(string)$v:wp_json_encode($v)).'</td></tr>';
        }
        echo '</tbody></table><h2>Consent receipts (no names, emails or IP addresses stored)</h2><table class="widefat striped"><tbody>';
        foreach(array_merge($stats,['recent_signatures_verified'=>$proof['verified'],'old_unsigned_records'=>$proof['unsigned_legacy'],'recent_signatures_invalid'=>$proof['invalid']]) as $k=>$v)
           echo '<tr><th>'.esc_html((string)$k).'</th><td>'.esc_html((string)$v).'</td></tr>';
        echo '</tbody></table><p>Evidence records expire automatically. Public policy version changes require renewed consent. Existing checkout and payment systems are not controlled by this page.</p>';
        if(class_exists('MAD4B_GTM_Consent_Governance',false))
            MAD4B_GTM_Consent_Governance::adminInfo();
        echo '<p><a class="button button-primary" href="'.esc_url(admin_url('admin.php?page=look-and-feel')).'">Configure in Look & Feel</a></p></div>';
    }
    public static function summary():array {
        global $wpdb;
        if(!self::installed())return ['installed'=>false,'events'=>0];
        return [
           'installed'=>true,
           'events'=>(int)$wpdb->get_var('SELECT COUNT(*) FROM '.self::table()),
           'analytics_yes'=>(int)$wpdb->get_var('SELECT COUNT(*) FROM '.self::table().' WHERE analytics_allowed=1'),
           'marketing_yes'=>(int)$wpdb->get_var('SELECT COUNT(*) FROM '.self::table().' WHERE marketing_allowed=1'),
           'expired'=>(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.self::table().' WHERE expire_time < %s',current_time('mysql',true))),
           'policy_snapshots'=>(int)$wpdb->get_var(
              "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'mad4b_consent_policy_snapshot_%'"),
        ];
    }
    public static function boot():void {
        add_action('rest_api_init',[self::class,'registerRoutes']);
        add_action('admin_menu',[self::class,'adminMenu']);
        add_action(self::CRON,[self::class,'purge']);
    }
}
MAD4B_Consent_Receipts::boot();
}
