<?php
defined('ABSPATH') || exit;

/**
 * Dynamic company-scoped JetEngine consumer over canonical Hub versions.
 *
 * Local: JetEngine native REST Listings endpoint, HTTP intercepted ONLY for
 * the exact registered local endpoint in the same request.
 * Remote: signed/authenticated transport is server-owned and remains inactive
 * until a verified peer connection is available.
 */
final class MAD4B_Hub_JetEngine_Consumer_Runtime {
    const CONTRACT = 'mad4b.hub.jetengine-consumer-runtime.v2';
    const REGISTRY = 'mad4b_hub_jetengine_consumers_v2';
    private static $native_requests = array();

    public static function boot() {
        add_action('rest_api_init',array(__CLASS__,'routes'),38);
        add_filter('jet-engine/rest-api-listings/request/args',array(__CLASS__,'native_request_args'),38,2);
        add_filter('pre_http_request',array(__CLASS__,'serve_local_transport'),8,3);
        add_action('wp_abilities_api_init',array(__CLASS__,'abilities'),42);
    }

    public static function native_ready() {
        return class_exists('MAD4B_Hub_Canonical_Store')
            && class_exists('MAD4B_JetEngine_Hub_Addon')
            && MAD4B_JetEngine_Hub_Addon::dependencies_ready()
            && class_exists('Jet_Engine\Modules\Rest_API_Listings\Module');
    }

    public static function operator() {
        return current_user_can('manage_options')
            && class_exists('MAD4B_SCP_Policy') && MAD4B_SCP_Policy::can_mutate()
            && class_exists('MAD4B_Content_Hub') && MAD4B_Content_Hub::permission();
    }

    public static function registry() {
        $r=get_option(self::REGISTRY,array());
        return is_array($r)?$r:array();
    }

    /** Real provider discovery, not a forged remote Site UUID or grant. */
    public static function discovered_companies() {
        $out=array();
        if(!class_exists('MAD4B_Central_Company_REST_V1')
            || !class_exists('MAD4B_Central_Hub_Remote_Control_V1'))return $out;
        foreach(MAD4B_Central_Company_REST_V1::COMPANIES as $id=>$company) {
            $signed=MAD4B_Central_Hub_Remote_Control_V1::reference_share_eligible($id);
            $runtime=false;
            foreach(self::registry() as $config)
                if(($config['company_id']??'')===$id
                    && ($config['transport']??'')==='signed_rest'
                    && ($config['state']??'')==='active')$runtime=true;
            $out[]=array('company_id'=>$id,'canonical_host'=>(string)($company['host']??''),
                'signed_reference_connection_ready'=>(bool)$signed,
                'remote_jetengine_consumer_active'=>$runtime,
                'remote_schema_state'=>$runtime?'configured':'not_discovered',
                'remote_content_distribution_performed'=>false,
                'next_step'=>$signed?'discover_remote_jetengine_schema_and_site_uuid':'restore_signed_company_connection');
        }
        return $out;
    }

    public static function identity() {
        $site=MAD4B_SCP_Site_Profile::status();
        $profile=MAD4B_SCP_Context_Authority::profile();
        return array(
            'site_uuid'=>(string)($site['site_uuid']??''),
            'brand_id'=>(string)($profile['brand_id']??''),
            'company_name'=>(string)($profile['brand_name']??''),
        );
    }

    private static function fail($code,$status=409) {
        return new WP_Error('mad4b_jetengine_consumer_'.$code,$code,array('status'=>$status,'contract'=>self::CONTRACT));
    }

    private static function schema_ready($kind,$language) {
        if (!self::native_ready())return self::fail('jetengine_dependencies_missing',503);
        if(!in_array($kind,array('research_observation','article','tour','property','transport'),true))
            return self::fail('content_kind_invalid',400);
        if(!in_array($language,array('en','ar','fr','de','it','es'),true))
            return self::fail('language_unsupported',400);
        if($language!=='en' && !defined('ICL_SITEPRESS_VERSION'))
            return self::fail('wpml_unavailable');
        $post_type=array('article'=>'post','tour'=>'tours-and-activities','property'=>'properties','transport'=>'transportations');
        if(isset($post_type[$kind])&&!post_type_exists($post_type[$kind]))
            return self::fail('native_cpt_missing');
        return array('schema_status'=>'matched','native_provider'=>'jet-engine','wpml'=>defined('ICL_SITEPRESS_VERSION'));
    }

