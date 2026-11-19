<?php
/** Hermetic, side-effect-free first-party release-readiness boundary. */
define('ABSPATH', __DIR__.'/');
$GLOBALS['abilities'] = array();
$GLOBALS['hooks'] = array();
function add_action($hook,$handler,$priority=10) { $GLOBALS['hooks'][]=array($hook,$handler,$priority); }
function wp_has_ability($ability) {return $ability==='mad4b/company-code-release-dispatch';}
function wp_register_ability($name,$args) { $GLOBALS['abilities'][$name]=$args; }
class MAD4B_Release_Source_Admission {
    public static $preflight=array('preflight_ready'=>true,'source'=>'fixture');
    public static function check() {return self::$preflight;}
}
function ensure_release($ok,$name) {if(!$ok){fwrite(STDERR,"FAIL: $name
");exit(1);}}
require_once dirname(__DIR__).'/includes/class-mad4b-scp-company-code-release-readiness.php';
$c='MAD4B_SCP_Company_Code_Release_Readiness';
$c::register_ability();
ensure_release(isset($GLOBALS['abilities'][$c::ABILITY]),'Original read-only ability registered');
ensure_release(($GLOBALS['abilities'][$c::ABILITY]['meta']['annotations']['readonly']??false)===true,'Ability remains read-only');
$x=$c::status(array());
ensure_release($x['host_preflight_ready']===true,'Preflight observation surfaced');
ensure_release($x['dispatch_ability_registered']===true,'Registration observed');
ensure_release($x['production_release_admitted']===false && $x['broker_grant_verified']===false
    && $x['original_313_receipt_verified']===false,'No forged Production grant or independent Host313 receipt');
ensure_release($x['mutation_performed']===false && $x['authority_created']===false
    && $x['read_only']===true,'Readiness cannot mutate');
$invalid=$c::status(array('execute'=>true));
ensure_release($invalid['input_valid']===false && $invalid['production_release_admitted']===false,'Unexpected action rejected');
MAD4B_Release_Source_Admission::$preflight=array();
$unverified=$c::status(array());
ensure_release($unverified['host_preflight_ready']===false && $unverified['end_to_end_complete']===false,'Missing host preflight stays blocked');
echo "SOURCE_CONVERGENCE_COMPANY_RELEASE_READINESS: PASS
";
