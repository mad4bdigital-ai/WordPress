<?php
/**
 * Central source-of-truth contract for tenant presentation options.
 *
 * A duplicate UI field is never proof that a runtime consumer reads it.
 * This module plans reconciliation; only original target-native operations
 * may perform authorized writes. It never copies central demo identity.
 */
defined('ABSPATH') || exit;

final class MAD4B_Company_Presentation_Option_Ownership_V1 {
    public const CONTRACT = 'mad4b.company-presentation-option-ownership.v1';
    private const REGISTRY_CLASS = 'MAD4B_Company_Environment_Registry_V2';

    /**
     * Each semantic key has one runtime owner. Aliases are read-only projections
     * unless the company-specific editor routes writes through the real owner.
     * target_owned: preserve actual site value; portable: selective source import.
     */
    public static function registry(): array {
        return [
            'site.name' => [
                'owner'=>'elementor_kit','field'=>'site_name','alias'=>'site.brand_name',
                'secondary_aliases'=>['jetengine_look_and_feel.mad4b_brand_name'],
                'scope'=>'target_owned','binding'=>'elementor-site-title','language'=>'wpml_localized',
            ],
            'site.tagline' => [
                'owner'=>'elementor_kit','field'=>'site_description','alias'=>'site.tagline',
                'secondary_aliases'=>['jetengine_look_and_feel.mad4b_brand_tagline'],
                'scope'=>'target_owned','binding'=>'elementor-site-tagline','language'=>'wpml_localized',
            ],
            'site.logo' => [
                'owner'=>'elementor_kit','field'=>'site_logo.id','alias'=>'site.brand_logo',
                'secondary_aliases'=>['jetengine_look_and_feel.mad4b_brand_logo_media'],
                'scope'=>'target_owned','binding'=>'elementor-site-logo','remap'=>'attachment',
            ],
            'site.favicon' => [
                'owner'=>'elementor_kit','field'=>'site_favicon.id','alias'=>'site.brand_favicon',
                'secondary_aliases'=>['jetengine_look_and_feel.mad4b_brand_favicon_media'],
                'scope'=>'target_owned','binding'=>'elementor-site-favicon','remap'=>'attachment',
            ],
            'site.currency' => [
                'owner'=>'woocommerce','field'=>'currency','alias'=>'site.default_currency',
                'scope'=>'target_owned','binding'=>'woocommerce-currency','write_policy'=>'woocommerce_only',
            ],
            'site.contact_email' => [
                'owner'=>'jetengine_contact','field'=>'site_email','alias'=>'site.contact_email',
                'scope'=>'target_owned','binding'=>'jetengine-contact','empty'=>'advisory',
            ],
            'site.contact_phone' => [
                'owner'=>'jetengine_contact','field'=>'site_whatsapp_num','alias'=>'site.contact_phone',
                'scope'=>'target_owned','binding'=>'jetengine-contact','empty'=>'advisory',
            ],
            'site.contact_phone_link' => [
                'owner'=>'jetengine_contact','field'=>'site_wa_tel_link','alias'=>null,
                'scope'=>'target_owned','binding'=>'jetengine-contact-link','empty'=>'advisory',
            ],
            'site.email_link' => [
                'owner'=>'jetengine_contact','field'=>'site_email_link','alias'=>null,
                'scope'=>'target_owned','binding'=>'jetengine-contact-link','empty'=>'advisory',
            ],
            'site.facebook' => [
                'owner'=>'jetengine_contact','field'=>'site_facebook_page_link','alias'=>'site.facebook_url',
                'scope'=>'target_owned','binding'=>'jetengine-header-social','empty'=>'advisory',
            ],
            'site.instagram' => [
                'owner'=>'jetengine_contact','field'=>'site_instagram_page_link','alias'=>'site.instagram_url',
                'scope'=>'target_owned','binding'=>'jetengine-header-social','empty'=>'advisory',
            ],
            'site.primary_cta_label' => [
                'owner'=>'unresolved_runtime_widget','field'=>'header_cta_label','alias'=>'site.primary_cta_text',
                'scope'=>'target_owned','binding'=>'elementor-widget-dynamic-proof-required','language'=>'wpml_localized',
            ],
            'site.primary_cta_url' => [
                'owner'=>'unresolved_runtime_widget','field'=>'header_cta_url','alias'=>'site.primary_cta_url',
                'scope'=>'target_owned','binding'=>'elementor-widget-dynamic-proof-required','language'=>'wpml_localized',
            ],
            'header.template' => [
                'owner'=>'elementor_theme_builder','field'=>'header','alias'=>'header_footer.header_template',
                'scope'=>'target_owned','binding'=>'elementor-location-display-condition','remap'=>'template_wpml',
            ],
            'footer.template' => [
                'owner'=>'elementor_theme_builder','field'=>'footer','alias'=>'header_footer.footer_template',
                'scope'=>'portable_layout_target_identity','binding'=>'elementor-location-display-condition','remap'=>'template_wpml',
            ],
            'newsletter.template' => [
                'owner'=>'elementor_library','field'=>'newsletter','alias'=>'header_footer.newsletter_template',
                'scope'=>'portable_layout_target_identity','binding'=>'elementor-shortcode-template','remap'=>'template_wpml',
            ],
            'language.switcher' => [
                'owner'=>'wpml_header_widget','field'=>'enabled','alias'=>'header_footer.show_language_switcher',
                'scope'=>'target_owned','binding'=>'wpml-widget-header-footer','language'=>'target_active_only',
            ],
            'market.switcher' => [
                'owner'=>'market_profile_widget','field'=>'enabled','alias'=>'header_footer.show_market_switcher',
                'scope'=>'target_owned','binding'=>'market-provider','empty'=>'advisory',
            ],
            'visual.look_and_feel' => [
                'owner'=>'jetengine_look_and_feel','field'=>'presentation','alias'=>null,
                'scope'=>'selective_portable_content','binding'=>'elementor-dynamic-tags','remap'=>'media_assets',
            ],
            'visual.help_links' => [
                'owner'=>'jetengine_contact','field'=>'support_group_url,support_chat_url','alias'=>null,
                'scope'=>'target_owned','binding'=>'jetengine-support-dynamic-tags','empty'=>'advisory',
            ],
            'archive.listing' => [
                'owner'=>'jetengine_query_and_filters','field'=>'archive','alias'=>'archive.listing_template',
                'scope'=>'reconcile_source_ids','binding'=>'jetengine-query-listing-filter-readback','remap'=>'jetengine',
            ],
            'archive.hero' => [
                'owner'=>'jetengine_look_and_feel','field'=>'archive_hero','alias'=>'archive.hero_media',
                'scope'=>'target_owned','binding'=>'elementor-archive-dynamic-tags','remap'=>'attachment',
            ],
            'popup.promotion' => [
                'owner'=>'approved_promotion_provider','field'=>'active','alias'=>'popup.show_promotions',
                'scope'=>'target_owned','binding'=>'approved-popup-only','empty'=>'disabled',
            ],
            'tour.pricing' => [
                'owner'=>'woocommerce_pricing_orchestrator','field'=>'live_quote','alias'=>null,
                'scope'=>'protected_target_commerce','binding'=>'commerce-runtime-only','write_policy'=>'never_from_site_options',
            ],
        ];
    }

