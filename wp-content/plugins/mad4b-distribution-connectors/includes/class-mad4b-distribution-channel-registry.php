<?php
/** Company-owned Distribution Channel Registry — catalogs / partners / OTAs. */
defined('ABSPATH') || exit;
final class MAD4B_Distribution_Channel_Registry_V1 {
    public const CONTRACT = 'mad4b.distribution-channels.v1';
    public const STORE = 'mad4b_distribution_channels_v1';
    public const AUDIT = 'mad4b_distribution_channel_events_v1';

    public static function catalog(): array {
        $definitions = [
            'meta_catalog'=>['label'=>'Meta Catalog','category'=>'social_catalog',
                'transport'=>'feed','formats'=>['csv','tsv','xml'],
                'content'=>['tour','destination','property'],'integration'=>'native_mapper',
                'approval'=>'meta_catalog_policy_required'],
            'google_things_to_do'=>['label'=>'Google Things to do','category'=>'tourism_marketplace',
                'transport'=>'sftp','formats'=>['google_productfeed_json'],
                'content'=>['tour','activity','attraction'],'integration'=>'approved_partner_or_direct',
                'approval'=>'provider_enrollment_required'],
            'facebook_shops'=>['label'=>'Facebook Shops / Catalog','category'=>'social_catalog',
                'transport'=>'feed','formats'=>['meta_catalog_reference'],
                'content'=>['tour','destination','property'],'integration'=>'meta_catalog_dependent',
                'approval'=>'meta_commerce_policy_required'],
            'instagram_shopping'=>['label'=>'Instagram Shopping','category'=>'social_catalog',
                'transport'=>'feed','formats'=>['meta_catalog_reference'],
                'content'=>['tour','destination','property'],'integration'=>'meta_catalog_dependent',
                'approval'=>'meta_commerce_policy_required'],
            'whatsapp_business_catalog'=>['label'=>'WhatsApp Business Catalog','category'=>'social_catalog',
                'transport'=>'partner_api','formats'=>['meta_catalog_reference'],
                'content'=>['tour','activity'],'integration'=>'meta_catalog_and_business_approval_required',
                'approval'=>'whatsapp_business_account_required'],
            'google_ads'=>['label'=>'Google Ads','category'=>'advertising',
                'transport'=>'api','formats'=>['campaign_metadata'],
                'content'=>['tour','activity'],'integration'=>'external_adapter_required',
                'approval'=>'ads_account_and_channel_approval_required'],
            'google_business_profile'=>['label'=>'Google Business Profile','category'=>'local_discovery',
                'transport'=>'api','formats'=>['listing_metadata'],
                'content'=>['destination','attraction','business'],'integration'=>'external_adapter_required',
                'approval'=>'business_ownership_verification_required'],
            'viator'=>['label'=>'Viator','category'=>'ota_marketplace',
                'transport'=>'partner_api','formats'=>['partner_contract_defined'],
                'content'=>['tour','activity'],'integration'=>'partner_contract_required',
                'approval'=>'partner_enrollment_required'],
            'getyourguide'=>['label'=>'GetYourGuide','category'=>'ota_marketplace',
                'transport'=>'partner_api','formats'=>['partner_contract_defined'],
                'content'=>['tour','activity'],'integration'=>'partner_contract_required',
                'approval'=>'partner_enrollment_required'],
            'klook'=>['label'=>'Klook','category'=>'ota_marketplace',
                'transport'=>'partner_api','formats'=>['partner_contract_defined'],
                'content'=>['tour','activity'],'integration'=>'partner_contract_required',
                'approval'=>'partner_enrollment_required'],
            'partner_b2b'=>['label'=>'B2B / Wholesale Partner','category'=>'partner_distribution',
                'transport'=>'api','formats'=>['partner_contract_defined'],
                'content'=>['tour','destination','property','transport'],'integration'=>'custom_adapter_required',
                'approval'=>'partner_enrollment_required'],
        ];
        $result=apply_filters('mad4b/distribution/channel_blueprints',$definitions);
        return is_array($result) ? $result : $definitions;
    }

