<?php
/**
 * WordPress bridge for MAD4B Company Performance Intelligence.
 * Read-only; reuses existing REST/Ability permissions.
 */
defined('ABSPATH') || exit;
require_once __DIR__.'/engine.php';

final class MAD4B_Adaptive_CSS_WP_Report_Adapter {
    private static function evidence(): array {
        $host=(string)wp_parse_url(home_url('/'),PHP_URL_HOST);
        $records=get_option('mad4b_perf_css_browser_evidence_v2',[]);
        $records=is_array($records)?(array)($records['records']??[]):[];
        $record=[];
        foreach ($records as $candidate) {
            if (is_array($candidate) && ($candidate['site_host']??'')===$host
                && ($candidate['path']??'')==='/' && ($candidate['locale']??'')==='en'
                && ($candidate['device']??'')==='mobile') {
                $record=$candidate;break;
            }
        }
        $manifestPath=WP_CONTENT_DIR.'/mad4b-predictive-css-v2/manifest.json';
        $raw=is_file($manifestPath)?file_get_contents($manifestPath):'';
        $manifest=is_string($raw)?json_decode($raw,true):null;
        $manifest=is_array($manifest)?$manifest:[];
        $route=(array)($manifest['routes']['/|en']??[]);
        $criticalName=(string)($route['devices']['mobile']['critical_file']??'');
        $criticalPath=preg_match('/^[a-f0-9]{64}\.css$/D',$criticalName)
            ? WP_CONTENT_DIR.'/mad4b-predictive-css-v2/'.$criticalName : '';
        $criticalSha=$criticalPath!=='' && is_file($criticalPath) && is_readable($criticalPath)
            ? hash_file('sha256',$criticalPath) : '';
        $expected=(string)($record['source_bundle_sha']??'');
        $found='';
        foreach ((array)($route['bundle_variants']??[]) as $name=>$entry) {
            if (!is_string($name)||!preg_match('/^[a-z0-9._-]+\.css$/iD',$name))continue;
            if ((string)($entry['bundle_sha']??'')!==$expected)continue;
            $path=WP_CONTENT_DIR.'/litespeed/css/'.$name;
            if(is_file($path) && is_readable($path)) $found=hash_file('sha256',$path);
            break;
        }
        $pairs=(array)($record['pairs']??[]);
        $native=[];$candidate=[];$pairedDesign=true;
        foreach($pairs as $index=>$pair) {
            if (!is_array($pair) || !in_array($pair['cache_tier']??null,['cold','warm'],true)
                || empty($pair['same_document']) || empty($pair['same_css_source'])) {
                $pairedDesign=false;continue;
            }
            $checks=(array)($pair['interaction_checks']??[]);
            $known=['swiper','filters','booking_cta','language','market'];
            $all=true;foreach($known as $k)if(($checks[$k]??null)!==true)$all=false;
            foreach(['native','candidate'] as $mode) {
                $metric=(array)($pair[$mode]??[]);
                $row=['id'=>(string)$index,'cache'=>$pair['cache_tier'],
                    'fcp_ms'=>$metric['fcp_ms']??null,
                    'lcp_ms'=>$metric['lcp_ms']??null,
                    'cls'=>$metric['cls']??null,
                    'functional_ok'=>$all && ($metric['js_errors']??-1)===0,
                    'checks'=>array_fill_keys($known,$all)];
                if($mode==='native')$native[]=$row;else $candidate[]=$row;
            }
        }
        $epoch=(int)($record['css_epoch']??$route['css_epoch']??-1);
        return [
            'scope'=>['site'=>$host,'route'=>'/','locale'=>'en','device'=>'mobile','market'=>'public',
                'css_epoch'=>$epoch,'css_sha256'=>$expected,
                'critical_css_sha256'=>$criticalSha,
                'manifest_sha256'=>is_string($raw)&&$raw!==''?hash('sha256',$raw):''],
            'live_source'=>['css_epoch'=>(int)get_option('mad4b_css_ai_epoch_v1',-1),
                'css_sha256'=>$found,'critical_css_sha256'=>$criticalSha,
                'manifest_sha256'=>is_string($raw)&&$raw!==''?hash('sha256',$raw):''],
            'sample_design'=>$pairedDesign?'paired_device_network_cache_state':'unverified_sample_pairing',
            'required_checks'=>['swiper','filters','booking_cta','language','market'],
            'baseline_runs'=>$native,'candidate_runs'=>$candidate,
            // No source-file/group removals until browser/provenance proof exists.
            'groups'=>[],
            'verified_source_snapshots'=>[],
            'has_private_cache_mixing'=>false,
        ];
    }

