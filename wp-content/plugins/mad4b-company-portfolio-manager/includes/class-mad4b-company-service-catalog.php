<?php
/**
 * Central Company Management: independent company-specific catalog plans.
 * Explicitly not a remote provider activation or production write grant.
 */
defined('ABSPATH') || exit;
final class MAD4B_Company_Service_Catalog_V1 {
    public const CONTRACT='mad4b.company-service-catalog.v1';
    public const STORE='mad4b_company_service_catalog_modes_v1';
    public const MODES=['tour_only','full_catalog'];
    public const OWNERS=['tours','properties','transportations','visas'];
    public const OBSERVATION_SCOPE='mad4b_service_public_observation_pilot_v1';
    public const NATIVE_OBSERVATION_PREFIX='mad4b_company_service_native_wpcli_readback_v1_';
    public const NATIVE_READBACK_CONTRACT='mad4b.company.service.native-wpcli-observation.v1';
    public const NATIVE_READBACK_MAX_AGE=360;


    public static function staging(): bool {
        return class_exists('MAD4B_Company_Portfolio_Manager_V1',false)
            && MAD4B_Company_Portfolio_Manager_V1::staging();
    }
    private static function error(string $code,string $message,int $http=409): WP_Error {
        return new WP_Error('mad4b_catalog_'.$code,$message,['status'=>$http]);
    }
    private static function admin(): bool {
        return self::staging() && current_user_can('manage_options') && current_user_can('activate_plugins');
    }
    public static function permission() {
        return self::admin() ? true:self::error('admin_required','Central Staging administrator required',403);
    }
    private static function binding(string $code) {
        if(!preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$code))
            return self::error('company_invalid','Invalid company code',422);
        $b=MAD4B_Company_Portfolio_Manager_V1::company_binding($code);
        if(is_wp_error($b))return $b;
        if(!is_array($b)||($b['company_code']??'')!==$code
            ||!wp_is_uuid((string)($b['company_site_uuid']??''))
            ||!preg_match('/^[a-f0-9]{64}$/D',(string)($b['binding_sha256']??'')))
            return self::error('binding_invalid','Verified company site binding required',403);
        return $b;
    }
    /**
     * Explicit, local central rollout scope. Unreconciled company records are
     * not probed merely because an old Site Profile / catalog record exists.
     */
    public static function observation_enabled(string $code):bool {
        if (!preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$code))return false;
        $scope=get_option(self::OBSERVATION_SCOPE,[]);
        return is_array($scope)&&in_array($code,$scope,true);
    }
    public static function matrix(string $mode): array {
        if(!in_array($mode,self::MODES,true))return [];
        $result=[];
        foreach(self::OWNERS as $service)
            $result[$service]=($service==='tours'||$mode==='full_catalog')
                ? 'standalone_and_tour':'tour_only';
        return $result;
    }
    private static function records() {
        $x=get_option(self::STORE,[]);
        return is_array($x)?$x:self::error('store_invalid','Company catalog registry invalid',503);
    }
    public static function companies(): array {
        if(!self::staging())return [];
        $x=get_option(MAD4B_Company_Portfolio_Manager_V1::REGISTRY,[]);
        $codes=is_array($x)?array_keys($x):[];
        if(class_exists('MAD4B_Native_VPS_Principal_V1',false)) {
            $scope=MAD4B_Native_VPS_Principal_V1::scope();
            if(is_array($scope)&&($scope['scope_kind']??'')==='central_native_host'
                &&($scope['company_target']??true)===false) {
                $candidate=$scope['workload']??null;
                if(is_string($candidate)&&preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$candidate)
                    &&!is_wp_error(self::binding($candidate)))$codes[]=$candidate;
            }
        }
        $codes=array_values(array_unique($codes));
        sort($codes,SORT_STRING);
        return $codes;
    }
    public static function current(string $code) {
        if(!self::staging())return self::error('environment','Central Staging only',403);
        $b=self::binding($code);
        if(is_wp_error($b))return $b;
        $items=self::records();
        if(is_wp_error($items))return $items;
        $row=$items[$code]??[
            'contract'=>self::CONTRACT,'company_code'=>$code,
            'company_site_uuid'=>$b['company_site_uuid'],
            'binding_sha256'=>$b['binding_sha256'],
            'mode'=>'tour_only','revision'=>0,'source'=>'safe_default',
            'updated_at'=>null,'updated_by'=>null,'history'=>[]
        ];
        if(!is_array($row)||($row['contract']??'')!==self::CONTRACT
            ||($row['company_code']??'')!==$code
            ||($row['company_site_uuid']??'')!==$b['company_site_uuid']
            ||!is_string($row['binding_sha256']??null)
            ||!hash_equals($b['binding_sha256'],$row['binding_sha256'])
            ||!in_array($row['mode']??null,self::MODES,true)
            ||!is_int($row['revision']??null)||$row['revision']<0)
            return self::error('binding_drift','Company policy binding changed; reconciliation required',409);
        $row['target_channels']=self::matrix($row['mode']);
        $row['site_activation_applied']=false;
        $row['provider_activation_granted']=false;
        $row['tenant_write_authorized']=false;
        return $row;
    }
    public static function save(string $code,int $revision,string $mode) {
        $p=self::permission();if(is_wp_error($p))return $p;
        if($revision<0||!in_array($mode,self::MODES,true))
            return self::error('invalid_mode','Expected revision and catalog mode required',422);
        $b=self::binding($code);if(is_wp_error($b))return $b;
        global $wpdb;
        $lock='mad4b:company:catalog:'.substr(hash('sha256',$code),0,25);
        if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,3)',$lock))!==1)
            return self::error('busy','Catalog update busy',423);
        try {
            $before=self::current($code);if(is_wp_error($before))return $before;
            if($before['revision']!==$revision)
                return self::error('revision_stale','Company catalog changed; reload',409);
            $items=self::records();if(is_wp_error($items))return $items;
            // Explicit Save must persist the company's own settings even
            // when the selected mode equals the inherited safe default.
            if($before['mode']===$mode && array_key_exists($code,$items))return $before;
            $history=(array)($before['history']??[]);
            $history[]=['revision'=>$revision+1,'from'=>$before['mode'],
                'to'=>$mode,'actor_id'=>get_current_user_id(),'at'=>gmdate('c')];
            $items[$code]=[
                'contract'=>self::CONTRACT,'company_code'=>$code,
                'company_site_uuid'=>$b['company_site_uuid'],
                'binding_sha256'=>$b['binding_sha256'],
                'mode'=>$mode,'revision'=>$revision+1,
                'source'=>'company_management',
                'updated_at'=>gmdate('c'),'updated_by'=>get_current_user_id(),
                'history'=>array_slice($history,-20)
            ];
            if(!update_option(self::STORE,$items,false))
                return self::error('write_failed','Catalog settings not saved',503);
            $check=self::current($code);
            if(is_wp_error($check)||$check['revision']!==$revision+1||$check['mode']!==$mode)
                return self::error('readback_failed','Catalog readback mismatch',503);
            do_action('mad4b_company_service_catalog_plan_saved',$code,$check);
            return $check;
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));
        }
    }
    /**
     * Observe only publicly accessible guest routes at the verified registered
     * company origin. Does not consume admin-only site readiness, save options,
     * apply a catalog plan, issue a grant, or certify booking/payment authority.
     */
    public static function public_observation(string $code):array {
        $base=[
            'contract'=>'mad4b.company-service.public-observation.v1',
            'company_code'=>$code,
            'observation_state'=>'unverified',
            'verification_level'=>'guest_https_routes_only_not_signed',
            'company_plan_applied'=>false,
            'tenant_mutated'=>false,
            'booking_verified'=>false,
            'payment_verified'=>false,
            'checked_at'=>gmdate('c'),
            'checks'=>[]
        ];
        if(!self::admin())return $base+['reason'=>'administrator_required'];
        if(!self::observation_enabled($code))
            return $base+['reason'=>'company_pending_reconciliation_or_full_clone'];
        $binding=self::binding($code);
        if(is_wp_error($binding))return $base+['reason'=>'company_binding_invalid'];
        if(!class_exists('MAD4B_Company_Environment_Registry_V2',false))
            return $base+['reason'=>'environment_registry_unavailable'];
        $target=MAD4B_Company_Environment_Registry_V2::target($code,'production');
        if(!is_array($target) ||
            ($target['company_id']??'')!==$code ||
            ($target['environment']??'')!=='production' ||
            ($target['site_uuid']??'')!==$binding['company_site_uuid'])
            return $base+['reason'=>'exact_production_site_binding_missing'];
        $origin=rtrim((string)($target['canonical_origin']??''),'/');
        $parts=wp_parse_url($origin);
        if(!is_array($parts)||($parts['scheme']??'')!=='https'
            ||empty($parts['host'])||isset($parts['user'])||isset($parts['pass'])
            ||isset($parts['port'])||isset($parts['query'])||isset($parts['fragment'])
            ||!preg_match('/^[a-z0-9][a-z0-9.-]*\\.[a-z]{2,}$/i',(string)$parts['host'])
            ||($parts['path']??'')!=='')
            return $base+['reason'=>'canonical_https_origin_invalid'];
        $paths=[
            'tours'=>'/wp-json/wp/v2/tours-and-activities?per_page=1&_fields=id',
            'internal_rates'=>'/wp-json/wp/v2/tour-rates?per_page=1&_fields=id',
            'properties'=>'/wp-json/wp/v2/properties?per_page=1&_fields=id',
            'transportations'=>'/wp-json/wp/v2/transportations?per_page=1&_fields=id'
        ];
        $observed=[];
        foreach($paths as $key=>$path){
            $result=wp_safe_remote_get($origin.$path,[
                'timeout'=>5,'redirection'=>0,'sslverify'=>true,
                'cookies'=>[],'limit_response_size'=>512,
                'headers'=>[
                    'Accept'=>'application/json',
                    'Cache-Control'=>'no-cache',
                ],
            ]);
            if(is_wp_error($result)){
                $observed[$key]=['result'=>'unverified','reason'=>'transport_unavailable'];
                continue;
            }
            $http=(int)wp_remote_retrieve_response_code($result);
            $contentType=strtolower((string)wp_remote_retrieve_header($result,'content-type'));
            $isJson=str_contains($contentType,'application/json');
            if(!in_array($http,[200,404],true)||!$isJson){
                $observed[$key]=['result'=>'unverified','reason'=>'non_rest_or_unexpected_http'];
                continue;
            }
            $expected=$key==='internal_rates'?404:null;
            $observed[$key]=[
                'result'=>$expected===null?'observed':($http===$expected?'pass':'policy_drift'),
                'http_status'=>$http,
                'meaning'=>$http===200?'guest_readable':'guest_not_found',
            ];
        }
        $status='observed';
        foreach($observed as $row) {
            if($row['result']==='policy_drift'){$status='policy_drift';break;}
            if($row['result']==='unverified')$status='partial_unverified';
        }
        // A plan is not deployment authority. This comparison is advisory and
        // must never be interpreted as proof that a service was activated.
        $plan=self::current($code);
        $mode=is_array($plan)?(string)($plan['mode']??''):'';
        $matrix=self::matrix($mode);
        $comparison=[];$disagreements=[];
        foreach(['tours','properties','transportations'] as $family){
            $expected=($matrix[$family]??'tour_only')==='standalone_and_tour'?200:404;
            $actual=$observed[$family]['http_status']??null;
            $known=in_array($actual,[200,404],true);
            $agrees=$known?($actual===$expected):null;
            $comparison[$family]=[
                'planned_mode'=>$matrix[$family]??'unverified',
                'expected_guest_http_if_deployed'=>$expected,
                'observed_guest_http'=>$actual,
                'plan_to_public_visibility_agrees'=>$agrees,
                'plan_confirmed_deployed'=>false
            ];
            if($known&&!$agrees)$disagreements[]=$family;
        }
        return array_replace($base,[
            'site_host'=>$parts['host'],'registered_site_uuid'=>$target['site_uuid'],
            'observation_state'=>$status,'checks'=>$observed,
            'plan_mode'=>$mode,'plan_revision'=>is_array($plan)?($plan['revision']??0):null,
            'plan_vs_public'=>$comparison,
            'plan_to_public_disagreements'=>$disagreements,
            'reason'=>$status==='observed'?'guest_routes_checked':'see_route_results',
        ]);
    }
    /**
     * Last host-collected WP-CLI response, protected by central administrator
     * permissions and the exact enrolled company binding. No HTTP fallback.
     *
     * Digest validates transport integrity, NOT issuer authenticity.
     * Original313 signed execution/readback is never inferred.
     */
    public static function native_observation(string $code):array {
        $base=[
            'contract'=>self::NATIVE_READBACK_CONTRACT,
            'company_code'=>$code,'state'=>'unverified',
            'transport'=>'same_host_scoped_wpcli',
            'cryptographically_signed'=>false,
            'original313_signed_receipt'=>false,
            'company_plan_applied'=>false,
            'booking_verified'=>false,
            'payment_verified'=>false,
            'tenant_mutated_by_reader'=>false,
            'snapshot'=>null
        ];
        if(!self::admin())return $base+['reason'=>'central_administrator_required'];
        if(!self::observation_enabled($code))
            return $base+['reason'=>'company_pending_reconciliation_or_full_clone'];
        $binding=self::binding($code);
        if(is_wp_error($binding))return $base+['reason'=>'company_binding_invalid'];
        if(!class_exists('MAD4B_Company_Environment_Registry_V2',false))
            return $base+['reason'=>'environment_registry_unavailable'];
        $target=MAD4B_Company_Environment_Registry_V2::target($code,'production');
        if(!is_array($target)
            ||($target['environment']??'')!=='production'
            ||($target['company_id']??'')!==$code
            ||($target['site_uuid']??'')!==$binding['company_site_uuid'])
            return $base+['reason'=>'enrolled_production_identity_not_matched'];
        $origin=rtrim((string)($target['canonical_origin']??''),'/');
        $host=wp_parse_url($origin,PHP_URL_HOST);
        if(!is_string($host)||$host===''||wp_parse_url($origin,PHP_URL_SCHEME)!=='https')
            return $base+['reason'=>'invalid_registered_origin'];
        $row=get_option(self::NATIVE_OBSERVATION_PREFIX.$code,null);
        if(!is_array($row)
            ||array_keys($row)!==['contract','company_code','site_uuid',
                'canonical_origin','binding_sha256','transport','observed_epoch',
                'expires_epoch','snapshot_json','snapshot_sha256','issuer_signed'])
            return $base+['reason'=>'host_wpcli_receipt_unavailable'];
        $json=$row['snapshot_json']??null;
        if(($row['contract']??'')!==self::NATIVE_READBACK_CONTRACT
            ||($row['company_code']??'')!==$code
            ||($row['site_uuid']??'')!==$binding['company_site_uuid']
            ||($row['canonical_origin']??'')!==$origin
            ||($row['binding_sha256']??'')!==$binding['binding_sha256']
            ||($row['transport']??'')!=='same_host_scoped_wpcli'
            ||($row['issuer_signed']??null)!==false
            ||!is_int($row['observed_epoch']??null)
            ||!is_int($row['expires_epoch']??null)
            ||!is_string($json)||strlen($json)>24000
            ||!preg_match('/^[a-f0-9]{64}$/D',(string)($row['snapshot_sha256']??''))
            ||!hash_equals((string)$row['snapshot_sha256'],hash('sha256',$json)))
            return $base+['reason'=>'receipt_binding_or_digest_invalid'];
        $observed=(int)$row['observed_epoch'];
        if($observed>time()+30 || $row['expires_epoch']>$observed+self::NATIVE_READBACK_MAX_AGE
            ||$row['expires_epoch']<=$observed)
            return $base+['reason'=>'invalid_receipt_freshness_envelope'];
        if(time()>=$row['expires_epoch'])
            return $base+['reason'=>'host_wpcli_readback_stale','last_observed_epoch'=>$observed];
        $payload=json_decode($json,true);
        if(!is_array($payload) || array_keys($payload)!==['challenge','site',
                'site_uuid','site_environment','readback_module_sha256','snapshot'])
            return $base+['reason'=>'source_snapshot_schema_invalid'];
        $snapshot=$payload['snapshot']??null;
        if(!is_array($snapshot)
            ||!preg_match('/^[a-f0-9]{32}$/D',(string)($payload['challenge']??''))
            ||($payload['site_uuid']??'')!==$binding['company_site_uuid']
            ||rtrim((string)($payload['site']??''),'/')!==$origin
            ||($payload['site_environment']??'')!=='production'
            ||!is_string($payload['readback_module_sha256']??null)
            ||!preg_match('/^[a-f0-9]{64}$/D',$payload['readback_module_sha256'])
            ||!hash_equals((string)hash_file('sha256',
                WP_CONTENT_DIR.'/mu-plugins/mad4b-service-activation-live-readback-v1.php'),
                $payload['readback_module_sha256'])
            ||($snapshot['contract']??'')!=='mad4b.service-activation-live-readback.v1'
            ||($snapshot['site_host']??'')!==$host
            ||!is_array($snapshot['families']??null)
            ||array_keys($snapshot['families'])!==['tours','properties','transportations','visas']
            ||!is_array($snapshot['schema']??null)
            ||!is_bool($snapshot['public_guard_loaded']??null)
            ||!is_bool($snapshot['unified_fulfillment_loaded']??null)
            ||!is_bool($snapshot['stripe_enabled']??null)
            ||!is_bool($snapshot['early_payment_benefits_enabled']??null))
            return $base+['reason'=>'runtime_snapshot_identity_or_schema_mismatch'];
        $related=$snapshot['related_options_live_status']??null;
        if(!is_array($related)
            ||($related['contract']??'')!=='mad4b.options-live-readback.v1'
            ||($related['site_host']??'')!==$host
            ||!is_array($related['pricing_configuration']??null)
            ||!is_array($related['pricing_dimensions']??null)
            ||!is_array($related['destinations']??null)
            ||!is_array($related['currencies']??null)
            ||!is_array($related['selling_options']??null)
            ||($related['writes_performed']??true)!==false
            ||($related['payment_authority_changed']??true)!==false
            ||($related['prices_changed']??true)!==false)
            return $base+['reason'=>'live_options_snapshot_missing_or_invalid'];
        // A successful local WP-CLI observation does not certify booking, grants
        // or production deployment, even if matching the central plan.
        return array_replace($base,[
            'state'=>'fresh_local_wordpress_wpcli_unsigned',
            'site_host'=>$host,'site_uuid'=>$payload['site_uuid'],
            'observed_epoch'=>$observed,
            'observed_utc'=>gmdate('c',$observed),
            'max_age_seconds'=>self::NATIVE_READBACK_MAX_AGE,
            'source_readback_contract'=>$snapshot['contract'],
            'snapshot'=>[
                'schema'=>$snapshot['schema'],
                'families'=>$snapshot['families'],
                'global_booking_status'=>$snapshot['global_booking_status']??'unverified',
                'public_guard_loaded'=>$snapshot['public_guard_loaded'],
                'unified_fulfillment_loaded'=>$snapshot['unified_fulfillment_loaded'],
                'stripe_enabled'=>$snapshot['stripe_enabled'],
                'early_payment_benefits_enabled'=>$snapshot['early_payment_benefits_enabled'],
                'related_options_live_status'=>[
                    'observed_utc'=>$related['observed_utc']??null,
                    'destinations'=>$related['destinations'],
                    'pricing_configuration'=>[
                        'schema_fields'=>$related['pricing_configuration']['schema_fields']??0,
                        'expected_booking_field_present'=>$related['pricing_configuration']['expected_booking_field_present']??false,
                        'financial_policy_complete'=>$related['pricing_configuration']['financial_policy_complete']??false,
                        'provider_effective'=>$related['pricing_configuration']['provider_effective']??[],
                    ],
                    'pricing_dimensions'=>[
                        'mode'=>$related['pricing_dimensions']['mode']??'unknown',
                        'available'=>$related['pricing_dimensions']['available']??0,
                        'active'=>$related['pricing_dimensions']['active']??0,
                    ],
                    'currencies'=>[
                        'store_base_currency'=>$related['currencies']['store_base_currency']??null,
                        'display_currencies'=>$related['currencies']['display_currencies']??[],
                        'new_draft_authoring_currencies'=>$related['currencies']['new_draft_authoring_currencies']??[],
                    ],
                    'selling_options'=>$related['selling_options'],
                ],
            ],
            'reason'=>'site_wordpress_wpcli_observed_no_first_party_signature',
        ]);
    }
    public static function public_observation_response($result) {
        $response=rest_ensure_response($result);
        if($response instanceof WP_REST_Response)
            $response->header('Cache-Control','private, no-store, max-age=0');
        return $response;
    }
    public static function rest_routes():void {
        if(!self::staging())return;
        register_rest_route('mad4b/v1',
            '/company-management/companies/(?P<company_code>[a-z][a-z0-9-]{2,47})/service-catalog/native-observation',[
                'methods'=>'GET',
                'permission_callback'=>[__CLASS__,'permission'],
                'callback'=>static fn($r)=>self::public_observation_response(
                    self::native_observation((string)$r['company_code']))
            ]);
        register_rest_route('mad4b/v1',
            '/company-management/companies/(?P<company_code>[a-z][a-z0-9-]{2,47})/service-catalog/public-observation',[
                'methods'=>'GET',
                'permission_callback'=>[__CLASS__,'permission'],
                'callback'=>static fn($r)=>self::public_observation_response(
                    self::public_observation((string)$r['company_code']))
            ]);
        register_rest_route('mad4b/v1',
            '/company-management/companies/(?P<company_code>[a-z][a-z0-9-]{2,47})/service-catalog',[
              ['methods'=>'GET','permission_callback'=>[__CLASS__,'permission'],
               'callback'=>static fn($r)=>self::response(self::current((string)$r['company_code']))],
              ['methods'=>'POST',
               'permission_callback'=>[MAD4B_Company_Portfolio_Manager_V1::class,'rest_write_permission'],
               'callback'=>static function($r){
                   $v=$r->get_json_params();
                   if(!is_array($v)||!is_int($v['expected_revision']??null)
                       ||!is_string($v['mode']??null))
                       return self::response(self::error('payload','Expected revision and mode required',422));
                   return self::response(self::save((string)$r['company_code'],
                       $v['expected_revision'],$v['mode']));
               }]
            ]);
    }
    private static function response($result) {
        $response=rest_ensure_response($result);
        if($response instanceof WP_REST_Response)
            $response->header('Cache-Control','no-store,private');
        return $response;
    }
    public static function admin_menu():void {
        if(!self::staging())return;
        add_submenu_page('mad4b-company-brand-readiness','Company Service Catalog',
            'Service Catalog','manage_options','mad4b-company-service-catalog',
            [__CLASS__,'screen']);
    }
    public static function screen():void {
        $p=self::permission();
        if(is_wp_error($p))wp_die(esc_html($p->get_error_message()));
        echo '<div class="wrap"><h1>Company Management · Service Catalog</h1>';
        echo '<p>Each verified company has one catalog mode. <strong>Tour Only</strong> keeps Tours public and places enabled Property, Transportation and other services inside verified Tour context. <strong>Full Catalog</strong> is only a company plan until separate provider readiness and tenant deployment.</p>';
        echo '<p>Changing this plan does not alter Service Activation, WordPress product visibility, payments, prices or any remote company site.</p>';
        $status=sanitize_key((string)($_GET['catalog_status']??''));
        if($status==='saved')echo '<div class="notice notice-success"><p>Company plan saved. No tenant activation was performed.</p></div>';
        if($status==='error')echo '<div class="notice notice-error"><p>Save failed or revision changed. Check binding and reload.</p></div>';
        echo '<table class="widefat striped"><thead><tr><th>Company</th><th>Site ID</th><th>Catalog mode</th><th>Readback</th></tr></thead><tbody>';
        foreach(self::companies() as $code) {
            $v=self::current($code);
            echo '<tr><td><strong>'.esc_html($code).'</strong></td>';
            if(is_wp_error($v)){
                echo '<td colspan="3">'.esc_html($v->get_error_message()).'</td></tr>';
                continue;
            }
            echo '<td><code>'.esc_html(substr($v['company_site_uuid'],0,8)).'…</code></td>';
            echo '<td><form action="'.esc_url(admin_url('admin-post.php')).'" method="post">';
            wp_nonce_field('mad4b_catalog_'.$code);
            echo '<input type="hidden" name="action" value="mad4b_company_service_catalog_save">';
            echo '<input type="hidden" name="company_code" value="'.esc_attr($code).'">';
            echo '<input type="hidden" name="expected_revision" value="'.esc_attr((string)$v['revision']).'">';
            echo '<select name="catalog_mode" aria-label="'.esc_attr('Catalog mode for '.$code).'">';
            foreach(['tour_only'=>'Tour Only','full_catalog'=>'Full Catalog (plan only)'] as $mode=>$label)
                echo '<option value="'.esc_attr($mode).'"'.selected($mode,$v['mode'],false).'>'.esc_html($label).'</option>';
            echo '</select> ';
            submit_button('Save','secondary','submit',false);
            echo '</form></td><td><span>Rev '.esc_html((string)$v['revision']).' · Plan not deployed</span>';
            if(self::observation_enabled($code)){
                $native=self::native_observation($code);
                echo '<p><strong>WordPress native:</strong> '
                    .esc_html(($native['state']??'')==='fresh_local_wordpress_wpcli_unsigned'
                        ?'Fresh local host WP-CLI (unsigned)':'Unverified / stale')
                    .'</p><p><button type="button" class="button button-small mad4b-company-native-observe"'
                    .' data-company="'.esc_attr($code).'">Read protected WordPress status</button></p>'
                    .'<div class="mad4b-company-native-result" role="status" aria-live="polite">'
                    .'No signed Original313 readback. Guest HTTP evidence is separate.</div>';
                echo '<p><button type="button" class="button button-small mad4b-company-live-observe"'
                    .' data-company="'.esc_attr($code).'">Check live public routes</button></p>'
                    .'<div class="mad4b-company-observation-result" data-company="'.esc_attr($code).'"'
                    .' role="status" aria-live="polite">Not checked. Booking and payment authority remain unverified.</div>';
            }else {
                echo '<p><small>Live observation deferred: company reconciliation / Full Clone pending.</small></p>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
        echo '<p>Inherited services include Rooms/Property Rates, Transport Rates/Vehicle Lines/Trains, Tour Rates, and Visas. Disabled native providers stay disabled.</p></div>';
    }
    /** One explicit administrator action, not automatic per-company network calls. */
    public static function admin_assets():void {
        if(!self::admin() || !is_admin() ||
            sanitize_key((string)($_GET['page']??''))!=='mad4b-company-service-catalog')return;
        $config=['base'=>rest_url('mad4b/v1/company-management/companies/'),
                 'nonce'=>wp_create_nonce('wp_rest')];
        $script='(()=>{const cfg='.wp_json_encode($config).';'
            .'for(const button of document.querySelectorAll(".mad4b-company-live-observe")){'
            .'button.addEventListener("click",async()=>{const company=button.dataset.company;'
            .'const host=button.closest("td");const out=host&&host.querySelector(".mad4b-company-observation-result");'
            .'if(!out||!company||!/^[a-z][a-z0-9-]{2,47}$/.test(company))return;'
            .'button.disabled=true;out.textContent="Checking exact company public routes…";'
            .'try{const url=cfg.base+encodeURIComponent(company)+"/service-catalog/public-observation";'
            .'const response=await fetch(url,{headers:{"X-WP-Nonce":cfg.nonce},credentials:"same-origin",cache:"no-store"});'
            .'if(!response.ok)throw new Error("readback_unavailable");const v=await response.json();'
            .'const bits=Object.entries(v.checks||{}).map(([name,row])=>name+": "+(row.http_status??"unknown")'
            .'+ " ("+(row.result||"unverified")+")");'
            .'const differences=v.plan_to_public_disagreements||[];'
            .'const comparison=differences.length'
            .'?" · Plan differs from public site: "+differences.join(", ")'
            .':" · No observed plan-to-public differences on checked routes";'
            .'out.textContent="Guest HTTPS: "+String(v.observation_state||"unverified")+" · "'
            .'+bits.join(" · ")+" · Plan: "+String(v.plan_mode||"unverified")+comparison'
            .'+ " · "+String(v.checked_at||"")'
            .'+". This is not booking, payment, or signed activation evidence.";'
            .'}catch(e){out.textContent="Live observation unavailable; plan remains not deployed/unverified.";}'
            .'finally{button.disabled=false;}});}'
            .'for(const button of document.querySelectorAll(".mad4b-company-native-observe")){'
            .'button.addEventListener("click",async()=>{const company=button.dataset.company;'
            .'const cell=button.closest("td");const out=cell?.querySelector(".mad4b-company-native-result");'
            .'if(!out||!/^[a-z][a-z0-9-]{2,47}$/.test(company||""))return;'
            .'button.disabled=true;out.textContent="Reading latest host-collected WordPress state…";'
            .'try{const url=cfg.base+encodeURIComponent(company)+"/service-catalog/native-observation";'
            .'const res=await fetch(url,{credentials:"same-origin",cache:"no-store",'
            .'headers:{"X-WP-Nonce":cfg.nonce}});'
            .'if(!res.ok)throw new Error("native_read_denied");const v=await res.json();'
            .'if(v.state!=="fresh_local_wordpress_wpcli_unsigned"){out.textContent='
            .'"Native state unavailable: "+String(v.reason||"unverified")'
            .'+" — no activation claim.";return;}'
            .'const families=Object.entries(v.snapshot?.families||{}).map(([name,d])=>'
            .'name+": "+String(d.effective_mode||"unknown")+", "+String(d.published||0)+" published");'
            .'const options=v.snapshot?.related_options_live_status||{};'
            .'const groups=options.destinations?.groups||{};'
            .'const destinations=Object.entries(groups).map(([name,r])=>name.replace("active_","")+": "+r.selected).join("/");'
            .'out.textContent="WordPress WP-CLI at "+String(v.observed_utc||"?")+" · "'
            .'+families.join(" · ")+" · Destination "+destinations+" · Pricing fields "'
            .'+String(options.pricing_configuration?.schema_fields||"unknown")'
            .'+ " · Financial mode policies "'
            .'+(options.pricing_configuration?.financial_policy_complete?"COMPLETE":"UNVERIFIED")'
            .'+ " · Draft currencies "+(options.currencies?.new_draft_authoring_currencies||[]).join(", ")'
            .'+ " · Booking "+String(v.snapshot?.global_booking_status)'
            .'+" · Stripe "+String(v.snapshot?.stripe_enabled?"ON":"OFF")'
            .'+" · Origin 313 signed: NO. No deployed plan or payment authority inferred.";'
            .'}catch(e){out.textContent="Protected WordPress readback failed; no activation claim.";}'
            .'finally{button.disabled=false;}});}})();';
        wp_register_script('mad4b-company-public-observation',false,[],null,true);
        wp_enqueue_script('mad4b-company-public-observation');
        wp_add_inline_script('mad4b-company-public-observation',$script);
    }
    public static function admin_save():void {
        if(!self::admin())wp_die('Central Staging administrator required',403);
        $code=sanitize_key((string)($_POST['company_code']??''));
        check_admin_referer('mad4b_catalog_'.$code);
        $raw=wp_unslash((string)($_POST['expected_revision']??''));
        $mode=wp_unslash((string)($_POST['catalog_mode']??''));
        $result=ctype_digit($raw)&&strlen($raw)<10
            ? self::save($code,(int)$raw,$mode)
            : self::error('revision_invalid','Invalid revision',422);
        $status=is_wp_error($result)?'error':'saved';
        wp_safe_redirect(add_query_arg('catalog_status',$status,
            admin_url('admin.php?page=mad4b-company-service-catalog')));
        exit;
    }
    public static function boot():void {
        add_action('rest_api_init',[__CLASS__,'rest_routes'],76);
        add_action('admin_menu',[__CLASS__,'admin_menu'],94);
        add_action('admin_enqueue_scripts',[__CLASS__,'admin_assets']);
        add_action('admin_post_mad4b_company_service_catalog_save',[__CLASS__,'admin_save']);
    }
}
MAD4B_Company_Service_Catalog_V1::boot();