    public static function configuration($input) {
        if(!is_array($input))return self::fail('invalid_configuration',400);
        $company=sanitize_key((string)($input['company_id']??''));
        $kind=sanitize_key((string)($input['content_kind']??'research_observation'));
        $language=sanitize_key((string)($input['language']??'en'));
        $transport=sanitize_key((string)($input['transport']??'local'));
        if(!preg_match('/^[a-z0-9][a-z0-9-]{2,60}$/',$company))
            return self::fail('company_id_invalid',400);
        if(!in_array($transport,array('local','signed_rest'),true))
            return self::fail('transport_invalid',400);
        $ready=self::schema_ready($kind,$language);
        if(is_wp_error($ready))return $ready;
        $current=self::identity();
        if(!$current['site_uuid'] || !$current['brand_id'])return self::fail('site_brand_identity_missing');
        $consumer_uuid=(string)($input['consumer_site_uuid']??$current['site_uuid']);
        if(!preg_match('/^[a-f0-9-]{36}$/D',$consumer_uuid))return self::fail('consumer_uuid_invalid',400);
        if($transport==='local' && $consumer_uuid!==$current['site_uuid'])
            return self::fail('local_site_mismatch');
        if($transport==='signed_rest' && $consumer_uuid===$current['site_uuid'])
            return self::fail('remote_site_required');
        $remote_uri='';
        if($transport==='signed_rest'){
            $remote_uri=esc_url_raw((string)($input['consumer_api_url']??''));
            $parts=wp_parse_url($remote_uri);
            if(!is_array($parts)||($parts['scheme']??'')!=='https'
                ||empty($parts['host']) ||isset($parts['user']) ||isset($parts['pass']))
                return self::fail('remote_endpoint_invalid',400);
            // Configuration alone cannot verify a remote signed peer.
            $verified=apply_filters('mad4b_hub_consumer_remote_peer_verified',false,
                $company,$consumer_uuid,$remote_uri);
            if(true!==$verified)return self::fail('remote_signed_connection_unverified');
        }
        $fields=$input['field_map']??array('source_id'=>'source_id','title'=>'title','summary'=>'summary','language'=>'language','version'=>'version');
        $safe_sources=array('source_id','title','summary','body','language','version','content_kind','source_company','consumer_company','source_url','license_state','starting_prices','product_rates','pricing_state');
        if(!is_array($fields)||!$fields||count($fields)>count($safe_sources))
            return self::fail('field_map_invalid',400);
        $used=array();$clean=array();
        foreach($fields as $destination=>$source){
            if(!is_string($destination)||!preg_match('/^[a-z][a-z0-9_]{0,60}$/',$destination)
                ||!in_array($source,$safe_sources,true)||isset($used[$source]))
                return self::fail('field_map_unmapped',400);
            $used[$source]=true;$clean[$destination]=$source;
        }
        foreach(array('source_id','title','language','version') as $required)
            if(!isset($used[$required]))return self::fail('required_field_missing');
        $taxonomy=$input['taxonomy_map']??array();
        if(!is_array($taxonomy)||count($taxonomy)>100)return self::fail('taxonomy_mapping_invalid',400);
        foreach($taxonomy as $source=>$target){
            if(!preg_match('/^[a-z0-9_-]{1,70}$/',(string)$source)
                ||!preg_match('/^[a-z0-9_-]{1,70}$/',(string)$target)
                ||!taxonomy_exists((string)$target))
                return self::fail('taxonomy_mapping_missing');
        }
        $key=hash('sha256',$company.'|'.$consumer_uuid.'|'.$kind.'|'.$language.'|'.$transport);
        $config=array('contract'=>self::CONTRACT,'consumer_key'=>$key,
            'company_id'=>$company,'consumer_site_uuid'=>$consumer_uuid,
            'source_site_uuid'=>$current['site_uuid'],'source_brand_id'=>$current['brand_id'],
            'transport'=>$transport,'consumer_api_url'=>$remote_uri,
            'content_kind'=>$kind,'language'=>$language,
            'field_map'=>$clean,'taxonomy_map'=>$taxonomy,
            'schema_status'=>'matched','jet_endpoint_id'=>0,
            'state'=>'configured','version'=>1,
            'created_at'=>gmdate('c'),'updated_at'=>gmdate('c'));
        return $config;
    }

