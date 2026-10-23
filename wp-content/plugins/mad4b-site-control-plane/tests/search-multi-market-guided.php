<?php
/** Static regression check: guided Search Profile creation supports markets without silently enabling spend. */
$base=dirname(__DIR__).'/includes/search/class-mad4b-scp-search-profile-admin.php';
$context=dirname(__DIR__).'/includes/search/class-mad4b-scp-search-context.php';
$a=file_get_contents($base);$c=file_get_contents($context);
$need=array(
 'additional_market_countries',
 "'map_provider_location' === \$input['operation']",
 'Save provider location mapping',
 'MAD4B_SCP_Search_Budgets::status',
 'Provider account budget readiness (read-only)',
 'location_precision',
 "'add_market' === \$input['operation']",
 'market_duplicate_country',
 'profile_targeting_requires_pause_and_spend_freeze',
 'Add market and verify',
 "\$base = 'search-main'",
 'count( $additional ) > 63',
 'isset( $seen_countries[ $country ] )',
 "'markets' => ".'$markets',
 "'enabled' => false",
 "'freeze_spend' => true",
 'Search Profile can contain up to 64 target markets'
);
foreach($need as $fragment)if(strpos($a,$fragment)===false){fwrite(STDERR,"Missing multi-market guard: $fragment\n");exit(1);}
if(strpos($c,'count( $out[\'markets\'] ) > 64')===false){fwrite(STDERR,"Canonical 64 market limit missing\n");exit(1);}
if(strpos($a,"\$base = 'search-' . strtolower( \$market_country )")!==false){fwrite(STDERR,"Country-derived profile IDs forbidden\\n");exit(1);}
echo "PASS: multi-market guided creation retains paused/frozen defaults and canonical market limit\n";
