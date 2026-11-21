<?php
/**
 * Plugin Name: MAD4B Hub Dedup Tourism Nile Specialist
 * Description: Reuse prior Source Reuse Gate canonical taxonomy and legacy-demo evidence; never re-run Nile gate on unrelated content.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Hub_Dedup_Tourism_Nile_V1 {
    const SOURCE='tourism-almallahgroup';
    const ADAPTER='tourism_nile_v1';
    public static function discover(array $context): array {
        $scope=$context['scope']??[];
        if(($scope['company_id']??'')!==''&&$scope['company_id']!==self::SOURCE ||
           ($scope['source_type']??'')!==''&&$scope['source_type']!=='tours-and-activities') {
            return ['applicable'=>false,'reason'=>'source_scope_mismatch'];
        }
        if(!class_exists('MAD4B_Hub_Source_Reuse_Gate_V1')) {
            throw new RuntimeException('Existing canonical gate not available');
        }
        $ids=get_posts(['post_type'=>'mad4b_hub_nile',
            'post_status'=>['publish','private','draft','pending'],
            'numberposts'=>-1,'fields'=>'ids','suppress_filters'=>true]);
        if(!is_array($ids)||count($ids)>1000)
            throw new RuntimeException('Legacy evidence exceeds scan limit');
        $kinds=[];$links=[];$pending=[];$curated=[];$canonicalCount=0;
        $translationGroups=[];$unmatched=0;
        foreach($ids as $id) {
            $id=(int)$id;
            if((string)get_post_meta($id,'_mad4b_hub_source_company',true)!==self::SOURCE)
                continue;
            $kind=(string)get_post_meta($id,'_mad4b_hub_source_kind',true);
            $kinds[$kind]=($kinds[$kind]??0)+1;
            if($kind==='nile_category') {
                if((string)get_post_meta($id,'_mad4b_hub_reuse_policy_state',true)==='existing_canonical_term_linked' &&
                   (int)get_post_meta($id,'_mad4b_hub_target_term_id',true)>0)
                    $canonicalCount++;
                continue;
            }
            if($kind!=='nile_tour')continue;
            $sourceId=(int)get_post_meta($id,'_mad4b_hub_source_id',true);
            $payload=json_decode((string)get_post_meta($id,'_mad4b_hub_source_payload_json',true),true);
            $lang=$payload['translation_source']['language_code']??null;
            if($sourceId<1||!is_string($lang)||$lang==='')throw new RuntimeException('Legacy source identity invalid');
            $trid=(int)get_post_meta($id,'_mad4b_hub_translation_source_trid',true);
            if($trid>0)$translationGroups[$trid][]=$lang;
            $key=self::SOURCE.':tours-and-activities:'.$lang.':'.$sourceId;
            $modern=$context['source_key_index'][$key]??[];
            if(count($modern)===1) {
                $links[]=[
                    'source_key'=>$key,
                    'legacy_hub_post_id'=>$id,
                    'imported_hub_post_id'=>(int)$modern[0],
                    'decision'=>'link_same_source_identity_preserve_legacy',
                    'auto_merge'=>false,
                ];
            } else $unmatched++;
            $policy=(string)get_post_meta($id,'_mad4b_hub_reuse_policy_state',true);
            $native=json_decode((string)get_post_meta($id,'_mad4b_hub_native_candidates_json',true),true);
            if(!is_array($native))$native=[];
            if($native) {
                $record=[
                    'source_id'=>$sourceId,'language'=>$lang,
                    'legacy_hub_post_id'=>$id,
                    'imported_hub_post_id'=>count($modern)===1?(int)$modern[0]:null,
                    'native_candidate_ids'=>array_map('intval',array_values($native)),
                    'existing_gate_decision'=>$policy,
                    'decision'=>'preserve_existing_review',
                    'auto_merge'=>false,
                ];
                if($policy==='native_legacy_demo_source_authority') {
                    $record['decision']='reuse_curated_demo_provenance_only';
                    $curated[]=$record;
                }else $pending[]=$record;
            }
        }
        $translations=0;
        foreach($translationGroups as $languages)
            if(count(array_unique($languages))>1)$translations++;
        return [
            'applicable'=>true,
            'source_company'=>self::SOURCE,
            'authority'=>'MAD4B_Hub_Source_Reuse_Gate_V1',
            'legacy_records_by_kind'=>$kinds,
            'legacy_source_identity_links'=>count($links),
            'unmatched_legacy_tours'=>$unmatched,
            'links_for_review'=>$links,
            'canonical_category_reuse_count'=>$canonicalCount,
            'existing_wpml_multilingual_tour_groups'=>$translations,
            'native_review_holds'=>$pending,
            'native_curated_demo_evidence'=>$curated,
            'note'=>'Provenance links and existing gate decisions only; no post/term/translation mutation.',
            'auto_merge'=>false,
        ];
    }
    public static function boot(): void {
        if(class_exists('MAD4B_Hub_Dedup_Intelligence_V1')) {
            MAD4B_Hub_Dedup_Intelligence_V1::register_adapter(self::ADAPTER,[__CLASS__,'discover']);
        }
    }
}
add_action('init',['MAD4B_Hub_Dedup_Tourism_Nile_V1','boot'],35);
