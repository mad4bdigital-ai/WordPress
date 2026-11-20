<?php
/**
 * MAD4B Pricing Dimensions v1
 *
 * User-selected targeting dimensions backed by the EXISTING JetEngine
 * Strategy Schema Bridge. Saving piggybacks on the native Options Page
 * after-save hook. It does not change rate values, pricing quotes, or
 * booking approval. A custom set restricts new and revalidated Lab rules.
 */
defined('ABSPATH') || exit;
final class MAD4B_JetEngine_Pricing_Dimensions_V1 {
    public const OPTION='mad4b_pricing_dimension_preferences_v1';
    public const ACTION='mad4b_pricing_dimensions_catalog_v1';
    public const SAVE_ACTION='mad4b_pricing_dimensions_native_save_v1';
    public const SLUG='etg-dynamic-pricing-controls';
    private const MAX_ITEMS=90;
    public static function boot(): void {
        if (!is_admin()) return;
        add_action('wp_ajax_'.self::ACTION,[__CLASS__,'ajax_catalog']);
        add_action('jet-engine/options-pages/after-save/'.self::SLUG,
            [__CLASS__,'native_after_save'],10,1);
    }
    public static function current(): array {
        $v=get_option(self::OPTION,[]);
        if(!is_array($v) || !in_array(($v['mode']??''),['all','custom'],true))
            return ['mode'=>'all','enabled_ids'=>[],'saved'=>false,
                'revision'=>0,'updated_at'=>null];
        return [
            'mode'=>$v['mode'],
            'enabled_ids'=>array_values(array_filter(
                (array)($v['enabled_ids']??[]),'is_string')),
            'saved'=>true,
            'revision'=>max(0,(int)($v['revision']??0)),
            'updated_at'=>isset($v['updated_at'])?(string)$v['updated_at']:null,
        ];
    }
    /** One policy authoring language per company; display translations inherit. */
    public static function language_policy(): array {
        $source='MAD4B_JetEngine_Tour_Preview_Filter_Registry_V1';
        $languages=$source::languages();
        $default=sanitize_key((string)apply_filters('wpml_default_language',''));
        if(!isset($languages[$default]))$default=array_key_first($languages)?:'en';
        $raw=get_option(self::OPTION,[]);
        $raw=is_array($raw)?$raw:[];
        $mode=($raw['language_mode']??'')==='per_language'
            ?'per_language':'inherit_default';
        $authoring=sanitize_key((string)($raw['authoring_language']??$default));
        if(!isset($languages[$authoring]))$authoring=$default;
        if($mode==='inherit_default')$authoring=$default;
        return [
            'mode'=>$mode,'default_language'=>$default,
            'authoring_language'=>$authoring,'available_languages'=>$languages,
            'effective_languages'=>$mode==='inherit_default'
                ?array_keys($languages):[$authoring],
            'saved'=>isset($raw['language_mode']),
            'scope'=>'pricing_lab_authoring_and_preview_not_checkout',
        ];
    }
    /** Only a discovered, safe field can become a selectable dimension. */
    public static function available(): array {
        $bridge='MAD4B_JetEngine_Strategy_Schema_Bridge_V1';
        $languages=self::language_policy();
        // Preserve the complete multi-language inventory for discovery, but
        // count values available to the actual policy editor (canonical
        // WPML language). Without this distinction, 7 weekdays appear as
        // 14 on EN/ES or 42 on a six-language site.
        $catalog=$bridge::catalog(array_keys($languages['available_languages']),false);
        $canonical=$bridge::catalog([$languages['default_language']],false);
        $canonicalCounts=[];
        foreach($canonical['dimensions'] as $dimension)
            if(is_array($dimension)&&isset($dimension['id']))
                $canonicalCounts[(string)$dimension['id']]
                    =count((array)($dimension['options']??[]));
        $out=[];
        foreach($catalog['dimensions'] as $entry){
            if(!is_array($entry))continue;
            $id=(string)($entry['id']??'');
            if(!preg_match('/^(taxonomy|meta|metabox):[a-z0-9_-]{2,80}$/D',$id))
                continue;
            $source=(string)($entry['source']??'');
            $out[$id]=[
                'id'=>$id,'label'=>sanitize_text_field((string)($entry['label']??$id)),
                'source'=>$source,
                'group'=>sanitize_key((string)($entry['group']??'other')),
                'kind'=>(string)($entry['kind']??'multi'),
                'options_count'=>$canonicalCounts[$id]??0,
                'source_language'=>$languages['default_language'],
                'wpml_term_records_count'=>count((array)($entry['options']??[])),
                'is_advanced'=>!isset($canonicalCounts[$id])
                    ||in_array($entry['group']??'other',['other'],true)
                    || ($entry['kind']??'')==='range',
            ];
            if(count($out)>=self::MAX_ITEMS)break;
        }
        return array_values($out);
    }
    /** Used by Pricing Lab catalog and rule validation (not live quotes). */
    public static function filter_catalog(array $dimensions): array {
        $state=self::current();
        if($state['mode']==='all')return $dimensions; // no change for preexisting sites
        $ids=array_fill_keys($state['enabled_ids'],true);
        return array_values(array_filter($dimensions,
            static fn($x)=>isset($ids[(string)($x['id']??'')])));
    }
    public static function ajax_catalog(): void {
        if(!MAD4B_JetEngine_Pricing_Controls_Access_V1::authorized())
            wp_send_json_error(['message'=>'Pricing permission required'],403);
        check_ajax_referer(self::ACTION,'nonce');
        $op=isset($_POST['operation'])&&is_string($_POST['operation'])
            ?sanitize_key(wp_unslash($_POST['operation'])):'catalog';
        if($op==='dimension') {
            $id=isset($_POST['dimension_id'])&&is_string($_POST['dimension_id'])
                ?sanitize_text_field(wp_unslash($_POST['dimension_id'])):'';
            $raw=isset($_POST['languages'])&&is_string($_POST['languages'])
                ?wp_unslash($_POST['languages']):'["en"]';
            if(strlen($raw)>1500)wp_send_json_error(['message'=>'Languages payload too large'],400);
            $langs=json_decode($raw,true);
            if(!is_array($langs))wp_send_json_error(['message'=>'Invalid languages'],400);
            $resolver='MAD4B_JetEngine_Tour_Preview_Filter_Registry_V1';
            $langs=$resolver::selected_languages($langs);
            $bridge='MAD4B_JetEngine_Strategy_Schema_Bridge_V1';
            $catalog=$bridge::catalog($langs,false);
            $allowed=array_fill_keys(array_column(self::available(),'id'),true);
            foreach($catalog['dimensions'] as $dimension) {
                if($id!==($dimension['id']??'')||!isset($allowed[$id]))continue;
                wp_send_json_success(['dimension'=>$dimension,'languages'=>$langs,
                    'help'=>MAD4B_Pricing_Contextual_Help_V1::dimension_help($dimension),
                    'source'=>'native_jetengine','read_only'=>true]);
            }
            wp_send_json_error(['message'=>'Dimension not found for selected language'],404);
        }
        // Read-only, on-demand inspection of a published Tour Rate via
        // the already-authorized shared Hybrid Policy/Rate Generator
        // resolver. Same WP nonce and pricing capability as the editor.
        if($op==='inheritance'){
            $rate=isset($_POST['rate_id'])?absint($_POST['rate_id']):0;
            if($rate<1||get_post_type($rate)!=='tour-rates'
                ||get_post_status($rate)!=='publish')
                wp_send_json_error(['message'=>'Choose a published Tour Rate'],400);
            $tour=(int)get_post_meta($rate,'related_tour_id',true);
            $bridge='MAD4B_Hybrid_Rate_Generator_Bridge_V1';
            if(!class_exists($bridge))
                wp_send_json_error(['message'=>'Policy inheritance provider unavailable'],503);
            $inspected=$bridge::inspect('pricing_controls_preview','tour',$tour,$rate);
            if(($inspected['status']??'')!=='ready')
                wp_send_json_error(['message'=>'Could not inspect this Rate',
                    'reason'=>$inspected['status']??'unavailable'],409);
            wp_send_json_success($inspected);
        }
        if(in_array($op,['preview','save_draft'],true)) {
            $raw=isset($_POST['config'])&&is_string($_POST['config'])
                ?wp_unslash($_POST['config']):'';
            if(strlen($raw)>10000)wp_send_json_error(['message'=>'Policy request too large'],413);
            $config=json_decode($raw,true);
            if(!is_array($config))wp_send_json_error(['message'=>'Invalid policy draft'],400);
            // Delegate ALL pricing and target validation to the existing Lab.
            // Its strategy bridge filters out dimensions disabled by company
            // policy; it never changes active prices or checkout rules.
            $lab='MAD4B_JetEngine_Pricing_Lab_V1';
            if($op==='save_draft') {
                if(!isset($_POST['confirm'])||!is_string($_POST['confirm'])
                    ||wp_unslash($_POST['confirm'])!=='save_inactive_preset_draft')
                    wp_send_json_error(['message'=>'Draft confirmation required'],400);
                $result=$lab::save($config,'',0);
            }else $result=$lab::preview($config);
            if(is_wp_error($result))wp_send_json_error([
                'code'=>$result->get_error_code(),
                'message'=>$result->get_error_message()],409);
            wp_send_json_success($result);
        }
        if($op!=='catalog')wp_send_json_error(['message'=>'Unknown dimension operation'],400);
        $catalog=self::available();
        $state=self::current();
        $language=self::language_policy();
        $active=$state['mode']==='all'
            ?array_column($catalog,'id')
            :array_values(array_intersect(
                array_column($catalog,'id'),$state['enabled_ids']));
        $lab=MAD4B_JetEngine_Pricing_Lab_V1::load();
        wp_send_json_success([
            'status'=>'ready',
            'native_templates'=>$lab['native_templates'],
            // This is the centrally configured JetEngine Glossary catalog,
            // already filtered by allowed new-Rate currencies.
            'currency_choices'=>class_exists('MAD4B_Pricing_Currency_Configuration_V1')
                ?MAD4B_Pricing_Currency_Configuration_V1::authoring_choices():[],
            'languages'=>$lab['languages'],
            'pricing_language'=>$language,
            'market_countries'=>$lab['catalog']['market_countries']??[],
            'existing_draft_presets'=>count($lab['presets']),
            'registry'=>'native_jetengine_strategy_schema',
            'catalog'=>$catalog,
            'mode'=>$state['mode'],
            'enabled_ids'=>$active,
            'available'=>count($catalog),
            'active'=>count($active),
            'saved'=>$state['saved'],
            'updated_at'=>$state['updated_at'],
            'revision'=>$state['revision'],
            'effect'=>'pricing_lab_targeting_eligibility_only',
            'live_prices_changed'=>false,
        ]);
    }
    public static function native_after_save($page): void {
        if(!is_object($page)
            || (string)($page->slug??'')!==self::SLUG
            || !MAD4B_JetEngine_Pricing_Controls_Access_V1::authorized())return;
        // The JetEngine save callback already verified the page capability;
        // our own signed hidden form fields independently authorize the new
        // preference. Missing fields mean an unmodified legacy editor.
        $nonce=isset($_POST['mad4b_pricing_dimensions_nonce'])
            &&is_string($_POST['mad4b_pricing_dimensions_nonce'])
                ?sanitize_text_field(wp_unslash($_POST['mad4b_pricing_dimensions_nonce'])):'';
        if(!$nonce || !wp_verify_nonce($nonce,self::SAVE_ACTION))return;
        $mode=isset($_POST['mad4b_pricing_dimensions_mode'])
            &&is_string($_POST['mad4b_pricing_dimensions_mode'])
                ?sanitize_key(wp_unslash($_POST['mad4b_pricing_dimensions_mode'])):'';
        if(!in_array($mode,['all','custom'],true))return;
        $raw=isset($_POST['mad4b_pricing_dimensions_selected'])
            &&is_string($_POST['mad4b_pricing_dimensions_selected'])
                ?wp_unslash($_POST['mad4b_pricing_dimensions_selected']):'';
        if(strlen($raw)>4000)return;
        $selected=json_decode($raw,true);
        if(!is_array($selected)||count($selected)>self::MAX_ITEMS)return;
        $registered=array_fill_keys(array_column(self::available(),'id'),true);
        $clean=[];
        foreach($selected as $id) {
            if(!is_string($id)||!isset($registered[$id]))return;
            $clean[$id]=$id;
        }
        $languageMode=isset($_POST['mad4b_pricing_language_mode'])
            &&is_string($_POST['mad4b_pricing_language_mode'])
                ?sanitize_key(wp_unslash($_POST['mad4b_pricing_language_mode'])):'';
        $language=isset($_POST['mad4b_pricing_authoring_language'])
            &&is_string($_POST['mad4b_pricing_authoring_language'])
                ?sanitize_key(wp_unslash($_POST['mad4b_pricing_authoring_language'])):'';
        $beforeLanguage=self::language_policy();
        if($languageMode==='')$languageMode=$beforeLanguage['mode'];
        if(!in_array($languageMode,['inherit_default','per_language'],true))return;
        if($language==='')$language=$beforeLanguage['authoring_language'];
        if(!isset($beforeLanguage['available_languages'][$language]))return;
        if($languageMode==='inherit_default')
            $language=$beforeLanguage['default_language'];
        $before=self::current();
        $value=[
            'mode'=>$mode,
            'language_mode'=>$languageMode,
            'authoring_language'=>$language,
            'enabled_ids'=>$mode==='all'?[]:array_values($clean),
            'revision'=>$before['revision']+1,
            'updated_at'=>gmdate('c'),
            'actor'=>get_current_user_id(),
            'scope'=>'pricing_lab_targeting_only',
        ];
        update_option(self::OPTION,$value,false);
    }
}
