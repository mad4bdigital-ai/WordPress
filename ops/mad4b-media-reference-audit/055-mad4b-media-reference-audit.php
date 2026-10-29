<?php
/**
 * Plugin Name: MAD4B Media Reference Audit Policy
 * Description: Permanent bounded, read-only audit of unparented/dangling image attachments. Never deletes, moves, unpublishes, or grants media access.
 * Version: 1.1.0
 */
defined('ABSPATH') || exit;

final class MAD4B_Media_Reference_Audit {
    const ABILITY = 'mad4b/media-reference-audit';
    const CONTRACT = 'mad4b.media-reference-audit.v1';
    const HOOK = 'mad4b_media_reference_audit_tick_v1';
    const OPTION = 'mad4b_media_reference_audit_checkpoint_v1';
    const BATCH = 60;
    const MAX_BATCH = 60;

    public static function boot() {
        add_filter('cron_schedules', [__CLASS__, 'schedule']);
        add_action('init', [__CLASS__, 'ensure_schedule'], 90);
        add_action(self::HOOK, [__CLASS__, 'scheduled']);
        add_action('wp_abilities_api_init', [__CLASS__, 'register_ability'], 45);
        add_action('admin_menu', [__CLASS__, 'admin_menu']);
    }

    public static function schedule($schedules) {
        $schedules['mad4b_media_5min'] = [
            'interval' => 300,
            'display' => 'MAD4B bounded media reference scan (5 minutes)'
        ];
        return $schedules;
    }

