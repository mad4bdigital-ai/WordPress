<?php
/** Source-only acceptance: requires no WordPress or live provider mutations. */
$dir=dirname(__DIR__);
$policy=file_get_contents($dir.'/class-mad4b-unified-governed-lifecycle-policy.php');
$core=file_get_contents($dir.'/mad4b-lifecycle-orchestrator.php');
$checks=[
 'versioned policy contract'=>str_contains($policy,'mad4b.unified-governed-lifecycle-policy.v1'),
 'all runtime states declared'=>count(array_filter([
    'READY','RUNNING','RECOVERING','PARTIAL','BLOCKED_AUTHORITY',
    'BLOCKED_DEPENDENCY','NEEDS_RECONCILIATION','VERIFIED'
 ],static fn($v)=>str_contains($policy,"'".$v."'")))===8,
 'SEO graph'=>str_contains($policy,"case 'seo':"),
 'Media graph'=>str_contains($policy,"case 'media':"),
 'Content graph'=>str_contains($policy,"case 'content':"),
 'Company graph'=>str_contains($policy,"case 'company':"),
 'SEO content diagnostics independent'=>str_contains($policy,"'content_plan'"),
 'SEO media plan independent'=>str_contains($policy,"'media_plan'"),
 'Rank Math native write gated'=>str_contains($policy,"'seo/update-meta','write'"),
 'Media native write gated'=>str_contains($policy,"'media/update-metadata','write'"),
 'Content Hub queue native'=>str_contains($policy,"'mad4b/hub-bulk-enqueue','write'"),
 'Company apply original provider'=>str_contains($policy,"'company_apply'"),
 'dependent stage not considered executed'=>str_contains($policy,'prerequisite_execution_receipt_missing'),
 'native scoring source owned'=>str_contains($policy,'source_owned_scoring_required'),
 'write readiness not implicit'=>str_contains($policy,'native_execution_requires_exact_principal_and_provider_gates'),
 'native receipt not fabricated'=>str_contains($policy,"'provider_mutation_receipt'=>null"),
 'provider write not performed'=>str_contains($policy,"'provider_write_executed'=>false"),
 'production mutation forbidden'=>str_contains($policy,"'production_mutation_allowed'=>false"),
 'explicit release not approved'=>str_contains($policy,"'delivery_release_approved'=>false"),
 'uncertain journal state recognized'=>str_contains($policy,"'NEEDS_RECONCILIATION'"),
 'busy/timeout state recognized'=>str_contains($policy,"'RECOVERING'"),
 'authority state recognized'=>str_contains($policy,"'BLOCKED_AUTHORITY'"),
 'dependency state recognized'=>str_contains($policy,"'BLOCKED_DEPENDENCY'"),
 'blind write retry denied'=>str_contains($policy,"'retry_write'=>false"),
 'readiness graph read-only'=>!str_contains($policy,'wp_update_post(')&&!str_contains($policy,'wp_get_ability('),
 'no shell executor'=>!str_contains($policy,'shell_exec(')&&!str_contains($policy,'exec('),
 'admin dashboard'=>str_contains($policy,"'mad4b-unified-lifecycle-policy'"),
 'dashboard no mutation'=>str_contains($policy,'No mutations were performed by this inspection'),
 'native Staging fence'=>str_contains($policy,'https://tours.dev.mad4b.com/'),
 'central policy required'=>str_contains($core,'MAD4B_Unified_Governed_Lifecycle_Policy::plan'),
 'new read-only policy Ability'=>str_contains($core,'mad4b/lifecycle-policy-plan'),
 'read-only policy REST'=>str_contains($core,"'policy'=>['policy','GET']"),
 'dynamic diagram not stored in journal'=>str_contains($core,"unset(")&&str_contains($core,"dependency_graph"),
 'reconciliation errors classified'=>str_contains($core,'::classify_error($resolved)'),
 'original user remains actor'=>str_contains($core,'administrator_actor_user_id'),
 'native provider execution absent'=>!str_contains($core,'wp_get_ability('),
 'old source-verification test retained'=>str_contains($core,'private static function source_verified'),
];
foreach($checks as $name=>$pass){
 if(!$pass){fwrite(STDERR,"FAIL policy contract: ".$name.PHP_EOL);exit(1);}
}
echo 'PASS unified governed lifecycle policy: '.count($checks).' source checks'.PHP_EOL;
