<?php
/**
 * MAD4B — owner-directed relaxed visual coverage, exactly two Draft Units.
 *
 * Existing JetEngine Unit native fields only; no new CPT or rate/stock mutation.
 * Fairmont reuses the SAME official image attachment (no duplicate import).
 * Hilton uses an explicitly approximate, partner-hosted room/pool photograph.
 * Original source gaps remain in history; resolution is a SEPARATE record.
 *
 * Modes: dry-run | apply | rollback-preview | rollback
 * Mutations need MAD4B_STAY_RELAXED_OWNER_APPROVED=1 and explicit Unit IDs.
 */
if (!defined('ABSPATH') || !defined('WP_CLI') || !WP_CLI) {
    throw new RuntimeException('WP-CLI only');
}
const MAD4B_RELAXED_SCHEMA = 'mad4b.stay-relaxed-visual-coverage.v1';
const MAD4B_RELAXED_META = '_mad4b_stay_visual_coverage_v1';
const MAD4B_RELAXED_RESOLUTION = '_mad4b_stay_room_photo_gap_resolution_v1';
const MAD4B_HILTON_CANDIDATE = '/tmp/mad4b-stay-two-final/hilton-partner-poolview.jpg';
const MAD4B_HILTON_IMAGE_URL = 'https://fairmont-heliopolis.cairo-hotels-eg.com/data/Photos/OriginalPhoto/12770/1277078/1277078296/cairo-fairmont-heliopolis-hotel-photo-49.JPEG';
const MAD4B_HILTON_SHA = 'c36460b9f0905bfd188427090b00c4443790616bcae302d7d06f751ac3070071';
const MAD4B_FAIRMONT_ASSET = 71364;
const MAD4B_FAIRMONT_SHA = '9a453bbe0dcda4c9ff3ee83d267e3c18bba9c4cd0d2ff7e535e54ce5fd8998f1';

