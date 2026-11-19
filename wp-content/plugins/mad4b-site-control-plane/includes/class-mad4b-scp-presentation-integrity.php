<?php
/**
 * MAD4B Presentation Integrity v1 — read-only audit for Elementor + JetEngine.
 * An audit is deliberately opt-in (Readiness scan / WP-CLI), never a frontend
 * request hook. Counts and evidence are generated from native editor data.
 */
defined('ABSPATH') || exit;
require_once __DIR__.'/class-mad4b-scp-presentation-browser-evidence.php';

final class MAD4B_SCP_Presentation_Integrity_V1 {
    public const CONTRACT = 'mad4b.presentation-integrity.v1';
    public const SOURCE = 'mad4b-presentation-integrity';
    private const MAX_TEMPLATES = 700;
    private const MAX_ISSUES = 240;
    private static $complete = false;

    public static function covered(): bool { return self::$complete; }
    public static function browser_covered(): bool {return MAD4B_SCP_Presentation_Browser_Evidence_V1::fresh();}

    private static function issue(string $post_type, int $id, string $code, string $severity,
                                  string $label, array $evidence=[]): array {
        return [
            'id' => hash('sha256', get_current_blog_id().'|'.$post_type.'|'.$id.'|'.$code),
            'post_type' => $post_type, 'object_id' => $id, 'code' => $code,
            'source' => self::SOURCE, 'severity' => $severity,
            'label' => mb_substr($label, 0, 225),
            'source_evidence' => $evidence,
            'remediation_policy' => 'review_original_elementor_design_no_implicit_mutation',
        ];
    }

    /** Count badges that risk shadowing the author's Elementor/JetEngine design.
     * Only inspect registered, currently active first-party wp_head callbacks;
     * inactive/dead code does not qualify as a live CSS override. */
    private static function forced_css_callbacks(): array {
        global $wp_filter;
        $callbacks = $wp_filter['wp_head']->callbacks ?? [];
        $matches = [];
        foreach ($callbacks as $priority => $group) {
            foreach ($group as $entry) {
                $cb = $entry['function'] ?? null;
                try {
                    if (is_array($cb) && count($cb) === 2) {
                        $ref = new ReflectionMethod($cb[0], $cb[1]);
                        $label = (is_object($cb[0]) ? get_class($cb[0]) : (string)$cb[0]).'::'.$cb[1];
                    } elseif (is_string($cb) || $cb instanceof Closure) {
                        $ref = new ReflectionFunction($cb);
                        $label = $ref->getName();
                    } else continue;
                    $file = (string)$ref->getFileName();
                    if (!$file || !str_contains($file, WP_CONTENT_DIR.'/') ||
                        !str_contains(strtolower($file), 'mad4b')) continue;
                    $lines = @file($file, FILE_IGNORE_NEW_LINES);
                    if (!is_array($lines)) continue;
                    $length = min(350, $ref->getEndLine() - $ref->getStartLine() + 1);
                    $source = implode("\n", array_slice($lines, $ref->getStartLine() - 1, $length));
                    if (!str_contains($source, '!important') ||
                        !str_contains($source, 'elementor-element') ||
                        !preg_match('/(?:background-color|border-radius|padding|font-size|color)\\s*:/i', $source)) continue;
                    // This is a competing style owner candidate, not proof of a
                    // browser regression; the frontend DOM/CSS must still be tested.
                    $matches[] = ['callback'=>$label, 'priority'=>(int)$priority,
                                  'file'=>basename($file), 'kind'=>'author_style_override_candidate'];
                } catch (Throwable $e) { continue; }
            }
        }
        return array_slice($matches, 0, 30);
    }

    private static function walk(array $nodes, int $post_id, string $post_type,
                                 array &$counts, array &$issues, array &$query_cache): void {
        foreach ($nodes as $node) {
            if (!is_array($node)) continue;
            $settings = is_array($node['settings'] ?? null) ? $node['settings'] : [];
            if (($node['widgetType'] ?? '') === 'jet-listing-dynamic-field') {
                $counts['dynamic_fields']++;
                $widget = strtolower((string)($node['id'] ?? ''));
                $source = (string)($settings['dynamic_field_source'] ?? '');
                $hide = filter_var($settings['hide_if_empty'] ?? false, FILTER_VALIDATE_BOOLEAN);
                $fallback = (string)($settings['__dynamic__']['field_fallback'] ??
                                      ($settings['field_fallback'] ?? ''));
                $variable = trim((string)($settings['dynamic_field_var_name'] ?? ''));
                if ($source === 'query_var' && $variable === '') {
                    $counts['query_var_missing_name']++;
                    if ($hide) {
                        $counts['unreachable_fallback']++;
                        $code = 'pres_fallback_blocked_'.substr(hash('sha256', $widget), 0, 12);
                        $issues[] = self::issue($post_type, $post_id, $code, 'high',
                            'JetEngine Dynamic Field '.$widget.' has query_var with no variable and Hide if Empty ON; fallback is unreachable.',
                            ['widget_id'=>$widget, 'field_source'=>'query_var',
                             'fallback_kind'=>str_contains($fallback,'jet-query-count')?'jet-query-count':($fallback!==''?'other':'none'),
                             'dom_optimization_related'=>'not_required_for_failure']);
                    } elseif ($fallback !== '') {
                        // A fallback-only display is valid for many legacy widgets.
                        $counts['intentional_fallback_candidates']++;
                    } else {
                        $counts['unbound_empty_fields']++;
                        $code='pres_unbound_field_'.substr(hash('sha256',$widget),0,12);
                        $issues[]=self::issue($post_type,$post_id,$code,'medium',
                            'JetEngine Dynamic Field '.$widget.' has neither query variable nor fallback.',
                            ['widget_id'=>$widget, 'field_source'=>'query_var']);
                    }
                }
                if (!empty($settings['jedv_enabled'])) {
                    foreach ((array)($settings['jedv_conditions'] ?? []) as $condition) {
                        if (!is_array($condition) || ($condition['jedv_condition']??'')!=='query-has-items') continue;
                        $qid = (string)($condition['query_id']??'');
                        if (!$qid) continue;
                        if (!array_key_exists($qid,$query_cache)) {
                            $cls='Jet_Engine\\Query_Builder\\Manager';
                            if (class_exists($cls, false))
                                $query_cache[$qid] = (bool)$cls::instance()->get_query_by_id($qid);
                        }
                        if (isset($query_cache[$qid]) && $query_cache[$qid]===false) {
                            $counts['missing_visibility_queries']++;
                            $code='pres_query_missing_'.substr(hash('sha256',$widget.'|'.$qid),0,12);
                            $issues[]=self::issue($post_type,$post_id,$code,'high',
                                'JetEngine visibility condition on '.$widget.' references unavailable query '.$qid.'.',
                                ['widget_id'=>$widget, 'query_id'=>$qid]);
                        }
                    }
                }
            }
            if (is_array($node['elements'] ?? null))
                self::walk($node['elements'], $post_id, $post_type, $counts, $issues, $query_cache);
        }
    }

