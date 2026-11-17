<?php
/**
 * Plugin Name: MAD4B Home Top Cities — Published Tour Ranking
 * Description: Sorts the existing JetEngine Query 288 home carousel by distinct published tours in the active WPML language.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;

final class MAD4B_Home_Top_Cities_Tour_Rank_V1 {
    public const CONTRACT = 'mad4b.home-top-cities-tour-rank.v1';
    public const QUERY_ID = 288;
    public const HOME_SECTION_ID = 25909;

    /**
     * Query 288 is shared with destination archive navigation. It must NOT
     * change sibling/governorate ordering on non-home destinations.
     */
    public static function applies($query):bool {
        return is_object($query)
            && (int)($query->id ?? 0) === self::QUERY_ID
            && (string)($query->query_type ?? '') === 'terms'
            && is_front_page();
    }

    /** Stable descending sort. When counts tie, retain editorial query order. */
    public static function rank_terms(array $items,array $counts):array {
        $entries=[];
        foreach(array_values($items) as $i=>$term) {
            $entries[]=['term'=>$term,'index'=>$i,
                'tours'=>$term instanceof WP_Term
                    ? (int)($counts[(int)$term->term_id]??0):0];
        }
        usort($entries,static function($a,$b) {
            $diff=$b['tours']<=>$a['tours'];
            return $diff!==0?$diff:($a['index']<=>$b['index']);
        });
        return array_map(static fn($row)=>$row['term'],$entries);
    }

    /**
     * One indexed SQL aggregate, not an N+1 WP_Query per city.
     * The taxonomy term is the exact displayed card destination. DISTINCT
     * avoids double-counting a tour, and WPML restricts counting to the
     * current language rather than mixing translated copies of an offer.
     */
    public static function published_tour_counts(array $items):?array {
        global $wpdb;
        if(!($wpdb instanceof wpdb))return null;
        $ids=[];
        foreach($items as $term) {
            if(!($term instanceof WP_Term)||$term->taxonomy!=='destinations')
                return null;
            $ids[(int)$term->term_id]=true;
        }
        $ids=array_keys($ids);
        if(!$ids || count($ids)>80)return null;

        $lang=(string)apply_filters('wpml_current_language','');
        $wpml=defined('ICL_SITEPRESS_VERSION')
            && preg_match('/^[a-z]{2}(?:-[a-z]{2})?$/iD',$lang)===1;
        $placeholder=implode(',',array_fill(0,count($ids),'%d'));
        $join='';
        if($wpml) {
            $join=" INNER JOIN {$wpdb->prefix}icl_translations AS wpl
                ON wpl.element_id=p.ID
                AND wpl.element_type='post_tours-and-activities'
                AND wpl.language_code=%s ";
        }
        // WPML placeholder appears in JOIN before the term_id IN placeholders.
        $arguments=$wpml?array_merge([$lang],$ids):$ids;
        $sql="SELECT tt.term_id AS destination_id,
                     COUNT(DISTINCT p.ID) AS tour_count
              FROM {$wpdb->term_taxonomy} AS tt
              INNER JOIN {$wpdb->term_relationships} AS tr
                ON tr.term_taxonomy_id=tt.term_taxonomy_id
              INNER JOIN {$wpdb->posts} AS p
                ON p.ID=tr.object_id
              {$join}
              WHERE tt.taxonomy='destinations'
                AND tt.term_id IN ({$placeholder})
                AND p.post_type='tours-and-activities'
                AND p.post_status='publish'
                AND p.post_password=''
              GROUP BY tt.term_id";
        $rows=$wpdb->get_results($wpdb->prepare($sql,...$arguments),ARRAY_A);
        if(!is_array($rows)||$wpdb->last_error)return null;
        $counts=array_fill_keys($ids,0);
        foreach($rows as $row) {
            $id=(int)($row['destination_id']??0);
            if(array_key_exists($id,$counts))
                $counts[$id]=max(0,(int)($row['tour_count']??0));
        }
        return $counts;
    }

    public static function sort_query_items($items,$query) {
        if(!self::applies($query)||!is_array($items)||count($items)<2)
            return $items;
        $counts=self::published_tour_counts($items);
        return is_array($counts)?self::rank_terms($items,$counts):$items;
    }
}
add_filter('jet-engine/query-builder/query/items',
    [MAD4B_Home_Top_Cities_Tour_Rank_V1::class,'sort_query_items'],2500,2);