    private static function sourceInventory(): array {
        $file=__DIR__.'/source-inventory-summary.json';
        if (!is_file($file) || !is_readable($file))return ['state'=>'missing'];
        $data=json_decode((string)file_get_contents($file),true);
        if (!is_array($data) || ($data['contract']??'')!=='mad4b.adaptive-css-source-summary.v1'
            || ($data['site']??'')!==wp_parse_url(home_url('/'),PHP_URL_HOST)
            || empty($data['full_css_untouched'])
            || !preg_match('~^mad4b-predictive-css-v2/[a-f0-9]{64}\.css$~D',(string)($data['critical_css_relative_path']??''))) {
            return ['state'=>'invalid_inventory_contract'];
        }
        $css=WP_CONTENT_DIR.'/'.$data['critical_css_relative_path'];
        $manifest=WP_CONTENT_DIR.'/mad4b-predictive-css-v2/manifest.json';
        if (!is_file($css) || !hash_equals((string)$data['critical_css_sha256'],hash_file('sha256',$css)))
            return ['state'=>'stale_css_sha'];
        if (!is_file($manifest) || !hash_equals((string)$data['manifest_sha256'],hash_file('sha256',$manifest)))
            return ['state'=>'stale_manifest_sha'];
        return ['state'=>'source_inventory_current','sources_examined'=>(int)$data['sources_examined'],
            'total_groups'=>(int)$data['total_groups'],
            'mapped_unique'=>(int)$data['mapped_unique'],
            'unmatched'=>(int)$data['unmatched'],
            'mapped_protected'=>(int)$data['mapped_protected'],
            'mapped_unprotected'=>(int)$data['mapped_unprotected'],
            'verified_exclusion_count'=>0,
            'source_group_counts'=>$data['source_group_counts']??[],
            'next_action'=>$data['next_action']??'Verify provenance and browser ablation',
            'read_only'=>true,'full_css_untouched'=>true,'release_approval'=>false];
    }

    private static function firstCityPatchReceipt(): array {
        $file=__DIR__.'/lcp-first-city-staging-receipt.json';
        if (!is_file($file) || is_link($file) || !is_readable($file) || filesize($file)>8192)
            return ['status'=>'not_recorded'];
        $r=json_decode((string)file_get_contents($file),true);
        if (!is_array($r) || ($r['contract']??'')!=='mad4b.mobile-first-city-lcp-patch.v1'
            || ($r['site_host']??'')!==(string)wp_parse_url(home_url('/'),PHP_URL_HOST)
            || ($r['environment']??'')!==wp_get_environment_type()
            || ($r['status']??'')!=='staging_patch_deployed_monitor'
            || !empty($r['release_approval']) || !empty($r['public_predictive_css_approved'])
            || ($r['scope']??'')!=='selected_first_destination_only_mobile_max_767')
            return ['status'=>'invalid_receipt_contract'];
        $relative=(string)($r['site_plugin']??'');
        if ($relative!=='wp-content/mu-plugins/406-mad4b-shared-performance-parity.php')
            return ['status'=>'unexpected_plugin_source'];
        $path=ABSPATH.$relative;
        $sha=(string)($r['site_plugin_sha256']??'');
        if (!preg_match('/^[a-f0-9]{64}$/D',$sha)
            || !is_file($path) || is_link($path) || !hash_equals($sha,(string)hash_file('sha256',$path)))
            return ['status'=>'source_drift_retest_required'];
        $pairs=(array)($r['pilot_pairs']??[]);
        if (count($pairs)!==2 || (int)($r['pilot_pair_count']??0)!==2
            || !isset($r['postdeploy_mobile']['cls'],$r['postdeploy_mobile']['lcp_ms']))
            return ['status'=>'incomplete_browser_evidence'];
        return [
            'status'=>'staging_patch_deployed_monitor',
            'source_sha256'=>$sha,
            'scope'=>'first_selected_destination_mobile_only',
            'pilot_pair_count'=>2,
            'pilot_lcp_after_ttfb_delta_ms'=>array_map(static fn($p)=>(float)($p['lcp_after_ttfb_delta_ms']??0),$pairs),
            'postdeploy_mobile_lcp_ms'=>(float)$r['postdeploy_mobile']['lcp_ms'],
            'postdeploy_mobile_cls'=>(float)$r['postdeploy_mobile']['cls'],
            'live_activation_eligible'=>false,
            'separate_predictive_css_release_required'=>true,
            'read_only'=>true
        ];
    }