    public static function ensure_schedule() {
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + 300, 'mad4b_media_5min', self::HOOK);
        }
    }

    public static function policy() {
        return [
            'contract' => self::CONTRACT,
            'action' => 'report_only',
            'delete_allowed' => false,
            'trash_allowed' => false,
            'quarantine_move_allowed' => false,
            'auto_publish_allowed' => false,
            'auto_media_catalog_approval' => false,
            'candidate_is_not_proof_of_unused' => true,
            'scheduled_scan_depth' => 'quick',
            'deep_scan_max_batch' => 3,
            'scan_scope' => 'image attachments with missing or nonexistent post_parent',
            'coverage' => ['quick: featured / known image metadata and term metadata',
                'deep (opt-in, max 3): all post metadata, options, post content and excerpt'],
            'blind_spots' => ['remote databases and headless clients', 'rendered JS and dynamic remote content',
                'offline CSS/files and external integrations', 'unrecognized custom encoding'],
            'review_required' => true
        ];
    }

    public static function can_read($args = null) {
        if (current_user_can('manage_options')) return true;
        return class_exists('MAD4B_SCP_Policy')
            && is_callable(['MAD4B_SCP_Policy', 'can_read'])
            && (bool) MAD4B_SCP_Policy::can_read();
    }

    public static function register_ability() {
        if (!function_exists('wp_register_ability') ||
            (function_exists('wp_has_ability') && wp_has_ability(self::ABILITY))) return;
        wp_register_ability(self::ABILITY, [
            'label' => 'Audit unparented media references',
            'description' => 'Read-only bounded scan. An unparented attachment is NOT necessarily unused; never remove images or create automatic publication permissions.',
            'category' => 'mad4b-read',
            'execute_callback' => [__CLASS__, 'ability'],
            'permission_callback' => [__CLASS__, 'can_read'],
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'action' => ['type'=>'string','enum'=>['status','scan']],
                    'after_id' => ['type'=>'integer','minimum'=>0],
                    'limit' => ['type'=>'integer','minimum'=>1,'maximum'=>self::MAX_BATCH],
                    'deep' => ['type'=>'boolean']
                ],
                'additionalProperties' => false
            ],
            'output_schema' => ['type'=>'object','additionalProperties'=>true],
            'meta'=>[
                'public'=>false, 'show_in_rest'=>false,
                'mcp'=>['public'=>false,'type'=>'tool','surface'=>'read'],
                'annotations'=>['readonly'=>true,'destructive'=>false,'idempotent'=>true]
            ]
        ]);
    }

    public static function candidates($after_id = 0, $limit = self::BATCH) {
        global $wpdb;
        $limit = max(1, min(self::MAX_BATCH, (int)$limit));
        $after_id = max(0, (int)$after_id);
        // Distinguish no parent from dangling parent; both require reference review.
        $sql = $wpdb->prepare(
            "SELECT a.ID, a.post_parent,
                    CASE WHEN a.post_parent=0 THEN 'no_parent' ELSE 'dangling_parent' END AS reason
             FROM {$wpdb->posts} a
             LEFT JOIN {$wpdb->posts} parent ON parent.ID=a.post_parent
             WHERE a.ID > %d AND a.post_type='attachment'
               AND a.post_mime_type LIKE 'image/%%'
               AND (a.post_parent=0 OR parent.ID IS NULL)
             ORDER BY a.ID ASC LIMIT %d", $after_id, $limit
        );
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if ($wpdb->last_error) return new WP_Error('media_reference_sql_error', 'Media reference scan failed');
        return is_array($rows) ? $rows : [];
    }

    private static function exists($sql, $params) {
        global $wpdb;
        $value = $wpdb->get_var($wpdb->prepare($sql, ...$params));
        if ($wpdb->last_error) throw new RuntimeException('Reference query failed');
        return $value !== null;
    }

    private static function reference($id, $deep=false) {
        global $wpdb;
        $id = absint($id);
        // Stored within the attachment itself is not evidence of being used.
        $path = (string) get_post_meta($id, '_wp_attached_file', true);
        $base = basename($path);
        $filename = strlen($base) >= 5 && strlen($base) <= 220 ? $base : '';
        $id_string = (string)$id;
        $evidence = [];
        if (self::exists("SELECT 1 FROM {$wpdb->postmeta} WHERE meta_key='_thumbnail_id' AND meta_value=%s LIMIT 1", [$id_string])) {
            $evidence[]='featured_image';
        }
        // Quick path is indexed by a short allowlist of image-reference keys.
        // Unknown encodings stay unresolved; the policy NEVER deletes them.
        $id_pattern = $wpdb->esc_like('"' . $id_string . '"');
        $name_pattern = $filename ? '%' . $wpdb->esc_like($filename) . '%' : '';
        if (self::exists(
            "SELECT 1 FROM {$wpdb->postmeta} WHERE post_id<>%d
             AND meta_key IN ('gallery','image_gellery','featured_image',
                 '_product_image_gallery','_gallery_ids','_thumbnail_id')
             AND (meta_value=%s OR meta_value LIKE %s OR (%s<>'' AND meta_value LIKE %s))
             LIMIT 1",
            [$id, $id_string, '%'.$id_pattern.'%', $name_pattern, $name_pattern]
        )) $evidence[]='known_image_metadata';
        if ($deep && self::exists(
            "SELECT 1 FROM {$wpdb->postmeta} WHERE post_id<>%d
             AND meta_key NOT IN ('_edit_lock','_edit_last')
             AND (meta_value=%s OR meta_value LIKE %s OR (%s<>'' AND meta_value LIKE %s))
             LIMIT 1",
            [$id, $id_string, '%'.$id_pattern.'%', $name_pattern, $name_pattern]
        )) $evidence[]='other_post_metadata_deep';
        if (self::exists(
            "SELECT 1 FROM {$wpdb->termmeta} WHERE meta_value=%s
             OR meta_value LIKE %s OR (%s<>'' AND meta_value LIKE %s) LIMIT 1",
            [$id_string, '%'.$id_pattern.'%', $name_pattern, $name_pattern]
        )) $evidence[]='term_metadata';
        if ($deep && $name_pattern && self::exists(
            "SELECT 1 FROM {$wpdb->options} WHERE option_value LIKE %s LIMIT 1", [$name_pattern]
        )) $evidence[]='options';
        $id_block = '%'.$wpdb->esc_like('wp-image-'.$id_string).'%';
        $content_name = $name_pattern ?: $id_block;
        if ($deep && self::exists(
            "SELECT 1 FROM {$wpdb->posts} WHERE post_type<>'attachment'
             AND (post_content LIKE %s OR post_content LIKE %s
                  OR post_excerpt LIKE %s) LIMIT 1",
            [$id_block, $content_name, $content_name]
        )) $evidence[]='post_content';
        return array_values(array_unique($evidence));
    }

    private static function is_test($id, $parent) {
        foreach ([$id, $parent] as $target) {
            if (!$target) continue;
            $flag = strtolower(trim((string)get_post_meta($target, '_mad4b_is_test_data', true)));
            $class = strtolower(trim((string)get_post_meta($target, '_mad4b_data_classification', true)));
            if (in_array($flag, ['1','true','on','yes'], true) ||
                in_array($class, ['test_only','sandbox_only'], true)) return true;
        }
        return false;
    }

    public static function scan($after_id=0, $limit=self::BATCH, $deep=false) {
        $limit = $deep ? min(3, max(1, (int)$limit)) : min(self::MAX_BATCH, max(1, (int)$limit));
        $rows = self::candidates($after_id, $limit);
        if (is_wp_error($rows)) return $rows;
        $items = []; $totals = ['referenced'=>0,'unresolved_review_only'=>0,'test_only'=>0];
        $cursor = max(0, (int)$after_id);
        foreach ($rows as $row) {
            $id = absint($row['ID']);
            $cursor = max($cursor, $id);
            try {
                $test = self::is_test($id, (int)$row['post_parent']);
                $refs = $test ? [] : self::reference($id, $deep);
                $state = $test ? 'test_only' : ($refs ? 'referenced' : 'unresolved_review_only');
            } catch (Throwable $e) {
                return new WP_Error('media_reference_scan_incomplete', 'Reference audit failed; no cleanup permitted');
            }
            $totals[$state]++;
            $items[]=[
                'attachment_id'=>$id,
                'parent_state'=>$row['reason'],
                'classification'=>$state,
                'reference_evidence'=>$refs,
                'delete_allowed'=>false
            ];
        }
        return [
            'contract'=>self::CONTRACT, 'after_id'=>(int)$after_id,
            'next_after_id'=>$cursor, 'batch_size'=>count($rows),
            'scan_depth'=>$deep?'deep':'quick',
            'potentially_more'=>count($rows)==$limit,
            'counts'=>$totals,'items'=>$items,
            'policy'=>self::policy(), 'mutation_performed'=>false
        ];
    }

    public static function ability($args=[]) {
        if (!self::can_read()) return new WP_Error('media_reference_forbidden', 'Not authorized');
        if (!is_array($args)) $args=[];
        if (($args['action'] ?? 'status') === 'status') return self::status();
        $deep=($args['deep']??false)===true;
        return self::scan(absint($args['after_id']??0), max(1,min($deep?3:self::MAX_BATCH,(int)($args['limit']??($deep?3:self::BATCH)))), $deep);
    }

    public static function status() {
        $state = get_option(self::OPTION, []);
        return ['contract'=>self::CONTRACT, 'policy'=>self::policy(),
            'checkpoint'=>is_array($state)?$state:[], 'scheduled'=> (bool)wp_next_scheduled(self::HOOK),
            'mutation_performed'=>false];
    }

    public static function scheduled() {
        $lock = 'mad4b_media_reference_audit_lock_v1';
        if (get_transient($lock)) return;
        set_transient($lock, 'running', 240);
        try {
            $state=get_option(self::OPTION, []);
            if (!is_array($state)) $state=[];
            $after=max(0,(int)($state['cursor']??0));
            $out=self::scan($after,self::BATCH);
            if (is_wp_error($out)) {
                $state['last_error']=$out->get_error_code();
                $state['updated_at']=gmdate(DATE_ATOM);
            } else {
                $state['cursor']=$out['next_after_id'];
                $state['last_batch_counts']=$out['counts'];
                $state['last_batch_sample']=array_slice($out['items'],0,12);
                $state['updated_at']=gmdate(DATE_ATOM);
                $state['scanned_in_cycle']=(int)($state['scanned_in_cycle']??0)+$out['batch_size'];
                $totals=$state['cycle_counts']??array_fill_keys(array_keys($out['counts']),0);
                foreach ($out['counts'] as $k=>$v) $totals[$k]=(int)($totals[$k]??0)+$v;
                $state['cycle_counts']=$totals;
                unset($state['last_error']);
                if (!$out['potentially_more']) {
                    $state['last_completed_cycle']=[
                        'completed_at'=>gmdate(DATE_ATOM),
                        'scanned'=>$state['scanned_in_cycle'],
                        'counts'=>$state['cycle_counts']
                    ];
                    $state['cursor']=0;
                    $state['scanned_in_cycle']=0;
                    $state['cycle_counts']=array_fill_keys(array_keys($out['counts']),0);
                }
            }
            // Only checkpoint metadata changes. Attachment rows/files NEVER change.
            update_option(self::OPTION,$state,false);
        } finally {
            delete_transient($lock);
        }
    }

    public static function admin_menu() {
        add_management_page('Media Reference Audit', 'Media Reference Audit', 'manage_options',
            'mad4b-media-reference-audit', [__CLASS__,'admin_page']);
    }
    public static function admin_page() {
        if (!current_user_can('manage_options')) wp_die('Not authorized');
        $state=self::status();
        echo '<div class="wrap"><h1>Media Reference Audit</h1>';
        echo '<p>Read-only continuous reference checks. Unlinked does not mean unused. No automatic deletion, trash or media catalog approval.</p>';
        echo '<pre>'.esc_html(wp_json_encode($state,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)).'</pre></div>';
    }
}
MAD4B_Media_Reference_Audit::boot();
