<?php
/**
 * MAD4B market-aware selection gate (dry-run only).
 *
 * A market is a SERVER-registered scope, never a user-invented geography.
 * The local registry is not proof of a source owner's signed release grant.
 */
defined('ABSPATH') || exit;
final class MAD4B_Hub_Market_Policy {
    const CONTRACT='mad4b.hub.market-policy.v1';
    const REGISTRY_OPTION='mad4b_hub_market_registry_v1';
    const FIELDS=array(
        'languages'=>'language',
        'nationality_countries'=>'country',
        'residence_countries'=>'country',
        'point_of_sale_countries'=>'country',
        'destination_countries'=>'country',
        'channels'=>'channel',
        'currencies'=>'currency',
        'source_record_ids'=>'id'
    );
    private static function fail($code){
        return new WP_Error('mad4b_market_'.$code,$code,
            array('status'=>422,'contract'=>self::CONTRACT));
    }
    private static function valid_date($date){
        if(!is_string($date) || !preg_match('/^\d{4}-\d\d-\d\d$/D',$date))return false;
        $dt=DateTimeImmutable::createFromFormat('!Y-m-d',$date,new DateTimeZone('UTC'));
        return $dt && $dt->format('Y-m-d')===$date;
    }
    private static function normalized($value,$kind,$allowEmpty=false){
        if(!is_array($value)||count($value)>100||
           (!$allowEmpty&&count($value)===0))return false;
        $seen=array();$list=array();
        foreach($value as $v){
            if($kind==='id'){
                if(!is_int($v) && !(is_string($v)&&ctype_digit($v)))return false;
                if((int)$v<1||(string)(int)$v!==(string)$v)return false;
                $v=(int)$v;
            }else{
                if(!is_string($v))return false;
                if($kind==='language' && !in_array($v,array('ar','en','de','fr','es','it'),true))return false;
                if($kind==='country' && !preg_match('/^[A-Z]{2}$/D',$v))return false;
                if($kind==='currency' && !preg_match('/^[A-Z]{3}$/D',$v))return false;
                if($kind==='channel' && !preg_match('/^[a-z][a-z0-9_-]{1,39}$/D',$v))return false;
            }
            $key=(string)$v;
            if(isset($seen[$key]))return false;
            $seen[$key]=true;$list[]=$v;
        }
        return $list;
    }
    /** Restriction sources are ANDed; no lower layer can widen a set. */
    private static function restrict($requested,$allowed,$kind){
        $r=self::normalized($requested,$kind,true);
        $a=self::normalized($allowed,$kind,true);
        if($r===false||$a===false)return false;
        if(array_diff($r,$a))return false;
        return true;
    }
    public static function evaluate($markets,$selected,$policyLayers,$sourceCompany){
        if($markets===null){
            return array('contract'=>self::CONTRACT,'status'=>'UNSCOPED_DENY',
                'scopes'=>array(),'runtime_approved'=>false,
                'pending'=>array('explicit_market_selection','source_signed_market_grants'));
        }
        if(!is_array($markets)||count($markets)<1||count($markets)>12||
           array_keys($markets)!==range(0,count($markets)-1))
            return self::fail('market_selection_invalid');
        $registry=get_option(self::REGISTRY_OPTION,array());
        if(!is_array($registry)||!$registry)return self::fail('registry_unconfigured');
        $scopes=array();$seen=array();
        foreach($markets as $market){
            if(!is_array($market))return self::fail('market_scope_invalid');
            $allowedKeys=array_merge(array_keys(self::FIELDS),array(
                'market_id','valid_from','valid_until','pricing_authority',
                'import_strategy','commercial_status'));
            if(array_diff(array_keys($market),$allowedKeys))
                return self::fail('unrecognized_market_instruction');
            $marketId=$market['market_id']??null;
            if(!is_string($marketId)||
               !preg_match('/^[a-z][a-z0-9_-]{2,47}$/D',$marketId)||
               isset($seen[$marketId]))return self::fail('market_identity_invalid');
            $seen[$marketId]=true;
            $registered=$registry[$marketId]??null;
            if(!is_array($registered) ||
               ($registered['source_company']??null)!==$sourceCompany ||
               ($registered['status']??null)!=='active')
                return self::fail('market_not_registered_for_source');
            $from=$market['valid_from']??null;
            $until=$market['valid_until']??null;
            $regFrom=$registered['valid_from']??null;
            $regUntil=$registered['valid_until']??null;
            if(!self::valid_date($from)||!self::valid_date($until)||
                !self::valid_date($regFrom)||!self::valid_date($regUntil) ||
                $from>$until || $regFrom>$regUntil ||
                $from<$regFrom || $until>$regUntil ||
                $until<gmdate('Y-m-d'))
                return self::fail('market_date_out_of_bounds');
            $values=array();
            foreach(self::FIELDS as $name=>$kind){
                $requested=self::normalized($market[$name]??null,$kind,
                    $name==='source_record_ids');
                $allowed=self::normalized($registered[$name]??null,$kind,
                    $name==='source_record_ids');
                if($requested===false||$allowed===false)return self::fail('market_dimension_invalid');
                if(!self::restrict($requested,$allowed,$kind))
                    return self::fail('market_exceeds_registered_scope');
                if($name==='languages' &&
                   array_diff($requested,$selected['languages']??array()))
                    return self::fail('market_language_outside_selection');
                if($name==='source_record_ids'){
                    if(array_diff($requested,$selected['source_record_ids']??array()))
                        return self::fail('market_record_outside_selection');
                    // Nonempty source record selection must be explicit per market.
                    if(($selected['source_record_ids']??array()) && !$requested)
                        return self::fail('market_record_scope_implicit_all');
                }
                $values[$name]=$requested;
            }
            // Optional restrictions from every ancestor; no "allow" in the
            // manifest can change a higher-priority market restriction.
            foreach((array)$policyLayers as $level=>$policy){
                if(!is_array($policy))return self::fail('invalid_policy_level');
                $limits=$policy['market_limits']??array();
                if(!is_array($limits))return self::fail('market_limits_invalid');
                foreach($limits as $key=>$allow){
                    if($key==='market_ids'){
                        if(!is_array($allow)||!in_array($marketId,$allow,true))
                            return self::fail('market_denied_by_inheritance');
                        continue;
                    }
                    if(!isset(self::FIELDS[$key]) ||
                       !self::restrict($values[$key],$allow,self::FIELDS[$key]))
                        return self::fail('market_dimension_denied_by_inheritance');
                }
            }
            if(($market['pricing_authority']??null)!=='reference_only' ||
               ($market['import_strategy']??null)!=='remote_reference' ||
               ($market['commercial_status']??null)!=='not_bookable')
                return self::fail('commercial_or_import_authority');
            $scopes[]=array('market_id'=>$marketId,
                'dimensions'=>$values,
                'valid_from'=>$from,'valid_until'=>$until,
                'source_grant_verified'=>false,
                'status'=>'PLANNED_NOT_AUTHORIZED',
                'temporal_state'=>($from>gmdate('Y-m-d')?'FUTURE_SCHEDULED_ONLY':'WITHIN_REQUESTED_WINDOW'),
                'pricing_authority'=>'reference_only',
                'commercial_status'=>'not_bookable',
                'import_strategy'=>'remote_reference',
                'owner_approval_required'=>true);
        }
        return array('contract'=>self::CONTRACT,'status'=>'MARKET_SCOPED_DRY_RUN',
            'scopes'=>$scopes,'runtime_approved'=>false,
            'local_registry_is_not_signed_source_grant'=>true,
            'pending'=>array('verify_source_company_market_grant',
                'market_rules_signature_and_version','market_availability',
                'market_specific_taxonomy_and_source_record_provenance',
                'confirm_destination_country_and_residency_semantics',
                'verify_currency_is_reference_only','cache_partition_per_market'));
    }
}
