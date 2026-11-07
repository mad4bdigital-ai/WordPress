<?php
/**
 * Plugin Name: MAD4B – Archive Runtime Filter Reconciliation
 * Description: Inspects and reconciles archive filter semantics through WP-CLI and native WordPress APIs, without touching business content or enabling SEO publication.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;

/** Source-approved portable filter semantics for the currently enrolled archive family. */
function mad4b_archive_filter_semantic_contract_v1(): array {
    return [
        15032 => ['_query_var' => '_tax_query::tour-styles', '_terms_relational_operator' => 'OR'],
        15033 => ['_query_var' => '_tax_query::duration-options', '_terms_relational_operator' => 'OR'],
        15034 => ['_terms_relational_operator' => 'OR'],
        15035 => ['_terms_relational_operator' => 'OR'],
        15902 => ['_terms_relational_operator' => 'OR'],
        15903 => ['_terms_relational_operator' => 'OR'],
        16204 => ['_terms_relational_operator' => 'OR'],
    ];
}

/** Check semantic drift; don't equate matching IDs with equal filter behavior. */
function mad4b_archive_filter_plan_v1(): array {
    global $wpdb;
    $plan = ['contract'=>'mad4b.archive-filter-reconciliation.v1', 'site'=>home_url('/'),
        'changes'=>[], 'errors'=>[], 'requires_browser_acceptance'=>true,
        'seo_publication_changed'=>false];
    $taxonomyCounts = [];
    foreach (['tour-styles','duration-options'] as $taxonomy) {
        $taxonomyCounts[$taxonomy] = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy=%s", $taxonomy
        ));
        if ($taxonomyCounts[$taxonomy] < 1) $plan['errors'][] = 'canonical_taxonomy_has_no_terms:' . $taxonomy;
    }
    $plan['canonical_taxonomies'] = $taxonomyCounts;
    foreach (mad4b_archive_filter_semantic_contract_v1() as $id=>$rules) {
        $post = get_post($id);
        if (!$post || $post->post_type !== 'jet-smart-filters' || $post->post_status !== 'publish') {
            $plan['errors'][] = 'filter_identity_missing:' . $id;continue;
        }
        foreach ($rules as $key=>$expected) {
            $old = get_post_meta($id,$key,true);
            if ((string)$old === $expected) continue;
            $allowedOld = $key === '_terms_relational_operator' ? ['AND',''] :
                ($id === 15032 ? ['_tax_query::st_tour_type'] : ['_tax_query::durations']);
            if (!in_array((string)$old,$allowedOld,true)) {
                $plan['errors'][]='unexpected_filter_mapping:' . $id . ':' . $key;continue;
            }
            $plan['changes'][]=['post_id'=>$id,'meta_key'=>$key,
                'expected_before'=>(string)$old,'desired'=>$expected];
        }
    }
    $listing = get_post(35084);
    if (!$listing || $listing->post_type !== 'jet-engine' || $listing->post_status !== 'publish') {
        $plan['errors'][]='listing_35084_missing';
    } else {
        $desired = ['source'=>'posts','post_type'=>'tours-and-activities','tax'=>'category'];
        $old=get_post_meta(35084,'_listing_data',true);
        if ($old === '' || $old === []) {
            $plan['changes'][]=['post_id'=>35084,'meta_key'=>'_listing_data',
                'expected_before'=>'','desired'=>$desired];
        } elseif ($old !== $desired) {
            $plan['errors'][]='unexpected_listing_data_35084';
        }
    }
    $plan['ready_to_apply']=empty($plan['errors']);
    $plan['metadata_parity']=empty($plan['errors']) && empty($plan['changes']);
    return $plan;
}