    public static function default_row(string $id,array $definition): array {
        return [
            'id'=>$id,'label'=>$definition['label'],'category'=>$definition['category'],
            'transport'=>$definition['transport'],'supported_formats'=>$definition['formats'],
            'content_types'=>$definition['content'],'integration'=>$definition['integration'],
            'approval_requirement'=>$definition['approval'],
            'status'=>'not_connected','provider_approval'=>'pending',
            'credential_reference'=>'','account_alias'=>'',
            'endpoint_host'=>'','scope_languages'=>[],'scope_markets'=>[],
            'source'=>'central_blueprint_inherited','company_override'=>false,
            'shared_dataset'=>'tourism_catalog_v1',
            'linked_channels'=>match ($id) {
                'google_ads'=>['google_things_to_do'],
                'facebook_shops','instagram_shopping','whatsapp_business_catalog'=>['meta_catalog'],
                default=>[],
            },
            'publishing_enabled'=>false,'external_delivery_enabled'=>false,
            'verified_connection_receipt'=>null,
        ];
    }

    public static function provision_defaults() {
        $existing=get_option(self::STORE,null);
        if ($existing!==null && !is_array($existing)) return new WP_Error('channel_registry_corrupt');
        $rows=is_array($existing)?$existing:[];
        $added=[];
        foreach(self::catalog() as $id=>$definition){
            if (!preg_match('/^[a-z][a-z0-9_]{2,63}$/D',(string)$id)
                || !is_array($definition) || !isset($definition['label'],$definition['category'],
                    $definition['transport'],$definition['formats'],$definition['content'],$definition['integration'],$definition['approval']))
                continue;
            if (isset($rows[$id])) continue;
            $rows[$id]=self::default_row((string)$id,$definition);
            $added[]=$id;
        }
        if ($added) {
            if (!update_option(self::STORE,$rows,false)) return new WP_Error('channel_registry_save_failed');
            if (get_option(self::STORE,[])!==$rows) return new WP_Error('channel_registry_readback_failed');
        }
        return ['contract'=>self::CONTRACT,'added'=>$added,'count'=>count($rows),
            'existing_preserved'=>count($rows)-count($added),
            'external_delivery_enabled'=>false];
    }

    public static function rows(): array {
        $data=get_option(self::STORE,[]);
        return is_array($data)?$data:[];
    }

    public static function public_status(): array {
        $rows=[];
        foreach(self::rows() as $id=>$item){
            if (!is_array($item)) continue;
            $rows[$id]=[
                'id'=>$id,'label'=>(string)($item['label']??''),
                'category'=>(string)($item['category']??''),
                'transport'=>(string)($item['transport']??''),
                'content_types'=>(array)($item['content_types']??[]),
                'status'=>(string)($item['status']??'not_connected'),
                'provider_approval'=>(string)($item['provider_approval']??'pending'),
                'legacy_credential_reference_present'=>!empty($item['credential_reference']),
                'local_credential_present'=>class_exists('MAD4B_Distribution_Local_Credentials_V1')
                    && MAD4B_Distribution_Local_Credentials_V1::has((string)$id),
                'account_alias'=>(string)($item['account_alias']??''),
                'endpoint_host'=>(string)($item['endpoint_host']??''),
                'scope_languages'=>(array)($item['scope_languages']??[]),
                'scope_markets'=>(array)($item['scope_markets']??[]),
                'external_delivery_enabled'=>($item['external_delivery_enabled']??false)===true,
                'source'=>(string)($item['source']??'local'),
                'shared_dataset'=>(string)($item['shared_dataset']??'tourism_catalog_v1'),
                'linked_channels'=>(array)($item['linked_channels']??[]),
                'integration'=>(string)($item['integration']??'unknown'),
            ];
        }
        return $rows;
    }

