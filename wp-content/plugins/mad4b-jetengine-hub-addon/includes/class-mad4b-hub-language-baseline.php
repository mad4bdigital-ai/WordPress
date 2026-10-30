<?php
/**
 * MAD4B read-only language inheritance (minimum distribution policy).
 *
 * Unlike commercial market grants, visible WPML locales are *capability*
 * evidence only. This does not authorize public rendering or pricing.
 */
defined('ABSPATH') || exit;

final class MAD4B_Hub_Language_Baseline {
    const CONTRACT = 'mad4b.hub.language-inheritance.v1';
    const OBSERVATIONS = 'mad4b_hub_source_wpml_observations_v1';
    const MAX_AGE_SECONDS = 604800; // Stale source observations are rejected.
    const LANGS = array('en','ar','fr','de','es','it');

    private static function err($code) {
        return new WP_Error('mad4b_language_'.$code, $code,
            array('status'=>422,'contract'=>self::CONTRACT));
    }
    private static function codes($value, $allowEmpty=false) {
        if(!is_array($value)||count($value)>count(self::LANGS) ||
           (!$allowEmpty&&!$value))return false;
        $unique=array();
        foreach($value as $code){
            if(!is_string($code)||!in_array($code,self::LANGS,true)||
                isset($unique[$code]))return false;
            $unique[$code]=true;
        }
        return array_keys($unique);
    }
    private static function local_wpml() {
        if(!defined('ICL_SITEPRESS_VERSION'))
            return self::err('destination_wpml_missing');
        $settings=get_option('icl_sitepress_settings',null);
        if(!is_array($settings))return self::err('destination_wpml_settings_missing');
        $active=self::codes($settings['active_languages']??null);
        $hidden=self::codes($settings['hidden_languages']??array(),true);
        $default=$settings['default_language']??null;
        if($active===false||$hidden===false||array_diff($hidden,$active)||
           !is_string($default)||!in_array($default,$active,true))
            return self::err('destination_wpml_settings_invalid');
        return array('active'=>$active,'hidden'=>$hidden,'default'=>$default,
            'visible'=>array_values(array_diff($active,$hidden)));
    }
    public static function summary() {
        $wpml=self::local_wpml();
        $source=get_option(self::OBSERVATIONS,array());
        $out=array();
        if(is_array($source)){
            foreach($source as $id=>$row){
                if(!is_array($row))continue;
                $out[$id]=array('observed_at'=>$row['observed_at']??null,
                    'authority'=>'observational_only_no_publication_grant',
                    'is_fresh'=>isset($row['observed_at'])&&is_int($row['observed_at'])&&
                        (time()-$row['observed_at'])>=0 &&
                        (time()-$row['observed_at'])<=self::MAX_AGE_SECONDS);
            }
        }
        return array('contract'=>self::CONTRACT,
            'status'=>'LANGUAGE_CAPABILITY_ONLY',
            'consumer_wpml'=>is_wp_error($wpml)?array('error'=>$wpml->get_error_code()):$wpml,
            'sources'=>$out,'market_registry_required_for_language_only'=>false,
            'public_distribution_authorized'=>false,
            'pricing_authorized'=>false);
    }

    public static function evaluate($company, $selected, $layers) {
        if(!is_string($company)||!is_array($selected)||!is_array($layers))
            return self::err('input_invalid');
        if(!class_exists('MAD4B_Central_Company_REST_V1') ||
           !isset(MAD4B_Central_Company_REST_V1::COMPANIES[$company]))
            return self::err('company_unknown');
        $source=get_option(self::OBSERVATIONS,array());
        $row=is_array($source)?($source[$company]??null):null;
        if(!is_array($row) || ($row['company']??null)!==$company ||
           ($row['canonical_host']??null)!==
             MAD4B_Central_Company_REST_V1::COMPANIES[$company]['host'] ||
           ($row['evidence_type']??null)!=='verified_ssh_wpml_option_observation' ||
           ($row['authorization']??null)!=='capability_only' ||
           !is_int($row['observed_at']??null) ||
           $row['observed_at']>time() ||
           time()-$row['observed_at']>self::MAX_AGE_SECONDS)
            return self::err('source_language_evidence_missing_or_stale');
        $sourceActive=self::codes($row['active_languages']??null);
        $sourceHidden=self::codes($row['hidden_languages']??array(),true);
        if($sourceActive===false||$sourceHidden===false ||
           array_diff($sourceHidden,$sourceActive) ||
           !is_string($row['default_language']??null) ||
           !in_array($row['default_language'],$sourceActive,true))
            return self::err('source_wpml_settings_invalid');

        $dest=self::local_wpml();
        if(is_wp_error($dest))return $dest;
        $available=array_values(array_intersect(
            array_values(array_diff($sourceActive,$sourceHidden)),
            $dest['visible']));
        $requested=self::codes($selected['languages']??null);
        if($requested===false || array_diff($requested,$available))
            return self::err('locale_not_visible_on_both_sites');

        $ids=$selected['source_record_ids']??null;
        if(!is_array($ids)||count($ids)<1||count($ids)>50)
            return self::err('explicit_record_ids_required');
        $seen=array();
        foreach($ids as $id){
            if(!is_int($id)||$id<1||isset($seen[$id]))
                return self::err('record_selection_invalid');
            $seen[$id]=true;
        }
        // Do not bypass any existing explicit market rule: the minimal
        // language-only profile is permitted only when no additional region,
        // audience, currency or specific market restrictions were configured.
        foreach($layers as $level=>$layer){
            if(!is_array($layer))return self::err('policy_level_invalid');
            $limits=$layer['market_limits']??array();
            if(!is_array($limits))return self::err('market_limits_invalid');
            foreach($limits as $dimension=>$allowed){
                if($dimension!=='languages')return self::err('explicit_market_policy_required');
                $allowed=self::codes($allowed,true);
                if($allowed===false||array_diff($requested,$allowed))
                    return self::err('language_disallowed_by_inheritance');
            }
            if(in_array('read_catalog',$layer['deny']??array(),true))
                return self::err('read_denied_by_policy');
        }
        $scope=array('market_id'=>'language-baseline',
            'status'=>'LANGUAGE_BASELINE_DRY_RUN',
            'dimensions'=>array(
                'languages'=>$requested,
                'source_record_ids'=>$ids
            ),
            'source_observation_verified'=>true,
            'source_market_grant_verified'=>false,
            'source_publication_grant_verified'=>false,
            'market_countries_inferred'=>false,
            'currencies_inferred'=>false,
            'preview_only'=>true,
            'reference_only'=>true,
            'bookable'=>false);
        return array(
            'contract'=>self::CONTRACT,
            'status'=>'LANGUAGE_BASELINE_DRY_RUN',
            'scopes'=>array($scope),
            'effective_visible_languages'=>$available,
            'inherited_languages'=>$requested,
            'runtime_approved'=>false,
            'source_publication_grant_verified'=>false,
            'requires_market_registry'=>false,
            'pending'=>array('signed_source_content_release_grant',
                'native_binding_authority','language_baseline_runtime_authority',
                'source_content_locale_and_record_permissions',
                'visibility_freshness_on_each_request',
                'per_language_cache_partition'),
            'evidence_digest'=>hash('sha256',wp_json_encode(array(
                $company,$row['canonical_host'],$row['observed_at'],$sourceActive,
                $sourceHidden,$dest['active'],$dest['hidden'],$requested,$ids
            ),JSON_UNESCAPED_SLASHES))
        );
    }
}
