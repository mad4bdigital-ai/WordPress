<?php
/**
 * Hermetic company admin-menu ordering and capability parity.
 * Does not load WordPress, execute a write, access a database or create grants.
 */
define('ABSPATH','/tmp/');
$GLOBALS['menu_hooks']=[];
$GLOBALS['menu_rows']=[];
$GLOBALS['menu_parents']=['mad4b-control-plane'=>true];
$GLOBALS['grant']=[];
function add_action($event,$callback,$priority=10,$accepted=1) {
    $GLOBALS['menu_hooks'][$event][$priority][]=$callback;
}
function add_filter($event,$callback,$priority=10,$accepted=1) {add_action($event,$callback,$priority,$accepted);}
function add_menu_page($title,$name,$cap,$slug,$cb=null,$icon='',$position=null) {
    $GLOBALS['menu_parents'][$slug]=true;
    $GLOBALS['menu_rows'][]=['kind'=>'parent','slug'=>$slug,'cap'=>$cap,'parent'=>null];
}
function add_submenu_page($parent,$title,$name,$cap,$slug,$cb=null) {
    if(empty($GLOBALS['menu_parents'][$parent]))throw new RuntimeException('submenu before parent: '.$slug);
    $GLOBALS['menu_rows'][]=['kind'=>'child','slug'=>$slug,'cap'=>$cap,'parent'=>$parent];
}
function current_user_can($cap) {return !empty($GLOBALS['grant'][$cap]);}
function wp_get_environment_type() {return 'staging';}
function admin_url($path='') {return 'https://example.test/wp-admin/'.ltrim($path,'/');}
function add_query_arg($key,$val,$url){return $url.'?'.urlencode($key).'='.urlencode($val);}
class MAD4B_SCP_Content_Readiness_Alerts {const PAGE='mad4b-content-readiness-alerts';}
class MAD4B_SCP_Readiness_Workflow_Options {const PAGE='mad4b-content-readiness-workflow';}
class MAD4B_SCP_Content_Completeness {const PAGE='mad4b-content-completeness';}
$root=getenv('MAD4B_PLUGIN_ROOT') ?: dirname(__DIR__);
require $root.'/includes/class-mad4b-scp-admin-route-registry.php';
require $root.'/includes/class-mad4b-scp-company-master-admin.php';
require $root.'/includes/class-mad4b-scp-readiness-integrations.php';
require $root.'/includes/class-mad4b-scp-readiness-scopes.php';
require $root.'/includes/class-mad4b-scp-readiness-preactivation.php';

MAD4B_SCP_Readiness_Integrations::boot();
MAD4B_SCP_Readiness_Scopes::boot();
MAD4B_SCP_Readiness_PreActivation::boot();
$hooks=$GLOBALS['menu_hooks']['admin_menu']??[];
ksort($hooks,SORT_NUMERIC);
foreach($hooks as $priority=>$callbacks)foreach($callbacks as $callback)$callback();
$rows=$GLOBALS['menu_rows'];
$slugs=['mad4b-company','mad4b-content-readiness-alerts','mad4b-content-readiness-workflow',
        'mad4b-content-completeness','mad4b-readiness-scopes','mad4b-readiness-preactivation'];
foreach($slugs as $slug) {
    $matches=array_values(array_filter($rows,static fn($r)=>$r['slug']===$slug));
    $expected=$slug==='mad4b-company'?2:1;
    if(count($matches)!==$expected)throw new RuntimeException('route missing or duplicated: '.$slug.' total='.count($matches));
    foreach($matches as $match)if($match['cap']!=='mad4b_manage_company_operations')
        throw new RuntimeException('capability changed: '.$slug);
    if($matches[0]['cap']!=='mad4b_manage_company_operations')
        throw new RuntimeException('capability changed: '.$slug);
}
$routes=MAD4B_SCP_Admin_Route_Registry::routes();
foreach($slugs as $slug) {
    if(($routes[$slug]['required_capability']??null)!=='mad4b_manage_company_operations')
        throw new RuntimeException('route registry permission mismatch: '.$slug);
}
$GLOBALS['grant']=['mad4b_manage_company_operations'=>true];
if(!MAD4B_SCP_Readiness_Integrations::can_manage() || !MAD4B_SCP_Readiness_PreActivation::can_manage())
    throw new RuntimeException('company operator lost readiness access');
$GLOBALS['grant']=[];
if(MAD4B_SCP_Readiness_Integrations::can_manage() || MAD4B_SCP_Readiness_PreActivation::can_manage())
    throw new RuntimeException('unprivileged caller granted readiness access');
echo 'MAD4B_COMPANY_ADMIN_MENU_HERMETIC PASS parent-order=1 scoped=6 operator_allow=1 anonymous_deny=1'.PHP_EOL;