    /** Classify schema/identity drift for an optional resolver engine. */
    public static function diagnose($input) {
        $candidate=self::configuration($input);
        if(!is_wp_error($candidate))
            return array('contract'=>self::CONTRACT,'schema_status'=>'matched',
                'auto_activatable'=>($candidate['transport']==='local'),
                'config'=>$candidate,'resolver_required'=>false);
        $reason=$candidate->get_error_code();
        return array('contract'=>self::CONTRACT,'schema_status'=>'resolver_required',
            'auto_activatable'=>false,'resolver_required'=>true,
            'reason'=>$reason,
            'resolver_mode'=>'jetengine_schema_discovery_and_field_reconciliation',
            'required_actions'=>array(
                'discover_registered_cpt_and_cct_schemas',
                'compare_meta_fields_and_taxonomy_bindings',
                'reconcile_wpml_terms_and_language_authority',
                'verify_consumer_site_identity_and_signed_rest_transport',
                'retry_configuration_on_resolved_schema',
            ),
            'mutation_performed'=>false);
    }

    public static function configure($input) {
        if(!self::operator())return self::fail('write_permission_denied',403);
        $config=self::configuration($input);
        if(is_wp_error($config))return $config;
        $all=self::registry();$key=$config['consumer_key'];
        $old=$all[$key]??null;
        if(is_array($old)){
            foreach(array('source_site_uuid','source_brand_id','company_id','consumer_site_uuid','transport') as $name)
                if(($old[$name]??null)!==$config[$name])return self::fail('immutable_scope_conflict');
            $config['jet_endpoint_id']=(int)($old['jet_endpoint_id']??0);
            $config['version']=(int)$old['version']+1;
            $config['created_at']=$old['created_at'];
            $config['state']=$old['state']==='active'?'active':'configured';
        }
        $all[$key]=$config;
        if(!update_option(self::REGISTRY,$all,false) && get_option(self::REGISTRY)!==$all)
            return self::fail('registry_write_failed');
        $auto=!isset($input['auto_activate']) || $input['auto_activate']===true;
        if($auto && $config['transport']==='local'){
            $activation=self::activate(array('consumer_key'=>$key));
            if(is_wp_error($activation))
                return array('contract'=>self::CONTRACT,'configured'=>true,
                    'config'=>$all[$key],'activation_state'=>'resolver_required',
                    'activation_error_code'=>$activation->get_error_code());
            return array('contract'=>self::CONTRACT,'configured'=>true,
                'config'=>self::registry()[$key],'activation_state'=>'active',
                'jet_endpoint_id'=>$activation['jet_endpoint_id']);
        }
        return array('contract'=>self::CONTRACT,'configured'=>true,
            'config'=>$all[$key],'activation_state'=>'configured');
    }

    private static function validate_stored($key,$need_active=true) {
        $config=self::registry()[$key]??null;
        if(!is_array($config)||($config['contract']??'')!==self::CONTRACT)
            return self::fail('binding_missing',404);
        if($need_active && ($config['state']??'')!=='active')return self::fail('binding_inactive');
        $current=self::identity();
        if(($config['source_site_uuid']??'')!==$current['site_uuid']||
            ($config['source_brand_id']??'')!==$current['brand_id'])
            return self::fail('source_identity_changed');
        $ready=self::schema_ready($config['content_kind'],$config['language']);
        if(is_wp_error($ready))return $ready;
        return $config;
    }

