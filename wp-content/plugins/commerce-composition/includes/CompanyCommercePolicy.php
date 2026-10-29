<?php
declare(strict_types=1);
namespace Commerce\Composition;

/**
 * Site-bound company business intent, inherited from native Woo CPT identity
 * and existing RateProvenance relations. Does not calculate or authorize sales.
 */
final class CompanyCommercePolicy
{
    public const OPTION='commerce_company_policy_registry_v1';
    public const CONTRACT='commerce.company-policy.v1';
    // A source Rate context term may be duration-options, not an itinerary.
    private const SCOPES=['company','service_type','product','rate_context_term','rate','market','channel','override'];
    private const STAGES=['company','service_type','product','rate_context_term','rate','market','channel','override'];
    private const SETTINGS=[
        'pricing_source'=>['rate_engine','supplier_contract','manual_quote'],
        'supplier_cost_source'=>['provider','manual_review'],
        'margin_mode'=>['percent','fixed','manual_review'],
        'tax_mode'=>['woocommerce','external','manual_review'],
        'availability_mode'=>['live_provider','allotment','manual_confirmation'],
        'manager_approval'=>['required','conditional','not_required'],
        'traveler_acceptance'=>['required','channel_verified'],
        'order_timing'=>['after_acceptance','after_payment','manual'],
        'supplier_booking_timing'=>['after_order','after_payment','manual'],
        'deposit_mode'=>['full','percent','manual_review'],
        'currency_source'=>['woocommerce','rate','manual_review'],
        'rate_sale_mode'=>['parent_with_rate','independent_rate','both','disabled']
    ];
    private static function problem(string $code):\WP_Error {
        return new \WP_Error($code,'Company commerce policy unavailable');
    }
    public static function context():array|\WP_Error {
        if(!class_exists('MAD4B_SCP_Site_Profile'))
            return self::problem('policy_site_profile_missing');
        $p=\MAD4B_SCP_Site_Profile::profile();
        $s=\MAD4B_SCP_Site_Profile::status();
        $company=get_option('commerce_management_company_definitions_v1',[]);
        if(!is_array($p)||!is_array($s)||!is_array($company)
            ||($p['contract']??null)!=='mad4b.site-profile.v2'
            ||($company['contract']??null)!=='commerce.management.company.v1'
            ||!is_string($p['site_uuid']??null)||$p['site_uuid']===''
            ||($company['site_uuid']??null)!==$p['site_uuid']
            ||(int)($company['site_profile_revision']??-1)!==(int)($p['revision']??0)
            ||!is_string($s['profile_digest']??null)
            ||($company['site_profile_digest']??null)!==$s['profile_digest']
            ||($company['environment']??null)!==($p['environment']??null))
            return self::problem('policy_company_binding_invalid');
        $rawContextFields=(array)($company['rate_context_term_fields']??[]);
        $contextFields=[];
        foreach($rawContextFields as $rateType=>$metaName) {
            if(!is_string($rateType)||!in_array($rateType,
                (array)($company['source_types']['rates']??[]),true)||
               !is_string($metaName)||
               !preg_match('/^_?[a-zA-Z][a-zA-Z0-9_-]{0,119}$/D',$metaName))
                return self::problem('policy_rate_context_mapping_invalid');
            $contextFields[$rateType]=$metaName;
        }
        return [
            'site_uuid'=>$p['site_uuid'],'profile_revision'=>(int)$p['revision'],
            'profile_digest'=>$s['profile_digest'],'environment'=>$p['environment'],
            'company_revision'=>(int)($company['revision']??0),
            'service_types'=>(array)($company['source_types']['services']??[]),
            'rate_types'=>(array)($company['source_types']['rates']??[]),
            'rate_context_fields'=>$contextFields,
            'site_write_ready'=>($s['authority_ready']??false)===true
                &&($s['write_enabled']??false)===true
                &&($s['origin_match']??false)===true
                &&($s['environment_match']??false)===true
                &&($s['deployment_binding_match']??false)===true
                &&($s['profile_authority_quarantined']??true)===false
        ];
    }
    private static function checkSettings(array $settings):bool {
        if($settings===[]||count($settings)>25)return false;
        foreach($settings as $k=>$v) {
            if(!is_string($k))return false;
            if($k==='margin_value'||$k==='deposit_percent'){
                if(!is_int($v)&&!is_float($v))return false;
                if(!is_finite((float)$v)||(float)$v<0||(float)$v>($k==='deposit_percent'?100:1000000))
                    return false;
            }elseif(!isset(self::SETTINGS[$k])||!is_string($v)
                ||!in_array($v,self::SETTINGS[$k],true))return false;
        }
        if(array_key_exists('margin_value',$settings) &&
            !isset($settings['margin_mode']))return false;
        if(array_key_exists('deposit_percent',$settings) &&
            ($settings['deposit_mode']??null)!=='percent')return false;
        return true;
    }
    public static function validateCandidate(array $candidate):array|\WP_Error {
        $ctx=self::context();
        if(is_wp_error($ctx))return $ctx;
        return self::validateDocument($candidate,$ctx);
    }
    /** Strict schema and binding. Unconfigured policies are never synthesized. */
    public static function registry():array|\WP_Error {
        $ctx=self::context();
        if(is_wp_error($ctx))return $ctx;
        $raw=get_option(self::OPTION,[]);
        if(!is_array($raw)||$raw===[])return self::problem('policy_registry_unconfigured');
        return self::validateDocument($raw,$ctx);
    }
    private static function validateDocument(array $raw,array $ctx):array|\WP_Error {
        foreach(['contract','site_uuid','profile_digest','environment','status'] as $key)
            if(!is_string($raw[$key]??null))return self::problem('policy_registry_invalid');
        if($raw['contract']!==self::CONTRACT||
            !hash_equals($ctx['site_uuid'],$raw['site_uuid'])||
            !hash_equals($ctx['profile_digest'],$raw['profile_digest'])||
            $raw['environment']!==$ctx['environment']||
            !is_int($raw['profile_revision']??null)||
            $raw['profile_revision']!==$ctx['profile_revision']||
            !is_int($raw['revision']??null)||$raw['revision']<1||
            !in_array($raw['status'],['draft','active','retired'],true)||
            !is_array($raw['rules']??null)||!array_is_list($raw['rules'])||
            count($raw['rules'])<1||count($raw['rules'])>100)
            return self::problem('policy_registry_binding_or_schema_invalid');
        if(isset($raw['intents'])&&(!is_array($raw['intents'])||
           !array_is_list($raw['intents'])||count($raw['intents'])>32))
            return self::problem('policy_intents_invalid');
        $ids=[];$stages=[];$companyCount=0;
        foreach($raw['rules'] as $rule){
            if(!is_array($rule)||!is_string($rule['id']??null)||
                !preg_match('/^[a-z][a-z0-9_-]{2,63}$/D',$rule['id'])||
                isset($ids[$rule['id']])||!in_array($rule['scope']??null,self::SCOPES,true)||
                !is_array($rule['selectors']??null)||
                !is_array($rule['settings']??null)||
                !self::checkSettings($rule['settings']))return self::problem('policy_rule_invalid');
            $ids[$rule['id']]=true;
            $scope=$rule['scope'];$sel=$rule['selectors'];
            if(count($sel)>5)return self::problem('policy_selectors_invalid');
            $keys=array_keys($sel);sort($keys);
            $expected=match($scope){
              'company'=>[],'service_type'=>['service_type'],
              'product'=>['product_id'],'rate_context_term'=>['rate_context_term_id'],
              'rate'=>['rate_id'],
              'market'=>['market'],'channel'=>['channel'],default=>null};
            if($expected!==null&&$keys!==$expected)
                return self::problem('policy_scope_selectors_invalid');
            if($scope==='company')$companyCount++;
            if($scope==='override'&&count($sel)<2)
                return self::problem('policy_override_not_specific');
            foreach($sel as $key=>$value){
                if(!in_array($key,['service_type','product_id','rate_context_term_id','rate_id','market','channel'],true))
                    return self::problem('policy_selector_key_invalid');
                if(in_array($key,['product_id','rate_context_term_id','rate_id'],true)){
                    if(!is_int($value)||$value<1)return self::problem('policy_selector_id_invalid');
                }elseif(!is_string($value)||!preg_match(
                    $key==='market'?'/^[A-Z]{2}$/D':'/^[a-z][a-z0-9_-]{1,63}$/D',$value))
                    return self::problem('policy_selector_value_invalid');
            }
            $stageKey=$scope.'|'.wp_json_encode($sel);
            if(isset($stages[$stageKey]))return self::problem('policy_duplicate_selector');
            $stages[$stageKey]=true;
        }
        if($companyCount!==1)return self::problem('policy_company_default_missing');
        // Every Policy must define all required defaults. Scope refinements
        // cannot silently cause a missing financial or booking decision.
        $defaults=[];
        foreach($raw['rules'] as $rule){
            if($rule['scope']==='company')$defaults=$rule['settings'];
        }
        $required=['pricing_source','supplier_cost_source','margin_mode','tax_mode',
            'availability_mode','manager_approval','traveler_acceptance','order_timing',
            'supplier_booking_timing','deposit_mode','currency_source','rate_sale_mode'];
        foreach($required as $setting){
            if(!array_key_exists($setting,$defaults))
                return self::problem('policy_default_missing_'.$setting);
        }
        return $raw;
    }

