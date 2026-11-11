<?php
/**
 * Plugin Name: MAD4B Configuration Jobs
 * Description: Plugin-neutral operational alerts and non-executing configuration tasks.
 * Version: 0.3.0
 */
defined('ABSPATH') || exit;
require_once __DIR__.'/mad4b-configuration-jobs/endpoints.php';
require_once __DIR__.'/mad4b-configuration-jobs/providers.php';
require_once __DIR__.'/mad4b-configuration-jobs/boards.php';
require_once __DIR__.'/mad4b-configuration-jobs/routing.php';
final class MAD4B_Configuration_Jobs {
    use MAD4B_Configuration_Jobs_Endpoints;
    const CONTRACT = 'mad4b.configuration-jobs.v1';
    const STORE = 'mad4b_configuration_alerts_v1';
    const CPT = 'mad4b_config_job';
    const MENU = 'mad4b-configuration-jobs';
    const CAP = 'mad4b_manage_company_operations';
    const MAX_ALERTS = 240;

    public static function boot(): void {
        add_action('init', [__CLASS__, 'register_type'], 11);
        add_action('admin_menu', [__CLASS__, 'menu'], 90);
        add_action('admin_bar_menu', [__CLASS__, 'admin_bar'], 97);
        add_action('admin_notices', [__CLASS__, 'dashboard_notice'], 90);
        add_action('admin_post_mad4b_config_scan', [__CLASS__, 'admin_scan']);
        add_action('admin_post_mad4b_config_promote', [__CLASS__, 'admin_promote']);
        add_action('admin_post_mad4b_config_progress', [__CLASS__, 'admin_progress']);
        add_action('admin_init', [__CLASS__, 'schedule_if_missing'], 90);
        add_action('mad4b_configuration_jobs_scan', [__CLASS__, 'scan']);
        add_action('mad4b/configuration_jobs/report', [__CLASS__, 'ingest_event'], 10, 1);
        add_action('wp_mail_failed', [__CLASS__, 'mail_failure'], 10, 1);
        add_action('action_scheduler_failed_action', [__CLASS__, 'action_failure'], 10, 2);
        add_action('rest_api_init', [__CLASS__, 'routes']);
        add_action('wp_abilities_api_init',[__CLASS__,'register_abilities'],45);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_notice_bridge']);
    }
    public static function register_abilities():void {
        if(!function_exists('wp_register_ability'))return;
        $abilities=[
            'mad4b/configuration-alerts-list'=>['Configuration alerts and plugin notices',true,[
                'limit'=>['type'=>'integer','minimum'=>1,'maximum'=>240,'default'=>100]]],
            'mad4b/configuration-jobs-list'=>['Configuration task records',true,[
                'limit'=>['type'=>'integer','minimum'=>1,'maximum'=>100,'default'=>50]]],
            'mad4b/configuration-job-promote'=>['Convert an alert into a non-executing configuration job',false,[
                'id'=>['type'=>'string','minLength'=>64,'maxLength'=>64,'pattern'=>'^[a-f0-9]{64}$']]],
        ];
        foreach($abilities as $name=>$cfg){
            if(function_exists('wp_has_ability') && wp_has_ability($name))continue;
            $readonly=$cfg[1];
            $execute=static function($input)use($name){
                $input=is_array($input)?$input:[];
                if($name==='mad4b/configuration-alerts-list')return [
                    'contract'=>self::CONTRACT,'summary'=>self::summary(),
                    'alerts'=>self::issue_list((int)($input['limit']??100))];
                if($name==='mad4b/configuration-jobs-list'){
                    $ids=get_posts(['post_type'=>self::CPT,'post_status'=>'private',
                        'posts_per_page'=>max(1,min(100,(int)($input['limit']??50))),
                        'fields'=>'ids','orderby'=>'date','order'=>'DESC','suppress_filters'=>true]);
                    return ['contract'=>self::CONTRACT,'jobs'=>array_map(static fn($id)=>[
                        'id'=>(int)$id,'state'=>get_post_meta($id,'_mad4b_configuration_state',true),
                        'alert_id'=>(string)get_post_meta($id,'_mad4b_configuration_alert',true)],$ids)];
                }
                return self::promote((string)($input['id']??''));
            };
            wp_register_ability($name,[
                'label'=>$cfg[0],'description'=>$cfg[0].' for this exact WordPress tenant.',
                'category'=>$readonly?'mad4b-read':'mad4b-write',
                'execute_callback'=>$execute,
                'permission_callback'=>static fn()=>current_user_can(self::CAP),
                'input_schema'=>['type'=>'object','properties'=>$cfg[2],
                    'required'=>$readonly?[]:['id'],'additionalProperties'=>false],
                'output_schema'=>['type'=>'object','additionalProperties'=>true],
                'meta'=>['public'=>false,'show_in_rest'=>false,
                    'mcp'=>['public'=>false,'type'=>'tool','surface'=>$readonly?'read':'write'],
                    'annotations'=>['readonly'=>$readonly,'destructive'=>false,'idempotent'=>true]],
            ]);
        }
    }
    public static function register_type(): void {
        register_post_type(self::CPT, [
            'label'=>'Configuration Jobs', 'public'=>false, 'show_ui'=>false,
            'show_in_rest'=>false, 'exclude_from_search'=>true,
            'supports'=>['title','editor'], 'capability_type'=>'post',
        ]);
    }
    private static function tenant(): string {
        if(class_exists('MAD4B_SCP_Site_Profile') && method_exists('MAD4B_SCP_Site_Profile','site_uuid')) {
            $uuid=(string)MAD4B_SCP_Site_Profile::site_uuid();if($uuid!=='')return $uuid;
        }
        $profile=get_option('mad4b_scp_site_profile_v2',[]);
        $site=is_array($profile)?strtolower((string)($profile['site_uuid']??'')):'';
        if(preg_match('/^[a-f0-9-]{36}$/D',$site))return $site;
        return hash('sha256',strtolower(home_url('/')).'|'.get_current_blog_id());
    }
    private static function normalize(array $input): array {
        $provider=sanitize_key((string)($input['provider']??'wordpress'));
        $code=sanitize_key((string)($input['code']??''));
        $scope=sanitize_key((string)($input['scope']??'site'));
        $severity=sanitize_key((string)($input['severity']??'medium'));
        $source=sanitize_key((string)($input['source']??'provider'));
        if(!in_array($severity,['critical','high','medium','low'],true))$severity='medium';
        if(!in_array($source,['probe','hook','admin_notice','provider'],true))$source='provider';
        if(!$provider || !$code || strlen($code)>85 || strlen($provider)>75 || strlen($scope)>90) return [];
        $short=static fn(string $text,int $limit)=>function_exists('mb_substr')?mb_substr($text,0,$limit):substr($text,0,$limit);
        $label=$short(sanitize_text_field((string)($input['label']??$code)),180);
        $guidance=$short(sanitize_text_field((string)($input['guidance']??'Inspect original provider status, correct the configuration and verify the live result.')),350);
        // Never persist raw IPs, email addresses, credentials, stack traces or HTML from notices.
        $scrub=static function(string $v):string {
            $v=preg_replace('~https?://[^\s]+~i','[link]', $v);
            $v=preg_replace('/[\w.+-]+@[\w.-]+\.[A-Za-z]{2,}/','[email]', $v);
            $v=preg_replace('/\b(?:\d{1,3}\.){3}\d{1,3}\b/','[ip]', $v);
            $v=preg_replace('/\b(?:token|secret|api[_-]?key|password)\s*[:=]\s*\S+/i','[redacted]', $v);
            return $v;
        };
        $label=$scrub($label);$guidance=$scrub($guidance);
        $id=hash('sha256',self::tenant().'|'.$provider.'|'.$code.'|'.$scope);
        $routing=MAD4B_Job_Classification_Router::classify([
            'origin'=>'configuration','provider'=>$provider,'code'=>$code,
            'scope'=>$scope,'source'=>$source,'label'=>$label]);
        return ['id'=>$id,'family'=>'configuration','site_uuid'=>self::tenant(),
            'routing'=>$routing,
            'provider'=>$provider,'code'=>$code,'scope'=>$scope,'severity'=>$severity,
            'source'=>$source,'label'=>$label,'guidance'=>$guidance,
            'evidence_level'=>$source==='admin_notice'?'observed_unverified':'provider_signal',
            'auto_execute'=>false];
    }
    private static function lock(callable $work) {
        global $wpdb;
        $name='mad4b_config_alerts_'.get_current_blog_id();
        if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,1)',$name))!==1)
            return new WP_Error('configuration_alert_ledger_busy','Configuration alert ledger busy');
        try{return $work();}finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$name));}
    }
    public static function ingest_event($input) {
        if(!is_array($input))return new WP_Error('configuration_event_invalid');
        $row=self::normalize($input);if(!$row)return new WP_Error('configuration_event_invalid');
        return self::lock(static function()use($row){
            $saved=get_option(self::STORE,[]);if(!is_array($saved))$saved=[];
            $key=$row['id'];$was=$saved[$key]??[];
            $now=gmdate('c');
            $saved[$key]=array_merge($row,[
                'state'=>'open','first_seen'=>$was['first_seen']??$now,'last_seen'=>$now,
                'observations'=>min(100000,1+(int)($was['observations']??0)),
                'job_id'=>absint($was['job_id']??0),
                'reopens'=>($was['state']??'')==='resolved'?(int)($was['reopens']??0)+1:(int)($was['reopens']??0),
            ]);
            if(count($saved)>self::MAX_ALERTS){
                uasort($saved,static fn($a,$b)=>strcmp((string)($a['last_seen']??''),(string)($b['last_seen']??'')));
                // Preserve open incidents that may already have linked jobs.
                foreach(array_keys($saved) as $oldKey){
                    if(count($saved)<=self::MAX_ALERTS)break;
                    if(($saved[$oldKey]['state']??'')==='resolved' && empty($saved[$oldKey]['job_id']))unset($saved[$oldKey]);
                }
                if(count($saved)>self::MAX_ALERTS)return new WP_Error('configuration_ledger_capacity_reached');
            }
            update_option(self::STORE,$saved,false);
            return ['status'=>empty($was)?'created':'updated','id'=>$key,'job_id'=>(int)$saved[$key]['job_id']];
        });
    }
    public static function mail_failure($failure): void {
        self::ingest_event(['provider'=>'wordpress-mail','code'=>'wp_mail_failed',
            'scope'=>'transport','source'=>'hook','severity'=>'high',
            'label'=>'WordPress or SMTP email delivery failed',
            'guidance'=>'Check native mail/Fluent SMTP diagnostics and confirm delivery with a fresh test. Do not save recipients or email content.']);
    }
    public static function action_failure($action_id, $error=null):void {
        $hook='unknown';
        if(class_exists('ActionScheduler') && function_exists('as_get_scheduled_actions')){
            try{$act=\ActionScheduler::store()->fetch_action((int)$action_id);
                if(is_object($act)&&method_exists($act,'get_hook'))$hook=sanitize_key((string)$act->get_hook());
            }catch(Throwable $e){}
        }
        self::ingest_event(['provider'=>'action-scheduler','code'=>'failed_action',
            'scope'=>substr($hook,0,65),'source'=>'hook','severity'=>'high',
            'label'=>'Scheduled action failed: '.$hook,
            'guidance'=>'Inspect Action Scheduler failure details, provider dependency and recent logs; verify the next run.']);
    }
    /** Extensible read-only observations. Other plugins can contribute a provider adapter. */
    public static function discover():array {
        $signals=[];
        if(class_exists('WooCommerce') && function_exists('WC') && WC()->integrations){
            $adapter=WC()->integrations->get_integration('maxmind_geolocation');
            if($adapter && method_exists($adapter,'get_database_service')){
                $service=$adapter->get_database_service();$db=$service?$service->get_database_path():'';
                if(!$db || !is_file($db) || !is_readable($db)){
                    $signals[]=['provider'=>'woocommerce','code'=>'maxmind_database_missing',
                      'scope'=>'geoip','source'=>'probe','severity'=>'high',
                      'label'=>'WooCommerce MaxMind local GeoLite2 database unavailable',
                      'guidance'=>'Check the WooCommerce MaxMind integration, local database path and authorized update source.'];
                }elseif(time()-filemtime($db)>28*DAY_IN_SECONDS){
                    $signals[]=['provider'=>'woocommerce','code'=>'maxmind_database_stale',
                      'scope'=>'geoip','source'=>'probe','severity'=>'high',
                      'label'=>'WooCommerce MaxMind GeoLite2 data exceeds freshness limit',
                      'guidance'=>'Refresh the licensed GeoLite2 database and verify location lookup without changing customer billing location.'];
                }
                // Read-only HEAD: a WooCommerce private-upload directory must not
                // serve GeoLite2 to arbitrary web clients. Run only during the
                // scheduled/operator scan, never during normal page rendering.
                if($db && is_file($db) && is_readable($db) && function_exists('wp_remote_head')) {
                    $public=set_url_scheme(content_url('/uploads/woocommerce_uploads/'.basename($db)), 'https');
                    $test=wp_remote_head($public,['timeout'=>5,'redirection'=>0,'sslverify'=>true]);
                    if(!is_wp_error($test)) {
                        $code=(int)wp_remote_retrieve_response_code($test);
                        if(in_array($code,[200,206],true)) {
                            $signals[]=['provider'=>'woocommerce','code'=>'maxmind_database_publicly_accessible',
                              'scope'=>'private-uploads','source'=>'probe','severity'=>'critical',
                              'label'=>'WooCommerce private GeoLite2 database is downloadable from public HTTP',
                              'guidance'=>'Verify that direct web access to woocommerce_uploads returns 404 or 403, while private PHP lookup still works. Do not publish database contents.'];
                        } elseif(!in_array($code,[403,404],true)) {
                            $signals[]=['provider'=>'woocommerce','code'=>'maxmind_public_access_verification_incomplete',
                              'scope'=>'private-uploads','source'=>'probe','severity'=>'medium',
                              'label'=>'MaxMind private-upload HTTP verification was inconclusive',
                              'guidance'=>'Check the trusted public HEAD response and the Nginx uploads policy.'];
                        }
                    } else {
                        $signals[]=['provider'=>'woocommerce','code'=>'maxmind_public_access_verification_incomplete',
                          'scope'=>'private-uploads','source'=>'probe','severity'=>'medium',
                          'label'=>'MaxMind private-upload HTTP verification could not complete',
                          'guidance'=>'Verify TLS, Nginx private-upload path and public HTTP availability with a bounded test.'];
                    }
                }
                // A company can receive a protected, current GeoLite2 data file
                // from a centrally licensed update provider without sharing the
                // MaxMind account secret. Accept ONLY live, site-bound proof of
                // the actual database bytes and recent licensed source renewal.
                $proof=(array)get_option('mad4b_maxmind_delegated_update_v1',[]);
                $profile=(array)get_option('mad4b_scp_site_profile_v2',[]);
                $verifiedAt=(int)($proof['verified_at']??0);
                $sourceAge=(int)($proof['source_age_seconds']??9999999);
                $verifiedDelegation=($proof['contract']??'')==='mad4b.geolite-delegated-update.v1'
                    && ($proof['mode']??'')==='source_licensed_mirror'
                    && ($proof['site_uuid']??'')===($profile['site_uuid']??'')
                    && !empty($proof['source_site_uuid'])
                    && ($proof['source_site_uuid']??'')!==($profile['site_uuid']??'')
                    && !empty($proof['origin_license_present'])
                    && !empty($proof['origin_updater_pending'])
                    && empty($proof['local_download_key_shared'])
                    && $verifiedAt<=time() && $verifiedAt>time()-2*HOUR_IN_SECONDS
                    && $sourceAge>=0 && $sourceAge<28*DAY_IN_SECONDS
                    && $db && is_readable($db)
                    && preg_match('/^[a-f0-9]{64}$/D',(string)($proof['sha256']??''))
                    && hash_equals((string)$proof['sha256'],hash_file('sha256',$db));
                if(!$adapter->get_option('license_key') && !$verifiedDelegation){
                    $signals[]=['provider'=>'woocommerce','code'=>'maxmind_license_not_configured',
                      'scope'=>'geoip-update','source'=>'probe','severity'=>'medium',
                      'label'=>'No independent MaxMind database update credential or verified delegated source',
                      'guidance'=>'Verify a recent centrally licensed GeoLite2 mirror receipt and matching local database hash, or configure a company-owned MaxMind key. Never assume a local database alone proves an active update mechanism.'];
                }
            }
        }
        global $wpdb;
        $table=$wpdb->prefix.'actionscheduler_actions';
        if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table))===$table){
            // No action args, customer data or exception trace leaves Action Scheduler.
            $failed=(int)$wpdb->get_var("SELECT COUNT(*) FROM `{$table}` WHERE status='failed' AND last_attempt_gmt > UTC_TIMESTAMP() - INTERVAL 7 DAY");
            if($failed>0){
                $signals[]=['provider'=>'action-scheduler','code'=>'failed_actions_recent',
                  'scope'=>'site','source'=>'probe','severity'=>$failed>=10?'high':'medium',
                  'label'=>'Action Scheduler has '.$failed.' failed executions in the last seven days',
                  'guidance'=>'Review failed actions by provider and retry only after resolving their cause.'];
            }
        }
        try {
            $extensions=apply_filters('mad4b/configuration_jobs/discover',$signals);
            if(!is_array($extensions))throw new RuntimeException('provider_filter_invalid');
            $signals=array_slice($extensions,0,75);
        } catch(Throwable $e) {
            // Never mark previously observed provider faults resolved on an incomplete scan.
            $GLOBALS['mad4b_configuration_discovery_incomplete']=true;
            $signals[]=['provider'=>'mad4b','code'=>'provider_filter_diagnostics_unavailable',
              'scope'=>'extensions','source'=>'probe','severity'=>'medium',
              'label'=>'Plugin health aggregation could not finish',
              'guidance'=>'Review the plugin adapter registry and retry diagnostics; earlier alerts remain open.'];
        }
        return array_values(array_filter(array_map([__CLASS__,'normalize'],$signals)));
    }
    public static function scan():array {
        $GLOBALS['mad4b_configuration_discovery_incomplete']=false;
        $found=self::discover();$seen=[];$created=0;$errors=0;
        $inconclusive=[];
        foreach($found as $signal){
            if(in_array((string)($signal['code']??''),['provider_diagnostics_unavailable','provider_filter_diagnostics_unavailable'],true))
                $inconclusive[(string)($signal['provider']??'')]=true;
            $seen[$signal['id']]=true;
            $result=self::ingest_event($signal);
            if(is_wp_error($result))$errors++;
            elseif(($result['status']??'')==='created')$created++;
        }
        $resolved=self::lock(static function()use($seen,$inconclusive){
            $rows=(array)get_option(self::STORE,[]);$count=0;
            foreach($rows as &$row){
                if(($row['source']??'')!=='probe'||($row['state']??'')!=='open')continue;
                if(!empty($GLOBALS['mad4b_configuration_discovery_incomplete']) ||
                    isset($inconclusive[(string)($row['provider']??'')]))continue;
                if(!isset($seen[$row['id']??''])){$row['state']='resolved';$row['resolved_at']=gmdate('c');$count++;}
            }unset($row);
            if($count)update_option(self::STORE,$rows,false);
            return $count;
        });
        return ['contract'=>self::CONTRACT,'discovered'=>count($found),'new'=>$created,
            'errors'=>$errors,'resolved'=>is_wp_error($resolved)?0:$resolved,
            'status'=>is_wp_error($resolved)?$resolved->get_error_code():'scanned'];
    }
    public static function schedule_if_missing():void {
        if(!self::operator() || !function_exists('as_schedule_recurring_action') || !function_exists('as_has_scheduled_action'))return;
        if(as_has_scheduled_action('mad4b_configuration_jobs_scan',[],'mad4b-configuration'))return;
        as_schedule_recurring_action(time()+300,30*MINUTE_IN_SECONDS,
            'mad4b_configuration_jobs_scan',[],'mad4b-configuration',true);
    }
    public static function summary():array {
        $rows=(array)get_option(self::STORE,[]);$s=['open'=>0,'critical'=>0,'high'=>0,'medium'=>0,'low'=>0,'resolved'=>0,'linked'=>0];
        foreach($rows as $a){$st=$a['state']??'open';if($st==='resolved'){$s['resolved']++;continue;}
            $s['open']++;$sev=$a['severity']??'medium';if(isset($s[$sev]))$s[$sev]++;
            if(!empty($a['job_id']))$s['linked']++;}
        return $s;
    }
}
MAD4B_Configuration_Jobs::boot();
