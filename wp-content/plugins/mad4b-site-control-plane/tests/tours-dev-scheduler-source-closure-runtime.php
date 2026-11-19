<?php
/** No-network source-closure, explicit tenant-role and no-autoboot test. */
error_reporting(E_ALL);
define('ABSPATH',__DIR__.'/');
function demand($ok,$why){if(!$ok){fwrite(STDERR,'FAIL: '.$why.PHP_EOL);exit(1);}}
function apply_filters($name,$default){return $name==='mad4b_scp_content_type_roles'?$GLOBALS['scoped_roles']:$default;}
function get_post_type_object($name){$x=$GLOBALS['registered_types'][$name]??null;return $x ? (object)$x : null;}
class MAD4B_SCP_Site_Profile {public static $enrolled=false;public static function origin_enrolled(){return self::$enrolled;}public static function site_urls_match_enrollment(){return self::$enrolled;}}
$GLOBALS['scoped_roles']=array();$GLOBALS['registered_types']=array();
$root=dirname(__DIR__);$main=file_get_contents($root.'/mad4b-site-control-plane.php');
demand(is_string($main),'Candidate Bootstrap required');
$originals=array(
    'includes/class-mad4b-scp-brand-experience-quality.php'=>array('183a282254251deb116194f3ef7c8863f2f2836b45235ca27e639c4b0b98ac63','0c72426b00ff470e407cd3dca909d6685b78c1de7bd42016fd649fb4b57408ef',true),
    'includes/class-mad4b-scp-brand-integrity.php'=>array('969b1ef0033e01bf2bb0919f5b2d29aa84d10732d87dd4ef906a8523c1f3a933','969b1ef0033e01bf2bb0919f5b2d29aa84d10732d87dd4ef906a8523c1f3a933',false),
    'includes/class-mad4b-scp-content-completeness.php'=>array('75c5a73e1a91e9b356f7f5adad494789daf8a774d754f33620d4c395b152bee8','75c5a73e1a91e9b356f7f5adad494789daf8a774d754f33620d4c395b152bee8',false),
    'includes/class-mad4b-scp-content-readiness-alerts.php'=>array('b04424d4bbe900747555452fdd5c0b083dff761635605e3daf9338a1d5b2affb','48d22fac76c26a36a462d7a84aeb0e10cf00f39901f6eef5fe87fea16be9bb4b',true),
    'includes/class-mad4b-scp-core-web-vitals.php'=>array('744a7ff5e6a1cc5fc3c6b0d4549fb6f5dbc08ddfc10fa388ec9c70f9047955f8','3c34e0e13c6be8b24cbde1c0ece9ffc8cab2998ddc83be39e5441fc566251967',true),
    'includes/class-mad4b-scp-frontend-impact-priority.php'=>array('629c18865d6d47f4613cab3cd0e91bea3f5cdf09603702403726c794f5d4b22e','6aa06c83ae42da7b86e4afcf17373f814e34b7ca217eeecba79d3a977fdeaecd',true),
    'includes/class-mad4b-scp-geo-quality.php'=>array('220d953c9576b615b9ff8b82406e9674d1e6d11f96de468973a552c152ea2ebb','79a3f48ee3563338d2f185770df29614b2910d78eb6561499dac078ba2c74098',true),
    'includes/class-mad4b-scp-hub-jetengine-consumer-governance.php'=>array('857ccb61407c483c90e6874c9e3c3ff7d7008ebe8ae4d552dc8c04cb5ac729be','857ccb61407c483c90e6874c9e3c3ff7d7008ebe8ae4d552dc8c04cb5ac729be',false),
    'includes/class-mad4b-scp-locale-experience-alerts.php'=>array('ca05fccee813f2b614d9bedbfdf9d51620e24ef2d56338211fc85d280d1362f9','ca05fccee813f2b614d9bedbfdf9d51620e24ef2d56338211fc85d280d1362f9',false),
    'includes/class-mad4b-scp-presentation-browser-evidence.php'=>array('20bb927951251ec356e2c6f1a8c135a22d1e7785c51eb9ce4f11ef8e98376159','20bb927951251ec356e2c6f1a8c135a22d1e7785c51eb9ce4f11ef8e98376159',false),
    'includes/class-mad4b-scp-presentation-integrity.php'=>array('4d22f00e4a1ef2e5ef4cdf9a5ccc7f3e519891586f4982a06ed243e143b7cd6a','4d22f00e4a1ef2e5ef4cdf9a5ccc7f3e519891586f4982a06ed243e143b7cd6a',false),
    'includes/class-mad4b-scp-readiness-event-automation.php'=>array('5a18636dda2e1ec5df8ee677f8869c61ccf980c8e1b0d75cd075a6106e1ffba5','ad8eac90e66ac695ea2ee6aab0c06ed5cb90fea2ffb7415ee6bc45d85b53a01d',true),
    'includes/class-mad4b-scp-readiness-integrations.php'=>array('114faf65fb3a19c6ceb4d23a6f21f51df19b0d7e6359ae68104cf7fdc650797c','5ad0e90f76843527ffaac5bcde288beb181f83d7590141a77ea59e33428f69cb',true),
    'includes/class-mad4b-scp-readiness-preactivation.php'=>array('6042798ef0eaa09578ae9e9b014373554af36bab0dcf56c40d4b25ef0847c97b','d9a07f0e9d9105e24cd2a27ab6ef1ce8c086c8f4e8e3688724e1d23bfd74f915',true),
    'includes/class-mad4b-scp-readiness-scopes.php'=>array('35e158ec11006ac20271392d22d6a10c450065dca91b3631790aa38009ed782c','cf7943d2c8706c6a8f8812b9d95dc3a3a7dc70aa55fdf396f3faffc2f380ab2b',true),
    'includes/class-mad4b-scp-readiness-team-preparation.php'=>array('32ef4b54ad67d541fe1de2cd827b14dca76300cc005bc5df77164987df752615','32ef4b54ad67d541fe1de2cd827b14dca76300cc005bc5df77164987df752615',false),
    'includes/class-mad4b-scp-readiness-workflow-options.php'=>array('b99f4b8fd77c8f9578b104258dd624aa12a78eaa44857ffaf15fc00d39819e8c','b99f4b8fd77c8f9578b104258dd624aa12a78eaa44857ffaf15fc00d39819e8c',false),
    'includes/class-mad4b-scp-source-lifecycle.php'=>array('ac95b12345f26c355bbdf45df20a3740a3878eeb2cb08a2365a1ccd7ec4183e4','ac95b12345f26c355bbdf45df20a3740a3878eeb2cb08a2365a1ccd7ec4183e4',false),
);
$transitive_links=array(
    array('includes/class-mad4b-scp-brand-experience-quality.php','includes/class-mad4b-scp-brand-integrity.php'),
    array('includes/class-mad4b-scp-content-readiness-alerts.php','includes/class-mad4b-scp-brand-experience-quality.php'),
    array('includes/class-mad4b-scp-content-readiness-alerts.php','includes/class-mad4b-scp-content-completeness.php'),
    array('includes/class-mad4b-scp-content-readiness-alerts.php','includes/class-mad4b-scp-content-type-roles.php'),
    array('includes/class-mad4b-scp-core-web-vitals.php','includes/class-mad4b-scp-core-web-vitals-admin-trait.php'),
    array('includes/class-mad4b-scp-content-readiness-alerts.php','includes/class-mad4b-scp-readiness-discovery-trait.php'),
    array('includes/class-mad4b-scp-content-readiness-alerts.php','includes/class-mad4b-scp-readiness-reconciliation-trait.php'),
    array('includes/class-mad4b-scp-content-readiness-alerts.php','includes/class-mad4b-scp-geo-quality.php'),
    array('includes/class-mad4b-scp-content-readiness-alerts.php','includes/class-mad4b-scp-locale-experience-alerts.php'),
    array('includes/class-mad4b-scp-content-readiness-alerts.php','includes/class-mad4b-scp-presentation-integrity.php'),
    array('includes/class-mad4b-scp-content-readiness-alerts.php','includes/class-mad4b-scp-readiness-workflow-options.php'),
    array('includes/class-mad4b-scp-frontend-impact-priority.php','includes/class-mad4b-scp-content-type-roles.php'),
    array('includes/class-mad4b-scp-presentation-integrity.php','includes/class-mad4b-scp-presentation-browser-evidence.php'),
    array('includes/class-mad4b-scp-readiness-preactivation.php','includes/class-mad4b-scp-readiness-event-automation.php'),
    array('includes/class-mad4b-scp-readiness-preactivation.php','includes/class-mad4b-scp-readiness-team-preparation.php'),
);
$same=0;$successors=0;
foreach($originals as $rel=>$info){
 list($candidate,$sealed,$changed)=$info;
 $file=$root.'/'.$rel;
 demand(is_file($file),'Captured source missing: '.$rel);
 demand(hash_equals($candidate,hash_file('sha256',$file)),
        'Git candidate source differs from reviewed SHA: '.$rel);
 demand($changed ? !hash_equals($candidate,$sealed) : hash_equals($candidate,$sealed),
        'Frozen-original relationship changed: '.$rel);
 demand(false===strpos($main,basename($rel)),
        'Original optional service unexpectedly loaded by plugin Bootstrap: '.$rel);
 if($changed)$successors++;else $same++;
}
demand(count($originals)===18&&$same===9&&$successors===9,
       'Exact original 9 source-identical + 9 reviewed successors including parent-safe admin routes');
