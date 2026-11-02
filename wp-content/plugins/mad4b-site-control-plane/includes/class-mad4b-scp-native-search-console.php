<?php
defined('ABSPATH') || exit;

/**
 * Read-only Google Search Console bridge through MAD4B's exact, encrypted
 * Google Workspace OAuth grant. Never extracts, stores or exports a token.
 */
final class MAD4B_SCP_Native_Search_Console {
    const CONTRACT = 'mad4b.native-gsc-page-evidence.v1';
    const SCOPE = 'https://www.googleapis.com/auth/webmasters.readonly';
    const API = 'https://www.googleapis.com/webmasters/v3/sites';
    const OPTION = 'mad4b_scp_native_gsc_snapshot_v1';
    const MAX_PAGES = 3;
    const PAGE_LIMIT = 1000;
    const MAX_AGE = 7 * DAY_IN_SECONDS;
    private static $booted=false;

    public static function boot() {
        if(self::$booted)return;
        self::$booted=true;
        add_action('admin_menu',array(__CLASS__,'menu'),99);
        add_action('admin_post_mad4b_native_gsc_action',array(__CLASS__,'post_action'));
        add_filter('mad4b/seo_opportunities/native_page_metrics',array(__CLASS__,'seo_page_metrics'),10,2);
    }

