<?php
/**
 * Plugin Name: MAD4B Content Hub Curated Legacy-Demo Decisions
 * Version: 0.1.0
 * Exact source/native decisions; optional provenance-only postmeta labels. No status or content edits.
 *
 * Company-neutral mechanism; this file only contains the two decisions explicitly
 * made by the operator for Tourism Esmerada on Staging.
 */
defined('ABSPATH') || exit;

final class MAD4B_Hub_Curated_Demo_V1 {
    const CONTRACT='mad4b.content-hub.curated-legacy-demo.v1';
    const COMPANY='tourism-almallahgroup';
    const EXACT_DECISIONS=[
        24900 => [
            'native_id'=>60617,
            'native_title'=>'Esmerada Nile Cruise',
            'source_language'=>'en',
            'expected_initial_status'=>'publish',
        ],
        41909 => [
            'native_id'=>60745,
            'native_title'=>'رحلة نيلية إيسميرادا',
            'source_language'=>'ar',
            'expected_initial_status'=>'draft',
        ],
    ];

    public static function classify(array $source, array $candidate): ?array {
        if (!class_exists('MAD4B_Hub_Nile_Publisher_V1') ||
            !MAD4B_Hub_Nile_Publisher_V1::enabled()) {
            return null;
        }
        $sourceId=(int)($source['source_tour_id']??0);
        $decision=self::EXACT_DECISIONS[$sourceId]??null;
        if(!is_array($decision))return null;
        $targetId=(int)($candidate['ID']??0);
        if ($targetId!==$decision['native_id'] ||
            ($source['source_company']??'')!==self::COMPANY ||
            ($source['translation_source']['language_code']??'')!==$decision['source_language'] ||
            ($candidate['post_name']??'')!==($source['source_slug']??null) ||
            ($candidate['post_title']??'')!==$decision['native_title'] ||
            ($source['title']??'')!==$decision['native_title'] ||
            !in_array((string)($candidate['post_status']??''),['publish','draft','pending','private'],true)) {
            // Do not infer demo state from a coincidentally reused numeric ID.
            throw new RuntimeException('Curated legacy-demo identity drifted: '.$sourceId);
        }
        $post=get_post($targetId);
        if(!$post||$post->post_type!=='tours-and-activities') {
            throw new RuntimeException('Curated legacy-demo target post changed type');
        }
        $owner=(string)get_post_meta($targetId,'_mad4b_source_company',true);
        $ownerSource=get_post_meta($targetId,'_mad4b_source_post_id',true);
        if(($owner!==''&&$owner!==self::COMPANY) ||
            ($ownerSource!==''&&(int)$ownerSource!==$sourceId)) {
            throw new RuntimeException('Legacy-demo declaration conflicts with explicit source metadata');
        }
        return [
            'contract'=>self::CONTRACT,
            'classification'=>'owner_declared_legacy_demo',
            'native_post_id'=>$targetId,
            'source_tour_id'=>$sourceId,
            'source_company'=>self::COMPANY,
            'authoritative_content_source'=>'signed_source_tourism',
            'may_merge_with_native'=>false,
            'may_modify_native'=>false,
            'may_publish_native'=>false,
            'may_delete_native'=>false,
            'may_activate_historical_sale'=>false,
            'owner_confirmation_date'=>'2026-10-29',
        ];
    }

