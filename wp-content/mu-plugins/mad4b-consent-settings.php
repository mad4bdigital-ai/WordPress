<?php
/**
 * Plugin Name: MAD4B Consent Company Settings
 * Description: Native JetEngine Look & Feel fields for tenant-scoped multilingual consent design, regional rules, tag governance and audit retention.
 * Version: 0.2.4
 */
defined('ABSPATH') || exit;
if (!class_exists('MAD4B_Consent_Settings',false)) {
final class MAD4B_Consent_Settings {
    private const LANGUAGES=['en','ar','fr','de','es','it'];
    private const LABEL_KEYS=['title','intro','analytics','ads','analytics_desc','ads_desc','all','reject','customize','save','manage','options','never','receipt_note'];
    public static function raw():array {
        $a=get_option('look-and-feel',[]);
        return is_array($a)?$a:[];
    }
    public static function settings():array {
        $a=self::raw();
        $color=(string)($a['consent_primary_color']??'#0f5866');
        if(!preg_match('/^#[A-Fa-f0-9]{6}$/D',$color))$color='#0f5866';
        $position=(string)($a['consent_position']??'bottom_left');
        if(!in_array($position,['bottom_left','bottom_right','bottom_center'],true))$position='bottom_left';
        $strategy=(string)($a['consent_tag_strategy']??'shared_both');
        if(!in_array($strategy,['shared_both','verified_gtm_any','split_google_tags'],true))$strategy='shared_both';
        // UI review flags alone cannot authorize a mixed tracking container.
        // Require source-backed GTM Workspace proof, exact published-script
        // fingerprint, and an independent per-tag consent review.
        $manualReview=($a['consent_gtm_tag_inventory_reviewed']??'no')==='yes'
            && ($a['legal_tracking_consent_status']??'needs_review')==='verified';
        $workspaceProof=class_exists('MAD4B_GTM_Consent_Governance',false)
            && MAD4B_GTM_Consent_Governance::eligible();
        $tagsReviewed=$manualReview && $workspaceProof;
        if($strategy==='verified_gtm_any' && !$tagsReviewed)$strategy='shared_both';
        $ga4=(string)($a['consent_ga4_measurement_id']??'');
        if(!preg_match('/^G-[A-Z0-9]{6,18}$/D',$ga4))$ga4='';
        $ads=(string)($a['consent_google_ads_id']??'');
        if(!preg_match('/^AW-[0-9]{6,16}$/D',$ads))$ads='';
        $ttl=max(1,min(365,(int)($a['consent_retention_days']??180)));
        $marketCountry='';
        // Explicitly chosen and signed country only. Never infer from IP or nationality.
        if(class_exists('MAD4B_Country_Switcher'))$marketCountry=(string)MAD4B_Country_Switcher::saved_country();
        $marketCountry=preg_match('/^[A-Z]{2}$/D',$marketCountry)?$marketCountry:'';
        $rules=(array)($a['consent_market_rules']??[]);
        foreach(array_slice($rules,0,80) as $rule) {
            if(!is_array($rule)||$marketCountry===''||
               strtoupper((string)($rule['country_code']??''))!==$marketCountry)continue;
            $days=(int)($rule['ttl_days']??0);
            if($days>=1&&$days<=365)$ttl=min($ttl,$days); // market never relaxes global TTL
            break;
        }
        return [
          'ttl_days'=>$ttl,
          'receipt_days'=>max(30,min(730,(int)($a['consent_receipt_retention_days']??365))),
          'primary_color'=>$color,
          'position'=>$position,
          'width_px'=>max(320,min(560,(int)($a['consent_width_px']??480))),
          'radius_px'=>max(0,min(24,(int)($a['consent_radius_px']??12))),
          'strategy'=>$strategy,
          'gtm_tag_reviewed'=>$tagsReviewed,
          'ga4_id'=>$ga4,
          'ads_id'=>$ads,
          'country'=>$marketCountry,
          'policy_revision'=>(string)($a['consent_policy_revision']??'2026-11-10'),
        ];
    }
    public static function labels(array $defaults,string $language):array {
        $settings=self::raw();
        if(!in_array($language,self::LANGUAGES,true))$language='en';
        foreach(self::LABEL_KEYS as $key){
            $raw=$settings['consent_'.$key.'_'.$language]??null;
            if(!is_string($raw)||trim($raw)==='')continue;
            $clean=trim(wp_strip_all_tags($raw));
            if(mb_strlen($clean)>700)$clean=mb_substr($clean,0,700);
            $defaults[$key]=$clean;
        }
        $mode=self::settings()['strategy'];
        if(in_array($mode,['verified_gtm_any','split_google_tags'],true) &&
           empty($settings['consent_intro_'.$language])) {
            $wording=[
             'en'=>'Essential features remain available. Analytics and marketing are separate optional choices; only technologies for permitted categories may run.',
             'es'=>'Las funciones esenciales siguen disponibles. Analítica y marketing son decisiones independientes; solo funcionan las tecnologías de las categorías permitidas.',
             'ar'=>'تظل الوظائف الأساسية متاحة. التحليلات والتسويق خياران مستقلان؛ ولا تعمل إلا تقنيات الفئات التي وافقت عليها.',
             'fr'=>'Les fonctions essentielles restent disponibles. Analyse et marketing sont des choix indépendants; seules les technologies autorisées fonctionnent.',
             'de'=>'Notwendige Funktionen bleiben verfügbar. Analyse und Marketing sind getrennte Optionen; nur genehmigte Technologien werden geladen.',
             'it'=>'Le funzioni essenziali restano disponibili. Analisi e marketing sono scelte separate; vengono caricate solo le tecnologie autorizzate.'
            ];
            $defaults['intro']=$wording[$language]??$wording['en'];
        }
        return $defaults;
    }
    public static function optionFields($fields,$page) {
        if(!is_object($page)||($page->slug??'')!=='look-and-feel'||!is_array($fields))return $fields;
        $items=[
         ['name'=>'mad4b_consent_tab','title'=>'Privacy & Cookie Consent','object_type'=>'tab','tab_layout'=>'horizontal'],
         ['name'=>'mad4b_consent_section_info','title'=>'Company consent settings','type'=>'html',
          'html'=>'<p>Per-company settings. No pre-consent tracking or silent acceptance. Shared GTM loads after both categories unless its tags were independently audited. Preview is for logged-in administrators and does not save a new visitor decision.</p>'],
         ['name'=>'mad4b_consent_preview','title'=>'Admin-only consent popup preview','type'=>'html',
          'html'=>'<p><a href="'.esc_url(add_query_arg(['mad4b_consent_preview'=>'1','_wpnonce'=>wp_create_nonce('mad4b_consent_preview')],
            get_privacy_policy_url()?:home_url('/'))).'" target="_blank" rel="noopener noreferrer">Preview the floating consent window</a> · <a href="'.esc_url(admin_url('tools.php?page=mad4b-consent-qa')).'">Open Consent QA</a></p>'],
         ['name'=>'consent_primary_color','title'=>'Primary button / accent · #RRGGBB','type'=>'text'],
         ['name'=>'consent_position','title'=>'Floating banner position','type'=>'select','options_source'=>'manual',
          'options'=>[['key'=>'bottom_left','value'=>'Bottom left'],['key'=>'bottom_right','value'=>'Bottom right'],['key'=>'bottom_center','value'=>'Bottom center']]],
         ['name'=>'consent_width_px','title'=>'Popup width (320–560 px)','type'=>'number','min_value'=>320,'max_value'=>560],
         ['name'=>'consent_radius_px','title'=>'Corner radius (0–24 px)','type'=>'number','min_value'=>0,'max_value'=>24],
         ['name'=>'consent_retention_days','title'=>'Visitor choice expiry (1–365 days)','type'=>'number','min_value'=>1,'max_value'=>365],
         ['name'=>'consent_receipt_retention_days','title'=>'Consent proof retention (30–730 days)','type'=>'number','min_value'=>30,'max_value'=>730],
         ['name'=>'consent_policy_revision','title'=>'Published privacy/consent text revision','type'=>'text',
          'description'=>'Increment when material consent terms change; previous choices then require renewal.'],
         ['name'=>'consent_tag_strategy','title'=>'Tag loading policy','type'=>'select','options_source'=>'manual',
          'options'=>[['key'=>'shared_both','value'=>'Shared GTM: load only after both categories (safe default)'],
                    ['key'=>'verified_gtm_any','value'=>'Shared GTM: partial consent only with authenticated GTM Workspace proof'],
                    ['key'=>'split_google_tags','value'=>'Separate GA4 / Google Ads IDs (disables shared GTM load)']]],
         ['name'=>'consent_gtm_tag_inventory_reviewed','title'=>'GTM tag inventory verified for consent','type'=>'select','options_source'=>'manual',
          'options'=>[['key'=>'no','value'=>'No (safety default)'],['key'=>'yes','value'=>'Review requested: requires independent GTM Workspace verification']]],
         ['name'=>'consent_ga4_measurement_id','title'=>'GA4 measurement ID (only for split Google Tags)','type'=>'text',
          'description'=>'Format G-XXXXXXXXX. Avoid configuring the same GA4 ID in a live GTM container.'],
         ['name'=>'consent_google_ads_id','title'=>'Google Ads tag ID (only for split Google Tags)','type'=>'text',
          'description'=>'Format AW-123456789. This is not a conversion event.'],
         ['name'=>'consent_market_rules','title'=>'Opt-in expiry by explicitly selected market · never relaxes global consent','type'=>'repeater','repeater-fields'=>[
            ['name'=>'country_code','title'=>'ISO 3166-1 alpha-2 country code','type'=>'text','object_type'=>'field'],
            ['name'=>'ttl_days','title'=>'Shorter consent lifespan in days','type'=>'number','object_type'=>'field'],
         ],'description'=>'Only applies to an explicitly selected country via the site country switcher; never inferred from an IP address.'],
        ];
        $names=['title'=>'Banner heading','intro'=>'Explanatory text','analytics'=>'Analytics label','ads'=>'Marketing label',
          'analytics_desc'=>'Analytics explanation','ads_desc'=>'Marketing explanation','all'=>'Accept all label',
          'reject'=>'Reject optional label','customize'=>'Customize label','save'=>'Save label','manage'=>'Reopen preferences label',
          'options'=>'Optional categories heading','never'=>'Pre-consent notice',
          'receipt_note'=>'Consent receipt privacy notice · use {days} placeholder'];
        foreach(self::LANGUAGES as $lang){
           $items[]=['name'=>'mad4b_consent_language_'.$lang,
                     'title'=>'Cookie consent labels: '.strtoupper($lang),
                     'object_type'=>'field','type'=>'html',
                     'html'=>'<h3 style="margin:14px 0 5px">'.esc_html('Cookie consent labels: '.strtoupper($lang)).'</h3>'];
           foreach($names as $key=>$label) {
               $items[]=['name'=>'consent_'.$key.'_'.$lang,'title'=>$label.' ['.strtoupper($lang).']',
                         'type'=>in_array($key,['intro','analytics_desc','ads_desc','never','receipt_note'],true)?'textarea':'text'];
           }
        }
        $known=[];
        foreach($fields as $f)if(is_array($f)&&isset($f['name']))$known[(string)$f['name']]=true;
        foreach($items as $i=>$f) {
            if(isset($known[$f['name']]))continue;
            $fields[]=array_replace(['object_type'=>'field','id'=>947000+$i,'width'=>'100%'],$f);
        }
        return $fields;
    }
    public static function diagnostics():array {
        $s=self::settings();
        return [
          'site'=>site_url(),
          'strategy'=>$s['strategy'],
          'gtm_reviewed'=>$s['gtm_tag_reviewed'],
          'gtm_workspace_evidence'=>class_exists('MAD4B_GTM_Consent_Governance',false)
              ?MAD4B_GTM_Consent_Governance::status()['blockers']:['audit_engine_missing'],
          'configured_ga4_id'=> $s['ga4_id']!=='' ,
          'configured_ads_id'=> $s['ads_id']!=='' ,
          'consent_defaults'=>'denied',
          'selected_market'=>$s['country'],
          'privacy_page'=>(int)get_option('wp_page_for_privacy_policy'),
          'tag_manager'=>class_exists('MAD4B_Consent_Gateway')?MAD4B_Consent_Gateway::id():'',
          'gtm4wp_suppressed'=>!apply_filters('gtm4wp_output_container',true)
        ];
    }
    public static function boot():void {
        add_filter('jet-engine/options-pages/raw-fields',[self::class,'optionFields'],125,2);
    }
}
MAD4B_Consent_Settings::boot();
}
