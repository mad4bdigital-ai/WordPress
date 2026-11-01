<?php
/**
 * WP-CLI-only, exact-metadata reconciliation for duplicate attachments
 * pointing to ONE identical image file. STAGING ONLY.
 *
 * Environment:
 * MEDIA_META_ACTION=plan|apply|rollback
 * MEDIA_META_SOURCE_ID=<int>
 * MEDIA_META_TARGET_IDS=<comma-separated>
 * MEDIA_META_PLAN=/tmp/reviewed-private-plan.json (apply/rollback)
 *
 * Never deletes files or changes attachment IDs, parent, URL, status, or title.
 */
if (!defined('WP_CLI') || !WP_CLI) { throw new RuntimeException('WP-CLI only'); }
if (wp_get_environment_type() !== 'staging') { throw new RuntimeException('Only staging authorized'); }

function media_meta_require($yes, $msg) {
    if (!$yes) throw new RuntimeException($msg);
}
function media_meta_sha($v) { return hash('sha256', serialize($v)); }
function media_meta_valid($m) {
    return is_array($m) && !empty($m['file']) && !empty($m['width']) && !empty($m['height']) && is_array($m['sizes'] ?? null);
}
function media_meta_source_valid($id) {
    $m = wp_get_attachment_metadata($id);
    media_meta_require(media_meta_valid($m), 'Invalid source metadata');
    $root = wp_upload_dir()['basedir'];
    media_meta_require(is_file($root.'/'.$m['file']), 'Source file absent');
    foreach ($m['sizes'] as $size) {
        media_meta_require(is_array($size) && !empty($size['file']), 'Invalid generated variant');
        media_meta_require(is_file($root.'/'.dirname($m['file']).'/'.$size['file']), 'Variant missing in source reference');
    }
    return $m;
}
$action = (string)getenv('MEDIA_META_ACTION');
media_meta_require(in_array($action, ['plan', 'apply', 'rollback'], true), 'Invalid action');
$master = filter_var(getenv('MEDIA_META_SOURCE_ID'), FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
$raw = (string)getenv('MEDIA_META_TARGET_IDS');
media_meta_require((bool)$master && preg_match('/^\d+(?:,\d+){0,30}$/D', $raw), 'Invalid IDs');
$targets = array_values(array_unique(array_map('intval',explode(',',$raw))));
media_meta_require(!in_array($master,$targets,true) && count($targets)>0, 'Unsafe target list');
$source = media_meta_source_valid($master);

function media_meta_expected_targets($targets, $source) {
    $ret = [];
    foreach ($targets as $id) {
        media_meta_require(get_post_type($id)==='attachment', 'Not an attachment');
        $m = wp_get_attachment_metadata($id);
        media_meta_require(media_meta_valid($m), 'Target metadata missing');
        foreach (['file','width','height'] as $field) {
            media_meta_require($m[$field] === $source[$field], 'Target differs from shared source: '.$field);
        }
        media_meta_require(($m['original_image'] ?? null) === ($source['original_image'] ?? null), 'Original-image pairing differs');
        $after = $m;
        $after['sizes'] = $source['sizes'];
        $ret[(string)$id] = [
            'before_hash'=>media_meta_sha($m),
            'after_hash'=>media_meta_sha($after),
            'before_b64'=>base64_encode(serialize($m)),
        ];
    }
    return $ret;
}
if ($action==='plan') {
    $ret=[
       'contract'=>'mad4b.media.duplicate-attachment-metadata-repair.v1',
       'source_id'=>$master,
       'source_hash'=>media_meta_sha($source),
       'target_ids'=>$targets,
       'targets'=>media_meta_expected_targets($targets,$source),
       'staging_only'=>true,
       'no_file_deletion'=>true,
    ];
    echo wp_json_encode($ret, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
    return;
}
$file = (string)getenv('MEDIA_META_PLAN');
media_meta_require(str_starts_with($file,'/tmp/mad4b-media-meta-') && is_file($file), 'Missing private review plan');
$plan = json_decode((string)file_get_contents($file), true);
media_meta_require(is_array($plan) && ($plan['contract']??'')==='mad4b.media.duplicate-attachment-metadata-repair.v1', 'Invalid plan');
media_meta_require($plan['source_id']===$master && $plan['target_ids']===$targets && $plan['source_hash']===media_meta_sha($source), 'Source/target drift');
$current = media_meta_expected_targets($targets,$source);
media_meta_require(array_keys($current)===array_keys($plan['targets']), 'Target keys drift');
$done=0;
foreach ($targets as $id) {
    $orig = @unserialize(base64_decode($plan['targets'][(string)$id]['before_b64'],true), ['allowed_classes'=>false]);
    media_meta_require(is_array($orig) && media_meta_sha($orig)===$plan['targets'][(string)$id]['before_hash'], 'Baseline tamper');
    $now = wp_get_attachment_metadata($id);
    $actual = media_meta_sha($now);
    $before = $plan['targets'][(string)$id]['before_hash'];
    $after = $plan['targets'][(string)$id]['after_hash'];
    if ($action==='apply') {
        media_meta_require($actual===$before || $actual===$after, 'Target changed since planning');
        if ($actual===$before) {
            $updated=$now; $updated['sizes']=$source['sizes'];
            media_meta_require(media_meta_sha($updated)===$after, 'Target after-state mismatch');
            update_post_meta($id,'_wp_attachment_metadata',$updated);
            media_meta_require(media_meta_sha(wp_get_attachment_metadata($id))===$after,'Target update verification failed');
            $done++;
        }
    } else {
        media_meta_require($actual===$before || $actual===$after, 'Refuse rollback of changed metadata');
        if ($actual===$after && $actual!==$before) {
            update_post_meta($id,'_wp_attachment_metadata',$orig);
            media_meta_require(media_meta_sha(wp_get_attachment_metadata($id))===$before,'Rollback verification failed');
            $done++;
        }
    }
}
echo wp_json_encode(['contract'=>'mad4b.media.duplicate-attachment-metadata-repair.v1',
    'action'=>$action,'changed_attachment_metadata'=>$done,'files_changed'=>0,'production_changed'=>false])."\n";