    /** Read-only readiness. No external secret storage or provider API call. */
    public static function connection_health(): array {
        $local=class_exists('MAD4B_Distribution_Local_Credentials_V1')
            ? MAD4B_Distribution_Local_Credentials_V1::status() : [];
        $encryptionReady=($local['encryption_available']??false)===true;
        $rows=self::rows();$out=[];
        foreach($rows as $id=>$row) {
            if(!is_array($row))continue;
            $reasons=[];
            if(($row['provider_approval']??'pending')!=='approved')
                $reasons[]='provider_enrollment_pending';
            if(empty($row['account_alias']))$reasons[]='account_not_bound';
            $transport=(string)($row['transport']??'');
            $secretRequired=in_array($transport,['api','partner_api','sftp','webhook'],true);
            $storedLocally=$encryptionReady && MAD4B_Distribution_Local_Credentials_V1::has((string)$id);
            if($secretRequired && !$encryptionReady)$reasons[]='site_local_encryption_unavailable';
            if($secretRequired && !$storedLocally)$reasons[]='company_local_credential_missing';
            if($storedLocally && (($row['status']??'')!=='connected_verified'
                || empty($row['verified_connection_receipt'])))
                $reasons[]='provider_credential_not_tested';
            if(in_array($transport,['api','partner_api','sftp','webhook'],true)
                && empty($row['endpoint_host']))$reasons[]='provider_endpoint_unbound';
            $linked=(array)($row['linked_channels']??[]);
            foreach($linked as $dependency) {
                if(!isset($rows[$dependency]) || ($rows[$dependency]['status']??'')!=='connected_verified')
                    $reasons[]='linked_channel_not_connected:'.sanitize_key((string)$dependency);
            }
            $adapterHook='mad4b/distribution/adapter/'.sanitize_key((string)$id).'/verify';
            $adapterInstalled=has_filter($adapterHook)!==false ||
                ($id==='meta_catalog' && class_exists('MAD4B_Meta_Catalog_Adapter_V1'));
            if(!$adapterInstalled)$reasons[]='channel_adapter_not_installed';
            $out[$id]=[
                'status'=>(string)($row['status']??'not_connected'),
                'account_alias_present'=>!empty($row['account_alias']),
                'credentials_backend'=>'wordpress_company_local',
                'external_vault_required'=>false,
                'local_encryption_available'=>$encryptionReady,
                'credential_required'=>$secretRequired,
                'credential_saved_locally'=>$storedLocally,
                'adapter_installed'=>$adapterInstalled,
                'missing'=>array_values(array_unique($reasons)),
                'connection_verified'=>($row['status']??'')==='connected_verified'
                    && !$reasons && ($row['verified_connection_receipt']??null)!==null,
                'external_delivery_enabled'=>($row['external_delivery_enabled']??false)===true,
            ];
        }
        return $out;
    }

    /** Redacted audit trail: ids and changed field names only, no credentials. */
    private static function audit(string $event,string $id,array $changed): void {
        $entries=get_option(self::AUDIT,[]);
        if(!is_array($entries))$entries=[];
        $entries[]=[
            'time'=>gmdate('c'),'event'=>$event,'channel'=>$id,
            'actor_id'=>get_current_user_id(),
            'fields'=>array_values(array_intersect($changed,
                ['account_alias','credential_reference','endpoint_host','scope_languages',
                 'scope_markets','linked_channels','channel_registered'])),
            'external_send_performed'=>false,
        ];
        update_option(self::AUDIT,array_slice($entries,-100),false);
    }

