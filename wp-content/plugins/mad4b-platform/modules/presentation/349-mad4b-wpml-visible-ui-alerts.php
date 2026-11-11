<?php
/** Observed frontend surfaces -> page context -> locale -> concrete UI issue. */
defined('ABSPATH') || exit;
final class MAD4B_WPML_Visible_UI_Alerts_V1 {
    const REGISTRY='mad4b_wpml_visible_ui_surfaces_v1';
    private static array $observed=[];
    private static array $evaluatedStructuralSources=[];
    private static array $evaluatedLocaleSources=[];
    public static function mark_locale_evaluated(int $source):void {if($source>0)self::$evaluatedLocaleSources[$source]=true;}
    public static function covered_locale_source(int $source):bool {return $source>0&&isset(self::$evaluatedLocaleSources[$source]);}
    public static function mark_structural_evaluated(int $source):void {
        if($source>0)self::$evaluatedStructuralSources[$source]=true;
    }
    public static function observe($tree,$document_id) {
        if(is_admin() || wp_doing_ajax() || (defined('REST_REQUEST')&&REST_REQUEST))return $tree;
        $id=absint($document_id);
        if(!$id || get_post_type($id)!=='elementor_library' || !is_array($tree))return $tree;
        $lang=(string)apply_filters('wpml_default_language',null);
        $source=(int)apply_filters('wpml_object_id',$id,'elementor_library',false,$lang);
        if(!$source || get_post_status($source)!=='publish' ||
            !MAD4B_WPML_Design_Reconciliation_V1::eligible_types() ||
            !in_array('elementor_library',MAD4B_WPML_Design_Reconciliation_V1::eligible_types(),true))return $tree;
        $kind=is_singular()?'single_'.get_post_type(get_queried_object_id()):(is_archive()?'archive':'other');
        $key=$source.'|'.$kind;
        $page=absint(get_queried_object_id());
        $page_type=$page?get_post_type($page):'';
        $source_page=($page&&$page_type)?(int)apply_filters('wpml_object_id',$page,$page_type,false,$lang):0;
        self::$observed[$key]=['source_id'=>$source,'source_page_id'=>$source_page,
            'surface'=>'frontend','page_context'=>$kind,'last_observed'=>gmdate('c')];
        if(count(self::$observed)===1)add_action('shutdown',[__CLASS__,'flush'],99);
        return $tree;
    }
    public static function flush() {
        if(!self::$observed)return;
        $rows=get_option(self::REGISTRY,[]);if(!is_array($rows))$rows=[];
        $updated=false;
        foreach(self::$observed as $key=>$row){
            $previous=is_array($rows[$key]??null)?$rows[$key]:[];
            $last=strtotime((string)($previous['last_observed']??''))?:0;
            // A genuine frontend visit refreshes priority at most once per 30 minutes.
            if(!$previous || (empty($previous['source_page_id']) && !empty($row['source_page_id'])) || time()-$last>=1800) {
                $rows[$key]=$row;$updated=true;
            }
        }
        if($updated){$rows=array_slice($rows,-80,null,true);update_option(self::REGISTRY,$rows,false);}
    }
    /** Bounded rotating coverage: four most recently observed plus eight rotating older surfaces. */
    /** Only a freshly evaluated source may resolve its previous scan issue. */
    public static function covered_source(int $source):bool {
        return $source>0 && isset(self::$evaluatedStructuralSources[$source]);
    }
    public static function observed_slice(int $limit=12):array {
        $rows=array_values(array_filter((array)get_option(self::REGISTRY,[]),'is_array'));
        usort($rows,static function($a,$b){
            return strcmp((string)($b['last_observed']??''),(string)($a['last_observed']??''));
        });
        $limit=max(1,min(20,$limit));
        if(count($rows)<=$limit)return $rows;
        $head=min(4,$limit);$recent=array_slice($rows,0,$head);
        $older=array_slice($rows,$head);$slots=$limit-$head;
        if($slots<=0)return $recent;
        $windows=(int)ceil(count($older)/$slots);
        $window=(int)(floor(time()/3600)%max(1,$windows));
        return array_merge($recent,array_slice($older,$window*$slots,$slots));
    }
    public static function external($out,$policy=[]) {
        if(!is_array($out)||!MAD4B_WPML_Design_Reconciliation_V1::enabled())return $out;
        $rows=self::observed_slice();
        $seen=[];foreach($out as $v)if(is_array($v)&&isset($v['id']))$seen[(string)$v['id']]=true;
        $processed=[];
        foreach($rows as $row){
            if(!is_array($row)||($row['surface']??'')!=='frontend')continue;
            $src=absint($row['source_id']??0);
            if(!$src || isset($processed[$src]) || get_post_type($src)!=='elementor_library'
                || get_post_status($src)!=='publish'
                || !mad4b_wpml_auto_sync_allowed_v1($src))continue;
            if(count($processed)>=12)break;
            $processed[$src]=true;
            $result=MAD4B_WPML_Design_Reconciliation_V1::reconcile($src,false);
            if(is_wp_error($result)||!is_array($result))continue;
            self::mark_locale_evaluated($src);
            $context=sanitize_key((string)($row['page_context']??'unknown'));
            foreach((array)($result['translations']??[]) as $tr){
                $lang=sanitize_key((string)($tr['lang']??''));
                if(!in_array($lang,['ar','de','es','fr','it','en'],true)
                    || !empty($tr['skipped']))continue;
                $groups=[];
                foreach((array)($tr['issues']??[]) as $issue){
                    $code=(string)($issue['code']??'');
                    $family=match($code){
                        'widget_created_from_master','widget_type_conflict','missing_root_widget_translation','missing_widget_translation'=>'layout',
                        'missing_linked_reference','invalid_source_reference','invalid_reference_array'=>'binding',
                        'widget_pending_locale_content'=>'missing',
                        'missing_widget_text_translation','existing_untranslated_text_preserved'=>'text',
                        default=>'',
                    };
                    if(!$family)continue;
                    if(!isset($groups[$family]))$groups[$family]=['count'=>0,'widgets'=>[]];
                    $groups[$family]['count']++;
                    $w=sanitize_key((string)($issue['widget']??''));
                    if($w && count($groups[$family]['widgets'])<5)$groups[$family]['widgets'][$w]=true;
                }
                foreach($groups as $family=>$group){
                    $code='loc_'.$family.'_'.$lang;
                    $issueId=hash('sha256',get_current_blog_id().'|elementor_library|'.$src.'|'.$code);
                    if(isset($seen[$issueId]))continue;
                    $seen[$issueId]=true;
                    $severity=$family==='text'?'medium':'high';
                    $widgetIds=implode(',',array_keys($group['widgets']));
                    $label='Frontend > '.$context.' > '.$lang.' > '.$family.': '.$group['count'].' findings in '.get_the_title($src).' (#'.$src.'); widgets '.$widgetIds;
                    $out[]=['id'=>$issueId,'post_type'=>'elementor_library','object_id'=>$src,
                        'code'=>$code,'severity'=>$severity,'source'=>'mad4b-elementor-localization',
                        'label'=>mb_substr($label,0,230)];
                }
            }
        }
        return $out;
    }
}
add_filter('elementor/frontend/builder_content_data',['MAD4B_WPML_Visible_UI_Alerts_V1','observe'],99,2);
add_filter('mad4b/content_readiness/external_issues',['MAD4B_WPML_Visible_UI_Alerts_V1','external'],78,2);
require_once __DIR__.'/349-mad4b-locale-structural-health.php';