function mad4b_relaxed_check(int $id): array {
    $configs = [
        70905 => [
            'property_id' => 70737,
            'title' => 'Hilton Cairo Heliopolis — Guest Room with Pool View',
            'gap_code' => 'official_hilton_image_unavailable',
            'strategy' => 'partner_nearest_room_with_pool_view',
        ],
        70893 => [
            'property_id' => 70735,
            'title' => 'Fairmont Nile City — Deluxe Premier Partial Nile View room',
            'gap_code' => 'official_fairmont_hero_reused_across_room_categories',
            'strategy' => 'shared_official_asset_across_room_categories',
        ],
    ];
    if (!isset($configs[$id])) return ['error' => 'unit_not_in_explicit_approved_scope'];
    $c = $configs[$id];
    $pid = (int) $c['property_id'];
    if (get_post_type($id) !== 'units' || get_post_status($id) !== 'draft' ||
        get_post_type($pid) !== 'properties' ||
        !in_array(get_post_status($pid), ['publish','draft'], true)) {
        return ['error' => 'entity_post_type_or_status_changed'];
    }
    if (html_entity_decode((string)get_the_title($id), ENT_QUOTES, 'UTF-8') !== $c['title']) {
        return ['error' => 'original_room_identity_changed'];
    }
    global $wpdb;
    $rel = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}jet_rel_default
         WHERE rel_id=223 AND parent_object_id=%d AND child_object_id=%d",
        $pid, $id
    ));
    if ($rel !== 1) return ['error' => 'original_jetengine_relation_mismatch'];
    if (get_post_thumbnail_id($id) || get_post_meta($id, 'unit_gellery', true)) {
        return ['error' => 'existing_media_must_not_be_overwritten'];
    }
    if (get_post_meta($id, MAD4B_RELAXED_META, true) ||
        get_post_meta($id, MAD4B_RELAXED_RESOLUTION, true)) {
        return ['error' => 'prior_resolution_preserved'];
    }
    $gap = get_post_meta($id, '_mad4b_stay_room_photo_source_gap_v1', true);
    if (!is_array($gap) || ($gap['code'] ?? '') !== $c['gap_code'] ||
        (int)($gap['property_id'] ?? 0) !== $pid) {
        return ['error' => 'original_source_gap_not_verified'];
    }
    if ($id === 70893) {
        $orig = get_post_meta($id, '_mad4b_unit_official_room_photo_reference_v1', true);
        $photo = get_post(MAD4B_FAIRMONT_ASSET);
        $meta = get_post_meta(MAD4B_FAIRMONT_ASSET, '_mad4b_unit_photo_provenance_v1', true);
        if (!is_array($orig) || (int)($orig['property_id'] ?? 0) !== $pid ||
            ($orig['room_name'] ?? '') !== 'Deluxe Premier Partial Nile View room' ||
            !$photo || $photo->post_type !== 'attachment' ||
            (int)$photo->post_parent !== 70891 ||
            (int)get_post_thumbnail_id(70891) !== MAD4B_FAIRMONT_ASSET ||
            (string)get_post_meta(70891, 'unit_gellery', true) !== (string)MAD4B_FAIRMONT_ASSET ||
            !is_array($meta) || (int)($meta['property_id'] ?? 0) !== $pid ||
            ($meta['image_sha256'] ?? '') !== MAD4B_FAIRMONT_SHA ||
            !is_file((string)get_attached_file(MAD4B_FAIRMONT_ASSET)) ||
            !hash_equals(MAD4B_FAIRMONT_SHA, hash_file('sha256', (string)get_attached_file(MAD4B_FAIRMONT_ASSET)))) {
            return ['error' => 'shared_official_photo_or_original_parent_changed'];
        }
        $c['attachment'] = MAD4B_FAIRMONT_ASSET;
        $c['image_sha256'] = MAD4B_FAIRMONT_SHA;
        $c['source_type'] = 'official_shared_asset';
        $c['source_page'] = 'https://www.fairmont.com/en/hotels/cairo/fairmont-nile-city/rooms/kgb.room.html';
        $c['image_url'] = (string)$meta['original_image_url'];
        $c['exact_room_match_verified'] = false;
        $c['shared_official_asset_across_room_categories'] = true;
        $c['distinct_room_photo_unavailable'] = true;
        $c['partner_provenance_not_hotel_official'] = false;
    } else {
        $file = realpath(MAD4B_HILTON_CANDIDATE);
        $root = realpath('/tmp/mad4b-stay-two-final');
        $size = $file ? @getimagesize($file) : false;
        if (!$file || !$root || strpos($file, $root . DIRECTORY_SEPARATOR) !== 0 ||
            !$size || $size[0] !== 2000 || $size[1] !== 1327 || $size['mime'] !== 'image/jpeg' ||
            !hash_equals(MAD4B_HILTON_SHA, hash_file('sha256', $file))) {
            return ['error' => 'partner_photo_quality_or_sha_changed'];
        }
        $c['attachment'] = 0;
        $c['image_sha256'] = MAD4B_HILTON_SHA;
        $c['source_type'] = 'partner_approximate_photo';
        $c['source_page'] = 'https://fairmont-heliopolis.cairo-hotels-eg.com/en/';
        $c['image_url'] = MAD4B_HILTON_IMAGE_URL;
        $c['exact_room_match_verified'] = false;
        $c['shared_official_asset_across_room_categories'] = false;
        $c['distinct_room_photo_unavailable'] = false;
        $c['partner_provenance_not_hotel_official'] = true;
        $c['historic_hotel_branding'] = 'Former Fairmont Heliopolis branding; current hotel is Hilton Cairo Heliopolis';
    }
    return ['value' => $c];
}

function mad4b_relaxed_receipt(int $id, array $cfg, int $aid): array {
    return [
        'schema' => MAD4B_RELAXED_SCHEMA,
        'unit_id' => $id,
        'property_id' => $cfg['property_id'],
        'attachment_id' => $aid,
        'image_sha256' => $cfg['image_sha256'],
        'source_type' => $cfg['source_type'],
        'source_page' => $cfg['source_page'],
        'original_image_url' => $cfg['image_url'],
        'coverage_status' => 'visual_only_not_commercially_approved',
        'strategy' => $cfg['strategy'],
        'exact_room_match_unverified' => !$cfg['exact_room_match_verified'],
        'shared_official_asset_across_room_categories' => $cfg['shared_official_asset_across_room_categories'],
        'distinct_room_photo_unavailable' => $cfg['distinct_room_photo_unavailable'],
        'partner_provenance_not_hotel_official' => $cfg['partner_provenance_not_hotel_official'],
        'rights_independently_verified' => false,
        'original_source_gap_retained_for_history' => true,
        'original_photo_reference_preserved' => true,
        'before' => ['thumbnail' => 0, 'gallery' => ''],
        'after' => ['thumbnail' => $aid, 'gallery' => (string)$aid],
        'price_or_inventory_mutation' => false,
        'owner_attested_public_use' => true,
        'recorded_at' => gmdate('c'),
    ];
}

