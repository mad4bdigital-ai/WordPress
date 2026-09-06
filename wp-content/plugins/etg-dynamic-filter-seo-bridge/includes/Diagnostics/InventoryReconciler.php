<?php
namespace ETG\DynamicFilterSEOBridge\Diagnostics;

require_once __DIR__ . '/InventoryReconcilerBindingTrait.php';
require_once __DIR__ . '/InventoryReconcilerDriftTrait.php';
require_once __DIR__ . '/InventoryReconcilerSupportTrait.php';

final class InventoryReconciler {
    const CONTRACT = 'etg.dfsb.inventory-reconciliation.v3';
    const MAX_FINDINGS = 500;
    const MAX_CANDIDATES = 50;

    public function analyze( array $snapshot, array $profiles, array $previous = array() ): array {
        $validation=$this->validateSnapshot($snapshot);
        if($validation){return $this->invalidResult($validation);}
        $inventory=(array)$snapshot['inventory'];
        $postTypes=(array)($inventory['post_types']??array());
        $taxonomies=(array)($inventory['taxonomies']??array());
        $queryBuilder=(array)($inventory['query_builder']??array());
        $queries=(array)($queryBuilder['queries']??array());
        $identityRecords=$this->identityRecords($queryBuilder);
        $languages=(array)($inventory['languages']??array());
        $topology=(array)($inventory['elementor_topology']??array());
        $queryConflicts=$this->queryConflictIndex((array)($queryBuilder['identity_conflicts']??array()));
        $queryIndex=$this->queryIndex($identityRecords,$queryConflicts);
        $findings=$this->inventoryQualityFindings($inventory);
        $profiledPostTypes=array();$profiledTaxonomies=array();

        foreach(array_slice($profiles,0,50,true) as $profileId=>$profile){
            if(!is_array($profile)){continue;}
            $id=sanitize_key((string)($profile['id']??$profileId));if(''===$id){continue;}
            $enabled=!empty($profile['enabled']);$severity=$enabled?'blocking':'warning';
            $allowedPostTypes=$this->cleanKeys((array)($profile['post_types']??array()));$allowedMap=array_fill_keys($allowedPostTypes,true);
            foreach($allowedPostTypes as $postType){$profiledPostTypes[$postType]=true;if(isset($postTypes[$postType])){continue;}$code=$this->sectionTruncated($inventory,'post_types')?'profile_post_type_unresolved_inventory_truncated':'profile_post_type_missing';$this->finding($findings,$severity,$code,'profile:'.$id,array('post_type'=>$postType,'profile_enabled'=>$enabled));}
            foreach(array_slice((array)($profile['taxonomy_rules']??array()),0,50,true) as $taxonomy=>$rule){
                $taxonomy=sanitize_key((string)$taxonomy);if(''===$taxonomy){continue;}$profiledTaxonomies[$taxonomy]=true;
                if(!isset($taxonomies[$taxonomy])){$code=$this->sectionTruncated($inventory,'taxonomies')?'profile_taxonomy_unresolved_inventory_truncated':'profile_taxonomy_missing';$this->finding($findings,$severity,$code,'profile:'.$id,array('taxonomy'=>$taxonomy,'profile_enabled'=>$enabled));continue;}
                $attached=$this->cleanKeys((array)($taxonomies[$taxonomy]['object_type']??array()));
                if($allowedPostTypes&&!array_intersect($allowedPostTypes,$attached)){$this->finding($findings,$severity,'taxonomy_no_longer_attached_to_profile_post_type','profile:'.$id,array('taxonomy'=>$taxonomy,'runtime_object_types'=>$attached,'profile_post_types'=>$allowedPostTypes));}
            }
            $runtimeArchivePaths=array();foreach($allowedPostTypes as $postType){foreach((array)($postTypes[$postType]['archive_paths']??array()) as $path){$n=$this->normalizePath((string)$path);if(''!==$n){$runtimeArchivePaths[$n]=true;}}}
            foreach(array_slice((array)($profile['archive_paths']??array()),0,100) as $path){$path=$this->normalizePath((string)$path);if(''===$path||!$runtimeArchivePaths||isset($runtimeArchivePaths[$path])){continue;}$code=$this->sectionTruncated($inventory,'archive_path_translations')?'profile_archive_path_unresolved_inventory_truncated':'profile_archive_path_not_observed_as_post_type_archive';$this->finding($findings,'warning',$code,'profile:'.$id,array('archive_path'=>$path,'profile_enabled'=>$enabled,'evidence_scope'=>'post_type_archive_only'));}

            foreach(array_slice((array)($profile['routes']??array()),0,20) as $route){
                if(!is_array($route)){continue;}$provider=sanitize_key((string)($route['provider']??''));$providerQueryId=sanitize_key((string)(($route['provider_query_id']??'')?:($route['query_id']??'')));
                if('jet-engine'!==$provider){$this->finding($findings,'info','route_provider_outside_inventory_authority','profile:'.$id,array('provider'=>$provider,'query_id'=>$providerQueryId));continue;}
                if(empty($queryBuilder['available'])){$this->finding($findings,$severity,'profile_query_inventory_unavailable','profile:'.$id,array('query_id'=>$providerQueryId,'profile_enabled'=>$enabled));continue;}
                $binding=$this->resolveRouteIdentity($provider,$providerQueryId,$route,$queryIndex,$queryConflicts,$topology);
                if(empty($binding['resolved'])){$this->finding($findings,$severity,(string)$binding['code'],'profile:'.$id,array_merge(array('query_id'=>$providerQueryId,'profile_enabled'=>$enabled),(array)($binding['details']??array())));continue;}
                $queryId=(string)$binding['query_builder_query_id'];
                if(isset($queryConflicts[$queryId])){$this->finding($findings,$severity,'profile_query_identity_collision','profile:'.$id,array('query_id'=>$queryId,'provider_query_id'=>$providerQueryId,'profile_enabled'=>$enabled,'conflict'=>$queryConflicts[$queryId]));continue;}
                if(!isset($queryIndex[$queryId])){$code=$this->queryIdentityComplete($inventory)?'profile_query_missing':'profile_query_unresolved_inventory_truncated';$this->finding($findings,$severity,$code,'profile:'.$id,array('query_id'=>$queryId,'provider_query_id'=>$providerQueryId,'profile_enabled'=>$enabled));continue;}
                $query=(array)$queryIndex[$queryId];
                if('posts'!==(string)($query['type']??'')){$this->finding($findings,$severity,'profile_query_not_posts','profile:'.$id,array('query_id'=>$queryId,'provider_query_id'=>$providerQueryId,'query_type'=>(string)($query['type']??'')));continue;}
                if(empty($query['post_type_bounded'])){$this->finding($findings,$severity,'profile_query_unbounded_post_type','profile:'.$id,array('query_id'=>$queryId,'provider_query_id'=>$providerQueryId));continue;}
                $observed=$this->cleanKeys((array)($query['post_types']??array()));
                if(!$allowedPostTypes&&$observed){$this->finding($findings,'warning','profile_post_type_binding_suggestion','profile:'.$id,array('provider_query_id'=>$providerQueryId,'query_builder_query_id'=>$queryId,'suggested_post_types'=>$observed,'suggested_require_post_type_binding'=>true,'authorizing'=>false));continue;}
                $foreign=array_keys(array_diff_key(array_fill_keys($observed,true),$allowedMap));
                if(!$observed||$foreign){$this->finding($findings,$severity,'profile_query_post_type_mismatch','profile:'.$id,array('query_id'=>$queryId,'provider_query_id'=>$providerQueryId,'observed_post_types'=>$observed,'profile_post_types'=>$allowedPostTypes));}
                else{$this->finding($findings,'info','profile_query_binding_verified','profile:'.$id,array('provider_query_id'=>$providerQueryId,'query_builder_query_id'=>$queryId,'binding_source'=>(string)$binding['source'],'internal_query_id'=>(string)($query['id']??''),'post_types'=>$observed));}
            }
        }

        foreach(array_slice($postTypes,0,100,true) as $postType=>$record){$postType=sanitize_key((string)$postType);if(''===$postType||isset($profiledPostTypes[$postType])){continue;}$this->finding($findings,'info','unprofiled_post_type_discovered','post_type:'.$postType,array('post_type'=>$postType));}
        foreach(array_slice($taxonomies,0,150,true) as $taxonomy=>$record){$taxonomy=sanitize_key((string)$taxonomy);if(''===$taxonomy||isset($profiledTaxonomies[$taxonomy])){continue;}$attached=$this->cleanKeys((array)($record['object_type']??array()));if(array_intersect(array_keys($profiledPostTypes),$attached)){$this->finding($findings,'warning','unprofiled_taxonomy_attached_to_profiled_post_type','taxonomy:'.$taxonomy,array('taxonomy'=>$taxonomy,'runtime_object_types'=>$attached));}}

        $previousValidation=$previous?$this->validateSnapshot($previous):array();if($previousValidation){$this->finding($findings,'warning','previous_inventory_invalid','previous_snapshot',array('errors'=>array_slice($previousValidation,0,20)));}
        $drift=$previousValidation?array('findings'=>array()):$this->compareSnapshots($previous,$snapshot);foreach($drift['findings'] as $finding){$this->findingArray($findings,$finding);}
        $blocking=$this->countSeverity($findings,'blocking');$warnings=$this->countSeverity($findings,'warning');
        return array(
            'contract'=>self::CONTRACT,'authorizing'=>false,'read_only'=>true,'profile_mutation'=>false,'requires_operator_review'=>true,
            'snapshot_fingerprint'=>(string)($snapshot['snapshot_fingerprint']??''),'previous_snapshot_fingerprint'=>(string)($previous['snapshot_fingerprint']??''),
            'state'=>$blocking?'blocked_drift':($warnings?'review_required':'clean_or_informational'),
            'summary'=>array('blocking'=>$blocking,'warnings'=>$warnings,'info'=>$this->countSeverity($findings,'info'),'profiles'=>count($profiles),'post_types'=>count($postTypes),'taxonomies'=>count($taxonomies),'languages'=>count($languages),'queries'=>count($queries),'query_identities'=>count($identityRecords),'inventory_complete_for_candidates'=>$this->inventorySafeForCandidates($inventory)),
            'findings'=>array_slice($findings,0,self::MAX_FINDINGS),'disabled_candidates'=>$this->inventorySafeForCandidates($inventory)?$this->candidateProfiles($inventory,$profiledPostTypes,$queryConflicts):array(),
        );
    }

    use InventoryReconcilerBindingTrait;
    use InventoryReconcilerDriftTrait;
    use InventoryReconcilerSupportTrait;
}
