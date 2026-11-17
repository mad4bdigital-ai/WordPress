<?php
/** Read-only site-native checks for empty presentation cards and translated-term duplication. */
defined('ABSPATH') || exit;
final class MAD4B_Locale_Structural_Health_V1 {
    public static function empty_cards(array $tree): array {
        $problems=[];
        $walk=static function(array $nodes)use(&$walk,&$problems) {
            foreach($nodes as $node){
                if(!is_array($node))continue;
                $children=(array)($node['elements']??[]);
                $isGrid=($node['elType']??'')==='container' &&
                    (($node['settings']['container_type']??'')==='grid' || ($node['settings']['display']??'')==='grid');
                if($isGrid){
                    foreach($children as $card){
                        if(!is_array($card)||($card['elType']??'')!=='container')continue;
                        $types=[];$queue=[(array)($card['elements']??[])];
                        while($queue){foreach(array_pop($queue) as $n){if(!is_array($n))continue;
                            if(($n['elType']??'')==='widget')$types[]=(string)($n['widgetType']??'');
                            if(!empty($n['elements']))$queue[]=(array)$n['elements'];
                        }}
                        // Heading + decorative icon alone cannot supply a value. Only flag info-card patterns.
                        if(in_array('heading',$types,true)&&in_array('icon',$types,true) &&
                            !array_diff($types,['heading','icon','spacer']))
                            $problems[]=(string)($card['id']??'unknown');
                    }
                }
                if($children)$walk($children);
            }
        };
        $walk($tree);
        return array_values(array_unique($problems));
    }
    /** A locale-only grid with visitor-facing information cards is structural drift. */
    public static function orphan_grids(array $source,array $translated):array {
        $original=[];
        $index=function($nodes)use(&$index,&$original){
            foreach((array)$nodes as $n){if(!is_array($n))continue;
                if(!empty($n['id']))$original[(string)$n['id']]=true;
                $index($n['elements']??[]);
            }
        };
        $index($source);$orphans=[];
        $scan=function($nodes)use(&$scan,&$orphans,$original){
            foreach((array)$nodes as $n){if(!is_array($n))continue;
                $id=(string)($n['id']??'');
                $isGrid=($n['elType']??'')==='container' &&
                    (($n['settings']['container_type']??'')==='grid' || ($n['settings']['display']??'')==='grid');
                if($id && $isGrid && !isset($original[$id]) &&
                    ($n['settings']['mad4b_wpml_sync_mode']??'')!=='detach' &&
                    count((array)($n['elements']??[]))>=3)$orphans[]=$id;
                $scan($n['elements']??[]);
            }
        };
        $scan($translated);
        return array_values(array_unique($orphans));
    }
    public static function duplicates(int $id,string $lang,int $limit=60):array {
        if(!$id||!get_post($id))return [];
        global $wpdb;$issues=[];
        $taxes=get_object_taxonomies(get_post_type($id),'names');
        foreach(array_slice($taxes,0,$limit) as $tax){
            $ids=array_map('intval',(array)$wpdb->get_col($wpdb->prepare(
                "SELECT tt.term_id FROM {$wpdb->term_relationships} tr JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=tr.term_taxonomy_id WHERE tr.object_id=%d AND tt.taxonomy=%s",
                $id,$tax)));
            $present=array_fill_keys($ids,true);
            foreach($ids as $term){
                $mapped=(int)apply_filters('wpml_object_id',$term,$tax,false,$lang);
                if($mapped && $mapped!==$term && isset($present[$mapped])){
                    $issues[$tax.'|'.$term.'|'.$mapped]=['taxonomy'=>$tax,'source_term'=>$term,'local_term'=>$mapped];
                }
            }
        }
        return array_values($issues);
    }
    public static function external($out,$cfg=[]) {
        if(!is_array($out)||!class_exists('MAD4B_WPML_Design_Reconciliation_V1')||
            !MAD4B_WPML_Design_Reconciliation_V1::enabled())return $out;
        $entries=MAD4B_WPML_Visible_UI_Alerts_V1::observed_slice();
        $seen=[];foreach($out as $v)if(is_array($v)&&isset($v['id']))$seen[$v['id']]=true;
        $count=0;
        foreach($entries as $entry){
            if(!is_array($entry)||($entry['surface']??'')!=='frontend')continue;
            if(++$count>12)break;
            $master=absint($entry['source_id']??0);
            if(!$master||get_post_type($master)!=='elementor_library'||
                get_post_status($master)!=='publish'||!mad4b_wpml_auto_sync_allowed_v1($master))continue;
            $page=absint($entry['source_page_id']??0);
            $pageType=$page?get_post_type($page):'';
            $sourceTree=json_decode((string)get_post_meta($master,'_elementor_data',true),true);
            if(!is_array($sourceTree))continue;
            $context=sanitize_key((string)($entry['page_context']??'unknown'));
            $trid=apply_filters('wpml_element_trid',null,$master,'post_elementor_library');
            $refs=$trid?apply_filters('wpml_get_element_translations',null,$trid,'post_elementor_library'):[];
            foreach((array)$refs as $lang=>$ref){
                if(!in_array($lang,(array)array_keys((array)apply_filters('wpml_active_languages',null,'skip_missing=0')),true))continue;
                $local=absint($ref->element_id??0);
                if(!$local||get_post_status($local)!=='publish'||!mad4b_wpml_auto_sync_allowed_v1($master,$local))continue;
                $tree=json_decode((string)get_post_meta($local,'_elementor_data',true),true);
                $empty=is_array($tree)?self::empty_cards($tree):[];
                $orphan=is_array($tree)?self::orphan_grids($sourceTree,$tree):[];
                $dup=[];
                if($page && $pageType){
                    $target=(int)apply_filters('wpml_object_id',$page,$pageType,false,$lang);
                    if($target && get_post_status($target)==='publish')$dup=self::duplicates($target,$lang);
                }
                foreach(['empty_card'=>$empty,'orphan_grid'=>$orphan,'duplicate_terms'=>$dup] as $problem=>$items){
                    if(!$items)continue;
                    $fingerprint=substr(hash('sha256',$context.'|'.$problem),0,10);
                    $code='loc_ui_'.$problem.'_'.$lang.'_'.$fingerprint;
                    $id=hash('sha256',get_current_blog_id().'|elementor_library|'.$master.'|'.$code);
                    if(isset($seen[$id]))continue;$seen[$id]=true;
                    $detail=$problem==='duplicate_terms'?
                        implode(',',array_slice(array_unique(array_column($items,'taxonomy')),0,5)):
                        implode(',',array_slice($items,0,5));
                    $label='Frontend > '.$context.' > '.$lang.' > '.$problem.': '.count($items).
                        ' affected on '.get_the_title($master).' (#'.$master.'); '.$detail;
                    $out[]=['id'=>$id,'post_type'=>'elementor_library','object_id'=>$master,
                        'code'=>$code,'source'=>'mad4b-locale-structural-health','severity'=>'high',
                        'label'=>mb_substr($label,0,235)];
                }
            }
            MAD4B_WPML_Visible_UI_Alerts_V1::mark_structural_evaluated($master);
        }
        return $out;
    }
}
add_filter('mad4b/content_readiness/external_issues',['MAD4B_Locale_Structural_Health_V1','external'],79,2);
