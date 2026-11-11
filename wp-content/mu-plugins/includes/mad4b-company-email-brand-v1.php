<?php
/**
 * Brand tokens for company-owned WooCommerce lifecycle emails only.
 * Existing Company Communication / Look & Feel / active Elementor Kit remain sources.
 * No second branding store, no company switching and no global Woo option mutation.
 */
defined('ABSPATH') || exit;

final class MAD4B_Company_Email_Brand_V1 {
    private const DEFAULT = [
        'bound'=>false, 'name'=>'', 'site_url'=>'', 'tagline'=>'', 'legal'=>'',
        'logo_url'=>'', 'reply_to'=>'', 'primary'=>'#24323B',
        'secondary'=>'#40545C', 'accent'=>'#B48A46',
        'surface'=>'#F8F7F4', 'text'=>'#24323B',
    ];
    private static function color($value, string $fallback):string {
        return is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/D', $value)
            ? strtoupper($value) : $fallback;
    }
    /** Only the already-owned active kit. Never resolve a foreign company's kit. */
    private static function kitColors():array {
        $kit=(int)get_option('elementor_active_kit',0);
        if($kit<1 || get_post_type($kit)!=='elementor_library')return [];
        $settings=get_post_meta($kit,'_elementor_page_settings',true);
        if(!is_array($settings))return [];
        $colors=[];
        foreach((array)($settings['system_colors']??[]) as $entry) {
            if(!is_array($entry))continue;
            $key=(string)($entry['_id']??'');
            if(in_array($key,['primary','secondary','text','accent'],true))
                $colors[$key]=(string)($entry['color']??'');
        }
        foreach((array)($settings['custom_colors']??[]) as $entry) {
            if(!is_array($entry))continue;
            $key=strtolower(trim((string)($entry['title']??'')));
            if($key==='warm bg')$colors['surface']=(string)($entry['color']??'');
        }
        return $colors;
    }
    private static function logo($value):string {
        $id=is_numeric($value)?absint($value):(is_array($value)?absint($value['id']??0):0);
        if(!$id || get_post_type($id)!=='attachment'
            || !in_array(get_post_mime_type($id),['image/png','image/jpeg','image/gif','image/webp'],true)
            || !wp_attachment_is_image($id))return '';
        // Use the WordPress media derivative rather than mailing a 2400px master.
        $url=function_exists('wp_get_attachment_image_url')
            ?wp_get_attachment_image_url($id,'medium'):wp_get_attachment_url($id);
        $host=wp_parse_url(home_url('/'),PHP_URL_HOST);
        // Only a media URL hosted by this enrolled site may be used in outgoing mail.
        if(!is_string($url)||wp_parse_url($url,PHP_URL_SCHEME)!=='https'
            || wp_parse_url($url,PHP_URL_HOST)!==$host)return '';
        return esc_url_raw($url);
    }
    public static function resolve():array {
        $fallback=self::DEFAULT;
        if(!class_exists('MAD4B_SCP_Company_Communication',false)
            || !function_exists('get_option'))return $fallback;
        $identity=MAD4B_SCP_Company_Communication::brandIdentity();
        if(empty($identity['valid']) || empty($identity['brand_name']))return $fallback;
        $look=get_option('look-and-feel',[]);
        if(!is_array($look))$look=[];
        $palette=self::kitColors();
        $cfg=MAD4B_SCP_Company_Communication::config();
        $name=sanitize_text_field((string)$identity['brand_name']);
        $tagline=sanitize_text_field((string)($look['mad4b_brand_tagline']??''));
        $legal=sanitize_text_field((string)($look['mad4b_footer_legal_line']??''));
        $email=sanitize_email((string)($cfg['traveler_reply_to']??''));
        return [
            'bound'=>true, 'name'=>mb_substr($name,0,120),
            'site_url'=>home_url('/'),
            'tagline'=>mb_substr($tagline,0,160),
            'legal'=>mb_substr($legal,0,200) ?: $name,
            'logo_url'=>self::logo($look['mad4b_brand_logo_media']??null),
            'reply_to'=>is_email($email)?$email:'',
            'primary'=>self::color($palette['primary']??'',self::DEFAULT['primary']),
            'secondary'=>self::color($palette['secondary']??'',self::DEFAULT['secondary']),
            'accent'=>self::color($palette['accent']??'',self::DEFAULT['accent']),
            'surface'=>self::color($palette['surface']??'',self::DEFAULT['surface']),
            'text'=>self::color($palette['text']??'',self::DEFAULT['text']),
        ];
    }
    private static function translatedBrandText(string $text,string $key,string $lang):string {
        if($text==='' || $lang==='en')return $text;
        if(!has_filter('wpml_translate_single_string'))return '';
        $translated=apply_filters('wpml_translate_single_string',
            $text,'admin_texts_look-and-feel','[look-and-feel]'.$key,$lang);
        // Do not label English marketing text as a translated message.
        return is_string($translated) && $translated!==$text
            ?sanitize_text_field($translated):'';
    }
    /** Resolve public links for the exact enrolled site and original request language. */
    private static function localUrl($url):string {
        if(!is_string($url)||$url==='')return '';
        $siteHost=strtolower((string)wp_parse_url(home_url('/'),PHP_URL_HOST));
        return $siteHost!=='' && wp_parse_url($url,PHP_URL_SCHEME)==='https'
            && strtolower((string)wp_parse_url($url,PHP_URL_HOST))===$siteHost
            && !wp_parse_url($url,PHP_URL_USER)
            && !wp_parse_url($url,PHP_URL_PASS) ? esc_url_raw($url) : '';
    }
    /**
     * WPML's get_permalink() can return the default language in background
     * jobs. Always resolve and then verify the requested language route.
     */
    private static function localizedUrl(string $base,string $lang):string {
        $langRoot=self::homeForLanguage($lang);
        if($langRoot==='')return '';
        $candidate=has_filter('wpml_permalink')
            ?apply_filters('wpml_permalink',$base,$lang,true):$base;
        $url=self::localUrl($candidate);
        if($url==='')return '';
        $path=(string)wp_parse_url($url,PHP_URL_PATH);
        $rootPath=(string)wp_parse_url($langRoot,PHP_URL_PATH);
        $rootPath='/'.trim($rootPath,'/').'/';
        if($rootPath==='//')$rootPath='/';
        if($rootPath!=='/' && !str_starts_with($path.'/',rtrim($rootPath,'/').'/'))return '';
        $rootQuery=(string)wp_parse_url($langRoot,PHP_URL_QUERY);
        if($rootQuery!=='') {
            parse_str($rootQuery,$rq);
            parse_str((string)wp_parse_url($url,PHP_URL_QUERY),$uq);
            foreach($rq as $k=>$v)if(($uq[$k]??null)!==$v)return '';
        }
        return $url;
    }
    public static function homeForLanguage(string $lang):string {
        $default=(string)apply_filters('wpml_default_language','en');
        if($lang===$default)return self::localUrl(home_url('/'));
        $config=get_option('icl_sitepress_settings',[]);
        if(!is_array($config))return '';
        $active=apply_filters('wpml_active_languages',null,['skip_missing'=>0]);
        if(!is_array($active)||!isset($active[$lang]))return '';
        $mode=(int)($config['language_negotiation_type']??0);
        if($mode===1) {
            // Language directories, verified against WPML's own active codes.
            return self::localUrl(home_url('/'.$lang.'/'));
        }
        if($mode===3) {
            return self::localUrl(add_query_arg('lang',$lang,home_url('/')));
        }
        // Domain-per-language needs a separately enrolled alternate-domain owner.
        return '';
    }
    private static function localizedPage(int $pageId,string $lang):string {
        if($pageId<1)return '';
        $id=$pageId;
        if(has_filter('wpml_object_id'))
            $id=(int)apply_filters('wpml_object_id',$pageId,'page',false,$lang);
        if($id<1 || get_post_status($id)!=='publish')return '';
        if(has_filter('wpml_element_language_code')) {
            $actual=(string)apply_filters('wpml_element_language_code','',
                ['element_id'=>$id,'element_type'=>'post_page']);
            if($actual!=='' && $actual!==$lang)return '';
        }
        return self::localizedUrl((string)get_permalink($id),$lang);
    }
    private static function localizedTour(int $sourceId,string $lang):array {
        if($sourceId<1 || !get_post($sourceId))return [];
        $type=get_post_type($sourceId);
        if(!is_string($type)||!in_array($type,['tours-and-activities'],true))return [];
        $id=$sourceId;
        if(has_filter('wpml_object_id'))
            $id=(int)apply_filters('wpml_object_id',$sourceId,$type,false,$lang);
        if($id<1 || get_post_status($id)!=='publish')return [];
        if(has_filter('wpml_element_language_code')) {
            $actual=(string)apply_filters('wpml_element_language_code','',
                ['element_id'=>$id,'element_type'=>'post_'.$type]);
            if($actual!=='' && $actual!==$lang)return [];
        }
        $url=self::localizedUrl((string)get_permalink($id),$lang);
        if($url==='')return [];
        return ['url'=>$url,'title'=>sanitize_text_field(get_the_title($id))];
    }
    public static function experience(string $language,int $sourceId=0):array {
        $lang=in_array($language,['en','ar','fr','de','es','it'],true)?$language:'en';
        $brand=self::resolve();
        if(!$brand['bound'])return ['language'=>$lang,'links'=>[],'tour'=>[],'brand'=>$brand];
        $look=get_option('look-and-feel',[]);
        if(!is_array($look))$look=[];
        // Only exact WPML matches; a missing translation does not redirect to English.
        $links=['home'=>self::homeForLanguage($lang)];
        $links['privacy']=self::localizedPage((int)($look['footer_privacy_page']??get_option('wp_page_for_privacy_policy',0)),$lang);
        $links['terms']=self::localizedPage((int)($look['footer_terms_page']??get_option('woocommerce_terms_page_id',0)),$lang);
        $brand['tagline']=self::translatedBrandText(
            $brand['tagline'],'mad4b_brand_tagline',$lang);
        // The Woo logo links to the same localized home as the message body.
        $brand['site_url']=$links['home'];
        return [
            'language'=>$lang,
            'links'=>$links,
            'tour'=>self::localizedTour($sourceId,$lang),
            'brand'=>$brand,
        ];
    }
    /**
     * Temporarily override Woo email presentation options for this one email.
     * Remove every filter even if Woo throws. Other templates remain unchanged.
     */
    public static function renderScoped(array $brand, callable $renderer):string {
        if(empty($brand['bound']))return (string)$renderer();
        $opts=[
            'woocommerce_email_header_image'=>$brand['logo_url'],
            'woocommerce_email_header_image_width'=>'150',
            'woocommerce_email_base_color'=>$brand['primary'],
            'woocommerce_email_background_color'=>$brand['surface'],
            'woocommerce_email_body_background_color'=>'#FFFFFF',
            'woocommerce_email_text_color'=>$brand['text'],
            'woocommerce_email_footer_text'=>$brand['legal'],
            'blogname'=>$brand['name'],
        ];
        $hooks=[];
        foreach($opts as $name=>$value){
            $fn=static fn($original)=>$value;
            $hook='pre_option_'.$name;
            add_filter($hook,$fn,25,1);
            $hooks[]=[$hook,$fn];
        }
        $logoLink=self::localUrl((string)($brand['site_url']??''));
        $logoLinkFilter=static fn($original)=>$logoLink;
        add_filter('woocommerce_email_header_image_url',$logoLinkFilter,25,1);
        $hooks[]=['woocommerce_email_header_image_url',$logoLinkFilter];
        try {return (string)$renderer();}
        finally {
            foreach($hooks as [$hook,$fn])remove_filter($hook,$fn,25);
        }
    }
}