    public static function activate($input) {
        if(!self::operator())return self::fail('write_permission_denied',403);
        $key=(string)($input['consumer_key']??'');
        $c=self::validate_stored($key,false);
        if(is_wp_error($c))return $c;
        if($c['transport']==='signed_rest'){
            if(true!==apply_filters('mad4b_hub_consumer_remote_peer_verified',false,
                $c['company_id'],$c['consumer_site_uuid'],$c['consumer_api_url']))
                return self::fail('remote_peer_no_longer_verified');
            return self::fail('remote_transport_activation_requires_signed_connector');
        }
        $id=(int)($c['jet_endpoint_id']??0);
        $endpoint_created=false;
        $module=Jet_Engine\Modules\Rest_API_Listings\Module::instance();
        if(!$module->data)return self::fail('jetengine_native_data_unavailable');
        $url=rest_url('mad4b-hub/v1/jetengine-consumer-feed/'.$key);
        if(!$id){
            // Create only a native JetEngine Endpoint; do not touch Listings,
            // Queries, page layouts, product CPTs or existing configurations.
            $module->data->set_request(array(
                'name'=>'MAD4B Hub '. $c['company_id'].' '. $c['content_kind'].' '.$c['language'],
                'slug'=>'mad4b-hub-'.substr($key,0,16),
                'url'=>$url,'items_path'=>'items','authorization'=>false,
                'connected'=>true,'cache'=>false,
                'fetched_fields'=>array_keys($c['field_map']),
            ));
            $id=(int)$module->data->create_item(false);
            if(!$id)return self::fail('jetengine_native_endpoint_create_failed');
            $endpoint_created=true;
        }else{
            $endpoints=$module->data->get_items();
            $exists=false;
            foreach((array)$endpoints as $raw) {
                $ep=$module->data->filter_item_for_register($raw);
                if((int)($ep['id']??0)===$id &&($ep['url']??'')===$url)$exists=true;
            }
            if(!$exists)return self::fail('jetengine_binding_drift');
        }
        $all=self::registry();
        $all[$key]['jet_endpoint_id']=$id;
        $all[$key]['state']='active';
        $all[$key]['updated_at']=gmdate('c');
        if(!update_option(self::REGISTRY,$all,false) && get_option(self::REGISTRY)!==$all)
            return self::fail('native_endpoint_registry_save_failed');
        return array('contract'=>self::CONTRACT,'active'=>true,'consumer_key'=>$key,
            'jet_endpoint_id'=>$id,'endpoint_url'=>$url,
            'native_jetengine_endpoint_created'=>$endpoint_created,'listing_replaced'=>false,
            'query_replaced'=>false,'publication_performed'=>false);
    }

    /** Shared content rights, QA and company gates. No test candidate can pass. */
    public static function eligible($record,$config) {
        if(!is_array($record)||!is_array($config))return false;
        $normalized=$record['normalized']??array();
        $provenance=$record['provenance']??array();
        if(($record['state']??'')!=='approved' ||
            ($normalized['quality_state']??'')!=='passed' ||
            ($normalized['publish_authorized']??false)!==true ||
            ($provenance['external_distribution_authorized']??false)!==true ||
            !empty($provenance['test_only']))return false;
        if(($record['site_uuid']??'')!==($config['source_site_uuid']??'') ||
            ($record['brand_id']??'')!==($config['source_brand_id']??'') ||
            ($record['locale']??'')!==($config['language']??'') ||
            ($normalized['content_kind']??'')!==($config['content_kind']??''))return false;
        // Per-company legal/rights risk is not a global publication veto.
        // Do not confuse this policy choice with a license, and keep
        // canonical QA, approval, provenance and scope gates independent.
        if (!class_exists('MAD4B_Hub_Usage_Rights')) return false;
        $source = array(
            'origin' => (string)($provenance['source_origin'] ?? $provenance['origin'] ?? 'external_reference'),
            'company_id' => (string)($record['brand_id'] ?? ''),
            'consumer_company_id' => (string)($config['company_id'] ?? ''),
            'license_id' => (string)($provenance['license_id'] ?? ''),
        );
        $draft = array(
            'quality_state' => (string)($normalized['quality_state'] ?? ''),
            'hard_blockers' => $normalized['hard_blockers'] ?? array(),
            'external_media' => !empty($normalized['external_media']) || !empty($provenance['external_media']),
        );
        $decision = MAD4B_Hub_Usage_Rights::assess($source,$draft,'distribution');
        return is_array($decision) && ($decision['allowed'] ?? false) === true;
    }

