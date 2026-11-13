<?php
/**
 * Tour Highlights frontend presentation — native JetEngine Dynamic Field result.
 * View only. Does not modify Tour source facts, editorial approvals or translations.
 */
defined('ABSPATH') || exit;

final class MAD4B_Tour_Highlights_Presentation_V1 {
    public static function register():void {
        add_action('wp_enqueue_scripts',[__CLASS__,'enqueue'],45);
    }

    public static function enqueue():void {
        if(!is_singular('tours-and-activities') && !self::elementor_preview())return;
        $css=__DIR__.'/../assets/tour-highlights-v1.css';
        if(!is_readable($css))return;
        wp_register_style('tour-highlights-v1',false,[],'1.0.0');
        wp_enqueue_style('tour-highlights-v1');
        wp_add_inline_style('tour-highlights-v1',(string)file_get_contents($css));
        $splitCss=__DIR__.'/../assets/tour-journey-split-v3.css';
        if(is_readable($splitCss)){
            wp_add_inline_style('tour-highlights-v1',(string)file_get_contents($splitCss));
        }
        // Shared Quick Preview interaction contract, scoped to native Journey
        // destination cards. Unlike Extras, this is purely informational and
        // never touches selection controls or the Fluent Form projection.
        $destinationCss=__DIR__.'/../assets/tour-destination-quick-preview.css';
        $destinationJs=__DIR__.'/../assets/tour-destination-quick-preview.js';
        if(is_readable($destinationCss)&&is_readable($destinationJs)){
            wp_register_style('tour-destination-preview',false,['tour-highlights-v1'],'1.0.0');
            wp_enqueue_style('tour-destination-preview');
            wp_add_inline_style('tour-destination-preview',(string)file_get_contents($destinationCss));
            wp_register_script('tour-destination-preview',false,['tour-journey-ui'],'1.0.0',true);
            wp_enqueue_script('tour-destination-preview');
            wp_add_inline_script('tour-destination-preview',(string)file_get_contents($destinationJs));
        }
        $js=__DIR__.'/../assets/tour-journey-ui.js';
        if(is_readable($js)){
            wp_register_script('tour-journey-ui',false,[],'1.1.0',true);
            wp_enqueue_script('tour-journey-ui');
            wp_add_inline_script('tour-journey-ui',(string)file_get_contents($js));
            // Split's taxonomy hierarchy is intentionally a separate renderer.
            $splitJs=__DIR__.'/../assets/tour-journey-split-v3.js';
            if(is_readable($splitJs)){
                wp_add_inline_script('tour-journey-ui',(string)file_get_contents($splitJs));
            }
        }
    }

    private static function elementor_preview():bool {
        return did_action('elementor/loaded') && isset($_GET['elementor-preview'])
            && current_user_can('edit_posts');
    }

    private static function lang():string {
        $lang=(string)apply_filters('wpml_current_language',null);
        if($lang==='')$lang=substr((string)get_locale(),0,2);
        return strtolower($lang);
    }

    private static function translations():array {
        $strings=[
            'en'=>['label'=>'AT A GLANCE','scroll'=>'Swipe to explore','scroll_label'=>'Scroll through tour highlights','summary'=>'Experience highlights'],
            'es'=>['label'=>'DE UN VISTAZO','scroll'=>'Desliza para explorar','scroll_label'=>'Explorar los momentos destacados','summary'=>'Lo mejor de la experiencia'],
            'fr'=>['label'=>'EN UN COUP D’ŒIL','scroll'=>'Faites glisser pour explorer','scroll_label'=>'Explorer les temps forts','summary'=>'Les temps forts du voyage'],
            'de'=>['label'=>'AUF EINEN BLICK','scroll'=>'Zum Entdecken wischen','scroll_label'=>'Reise-Highlights entdecken','summary'=>'Besondere Erlebnisse'],
            'it'=>['label'=>'IN BREVE','scroll'=>'Scorri per scoprire','scroll_label'=>'Esplora i punti salienti','summary'=>'Le esperienze principali'],
            'ar'=>['label'=>'لمحة سريعة','scroll'=>'اسحب لاستكشاف المزيد','scroll_label'=>'تصفح مميزات الرحلة','summary'=>'مميزات التجربة'],
        ];
        return $strings[self::lang()]??$strings['en'];
    }