    public static function menu() {
        add_submenu_page('mad4b-control-plane','Google Search Console','Search Console',
            'manage_options','mad4b-native-search-console',array(__CLASS__,'render'));
    }
    private static function site_uuid() {
        return class_exists('MAD4B_SCP_Site_Profile',false)
            ? (string)MAD4B_SCP_Site_Profile::site_uuid() : '';
    }
    private static function origin() {
        $parts=wp_parse_url(home_url('/'));
        return is_array($parts) && in_array($parts['scheme']??'',array('https','http'),true)
            ? strtolower($parts['scheme'].'://'.$parts['host']).(isset($parts['port'])?':'.$parts['port']:'').'/' : '';
    }
    public static function property_matches_site($property) {
        if(!is_string($property)||$property===''||strlen($property)>350)return false;
        $origin=self::origin();if(!$origin)return false;
        if(str_starts_with($property,'sc-domain:')) {
            $domain=substr($property,10);
            if(!preg_match('/^[a-z0-9.-]{3,240}$/D',$domain)||str_contains($domain,'..'))return false;
            $host=(string)wp_parse_url($origin,PHP_URL_HOST);
            return $host===$domain || str_ends_with($host,'.'.$domain);
        }
        if(!str_ends_with($property,'/'))return false;
        $parts=wp_parse_url($property);
        if(!is_array($parts)||!isset($parts['scheme'],$parts['host'])||(isset($parts['user'])||isset($parts['pass'])))return false;
        // Accept only the exact Staging origin as a URL-prefix property.
        return hash_equals($origin,strtolower($property));
    }
    public static function status() {
        $selection=array();$connection=array();
        if(class_exists('MAD4B_SCP_Google_Drive_Context',false)) {
            $grants=MAD4B_SCP_Google_Drive_Context::workspace_grants_status();
            $selection=(array)($grants['selection']??array());
            $connection=(array)MAD4B_SCP_Google_Drive_Context::connection_status();
        }
        $snapshot=self::valid_snapshot();
        return array('contract'=>self::CONTRACT,
            'site_uuid_bound'=>self::site_uuid()!=='',
            'google_connected'=>!empty($connection['connected']) && empty($connection['token_unreadable']) && empty($connection['revocation_pending']),
            'scope_selected'=>($selection['search_console']??'off')==='read',
            'scope'=>self::SCOPE,
            'capture_ready'=>($selection['search_console']??'off')==='read' &&
                !empty($connection['connected']) && empty($connection['token_unreadable']) && empty($connection['revocation_pending']),
            'retained_evidence_ready'=>is_array($snapshot),
            'captured_at'=>$snapshot['captured_at']??null,
            'property'=>$snapshot['property']??null,
            'pages'=>is_array($snapshot)?count($snapshot['rows']):0,
            'requires_explicit_property_selection'=>true,
            'outbound_request_performed'=>false,
            'read_only'=>true,'authorizing'=>false);
    }
    public static function decode_rows($payload,$site_origin) {
        $out=array();
        if(!is_array($payload)||
            (array_key_exists('rows',$payload)&&!is_array($payload['rows'])))
            return new WP_Error('mad4b_gsc_response_invalid');
        // Search Console may omit rows entirely when the property has no
        // finalized search data for the selected date range.
        foreach((array)($payload['rows']??array()) as $entry) {
            if(!is_array($entry)||count((array)($entry['keys']??array()))!==1)continue;
            $url=(string)$entry['keys'][0];
            $parts=wp_parse_url($url);
            if(!is_array($parts)||!isset($parts['scheme'],$parts['host'])||(isset($parts['user'])||isset($parts['pass']))||
                isset($parts['fragment'])||!in_array($parts['scheme'],array('https','http'),true))continue;
            $row_origin=strtolower($parts['scheme'].'://'.$parts['host']).(isset($parts['port'])?':'.$parts['port']:'').'/';
            if(!hash_equals($site_origin,$row_origin))continue;
            $clicks=$entry['clicks']??null;$impressions=$entry['impressions']??null;
            $ctr=$entry['ctr']??null;$position=$entry['position']??null;
            if(!is_numeric($clicks)||!is_numeric($impressions)||!is_numeric($ctr)||!is_numeric($position)||
                !is_finite((float)$clicks)||!is_finite((float)$impressions)||
                !is_finite((float)$ctr)||!is_finite((float)$position)||
                $clicks<0||$impressions<0||$ctr<0||$ctr>1||$position<0||$clicks>$impressions)continue;
            $key=strtolower(untrailingslashit($url));
            $out[$key]=array('page'=>$url,'clicks'=>(float)$clicks,'impressions'=>(float)$impressions,
                'ctr_percent'=>round((float)$ctr*100,4),'position'=>round((float)$position,3));
        }
        return $out;
    }
    private static function valid_snapshot() {
        if(!class_exists('MAD4B_SCP_Google_Drive_Context',false))return null;
        $grants=MAD4B_SCP_Google_Drive_Context::workspace_grants_status();
        $conn=MAD4B_SCP_Google_Drive_Context::connection_status();
        if(($grants['selection']['search_console']??'off')!=='read' ||
            empty($conn['connected'])||!empty($conn['revocation_pending'])||
            !empty($conn['token_unreadable']))return null;
        $s=get_option(self::OPTION,array());
        if(!is_array($s)||($s['contract']??'')!==self::CONTRACT||
            ($s['site_uuid']??'')!==self::site_uuid()||
            ($s['site_origin']??'')!==self::origin()||
            !self::property_matches_site($s['property']??'')||
            !is_array($s['rows']??null)||
            (int)($s['valid_until']??0)<=time()||
            (int)($s['captured_unix']??0)>time() ||
            (int)($s['captured_unix']??0)<time()-self::MAX_AGE)return null;
        $digest=hash('sha256',wp_json_encode($s['rows'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
        if(!is_string($s['sha256']??null)||!hash_equals($s['sha256'],$digest))return null;
        return $s;
    }
    public static function seo_page_metrics($existing,$targetUrls) {
        if(!is_array($existing)||$existing)return $existing;
        if(!is_array($targetUrls))return array();
        $snapshot=self::valid_snapshot();
        if(!$snapshot)return array();
        $data=array();
        foreach($snapshot['rows'] as $row){
            $page=strtolower(untrailingslashit((string)($row['page']??'')));
            $url=wp_parse_url($page);
            if(!is_array($url)||empty($url['host']))continue;
            $key=strtolower($url['host']).'|'.untrailingslashit('/'.ltrim((string)($url['path']??'/'),'/'));
            if(!isset($targetUrls[$key])||(float)($row['impressions']??0)<20)continue;
            $data[$key]=array('source'=>'mad4b_native_gsc','clicks'=>(float)$row['clicks'],
                'impressions'=>(float)$row['impressions'],'ctr'=>(float)$row['ctr_percent'],
                'position'=>(float)$row['position'],'observed_at'=>$snapshot['captured_at'],
                'evidence_sha256'=>$snapshot['sha256'],'property'=>$snapshot['property']);
        }
        return $data;
    }
    private static function bearer() {
        if(!current_user_can('manage_options') ||
            !class_exists('MAD4B_SCP_Google_Drive_Context',false)||
            !class_exists('MAD4B_SCP_Policy',false)||!MAD4B_SCP_Policy::can_read()||
            self::site_uuid()==='')return new WP_Error('mad4b_gsc_permission_denied');
        $g=MAD4B_SCP_Google_Drive_Context::measurement_broker_grant_for_scope(self::SCOPE);
        if(!is_array($g)||empty($g['access_token'])||empty($g['subject_binding_verified'])||
            empty($g['site_binding_verified'])||($g['site_uuid']??'')!==self::site_uuid())
            return new WP_Error('mad4b_gsc_explicit_consent_required','Enable Google Search Console read in Workspace Grants, then reconnect as the authorized owner.');
        return $g['access_token'];
    }
    private static function api($method,$url,$bearer,$payload=null) {
        // No client-supplied URLs: only Google's fixed HTTPS resource paths.
        if(!str_starts_with($url,self::API)||!preg_match('#^https://www\.googleapis\.com/webmasters/v3/sites(?:/|$)#',$url))
            return new WP_Error('mad4b_gsc_outbound_url_denied');
        $args=array('method'=>$method,'timeout'=>18,'redirection'=>0,
            'headers'=>array('Authorization'=>'Bearer '.$bearer,'Accept'=>'application/json'));
        if($payload!==null){$args['headers']['Content-Type']='application/json';$args['body']=wp_json_encode($payload);}
        $res=wp_remote_request($url,$args);
        if(is_wp_error($res))return new WP_Error('mad4b_gsc_request_unavailable');
        if((int)wp_remote_retrieve_response_code($res)!==200)return new WP_Error('mad4b_gsc_remote_not_authorized_or_unavailable');
        $body=wp_remote_retrieve_body($res);
        if(strlen($body)>6000000)return new WP_Error('mad4b_gsc_response_exceeds_limit');
        $data=json_decode($body,true);
        return is_array($data)?$data:new WP_Error('mad4b_gsc_invalid_json');
    }
    private static function authorized_properties($bearer) {
        $result=self::api('GET',self::API,$bearer);
        if(is_wp_error($result))return $result;
        $options=array();
        foreach((array)($result['siteEntry']??array()) as $site){
            $property=(string)($site['siteUrl']??'');
            $permission=(string)($site['permissionLevel']??'');
            if(self::property_matches_site($property)&&in_array($permission,
                array('siteOwner','siteFullUser','siteRestrictedUser'),true))
                $options[$property]=array('property'=>$property,'permission'=>$permission,
                    'domain_property'=>str_starts_with($property,'sc-domain:'));
        }
        return $options;
    }
    public static function discover() {
        $g=self::bearer();if(is_wp_error($g))return $g;
        return self::authorized_properties($g);
    }
    public static function capture($property) {
        if(!self::property_matches_site($property))return new WP_Error('mad4b_gsc_property_not_in_site_scope');
        $bearer=self::bearer();if(is_wp_error($bearer))return $bearer;
        $allowed=self::authorized_properties($bearer);
        if(is_wp_error($allowed))return $allowed;
        if(!isset($allowed[$property]))return new WP_Error('mad4b_gsc_property_permission_not_verified');
        $end=gmdate('Y-m-d',time()-3*DAY_IN_SECONDS);
        $start=gmdate('Y-m-d',time()-31*DAY_IN_SECONDS);
        $url=self::API.'/'.rawurlencode($property).'/searchAnalytics/query';
        $rows=array();$complete=false;$calls=0;
        for($page=0;$page<self::MAX_PAGES;$page++){
            $data=self::api('POST',$url,$bearer,array('startDate'=>$start,'endDate'=>$end,
                'type'=>'web','dimensions'=>array('page'),'aggregationType'=>'byPage',
                'dataState'=>'final','rowLimit'=>self::PAGE_LIMIT,'startRow'=>$page*self::PAGE_LIMIT));
            ++$calls;if(is_wp_error($data))return $data;
            $chunk=self::decode_rows($data,self::origin());
            if(is_wp_error($chunk))return $chunk;
            foreach($chunk as $key=>$r)$rows[$key]=$r;
            if(count((array)($data['rows']??array()))<self::PAGE_LIMIT){$complete=true;break;}
        }
        if(!$complete)return new WP_Error('mad4b_gsc_result_limit_no_partial_write');
        $site=self::site_uuid();$now=time();
        $s=array('contract'=>self::CONTRACT,'site_uuid'=>$site,'site_origin'=>self::origin(),
            'property'=>$property,'captured_unix'=>$now,'captured_at'=>gmdate('c',$now),
            'valid_until'=>$now+self::MAX_AGE,'window_start'=>$start,'window_end'=>$end,
            'rows'=>$rows,'sha256'=>hash('sha256',wp_json_encode($rows,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)),
            'complete'=>true,'source'=>'google_search_console_oauth_via_mad4b','page_count'=>count($rows),'api_calls'=>$calls);
        update_option(self::OPTION,$s,false);
        $saved=self::valid_snapshot();
        if(!is_array($saved)||!hash_equals($saved['sha256'],$s['sha256']))return new WP_Error('mad4b_gsc_readback_failed');
        return array('contract'=>self::CONTRACT,'state'=>'captured','pages'=>count($rows),
            'window_start'=>$start,'window_end'=>$end,'captured_at'=>$s['captured_at'],
            'sha256'=>$s['sha256'],'partial'=>false,'jobs_created'=>0,'content_modified'=>false);
    }
    public static function post_action() {
        if(!current_user_can('manage_options'))wp_die('Denied', '',array('response'=>403));
        check_admin_referer('mad4b_gsc_action');
        $action=isset($_POST['operation'])&&is_string($_POST['operation'])?sanitize_key($_POST['operation']):'';
        $key='mad4b_gsc_ui_'.get_current_user_id();
        if($action==='discover'){
            $properties=self::discover();
            if(is_wp_error($properties))set_transient($key,array('error'=>$properties->get_error_code()),300);
            else set_transient($key,array('properties'=>$properties),300);
        }elseif($action==='capture'){
            $property=isset($_POST['property'])&&is_string($_POST['property'])
                ?sanitize_text_field(wp_unslash($_POST['property'])):'';
            $result=self::capture($property);
            set_transient($key,is_wp_error($result)?array('error'=>$result->get_error_code()):
                array('message'=>'Captured '.(int)$result['pages'].' pages'),300);
        }else wp_die('Invalid action','',array('response'=>422));
        wp_safe_redirect(admin_url('admin.php?page=mad4b-native-search-console'));exit;
    }
    public static function render() {
        if(!current_user_can('manage_options'))return;
        $st=self::status();$key='mad4b_gsc_ui_'.get_current_user_id();
        $notice=get_transient($key);delete_transient($key);
        echo '<div class="wrap"><h1>MAD4B Search Console</h1><p>Read-only page-level search intelligence for the current enrolled WordPress site. No Content Jobs or publishing.</p>';
        if(isset($notice['error']))echo '<div class="notice notice-error"><p>'.esc_html($notice['error']).'</p></div>';
        if(isset($notice['message']))echo '<div class="notice notice-success"><p>'.esc_html($notice['message']).'</p></div>';
        echo '<p>Google connection: '.esc_html($st['google_connected']?'connected':'not connected').
            '; Search Console read grant: '.esc_html($st['scope_selected']?'selected':'off').
            '; retained evidence: '.esc_html($st['retained_evidence_ready']?'fresh':'unavailable').
            '; pages: '.(int)$st['pages'].'</p>';
        echo '<p><a href="'.esc_url(admin_url('admin.php?page=mad4b-control-plane-context&tab=google-drive')).'">Configure Google Workspace Grants and reconnect Google</a></p>';
        if($st['capture_ready']){
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="mad4b_native_gsc_action"><input type="hidden" name="operation" value="discover">';
            wp_nonce_field('mad4b_gsc_action');submit_button('Discover properties for this site','secondary');echo '</form>';
        }
        foreach((array)($notice['properties']??array())as $property=>$details){
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="mad4b_native_gsc_action"><input type="hidden" name="operation" value="capture"><input type="hidden" name="property" value="'.esc_attr($property).'">';
            wp_nonce_field('mad4b_gsc_action');
            echo '<p>'.esc_html($property).' ('.esc_html($details['permission']).')</p>';
            submit_button('Capture 28-day search performance','secondary');echo '</form>';
        }
        echo '</div>';
    }
}

// Register the exact capability-scoped admin route, not a generic action endpoint.
if(class_exists('MAD4B_SCP_Admin_Route_Registry',false))
    MAD4B_SCP_Admin_Route_Registry::register('mad4b-native-search-console','manage_options');