function mad4b_relaxed_apply(int $id, array $cfg): array {
    require_once ABSPATH . 'wp-admin/includes/image.php';
    $created = false;
    $aid = (int)($cfg['attachment'] ?? 0);
    if ($id === 70905) {
        $file = MAD4B_HILTON_CANDIDATE;
        $destName = 'mad4b-unit-70905-relaxed-pool-view-' .
            substr($cfg['image_sha256'], 0, 12) . '.jpg';
        $save = wp_upload_bits($destName, null, file_get_contents($file));
        if (!empty($save['error']) || empty($save['file'])) {
            return ['status' => 'local_media_upload_failed'];
        }
        $aid = wp_insert_attachment([
            'post_mime_type' => 'image/jpeg',
            'post_title' => 'Hilton Cairo Heliopolis — pool-view visual reference (approximate)',
            'post_excerpt' => 'Partner room photo. Exact Unit room variant not verified. ' . $cfg['source_page'],
            'post_content' => $cfg['image_url'],
            'post_status' => 'inherit',
        ], $save['file'], $id, true);
        if (is_wp_error($aid) || !$aid) {
            @unlink($save['file']);
            return ['status' => 'attachment_record_failed'];
        }
        $created = true;
        $aid = (int)$aid;
        $imgmeta = wp_generate_attachment_metadata($aid, $save['file']);
        if (!is_array($imgmeta) || empty($imgmeta['width'])) {
            wp_delete_attachment($aid, true);
            return ['status' => 'attachment_metadata_generation_failed'];
        }
        wp_update_attachment_metadata($aid, $imgmeta);
        update_post_meta($aid, '_wp_attachment_image_alt',
            'Hilton Cairo Heliopolis hotel room with pool view — representative photo');
        update_post_meta($aid, '_mad4b_unit_photo_provenance_v1', [
            'schema' => 'mad4b.stay-relaxed-room-photo.v1',
            'unit_id' => $id,
            'property_id' => $cfg['property_id'],
            'image_sha256' => $cfg['image_sha256'],
            'original_image_url' => $cfg['image_url'],
            'hotel_source_page' => $cfg['source_page'],
            'source_type' => $cfg['source_type'],
            'partner_provenance_not_hotel_official' => true,
            'exact_room_match_unverified' => true,
            'original_rights_status' => 'external_source_reference_only_not_licensed_for_copy',
            'rights_independently_verified' => false,
            'approved_use_basis' => 'owner_attested_public_source_use',
            'recorded_at' => gmdate('c'),
        ]);
    }
    // Refuse any later editor/manual changes while writing.
    if (get_post_status($id) !== 'draft' ||
        get_post_thumbnail_id($id) ||
        get_post_meta($id, 'unit_gellery', true)) {
        if ($created) wp_delete_attachment($aid, true);
        return ['status' => 'editorial_image_changed_since_preflight'];
    }
    if (!add_post_meta($id, '_thumbnail_id', $aid, true)) {
        if ($created) wp_delete_attachment($aid, true);
        return ['status' => 'featured_image_atomic_insert_failed'];
    }
    if (!add_post_meta($id, 'unit_gellery', (string)$aid, true)) {
        delete_post_meta($id, '_thumbnail_id', $aid);
        if ($created) wp_delete_attachment($aid, true);
        return ['status' => 'native_gallery_atomic_insert_failed'];
    }
    $receipt = mad4b_relaxed_receipt($id, $cfg, $aid);
    if (!add_post_meta($id, MAD4B_RELAXED_META, $receipt, true)) {
        delete_post_meta($id, 'unit_gellery', (string)$aid);
        delete_post_meta($id, '_thumbnail_id', $aid);
        if ($created) wp_delete_attachment($aid, true);
        return ['status' => 'audit_receipt_failure_reverted'];
    }
    $resolution = [
        'schema' => 'mad4b.stay-room-photo-gap-resolution.v1',
        'unit_id' => $id,
        'attachment_id' => $aid,
        'original_gap_code' => $cfg['gap_code'],
        'outcome' => $cfg['strategy'],
        'coverage_status' => 'visual_only_not_commercially_approved',
        'exact_room_match_unverified' => true,
        'original_gap_retained_for_history' => true,
        'rights_independently_verified' => false,
        'commercial_readiness_unchanged' => true,
        'resolved_at' => gmdate('c'),
    ];
    if (!add_post_meta($id, MAD4B_RELAXED_RESOLUTION, $resolution, true)) {
        delete_post_meta($id, MAD4B_RELAXED_META);
        delete_post_meta($id, 'unit_gellery', (string)$aid);
        delete_post_meta($id, '_thumbnail_id', $aid);
        if ($created) wp_delete_attachment($aid, true);
        return ['status' => 'resolution_receipt_failure_reverted'];
    }
    return [
        'status' => 'applied_relaxed_visual_coverage',
        'unit_id' => $id,
        'attachment_id' => $aid,
        'source_type' => $cfg['source_type'],
        'shared_attachment' => $id === 70893,
        'post_status' => get_post_status($id),
    ];
}

