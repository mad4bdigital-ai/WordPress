<?php
/**
 * Read-only, provider-neutral MCP readiness inheritance.
 * Never initiates MCP, triggers plugin installation, changes auth, or mutates
 * the shared Adapter class loader. Provider registries are evidence, not grants.
 */
defined('ABSPATH') || exit;

final class MAD4B_SCP_MCP_Provider_Readiness_Matrix {
    const CONTRACT = 'mad4b.mcp-provider-readiness-matrix.v1';
    const POLICY_CONTRACT = 'mad4b.mcp-provider-inherited-readiness-policy.v1';
    const POLICY_FILE = 'config/mcp-provider-handshake-inheritance.json';
    const MAX_PROVIDERS = 96;
    const MAX_ABILITIES = 2200;

    public static function boot() {
        add_action('wp_abilities_api_init', array(__CLASS__, 'register_abilities'), 39);
    }

    public static function register_abilities() {
        if (!function_exists('wp_register_ability')) return;
        foreach (array(
            'mad4b/mcp-provider-readiness-matrix' => 'matrix',
            'mad4b/mcp-provider-readiness-preflight' => 'preflight',
        ) as $name => $method) {
            if (function_exists('wp_has_ability') && wp_has_ability($name)) continue;
            wp_register_ability($name, array(
                'label' => ucwords(str_replace(array('mad4b/', '-'), ' ', $name)),
                'description' => 'Read-only inherited native MCP discovery, handshake and shared class provenance. Never an auth grant.',
                'category' => 'mad4b-read',
                'execute_callback' => array(__CLASS__, $method),
                'permission_callback' => array('MAD4B_SCP_Policy', 'can_read'),
                'input_schema' => array(
                    'type'=>'object','properties'=>array(
                        'provider_id'=>array('type'=>'string','maxLength'=>64,'default'=>''),
                    ),'additionalProperties'=>false,
                ),
                'output_schema'=>array('type'=>'object','additionalProperties'=>true),
                'meta'=>array('public'=>false,'show_in_rest'=>false,
                    'mcp'=>array('public'=>false,'type'=>'tool','surface'=>'read'),
                    'annotations'=>array('readonly'=>true,'destructive'=>false,'idempotent'=>false)),
            ));
        }
    }

    private static function policy() {
        $fallback=array(
            'contract'=>self::POLICY_CONTRACT,
            'inherited_defaults'=>array(
                'require_real_initialize'=>true,'require_real_tools_list'=>true,
                'require_authenticated_principal'=>true,'require_tool_schema_identity'=>true,
                'require_source_site_binding'=>true,'require_fresh_receipt'=>true,
                'allow_shared_oauth_session_inheritance'=>false,
                'allow_automatic_adapter_class_rebinding'=>false,
                'allow_auto_mcp_enablement'=>false,
                'allow_write_from_local_registry_only'=>false,
                'unrelated_content_jobs_not_blocked'=>true,
                'missing_static_release_profile_is_runtime_blocker'=>false,
                'dynamic_installed_provider_version_detection'=>true,
                'dynamic_official_upstream_source_verification_on_maintenance'=>true,
                'privileged_runtime_recovery_requires_upstream_source_verification'=>true,
                'release_policy_target_is_not_a_version_allowlist'=>true,
                'mixed_vendor_mcp_php_runtime_still_requires_independent_compatibility_check'=>true,
                'skill_dimensions'=>array('provider','security','content_jobs','devops','frontend','qa'),
            ),'provider_overrides'=>array(),
        );
        if (!defined('MAD4B_SCP_DIR'))return $fallback;
        $path=MAD4B_SCP_DIR.self::POLICY_FILE;
        if(!is_file($path)||!is_readable($path)||is_link($path)||filesize($path)>32768)return $fallback;
        $value=json_decode((string)file_get_contents($path),true);
        if(!is_array($value)||($value['contract']??'')!==self::POLICY_CONTRACT)return $fallback;
        // Provider overrides may add restrictive notes/dimensions, never turn
        // off authentication, handshake, class/OAuth separation, or write gates.
        if(is_array($value['inherited_defaults']??null))
            foreach($fallback['inherited_defaults'] as $key=>$original){
                if(is_bool($original))continue;
                if($key==='skill_dimensions' && is_array($value['inherited_defaults'][$key]??null))
                    $fallback['inherited_defaults'][$key]=array_values(array_unique(array_merge($original,
                        array_slice(array_filter($value['inherited_defaults'][$key],'is_string'),0,16))));
            }
        if(is_array($value['provider_overrides']??null))
            foreach(array_slice($value['provider_overrides'],0,48,true) as $id=>$entry)
                if(is_string($id)&&preg_match('/^[a-z][a-z0-9_-]{0,63}$/D',$id)&&is_array($entry)){
                    $fallback['provider_overrides'][$id]=array(
                        'notes'=>array_slice(array_filter((array)($entry['notes']??array()),'is_string'),0,12),
                    );
                }
        return $fallback;
    }