    /**
     * Exact approved-only database read for a native/public JetEngine query.
     * Never delegates the administrator-only source read endpoint to a guest.
     * Preview requests may count held candidates, without exposing payloads.
     */
    private static function approved_record($record_key) {
        global $wpdb;
        $t=MAD4B_Hub_Canonical_Store::tables();
        $row=$wpdb->get_row($wpdb->prepare(
            "SELECT r.record_key,r.site_uuid,r.brand_id,r.locale,r.status,r.current_version_sha256,
              v.normalized_json,v.provenance_json
             FROM {$t['records']} r INNER JOIN {$t['versions']} v
              ON v.record_id=r.id AND v.version_sha256=r.current_version_sha256
             WHERE r.record_key=%s AND r.status=%s LIMIT 1",
            $record_key,'approved'),ARRAY_A);
        if(!is_array($row))return null;
        $normalized=json_decode((string)$row['normalized_json'],true);
        $provenance=json_decode((string)$row['provenance_json'],true);
        if(!is_array($normalized)||!is_array($provenance))return null;
        return array('record_key'=>$row['record_key'],'site_uuid'=>$row['site_uuid'],
            'brand_id'=>$row['brand_id'],'locale'=>$row['locale'],
            'state'=>$row['status'],'version_sha256'=>$row['current_version_sha256'],
            'normalized'=>$normalized,'provenance'=>$provenance);
    }

    public static function feed($key,$page=1,$per_page=20,$preview=false) {
        $config=self::validate_stored($key,!$preview);
        if(is_wp_error($config))return $config;
        $page=max(1,min(10000,(int)$page));
        $per_page=max(1,min(50,(int)$per_page));
        global $wpdb;
        $t=MAD4B_Hub_Canonical_Store::tables();
        // Filter all candidates before pagination. No unsafe SQL lookups by
        // user-supplied IDs, and no cross-brand records.
        $rows=$wpdb->get_results($wpdb->prepare(
            "SELECT record_key FROM {$t['records']} WHERE site_uuid=%s AND brand_id=%s AND locale=%s ORDER BY id DESC LIMIT 500",
            $config['source_site_uuid'],$config['source_brand_id'],$config['language']),ARRAY_A);
        if(!is_array($rows))return self::fail('records_query_failed');
        $items=array();$held=0;$eligible=0;
        foreach($rows as $row){
            $record=self::approved_record($row['record_key']);
            if(!$record){if($preview)$held++;continue;}
            if(!self::eligible($record,$config)){if($preview)$held++;continue;}
            $eligible++;
            $normalized=$record['normalized'];
            $quote=null;
            $kind=(string)($config['content_kind']??'');
            $productType=array('tour'=>'tours-and-activities',
                'property'=>'properties','transport'=>'transportations');
            $sourceId=(int)($normalized['source_post_id']??0);
            if($config['transport']==='local' && isset($productType[$kind]) && $sourceId>0
                && class_exists('MAD4B_Hub_Price_Sources_V1')
                && class_exists('MAD4B_Hub_Company_Pricing_V1')){
                $source=MAD4B_Hub_Price_Sources_V1::local($sourceId,$productType[$kind]);
                if(!is_wp_error($source)){
                    $quote=MAD4B_Hub_Company_Pricing_V1::quote(
                        $source,$config['company_id'],array('language'=>$config['language']));
                    if(is_wp_error($quote))$quote=null;
                }
            }
            $data=array('source_id'=>$record['record_key'],
                'title'=>sanitize_text_field((string)($normalized['title']??'')),
                'summary'=>sanitize_textarea_field((string)($normalized['description']??'')),
                'body'=>wp_kses_post((string)($normalized['body']??'')),
                'source_url'=>esc_url_raw((string)($record['provenance']['source_url']??'')),
                'license_state'=>sanitize_key((string)($normalized['rights_state']??'not_verified')),
                'language'=>$record['locale'],
                'version'=>$record['version_sha256'],
                'content_kind'=>$normalized['content_kind'],
                'source_company'=>$record['brand_id'],
                'consumer_company'=>$config['company_id'],
                'starting_prices'=>$quote['starting_price_options']??array(),
                'product_rates'=>$quote['rate_entries']??array(),
                'pricing_state'=>$quote?'indicative_company_quote':'not_available');
            if($data['title']==='')continue;
            $item=array();
            foreach($config['field_map'] as $destination=>$source)$item[$destination]=$data[$source];
            $items[]=$item;
        }
        $total=count($items);
        $offset=($page-1)*$per_page;
        return array('contract'=>self::CONTRACT,'consumer_key'=>$key,
            'state'=>$config['state'],'items'=>array_slice($items,$offset,$per_page),
            'page'=>$page,'per_page'=>$per_page,'eligible_count'=>$eligible,
            'held_count'=>$held,'total'=>$total,'next_page'=>$offset+$per_page<$total?$page+1:null,
            'read_only'=>true,'publication_performed'=>false,
            'target_post_created'=>false,'pricing_overridden'=>false);
    }

