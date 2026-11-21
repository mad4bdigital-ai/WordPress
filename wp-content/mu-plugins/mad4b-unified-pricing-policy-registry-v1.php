<?php
/**
 * Plugin Name: MAD4B Unified Pricing Policy Read Registry
 * Version: 1.0.0
 * Description: One normalized, read-only rule catalog across native WordPress / JetEngine policy sources; never duplicates or applies pricing.
 */
defined('ABSPATH')||exit;

final class MAD4B_Unified_Pricing_Policy_Registry_V1 {
    public const SCHEMA='mad4b.pricing-policy-registry.read.v1';

    private static function selected($value):bool {
        return in_array($value,[true,1,'1','true','on','yes'],true);
    }
    private static function canonical(int $id,string $taxonomy):int {
        if($id<1)return 0;
        $en=apply_filters('wpml_object_id',$id,$taxonomy,false,'en');
        return is_numeric($en)&&(int)$en>0?(int)$en:$id;
    }
    private static function safeName(string $value):string {
        return mb_substr(sanitize_text_field($value),0,145);
    }
    private static function record(
        string $family,string $id,string $name,string $source,string $dimension,
        string $status,string $effect,array $data=[]
    ):array {
        return [
            'id'=>$family.':'.$id,'family'=>$family,'name'=>self::safeName($name),
            'source'=>$source,'dimension'=>$dimension,'status'=>$status,
            'effect'=>$effect,'data'=>$data,
            'commercial_active'=>false,'authorized_to_change_rate'=>false,
        ];
    }
    /**
     * Authoritative sources remain their existing JetEngine options and
     * read-only metadata. This normalized projection is NOT a new price ledger.
     */
    public static function snapshot():array|WP_Error {
        if(!current_user_can('commerce_manage_pricing') ||
           !current_user_can('manage_woocommerce'))
           return new WP_Error('unified_pricing_registry_forbidden');

        $rows=[];$warnings=[];$sources=[];
        $add=static function(array $entry)use(&$rows):void{$rows[]=$entry;};

        // Existing self-contained Tour Type + Package Level preview controller.
        $dynamic=get_option('mad4b_dynamic_dimension_markup_v1',null);
        $sources['tour_type_markup']=[
            'option'=>'mad4b_dynamic_dimension_markup_v1',
            'configured'=>is_array($dynamic),
            'source_kind'=>'preview_controller',
        ];
        if(is_array($dynamic)){
            $valid=($dynamic['schema']??'')==='mad4b.dynamic-dimensions.v1'
                &&($dynamic['sequence']??[])===['tour_type','package_level'];
            $enabled=($dynamic['enabled']??false)===true;
            $preview=($dynamic['preview_only']??false)===true
                &&($dynamic['commercial_active']??true)===false;
            $grouper=[];
            foreach((array)($dynamic['type_rules']??[]) as $item){
                if(!is_array($item)){$warnings[]='tour_type_row_invalid';continue;}
                $id=(int)($item['term_id']??0);
                $canonical=self::canonical($id,'tour-types');
                $term=$canonical?get_term($canonical,'tour-types'):null;
                if(!($term instanceof WP_Term)){$warnings[]='tour_type_term_unresolved';continue;}
                $pct=$item['increase_percent']??null;
                $enabledRow=self::selected($item['enabled']??false);
                $grouper[$canonical][]=['id'=>$id,'pct'=>$pct,'enabled'=>$enabledRow];
            }
            foreach($grouper as $id=>$items){
                $canonical=(int)$id;
                $term=get_term($canonical,'tour-types');
                $pcts=array_unique(array_map(static fn($x)=>json_encode(
                    [$x['pct'],$x['enabled']]),$items));
                $same=count($pcts)===1;
                $pct=$items[0]['pct'];
                $validAmount=is_numeric($pct)&&is_finite((float)$pct)
                    &&(float)$pct>=0&&(float)$pct<=300;
                $status=!$valid||!$enabled||!$preview||!$same||!$validAmount
                    ?'blocked_source_or_conflict'
                    :($items[0]['enabled']?'preview_eligible':'disabled');
                $add(self::record('tour_type',strval($canonical),
                    $term->name,'mad4b_dynamic_dimension_markup_v1',
                    'taxonomy:tour-types',$status,
                    $validAmount?'+'.(float)$pct.'%':'invalid',
                    ['term_id'=>$canonical,'source_term_ids'=>array_column($items,'id'),
                    'percent'=>$validAmount?(float)$pct:null,
                    'duplicates_consolidated'=>count($items)>1,'runtime'=>'preview_only']));
            }
            if(!$valid||!$preview)$warnings[]='tour_type_controller_invalid_or_not_preview_only';
        }

        // Native JetEngine Package Levels, canonicalized across WPML language terms.
        $nativePackage=get_option('etg-dynamic-pricing-controls_shared_package_pricing_rows',[]);
        $sources['package_levels']=[
            'option'=>'etg-dynamic-pricing-controls_shared_package_pricing_rows',
            'configured'=>is_array($nativePackage)&&count($nativePackage)>0,
            'source_kind'=>'native_jetengine_options_page',
        ];
        $groups=[];
        foreach((array)(is_array($nativePackage)?$nativePackage:[]) as $i=>$item){
            if(!is_array($item)){$warnings[]='package_row_invalid';continue;}
            $termId=(int)($item['package_term_id']??0);
            $canon=self::canonical($termId,'package_levels');
            $term=$canon?get_term($canon,'package_levels'):null;
            if(!($term instanceof WP_Term)){$warnings[]='package_term_unresolved';continue;}
            $scope=sanitize_key((string)($item['scope']??''));
            $tour=(int)($item['tour_id']??0);
            $rate=(int)($item['rate_id']??0);
            $key=implode(':',[$canon,$scope,$tour,$rate]);
            $groups[$key][]=['source_id'=>$termId,'term'=>$term,'scope'=>$scope,
                'tour_id'=>$tour,'rate_id'=>$rate,'percent'=>$item['percent']??null,
                'enabled'=>self::selected($item['enabled']??false)];
        }
        foreach($groups as $key=>$items){
            $first=$items[0];
            $term=$first['term'];
            $pct=$first['percent'];
            $unique=array_unique(array_map(static fn($x)=>json_encode(
                [$x['percent'],$x['enabled']]),$items));
            $hasConflict=count($unique)>1;
            $valid=is_numeric($pct)&&is_finite((float)$pct)
                &&(float)$pct>=0&&(float)$pct<=300;
            $scopeValid=in_array($first['scope'],['global','tour','rate'],true);
            $targetValid=($first['scope']==='global'&&$first['tour_id']===0&&$first['rate_id']===0)
                ||($first['scope']==='tour'&&$first['tour_id']>0&&$first['rate_id']===0)
                ||($first['scope']==='rate'&&$first['tour_id']===0&&$first['rate_id']>0);
            $termEnabled=self::selected(get_term_meta((int)$term->term_id,
                'package_pricing_enabled',true));
            $state=$hasConflict||!$valid||!$scopeValid||!$targetValid
                ?'blocked_source_or_conflict'
                :(!$first['enabled']?'disabled':
                  (!$termEnabled?'blocked_term_gate':'preview_eligible'));
            $add(self::record('package_level',str_replace(':','_',$key),
                $term->name,'etg-dynamic-pricing-controls_shared_package_pricing_rows',
                'taxonomy:package_levels',$state,
                $valid?'×'.rtrim(rtrim(sprintf('%.3f',(float)$pct/100),'0'),'.'):'invalid',
                ['term_id'=>(int)$term->term_id,
                'source_term_ids'=>array_column($items,'source_id'),
                'scope'=>$first['scope'],'tour_id'=>$first['tour_id'],
                'rate_id'=>$first['rate_id'],
                'percent'=>$valid?(float)$pct:null,
                'term_enabled'=>$termEnabled,'duplicates_consolidated'=>count($items)>1,
                'runtime'=>'preview_only']));
        }

        // Actual price-source decisions and Tour Mode rules, not lab strategies.
        foreach([
          'tour_mode'=>[
              'option'=>'etg-dynamic-pricing-controls_shared_mode_policy_rows',
              'dimension'=>'meta:tour_mode','label'=>'Tour mode',
          ],
          'price_source'=>[
              'option'=>'etg-dynamic-pricing-controls_shared_price_source_mode_rows',
              'dimension'=>'meta:tour_mode','label'=>'Source priority',
          ],
        ] as $family=>$def){
            $option=$def['option'];$items=get_option($option,[]);
            $sources[$family]=['option'=>$option,'source_kind'=>'native_jetengine_options_page',
                'configured'=>is_array($items)&&count($items)>0];
            if(!is_array($items)){$warnings[]=$family.'_invalid_store';continue;}
            $seen=[];
            foreach($items as $i=>$item){
                if(!is_array($item)){$warnings[]=$family.'_invalid_row';continue;}
                $mode=sanitize_key((string)($item['mode']??''));
                if(!$mode){$warnings[]=$family.'_missing_mode';continue;}
                $seen[$mode]=($seen[$mode]??0)+1;
                $enabled=$family==='tour_mode'?
                    self::selected($item['enabled']??false):true;
                $state=$enabled?'configured_native':'disabled';
                $effect=$family==='tour_mode'
                    ?sanitize_key((string)($item['strategy']??'rate_occupancy'))
                    :('priority '.sanitize_key((string)($item['tie_break']??'inherited')));
                $add(self::record($family,$mode,$def['label'].' · '.$mode,
                    $option,$def['dimension'],$state,$effect,
                    ['mode'=>$mode,'enabled'=>$enabled,'runtime'=>'native_provider_configuration']));
            }
            if(array_filter($seen,static fn($count)=>$count>1))
                $warnings[]=$family.'_duplicate_mode_rules';
        }

        // Additional native policy families. These remain provider-owned.
        foreach([
          'group_tier'=>[
            'option'=>'etg-dynamic-pricing-controls_shared_group_tier_pricing_rows',
            'dimension'=>'taxonomy:group-size-tiers','kind'=>'tier',
          ],
          'promotion'=>[
            'option'=>'etg-dynamic-pricing-controls_shared_promotion_rows',
            'dimension'=>'policy:promotion','kind'=>'promotion',
          ],
          'payment_fee'=>[
            'option'=>'etg-dynamic-pricing-controls_shared_payment_fee_rows',
            'dimension'=>'policy:payment_fee','kind'=>'payment',
          ],
        ] as $family=>$def){
            $option=$def['option'];
            $items=get_option($option,[]);
            $sources[$family]=['option'=>$option,
                'source_kind'=>'native_jetengine_options_page',
                'configured'=>is_array($items)&&count($items)>0];
            if($items===''||$items===null)continue;
            if(!is_array($items)){ $warnings[]=$family.'_invalid_store';continue;}
            foreach($items as $idx=>$item){
                if(!is_array($item)){$warnings[]=$family.'_invalid_row';continue;}
                $enabled=self::selected($item['enabled']??($family==='payment_fee'));
                $state=$enabled?'configured_native':'disabled';
                $id=(string)($item['id']??($item['tier_id']??($item['method']??$idx)));
                $value=$item['percent']??null;
                $effect=is_numeric($value)?((float)$value.'%'):'source policy';
                if($family==='group_tier' && $enabled){
                    $tier=(int)($item['tier_id']??0);
                    $rate=(int)($item['rate_id']??0);
                    if($tier<1||$rate<1||!self::selected(get_term_meta($tier,'tier_enabled',true)))
                        $state='blocked_term_gate';
                }
                $add(self::record($family,$id,ucwords(str_replace('_',' ',$family)).
                    ' · '.$id,$option,$def['dimension'],$state,$effect,
                    ['source_index'=>$idx,'runtime'=>'native_provider_configuration']));
            }
        }

        // Pricing Lab is a Draft workspace; never equate it with an active policy.
        $strategySummaries=[];
        $lab=get_option('mad4b_jetengine_pricing_lab_presets_v1',[]);
        $sources['pricing_lab']=['option'=>'mad4b_jetengine_pricing_lab_presets_v1',
            'source_kind'=>'draft_workspace','configured'=>is_array($lab)&&count($lab)>0];
        foreach((array)(is_array($lab)?$lab:[]) as $idx=>$item){
            if(!is_array($item))continue;
            $status=($item['status']??'')==='staged_for_native_review'?'staged':'draft';
            $ids=[];
            foreach((array)($item['rules']??[]) as $rule)
                if(is_array($rule)&&is_string($rule['id']??null)&&
                    preg_match('/^(taxonomy|meta|metabox):[a-z0-9_-]{2,80}$/D',$rule['id']))
                    $ids[]=$rule['id'];
            $sourceId=sanitize_text_field((string)($item['id']??('row-'.$idx)));
            $title=self::safeName((string)($item['title']??'Untitled Strategy'));
            $strategy=sanitize_key((string)($item['strategy']??'unchanged'));
            $sourceStatus=$status==='staged'?'staged_for_native_review':'draft';
            $amount=is_numeric($item['value']??null)?(float)$item['value']:null;
            $dimensions=array_values(array_unique($ids));
            $strategySummaries[]=[
                'id'=>$sourceId,'title'=>$title,'status'=>$sourceStatus,
                'revision'=>max(0,(int)($item['revision']??0)),
                'strategy'=>$strategy,'value'=>$amount,
                'currency'=>substr(sanitize_text_field((string)($item['currency']??'')),0,3),
                'dimensions'=>$dimensions,
                'staged_rows'=>count((array)($item['staged_rows']??[])),
                'authorizes_checkout'=>false,
            ];
            foreach(($dimensions?:['draft:unscoped']) as $dimension)
                $add(self::record('pricing_lab',$sourceId.'-'.substr(md5($dimension),0,7),
                    $title,'mad4b_jetengine_pricing_lab_presets_v1',
                    $dimension,$status,$strategy,
                    ['draft_id'=>$sourceId,'authorizes_checkout'=>false,
                     'revision'=>max(0,(int)($item['revision']??0)),
                     'value'=>$amount]));
        }

        // Native assignment rows are another family, not a second source of Lab truth.
        $assignments=get_option('booking_experience_pricing_preset_rows_v1',[]);
        $nativeSummary=['rows'=>0,'disabled'=>0,'enabled_unapproved'=>0,
            'enabled_approved'=>0,'native_layer_enabled'=>false];
        $layer=function_exists('BookingExperience\pricing_preset_settings')
            ?(array)call_user_func('BookingExperience\\pricing_preset_settings'):[];
        $nativeSummary['native_layer_enabled']=($layer['enabled']??false)===true;
        $sources['native_presets']=['option'=>'booking_experience_pricing_preset_rows_v1',
            'source_kind'=>'native_preset_assignments',
            'configured'=>is_array($assignments)&&count($assignments)>0];
        foreach((array)(is_array($assignments)?$assignments:[]) as $idx=>$item){
            if(!is_array($item))continue;
            $active=self::selected($item['enabled']??false);
            $approved=self::selected($item['approved']??false);
            $nativeSummary['rows']++;
            if(!$active)$nativeSummary['disabled']++;
            elseif(!$approved)$nativeSummary['enabled_unapproved']++;
            else $nativeSummary['enabled_approved']++;
            $add(self::record('native_preset',(string)$idx,
                (string)($item['title']??('Native assignment '.($idx+1))),
                'booking_experience_pricing_preset_rows_v1','native:assignment',
                $active&&$approved?'configured_native':($active?'approval_pending':'disabled'),
                'native source', ['enabled'=>$active,'approved'=>$approved,
                'runtime'=>'provider_eligibility_not_proven']));
        }

        // Supplier-cost services are separate input families, not selling strategies.
        $components=get_option('mad4b_company_component_pricing_defaults_v1',[]);
        $sources['components']=['option'=>'mad4b_company_component_pricing_defaults_v1',
            'source_kind'=>'native_component_pricing_defaults',
            'configured'=>is_array($components)&&count($components)>0];
        if(is_array($components)){
            foreach(['accommodation','transport','extras'] as $family){
                $cfg=$components[$family]??null;
                if(!is_array($cfg))continue;
                $add(self::record('component',$family,ucfirst($family).' supplier source',
                    'mad4b_company_component_pricing_defaults_v1','component:'.$family,
                    self::selected($cfg['enabled']??false)?'configured_component':'disabled',
                    sanitize_key((string)($cfg['strategy']??'unconfigured')),
                    ['runtime'=>'component_only','checkout_enabled'=>self::selected(
                        $cfg['checkout_enabled']??false)]));
            }
        }

        // Guide pricing is a supplier COST input, not a customer-facing markup.
        $sources['guide_costs']=[
            'option'=>'guide-languages term meta / mad4b_guide_daily_base_cost_v1',
            'source_kind'=>'native_jetengine_term_costs',
            'configured'=>class_exists('MAD4B_Guide_Taxonomy_Pricing_V1',false)
        ];
        if(class_exists('MAD4B_Guide_Taxonomy_Pricing_V1',false)){
            foreach(MAD4B_Guide_Taxonomy_Pricing_V1::allLanguages() as $language){
                $cost=$language['cost']??null;
                $state=($language['error']??null)!==null
                    ?'blocked_source_or_conflict'
                    :(($cost['policy_source']??'')==='approved_taxonomy_term'
                        ?'approved_supplier_cost':'inherited_supplier_cost');
                $amount=is_array($cost)
                    ?((string)($cost['source_unit_cost']??'').' '.
                        (string)($cost['source_currency']??''))
                    :'unverified';
                $add(self::record('guide_cost',(string)$language['id'],
                    'Guide · '.(string)$language['name'],
                    'guide-languages term meta','taxonomy:guide-languages',
                    $state,$amount,['term_id'=>(int)$language['id'],
                    'runtime'=>'internal_cost_only','currency'=>$cost['source_currency']??null]));
            }
        }
        // Supplier transport source policy. A reference-only PDF cannot be
        // consumed as a certified Package supplier COST.
        $transport=get_option('mad4b_transport_self_pricing_controls_v1',[]);
        $sources['transport_self']=[
            'option'=>'mad4b_transport_self_pricing_controls_v1',
            'source_kind'=>'company_supplier_cost_policy',
            'configured'=>is_array($transport)&&count($transport)>0
        ];
        if(is_array($transport)&&$transport){
            $role=(string)($transport['price_role']??'reference_only');
            $currency=(string)($transport['currency']??'');
            $state=$role==='self_cost' && in_array($currency,['EGP','USD','EUR','GBP'],true)
                ?'configured_supplier_cost':'reference_only_no_cost_authority';
            $add(self::record('transport_self','source','Transport supplier · self',
                'mad4b_transport_self_pricing_controls_v1',
                'component:transport',$state,$currency!==''?$currency:'currency unverified',
                ['source_role'=>$role,'currency'=>$currency,
                'runtime'=>'supplier_source_not_checkout']));
        }

        $counts=[];
        $byDimension=[];
        foreach($rows as $entry){
            $counts[$entry['status']]=($counts[$entry['status']]??0)+1;
            $byDimension[$entry['dimension']][]=$entry;
        }
        ksort($counts);
        $financial=array_values(array_filter($rows,static fn($row)=>
           in_array($row['family'],['tour_type','package_level','pricing_lab','native_preset'],true)));
        $fingerprint=hash('sha256',wp_json_encode([$rows,$sources]));
        return [
            'schema'=>self::SCHEMA,'read_only'=>true,
            'stores_unmodified'=>true,'checkout_activation_unproven'=>true,
            'source_count'=>count($sources),'source_fingerprint'=>$fingerprint,
            'rows'=>$rows,'financial_rule_count'=>count($financial),
            'strategy_summaries'=>$strategySummaries,
            'native_assignment_summary'=>$nativeSummary,
            'status_counts'=>$counts,'by_dimension'=>$byDimension,
            'warnings'=>array_values(array_unique($warnings)),
            'execution_order'=>[
                'source_tour_base',
                'tour_type_markup_preview',
                'package_level_native_preview',
                'native_price_source_and_mode_policies',
                'company_component_costs_separately',
                'approved_native_checkout_eligibility_not_certified',
            ],
            'sources'=>$sources,
        ];
    }
}
