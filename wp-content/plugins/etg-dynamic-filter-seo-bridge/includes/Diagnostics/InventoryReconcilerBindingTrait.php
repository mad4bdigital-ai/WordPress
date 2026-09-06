<?php
namespace ETG\DynamicFilterSEOBridge\Diagnostics;

trait InventoryReconcilerBindingTrait {
    private function resolveRouteIdentity(string $provider,string $providerQueryId,array $route,array $queryIndex,array $conflicts,array $topology):array{
        $explicit=sanitize_key((string)($route['query_builder_query_id']??''));
        if(''!==$explicit){return array('resolved'=>true,'query_builder_query_id'=>$explicit,'source'=>'profile_explicit_query_builder_query_id');}
        if(isset($conflicts[$providerQueryId])){return array('resolved'=>false,'code'=>'profile_query_identity_collision','details'=>array('conflict'=>$conflicts[$providerQueryId]));}
        if(isset($queryIndex[$providerQueryId])){return array('resolved'=>true,'query_builder_query_id'=>$providerQueryId,'source'=>'provider_query_id_equals_query_builder_custom_id');}
        $matches=array();$blocked=array();
        foreach((array)($topology['bindings']??array()) as $binding){if(!is_array($binding)){continue;}if($provider!==sanitize_key((string)($binding['provider']??''))||$providerQueryId!==sanitize_key((string)($binding['provider_query_id']??''))){continue;}if('verified'===(string)($binding['status']??'')){$qid=sanitize_key((string)($binding['query_builder_custom_query_id']??''));if(''!==$qid){$matches[$qid][]=$binding;}}else{$blocked[]=$binding;}}
        if(1===count($matches)){$qid=(string)array_key_first($matches);return array('resolved'=>true,'query_builder_query_id'=>$qid,'source'=>'elementor_runtime_topology','details'=>array('topology_evidence'=>array_slice(reset($matches),0,5)));}
        if(count($matches)>1){return array('resolved'=>false,'code'=>'profile_query_topology_ambiguous','details'=>array('provider_query_id'=>$providerQueryId,'query_builder_query_ids'=>array_keys($matches)));}
        if($blocked){return array('resolved'=>false,'code'=>'profile_query_topology_blocked','details'=>array('provider_query_id'=>$providerQueryId,'topology_reason'=>(string)($blocked[0]['reason']??'blocked')));}
        $code=$this->queryIdentityCompleteFromTopology($topology)?'profile_query_missing':'profile_query_unresolved_inventory_truncated';
        return array('resolved'=>false,'code'=>$code,'details'=>array('provider_query_id'=>$providerQueryId,'topology_available'=>!empty($topology['available'])));
    }

    private function inventoryQualityFindings(array $inventory):array{
        $out=array();
        foreach(array('post_types','taxonomies','languages','archive_path_translations') as $section){$r=(array)(((array)($inventory['completeness']??array()))[$section]??array());if(empty($r['truncated'])){continue;}$out[]=$this->findingValue('blocking','inventory_'.$section.'_truncated','inventory:'.$section,array('observed_count'=>(int)($r['observed_count']??0),'included_count'=>(int)($r['included_count']??0),'limit'=>(int)($r['limit']??0)));}
        $queryBuilder=(array)($inventory['query_builder']??array());$queryDetail=(array)(((array)($inventory['completeness']??array()))['query_builder']??array());
        if(!empty($queryDetail['truncated'])){$severity=$this->queryIdentityComplete($inventory)?'info':'blocking';$code=$this->queryIdentityComplete($inventory)?'inventory_query_details_truncated_identity_index_complete':'inventory_query_identity_index_truncated';$out[]=$this->findingValue($severity,$code,'inventory:query_builder',array('observed_count'=>(int)($queryDetail['observed_count']??0),'included_count'=>(int)($queryDetail['included_count']??0),'identity_index_count'=>count($this->identityRecords($queryBuilder))));}
        if(empty($queryBuilder['available'])){$out[]=$this->findingValue('blocking','inventory_query_builder_unavailable','inventory:query_builder',array('source'=>(string)($queryBuilder['source']??'unavailable')));}
        $count=(int)($queryBuilder['identity_conflict_count']??0);if($count>0){$out[]=$this->findingValue('warning','query_builder_identity_collision','inventory:query_builder',array('identity_conflict_count'=>$count,'identity_conflicts_truncated'=>!empty($queryBuilder['identity_conflicts_truncated']),'identity_conflicts'=>array_slice((array)($queryBuilder['identity_conflicts']??array()),0,20)));}
        $topology=(array)($inventory['elementor_topology']??array());if(!empty($topology['truncated'])){$out[]=$this->findingValue('warning','elementor_topology_truncated','inventory:elementor_topology',array('templates_scanned'=>(int)($topology['templates_scanned']??0),'elements_scanned'=>(int)($topology['elements_scanned']??0)));}
        return $out;
    }

