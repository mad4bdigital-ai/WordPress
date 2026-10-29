<?php
declare(strict_types=1);
namespace Commerce\Composition;

/**
 * Form-render translations do not modify the saved native Fluent Form, its
 * immutable SHA policy or its canonical yes/no source answer.
 */
final class MarketingConsentTranslations
{
    public static function bootstrap():void
    {
        add_filter('fluentform/rendering_field_data_input_checkbox',
            [self::class,'translate'],30,2);
    }
    /** Audit the exact statement displayed in the current language. */
    public static function statementForCurrentLanguage(array $policy):array
    {
        $lang=apply_filters('wpml_current_language',null);
        if(!is_string($lang)||$lang==='')$lang=substr((string)get_locale(),0,2);
        $config=get_option('commerce_marketing_consent_i18n',[]);
        $strings=is_array($config)?($config['translations'][$lang]??null):null;
        if(is_array($strings)&&is_string($strings['statement']??null)&&
            trim($strings['statement'])!=='')
            return ['language'=>$lang,'text'=>$strings['statement']];
        return [
            'language'=>is_string($config['source_language']??null)
                ?$config['source_language']:'en',
            'text'=>(string)($policy['text']??'')
        ];
    }

    public static function translate($field,$form)
    {
        if(!is_array($field) || !is_object($form))return $field;
        $fieldName=$field['attributes']['name']??'';
        if(!is_string($fieldName))return $field;
        $config=get_option('commerce_marketing_consent_i18n',[]);
        if(!is_array($config)||($config['field']??null)!==$fieldName)return $field;
        $policy=ConsentPolicySource::all();
        $id=(int)($form->id??0);
        if($id<1||!isset($policy[$id]))return $field;
        $language=apply_filters('wpml_current_language',null);
        if(!is_string($language)||$language==='')
            $language=substr((string)get_locale(),0,2);
        $locale=$config['translations'][$language]??null;
        if(!is_array($locale))return $field;
        $keys=['label','statement','help'];
        foreach($keys as $key)if(!is_string($locale[$key]??null)||
            trim((string)$locale[$key])==='')return $field;
        $field['settings']['label']=wp_strip_all_tags($locale['label']);
        if(!isset($field['settings']['advanced_options'][0]))return $field;
        $field['settings']['advanced_options'][0]['label']=
            wp_strip_all_tags($locale['statement']);
        $field['settings']['help_message']=wp_strip_all_tags($locale['help']);
        // Form source answer is canonical yes in EVERY language.
        $field['settings']['advanced_options'][0]['value']='yes';
        $field['attributes']['value']=[];
        $field['settings']['validation_rules']['required']['value']=false;
        return $field;
    }
}
