<?php
/**
 * Plugin Name: MAD4B Destination Card Integrity
 * Description: Source-owned locale links, Jet Query Count grammar, and readable card captions.
 * Version: 1.0.1
 * Design: Preserves Elementor/JetEngine query/typography/brand text colours.
 */
defined('ABSPATH') || exit;

/** A single native locale read, only for the handful of managed card widgets. */
function mad4b_card_integrity_locale(): string {
    $l=(string)apply_filters('wpml_current_language',null);
    return in_array($l,['en','ar','fr','de','es','it'],true)?$l:'en';
}

function mad4b_card_integrity_word(string $kind,string $lang,int $n,string $existing): string {
    // Do not translate a content editor's unrelated/customized label.
    $existing=trim($existing);
    if($kind==='city'){
        $words=['City','Cities','Stadt','Städte','ville','villes','ciudad','ciudades',
            'città','Ciudad','Ciudades','مدينة','مدن'];
        if(!in_array($existing,$words,true)) return $existing;
        return match($lang){
            'de'=>$n===1?'Stadt':'Städte','fr'=>$n===1?'ville':'villes',
            'es'=>$n===1?'Ciudad':'Ciudades','it'=>'città',
            'ar'=>$n===1?'مدينة':($n===2?'مدينتان':'مدن'),
            default=>$n===1?'City':'Cities',
        };
    }
    $words=['Tours','Tour','tour','tours','circuit','circuits','Reisen','Reise',
        'viajes','viaje','excursiones','excursión','viaggi','viaggio',
        'رحلات','رحلة','رحلتان'];
    if(!in_array($existing,$words,true))return $existing;
    return match($lang){
        'fr'=>$n===1?'circuit':'circuits',
        'de'=>$n===1?'Reise':'Reisen',
        'es'=>$n===1?'excursión':'excursiones',
        'it'=>$n===1?'viaggio':'viaggi',
        'ar'=>$n===1?'رحلة':($n===2?'رحلتان':($n<=10?'رحلات':'رحلة')),
        default=>$n===1?'Tour':'Tours',
    };
}

function mad4b_card_integrity_count(string $html,string $widget_id):string {
    if(strpos($html,'jet-engine-query-count')===false)return $html;
    $kind=$widget_id==='70d47b9'?'city':'tours';
    $lang=mad4b_card_integrity_locale();
    return (string)preg_replace_callback('~(<span\b[^>]*class=["\'][^"\']*jet-engine-query-count[^"\']*["\'][^>]*>)([0-9]+)(</span>)(\s*)([^<\s][^<]*?)(?=</div>)~u',
        static function($m)use($lang,$kind){
            $suffix=trim(html_entity_decode($m[5],ENT_QUOTES|ENT_HTML5,'UTF-8'));
            $word=mad4b_card_integrity_word($kind,$lang,(int)$m[2],$suffix);
            if($word===$suffix)return $m[0];
            return $m[1].$m[2].$m[3].$m[4].esc_html($word);
        },$html);
}

function mad4b_card_integrity_replace_href(string $html,string $old_url,string $new_url): string {
    // Alter exactly the authored source destination, never other links.
    if($old_url===$new_url)return $html;
    return str_replace(['href="'.esc_url($old_url).'"',"href='".esc_url($old_url)."'"],
        ['href="'.esc_url($new_url).'"',"href='".esc_url($new_url)."'"],$html);
}

