<?php
/**
 * MAD4B Adaptive CSS Intelligence — native Staging implementation.
 * No options/HTML/CSS writes, no network. Decisions are evidence only.
 */
defined('ABSPATH') || exit;
final class MAD4B_Adaptive_CSS_Decision_Engine {
    public const CONTRACT = 'mad4b.adaptive-css-intelligence.v1';
    public const MIN_PAIRS = 5;
    private const STATES = ['first_viewport','lcp_dependency','swiper','jetengine',
        'jetsmartfilters','dynamic_state','interactive','structural_layout',
        'booking','checkout','pricing','currency','wpml','font_face',
        'keyframes','global_reset','css_variable_definition','unknown_at_rule'];

    private static function quantile(array $values, float $p): float {
        if (!$values) return 0.0;
        sort($values, SORT_NUMERIC);
        $at=(count($values)-1)*$p; $low=(int)floor($at);$high=(int)ceil($at);
        return (float)$values[$low]+((float)$values[$high]-(float)$values[$low])*($at-$low);
    }
    private static function paired(array $native,array $candidate): array {
        $index=[];
        foreach ($native as $row) {
            if (!self::valid($row)) continue;
            $key=(string)$row['id'].'|'.$row['cache'];
            if (!isset($index[$key])) $index[$key]=$row;
        }
        $pairs=[];
        foreach ($candidate as $row) {
            if (!self::valid($row)) continue;
            $key=(string)$row['id'].'|'.$row['cache'];
            if (!isset($index[$key])) continue;
            $pairs[]=[$index[$key],$row];
            unset($index[$key]);
        }
        return $pairs;
    }
    private static function valid($r): bool {
        if (!is_array($r) || !isset($r['id'],$r['cache'],$r['fcp_ms'],
            $r['lcp_ms'],$r['cls'],$r['functional_ok'])
            || !in_array($r['cache'],['cold','warm'],true)
            || !is_bool($r['functional_ok'])) return false;
        foreach (['fcp_ms','lcp_ms','cls'] as $m) {
            if (!is_numeric($r[$m]) || !is_finite((float)$r[$m])
                || (float)$r[$m]<0 || (float)$r[$m]>120000) return false;
        }
        return true;
    }
    private static function measures(array $pairs): array {
        $f=[];$l=[];$c=[];$base=[];$cls=[];$cold=0;$warm=0;$fail=0;
        foreach($pairs as [$a,$b]) {
            $f[]=(float)$b['fcp_ms']-(float)$a['fcp_ms'];
            $l[]=(float)$b['lcp_ms']-(float)$a['lcp_ms'];
            $c[]=(float)$b['cls']-(float)$a['cls'];$cls[]=(float)$b['cls'];
            $base[]=(float)$a['fcp_ms'];
            $a['cache']==='cold'?$cold++:$warm++;
            if (!$a['functional_ok'] || !$b['functional_ok'])$fail++;
        }
        $n=count($pairs);$gain=-self::quantile($f,.5);
        return ['paired_runs'=>$n,'cold_pairs'=>$cold,'warm_pairs'=>$warm,
            'fcp_median_gain_ms'=>round($gain,2),
            'fcp_median_gain_pct'=>round(100*$gain/max(1,self::quantile($base,.5)),2),
            'fcp_beneficial_fraction'=>$n?count(array_filter($f,static fn($x)=>$x<0))/$n:0,
            'lcp_p75_delta_ms'=>round(self::quantile($l,.75),2),
            'lcp_upper_bound_ms'=>$l?max($l):0,
            'lcp_regression_fraction'=>$n?count(array_filter($l,static fn($x)=>$x>100))/$n:0,
            'candidate_cls_p75'=>self::quantile($cls,.75),
            'cls_p75_delta'=>self::quantile($c,.75),
            'functional_failures'=>$fail];
    }
    private static function checks(array $pairs,array $required): bool {
        if (!$required || count($pairs)<self::MIN_PAIRS) return false;
        foreach ($pairs as [$a,$b]) {
            foreach ($required as $key) {
                if (($a['checks'][$key]??null)!==true || ($b['checks'][$key]??null)!==true)return false;
            }
        }
        return true;
    }
    private static function groups(array $rows,array $scope,array $required,array $sourceSnapshots): array {
        $out=[];
        foreach($rows as $g) {
            if (!is_array($g)||!is_string($g['id']??null))continue;
            $tags=array_intersect((array)($g['tags']??[]),self::STATES);
            $cov=(array)($g['coverage']??[]);
            $keys=['first_viewport','interaction','swiper_after_init','filters','market_switch','booking_flow'];
            $coverage_complete=count(array_intersect($keys,array_keys($cov)))===count($keys);
            $covered=false;foreach($keys as $k)if(!empty($cov[$k]))$covered=true;
            $mapped=!empty($g['source_mapped'])&&!empty($g['source_file']);
            // Critical stylesheet hash differs from the full LiteSpeed bundle hash.
            // Both must be current. Only uniquely attributed and freshly hashed
            // original files can be considered for any exclusion decision.
            $sourceFile=(string)($g['source_file']??'');
            $ownerSha=(string)($g['source_file_sha256']??'');
            $source_ok=(
                (string)($g['critical_css_sha256']??'')===$scope['critical_css_sha256']
                && (string)($g['full_bundle_sha256']??'')===$scope['css_sha256']
                && preg_match('/^[a-f0-9]{64}$/D',$ownerSha)
                && isset($sourceSnapshots[$sourceFile])
                && hash_equals($ownerSha,(string)$sourceSnapshots[$sourceFile])
            );
            $ab=(array)($g['ablation']??[]);
            $pairs=self::paired((array)($ab['with_group']??[]),(array)($ab['without_group']??[]));
            $metrics=self::measures($pairs);
            $ready=count($pairs)>=self::MIN_PAIRS && $metrics['cold_pairs']>0
                && $metrics['warm_pairs']>0 && $metrics['functional_failures']===0
                && self::checks($pairs,$required);
            $pass=$ready && $metrics['lcp_p75_delta_ms']<=0
                && $metrics['lcp_upper_bound_ms']<=100
                && $metrics['candidate_cls_p75']<=.1
                && $metrics['cls_p75_delta']<=.015;
            $decision=(!$mapped)?'unmapped_bundle_hold':
                (!$source_ok?'source_hash_mismatch_hold':
                    ($tags || $covered?'protected_keep_in_critical':
                        (!$coverage_complete?'coverage_missing_hold':
                            ($pass?'verified_exclude_from_critical_only':'ablation_required'))));
            $out[]=[
                'id'=>$g['id'],'source_file'=>(string)($g['source_file']??'unattributed'),
                'critical_bytes'=>max(0,(int)($g['critical_bytes']??0)),
                'source_verified'=>$source_ok,'source_mapped'=>$mapped,
                'coverage_verified'=>$coverage_complete,
                'protected_tags'=>array_values($tags),'decision'=>$decision,
                'ablation_pairs'=>count($pairs),'ablation_metrics'=>$metrics,
                'full_css_preserved'=>true
            ];
        }
        usort($out,static fn($a,$b)=>$b['critical_bytes']<=>$a['critical_bytes']);
        return $out;
    }

