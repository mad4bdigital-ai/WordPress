<?php
/**
 * Plugin Name: MAD4B Tour-Origin Readiness Adapter
 * Description: Optional company-local binding for travel-origin research evidence.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;
add_filter('mad4b/content_readiness/source_origin_post_type',static function($type) {
    if(is_string($type) && $type!=='' && post_type_exists($type))return $type;
    return post_type_exists('tours-and-activities') ? 'tours-and-activities' : '';
},20,1);

add_filter('mad4b/content_readiness/source_backed_hotel_pickup',static function($prior,$id,$type) {
    if($prior!==false)return $prior;
        if($type!=='tours-and-activities'||get_post_status($id)!=='publish'||
           !class_exists('MAD4B_SCP_Research_Groups')||
           !class_exists('MAD4B_SCP_Site_Profile'))return false;
        $highlight=get_post_meta($id,'highlight_rows',true);
        if(!is_string($highlight)||strlen($highlight)>60000)return false;
        $text=trim(wp_strip_all_tags($highlight));
        if(!preg_match('/\bhotel\s+pick[ -]?up\b/i',$text) ||
           preg_match('/\b(?:no|not|without|excluded|not included)\b.{0,36}\bhotel\s+pick[ -]?up\b/i',$text))
           return false;
        $site=(string)MAD4B_SCP_Site_Profile::site_uuid();
        $canonical=(int)(apply_filters('wpml_object_id',$id,$type,false,apply_filters('wpml_default_language',null)) ?: $id);
        global $wpdb;
        $group=$wpdb->prefix.'mad4b_research_groups';
        $record=$wpdb->prefix.'mad4b_research_records';
        $raw=$wpdb->get_var($wpdb->prepare(
            "SELECT r.data_json FROM {$record} r INNER JOIN {$group} g ON g.id=r.group_id
             WHERE g.task_key=%s AND g.site_uuid=%s AND r.provider_id=%s
             ORDER BY r.id DESC LIMIT 1",
            'origin:tour:'.$canonical,$site,'native_wp_source'));
        $e=is_string($raw)?json_decode($raw,true):null;
        if(!is_array($e)||($e['contract']??'')!=='mad4b.origin-evidence-workflow.v1'||
           (int)($e['post_id']??0)!==$id ||
           ($e['site_uuid']??'')!==$site ||
           ($e['classification']??'')!=='variable_hotel_pickup' ||
           ($e['exact_pickup_verified']??true)!==false)
            return false;
        $terms=wp_get_object_terms($id,'destinations',['fields'=>'ids']);
        if(is_wp_error($terms))return false;
        $terms=array_map('intval',(array)$terms);sort($terms,SORT_NUMERIC);
        $source=hash('sha256',wp_json_encode([
            'canonical_id'=>$canonical,
            'origin_term'=>get_post_meta($id,'tour_origin_location_term',true),
            'destination_terms'=>$terms
        ],JSON_UNESCAPED_SLASHES));
        if(!hash_equals((string)($e['source_sha256']??''),$source))return false;
        // A stored classification alone cannot outlive changed tour copy.
        $quotes=(array)($e['source_snippets']['highlight_rows']??[]);
        $matched=false;
        $now=trim(preg_replace('/\s+/u',' ',$text));
        foreach($quotes as $quote){
            $quote=trim(preg_replace('/\s+/u',' ',(string)$quote));
            if(strlen($quote)>=35 && str_contains($now,$quote) &&
               preg_match('/\bhotel\s+pick[ -]?up\b/i',$quote))$matched=true;
        }
        if(!$matched)return false;
        $issue_key=hash('sha256',get_current_blog_id().'|'.$type.'|'.$id.'|origin_missing');
        $ledger=(array)get_option('mad4b_content_readiness_issues_v1',[]);
        if(($ledger[$issue_key]['content_job_id']??'')!==(string)($e['job_id']??''))return false;
        return ['policy'=>'variable_guest_hotel_pickup','source'=>'published_tour_highlights',
            'source_meta_sha256'=>hash('sha256',$highlight),
            'research_job_id'=>(string)($e['job_id']??''),
            'fixed_origin_required'=>false,'guest_location_capture_verified'=>false];
},20,3);