    /**
     * Existing per-site policy can be replaced only after authoritative Site
     * Profile recovery. Exact expected revision, idempotency, atomic CAS.
     * Never performs Woo or supplier side effects.
     */
    public static function replace(array $payload,string $intent):array|\WP_Error {
        global $wpdb;
        if(!current_user_can('manage_options')||!current_user_can('manage_woocommerce'))
            return self::problem('policy_permission_denied');
        $ctx=self::context();
        if(is_wp_error($ctx))return $ctx;
        if(!$ctx['site_write_ready'])return self::problem('policy_site_authority_not_ready');
        if(!preg_match('/^[A-Za-z0-9._:-]{16,128}$/D',$intent))
            return self::problem('policy_intent_key_invalid');
        $keys=array_keys($payload);sort($keys);
        $expected=['site_uuid','profile_revision','company_revision',
            'expected_revision','status','rules'];sort($expected);
        if($keys!==$expected||!is_string($payload['site_uuid'])||
           !hash_equals($ctx['site_uuid'],$payload['site_uuid'])||
           !is_int($payload['profile_revision'])||
           $payload['profile_revision']!==$ctx['profile_revision']||
           !is_int($payload['company_revision'])||
           $payload['company_revision']!==$ctx['company_revision']||
           !is_int($payload['expected_revision'])||
           !is_string($payload['status'])||
           !is_array($payload['rules']))
            return self::problem('policy_command_invalid');
        $old=self::registry();
        if(is_wp_error($old))return $old;
        $intentHash=hash('sha256',$intent);
        $payloadHash=hash('sha256',wp_json_encode($payload,JSON_UNESCAPED_SLASHES));
        foreach(($old['intents']??[]) as $receipt){
            if(($receipt['intent_sha256']??null)!==$intentHash)continue;
            if(($receipt['payload_sha256']??null)===$payloadHash)
                return ['state'=>'idempotent_replay',
                    'revision'=>(int)($receipt['revision']??0),'no_financial_execution'=>true];
            return self::problem('policy_idempotency_conflict');
        }
        if($old['revision']!==$payload['expected_revision'])
            return self::problem('policy_revision_conflict');
        $new=$old;
        $new['revision']=$old['revision']+1;
        $new['status']=$payload['status'];
        $new['rules']=$payload['rules'];
        $new['intents']=array_slice(array_merge((array)($old['intents']??[]),[[
            'intent_sha256'=>$intentHash,'payload_sha256'=>$payloadHash,
            'revision'=>$new['revision'],'actor_id'=>get_current_user_id(),
            'created_utc'=>gmdate('c')]]),-32);
        $valid=self::validateDocument($new,$ctx);
        if(is_wp_error($valid))return $valid;
        $table=$wpdb->options;
        $originalSerialized=maybe_serialize($old);
        $serialized=maybe_serialize($new);
        $changed=$wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET option_value=%s WHERE option_name=%s AND option_value=%s",
            $serialized,self::OPTION,$originalSerialized
        ));
        if($changed!==1)return self::problem('policy_concurrent_update_conflict');
        wp_cache_delete(self::OPTION,'options');
        wp_cache_delete('alloptions','options');
        $readback=get_option(self::OPTION,[]);
        if(!is_array($readback)||$readback['revision']!==$new['revision']||
           $readback['status']!==$new['status'])
            return self::problem('policy_commit_readback_unconfirmed');
        return ['state'=>'replaced','revision'=>$new['revision'],
            'status'=>$new['status'],'policy_intent_only'=>true,
            'no_financial_execution'=>true];
    }

    /**
     * Source can be a Woo-mapped service CPT or a Woo-mapped Rate CPT.
     * The source's original parent is verified via the existing Rate Registry.
     * No new product records or copied relationship meta are created.
     */
    public static function identity(int $sourceId,?int $explicitRate=null):array|\WP_Error {
        $ctx=self::context();
        if(is_wp_error($ctx))return $ctx;
        if($sourceId<1||($explicitRate!==null&&$explicitRate<1))
            return self::problem('policy_source_invalid');
        $post=get_post($sourceId);
        if(!($post instanceof \WP_Post)||$post->post_status!=='publish')
            return self::problem('policy_source_not_published');
        $rateId=$explicitRate;
        $serviceId=$sourceId;
        $relationSource='none';
        $registry=RateProvenance::registry();
        if(is_wp_error($registry))return $registry;
        if(in_array($post->post_type,$ctx['rate_types'],true)){
            if($explicitRate!==null&&$explicitRate!==$sourceId)
                return self::problem('policy_rate_conflicting_identity');
            $rateId=$sourceId;
            $matches=[];
            foreach($registry['definitions'] as $def)
                if(($def['rate_post_type']??null)===$post->post_type)
                    $matches[]=$def;
            if(count($matches)!==1)
                return self::problem('policy_rate_relation_unconfigured');
            $def=$matches[0];
            if(!in_array($def['parent_post_type']??'', $ctx['service_types'],true))
                return self::problem('policy_rate_parent_type_not_registered');
            $serviceId=(int)get_post_meta($rateId,$def['parent_meta'],true);
            $relationSource='rate_provenance_registry';
        }
        $service=get_post($serviceId);
        if(!($service instanceof \WP_Post)||$service->post_status!=='publish'||
            !in_array($service->post_type,$ctx['service_types'],true))
            return self::problem('policy_service_not_authorized');
        if($rateId!==null){
            $relation=RateProvenance::verify($serviceId,$rateId);
            if(is_wp_error($relation))return $relation;
            $relationSource='rate_provenance_registry';
        }
        $selected=get_option('cptwooint_settings',[]);
        $selected=is_array($selected)?($selected['selected_post_types']??[]):[];
        $selected=is_array($selected)?$selected:[];
        $wcService=isset($selected[$service->post_type])&&function_exists('wc_get_product')?
            wc_get_product($serviceId):null;
        $mapped=$wcService instanceof \WC_Product &&
            (int)$wcService->get_id()===$serviceId;
        $ratePost=$rateId!==null?get_post($rateId):null;
        $rateWc=$ratePost instanceof \WP_Post&&isset($selected[$ratePost->post_type])
            &&function_exists('wc_get_product')?wc_get_product($rateId):null;
        $rateMapped=$rateWc instanceof \WC_Product&&(int)$rateWc->get_id()===$rateId;
        // Optional native Rate term reference; inspect its real taxonomy rather
        // than assuming every Rate term is an itinerary program.
        $contextMeta=$ratePost instanceof \WP_Post?
            ($ctx['rate_context_fields'][$ratePost->post_type]??null):null;
        $termRef=$rateId!==null&&is_string($contextMeta)?
            get_post_meta($rateId,$contextMeta,true):null;
        $termId=(is_int($termRef)||is_string($termRef)&&preg_match('/^[1-9][0-9]*$/D',$termRef))
            ?(int)$termRef:null;
        $term=$termId!==null?get_term($termId):null;
        $termVerified=$term instanceof \WP_Term;
        return [
            'source_id'=>$sourceId,'service_id'=>$serviceId,
            'service_type'=>$service->post_type,'rate_id'=>$rateId,
            'relation_source'=>$relationSource,'relationship_verified'=>$rateId!==null,
            'cpt_integration_owner'=>'cpt_product_integration',
            'wc_service_product_id'=>$mapped?$serviceId:null,
            'wc_service_identity_readable'=>$mapped,
            'wc_rate_identity_readable'=>$rateMapped,
            'rate_context'=>[
                'source_meta'=>$contextMeta,
                'term_id'=>$termVerified?$termId:null,
                'taxonomy'=>$termVerified?$term->taxonomy:null,
                'verified'=>$termVerified,
                'is_certified_itinerary_program'=>false
            ],
            'rate_wc_product_id'=>$rateMapped?$rateId:null,
            'rate_wc_product_identity_readable'=>$rateMapped,
            'rate_is_independent_sale_item'=>null,
            'wc_raw_price_is_final_quote'=>false
        ];
    }

    public static function resolve(int $sourceId,?int $rateId=null,string $market='',
        string $channel='web'):array|\WP_Error {
        $ctx=self::context();if(is_wp_error($ctx))return $ctx;
        if($market!==''&&!preg_match('/^[A-Z]{2}$/D',$market))
            return self::problem('policy_market_invalid');
        if(!preg_match('/^[a-z][a-z0-9_-]{1,63}$/D',$channel))
            return self::problem('policy_channel_invalid');
        $identity=self::identity($sourceId,$rateId);
        if(is_wp_error($identity))return $identity;
        $registry=self::registry();if(is_wp_error($registry))return $registry;
        $facts=[
            'service_type'=>$identity['service_type'],
            'product_id'=>$identity['service_id'],
            'rate_id'=>$identity['rate_id'],
            'rate_context_term_id'=>$identity['rate_context']['term_id'],
            'market'=>$market,'channel'=>$channel
        ];
        $effective=[];$from=[];$matched=[];
        foreach(self::STAGES as $stage){
            $hits=[];
            foreach($registry['rules'] as $rule){
                if($rule['scope']!==$stage)continue;
                $ok=true;
                foreach($rule['selectors'] as $key=>$expected)
                    if(($facts[$key]??null)!==$expected){$ok=false;break;}
                if($ok)$hits[]=$rule;
            }
            if(count($hits)>1)return self::problem('policy_ambiguous_'.$stage);
            if(!$hits)continue;
            foreach($hits[0]['settings'] as $key=>$value){
                $effective[$key]=$value;
                $from[$key]=$hits[0]['id'];
            }
            $matched[]=['scope'=>$stage,'rule_id'=>$hits[0]['id']];
        }
        $required=['pricing_source','supplier_cost_source','margin_mode','tax_mode',
            'availability_mode','manager_approval','traveler_acceptance','order_timing',
            'supplier_booking_timing','deposit_mode','currency_source','rate_sale_mode'];
        foreach($required as $key)if(!array_key_exists($key,$effective))
            return self::problem('policy_required_default_'.$key);
        if($effective['margin_mode']==='manual_review')unset($effective['margin_value']);
        if($effective['deposit_mode']!=='percent')unset($effective['deposit_percent']);
        // Sell mode is a company option, never an unconditional purchasability
        // override: parent/rate Woo identity and native checkout gates remain.
        $sellMode=$effective['rate_sale_mode'];
        $rateIndependent=$identity['rate_id']!==null
            &&in_array($sellMode,['independent_rate','both'],true)
            &&$identity['rate_wc_product_identity_readable']===true;
        $parentSale=$identity['wc_service_identity_readable']===true
            &&in_array($sellMode,['parent_with_rate','both'],true);
        $identity['rate_is_independent_sale_item']=$rateIndependent;
        $identity['sale_item_choices']=[
            'parent_service'=>[
                'product_id'=>$parentSale?$identity['wc_service_product_id']:null,
                'policy_selected'=>$parentSale
            ],
            'independent_rate'=>[
                'product_id'=>$rateIndependent?$identity['rate_wc_product_id']:null,
                'policy_selected'=>$rateIndependent
            ]
        ];
        return [
            'contract'=>'commerce.company-policy-resolution.v1',
            'site_uuid'=>$ctx['site_uuid'],'environment'=>$ctx['environment'],
            'status'=>$registry['status'],
            'policy_revision'=>$registry['revision'],
            'company_definition_revision'=>$ctx['company_revision'],
            'site_profile_revision'=>$ctx['profile_revision'],
            'source'=>$identity,'facts'=>$facts,
            'effective'=>$effective,'provenance'=>$from,'matched_rules'=>$matched,
            // Same Rate + company + market/channel + effective policy must
            // produce the same decision digest from either the Tour page or
            // the Rate's independent Woo Product page.
            'policy_digest'=>hash('sha256',wp_json_encode([
                $ctx['site_uuid'],$registry['revision'],
                $facts,$effective,$from,
                $identity['sale_item_choices']
            ],JSON_UNESCAPED_SLASHES)),
            'company_policy_intent_only'=>true,
            'provider_price_calculated'=>false,
            'supplier_cost_certified'=>false,
            'tax_certified'=>false,
            'inventory_certified'=>false,
            'manager_approval_verified'=>false,
            'traveler_acceptance_verified'=>false,
            'woocommerce_order_authorized'=>false,
            'checkout_authorized'=>false,
            'supplier_booking_authorized'=>false,
            'execution_blockers'=>array_values(array_unique(array_filter([
                $registry['status']==='active'?null:'policy_not_active',
                !$ctx['site_write_ready']?'site_authority_not_ready':null,
                !$parentSale&&!$rateIndependent?'policy_sellable_cpt_identity_missing':null,
                'certified_commercial_quote_missing','provider_execution_evidence_missing'
            ])))
        ];
    }
}
