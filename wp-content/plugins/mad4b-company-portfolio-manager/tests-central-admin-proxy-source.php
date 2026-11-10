<?php
$root=__DIR__;
$code=file_get_contents($root.'/class-mad4b-central-admin-proxy.php');
$manager=file_get_contents($root.'/mad4b-company-portfolio-manager.php');
$orchestrator=file_get_contents(dirname($root).'/mad4b-lifecycle-orchestrator/mad4b-lifecycle-orchestrator.php');
$tests=[
 'central administrator source verification'=>str_contains($code,"current_user_can('manage_options')"),
 'central platform administrator plugin control'=>str_contains($code,"current_user_can('activate_plugins')"),
 'verified company site binding'=>str_contains($code,'::company_binding($code)'),
 'original authenticated principal'=>str_contains($code,'get_current_user_id()'),
 'disclosed proxy mode'=>str_contains($code,"'central_admin_proxy'"),
 'explicit company scoped operation list'=>str_contains($code,'const OPERATIONS='),
 'stale binding rejected'=>str_contains($code,'company_binding_stale'),
 'event idempotency'=>str_contains($code,'idempotency_conflict'),
 'intent reason digest only'=>str_contains($code,"'reason_sha256'"),
 'no owner approval substitution'=>str_contains($code,"'owner_approval_substituted'=>false"),
 'no remote write grant'=>str_contains($code,"'remote_write_authority_granted'=>false"),
 'native provider still required'=>str_contains($code,"'provider_specific_permission_required'=>true"),
 'no direct native executor'=>!str_contains($code,'wp_get_ability(')&&!str_contains($code,'wp_update_post('),
 'REST write nonce'=>str_contains($code,"'rest_write_permission'"),
 'admin form nonce'=>str_contains($code,'check_admin_referer('),
 'admin company UI'=>str_contains($code,'Act for Company'),
 'original user verifies own journal'=>str_contains($code,"'original_actor_required'"),
 'REST context read only'=>str_contains($code,"'methods'=>'GET'"),
 'source manager plugin includes proxy'=>str_contains($manager,"class-mad4b-central-admin-proxy.php"),
 'orchestrator accepts central role'=>str_contains($orchestrator,"'central_admin_proxy'"),
 'orchestrator admin actor derived from session'=>str_contains($orchestrator,"$".'event[\'actor_user_id\']=get_current_user_id()'),
 'orchestrator retains real admin identity'=>str_contains($orchestrator,'administrator_actor_user_id'),
 'orchestrator checks restored principal'=>str_contains($orchestrator,"user_can(")&&str_contains($orchestrator,"'manage_options'"),
 'orchestrator refuses provider mutation'=>str_contains($orchestrator,"'mutation_authorized'=>false"),
];
foreach($tests as $name=>$ok){if(!$ok){fwrite(STDERR,"FAIL central admin proxy: $name\n");exit(1);}}
echo 'PASS central admin proxy source-only: '.count($tests).' checks'.PHP_EOL;
