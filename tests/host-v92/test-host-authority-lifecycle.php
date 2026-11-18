<?php
/** Hermetic WordPress PHP 8.5 contract; no database, HTTP or root signer. */
declare(strict_types=1);
define('ABSPATH', '/source');
define('MAD4B_TEST_MODE', true);
define('MAD4B_SCP_FILE','/source/wp-content/plugins/mad4b-site-control-plane/plugin.php');
define('MAD4B_SCP_VERSION','v92');
$GLOBALS['allowed']=true;
$GLOBALS['queries']=0;
$GLOBALS['routes']=[];
$GLOBALS['mutations']=0;
class TestDatabase {
    public string $options='wp_options';
    public function esc_like($s) { return addcslashes($s,'_%\\'); }
    public function prepare($sql,...$args) { return [$sql,$args]; }
    public function get_col($stmt) {
        $GLOBALS['queries']++;
        return array_keys($GLOBALS['options']);
    }
}
$GLOBALS['wpdb']=new TestDatabase();
function current_user_can($cap) { return $GLOBALS['allowed']&&$cap==='manage_options'; }
function wp_enqueue_script(...$args) {}
function plugins_url($path,$base) { return 'https://tours.dev.mad4b.com/assets/'.$path; }
function esc_attr($text) { return htmlspecialchars((string)$text,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
function wp_create_nonce($name) { return 'test-nonce'; }
function get_num_queries() { return $GLOBALS['queries']; }

function get_option($name,$default=false) { return $GLOBALS['options'][$name]??$default; }
function wp_is_uuid($s) { return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',$s)===1; }
function rest_ensure_response($data) { return new TestResponse($data); }
class WP_Error {
    public function __construct(public string $code, public string $message, public array $data) {}
}
class TestResponse {
    public array $headers=[];
    public function __construct(public array $data) {}
    public function header($key,$value) { $this->headers[$key]=$value; }
}
function register_rest_route($ns,$route,$args) { $GLOBALS['routes'][]=compact('ns','route','args'); }
function esc_html($text) { return htmlspecialchars((string)$text, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
function esc_url($url) { return htmlspecialchars((string)$url, ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
function admin_url($path) { return 'https://tours.dev.mad4b.com/wp-admin/'.$path; }
function wp_die($message) { throw new RuntimeException($message); }

class MAD4B_Company_Native_WPCLI_Enrollment_V1 {
    const SLOT='mad4b_company_native_wpcli_slot_v1_';
    public static function readback($company,$environment) {
        return ['state'=>'ORIGINAL_305_PERSISTENT_PAIR_ENROLLED'];
    }
}
class MAD4B_Central_SSH_Command_Intake_V1 {
    const TRANSPORT_PREFIX='native_transport_';
    const PREFIX='native_grant_';
}
class MAD4B_Original_Remote_Hostinger_328_V1 {
    public static function active($transport,$grant) {
        return $transport['active']===true && $grant['active']===true;
    }
}
class MAD4B_Original_Native_Operation_Providers_V1 {
    public static function discover($op) {
        return ['operations'=>[[
            'operation'=>$op,
            'registration_verified'=>true,
            'provider_readback_state'=>'FIRST_PARTY_ORIGINAL312_ISSUER_NOT_COMMISSIONED',
            'provider_capability_ready'=>false,
        ]]];
    }
}
class MAD4B_Native_Host_Exact_Grant_V1 {
    public static function current_anchor_readback_v2($company,$env) {
        return ['state'=>'MISSING_OR_INVALID','revision'=>0,'readback_verified'=>false,
            'original313_certified'=>false,'write_authorized'=>false];
    }
}

$base=MAD4B_Company_Native_WPCLI_Enrollment_V1::SLOT;
$site='49c562d1-8f2f-456f-b454-26816c6ba4cb';
$GLOBALS['options']=[
    $base.'all-royal-egypt_staging'=>[
        'company'=>'all-royal-egypt','environment'=>'staging',
        'site_uuid'=>$site,'transport_id'=>'1','grant_id'=>'1',
    ],
    $base.'wayfatours_production'=>[
        'company'=>'wayfatours','environment'=>'production',
        'site_uuid'=>$site,'transport_id'=>'2','grant_id'=>'2',
    ],
    // A valid-looking slot name must not enable an unvalidated stored scope.
    $base.'foreign-site_staging'=>[
        'company'=>'other-site','environment'=>'staging','site_uuid'=>$site,
    ],
    $base.'evil<script>_staging'=>[
        'company'=>'evil<script>','environment'=>'staging','site_uuid'=>$site,
    ],
    'native_transport_1'=>['active'=>true],
    'native_transport_2'=>['active'=>false],
    'native_grant_1'=>['active'=>true],
    'native_grant_2'=>['active'=>false],
];

require '/source/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-deferred-admin-reports.php';
$class=MAD4B_SCP_Host_Authority_Lifecycle::class;
$checks=0;
function check($ok,$message) {
    global $checks;
    $checks++;
    if (!$ok)throw new RuntimeException('FAIL '.$checks.': '.$message);
}
$class::register_routes();
check(count($GLOBALS['routes'])===1,'one route only');
$route=$GLOBALS['routes'][0];
check($route['ns']==='mad4b/v1','namespace');
check($route['route']==='/host-authority-lifecycle','exact endpoint');
check($route['args']['methods']==='GET','read-only GET');
check(is_callable($route['args']['permission_callback']),'permission callback');
check(call_user_func($route['args']['permission_callback'])===true,'authorized');
$snapshot=$class::snapshot();
check($snapshot['target_count']===2,'scope derived from enrolled slots only');
check(count($snapshot['targets'])===2,'no foreign or untrusted scope');
check($snapshot['targets'][0]['original328_active']===true,'328 active verified');
check($snapshot['targets'][1]['original328_active']===false,'328 revoked retained');
check($snapshot['targets'][0]['anchor_state']==='MISSING_OR_INVALID','318 actual readback');
check($snapshot['original313_remote_receipt_verified']===false,'not a fake 313 certificate');
check($snapshot['root_highwater_verified']===false,'not a fake root ledger');
check($snapshot['issue_execution_available']===false,'no premature issue');
check($snapshot['revoke_execution_available']===false,'no premature revoke');
check($snapshot['targets'][0]['lifecycle_execution_enabled']===false,'no target exception');
$response=$class::rest_read();
check($response instanceof TestResponse,'response object');
check(str_contains($response->headers['Cache-Control'],'no-store'),'not cached');
ob_start();$class::render_admin();$html=ob_get_clean();
check(str_contains($html,'Current company Host Authority'),'accessible section heading');
check(str_contains($html,'all-royal-egypt'),'actual enrolled scope');
check(!str_contains($html,'evil&lt;script&gt;'),'malicious slot filtered');
check(substr_count($html,' disabled ')===0,'no misleading disabled action buttons');
check(str_contains($html,'Next step:'),'one clear next step');
check(str_contains($html,'Technical evidence and exact provider states'),'technical audit collapsed');
check(str_contains($html,'Not independently certified'),'root independence shown');
ob_start();MAD4B_SCP_Deferred_Admin_Reports::render('operator');$top=ob_get_clean();
check(str_contains($top,'data-report="host_authority"'),'operator refresh button wired');
check(str_contains($top,'data-auto-report="host_authority"'),'single automatic light read');
check(str_contains($top,'<details id="mad4b-advanced-operator-checks"'),'technical diagnostics collapsed');
check(substr_count($top,'<details')===1,'one advanced section only');
$measured=MAD4B_SCP_Deferred_Admin_Reports::measure('host_authority');
check($measured['state']==='MEASURED' && str_contains($measured['html'],'Current company Host Authority'),
      'on-demand report renders');
check($measured['ready']===null,'no self-certification from report');
check($GLOBALS['mutations']===0,'no writes');
$GLOBALS['allowed']=false;
$previousQueries=$GLOBALS['queries'];
check(call_user_func($route['args']['permission_callback'])===false,'not anonymously authorized');
$restDenied=$class::rest_read();
check($restDenied instanceof WP_Error
    && $restDenied->code==='mad4b_host_authority_forbidden'
    && $restDenied->data['status']===403,'direct REST callback also denied');
$forbidden=$class::snapshot();
check($forbidden['state']==='FORBIDDEN','forbidden explicit');
check($GLOBALS['queries']===$previousQueries,'forbidden snapshot never touches DB');
try {$class::render_admin();throw new RuntimeException('render should have denied');}
catch (RuntimeException $e) {check($e->getMessage()==='Administrator capability required.','render denied');}
echo json_encode(['state'=>'PASS','checks'=>$checks,'targets'=>2,
    'rest_get_only'=>true,'original313_signed'=>false,'authority_mutated'=>false]).PHP_EOL;
