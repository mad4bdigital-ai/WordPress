<?php
/**
 * Plugin Name: MAD4B Content Hub Source Reuse Gate (Staging)
 * Version: 0.1.0
 * Exact taxonomy/parent/language and tenant provenance review BEFORE publication.
 * No native terms or company tours are created, overwritten, deleted, or merged.
 */
defined('ABSPATH') || exit;

final class MAD4B_Hub_Source_Reuse_Gate_V1 {
    const CONTRACT = 'mad4b.content-hub.source-reuse-admission.v1';
    const SOURCE = 'tourism-almallahgroup';
    const TARGET_TAXONOMY = 'tour-types';
    const SOURCE_TAXONOMY = 'tour-types_jet';

    public static function evaluate(array $snapshots): array {
        if (!class_exists('MAD4B_Hub_Nile_Publisher_V1') ||
            !MAD4B_Hub_Nile_Publisher_V1::enabled()) {
            throw new RuntimeException('Source reuse policy requires central Staging authority');
        }
        $categories = $snapshots['nile_cruise_categories.read'] ?? [];
        $tours = $snapshots['nile_cruise_catalog.read'] ?? [];
        if (count($categories) !== 18 || count($tours) !== 35) {
            throw new RuntimeException('Unexpected source hierarchy or tour count');
        }
        global $wpdb;
        $sourceById = [];
        foreach ($categories as $category) {
            if (($category['taxonomy'] ?? '') !== self::SOURCE_TAXONOMY ||
                ($category['source_company'] ?? '') !== self::SOURCE ||
                !is_int($category['source_term_id'] ?? null) ||
                !is_int($category['parent_source_term_id'] ?? null) ||
                empty($category['slug'])) {
                throw new RuntimeException('Invalid category authority or taxonomy');
            }
            $id = $category['source_term_id'];
            if (isset($sourceById[$id])) throw new RuntimeException('Duplicate source term');
            $sourceById[$id] = $category;
        }
        $mapped = [];
        $languages = [];
        $resolve = function(int $sourceId, array $parents = []) use (&$resolve, &$mapped, &$languages, $sourceById, $wpdb): int {
            if (isset($mapped[$sourceId])) return (int)$mapped[$sourceId]['target_term_id'];
            if (!isset($sourceById[$sourceId]) || isset($parents[$sourceId])) {
                throw new RuntimeException('Unknown or circular source taxonomy parent');
            }
            $parents[$sourceId] = true;
            $item = $sourceById[$sourceId];
            $sourceParent = (int)$item['parent_source_term_id'];
            $expectedTargetParent = $sourceParent
                ? $resolve($sourceParent, $parents) : 0;
            $targetTax = self::TARGET_TAXONOMY;
            $slug = (string)$item['slug'];

            // Read unfiltered taxonomy records, avoiding WPML frontend language filters.
            // Parent and slug must BOTH match. Do not blindly reuse source numeric IDs.
            $candidates = $wpdb->get_results($wpdb->prepare(
                "SELECT t.term_id, t.slug, tt.parent, tt.taxonomy FROM {$wpdb->terms} t
                 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id=t.term_id
                 WHERE tt.taxonomy=%s AND t.slug=%s AND tt.parent=%d ORDER BY t.term_id",
                $targetTax, $slug, $expectedTargetParent
            ), ARRAY_A);
            if ($wpdb->last_error || count($candidates) !== 1) {
                throw new RuntimeException('Missing or ambiguous canonical category for source term '.$sourceId);
            }
            $targetId = (int)$candidates[0]['term_id'];
            $languageTable = $wpdb->prefix.'icl_translations';
            $lingua = $wpdb->get_results($wpdb->prepare(
                "SELECT language_code, trid FROM {$languageTable}
                 WHERE element_type=%s AND element_id=%d LIMIT 2",
                'tax_' . $targetTax, $targetId
            ),ARRAY_A);
            if ($wpdb->last_error || count($lingua) !== 1 ||
                !in_array((string)$lingua[0]['language_code'], ['en','ar'], true) ||
                (int)$lingua[0]['trid'] <= 0) {
                throw new RuntimeException('Canonical taxonomy WPML language unverified for source term '.$sourceId);
            }
            $mapped[$sourceId] = [
                'source_term_id' => $sourceId,
                'source_slug' => $slug,
                'source_parent_term_id' => $sourceParent,
                'target_term_id' => $targetId,
                'target_parent_term_id' => $expectedTargetParent,
                'target_taxonomy' => $targetTax,
                'target_language' => (string)$lingua[0]['language_code'],
                'target_wpml_trid' => (int)$lingua[0]['trid'],
                'resolution' => 'reused_existing_canonical_taxonomy',
            ];
            $languages[$targetId] = (string)$lingua[0]['language_code'];
            return $targetId;
        };
        foreach (array_keys($sourceById) as $sourceId) $resolve($sourceId);
        if (count($mapped) !== 18 ||
            count(array_unique(array_column($mapped,'target_term_id'))) !== 18) {
            throw new RuntimeException('Source-to-target taxonomy mapping not one-to-one');
        }

        // Translation pairs already exist; require one EN and one AR per target trid.
        $translationPairs = [];
        foreach ($mapped as $row) {
            $translationPairs[$row['target_wpml_trid']][$row['target_language']] =
                ($translationPairs[$row['target_wpml_trid']][$row['target_language']] ?? 0) + 1;
        }
        if (count($translationPairs) !== 9) throw new RuntimeException('Unexpected WPML category translation groups');
        foreach ($translationPairs as $group) {
            if (count($group) !== 2 || ($group['en']??0)!==1 || ($group['ar']??0)!==1)
                throw new RuntimeException('Missing canonical bilingual taxonomy pair');
        }

        $tourMap=[]; $collisions=[];
        foreach ($tours as $source) {
            if (($source['source_company'] ?? '') !== self::SOURCE ||
                !is_int($source['source_tour_id'] ?? null) ||
                !preg_match('/^[a-z0-9-]{1,200}$/D',(string)($source['source_slug']??''))) {
                throw new RuntimeException('Source tour tenant or slug not valid');
            }
            $id = (int)$source['source_tour_id'];
            if (isset($tourMap[$id])) throw new RuntimeException('Duplicate source tour');
            $slug = (string)$source['source_slug'];
            $found = $wpdb->get_results($wpdb->prepare(
                "SELECT ID, post_title, post_name, post_status FROM {$wpdb->posts}
                 WHERE post_type=%s AND post_name=%s AND post_status NOT IN ('trash','auto-draft') ORDER BY ID LIMIT 12",
                'tours-and-activities', $slug
            ), ARRAY_A);
            if ($wpdb->last_error || count($found)>=12) throw new RuntimeException('Unbounded native tour collision');
            $state='no_native_tour_collision';
            $candidateIds=[];
            $verifiedId=null;
            if ($found) {
                $candidateIds=array_map(static fn($v)=>(int)$v['ID'],$found);
                $state='native_slug_collision_requires_owner_review';
                foreach ($found as $native) {
                    $postId=(int)$native['ID'];
                    $owner=get_post_meta($postId,'_mad4b_source_company',true);
                    $sourcePost=(int)get_post_meta($postId,'_mad4b_source_post_id',true);
                    // Only exact explicit company AND source ID can certify ownership.
                    if ($owner===self::SOURCE && $sourcePost===$id &&
                        $native['post_title']===(string)$source['title']) {
                        if ($verifiedId!==null) throw new RuntimeException('Multiple certified native owners for tour '.$id);
                        $verifiedId=$postId;
                    }
                }
                if($verifiedId!==null) $state='verified_same_source_native_tour';
                $collisions[]=[
                    'source_tour_id'=>$id,
                    'candidate_native_ids'=>$candidateIds,
                    'provenance_approved'=>$verifiedId!==null,
                    'state'=>$state
                ];
            }
            $tourMap[$id]=[
                'source_tour_id'=>$id,
                'source_slug'=>$slug,
                'state'=>$state,
                'certified_native_tour_id'=>$verifiedId,
                'candidate_native_ids'=>$candidateIds,
                'may_modify_native_tour'=>false,
                'may_auto_merge_by_slug'=>false,
            ];
        }
        return [
            'contract'=>self::CONTRACT,
            'source_company'=>self::SOURCE,
            'canonical_taxonomy'=>self::TARGET_TAXONOMY,
            'taxonomy_reuse_count'=>count($mapped),
            'existing_bilingual_taxonomy_groups'=>count($translationPairs),
            'native_slug_collision_count'=>count($collisions),
            'native_tour_merge_count'=>0,
            'creates_native_taxonomies'=>false,
            'modifies_existing_tour_posts'=>false,
            'categories'=>$mapped,
            'tours'=>$tourMap,
            'tour_collisions'=>$collisions,
            'hard_gate_passed'=>true,
        ];
    }

