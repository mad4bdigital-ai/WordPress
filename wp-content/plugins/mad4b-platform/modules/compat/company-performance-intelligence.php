<?php
/**
 * Company Performance Intelligence — Staging-only, read-only MAD4B Ability.
 * Exact site/source observations and deterministic remediation plans only.
 * No options, products, site content, server config, or presets are modified.
 */
defined('ABSPATH') || exit;
require_once __DIR__.'/adaptive-css-intelligence/adapter.php';
final class MAD4B_Company_Performance_Intelligence {
    const ABILITY = 'mad4b/company-performance-scan-plan';
    const CONTRACT = 'mad4b.company-performance-intelligence.v1';
    const MAX_DOCS = 16;
    const MAX_ACTIVE_PLUGINS = 160;

    public static function boot() {
        add_action('wp_abilities_api_init', [__CLASS__, 'register_ability'], 45);
        add_action('admin_menu', [__CLASS__, 'register_admin_page'], 50);
    }

    public static function register_admin_page() {
        if (wp_get_environment_type() !== 'staging') return;
        add_management_page('Company Performance Intelligence',
            'Company Performance Scan', 'manage_options',
            'mad4b-company-performance-scan', [__CLASS__, 'render_admin_page']);
    }

    public static function render_admin_page() {
        if (wp_get_environment_type() !== 'staging' || !current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to view this report.'));
        }
        $report = self::scan_plan([]);
        echo '<div class="wrap"><h1>Company Performance Intelligence</h1>';
        echo '<p>Read-only Staging scan. No template, cache setting, database, or Production change is performed.</p>';
        if (is_wp_error($report)) {
            echo '<p class="notice notice-error">'.esc_html($report->get_error_message()).'</p></div>';
            return;
        }
        echo '<p><strong>Company:</strong> '.esc_html($report['inventory']['host']).'</p>';
        echo '<p><strong>Critical CSS:</strong> '.esc_html($report['summary']['critical_css_state']);
        echo ' &nbsp; <strong>Fragments:</strong> '.esc_html($report['summary']['fragment_state']);
        echo ' &nbsp; <strong>Critical issues:</strong> '.(int)$report['summary']['p0_count'].'</p>';
        echo '<table class="widefat striped"><thead><tr><th>Priority</th><th>Finding</th><th>State</th><th>Remediation plan</th></tr></thead><tbody>';
        foreach ($report['remediation_plan'] as $step) {
            echo '<tr><td>'.esc_html($step['priority']).'</td><td>'.
                esc_html($step['id']).'</td><td>'.esc_html($step['state']).
                '</td><td>'.esc_html($step['recommendation']).'</td></tr>';
        }
        echo '</tbody></table>';
        echo '<p><strong>Snapshot fingerprint:</strong> <code>'.esc_html($report['scan_snapshot_sha256']).'</code></p>';
        echo '<p><em>Browser evidence and change history are collected separately by the private VPS runner; this page does not claim real-time Lighthouse or apply remediation.</em></p></div>';
    }

    public static function can_read($input = null) {
        return wp_get_environment_type() === 'staging'
            && class_exists('MAD4B_SCP_Policy')
            && is_callable(['MAD4B_SCP_Policy', 'can_read'])
            && (bool) MAD4B_SCP_Policy::can_read();
    }

