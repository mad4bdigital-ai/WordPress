<?php
namespace ETG\DynamicFilterSEOBridge\Acceptance;

final class VerdictReducer {
    const CONTRACT = 'etg.dfsb.acceptance-verdict-reducer.v1';

    public function reduce( array $cases ): array {
        $rank=array('PASS'=>0,'INCOMPLETE_EVIDENCE'=>1,'BLOCKED'=>2,'FAIL'=>3);
        $verdict='PASS';$classification='NO_CONFIRMED_DEFECT';$blocking=array();$incomplete=array();$defects=array();$infrastructure=array();
        $tests=array('profile_resolution'=>'PASS','provider_binding'=>'PASS','query_binding'=>'PASS','direct_ajax_state_parity'=>'PASS','result_count_parity'=>'PASS','dataset_id_parity'=>'PASS','seo_non_authority'=>'PASS');
        foreach($cases as$case){
            $caseVerdict=(string)($case['verdict']??'BLOCKED');
            if(($rank[$caseVerdict]??2)>($rank[$verdict]??0)){$verdict=$caseVerdict;$classification=(string)($case['classification']??'UNKNOWN');}
            $blocking=array_merge($blocking,(array)($case['blocking_reasons']??array()));
            $incomplete=array_merge($incomplete,(array)($case['incomplete_evidence']??array()));
            $defects=array_merge($defects,(array)($case['defect_reasons']??array()));
            $infrastructure=array_merge($infrastructure,(array)($case['infrastructure_failures']??array()));
            if(empty($case['provider_binding_parity']))$tests['provider_binding']='FAIL';
            if(false===($case['state_parity']??null))$tests['direct_ajax_state_parity']='FAIL';
            if(false===($case['result_count_parity']??null))$tests['result_count_parity']='FAIL';
            elseif(null===($case['result_count_parity']??null)&&'PASS'===$tests['result_count_parity'])$tests['result_count_parity']='BLOCKED';
            if(false===($case['ids_parity']??null))$tests['dataset_id_parity']='FAIL';
            elseif(null===($case['ids_parity']??null)&&'TEST_INFRASTRUCTURE_FAILURE'===(string)($case['classification']??''))$tests['dataset_id_parity']='BLOCKED';
            elseif(null===($case['ids_parity']??null)&&'PASS'===$tests['dataset_id_parity'])$tests['dataset_id_parity']='INCOMPLETE_EVIDENCE';
            if(empty($case['seo_non_authority']))$tests['seo_non_authority']='FAIL';
            if(!empty($case['direct_dataset']['blocking_reasons'])||!empty($case['ajax_dataset']['blocking_reasons']))$tests['query_binding']='BLOCKED';
        }
        return array('contract'=>self::CONTRACT,'verdict'=>$verdict,'classification'=>$classification,'tests'=>$tests,
            'blocking_reasons'=>array_values(array_unique(array_filter($blocking))),
            'incomplete_evidence'=>array_values(array_unique(array_filter($incomplete))),
            'defect_reasons'=>array_values(array_unique(array_filter($defects))),
            'infrastructure_failures'=>array_values(array_unique(array_filter($infrastructure))));
    }
}
