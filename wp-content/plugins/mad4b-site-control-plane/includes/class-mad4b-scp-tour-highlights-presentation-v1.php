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
        // Cacheable, versioned first-party JS reduces uncacheable Tour HTML.
        // The original native scripts and dependency order are unchanged;
        // Elementor, JetEngine and the private booking widgets stay untouched.
        $assets=plugins_url('assets/',dirname(__DIR__).'/mad4b-site-control-plane.php');
        $js=__DIR__.'/../assets/tour-journey-ui.js';
        $splitJs=__DIR__.'/../assets/tour-journey-split-v3.js';
        if(is_readable($js)&&is_readable($splitJs)){
            wp_enqueue_script('tour-journey-ui',$assets.'tour-journey-ui.js',
                [],(string)filemtime($js),true);
            wp_enqueue_script('tour-journey-split-v3',$assets.'tour-journey-split-v3.js',
                ['tour-journey-ui'],(string)filemtime($splitJs),true);
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
            if(is_readable($js)&&is_readable($splitJs))
                wp_enqueue_script('tour-destination-preview',$assets.'tour-destination-quick-preview.js',
                    ['tour-journey-split-v3'],(string)filemtime($destinationJs),true);
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

    /**
     * Ignore editorial formatting-only fragments, including non-breaking
     * spaces and Unicode format separators. Never modify stored HTML or
     * collapse meaningful text; this is a presentation-only eligibility test.
     */
    private static function has_visible_text(string $html):bool {
        $plain=html_entity_decode(wp_strip_all_tags($html),ENT_QUOTES|ENT_HTML5,'UTF-8');
        $plain=preg_replace('/[\\p{Z}\\p{C}]+/u','',$plain);
        return is_string($plain) && $plain!=='';
    }

    private static function segment(string $text):array {
        $raw=trim($text);
        if(!self::has_visible_text($raw))return [];
        $title='';$body=$raw;
        if(preg_match('~^\s*(?:<span\b[^>]*>\s*)?<strong\b[^>]*>(.*?)</strong>\s*(?:</span>\s*)?[:：\-–]?\s*~isu',$raw,$match)){
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
        if($title==='' && preg_match('~^\s*([^:：\n]{2,80})\s*[:：]\s*(.+)$~us',$raw,$m)
          && !preg_match('/^\s*[•·\-–]/u',$raw)){
            $title=trim(wp_strip_all_tags($m[1]));
            $body=trim($m[2]);
        } elseif($title==='' && !preg_match('/<[^>]+>/', $raw)
          && mb_strlen(wp_strip_all_tags($raw),'UTF-8')<=75
          && !preg_match('/^\s*[•·\-–]/u',$raw)
          && !preg_match('/[.!?؟،;,]$/u',$raw)){
            $title=wp_strip_all_tags($raw);$body='';
        }
        if(!self::has_visible_text($title.' '.$body))return [];
        return ['title'=>$title,'body'=>wp_kses_post(trim($body))];
    }

    private static function inner_html(\DOMNode $node):string {
        $html='';
        foreach($node->childNodes as $child)$html.=$node->ownerDocument->saveHTML($child);
        return $html;
    }

    public static function cards(string $html):array {
        if(!self::has_visible_text($html))return [];
        if(preg_match('~<(table|iframe|blockquote|video|audio|figure)\b~i',$html)){
            return [['title'=>'','body'=>wp_kses_post($html)]];
        }
        // WPML may store a translation as plain UTF-8 lines instead of HTML.
        // Preserve every authored line and paragraph boundary instead of
        // collapsing an entire cruise itinerary to one unselectable card.
        // A trailing <br> should not turn a plain translated bullet list into
        // one giant HTML text node.
        $isPlain=!preg_match('/<\/?(?!br\b)[a-z][^>]*>/i',$html);
        if($isPlain){
            $plain=html_entity_decode(preg_replace('~<br\s*/?>~i',"\n",$html),ENT_QUOTES|ENT_HTML5,'UTF-8');
            $lines=preg_split('/\R+/u',$plain,-1,PREG_SPLIT_NO_EMPTY);
            $parts=[];
            foreach($lines as $line)if(self::has_visible_text($line))$parts[]=esc_html(trim($line));
        } else{
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
                                if(self::has_visible_text($text))$parts[]=esc_html($text);
                                continue;
                            }
                            if($child->nodeType!==XML_ELEMENT_NODE)continue;
                            $tag=strtolower($child->nodeName);
                            // Outer UL/LI wrappers in imported WPML markup are
                            // not single Highlights: nested leaf <li> items are.
                            if($tag==='li'&&$child instanceof \DOMElement
                                &&$child->getElementsByTagName('li')->length>0){
                                $walk($child);
                                continue;
                            }
                            if(in_array($tag,['p','li','h2','h3','h4','h5','h6'],true)){
                                $inside=self::inner_html($child);
                                if(preg_match('~<br\s*/?>~i',$inside)){
                                    foreach(preg_split('~<br\s*/?>~i',$inside) as $part)
                                        if(self::has_visible_text($part))$parts[]=$part;
                                } elseif(self::has_visible_text($inside))$parts[]=$inside;
                            } elseif(in_array($tag,['div','section','article','main','ul','ol'],true)){
                                $walk($child);
                            } elseif($tag!=='br' && self::has_visible_text((string)$child->textContent)){
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
        } // end HTML parsing; $parts retained in their original order
        $items=[];
        foreach($parts as $part){
            $parsed=self::segment($part);
            if($parsed)$items[]=$parsed;
        }
        if(!$items)return self::has_visible_text($html)
            ?[['title'=>'','body'=>wp_kses_post($html)]]:[];
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

    public static function card_keys(array $cards):array {
        $seen=[];$keys=[];
        foreach($cards as $card){
            $raw=html_entity_decode(wp_strip_all_tags((string)($card['title']??'').' '.(string)($card['body']??'')),ENT_QUOTES|ENT_HTML5,'UTF-8');
            $raw=mb_strtolower(trim((string)preg_replace('/\s+/u',' ',$raw)),'UTF-8');
            $hash=substr(hash('sha256',$raw),0,20);
            $seen[$hash]=($seen[$hash]??0)+1;
            $keys[]='hl-'.$hash.($seen[$hash]>1?'-'.$seen[$hash]:'');
        }
        return $keys;
    }

    private static function verified_places():array {
        if(!class_exists('MAD4B_Tour_Highlights_V1',false))return [];
        $post=MAD4B_Tour_Highlights_V1::get_tour_id();
        if(!$post)return [];
        // Reuse the single dynamic policy result; the editorial chip may
        // reference a specific attraction/city but not a broad country.
        return array_values(array_filter(
            MAD4B_Tour_Highlights_V1::related_places($post),
            static fn($item)=>in_array($item['kind'],['place_attraction','city','zone_area','state_governorate','country'],true)
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
        $pattern=static fn($term)=>'~(?<![\p{L}\p{N}])'.preg_quote($term,'~').'(?![\p{L}\p{N}])~iu';
        if(preg_match($pattern($phrase),$content)===1)return true;
        // WPML may use Lúxor while the editorial copy says Luxor.
        // Fold only diacritics; never infer substring or geographic siblings.
        if(function_exists('remove_accents')){
            $foldedPhrase=remove_accents($phrase);
            $foldedContent=remove_accents($content);
            if($foldedPhrase!==$phrase || $foldedContent!==$content)
                return preg_match($pattern($foldedPhrase),$foldedContent)===1;
        }
        return false;
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

    /**
     * Optional native JetEngine Tour Page Options repeater. No extra taxonomy,
     * no new database table or generated linked-place list.
     * Rules are keyed by the visible 1-based Highlight number.
     *
     * auto: only verified text matches; explicit: one or several manually
     * chosen, still tour-linked and company-eligible terms; none: hide links.
     */
    private static function place_binding(int $tour,int $step,string $key=''):array {
        if($tour<=0||$step<=0||$step>80)return ['policy'=>'auto','ids'=>[]];
        static $registry=[];
        if(!array_key_exists($tour,$registry)){
            $registry[$tour]=[];
            $rows=get_post_meta($tour,'mad4b_journey_place_bindings_v1',true);
            if(is_array($rows)){
                foreach(array_slice($rows,0,80) as $row){
                    if(!is_array($row))continue;
                    $index=(int)($row['item_number']??0);
                    if($index<=0||$index>80||isset($registry[$tour][$index]))continue;
                    $policy=(string)($row['link_policy']??'auto');
                    if(!in_array($policy,['auto','explicit','none'],true))$policy='auto';
                    $ids=$row['destination_term_ids']??[];
                    if(is_string($ids))$ids=preg_split('/[\s,|]+/',$ids,-1,PREG_SPLIT_NO_EMPTY);
                    if(!is_array($ids))$ids=[];
                    $ids=array_values(array_unique(array_filter(array_map('absint',array_slice($ids,0,40)))));
                    $itemKey=(string)($row['item_key']??'');
                    if($itemKey!==''&&!preg_match('/^hl-[a-f0-9]{20}(?:-[0-9]{1,3})?$/D',$itemKey))continue;
                    $registry[$tour][$index]=['policy'=>$policy,'ids'=>$ids,'item_key'=>$itemKey];
                }
            }
        }
        // Once a stable key exists, the binding follows that Highlight across
        // reorders. A copy/edit with an unmatched key FAILS CLOSED instead of
        // attaching the old place to the wrong text.
        if($key!==''){
            foreach($registry[$tour] as $row){
                if(($row['item_key']??'')===$key)return $row;
            }
        }
        $legacy=$registry[$tour][$step]??null;
        if($legacy!==null&&($legacy['item_key']??'')==='')return $legacy;
        return ['policy'=>'auto','ids'=>[]];
    }

    /**
     * Conservative certainty gate (six supported company languages).
     * This intentionally recognizes explicit exclusion and optional phrasing
     * before a place can become a visit, even when its name is an exact match.
     * Ambiguous clauses must NOT become an included itinerary stop.
     */
    private static function visit_certainty(string $text,string $phrase):string {
        if(!self::text_mention($text,$phrase))return 'absent';
        $text=html_entity_decode(wp_strip_all_tags($text),ENT_QUOTES|ENT_HTML5,'UTF-8');
        $normalized=trim((string)preg_replace('/\s+/u',' ',$text));
        $at=mb_stripos($normalized,$phrase,0,'UTF-8');
        if($at===false)return 'absent';
        $start=max(0,$at-110);
        $window=mb_substr($normalized,$start,mb_strlen($phrase,'UTF-8')+200,'UTF-8');
        // Exclusion has precedence over optional language.
        $excluded=[
            'not\\s+(?:included|visited|visiting|part\\s+of|on\\s+this\\s+tour|stopping\\s+at)',
            '(?:exclude[ds]?|exclusion|skip(?:ping|ped)?|do\\s+not\\s+visit|no\\s+visit|without\\s+visiting)',
            '(?:no\\s+(?:incluid[oa]|visitad[oa])|sin\\s+(?:visitar|visita)|excluid[oa]|omitid[oa])',
            '(?:non\\s+(?:inclus(?:e|es)?|visit[ée])|sans\\s+visiter|exclu[es]?)',
            '(?:nicht\\s+(?:enthalten|inklusive|besucht)|ohne\\s+besuch|ausgeschlossen)',
            '(?:non\\s+(?:inclus[oa]|previst[oa])|senza\\s+visita|esclus[oa])',
            '(?:غير\\s+(?:مشمول|مشملة|متضمن|مضمن|مدرج|متاح)|لا\\s+(?:تشمل|يشمل|نزور|نقوم\\s+بزيارة)|بدون\\s+زيارة|يستثنى|مستبعد)'
        ];
        foreach($excluded as $pattern)if(preg_match('~(?<![\\p{L}])(?:'.$pattern.')(?![\\p{L}])~iu',$window))return 'excluded';
        $optional=[
            '(?:optional|if\\s+time\\s+permits|if\\s+available|on\\s+request|subject\\s+to\\s+availability|may\\s+visit|can\\s+visit|possible\\s+stop|alternative\\s+visit)',
            '(?:opcional|si\\s+hay\\s+tiempo|seg[uú]n\\s+disponibilidad|alternativ[oa])',
            '(?:facultativ[eo]|en\\s+option|si\\s+le\\s+temps\\s+le\\s+permet|selon\\s+disponibilit[ée])',
            '(?:optional|wahlweise|bei\\s+verf[uü]gbarkeit|wenn\\s+die\\s+zeit\\s+reicht)',
            '(?:facoltativ[oa]|opzional[ea]|se\\s+il\\s+tempo\\s+lo\\s+permette|su\\s+richiesta)',
            '(?:اختيار[ىي]|اختياري[ة]?|حسب\\s+التوفر|عند\\s+الطلب|إذا\\s+سمح\\s+الوقت|في\\s+حال\\s+توفر\\s+الوقت|زيارة\\s+بديلة)'
        ];
        foreach($optional as $pattern)if(preg_match('~(?<![\\p{L}])(?:'.$pattern.')(?![\\p{L}])~iu',$window))return 'optional';
        return 'included';
    }

    /**
     * Co-stop evidence, never simple shared-parent inference:
     * - Highlight must already name >=2 verified attractions;
     * - candidate and at least one mentioned attraction must BOTH be named
     *   in the SAME original authored itinerary step;
     * - candidate must be an approved Tour term with the same direct parent.
     * Thus "Khafre" in a Pyramids headline alone can never suggest the
     * Valley Temple. This is scoped to the existing Tour itinerary meta, is
     * read-only and does not alter the canonical Term relationships.
     */
    private static function itinerary_companions(int $tour,array $places,array $directIds):array {
        if($tour<=0 || count($directIds)<2)return [];
        $byId=[];
        foreach($places as $place){
            if(($place['kind']??'')!=='place_attraction')continue;
            $id=(int)($place['id']??0);
            if($id>0)$byId[$id]=$place;
        }
        $direct=array_intersect_key($byId,array_fill_keys($directIds,true));
        if(count($direct)<2)return [];
        $steps=get_post_meta($tour,'itinerary_item',true);
        if(!is_array($steps)||!$steps||count($steps)>80)return [];
        $found=[];
        foreach($steps as $step){
            if(!is_array($step))continue;
            $title=(string)($step['title']??'');
            $description=(string)($step['description']??'');
            if(strlen($title)+strlen($description)>36000)continue;
            $text=html_entity_decode(wp_strip_all_tags($title.' '.$description),ENT_QUOTES|ENT_HTML5,'UTF-8');
            $text=trim((string)preg_replace('/\s+/u',' ',$text));
            if($text==='')continue;
            $mentioned=[];
            foreach($byId as $id=>$place){
                $name=(string)($place['label']??'');
                if(mb_strlen($name,'UTF-8')<5)continue;
                if(self::visit_certainty($text,$name)==='included')$mentioned[$id]=$place;
            }
            if(!$mentioned)continue;
            $anchorIds=array_intersect_key($mentioned,$direct);
            if(!$anchorIds)continue;
            foreach($mentioned as $id=>$candidate){
                if(isset($direct[$id]))continue;
                $parent=(int)($candidate['parent']??0);
                if(!$parent)continue;
                foreach($anchorIds as $anchor){
                    if((int)($anchor['parent']??0)!==$parent)continue;
                    $found[$id]=$candidate;
                    break;
                }
            }
        }
        return array_values($found);
    }

    private static function related_links(array $card,array $places,int $step,int $tour,string $itemKey=''):string {
        if(!$places)return '';
        $rule=self::place_binding($tour,$step,$itemKey);
        if($rule['policy']==='none')return '';
        $explicit=$rule['policy']==='explicit';
        // WPML translations may keep the source repeater IDs while the
        // localized Tour is assigned translated destination terms.
        // Always verify the final ID against $places before rendering.
        if($explicit){
            $lang=self::lang();
            foreach($rule['ids'] as $sourceId){
                $translated=(int)apply_filters('wpml_object_id',$sourceId,'destinations',false,$lang);
                if($translated>0)$rule['ids'][]=$translated;
            }
            $rule['ids']=array_values(array_unique($rule['ids']));
        }
        $content=html_entity_decode(wp_strip_all_tags(($card['title']??'').' '.($card['body']??'')),ENT_QUOTES|ENT_HTML5,'UTF-8');
        $links=[];
        $geographicContextLinks=[];
        $directIds=[];
        foreach($places as $place) {
            $name=trim((string)$place['label']);
            if(mb_strlen($name,'UTF-8')<4)continue;
            $id=(int)($place['id']??0);
            // Broad country-level terms are never guessed as visit links.
            // An editor can explicitly assign a country as geographic context.
            if(!$explicit&&($place['kind']??'')==='country')continue;
            // Explicit mappings cannot bypass the already-verified and
            // company-eligible Tour destination list.
            if($explicit && !in_array($id,$rule['ids'],true))continue;
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
            if(!$explicit&&!$hasExact&&!$hasAlias&&$curated===''&&!$hasParentHead)continue;
            // Surface a destination only when its mention is not excluded or
            // conditional. Exact editorial overrides remain context, never
            // automatic proof that the Tour includes a visit.
            if(!$explicit){
                $phrase=$hasExact?$name:($hasAlias?$alias:($curated!==''?$curated:$name));
                $certainty=self::visit_certainty($content,$phrase);
                if($certainty==='excluded'||$certainty==='optional')continue;
            }
            // A one-word governorate is useful only on a genuine exact
            // mention. The generic country 'Egypt' remains excluded.
            if(!$explicit&&($place['kind']??'')==='state_governorate'&&!$hasExact)continue;
            $match=$explicit?'explicit':($hasExact?'exact':($hasAlias?'qualified':($curated!==''?'curated-alias':'parent-head')));
            $display=($explicit||$curated==='')?$name:$curated;
            // A linked geographic area or manual editorial selection is
            // context, not evidence that a specific visit is included.
            $evidence=($match==='explicit')?'editorial-context':
                ((($place['kind']??'')==='place_attraction'
                  &&($place['evidence']??'assigned')==='assigned')
                  ?'mentioned':'geographic-context');
            $link='<a class="tour-hl-ui__place" data-tour-evidence="'.esc_attr($evidence).'" data-tour-match="'.esc_attr($match).'" data-tour-term-id="'.esc_attr((string)$place['id']).'" href="'.esc_url($place['url']).'">'
                .'<span aria-hidden="true">⌖</span> '.esc_html($display).'</a>';
            // Do not turn "Cairo tour" service promises into a destination
            // attachment. A geographic context link accompanies at least
            // one genuinely mentioned attraction in the SAME highlight;
            // otherwise the place remains available only in Explorer.
            if($evidence==='geographic-context')$geographicContextLinks[]=$link;
            else $links[]=$link;
            $directIds[]=$id;
        }
        // In Auto mode only: an extra attraction is eligible when the
        // source itinerary actually lists it alongside a directly mentioned
        // attraction in the same stop. Never add unrelated sibling cards.
        if(!$explicit && count($directIds)>=2){
            foreach(self::itinerary_companions($tour,$places,$directIds) as $place){
                $links[]='<a class="tour-hl-ui__place" data-tour-evidence="itinerary"'
                    .' data-tour-match="itinerary-same-stop" data-tour-term-id="'.esc_attr((string)$place['id']).'"'
                    .' href="'.esc_url($place['url']).'" title="'.esc_attr__('Listed together in the tour itinerary','mad4b-site-control-plane').'">'
                    .'<span aria-hidden="true">⌖</span> '.esc_html((string)$place['label']).'</a>';
            }
        }
        if(!$links&&$geographicContextLinks){
            // Geographic-only Highlights are legitimate when the editor
            // explicitly names a place in the heading, or describes a journey
            // with named stops/route. Merely saying "Cairo tour service" in
            // generic logistics copy never becomes a confirmed visit.
            $heading=html_entity_decode(wp_strip_all_tags((string)($card['title']??'')),ENT_QUOTES|ENT_HTML5,'UTF-8');
            $namedHeading=false;
            foreach($places as $place){
                if(($place['kind']??'')!=='state_governorate')continue;
                if(self::text_mention($heading,(string)($place['label']??''))){
                    $namedHeading=true;break;
                }
            }
            // Two explicitly named governorates are valid route context in
            // all six locales, even when the travel verb has a translation
            // not represented by English keywords (e.g. أبحر بين أسوان والأقصر).
            $routeMention=count($geographicContextLinks)>=2;
            $travelAction=preg_match('~(?<![\p{L}])(?:visit|explore|sail|naveg|navig|besuch|visita|voyage|reise|recorr|أبحر|ابحر|تنقل|توجه|استكشف|اكتشف|زيارة|سافر|تجوّل|تجول|زُر|زر)[\p{L}]*~iu',$content);
            // Some legacy Highlights begin with a comma-separated SEO query
            // string mentioning many destinations. It is not an itinerary
            // route, even though several geographic names are present.
            $keywordDump=mb_strlen($content,'UTF-8')>230
                &&preg_match_all('/[,،]/u',$content)>5;
            // A location in a vague promotional heading (e.g. "Cairo's
            // Classics") is not a visit. Require a clear travel action or a
            // multi-destination route. Even a named heading must not elevate
            // an SEO keyword list into mapped itinerary stops.
            if($keywordDump||(!$routeMention&&!$travelAction))
                $geographicContextLinks=[];
        }
        if(!$links&&!$geographicContextLinks)return '';
        $links=array_merge($links,$geographicContextLinks);
        return '<div class="tour-hl-ui__related">'.implode('',$links).'</div>';
    }

    public static function render($html):string {
        if(!is_string($html)||!self::has_visible_text($html))return '';
        $cards=self::cards($html);
        if(!$cards)return wp_kses_post($html);
        $places=self::verified_places();
        $labels=self::translations();$count=count($cards);
        $keys=self::card_keys($cards);
        $many=$count>1;
        $output='<div class="tour-hl-ui" data-tour-hl-version="1" data-tour-hl-count="'.$count.'">';
        $output.='<div class="tour-hl-ui__intro"><span class="tour-hl-ui__label">'.esc_html($labels['label']).'</span>';
        if($many)$output.='<span class="tour-hl-ui__count" aria-hidden="true">'.esc_html((string)$count).'</span>';
        if($many)$output.='<span class="tour-hl-ui__hint" aria-hidden="true">'.esc_html($labels['scroll']).' <span class="tour-hl-ui__arrow">↔</span></span>';
        $output.='</div>';
        $output.='<ol class="tour-hl-ui__grid" '.($many?'tabindex="0" ':'').'aria-label="'.esc_attr($labels['scroll_label']).'">';
        foreach($cards as $index=>$card){
            $number=str_pad((string)($index+1),2,'0',STR_PAD_LEFT);
            $output.='<li class="tour-hl-ui__card" data-journey-item-id="'.esc_attr($keys[$index]).'"><span class="tour-hl-ui__number" aria-hidden="true">'.$number.'</span><div class="tour-hl-ui__copy">';
            if($card['title']!=='')$output.='<h3 class="tour-hl-ui__title">'.esc_html($card['title']).'</h3>';
            if(trim(wp_strip_all_tags($card['body']))!=='')$output.='<div class="tour-hl-ui__description">'.wp_kses_post($card['body']).'</div>';
            $output.=self::related_links($card,$places,$index+1,MAD4B_Tour_Highlights_V1::get_tour_id(),$keys[$index]);
            $output.='</div></li>';
        }
        $output.='</ol></div>';
        return $output;
    }
}

MAD4B_Tour_Highlights_Presentation_V1::register();
