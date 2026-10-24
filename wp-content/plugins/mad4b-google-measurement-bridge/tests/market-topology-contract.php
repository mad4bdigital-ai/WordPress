<?php
/** Fail-closed regression: market audit cannot grant spend or mutate profiles. */
$base=dirname(__DIR__);
$src=file_get_contents($base.'/includes/class-mad4b-keyword-market-topology.php');
$required=array(
 'MAD4B_SCP_Search_Context::profile',
 "'independent_market_budget_certified'=>false",
 "'market_capture_ready'=>false",
 "'ready_for_paid_capture'=>false",
 "'mutation_performed'=>false",
 'missing_provider_location_mappings',
 'market_id',
 'profile_market_budget_node_hints',
 'Multiple market identities target country',
 'profile_id'
);
foreach($required as $marker)if(strpos($src,$marker)===false){fwrite(STDERR,"Missing bounded market audit invariant: $marker\n");exit(1);}
foreach(array('wp_remote_request(', 'wp_remote_post(', 'wp_remote_get(', 'MAD4B_SCP_Search_Budgets::reserve(', 'MAD4B_SCP_Search_Runtime::capture_apply(') as $denied)
 if(strpos($src,$denied)!==false){fwrite(STDERR,"Unexpected external/spend operation: $denied\n");exit(1);}
echo "PASS: Multi-Profile/Market topology audit is read-only and fail-closed\n";
