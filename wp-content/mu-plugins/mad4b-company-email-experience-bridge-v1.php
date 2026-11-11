<?php
/**
 * Plugin Name: MAD4B Company Email Experience Bridge
 * Description: Brand presentation for existing WooCommerce and Fluent Forms notifications. Never sends.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;
require_once __DIR__.'/includes/mad4b-company-email-brand-v1.php';

final class MAD4B_Company_Email_Experience_Bridge_V1 {
    private static array $formContext=[];
    private static array $wcHeaderHooks=[];
    private static string $wooEmailContextId='';
    private static string $wooEmailContextLang='';
    private static function brand():array {
        return MAD4B_Company_Email_Brand_V1::resolve();
    }
    private static function locale($email=null):string {
        $candidate='';
        if(is_object($email) && isset($email->object) && $email->object instanceof WC_Order) {
            $candidate=(string)$email->object->get_meta('_wpml_language',true);
        }
        if($candidate==='')$candidate=(string)apply_filters('wpml_current_language','');
        if(!in_array($candidate,['en','ar','fr','de','es','it'],true))
            $candidate=(string)apply_filters('wpml_default_language','en');
        return in_array($candidate,['en','ar','fr','de','es','it'],true)?$candidate:'en';
    }
    private static function paletteCss(array $brand):string {
        $primary=$brand['primary'];$accent=$brand['accent'];$surface=$brand['surface'];$text=$brand['text'];
        return "
#outer_wrapper,#wrapper {background-color:{$surface} !important;}
#template_header,#header_wrapper {background-color:{$primary} !important;}
#template_header h1,#header_wrapper h1 {color:#FFFFFF !important;}
#body_content,#body_content_inner,#template_container {color:{$text} !important;}
#body_content a,#credit a {color:{$primary};}
a.button,.button {background-color:{$primary} !important;color:#FFFFFF !important;border-color:{$primary} !important;}
#template_header_image img {max-width:154px !important;width:auto;height:auto !important;}
#template_footer,#credit {border-top-color:{$accent} !important;}
#credit,#credit p,#template_footer td {color:{$brand['secondary']} !important;}
@media screen and (max-width:600px) {
 #wrapper,#template_container,#template_body,#template_header,#template_footer {width:100% !important;max-width:600px !important;}
 #wrapper {padding:16px 0 !important;}
 #body_content,#body_content_inner {overflow-wrap:break-word !important;}
}";
    }
    private static function legalLinks(string $language,array $experience):string {
        $dict=[
          'en'=>['home'=>'Website','privacy'=>'Privacy','terms'=>'Terms'],
          'ar'=>['home'=>'الموقع','privacy'=>'الخصوصية','terms'=>'الشروط'],
          'es'=>['home'=>'Sitio web','privacy'=>'Privacidad','terms'=>'Términos'],
          'fr'=>['home'=>'Site web','privacy'=>'Confidentialité','terms'=>'Conditions'],
          'de'=>['home'=>'Website','privacy'=>'Datenschutz','terms'=>'AGB'],
          'it'=>['home'=>'Sito web','privacy'=>'Privacy','terms'=>'Termini']
        ];
        $labels=$dict[$language]??$dict['en'];$out=[];
        foreach(['home','privacy','terms'] as $key) {
            $url=$experience['links'][$key]??'';
            if(is_string($url)&&$url!=='')
                $out[]='<a href="'.esc_url($url).'">'.esc_html($labels[$key]).'</a>';
        }
        return implode(' &nbsp;·&nbsp; ',$out);
    }
    // WooCommerce: scope the actual native header render, not sitewide Woo options.
    public static function wooHeader($heading,$email=null):void {
        // Woo's footer template does not reliably forward the WC_Email object.
        // Carry the exact native email/language from header to footer once only.
        self::$wooEmailContextId=is_object($email)?(string)($email->id??''):'';
        self::$wooEmailContextLang=self::locale($email);
        if(self::$wcHeaderHooks)return;
        $brand=self::brand();if(empty($brand['bound']))return;
        $lang=self::$wooEmailContextLang;
        $experience=MAD4B_Company_Email_Brand_V1::experience($lang);
        $logo=$experience['brand']['logo_url'];
        $options=[
            'woocommerce_email_header_image'=>$logo,
            'blogname'=>$brand['name'],
        ];
        foreach($options as $option=>$value) {
            $fn=static fn($pre)=>$value;
            $hook='pre_option_'.$option;
            add_filter($hook,$fn,15,1);
            self::$wcHeaderHooks[]=[$hook,$fn];
        }
        $url=$experience['links']['home']??'';
        $fn=static fn($prior)=>$url!==''?$url:$prior;
        add_filter('woocommerce_email_header_image_url',$fn,15,1);
        self::$wcHeaderHooks[]=['woocommerce_email_header_image_url',$fn];
    }
    public static function wooHeaderCleanup():void {
        foreach(self::$wcHeaderHooks as [$hook,$fn])remove_filter($hook,$fn,15);
        self::$wcHeaderHooks=[];
    }
    public static function wooStyles($css,$email):string {
        $brand=self::brand();
        if(empty($brand['bound']) || !is_string($css))return $css;
        return $css."\n".self::paletteCss($brand);
    }
    public static function wooFooter($original,$email=null):string {
        $type=self::$wooEmailContextId;
        $lang=self::$wooEmailContextLang?:self::locale($email);
        self::$wooEmailContextId='';
        self::$wooEmailContextLang='';
        // RequestLifecycle already has its own exact-language links inside its
        // HTML template. Never append an English fallback or duplicate footer.
        if($type==='mad4b_request_lifecycle')return $original;
        $brand=self::brand();
        if(empty($brand['bound'])||!is_string($original))return $original;
        $context=MAD4B_Company_Email_Brand_V1::experience($lang);
        $legal=self::legalLinks($lang,$context);
        return esc_html($brand['legal']).($legal!==''?'<p>'.$legal.'</p>':'');
    }
    // Fluent Forms native email template filters: no new sender or bypass of notification toggles.
    public static function fluentMessage($text,$entryId,$submittedData,$form) {
        $fid=(int)($form->id??0);
        unset(self::$formContext[$fid]);
        if($fid<1 || (int)$entryId<1 || empty(self::brand()['bound']))return $text;
        global $wpdb;
        $table=$wpdb->prefix.'fluentform_submissions';
        $row=$wpdb->get_row($wpdb->prepare("SELECT form_id,source_url,status FROM $table WHERE id=%d",(int)$entryId),ARRAY_A);
        if(!is_array($row) || (int)$row['form_id']!==$fid
            || in_array((string)($row['status']??''),['spam','trashed','deleted'],true))return $text;
        $context=['source_id'=>0,'row'=>['source_url'=>(string)($row['source_url']??'')]];
        if(class_exists('Commerce\Composition\RequestLifecycle'))
            $lang=\Commerce\Composition\RequestLifecycle::requestLanguage($context);
        else $lang=self::locale();
        self::$formContext[$fid]=$lang;
        return $text;
    }
    public static function fluentColors($prior):array {
        if(!is_array($prior))return [];
        $brand=self::brand();
        if(empty($brand['bound']))return $prior;
        $prior['background_color']=$brand['surface'];
        $prior['body_background_color']='#FFFFFF';
        $prior['base_color']=$brand['primary'];
        $prior['text_color']=$brand['text'];
        return $prior;
    }
    public static function fluentHeaderImage($prior,$form=null,$notification=null) {
        $brand=self::brand();
        return !empty($brand['bound']) && !empty($brand['logo_url'])
            ?$brand['logo_url']:$prior;
    }
    public static function fluentHeading($prior,$form=null,$notification=null) {
        $brand=self::brand();
        if(empty($brand['bound'])||$prior)return $prior;
        return $brand['name'];
    }
    public static function fluentFooter($prior,$form=null,$notification=null) {
        $brand=self::brand();
        if(empty($brand['bound']))return $prior;
        $fid=(int)($form->id??0);
        $lang=self::$formContext[$fid]??self::locale();
        $experience=MAD4B_Company_Email_Brand_V1::experience($lang);
        $links=self::legalLinks($lang,$experience);
        return esc_html($brand['legal']).($links!==''?'<p>'.$links.'</p>':'');
    }
    public static function fluentCredit($prior,$form=null,$notification=null) {
        return empty(self::brand()['bound'])?$prior:'';
    }
    public static function fluentStyles($css,$form=null,$notification=null) {
        if(!is_string($css))return $css;
        $brand=self::brand();if(empty($brand['bound']))return $css;
        return $css."\n".self::paletteCss($brand);
    }
    public static function fluentBody($html,$notification,$data,$form) {
        // Finish this specific submission's presentation context without changing
        // existing form fields, recipients, conditional feeds or transport.
        unset(self::$formContext[(int)($form->id??0)]);
        if(!is_string($html)||!str_contains($html,'<html')
            || empty(self::brand()['bound'])
            || ($notification['asPlainText']??'')==='yes')return $html;
        // Fluent Forms emits four fixed-width 600px tables. Emogrifier may strip
        // responsive media rules, so constrain the *real table attributes* after
        // inlining; leave every other user-supplied table untouched.
        $brand=self::brand();
        $ids=['template_container','template_header','template_body','template_footer'];
        $html=preg_replace_callback('~<table\\b[^>]*>~i',static function($m)use($ids) {
            $tag=$m[0];
            if(!preg_match('~\\bid\\s*=\\s*(["\\\'])([^"\\\']+)\\1~i',$tag,$id)
                || !in_array($id[2],$ids,true))return $tag;
            $tag=preg_replace('~\\bwidth\\s*=\\s*(["\\\'])600\\1~i','width="100%"',$tag,1);
            if(preg_match('~\\sstyle\\s*=\\s*(["\\\'])(.*?)\\1~s',$tag,$style)) {
                $css=rtrim($style[2],';').' ; width:100% !important; max-width:600px;';
                return str_replace($style[0],' style="'.esc_attr($css).'"',$tag);
            }
            return substr($tag,0,-1).' style="width:100% !important;max-width:600px;">';
        },$html);
        // Apply the final brand palette to known Fluent-owned elements after
        // Emogrifier. No style is added to user-entered form content.
        $extra=[
            'header_wrapper'=>'background-color:'.$brand['primary'].';color:#FFFFFF;padding:18px 26px;',
            'template_header'=>'background-color:'.$brand['primary'].';color:#FFFFFF;',
            'template_footer'=>'border-top:2px solid '.$brand['accent'].';',
            'credit'=>'color:'.$brand['secondary'].';padding:20px 16px 24px;line-height:1.6;'
        ];
        foreach($extra as $elementId=>$declaration) {
            $html=preg_replace_callback('~<(table|td)\\b[^>]*>~i',static function($m)use($elementId,$declaration) {
                $tag=$m[0];
                if(!preg_match('~\\bid\\s*=\\s*(["\\\'])'.preg_quote($elementId,'~').'\\1~i',$tag))
                    return $tag;
                if(preg_match('~\\sstyle\\s*=\\s*(["\\\'])(.*?)\\1~s',$tag,$style)) {
                    $newStyle=rtrim($style[2],';').';'.$declaration;
                    return str_replace($style[0],' style="'.esc_attr($newStyle).'"',$tag);
                }
                return substr($tag,0,-1).' style="'.esc_attr($declaration).'">';
            },$html);
        }
        // Only resize the exact approved company logo, never images submitted
        // through form fields or embedded by a customer.
        if(!empty($brand['logo_url'])) {
            $html=preg_replace_callback('~<img\\b[^>]*>~i',static function($m)use($brand) {
                $tag=$m[0];
                if(!str_contains($tag,esc_attr($brand['logo_url'])))return $tag;
                $tag=preg_replace('~\\swidth\\s*=\\s*(["\\\'])[^"\\\']*\\1~i','',$tag);
                $style='width:154px !important;max-width:154px;height:auto;';
                if(preg_match('~\\sstyle\\s*=\\s*(["\\\'])(.*?)\\1~s',$tag,$prev))
                    $tag=str_replace($prev[0],' style="'.esc_attr(rtrim($prev[2],';').';'.$style).'"',$tag);
                else $tag=substr($tag,0,-1).' style="'.esc_attr($style).'">';
                return str_replace('<img','<img width="154"',$tag);
            },$html);
        }
        // The only native Fluent Forms email heading appears before the form
        // body and is created by the Fluent template (never submitted content).
        if(str_contains($html,'id="template_header"')||str_contains($html,"id='template_header'")) {
            $html=preg_replace_callback('~<h1\\b[^>]*>~i',static function($m) {
                $tag=$m[0];$declaration='color:#FFFFFF !important;font-size:24px;line-height:1.3;';
                if(preg_match('~\\sstyle\\s*=\\s*(["\\\'])(.*?)\\1~s',$tag,$style))
                    return str_replace($style[0],' style="'.esc_attr(rtrim($style[2],';').';'.$declaration).'"',$tag);
                return substr($tag,0,-1).' style="'.esc_attr($declaration).'">';
            },$html,1);
        }
        return $html;
    }
    public static function boot():void {
        add_action('woocommerce_email_header',[__CLASS__,'wooHeader'],1,2);
        add_action('woocommerce_email_header',[__CLASS__,'wooHeaderCleanup'],99,0);
        add_filter('woocommerce_email_styles',[__CLASS__,'wooStyles'],25,2);
        add_filter('woocommerce_email_footer_text',[__CLASS__,'wooFooter'],25,2);
        add_filter('fluentform/submission_message_parse',[__CLASS__,'fluentMessage'],25,4);
        add_filter('fluentform/email_template_colors',[__CLASS__,'fluentColors'],25,1);
        add_filter('fluentform/email_template_header_image',[__CLASS__,'fluentHeaderImage'],25,3);
        add_filter('fluentform/email_template_email_heading',[__CLASS__,'fluentHeading'],25,3);
        add_filter('fluentform/email_template_footer_text',[__CLASS__,'fluentFooter'],25,3);
        add_filter('fluentform/email_template_footer_credit',[__CLASS__,'fluentCredit'],25,3);
        add_filter('fluentform/email_styles',[__CLASS__,'fluentStyles'],25,3);
        add_filter('fluentform/email_body',[__CLASS__,'fluentBody'],25,4);
    }
}
MAD4B_Company_Email_Experience_Bridge_V1::boot();