$menuSource=file_get_contents($root.'/includes/class-mad4b-scp-readiness-integrations.php');
demand(false!==strpos($menuSource,'MAD4B_SCP_Admin_Route_Registry::schedule_submenu(')
       && false===strpos($menuSource,"add_action('admin_menu'")
       && false===strpos($menuSource,'add_submenu_page('),
       'Readiness Integrations must defer menus to canonical parent-aware owners');
$scopeMenuSource=file_get_contents($root.'/includes/class-mad4b-scp-readiness-scopes.php');
demand(false!==strpos($scopeMenuSource,'MAD4B_SCP_Admin_Route_Registry::schedule_submenu(')
       && false!==strpos($scopeMenuSource,"MAD4B_SCP_Admin_Route_Registry::register(self::PAGE,'manage_options');")
       && false===strpos($scopeMenuSource,"add_action('admin_menu'"),
       'Readiness Scopes must use parent-safe admin route and manage_options');
$events=file_get_contents($root.'/includes/class-mad4b-scp-readiness-event-automation.php');
demand(false!==strpos($events,"if(0===strpos(\$type,'term:')){"), 'PR906 PHP 7.4 compatibility guard must remain');
$preactivation=file_get_contents($root.'/includes/class-mad4b-scp-readiness-preactivation.php');
demand(false!==strpos($preactivation,"MAD4B_SCP_Admin_Route_Registry::register(self::PAGE,'manage_options');")
       && false!==strpos($preactivation,'MAD4B_SCP_Admin_Route_Registry::schedule_submenu('),
       'PR906 parent-aware admin route and manage_options must remain');
