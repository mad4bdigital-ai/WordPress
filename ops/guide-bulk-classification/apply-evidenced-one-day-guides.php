<?php
/**
 * Exact source-backed one-day guided tour acceptance.
 * Dry run by default; live only if MAD4B_GUIDE_APPROVE_HIGH=1.
 * Explicitly delegated chat operator action, not fake browser approval.
 */
if (!defined('ABSPATH') || !defined('WP_CLI')) exit(1);
wp_set_current_user(1);
$bulk='MAD4B_Guide_Bulk_Classification_V1';
$core='MAD4B_Guide_Service_Composition_V1';
if (!class_exists($bulk,false)||!$bulk::permitted())WP_CLI::error('No permitted Bulk Guide operator');
$ids=[60451,60455,60457,60461];
$apply=getenv('MAD4B_GUIDE_APPROVE_HIGH')==='1';
$beforeRates=wp_count_posts('tour-rates');
$snapshot=['contract'=>'mad4b.guide-bulk-source-approval-backup.v1',
    'site'=>home_url(),'at'=>gmdate('c'),'dry_run'=>!$apply,
    'selected_ids'=>$ids,'rate_count_before'=>(array)$beforeRates,
    'sources'=>[]];
foreach ($ids as $id) {
    if (get_post_type($id)!=='tours-and-activities' ||
        get_post_status($id)!=='publish' ||
        $core::canonical($id,'tours-and-activities')!==$id ||
        !current_user_can('edit_post',$id)) WP_CLI::error('Tour invalid '.$id);
    $source=$core::source($id);
    $review=$bulk::detail($id);
    if (is_wp_error($source)||is_wp_error($review) ||
        count($source['days'])!==1 || $review['high_new_candidates']!==1 ||
        $review['revision']!==0 ||
        $source['day_unit_basis']!=='explicit_single_day_duration_taxonomy') {
        WP_CLI::error('Cannot certify selected Guide Tour '.$id);
    }
    $candidate=$review['days'][0];
    if ($candidate['key']!=='day-1' ||
        $candidate['confidence']!=='high' ||
        $candidate['suggested_status']!=='full_day' ||
        $candidate['reason']!=='guide_and_guided_visit_included_with_one_day_duration') {
        WP_CLI::error('Guide evidence incomplete '.$id);
    }
    $langs=wp_get_post_terms($id,'guide-languages',['fields'=>'slugs']);
    if (is_wp_error($langs)||!in_array('english',$langs,true)) {
        WP_CLI::error('No English Guide language assigned '.$id);
    }
    $saved=get_post_meta($id,$core::META,true);
    $audits=get_post_meta($id,'_mad4b_guide_day_review_audit_v1',false);
    if ($saved!==''||$audits) WP_CLI::error('Existing review/audit; refuse overwrite '.$id);
    $snapshot['sources'][$id]=[
        'source_hash'=>$source['source_hash'],'day_unit_basis'=>$source['day_unit_basis'],
        'duration_terms'=>$source['duration_proof'],
        'candidate'=>$candidate,'prior_coverage'=>$saved,'prior_audits'=>$audits,
        'tour_status'=>get_post_status($id),
    ];
}
$backup='/tmp/mad4b-guide-four-approved-before-'.gmdate('Ymd-His').'.json';
$serialized=wp_json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
if (!$serialized || file_put_contents($backup,$serialized)===false) WP_CLI::error('Backup refused');
WP_CLI::log(($apply?'APPLY':'DRY_RUN').' source_confirmed=4 backup='.$backup);
foreach($ids as $id) {
    $entry=$snapshot['sources'][$id];
    WP_CLI::log('SOURCE '.$id.' source_hash='.$entry['source_hash'].' evidence='.
       $entry['candidate']['evidence']);
    if (!$apply) continue;
    $detail=$bulk::detail($id);
    if (is_wp_error($detail) ||
        $detail['source_hash']!==$entry['source_hash'] || $detail['revision']!==0) {
        WP_CLI::error('Source moved after backup; stop '.$id);
    }
    $result=$bulk::approve([
        'tour_id'=>$id,'program_id'=>0,'mode'=>'high_only',
        'revision'=>0,'source_hash'=>$entry['source_hash'],
        'review_note'=>'Delegated source-backed review on 2026-11-20: verified included professional Guide, guided tour and one-day Duration. This is an operator-assisted review, not a manual WordPress UI approval.',
    ]);
    if (is_wp_error($result)||($result['changed_days']??0)!==1 ||
        ($result['unknown_remaining']??1)!==0) {
        WP_CLI::error('Source write failed '.$id.' '.(is_wp_error($result)?$result->get_error_code():'invalid result'));
    }
    $receipt=$core::saved($core::source($id));
    if (is_wp_error($receipt) || ($receipt['statuses']['day-1']??'')!=='full_day' ||
        ($receipt['approval_channel']??'')!=='delegated_wp_cli_operator' ||
        ($receipt['revision']??0)!==1) WP_CLI::error('Readback validation failed '.$id);
    $price=$core::guide($id,'english');
    if (is_wp_error($price)||($price['cost']??null)!==2500.0 &&
        ($price['cost']??null)!==2500) {
        WP_CLI::error('Internal English single-day base cost mismatch '.$id);
    }
    WP_CLI::log('VERIFIED '.$id.' full_day=1 base_english_cost_egp=2500 internal_only=true');
}
$afterRates=wp_count_posts('tour-rates');
foreach(['publish','draft','auto-draft','trash'] as $status) {
    if ((int)($afterRates->$status??0)!==(int)($beforeRates->$status??0)) {
        WP_CLI::error('Tour Rate statuses changed during source-only guide review');
    }
}
WP_CLI::success(($apply?'GUIDE_FOUR_APPROVED':'GUIDE_FOUR_DRYRUN_PASSED').' no_rate_changes');