    private static function segment(string $text):array {
        $raw=trim($text);
        if($raw==='')return [];
        $title='';$body=$raw;
        if(preg_match('~^\s*<strong\b[^>]*>(.*?)</strong>\s*[:：\-–]?\s*~isu',$raw,$match)){
            $title=trim(wp_strip_all_tags($match[1]));
            $title=preg_replace('/[\s:：.]+$/u','',$title);
            $body=substr($raw,strlen($match[0]));
            $body=preg_replace('/^\s*[:：\-–]\s*/u','',$body);
        } elseif(preg_match('~^\s*<b\b[^>]*>(.*?)</b>\s*[:：\-–]?\s*~isu',$raw,$match)){
            $title=trim(wp_strip_all_tags($match[1]));
            $title=preg_replace('/[\s:：.]+$/u','',$title);
            $body=substr($raw,strlen($match[0]));
            $body=preg_replace('/^\s*[:：\-–]\s*/u','',$body);
        }
        if(trim(wp_strip_all_tags($body))==='' && $title==='')return [];
        return ['title'=>$title,'body'=>wp_kses_post(trim($body))];
    }

    private static function inner_html(\DOMNode $node):string {
        $html='';
        foreach($node->childNodes as $child)$html.=$node->ownerDocument->saveHTML($child);
        return $html;
    }

    public static function cards(string $html):array {
        if(trim(wp_strip_all_tags($html))==='')return [];
        if(preg_match('~<(table|iframe|blockquote|video|audio|figure)\b~i',$html)){
            return [['title'=>'','body'=>wp_kses_post($html)]];
        }
        $parts=[];
        if(class_exists('DOMDocument')){
            $dom=new \DOMDocument('1.0','UTF-8');
            $old=libxml_use_internal_errors(true);
            $ok=$dom->loadHTML('<?xml encoding="UTF-8"?><div id="tour-hl-parser-root">'.$html.'</div>',
                LIBXML_NOERROR|LIBXML_NOWARNING|LIBXML_NONET);
            libxml_clear_errors();libxml_use_internal_errors($old);
            if($ok){
                $root=null;
                foreach($dom->getElementsByTagName('div') as $el){
                    if($el->getAttribute('id')==='tour-hl-parser-root'){$root=$el;break;}
                }
                if($root){
                    $walk=function(\DOMNode $node)use(&$walk,&$parts){
                        foreach($node->childNodes as $child){
                            if($child->nodeType===XML_TEXT_NODE){
                                $text=trim((string)$child->textContent);
                                if($text!=='')$parts[]=esc_html($text);
                                continue;
                            }
                            if($child->nodeType!==XML_ELEMENT_NODE)continue;
                            $tag=strtolower($child->nodeName);
                            if(in_array($tag,['p','li','h2','h3','h4','h5','h6'],true)){
                                $inside=self::inner_html($child);
                                if(preg_match('~<br\s*/?>~i',$inside)){
                                    foreach(preg_split('~<br\s*/?>~i',$inside) as $part)
                                        if(trim(wp_strip_all_tags($part))!=='')$parts[]=$part;
                                } elseif(trim(wp_strip_all_tags($inside))!=='')$parts[]=$inside;
                            } elseif(in_array($tag,['div','section','article','main','ul','ol'],true)){
                                $walk($child);
                            } elseif($tag!=='br' && trim((string)$child->textContent)!==''){
                                $parts[]=self::inner_html($child);
                            }
                        }
                    };
                    $walk($root);
                }
            }
        }
        if(!$parts){
            $plain=preg_replace('/<br\s*\/?>/i',"\n",$html);
            $plain=wp_strip_all_tags($plain);
            $parts=preg_split('/\r?\n+/u',$plain,-1,PREG_SPLIT_NO_EMPTY)?:[$html];
        }
        $items=[];
        foreach($parts as $part){
            $parsed=self::segment($part);
            if($parsed)$items[]=$parsed;
        }
        if(!$items)return [['title'=>'','body'=>wp_kses_post($html)]];
        $normalize=static function(string $str):string{
            return trim(preg_replace('/\s+/u',' ',html_entity_decode(wp_strip_all_tags($str),ENT_QUOTES|ENT_HTML5,'UTF-8')));
        };
        $source=$normalize($html);
        $combined=$normalize(implode(' ',array_map(static fn($c)=>$c['title'].' '.$c['body'],$items)));
        $from=mb_strlen($source,'UTF-8');$to=mb_strlen($combined,'UTF-8');
        if($from>0 && ($to/$from<0.96 || $to/$from>1.15)){
            return [['title'=>'','body'=>wp_kses_post($html)]];
        }
        return $items;
    }

