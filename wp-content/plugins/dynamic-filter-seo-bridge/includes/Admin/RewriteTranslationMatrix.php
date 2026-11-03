<?php
namespace DynamicFilterSEOBridge\Admin;

use DynamicFilterSEOBridge\IndexFlow\LocalizedRewriteBase;
require_once dirname(__DIR__) . '/IndexFlow/LocalizedRewriteBase.php';

/** Read-only cross-type WPML URL slug readiness matrix. WPML owns all writes. */
final class RewriteTranslationMatrix {
    public static function render(): void {
        if (!current_user_can('manage_options')) return;
        $languages = (new \DynamicFilterSEOBridge\WPML\LanguageResolver())->activeLanguages();
        if (!is_array($languages) || !$languages) return;
        $types=array();
        foreach (get_post_types(array('public'=>true),'objects') as $name=>$object) {
            if (!in_array($name,array('post','page','attachment'),true) &&
                is_array($object->rewrite) && !empty($object->rewrite['slug']))
                $types[]=array('kind'=>'post_type','name'=>$name,'label'=>$object->labels->name??$name);
        }
        foreach (get_taxonomies(array('public'=>true),'objects') as $name=>$object) {
            if (is_array($object->rewrite) && !empty($object->rewrite['slug']))
                $types[]=array('kind'=>'taxonomy','name'=>$name,'label'=>$object->labels->name??$name);
        }
        if (count($types)>100) {
            echo '<p>WPML rewrite inventory exceeds the bounded safe preview; request a smaller governed profile.</p>';
            return;
        }
        echo '<h2>WPML URL base translations — CPTs and Taxonomies</h2>';
        echo '<p>Source of truth: WordPress/JetEngine rewrite settings and WPML String Translation. Missing/disabled bases are not approved SEO route components. Editing is done in WPML, not in this preview.</p>';
        echo '<div style="overflow-x:auto"><table class="widefat striped"><thead><tr><th>Type</th><th>Registered name</th><th>Native base</th>';
        foreach(array_keys($languages) as $lang)echo '<th>'.esc_html(strtoupper($lang)).'</th>';
        echo '<th>WPML settings</th></tr></thead><tbody>';
        foreach($types as $item){
            $result=array();
            foreach(array_keys($languages) as $lang)
                $result[$lang]=LocalizedRewriteBase::inspect($item['kind'],$item['name'],$lang);
            $first=reset($result);
            echo '<tr><td>'.esc_html($item['kind']).'</td><td>'.esc_html($item['name']).'</td>';
            echo '<td><code>'.esc_html((string)($first['source_base']??'')).'</code></td>';
            foreach($result as $value){
                $verified=!empty($value['translation_verified']);
                $label=$verified?(string)$value['localized_base']:(string)($value['reason']??'not_ready');
                echo '<td>'.($verified?'&#10003; ':'&#9888; ').esc_html($label).'</td>';
            }
            $kind=$item['kind']==='taxonomy'?'taxonomy':'post';
            $link=apply_filters('wpml_get_slug_translation_url','',$kind);
            echo '<td>';
            if(is_string($link) && wp_http_validate_url($link) && strpos($link,admin_url())===0)
                echo '<a href="'.esc_url($link).'">Configure in WPML</a>';
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
}
