<?php
/**
 * Runtime regression for MAD4B home Top Cities ordering.
 * Execute with WP-CLI eval-file after the candidate MU file is loaded.
 */
defined('ABSPATH') || exit(1);
if(!class_exists('MAD4B_Home_Top_Cities_Tour_Rank_V1',false))
    require_once '/tmp/mad4b-home-top-cities-rank-candidate.php';

$rank=MAD4B_Home_Top_Cities_Tour_Rank_V1::class;
$orig=$GLOBALS['wp_query']??null;
try {
    foreach(['en','es'] as $lang) {
        do_action('wpml_switch_language',$lang);
        $termIds=[];
        foreach([722,122,123] as $en) {
            $id=(int)apply_filters('wpml_object_id',$en,'destinations',false,$lang);
            if(!$id)throw new RuntimeException("Missing destination translation $en / $lang");
            $termIds[]=$id;
        }
        $terms=array_map(static fn($id)=>get_term($id,'destinations'),$termIds);
        foreach($terms as $term)if(!($term instanceof WP_Term))
            throw new RuntimeException('Missing or invalid destination term');
        $counts=$rank::published_tour_counts($terms);
        if(!is_array($counts)||$counts[$termIds[0]]<0)
            throw new RuntimeException("SQL counts unavailable for $lang");
        $order=$rank::rank_terms($terms,$counts);
        $want=array_reverse($termIds);
        if(array_map(static fn($t)=>(int)$t->term_id,$order)!==$want)
            throw new RuntimeException("Wrong ranked term IDs for $lang");

        // The Elementor home shortcode runs within the WordPress front page.
        $front=(int)apply_filters('wpml_object_id',(int)get_option('page_on_front'),'page',false,$lang);
        $GLOBALS['wp_query']=new WP_Query(['page_id'=>$front?:get_option('page_on_front')]);
        $onHome=is_front_page();
        $probe=(object)['id'=>288,'query_type'=>'terms'];
        $actual=$rank::sort_query_items($terms,$probe);
        $filtered=apply_filters('jet-engine/query-builder/query/items',$terms,$probe);

        // WPML must not force a translated search to count English-only tours.
        if($onHome && array_map(static fn($t)=>(int)$t->term_id,$actual)!==$want)
            throw new RuntimeException("Query hook failed on $lang homepage");
        if($onHome && array_map(static fn($t)=>(int)$t->term_id,(array)$filtered)!==$want)
            throw new RuntimeException("Existing JetEngine filter chain lost ranking for $lang");

        $nonTerm=$rank::sort_query_items($terms,(object)['id'=>288,'query_type'=>'posts']);
        if($nonTerm!==$terms)throw new RuntimeException('Non-term query changed');
        $GLOBALS['wp_query']=new WP_Query(['post_type'=>'post','p'=>1]);
        if(is_front_page())throw new RuntimeException('Non-home query unexpectedly marked front');
        if($rank::sort_query_items($terms,$probe)!==$terms)
            throw new RuntimeException('Destination/archive Query 288 must retain its original order');

        $tie=$rank::rank_terms($terms,[$termIds[0]=>5,$termIds[1]=>5,$termIds[2]=>3]);
        if((int)$tie[0]->term_id!==$termIds[0]||(int)$tie[1]->term_id!==$termIds[1])
            throw new RuntimeException("Ties did not preserve editorial order for $lang");

        echo wp_json_encode([
            'lang'=>$lang,'is_home'=>$onHome,'counts'=>$counts,
            'input'=>array_map(static fn($t)=>$t->name,$terms),
            'sort'=>array_map(static fn($t)=>$t->name,$order),
            'jetengine_filters'=>array_map(static fn($t)=>$t instanceof WP_Term?$t->name:'INVALID',(array)$filtered),
            'tie_preserved'=>true
        ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
    }
}finally{
    if($orig!==null)$GLOBALS['wp_query']=$orig;
    do_action('wpml_switch_language',null);
}