    public static function discover(): array {
        self::$complete = false;
        global $wpdb;
        $counts=['templates'=>0,'dynamic_fields'=>0,'query_var_missing_name'=>0,
                 'unreachable_fallback'=>0,'intentional_fallback_candidates'=>0,
                 'unbound_empty_fields'=>0,'missing_visibility_queries'=>0,
                 'forced_style_callbacks'=>0];
        $issues=[]; $query_cache=[];
        $sql = $wpdb->prepare(
            "SELECT p.ID,p.post_type,p.post_status,pm.meta_value AS elementor_data
             FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID
             WHERE pm.meta_key='_elementor_data'
               AND p.post_status IN ('publish','private','draft')
               AND p.post_type IN ('jet-engine','elementor_library')
               AND pm.meta_value LIKE %s ORDER BY p.ID LIMIT %d",
            '%"jet-listing-dynamic-field"%', self::MAX_TEMPLATES+1);
        $rows=$wpdb->get_results($sql,ARRAY_A);
        if (!is_array($rows) || $wpdb->last_error)
            return ['contract'=>self::CONTRACT,'complete'=>false,'reason'=>'template_inventory_unavailable','issues'=>[],'counts'=>$counts];
        if (count($rows)>self::MAX_TEMPLATES)
            return ['contract'=>self::CONTRACT,'complete'=>false,'reason'=>'template_inventory_exceeds_bound','issues'=>[],'counts'=>$counts];
        foreach ($rows as $row) {
            $nodes=json_decode((string)$row['elementor_data'],true);
            if (!is_array($nodes))
                return ['contract'=>self::CONTRACT,'complete'=>false,'reason'=>'elementor_template_invalid_json','issues'=>[],'counts'=>$counts];
            $counts['templates']++;
            $active=$row['post_status']==='publish';
            $before=count($issues);
            self::walk($nodes,(int)$row['ID'],(string)$row['post_type'],$counts,$issues,$query_cache);
            if (!$active) $issues=array_slice($issues,0,$before); // still count draft warnings
            if (count($issues)>self::MAX_ISSUES)
                return ['contract'=>self::CONTRACT,'complete'=>false,'reason'=>'issue_count_exceeds_bound','issues'=>[],'counts'=>$counts];
        }
        // Verified and fresh browser readback only. This is passive WP option
        // ingestion; never launch Chromium or fetch a page on the web request.
        $issues=array_merge($issues,MAD4B_SCP_Presentation_Browser_Evidence_V1::issues());
        // Style ownership is diagnostic, not a license to modify Elementor CSS.
        $forced=self::forced_css_callbacks();
        $counts['forced_style_callbacks']=count($forced);
        foreach ($forced as $row) {
            $code='pres_forced_css_'.substr(hash('sha256',$row['callback']),0,12);
            $issues[]=self::issue('site',0,$code,'medium',
                'MAD4B active frontend CSS may override authored Elementor styles: '.$row['callback'], $row);
        }
        self::$complete=count($issues)<=self::MAX_ISSUES;
        return ['contract'=>self::CONTRACT,'complete'=>self::$complete,
                'issues'=>self::$complete?$issues:[], 'counts'=>$counts,
                'optimization'=>[
                    'jet_engine_optimized_dom'=>!empty(((array)get_option('jet-engine-performance-tweaks',[]))['optimized_dom']),
                    'elementor_optimized_markup'=>get_option('elementor_experiment-e_optimized_markup')==='active',
                ], 'scan_at'=>gmdate('c'), 'scan_mode'=>'readonly_source'];
    }

    public static function cli($args=[], $assoc=[]): void {
        $report=self::discover();
        if (isset($assoc['persist']) && $report['complete'])
            update_option('mad4b_presentation_integrity_last_audit_v1',$report,false);
        $payload=wp_json_encode($report,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        WP_CLI::line($payload);
        if (!$report['complete']) WP_CLI::error('Presentation audit incomplete',false);
    }
}
if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI',false))
    WP_CLI::add_command('mad4b presentation-audit', ['MAD4B_SCP_Presentation_Integrity_V1','cli']);
