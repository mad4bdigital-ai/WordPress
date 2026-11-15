<?php
/** MAD4B Tour Photo Quality: schema-driven weighted coverage, proposal only. */
defined('ABSPATH') || exit;
trait MAD4B_Tour_Photo_Weighted_Coverage_Trait {
    private static function coverage_policy(int $tour):array {
        $default=[
            'hero'=>['title'=>36,'tour_mode'=>20,'tour_type'=>17,'program'=>12,'itinerary'=>8,'destination'=>5,'related_cpt'=>2],
            'gallery'=>['title'=>8,'tour_mode'=>5,'tour_type'=>5,'program'=>15,'itinerary'=>25,'destination'=>17,'related_cpt'=>15,'related_taxonomy'=>10],
            'budget'=>['relation_types'=>36,'related_objects'=>30,'term_objects'=>42,'media_per_object'=>12,'media_candidates'=>110,'gallery_additions'=>48],
        ];
        $stored=get_option('mad4b_media_weighted_coverage_v1',[]);
        if(is_array($stored))foreach(['hero','gallery','budget'] as $section){
            if(isset($stored[$section])&&is_array($stored[$section]))
                $default[$section]=array_replace($default[$section],$stored[$section]);
        }
        $result=apply_filters('mad4b/tour_photo_quality/weighted_coverage_policy',$default,$tour);
        if(!is_array($result))$result=$default;
        foreach(['hero','gallery'] as $section){
            $incoming=is_array($result[$section]??null)?$result[$section]:[];
            $clean=[];
            foreach($default[$section] as $name=>$fallback){
                $v=$incoming[$name]??$fallback;
                $clean[$name]=is_numeric($v)?max(0,min(100,(int)$v)):$fallback;
            }
            $result[$section]=$clean;
        }
        foreach($default['budget'] as $name=>$fallback){
            $v=$result['budget'][$name]??$fallback;
            $result['budget'][$name]=is_numeric($v)?max(1,min(160,(int)$v)):$fallback;
        }
        return $result;
    }
    private static function coverage_words(string $raw):array {
        $raw=remove_accents(mb_strtolower(html_entity_decode(wp_strip_all_tags($raw),ENT_QUOTES|ENT_HTML5,'UTF-8'),'UTF-8'));
        $words=preg_split('/[^\p{L}\p{N}]+/u',$raw,-1,PREG_SPLIT_NO_EMPTY);
        if(!is_array($words))return [];
        $stop=array_fill_keys(['the','and','with','from','tour','day','days','private','egypt','for','your','that','this','trip','in','of','to','a','an','on','or'],true);
        $out=[];
        foreach(array_slice($words,0,200) as $w)
            if(mb_strlen($w)>=3&&!isset($stop[$w]))$out[$w]=true;
        return array_slice(array_keys($out),0,50);
    }
    private static function coverage_context(int $tour):array {
        $policy=self::coverage_policy($tour);
        $tokens=['title'=>self::coverage_words((string)get_the_title($tour))];
        $mode=(string)get_post_meta($tour,'tour_mode',true);
        if($mode!=='')$tokens['tour_mode']=self::coverage_words($mode);
        $taxonomies=get_object_taxonomies('tours-and-activities','objects');
        $termSources=[];
        foreach((array)$taxonomies as $slug=>$obj){
            if(!is_object($obj)||!$obj->public)continue;
            $terms=wp_get_object_terms($tour,$slug,['fields'=>'all']);
            if(is_wp_error($terms)||!is_array($terms))continue;
            foreach(array_slice($terms,0,35) as $t){
                $bucket=$slug==='programs'?'program':($slug==='destinations'?'destination':($slug==='tour-types'?'tour_type':'related_taxonomy'));
                $tokens[$bucket]=array_values(array_unique(array_merge($tokens[$bucket]??[],self::coverage_words($t->name.' '.$t->slug))));
                $termSources[]=['term_id'=>(int)$t->term_id,'taxonomy'=>$slug,'bucket'=>$bucket,'name'=>$t->name];
            }
        }
        $raw=get_post_meta($tour,'itinerary_item',true);
        $days=[];
        foreach(array_slice((array)$raw,0,25) as $item)if(is_array($item))$days[]=(string)($item['title']??'');
        $tokens['itinerary']=self::coverage_words(implode(' ',$days));
        return ['policy'=>$policy,'tokens'=>$tokens,'terms'=>$termSources,'mode'=>$mode];
    }
    private static function coverage_score(int $id,array $context,string $role,array $source=[]):array {
        $policy=$context['policy'][$role]??[];$origin=self::origin($id);
        if(empty($origin['verified']))return ['score'=>0,'signals'=>[]];
        $root=(int)($origin['origin_attachment_id']??$id);
        $file=(string)get_post_meta($root,'_wp_attached_file',true);
        $filename=pathinfo(wp_basename($file),PATHINFO_FILENAME).' '.get_the_title($root);
        $filenameWords=array_fill_keys(self::coverage_words($filename),true);
        $alt=(string)get_post_meta($root,'_wp_attachment_image_alt',true);
        // Generic cross-topic SEO descriptions alone do not justify a hero match.
        $altWords=self::coverage_words($alt);
        $candidate=$filenameWords;
        if(count($altWords)<=12)
            $candidate+=array_fill_keys($altWords,true);
        $signals=[];$total=0;$available=0;
        foreach($policy as $key=>$weight){
            $words=(array)($context['tokens'][$key]??[]);
            if(!$words||$weight<=0)continue;
            $available+=$weight;$matched=0;
            foreach($words as $w)if(isset($candidate[$w]))$matched++;
            $score=$matched?($weight*min(1,$matched/max(1,min(3,count($words))))):0;
            if($score>0){$signals[$key]=round($score,2);$total+=$score;}
        }
        $sourceBucket=(string)($source['bucket']??'');
        if($sourceBucket!==''&&isset($policy[$sourceBucket])){
            $boost=min(20,$policy[$sourceBucket]);
            $total+=$boost;$signals['registered_relation_'.$sourceBucket]=$boost;
        }
        $headlineToken=array_fill_keys((array)($context['tokens']['title']??[]),true);
        $anchors=array_intersect_key($headlineToken,$filenameWords);
        return ['score'=>min(100,round($total*100/max(1,$available),2)),
            'signals'=>$signals,'hero_title_anchor'=>count($anchors)>0,
            'source_class'=>$sourceBucket,
            'evidence_level'=>'text_and_relationship_context_not_visual_or_rights_proof'];
    }
    private static function coverage_media_refs($raw,int $tour,int $cap):array {
        $stack=is_array($raw)?$raw:[$raw];$ids=[];$checked=0;
        while($stack&&$checked<max(2,$cap*3)&&count($ids)<$cap){
            $item=array_shift($stack);$checked++;
            if(is_array($item)){
                foreach(array_slice($item,0,$cap) as $part)$stack[]=$part;
                continue;
            }
            if(!is_scalar($item))continue;
            $text=trim((string)$item);
            if($text==='')continue;
            foreach(array_slice(explode(',',$text),0,$cap) as $part){
                $part=trim($part);$id=0;
                if(ctype_digit($part))$id=(int)$part;
                elseif(str_starts_with($part,'http')){
                    $host=strtolower((string)wp_parse_url($part,PHP_URL_HOST));
                    $ours=strtolower((string)wp_parse_url(home_url('/'),PHP_URL_HOST));
                    if($host!==$ours)continue;
                    $id=(int)attachment_url_to_postid($part);
                }
                if($id>0&&get_post_type($id)==='attachment')$ids[$id]=true;
                if(count($ids)>=$cap)break;
            }
        }
        return array_keys($ids);
    }
    private static function coverage_media_fields(string $kind,int $id,int $tour,int $limit):array {
        $meta=$kind==='term'?get_term_meta($id):get_post_meta($id);
        $result=[];$used=0;
        foreach(array_keys((array)$meta) as $key){
            if(!is_string($key)||!preg_match('/(?:image|gellery|gallery|photo|media|portrait|cover|thumbnail|featured)/i',$key))continue;
            // Avoid heavy serialized logs, debug dumps or tracking records.
            if(preg_match('/(?:log|audit|manifest|history|policy|receipt|cache|fingerprint|status|source_sha)/i',$key))continue;
            $value=$kind==='term'?get_term_meta($id,$key,true):get_post_meta($id,$key,true);
            foreach(self::coverage_media_refs($value,$tour,max(1,$limit-$used)) as $aid){
                $result[$aid]=['field'=>$key,'owner_type'=>$kind,'owner_id'=>$id];
                $used=count($result);if($used>=$limit)break 2;
            }
        }
        return $result;
    }
    /** Discover actual connected CPT/Taxonomy image sources via native registries.
     * Bounded candidate scan; independent of site-specific IDs and post types.
     */
    /** Resolve stay options from the existing Rate assignment service, not a
     * synthetic Tour->Property link. A rate option is never a guaranteed ship.
     */
    private static function coverage_property_subject_hint(int $property,int $image):bool {
        $skip=array_fill_keys(['hotel','cruise','nile','egypt','cairo','luxor',
          'aswan','resort','boat','ship','pyramids','golf','luxury'],true);
        $tokens=array_values(array_filter(self::coverage_words(get_the_title($property)),
            static fn($w)=>!isset($skip[$w])));
        if(!$tokens)return false;
        $origin=self::origin($image);
        if(empty($origin['verified']))return false;
        $id=(int)($origin['origin_attachment_id']??$image);
        $path=(string)get_post_meta($id,'_wp_attached_file',true);
        $label=pathinfo(wp_basename($path),PATHINFO_FILENAME).' '.get_the_title($id);
        $words=array_fill_keys(self::coverage_words($label),true);
        $matches=0;
        foreach($tokens as $token)if(isset($words[$token]))$matches++;
        return (bool)apply_filters('mad4b/tour_photo_quality/property_photo_match',
            $matches>=min(2,count($tokens)),$property,$image,$origin);
    }
    private static function coverage_rate_property_sources(int $tour,array &$context):array {
        $report=['contract'=>'mad4b.rate-property-photo-coverage.v1',
            'resolver'=>'shared_product_assignment_legacy_links',
            'rate_property_options'=>0,'options_with_media'=>0,
            'options_missing_media'=>[],'rate_ids'=>[],
            'media_candidates'=>0,'state'=>'not_checked'];
        $sources=[];
        if(!function_exists('shared_product_assignment_legacy_links')){
            $report['state']='native_assignment_provider_unavailable';
            return ['sources'=>$sources,'report'=>$report];
        }
        $links=shared_product_assignment_legacy_links($tour);
        if(is_wp_error($links)){
            $report['state']='native_assignment_'.$links->get_error_code();
            return ['sources'=>$sources,'report'=>$report];
        }
        if(!is_array($links)){
            $report['state']='native_assignment_invalid';
            return ['sources'=>$sources,'report'=>$report];
        }
        $cap=min(40,(int)($context['policy']['budget']['related_objects']??30));
        $limit=(int)($context['policy']['budget']['media_per_object']??12);
        $rateIds=[];$seen=0;
        foreach($links as $rawId=>$row){
            if($seen>=$cap){$report['state']='partial_bounded_property_options';break;}
            $id=absint($rawId);
            if(!$id||get_post_type($id)!=='properties'||get_post_status($id)!=='publish')continue;
            if(get_post_field('post_password',$id)!==''||
                get_post_meta($id,'_booking_experience_qa_pricing_fixture',true)!=='')continue;
            if(!is_array($row))continue;
            $rates=[];$tiers=[];
            foreach(array_slice((array)($row['source_rate_ids']??[]),0,80) as $raw){
                $rate=absint($raw);
                if(!$rate||get_post_type($rate)!=='tour-rates'||get_post_status($rate)!=='publish')continue;
                if((string)get_post_meta($rate,'has_properties',true)!=='1')continue;
                $rateTour=absint(get_post_meta($rate,'related_tour_id',true));
                if($rateTour!==$tour&&
                   (!function_exists('shared_product_assignment_tour_siblings')||
                    !in_array($rateTour,shared_product_assignment_tour_siblings($tour),true)))continue;
                $rates[$rate]=true;
                $tier=absint(get_post_meta($rate,'related_package_term_id',true));
                if($tier&&get_term($tier,'package_levels') instanceof WP_Term)$tiers[$tier]=true;
            }
            if(!$rates)continue;
            $seen++;
            $report['rate_property_options']++;
            foreach(array_keys($rates) as $rid)$rateIds[$rid]=true;
            $metadata=self::coverage_media_fields('post',$id,$tour,$limit);
            $thumb=(int)get_post_thumbnail_id($id);
            if($thumb>0&&get_post_type($thumb)==='attachment'&&count($metadata)<$limit)
                $metadata[$thumb]=['field'=>'_thumbnail_id','owner_type'=>'post','owner_id'=>$id];
            $title=get_the_title($id);
            $types=wp_get_object_terms($id,'property-types',['fields'=>'slugs']);
            $propertyTypes=is_wp_error($types)?[]:array_values((array)$types);
            $propertyRole=array_intersect($propertyTypes,['nile-cruise','dahabiya-nile-cruise'])
                ?'nile_vessel':'accommodation_property';
            $rateOptions=[];
            foreach(array_keys($rates) as $rid)$rateOptions[]=[
                'rate_id'=>$rid,'package_term_id'=>absint(get_post_meta($rid,'related_package_term_id',true)),
                'assignment_mode'=>(string)get_post_meta($rid,'accommodation_assignment_mode',true),
                'multi_stage'=>(string)get_post_meta($rid,'has_multiple_accommodation_stages',true)==='1',
                'stop_sale'=>(string)get_post_meta($rid,'stop_sale',true)==='1',
                'published_is_not_booking_availability'=>true];
            $unverified=count($metadata);
            $matched=[];
            foreach($metadata as $aid=>$info)
                if(self::coverage_property_subject_hint($id,(int)$aid))$matched[$aid]=$info;
            $metadata=$matched;
            if(!$metadata){
                $report['options_missing_media'][]=['property_id'=>$id,
                    'title'=>$title,'source_rate_ids'=>array_keys($rates),
                    'rate_options'=>$rateOptions,'property_role'=>$propertyRole,
                    'unverified_media_count'=>$unverified,
                    'reason'=>$unverified?'property_media_subject_unverified':'property_no_media',
                    'package_term_ids'=>array_keys($tiers),
                    'next_action'=>'populate_exact_property_media_with_reviewed_source_photos'];
                continue;
            }
            $report['options_with_media']++;
            $context['tokens']['related_cpt']=array_values(array_unique(array_merge(
                $context['tokens']['related_cpt']??[],self::coverage_words($title))));
            $key='rate_property:'.$id;
            foreach($metadata as $aid=>$media){
                $sources[$key][$aid]=[
                    'bucket'=>'related_cpt','owner'=>$key,'owner_title'=>$title,
                    'field'=>$media['field'],'source_evidence'=>'native_tour_rate_property_media_review_only',
                    'rate_property_id'=>$id,'source_rate_ids'=>array_keys($rates),
                    'package_term_ids'=>array_keys($tiers),
                    'rate_options'=>$rateOptions,'property_role'=>$propertyRole,
                    'photo_label_matches_property_name_only'=>true,
                    'available_accommodation_option_not_guaranteed_vessel'=>true];
                $report['media_candidates']++;
            }
        }
        $report['rate_ids']=array_keys($rateIds);
        if($report['state']==='not_checked')$report['state']=$report['rate_property_options']
            ?($report['options_missing_media']?'rate_properties_need_media':'rate_properties_have_media')
            :'no_published_rate_property_options';
        return ['sources'=>$sources,'report'=>$report];
    }
    private static function coverage_registered_sources(int $tour,array &$context,int $page=0):array {
        $budget=$context['policy']['budget'];$maxObjects=$budget['related_objects'];
        $maxTerms=$budget['term_objects'];$maxPer=$budget['media_per_object'];
        $rate=self::coverage_rate_property_sources($tour,$context);
        $sources=$rate['sources'];$partial=[];$nextPage=false;
        $sourceCounts=['taxonomy'=>0,'cpt'=>0,'itinerary'=>0,
            'rate_property'=>count($sources)];
        $page=max(0,min(1000,$page));
        $tax=(array)($context['terms']??[]);
        $termStart=$page*$maxTerms;
        if(count($tax)>$termStart+$maxTerms){$partial[]='terms_more_pages';$nextPage=true;}
        foreach(array_slice($tax,$termStart,$maxTerms) as $t){
            $id=(int)$t['term_id'];$taxName=(string)$t['taxonomy'];
            $media=self::coverage_media_fields('term',$id,$tour,$maxPer);
            if(!$media)continue;
            $key='taxonomy:'.$taxName.':'.$id;
            foreach($media as $aid=>$info)$sources[$key][$aid]=['bucket'=>$t['bucket'],
                'owner'=>$key,'owner_title'=>$t['name'],'field'=>$info['field'],
                'source_evidence'=>'registered_taxonomy_media_review_only'];
            $sourceCounts['taxonomy']++;
        }
        $manager=function_exists('jet_engine')?jet_engine()->relations:null;
        $registry=is_object($manager)&&method_exists($manager,'get_active_relations')
            ?(array)$manager->get_active_relations():[];
        $examined=0;$linked=0;$relEligible=0;
        $skippedLinked=0;$startLinked=$page*$maxObjects;
        $relStart=$page*$budget['relation_types'];
        foreach($registry as $id=>$relation){
            if(!is_object($relation)||!method_exists($relation,'get_args'))continue;
            $args=$relation->get_args();
            if(!is_array($args))continue;
            $child=(string)($args['child_object']??'');
            if($child!=='posts::tours-and-activities')continue;
            $parent=(string)($args['parent_object']??'');
            if(!str_starts_with($parent,'posts::'))continue;
            $pt=substr($parent,7);
            if($pt==='tours-and-activities'||$pt==='properties')continue;
            $object=get_post_type_object($pt);
            if(!$object||empty($object->public))continue;
            $relEligible++;
            if($relEligible<=$relStart)continue;
            $examined++;
            if($examined>$budget['relation_types']){$partial[]='relation_types_more_pages';$nextPage=true;break;}
            if(!method_exists($relation,'get_parents'))continue;
            $parents=(array)$relation->get_parents($tour,'ids');
            foreach($parents as $linkedId){
                if($skippedLinked<$startLinked){$skippedLinked++;continue;}
                $linkedId=(int)$linkedId;
                if($linkedId<=0||get_post_type($linkedId)!==$pt||get_post_status($linkedId)!=='publish')continue;
                if(++$linked>$maxObjects){$partial[]='related_objects_more_pages';$nextPage=true;break 2;}
                $media=self::coverage_media_fields('post',$linkedId,$tour,$maxPer);
                if(!$media)continue;
                $key='cpt:'.$pt.':'.$linkedId;
                foreach($media as $aid=>$info)$sources[$key][$aid]=['bucket'=>'related_cpt',
                    'owner'=>$key,'owner_title'=>get_the_title($linkedId),'field'=>$info['field'],
                    'source_evidence'=>'registered_cpt_relation_media_review_only'];
                $context['tokens']['related_cpt']=array_values(array_unique(array_merge(
                    $context['tokens']['related_cpt']??[],self::coverage_words(get_the_title($linkedId)))));
                $sourceCounts['cpt']++;
            }
        }
        if(class_exists('MAD4B_Tour_Story_Gallery_V1',false)){
            $story=MAD4B_Tour_Story_Gallery_V1::build($tour);
            foreach(array_slice((array)($story['sections']??[]),0,$maxTerms) as $section){
                $label=(string)($section['title']??'');$key='itinerary:'.md5($label);
                foreach(array_slice((array)($section['images']??[]),0,$maxPer) as $image){
                    $aid=(int)($image['id']??0);if($aid<1)continue;
                    $sources[$key][$aid]=['bucket'=>'itinerary','owner'=>$key,
                        'owner_title'=>$label,'field'=>'story_gallery',
                        'source_evidence'=>'verified_itinerary_story_media_review_only'];
                }
                if(!empty($sources[$key]))$sourceCounts['itinerary']++;
            }
        }
        return ['sources'=>$sources,'source_counts'=>$sourceCounts,'partial_reasons'=>array_values(array_unique($partial)),
            'relation_types_examined'=>$examined,'related_objects_examined'=>$linked,
            'page'=>$page,'next_page'=>$nextPage?$page+1:null,
            'rate_property_coverage'=>$rate['report']];
    }
    private static function coverage_candidates(int $tour,int $page=0):array {
        $policy=self::coverage_policy($tour);
        $page=max(0,min(1000,$page));
        $changed=function_exists('wp_cache_get_last_changed')
            ?wp_cache_get_last_changed('posts').'|'.wp_cache_get_last_changed('terms'):'';
        $cacheKey='rate-property-v2-coverage_'.hash('sha256',$tour.'|'.$page.'|'.self::state_sha($tour).'|'.
            (string)get_post_field('post_modified_gmt',$tour).'|'.
            wp_json_encode($policy).'|'.$changed);
        $cached=wp_cache_get($cacheKey,'mad4b_tour_photo_quality');
        if(is_array($cached)&&isset($cached['diagnostics']['schema_driven'])){
            $cached['diagnostics']['cache_hit']=true;
            return $cached;
        }
        $context=self::coverage_context($tour);$policy=$context['policy'];
        $registered=self::coverage_registered_sources($tour,$context,$page);
        $groups=[];$seenIds=[];$diagnostics=[];
        foreach($registered['sources'] as $group=>$photos){
            foreach($photos as $id=>$source){
                $id=(int)$id;$origin=self::origin($id);
                if(empty($origin['verified'])||self::provenance($id)['status']==='synthetic_confirmed')continue;
                $original=(string)($origin['origin_sha256']??'');
                if(!preg_match('/^[a-f0-9]{64}$/D',$original))continue;
                $dimensions=wp_get_attachment_metadata($id);
                $w=(int)($dimensions['width']??0);$h=(int)($dimensions['height']??0);
                if($w<420||$h<280)continue;
                $rank=self::coverage_score($id,$context,'gallery',$source);
                $heroRank=self::coverage_score($id,$context,'hero',$source);
                $item=['id'=>$id,'origin_sha256'=>$original,'gallery_score'=>$rank['score'],
                    'hero_score'=>$heroRank['score'],
                    'hero_title_anchor'=>$heroRank['hero_title_anchor'],
                    'score_evidence'=>$rank['signals'],'source'=>$source['source_evidence'],
                    'coverage_owner'=>$group,'coverage_title'=>$source['owner_title'],
                    'source_field'=>$source['field'],
                    'source_rate_ids'=>$source['source_rate_ids']??[],
                    'rate_property_id'=>$source['rate_property_id']??null,
                    'package_term_ids'=>$source['package_term_ids']??[],
                    'rate_options'=>$source['rate_options']??[],
                    'property_role'=>$source['property_role']??'',
                    'available_accommodation_option_not_guaranteed_vessel'=>
                        $source['available_accommodation_option_not_guaranteed_vessel']??false,
                    'needs_editorial_review'=>true,
                    'rights_verified'=>false,'visual_verified'=>false,
                    'auto_apply_authorized'=>false];
                $groups[$group][$original]=$item;
                $seenIds[$id]=true;
            }
        }
        foreach($groups as &$items){
            uasort($items,static fn($a,$b)=>$b['gallery_score']<=>$a['gallery_score']);
            $items=array_values($items);
        }unset($items);
        $ordered=[];$seenOrigins=[];$cap=$policy['budget']['media_candidates'];
        // Round-robin across actual related chapters; prevents a large property
        // gallery from drowning out all other destinations or experiences.
        while(count($ordered)<$cap){
            $changed=false;
            foreach($groups as &$items){
                if(!$items)continue;
                $next=array_shift($items);$changed=true;
                if(isset($seenOrigins[$next['origin_sha256']]))continue;
                $seenOrigins[$next['origin_sha256']]=true;$ordered[]=$next;
                if(count($ordered)>=$cap)break;
            }unset($items);
            if(!$changed)break;
        }
        $active=[];
        foreach($context['tokens'] as $key=>$words)if($words)$active[]=$key;
        $diagnostics=['candidate_count'=>count($ordered),'page'=>$page,'next_page'=>$registered['next_page'],
            'active_data_sources'=>$active,'weight_renormalization_on_missing_data'=>true,
            'coverage_source_counts'=>$registered['source_counts'],
            'partial_reasons'=>$registered['partial_reasons'],'relation_types_examined'=>$registered['relation_types_examined'],
            'related_objects_examined'=>$registered['related_objects_examined'],
            'rate_property_coverage'=>$registered['rate_property_coverage'],
            'max_media_candidates'=>$cap,'limit_reached'=>count($ordered)>=$cap,
            'schema_driven'=>true,'cross_tour_media_reuse_allowed'=>true,
            'rights_and_visual_review_required'=>true];
        $result=['context'=>$context,'items'=>$ordered,'diagnostics'=>$diagnostics];
        wp_cache_set($cacheKey,$result,'mad4b_tour_photo_quality',300);
        return $result;
    }
    /** Read-only page endpoint. Continuation does not truncate existing gallery. */
    public static function coverage_page(int $tour,int $page=0):array {
        if(get_post_type($tour)!=='tours-and-activities')return self::fail('tour_missing');
        $r=self::coverage_candidates($tour,$page);
        return ['contract'=>'mad4b.tour-photo-weighted-coverage.v1',
            'post_id'=>$tour,'mode'=>'proposal_only_no_automatic_writes',
            'policy'=>$r['context']['policy'],'diagnostics'=>$r['diagnostics'],
            'items'=>$r['items'],'visual_and_rights_review_required'=>true];
    }
}
