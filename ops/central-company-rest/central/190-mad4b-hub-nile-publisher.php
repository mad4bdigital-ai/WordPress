<?php
/**
 * Plugin Name: MAD4B Content Hub Nile Publisher (Staging)
 * Description: Publishes authenticated Tourism Nile data inside central Content Hub, independent from commerce.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;

final class MAD4B_Hub_Nile_Publisher_V1 {
    const CPT = 'mad4b_hub_nile';
    const SOURCE = 'tourism-almallahgroup';
    const SNAPSHOT = 'mad4b_nile_source_reference_v1';
    const RECEIPT = 'mad4b_hub_nile_publication_v1';
    const SOURCES = [
        'nile_cruise_categories.read' => ['kind' => 'nile_category', 'id' => 'source_term_id', 'count' => 18],
        'nile_cruise_catalog.read' => ['kind' => 'nile_tour', 'id' => 'source_tour_id', 'count' => 35],
        'nile_rate_history.read' => ['kind' => 'historical_rate', 'id' => 'source_rate_id', 'count' => 364],
    ];
    public static function enabled(): bool {
        return class_exists('MAD4B_Content_Hub') &&
            class_exists('MAD4B_Central_Nile_Reference_V1') &&
            MAD4B_Central_Nile_Reference_V1::enabled() &&
            wp_get_environment_type() === 'staging' &&
            strtolower((string)wp_parse_url(home_url('/'), PHP_URL_HOST)) === 'tours.dev.mad4b.com';
    }
    public static function permission(): bool {
        return self::enabled() && MAD4B_Content_Hub::permission() &&
            current_user_can('manage_options') && current_user_can('activate_plugins');
    }
    public static function register(): void {
        if (!self::enabled()) return;
        register_post_type(self::CPT, [
            'label' => 'Nile Content Hub',
            'labels' => [
                'name' => 'Nile Content Hub',
                'singular_name' => 'Nile Hub Entry',
                'all_items' => 'Published Nile Entries',
                'edit_item' => 'Inspect Nile Content',
            ],
            'public' => false,
            'publicly_queryable' => false,
            'exclude_from_search' => true,
            'show_ui' => true,
            'show_in_menu' => 'mad4b-shared-content-hub',
            'show_in_rest' => false,
            'query_var' => false,
            'rewrite' => false,
            'has_archive' => false,
            'supports' => ['title', 'editor', 'excerpt'],
            'capability_type' => 'post',
            'capabilities' => [
                'edit_post' => 'manage_options',
                'read_post' => 'manage_options',
                'delete_post' => 'manage_options',
                'edit_posts' => 'manage_options',
                'edit_others_posts' => 'manage_options',
                'publish_posts' => 'manage_options',
                'read_private_posts' => 'manage_options',
                'delete_posts' => 'manage_options',
                'delete_private_posts' => 'manage_options',
                'delete_published_posts' => 'manage_options',
                'delete_others_posts' => 'manage_options',
                'edit_private_posts' => 'manage_options',
                'edit_published_posts' => 'manage_options',
                'create_posts' => 'manage_options',
            ],
            'map_meta_cap' => false,
        ]);
    }
    private static function valid_snapshot(): array {
        $stored = get_option(self::SNAPSHOT, null);
        if (!is_array($stored)) throw new RuntimeException('Missing verified source snapshot');
        $result = [];
        foreach (self::SOURCES as $scope => $definition) {
            $source = $stored[$scope] ?? null;
            if (!is_array($source) ||
                ($source['contract'] ?? '') !== 'mad4b.central.nile-reference-snapshot.v1' ||
                ($source['source_company'] ?? '') !== self::SOURCE ||
                ($source['scope'] ?? '') !== $scope ||
                ($source['reference_only'] ?? false) !== true ||
                ($source['may_publish'] ?? true) !== false ||
                ($source['may_checkout'] ?? true) !== false ||
                !is_array($source['items'] ?? null) ||
                count($source['items']) !== $definition['count'] ||
                ($source['source_count'] ?? null) !== count($source['items']) ||
                empty($source['source_page_sha256'])) {
                throw new RuntimeException('Source scope not verified or incomplete: ' . $scope);
            }
            $ids = [];
            foreach ($source['items'] as $item) {
                $id = $item[$definition['id']] ?? null;
                if (!is_array($item) || !is_int($id) || $id <= 0 ||
                    ($item['source_company'] ?? '') !== self::SOURCE || isset($ids[$id])) {
                    throw new RuntimeException('Invalid or foreign source item: ' . $scope);
                }
                if ($scope === 'nile_rate_history.read' &&
                    (($item['reference_only'] ?? false) !== true ||
                     ($item['for_sale'] ?? true) !== false ||
                     ($item['checkout_enabled'] ?? true) !== false ||
                     ($item['validated_current_price'] ?? true) !== false)) {
                    throw new RuntimeException('Unverified rate must not become an offer');
                }
                $ids[$id] = true;
            }
            $result[$scope] = $source['items'];
        }
        // Every rate must reference one of the published source tours.
        $tourKeys = array_fill_keys(array_column($result['nile_cruise_catalog.read'], 'source_tour_id'), true);
        foreach ($result['nile_rate_history.read'] as $item) {
            if (!isset($tourKeys[$item['source_tour_id'] ?? -1])) {
                throw new RuntimeException('Orphan historical rate tour reference');
            }
        }
        return $result;
    }
    private static function present(array $item, string $kind, int $sourceId, array $tourTitles): array {
        if ($kind === 'nile_category') {
            $title = sanitize_text_field((string)($item['name'] ?? ''));
            $body = 'Nile cruise category from Tourism source. Source taxonomy: ' .
                sanitize_text_field((string)($item['taxonomy'] ?? '')) .
                '. This item belongs to the Tourism company catalog.';
            $locale = 'source';
        } elseif ($kind === 'nile_tour') {
            $title = sanitize_text_field((string)($item['title'] ?? ''));
            $body = (string)($item['source_content_text'] ?? '');
            $excerpt = sanitize_textarea_field((string)($item['summary'] ?? ''));
            if ($body === '') $body = $excerpt;
            $locale = sanitize_key((string)($item['translation_source']['language_code'] ?? 'und'));
        } else {
            $tourId = (int)($item['source_tour_id'] ?? 0);
            $tourName = (string)($tourTitles[$tourId] ?? 'Nile cruise');
            $title = 'Historical rate ' . $sourceId . ' — ' . sanitize_text_field($tourName);
            $st = $item['source_date_values'][0] ?? '';
            $en = $item['source_end_date'] ?? '';
            $start = is_scalar($st) && ctype_digit((string)$st) ? gmdate('Y-m-d', (int)$st) : 'unknown';
            $end = is_scalar($en) && ctype_digit((string)$en) ? gmdate('Y-m-d', (int)$en) : 'unknown';
            $body = "Historical source rate reference for Nile cruise #{$tourId}. " .
                "Source validity: {$start} to {$end}. Pricing requires independent verification " .
                "before use in an offer. This item is not bookable and cannot be used in checkout.";
            $locale = 'source';
        }
        if ($title === '' || mb_strlen($title) > 240) {
            throw new RuntimeException('Invalid source title for ' . $kind . ' / ' . $sourceId);
        }
        $excerpt = $kind === 'nile_tour'
            ? sanitize_textarea_field((string)($item['summary'] ?? '')) : '';
        $body = mb_substr(wp_strip_all_tags($body), 0, 15000);
        return [
            'post_title' => $title,
            'post_content' => $body,
            'post_excerpt' => mb_substr($excerpt, 0, 2500),
            'post_name' => 'tourism-nile-' . str_replace('_', '-', $kind) . '-' . $sourceId,
            'post_status' => 'publish',
            'post_type' => self::CPT,
            'comment_status' => 'closed',
            'ping_status' => 'closed',
            'post_parent' => 0,
            'meta_input' => [
                '_mad4b_hub_source_company' => self::SOURCE,
                '_mad4b_hub_source_kind' => $kind,
                '_mad4b_hub_source_id' => $sourceId,
                '_mad4b_hub_source_locale' => $locale,
                '_mad4b_hub_source_sha256' => hash('sha256', wp_json_encode($item, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)),
                '_mad4b_hub_source_payload_json' => wp_json_encode($item, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
                '_mad4b_hub_publication_state' => 'published_content_hub',
                '_mad4b_hub_content_owned_by' => self::SOURCE,
                '_mad4b_hub_commerce_approved' => 'no',
                '_mad4b_hub_bookable' => 'no',
                '_mad4b_hub_pricing_status' => $kind === 'historical_rate' ? 'historical_unverified' : 'not_applicable',
                '_mad4b_hub_translation_source_trid' =>
                    $kind === 'nile_tour' ? (int)($item['translation_source']['source_translation_group_id'] ?? 0) : 0,
            ],
        ];
    }
    private static function existing_map(): array {
        $posts = get_posts([
            'post_type' => self::CPT,
            'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
            'numberposts' => -1,
            'fields' => 'ids',
            'suppress_filters' => true,
        ]);
        $found = [];
        foreach ($posts as $postId) {
            $company = (string)get_post_meta($postId, '_mad4b_hub_source_company', true);
            if ($company !== self::SOURCE) continue;
            $kind = (string)get_post_meta($postId, '_mad4b_hub_source_kind', true);
            $sourceId = (int)get_post_meta($postId, '_mad4b_hub_source_id', true);
            if ($kind === '' || $sourceId <= 0) {
                throw new RuntimeException('Existing source publication has missing identity');
            }
            $key = $kind . ':' . $sourceId;
            if (isset($found[$key])) throw new RuntimeException('Duplicate source mapping');
            $found[$key] = (int)$postId;
        }
        return $found;
    }
    public static function publish(bool $dryRun = true): array {
        if (!self::permission()) throw new RuntimeException('Central Hub admin authority required');
        $all = self::valid_snapshot();
        // Hard admission gate: resolve canonical native terms and review native tour
        // collisions BEFORE any Content Hub post is inserted or updated.
        if (!class_exists('MAD4B_Hub_Source_Reuse_Gate_V1')) {
            throw new RuntimeException('Canonical-source reuse policy unavailable');
        }
        $reusePolicy = MAD4B_Hub_Source_Reuse_Gate_V1::evaluate($all);
        if (empty($reusePolicy['hard_gate_passed'])) {
            throw new RuntimeException('Canonical-source reuse policy rejected publication');
        }
        $existing = self::existing_map();
        $tourTitles = [];
        foreach ($all['nile_cruise_catalog.read'] as $item) {
            $tourTitles[(int)$item['source_tour_id']] = (string)$item['title'];
        }
        $records = [];
        foreach (self::SOURCES as $scope=>$group) {
            foreach ($all[$scope] as $item) {
                $id = (int)$item[$group['id']];
                $desired = self::present($item, $group['kind'], $id, $tourTitles);
                $desired = MAD4B_Hub_Source_Reuse_Gate_V1::enrich($desired, $group['kind'], $id, $reusePolicy);
                $key = $group['kind'] . ':' . $id;
                $records[$key] = $desired;
            }
        }
        if (count($records) !== 417) throw new RuntimeException('Unexpected catalog size');
        // Egypt's late-October DST transition can make WordPress' inferred
        // post_date_gmt one hour ahead of UTC, turning publish into future.
        // Supply explicit UTC/local timestamps already safely in the past.
        $publishedUtc = gmdate('Y-m-d H:i:s', time() - 180);
        $publishedLocal = get_date_from_gmt($publishedUtc, 'Y-m-d H:i:s');
        $summary = [
            'contract'=>'mad4b.content-hub.tourism-nile-publication.v1',
            'company'=>self::SOURCE,
            'scope'=>'central_content_hub_only',
            'content_hub_post_type'=>self::CPT,
            'publishes_post_status'=>'publish',
            'record_count'=>count($records),
            'dry_run'=>$dryRun,
            'created'=>0,'updated'=>0,'unchanged'=>0,
            'categories'=>18,'tours'=>35,'historical_rates'=>364,
            'commerce_approved'=>false,'checkout_enabled'=>false,
            'public_website_distribution_enabled'=>false,
            'source_reuse_policy'=>MAD4B_Hub_Source_Reuse_Gate_V1::CONTRACT,
            'canonical_taxonomy_reused'=>$reusePolicy['taxonomy_reuse_count'],
            'canonical_wpml_groups_reused'=>$reusePolicy['existing_bilingual_taxonomy_groups'],
            'native_tour_candidates_pending_owner_review'=>$reusePolicy['native_slug_collision_count'],
            'native_terms_created'=>0,
            'native_tours_modified'=>0,
        ];
        foreach ($records as $key=>$desired) {
            $desired['post_date_gmt'] = $publishedUtc;
            $desired['post_date'] = $publishedLocal;
            $postId = $existing[$key] ?? 0;
            $payloadHash = (string)$desired['meta_input']['_mad4b_hub_source_sha256'];
            if ($postId) {
                $current = get_post($postId);
                if (!$current || $current->post_type !== self::CPT) {
                    throw new RuntimeException('Unsafe existing post identity');
                }
                $same = (string)get_post_meta($postId,'_mad4b_hub_source_sha256',true) === $payloadHash &&
                    $current->post_status === 'publish' &&
                    $current->post_title === $desired['post_title'] &&
                    $current->post_content === $desired['post_content'] &&
                    MAD4B_Hub_Source_Reuse_Gate_V1::desired_meta_equal((int)$postId, $desired);
                if ($same) { $summary['unchanged']++; continue; }
                $summary['updated']++;
                if (!$dryRun) {
                    $update = $desired;
                    $update['ID']=$postId;
                    $res=wp_insert_post($update,true,false);
                    if (is_wp_error($res))throw new RuntimeException('Content Hub update failed');
                }
            } else {
                $summary['created']++;
                if (!$dryRun) {
                    $res=wp_insert_post($desired,true,false);
                    if (is_wp_error($res) || !$res) throw new RuntimeException('Content Hub insert failed');
                }
            }
        }
        if (!$dryRun) {
            update_option(self::RECEIPT,[
                'contract'=>$summary['contract'],
                'company'=>self::SOURCE,'status'=>'published',
                'counts'=>['nile_category'=>18,'nile_tour'=>35,'historical_rate'=>364],
                'post_type'=>self::CPT,'published_at'=>gmdate('c'),
                'checkout_allowed'=>false,'external_distribution_enabled'=>false,
                'source_snapshot_option'=>self::SNAPSHOT,
                'source_reuse_policy'=>MAD4B_Hub_Source_Reuse_Gate_V1::CONTRACT,
                'canonical_taxonomy_reused'=>$reusePolicy['taxonomy_reuse_count'],
                'canonical_wpml_groups_reused'=>$reusePolicy['existing_bilingual_taxonomy_groups'],
                'native_tour_matches_pending_review'=>$reusePolicy['native_slug_collision_count'],
                'native_terms_created'=>0,'native_tours_modified'=>0,
            ], false);
        }
        return $summary;
    }
    public static function columns(array $columns): array {
        return ['cb'=>$columns['cb']??'','title'=>'Title','source_kind'=>'Source kind',
            'source_id'=>'Source ID','market'=>'Commerce','date'=>'Date'];
    }
    public static function column(string $column,int $postId): void {
        if ($column==='source_kind') echo esc_html((string)get_post_meta($postId,'_mad4b_hub_source_kind',true));
        elseif ($column==='source_id') echo esc_html((string)get_post_meta($postId,'_mad4b_hub_source_id',true));
        elseif ($column==='market') echo 'Content Hub only · Not bookable';
    }
    public static function boot(): void {
        add_action('init',[__CLASS__,'register'],21);
        add_filter('manage_' . self::CPT . '_posts_columns',[__CLASS__,'columns']);
        add_action('manage_' . self::CPT . '_posts_custom_column',[__CLASS__,'column'],10,2);
    }
}
MAD4B_Hub_Nile_Publisher_V1::boot();
