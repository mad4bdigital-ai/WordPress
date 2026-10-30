<?php
/**
 * MAD4B verified Tourism source -> Content Hub exporter.
 * Run ONLY as: wp --path=<exact Tourism WordPress root> --skip-themes eval-file <file>.
 * Uses local WP_Query with published-only posts and WPML language metadata.
 * No REST credentials, customers, prices, uploads, orders or WordPress writes.
 */
if (!defined('WP_CLI') || !WP_CLI || !defined('ABSPATH')) { exit(82); }
if (!class_exists('MAD4B_Company_Simple_Identity_V1')) {
    fwrite(STDERR,"CROSS_SSH_IDENTITY_MISSING\n");exit(83);
}
$identity=MAD4B_Company_Simple_Identity_V1::identity_for_host();
if (!is_array($identity) ||
    ($identity['company_id']??null)!=='tourism-almallahgroup' ||
    ($identity['canonical_host']??null)!=='tourism.almallahgroup-mg.com' ||
    wp_parse_url(home_url('/'),PHP_URL_HOST)!=='tourism.almallahgroup-mg.com') {
    fwrite(STDERR,"CROSS_SSH_SOURCE_HOST_MISMATCH\n");exit(84);
}
$types=['tours-and-activities'=>'tour','properties'=>'property','transportations'=>'transportation'];
$languages=['en','ar','fr','de','it','es'];
$rows=[];$counts=[];$wpdb=$GLOBALS['wpdb'];
// Narrow, audited field metadata registry. Extend via a reviewed source adapter;
// do not export arbitrary postmeta (customer data, pricing, tokens, rates).
$fieldAllow=[
    'tours-and-activities'=>[
        'tour_mode'=>'text','tour_type'=>'text',
    ],
    'properties'=>[],
    'transportations'=>[
        'trans_route_estimated_duration'=>'text',
        'trans_train_operator'=>'text',
    ],
];
$taxonomyAllow=[
 'tours-and-activities'=>['tour-types_jet','tour_categories_jet','location_jet'],
 'properties'=>['property-types','location_jet'],
 'transportations'=>['transportation-types','transport-service-type','location_jet']
];
$cleanDescription=static function($value) {
    if(!is_string($value))return '';
    return sanitize_textarea_field(mb_substr(wp_strip_all_tags($value),0,12000));
};
$cleanLocation=static function($raw) {
    $value=is_scalar($raw)?trim((string)$raw):'';
    return preg_match('/^[a-zA-Z0-9_. -]{1,100}$/D',$value)?$value:'';
};
foreach($types as $type=>$kind) {
    if(!post_type_exists($type)){fwrite(STDERR,"CROSS_SSH_SOURCE_TYPE_MISSING\n");exit(85);}
    $q=new WP_Query([
        'post_type'=>$type,'post_status'=>'publish',
        'posts_per_page'=>2000,'paged'=>1,'fields'=>'ids',
        'orderby'=>'ID','order'=>'ASC','ignore_sticky_posts'=>true,
        'no_found_rows'=>false,'suppress_filters'=>true,
    ]);
    $count=(int)$q->found_posts;
    if($count<0 || $count>2000 || $count!==count($q->posts)) {
        fwrite(STDERR,"CROSS_SSH_SOURCE_COUNT_CHANGED\n");exit(86);
    }
    $counts[$type]=$count;
    foreach($q->posts as $id) {
        $id=(int)$id;
        $post=get_post($id);
        if(!$post || $post->post_type!==$type || $post->post_status!=='publish') {
            fwrite(STDERR,"CROSS_SSH_SOURCE_CHANGED\n");exit(87);
        }
        $wpml=apply_filters('wpml_post_language_details',null,$id);
        $language=is_array($wpml)?($wpml['language_code']??null):null;
        if(!in_array($language,$languages,true)) {
            fwrite(STDERR,"CROSS_SSH_SOURCE_LANGUAGE_MISSING\n");exit(88);
        }
        $title=sanitize_text_field((string)$post->post_title);
        $summary=$cleanDescription((string)$post->post_excerpt);
        $body=$cleanDescription((string)$post->post_content);
        $textOrigin=$body!==''?'post_content':'none';
        if($body==='') {
            $field=['tours-and-activities'=>'_description','properties'=>'prop__description',
                'transportations'=>'trans_description'][$type];
            $body=$cleanDescription(get_post_meta($id,$field,true));
            if($body!=='')$textOrigin='source_meta';
        }
        if($body==='' && $summary!==''){$body=$summary;$textOrigin='excerpt_fallback';}
        $slug=(string)$post->post_name;
        if($slug===''||strlen($slug)>250) {
            fwrite(STDERR,"CROSS_SSH_SOURCE_SLUG_INVALID\n");exit(91);
        }
        $url=get_permalink($id);
        if(!is_string($url) || wp_parse_url($url,PHP_URL_HOST)!=='tourism.almallahgroup-mg.com' ||
            strlen($url)>2048 || wp_parse_url($url,PHP_URL_SCHEME)!=='https') {
            fwrite(STDERR,"CROSS_SSH_SOURCE_PERMALINK_INVALID\n");exit(92);
        }
        $trid=(int)$wpdb->get_var($wpdb->prepare(
            "SELECT trid FROM {$wpdb->prefix}icl_translations WHERE element_type=%s AND element_id=%d",
            'post_'.$type,$id
        ));
        if($trid<1){fwrite(STDERR,"CROSS_SSH_SOURCE_WPML_TRID_MISSING\n");exit(93);}
        $taxonomies=[];
        foreach($taxonomyAllow[$type] as $taxonomy) {
            $terms=wp_get_post_terms($id,$taxonomy,['fields'=>'all']);
            if(is_wp_error($terms)) {fwrite(STDERR,"CROSS_SSH_SOURCE_TERMS_ERROR\n");exit(94);}
            if(count($terms)>40) {fwrite(STDERR,"CROSS_SSH_SOURCE_TERMS_EXCESS\n");exit(95);}
            $refs=[];
            foreach($terms as $term) {
                $refs[]=['id'=>(int)$term->term_id,'slug'=>(string)$term->slug,
                    'parent'=>(int)$term->parent];
            }
            usort($refs,static function($a,$b){return $a['id']<=>$b['id'];});
            $taxonomies[$taxonomy]=$refs;
        }
        $facts=[];
        if($type==='tours-and-activities'){
            $facts['origin_place_id']=$cleanLocation(get_post_meta($id,'tour_origin_address_place_id',true));
            $facts['origin_location_term']=$cleanLocation(get_post_meta($id,'tour_origin_location_term',true));
        } elseif($type==='transportations'){
            $facts['from_station_id']=$cleanLocation(get_post_meta($id,'trans_from_station_id',true));
            $facts['to_station_id']=$cleanLocation(get_post_meta($id,'trans_to_station_id',true));
        }
        $facts=array_filter($facts,static function($v){return $v!=='';});
        $metadata=[];
        foreach($fieldAllow[$type] as $field=>$valueType) {
            $raw=get_post_meta($id,$field,true);
            if(!is_scalar($raw) || is_bool($raw))continue;
            $value=sanitize_text_field(mb_substr((string)$raw,0,300));
            if($value!=='')$metadata[$field]=$value;
        }
        if(trim($title)==='' || strlen($title)>1500 || strlen($summary)>3000 ||
            strlen($body)>50000) {
            fwrite(STDERR,"CROSS_SSH_SOURCE_CONTENT_INVALID\n");exit(89);
        }
        $modified=(string)$post->post_modified_gmt;
        if(!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D',$modified))
            $modified='0000-00-00 00:00:00';
        $rows[]=[
            'source_company'=>'tourism-almallahgroup',
            'source_post_type'=>$type,'kind'=>$kind,'source_id'=>$id,
            'language'=>$language,'title'=>$title,'summary'=>$summary,
            'body'=>$body,'modified_gmt'=>$modified,
            'source_slug'=>$slug,'source_url'=>$url,'source_wpml_trid'=>$trid,
            'source_text_origin'=>$textOrigin,'source_taxonomy_refs'=>$taxonomies,
            'source_identity_facts'=>$facts,
            'source_metadata'=>$metadata,
            'reference_only'=>true,'may_publish'=>false,'may_checkout'=>false,
        ];
    }
}
$manifest=[
    'contract'=>'mad4b.hub.wp-cli-cross-ssh.v1',
    'source_company'=>'tourism-almallahgroup',
    'canonical_host'=>'tourism.almallahgroup-mg.com',
    'types'=>array_keys($types),
    'counts'=>$counts,'items'=>$rows,
];
$json=wp_json_encode($manifest,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
if(!is_string($json) || strlen($json)>27000000) {
    fwrite(STDERR,"CROSS_SSH_SNAPSHOT_OVERSIZE\n");exit(90);
}
echo $json."\n";