    public static function digest(): string {
        return hash('sha256', (string) wp_json_encode(
            ['contract'=>self::CONTRACT,'owners'=>self::registry()],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ));
    }

    public static function valid_registry(): bool {
        $rows=self::registry();
        if (!$rows || count($rows)!==count(array_unique(array_keys($rows))))return false;
        foreach ($rows as $key=>$row) {
            if (!preg_match('/^[a-z][a-z0-9_.]+$/D',$key) ||
                !is_string($row['owner']??null)||!is_string($row['field']??null)||
                !is_string($row['scope']??null)||!is_string($row['binding']??null))return false;
            if (($row['scope']??'')==='protected_target_commerce' && ($row['write_policy']??'')!=='never_from_site_options')
                return false;
        }
        return true;
    }

    /** The company + environment must match the central registry. No grants. */
    public static function plan(string $company, string $environment='production'): array|WP_Error {
        if (!class_exists('MAD4B_Company_Portfolio_Manager_V1',false) ||
            !MAD4B_Company_Portfolio_Manager_V1::staging() ||
            !class_exists(self::REGISTRY_CLASS,false))
            return new WP_Error('mad4b_presentation_owner_central_only','Central source unavailable');
        if (!preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$company) ||
            !in_array($environment,['staging','production'],true))
            return new WP_Error('mad4b_presentation_owner_invalid_scope','Company/environment invalid');
        $target=MAD4B_Company_Environment_Registry_V2::target($company,$environment);
        if(!is_array($target)||($target['company_id']??'')!==$company||
            !wp_is_uuid((string)($target['site_uuid']??'')) ||
            !preg_match('~^https://[a-zA-Z0-9.-]+$~D',(string)($target['canonical_origin']??'')))
            return new WP_Error('mad4b_presentation_owner_site_binding_required','Target identity is not enrolled');
        return [
            'contract'=>self::CONTRACT,'company_id'=>$company,'environment'=>$environment,
            'target_site_uuid'=>$target['site_uuid'],
            'target_origin'=>$target['canonical_origin'],
            'ownership_schema_sha256'=>self::digest(),
            'owner_count'=>count(self::registry()),
            'owners'=>self::registry(),
            'source_demo_brand_may_overwrite_target'=>false,
            'target_owned_data_must_be_preserved'=>true,
            'aliases_are_not_independent_runtime_sources'=>true,
            'target_languages_must_be_read_from_target_wpml'=>true,
            'source_language_count_is_not_target_activation_instruction'=>true,
            'required_target_readbacks'=>[
                'elementor_active_kit + selected site settings + rendering',
                'wpml active languages, actual localized widget settings',
                'jetengine look-and-feel and contact option page active field owners',
                'elementor theme builder header/footer matching conditions',
                'woocommerce currency and pricing orchestrator',
                'actual target template/media/JetEngine ID mappings',
                'frontend header/footer/archive/CTA/social widget dynamic binding',
            ],
            'reconcile_steps'=>[
                'read target canonical owner values and aliases',
                'keep target-owned brand, business and commerce values, never demo-seed them',
                'remap imported media and Elementor/JetEngine IDs to the target',
                'bind each editable alias to its canonical owner or make it read-only',
                'do not mark a field completed until its frontend consumer actually reads it',
                'leave optional missing social/contact/promotions as advisories',
                'rebuild CSS and cache, test active target languages',
                'require original authorized Native 305 and exact target 313 readback',
            ],
            'state'=>'OWNERSHIP_SCHEMA_READY_TARGET_READBACK_REQUIRED',
            'target_readback_verified'=>false,'native_write_authorized'=>false,
            'production_mutation'=>false,
        ];
    }

    /**
     * Evaluate a bounded, read-only target WP-CLI snapshot (never a receipt).
     * Do not expose actual emails/phone numbers; report only mismatch states.
     * The active language, and per-language bindings, need separate target proof.
     */
    public static function compare_snapshot(array $plan,array $snapshot): array {
        $valid=($plan['contract']??'')===self::CONTRACT &&
            ($snapshot['site_uuid']??'')===($plan['target_site_uuid']??null) &&
            ($snapshot['origin']??'')===($plan['target_origin']??null) &&
            ($snapshot['environment']??'')===($plan['environment']??null);
        if(!$valid)return ['contract'=>self::CONTRACT.'.comparison',
            'state'=>'SNAPSHOT_IDENTITY_MISMATCH','target_readback_verified'=>false,
            'ready_to_apply'=>false];
        $canonical=is_array($snapshot['canonical']??null)?$snapshot['canonical']:[];
        $aliases=is_array($snapshot['aliases']??null)?$snapshot['aliases']:[];
        $consumerBindings=is_array($snapshot['consumer_bindings']??null)?$snapshot['consumer_bindings']:[];
        $statuses=[];$drifts=[];$advisories=[];$pending=[];$aliasDrifts=[];$unboundEditors=[];$bindingBreaks=[];
        $normalize=static function($value):string {
            // JetEngine media fields may store an attachment object instead of
            // the numeric ID returned by Elementor Kit site settings.
            if(is_array($value))$value=$value['id']??$value['value']['id']??null;
            return is_scalar($value)?trim((string)$value):'';
        };
        foreach(self::registry() as $key=>$rule) {
            $owner=$rule['owner'];
            $field=$rule['field'];
            $canonicalValue=$normalize($canonical[$owner][$field]??null);
            $idReference=in_array(($rule['remap']??''),['attachment','template_wpml','jetengine'],true);
            if($idReference&&$canonicalValue==='0')$canonicalValue='';
            $aliasesForField=[];
            if(is_string($rule['alias']??null)&&$rule['alias']!=='')
                $aliasesForField[]=$rule['alias'];
            foreach((array)($rule['secondary_aliases']??[]) as $a)
                if(is_string($a) && $a!=='')$aliasesForField[]=$a;
            $fieldDrifts=[];$emptyEditors=[];
            $isLocalized=($rule['language']??'')==='wpml_localized'
                && ($snapshot['observed_language']??'')!=='en';
            foreach($aliasesForField as $aliasKey) {
                if(!array_key_exists($aliasKey,$aliases))continue;
                $alternative=$normalize($aliases[$aliasKey]);
                if($idReference&&$alternative==='0')$alternative='';
                if($canonicalValue===''&&$alternative!=='') {
                    $fieldDrifts[]=$aliasKey;
                }elseif(!$isLocalized && $canonicalValue!=='' && $alternative!==''
                    && $canonicalValue!==$alternative) {
                    $fieldDrifts[]=$aliasKey;
                }elseif($alternative==='' && $canonicalValue!=='') {
                    // The UI alias is present but its readback does not project
                    // the live owner. This is an editor integration advisory,
                    // not a reason to overwrite a healthy runtime owner.
                    $emptyEditors[]=$aliasKey;
                }
            }
            if($owner==='unresolved_runtime_widget'){
                $status='RUNTIME_WIDGET_BINDING_REQUIRED';
                $pending[]=$key;
            }elseif($fieldDrifts){
                $status=$canonicalValue===''?'CANONICAL_OWNER_EMPTY_ALIAS_POPULATED':'ALIAS_DRIFT_FROM_RUNTIME_OWNER';
                $drifts[]=$key;
                $aliasDrifts[$key]=$fieldDrifts;
            }elseif($canonicalValue==='' && in_array(($rule['empty']??''),['advisory','disabled'],true)){
                $status='OPTIONAL_UNSET';
                $advisories[]=$key;
            }elseif($emptyEditors){
                $status='EDITOR_ALIAS_NOT_PROJECTING_CANONICAL';
                $unboundEditors[$key]=$emptyEditors;
                $advisories[]=$key;
            }else{
                $status='RUNTIME_OWNER_PRESENT_OR_TARGET_PROOF_REQUIRED';
                $pending[]=$key;
            }
            $binding=$consumerBindings[$key]??null;
            if(is_array($binding)&&($binding['active']??false)===true) {
                if(($binding['reads_canonical_owner']??null)!==true) {
                    // Aligned stored values are not evidence that the active
                    // Elementor widget actually consumes the correct field.
                    $bindingBreaks[]=$key;
                    $status='ACTIVE_UI_NOT_READING_CANONICAL_OWNER';
                }elseif($status==='RUNTIME_OWNER_PRESENT_OR_TARGET_PROOF_REQUIRED') {
                    $status='CONSUMER_READBACK_OBSERVED_NOT_CERTIFIED';
                }
            }
            $statuses[$key]=['owner'=>$owner,'scope'=>$rule['scope'],'status'=>$status];
        }
        return ['contract'=>self::CONTRACT.'.comparison',
          'state'=>'TARGET_VALUES_COMPARED_NOT_AUTHORIZED',
          'statuses'=>$statuses,'drift_count'=>count($drifts),
          'drift_keys'=>$drifts,'drift_aliases'=>$aliasDrifts,
          'advisory_keys'=>$advisories,'editor_alias_projection_missing'=>$unboundEditors,
          'binding_break_count'=>count($bindingBreaks),'active_widget_binding_breaks'=>$bindingBreaks,
          'binding_proof_pending'=>$pending,
          'target_readback_verified'=>false,'ready_to_apply'=>false,
          'original_305_issued'=>false,'original_313_verified'=>false,
          'writes_performed'=>false];
    }
}
