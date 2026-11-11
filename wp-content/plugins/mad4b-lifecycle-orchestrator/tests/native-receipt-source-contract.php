<?php
$root=dirname(__DIR__);
$source=file_get_contents($root.'/class-mad4b-lifecycle-native-receipt.php');
$main=file_get_contents($root.'/mad4b-lifecycle-orchestrator.php');
$checks=[
 'exact source journal read'=>str_contains($source,'MAD4B_SCP_Mutation_Manager::get($id)'),
 'only reviewed SEO and Media'=>str_contains($source,"'media/update-metadata','seo/update-meta'"),
 'original provider registry'=>str_contains($source,'MAD4B_SCP_Adapter_Registry::instance()'),
 'original readback'=>str_contains($source,'read_reversible_state('),
 'verified journal required'=>str_contains($source,"$".'row[\'verification_code\']'),
 'after hash comparison'=>str_contains($source,'hash_equals($expected,$fresh_hash)'),
 'rollback payload integrity'=>str_contains($source,'hash_equals(hash('),
 'adapter cannot drift'=>str_contains($source,'receipt_adapter_drift'),
 'expected adapter binding'=>str_contains($source,'receipt_ability_adapter_mismatch'),
 'no guessed Rank Math score'=>str_contains($source,"score_recomputed")&&str_contains($source,"=false"),
 'historical record not current truth'=>str_contains($source,"'historical_journal_verified'"),
 'stale source does not claim success'=>str_contains($source,"'verified'=>false"),
 'current source verified explicit'=>str_contains($source,"'source_readback_current']=true"),
 'no source mutation'=>!str_contains($source,'wp_update_post(')&&!str_contains($source,'update_post_meta('),
 'no generic ability execute'=>!str_contains($source,'wp_get_ability('),
 'only authenticated admin'=>str_contains($source,'MAD4B_Lifecycle_Orchestrator::can_read()'),
 'read-only native Ability'=>str_contains($source,'mad4b/lifecycle-native-receipt'),
 'read-only GET API'=>str_contains($source,"'methods'=>'GET'"),
 'registered plugin class'=>str_contains($main,"class-mad4b-lifecycle-native-receipt.php"),
 'plugin booted'=>str_contains($main,"MAD4B_Lifecycle_Native_Receipt','boot'"),
];
foreach($checks as $name=>$ok)if(!$ok){fwrite(STDERR,"FAIL native receipt contract: ".$name.PHP_EOL);exit(1);}
echo 'PASS native receipt source: '.count($checks).' checks'.PHP_EOL;