foreach($transitive_links as $edge){
 list($from,$to)=$edge;
 demand(is_file($root.'/'.$to),'Unresolved transitive PHP include: '.$from.' => '.$to);
}
demand(count($transitive_links)===15,'Original and reviewed helper/trait include closure with PR907 independent Brand CPT gate');
// PR907 preserves a separate explicit company CPT opt-in for Brand Experience.
$brandSource=file_get_contents($root.'/includes/class-mad4b-scp-brand-experience-quality.php');
demand(false!==strpos($brandSource,'mad4b_scp_brand_experience_post_type'),
 'Brand Experience must retain PR907 company opt-in');
demand(false===strpos($brandSource,"'tours-and-activities'"),
 'Brand Experience must not fall back to travel-specific defaults');
// The original 1,852-line class was partitioned without losing its method bodies.
$facade=file_get_contents($root.'/includes/class-mad4b-scp-content-readiness-alerts.php');
demand(false!==strpos($facade,'use MAD4B_SCP_Readiness_Discovery_Trait;') && false!==strpos($facade,'use MAD4B_SCP_Readiness_Reconciliation_Trait;'),'Original Readiness API is still composed');
$traitfile=$root.'/includes/class-mad4b-scp-readiness-discovery-trait.php';
demand(hash_equals('725e8eec380e33e774565c9f37c29d8d6f34f4042184a2919f144b04c743f80c',hash_file('sha256',$traitfile)),'Pinned trait method source unchanged');
require_once $traitfile;
$traitfile=$root.'/includes/class-mad4b-scp-readiness-reconciliation-trait.php';
demand(hash_equals('43afc8a5dffee6db6b7d162f7451d6ee2b0110e2101eff0043f83c7059de5c3b',hash_file('sha256',$traitfile)),'Pinned trait method source unchanged');
require_once $traitfile;
final class MAD4B_SCP_Readiness_Trait_Probe {use MAD4B_SCP_Readiness_Discovery_Trait;use MAD4B_SCP_Readiness_Reconciliation_Trait;}
demand(method_exists('MAD4B_SCP_Readiness_Trait_Probe','discover') && method_exists('MAD4B_SCP_Readiness_Trait_Probe','reconcile') && method_exists('MAD4B_SCP_Readiness_Trait_Probe','source_backed_hotel_pickup'),'Original discover/reconcile/source-backed-pickup functions are preserved');
$geo=file_get_contents($root.'/includes/class-mad4b-scp-geo-quality.php');
demand(hash('sha256',$geo."\n")==='79a3f48ee3563338d2f185770df29614b2910d78eb6561499dac078ba2c74098',
       'One final newline must exactly recover original Geo Quality SHA');
