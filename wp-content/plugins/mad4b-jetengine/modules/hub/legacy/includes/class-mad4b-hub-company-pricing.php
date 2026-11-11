<?php
/**
 * MAD4B Hub price transformation: per-company governed reference-only
 * overrides, market dynamic pricing, and deterministic Rate Generator drafts.
 * No Woo checkout, rates CPT writes, imports or implicit currency conversion.
 */
defined('ABSPATH') || exit;
final class MAD4B_Hub_Company_Pricing_V1 {
    const CONTRACT='mad4b.hub.company-pricing.v1';
    const OPTION='mad4b_hub_company_pricing_policies_v1';
    const LIMIT=200;
    private static function err($reason,$status=422) {
        return new WP_Error('mad4b_hub_company_price_'.$reason,$reason,
            array('status'=>$status,'contract'=>self::CONTRACT));
    }
    private static function company($id){
        if(!is_string($id)||!preg_match('/^[a-z0-9][a-z0-9-]{2,79}$/D',$id))
            return false;
        if(class_exists('MAD4B_Hub_JetEngine_Consumer_Runtime')){
            foreach(MAD4B_Hub_JetEngine_Consumer_Runtime::registry() as $consumer)
                if(is_array($consumer) && ($consumer['company_id']??'')===$id)
                    return true;
        }
        return $id==='mad4b-central' ||
            (class_exists('MAD4B_Central_Company_REST_V1') &&
             isset(MAD4B_Central_Company_REST_V1::COMPANIES[$id]));
    }
    private static function cash($v,$zero=false){
        if(!is_int($v)&&!is_float($v)&&!is_string($v))return null;
        $v=trim((string)$v);
        if(!preg_match('/^[0-9]{1,10}(?:\.[0-9]{1,4})?$/D',$v))return null;
        $f=(float)$v;if(!is_finite($f)||$f>1000000000 || (!$zero&&$f<=0))return null;
        // Financial calculations operate in fixed 1/10000 units to avoid
        // multiplying and accumulating binary-floating money.
        return (int)round($f*10000,0,PHP_ROUND_HALF_UP);
    }
    private static function decimal($units){
        $text=sprintf('%d.%04d',intdiv($units,10000),$units%10000);
        return rtrim(rtrim($text,'0'),'.');
    }
    private static function guardCurrency($v){
        return is_string($v)&&preg_match('/^[A-Z]{3}$/D',$v);
    }
    private static function stringValue($v){
        return is_string($v)&&preg_match('/^[a-zA-Z0-9_-]{1,90}$/D',$v);
    }
    private static function positiveId($v){
        return is_int($v)&&$v>0;
    }
    public static function validate_rule($row){
        if(!is_array($row) ||
           array_diff(array_keys($row),
            array('mode','amount','percent_basis_points','currency','conditions','rate_id','rate_key','field')))
            return self::err('rule_structure_invalid');
        $mode=$row['mode']??null;
        if(!in_array($mode,array('inherit','fixed','percent_adjust','fixed_adjust','rate_generator'),true))
            return self::err('mode_not_supported');
        $currency=$row['currency']??null;
        if($currency!==null&&!self::guardCurrency($currency))
            return self::err('currency_invalid');
        $amount=null;$bps=null;
        if(in_array($mode,array('fixed','fixed_adjust'),true)){
            $amount=self::cash($row['amount']??null,$mode==='fixed_adjust');
            if($amount===null)return self::err('amount_invalid');
        }elseif(array_key_exists('amount',$row))return self::err('unexpected_amount');
        if(in_array($mode,array('percent_adjust','rate_generator'),true)){
            $bps=$row['percent_basis_points']??null;
            if(!is_int($bps)||$bps< -10000||$bps>30000)
                return self::err('basis_points_invalid');
        }elseif(array_key_exists('percent_basis_points',$row))
            return self::err('unexpected_basis_points');
        $when=$row['conditions']??array();
        if(!is_array($when)||count($when)>6||
           array_diff(array_keys($when),array(
             'language','market_id','channel','nationality_country',
             'residence_country','date_from','date_until')))
            return self::err('conditions_invalid');
        foreach($when as $key=>$v){
            if(!is_string($v))return self::err('condition_value_invalid');
            if(in_array($key,array('nationality_country','residence_country'),true)){
                if(!preg_match('/^[A-Z]{2}$/D',$v))return self::err('country_invalid');
            }elseif(in_array($key,array('date_from','date_until'),true)){
                $date=DateTimeImmutable::createFromFormat('!Y-m-d',$v);
                if(!$date||$date->format('Y-m-d')!==$v)return self::err('date_invalid');
            }elseif(!self::stringValue($v))return self::err('condition_invalid');
        }
        if(isset($when['date_from'],$when['date_until'])&&$when['date_from']>$when['date_until'])
            return self::err('window_invalid');
        $rateId=$row['rate_id']??null;
        if($rateId!==null&&!self::positiveId($rateId))
            return self::err('rate_id_invalid');
        $rateKey=$row['rate_key']??null;
        if($rateKey!==null && (!is_string($rateKey) ||
            !preg_match('/^[a-z0-9_-]+(?::[a-z0-9_-]+){1,3}$/D',$rateKey)))
            return self::err('rate_key_invalid');
        $field=$row['field']??null;
        if($field!==null && (!is_string($field)||
             !preg_match('/^[_a-z][a-z0-9_]{1,69}$/D',$field)))
            return self::err('price_field_invalid');
        return array('mode'=>$mode,
            'amount_units'=>$amount,
            'basis_points'=>$bps,
            'currency'=>$currency,
            'conditions'=>$when,
            'rate_id'=>$rateId,'rate_key'=>$rateKey,'field'=>$field);
    }
    public static function validate_policy($payload){
        if(!is_array($payload)||array_diff(array_keys($payload),array(
           'source_company','consumer_company','product_type','source_product_id',
           'starting_rule','rate_rules','default_rate_rule','enabled')))
            return self::err('policy_keys_invalid');
        if(!self::company($payload['source_company']??null) ||
           !self::company($payload['consumer_company']??null) ||
           !self::stringValue($payload['product_type']??null) ||
           !self::positiveId($payload['source_product_id']??null) ||
           !is_bool($payload['enabled']??null))
            return self::err('policy_identity_invalid');
        $starting=$payload['starting_rule']??null;
        if($starting!==null){
            $starting=self::validate_rule($starting);
            if(is_wp_error($starting))return $starting;
        }
        $default=$payload['default_rate_rule']??null;
        if($default!==null){
            $default=self::validate_rule($default);
            if(is_wp_error($default))return $default;
        }
        $rateRules=$payload['rate_rules']??array();
        if(!is_array($rateRules)||count($rateRules)>150)
            return self::err('rate_rules_invalid');
        $clean=array();$seen=array();
        foreach($rateRules as $rule){
            $validated=self::validate_rule($rule);
            if(is_wp_error($validated))return $validated;
            if(($validated['rate_id']===null && $validated['rate_key']===null) ||
                $validated['field']===null)
                return self::err('rate_rule_needs_exact_id_and_field');
            $key=($validated['rate_key']??$validated['rate_id']).'/'.
                $validated['field'].
                '/'.hash('sha256',wp_json_encode($validated['conditions']));
            if(isset($seen[$key]))return self::err('duplicate_rate_rule');
            $seen[$key]=true;$clean[]=$validated;
        }
        return array('source_company'=>$payload['source_company'],
            'consumer_company'=>$payload['consumer_company'],
            'product_type'=>$payload['product_type'],
            'source_product_id'=>$payload['source_product_id'],
            'enabled'=>$payload['enabled'],'starting_rule'=>$starting,
            'default_rate_rule'=>$default,'rate_rules'=>$clean,
            'contract'=>self::CONTRACT);
    }
    private static function key($policy){
        return $policy['source_company'].'|'.$policy['consumer_company'].'|'.
            $policy['product_type'].'|'.$policy['source_product_id'];
    }
    public static function policies_summary(){
        $rows=get_option(self::OPTION,array());
        if(!is_array($rows))$rows=array();
        $out=array();
        foreach($rows as $key=>$r){
            if(!is_array($r))continue;
            $out[]=array('policy_key'=>$key,
              'revision'=>$r['revision']??0,'source'=>$r['source_company']??null,
              'consumer'=>$r['consumer_company']??null,
              'product_type'=>$r['product_type']??null,
              'product_id'=>$r['source_product_id']??null,'enabled'=>$r['enabled']??false);
        }
        return array('contract'=>self::CONTRACT,'policies'=>$out,
            'policy_count'=>count($out),'prices_modified'=>false);
    }
    public static function upsert($input){
        if(!is_array($input)||!isset($input['policy'],$input['expected_revision'])||
           !is_int($input['expected_revision'])||$input['expected_revision']<0)
            return self::err('upsert_shape_invalid');
        $policy=self::validate_policy($input['policy']);
        if(is_wp_error($policy))return $policy;
        // Atomic cross-request lock: compare-and-set revisions must not
        // race against another company's pricing policy update.
        global $wpdb;
        $lock='mad4b_hub_company_price_registry_lock_v1';
        $got=$wpdb->query($wpdb->prepare(
          "INSERT IGNORE INTO {$wpdb->options}(option_name,option_value,autoload)
           VALUES (%s,%s,'off')",$lock,(string)time()));
        if($got!==1)return self::err('pricing_registry_update_busy',409);
        try {
            $rows=get_option(self::OPTION,array());
            if(!is_array($rows)||count($rows)>self::LIMIT)
                return self::err('registry_over_capacity',409);
            $key=self::key($policy);$current=$rows[$key]??null;
            $actual=$current['revision']??0;
            if($input['expected_revision']!==$actual)
                return self::err('revision_conflict',409);
            $policy['revision']=$actual+1;
            $policy['updated_at']=time();
            $rows[$key]=$policy;
            if(!update_option(self::OPTION,$rows,false))
                return self::err('policy_save_failed',503);
            return array('contract'=>self::CONTRACT,'policy_key'=>$key,
                'revision'=>$policy['revision'],
                'company_pricing_policy_saved'=>true,
                'native_prices_or_rates_changed'=>false,
                'checkout_authorized'=>false);
        } finally {
            $wpdb->delete($wpdb->options,array('option_name'=>$lock),
                array('%s'));
        }
    }
    private static function matches($when,$context){
        foreach($when as $key=>$expected){
            $actual=$context[$key]??null;
            if($key==='date_from'){
                if(!isset($context['date'])||$context['date']<$expected)return false;
            }elseif($key==='date_until'){
                if(!isset($context['date'])||$context['date']>$expected)return false;
            }elseif($actual!==$expected)return false;
        }
        return true;
    }
    private static function apply_price($amount,$currency,$rule){
        if(!$rule || $rule['mode']==='inherit')
            return array('amount'=>$amount,'currency'=>$currency,'mode'=>'inherit');
        if(!self::guardCurrency($currency)||($rule['currency']!==null &&
             $rule['currency']!==$currency))
            return self::err('currency_mismatch_requires_verified_fx',409);
        $minor=self::cash($amount);
        if($minor===null)return self::err('source_amount_not_valid',409);
        switch($rule['mode']){
            case 'fixed':$calc=$rule['amount_units'];break;
            case 'fixed_adjust':$calc=$minor+$rule['amount_units'];break;
            case 'percent_adjust':
            case 'rate_generator':
                $calc=(int)round($minor*(10000+$rule['basis_points'])/10000,0,PHP_ROUND_HALF_UP);
                break;
            default:return self::err('unknown_calculator');
        }
        if($calc<=0||$calc>self::cash('1000000000'))
            return self::err('result_amount_out_of_bounds',409);
        return array('amount'=>self::decimal($calc),
            'currency'=>$currency,
            'mode'=>$rule['mode'],
            'basis_points'=>$rule['basis_points'],
            'source_amount'=>$amount,
            'calculated_preview_only'=>true);
    }
    private static function validate_context($context){
        if(!is_array($context) ||
           array_diff(array_keys($context),array('language','market_id','channel',
              'nationality_country','residence_country','date')))
            return self::err('quote_context_invalid');
        foreach($context as $key=>$value){
            if(!is_string($value))return self::err('quote_context_type_invalid');
            if(in_array($key,array('nationality_country','residence_country'),true)){
                if(!preg_match('/^[A-Z]{2}$/D',$value))
                    return self::err('quote_country_invalid');
            }elseif($key==='date'){
                $dt=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
                if(!$dt || $dt->format('Y-m-d')!==$value)
                    return self::err('quote_date_invalid');
            }elseif($key==='language'){
                if(!in_array($value,array('en','ar','de','fr','it','es'),true))
                    return self::err('quote_language_invalid');
            }elseif(!self::stringValue($value))return self::err('quote_context_token_invalid');
        }
        return true;
    }
    private static function choose($policy,$rateId,$rateKey,$field,$context){
        foreach($policy['rate_rules'] as $rule)
            if(($rule['rate_key']!==null
                ? $rule['rate_key']===$rateKey
                : $rule['rate_id']===$rateId)
               && $rule['field']===$field &&
               self::matches($rule['conditions'],$context))return $rule;
        $fallback=$policy['default_rate_rule'];
        return $fallback && self::matches($fallback['conditions'],$context)?$fallback:null;
    }
    public static function quote($source,$consumerCompany,$context=array(),$unsavedPolicy=null){
        if(!is_array($source)||($source['contract']??null)!==
           MAD4B_Hub_Price_Sources_V1::CONTRACT ||
           !self::company($consumerCompany) || !is_array($context) ||
           array_diff(array_keys($context),array('language','market_id','channel',
             'nationality_country','residence_country','date')))
            return self::err('quote_input_invalid');
        $contextValid=self::validate_context($context);
        if(is_wp_error($contextValid))return $contextValid;
        $sourceCompany=$source['source_company']??'mad4b-central';
        $postType=$source['post_type']??'tours-and-activities';
        $id=$source['source_post_id']??null;
        if(!self::company($sourceCompany)||!self::positiveId($id))
            return self::err('source_id_missing');
        $policies=get_option(self::OPTION,array());
        $key=$sourceCompany.'|'.$consumerCompany.'|'.$postType.'|'.$id;
        $policy=$unsavedPolicy===null?($policies[$key]??null):
            self::validate_policy($unsavedPolicy);
        if(is_wp_error($policy))return $policy;
        if($unsavedPolicy!==null && self::key($policy)!==$key)
            return self::err('quote_policy_product_identity_mismatch');
        $effective=$policy&&($policy['enabled']??false)?$policy:null;
        $direct=array();
        foreach($source['direct_starting_prices']??array() as $entry){
            if(!is_array($entry))return self::err('source_direct_shape');
            $rule=$effective['starting_rule']??null;
            if($rule&&$rule['field']!==null&&$rule['field']!==$entry['field'])$rule=null;
            if($rule&&!self::matches($rule['conditions'],$context))$rule=null;
            $result=self::apply_price($entry['amount']??null,$entry['currency']??null,$rule);
            if(is_wp_error($result))return $result;
            $direct[]=array('field'=>$entry['field'],'price'=>$result,'source_reference'=>$entry);
        }
        $rates=array();$idToRateKeys=array();
        foreach($source['rates']??array() as $rate){
            if(!is_array($rate)||!self::positiveId($rate['source_rate_id']??null))
                return self::err('source_rate_invalid');
            $id=(int)$rate['source_rate_id'];
            $key=(string)($rate['source_rate_key']??('legacy:'.$id));
            if(!isset($idToRateKeys[$id]))$idToRateKeys[$id]=array();
            $idToRateKeys[$id][$key]=true;
        }
        if($effective)foreach($effective['rate_rules'] as $rule){
            if($rule['rate_key']===null && $rule['rate_id']!==null &&
               count($idToRateKeys[$rule['rate_id']]??array())>1)
                return self::err('ambiguous_rate_id_requires_provider_key',409);
        }
        foreach($source['rates']??array() as $rate){
            if(!is_array($rate)||!self::positiveId($rate['source_rate_id']??null))
                return self::err('source_rate_invalid');
            $fields=array();$rId=$rate['source_rate_id'];
            foreach($rate['price_fields']??array() as $field=>$amount){
                $isEgyptian=(strpos($field,'egyptians_')===0 ||
                    substr($field,-9)==='_egyptian');
                $currency=($isEgyptian&&!empty($rate['egyptians_currency']))?
                    $rate['egyptians_currency']:($rate['currency']??null);
                if(!self::guardCurrency($currency))continue;
                $rule=$effective?self::choose($effective,$rId,
                    (string)($rate['source_rate_key']??('legacy:'.$rId)),
                    $field,$context):null;
                $result=self::apply_price($amount,$currency,$rule);
                if(is_wp_error($result))return $result;
                $fields[$field]=$result;
            }
            $rates[]=array(
                'source_rate_id'=>$rId,
                'source_rate_key'=>$rate['source_rate_key']??('legacy:'.$rId),
                'original_rate_reference'=>$rate['authority']??'unverified',
                'original_valid_from'=>$rate['valid_from']??null,
                'original_valid_until'=>$rate['valid_until']??null,
                'source_stop_sale'=>$rate['stop_sale']??false,
                'is_test_data'=>$rate['is_test_data']??false,
                'price_fields'=>$fields,
                'generated_rate_preview'=>in_array('rate_generator',
                   array_column($fields,'mode'),true),
                'original_rate_untouched'=>true,'bookable'=>false);
        }
        $result=array('contract'=>self::CONTRACT,
          'source_company'=>$sourceCompany,'consumer_company'=>$consumerCompany,
          'product_type'=>$postType,'source_product_id'=>$id,
          'pricing_policy_revision'=>$effective['revision']??null,
          'starting_price_options'=>$direct,'rate_entries'=>$rates,
          'source_direct_rate_candidates_not_combined'=>true,
          'price_effective_state'=>'indicative_reference_not_checkout',
          'market_context'=>array_intersect_key($context,array_flip(array('language','market_id','channel','date'))),
          'currency_conversion_performed'=>false,
          'rate_generator_only_generates_preview'=>true,
          'source_rate_records_mutated'=>false,'checkout_ready'=>false);
        $result['quote_sha256']=hash('sha256',wp_json_encode($result,JSON_UNESCAPED_SLASHES));
        return $result;
    }
    /**
     * Reuse the EXISTING Shared Product Rate Generator, not an invented CPT.
     * Prepare is read-only. Creating a real draft requires the native provider
     * create() path and its separate review / remote authority.
     */
    public static function native_generator_preview($input){
        if(!is_array($input) ||
           array_diff(array_keys($input),array(
             'consumer_company','generator_input','quote_sha256')))
            return self::err('native_generator_input_invalid');
        if(!self::company($input['consumer_company']??null) ||
           !is_array($input['generator_input']??null))
            return self::err('native_generator_company_invalid');
        if(!class_exists('Shared_Product_Rate_Generation') ||
           !is_callable(array('Shared_Product_Rate_Generation','prepare')))
            return self::err('native_rate_generator_not_active',503);
        $params=$input['generator_input'];
        $kind=$params['kind']??null;
        if(!in_array($kind,array('tour','accommodation','transport'),true))
            return self::err('native_generator_kind_invalid');
        $parents=array('tour'=>'tours-and-activities',
            'accommodation'=>'properties','transport'=>'transportations');
        $id=$params['parent_id']??null;
        if(!self::positiveId($id)||get_post_type($id)!==$parents[$kind] ||
           get_post_status($id)!=='publish')
            return self::err('native_generator_parent_invalid');
        $plan=Shared_Product_Rate_Generation::prepare($params);
        if(is_wp_error($plan))return $plan;
        if(!is_array($plan) ||
           ($plan['schema']??null)!=='shared-rate-generation.v1' ||
           ($plan['post_status']??null)!=='draft' ||
           ($plan['checkout_ready']??true)!==false ||
           ($plan['commercial_ready']??true)!==false ||
           !is_string($plan['fingerprint']??null))
            return self::err('native_generator_contract_mismatch',409);
        // A quote digest may be passed for later audit joining but it cannot
        // self-authorize creating or publishing any Rate.
        $quoteDigest=$input['quote_sha256']??null;
        if($quoteDigest!==null &&
           (!is_string($quoteDigest)||!preg_match('/^[a-f0-9]{64}$/D',$quoteDigest)))
            return self::err('quote_digest_invalid');
        return array('contract'=>self::CONTRACT,
          'provider'=>'Shared_Product_Rate_Generation',
          'native_schema'=>$plan['schema'],
          'consumer_company'=>$input['consumer_company'],
          'kind'=>$kind,'parent_id'=>$id,
          'native_post_type'=>$plan['post_type'],
          'native_fingerprint'=>$plan['fingerprint'],
          'native_draft_plan'=>$plan,
          'linked_company_quote_sha256'=>$quoteDigest,
          'creation_performed'=>false,
          'provider_create_invoked'=>false,
          'commercial_ready'=>false,
          'checkout_ready'=>false);
    }
    public static function rest_native_generator($request){
        return self::native_generator_preview($request->get_json_params());
    }
    public static function admin(){return current_user_can('manage_options');}
    public static function rest_inventory(){return MAD4B_Hub_Price_Sources_V1::discover();}
    public static function rest_policies(){return self::policies_summary();}
    public static function rest_policy($request){return self::upsert($request->get_json_params());}
    public static function rest_quote($request){
        $body=$request->get_json_params();
        if(!is_array($body))return self::err('request_malformed');
        if(array_diff(array_keys($body),array('origin','post_type','product_id',
           'consumer_company','source_company','context','draft_policy')))
            return self::err('quote_unknown_keys');
        $origin=$body['origin']??null;$id=$body['product_id']??null;
        if(!self::positiveId($id))return self::err('id_invalid');
        if($origin==='local_product'){
            $source=MAD4B_Hub_Price_Sources_V1::local($id,$body['post_type']??null);
            if(is_array($source))$source['source_company']='mad4b-central';
        }elseif($origin==='signed_historical_nile'){
            $source=MAD4B_Hub_Price_Sources_V1::signedHistorical($body['source_company']??null,$id);
            if(is_array($source))$source['post_type']='tours-and-activities';
        }else return self::err('origin_invalid');
        if(is_wp_error($source))return $source;
        return self::quote($source,$body['consumer_company']??null,
          $body['context']??array(),$body['draft_policy']??null);
    }
    public static function boot(){
        add_action('rest_api_init',static function(){
            $routes=array(
                '/hub-pricing/sources'=>array('GET','rest_inventory'),
                '/hub-pricing/policies'=>array('GET','rest_policies'),
                '/hub-pricing/policy'=>array('POST','rest_policy'),
                '/hub-pricing/quote-preview'=>array('POST','rest_quote'),
                '/hub-pricing/rate-generator-preview'=>array('POST','rest_native_generator')
            );
            foreach($routes as $path=>$spec)
                register_rest_route('mad4b/v1',$path,array(
                   'methods'=>$spec[0],'callback'=>array(__CLASS__,$spec[1]),
                   'permission_callback'=>array(__CLASS__,'admin')));
        },37);
    }
}