    private static function ensure(&$items,$provider,$source) {
        $id=sanitize_key((string)$provider);
        if(!preg_match('/^[a-z][a-z0-9_-]{0,63}$/D',$id))return '';
        if(!isset($items[$id])){
            if(count($items)>=self::MAX_PROVIDERS)return '';
            $items[$id]=array(
                'provider_id'=>$id,'evidence_sources'=>array(),
                'local_ability_count'=>0,'transport_declaration_count'=>0,
                'transport_kinds'=>array(),'pinned_peers'=>array(),
                'plugin_active'=>null,
            );
        }
        if(!in_array($source,$items[$id]['evidence_sources'],true))
            $items[$id]['evidence_sources'][]=$source;
        return $id;
    }

    private static function provenance() {
        // Never autoload shared classes while inspecting their ownership.
        $classes=array(
            'WP\\MCP\\Core\\McpAdapter',
            'WP\\MCP\\Core\\McpServer',
            'WP\\MCP\\Domain\\Tools\\McpTool',
            'WP\\MCP\\Domain\\Resources\\McpResource',
            'WP\\MCP\\Infrastructure\\ErrorHandling\\McpErrorFactory',
        );
        $records=array();$origins=array();
        foreach($classes as $name){
            $loaded=class_exists($name,false);
            $origin='NOT_LOADED';
            if($loaded){
                try {
                    $path=(string)(new ReflectionClass($name))->getFileName();
                    if(str_contains($path,'/seo-by-rank-math/'))$origin='RANK_MATH_VENDOR';
                    elseif(str_contains($path,'/mcp-adapter/'))$origin='OFFICIAL_MCP_ADAPTER';
                    elseif(str_contains($path,'/elementor/'))$origin='ELEMENTOR_VENDOR';
                    elseif(str_contains($path,'/hostinger-'))$origin='HOSTINGER_VENDOR';
                    else $origin='OTHER_ORIGIN_UNVERIFIED';
                    $origins[$origin]=true;
                }catch(Throwable $error){$origin='PROVENANCE_UNAVAILABLE';}
            }
            $records[]=array('class'=>$name,'loaded'=>$loaded,'origin'=>$origin);
        }
        $known=array_values(array_filter(array_keys($origins),static fn($x)=>$x!=='PROVENANCE_UNAVAILABLE'));
        $mixed=count($known)>1;
        return array(
            'state'=>$mixed?'MIXED_SHARED_CLASS_PROVENANCE':(count($known)===1?'ONE_LOADED_ORIGIN':'UNVERIFIED'),
            'loaded_origin_count'=>count($known),
            'origins'=>$known,
            'classes'=>$records,
            'mixed_vendor_runtime_risk'=>$mixed,
            'rebind_in_current_request_allowed'=>false,
            'change_plugin_load_order_allowed'=>false,
            'register_another_server_during_readonly_probe_allowed'=>false,
            'oauth_session_inheritance_allowed'=>false,
        );
    }

