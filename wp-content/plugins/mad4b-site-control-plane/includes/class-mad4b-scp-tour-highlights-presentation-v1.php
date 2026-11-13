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

    private static function related_links(array $card,array $places):string {
        if(!$places)return '';
        $content=html_entity_decode(wp_strip_all_tags(($card['title']??'').' '.($card['body']??'')),ENT_QUOTES|ENT_HTML5,'UTF-8');
        $links=[];
        foreach($places as $place) {
            $name=trim((string)$place['label']);
            if(mb_strlen($name,'UTF-8')<4)continue;
            // Verifiable text match: only label a place if it is actually mentioned.
            $hasExact=mb_stripos($content,$name,0,'UTF-8')!==false;
            // A term title may carry a geographic qualifier that is not repeated
            // in editorial text (e.g. 'Gran Esfinge de Giza' vs 'Gran Esfinge').
            // Match only distinctive, multiword prefixes of eligible linked terms.
            $alias=preg_replace('/\s+(?:de|del|of|in|at|di|da|von|bei)\s+[\p{L}\s-]+$/iu','',$name);
            $alias=trim((string)$alias);
            $distinct=mb_strlen($alias,'UTF-8')>=9 && preg_match('/\S+\s+\S+/u',$alias);
            $hasAlias=$distinct&&$alias!==$name&&mb_stripos($content,$alias,0,'UTF-8')!==false;
            if(!$hasExact&&!$hasAlias)continue;
            $links[]='<a class="tour-hl-ui__place" data-tour-term-id="'.esc_attr((string)$place['id']).'" href="'.esc_url($place['url']).'">'
                .'<span aria-hidden="true">⌖</span> '.esc_html($name).'</a>';
            if(count($links)>=3)break;
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