    private static function precompressRotationState(): array {
        // Consume the sanitized root-published status, never the root-only
        // detailed rotation metadata or the VPS host transport directly.
        if ((string)wp_parse_url(home_url('/'),PHP_URL_HOST)!=='tours.dev.mad4b.com')
            return ['status'=>'not_applicable'];
        $file=WP_CONTENT_DIR.'/litespeed/.mad4b-asset-retention/tourdev-priority-status-v1.json';
        if (!is_file($file) || is_link($file) || !is_readable($file)
            || filesize($file)>8192)
            return ['status'=>'not_recorded'];
        $data=json_decode((string)file_get_contents($file),true);
        if (!is_array($data)
            || ($data['contract']??'')!=='mad4b.precompress-rotation-status.v1'
            || ($data['site_host']??'')!==(string)wp_parse_url(home_url('/'),PHP_URL_HOST)
            || !is_array($data['routes_discovered']??null)
            || !is_numeric($data['last_checked']??null)
            || !is_numeric($data['configured_route_count']??null))
            return ['status'=>'invalid_state'];
        $configured=(int)$data['configured_route_count'];
        if ($configured<1 || $configured>50)
            return ['status'=>'invalid_route_contract'];
        $routeCounts=[];
        foreach ($data['routes_discovered'] as $path=>$count) {
            if (!is_string($path)
                || !preg_match('~^/(?:[a-z]{2}/)?$~D',$path)
                || !is_numeric($count)) continue;
            $routeCounts[$path]=max(0,(int)$count);
        }
        $age=max(0,time()-(int)$data['last_checked']);
        $seedCount=max(0,(int)($data['legacy_seed_count']??0));
        $complete=!empty($data['rotation_complete'])
            && count($routeCounts)===$configured && $seedCount===0;
        $status=$complete?'rotation_completed'
            : ($age<=900 ? 'rotation_in_progress' : 'discovery_paused_or_stale');
        return [
            'status'=>$status,'routes_discovered'=>$routeCounts,
            'configured_routes'=>$configured,
            'routes_discovered_count'=>count($routeCounts),
            'legacy_seed_count'=>$seedCount,
            'last_checked_age_seconds'=>$age,
            'priority_css'=>max(0,(int)($data['priority_css']??0)),
            'priority_js'=>max(0,(int)($data['priority_js']??0)),
            'read_only'=>true,'mutation_performed'=>false,
            'public_css_release_approved'=>false
        ];
    }

    private static function minimumPairs(): int { return MAD4B_Adaptive_CSS_Decision_Engine::MIN_PAIRS; }

