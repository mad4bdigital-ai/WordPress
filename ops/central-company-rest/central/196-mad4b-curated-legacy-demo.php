<?php
/**
 * Plugin Name: MAD4B Content Hub Curated Legacy-Demo Decisions
 * Version: 0.1.0
 * Exact, reviewed source/native pair declarations. NO post edits or deletions.
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
}