    /**
     * Set provenance labels only. This is an explicit operator-initiated,
     * idempotent operation, not an activation hook or background automation.
     * It never changes the post status, terms, language, body or commercial rates.
     */
    public static function apply_native_provenance_labels(bool $dryRun=true): array {
        if (!class_exists('MAD4B_Hub_Nile_Publisher_V1') ||
            !MAD4B_Hub_Nile_Publisher_V1::enabled() ||
            !current_user_can('manage_options')) {
            throw new RuntimeException('Authorized Staging administrator required');
        }
        $snapshot=get_option(MAD4B_Central_Nile_Reference_V1::REGISTRY,[]);
        $tours=(array)($snapshot['nile_cruise_catalog.read']['items']??[]);
        $bySource=[];
        foreach($tours as $tour) {
            if(!is_array($tour))continue;
            $key=(int)($tour['source_tour_id']??0);
            if(isset($bySource[$key]))throw new RuntimeException('Duplicate source tour identity');
            $bySource[$key]=$tour;
        }
        $plans=[];
        foreach(self::EXACT_DECISIONS as $sourceId=>$expected) {
            $source=$bySource[(int)$sourceId]??null;
            $p=get_post((int)$expected['native_id']);
            $archivedEnglish=((int)$p->ID===60617 &&
                $p->post_status==='draft' &&
                get_post_meta((int)$p->ID,'_mad4b_legacy_demo_unpublished_v1',true)==='yes');
            if(!$source||!$p||
               ($p->post_status!==$expected['expected_initial_status']&&!$archivedEnglish))
                throw new RuntimeException('Demo source or native post status has drifted');
            $native=[
                'ID'=>$p->ID,'post_name'=>$p->post_name,
                'post_title'=>$p->post_title,'post_status'=>$p->post_status,
            ];
            $verified=self::classify($source,$native);
            if(!is_array($verified))throw new RuntimeException('Legacy-demo identity was not verified');
            $hub=get_posts([
                'post_type'=>'mad4b_hub_nile','post_status'=>'publish',
                'numberposts'=>50,'fields'=>'ids',
                'meta_key'=>'_mad4b_hub_source_id','meta_value'=>(string)$sourceId,
                'suppress_filters'=>true,
            ]);
            $matched=[];
            foreach($hub as $postId) {
                if(get_post_meta($postId,'_mad4b_hub_source_kind',true)==='nile_tour' &&
                   get_post_meta($postId,'_mad4b_hub_source_company',true)===self::COMPANY)
                    $matched[]=(int)$postId;
            }
            if(count($matched)!==1 ||
                get_post_meta($matched[0],'_mad4b_hub_reuse_policy_state',true)!=='native_legacy_demo_source_authority')
                throw new RuntimeException('Source-authoritative Content Hub identity not found');
            $record=[
                '_mad4b_legacy_demo_classification'=>'owner_declared_legacy_demo',
                '_mad4b_legacy_demo_source_company'=>self::COMPANY,
                '_mad4b_legacy_demo_source_tour_id'=>(string)$sourceId,
                '_mad4b_legacy_demo_authoritative_hub_id'=>(string)$matched[0],
                '_mad4b_legacy_demo_catalog_authority'=>'no',
                '_mad4b_legacy_demo_commerce_authority'=>'no',
                '_mad4b_legacy_demo_dependency_review'=>($p->ID===60617?'booking_form_and_elementor_review':'draft_reference_review'),
            ];
            $changes=0;
            foreach($record as $key=>$value) {
                $current=get_post_meta($p->ID,$key,true);
                if($current!=='' && (string)$current!==(string)$value)
                    throw new RuntimeException('Existing legacy-demo provenance is conflicting');
                if((string)$current!==(string)$value)$changes++;
            }
            $plans[]=[
                'native_post_id'=>(int)$p->ID,'source_tour_id'=>(int)$sourceId,
                'authoritative_hub_id'=>$matched[0],
                'native_post_status_preserved'=>$p->post_status,
                'metadata_changes'=>$changes,'meta'=>$record,
            ];
        }
        if (!$dryRun) {
            foreach($plans as $plan) {
                foreach($plan['meta'] as $key=>$value) {
                    if(!update_post_meta((int)$plan['native_post_id'],$key,$value)) {
                        if((string)get_post_meta((int)$plan['native_post_id'],$key,true)!==(string)$value)
                            throw new RuntimeException('Legacy-demo classification save failed');
                    }
                }
            }
        }
        return [
            'contract'=>self::CONTRACT,
            'action'=>'native_demo_provenance_metadata_only',
            'dry_run'=>$dryRun,
            'records_checked'=>count($plans),
            'metadata_fields_changing'=>array_sum(array_column($plans,'metadata_changes')),
            'records'=>array_map(static fn($row)=>[
                'native_post_id'=>$row['native_post_id'],
                'source_tour_id'=>$row['source_tour_id'],
                'authoritative_hub_id'=>$row['authoritative_hub_id'],
                'native_post_status_preserved'=>$row['native_post_status_preserved'],
                'metadata_changes'=>$row['metadata_changes'],
            ],$plans),
            'native_post_status_modified'=>false,
            'native_post_content_modified'=>false,
            'woocommerce_rate_modified'=>false,
            'external_content_exposure_changed'=>false,
        ];
    }
}
