<?php
/**
 * Reusable JetEngine/Elementor Journey Discovery role migration.
 * No site IDs, Elementor element IDs, locale IDs or operation allowlists.
 * Preconditions: site identity, native CPT field and related-listing template.
 * Mutations are CAS-protected and require TOUR_JOURNEY_ACTION=apply.
 */
global $wpdb;
$site=(string)getenv('TOUR_JOURNEY_SITE');
$action=(string)getenv('TOUR_JOURNEY_ACTION');
if(!$site || get_option('home')!==$site || !in_array($action,['preflight','apply','verify'],true)){
    fwrite(STDERR,"ERROR: SITE_OR_ACTION_MISMATCH\n");exit(2);
}
$rows=$wpdb->get_results(
    $wpdb->prepare(
        "SELECT m.meta_id,m.post_id,m.meta_value FROM {$wpdb->postmeta} m
         INNER JOIN {$wpdb->posts} p ON p.ID=m.post_id
         WHERE p.post_type=%s AND m.meta_key=%s AND m.meta_value LIKE %s ORDER BY m.post_id",
        'elementor_library','_elementor_data','%tour_highlights_content%'
    ),ARRAY_A
);
if(!$rows){fwrite(STDERR,"ERROR: NO_TOUR_HIGHLIGHTS_DOCUMENTS\n");exit(3);}
$relatedCache=[];
$isRelated=function($node)use(&$relatedCache):bool {
    if(($node['widgetType']??'')!=='template')return false;
    $id=(int)($node['settings']['template_id']??0);
    if($id<=0||get_post_type($id)!=='elementor_library')return false;
    if(!array_key_exists($id,$relatedCache)){
        $raw=(string)get_post_meta($id,'_elementor_data',true);
        $relatedCache[$id]=substr_count($raw,'"widgetType":"jet-listing-grid"')>=2;
    }
    return $relatedCache[$id];
};
$addClass=function(&$node,$class):bool {
    if(!isset($node['settings'])||!is_array($node['settings']))$node['settings']=[];
    $existing=preg_split('/\s+/u',trim((string)($node['settings']['css_classes']??'')),-1,PREG_SPLIT_NO_EMPTY);
    if(in_array($class,$existing,true))return false;
    $existing[]=$class;
    $node['settings']['css_classes']=implode(' ',array_unique($existing));
    return true;
};
$out=[];
foreach($rows as $row) {
    $id=(int)$row['post_id'];$raw=(string)$row['meta_value'];
    $nodes=json_decode($raw,true);
    if(!is_array($nodes)){fwrite(STDERR,"ERROR: DOC_JSON_INVALID $id\n");exit(4);}
    $matches=0;$changes=0;$moved=0;$issues=[];
    $walk=function(&$list)use(&$walk,$isRelated,$addClass,&$matches,&$changes,&$moved,&$issues){
        foreach($list as &$parent) {
            if(!isset($parent['elements'])||!is_array($parent['elements']))continue;
            $children=&$parent['elements'];
            foreach($children as $key=>$candidate) {
                if(($candidate['elType']??'')!=='container'
                    || !isset($candidate['elements']) || !is_array($candidate['elements']))continue;
                $fieldKeys=[];$headingKeys=[];$innerTemplateKeys=[];
                foreach($candidate['elements'] as $j=>$child){
                    if(($child['widgetType']??'')==='jet-listing-dynamic-field'
                        && ($child['settings']['dynamic_field_post_meta']??'')==='tour_highlights_content')$fieldKeys[]=$j;
                    if(($child['widgetType']??'')==='heading')$headingKeys[]=$j;
                    if($isRelated($child))$innerTemplateKeys[]=$j;
                }
                if(count($fieldKeys)!==1)continue;
                $matches++;
                if(count($headingKeys)!==1){$issues[]='missing_or_multiple_heading';continue;}
                if(count($innerTemplateKeys)>1){$issues[]='multiple_related_templates_inside';continue;}
                if(count($innerTemplateKeys)===0) {
                    $siblings=[];
                    foreach($children as $sibkey=>$sib)if($sibkey!==$key && $isRelated($sib))$siblings[]=$sibkey;
                    if(count($siblings)!==1){$issues[]='missing_or_ambiguous_related_template';continue;}
                    $tpl=$children[$siblings[0]];
                    $children[$key]['elements'][]=$tpl;
                    unset($children[$siblings[0]]);
                    $children=array_values($children);
                    // Since sibling was potentially removed before this candidate, look up
                    // the target by its unique original Elementor ID rather than index.
                    $matched=[];
                    foreach($children as $idx=>$v)if(($v['id']??null)===($candidate['id']??null))$matched[]=$idx;
                    if(count($matched)!==1){$issues[]='candidate_relocation_conflict';continue;}
                    $key=$matched[0];
                    $moved++;
                }
                $target=&$children[$key];
                if($addClass($target,'tour-journey-discovery-editor'))$changes++;
                foreach($target['elements'] as &$child) {
                    if(($child['widgetType']??'')==='jet-listing-dynamic-field'
                        && ($child['settings']['dynamic_field_post_meta']??'')==='tour_highlights_content') {
                        if($addClass($child,'tour-journey-content-editor'))$changes++;
                    }elseif(($child['widgetType']??'')==='heading'){
                        if($addClass($child,'tour-journey-heading-editor'))$changes++;
                    }elseif($isRelated($child)){
                        if($addClass($child,'tour-journey-related-editor'))$changes++;
                    }
                }
                unset($child,$target);
            }
            unset($children);
            $walk($parent['elements']);
        }
        unset($parent);
    };
    $walk($nodes);
    if($matches!==1||$issues){
        $detail=wp_json_encode(['document'=>$id,'matches'=>$matches,'issues'=>$issues]);
        fwrite(STDERR,"ERROR: DOCUMENT_DISCOVERY_CONFLICT {$detail}\n");exit(5);
    }
    $encoded=wp_json_encode($nodes,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    if(!is_string($encoded)||!is_array(json_decode($encoded,true)))exit(6);
    $out[]=['id'=>$id,'meta_id'=>(int)$row['meta_id'],'old'=>$raw,'new'=>$encoded,
        'changed'=>$changes,'moved'=>$moved,'before_sha256'=>hash('sha256',$raw),'after_sha256'=>hash('sha256',$encoded)];
}
$summary=['site'=>$site,'action'=>$action,'docs'=>count($out),'changes'=>array_sum(array_column($out,'changed')),
    'moved_related'=>array_sum(array_column($out,'moved')),'documents'=>array_map(
        static fn($d)=>array_diff_key($d,array_flip(['old','new'])),$out)];
if($action==='apply'){
    foreach($out as $doc){
        if($doc['old']===$doc['new'])continue;
        $sql=$wpdb->prepare("UPDATE {$wpdb->postmeta} SET meta_value=%s WHERE meta_id=%d AND meta_value=%s",
            $doc['new'],$doc['meta_id'],$doc['old']);
        if($wpdb->query($sql)!==1){fwrite(STDERR,"ERROR: ELEMENTOR_CAS_FAILED {$doc['id']}\n");exit(7);}
        wp_cache_delete($doc['id'],'post_meta');
        if((string)get_post_meta($doc['id'],'_elementor_data',true)!==$doc['new']){
            fwrite(STDERR,"ERROR: ELEMENTOR_READBACK_FAILED {$doc['id']}\n");exit(8);
        }
        // Do not reuse stale cached HTML where dynamic classes were added.
        delete_post_meta($doc['id'],'_elementor_element_cache');
    }
}
echo wp_json_encode($summary,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n";