    public static function enrich(array $desired, string $kind, int $sourceId, array $policy): array {
        if (($policy['contract']??'')!==self::CONTRACT || empty($policy['hard_gate_passed']))
            throw new RuntimeException('Source reuse policy missing');
        if (!isset($desired['meta_input']) || !is_array($desired['meta_input']))
            throw new RuntimeException('Source metadata absent');
        if ($kind==='nile_category') {
            $record = $policy['categories'][$sourceId] ?? null;
            if (!is_array($record)) throw new RuntimeException('Canonical category resolution absent');
            $desired['meta_input']['_mad4b_hub_reuse_policy_state']='existing_canonical_term_linked';
            $desired['meta_input']['_mad4b_hub_target_taxonomy']=self::TARGET_TAXONOMY;
            $desired['meta_input']['_mad4b_hub_target_term_id']=(int)$record['target_term_id'];
            $desired['meta_input']['_mad4b_hub_target_term_language']=$record['target_language'];
            $desired['meta_input']['_mad4b_hub_target_wpml_trid']=(int)$record['target_wpml_trid'];
            $desired['meta_input']['_mad4b_hub_native_category_created']='no';
        } elseif ($kind==='nile_tour') {
            $record=$policy['tours'][$sourceId] ?? null;
            if(!is_array($record))throw new RuntimeException('Tour duplication policy absent');
            $desired['meta_input']['_mad4b_hub_reuse_policy_state']=$record['state'];
            $desired['meta_input']['_mad4b_hub_native_candidates_json']=wp_json_encode($record['candidate_native_ids']);
            $desired['meta_input']['_mad4b_hub_certified_native_tour_id']=(int)($record['certified_native_tour_id']??0);
            $desired['meta_input']['_mad4b_hub_native_tour_overwritten']='no';
        } else {
            $desired['meta_input']['_mad4b_hub_reuse_policy_state']='independent_historical_rate_reference';
            $desired['meta_input']['_mad4b_hub_commerce_approved']='no';
            $desired['meta_input']['_mad4b_hub_bookable']='no';
        }
        $desired['meta_input']['_mad4b_hub_reuse_contract']=self::CONTRACT;
        return $desired;
    }

    public static function desired_meta_equal(int $postId, array $desired): bool {
        $keys=['_mad4b_hub_reuse_contract','_mad4b_hub_reuse_policy_state'];
        $kind=$desired['meta_input']['_mad4b_hub_source_kind']??'';
        if($kind==='nile_category') {
            array_push($keys,'_mad4b_hub_target_taxonomy',
                '_mad4b_hub_target_term_id','_mad4b_hub_target_term_language',
                '_mad4b_hub_target_wpml_trid','_mad4b_hub_native_category_created');
        }
        if($kind==='nile_tour') {
            array_push($keys,'_mad4b_hub_native_candidates_json',
                '_mad4b_hub_certified_native_tour_id',
                '_mad4b_hub_native_tour_overwritten');
        }
        foreach($keys as $key) {
            if((string)get_post_meta($postId,$key,true)!==
               (string)($desired['meta_input'][$key]??''))return false;
        }
        return true;
    }
}