    public static function augment(array $report): array {
        if(($report['readonly']??false)!==true || ($report['mutation_performed']??true)!==false) return $report;
        $evidence=self::evidence();
        $plan=MAD4B_Adaptive_CSS_Decision_Engine::evaluate($evidence);
        $legacy=get_option('mad4b_perf_predictive_browser_evidence_v1',[]);
        $samples=is_array($legacy)?(array)($legacy['samples']??[]):[];
        $fcpWins=0;$lcpRegressions=0;
        foreach($samples as $sample) {
            if(!is_array($sample))continue;
            if(is_numeric($sample['candidate_fcp_ms']??null) && is_numeric($sample['baseline_fcp_ms']??null)
                && $sample['candidate_fcp_ms']<$sample['baseline_fcp_ms'])$fcpWins++;
            if(is_numeric($sample['candidate_lcp_ms']??null) && is_numeric($sample['baseline_lcp_ms']??null)
                && $sample['candidate_lcp_ms']>$sample['baseline_lcp_ms'])$lcpRegressions++;
        }
        if ($fcpWins>0 && $lcpRegressions>0 &&
            ($plan['metrics']['paired_runs']??0)<self::minimumPairs()) {
            // Preserve a *hypothesis*, never treat unclassified cache runs as
            // release evidence. The core gate remains fail-closed.
            $plan['strategy_opportunity']='retain_unverified_fcp_hypothesis_separate_lcp_fix';
            $plan['jobs'][0]['state']='recommended_for_controlled_experiment';
        }
        $plan['legacy_unpaired_observation']=[
            'sample_count'=>count($samples),'fcp_wins'=>$fcpWins,
            'lcp_regressions'=>$lcpRegressions,'release_evidence'=>false,
            'recommendation'=>$fcpWins>0?'retain_fcp_hypothesis_remediate_lcp':'collect_more_fcp_evidence'
        ];
        $plan['first_city_lcp_patch']=self::firstCityPatchReceipt();
        $report['adaptive_css_group_intelligence']=$plan;
        $report['adaptive_css_source_inventory']=self::sourceInventory();
        $report['adaptive_asset_precompress_rotation']=self::precompressRotationState();
        $ids=[];
        foreach((array)($report['remediation_plan']??[]) as $x) {
            if(is_array($x) && is_string($x['id']??null))$ids[$x['id']]=true;
        }
        $issues=(array)($report['remediation_plan']??[]);
        foreach($plan['jobs'] as $job) {
            $id='adaptive_css_'.$job['id'];
            if(isset($ids[$id]))continue;
            $ids[$id]=true;
            $issues[]=['id'=>$id,'priority'=>$job['kind']==='diagnostics'?'P1':'P2',
                'state'=>$job['state'],'source'=>'adaptive_css_group_intelligence',
                'mode'=>'recommendation_only','description'=>$job['action'],
                'scope'=>$plan['scope'],'requires_release_gate'=>true];
        }
        $inventory=$report['adaptive_css_source_inventory'];
        $inventoryId='adaptive_css_source_inventory_provenance';
        if (!isset($ids[$inventoryId]) && ($inventory['state']??null)==='source_inventory_current') {
            $issues[]=['id'=>$inventoryId,'priority'=>'P1','state'=>'coverage_and_ablation_required',
                'source'=>'adaptive_css_group_intelligence','mode'=>'recommendation_only',
                'description'=>'Only '.$inventory['mapped_unique'].' of '.$inventory['total_groups'].
                    ' CSS groups have a unique source and all currently mapped rules are protected. Expand source provenance and conduct targeted browser tests; exclude nothing yet.',
                'scope'=>$plan['scope'],'requires_release_gate'=>true];
        }
        $firstCity=$plan['first_city_lcp_patch'];
        if (($firstCity['status']??'')==='staging_patch_deployed_monitor'
            && !isset($ids['adaptive_css_first_city_lcp_monitor'])) {
            $issues[]=['id'=>'adaptive_css_first_city_lcp_monitor','priority'=>'P1',
                'state'=>'staging_patch_deployed_monitor','source'=>'adaptive_css_group_intelligence',
                'mode'=>'recommendation_only',
                'description'=>'A SHA-verified mobile first-city geometry-lazy CSS exemption is deployed on Staging. Two reversed browser pilots improved LCP after TTFB; post-deploy mobile visual and Swiper checks passed. Monitor more cold/warm samples; this does not certify public Predictive CSS.',
                'scope'=>$plan['scope'],'requires_release_gate'=>true];
        }
        $rotation=$report['adaptive_asset_precompress_rotation'];
        if (in_array(($rotation['status']??''),['rotation_in_progress','discovery_paused_or_stale'],true)
            && !isset($ids['adaptive_precompress_rotation_monitor'])) {
            $issues[]=['id'=>'adaptive_precompress_rotation_monitor',
                'priority'=>($rotation['status']==='discovery_paused_or_stale'?'P1':'P2'),
                'state'=>$rotation['status'],
                'source'=>'adaptive_css_group_intelligence',
                'mode'=>'recommendation_only',
                'description'=>'Tours.dev asset discovery rotates one language at a time. '.
                    $rotation['routes_discovered_count'].' routes discovered; '.
                    $rotation['legacy_seed_count'].' prior references retained. '.
                    'Monitor CPU pressure before the remaining routes are checked; public CSS remains unchanged.',
                'scope'=>$plan['scope'],'requires_release_gate'=>false];
        }
        $report['remediation_plan']=$issues;
        $report['css_intelligence_read_only']=true;
        $report['css_intelligence_mutation_performed']=false;
        return $report;
    }
}
