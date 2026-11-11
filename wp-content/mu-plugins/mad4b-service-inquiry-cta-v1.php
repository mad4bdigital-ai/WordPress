<?php
/**
 * Plugin Name: MAD4B Service Inquiry CTA Truth
 * Description: Makes Form #6 visibly an inquiry, not a confirmed instant booking.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;
if(!class_exists('MAD4B_Service_Inquiry_CTA_V1',false)){
final class MAD4B_Service_Inquiry_CTA_V1 {
    public static function language():string {
        $lang=(string)apply_filters('wpml_current_language','');
        return in_array($lang,['en','ar','es','fr','de','it'],true)?$lang:'en';
    }
    public static function labels(string $language):array {
        return [
            'en'=>['anchor'=>'Request This Tour','submit'=>'Send Travel Request'],
            'es'=>['anchor'=>'Solicitar este viaje','submit'=>'Enviar solicitud de viaje'],
            'ar'=>['anchor'=>'اطلب تفاصيل الرحلة','submit'=>'إرسال طلب الرحلة'],
            'fr'=>['anchor'=>'Demander ce voyage','submit'=>'Envoyer une demande'],
            'de'=>['anchor'=>'Diese Reise anfragen','submit'=>'Reiseanfrage senden'],
            'it'=>['anchor'=>'Richiedi questo tour','submit'=>'Invia richiesta di viaggio'],
        ][$language]??['anchor'=>'Request This Tour','submit'=>'Send Travel Request'];
    }
    public static function elementorCTA($html) {
        if(!is_string($html) || !str_contains($html,'#fluentform_6')
            || (is_admin()&&!wp_doing_ajax()))return $html;
        $label=esc_html(self::labels(self::language())['anchor']);
        return preg_replace_callback(
            '~(<a\b(?=[^>]*\bhref\s*=\s*["\']#fluentform_6["\'])[^>]*>)(.*?)(</a>)~is',
            static function($match)use($label){
                $content=$match[2];
                $pattern='~(<span\b(?=[^>]*\bclass\s*=\s*["\'][^"\']*elementor-button-text[^"\']*["\'])[^>]*>)(.*?)(</span>)~is';
                $count=0;
                $text=preg_replace_callback($pattern,static fn($inner)=>
                    $inner[1].$label.$inner[3],$content,1,$count);
                if($count!==1)return $match[0]; // Not an Elementor CTA; preserve.
                return $match[1].$text.$match[3];
            },$html
        );
    }
    public static function fluentForm($form) {
        if(!is_object($form) || (int)($form->id??0)!==6
            || !isset($form->fields) || !is_array($form->fields))return $form;
        $label=self::labels(self::language())['submit'];
        // The existing Form6 submission-mode engine owns quote-vs-advance
        // wording. Use its public locale copy for the initial HTML too.
        if(class_exists('MAD4B_Tour_Form6_Submission_Mode_V1',false)
            && is_callable(['MAD4B_Tour_Form6_Submission_Mode_V1','travelerLabels'])){
            $copy=MAD4B_Tour_Form6_Submission_Mode_V1::travelerLabels(self::language());
            if(is_array($copy)&&!empty($copy['quote_button']))
                $label=(string)$copy['quote_button'];
        }
        $fields=$form->fields;
        foreach((array)($fields['fields']??[]) as $i=>$item){
            if(!is_array($item)||($item['element']??'')!=='custom_submit_button')continue;
            if(!isset($item['settings']['button_ui']) || !is_array($item['settings']['button_ui']))continue;
            $fields['fields'][$i]['settings']['button_ui']['text']=$label;
        }
        if(isset($fields['submitButton']['settings']['button_ui'])
            && is_array($fields['submitButton']['settings']['button_ui']))
            $fields['submitButton']['settings']['button_ui']['text']=$label;
        // In-memory response rendering only: original form fields and settings remain saved unchanged.
        $form->fields=$fields;
        return $form;
    }
    public static function boot():void {
        add_filter('elementor/widget/render_content',[__CLASS__,'elementorCTA'],1190,1);
        add_filter('fluentform/rendering_form',[__CLASS__,'fluentForm'],60,1);
    }
}
MAD4B_Service_Inquiry_CTA_V1::boot();
}
