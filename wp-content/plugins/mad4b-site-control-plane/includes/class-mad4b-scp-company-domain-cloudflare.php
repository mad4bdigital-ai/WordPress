<?php
/**
 * MAD4B Control Plane: governed Cloudflare company-domain onboarding.
 * No Cloudflare secrets in WordPress options, REST output, Git or source.
 * PHP 7.4-compatible. External DNS mutation only with owner session + exact plan.
 */
defined('ABSPATH') || exit;

final class MAD4B_SCP_Company_Domain_Cloudflare {
    const NS = 'mad4b/v1';
    const BASE = '/company-domains';
    const CONTRACT = 'mad4b.company-domains.cloudflare.v1';
    const SUBZONE = 'mad4b.com';
    private static $booted = false;

    public static function boot() {
        if (self::$booted) return;
        self::$booted = true;
        add_action('rest_api_init', array(__CLASS__, 'routes'), 39);
    }
    public static function routes() {
        foreach (array(
            '/status' => 'status',
            '/plan' => 'plan',
            '/readback' => 'readback',
        ) as $path => $callback) {
            register_rest_route(self::NS, self::BASE.$path, array(
                'methods' => 'GET', 'callback' => array(__CLASS__, $callback),
                'permission_callback' => array(__CLASS__, 'can_read'),
            ));
        }
        foreach (array(
            '/zones/enroll' => 'enroll',
            '/dns/commit' => 'commit',
        ) as $path => $callback) {
            register_rest_route(self::NS, self::BASE.$path, array(
                'methods' => 'POST', 'callback' => array(__CLASS__, $callback),
                'permission_callback' => array(__CLASS__, 'can_write'),
            ));
        }
    }
    public static function can_read() {
        return current_user_can('manage_options') ? true :
            new WP_Error('mad4b_domain_owner_required','Owner administrator permission required.',array('status'=>403));
    }
    public static function can_write() {
        if (!current_user_can('manage_options') || !current_user_can('activate_plugins')) {
            return new WP_Error('mad4b_domain_owner_required','Owner administrator permission required.',array('status'=>403));
        }
        $cookie = defined('LOGGED_IN_COOKIE') ? (string)($_COOKIE[LOGGED_IN_COOKIE] ?? '') : '';
        $user = $cookie !== '' ? wp_validate_auth_cookie($cookie,'logged_in') : false;
        $session = function_exists('wp_get_session_token') ? (string)wp_get_session_token() : '';
        $nonce = (string)($_SERVER['HTTP_X_WP_NONCE'] ?? '');
        if (!$user || (int)$user !== (int)get_current_user_id() ||
            $session === '' || $nonce === '' || !wp_verify_nonce($nonce,'wp_rest')) {
            return new WP_Error('mad4b_domain_browser_approval_required',
                'Verified owner browser session and WordPress REST nonce required.',array('status'=>403));
        }
        return true;
    }
    private static function settings() {
        $registry = json_decode((string)getenv('MAD4B_COMPANY_LAUNCH_REGISTRY_JSON'), true);
        $registry = is_array($registry) ? $registry : array();
        return array(
            'token' => trim((string)getenv('MAD4B_CLOUDFLARE_API_TOKEN')),
            'zone_id' => trim((string)getenv('MAD4B_CLOUDFLARE_ZONE_ID')),
            'account_id' => trim((string)getenv('MAD4B_CLOUDFLARE_ACCOUNT_ID')),
            'origin_ipv4' => trim((string)getenv('MAD4B_COMPANY_ORIGIN_IPV4')),
            'registry' => $registry,
        );
    }
    public static function valid_host($host) {
        if (!is_string($host) || strlen($host)>253 || strtolower($host)!==$host ||
            substr($host,-1)==='.' || strpos($host,'..')!==false ||
            !preg_match('/^(?=.{4,253}$)[a-z0-9]+(?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9]+(?:[a-z0-9-]*[a-z0-9])?)+$/D',$host)) {
            return false;
        }
        foreach (explode('.',$host) as $label) {
            if (strlen($label)>63) return false;
        }
        return true;
    }
    public static function validate_target($input, $settings) {
        $code = (string)($input['company_code'] ?? '');
        $mode = (string)($input['mode'] ?? '');
        $hostname = strtolower(trim((string)($input['hostname'] ?? '')));
        if (!preg_match('/^[a-z][a-z0-9-]{1,48}[a-z0-9]$/D',$code) ||
            !in_array($mode,array('subdomain','custom_domain'),true) ||
            !self::valid_host($hostname)) {
            return new WP_Error('mad4b_domain_invalid_target','Invalid domain or company selector.',array('status'=>422));
        }
        $entry = $settings['registry'][$code] ?? null;
        if (!is_array($entry) || ($entry['production_admitted'] ?? false) !== true ||
            !self::valid_host((string)($entry['origin_host'] ?? ''))) {
            return new WP_Error('mad4b_domain_company_not_admitted','Company production identity not enrolled.',array('status'=>409));
        }
        if ($mode === 'subdomain' && $hostname !== $code.'.'.self::SUBZONE) {
            return new WP_Error('mad4b_domain_subdomain_scope','Subdomain must match company code in managed MAD4B zone.',array('status'=>422));
        }
        if ($mode === 'custom_domain' && ($hostname === self::SUBZONE ||
            substr($hostname,-strlen('.'.self::SUBZONE)) === '.'.self::SUBZONE ||
            $hostname === (string)$entry['origin_host'])) {
            return new WP_Error('mad4b_domain_custom_scope','Use subdomain mode for the existing managed hostname.',array('status'=>422));
        }
        if (!filter_var($settings['origin_ipv4'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return new WP_Error('mad4b_domain_origin_unconfigured','Verified public origin IPv4 is not configured.',array('status'=>503));
        }
        return array('company_code'=>$code,'mode'=>$mode,'hostname'=>$hostname,
            'origin_host'=>$entry['origin_host'],'origin_ipv4'=>$settings['origin_ipv4'],
            'zone_name'=>$mode==='subdomain'?self::SUBZONE:$hostname);
    }
    private static function provider($method, $path, $body=null, $settings=null) {
        $settings = $settings === null ? self::settings() : $settings;
        if (empty($settings['token']) || !preg_match('~^/(zones|user/tokens)(?:[/?][a-zA-Z0-9_./%&=?-]*)?$~D',$path)) {
            return new WP_Error('mad4b_cloudflare_not_configured','Cloudflare zone-scoped access is not configured.',array('status'=>503));
        }
        $args=array('method'=>$method,'timeout'=>14,'redirection'=>0,'sslverify'=>true,
            'headers'=>array('Authorization'=>'Bearer '.$settings['token'],'Accept'=>'application/json'));
        if ($body !== null) {
            $args['headers']['Content-Type']='application/json';
            $args['body']=wp_json_encode($body);
        }
        $response=wp_remote_request('https://api.cloudflare.com/client/v4'.$path,$args);
        if (is_wp_error($response)) {
            return new WP_Error('mad4b_cloudflare_remote_unknown',
                'Cloudflare response unknown; inspect provider before retry.',array('status'=>502));
        }
        $code=(int)wp_remote_retrieve_response_code($response);
        $raw=(string)wp_remote_retrieve_body($response);
        if (strlen($raw)>200000) {
            return new WP_Error('mad4b_cloudflare_bad_response','Oversize provider response.',array('status'=>502));
        }
        $json=json_decode($raw,true);
        if (!is_array($json) || !isset($json['success']) || !is_bool($json['success']) ||
            ($code<200 || $code>=300) || $json['success']!==true) {
            return new WP_Error('mad4b_cloudflare_provider_rejected',
                'Provider request failed; inspect Cloudflare permissions and logs.',array('status'=>502));
        }
        return $json['result'] ?? null;
    }
    private static function zone_for($target,$settings) {
        if ($target['mode']==='subdomain') {
            if (!preg_match('/^[a-f0-9]{32}$/D',$settings['zone_id'])) {
                return new WP_Error('mad4b_cloudflare_zone_unconfigured','MAD4B Cloudflare zone ID missing.',array('status'=>503));
            }
            $zone=self::provider('GET','/zones/'.$settings['zone_id'],null,$settings);
            if (is_wp_error($zone)) return $zone;
            if (!is_array($zone) || ($zone['id'] ?? '')!==$settings['zone_id'] ||
                ($zone['name'] ?? '')!==self::SUBZONE || ($zone['status'] ?? '')!=='active') {
                return new WP_Error('mad4b_cloudflare_wrong_zone','MAD4B zone is not active and matched.',array('status'=>409));
            }
            return $zone;
        }
        $zones=self::provider('GET','/zones?name='.rawurlencode($target['zone_name']),null,$settings);
        if (is_wp_error($zones)) return $zones;
        if (!is_array($zones)) return new WP_Error('mad4b_cloudflare_bad_zone','Invalid zone collection.',array('status'=>502));
        foreach ($zones as $zone) {
            if (is_array($zone) && ($zone['name'] ?? '')===$target['zone_name'] &&
                preg_match('/^[a-f0-9]{32}$/D',(string)($zone['id'] ?? ''))) return $zone;
        }
        return null;
    }
    private static function records($zone_id,$name,$type,$settings) {
        $path='/zones/'.$zone_id.'/dns_records?name='.rawurlencode($name).'&per_page=100';
        $result=self::provider('GET',$path,null,$settings);
        if (is_wp_error($result)) return $result;
        if (!is_array($result)) return new WP_Error('mad4b_cloudflare_bad_records','Invalid DNS listing.',array('status'=>502));
        return array_values(array_filter($result,static function($row) use($name) {
            return is_array($row) && ($row['name'] ?? '')===$name;
        }));
    }
    private static function challenge($target) {
        return 'mad4b-owner='.substr(hash_hmac('sha256',
            $target['company_code'].'|'.$target['hostname'].'|'.$target['origin_host'],
            wp_salt('auth')),0,48);
    }
    private static function ownership_ok($target,$zone,$settings) {
        if ($target['mode']==='subdomain') return true;
        $name='_mad4b-verify.'.$target['hostname'];
        $rows=self::records($zone['id'],$name,'TXT',$settings);
        if (is_wp_error($rows)) return $rows;
        foreach ($rows as $row) {
            if (($row['type'] ?? '')==='TXT' &&
                trim((string)($row['content'] ?? ''),'"')===self::challenge($target)) return true;
        }
        return false;
    }
    private static function make_plan($data) {
        $s=self::settings();
        $target=self::validate_target($data,$s);
        if (is_wp_error($target)) return $target;
        $blockers=array();
        $zone=null;
        if ($s['token']==='') $blockers[]='cloudflare_token_missing';
        else {
            $zone=self::zone_for($target,$s);
            if (is_wp_error($zone)) $blockers[]=$zone->get_error_code();
            elseif ($zone===null) $blockers[]='zone_not_enrolled_in_cloudflare';
            elseif (($zone['status'] ?? '')!=='active') $blockers[]='zone_pending_nameserver_activation';
            else {
                $proof=self::ownership_ok($target,$zone,$s);
                if (is_wp_error($proof)) $blockers[]=$proof->get_error_code();
                elseif (!$proof) $blockers[]='company_domain_ownership_txt_unverified';
            }
        }
        $core=array(
            'contract'=>self::CONTRACT,'target'=>$target,
            'zone_id'=>is_array($zone)?(string)($zone['id'] ?? ''):'',
            'zone_status'=>is_array($zone)?(string)($zone['status'] ?? ''):'',
            'blockers'=>$blockers,'slot'=>(int)floor(time()/300),
        );
        $core['plan_sha256']=hash_hmac('sha256',wp_json_encode($core),wp_salt('auth'));
        $core['eligible_dns_commit']=count($blockers)===0;
        $core['requested_record']=array('type'=>'A','name'=>$target['hostname'],
            'content'=>$target['origin_ipv4'],'ttl'=>1,'proxied'=>false);
        $core['ownership_txt']=$target['mode']==='custom_domain' ?
            array('type'=>'TXT','name'=>'_mad4b-verify.'.$target['hostname'],
                'content'=>self::challenge($target),'provided_by'=>'domain_owner') : null;
        $core['next_actions']=$blockers ? array('configure_provider_or_verify_zone','refresh_plan') :
            array('owner_confirm_dns_commit','verify_provider_readback','verify_public_tls');
        $core['public_live']=false;
        return $core;
    }
    private static function respond($body,$code=200) {
        if (is_wp_error($body)) return $body;
        $response=new WP_REST_Response($body,$code);
        $response->header('Cache-Control','private, no-store');
        return $response;
    }
    public static function status() {
        $s=self::settings();
        return self::respond(array('contract'=>self::CONTRACT,
            'cloudflare_token_configured'=>$s['token']!=='',
            'mad4b_zone_id_configured'=>preg_match('/^[a-f0-9]{32}$/D',$s['zone_id'])===1,
            'cloudflare_account_id_configured'=>preg_match('/^[a-f0-9]{32}$/D',$s['account_id'])===1,
            'origin_configured'=>filter_var($s['origin_ipv4'],FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)!==false,
            'enrolled_companies'=>array_keys($s['registry']),
            'modes'=>array('subdomain','custom_domain'),
            'provider_secret_exposed'=>false,
            'automatic_public_activation'=>false));
    }
    public static function plan($request) {
        return self::respond(self::make_plan($request->get_params()));
    }
    public static function enroll($request) {
        $data=$request->get_json_params();
        $s=self::settings();
        $target=self::validate_target((array)$data,$s);
        if (is_wp_error($target)) return $target;
        if ($target['mode']!=='custom_domain' ||
            (string)($data['confirmation'] ?? '')!=='ENROLL CLOUDFLARE ZONE '.$target['hostname']) {
            return new WP_Error('mad4b_domain_enroll_confirmation','Exact custom-zone owner approval required.',array('status'=>422));
        }
        $plan=self::make_plan($data);
        if (is_wp_error($plan)) return $plan;
        if (!hash_equals($plan['plan_sha256'],(string)($data['expected_plan_sha256'] ?? ''))) {
            return new WP_Error('mad4b_domain_stale_plan','Refresh exact owner plan.',array('status'=>409));
        }
        if (!in_array('zone_not_enrolled_in_cloudflare',$plan['blockers'],true) ||
            count($plan['blockers'])!==1 ||
            !preg_match('/^[a-f0-9]{32}$/D',$s['account_id'])) {
            return new WP_Error('mad4b_domain_zone_not_eligible','Zone is already present or account authority unavailable.',array('status'=>409));
        }
        $key='mad4b_cf_zone_enrollment_'.md5($target['company_code'].'|'.$target['hostname']);
        if (!add_option($key,array('state'=>'request_unknown','host'=>$target['hostname']), '', false)) {
            return new WP_Error('mad4b_domain_enroll_review','Earlier enrollment intent exists; reconcile Cloudflare first.',array('status'=>409));
        }
        $created=self::provider('POST','/zones',array(
            'name'=>$target['zone_name'],'type'=>'full','account'=>array('id'=>$s['account_id'])), $s);
        if (is_wp_error($created)) return $created;
        update_option($key,array('state'=>'submitted','host'=>$target['hostname']),false);
        return self::respond(array('state'=>'ZONE_CREATED_PENDING_OWNER_NS',
            'zone_name'=>$target['zone_name'],
            'nameservers'=>is_array($created)?array_values((array)($created['name_servers']??array())):array(),
            'requires_domain_registrar_ns_update'=>true,'public_live'=>false),201);
    }
    public static function commit($request) {
        $data=(array)$request->get_json_params();
        $plan=self::make_plan($data);
        if (is_wp_error($plan)) return $plan;
        if (!$plan['eligible_dns_commit']) {
            return new WP_Error('mad4b_domain_preflight_denied','Provider/domain ownership preflight not complete.',
                array('status'=>409,'blockers'=>$plan['blockers']));
        }
        $hostname=$plan['target']['hostname'];
        if ((string)($data['confirmation'] ?? '')!=='CREATE DNS FOR '.$hostname ||
            !hash_equals($plan['plan_sha256'],(string)($data['expected_plan_sha256']??''))) {
            return new WP_Error('mad4b_domain_approval_mismatch','Exact fresh plan and confirmation required.',array('status'=>409));
        }
        $key=(string)($data['idempotency_key']??'');
        if (!preg_match('/^[a-zA-Z0-9_-]{12,80}$/D',$key)) {
            return new WP_Error('mad4b_domain_idempotency_required','Stable client intent key required.',array('status'=>422));
        }
        $s=self::settings();
        global $wpdb;
        $lock='mad4b:cf-domain:'.md5($hostname);
        if ((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 3)',$lock))!==1) {
            return new WP_Error('mad4b_domain_busy','DNS write in progress.',array('status'=>423));
        }
        try {
            $records=self::records($plan['zone_id'],$hostname,'A',$s);
            if (is_wp_error($records)) return $records;
            foreach ($records as $row) {
                if (($row['type']??'')!=='A' || ($row['content']??'')!==$plan['target']['origin_ipv4'] ||
                    !empty($row['proxied'])) {
                    return new WP_Error('mad4b_domain_dns_conflict',
                        'Existing DNS record is not owned by this exact launch intent; never overwrite.',array('status'=>409));
                }
            }
            if (count($records)===1) {
                return self::respond(array('state'=>'DNS_ALREADY_MATCHES',
                    'hostname'=>$hostname,'record_id'=>$records[0]['id']??null,'public_live'=>false));
            }
            $idOption='mad4b_cf_dns_intent_'.hash('sha256',$key);
            $fingerprint=hash('sha256',wp_json_encode($plan['requested_record']));
            if (!add_option($idOption,array('fingerprint'=>$fingerprint,
                'state'=>'remote_outcome_unknown','hostname'=>$hostname), '', false)) {
                return new WP_Error('mad4b_domain_reconcile_required',
                    'Intent was previously submitted; inspect provider readback before retry.',array('status'=>409));
            }
            $result=self::provider('POST','/zones/'.$plan['zone_id'].'/dns_records',
                $plan['requested_record'],$s);
            if (is_wp_error($result)) return $result;
            if (!is_array($result) || ($result['name']??'')!==$hostname ||
                ($result['content']??'')!==$plan['target']['origin_ipv4'] ||
                ($result['type']??'')!=='A') {
                return new WP_Error('mad4b_domain_unverified_response',
                    'Provider response not verified; manual reconciliation required.',array('status'=>502));
            }
            update_option($idOption,array('fingerprint'=>$fingerprint,
                'state'=>'provider_created','hostname'=>$hostname),false);
            return self::respond(array('state'=>'DNS_CREATED_UNVERIFIED_PUBLIC_TLS',
                'hostname'=>$hostname,'record_id'=>$result['id']??null,'public_live'=>false),201);
        } finally {
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));
        }
    }
    public static function readback($request) {
        $plan=self::make_plan($request->get_params());
        if (is_wp_error($plan)) return $plan;
        if (!$plan['eligible_dns_commit']) return self::respond(array(
            'state'=>'NOT_READY','blockers'=>$plan['blockers'],'public_live'=>false));
        $rows=self::records($plan['zone_id'],$plan['target']['hostname'],'A',self::settings());
        if (is_wp_error($rows)) return $rows;
        $match=count($rows)===1 && ($rows[0]['type']??'')==='A' &&
            ($rows[0]['content']??'')===$plan['target']['origin_ipv4'] &&
            empty($rows[0]['proxied']);
        $dns = dns_get_record($plan['target']['hostname'], DNS_A);
        $resolved=false;
        foreach ((array)$dns as $record) {
            if (($record['ip']??'')===$plan['target']['origin_ipv4']) $resolved=true;
        }
        $tls=false;
        if ($match && $resolved) {
            $response=wp_remote_get('https://'.$plan['target']['hostname'].'/',array(
                'timeout'=>12,'redirection'=>0,'sslverify'=>true,'reject_unsafe_urls'=>true));
            $tls=!is_wp_error($response) && (int)wp_remote_retrieve_response_code($response)===200;
        }
        return self::respond(array('state'=>$match && $resolved && $tls?'PUBLIC_TLS_VERIFIED':'WAITING_PUBLIC_DNS_OR_TLS',
            'record_matches'=>$match,'dns_resolves_origin'=>$resolved,
            'trusted_https_200'=>$tls,'public_live'=>$match && $resolved && $tls,
            'activation_changed'=>false));
    }
}
