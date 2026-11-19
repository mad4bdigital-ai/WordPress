<?php
/**
 * Non-mutating regression for narrow, source-reviewed Tours.dev fixes.
 * No WordPress bootstrap, network, database, role write or owner grant.
 */
error_reporting( E_ALL );
define( 'ABSPATH', __DIR__ . '/' );
class WP_User { public $roles = array(); }
class WP_Error { public function __construct($code,$message='',$data=array()) {} }
class WP_Role_Fixture {
    public $capabilities;
    public $name;
    public function __construct($caps,$name) { $this->capabilities=$caps;$this->name=$name; }
}
$GLOBALS['roles_fixture'] = array(
    'editor' => new WP_Role_Fixture( array('read'=>true,'edit_posts'=>true), 'Editor' ),
    'author' => new WP_Role_Fixture( array('read'=>true,'edit_posts'=>true), 'Author' ),
    'operator' => new WP_Role_Fixture( array('read'=>true,'edit_posts'=>true), 'Operator' ),
);
$GLOBALS['caller_caps_fixture'] = array('read'=>true,'edit_posts'=>true);
function add_action($hook, $cb, $priority=10, $argc=1) { /* no action execution in hermetic fixture */ }
function get_role($name) {return $GLOBALS['roles_fixture'][$name] ?? null;}
function current_user_can($name) {return !empty($GLOBALS['caller_caps_fixture'][$name]);}
function sanitize_key($value) {return strtolower(preg_replace('/[^a-z0-9_\-]/i','',(string)$value));}
function apply_filters($hook,$default) {return $default;}
function get_post_status($id) {return $id === 7 ? 'publish' : 'draft';}
function absint($value) {return abs((int)$value);}
function is_wp_error($value) {return $value instanceof WP_Error;}
function ensure($condition,$name) {if (!$condition) {fwrite(STDERR,'FAIL: '.$name.PHP_EOL);exit(1);}}
require_once dirname(__DIR__).'/includes/class-mad4b-scp-company-master-admin.php';
require_once dirname(__DIR__).'/includes/class-mad4b-scp-impact-policy.php';
$cls = 'MAD4B_SCP_Company_Master_Admin';
ensure( $cls::roleWithinCaller('editor'), 'Allow bounded role entirely within caller abilities');
ensure( !$cls::roleWithinCaller('operator'), 'Deny broad operator role');
ensure( !$cls::roleWithinCaller('administrator'), 'Deny administrator escalation');
$GLOBALS['roles_fixture']['editor']->capabilities['manage_options']=true;
ensure( !$cls::roleWithinCaller('editor'), 'Deny any role containing forbidden system capability');
unset($GLOBALS['roles_fixture']['editor']->capabilities['manage_options']);
unset($GLOBALS['caller_caps_fixture']['edit_posts']);
ensure( !$cls::roleWithinCaller('editor'), 'Deny role wider than current caller even if in allowlist');
$GLOBALS['caller_caps_fixture']['edit_posts']=true;
$policy = 'MAD4B_SCP_Impact_Policy';
ensure( 'high' === $policy::impact_for('mad4b/content-set-meta','core',array('meta_key'=>'tour_booking_deposit_amount')), 'Deposit metadata high impact');
ensure( 'high' === $policy::impact_for('mad4b/content-set-meta','core',array('meta_key'=>'adult_price')), 'Price metadata high impact');
ensure( 'high' === $policy::impact_for('mad4b/content-delete-meta','core',array('meta_key'=>'currency')), 'Currency delete high impact');
ensure( 'high' === $policy::impact_for('mad4b/content-update-post','core',array('post_id'=>7)), 'Already published content high impact');
ensure( true === $policy::requires_approval('mad4b/content-set-meta','core',array('meta_key'=>'booking_deposit')), 'Commercial changes require approval');
ensure( 'low' === $policy::impact_for('mad4b/content-set-meta','core',array('meta_key'=>'editorial_note')), 'Unrelated draft metadata remains ordinary');
echo "SOURCE_CONVERGENCE_LIVE_SAFETY_REGRESSION: PASS\n";