    /** No API tokens, passwords, private keys or untrusted remote requests here. */
    public static function configure(string $id,array $input) {
        if (!current_user_can('manage_options')) return new WP_Error('channel_admin_required');
        $id=sanitize_key($id);
        if (!preg_match('/^[a-z][a-z0-9_]{2,63}$/D',$id)) return new WP_Error('invalid_channel_id');
        $rows=self::rows();
        if (!isset($rows[$id]) || !is_array($rows[$id])) return new WP_Error('channel_unknown');
        $allowed=['account_alias','credential_reference','endpoint_host','scope_languages','scope_markets','linked_channels'];
        foreach($input as $key=>$value){
            if (!in_array($key,$allowed,true)) return new WP_Error('channel_field_not_configurable');
            if ($key==='credential_reference') {
                if (!is_string($value) || ($value!=='' && !preg_match('/^mad4b_vault:[a-zA-Z0-9._-]{3,96}$/D',$value)))
                    return new WP_Error('credential_requires_vault_reference');
            } elseif ($key==='account_alias') {
                if (!is_string($value) || strlen($value)>96 || preg_match('/[\x00-\x1f]/',$value))
                    return new WP_Error('invalid_account_alias');
            } elseif ($key==='endpoint_host') {
                // Metadata only. Absolutely no connection attempts here.
                if (!is_string($value) || ($value!=='' &&
                    (!preg_match('/^(?=.{4,253}$)(?:[a-zA-Z0-9-]+\.)+[a-zA-Z]{2,}$/D',$value)
                     || preg_match('/\.(local|internal|test|invalid)$/i',$value))))
                    return new WP_Error('invalid_public_endpoint_host');
            } else {
                if (!is_array($value) || count($value)>20) return new WP_Error('invalid_channel_scope');
                foreach($value as $item) {
                    if (!is_string($item) || !preg_match('/^[a-zA-Z0-9_-]{2,63}$/D',$item))
                        return new WP_Error('invalid_channel_scope_item');
                    if ($key==='linked_channels' && (!isset($rows[$item]) || $item===$id))
                        return new WP_Error('invalid_linked_channel');
                }
                $input[$key]=array_values(array_unique($value));
            }
        }
        $old=$rows[$id];$next=$old;$changed=[];
        foreach($input as $key=>$value) {
            if(($next[$key]??null)!==$value)$changed[]=$key;
            $next[$key]=$value;
        }
        if (!$changed) return ['state'=>'UNCHANGED','channel'=>$id];
        $next['company_override']=true;
        $next['status']='configuration_pending_verification';
        $next['external_delivery_enabled']=false;
        $next['publishing_enabled']=false;
        $next['verified_connection_receipt']=null;
        $next['configuration_revision']=(int)($old['configuration_revision']??0)+1;
        $next['configuration_updated_at']=gmdate('c');
        $rows[$id]=$next;
        if (!update_option(self::STORE,$rows,false)) return new WP_Error('channel_config_save_failed');
        if (get_option(self::STORE,[])!==$rows) return new WP_Error('channel_config_readback_failed');
        self::audit('connection_config_updated',$id,$changed);
        return ['state'=>'CONFIGURED_AWAITING_VERIFICATION','channel'=>$id,
            'external_delivery_enabled'=>false];
    }

    /** Resets external readiness before credentials are modified, never auto-enables delivery. */
    public static function invalidate_verification(string $id) {
        if(!current_user_can('manage_options'))return new WP_Error('channel_admin_required');
        $rows=self::rows();
        if(!isset($rows[$id]))return new WP_Error('channel_unknown');
        $old=$rows[$id];
        if(($old['status']??'')==='configuration_pending_verification'
            && empty($old['verified_connection_receipt'])
            && empty($old['external_delivery_enabled'])
            && empty($old['publishing_enabled']))return ['state'=>'ALREADY_UNVERIFIED'];
        $rows[$id]['status']='configuration_pending_verification';
        $rows[$id]['verified_connection_receipt']=null;
        $rows[$id]['external_delivery_enabled']=false;
        $rows[$id]['publishing_enabled']=false;
        if(!update_option(self::STORE,$rows,false))return new WP_Error('channel_invalidation_failed');
        if((self::rows()[$id]??null)!==$rows[$id])return new WP_Error('channel_invalidation_readback_failed');
        return ['state'=>'UNVERIFIED','external_delivery_enabled'=>false];
    }

