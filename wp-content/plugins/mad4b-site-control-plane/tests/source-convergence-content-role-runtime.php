<?php
/** Hermetic, non-authorizing tests for source-neutral content type enrollment. */
error_reporting(E_ALL);
define('ABSPATH', __DIR__ . '/');
class MAD4B_SCP_Site_Profile {
    public static $enrolled = false;
    public static $same_origin = false;
    public static function origin_enrolled() { return self::$enrolled; }
    public static function site_urls_match_enrollment() { return self::$same_origin; }
}
$GLOBALS['mad4b_roles'] = array();
$GLOBALS['mad4b_types'] = array();
function apply_filters($name,$default) { return $GLOBALS['mad4b_roles']; }
function get_post_type_object($name) { return $GLOBALS['mad4b_types'][$name] ?? null; }
function ensure_role($test,$label) { if(!$test){fwrite(STDERR, "FAIL: $label
");exit(1);} }
require_once dirname(__DIR__).'/includes/class-mad4b-scp-content-type-roles.php';
$cls='MAD4B_SCP_Content_Type_Roles';
ensure_role(''===$cls::get('tour'),'Unenrolled site fails closed');
MAD4B_SCP_Site_Profile::$enrolled = true;
ensure_role(''===$cls::get('tour'),'Source identity mismatch fails closed');
MAD4B_SCP_Site_Profile::$same_origin=true;
ensure_role(''===$cls::get('tour'),'No implicit tour CPT');
$GLOBALS['mad4b_roles']['tour']='tours-and-activities';
$GLOBALS['mad4b_types']['tours-and-activities']=(object)array('public'=>true,'_builtin'=>false);
ensure_role('tours-and-activities'===$cls::get('tour'),'Explicit enrolled role resolves');
ensure_role(''===$cls::get('unknown'),'Unexpected role denied');
$GLOBALS['mad4b_roles']['tour']='post';
$GLOBALS['mad4b_types']['post']=(object)array('public'=>true,'_builtin'=>true);
ensure_role(''===$cls::get('tour'),'Built-in type rejected');
$GLOBALS['mad4b_roles']['tour']='private_tours';
$GLOBALS['mad4b_types']['private_tours']=(object)array('public'=>false,'_builtin'=>false);
ensure_role(''===$cls::get('tour'),'Non-public type rejected');
$GLOBALS['mad4b_roles']['tour']='foreign/scope';
ensure_role(''===$cls::get('tour'),'Invalid type token rejected');
echo "SOURCE_CONVERGENCE_CONTENT_TYPE_ROLE: PASS
";