    private function candidateProfiles(array $inventory,array $profiledPostTypes,array $queryConflicts):array{
        $postTypes=(array)($inventory['post_types']??array());$taxonomies=(array)($inventory['taxonomies']??array());$queryBuilder=(array)($inventory['query_builder']??array());$queries=$this->identityRecords($queryBuilder);$out=array();
        foreach(array_slice($postTypes,0,self::MAX_CANDIDATES,true) as $postType=>$record){$postType=sanitize_key((string)$postType);if(''===$postType||isset($profiledPostTypes[$postType])){continue;}$record=is_array($record)?$record:array();$rules=array();$priority=10;foreach(array_slice($this->cleanKeys((array)($record['taxonomies']??array())),0,20) as $taxonomy){$tr=(array)($taxonomies[$taxonomy]??array());if(!in_array($postType,$this->cleanKeys((array)($tr['object_type']??array())),true)){continue;}$rules[$taxonomy]=array('role'=>$taxonomy,'priority'=>$priority,'gallery_priority'=>$priority,'index_single'=>false,'min_results'=>3,'required_meta_key'=>'','required_meta_values'=>array(),'meta_constraint_scope'=>'single','field_map'=>array());$priority+=10;}
            $suggested=array();foreach($queries as $query){if(!is_array($query)||'posts'!==(string)($query['type']??'')||empty($query['post_type_bounded'])){continue;}$observed=$this->cleanKeys((array)($query['post_types']??array()));if(array($postType)!==$observed){continue;}$qid=$this->queryIdentityKey($query);if(''!==$qid&&!isset($queryConflicts[$qid])){$suggested[]=array('provider'=>'jet-engine','query_id'=>$qid,'query_builder_query_id'=>$qid);}}
            if(empty($record['has_archive'])&&!$suggested){continue;}$archivePaths=array_values(array_unique(array_filter(array_map(array($this,'normalizePath'),array_values((array)($record['archive_paths']??array()))))));
            $out[]=array('contract'=>'etg.dfsb.reconciliation-candidate.v3','authorizing'=>false,'requires_operator_review'=>true,'evidence'=>array('observed_archive_paths'=>$archivePaths,'suggested_routes'=>array_slice($suggested,0,20)),'profile'=>array('id'=>$postType,'enabled'=>false,'inherit_global_defaults'=>false,'post_types'=>array($postType),'require_post_type_binding'=>true,'post_type_authority'=>'query_builder','archive_slugs'=>array(),'archive_paths'=>array(),'providers'=>array(),'query_ids'=>array(),'routes'=>array(),'max_filters'=>min(10,max(1,count($rules))),'composition_mode'=>'generic','canonical_mode'=>'filtered','require_exact_combination_approval'=>true,'require_exact_for_single'=>false,'allowed_taxonomy_sets'=>array(),'min_results_by_depth'=>array('1'=>3,'2'=>3,'3'=>3),'taxonomy_rules'=>$rules,'indexable_combinations'=>array(),'content'=>array('required'=>true,'require_meta_description'=>true,'min_chars'=>80)));
        }return array_slice($out,0,self::MAX_CANDIDATES);
    }
}
