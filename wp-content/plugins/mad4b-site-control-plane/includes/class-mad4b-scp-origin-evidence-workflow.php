<?php
defined('ABSPATH') || exit;
/**
 * Source-first origin evidence inside the original ContentJob/Research Group lifecycle.
 * A signed, scoped native intake request is required; evidence is not a pickup grant.
 */
final class MAD4B_SCP_Origin_Evidence_Workflow {
    const CONTRACT='mad4b.origin-evidence-workflow.v1';
    public static function process_one(array $issue,array $scope) {
        $req=(array)($issue['content_job_request']??array());
        $site=(string)($scope['site_uuid']??'');
        $token=(string)($req['deduplication_key']??'');
        $id=absint($req['source_post_id']??0);
        $canonical=absint($req['canonical_post_id']??0);
        $job_id=(string)($issue['content_job_id']??'');
        if(!class_exists('MAD4B_Native_Origin_Content_Jobs_V1',false) ||
            !MAD4B_Native_Origin_Content_Jobs_V1::is_admitted() ||
            !class_exists('MAD4B_SCP_Research_Groups') ||
            ($req['site_uuid']??'')!==$site ||
            !preg_match('/^[a-f0-9]{64}$/D',$token) ||
            !$id || !$canonical || !$job_id ||
            get_post_status($id)!=='publish' || get_post_status($canonical)!=='publish' ||
            trim((string)get_post_meta($id,'tour_origin_address',true))!=='') {
            return new WP_Error('mad4b_origin_evidence_source_not_eligible');
        }
        $terms=wp_get_object_terms($id,'destinations',['fields'=>'ids']);
        if(is_wp_error($terms))return $terms;
        $terms=array_map('intval',(array)$terms);sort($terms,SORT_NUMERIC);
        $sha=hash('sha256',wp_json_encode([
            'canonical_id'=>$canonical,'origin_term'=>get_post_meta($id,'tour_origin_location_term',true),
            'destination_terms'=>$terms],JSON_UNESCAPED_SLASHES));
        if(!hash_equals((string)($req['source_sha256']??''),$sha))
            return new WP_Error('mad4b_origin_evidence_source_changed');
        $job=MAD4B_SCP_Content_Jobs::get_job(['job_id'=>$job_id]);
        if(is_wp_error($job))return $job;
        $j=$job['job'];
        $actor=MAD4B_Native_Origin_Content_Jobs_V1::actor()['id'];
        if(($j['state']??'')!=='QUEUED' || ($j['stage']??'')!=='INTAKE' ||
           ($j['created_by_nhi']??'')!==$actor || ($j['site_uuid']??'')!==$site ||
           ($j['content_type']??'')!=='research_observation' ||
           !str_contains((string)($j['subject']??''),'origin-gap-ref:'.$token))
            return new WP_Error('mad4b_origin_evidence_job_scope_invalid');
        $post=get_post($id);
        $segments=[];$snippets=[];
        foreach(['itinerary_item_description','highlight_rows','_description','excerpt'] as $key){
            $raw=get_post_meta($id,$key,true);
            if(is_array($raw))$raw=wp_json_encode($raw);
            if(!is_scalar($raw)||trim((string)$raw)==='')continue;
            $clean=trim(preg_replace('/\s+/u',' ',wp_strip_all_tags((string)$raw)));
            if($clean==='')continue;
            $segments[$key]=mb_substr($clean,0,1500);
            if(preg_match_all('/.{0,90}\b(?:pick[ -]?up|meeting point|on arrival|airport|train station|train terminal|board|embark|marina|depart(?:ing)? from|hotel transfer)\b.{0,120}/iu',$clean,$hits)){
                $snippets[$key]=array_slice(array_map('trim',$hits[0]),0,6);
            }
        }
        $text=implode(' ',array_values($segments));
        $day_one=explode('|',(string)($segments['itinerary_item_description']??''))[0];
        if(stripos($post->post_title,'Nile Cruise')!==false &&
           preg_match('/airport or (?:the )?train (?:terminal|station)/iu',$text))
            $kind='multi_option_cruise_arrival_no_berth';
        elseif(preg_match('/hotel pick[ -]?up/iu',$text))
            $kind='variable_hotel_pickup';
        elseif(preg_match('/Cairo International Airport/iu',$day_one))
            $kind='named_airport_arrival_meeting_point_unverified';
        elseif(preg_match('/arriv(?:e|al).*Cairo.*airport/iu',$day_one))
            $kind='city_airport_arrival_unverified';
        elseif(preg_match('/depart(?:ing)? (?:early )?from Aswan/iu',$text))
            $kind='city_only_departure';
        else $kind='no_exact_pickup_evidence';
        $permalink=get_permalink($id);
        if(!$permalink || !wp_http_validate_url($permalink) ||
           wp_parse_url($permalink,PHP_URL_HOST)!==wp_parse_url(home_url(),PHP_URL_HOST))
            return new WP_Error('mad4b_origin_evidence_perma_url_invalid');
        $group=MAD4B_SCP_Research_Groups::group('origin:tour:'.$canonical,$site,'en');
        if(is_wp_error($group))return $group;
        $record=MAD4B_SCP_Research_Groups::add_record(
            $group['id'],'native_wp_source','en',$permalink,[
            'contract'=>self::CONTRACT,'post_id'=>$id,'canonical_post_id'=>$canonical,
            'site_uuid'=>$site,'job_id'=>$job_id,'source_sha256'=>$sha,
            'deduplication_key'=>$token,'source'=>'published_wordpress_metadata',
            'classification'=>$kind,'source_snippets'=>$snippets,
            'exact_pickup_verified'=>false,'maps_validation_required'=>true,
            'supplier_confirmation_required'=>true,'firecrawl_official_source_pending'=>true,
            'auto_writeback_authorized'=>false,'publication_authorized'=>false
        ]);
        if(is_wp_error($record))return $record;
        $artifact=(string)($record['record_uid']??'');
        if(!$artifact)return new WP_Error('mad4b_origin_evidence_record_missing');
        $start=MAD4B_SCP_Content_Jobs::transition_job([
            'job_id'=>$job_id,'expected_revision'=>(int)$j['job_revision'],
            'state'=>'RUNNING','stage'=>'FACT_QA','artifact_id'=>$artifact,
            'reason'=>'Native first-party evidence captured, independent official/Maps validation still pending.'
        ]);
        if(is_wp_error($start))return $start;
        $running=$start['job']??[];
        if(($running['state']??'')!=='RUNNING')
            return new WP_Error('mad4b_origin_evidence_start_readback_failed');
        $review=MAD4B_SCP_Content_Jobs::transition_job([
            'job_id'=>$job_id,'expected_revision'=>(int)$running['job_revision'],
            'state'=>'WAITING_REVIEW','stage'=>'FACT_QA','artifact_id'=>$artifact,
            'reason'=>'Exact pickup, transfer origin or cruise berth requires official provider and operator evidence.'
        ]);
        if(is_wp_error($review))return $review;
        if(($review['job']['state']??'')!=='WAITING_REVIEW' ||
            ($review['job']['current_artifact_id']??'')!==$artifact)
            return new WP_Error('mad4b_origin_evidence_review_readback_failed');
        return ['contract'=>self::CONTRACT,'job_id'=>$job_id,'record_uid'=>$artifact,
            'classification'=>$kind,'state'=>'WAITING_REVIEW','readback_verified'=>true];
    }
}