    public static function evaluate(array $input): array {
        $s=(array)($input['scope']??[]);
        $scope=['site'=>(string)($s['site']??''),'route'=>(string)($s['route']??''),
            'locale'=>(string)($s['locale']??''),'device'=>(string)($s['device']??''),
            'market'=>(string)($s['market']??'public'),
            'css_epoch'=>(int)($s['css_epoch']??-1),
            'css_sha256'=>(string)($s['css_sha256']??''),
            'critical_css_sha256'=>(string)($s['critical_css_sha256']??''),
            'manifest_sha256'=>(string)($s['manifest_sha256']??'')];
        $live=(array)($input['live_source']??[]);
        $errors=[];
        foreach(['site','route','locale','device'] as $k)if($scope[$k]==='')$errors[]='missing_scope_'.$k;
        if(!in_array($scope['device'],['mobile','desktop','tablet'],true))$errors[]='invalid_device';
        foreach(['css_sha256','critical_css_sha256','manifest_sha256'] as $k)if(!preg_match('/^[a-f0-9]{64}$/D',$scope[$k]))$errors[]='invalid_'.$k;
        if(($live['css_sha256']??null)!==$scope['css_sha256'])$errors[]='source_fingerprint_mismatch';
        if(($live['manifest_sha256']??null)!==$scope['manifest_sha256'])$errors[]='manifest_fingerprint_mismatch';
        if(($live['critical_css_sha256']??null)!==$scope['critical_css_sha256'])$errors[]='critical_css_fingerprint_mismatch';
        if((int)($live['css_epoch']??-1)!==$scope['css_epoch'])$errors[]='css_epoch_mismatch';
        if(!empty($input['has_private_cache_mixing']))$errors[]='private_cache_context_mixing';
        if(($input['sample_design']??'')!=='paired_device_network_cache_state')$errors[]='paired_method_not_proven';
        $required=array_values(array_unique(array_filter((array)($input['required_checks']??[]),'is_string')));
        $pairs=self::paired((array)($input['baseline_runs']??[]),(array)($input['candidate_runs']??[]));
        $metrics=self::measures($pairs);
        $enough=$metrics['paired_runs']>=self::MIN_PAIRS;
        $fcp=$enough && $metrics['fcp_median_gain_ms']>=100
            && $metrics['fcp_median_gain_pct']>=5
            && $metrics['fcp_beneficial_fraction']>=.8;
        $provisional=$metrics['paired_runs']>=2 && $metrics['fcp_beneficial_fraction']>0;
        $lcp=$metrics['paired_runs']>=2 &&
            ($metrics['lcp_p75_delta_ms']>100 || $metrics['lcp_regression_fraction']>.2
             || $metrics['lcp_upper_bound_ms']>100);
        $cls=$metrics['paired_runs']>=2 &&
            ($metrics['candidate_cls_p75']>.1 || $metrics['cls_p75_delta']>.015);
        $functional=$metrics['functional_failures']>0;
        $strategy=$fcp?($lcp||$cls||$functional?'adopt_fcp_strategy_with_separate_lcp_remediation':'adopt_fcp_strategy_candidate'):
            ($provisional?($lcp||$cls?'retain_provisional_fcp_candidate_with_lcp_remediation':'retain_provisional_fcp_candidate_repeat_validation'):
             'collect_more_evidence_or_rework_candidate');
        $allow=!$errors && $fcp && $enough && $metrics['cold_pairs']>0 && $metrics['warm_pairs']>0
            && self::checks($pairs,$required)
            && !$lcp && !$cls && !$functional
            && $metrics['lcp_p75_delta_ms']<=0 && $metrics['lcp_upper_bound_ms']<=0;
        $groups=self::groups((array)($input['groups']??[]),$scope,$required,
            (array)($input['verified_source_snapshots']??[]));
        $approved=array_values(array_map(static fn($g)=>$g['id'],
            array_filter($groups,static fn($g)=>$g['decision']==='verified_exclude_from_critical_only')));
        $jobs=[
            ['id'=>'preserve_fcp_candidate','kind'=>'candidate_registry','state'=>$fcp||$provisional?'recommended':'evidence_required',
                'action'=>'Retain measured FCP strategy as a Staging candidate, with no public activation.'],
            ['id'=>'profile_lcp_and_layout_dependencies','kind'=>'diagnostics','state'=>'required',
                'action'=>'Profile first JetEngine/Swiper card render delay, CSSOM dependencies, image resource timing, CLS and cold TTFB.'],
            ['id'=>'device_independent_release_gate','kind'=>'release_gate','state'=>$allow?'eligible_for_review':'blocked',
                'action'=>'Require >=5 cold/warm matched pairs, stable CSS SHA, LCP and CLS nonregression, all interaction/booking/market checks and explicit approved rollout.']
        ];
        foreach($groups as $g) {
            if($g['decision']==='ablation_required'||$g['decision']==='verified_exclude_from_critical_only') {
                $jobs[]=['id'=>'css_group_'.$g['id'],'kind'=>'group_ablation',
                    'state'=>$g['decision']==='ablation_required'?'experiment_only':'retest_complete_bundle',
                    'source_file'=>$g['source_file'],'group_id'=>$g['id'],
                    'action'=>'Evaluate this group excluded only from CCSS; never remove it from full CSS.'];
            }
        }
        return ['contract'=>self::CONTRACT,'scope'=>$scope,
            'read_only'=>true,'mutation_performed'=>false,
            'strategy_decision'=>$strategy,
            'release'=>$allow?'eligible_for_scoped_canary_approval':'hold_live_activation',
            'live_activation_eligible'=>$allow,'validation_problems'=>array_values(array_unique($errors)),
            'evidence_sufficiency'=>$enough?'sufficient_pairs':'insufficient_pairs',
            'metrics'=>$metrics,'groups'=>$groups,'proven_exclusion_ids'=>$approved,
            'jobs'=>$jobs,'full_css_preserved'=>true,
            'operator_note'=>'Only validated Critical CSS groups may be removed after A/B; no visitor, WooCommerce, WPML or market data enters public CSS.'];
    }
}