    public static function native_request_args($args,$request) {
        if(!is_object($request)||!method_exists($request,'get_endpoint'))return $args;
        $endpoint=$request->get_endpoint();
        if(!is_array($endpoint))return $args;
        foreach(self::registry() as $key=>$config){
            if(($config['state']??'')!=='active'||($config['transport']??'')!=='local'
                ||(int)($config['jet_endpoint_id']??0)!==(int)($endpoint['id']??0)
                ||(int)($endpoint['id']??0)<1)continue;
            $url=rest_url('mad4b-hub/v1/jetengine-consumer-feed/'.$key);
            if(($endpoint['url']??'')!==$url)continue;
            $token=wp_generate_password(36,false,false);
            self::$native_requests[$key]=$token;
            $args=is_array($args)?$args:array();
            $args['headers']=is_array($args['headers']??null)?$args['headers']:array();
            $args['headers']['X-MAD4B-JetEngine-Local']=$token;
            return $args;
        }
        return $args;
    }

    public static function serve_local_transport($preempt,$args,$url) {
        if($preempt!==false||!is_string($url)||!is_array($args))return $preempt;
        $p=wp_parse_url($url);$origin=wp_parse_url(home_url('/'));
        if(!is_array($p)||!is_array($origin)
            ||($p['scheme']??'')!==($origin['scheme']??'')
            ||($p['host']??'')!==($origin['host']??'')
            ||strtoupper((string)($args['method']??'GET'))!=='GET')return $preempt;
        foreach(self::$native_requests as $key=>$token){
            $base=rest_url('mad4b-hub/v1/jetengine-consumer-feed/'.$key);
            if(strtok($url,'?')!==$base)continue;
            $header=$args['headers']['X-MAD4B-JetEngine-Local']??null;
            unset(self::$native_requests[$key]); // single-request, not reusable
            if(!is_string($header)||!hash_equals($token,$header))return $preempt;
            $query=array();
            parse_str((string)($p['query']??''),$query);
            $feed=self::feed($key,(int)($query['page']??1),(int)($query['per_page']??20));
            if(is_wp_error($feed))return $feed;
            return array('headers'=>array('content-type'=>'application/json','cache-control'=>'no-store'),
                'body'=>wp_json_encode($feed),'response'=>array('code'=>200,'message'=>'OK'),
                'cookies'=>array(),'filename'=>null);
        }
        return $preempt;
    }

    public static function routes() {
        $base='mad4b-hub/v1';
        register_rest_route($base,'/jetengine-consumers',array('methods'=>'GET',
            'callback'=>array(__CLASS__,'rest_list'),'permission_callback'=>array(__CLASS__,'permission')));
        foreach(array('configure'=>'rest_configure','activate'=>'rest_activate',
            'preview'=>'rest_preview','diagnose'=>'rest_diagnose') as $route=>$callback)
            register_rest_route($base,'/jetengine-consumers/'.$route,array('methods'=>'POST',
                'callback'=>array(__CLASS__,$callback),'permission_callback'=>array(__CLASS__,'permission')));
        register_rest_route($base,'/jetengine-consumer-feed/(?P<consumer_key>[a-f0-9]{64})',
            array('methods'=>'GET','callback'=>array(__CLASS__,'rest_feed'),
                'permission_callback'=>array(__CLASS__,'feed_permission')));
    }

