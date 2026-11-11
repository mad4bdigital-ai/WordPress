<?php
/**
 * Plugin Name: MAD4B Consent Gateway
 * Description: Company-local, opt-in GTM load; separate optional analytics/marketing choices.
 * Version: 0.2.4
 */
defined('ABSPATH') || exit;

if (!class_exists('MAD4B_Consent_Gateway',false)) {
final class MAD4B_Consent_Gateway {
    public static function id():string {
        $options=(array)get_option('gtm4wp-options',[]);
        $items=(array)($options['gtm-containers']??[]);
        $ids=[];
        foreach($items as $row) {
            if(!is_array($row))continue;
            $id=(string)($row['id']??'');
            if(preg_match('/^GTM-[A-Z0-9]{6,20}$/D',$id))$ids[$id]=$id;
        }
        if(!$ids){
            $fallback=(string)($options['gtm-code']??'');
            if(preg_match('/^GTM-[A-Z0-9]{6,20}$/D',$fallback))$ids[$fallback]=$fallback;
        }
        return implode(',',array_values($ids));
    }
    public static function dl():string {
        $opts=(array)get_option('gtm4wp-options',[]);
        $dl=(string)($opts['gtm-datalayer-variable-name']??'');
        return preg_match('/^[A-Za-z_$][A-Za-z0-9_$]{0,55}$/D',$dl)?$dl:'dataLayer';
    }
    public static function suppress($value) {
        // Keep GTM4WP data layer, shopping integration and events;
        // suppress only its pre-consent container script/noscript.
        return false;
    }
    public static function suppressGtmDefaults($enabled):bool {
        return false; // This gateway owns the single early consent default.
    }
    private static function lang():string {
        $lang=(string)apply_filters('wpml_current_language','en');
        return in_array($lang,['en','es','fr','de','it','ar'],true)?$lang:'en';
    }
    private static function strings():array {
        $common=[
          'en'=>['title'=>'Your privacy choices','intro'=>'Essential site features always work. Optional analytics and marketing tags run only if you explicitly allow both categories. You may change your choice at any time.','analytics'=>'Analytics','ads'=>'Marketing','analytics_desc'=>'Measure site visits and improve journeys.','ads_desc'=>'Measure advertisements and campaign performance.','all'=>'Accept all optional','reject'=>'Reject optional','customize'=>'Customize','save'=>'Save preferences','manage'=>'Cookie settings','privacy'=>'Privacy Policy','options'=>'Optional categories','never'=>'Nothing optional loads before your choice.'],
          'es'=>['title'=>'Tus opciones de privacidad','intro'=>'Las funciones esenciales siempre funcionan. Las etiquetas opcionales de análisis y marketing solo se cargan cuando aceptas ambas categorías. Puedes cambiar tu decisión en cualquier momento.','analytics'=>'Analítica','ads'=>'Marketing','analytics_desc'=>'Medir visitas y mejorar la experiencia.','ads_desc'=>'Medir anuncios y campañas.','all'=>'Aceptar todo','reject'=>'Rechazar lo opcional','customize'=>'Personalizar','save'=>'Guardar preferencias','manage'=>'Configurar cookies','privacy'=>'Política de privacidad','options'=>'Categorías opcionales','never'=>'Ninguna etiqueta opcional se carga antes de tu elección.'],
          'fr'=>['title'=>'Vos choix de confidentialité','intro'=>'Les fonctions essentielles restent actives. Les balises d’analyse et de marketing ne sont chargées que si vous acceptez les deux catégories. Vous pouvez modifier votre choix.','analytics'=>'Analyse','ads'=>'Marketing','analytics_desc'=>'Mesurer les visites et améliorer le site.','ads_desc'=>'Mesurer les campagnes publicitaires.','all'=>'Tout accepter','reject'=>'Refuser le facultatif','customize'=>'Personnaliser','save'=>'Enregistrer les choix','manage'=>'Paramètres des cookies','privacy'=>'Politique de confidentialité','options'=>'Catégories facultatives','never'=>'Aucune balise facultative ne se charge avant votre choix.'],
          'de'=>['title'=>'Ihre Datenschutzauswahl','intro'=>'Notwendige Funktionen bleiben verfügbar. Analyse- und Marketing-Tags werden erst geladen, wenn Sie beiden Kategorien zustimmen. Sie können Ihre Auswahl ändern.','analytics'=>'Analyse','ads'=>'Marketing','analytics_desc'=>'Besuche messen und die Website verbessern.','ads_desc'=>'Werbung und Kampagnen messen.','all'=>'Alle akzeptieren','reject'=>'Optionale ablehnen','customize'=>'Anpassen','save'=>'Auswahl speichern','manage'=>'Cookie-Einstellungen','privacy'=>'Datenschutzerklärung','options'=>'Optionale Kategorien','never'=>'Vor Ihrer Auswahl werden keine optionalen Tags geladen.'],
          'it'=>['title'=>'Le tue scelte sulla privacy','intro'=>'Le funzioni essenziali restano attive. Le etichette di analisi e marketing vengono caricate solo se accetti entrambe le categorie. Puoi cambiare scelta.','analytics'=>'Analisi','ads'=>'Marketing','analytics_desc'=>'Misurare le visite e migliorare il sito.','ads_desc'=>'Misurare annunci e campagne.','all'=>'Accetta tutto','reject'=>'Rifiuta opzionali','customize'=>'Personalizza','save'=>'Salva preferenze','manage'=>'Impostazioni cookie','privacy'=>'Informativa sulla privacy','options'=>'Categorie facoltative','never'=>'Nessun tag facoltativo viene caricato prima della scelta.'],
          'ar'=>['title'=>'خيارات الخصوصية','intro'=>'تظل الخدمات الأساسية فعالة. لا تُحمّل وسوم التحليلات والتسويق الاختيارية إلا بعد موافقتك على الفئتين. يمكنك تغيير اختيارك في أي وقت.','analytics'=>'التحليلات','ads'=>'التسويق','analytics_desc'=>'قياس الزيارات وتحسين التجربة.','ads_desc'=>'قياس الإعلانات والحملات.','all'=>'الموافقة على الكل','reject'=>'رفض الاختياري','customize'=>'تخصيص','save'=>'حفظ الاختيارات','manage'=>'إعدادات ملفات الارتباط','privacy'=>'سياسة الخصوصية','options'=>'الفئات الاختيارية','never'=>'لن تُحمّل وسوم اختيارية قبل قرارك.']
        ];
        $default=$common[self::lang()];
        return class_exists('MAD4B_Consent_Settings')
            ?MAD4B_Consent_Settings::labels($default,self::lang()):$default;
    }
    public static function head():void {
        if(is_admin()||wp_doing_ajax()||is_feed())return;
        $settings=class_exists('MAD4B_Consent_Settings')?MAD4B_Consent_Settings::settings():[];
        $ids=array_values(array_filter(explode(',',self::id())));
        if(!$ids && empty($settings['ga4_id']) && empty($settings['ads_id']))return;
        $locale=self::lang();
        // One consent decision applies across WPML languages of the same company.
        // Any translated privacy page or consent wording revision renews it for all.
        $basePrivacy=(int)get_option('wp_page_for_privacy_policy');
        $policyParts=['revision'=>(string)($settings['policy_revision']??'2026-11-10'),
                      'privacy'=>[],'labels'=>[]];
        $optionValues=(array)get_option('look-and-feel',[]);
        foreach(['en','ar','fr','de','es','it'] as $language){
            $page=$basePrivacy?(int)apply_filters('wpml_object_id',$basePrivacy,'page',false,$language):0;
            if($page && get_post_status($page)==='publish')
                $policyParts['privacy'][$language]=[(int)$page,(string)get_post_field('post_modified_gmt',$page)];
            foreach($optionValues as $k=>$v)
                if(is_string($k)&&str_starts_with($k,'consent_')&&str_ends_with($k,'_'.$language)
                   &&is_scalar($v)&&trim((string)$v)!=='')
                    $policyParts['labels'][$k]=(string)$v;
        }
        ksort($policyParts['labels']);
        $fingerprint='v2.'.substr(hash('sha256',wp_json_encode($policyParts)),0,40);
        $config=[
            'ids'=>$ids,
            'dataLayer'=>self::dl(),
            'site'=>(string)wp_parse_url(home_url('/'),PHP_URL_HOST),
            'ttl'=>(int)($settings['ttl_days']??180),
            'country'=>(string)($settings['country']??''),
            'strategy'=>(string)($settings['strategy']??'shared_both'),
            'gtmAudited'=>(bool)($settings['gtm_tag_reviewed']??false),
            'ga4'=>(string)($settings['ga4_id']??''),
            'ads'=>(string)($settings['ads_id']??''),
            'policy'=>$fingerprint,
            'language'=>$locale,
            'receiptEndpoint'=>esc_url_raw(rest_url('mad4b-consent/v1/receipt')),
            'policyProof'=>hash_hmac('sha256',$fingerprint.'|'.$locale,wp_salt('auth')),
            'preview'=>current_user_can('manage_options')
                && isset($_GET['mad4b_consent_preview'])
                && wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce']??'')),'mad4b_consent_preview'),
        ];
        $json=wp_json_encode($config,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_QUOT|JSON_HEX_APOS);
        $script=__DIR__.'/assets/mad4b-consent-gateway-head.js';
        if(!$json || !is_file($script))return;
        echo "\n<script id=\"mad4b-consent-gate-head\" data-no-optimize=\"1\">\n";
        echo 'window.MAD4BConsentConfig='.$json.";\n";
        readfile($script);
        echo "\n</script>\n";
    }
    public static function foot():void {
        $config=class_exists('MAD4B_Consent_Settings')?MAD4B_Consent_Settings::settings():[];
        if(is_admin() || wp_doing_ajax() || is_feed() ||
            (!self::id() && empty($config['ga4_id']) && empty($config['ads_id'])))return;
        $t=self::strings();
        $privacy=(int)get_option('wp_page_for_privacy_policy');
        $translated=$privacy?(int)apply_filters('wpml_object_id',$privacy,'page',false,self::lang()):0;
        $link=$translated&&get_post_status($translated)==='publish'?(string)get_permalink($translated):'';
        $rtl=self::lang()==='ar'?' dir="rtl"':'';
        $css='--c-cta:'.($config['primary_color']??'#0f5866')
            .';--c-width:'.(int)($config['width_px']??480).'px'
            .';--c-radius:'.(int)($config['radius_px']??12).'px';
        $position=(string)($config['position']??'bottom_left');
        echo '<div id="mad4b-consent-root" class="mad4b-consent" data-position="'.esc_attr($position).'" style="'.esc_attr($css).'"'.$rtl.'>';
        echo '<section id="mad4b-consent-banner" class="mad4b-consent-banner" role="region" aria-label="'.esc_attr($t['title']).'" hidden>';
        echo '<h2>'.esc_html($t['title']).'</h2><p>'.esc_html($t['intro']).'</p>';
        if($link)echo '<a href="'.esc_url($link).'">'.esc_html($t['privacy']).'</a>';
        echo '<fieldset id="mad4b-consent-categories" hidden><legend>'.esc_html($t['options']).'</legend>';
        foreach([['a','analytics','analytics_desc'],['m','ads','ads_desc']] as $x){
          echo '<label class="mad4b-consent-option"><input type="checkbox" data-mad4b-consent="'.esc_attr($x[0]).'">';
          echo '<span><strong>'.esc_html($t[$x[1]]).'</strong><small>'.esc_html($t[$x[2]]).'</small></span></label>';
        }
        echo '</fieldset><div class="mad4b-consent-actions">';
        echo '<button type="button" data-mad4b-choice="accept">'.esc_html($t['all']).'</button>';
        echo '<button type="button" data-mad4b-choice="reject">'.esc_html($t['reject']).'</button>';
        echo '<button type="button" data-mad4b-choice="customize">'.esc_html($t['customize']).'</button>';
        echo '<button type="button" data-mad4b-choice="save" hidden>'.esc_html($t['save']).'</button>';
        echo '</div><p class="mad4b-consent-detail">'.esc_html($t['never']).'</p></section>';
        echo '</div>';
        // Consent choices cannot be delayed until user interaction by page optimizers:
        // the first banner must be available without a scroll or click.
        $ui=__DIR__.'/assets/mad4b-consent-gateway.js';
        if(is_file($ui)) {
            echo '<script id="mad4b-consent-ui" data-no-optimize="1">';
            readfile($ui);
            echo '</script>';
        }
    }
    /**
     * A discreet footer text link reopens the choices after the visitor decides.
     * The site does not need a permanent floating settings button.
     */
    public static function footerPrivacyLink($output,$tag,$attr,$m) {
        if($tag!=='mad4b_footer_legal' || !is_string($output) ||
           !str_contains($output,'</nav>') || str_contains($output,'data-mad4b-open-consent'))return $output;
        $t=self::strings();
        $link='<a href="#mad4b-consent-banner" class="mad4b-consent-footer-link" data-mad4b-open-consent="1">'
            .esc_html($t['manage']).'</a>';
        return str_replace('</nav>',$link.'</nav>',$output);
    }
    /**
     * A reliable in-document reopen link on the actual Privacy Policy page.
     * This remains usable if Elementor defers footer reveal animations.
     */
    private static function receiptNotice():string {
        $templates=[
          'en'=>'To record your choice, we keep a pseudonymous record of consent categories, time and privacy policy version for up to {days} days. This receipt does not include your name, email or IP address.',
          'es'=>'Para acreditar tu elección, conservamos un registro seudónimo de las categorías autorizadas, la fecha y la versión de la política hasta {days} días. No contiene tu nombre, correo electrónico ni dirección IP.',
          'ar'=>'لإثبات اختيارك نحتفظ بسجل مستعار الهوية لفئات الموافقة والتاريخ وإصدار السياسة لمدة تصل إلى {days} يومًا، دون تخزين اسمك أو بريدك الإلكتروني أو عنوان الإنترنت في هذا السجل.',
          'fr'=>'Pour tracer votre choix, nous conservons un enregistrement pseudonyme des catégories, de la date et de la version de la politique pendant {days} jours au maximum. Il ne contient ni nom, ni adresse électronique, ni adresse IP.',
          'de'=>'Zum Nachweis Ihrer Auswahl speichern wir bis zu {days} Tage lang einen pseudonymen Eintrag zu Einwilligungskategorien, Zeitpunkt und Richtlinienversion. Name, E-Mail-Adresse und IP-Adresse werden in diesem Eintrag nicht gespeichert.',
          'it'=>'Per documentare la tua scelta conserviamo per un massimo di {days} giorni una registrazione pseudonima delle categorie, dell’ora e della versione dell’informativa, senza nome, email o indirizzo IP.'
        ];
        $lang=self::lang();
        $config=class_exists('MAD4B_Consent_Settings')?MAD4B_Consent_Settings::settings():[];
        $raw=(array)get_option('look-and-feel',[]);
        $custom=trim((string)($raw['consent_receipt_note_'.$lang]??''));
        $text=$custom!==''?mb_substr(wp_strip_all_tags($custom),0,900):($templates[$lang]??$templates['en']);
        return str_replace('{days}',(string)(int)($config['receipt_days']??365),$text);
    }
    public static function privacyContentLink($content) {
        if(is_admin() || !is_page() || !is_string($content))return $content;
        $base=(int)get_option('wp_page_for_privacy_policy');
        if($base<1)return $content;
        $current=(int)get_queried_object_id();
        $translated=(int)apply_filters('wpml_object_id',$base,'page',false,self::lang());
        if($current!==$base && $current!==$translated)return $content;
        if(str_contains($content,'data-mad4b-open-consent') ||
           (!str_contains($content,'mad4b-company-legal-page') &&
            !str_contains($content,'wayfa-legal-content')))return $content;
        $t=self::strings();
        return $content
            .'<p class="mad4b-consent-evidence-notice">'.esc_html(self::receiptNotice()).'</p>'
            .'<p class="mad4b-consent-in-document"><a href="#mad4b-consent-banner"'
            .' data-mad4b-open-consent="1">'.esc_html($t['manage']).'</a></p>';
    }
    public static function assets():void {
        if(is_admin()||!self::id())return;
        $css=__DIR__.'/assets/mad4b-consent-gateway.css';
        $js=__DIR__.'/assets/mad4b-consent-gateway.js';
        if(is_file($css))wp_enqueue_style('mad4b-consent-gateway',plugins_url('assets/mad4b-consent-gateway.css',__FILE__),[],(string)filemtime($css));

    }
    public static function boot():void {
        // Only GTM's container is suppressed. All other Woo, Stripe and Fluent scripts remain.
        add_filter('gtm4wp_output_container',[self::class,'suppress'],999);
        add_filter('gtm4wp_consent_mode_default_enabled',[self::class,'suppressGtmDefaults'],999);
        add_action('wp_head',[self::class,'head'],-99);
        add_action('wp_enqueue_scripts',[self::class,'assets'],90);
        add_filter('do_shortcode_tag',[self::class,'footerPrivacyLink'],60,4);
        add_filter('the_content',[self::class,'privacyContentLink'],95);
        add_action('wp_footer',[self::class,'foot'],99);
    }
}
MAD4B_Consent_Gateway::boot();
}
