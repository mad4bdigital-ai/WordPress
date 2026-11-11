<?php
/** Isolated structural provider-registration smoke, not a WordPress grant. */
error_reporting(E_ALL);
define('ABSPATH','/offline-test/');
$GLOBALS['hooks'] = [];
$GLOBALS['writes'] = 0;
function add_action($hook,$callback,$priority=10,$argc=1) {
    $GLOBALS['hooks'][] = [$hook,$callback,$priority,$argc];
    return true;
}
function update_option() { $GLOBALS['writes']++; throw new RuntimeException('Write attempted'); }
function add_option() { $GLOBALS['writes']++; throw new RuntimeException('Write attempted'); }
function delete_option() { $GLOBALS['writes']++; throw new RuntimeException('Write attempted'); }
function is_admin() { return false; }
function must($good,$message) { if (!$good) {fwrite(STDERR,"FAIL: ".$message."\n");exit(2);} }
$dir=dirname(__DIR__).'/wp-content/plugins/mad4b-company-portfolio-manager/includes';
$classes=[
    'class-mad4b-company-native-scoped-write-handler.php' => 'MAD4B_Company_Native_Scoped_Write_Handler_V1',
    'class-mad4b-company-native-scoped-write-providers.php' => 'MAD4B_Company_Native_Scoped_Write_Providers_V1',
    'class-mad4b-company-native-mu-deploy-handler.php' => 'MAD4B_Company_Native_MU_Deploy_Handler_V1',
    'class-mad4b-company-native-mu-deploy-provider.php' => 'MAD4B_Company_Native_MU_Deploy_Provider_V1',
    'class-mad4b-company-mu-deploy-lifecycle.php' => 'MAD4B_Company_MU_Deploy_Lifecycle_V1',
    'class-mad4b-company-original-lifecycle-router.php' => 'MAD4B_Company_Original_Lifecycle_Router_V1',
    'class-mad4b-company-host-topology-registry.php' => 'MAD4B_Company_Host_Topology_Registry_V1',
    'class-mad4b-company-host-runner-enrollment.php' => 'MAD4B_Company_Host_Runner_Enrollment_V1',
    'class-mad4b-company-site-operations.php' => 'MAD4B_Company_Site_Operations_V1',
    'class-mad4b-company-distribution-provision.php' => 'MAD4B_Company_Distribution_Provision_V1',
];
foreach ($classes as $file=>$class) {
    require_once $dir.'/'.$file;
    must(class_exists($class,false),'Class declaration missing: '.$class);
}
$required=[
    'mad4b_original_native_operation_providers_register',
    'rest_api_init',
    'mad4b_company_strategy_enrolled',
    'mad4b_company_portfolio_transitioned',
];
foreach ($required as $hook) {
    $found=false;
    foreach ($GLOBALS['hooks'] as $actual) if ($actual[0] === $hook) {$found=true;break;}
    must($found,'Required source hook missing: '.$hook);
}
must($GLOBALS['writes']===0,'Unexpected write during includes');
must(!class_exists('MAD4B_Company_MU_Deploy_Owner_Launch_V1',false),
    'Hardcoded legacy owner launch accidentally loaded');
echo json_encode([
    'contract'=>'mad4b.portfolio-additive-offline-boot.v1',
    'source_classes_loaded'=>count($classes),
    'observed_hooks'=>count($GLOBALS['hooks']),
    'wordPress_writes'=>0,
    'grants_created'=>0,
    'runtime_site_loaded'=>false,
    'hardcoded_legacy_owner_launch_loaded'=>false,
    'pass'=>true
],JSON_UNESCAPED_SLASHES).PHP_EOL;
