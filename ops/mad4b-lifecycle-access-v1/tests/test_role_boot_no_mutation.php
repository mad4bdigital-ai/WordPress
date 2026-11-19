<?php
/* Isolated load fixture. Mock role APIs abort if a boot hook reads/edits roles. */
define('ABSPATH','/');
$GLOBALS['mad4b_hooks']=[];
function add_action($tag,$call,$priority=10,...$rest){$GLOBALS['mad4b_hooks'][$tag][]=[$call,$priority];}
function add_filter($tag,$call,$priority=10,...$rest){$GLOBALS['mad4b_hooks']['filter:'.$tag][]=[$call,$priority];}
function get_option($name,$default=null){return $name==='mad4b_lifecycle_access_schema'?'20261106.2':$default;}
function get_role($name){throw new RuntimeException('Do not read roles during plugin boot');}
function add_role($name,$label,$caps=[]){throw new RuntimeException('Do not create roles');}
$path=getenv('MAD4B_LIFECYCLE_BOOT_FILE');
if (!$path || !is_file($path)) exit(74);
require $path;
if (isset($GLOBALS['mad4b_hooks']['init'])) {
    fwrite(STDERR,"LIFECYCLE_INIT_ROLE_HOOK_UNEXPECTED\n");exit(75);
}
MAD4B_Lifecycle_Access::roles(); // Must remain compatible and read-only.
foreach (['set_user_role','add_user_role','user_register'] as $hook) {
    $rows=$GLOBALS['mad4b_hooks'][$hook]??[];
    if(count($rows)!==1 || $rows[0][0]!==[MAD4B_Lifecycle_Access::class,'wpml']) {
        fwrite(STDERR,"ROLE_CHANGE_HOOK_INVALID\n");exit(76);
    }
}
echo "PASS_LIFECYCLE_COMPATIBLE_NO_ROLE_MUTATIONS\n";