    public static function register_ability() {
        if (!function_exists('wp_register_ability') ||
            (function_exists('wp_has_ability') && wp_has_ability(self::ABILITY))) return;
        wp_register_ability(self::ABILITY, [
            'label' => 'Scan Company Performance and Plan Remediation',
            'description' => 'Read-only, bounded company-scoped inventory, Elementor template and optimization drift detection, and ordered remediation plan. Does not apply any optimization.',
            'category' => 'mad4b-read',
            'execute_callback' => [__CLASS__, 'scan_plan'],
            'permission_callback' => [__CLASS__, 'can_read'],
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'previous_snapshot_sha256' => [
                        'type' => 'string', 'pattern' => '^[a-f0-9]{64}$'
                    ],
                ],
                'additionalProperties' => false
            ],
            'output_schema' => ['type'=>'object','additionalProperties'=>true],
            'meta' => [
                'public'=>false,'show_in_rest'=>false,
                'mcp'=>['public'=>false,'type'=>'tool','surface'=>'read'],
                'annotations'=>['readonly'=>true,'destructive'=>false,'idempotent'=>true],
            ]
        ]);
    }

    private static function load_config($file) {
        // Fixed in-repository configuration files only; no user-defined paths.
        $path = WPMU_PLUGIN_DIR.'/'.$file;
        if (!is_readable($path) || is_link($path) || filesize($path)>32768) return null;
        $obj=json_decode((string)file_get_contents($path),true);
        return is_array($obj) ? $obj : null;
    }

    private static function add_issue(&$issues,$id,$priority,$state,$evidence,$action,$auto=false) {
        if (count($issues)>=32) return;
        $issues[]=['id'=>$id,'priority'=>$priority,'state'=>$state,
            'evidence'=>$evidence,'recommendation'=>$action,
            'auto_apply_allowed'=>$auto,'change_performed'=>false];
    }

    private static function pinned_docs($conf,$name,&$issues) {
        $out=[];
        if (!is_array($conf) || !is_array($conf['document_guard'] ?? null)) return $out;
        $guard=$conf['document_guard'];
        if (count($guard)>self::MAX_DOCS) {
            self::add_issue($issues,$name.'_guard_overflow','P0','blocked',
                ['count'=>count($guard)],'Reject this preset until its document bounds are reviewed.');
            return $out;
        }
        foreach ($guard as $k=>$sha) {
            if (!ctype_digit((string)$k) || (int)$k<=0 ||
                !is_string($sha) || !preg_match('/^[a-f0-9]{64}$/D',$sha)) continue;
            $id=(int)$k;
            $p=get_post($id);
            if (!$p || $p->post_status!=='publish') {
                $out[(string)$id]=['state'=>'missing_or_unpublished',
                    'expected_sha256'=>$sha,'observed_sha256'=>null];
                self::add_issue($issues,$name.'_document_'.$id,'P0','needs_review',
                    ['document_id'=>$id,'reason'=>'document_missing_or_unpublished'],
                    'Keep the affected asset optimization disabled. Restore or certify the published Elementor document and rerun browser QA.');
                continue;
            }
            $raw=(string)get_post_meta($id,'_elementor_data',true);
            $observed=hash('sha256',$raw);
            $match=hash_equals($sha,$observed);
            $out[(string)$id]=['state'=>$match?'match':'changed',
                'expected_sha256'=>$sha,'observed_sha256'=>$observed,
                'post_modified_gmt'=>(string)$p->post_modified_gmt];
            if (!$match) self::add_issue($issues,$name.'_document_'.$id,'P0','drift',
                ['document_id'=>$id,'reason'=>'elementor_source_hash_changed'],
                'Preserve fail-closed original CSS/JS. Audit the changed widget and above-fold position on Staging for mobile, desktop and WPML before issuing new pinned hashes.');
        }
        return $out;
    }

    /**
     * Existing intelligence engine: independent FCP adoption and LCP remediation
     * decisions. Browser evidence is internal and source-pinned. Never upgrades
     * live CSS solely because FCP improves.
     */
    public static function predictive_css_decisions(): array {
        if (!class_exists('MAD4B_Predictive_CSS_Plan_V2', false)) {
            return ['contract'=>'mad4b.predictive-css-decision.v1','state'=>'provider_missing','decisions'=>[]];
        }
        $plan=MAD4B_Predictive_CSS_Plan_V2::summary();
        $rows=(array)($plan['routes']??[]);
        $raw=get_option('mad4b_perf_predictive_browser_evidence_v1',[]);
        $host=(string)wp_parse_url(home_url('/'),PHP_URL_HOST);
        $evidence_ok=is_array($raw)
            && ($raw['contract']??'')==='mad4b.predictive-css-browser-evidence.v1'
            && ($raw['site_host']??'')===$host
            && ($raw['path']??'')==='/'
            && ($raw['locale']??'')==='en'
            && ($raw['device']??'')==='mobile'
            && isset($raw['samples']) && is_array($raw['samples'])
            && count($raw['samples'])>=2 && count($raw['samples'])<=20
            && !empty($raw['captured_at_utc'])
            && (int)strtotime((string)$raw['captured_at_utc'])>0;
        $lcpTrial=get_option('mad4b_perf_lcp_remediation_evidence_v1',[]);
        $trialRejected=is_array($lcpTrial)
            && ($lcpTrial['contract']??'')==='mad4b.lcp-remediation-evidence.v1'
            && ($lcpTrial['site_host']??'')===$host
            && ($lcpTrial['path']??'')==='/'
            && ($lcpTrial['device']??'')==='mobile'
            && ($lcpTrial['verdict']??'')==='rejected_regression'
            && is_array($lcpTrial['results']??null)
            && count($lcpTrial['results'])>=2;
        $lcpDiagnostics=get_option('mad4b_perf_lcp_diagnostics_v2',[]);
        $lcpDiagnosticsValid=is_array($lcpDiagnostics)
            && ($lcpDiagnostics['contract']??'')==='mad4b.lcp-diagnostics.v2'
            && ($lcpDiagnostics['site_host']??'')===$host
            && ($lcpDiagnostics['environment']??'')===wp_get_environment_type()
            && ($lcpDiagnostics['route']??'')==='/'
            && ($lcpDiagnostics['locale']??'')==='en'
            && ($lcpDiagnostics['device']??'')==='mobile'
            && ($lcpDiagnostics['conclusion']??'')==='no_safe_mobile_lcp_release_proven'
            && is_array($lcpDiagnostics['tests']??null)
            && count($lcpDiagnostics['tests'])===3;
        $modern=self::adaptive_css_decisions_v2();
        $modernRows=(array)($modern['decisions']??[]);
        $decisions=[];
        foreach($rows as $row) {
            if (!is_array($row)) continue;
            $device=(string)($row['device']??'');
            $locale=(string)($row['locale']??'');
            $route=(string)($row['path']??'');
            if (!in_array($device,['mobile','desktop'],true)) continue;
            $gate=(string)($row['performance_gate']??'unverified');
            $samples=[];
            $lcp_image=null;
            if ($evidence_ok && $device==='mobile' && $route==='/' && $locale==='en'
                && is_string($raw['manifest_css_sha256']??null)
                && preg_match('/^[a-f0-9]{64}$/D',$raw['manifest_css_sha256'])
                && (in_array($raw['manifest_css_sha256'],(array)($row['bundle_variant_shas']??[]),true)
                    || str_starts_with($raw['manifest_css_sha256'],(string)($row['bundle_sha']??'')))) {
                foreach($raw['samples'] as $sample) {
                    if (!is_array($sample) || empty($sample['first_slide_present'])
                        || empty($sample['candidate_applied'])) continue;
                    $keys=['baseline_fcp_ms','candidate_fcp_ms','baseline_lcp_ms','candidate_lcp_ms'];
                    $valid=true;
                    foreach($keys as $key) {
                        if (!isset($sample[$key]) || !is_numeric($sample[$key])
                            || (float)$sample[$key]<100 || (float)$sample[$key]>120000) $valid=false;
                    }
                    if (!$valid) continue;
                    $samples[]=[
                        'fcp_delta_ms'=>(int)$sample['candidate_fcp_ms']-(int)$sample['baseline_fcp_ms'],
                        'lcp_delta_ms'=>(int)$sample['candidate_lcp_ms']-(int)$sample['baseline_lcp_ms'],
                        'cls_delta'=>is_numeric($sample['candidate_cls']??null) && is_numeric($sample['baseline_cls']??null)
                            ? round((float)$sample['candidate_cls']-(float)$sample['baseline_cls'],6) : null,
                    ];
                    $asset=(string)($sample['lcp_asset']??'');
                    if (str_starts_with($asset,'https://'.$host.'/wp-content/uploads/')
                        && strlen($asset)<512) $lcp_image=$asset;
                }
            }
            // Use modern paired after-TTFB evidence in preference to historic raw
            // wall timings; otherwise the old and new dashboard would disagree.
            $normalized=null;
            foreach($modernRows as $modernRow) {
                if (($modernRow['path']??null)===$route && ($modernRow['locale']??null)===$locale
                    && ($modernRow['device']??null)===$device
                    && (int)($modernRow['evaluation']['pairs']??0)>0) {
                    $normalized=$modernRow['evaluation'];break;
                }
            }
            if ($normalized && count((array)($normalized['pair_fcp_delta_ms']??[]))
                ===count((array)($normalized['pair_lcp_delta_ms']??[]))) {
                $samples=[];
                foreach($normalized['pair_fcp_delta_ms'] as $idx=>$delta) {
                    $samples[]=['fcp_delta_ms'=>(int)$delta,
                        'lcp_delta_ms'=>(int)$normalized['pair_lcp_delta_ms'][$idx],
                        'cls_delta'=>null];
                }
            }
            $sample_count=count($samples);
            $fcp_wins=count(array_filter($samples,static fn($x)=>$x['fcp_delta_ms']<0));
            $lcp_regressions=count(array_filter($samples,static fn($x)=>$x['lcp_delta_ms']>0));
            $fcp_deltas=array_column($samples,'fcp_delta_ms');
            $lcp_deltas=array_column($samples,'lcp_delta_ms');
            $historic_fcp_delta=(int)($row['async_fcp_ms']??0)-(int)($row['baseline_fcp_ms']??0);
            $historic_lcp_delta=(int)($row['async_lcp_ms']??0)-(int)($row['baseline_lcp_ms']??0);
            $release_blocked=$gate!=='pass' || ($plan['runtime']??'off')!=='on';
            if ($sample_count && $lcp_regressions>0) {
                $decision='retain_fcp_candidate_remediate_lcp';
                $state='requires_lcp_remediation';
                $recommendation='Retain Predictive CSS as an FCP optimization candidate on Staging, create a separate LCP remediation step, and keep live CSS unchanged until repeated mobile tests pass.';
            } elseif ($sample_count && $fcp_wins>0) {
                $decision='candidate_for_device_only_validation';
                $state='needs_repeat_validation';
                $recommendation='Keep the FCP candidate and repeat device-specific LCP/CLS and interaction tests before a mobile-only release.';
            } elseif ($historic_fcp_delta<0 && $historic_lcp_delta>0) {
                $decision='historic_fcp_gain_lcp_regression';
                $state='requires_lcp_remediation';
                $recommendation='Preserve the FCP optimization candidate; investigate the LCP regression separately before enabling this device.';
            } elseif ($historic_fcp_delta<0) {
                $decision='historic_fcp_candidate';
                $state='needs_repeat_validation';
                $recommendation='Preserve the FCP candidate; run current browser A/B and certify LCP/CLS independently.';
            } else {
                $decision='unverified';
                $state='needs_baseline';
                $recommendation='Collect device-specific FCP, LCP, CLS and resource-timing evidence before making an adoption decision.';
            }
            $steps=[
                ['id'=>'preserve_fcp_candidate','stage'=>1,'status'=>'candidate','action'=>'Retain the measured first-paint alternative in the device canary; do not switch all visitors to it.'],
                ['id'=>'lcp_image_priority','stage'=>2,'status'=>'required','action'=>'Resolve the real LCP asset first. Keep exactly one high-priority preload for the first visible destination background, remove competing duplicate preloads, and verify request timing and responsive dimensions.'],
                ['id'=>'critical_css_for_lcp','stage'=>3,'status'=>'required','action'=>'Reduce first-screen critical CSS to selectors needed by the LCP element and hero; keep native complete CSS as a nonblocking fallback with no layout or interaction breakage.'],
                ['id'=>'independent_browser_validation','stage'=>4,'status'=>'required','action'=>'Compare at least five cold and warm mobile A/B samples with the same viewport/network and current CSS SHA. Gate on FCP, LCP, CLS, Swiper, JetSmartFilters and booking CTA.'],
                ['id'=>'device_scoped_release','stage'=>5,'status'=>'blocked','action'=>'Promote only the verified locale/route/device/CSS bundle once LCP no longer regresses; keep desktop on native CSS and automatically revert on regression.'],
            ];
            if ($device==='mobile' && $route==='/' && $trialRejected) {
                $steps[1]['status']='experiment_rejected';
                $steps[1]['action']='Do not deploy the isolated hero-preload arbitration pilot: two reversed-order A/B runs worsened FCP and LCP. This path was reverted.';
                $steps[2]['status']='next_required';
                $steps[2]['action']='Next: isolate the first destination background dependencies inside the 530KB inline Critical CSS, reduce CSS needed before first card paint, and profile CSS/JS scheduling without deprioritizing the hero preload.';
            }
            if ($device==='mobile' && $route==='/' && $locale==='en' && $lcpDiagnosticsValid) {
                // Nine additional browser A/B variants ruled out previously
                // proposed CSS priority, incomplete critical CSS and autoplay
                // toggles. Preserve the measured native fallback; avoid loops.
                $steps[2]['status']='render_sequence_analysis_required';
                $steps[2]['action']='Measured LCP is the first JetEngine Swiper destination background in Elementor template 45927, not a large image download. CSS-only removal triggered CLS~0.16, CSS priority regressed LCP, and pausing autoplay was inconsistent. Next isolate above-fold Swiper/Elementor first-card rendering and cold TTFB/cache eligibility without caching currency, booking or visitor context. Repeat 5+ controlled A/B tests before any mobile activation.';
            }
            $eligible=$sample_count>=5 && $fcp_wins===$sample_count
                && $lcp_regressions===0 && !$release_blocked;
            $decisions[]=[
                'route'=>$route,'locale'=>$locale,'device'=>$device,
                'recommendation_state'=>$state,
                'decision'=>$decision,
                'recommendation'=>$recommendation,
                'provisional_adoption'=>str_contains($decision,'fcp_'),
                'live_activation_eligible'=>$eligible,
                'release_gate'=>$gate,
                'runtime'=>$plan['runtime']??'off',
                'evidence_source'=>$normalized?'normalized_after_ttfb_v2':
                    ($sample_count?'legacy_raw_browser_timings':'historic_manifest_only'),
                'evidence_samples'=>$sample_count,
                'fcp_improved_samples'=>$fcp_wins,
                'lcp_regressed_samples'=>$lcp_regressions,
                'fcp_delta_ms'=>$fcp_deltas,
                'lcp_delta_ms'=>$lcp_deltas,
                'historic_fcp_delta_ms'=>$historic_fcp_delta,
                'historic_lcp_delta_ms'=>$historic_lcp_delta,
                'measured_lcp_element'=>$row['measured_lcp']??null,
                'verified_lcp_image'=>$lcp_image,
                'lcp_preload_pilot'=>$device==='mobile' && $trialRejected ? 'rejected_and_rolled_back' : 'not_accepted',
                'lcp_diagnostics_state'=>$device==='mobile' && $lcpDiagnosticsValid ?
                    'no_safe_mobile_lcp_release_proven' : 'not_recorded',
                'lcp_diagnostic_trials'=>$device==='mobile' && $lcpDiagnosticsValid ?
                    array_sum(array_map('count',$lcpDiagnostics['tests'])) : 0,
                'critical_css_gzip_bytes'=>(int)($row['critical_gzip_bytes']??0),
                'next_steps'=>$steps,
            ];
        }
        return [
            'contract'=>'mad4b.predictive-css-decision.v1',
            'state'=>empty($decisions)?'no_device_plans':'planned',
            'site_host'=>$host,
            'release'=>$plan['release']??'none',
            'runtime'=>$plan['runtime']??'off',
            'evidence_capture_at'=>$evidence_ok?$raw['captured_at_utc']:null,
            'device_independence'=>!empty($plan['device_independent_release_supported']),
            'decisions'=>$decisions,
            'mutation_performed'=>false,
        ];
    }

    /**
     * CSS Policy v2: deterministic, TTFB-normalized evidence instead of an
     * ungrounded AI score. Per company, source SHA, page, WPML locale and device.
     * This function only DECIDES; it never enables global LiteSpeed settings.
     */
    public static function css_evidence_policy_v2(array $record, array $context): array {
        $out = [
            'contract'=>'mad4b.css-decision.v2', 'state'=>'hold',
            'reason_codes'=>[], 'candidate'=>($record['candidate']??'unsubmitted'),
            'pairs'=>0,'cold_pairs'=>0,'warm_pairs'=>0,'viewports'=>[],
            'fcp_post_ttfb_delta_p75_ms'=>null,'lcp_post_ttfb_delta_p75_ms'=>null,
            'lcp_regressed_pairs'=>0,'cls_delta_max'=>null,
            'pair_fcp_delta_ms'=>[], 'pair_lcp_delta_ms'=>[], 'decision'=>'native',
            'public_eligible'=>false,'requires'=>['native_css_fallback'],
        ];
        $fail = static function (string $code) use (&$out) {
            if (!in_array($code, $out['reason_codes'], true)) $out['reason_codes'][]=$code;
        };
        if (($record['contract']??'')!=='mad4b.css-evidence-record.v2') {
            $fail('no_v2_evidence'); return $out;
        }
        foreach (['site_host','environment','path','locale','device','source_bundle_sha','elementor_doc_fingerprint','css_epoch'] as $key) {
            $expected=$context[$key]??null;
            if ($expected===null || !isset($record[$key]) || $record[$key]!==$expected) {
                $fail('source_scope_mismatch_'.$key);return $out;
            }
        }
        if (!in_array($record['device'],['mobile','desktop'],true)
            || !preg_match('/^[a-f0-9]{64}$/D',(string)$record['source_bundle_sha'])
            || !preg_match('/^[a-f0-9]{64}$/D',(string)$record['elementor_doc_fingerprint'])
            || !in_array($record['source_bundle_sha'],(array)($context['approved_variant_shas']??[]),true)) {
            $fail('unknown_css_variant');return $out;
        }
        $date=(string)($record['captured_at_utc']??'');
        $stamp=$date!=='' ? strtotime($date) : false;
        if (!$stamp || $stamp>time()+300 || $stamp<time()-72*3600) {
            $fail('evidence_stale_or_unclocked');return $out;
        }
        // Native LiteSpeed CSS changes invalidate all prior browser evidence.
        // Historic results remain readable, but they cannot certify delivery
        // under a different CSS configuration.
        $changed=get_option('mad4b_lsc_css_drift_event_v1',[]);
        if (is_array($changed) && ($changed['contract']??'')==='mad4b.lsc.css-option-event.v1'
            && (int)($changed['observed_at']??0)>0
            && $stamp < (int)$changed['observed_at']) {
            $fail('litespeed_css_changed_after_evidence');return $out;
        }

        if (($record['source']??'')!=='staging_chromium_ab'
            || !in_array(($record['candidate']??''),['critical_async','ucss_async','ucss_inline'],true)) {
            $fail('unknown_experiment_source_or_strategy');return $out;
        }
        $pairs=$record['pairs']??null;
        if (!is_array($pairs) || count($pairs)<1 || count($pairs)>24) {
            $fail('invalid_evidence_pair_count');return $out;
        }
        $deltasF=[];$deltasL=[];$maxCls=0.0;$interactionValid=true;$visualValid=true;
        $pairedControls=true;$cold=0;$warm=0;$views=[];$regressed=0;
        foreach ($pairs as $pair) {
            if (!is_array($pair) || !is_array($pair['native']??null) || !is_array($pair['candidate']??null)) {
                $fail('invalid_pair_schema');return $out;
            }
            $native=$pair['native'];$candidate=$pair['candidate'];
            foreach ([$native,$candidate] as $sample) {
                foreach (['ttfb_ms','fcp_ms','lcp_ms'] as $metric) {
                    if (!isset($sample[$metric]) || !is_numeric($sample[$metric]) ||
                        (float)$sample[$metric]<1 || (float)$sample[$metric]>120000) {
                        $fail('invalid_browser_metric');return $out;
                    }
                }
                if ((float)$sample['fcp_ms']<(float)$sample['ttfb_ms'] ||
                    (float)$sample['lcp_ms']<(float)$sample['fcp_ms'] ||
                    !isset($sample['cls']) || !is_numeric($sample['cls']) ||
                    (float)$sample['cls']<0 || (float)$sample['cls']>1) {
                    $fail('invalid_browser_timing_or_cls');return $out;
                }
                if (!array_key_exists('js_errors',$sample) || !is_int($sample['js_errors']) || $sample['js_errors']<0 || $sample['js_errors']>0) {
                    $interactionValid=false;
                }
            }
            if (empty($pair['same_document']) || empty($pair['same_css_source'])
                || !in_array(($pair['order']??''),['AB','BA'],true)) {
                $pairedControls=false;
            }
            $mode=(string)($pair['cache_tier']??'unknown');
            if ($mode==='cold') $cold++;
            elseif ($mode==='warm') $warm++;
            $viewport=(int)($pair['viewport_width']??0);
            if ($viewport>=280 && $viewport<=2200) $views[$viewport]=true;
            else $pairedControls=false;
            if (($pair['interaction_state']??'')!=='pass') $interactionValid=false;
            if (($pair['visual_state']??'')!=='pass') $visualValid=false;
            $fcp=((float)$candidate['fcp_ms']-(float)$candidate['ttfb_ms'])
                -((float)$native['fcp_ms']-(float)$native['ttfb_ms']);
            $lcp=((float)$candidate['lcp_ms']-(float)$candidate['ttfb_ms'])
                -((float)$native['lcp_ms']-(float)$native['ttfb_ms']);
            $deltasF[]=$fcp;$deltasL[]=$lcp;
            if ($lcp>150) $regressed++;
            $maxCls=max($maxCls, (float)$candidate['cls']-(float)$native['cls']);
        }
        $out['pair_fcp_delta_ms']=array_map(static fn($x)=>(int)round($x),$deltasF);
        $out['pair_lcp_delta_ms']=array_map(static fn($x)=>(int)round($x),$deltasL);
        sort($deltasF,SORT_NUMERIC);sort($deltasL,SORT_NUMERIC);
        $ix=(int)ceil(.75*count($deltasF))-1;
        $out['pairs']=count($pairs);$out['cold_pairs']=$cold;$out['warm_pairs']=$warm;
        $out['viewports']=array_keys($views);sort($out['viewports'],SORT_NUMERIC);
        $out['fcp_post_ttfb_delta_p75_ms']=(int)round($deltasF[$ix]);
        $out['lcp_post_ttfb_delta_p75_ms']=(int)round($deltasL[$ix]);
        $out['lcp_regressed_pairs']=$regressed;
        $out['cls_delta_max']=round($maxCls,6);
        if (!$pairedControls) $fail('pair_controls_not_proven');
        if (!$interactionValid) $fail('js_or_interaction_not_proven');
        if (!$visualValid) $fail('visual_parity_not_proven');
        if ($cold<3 || $warm<3 || count($views)<2 || count($pairs)<6) $fail('insufficient_cold_warm_viewport_coverage');
        if ($regressed>0 || $out['lcp_post_ttfb_delta_p75_ms']>0) $fail('lcp_regression');
        if ($maxCls>0.02) $fail('cls_regression');
        if ($out['fcp_post_ttfb_delta_p75_ms']>=0) $fail('fcp_not_improved');
        if (in_array($record['candidate'],['ucss_async','ucss_inline'],true)
            && ($record['dynamic_states_covered']??'')!=='validated') $fail('ucss_dynamic_states_not_proven');
        if (($context['discovery_fresh']??false)!==true) $fail('content_discovery_stale');
        if ((string)($context['live_runtime_mode']??'off')!=='on') $fail('public_runtime_not_enabled');
        if ($out['reason_codes']) {
            $out['state']=$regressed>0?'hold_lcp_regression':'needs_evidence';
            $out['decision']='native';
            return $out;
        }
        $out['state']='passed_evidence';
        $out['decision']='eligible_for_device_release';
        $out['public_eligible']=true;
        return $out;
    }

    /** Live multi-company evaluation. No hardcoded EN/home, no mutation. */
    public static function adaptive_css_decisions_v2(): array {
        $host=(string)wp_parse_url((string)get_option('home',''),PHP_URL_HOST);
        $file=WP_CONTENT_DIR.'/mad4b-predictive-css-v2/manifest.json';
        $result=['contract'=>'mad4b.css-adaptive-policy.v2','host'=>$host,
            'mode'=>'native_on_missing_evidence','runtime'=>get_option('mad4b_perf_predictive_css_v2','off'),
            'decisions'=>[],'all_languages'=>[], 'mutated'=>false];
        if (!is_file($file) || is_link($file) || filesize($file)>65536) {
            $result['state']='manifest_missing'; return $result;
        }
        $manifest=json_decode((string)file_get_contents($file),true);
        if (!is_array($manifest) || ($manifest['contract']??'')!=='mad4b.predictive-css-plan.v2'
            || ($manifest['site_host']??'')!==$host || !is_array($manifest['routes']??null)
            || count($manifest['routes'])>64) {
            $result['state']='manifest_invalid';return $result;
        }
        $evidence=get_option('mad4b_perf_css_browser_evidence_v2',[]);
        $records=is_array($evidence) && ($evidence['contract']??'')==='mad4b.css-evidence-collection.v2'
            && ($evidence['site_host']??'')===$host && is_array($evidence['records']??null)
            && count($evidence['records'])<=192 ? $evidence['records'] : [];
        $languages=apply_filters('wpml_active_languages',null,'skip_missing=0');
        $langs=is_array($languages)?array_keys($languages):[(string)apply_filters('wpml_current_language','en')];
        $result['all_languages']=$langs;
        $result['evidence_record_count']=count($records);
        $disc=get_option('mad4b_adaptive_presentation_discovery_v1',[]);
        $fresh=is_array($disc) && ($disc['state']??'')==='observed'
            && !empty($disc['published_graph_digest']) && empty($disc['truncated'])
            && (int)get_option('mad4b_adaptive_presentation_scan_needed_v1',0)<=(int)($disc['scanned_at']??0);
        $runtime=(string)$result['runtime'];
        foreach($manifest['routes'] as $route) {
            if (!is_array($route) || !is_string($route['path']??null)
                || !is_string($route['language']??null))continue;
            $sha=(string)($route['elementor_doc_fingerprint']??'');
            $variants=array_values(array_filter(array_map(static fn($v)=>is_array($v)?($v['bundle_sha']??null):null,
                (array)($route['bundle_variants']??[])),static fn($x)=>is_string($x)));
            if (!$variants && is_string($route['bundle_sha']??null))$variants=[$route['bundle_sha']];
            foreach(['mobile','desktop'] as $device) {
                if (!is_array($route['devices'][$device]??null)) continue;
                $record=null;
                foreach($records as $r) {
                    if (is_array($r) && ($r['path']??'')===$route['path']
                        && ($r['locale']??'')===$route['language']
                        && ($r['device']??'')===$device) {$record=$r;break;}
                }
                $ctx=['site_host'=>$host,'environment'=>wp_get_environment_type(),
                    'path'=>$route['path'],'locale'=>$route['language'],'device'=>$device,
                    'source_bundle_sha'=>is_array($record)?($record['source_bundle_sha']??null):null,
                    'elementor_doc_fingerprint'=>$sha,'css_epoch'=>(int)($route['css_epoch']??-1),
                    'approved_variant_shas'=>$variants,
                    'discovery_fresh'=>$fresh && ($route['discovery_digest']??'')===($disc['published_graph_digest']??'')
                        && (int)($route['css_epoch']??-1)===(int)get_option('mad4b_css_ai_epoch_v1',0),
                    'live_runtime_mode'=>$runtime];
                $decision=self::css_evidence_policy_v2($record??[],$ctx);
                $result['decisions'][]=['path'=>$route['path'],'locale'=>$route['language'],'device'=>$device,
                    'manifest_gate'=>$route['devices'][$device]['performance_gate']['status']??'unverified',
                    'source_sha256'=>is_array($record)?($record['source_bundle_sha']??null):null,
                    'evaluation'=>$decision];
            }
        }
        $represented=array_unique(array_column($result['decisions'],'locale'));
        $result['languages_missing_css_plan']=array_values(array_diff($langs,$represented));
        $result['state']='evaluated';
        return $result;
    }

    /** Compute a short-lived runtime readback, not a certificate or manual approval. */
    public static function refresh_css_policy_receipt_v2(): array {
        $analysis=self::adaptive_css_decisions_v2();
        $host=(string)($analysis['host']??'');
        $manifestFile=WP_CONTENT_DIR.'/mad4b-predictive-css-v2/manifest.json';
        $manifest=(is_file($manifestFile)&&!is_link($manifestFile)&&filesize($manifestFile)<=65536)
            ?json_decode((string)file_get_contents($manifestFile),true):[];
        if (!is_array($manifest))$manifest=[];
        $release=(string)($manifest['release_state']??'none');
        $now=time();$policies=[];$approved=0;
        $ownerCompatible=class_exists('MAD4B_LSC_Local_Provider_V1',false)
            && MAD4B_LSC_Local_Provider_V1::local_css_eligible();
        foreach((array)($analysis['decisions']??[]) as $entry) {
            if (!$ownerCompatible) continue;
            $evaluation=$entry['evaluation']??[];
            if (!is_array($evaluation) || empty($evaluation['public_eligible'])
                || ($evaluation['candidate']??'')!=='critical_async'
                || !in_array($release,['certified','device_certified'],true))continue;
            $route=$manifest['routes'][($entry['path']??'').'|'.($entry['locale']??'')]??[];
            $device=(string)($entry['device']??'');
            $source=(string)($entry['source_sha256']??'');
            $variant=null;
            foreach((array)($route['bundle_variants']??[]) as $v) {
                if (is_array($v) && ($v['bundle_sha']??'')===$source)$variant=$v;
            }
            $plan=is_array($variant)?($variant['devices'][$device]??[]):($route['devices'][$device]??[]);
            $criticalSha=(string)($plan['critical_sha']??'');
            if (($plan['state']??'')!=='certified' || ($plan['performance_gate']['status']??'')!=='pass'
                || !preg_match('/^[a-f0-9]{64}$/D',$criticalSha) || !$source)continue;
            $key=self::css_runtime_policy_key_v2(
                $host,(string)$entry['path'],(string)$entry['locale'],$device,$source,
                (string)($route['elementor_doc_fingerprint']??''),$criticalSha,
                (int)($route['css_epoch']??-1));
            $policies[$key]=['decision'=>'passed_evidence','route'=>$entry['path'],
                'device'=>$device,'locale'=>$entry['locale'],'expires_at_epoch'=>$now+3600];
            $approved++;
        }
        $receipt=['contract'=>'mad4b.css-runtime-policy.v2','site_host'=>$host,
            'environment'=>wp_get_environment_type(),'evaluated_at_epoch'=>$now,
            'evaluated_at_utc'=>gmdate('c',$now),'approved_count'=>$approved,
            'policy_count'=>count((array)($analysis['decisions']??[])),
            'state'=>$approved?'device_scoped_eligible':'native_hold','policies'=>$policies,
            'reason_summary'=>array_values(array_unique(array_merge(...array_map(
                static fn($d)=>(array)($d['evaluation']['reason_codes']??[]),
                (array)($analysis['decisions']??[])
            ))))];
        // The options row is the only result carrier. No new worker, signatures,
        // broker, or tenant-global blocking prerequisite is introduced.
        $previous=get_option('mad4b_perf_css_policy_receipt_v2',[]);
        update_option('mad4b_perf_css_policy_receipt_v2',$receipt,false);
        // A previous live pass that disappeared must not remain in an HTML cache.
        if (is_array($previous) && !empty($previous['policies'])) {
            foreach((array)$previous['policies'] as $key=>$policy) {
                if (isset($policies[$key]) || !is_array($policy))continue;
                $path=(string)($policy['route']??'');
                if (($previous['site_host']??'')===$host
                    && str_starts_with($path,'/') && !str_contains($path,'..')
                    && !str_contains($path,'?') && strlen($path)<256) {
                    // Use tenant's canonical origin, not locale-prefixed home_url().
                    $origin=rtrim((string)get_option('home',''),'/');
                    if ((string)wp_parse_url($origin,PHP_URL_HOST)===$host)
                        do_action('litespeed_purge_url',$origin.$path);
                }
            }
        }
        return $receipt;
    }

    /** Same bounded digest on write/evaluate and frontend read. */
    public static function css_runtime_policy_key_v2(string $host,string $path,string $locale,
        string $device,string $sourceSha,string $documentSha,string $criticalSha,int $epoch): string {
        return hash('sha256',implode('|',[$host,$path,$locale,$device,$sourceSha,
            $documentSha,$criticalSha,(string)$epoch]));
    }

    public static function scan_plan($input=[]) {
        if (wp_get_environment_type() !== 'staging') {
            return new WP_Error('company_performance_environment_denied',
                'Performance Intelligence is restricted to Staging.');
        }
        if (!is_array($input) || array_diff(array_keys($input), ['previous_snapshot_sha256']) ||
            (isset($input['previous_snapshot_sha256']) &&
             (!is_string($input['previous_snapshot_sha256']) ||
              !preg_match('/^[a-f0-9]{64}$/D',$input['previous_snapshot_sha256'])))) {
            return new WP_Error('company_performance_invalid_input','Only an optional exact prior digest is accepted.');
        }
        $issues=[];
        $host=(string)wp_parse_url(home_url('/'),PHP_URL_HOST);
        if (!preg_match('/^[a-z0-9.-]{4,150}$/D',$host)) {
            return new WP_Error('company_performance_host_invalid','Site host is not valid.');
        }
        $critical=self::load_config('company-critical-css-preset.json');
        $fragments=self::load_config('company-performance-fragment-preset.json');

        $active=get_option('active_plugins',[]);
        if (!is_array($active) || count($active)>self::MAX_ACTIVE_PLUGINS) {
            $active=[];
            self::add_issue($issues,'plugin_inventory_bound','P1','unknown',
                ['reason'=>'inventory_unavailable_or_overflow'],'Review active plugin inventory before certifying performance changes.');
        }
        $tracked=[
            'elementor'=>'elementor/elementor.php',
            'elementor_pro'=>'elementor-pro/elementor-pro.php',
            'jetengine'=>'jet-engine/jet-engine.php',
            'jetsmartfilters'=>'jet-smart-filters/jet-smart-filters.php',
            'woocommerce'=>'woocommerce/woocommerce.php',
            'wpml'=>'sitepress-multilingual-cms/sitepress.php',
            'litespeed'=>'litespeed-cache/litespeed-cache.php',
            'uael'=>'ultimate-elementor/ultimate-elementor.php',
            'control_plane'=>'mad4b-site-control-plane/mad4b-site-control-plane.php',
        ];
        $plugins=[];
        foreach($tracked as $key=>$slug) {
            $plugins[$key]=['active'=>in_array($slug,$active,true)];
        }
        $versions=[
            'elementor'=>defined('ELEMENTOR_VERSION')?(string)ELEMENTOR_VERSION:'',
            'jetengine'=>defined('JET_ENGINE_VERSION')?(string)JET_ENGINE_VERSION:'',
            'woo'=>defined('WC_VERSION')?(string)WC_VERSION:'',
            'uael'=>defined('UAEL_VER')?(string)UAEL_VER:'',
            'litespeed'=>defined('LSCWP_V')?(string)LSCWP_V:'',
        ];

        $critical_docs=self::pinned_docs($critical,'critical_css',$issues);
        $critical_state='unconfigured';
        if ($critical!==null) {
            $critical_state=($critical['enabled']??false)?'enabled':'disabled';
            if (($critical['contract']??'')!=='mad4b.company-critical-css-preset.v1' ||
                strtolower((string)($critical['site_host']??''))!==strtolower($host) ||
                ($critical['environment']??'')!=='staging') {
                $critical_state='invalid_contract';
                self::add_issue($issues,'critical_css_contract','P0','blocked',
                    ['reason'=>'invalid_tenant_scope_or_contract'],
                    'Keep the Critical CSS optimization disabled until company host and contract are reconciled.');
            }
            if (($critical['enabled']??false) &&
                ($critical['uael_version']??'')!==$versions['uael']) {
                $critical_state='fail_closed_version_drift';
                self::add_issue($issues,'critical_css_uael_version','P0','drift',
                    ['configured_version'=>$critical['uael_version']??'','observed_version'=>$versions['uael']],
                    'Keep normal CSS; recertify UAEL upgrade and device layouts before repinning.');
            }
            foreach($critical_docs as $row) {
                if ($row['state']!=='match') $critical_state='fail_closed_document_drift';
            }
        } else {
            self::add_issue($issues,'critical_css_config_missing','P1','missing',
                ['reason'=>'critical_preset_unavailable'],
                'Locate and validate the company-scoped Critical CSS config before any optimization.');
        }

        $fragment_state='unconfigured';
        $fragment_targets=[];
        if ($fragments!==null) {
            $fragment_state=($fragments['enabled']??false)?'enabled':'disabled';
            if (($fragments['contract']??'')!=='mad4b.company-performance-fragments.v1' ||
                ($fragments['environment']??'')!=='staging' ||
                strtolower((string)($fragments['site_host']??''))!==strtolower($host)) {
                $fragment_state='invalid_contract';
                self::add_issue($issues,'fragment_contract','P0','blocked',
                    ['reason'=>'invalid_tenant_scope_or_contract'],
                    'Keep fragment caches disabled until tenant contract is restored.');
            }
            foreach (['menu_document_id','attractions_document_id','attractions_listing_id'] as $name) {
                $id=(int)($fragments['targets'][$name]??0);
                if ($id<1) continue;
                $p=get_post($id);
                $fragment_targets[$name]=[
                    'document_id'=>$id,
                    'status'=>$p?(string)$p->post_status:'missing',
                    'modified_gmt'=>$p?(string)$p->post_modified_gmt:'',
                    'elementor_sha256'=>$p?hash('sha256',(string)get_post_meta($id,'_elementor_data',true)):null
                ];
            }
            // No content hashes are pinned for cards in v2: a 20s TTL
            // does NOT prove prices, visitor personalization, or term count parity.
            if ($fragment_state==='enabled') self::add_issue($issues,'fragment_visual_parity','P2','verification_required',
                ['ttl_seconds'=>$fragments['fragment_ttl_seconds']??null,
                 'risk'=>'dynamic_content_or_count_can_change_between_scans'],
                'Recheck anonymous English page cards, IDs, counts, menu images and carousel during browser QA; keep auth/cookie/geo-market bypass.');
        }

        $adaptive=self::adaptive_css_decisions_v2();
        $uncovered=(array)($adaptive['languages_missing_css_plan']??[]);
        if ($uncovered) self::add_issue($issues,'predictive_css_language_coverage','P1','missing_device_plans',
            ['locales'=>$uncovered,'source'=>'native_manifest_and_wpml_active_languages'],
            'Build exact route/locale/device CSS candidates for missing WPML languages; never silently copy an English CSS release across locales.');
        $predictive=self::predictive_css_decisions();
        foreach((array)($predictive['decisions']??[]) as $decision) {
            $device=(string)($decision['device']??'');
            $scope=(string)($decision['route']??'')==='/'?'home':'route';
            if ($device==='mobile' && ($decision['recommendation_state']??'')==='requires_lcp_remediation') {
                self::add_issue($issues,'predictive_css_fcp_candidate_mobile','P1','conditional_adoption',
                    ['device'=>'mobile','scope'=>$scope,'fcp_wins'=>$decision['fcp_improved_samples'],
                     'samples'=>$decision['evidence_samples']],
                    'Keep the Predictive CSS FCP improvement as a Staging candidate; advance it alongside an independent LCP fix, not as an immediate live rollout.');
                self::add_issue($issues,'predictive_css_mobile_lcp_recovery','P1','lcp_remediation_required',
                    ['device'=>'mobile','scope'=>$scope,
                     'lcp_regressions'=>$decision['lcp_regressed_samples'],
                     'samples'=>$decision['evidence_samples'],
                     'lcp_asset'=>$decision['verified_lcp_image']],
                    !empty($decision['lcp_preload_pilot']) && $decision['lcp_preload_pilot']==='rejected_and_rolled_back'
                        ? 'First image-preload pilot failed in two reverse-order A/B rounds and was rolled back. Next isolate 530KB Critical CSS LCP dependencies and JS/render scheduling; repeat 5+ device-specific cold/warm tests before rollout.'
                        : 'LCP recovery: measure image priority and CSS dependencies, optimize the first card, and rerun browser A/B before device-only promotion.');
            } elseif ($device==='desktop' && ($decision['recommendation_state']??'')==='requires_lcp_remediation') {
                self::add_issue($issues,'predictive_css_desktop_lcp_hold','P1','native_css_preserved',
                    ['device'=>'desktop','scope'=>$scope,'historic_lcp_delta_ms'=>$decision['historic_lcp_delta_ms']],
                    'Keep desktop on native CSS while investigating its independent LCP regression; do not block mobile remediation based on desktop evidence.');
            }
        }

        $settings=[
            'woocommerce_default_customer_address'=>(string)get_option('woocommerce_default_customer_address',''),
            'woocommerce_calc_taxes'=>(string)get_option('woocommerce_calc_taxes',''),
            'currency_base'=>(string)get_option('woocommerce_currency',''),
            'wp_cron_disabled'=>defined('DISABLE_WP_CRON') && (bool)DISABLE_WP_CRON,
        ];
        if ($fragment_state==='enabled' && ($settings['woocommerce_default_customer_address']!=='base' ||
                $settings['woocommerce_calc_taxes']!=='no')) {
            self::add_issue($issues,'market_cache_guard','P0','unsafe_market_context',
                ['default_customer_address'=>$settings['woocommerce_default_customer_address'],
                 'calc_taxes'=>$settings['woocommerce_calc_taxes']],
                'Disable any affected fragment caching and audit GeoIP/currency/tax variance before considering activation.');
        }
        $settings['js_defer_policy']='not_scanned_by_browser';
        $settings['css_combine_policy']='not_scanned_by_browser';
        $readable=['critical_config'=>$critical!==null,'fragment_config'=>$fragments!==null];
        $source=[
            'host'=>$host,'environment'=>wp_get_environment_type(),
            'tracked_plugins'=>$plugins,'runtime_versions'=>$versions,
            'critical_css'=>['state'=>$critical_state,'enabled'=>(bool)($critical['enabled']??false),
                'configured_route_count'=>is_array($critical['routes']??null)?count($critical['routes']):0,
                'documents'=>$critical_docs,'defer_webfontloader'=>(bool)($critical['defer_webfontloader']??false)],
            'fragments'=>['state'=>$fragment_state,'enabled'=>(bool)($fragments['enabled']??false),
                'targets'=>$fragment_targets,'geo_policy'=>(string)($fragments['geo_policy']??'missing')],
            'settings'=>$settings,'readable'=>$readable,
            'predictive_css_decision'=>$predictive,
            'adaptive_css_decision_v2'=>$adaptive,
        ];
        // Deterministic hash; avoid dynamic timestamps in fingerprint.
        $digest=hash('sha256',wp_json_encode($source,JSON_UNESCAPED_SLASHES));
        if (isset($input['previous_snapshot_sha256']) && !hash_equals($input['previous_snapshot_sha256'],$digest)) {
            self::add_issue($issues,'scan_snapshot_changed','P1','changed_since_last_scan',
                ['previous_digest'=>$input['previous_snapshot_sha256'],'current_digest'=>$digest],
                'Compare signed prior and current snapshots; rerun targeted browser QA before any performance-presets promotion.');
        }

        if (empty($issues)) self::add_issue($issues,'browser_baseline_pending','P2','verification_required',
            ['reason'=>'runtime_wp_does_not_measure_browser_lcp'],
            'Run Playwright/Lighthouse per locale and device; compare FCP/LCP/CLS, menu, gallery, JetSmartFilters and booking CTA before declaring a speed win.');
        else self::add_issue($issues,'browser_baseline_pending','P2','verification_required',
            ['reason'=>'runtime_wp_does_not_measure_browser_lcp'],
            'Run approved browser audit and attach Core Web Vitals; no estimated performance savings are release proof.');

        $weight=['P0'=>0,'P1'=>1,'P2'=>2];
        usort($issues,static function($a,$b)use($weight) {
            $p=$weight[$a['priority']]<=>$weight[$b['priority']];
            return $p ?: strcmp($a['id'],$b['id']);
        });

        $report = [
            'contract'=>self::CONTRACT,'scope'=>'current_staging_company_only',
            'readonly'=>true,'mutation_performed'=>false,'auto_apply_allowed'=>false,
            'scan_snapshot_sha256'=>$digest,
            'previous_snapshot_state'=>!isset($input['previous_snapshot_sha256'])?'not_supplied':
                (hash_equals($input['previous_snapshot_sha256'],$digest)?'unchanged':'changed'),
            'inventory'=>$source,
            'summary'=>[
                'critical_css_state'=>$critical_state,
                'fragment_state'=>$fragment_state,
                'issue_count'=>count($issues),
                'p0_count'=>count(array_filter($issues,static fn($x)=>$x['priority']==='P0')),
                'plan_state'=>'review_required',
                'predictive_css_decisions'=>count((array)($predictive['decisions']??[])),
            ],
            'remediation_plan'=>$issues,
            'limits'=>[
                'browser_lcp_cls_not_measured'=>true,
                'geoip_provider_behavior_unverified'=>true,
                'snapshot_persistence_external_operator_only'=>true,
                'plans_are_not_owner_approval'=>true,
            ]
        ];
        return MAD4B_Adaptive_CSS_WP_Report_Adapter::augment($report);
    }
}
MAD4B_Company_Performance_Intelligence::boot();
// Original Content Job, CSS source refresh and admin saves can publish fresh
// evidence. Refresh only a small device-scoped runtime policy receipt; no worker
// is launched during a frontend request.
add_action('update_option_mad4b_perf_css_browser_evidence_v2',
    ['MAD4B_Company_Performance_Intelligence','refresh_css_policy_receipt_v2'],20,0);
add_action('update_option_mad4b_perf_predictive_css_v2',
    ['MAD4B_Company_Performance_Intelligence','refresh_css_policy_receipt_v2'],20,0);
add_action('mad4b/performance/css_plan_updated',
    ['MAD4B_Company_Performance_Intelligence','refresh_css_policy_receipt_v2'],20,0);
// Material CSS source changes and pending discovery invalidate old evidence
// immediately, even when no administrator opens the Performance page.
add_action('update_option_mad4b_css_ai_epoch_v1',
    ['MAD4B_Company_Performance_Intelligence','refresh_css_policy_receipt_v2'],20,0);
add_action('update_option_mad4b_adaptive_presentation_scan_needed_v1',
    ['MAD4B_Company_Performance_Intelligence','refresh_css_policy_receipt_v2'],20,0);


