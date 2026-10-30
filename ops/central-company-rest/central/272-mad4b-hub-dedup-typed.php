<?php
/**
 * Plugin Name: MAD4B Hub Intelligence Typed Similarity
 * Description: Company/type/language scoped, evidence-based dedup candidates; never mutates.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Hub_Dedup_Typed_V1 {
    const ADAPTER='typed_evidence_v1';
    const REVIEW_LIMIT=100;
    const CANDIDATE_PAIR_LIMIT=100000;
    private static function words($value): array {
        $text=mb_strtolower((string)$value,'UTF-8');
        $tokens=preg_split('/[^\p{L}\p{N}]+/u',$text,-1,PREG_SPLIT_NO_EMPTY);
        if(!is_array($tokens))return [];
        return array_values(array_unique(array_filter($tokens,static function($x){return mb_strlen($x,'UTF-8')>1;})));
    }
    private static function normalized($text): string {
        $text=mb_strtolower((string)$text,'UTF-8');
        return trim(preg_replace('/[^\p{L}\p{N}]+/u',' ',trim($text)));
    }
    private static function dice(array $left,array $right): float {
        if(!$left||!$right)return 0.0;
        $a=array_fill_keys($left,true);
        $b=array_fill_keys($right,true);
        $match=count(array_intersect_key($a,$b));
        return 2*$match/(count($a)+count($b));
    }
    private static function taxIntersection(array $left,array $right,$tax): array {
        $a=$left[$tax]??[];$b=$right[$tax]??[];
        if(!is_array($a)||!is_array($b))return [];
        $ids=[];
        foreach($a as $t)if(is_array($t)&&isset($t['id']))$ids[]=(int)$t['id'];
        $target=[];
        foreach($b as $t)if(is_array($t)&&isset($t['id']))$target[]=(int)$t['id'];
        return array_values(array_intersect(array_unique($ids),array_unique($target)));
    }
    public static function discover(array $context): array {
        $rows=$context['records']??[];
        if(!is_array($rows))throw new RuntimeException('Invalid typed dedup context');
        $data=[];$tokens=[];$blocks=[];
        $bodyReady=0;$sourceTrids=0;$taxReady=0;$factsReady=0;
        foreach($rows as $hubId=>$r) {
            $id=(int)$hubId;
            $title=self::normalized($r['title']??'');
            if($title==='')continue;
            $token=self::words($title);
            if(!$token)continue;
            $tax=get_post_meta($id,'_mad4b_hub_import_taxonomy_refs',true);
            if(!is_array($tax))$tax=[];
            $facts=get_post_meta($id,'_mad4b_hub_import_identity_facts',true);
            if(!is_array($facts))$facts=[];
            $trid=(int)get_post_meta($id,'_mad4b_hub_import_translation_trid',true);
            $post=get_post($id);
            $body=$post ? trim(wp_strip_all_tags((string)$post->post_content)) : '';
            if($body!=='')$bodyReady++;
            if($trid>0)$sourceTrids++;
            if($tax)$taxReady++;
            if($facts)$factsReady++;
            $data[$id]=[
                'id'=>$id,'company'=>$r['company_id'],'type'=>$r['source_type'],
                'lang'=>$r['source_language'],'source_id'=>$r['source_post_id'],
                'title'=>$title,'trid'=>$trid,
                'tax'=>$tax,'facts'=>$facts,
                'text_hash'=>mb_strlen($body,'UTF-8')>=80 ?
                    hash('sha256',self::normalized($body)) : '',
            ];
            $tokens[$id]=$token;
            foreach($token as $word) {
                if(mb_strlen($word,'UTF-8')<3)continue;
                $block=$data[$id]['company'].':'.$data[$id]['type'].':'.$data[$id]['lang'].':'.$word;
                $blocks[$block][]=$id;
            }
        }
        $seen=[];$candidates=[];$checked=0;$truncated=false;
        foreach($blocks as $key=>$ids) {
            if(count($ids)<2 || count($ids)>200)continue;
            $size=count($ids);
            for($i=0;$i<$size;$i++)for($j=$i+1;$j<$size;$j++) {
                $a=$ids[$i];$b=$ids[$j];
                if($a===$b)continue;
                $pair=min($a,$b).':'.max($a,$b);
                if(isset($seen[$pair]))continue;
                $seen[$pair]=true;
                $checked++;
                if($checked>self::CANDIDATE_PAIR_LIMIT){$truncated=true;break 3;}
                $x=$data[$a];$y=$data[$b];
                if($x['source_id']===$y['source_id'])continue;
                // Genuine WPML translations must never be treated as duplicates.
                if($x['trid']>0 && $y['trid']>0 && $x['trid']===$y['trid'])continue;
                $titleScore=self::dice($tokens[$a],$tokens[$b]);
                if($x['title']===$y['title'])$titleScore=1.0;
                if($titleScore<0.73)continue;
                $signals=['normalized_title_similarity'];
                $locationShared=self::taxIntersection($x['tax'],$y['tax'],'location_jet');
                if($locationShared)$signals[]='shared_source_location_term';
                $typeTax=($x['type']==='properties'?'property-types':
                    ($x['type']==='transportations'?'transportation-types':'tour-types_jet'));
                $kindShared=self::taxIntersection($x['tax'],$y['tax'],$typeTax);
                if($kindShared)$signals[]='shared_type_taxonomy';
                $samePlace=($x['facts']['origin_place_id']??'')!=='' &&
                    ($x['facts']['origin_place_id']??'')===($y['facts']['origin_place_id']??null);
                if($samePlace)$signals[]='same_source_origin_place_id';
                $routeMatched=($x['facts']['from_station_id']??'')!=='' &&
                    ($x['facts']['to_station_id']??'')!=='' &&
                    ($x['facts']['from_station_id']??'')===($y['facts']['from_station_id']??null) &&
                    ($x['facts']['to_station_id']??'')===($y['facts']['to_station_id']??null);
                if($routeMatched)$signals[]='same_transport_route_stations';
                $sameContent=$x['text_hash']!=='' && $x['text_hash']===$y['text_hash'];
                if($sameContent)$signals[]='same_nonempty_text_fingerprint';
                $score=min(1.0,round(
                    .70*$titleScore + ($locationShared?.12:0) +
                    ($kindShared?.05:0) + ($samePlace?.06:0) +
                    ($routeMatched?.07:0) + ($sameContent?.10:0),3));
                if($score<.59)continue;
                $candidates[]=[
                    'hub_ids'=>[$a,$b],
                    'source_post_ids'=>[$x['source_id'],$y['source_id']],
                    'company_id'=>$x['company'],'source_type'=>$x['type'],'language'=>$x['lang'],
                    'heuristic_similarity_score'=>$score,
                    'evidence'=>$signals,
                    'review_state'=>'needs_human_review',
                    'auto_merge'=>false,
                ];
            }
        }
        usort($candidates,static function($a,$b) {
            $c=$b['heuristic_similarity_score']<=>$a['heuristic_similarity_score'];
            return $c!==0?$c:$a['hub_ids'][0]<=>$b['hub_ids'][0];
        });
        return [
            'contract'=>'mad4b.hub.typed-dedup-evidence.v1',
            'read_only'=>true,'score_is_probability'=>false,'auto_merge'=>false,
            'records_with_descriptions'=>$bodyReady,
            'records_with_wpml_group'=>$sourceTrids,
            'records_with_taxonomy_provenance'=>$taxReady,
            'records_with_typed_identity_facts'=>$factsReady,
            'candidate_pairs_checked'=>$checked,'scan_truncated'=>$truncated,
            'review_candidate_count'=>count($candidates),
            'review_candidates'=>array_slice($candidates,0,self::REVIEW_LIMIT),
        ];
    }
    public static function boot(): void {
        if(class_exists('MAD4B_Hub_Dedup_Intelligence_V1'))
            MAD4B_Hub_Dedup_Intelligence_V1::register_adapter(self::ADAPTER,[__CLASS__,'discover']);
    }
}
add_action('init',['MAD4B_Hub_Dedup_Typed_V1','boot'],35);