    public static function matrix($input=array()) {
        $input=is_array($input)?$input:array();
        $scope=sanitize_key((string)($input['provider_id']??''));
        if($scope!==''&&!preg_match('/^[a-z][a-z0-9_-]{0,63}$/D',$scope))
            return new WP_Error('mad4b_mcp_readiness_provider_invalid','Provider identifier invalid.');
        $policy=self::policy();$providers=array();
        $descriptors=class_exists('MAD4B_SCP_Provider_Transport_Registry',false)
            ? MAD4B_SCP_Provider_Transport_Registry::descriptors():array();
        foreach(array_slice((array)$descriptors,0,self::MAX_PROVIDERS) as $row){
            if(!is_array($row))continue;
            $id=self::ensure($providers,$row['provider_id']??'','transport_registry');
            if($id==='')continue;
            $providers[$id]['transport_declaration_count']++;
            $kind=sanitize_key((string)($row['kind']??'unknown'));
            if(!in_array($kind,$providers[$id]['transport_kinds'],true))
                $providers[$id]['transport_kinds'][]=$kind;
        }
        $peers=class_exists('MAD4B_SCP_MCP_Hybrid',false)?MAD4B_SCP_MCP_Hybrid::manifest():array();
        foreach(array_slice((array)$peers,0,24,true) as $peer_id=>$peer){
            if(!is_array($peer))continue;
            $id=self::ensure($providers,$peer_id,'operator_pinned_peer_manifest');
            if($id==='')continue;
            $providers[$id]['pinned_peers'][]=array(
                'peer_id'=>$peer_id,'declared_binding_count'=>count((array)($peer['bindings']??array())),
                'transport_not_probed'=>true,
            );
        }
        $active=(array)get_option('active_plugins',array());
        foreach(array(
            'rank-math'=>'seo-by-rank-math/',
            'elementor'=>'elementor/',
            'jetengine'=>'jet-engine/',
            'jetsmartfilters'=>'jet-smart-filters/',
            'wpml'=>'sitepress-multilingual-cms/',
        ) as $id=>$prefix) {
            $installed=false;
            foreach($active as $plugin)if(str_starts_with((string)$plugin,$prefix)){$installed=true;break;}
            if(!$installed && !isset($providers[$id]))continue;
            $id2=self::ensure($providers,$id,'installed_plugin_state');
            if($id2!=='')$providers[$id2]['plugin_active']=$installed;
        }
        $abilities=0;
        if(function_exists('wp_get_abilities')) {
            $registry=wp_get_abilities();
            if(is_array($registry))foreach($registry as $name=>$ability){
                if(++$abilities>self::MAX_ABILITIES)break;
                if(!is_string($name)||!preg_match('~^([a-z][a-z0-9_-]{0,63})/[a-z0-9._/-]{1,191}$~D',$name,$matches))continue;
                if($matches[1]==='mad4b')continue;
                $id=self::ensure($providers,$matches[1],'registered_wordpress_ability');
                if($id!=='')$providers[$id]['local_ability_count']++;
            }
        }
        $runtime=self::provenance();
        ksort($providers,SORT_STRING);
        $result=array();
        foreach($providers as $id=>$row){
            $enabled=null;
            if($id==='elementor' && class_exists('MAD4B_SCP_Elementor_Native_MCP_Awareness',false)){
                $read=MAD4B_SCP_Elementor_Native_MCP_Awareness::inspect();
                $enabled=(bool)($read['native_mcp']['site_enabled']??false);
            }
            // Rank Math currently bundles WP Media MCP OAuth. Read its native
            // enablement from the already-loaded vendor context, with no
            // Adapter initialization or route/OAuth mutation.
            if($id==='rank-math'){
                $ctx='WPMedia\\MCP\\OAuth\\Context';
                if(class_exists($ctx,false) && method_exists($ctx,'is_enabled')){
                    try { $enabled=(bool)(new $ctx())->is_enabled(); }
                    catch(Throwable $error) { $enabled=null; }
                }
            }
            $state=$enabled===false?'SITE_DISABLED'
                :(!empty($row['pinned_peers'])?'PINNED_PEER_UNVERIFIED'
                    :($row['transport_declaration_count']?'TRANSPORT_DECLARED_NO_HANDSHAKE'
                        :($row['local_ability_count']?'LOCAL_ABILITIES_ONLY_NO_HANDSHAKE':'DISCOVERED_UNVERIFIED')));
            $row['native_mcp']=array(
                'site_enabled'=>$enabled,'state'=>$state,
                'initialize_verified'=>false,'tools_list_verified'=>false,
                'tool_schema_verified'=>false,'client_and_site_binding_verified'=>false,
                'handshake_attestation'=>'NOT_OBSERVED',
                'live_transport_probed'=>false,'native_mcp_write_ready'=>false,
            );
            $row['inherited_policy']=$policy['inherited_defaults'];
            $row['provider_additions']=$policy['provider_overrides'][$id]??array();
            $row['shared_transport_conflict']=$runtime['mixed_vendor_runtime_risk'];
            $row['provider_execution_permitted_by_this_read']=false;
            $result[$id]=$row;
        }
        if($scope!=='')$result=isset($result[$scope])?array($scope=>$result[$scope]):array();
        return array(
            'contract'=>self::CONTRACT,
            'site_uuid'=>class_exists('MAD4B_SCP_Site_Profile',false)
                ?(string)(MAD4B_SCP_Site_Profile::status()['site_uuid']??''):'',
            'environment'=>wp_get_environment_type(),
            'provider_count'=>count($result),
            'observed_ability_count'=>min($abilities,self::MAX_ABILITIES),
            'pinned_external_peer_count'=>count((array)$peers),
            'shared_mcp_runtime'=>$runtime,
            'provider_policy_contract'=>self::POLICY_CONTRACT,
            'handshake_transport_probe_performed'=>false,
            'provider_writes_performed'=>false,
            'auth_or_oauth_changes_performed'=>false,
            'adapter_reinitialization_performed'=>false,
            'registry_or_plugin_state_changed'=>false,
            'write_authorized'=>false,'mutation_performed'=>false,
            'providers'=>$result,
        );
    }

    public static function preflight($input=array()) {
        $input=is_array($input)?$input:array();
        $provider=sanitize_key((string)($input['provider_id']??''));
        if($provider==='')return new WP_Error('mad4b_mcp_provider_required','Choose a provider from the live registry.');
        $report=self::matrix(array('provider_id'=>$provider));
        if(is_wp_error($report))return $report;
        return array(
            'contract'=>self::CONTRACT.'.preflight',
            'provider_id'=>$provider,
            'provider_found'=>!empty($report['providers']),
            'provider'=>$report['providers'][$provider]??null,
            'shared_mcp_runtime'=>$report['shared_mcp_runtime'],
            'select_native_mcp_execution'=>false,
            'reason'=>empty($report['providers'])?'PROVIDER_NOT_IN_REGISTRY':
                'AUTHENTICATED_INITIALIZE_TOOLS_LIST_AND_PROVIDER_BINDING_NOT_ATTESTED',
            'non_mcp_provider_workflows_unchanged'=>true,
            'mutation_performed'=>false,'write_authorized'=>false,
        );
    }
}
MAD4B_SCP_MCP_Provider_Readiness_Matrix::boot();