add_filter('elementor/widget/render_content',static function($content,$widget){
    if(!is_string($content)||!is_object($widget)||!method_exists($widget,'get_id'))return $content;
    $id=(string)$widget->get_id();
    if($id==='402c8ca'||$id==='70d47b9')return mad4b_card_integrity_count($content,$id);
    if(!in_array($id,['357dc71','1db1ac0','f7cb621'],true))return $content;
    $lang=mad4b_card_integrity_locale();
    $base=untrailingslashit((string)get_option('home',''));
    $host=(string)wp_parse_url($base,PHP_URL_HOST);
    if($id==='357dc71'&&$lang!=='en'&&in_array($host,['tours.dev.mad4b.com','wayfatours.com'],true)){
        $source=$base.'/tours-and-activities/';
        $translated=$base.'/'.$lang.'/tours-and-activities/';
        $content=mad4b_card_integrity_replace_href($content,$source,$translated);
        return mad4b_card_integrity_replace_href($content,'/tours-and-activities/',$translated);
    }
    if($id==='1db1ac0'&&in_array($lang,['en','es'],true)&&$host==='wayfatours.com'){
        // No /attractions archive is registered. Route to the existing Egypt
        // destination, using accurate text instead of claiming an all-site archive.
        $target_id=(int)apply_filters('wpml_object_id',665,'destinations',false,$lang);
        $term=$target_id?get_term($target_id,'destinations'):null;
        if(!$term instanceof WP_Term||is_wp_error($term))return $content;
        $destination=get_term_link($term);
        if(is_wp_error($destination)||wp_parse_url($destination,PHP_URL_HOST)!==$host
            ||($lang==='es'&&strpos((string)wp_parse_url($destination,PHP_URL_PATH),'/es/')!==0))return $content;
        foreach([$base.'/attractions',$base.'/attractions/','/attractions','/attractions/'] as $old)
            $content=mad4b_card_integrity_replace_href($content,$old,$destination);
        return $lang==='es'
          ?str_replace('Ver todas las atracciones','Explorar atracciones en Egipto',$content)
          :str_replace(['View all attractions','View All Attractions','Explore all attractions'],
                       'Explore attractions in Egypt',$content);
    }
    if($id==='f7cb621'&&$lang!=='en'&&function_exists('jet_engine')){
        $data=jet_engine()->listings->data??null;
        $term=($data&&method_exists($data,'get_current_object'))?$data->get_current_object():null;
        if(!$term instanceof WP_Term||$term->taxonomy!=='destinations')return $content;
        $source_lang=(string)apply_filters('wpml_element_language_code',null,[
            'element_id'=>$term->term_taxonomy_id,'element_type'=>'tax_destinations']);
        if($source_lang===$lang)return $content;
        $translated=(int)apply_filters('wpml_object_id',$term->term_id,'destinations',false,$lang);
        if($translated){
            $other=get_term($translated,'destinations');$url=$other instanceof WP_Term?get_term_link($other):null;
            if(is_string($url)&&wp_parse_url($url,PHP_URL_HOST)===$host){
                $old=get_term_link($term);
                if(is_string($old))return mad4b_card_integrity_replace_href($content,$old,$url);
            }
        }
        // Keep the actual original page functional where no translated term
        // exists; label the destination language for assistive technology.
        if(in_array($source_lang,['en','ar','fr','de','es','it'],true)){
            // Explicitly disclose that the *destination page* is not in the
            // current language. lang marks linked text; hreflang marks target.
            $source_names=['en'=>'English','ar'=>'Arabic','fr'=>'French',
                'de'=>'German','es'=>'Spanish','it'=>'Italian'];
            $target_name=$source_lang==='en'
                ?match($lang){'fr'=>'anglais','de'=>'Englisch','es'=>'inglés',
                    'it'=>'inglese','ar'=>'الإنجليزية',default=>'English'}
                :$source_names[$source_lang];
            $notice=match($lang){
                'fr'=>'Page disponible en '.$target_name,
                'de'=>'Seite verfügbar auf '.$target_name,
                'es'=>'Página disponible en '.$target_name,
                'it'=>'Pagina disponibile in '.$target_name,
                'ar'=>'الصفحة متاحة باللغة '.$target_name,
                default=>'Page available in '.$target_name,
            };
            $content=(string)preg_replace('/<a\b(?![^>]*\blang=)/',
                '<a lang="'.esc_attr($source_lang).'" hreflang="'.esc_attr($source_lang).'" title="'.esc_attr($notice).'" data-mad4b-translation-fallback="'.esc_attr($source_lang).'"',
                $content,1);
        }
    }
    return $content;
},95,2);

// Prevent published Jet Query Count and navigation edits from showing stale
// Elementor HTML while the page cache is bypassed. Only affected listing docs.
add_action('updated_post_meta',static function($meta_id,$post_id,$meta_key){
    if($meta_key!=='_elementor_data'||!in_array(get_post_type($post_id),['elementor_library','jet-engine'],true))return;
    $raw=get_post_meta($post_id,'_elementor_data',true);
    if(is_string($raw)&&(
        strpos($raw,'402c8ca')!==false||strpos($raw,'357dc71')!==false||
        strpos($raw,'1db1ac0')!==false||strpos($raw,'f7cb621')!==false)){
        delete_post_meta($post_id,'_elementor_element_cache');
    }
},30,3);

add_action('wp_enqueue_scripts',static function(){
    wp_register_style('mad4b-destination-card-integrity-v1',false,[],'1.0.1');
    wp_enqueue_style('mad4b-destination-card-integrity-v1');
    wp_add_inline_style('mad4b-destination-card-integrity-v1',
    '/* Keep original Brand Kit text colour and Elementor typography; support predictable contrast on varying image pixels. */
    .jet-listing-grid__item .elementor-element-1b772ff .jet-listing-dynamic-field__content,
    .jet-listing-grid__item .elementor-element-cafdfd6 .jet-listing-dynamic-field__content{
      background-color:rgba(10,29,35,.96)!important;
      padding:.14em .48em;border-radius:var(--mad4b-card-radius,.35rem);
      box-decoration-break:clone;-webkit-box-decoration-break:clone;
    }
    .jet-listing-grid__item .elementor-element-402c8ca .jet-listing-dynamic-field__content{
      background-color:rgba(10,29,35,.96)!important;
      padding:.14em .48em;border-radius:var(--mad4b-card-radius,.35rem);
      box-decoration-break:clone;-webkit-box-decoration-break:clone;
    }
    .jet-listing-grid__item .elementor-element-70d47b9 .jet-listing-dynamic-field__content{
      background-color:rgba(255,255,255,.96)!important;
      padding:.14em .4em;border-radius:var(--mad4b-card-radius,.35rem);
      box-decoration-break:clone;-webkit-box-decoration-break:clone;
    }
    .jet-listing-grid__item .elementor-element-3b68b75 .jet-listing-dynamic-link__link,
    .jet-listing-grid__item .elementor-element-f7cb621 .elementor-heading-title>a{
      background-color:rgba(10,29,35,.80);
      box-decoration-break:clone;-webkit-box-decoration-break:clone;
      padding:.1em .25em;border-radius:var(--mad4b-card-radius,.35rem);
    }
    .jet-listing-grid__item .elementor-element-3f2b785 .jet-listing-dynamic-field__content{
      background-color:rgba(10,29,35,.92)!important;
      box-decoration-break:clone;-webkit-box-decoration-break:clone;
      padding:.12em .28em;border-radius:var(--mad4b-card-radius,.35rem);
    }
    ');
},125);