    public static function permission() {return current_user_can('manage_options');}
    public static function feed_permission($request) {
        $key=(string)$request['consumer_key'];
        $config=self::validate_stored($key);
        if(is_wp_error($config))return $config;
        if(($config['transport']??'')==='local')return self::permission();
        // Existing signed connection verifier can provide this server-side.
        return true===apply_filters('mad4b_hub_consumer_signed_request_verified',false,$request,$config)
            ?true:self::fail('remote_signature_unverified',403);
    }
    public static function rest_list() {
        return array('contract'=>self::CONTRACT,'consumers'=>self::registry(),
            'signed_company_discovery'=>self::discovered_companies(),
            'native_jetengine_available'=>self::native_ready(),
            'local_consumer_enabled'=>true,
            'source_quality_gate'=>'approved_fact_rights_and_release',
            'remote_consumers_auto_installed'=>false);
    }
    public static function rest_diagnose($request) {return self::diagnose($request->get_json_params());}
    public static function rest_configure($request) {return self::configure($request->get_json_params());}
    public static function rest_activate($request) {return self::activate($request->get_json_params());}
    public static function rest_preview($request) {
        $p=$request->get_json_params();
        return self::feed((string)($p['consumer_key']??''),1,20,true);
    }
    public static function rest_feed($request) {
        $r=self::feed((string)$request['consumer_key'],(int)($request['page']??1),(int)($request['per_page']??20));
        if(is_wp_error($r))return $r;
        $response=rest_ensure_response($r);
        $response->header('Cache-Control','private, no-store');
        return $response;
    }
    public static function ability_preview($input) {
        $key=(string)($input['consumer_key']??'');
        return self::feed($key,1,20,true);
    }
    public static function abilities() {
        if(!function_exists('wp_register_ability'))return;
        $definitions=array(
            'mad4b/hub-jetengine-consumers'=>array('callback'=>'rest_list','readonly'=>true),
            'mad4b/hub-jetengine-consumer-configure'=>array('callback'=>'configure','readonly'=>false),
            'mad4b/hub-jetengine-consumer-activate'=>array('callback'=>'activate','readonly'=>false),
            'mad4b/hub-jetengine-consumer-preview'=>array('callback'=>'ability_preview','readonly'=>true),
            'mad4b/hub-jetengine-consumer-diagnose'=>array('callback'=>'diagnose','readonly'=>true),
        );
        foreach($definitions as $name=>$entry) {
            if(function_exists('wp_has_ability')&&wp_has_ability($name))continue;
            $props=$name==='mad4b/hub-jetengine-consumer-activate'
                ||$name==='mad4b/hub-jetengine-consumer-preview'
                ?array('consumer_key'=>array('type'=>'string','minLength'=>64,'maxLength'=>64))
                :($name==='mad4b/hub-jetengine-consumers'?array():array(
                    'company_id'=>array('type'=>'string'),
                    'consumer_site_uuid'=>array('type'=>'string'),
                    'transport'=>array('type'=>'string'),
                    'consumer_api_url'=>array('type'=>'string'),
                    'content_kind'=>array('type'=>'string'),
                    'language'=>array('type'=>'string'),
                    'field_map'=>array('type'=>'object','additionalProperties'=>array('type'=>'string')),
                    'taxonomy_map'=>array('type'=>'object','additionalProperties'=>array('type'=>'string')),
                    'auto_activate'=>array('type'=>'boolean')));
            $required=isset($props['consumer_key'])?array('consumer_key')
                :(isset($props['company_id'])?array('company_id'):array());
            wp_register_ability($name,array(
                'label'=>'MAD4B Hub JetEngine Consumer '. $entry['callback'],
                'description'=>'Company-scoped JetEngine consumer registry, activation and approved-only preview.',
                'category'=>$entry['readonly']?'mad4b-read':'mad4b-write',
                'execute_callback'=>array(__CLASS__,$entry['callback']),
                'permission_callback'=>$entry['readonly']?array(__CLASS__,'permission'):array(__CLASS__,'operator'),
                'input_schema'=>array('type'=>'object','properties'=>$props,'required'=>$required,'additionalProperties'=>false),
                'output_schema'=>array('type'=>'object','additionalProperties'=>true),
                'meta'=>array('show_in_rest'=>false,'public'=>false,
                    'mcp'=>array('type'=>'tool','public'=>false),
                    'annotations'=>array('readonly'=>$entry['readonly'],'destructive'=>false,'idempotent'=>true)),
            ));
        }
    }
}