    private static function verified_places():array {
        if(!class_exists('MAD4B_Tour_Highlights_V1',false))return [];
        $post=MAD4B_Tour_Highlights_V1::get_tour_id();
        if(!$post)return [];
        // Reuse the single dynamic policy result; the editorial chip may
        // reference a specific attraction/city but not a broad country.
        return array_values(array_filter(
            MAD4B_Tour_Highlights_V1::related_places($post),
            static fn($item)=>in_array($item['kind'],['place_attraction','city','zone_area'],true)
        ));
    }

    /**
     * An editorial mention is only evidence for an eligible, tour-linked term.
     * Never infer a sibling attraction from a person's name occurring in the
     * prose (e.g. Khafre != Valley Temple of Khafre).
     */
    private static function text_mention(string $content,string $phrase):bool {
        $phrase=trim((string)preg_replace('/\s+/u',' ',$phrase));
        if($phrase==='')return false;
        return preg_match('~(?<![\p{L}\p{N}])'.preg_quote($phrase,'~').'(?![\p{L}\p{N}])~iu',$content)===1;
    }

    /**
     * Safe geographic-title variant: "Giza Pyramids" may be mentioned as
     * "Pyramids" when its verified parent is Giza and NO other eligible
     * attraction has the same candidate head noun. Do not mine all words from
     * a term title, or match "Khafre" out of "Valley Temple of Khafre".
     */
    private static function parent_head_mentioned(string $content,array $place,array $places):bool {
        if(($place['kind']??'')!=='place_attraction')return false;
        $parentId=(int)($place['parent']??0);
        if($parentId<=0)return false;
        $parent=get_term($parentId,'destinations');
        if(!$parent instanceof \WP_Term)return false;
        $parentName=trim((string)$parent->name);
        $name=trim((string)($place['label']??''));
        $prefix=$parentName.' ';
        if($parentName==='' || mb_stripos($name,$prefix,0,'UTF-8')!==0)return false;
        $head=trim(mb_substr($name,mb_strlen($prefix,'UTF-8'),null,'UTF-8'));
        // A single distinct noun is required. Never strip 'of X' qualifiers.
        if(mb_strlen($head,'UTF-8')<8 || !preg_match('/^[\p{L}]{8,}$/u',$head))return false;
        foreach($places as $other) {
            if((int)($other['id']??0)===(int)($place['id']??0)
                || ($other['kind']??'')!=='place_attraction')continue;
            if(self::text_mention((string)($other['label']??''),$head))return false;
        }
        return self::text_mention($content,$head);
    }

    /**
     * Locale-specific names maintained on the original Destination term.
     * A single source of truth for Tour highlight linking and native JetEngine
     * preview; never create a second taxonomy or guess translations.
     *
     * JetEngine Repeater: tour_highlight_aliases =
     * [['tour_lang'=>'es','tour_alias'=>'Grandes Pirámides'], ...].
     */
    private static function curated_alias_mentioned(string $content,array $place,array $places):string {
        $id=(int)($place['id']??0);
        if($id<=0)return '';
        $lang=self::lang();
        $all=get_term_meta($id,'tour_highlight_aliases',true);
        if(!is_array($all))return '';
        $variants=[];
        // Native JetEngine Repeater rows: one language + alternative name.
        foreach($all as $row){
            if(is_array($row) && ($row['tour_lang']??'')===$lang
              && is_string($row['tour_alias']??null))$variants[]=$row['tour_alias'];
        }
        // Backward-compatible with an associative locale map, if imported.
        if(!empty($all[$lang]) && is_array($all[$lang]))
            $variants=array_merge($variants,$all[$lang]);
        if(!$variants)return '';
        foreach(array_slice($variants,0,12) as $alias){
            if(!is_string($alias))continue;
            $alias=trim(preg_replace('/\s+/u',' ',wp_strip_all_tags($alias)));
            if(mb_strlen($alias,'UTF-8')<8||mb_strlen($alias,'UTF-8')>120
              || !preg_match('/\p{L}+\s+\p{L}+/u',$alias)
              || !self::text_mention($content,$alias))continue;
            $duplicated=false;
            foreach($places as $other){
                if((int)($other['id']??0)===$id)continue;
                if(mb_strtolower(trim((string)($other['label']??'')),'UTF-8')
                    ===mb_strtolower($alias,'UTF-8')){$duplicated=true;break;}
                $candidate=get_term_meta((int)($other['id']??0),'tour_highlight_aliases',true);
                if(!is_array($candidate))continue;
                $altNames=[];
                foreach($candidate as $row)
                    if(is_array($row)&&($row['tour_lang']??'')===$lang)
                        $altNames[]=$row['tour_alias']??null;
                if(!empty($candidate[$lang])&&is_array($candidate[$lang]))
                    $altNames=array_merge($altNames,$candidate[$lang]);
                foreach($altNames as $alternative){
                    if(is_string($alternative)&&mb_strtolower(trim($alternative),'UTF-8')
                        ===mb_strtolower($alias,'UTF-8')){$duplicated=true;break 2;}
                }
            }
            if(!$duplicated)return $alias;
        }
        return '';
    }

