<?php
namespace ETG\DynamicFilterSEOBridge\Diagnostics;

require_once dirname( __DIR__ ) . '/Identifiers/QueryId.php';

use ETG\DynamicFilterSEOBridge\Identifiers\QueryId;

trait InventoryReconcilerSupportTrait {
    private function validateSnapshot(array $snapshot):array{
        $errors=array();if(RuntimeInventory::CONTRACT!==(string)($snapshot['contract']??'')){$errors[]='invalid_inventory_contract';}if(!array_key_exists('authorizing',$snapshot)||false!==$snapshot['authorizing']){$errors[]='inventory_must_be_non_authorizing';}if(!array_key_exists('read_only',$snapshot)||true!==$snapshot['read_only']){$errors[]='inventory_must_be_read_only';}if(!array_key_exists('profile_mutation',$snapshot)||false!==$snapshot['profile_mutation']){$errors[]='inventory_profile_mutation_must_be_false';}if(!isset($snapshot['inventory'])||!is_array($snapshot['inventory'])){$errors[]='inventory_payload_missing';return $errors;}
        $inventory=(array)$snapshot['inventory'];$postTypes=(array)($inventory['post_types']??array());$taxonomies=(array)($inventory['taxonomies']??array());$languages=(array)($inventory['languages']??array());$qb=(array)($inventory['query_builder']??array());$queries=(array)($qb['queries']??array());$identities=$this->identityRecords($qb);$comp=(array)($inventory['completeness']??array());
        if(count($postTypes)>RuntimeInventory::MAX_POST_TYPES){$errors[]='inventory_post_type_limit_exceeded';}if(count($taxonomies)>RuntimeInventory::MAX_TAXONOMIES){$errors[]='inventory_taxonomy_limit_exceeded';}if(count($languages)>RuntimeInventory::MAX_LANGUAGES){$errors[]='inventory_language_limit_exceeded';}if(count($queries)>RuntimeInventory::MAX_QUERIES){$errors[]='inventory_query_limit_exceeded';}if(count($identities)>RuntimeInventory::MAX_QUERY_IDENTITIES){$errors[]='inventory_query_identity_limit_exceeded';}
        $archiveCount=0;foreach($postTypes as $record){if(!is_array($record)){continue;}foreach(array_keys((array)($record['archive_paths']??array())) as $key){if('current'!==(string)$key){$archiveCount++;}}}
        $expected=array('post_types'=>array(count($postTypes),RuntimeInventory::MAX_POST_TYPES),'taxonomies'=>array(count($taxonomies),RuntimeInventory::MAX_TAXONOMIES),'languages'=>array(count($languages),RuntimeInventory::MAX_LANGUAGES),'query_builder'=>array(count($queries),RuntimeInventory::MAX_QUERIES),'archive_path_translations'=>array($archiveCount,RuntimeInventory::MAX_ARCHIVE_PATH_TRANSLATIONS));
        foreach($expected as $section=>$v){$errors=array_merge($errors,$this->validateCompletenessRecord((array)($comp[$section]??array()),$section,$v[0],$v[1]));}
        if(isset($comp['query_identity_index'])){$errors=array_merge($errors,$this->validateCompletenessRecord((array)$comp['query_identity_index'],'query_identity_index',count($identities),RuntimeInventory::MAX_QUERY_IDENTITIES));}
        $conflicts=(array)($qb['identity_conflicts']??array());$count=(int)($qb['identity_conflict_count']??0);if($count<0||count($conflicts)>RuntimeInventory::MAX_QUERY_IDENTITY_CONFLICTS||$count<count($conflicts)){$errors[]='inventory_query_identity_conflict_metadata_invalid';}if($count>0&&!$conflicts){$errors[]='inventory_query_identity_conflict_details_missing';}if((true===($qb['identity_conflicts_truncated']??false))!==($count>count($conflicts))){$errors[]='inventory_query_identity_conflict_truncation_mismatch';}
        $seen=array();foreach($conflicts as $conflict){if(!is_array($conflict)){$errors[]='inventory_query_identity_conflict_record_invalid';continue;}$key=QueryId::normalize($conflict['identity_key']??'');if(''===$key||isset($seen[$key])||(int)($conflict['count']??0)<2){$errors[]='inventory_query_identity_conflict_record_invalid';continue;}$seen[$key]=true;}
        $dups=$this->duplicateQueryKeys($identities);$ci=$this->queryConflictIndex($conflicts);foreach($dups as $key){if(!isset($ci[$key])){$errors[]='inventory_query_identity_collision_metadata_mismatch';break;}}
        $fingerprint=(string)($snapshot['snapshot_fingerprint']??'');if(''===$fingerprint||!hash_equals($this->inventoryFingerprint($inventory),$fingerprint)){$errors[]='inventory_fingerprint_mismatch';}return array_values(array_unique($errors));
    }

    private function validateCompletenessRecord(array $record,string $section,$actualIncluded,int $expectedLimit):array{$errors=array();$prefix='inventory_'.sanitize_key($section).'_completeness_';foreach(array('observed_count','included_count','limit','truncated') as $key){if(!array_key_exists($key,$record)){$errors[]=$prefix.'missing';return $errors;}}$observed=(int)$record['observed_count'];$included=(int)$record['included_count'];$limit=(int)$record['limit'];$truncated=true===$record['truncated'];if($observed<0||$included<0||$observed<$included){$errors[]=$prefix.'counts_invalid';}if($expectedLimit!==$limit){$errors[]=$prefix.'limit_mismatch';}if(null!==$actualIncluded&&$included!==(int)$actualIncluded){$errors[]=$prefix.'included_count_mismatch';}if($truncated!==($observed>$included)){$errors[]=$prefix.'truncation_mismatch';}return $errors;}

