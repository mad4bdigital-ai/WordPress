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
$rows=[];$counts=[];
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
        $summary=sanitize_textarea_field(wp_strip_all_tags((string)$post->post_excerpt));
        $body=sanitize_textarea_field(mb_substr(wp_strip_all_tags((string)$post->post_content),0,12000));
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
