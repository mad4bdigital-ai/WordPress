<?php
/**
 * MAD4B stay editorial post publication v1
 *
 * Publish only existing verified Units and their 8 Draft Properties.
 * No catalog UI, catalog option, pricing, rates, inventory, checkout changes.
 * The old properties CPT is publicly queryable; separate catalog behavior is
 * intentionally neither activated nor changed by this script.
 *
 * CLI modes: preflight | apply | rollback-preview | rollback
 * Phases: parents | units
 * Apply / rollback require owner approval and exact target IDs.
 */
if (!defined('ABSPATH') || !defined('WP_CLI') || !WP_CLI) {
    throw new RuntimeException('WP-CLI only');
}
if (rtrim((string)home_url(), '/') !== 'https://wayfatours.com') {
    WP_CLI::error('Only intended Wayfatours Production is authorized');
}
const MAD4B_STAY_PUBLISH_PLAN = 'mad4b_stay_editorial_publish_v1';
const MAD4B_STAY_PUBLISH_RECEIPT = '_mad4b_stay_editorial_published_v1';
const MAD4B_STAY_PUBLISH_LOCK = 'mad4b_stay_editorial_publish_lock_v1';
const MAD4B_STAY_VALID_DRAFT_PARENT_IDS = [70732,70733,70734,70735,70736,70737,70738,70739];
const MAD4B_STAY_INVALID_PROPERTY = 36260;
const MAD4B_STAY_EXPECTED_UNIT_COUNT = 131;

function mad4b_stay_publish_preflight(): array {
    global $wpdb;
    $linkRows = $wpdb->get_results(
        "SELECT child_object_id,parent_object_id FROM {$wpdb->prefix}jet_rel_default WHERE rel_id=223",
        ARRAY_A
    );
    $parents = [];
    foreach ($linkRows as $link) {
        $id=(int)$link['child_object_id'];
        $parents[$id][]=(int)$link['parent_object_id'];
    }
    $ids = get_posts([
        'post_type'=>'units','post_status'=>['draft','publish'],
        'posts_per_page'=>-1,'fields'=>'ids','suppress_filters'=>true,
    ]);
    $targetUnits = [];
    $actualParents = [];
    $skipped = ['qa'=>[], 'invalid_import'=>[]];
    $errors = [];
    foreach ($ids as $raw) {
        $id = (int)$raw;
        $links = $parents[$id] ?? [];
        if (count($links)!==1) {
            $errors[]='relation_cardinality_'.$id;
            continue;
        }
        $pid = (int)$links[0];
        $parent = get_post($pid);
        if (!$parent || $parent->post_type!=='properties') {
            $errors[]='missing_property_'.$id;
            continue;
        }
        if ($pid===MAD4B_STAY_INVALID_PROPERTY) {
            $skipped['invalid_import'][]=$id;
            continue;
        }
        $title = html_entity_decode((string)get_the_title($id),ENT_QUOTES,'UTF-8');
        $parentTitle=html_entity_decode((string)get_the_title($pid),ENT_QUOTES,'UTF-8');
        if (preg_match('/QA\s*ONLY|fixture|demo|test/i',$title.' '.$parentTitle) ||
            (string)get_post_meta($id,'_mad4b_is_test_data',true)==='1') {
            $skipped['qa'][]=$id;
            continue;
        }
        if (get_post_status($pid)!=='publish' && get_post_status($pid)!=='draft') {
            $errors[]='parent_status_invalid_'.$pid;
            continue;
        }
        if (get_post_status($id)!=='draft' && get_post_status($id)!=='publish') {
            $errors[]='unit_status_invalid_'.$id;
            continue;
        }
        $thumbnail = (int)get_post_thumbnail_id($id);
        $gallery = get_post_meta($id,'unit_gellery',true);
        if (!$thumbnail || !is_file((string)get_attached_file($thumbnail)) ||
            (string)$gallery==='') {
            $errors[]='media_not_ready_'.$id;
            continue;
        }
        $standard=get_post_meta($id,'_mad4b_unit_photo_curation_v1',true);
        $relaxed=get_post_meta($id,'_mad4b_stay_visual_coverage_v1',true);
        if (!is_array($standard) && !is_array($relaxed)) {
            $errors[]='missing_photo_provenance_'.$id;
            continue;
        }
        if (is_array($relaxed) && (
            (int)($relaxed['attachment_id']??0)!==$thumbnail ||
            ($relaxed['exact_room_match_unverified']??false)!==true)) {
            $errors[]='relaxed_visual_contract_mismatch_'.$id;
            continue;
        }
        $targetUnits[]=$id;
        $actualParents[$pid]=true;
    }
    $targetUnits=array_values(array_unique($targetUnits));
    sort($targetUnits);
    $parentIDs=array_map('intval',array_keys($actualParents));
    sort($parentIDs);
    $newParents=MAD4B_STAY_VALID_DRAFT_PARENT_IDS;
    sort($newParents);
    foreach ($newParents as $pid) {
        if (!in_array($pid,$parentIDs,true))$errors[]='expected_parent_without_units_'.$pid;
        if (!in_array(get_post_status($pid),['draft','publish'],true))$errors[]='expected_parent_invalid_status_'.$pid;
    }
    if (count($targetUnits)!==MAD4B_STAY_EXPECTED_UNIT_COUNT) {
        $errors[]='unexpected_unit_count_'.count($targetUnits);
    }
    if (count($parentIDs)!==17)$errors[]='unexpected_parent_count_'.count($parentIDs);
    if (count($skipped['qa'])!==14)$errors[]='unexpected_qa_count';
    if (count($skipped['invalid_import'])!==3)$errors[]='unexpected_import_count';
    foreach (array_merge([MAD4B_STAY_INVALID_PROPERTY],[37553,37554,37555]) as $id) {
        if (get_post_status($id)!=='draft')$errors[]='quarantined_item_not_draft_'.$id;
    }
    if ((int)(wp_count_posts('property-rates')->publish??0)!==0) {
        $errors[]='published_property_rates_must_remain_zero';
    }
    $newPublished=[];
    foreach ($newParents as $id)if (get_post_status($id)==='publish')$newPublished[]=$id;
    $alreadyPublishedUnits=[];
    foreach ($targetUnits as $id)if (get_post_status($id)==='publish')$alreadyPublishedUnits[]=$id;
    return [
        'site'=>home_url(),'expected_new_parent_ids'=>$newParents,
        'eligible_parent_ids'=>$parentIDs,'eligible_unit_ids'=>$targetUnits,
        'eligible_unit_count'=>count($targetUnits),
        'qa_excluded_ids'=>$skipped['qa'],
        'invalid_import_excluded_ids'=>$skipped['invalid_import'],
        'new_parent_ids_already_published'=>$newPublished,
        'unit_ids_already_published'=>$alreadyPublishedUnits,
        'errors'=>$errors,
        'catalog_activation_performed'=>false,
        'prices_rates_inventory_checkout_unchanged'=>true,
    ];
}