foreach(array(
 'class-mad4b-scp-content-readiness-alerts.php',
 'class-mad4b-scp-readiness-preactivation.php',
 'class-mad4b-scp-source-lifecycle.php',
 'class-mad4b-scp-core-web-vitals.php'
) as $name){
 $src=file_get_contents($root.'/includes/'.$name);
 demand(false!==strpos($src,'::boot();')||false!==strpos($src,'::boot()'),
        'Inline boot risk must not be obscured in sealed source: '.$name);
}
$neutral=file_get_contents($root.'/includes/class-mad4b-scp-content-type-roles.php');
demand(false!==strpos($neutral,'site_urls_match_enrollment')
       && false!==strpos($neutral,'mad4b_scp_content_type_roles'),
       'Company-specific roles must require enrolled site and explicit mapping');
foreach(array('class-mad4b-scp-content-readiness-alerts.php',
             'class-mad4b-scp-frontend-impact-priority.php') as $name){
 $src=file_get_contents($root.'/includes/'.$name);
 demand(false!==strpos($src,"require_once __DIR__.'/class-mad4b-scp-content-type-roles.php';"),
       'Shared source-only role contract must be explicitly imported: '.$name);
 demand(false===strpos($src,"'tours-and-activities'") && false===strpos($src,"'tour_type'"),
       'Unreviewed travel CPT defaults not allowed: '.$name);
}
require_once $root.'/includes/class-mad4b-scp-content-type-roles.php';
$policy='MAD4B_SCP_Content_Type_Roles';
demand($policy::get('tour')==='','Site unenrolled must deny roles');
MAD4B_SCP_Site_Profile::$enrolled=true;
demand($policy::get('tour')==='','Site enrolled but unconfigured must deny roles');
$GLOBALS['scoped_roles']=array('tour'=>'trip_program','property'=>'lodging_unit','transport'=>'vehicle');
$GLOBALS['registered_types']=array(
 'trip_program'=>array('public'=>true,'_builtin'=>false),
 'lodging_unit'=>array('public'=>true,'_builtin'=>false),
 'vehicle'=>array('public'=>true,'_builtin'=>false),
 'post'=>array('public'=>true,'_builtin'=>true),
 'private_type'=>array('public'=>false,'_builtin'=>false),
);
demand($policy::get('tour')==='trip_program','Custom tour role resolves only after explicit mapping');
demand($policy::get('property')==='lodging_unit','Site-neutral property role');
demand($policy::get('transport')==='vehicle','Site-neutral mobility role');
demand($policy::get('unknown')==='','Unknown business role fails closed');
$GLOBALS['scoped_roles']=array('tour'=>'post');
demand($policy::get('tour')==='','Built-in post type cannot become automatic business role');
$GLOBALS['scoped_roles']=array('tour'=>'private_type');
demand($policy::get('tour')==='','Nonpublic CPT cannot become business role');
$GLOBALS['scoped_roles']=array('tour'=>'not_registered');
demand($policy::get('tour')==='','Unknown CPT fails closed');
$GLOBALS['scoped_roles']=array('tour'=>'../../bad');
demand($policy::get('tour')==='','Invalid CPT slug fails closed');
$adminTrait=$root.'/includes/class-mad4b-scp-core-web-vitals-admin-trait.php';
demand(hash_equals('e13732d51f01e65e732bc2fb6d40d4ff960b1cae0cc8ecc806244ef31d7b39a1',hash_file('sha256',$adminTrait)),'Pinned Core Web Vitals admin method preserved');
require_once $adminTrait;
final class MAD4B_CWV_Admin_Probe {use MAD4B_SCP_Core_Web_Vitals_Admin_Trait;}
demand(method_exists('MAD4B_CWV_Admin_Probe','render_admin_section'),'CWV admin method retained');
// The daily schedule and verified-release hook are static declarations in
// a dormant file, not executable authorization or an active Cron event.
$sourceCwv=file_get_contents($root.'/includes/class-mad4b-scp-core-web-vitals.php');
demand(false!==strpos($sourceCwv,"'daily'") && false!==strpos($sourceCwv,"mad4b/performance/release_verified"),
       'Original daily schedule and release hook identifiers retained');
demand(false===strpos($main,'class-mad4b-scp-core-web-vitals.php'),
       'No Web Vitals schedule can boot through main Bootstrap');
$endpoint=file_get_contents($root.'/includes/class-mad4b-scp-core-web-vitals.php');
demand(false!==strpos($endpoint,"https://www.googleapis.com/pagespeedonline/v5/runPagespeed")
    && false!==strpos($endpoint,"'redirection' => 0")
    && false!==strpos($endpoint,'validate_monitored_url'),
    'Google PageSpeed endpoint is fixed and monitored-site URL validated');
echo "PASS: 18 originals, 15 verified include edges, 9 frozen files, 9 reviewed successors (PR906+907 + admin routes retained), no new boot, tenant policy denied by default".PHP_EOL;