    public static function change_local_credential(string $id,string $secret='',bool $delete=false) {
        if(!current_user_can('manage_options'))return new WP_Error('channel_admin_required');
        if(!class_exists('MAD4B_Distribution_Local_Credentials_V1'))
            return new WP_Error('local_credential_backend_missing');
        $reset=self::invalidate_verification($id);
        if(is_wp_error($reset))return $reset;
        $result=$delete ? MAD4B_Distribution_Local_Credentials_V1::delete($id)
            : MAD4B_Distribution_Local_Credentials_V1::put($id,$secret);
        if(!is_wp_error($result))self::audit($delete?'credential_revoked':'credential_encrypted_locally',
            $id,['credential_reference']);
        return $result;
    }

    /** Add a new marketplace/partner channel without copying another company's account. */
    public static function create_custom(array $input) {
        if (!current_user_can('manage_options')) return new WP_Error('channel_admin_required');
        $id=sanitize_key((string)($input['id']??''));
        $label=trim((string)($input['label']??''));
        $transport=sanitize_key((string)($input['transport']??''));
        $category=sanitize_key((string)($input['category']??''));
        if (!preg_match('/^custom_[a-z0-9_]{3,55}$/D',$id)
            || $label==='' || mb_strlen($label)>90
            || !in_array($transport,['api','partner_api','sftp','feed','webhook','manual'],true)
            || !in_array($category,['ota_marketplace','partner_distribution','social_catalog','advertising','other'],true))
            return new WP_Error('invalid_custom_channel');
        $rows=self::rows();
        if (isset($rows[$id]))return new WP_Error('custom_channel_exists');
        if (count($rows)>=48)return new WP_Error('channel_registry_limit');
        $row=self::default_row($id,[
            'label'=>sanitize_text_field($label),'category'=>$category,
            'transport'=>$transport,'formats'=>['provider_contract_defined'],
            'content'=>['tour','destination'],'integration'=>'custom_adapter_required',
            'approval'=>'partner_enrollment_required',
        ]);
        $row['source']='company_local_custom';
        $row['company_override']=true;
        $rows[$id]=$row;
        if (!update_option(self::STORE,$rows,false))return new WP_Error('custom_channel_write_failed');
        if ((get_option(self::STORE,[])[$id]??null)!==$row)return new WP_Error('custom_channel_readback_failed');
        self::audit('channel_registered',$id,['channel_registered']);
        return ['state'=>'CUSTOM_CHANNEL_REGISTERED','channel'=>$id,
            'connection'=>'not_connected','external_delivery_enabled'=>false];
    }

    public static function admin_post(): void {
        if (!current_user_can('manage_options')) wp_die('Not authorized',403);
        check_admin_referer('mad4b_distribution_channel_config');
        $op=sanitize_key((string)($_POST['operation']??''));
        if ($op==='create') {
            $result=self::create_custom([
                'id'=>wp_unslash((string)($_POST['channel_id']??'')),
                'label'=>wp_unslash((string)($_POST['label']??'')),
                'transport'=>wp_unslash((string)($_POST['transport']??'')),
                'category'=>wp_unslash((string)($_POST['category']??''))]);
        } elseif ($op==='link') {
            $refs=(array)($_POST['linked_channels']??[]);
            $result=self::configure(sanitize_key((string)($_POST['channel_id']??'')),
                ['linked_channels'=>array_map('sanitize_key',array_map('wp_unslash',$refs))]);
        } elseif ($op==='secret_save' || $op==='secret_delete') {
            $id=sanitize_key((string)($_POST['channel_id']??''));
            $raw=$op==='secret_save' && is_string($_POST['credential_value']??null)
                ?wp_unslash($_POST['credential_value']):'';
            $result=self::change_local_credential($id,$raw,$op==='secret_delete');
        } elseif ($op==='configure') {
            // Blank fields in the UI mean preserve: never inadvertently revoke
            // an existing account alias, endpoint, or Vault pointer.
            $input=[];
            foreach(['account_alias','endpoint_host'] as $name){
                $value=sanitize_text_field(wp_unslash((string)($_POST[$name]??'')));
                if($value!=='')$input[$name]=$value;
            }
            if(!$input) $result=new WP_Error('connection_no_fields_supplied');
            else $result=self::configure(sanitize_key((string)($_POST['channel_id']??'')),$input);
        } else $result=new WP_Error('invalid_channel_operation');
        $code=is_wp_error($result)?$result->get_error_code():'saved';
        $selected=sanitize_key((string)($_POST['channel_id']??''));
        $args=['page'=>'mad4b-distribution-channels','channel_notice'=>$code];
        if(isset(self::rows()[$selected]))$args['channel']=$selected;
        $redirect=add_query_arg($args,admin_url('tools.php'));
        wp_safe_redirect($redirect.'#mad4b-dc-channel-settings');
        exit;
    }

