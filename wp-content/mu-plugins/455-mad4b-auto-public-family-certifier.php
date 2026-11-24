<?php
/**
 * Plugin Name: MAD4B Automatic Public Elementor Family Certifier
 * Description: Discover only verifiable public container subtrees in signed Elementor family documents.
 * Version: 0.1.4
 */
defined('ABSPATH') || exit;

final class MAD4B_Auto_Public_Family_V1 {
    private const OPTION='mad4b_auto_public_family_v1';
    private const CONTRACT='mad4b.elementor-auto-public-family.v1';
    private static ?array $policy=null;
    private static array $registry=[];
    private static array $stats=[];

    public static function config(): array {
        if(self::$policy!==null)return self::$policy;
        $p=get_option(self::OPTION,[]);
        if(!is_array($p)||($p['contract']??'')!==self::CONTRACT
            || ($p['site_host']??'')!==wp_parse_url(home_url('/'),PHP_URL_HOST)
            || ($p['environment']??'')!==wp_get_environment_type()
            || ($p['status']??'')!=='approved'
            || !is_array($p['families']??null)
            || count($p['families'])<1 || count($p['families'])>12)return self::$policy=[];
        $seen=[];
        foreach($p['families'] as $family){
            if(!is_array($family)
                || !preg_match('/^[1-9][0-9]{3,7}$/D',(string)($family['id']??''))
                || !preg_match('/^[a-f0-9]{64}$/D',(string)($family['sha256']??''))
                || isset($seen[(int)$family['id']]))return self::$policy=[];
            $seen[(int)$family['id']]=true;
        }
        return self::$policy=$p;
    }
    public static function mode(): string {
        $mode=(string)get_option('mad4b_auto_public_family_mode_v1','off');
        if ($mode==='on')return 'on';
        return $mode==='canary'
            && ($_SERVER['HTTP_X_MAD4B_AUTO_FAMILY_CANARY']??'')==='auto-family-20261124'
            ? 'canary' : 'off';
    }
    public static function family_map(): array {
        $result=[];
        foreach((array)(self::config()['families']??[]) as $family)
            $result[(int)$family['id']]=(string)$family['sha256'];
        return $result;
    }
    public static function analyze_node(array $node): array {
        $count=0;$listings=[];$tagNames=[];$metaFields=[];$reasons=[];
        $allowed=['container'=>true,'heading'=>true,'icon'=>true,
            'icon-box'=>true,'text-editor'=>true,'divider'=>true,
            'jet-listing-dynamic-field'=>true,'jet-listing-dynamic-terms'=>true,
            'jet-listing-grid'=>true];
        $allowedMeta=['_description'=>true,'tour_closer_line_content'=>true,
            'tour_why_book_with_us'=>true,'tour_internal_linking_exploration_content'=>true,
            'tour_origin_address'=>true,'group_size'=>true];
        $walk=static function($nodes)use(&$walk,&$count,&$listings,&$tagNames,&$metaFields,
            &$reasons,$allowed,$allowedMeta):bool{
            foreach($nodes as $item){
                if(!is_array($item)||++$count>95){$reasons[]='node_limit';return false;}
                $type=(string)($item['widgetType']??$item['elType']??'');
                if(!isset($allowed[$type])){$reasons[]='widget_'.$type;return false;}
                $settings=(array)($item['settings']??[]);
                if (($settings['jedv_enabled']??'')==='yes') {
                    $reasons[]='nested_conditions';return false;
                }
                $tags=(array)($settings['__dynamic__']??[]);
                foreach($tags as $tag){
                    if(!is_string($tag)){ $reasons[]='unknown_dynamic_tag';return false;}
                    preg_match_all('/name="([^"]+)"/',$tag,$matches);
                    if(!$matches[1]) { $reasons[]='unparsed_dynamic_tag';return false;}
                    foreach($matches[1] as $name){
                        if(!in_array($name,['post-title','site-title'],true)){
                            $reasons[]='dynamic_tag_'.$name;return false;
                        }
                        $tagNames[$name]=true;
                    }
                }
                if($type==='jet-listing-dynamic-field'){
                    $source=(string)($settings['dynamic_field_source']??'');
                    $field=(string)($settings['dynamic_field_post_meta']??'');
                    if($source!=='meta'||!isset($allowedMeta[$field])){
                        $reasons[]='dynamic_field_'.$source.'_'.$field;return false;
                    }
                    $metaFields[$field]=true;
                }
                if($type==='jet-listing-grid'){
                    $id=absint($settings['lisitng_id']??0);
                    $data=$id?get_post_meta($id,'_listing_data',true):null;
                    if(!$id || get_post_status($id)!=='publish'
                        || get_post_type($id)!=='jet-engine'
                        || !is_array($data)){
                        $reasons[]='listing_invalid';return false;
                    }
                    $source=(string)($data['source']??'');
                    if(in_array($source,['posts','repeater'],true)){
                        if(($data['post_type']??'')!=='tours-and-activities'){
                            $reasons[]='listing_post_type';return false;
                        }
                    }elseif($source==='terms'){
                        if(!in_array((string)($data['tax']??''),['amenities','destinations','tour-types'],true)){
                            $reasons[]='listing_taxonomy';return false;
                        }
                    }else{$reasons[]='listing_unbounded_source';return false;}
                    $listings[$id]=true;
                }
                $children=(array)($item['elements']??[]);
                if($children && !$walk($children))return false;
            }
            return true;
        };
        $pass=$walk([$node]);
        return ['eligible'=>$pass && $count>=2,
            'nodes'=>$count,'listings'=>array_map('intval',array_keys($listings)),
            'dynamic_tags'=>array_keys($tagNames),'meta'=>array_keys($metaFields),
            'reason'=>$reasons[0]??($count<2?'small_static':'public')];
    }
    public static function discover(int $family):array {
        if(isset(self::$registry[$family]))return self::$registry[$family];
        $result=[];$stats=['family'=>$family,'approved'=>0,'rejected'=>0,'reasons'=>[]];
        $expected=self::family_map()[$family]??'';
        $raw=$expected?get_post_meta($family,'_elementor_data',true):'';
        if(!is_string($raw)||!$raw || !hash_equals($expected,hash('sha256',$raw))
            || get_post_status($family)!=='publish') {
            $stats['reasons']['revision_or_status_mismatch']=1;
            self::$stats[$family]=$stats;
            return self::$registry[$family]=[];
        }
        $nodes=json_decode($raw,true);
        if(!is_array($nodes))return self::$registry[$family]=[];
        // An explicitly approved existing island already owns its whole
        // descendant subtree. Do not duplicate its cached HTML at a second
        // nested auto boundary.
        $manualIds=[];
        $manual=get_option('mad4b_mixed_family_public_islands_v1',[]);
        if(is_array($manual) && ($manual['status']??'')==='approved'){
            foreach((array)($manual['roots']??[]) as $r)
                if(is_array($r) && (int)($r['family']??0)===$family
                    && hash_equals((string)($r['family_sha256']??''),$expected))
                    $manualIds[(string)($r['id']??'')]=true;
        }
        $visit=static function($nodes)use(&$visit,&$result,&$stats,$family,$expected,$manualIds):void {
            foreach((array)$nodes as $node){
                if(!is_array($node))continue;
                $id=(string)($node['id']??'');
                if(isset($manualIds[$id]))continue;
                if(($node['elType']??'')==='container' && preg_match('/^[a-f0-9]{7}$/D',$id)){
                    $meta=self::analyze_node($node);
                    if($meta['eligible']){
                        $root=[
                            'family'=>$family,'id'=>$id,
                            'family_sha256'=>$expected,
                            'root_sha256'=>hash('sha256',(string)wp_json_encode($node,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)),
                            'type'=>'auto_public_v1','listings'=>$meta['listings'],
                            'node_count'=>$meta['nodes'],'dynamic_tags'=>$meta['dynamic_tags'],
                            'meta_fields'=>$meta['meta']
                        ];
                        $result[$family.'|'.$id]=$root;
                        ++$stats['approved'];
                        // Never certify nested roots twice.
                        continue;
                    }
                    $stats['reasons'][$meta['reason']]=($stats['reasons'][$meta['reason']]??0)+1;
                    ++$stats['rejected'];
                }
                if(is_array($node['elements']??null))$visit($node['elements']);
            }
        };
        $visit($nodes);
        self::$stats[$family]=$stats;
        return self::$registry[$family]=$result;
    }
    public static function root_for(int $family,string $id):?array {
        if(self::mode()==='off')return null;
        $roots=self::discover($family);
        $root=$roots[$family.'|'.$id]??null;
        if(!is_array($root))return null;
        // Save-triggered family re-certification does NOT authorize a newly
        // changed public subtree. Two real native SSR witnesses must match
        // before this root can enter the public snapshot path.
        $pending=get_option('mad4b_auto_family_pending_v1',[]);
        $entry=is_array($pending)?($pending['roots'][$family.'|'.$id]??null):null;
        if(is_array($entry))return null; // any outstanding witness denies replay
        if(class_exists('MAD4B_Auto_Family_Revision_V1',false)
            && MAD4B_Auto_Family_Revision_V1::mode()!=='off'){
            $state=get_option('mad4b_auto_family_revision_state_v1',[]);
            if(!is_array($state)||($state['site_host']??'')!==wp_parse_url(home_url('/'),PHP_URL_HOST)
                ||($state['families'][$family]['sha']??'')!==$root['family_sha256']
                ||($state['families'][$family]['roots'][$id]??'')!==$root['root_sha256'])return null;
        }
        return $root;
    }
    public static function validate(array $sub,array $root):bool {
        $meta=self::analyze_node($sub);
        if(!$meta['eligible'] || ($root['type']??'')!=='auto_public_v1')return false;
        $listings=$meta['listings'];$expected=array_map('intval',(array)($root['listings']??[]));
        sort($listings);sort($expected);
        return $listings===$expected
            && $meta['nodes']===(int)($root['node_count']??0);
    }
    public static function safe_html($html):bool {
        if(!is_string($html)||strlen($html)<200||strlen($html)>350000
            || !preg_match('/(?:elementor-widget|jet-listing|elementor-element)/',$html))
            return false;
        if(preg_match('~<\s*(?:script|form|input|textarea|select|iframe|object|embed)\b~i',$html))return false;
        if(preg_match('~\bdata-(?:nonce|price|rate|checkout|payment|booking|cart|session|user|customer|token|order|quote)[-_a-z0-9]*\s*=~i',$html)
          || preg_match('~\bon[a-z]{3,25}\s*=~i',$html)
          || preg_match('~\b(?:_wpnonce|wp_nonce|auth_token|access_token|session_id|client_secret|order_key)\s*=~i',$html)
          || preg_match('~\bclass\s*=\s*["\'][^"\']{0,400}\b(?:fluentform|woocommerce|checkout-form|booking-form|rate-calculator|payment-form|order-total)(?:\b|[-_])~i',$html))return false;
        if(preg_match('~\b(?:href|src|action|formaction)\s*=\s*["\']\s*(?:javascript:|vbscript:|data:text/html)~i',$html)
            || preg_match('~\b(?:srcdoc|contenteditable)\s*=~i',$html))return false;
        $text=html_entity_decode(strip_tags($html),ENT_QUOTES|ENT_HTML5,'UTF-8');
        if(preg_match('~(?:[$€£]\s*\d{1,3}(?:[.,]\d{3})*|\b(?:USD|EUR|GBP|EGP)\s*\d+(?:[.,]\d+)?|\d+(?:[.,]\d+)?\s*(?:USD|EUR|GBP|EGP)\b)~iu',$text))return false;
        return true;
    }
    public static function status():array {
        foreach(array_keys(self::family_map()) as $f)self::discover($f);
        return self::$stats;
    }
}
