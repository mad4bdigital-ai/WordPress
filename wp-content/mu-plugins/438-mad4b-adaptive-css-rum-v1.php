<?php
/**
 * Plugin Name: MAD4B Adaptive CSS RUM
 * Version: 1.0.0
 * Description: Consent-first, site-owned aggregated Core Web Vitals. No live CSS switches.
 */
defined('ABSPATH') || exit;
if (!class_exists('MAD4B_Adaptive_CSS_RUM_V1',false)) {
final class MAD4B_Adaptive_CSS_RUM_V1 {
    const CONTRACT='mad4b.adaptive-css.rum.v1';
    const OPTION='mad4b_css_rum_v1';
    const CRON='mad4b_css_rum_health_hourly_v1';
    const ASSETS='/mad4b-adaptive-css-v1/assets/';
    private const LIMITS=[
      'lcp'=>[500,1000,1500,2000,2500,3000,4000,5000,6000,8000,10000,15000,20000,30000],
      'inp'=>[50,100,150,200,250,300,400,500,700,1000,1500,2000,3000,5000],
      'cls'=>[10,25,50,75,100,125,150,200,250,400,600,1000,2000]];
    private static function host():string {
        return strtolower((string)wp_parse_url((string)get_option('home',''),PHP_URL_HOST));
    }
    public static function site():?string {
        // Company discovery is the same validated first-party WordPress-Cron
        // site profile, not a copied list of hard-coded domains.
        if (!class_exists('MAD4B_CCSS_WPCron_Dispatch_V1',false))return null;
        $profile=MAD4B_CCSS_WPCron_Dispatch_V1::profile();
        $host=self::host();
        if (!is_array($profile)
            || ($profile['site_host']??'')!==$host
            || strtolower((string)($_SERVER['HTTP_HOST']??$host))!==$host
            || ($profile['environment']??'')!==wp_get_environment_type()
            || !preg_match('/^[a-z][a-z0-9_-]{1,63}$/D',
                          (string)($profile['site_id']??'')))return null;
        return (string)$profile['site_id'];
    }
    public static function policy():array {
        $p=get_option(self::OPTION,[]);
        if (!is_array($p)||!self::site())return ['mode'=>'off','sample_bps'=>0];
        $mode=$p['mode']??'off';$bps=$p['sample_bps']??0;
        if (!in_array($mode,['off','observe'],true)||!is_int($bps)
            || $bps<0||$bps>1000)return ['mode'=>'off','sample_bps'=>0];
        return ['mode'=>$mode,'sample_bps'=>$mode==='observe'?$bps:0];
    }
    private static function public_page():bool {
        if (is_admin()||wp_doing_ajax()||is_user_logged_in()||is_feed()
            || is_404()||is_search()||(defined('REST_REQUEST')&&REST_REQUEST)
            || (string)($_SERVER['REQUEST_METHOD']??'GET')!=='GET'
            || !empty($_SERVER['QUERY_STRING'])
            || (function_exists('is_preview')&&is_preview()))return false;
        foreach (['is_checkout','is_cart','is_account_page'] as $fun)
            if (function_exists($fun)&&$fun())return false;
        if (is_singular(['page','post','tours-and-activities','properties'])) {
            $p=get_post((int)get_queried_object_id());
            return is_object($p)&&($p->post_status??'')==='publish'
                &&($p->post_password??'')==='';
        }
        return is_tax(['destinations','tour-types','property-types','tour-categories'])
            ||is_category()||is_post_type_archive(['tours-and-activities','properties']);
    }
    private static function scope():?array {
        $site=self::site();
        if (!$site||!self::public_page())return null;
        $path=(string)wp_parse_url((string)($_SERVER['REQUEST_URI']??'/'),PHP_URL_PATH);
        if (!$path||strlen($path)>512||str_contains($path,'..'))return null;
        $language=(string)apply_filters('wpml_current_language','en');
        if (!preg_match('/^[a-z]{2}$/D',$language))return null;
        $device=(string)($_SERVER['MAD4B_TRUSTED_DEVICE_CLASS']??'');
        if (!in_array($device,['mobile','desktop'],true))return null;
        $currency=function_exists('get_woocommerce_currency')
            ?(string)get_woocommerce_currency():'NONE';
        if (!preg_match('/^[A-Z]{3}$|^NONE$/D',$currency))return null;
        $market='static';
        if (function_exists('mad4b_render_query_market_identity')) {
            $identity=mad4b_render_query_market_identity();
            if (!is_array($identity)||($identity['currency']??'')!==$currency)return null;
            $market=(string)($identity['country']??'');
            if ($market!=='base'&&!preg_match('/^[A-Z]{2}$/D',$market))return null;
        }
        $epoch=(int)get_option('mad4b_css_ai_epoch_v1',0);
        if ($epoch<0||$epoch>1000000000)return null;
        $id=(int)get_queried_object_id();
        $modified=$id>0?(string)get_post_field('post_modified_gmt',$id):'';
        $route=hash('sha256',self::host().'|'.$language.'|'.$path.'|'.$market.'|'.$currency);
        // Source epochs are not enough on JetEngine listing routes. An
        // Elementor/listing global generation change makes the previous
        // aggregate incompatible even if the individual post is untouched.
        $listingGeneration=(int)get_option('mad4b_ccss_listing_generation_v1',0);
        $globalGeneration=(int)get_option('mad4b_ccss_global_generation_v1',0);
        if ($listingGeneration<0||$listingGeneration>1000000000
            ||$globalGeneration<0||$globalGeneration>1000000000)return null;
        $variant=hash('sha256',implode('|',['v2',$route,$device,$epoch,
              $globalGeneration,$listingGeneration,$modified]));
        return [$site,$route,$variant,$device,(string)$epoch];
    }
    public static function signed_proof(array $fields,int $created):string {
        $head=implode('|',[...$fields,(string)$created]);
        return rtrim(strtr(base64_encode($head.'|'.
            hash_hmac('sha256',$head,wp_salt('secure_auth'))),'+/','-_'),'=');
    }
    public static function verify_proof($token):?array {
        if (!is_string($token)||strlen($token)<180||strlen($token)>450
            ||!preg_match('/^[A-Za-z0-9_-]+$/D',$token))return null;
        $raw=base64_decode(strtr($token,'-_','+/'),true);
        if (!is_string($raw))return null;
        $v=explode('|',$raw);
        if (count($v)!==7)return null;
        [$site,$route,$variant,$device,$epoch,$issued,$mac]=$v;
        if ($site!==self::site()||!preg_match('/^[a-f0-9]{64}$/D',$route)
            ||!preg_match('/^[a-f0-9]{64}$/D',$variant)
            ||!in_array($device,['mobile','desktop'],true)
            ||!preg_match('/^(0|[1-9][0-9]{0,9})$/D',$epoch)
            ||!preg_match('/^[0-9]{10}$/D',$issued)
            ||!preg_match('/^[a-f0-9]{64}$/D',$mac)
            ||(int)$epoch!==(int)get_option('mad4b_css_ai_epoch_v1',0)
            ||(int)$issued>time()+120||time()-(int)$issued>7200)return null;
        if (!hash_equals(hash_hmac('sha256',implode('|',array_slice($v,0,6)),
                    wp_salt('secure_auth')),$mac))return null;
        return ['site'=>$site,'route'=>$route,'variant'=>$variant,'device'=>$device];
    }
    public static function footer():void {
        $policy=self::policy();
        if ($policy['mode']!=='observe'||$policy['sample_bps']<1)return;
        $scope=self::scope();
        if (!$scope)return;
        $client=WP_CONTENT_DIR.self::ASSETS.'mad4b-css-rum-client.js';
        $vendor=WP_CONTENT_DIR.self::ASSETS.'web-vitals-6.2.3.iife.js';
        if (!is_file($client)||!is_file($vendor))return;
        $cfg=['contract'=>self::CONTRACT,'mode'=>'observe',
          'sampleBps'=>$policy['sample_bps'],
          'proof'=>self::signed_proof($scope,time()),
          'endpoint'=>rest_url('mad4b-css/v1/rum'),
          'vendor'=>content_url(self::ASSETS.'web-vitals-6.2.3.iife.js').
             '?v='.(string)filemtime($vendor)];
        $json=wp_json_encode($cfg,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_QUOT|JSON_HEX_APOS);
        if (!$json)return;
        echo '<script data-no-optimize="1" id="mad4b-css-rum-config">window.MAD4B_CSS_RUM='.$json.';</script>';
        echo '<script data-no-optimize="1" id="mad4b-css-rum-client" defer src="'.
             esc_url(content_url(self::ASSETS.'mad4b-css-rum-client.js').
                '?v='.(string)filemtime($client)).'"></script>';
    }
    public static function bucket(string $metric,int $value):int {
        if (!isset(self::LIMITS[$metric])||$value<0||$value>60000)
            throw new \InvalidArgumentException('invalid_metric');
        foreach(self::LIMITS[$metric] as $i=>$max)
            if ($value<=$max)return $i;
        return count(self::LIMITS[$metric]);
    }
    public static function p75_bound(string $metric,array $hist):?int {
        if (!isset(self::LIMITS[$metric]))return null;
        $total=0;
        foreach ($hist as $bucket=>$count) {
            if (!is_numeric($bucket) || (int)$bucket<0
                || (int)$bucket>count(self::LIMITS[$metric])
                || !is_int($count) || $count<0)return null;
            $total+=$count;
        }
        if ($total===0)return null;
        $threshold=(int)ceil($total*0.75);
        $observed=0;
        foreach (range(0,count(self::LIMITS[$metric])) as $index) {
            $observed+=(int)($hist[$index]??0);
            if ($observed>=$threshold)
                return self::LIMITS[$metric][$index]??60001;
        }
        return null;
    }
    public static function table():string {
        global $wpdb;
        return $wpdb->prefix.'mad4b_css_rum_v1';
    }
    public static function install_schema():bool {
        global $wpdb;
        // Invoked explicitly via enrolled WP-CLI, never on a public request.
        if (!self::site()||!defined('WP_CLI')||!WP_CLI)return false;
        $name=self::table();
        $sql="CREATE TABLE IF NOT EXISTS $name (
          site_id varchar(12) NOT NULL,route_sha char(64) NOT NULL,
          variant_sha char(64) NOT NULL,arm varchar(12) NOT NULL,
          device varchar(8) NOT NULL,day_utc date NOT NULL,
          metric varchar(4) NOT NULL,bucket tinyint unsigned NOT NULL,
          samples int unsigned NOT NULL DEFAULT 0,
          PRIMARY KEY (site_id,route_sha,variant_sha,arm,device,day_utc,metric,bucket),
          KEY day_utc (day_utc)
        ) ".$wpdb->get_charset_collate();
        return $wpdb->query($sql)!==false;
    }
    private static function rate_ok(string $site,string $proof):bool {
        // Rate limiting never depends on forwarded IP headers. Proxies can
        // share REMOTE_ADDR and untrusted X-Forwarded-For may be forged.
        // The only stored keys are 60-second aggregated counts, no visitor ID.
        $minute=gmdate('YmdHi');
        $global='mad4b_css_rum_global_'.$site.'_'.$minute;
        $per='mad4b_css_rum_scope_'.substr(hash('sha256',$proof),0,20).'_'.$minute;
        $a=(int)get_transient($global);$b=(int)get_transient($per);
        if ($a>=100||$b>=25)return false;
        set_transient($global,$a+1,60);
        set_transient($per,$b+1,60);
        return true;
    }
    public static function record(\WP_REST_Request $req) {
        if (self::policy()['mode']!=='observe')
            return new \WP_Error('rum_disabled','Disabled',['status'=>403]);
        if ((int)($_SERVER['CONTENT_LENGTH']??0)>1400)
            return new \WP_Error('rum_too_large','Too large',['status'=>413]);
        if ((string)($_SERVER['HTTP_ORIGIN']??'')!=='https://'.self::host()
            ||(!empty($_SERVER['HTTP_SEC_FETCH_SITE'])
               &&$_SERVER['HTTP_SEC_FETCH_SITE']!=='same-origin'))
            return new \WP_Error('rum_wrong_origin','Cross origin',['status'=>403]);
        $p=$req->get_json_params();
        if (!is_array($p)||array_keys($p)!==
            ['contract','proof','arm','metrics','cssRestored']
            ||($p['contract']??'')!==self::CONTRACT
            ||!in_array($p['arm']??'',['native','optimized'],true)
            ||!in_array($p['cssRestored']??null,[null,true,false],true))
            return new \WP_Error('rum_invalid','Malformed payload',['status'=>400]);
        $scope=self::verify_proof($p['proof']??null);
        if (!$scope)return new \WP_Error('rum_proof','Invalid proof',['status'=>403]);
        $metrics=$p['metrics']??null;
        if (!is_array($metrics)||!$metrics||count($metrics)>3)
            return new \WP_Error('rum_metrics','Invalid metrics',['status'=>400]);
        $rows=[];
        foreach ($metrics as $metric=>$value) {
            if (!isset(self::LIMITS[$metric])||!is_int($value)
                ||$value<0||$value>60000||($metric==='cls'&&$value>1000))
                return new \WP_Error('rum_metric','Invalid metric',['status'=>400]);
            $rows[$metric]=self::bucket($metric,$value);
        }
        if (!self::rate_ok($scope['site'],$p['proof']))
            return new \WP_Error('rum_throttled','Throttled',['status'=>429]);
        global $wpdb;
        $table=self::table();$day=gmdate('Y-m-d');
        if ($p['arm']==='optimized' && is_bool($p['cssRestored']))
            $rows['css']=$p['cssRestored']?1:0;
        foreach ($rows as $metric=>$bucket) {
            $query=$wpdb->prepare(
             "INSERT INTO $table
              (site_id,route_sha,variant_sha,arm,device,day_utc,metric,bucket,samples)
              VALUES (%s,%s,%s,%s,%s,%s,%s,%d,1)
              ON DUPLICATE KEY UPDATE samples=LEAST(samples+1,4294967295)",
             $scope['site'],$scope['route'],$scope['variant'],
             $p['arm'],$scope['device'],$day,$metric,$bucket);
            if ($wpdb->query($query)===false)
                return new \WP_Error('rum_storage','Unavailable',['status'=>503]);
        }
        nocache_headers();
        return new \WP_REST_Response(['accepted'=>true],202);
    }
    public static function routes():void {
        register_rest_route('mad4b-css/v1','/rum',[
          'methods'=>'POST','callback'=>[__CLASS__,'record'],
          'permission_callback'=>'__return_true']);
        register_rest_route('mad4b-css/v1','/status',[
          'methods'=>'GET','callback'=>[__CLASS__,'admin_status'],
          'permission_callback'=>static function() {
              return current_user_can('manage_options');
          }]);
    }
    public static function admin_status() {
        nocache_headers();
        return new \WP_REST_Response(['site'=>self::site(),
          'policy'=>self::policy(),'health'=>get_option('mad4b_css_rum_health_v1',[])],200);
    }
    public static function retention():void {
        global $wpdb;
        if (self::policy()['mode']!=='observe')return;
        $table=self::table();
        $wpdb->query("DELETE FROM $table WHERE day_utc<DATE_SUB(UTC_DATE(),INTERVAL 30 DAY)");
        // Operational heartbeat only: site-wide histograms are not a
        // substitute for route+market+device controlled Canary comparisons.
        $sql="SELECT arm,metric,bucket,SUM(samples) AS total FROM $table
              WHERE day_utc>=DATE_SUB(UTC_DATE(),INTERVAL 1 DAY)
              GROUP BY arm,metric,bucket";
        $observed=$wpdb->get_results($sql,ARRAY_A);
        if (!is_array($observed))return;
        $totals=[];
        foreach ($observed as $row) {
            $arm=(string)($row['arm']??'');
            $metric=(string)($row['metric']??'');
            $bucket=(int)($row['bucket']??-1);
            $count=(int)($row['total']??0);
            if (!in_array($arm,['native','optimized'],true)
                || !in_array($metric,['lcp','inp','cls','css'],true)
                || $bucket<0 || $bucket>32 || $count<0)continue;
            $totals[$arm][$metric][$bucket]=($totals[$arm][$metric][$bucket]??0)+$count;
        }
        $fail=(int)($totals['optimized']['css'][0]??0);
        $pass=(int)($totals['optimized']['css'][1]??0);
        $vitals=[];
        foreach (['native','optimized'] as $arm) {
            foreach (['lcp','inp','cls'] as $name) {
                $hist=$totals[$arm][$name]??[];
                $vitals[$arm][$name]=[
                  'samples'=>array_sum($hist),
                  'p75_upper'=>self::p75_bound($name,$hist)
                ];
            }
        }
        // Observational site-level metrics are not a randomized A/B trial.
        $state=['contract'=>'mad4b.adaptive-css.health.v1','site'=>self::site(),
          'observed_utc'=>gmdate('Y-m-d\TH:i:s\Z'),
          'health_status'=>($fail>=5 && $fail>($pass+$fail)*0.05)
                ?'css_restore_regression_observed':'shadow_monitoring',
          'css_restore_failed'=>$fail,'css_restore_passed'=>$pass,
          'vitals'=>$vitals,'totals'=>$totals,
          'public_rollout_percent_changed'=>false,
          'performance_warning_is_not_automatic_certificate_authority'=>true];
        update_option('mad4b_css_rum_health_v1',$state,false);
    }
    public static function boot():void {
        if (!self::site())return;
        add_action('rest_api_init',[__CLASS__,'routes']);
        add_action('wp_footer',[__CLASS__,'footer'],5);
        add_action(self::CRON,[__CLASS__,'retention']);
        if (self::policy()['mode']==='observe'&&!wp_next_scheduled(self::CRON))
            wp_schedule_event(time()+600,'hourly',self::CRON);
    }
}
add_action('init',['MAD4B_Adaptive_CSS_RUM_V1','boot'],40);
}