if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
    class MAD4B_Archive_Runtime_CLI_V1 {
        /** Inspect live metadata and discover mismatched taxonomy relationships. */
        public function audit($args, $assoc): void {
            $plan = mad4b_archive_filter_plan_v1();
            // Discover live semantic dependencies without making this legacy
            // archive repair command responsible for cross-company writes.
            if (function_exists('mad4b_live_semantic_discovery_v1')) {
                try {
                    $scan = mad4b_live_semantic_discovery_v1();
                    $plan['live_semantic_discovery'] = array(
                        'contract'=>$scan['contract']??null,
                        'findings'=>$scan['counts']??array(),
                        'program_filters'=>array_values(array_filter(
                            (array)($scan['observations']['filters']['definitions']??array()),
                            static fn($r)=>($r['query_var']??'')==='related_program_term_id'
                        )),
                        'rate_counts'=>$scan['observations']['rates']['counts']??array(),
                        'runtime'=>$scan['observations']['runtime']??array(),
                        'browser_acceptance'=>$scan['browser_acceptance']??'UNKNOWN',
                        'read_only'=>true,
                    );
                } catch (Throwable $error) {
                    $plan['live_semantic_discovery']=array(
                        'state'=>'DEFERRED','reason'=>'DISCOVERY_RUNTIME_READBACK_FAILED',
                        'read_only'=>true);
                }
            }
            WP_CLI::log(wp_json_encode($plan,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
        }
        /** Apply only prechecked archive filter semantic differences, with rollback. */
        public function reconcile($args, $assoc): void {
            $plan = mad4b_archive_filter_plan_v1();
            if (empty($plan['ready_to_apply'])) WP_CLI::error('ARCHIVE_RECONCILE_HOLD:'.implode(',',$plan['errors']));
            if (empty($assoc['apply'])) {
                WP_CLI::log(wp_json_encode(['status'=>'DRY_RUN','plan'=>$plan],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));return;
            }
            if (empty($plan['changes'])) {
                WP_CLI::log(wp_json_encode(['status'=>'ALREADY_IN_PARITY','site'=>home_url('/')],JSON_UNESCAPED_SLASHES));return;
            }
            $lock='mad4b_archive_filter_reconcile_lock_v1';
            if (!add_option($lock,['started_at'=>time(),'site'=>home_url('/')],'','no')) WP_CLI::error('RECONCILE_LOCK_BUSY');
            $applied=[];
            try {
                foreach ($plan['changes'] as $change) {
                    $id=$change['post_id'];$key=$change['meta_key'];
                    $before=get_post_meta($id,$key,true);
                    if ($before !== $change['expected_before']) throw new RuntimeException('concurrent_change:'.$id.':'.$key);
                    $expected=$change['desired'];
                    $value=is_string($expected)?wp_slash($expected):$expected;
                    if (!update_post_meta($id,$key,$value)) throw new RuntimeException('update_failed:'.$id.':'.$key);
                    $applied[]=['id'=>$id,'key'=>$key,'before'=>$before];
                    if (get_post_meta($id,$key,true)!==$expected) throw new RuntimeException('readback_failed:'.$id.':'.$key);
                    WP_CLI::log('RECONCILED:'.$id.':'.$key);
                }
                $after=mad4b_archive_filter_plan_v1();
                if (empty($after['metadata_parity'])) throw new RuntimeException('final_parity_failed');
                WP_CLI::log(wp_json_encode(['status'=>'METADATA_PARITY_VERIFIED','site'=>home_url('/'),
                    'updates'=>count($applied),'browser_acceptance_pending'=>true],JSON_UNESCAPED_SLASHES));
            } catch (Throwable $error) {
                foreach (array_reverse($applied) as $change) {
                    if ($change['before']==='' || $change['before']===[]) delete_post_meta($change['id'],$change['key']);
                    else update_post_meta($change['id'],$change['key'],is_string($change['before'])?wp_slash($change['before']):$change['before']);
                }
                delete_option($lock);
                WP_CLI::error('ROLLBACK_AFTER_FAILURE:'.$error->getMessage());
            } finally { delete_option($lock); }
        }
    }
    WP_CLI::add_command('mad4b archive-runtime', 'MAD4B_Archive_Runtime_CLI_V1');
}