$mode=(string)(getenv('MAD4B_STAY_PUBLISH_MODE')?:'preflight');
$phase=(string)(getenv('MAD4B_STAY_PUBLISH_PHASE')?:'parents');
if (!in_array($mode,['preflight','apply','rollback-preview','rollback'],true) ||
    !in_array($phase,['parents','units'],true)) {
    WP_CLI::error('invalid_mode_or_phase');
}
$report=mad4b_stay_publish_preflight();
if ($report['errors'])WP_CLI::error(wp_json_encode($report));
$targets=$phase==='parents'?$report['expected_new_parent_ids']:$report['eligible_unit_ids'];
$selection=trim((string)getenv('MAD4B_STAY_PUBLISH_IDS'));
$selected=$selection===''?[]:array_values(array_unique(array_map('intval',explode(',',$selection))));
if ($mode!=='preflight' && !$selected)WP_CLI::error('explicit_ID_selection_required');
if (array_diff($selected,$targets))WP_CLI::error('unapproved_ID_selection');
if ($mode==='preflight') {
    WP_CLI::line(wp_json_encode([
        'mode'=>$mode,'phase'=>$phase,'target_count'=>count($targets),
        'currently_published'=>$phase==='parents'
            ?$report['new_parent_ids_already_published']
            :$report['unit_ids_already_published'],
        'qa_excluded_count'=>count($report['qa_excluded_ids']),
        'invalid_import_excluded_count'=>count($report['invalid_import_excluded_ids']),
        'catalog_activation_performed'=>false,
        'unit_preview'=>array_slice($report['eligible_unit_ids'],0,10)
    ],JSON_UNESCAPED_SLASHES));
    return;
}
if (in_array($mode,['apply','rollback'],true) &&
    getenv('MAD4B_STAY_PUBLISH_OWNER_APPROVED')!=='1') {
    WP_CLI::error('explicit_owner_approval_required');
}
if ($mode==='apply' && $phase==='units') {
    foreach (MAD4B_STAY_VALID_DRAFT_PARENT_IDS as $pid) {
        if (get_post_status($pid)!=='publish' ||
            !is_array(get_post_meta($pid,MAD4B_STAY_PUBLISH_RECEIPT,true))) {
            WP_CLI::error('publish_eligible_properties_before_units_'.$pid);
        }
    }
}
$plan=get_option(MAD4B_STAY_PUBLISH_PLAN,false);
if ($mode==='apply' && !$plan) {
    $original=[];
    foreach (array_merge($report['expected_new_parent_ids'],$report['eligible_unit_ids']) as $id) {
        $original[$id]=[
            'post_status'=>get_post_status($id),
            'post_modified_gmt'=>get_post_field('post_modified_gmt',$id),
        ];
    }
    if (!add_option(MAD4B_STAY_PUBLISH_PLAN,[
        'schema'=>'mad4b.stay-editorial-publication.v1',
        'property_ids'=>$report['expected_new_parent_ids'],
        'unit_ids'=>$report['eligible_unit_ids'],
        'original'=>$original,
        'catalog_activated'=>false,
        'owner_approved'=>true,
        'source'=>'wayfatours.production.existing_cpts',
        'created_at'=>gmdate('c')
    ],'','no'))WP_CLI::error('cannot_create_plan');
    $plan=get_option(MAD4B_STAY_PUBLISH_PLAN);
}
if (!is_array($plan) ||
    ($plan['schema']??'')!=='mad4b.stay-editorial-publication.v1' ||
    ($plan['unit_ids']??null)!==$report['eligible_unit_ids'] ||
    ($plan['property_ids']??null)!==$report['expected_new_parent_ids']) {
    WP_CLI::error('plan_identity_or_source_changed');
}
if (in_array($mode,['rollback','rollback-preview'],true)) {
    $selected=array_reverse($selected);
}
$results=[];
if (in_array($mode,['apply','rollback'],true) &&
    !add_option(MAD4B_STAY_PUBLISH_LOCK,(string)time(),'','no')) {
    WP_CLI::error('concurrent_editorial_publish_job');
}
try {
    foreach ($selected as $id) {
        $original=$plan['original'][$id]??null;
        $previous=get_post_meta($id,MAD4B_STAY_PUBLISH_RECEIPT,true);
        if (!is_array($original) || ($original['post_status']??'')!=='draft') {
            $results[]=['id'=>$id,'status'=>'original_status_not_draft'];
            continue;
        }
        if (in_array($mode,['rollback','rollback-preview'],true)) {
            if (!is_array($previous) || ($previous['schema']??'')!=='mad4b.stay-editorial-publication.v1' ||
                get_post_status($id)!=='publish') {
                $results[]=['id'=>$id,'status'=>'not_published_by_this_operation'];
                continue;
            }
            if ($mode==='rollback-preview') {
                $results[]=['id'=>$id,'status'=>'rollback_eligible'];
                continue;
            }
            $modified=(string)get_post_field('post_modified_gmt',$id);
            if ($modified!==(string)($previous['post_modified_gmt_after']??'')) {
                $results[]=['id'=>$id,'status'=>'modified_since_publish_rollback_blocked'];
                continue;
            }
            $done=wp_update_post(['ID'=>$id,'post_status'=>'draft'],true);
            if (is_wp_error($done) || get_post_status($id)!=='draft') {
                $results[]=['id'=>$id,'status'=>'rollback_update_failed'];
            } else {
                delete_post_meta($id,MAD4B_STAY_PUBLISH_RECEIPT);
                $results[]=['id'=>$id,'status'=>'returned_to_draft'];
            }
            continue;
        }
        if (is_array($previous) && get_post_status($id)==='publish') {
            $results[]=['id'=>$id,'status'=>'already_published_by_this_operation'];
            continue;
        }
        if (get_post_status($id)!=='draft' || $previous) {
            $results[]=['id'=>$id,'status'=>'not_clean_draft'];
            continue;
        }
        $receipt=[
            'schema'=>'mad4b.stay-editorial-publication.v1',
            'source_type'=>$phase,'catalog_activated'=>false,
            'original_status'=>'draft',
            'post_modified_gmt_before'=>$original['post_modified_gmt'],
            'published_at'=>gmdate('c')
        ];
        if (!add_post_meta($id,MAD4B_STAY_PUBLISH_RECEIPT,$receipt,true)) {
            $results[]=['id'=>$id,'status'=>'receipt_insert_failed'];
            continue;
        }
        $done=wp_update_post(['ID'=>$id,'post_status'=>'publish'],true);
        if (is_wp_error($done)||get_post_status($id)!=='publish') {
            delete_post_meta($id,MAD4B_STAY_PUBLISH_RECEIPT);
            $results[]=['id'=>$id,'status'=>'publish_update_failed'];
        } else {
            $receipt['post_modified_gmt_after']=(string)get_post_field('post_modified_gmt',$id);
            update_post_meta($id,MAD4B_STAY_PUBLISH_RECEIPT,$receipt);
            $results[]=['id'=>$id,'status'=>'editorially_published'];
        }
    }
} finally {
    if (in_array($mode,['apply','rollback'],true))delete_option(MAD4B_STAY_PUBLISH_LOCK);
}
WP_CLI::line(wp_json_encode([
    'mode'=>$mode,'phase'=>$phase,'results'=>$results,
    'applied'=>count(array_filter($results,static function($r){
        return ($r['status']??'')==='editorially_published';
    })),
    'catalog_activation_performed'=>false,
],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