function mad4b_relaxed_rollback(int $id, bool $apply): array {
    $r = get_post_meta($id, MAD4B_RELAXED_META, true);
    $gap = get_post_meta($id, MAD4B_RELAXED_RESOLUTION, true);
    if (!is_array($r) || ($r['schema'] ?? '') !== MAD4B_RELAXED_SCHEMA ||
        !is_array($gap) || ($gap['schema'] ?? '') !== 'mad4b.stay-room-photo-gap-resolution.v1') {
        return ['status' => 'not_relaxed_curated'];
    }
    $aid = (int)($r['attachment_id'] ?? 0);
    if (!in_array($id, [70905,70893], true) || get_post_status($id) !== 'draft' ||
        (int)get_post_thumbnail_id($id) !== $aid ||
        (string)get_post_meta($id, 'unit_gellery', true) !== (string)$aid ||
        (int)($gap['attachment_id'] ?? 0) !== $aid ||
        !is_file((string)get_attached_file($aid))) {
        return ['status' => 'changed_since_import_rollback_blocked'];
    }
    if ($id === 70893 && (
        $aid !== MAD4B_FAIRMONT_ASSET || (int)get_post_thumbnail_id(70891) !== $aid)) {
        return ['status' => 'shared_official_media_changed'];
    }
    if ($id === 70905) {
        $photo = get_post($aid);
        $p = get_post_meta($aid, '_mad4b_unit_photo_provenance_v1', true);
        if (!$photo || (int)$photo->post_parent !== $id || !is_array($p) ||
            ($p['image_sha256'] ?? '') !== MAD4B_HILTON_SHA) {
            return ['status' => 'partner_attachment_changed'];
        }
        global $wpdb;
        $refs = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id<>%d
             AND (meta_key=%s OR meta_key=%s) AND meta_value=%s",
            $id, '_thumbnail_id', 'unit_gellery', (string)$aid
        ));
        if ($refs) return ['status' => 'media_reused_elsewhere_rollback_blocked'];
    }
    if (!$apply) return ['status' => 'rollback_eligible', 'unit_id' => $id, 'attachment_id' => $aid];
    delete_post_meta($id, MAD4B_RELAXED_RESOLUTION);
    delete_post_meta($id, MAD4B_RELAXED_META);
    delete_post_meta($id, 'unit_gellery', (string)$aid);
    delete_post_meta($id, '_thumbnail_id', $aid);
    if ($id === 70905) wp_delete_attachment($aid, true);
    return ['status' => 'rolled_back', 'unit_id' => $id, 'shared_original_attachment_preserved' => $id === 70893];
}

$mode = getenv('MAD4B_STAY_RELAXED_MODE') ?: 'dry-run';
if (!in_array($mode, ['dry-run','apply','rollback-preview','rollback'], true))
    WP_CLI::error('invalid_mode');
$units = array_values(array_unique(array_filter(array_map('intval',
    explode(',', (string)(getenv('MAD4B_STAY_RELAXED_UNIT_IDS') ?: ''))))));
if (!$units || count($units) > 2 || array_diff($units, [70893,70905]))
    WP_CLI::error('Only exact Unit IDs 70905,70893 accepted (max 2)');
if (in_array($mode, ['apply','rollback'], true) &&
    getenv('MAD4B_STAY_RELAXED_OWNER_APPROVED') !== '1')
    WP_CLI::error('explicit_owner_approval_required');
$results = [];
foreach ($units as $id) {
    $lock = 'mad4b_stay_relaxed_visual_lock_v1_' . $id;
    if ($mode === 'rollback' || $mode === 'rollback-preview') {
        $results[] = mad4b_relaxed_rollback($id, $mode === 'rollback');
        continue;
    }
    if ($mode === 'apply' && !add_option($lock, (string)time(), '', 'no')) {
        $results[] = ['unit_id'=>$id,'status'=>'parallel_operation_locked'];
        continue;
    }
    try {
        $check = mad4b_relaxed_check($id);
        $results[] = !empty($check['error'])
            ? ['unit_id'=>$id,'status'=>$check['error']]
            : ($mode === 'apply'
                ? mad4b_relaxed_apply($id, $check['value'])
                : ['unit_id'=>$id,'status'=>'eligible_relaxed',
                   'strategy'=>$check['value']['strategy'],
                   'source_type'=>$check['value']['source_type']]);
    } finally {
        if ($mode === 'apply') delete_option($lock);
    }
}
WP_CLI::line(wp_json_encode(['mode'=>$mode,'results'=>$results],
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