    private static function related_links(array $card,array $places):string {
        if(!$places)return '';
        $content=html_entity_decode(wp_strip_all_tags(($card['title']??'').' '.($card['body']??'')),ENT_QUOTES|ENT_HTML5,'UTF-8');
        $links=[];
        foreach($places as $place) {
            $name=trim((string)$place['label']);
            if(mb_strlen($name,'UTF-8')<4)continue;
            // Verifiable text match: only label a place if it is actually mentioned.
            // A term title may carry a geographic qualifier that is not repeated
            // in editorial text (e.g. 'Gran Esfinge de Giza' vs 'Gran Esfinge').
            // Match only distinctive, multiword prefixes of eligible linked terms.
            $alias=preg_replace('/\s+(?:de|del|of|in|at|di|da|von|bei)\s+[\p{L}\s-]+$/iu','',$name);
            $alias=trim((string)$alias);
            $distinct=mb_strlen($alias,'UTF-8')>=9 && preg_match('/\S+\s+\S+/u',$alias);
            $hasAlias=$distinct&&$alias!==$name&&self::text_mention($content,$alias);
            $hasExact=self::text_mention($content,$name);
            $curated=(!$hasExact&&!$hasAlias)
                ?self::curated_alias_mentioned($content,$place,$places):'';
            $hasParentHead=!$hasExact&&!$hasAlias&&$curated===''
                &&self::parent_head_mentioned($content,$place,$places);
            if(!$hasExact&&!$hasAlias&&$curated===''&&!$hasParentHead)continue;
            $match=$hasExact?'exact':($hasAlias?'qualified':($curated!==''?'curated-alias':'parent-head'));
            $display=$curated!==''?$curated:$name;
            $links[]='<a class="tour-hl-ui__place" data-tour-evidence="mentioned" data-tour-match="'.esc_attr($match).'" data-tour-term-id="'.esc_attr((string)$place['id']).'" href="'.esc_url($place['url']).'">'
                .'<span aria-hidden="true">⌖</span> '.esc_html($display).'</a>';
        }
        if(!$links)return '';
        return '<div class="tour-hl-ui__related">'.implode('',$links).'</div>';
    }

    public static function render($html):string {
        if(!is_string($html)||trim(wp_strip_all_tags($html))==='')return '';
        $cards=self::cards($html);
        if(!$cards)return wp_kses_post($html);
        $places=self::verified_places();
        $labels=self::translations();$count=count($cards);
        $many=$count>1;
        $output='<div class="tour-hl-ui" data-tour-hl-version="1" data-tour-hl-count="'.$count.'">';
        $output.='<div class="tour-hl-ui__intro"><span class="tour-hl-ui__label">'.esc_html($labels['label']).'</span>';
        if($many)$output.='<span class="tour-hl-ui__count" aria-hidden="true">'.esc_html((string)$count).'</span>';
        if($many)$output.='<span class="tour-hl-ui__hint" aria-hidden="true">'.esc_html($labels['scroll']).' <span class="tour-hl-ui__arrow">↔</span></span>';
        $output.='</div>';
        $output.='<ol class="tour-hl-ui__grid" '.($many?'tabindex="0" ':'').'aria-label="'.esc_attr($labels['scroll_label']).'">';
        foreach($cards as $index=>$card){
            $number=str_pad((string)($index+1),2,'0',STR_PAD_LEFT);
            $output.='<li class="tour-hl-ui__card"><span class="tour-hl-ui__number" aria-hidden="true">'.$number.'</span><div class="tour-hl-ui__copy">';
            if($card['title']!=='')$output.='<h3 class="tour-hl-ui__title">'.esc_html($card['title']).'</h3>';
            if(trim(wp_strip_all_tags($card['body']))!=='')$output.='<div class="tour-hl-ui__description">'.wp_kses_post($card['body']).'</div>';
            $output.=self::related_links($card,$places);
            $output.='</div></li>';
        }
        $output.='</ol></div>';
        return $output;
    }
}

MAD4B_Tour_Highlights_Presentation_V1::register();