    private function inventoryFingerprint(array $inventory):string{$json=function_exists('wp_json_encode')?wp_json_encode($inventory,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE):json_encode($inventory,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);return hash('sha256',is_string($json)?$json:'{}');}

    private function invalidResult(array $errors):array{return array('contract'=>self::CONTRACT,'authorizing'=>false,'read_only'=>true,'profile_mutation'=>false,'requires_operator_review'=>true,'state'=>'invalid_inventory','errors'=>array_values(array_unique($errors)),'summary'=>array('blocking'=>count($errors),'warnings'=>0,'info'=>0),'findings'=>array(),'disabled_candidates'=>array());}

    private function inventorySafeForCandidates(array $inventory):bool{foreach(array('post_types','taxonomies','languages','archive_path_translations') as $section){if($this->sectionTruncated($inventory,$section)){return false;}}$qb=(array)($inventory['query_builder']??array());return !empty($qb['available'])&&$this->queryIdentityComplete($inventory)&&0===(int)($qb['identity_conflict_count']??0);}

    private function queryIdentityComplete(array $inventory):bool{$qb=(array)($inventory['query_builder']??array());if(array_key_exists('identity_index_complete',$qb)){return !empty($qb['identity_index_complete']);}$record=(array)(((array)($inventory['completeness']??array()))['query_builder']??array());return empty($record['truncated']);}

    private function queryIdentityCompleteFromTopology(array $topology):bool{return !empty($topology['available'])&&!empty($topology['contract'])&&empty($topology['truncated'])&&!empty($topology['bindings']);}

    private function identityRecords(array $qb):array{$records=isset($qb['identity_index'])&&is_array($qb['identity_index'])?(array)$qb['identity_index']:(array)($qb['queries']??array());return array_slice($records,0,RuntimeInventory::MAX_QUERY_IDENTITIES);}

    private function sectionTruncated(array $inventory,string $section):bool{$record=(array)(((array)($inventory['completeness']??array()))[$section]??array());return !empty($record['truncated']);}

    private function sectionComparable(array $before,array $after,string $section):bool{return !$this->sectionTruncated($before,$section)&&!$this->sectionTruncated($after,$section);}

    private function queryIndex(array $queries,array $conflicts):array{$out=array();foreach(array_slice($queries,0,RuntimeInventory::MAX_QUERY_IDENTITIES) as $query){if(!is_array($query)){continue;}$key=$this->queryIdentityKey($query);if(''===$key||isset($conflicts[$key])||isset($out[$key])){continue;}$out[$key]=$query;}ksort($out,SORT_STRING);return $out;}

    private function queryConflictIndex(array $conflicts):array{$out=array();foreach(array_slice($conflicts,0,RuntimeInventory::MAX_QUERY_IDENTITY_CONFLICTS) as $conflict){if(!is_array($conflict)){continue;}$key=QueryId::normalize($conflict['identity_key']??'');if(''!==$key){$out[$key]=$conflict;}}ksort($out,SORT_STRING);return $out;}

    private function duplicateQueryKeys(array $queries):array{$counts=array();foreach($queries as $query){if(!is_array($query)){continue;}$key=$this->queryIdentityKey($query);if(''===$key){continue;}$counts[$key]=isset($counts[$key])?$counts[$key]+1:1;}$out=array();foreach($counts as $key=>$count){if($count>1){$out[]=$key;}}sort($out,SORT_STRING);return $out;}

    private function queryIdentityKey(array $query):string{return QueryId::normalize(($query['identity_key']??'')?:(($query['custom_query_id']??'')?:($query['id']??'')));}

    private function languageIndex(array $languages):array{$out=array();foreach(array_slice($languages,0,RuntimeInventory::MAX_LANGUAGES) as $language){if(!is_array($language)){continue;}$code=sanitize_key((string)($language['code']??''));if(''!==$code){$out[$code]=$language;}}ksort($out,SORT_STRING);return $out;}

    private function cleanKeys(array $values):array{$out=array_values(array_unique(array_filter(array_map('sanitize_key',array_map('strval',$values)))));sort($out,SORT_STRING);return $out;}

    private function normalizePath(string $path):string{$path=rawurldecode(trim($path));if(''===$path){return'';}$parsed=parse_url($path,PHP_URL_PATH);if(is_string($parsed)&&''!==$parsed){$path=$parsed;}$path='/'.trim($path,'/');return'/'===$path?'/':$path.'/';}

    private function stableHash(array $value):string{$this->sortRecursive($value);$json=function_exists('wp_json_encode')?wp_json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE):json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);return hash('sha256',is_string($json)?$json:'{}');}

    private function sortRecursive(array &$value):void{foreach($value as &$child){if(is_array($child)){$this->sortRecursive($child);}}unset($child);if(array_keys($value)!==array_keys(array_values($value))){ksort($value,SORT_STRING);}}

    private function countSeverity(array $findings,string $severity):int{$count=0;foreach($findings as $finding){if($severity===(string)($finding['severity']??'')){$count++;}}return$count;}

    private function finding(array &$findings,string $severity,string $code,string $subject,array $details=array()):void{if(count($findings)>=self::MAX_FINDINGS){return;}$findings[]=$this->findingValue($severity,$code,$subject,$details);}

    private function findingArray(array &$findings,array $finding):void{if(count($findings)<self::MAX_FINDINGS){$findings[]=$finding;}}

    private function findingValue(string $severity,string $code,string $subject,array $details=array()):array{return array('severity'=>in_array($severity,array('info','warning','blocking'),true)?$severity:'warning','code'=>sanitize_key($code),'subject'=>sanitize_text_field($subject),'details'=>$details);}
}