    public static function rest(): void {
        register_rest_route('mad4b-distribution/v1','/channels',[
            'methods'=>'POST',
            'permission_callback'=>static fn()=>current_user_can('manage_options'),
            'callback'=>static function(WP_REST_Request $r){
                $result=self::create_custom((array)$r->get_json_params());
                return is_wp_error($result)?$result:rest_ensure_response($result);
            },
        ]);
        register_rest_route('mad4b-distribution/v1','/channels',[
            'methods'=>'GET','permission_callback'=>static fn()=>current_user_can('manage_options'),
            'callback'=>static function(){
                $r=rest_ensure_response(['contract'=>self::CONTRACT,'channels'=>self::public_status(),
                    'connection_health'=>self::connection_health(),
                    'local_credentials'=>class_exists('MAD4B_Distribution_Local_Credentials_V1')
                        ?MAD4B_Distribution_Local_Credentials_V1::status():null,
                    'company_home'=>home_url('/'),'central_policy_inheritance'=>'extend_or_override_local',
                    'external_uploads_executed'=>false]);
                $r->header('Cache-Control','private, no-store');return $r;
            },
        ]);
        register_rest_route('mad4b-distribution/v1','/channels/(?P<id>[a-z0-9_]+)/credential',[
            'methods'=>'POST',
            'permission_callback'=>static fn()=>current_user_can('manage_options'),
            'callback'=>static function(WP_REST_Request $request){
                $params=(array)$request->get_json_params();
                $secret=$params['credential']??null;
                if(!is_string($secret))return new WP_Error('credential_string_required');
                $r=self::change_local_credential((string)$request['id'],$secret);
                if(is_wp_error($r))return $r;
                $response=rest_ensure_response($r);
                $response->header('Cache-Control','no-store, private');
                return $response;
            },
        ]);
        register_rest_route('mad4b-distribution/v1','/channels/(?P<id>[a-z0-9_]+)/credential',[
            'methods'=>'DELETE',
            'permission_callback'=>static fn()=>current_user_can('manage_options'),
            'callback'=>static function(WP_REST_Request $request){
                $r=self::change_local_credential((string)$request['id'],'',true);
                return is_wp_error($r)?$r:rest_ensure_response($r);
            },
        ]);
        register_rest_route('mad4b-distribution/v1','/channels/(?P<id>[a-z0-9_]+)',[
            'methods'=>'POST','permission_callback'=>static fn()=>current_user_can('manage_options'),
            'callback'=>static function(WP_REST_Request $request){
                $result=self::configure((string)$request['id'],(array)$request->get_json_params());
                return is_wp_error($result)?$result:rest_ensure_response($result);
            },
        ]);
    }

    public static function menu(): void {
        add_management_page('MAD4B Distribution Channels','MAD4B Distribution Channels',
            'manage_options','mad4b-distribution-channels',[__CLASS__,'screen']);
    }
    public static function screen(): void {
        if (!current_user_can('manage_options')) wp_die('Access denied');
        if (!class_exists('MAD4B_Distribution_Admin_V1'))
            wp_die('The company distribution console is not installed.');
        MAD4B_Distribution_Admin_V1::render();
    }
    public static function boot(): void {
        add_action('rest_api_init',[__CLASS__,'rest']);
        add_action('admin_menu',[__CLASS__,'menu']);
        add_action('admin_post_mad4b_distribution_channel_save',[__CLASS__,'admin_post']);
    }
}
MAD4B_Distribution_Channel_Registry_V1::boot();
